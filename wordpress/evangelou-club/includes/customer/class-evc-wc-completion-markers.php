<?php
defined('ABSPATH') || exit;

/**
 * STAGE-03D: order metadata of the WooCommerce-completion path, through the
 * WooCommerce CRUD API only (HPOS `wc_orders_meta` and legacy `postmeta` alike):
 *
 *  - `_evc_club_wc_completion`: the signed, immutable completion marker
 *    (EVC_Wc_Completion_Marker). Written once; a different or unverifiable
 *    stored value is never replaced. Every write is read back from the
 *    database (WC_Data::save_meta_data() ignores the data store's result).
 *  - `_evc_club_wc_followup`: an UNSIGNED index for the recovery scan only:
 *    `open:<attempts>` (retry automatically) or `manager:<reason>` (stopped).
 *    It is never trusted: the recovery re-reads the marker, the order, PMPro and
 *    the Club record. Deleting it only stops automatic recovery (fail closed).
 *  - `_evc_club_wc_ended`: "this Club order was cancelled, refunded or failed
 *    while the feature was on". A later completion of such an order is never
 *    a payment confirmation (fail closed, flagged).
 * Private (`_`) keys: never returned by the Store API or to customers; shop
 * managers / admins can see the opaque values through the admin REST API.
 */
final class EVC_Wc_Completion_Markers {
    const META_KEY = '_evc_club_wc_completion';
    const FOLLOWUP_KEY = '_evc_club_wc_followup';
    const ENDED_KEY = '_evc_club_wc_ended';
    const OPEN_PREFIX = 'open:';
    const MANAGER_PREFIX = 'manager:';

    /** @var EVC_Wc_Completion_Marker_Codec */
    private $codec;
    /** @var string */
    private $environment;

    public function __construct(EVC_Wc_Completion_Marker_Codec $codec, string $environment) {
        EVC_Wc_Completion_Feature::assert_constructible(__CLASS__);
        $this->codec = $codec;
        $this->environment = $environment;
    }

    public function read(int $order_id): EVC_Wc_Completion_Marker_Read {
        try {
            $order = $this->fresh_order($order_id);
            if ($order === null) {
                return EVC_Wc_Completion_Marker_Read::unavailable('order_not_found');
            }
            $rows = $order->get_meta(self::META_KEY, false, 'edit');
            if (!is_array($rows)) {
                return EVC_Wc_Completion_Marker_Read::unavailable('meta_unreadable');
            }
            $rows = array_values($rows);
            if (count($rows) === 0) {
                return EVC_Wc_Completion_Marker_Read::missing();
            }
            if (count($rows) > 1) {
                return EVC_Wc_Completion_Marker_Read::invalid('duplicate_marker_rows');
            }
            return $this->codec->decode($rows[0]->value, $order_id, (int) $order->get_customer_id(), $this->environment);
        } catch (Throwable $e) {
            return EVC_Wc_Completion_Marker_Read::unavailable('read_failed');
        }
    }

    /**
     * Stores the marker unless one is already stored. Returns written / already / conflict /
     * unavailable / write_failed / outcome_unknown (EVC_Bacs_Intent_Write outcomes).
     */
    public function create(EVC_Wc_Completion_Marker $marker): EVC_Bacs_Intent_Write {
        if ($marker->environment() !== $this->environment) {
            return new EVC_Bacs_Intent_Write(EVC_Bacs_Intent_Write::UNAVAILABLE, 'wrong_environment');
        }
        $current = $this->read($marker->order_id());
        if ($current->outcome === EVC_Wc_Completion_Marker_Read::VALID) {
            return $current->marker->same_as($marker)
                ? new EVC_Bacs_Intent_Write(EVC_Bacs_Intent_Write::ALREADY)
                : new EVC_Bacs_Intent_Write(EVC_Bacs_Intent_Write::CONFLICT, 'different_marker_stored');
        }
        if ($current->outcome === EVC_Wc_Completion_Marker_Read::INVALID) {
            return new EVC_Bacs_Intent_Write(EVC_Bacs_Intent_Write::CONFLICT, 'stored_marker_invalid:' . $current->reason);
        }
        if ($current->outcome !== EVC_Wc_Completion_Marker_Read::MISSING) {
            return new EVC_Bacs_Intent_Write(EVC_Bacs_Intent_Write::UNAVAILABLE, (string) $current->reason);
        }
        $value = $this->codec->encode($marker);
        if (!$this->set_meta($marker->order_id(), self::META_KEY, $value)) {
            return new EVC_Bacs_Intent_Write(EVC_Bacs_Intent_Write::UNAVAILABLE, 'write_not_started');
        }
        $after = $this->read($marker->order_id());
        if ($after->outcome === EVC_Wc_Completion_Marker_Read::VALID && $after->raw === $value) {
            return new EVC_Bacs_Intent_Write(EVC_Bacs_Intent_Write::WRITTEN);
        }
        return $after->outcome === EVC_Wc_Completion_Marker_Read::MISSING
            ? new EVC_Bacs_Intent_Write(EVC_Bacs_Intent_Write::WRITE_FAILED, 'not_persisted')
            : new EVC_Bacs_Intent_Write(EVC_Bacs_Intent_Write::OUTCOME_UNKNOWN, 'read_back_differs');
    }

    /** The follow-up index value, or null (none / unreadable). */
    public function followup(int $order_id): ?string {
        $value = $this->single($order_id, self::FOLLOWUP_KEY);
        return is_string($value) && $value !== '' ? $value : null;
    }

    /** Attempts so far when the order is open for automatic recovery, else null. */
    public static function open_attempts(?string $followup): ?int {
        if ($followup === null || !preg_match('/^open:(\d{1,4})$/D', $followup, $m)) {
            return null;
        }
        return (int) $m[1];
    }

    /** Sets (or with null removes) the follow-up index; true when the database shows it. */
    public function set_followup(int $order_id, ?string $value): bool {
        if ($value !== null && !preg_match('/^(open:\d{1,4}|manager:[a-z][a-z0-9_]{0,63})$/D', $value)) {
            throw new InvalidArgumentException('Invalid follow-up value.');
        }
        if (!$this->set_meta($order_id, self::FOLLOWUP_KEY, $value)) {
            return false;
        }
        return $this->followup($order_id) === $value;
    }

    public function mark_ended(int $order_id): bool {
        return $this->set_meta($order_id, self::ENDED_KEY, '1') && $this->was_ended($order_id);
    }

    public function was_ended(int $order_id): bool {
        return $this->single($order_id, self::ENDED_KEY) === '1';
    }

    /**
     * Orders open for automatic recovery, oldest first (bounded). Read straight from the order meta
     * table WooCommerce uses (HPOS or posts), never from a cache. Throws on a database error.
     *
     * @return int[]
     */
    public function open_orders(int $limit): array {
        global $wpdb;
        $table = \Automattic\WooCommerce\Utilities\OrderUtil::get_table_for_order_meta();
        $id_column = $table === $wpdb->postmeta ? 'post_id' : 'order_id';
        $wpdb->last_error = '';
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT {$id_column} FROM {$table} WHERE meta_key = %s AND meta_value LIKE %s ORDER BY {$id_column} ASC LIMIT %d",
            self::FOLLOWUP_KEY,
            $wpdb->esc_like(self::OPEN_PREFIX) . '%',
            max(1, min(100, $limit))
        ));
        if (!is_array($ids) || $wpdb->last_error !== '') {
            throw new RuntimeException('Open completion follow-ups could not be read.');
        }
        return array_map('intval', $ids);
    }

    /** @return mixed the single stored value, null when absent or unreadable */
    private function single(int $order_id, string $key) {
        try {
            $order = $this->fresh_order($order_id);
            if ($order === null) {
                return null;
            }
            $rows = array_values((array) $order->get_meta($key, false, 'edit'));
            return count($rows) === 1 ? $rows[0]->value : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    private function set_meta(int $order_id, string $key, ?string $value): bool {
        try {
            $order = $this->fresh_order($order_id);
            if ($order === null) {
                return false;
            }
            if ($value === null) {
                $order->delete_meta_data($key);
            } else {
                $order->update_meta_data($key, $value);
            }
            $order->save();
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** Same as the intent store: metadata read from the database, a failed read throws. */
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
