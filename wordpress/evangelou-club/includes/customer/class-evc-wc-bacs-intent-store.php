<?php
defined('ABSPATH') || exit;

/** Result of an intent write. Nothing here is payment evidence. */
final class EVC_Bacs_Intent_Write {
    /** written and read back byte-identical */
    const WRITTEN = 'written';
    /** the identical value was already stored (idempotent replay); nothing written */
    const ALREADY = 'already';
    /** another, different intent is stored (e.g. a second bank reference); nothing written */
    const CONFLICT = 'conflict';
    /** the stored value changed since it was read (compare-and-set failed); nothing written */
    const STALE = 'stale';
    /** the member lock is not held by this session; nothing written */
    const LOCK_NOT_HELD = 'lock_not_held';
    /** nothing could be read or the write was refused before it started; nothing written */
    const UNAVAILABLE = 'unavailable';
    /** the write was attempted and is NOT persisted (read back the previous value) */
    const WRITE_FAILED = 'write_failed';
    /** the write MAY have been persisted: read again later; never assume either way */
    const OUTCOME_UNKNOWN = 'outcome_unknown';

    /** @var string */
    public $outcome;
    /** @var string|null */
    public $reason;

    public function __construct(string $outcome, ?string $reason = null) {
        $this->outcome = $outcome;
        $this->reason = $reason;
    }
}

/**
 * STAGE-03B.2: durable BACS intents in PRIVATE WooCommerce order metadata
 * (owner decision O1), through the WooCommerce CRUD API only, so HPOS
 * (wc_orders_meta) and legacy storage (postmeta) behave the same.
 *
 * Verified WooCommerce 11.2.1 behaviour this relies on / defends against:
 *  - WC_Data::save_meta_data() ignores the data store's result, so a failed
 *    meta write looks like success: EVERY write is read back from the
 *    database (read_meta_data(true) bypasses the object cache) and compared
 *    byte for byte;
 *  - meta keys are not unique: two uncoordinated writers can leave two rows;
 *    a read that finds more than one row is invalid (fail closed);
 *  - "_"-prefixed meta is not exposed by the Store API or to customers; the
 *    admin REST API (shop managers/admins only) does return it — values are
 *    opaque references only.
 * Concurrency: writes require the member's EVC_Member_Lock (held by this
 * session, re-checked before and after the write) AND compare-and-set on
 * the exact stored bytes. The lock is advisory; anything bypassing it is
 * detected, not prevented (see EVC_Member_Lock).
 *
 * This class never completes orders, never touches PMPro and never records a
 * payment. Nothing in the plugin constructs it yet.
 */
final class EVC_Wc_Bacs_Intent_Store {
    const META_KEY = '_evc_club_bacs_intent';

    /** @var EVC_Bacs_Intent_Codec */
    private $codec;
    /** @var string */
    private $environment;

    public function __construct(EVC_Bacs_Intent_Codec $codec, string $environment) {
        EVC_Wc_Completion_Feature::assert_constructible(__CLASS__);
        $this->codec = $codec;
        $this->environment = $environment;
    }

    /** Fresh, strictly validated read (never from a cached object). */
    public function read(int $order_id): EVC_Bacs_Intent_Read {
        try {
            $order = $this->fresh_order($order_id);
            if ($order === null) {
                return EVC_Bacs_Intent_Read::unavailable('order_not_found');
            }
            $rows = $order->get_meta(self::META_KEY, false, 'edit');
            if (!is_array($rows)) {
                return EVC_Bacs_Intent_Read::unavailable('meta_unreadable');
            }
            $rows = array_values($rows); // WooCommerce keeps the meta list's own indices
            if (count($rows) === 0) {
                return EVC_Bacs_Intent_Read::missing();
            }
            if (count($rows) > 1) {
                return EVC_Bacs_Intent_Read::invalid('duplicate_intent_rows');
            }
            return $this->codec->decode($rows[0]->value, $order_id, (int) $order->get_customer_id(), $this->environment);
        } catch (Throwable $e) {
            return EVC_Bacs_Intent_Read::unavailable('read_failed');
        }
    }

    /** Stores the FIRST (planned) intent of an order; never replaces another one. */
    public function create(EVC_Member_Lock $lock, EVC_Bacs_Intent $intent): EVC_Bacs_Intent_Write {
        if ($intent->state() !== EVC_Bacs_Intent::STATE_PLANNED || $intent->environment() !== $this->environment) {
            return new EVC_Bacs_Intent_Write(EVC_Bacs_Intent_Write::UNAVAILABLE, 'not_a_new_intent');
        }
        if ($lock->wp_user_id() !== $intent->customer_id() || !$lock->is_held()) {
            return new EVC_Bacs_Intent_Write(EVC_Bacs_Intent_Write::LOCK_NOT_HELD);
        }
        $current = $this->read($intent->order_id());
        if ($current->outcome === EVC_Bacs_Intent_Read::VALID) {
            return $current->intent->same_as($intent)
                ? new EVC_Bacs_Intent_Write(EVC_Bacs_Intent_Write::ALREADY)
                : new EVC_Bacs_Intent_Write(EVC_Bacs_Intent_Write::CONFLICT, 'different_intent_stored');
        }
        if ($current->outcome === EVC_Bacs_Intent_Read::INVALID) {
            // Never overwrite something we cannot verify (tampered, foreign, duplicated).
            return new EVC_Bacs_Intent_Write(EVC_Bacs_Intent_Write::CONFLICT, 'stored_intent_invalid:' . $current->reason);
        }
        if ($current->outcome !== EVC_Bacs_Intent_Read::MISSING) {
            return new EVC_Bacs_Intent_Write(EVC_Bacs_Intent_Write::UNAVAILABLE, (string) $current->reason);
        }
        return $this->write($lock, $intent->order_id(), $intent, null);
    }

    /** Moves the stored intent one lifecycle step: compare-and-set on the exact stored bytes. */
    public function transition(EVC_Member_Lock $lock, EVC_Bacs_Intent $expected, EVC_Bacs_Intent $next): EVC_Bacs_Intent_Write {
        if (!$expected->is_followed_by($next)) {
            return new EVC_Bacs_Intent_Write(EVC_Bacs_Intent_Write::UNAVAILABLE, 'not_a_lifecycle_step');
        }
        if ($lock->wp_user_id() !== $expected->customer_id() || !$lock->is_held()) {
            return new EVC_Bacs_Intent_Write(EVC_Bacs_Intent_Write::LOCK_NOT_HELD);
        }
        $current = $this->read($expected->order_id());
        if ($current->outcome === EVC_Bacs_Intent_Read::VALID && $current->intent->same_as($next)) {
            return new EVC_Bacs_Intent_Write(EVC_Bacs_Intent_Write::ALREADY);
        }
        if ($current->outcome === EVC_Bacs_Intent_Read::UNAVAILABLE) {
            return new EVC_Bacs_Intent_Write(EVC_Bacs_Intent_Write::UNAVAILABLE, (string) $current->reason);
        }
        if ($current->outcome !== EVC_Bacs_Intent_Read::VALID || $current->raw !== $this->codec->encode($expected)) {
            return new EVC_Bacs_Intent_Write(EVC_Bacs_Intent_Write::STALE, $current->outcome === EVC_Bacs_Intent_Read::VALID ? 'intent_changed' : 'stored_intent_' . $current->outcome);
        }
        return $this->write($lock, $expected->order_id(), $next, $current->raw);
    }

    private function write(EVC_Member_Lock $lock, int $order_id, EVC_Bacs_Intent $intent, ?string $previous_raw): EVC_Bacs_Intent_Write {
        $value = $this->codec->encode($intent);
        $attempted = false;
        try {
            $order = $this->fresh_order($order_id);
            if ($order === null) {
                return new EVC_Bacs_Intent_Write(EVC_Bacs_Intent_Write::UNAVAILABLE, 'order_not_found');
            }
            $order->update_meta_data(self::META_KEY, $value);
            $attempted = true;
            $order->save();
        } catch (Throwable $e) {
            if (!$attempted) {
                return new EVC_Bacs_Intent_Write(EVC_Bacs_Intent_Write::UNAVAILABLE, 'write_not_started');
            }
            // Fall through: the database decides what happened.
        }
        $lock_kept = $lock->is_held();
        $after = $this->read($order_id);
        if ($after->outcome === EVC_Bacs_Intent_Read::VALID && $after->raw === $value) {
            return $lock_kept
                ? new EVC_Bacs_Intent_Write(EVC_Bacs_Intent_Write::WRITTEN)
                : new EVC_Bacs_Intent_Write(EVC_Bacs_Intent_Write::OUTCOME_UNKNOWN, 'lock_lost_during_write');
        }
        $unchanged = $previous_raw === null
            ? $after->outcome === EVC_Bacs_Intent_Read::MISSING
            : ($after->outcome === EVC_Bacs_Intent_Read::VALID && $after->raw === $previous_raw);
        if ($unchanged && $lock_kept) {
            return new EVC_Bacs_Intent_Write(EVC_Bacs_Intent_Write::WRITE_FAILED, 'not_persisted');
        }
        return new EVC_Bacs_Intent_Write(EVC_Bacs_Intent_Write::OUTCOME_UNKNOWN, $after->outcome === EVC_Bacs_Intent_Read::UNAVAILABLE ? 'read_back_failed' : 'unexpected_value_after_write');
    }

    /**
     * A new order object whose metadata is read from the database, not from any cache.
     * WooCommerce's meta readers turn a FAILED query into "no metadata"; a database error
     * during the read therefore throws here, so a failed read is never mistaken for "missing".
     */
    private function fresh_order(int $order_id): ?WC_Order {
        global $wpdb;
        if ($order_id < 1) {
            return null;
        }
        clean_post_cache($order_id);
        $order = wc_get_order($order_id);
        if (!$order instanceof WC_Order || $order instanceof WC_Order_Refund) {
            return null;
        }
        $wpdb->last_error = '';
        $order->read_meta_data(true);
        if ($wpdb->last_error !== '') {
            throw new RuntimeException('Order metadata could not be read.');
        }
        return $order;
    }
}
