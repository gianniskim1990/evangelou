<?php
defined('ABSPATH') || exit;

/** Result of one completion / recovery / correction attempt (internal; never shown to customers). */
final class EVC_Wc_Completion_Result {
    /** the Club recorded the payment now (the member is paid through the period) */
    const RECORDED = 'recorded';
    /** already recorded with exactly the signed period: nothing new funded */
    const ALREADY_RECORDED = 'already_recorded';
    /** completed by someone whose completion is not a payment confirmation (no user, the customer, the shared Club account, no capability) */
    const NOT_ATTESTED = 'not_attested';
    /** fail closed now, finished later by the recovery step (a signed marker is stored) */
    const DEFERRED = 'deferred';
    const REFUSED = 'refused';
    const CONFLICT = 'conflict';
    const MANAGER_REQUIRED = 'manager_required';
    const UNAVAILABLE = 'unavailable';
    /** not a Club order / nothing to do */
    const IGNORED = 'ignored';
    /** corrections: recorded now / already recorded / nothing was recorded for the order */
    const CORRECTED = 'corrected';
    const ALREADY_CORRECTED = 'already_corrected';
    const NOT_RECORDED = 'not_recorded';

    /** @var string */
    public $outcome;
    /** @var string|null internal reason code (no personal or bank data) */
    public $reason;
    /** @var EVC_Funded_Period|null */
    public $period;
    /** @var string[] steps taken (planned, issue, issued, record, finish_intent, issuance_mismatch) */
    public $steps = array();
    /** @var string|null what happened to the add-on's own run: suppressed / shipped (claimed completions only) */
    public $addon;
    /** @var string|null follow-up index after this attempt */
    public $followup;
    /** @var bool */
    public $paid_time_at_risk = false;
    /** @var bool */
    public $audited = false;

    public function __construct(string $outcome, ?string $reason = null, ?EVC_Funded_Period $period = null) {
        $this->outcome = $outcome;
        $this->reason = $reason;
        $this->period = $period;
    }
}

/**
 * STAGE-03D (owner decision 2026-10-26, Option A): a Club order set to
 * «Ολοκληρωμένη» (Completed) in WooCommerce by a logged-in shop user with
 * `edit_shop_orders` — never the order's customer, never the shared Club staff
 * account, never "nobody" (REST API keys, cron, automation) — IS the Club payment
 * confirmation. Only `completed` counts (never processing / on-hold / pending).
 * Exists only behind EVC_CLUB_WC_COMPLETION_ENABLED (EVC_Wc_Completion_Plugin);
 * the packager forbids constructing it anywhere else.
 *
 * `woocommerce_order_status_completed` at COMPLETION_PRIORITY (< 10, before
 * PMPro WooCommerce's own pmprowoo_add_membership_from_order() at 10), in ONE
 * callback that catches every Throwable (WooCommerce only catches Exception;
 * nothing of ours may abort WooCommerce's hook run):
 *   who completed it → Club order shape (BACS, EUR, 20.00, one Club item) →
 *   never after a cancellation / refund → member lock → pre-flight shared with
 *   the BACS issuer (Athens time zone, pinned environment, level group) → fresh
 *   plan, confirmation instant = now (whole second) → signed `planned` intent
 *   (movement id = keyed HMAC of the ORDER in its own namespace; authority
 *   manual_admin, evidence wc_order) → armed EVC_Bacs_Issuance_Guard + a DIRECT
 *   pmprowoo_add_membership_from_order() (the re-issue path of STAGE-03B.3b;
 *   the guard is told the order itself) → fresh re-read → recovery policy →
 *   `issued` → record_expecting → `recorded`, all under that one lock.
 * Then, for a completion this path took on ("claimed"), the add-on's own
 * priority-10 run for THIS order is suppressed through `pmpro_change_level`
 * returning the integer level id (PMPro 3.8.9 functions.php: the filter at
 * 1303, then 1326-1329 returns early when the user already has the level) —
 * ONLY while the customer holds an active Club row (an integer level without
 * one would make PMPro insert a row without an end) and only for the add-on's
 * own call. Not claimed (not attested, not a Club bank-transfer order, after a
 * cancellation / refund): the add-on behaves exactly as shipped.
 *
 * A completion that cannot be confirmed now (lock busy, Club database down,
 * an instant PMPro cannot show exactly, a late renewal before PMPro's own
 * expiry ran, the same customer twice in one second, ...) fails closed: a signed
 * EVC_Wc_Completion_Marker ("completed by user X at T") is stored and the
 * recovery step (recover(), EVC_Wc_Completion_Recovery) finishes it later with
 * everything re-read. Cancelled / refunded / failed recorded orders get a
 * correction (EVC_Provenance_Recorder::correct()) so the card is frozen; a
 * partial refund is only flagged for a manager.
 *
 * Every attempt is audited in evc_audit_events (opaque references and numeric
 * WordPress ids only; never names, e-mail addresses, notes or exception texts).
 * The bank-statement attestation of STAGE-03B.3a/b is NOT used on this path.
 */
final class EVC_Wc_Completion_Confirmer {
    const EVENT = 'wc_completion_confirmation';
    const CORRECTION_EVENT = 'wc_order_correction';
    /** HMAC namespace of the movement id on this path (one completed order = one money movement). */
    const MOVEMENT_NAMESPACE = 'wc_order_completion';
    const CORRECTION_NAMESPACE = 'wc_order_correction';
    /** Who may confirm by completing: WooCommerce's own order-editing capability. */
    const CAPABILITY = 'edit_shop_orders';
    /** Before the add-on's own issuance (10). */
    const COMPLETION_PRIORITY = 5;
    /** After the add-on's own priority: the add-on is put back here (never runs for the order it was removed for). */
    const AFTER_ADDON_PRIORITY = 11;
    /** PMPro WooCommerce's own completion callback: pmprowoo_add_membership_from_order (add-on 1.11: registered at 10, one argument). */
    const ADDON_HOOK = 'woocommerce_order_status_completed';
    const ADDON_FUNCTION = 'pmprowoo_add_membership_from_order';
    const ADDON_PRIORITY = 10;
    /** Cancelled / refunded / failed: before the add-on's own cancellation (10), so an exception there cannot skip it. */
    const ENDED_PRIORITY = 5;
    const MAX_STEPS = 6;
    /** Automatic recovery attempts before a deferred completion stops for a manager. */
    const MAX_ATTEMPTS = 96;
    const LOCK_WAIT_SECONDS = 2;
    const ENDED = array('cancelled', 'refunded', 'failed');
    /** Manager-required reasons that clear by themselves: the recovery step retries them. */
    const RETRY_REASONS = array('period_not_representable_in_pmpro', 'late_renewal_before_pmpro_expiry', 'plan_same_instant_payment', 'plan_out_of_order_payment');
    /** An outage of a source (lock held elsewhere, Club database or order meta unreadable): a cron tick during it is not an attempt. */
    const OUTAGE_REASONS = array('member_locked', 'club_unavailable', 'club_record_unavailable', 'marker_unreadable', 'membership_unreadable', 'plan_unavailable', 'lock_lost');

    /** @var EVC_Commerce_Source_Config */
    private $config;
    /** @var callable(): EVC_Provenance_Store */
    private $open_store;
    /** @var callable(EVC_Provenance_Store): EVC_Bacs_Recovery_Inspector */
    private $inspector_for;
    /** @var EVC_Wc_Bacs_Intent_Store */
    private $intents;
    /** @var EVC_Wc_Completion_Markers */
    private $markers;
    /** @var EVC_Bacs_Issuance_Guard */
    private $guard;
    /** @var wpdb */
    private $db;
    /** @var callable(): DateTimeImmutable */
    private $now;
    /** @var EVC_Audit_Log|null */
    private $audit;
    /** @var callable(): EVC_Club_Db|null */
    private $open_audit_db;
    /** @var int */
    private $lock_wait;
    /** @var EVC_Bacs_Recovery_Policy */
    private $policy;

    /** @var EVC_Provenance_Store|null opened lazily, once */
    private $store;
    /** @var array<int,bool> order id => the add-on's priority-10 callback is removed for this completion (put back at priority 11) */
    private $held = array();
    /** @var array<int,int> order id => customer id of a Club-product order this request is completing (for the tripwire) */
    private $club = array();
    /** @var int how often the add-on's own run was withheld from a completion (diagnostics only) */
    private $withheld = 0;
    /** @var int running REST dispatches (rest_request_before_callbacks .. rest_request_after_callbacks) */
    private $rest_depth = 0;
    /** @var EVC_Wc_Completion_Result|null */
    private $last;

    /**
     * @param callable(): EVC_Provenance_Store $open_store
     * @param callable(EVC_Provenance_Store): EVC_Bacs_Recovery_Inspector $inspector_for an inspector over THAT store and $intents, with may_attest() as its actor rule
     * @param callable(): DateTimeImmutable $now
     * @param callable(): EVC_Club_Db|null $open_audit_db
     */
    public function __construct(
        EVC_Commerce_Source_Config $config,
        callable $open_store,
        callable $inspector_for,
        EVC_Wc_Bacs_Intent_Store $intents,
        EVC_Wc_Completion_Markers $markers,
        EVC_Bacs_Issuance_Guard $guard,
        wpdb $db,
        callable $now,
        ?EVC_Audit_Log $audit = null,
        ?callable $open_audit_db = null,
        int $lock_wait_seconds = self::LOCK_WAIT_SECONDS
    ) {
        $this->config = $config;
        $this->open_store = $open_store;
        $this->inspector_for = $inspector_for;
        $this->intents = $intents;
        $this->markers = $markers;
        $this->guard = $guard;
        $this->db = $db;
        $this->now = $now;
        $this->audit = $audit;
        $this->open_audit_db = $open_audit_db;
        $this->lock_wait = $lock_wait_seconds;
        $this->policy = new EVC_Bacs_Recovery_Policy();
    }

    // ---- hooks ---------------------------------------------------------------------------------

    public function register(): void {
        add_action('woocommerce_order_status_completed', array($this, 'on_completed'), self::COMPLETION_PRIORITY, 3);
        add_action('woocommerce_order_status_completed', array($this, 'after_add_on'), self::AFTER_ADDON_PRIORITY, 1);
        add_filter('rest_request_before_callbacks', array($this, 'rest_begin'), 0, 1);
        add_filter('rest_request_after_callbacks', array($this, 'rest_end'), 9999, 1);
        foreach (self::ENDED as $status) {
            add_action('woocommerce_order_status_' . $status, array($this, 'on_ended'), self::ENDED_PRIORITY, 1);
        }
        add_action('woocommerce_order_refunded', array($this, 'on_refund'), 10, 2);
        add_action('shutdown', array($this, 'forget'), 0, 0);
    }

    public function unregister(): void {
        remove_action('woocommerce_order_status_completed', array($this, 'on_completed'), self::COMPLETION_PRIORITY);
        remove_action('woocommerce_order_status_completed', array($this, 'after_add_on'), self::AFTER_ADDON_PRIORITY);
        remove_filter('rest_request_before_callbacks', array($this, 'rest_begin'), 0);
        remove_filter('rest_request_after_callbacks', array($this, 'rest_end'), 9999);
        foreach (self::ENDED as $status) {
            remove_action('woocommerce_order_status_' . $status, array($this, 'on_ended'), self::ENDED_PRIORITY);
        }
        remove_action('woocommerce_order_refunded', array($this, 'on_refund'), 10);
        remove_action('shutdown', array($this, 'forget'), 0);
        $this->forget();
        $this->rest_depth = 0;
    }

    /**
     * `woocommerce_order_status_completed` (priority 5). Never throws: WooCommerce catches only
     * Exception, and nothing here may abort its hook run.
     *
     * First thing, before anything can fail: PMPro WooCommerce's own priority-10 callback is
     * REMOVED for this order (put back at priority 11 by after_add_on(), so it never runs for this
     * order and is there again for the next one of the same request). No completion of a
     * Club-product order may ever let the add-on issue a membership by itself: a stray row it made
     * for a completion the Club did not claim (a refused or unattested one, a toggle after the paid
     * period ended, a re-completion after a cancellation) would block a later legitimate
     * confirmation. Only the claimed, attested completion issues a membership, through this path.
     * An ordinary (non-Club) order gets the add-on back at once and behaves exactly as shipped.
     *
     * @param mixed $order_id
     * @param mixed $order
     * @param mixed $transition WooCommerce 11.2.1 passes {from, to, note, manual}
     */
    public function on_completed($order_id, $order = null, $transition = null): void {
        $order_id = is_numeric($order_id) ? (int) $order_id : 0;
        try {
            $this->hold_add_on($order_id);
            $from = is_array($transition) && isset($transition['from']) && is_string($transition['from']) ? $transition['from'] : null;
            $channel = $this->channel();
            $this->last = $this->confirm((int) get_current_user_id(), $order_id, $from, $channel, EVC_Wc_Completion_Context::problem($channel, $transition));
            if ($this->last->outcome === EVC_Wc_Completion_Result::IGNORED) {
                $this->release_add_on($order_id); // not a Club order: the add-on as shipped
            }
        } catch (Throwable $e) {
            $this->last = new EVC_Wc_Completion_Result(EVC_Wc_Completion_Result::UNAVAILABLE, 'unexpected_error');
            $this->flag_after_error($order_id, $transition);
        } finally {
            $this->guard->disarm();
        }
    }

    /**
     * `woocommerce_order_status_completed` (priority 11): the add-on's own priority is over. It is
     * put back for the next order (it does not run for this one: WP_Hook never revisits a lower
     * priority), then the tripwire looks for a stray endless Club row.
     */
    public function after_add_on($order_id): void {
        $order_id = is_numeric($order_id) ? (int) $order_id : 0;
        try {
            $this->release_add_on($order_id);
            if (isset($this->club[$order_id])) {
                $customer = $this->club[$order_id];
                unset($this->club[$order_id]);
                $this->tripwire($order_id, $customer);
            }
        } catch (Throwable $e) {
            // Never aborts WooCommerce's hook run.
        }
    }

    /** `shutdown`: nothing survives the request. */
    public function forget(): void {
        $this->rest_depth = 0;
        foreach (array_keys($this->held) as $order_id) {
            $this->release_add_on((int) $order_id);
        }
        $this->held = array();
        $this->club = array();
    }

    /** `rest_request_before_callbacks`: a REST handler (and everything it triggers) is running. */
    public function rest_begin($response = null) {
        $this->rest_depth++;
        return $response;
    }

    /** `rest_request_after_callbacks`: it is over (also applied by rest_do_request(), unlike rest_post_dispatch). */
    public function rest_end($response = null) {
        $this->rest_depth = max(0, $this->rest_depth - 1);
        return $response;
    }

    /** Removes the add-on's own completion callback for this order; only on the pinned Club environment. */
    private function hold_add_on(int $order_id): void {
        if ($order_id < 1 || !function_exists(self::ADDON_FUNCTION) || has_action(self::ADDON_HOOK, self::ADDON_FUNCTION) !== self::ADDON_PRIORITY) {
            return;
        }
        // Gate: the Club only takes over on the environment it has pinned (PMPro / add-on versions, level group).
        try {
            if (EVC_Bacs_Payment_Issuer::environment_problem($this->config) !== null) {
                return;
            }
        } catch (Throwable $e) {
            // Unknown: fail closed, the add-on stays withheld (the recovery re-checks the environment).
        }
        remove_action(self::ADDON_HOOK, self::ADDON_FUNCTION, self::ADDON_PRIORITY);
        $this->held[$order_id] = true;
        $this->withheld++;
    }

    /** Puts the add-on's callback back (priority 10) when this completion removed it. */
    private function release_add_on(int $order_id): void {
        if (!isset($this->held[$order_id])) {
            return;
        }
        unset($this->held[$order_id]);
        if (has_action(self::ADDON_HOOK, self::ADDON_FUNCTION) === false && function_exists(self::ADDON_FUNCTION)) {
            add_action(self::ADDON_HOOK, self::ADDON_FUNCTION, self::ADDON_PRIORITY, 1);
        }
    }

    /** The channel of the running request. */
    private function channel(): string {
        return EVC_Wc_Completion_Context::detect($this->rest_depth, is_admin());
    }

    /** Diagnostics: how often the add-on's run was withheld from a Club-order completion. */
    public function withheld_runs(): int {
        return $this->withheld;
    }

    /**
     * After the add-on's priority: no ACTIVE Club row of this customer may be endless (PMPro inserts
     * such a row for a level without an end date). Audited, flagged for a manager, noted on the order.
     */
    private function tripwire(int $order_id, int $customer): void {
        if ($customer < 1 || $this->endless_rows($customer) < 1) {
            return;
        }
        $r = new EVC_Wc_Completion_Result(EVC_Wc_Completion_Result::MANAGER_REQUIRED, 'endless_membership_row');
        $r->paid_time_at_risk = false;
        try {
            $r->followup = $this->markers->set_followup($order_id, EVC_Wc_Completion_Markers::MANAGER_PREFIX . 'endless_membership_row') ? EVC_Wc_Completion_Markers::MANAGER_PREFIX . 'endless_membership_row' : 'followup_not_written';
        } catch (Throwable $e) {
            $r->followup = 'followup_not_written';
        }
        $this->audit_completion($r, null, $order_id, 'tripwire');
        $this->note($order_id, $r);
    }

    /** Active Club rows of the customer without an end date (0 when unreadable: the tripwire only adds, never blocks). */
    private function endless_rows(int $customer): int {
        try {
            $this->db->last_error = '';
            $n = $this->db->get_var($this->db->prepare(
                "SELECT COUNT(*) FROM {$this->db->pmpro_memberships_users} WHERE user_id = %d AND membership_id = %d AND status = 'active' AND (enddate IS NULL OR enddate = '0000-00-00 00:00:00')",
                $customer,
                $this->config->level_id
            ));
            return $this->db->last_error === '' ? (int) $n : 0;
        } catch (Throwable $e) {
            return 0;
        }
    }

    /** `woocommerce_order_status_{cancelled,refunded,failed}` (priority 5). Never throws. */
    public function on_ended($order_id): void {
        try {
            $this->last = $this->correct((int) $order_id, null);
        } catch (Throwable $e) {
            $this->last = new EVC_Wc_Completion_Result(EVC_Wc_Completion_Result::UNAVAILABLE, 'unexpected_error');
        }
    }

    /** `woocommerce_order_refunded` (after every refund, full or partial). Never throws. */
    public function on_refund($order_id, $refund_id = null): void {
        try {
            $this->last = $this->refund((int) $order_id);
        } catch (Throwable $e) {
            $this->last = new EVC_Wc_Completion_Result(EVC_Wc_Completion_Result::UNAVAILABLE, 'unexpected_error');
        }
    }

    public function last_result(): ?EVC_Wc_Completion_Result {
        return $this->last;
    }

    /**
     * The actor rule of this path (also the recovery inspector's): a real user who can edit shop
     * orders and is not the shared Club staff account. "Not the order's customer" is checked
     * separately (recovery policy: own_order).
     */
    public static function may_attest(int $actor_user_id): bool {
        return self::actor_problem($actor_user_id, 0) === null;
    }

    // ---- completion --------------------------------------------------------------------------

    /**
     * One completion of $order_id by $actor (0 = nobody). $channel / $channel_problem: where it came
     * from (EVC_Wc_Completion_Context); a completion that is not a manual admin click is not a payment
     * confirmation, whoever the user is.
     */
    public function confirm(int $actor, int $order_id, ?string $from_status, string $channel = EVC_Wc_Completion_Context::CHANNEL_ADMIN, ?string $channel_problem = null): EVC_Wc_Completion_Result {
        $order = $order_id > 0 ? wc_get_order($order_id) : false;
        if (!$order instanceof WC_Order || $order instanceof WC_Order_Refund || !$this->has_club_item($order)) {
            return new EVC_Wc_Completion_Result(EVC_Wc_Completion_Result::IGNORED, 'not_a_club_order');
        }
        $customer = (int) $order->get_customer_id();
        $this->club[$order_id] = $customer;
        $not_attested = self::actor_problem($actor, $customer) ?? $channel_problem;
        if ($not_attested !== null) {
            // No membership is issued for it (the add-on is withheld); the Club stays unverified for this order.
            $r = new EVC_Wc_Completion_Result(EVC_Wc_Completion_Result::NOT_ATTESTED, $not_attested);
            $r->addon = isset($this->held[$order_id]) ? EVC_Wc_Completion_Marker::ADDON_SUPPRESSED : EVC_Wc_Completion_Marker::ADDON_SHIPPED;
            $this->audit_completion($r, $actor > 0 ? $actor : null, $order_id, 'completion', null, $channel);
            $this->note($order_id, $r);
            return $r;
        }
        $shape = $this->order_problem($order);
        if ($shape !== null) {
            $r = new EVC_Wc_Completion_Result(EVC_Wc_Completion_Result::CONFLICT, $shape);
            $r->addon = isset($this->held[$order_id]) ? EVC_Wc_Completion_Marker::ADDON_SUPPRESSED : EVC_Wc_Completion_Marker::ADDON_SHIPPED;
            $this->audit_completion($r, $actor, $order_id, 'completion', null, $channel);
            $this->note($order_id, $r);
            return $r;
        }
        if (in_array((string) $from_status, self::ENDED, true) || $this->markers->was_ended($order_id) || (float) $order->get_total_refunded() > 0) {
            // Never re-confirmed after a cancellation / refund (fail closed, flagged).
            $r = new EVC_Wc_Completion_Result(EVC_Wc_Completion_Result::MANAGER_REQUIRED, 'completed_after_cancellation_or_refund');
            $r->addon = isset($this->held[$order_id]) ? EVC_Wc_Completion_Marker::ADDON_SUPPRESSED : EVC_Wc_Completion_Marker::ADDON_SHIPPED;
            $this->audit_completion($r, $actor, $order_id, 'completion', null, $channel);
            $this->note($order_id, $r);
            return $r;
        }
        if (!isset($this->held[$order_id])) {
            // The Club does not take over outside its pinned environment (the add-on runs as shipped).
            $r = new EVC_Wc_Completion_Result(EVC_Wc_Completion_Result::MANAGER_REQUIRED, EVC_Bacs_Payment_Issuer::environment_problem($this->config) ?? 'add_on_not_withheld');
            $r->addon = EVC_Wc_Completion_Marker::ADDON_SHIPPED;
            $this->audit_completion($r, $actor, $order_id, 'completion', null, $channel);
            $this->note($order_id, $r);
            return $r;
        }

        // Claimed: from here on this path owns the completion. The signed marker and the open
        // follow-up index are written BEFORE anything is issued, so a crash at any point after this
        // is found and finished by the recovery step (never stranded).
        $completed_at = self::whole_second(($this->now)());
        $floor = 0;
        $this->active_club_rows($customer, $floor);
        $opened = $this->open_claim($order_id, $customer, $actor, $completed_at, $floor);
        if ($opened !== null) {
            $r = new EVC_Wc_Completion_Result(EVC_Wc_Completion_Result::MANAGER_REQUIRED, $opened);
            $r->addon = EVC_Wc_Completion_Marker::ADDON_SUPPRESSED;
            $this->audit_completion($r, $actor, $order_id, 'completion', $completed_at, $channel);
            $this->note($order_id, $r);
            return $r;
        }
        $steps = array();
        try {
            $r = $this->with_member_lock($customer, function (EVC_Member_Lock $lock) use ($actor, $order, $completed_at, &$steps) {
                return $this->pipeline($lock, $actor, $order, $completed_at, null, $steps);
            }) ?? new EVC_Wc_Completion_Result(EVC_Wc_Completion_Result::UNAVAILABLE, 'member_locked');
        } catch (Throwable $e) {
            $this->guard->disarm();
            $r = new EVC_Wc_Completion_Result(EVC_Wc_Completion_Result::UNAVAILABLE, 'unexpected_error');
        }
        $r->steps = $steps;
        self::settle($r);
        $r->addon = EVC_Wc_Completion_Marker::ADDON_SUPPRESSED;
        $this->close_or_flag($r, $order_id);
        $this->audit_completion($r, $actor, $order_id, 'completion', $completed_at, $channel);
        if (!in_array($r->outcome, array(EVC_Wc_Completion_Result::RECORDED, EVC_Wc_Completion_Result::ALREADY_RECORDED), true)) {
            $this->note($order_id, $r);
        }
        return $r;
    }

    /**
     * The signed marker (once) and the open follow-up index of a claimed completion. Null when both
     * are stored (a marker of an earlier completion of the same order is kept as it is: it is
     * immutable); else why not (nothing is issued then).
     */
    private function open_claim(int $order_id, int $customer, int $actor, DateTimeImmutable $completed_at, int $floor): ?string {
        try {
            $marker = EVC_Wc_Completion_Marker::create(EVC_Bacs_Payment_Confirmation::ENVIRONMENT, $order_id, $customer, $actor, $completed_at, 'claimed', EVC_Wc_Completion_Marker::ADDON_SUPPRESSED, $floor);
            $write = $this->markers->create($marker);
            if (!in_array($write->outcome, array(EVC_Bacs_Intent_Write::WRITTEN, EVC_Bacs_Intent_Write::ALREADY, EVC_Bacs_Intent_Write::CONFLICT), true)
                || ($write->outcome === EVC_Bacs_Intent_Write::CONFLICT && strpos((string) $write->reason, 'stored_marker_invalid') === 0)) {
                return 'marker_' . $write->outcome;
            }
            $open = EVC_Wc_Completion_Markers::OPEN_PREFIX . '0';
            if (EVC_Wc_Completion_Markers::open_attempts($this->markers->followup($order_id)) === null && !$this->markers->set_followup($order_id, $open)) {
                return 'followup_not_written';
            }
            return null;
        } catch (Throwable $e) {
            return 'marker_not_written';
        }
    }

    /** The outcome is final (recorded): the follow-up index is removed; else a manager is flagged (deferred ones stay open). */
    private function close_or_flag(EVC_Wc_Completion_Result $r, int $order_id): void {
        try {
            if (in_array($r->outcome, array(EVC_Wc_Completion_Result::RECORDED, EVC_Wc_Completion_Result::ALREADY_RECORDED), true)) {
                $r->followup = $this->markers->set_followup($order_id, null) ? null : 'followup_not_written';
                return;
            }
            if ($r->outcome === EVC_Wc_Completion_Result::DEFERRED) {
                $r->followup = $this->markers->followup($order_id);
                return;
            }
            $value = EVC_Wc_Completion_Markers::MANAGER_PREFIX . self::code((string) $r->reason);
            $r->followup = $this->markers->set_followup($order_id, $value) ? $value : 'followup_not_written';
        } catch (Throwable $e) {
            $r->followup = 'followup_not_written';
        }
    }

    /**
     * An exception escaped the confirmation (nothing is shown to WooCommerce). If the completion was
     * an attested manual admin one on a Club order, the signed marker and the open index are written
     * now, so the recovery finishes it with everything re-read. Best effort; never throws.
     */
    private function flag_after_error(int $order_id, $transition): void {
        try {
            $order = $order_id > 0 ? wc_get_order($order_id) : false;
            if (!$order instanceof WC_Order || !$this->has_club_item($order)) {
                return;
            }
            $actor = (int) get_current_user_id();
            $customer = (int) $order->get_customer_id();
            if (self::actor_problem($actor, $customer) !== null || EVC_Wc_Completion_Context::problem($this->channel(), $transition) !== null
                || $this->order_problem($order) !== null || $this->markers->was_ended($order_id)) {
                return;
            }
            $floor = 0;
            $this->active_club_rows($customer, $floor);
            $this->open_claim($order_id, $customer, $actor, self::whole_second(($this->now)()), $floor);
        } catch (Throwable $e) {
            // Nothing more can be done in this request.
        }
    }

    /**
     * The recovery step for one order open for follow-up (EVC_Wc_Completion_Recovery): a deferred
     * completion is finished from its signed marker with every fact re-read (confirmation instant =
     * now), or an ended order gets its correction. Never throws.
     */
    public function recover(int $order_id): EVC_Wc_Completion_Result {
        $attempts = EVC_Wc_Completion_Markers::open_attempts($this->markers->followup($order_id));
        try {
            $r = $this->recover_or_throw($order_id);
        } catch (Throwable $e) {
            $r = new EVC_Wc_Completion_Result(EVC_Wc_Completion_Result::UNAVAILABLE, 'unexpected_error');
            self::settle($r);
            $this->next_followup($r, $order_id, (int) $attempts);
            $this->audit_completion($r, null, $order_id, 'recovery');
        } finally {
            $this->guard->disarm();
        }
        return $r;
    }

    private function recover_or_throw(int $order_id): EVC_Wc_Completion_Result {
        $attempts = EVC_Wc_Completion_Markers::open_attempts($this->markers->followup($order_id));
        if ($attempts === null) {
            return new EVC_Wc_Completion_Result(EVC_Wc_Completion_Result::IGNORED, 'not_open');
        }
        $order = wc_get_order($order_id);
        if (!$order instanceof WC_Order || $order instanceof WC_Order_Refund || !$this->has_club_item($order)) {
            $r = new EVC_Wc_Completion_Result(EVC_Wc_Completion_Result::MANAGER_REQUIRED, 'not_a_club_order');
            $this->next_followup($r, $order_id);
            return $r;
        }
        $status = (string) $order->get_status();
        if (in_array($status, self::ENDED, true)) {
            $r = $this->record_correction($order, $status === 'refunded' ? EVC_Movement_Correction::KIND_REFUNDED : EVC_Movement_Correction::KIND_VOIDED);
            $this->next_followup($r, $order_id, $attempts);
            $this->audit_correction($r, $order_id, 'recovery');
            return $r;
        }
        $customer = (int) $order->get_customer_id();
        $read = $this->markers->read($order_id);
        $steps = array();
        if ($read->outcome !== EVC_Wc_Completion_Marker_Read::VALID || $read->marker === null) {
            $r = $read->outcome === EVC_Wc_Completion_Marker_Read::UNAVAILABLE
                ? new EVC_Wc_Completion_Result(EVC_Wc_Completion_Result::UNAVAILABLE, 'marker_unreadable')
                : new EVC_Wc_Completion_Result(EVC_Wc_Completion_Result::MANAGER_REQUIRED, 'marker_' . ($read->reason ?? $read->outcome));
        } elseif ($status !== 'completed') {
            $r = new EVC_Wc_Completion_Result(EVC_Wc_Completion_Result::MANAGER_REQUIRED, 'order_not_completed');
        } else {
            $marker = $read->marker;
            $actor = $marker->actor_id();
            $r = $this->with_member_lock($customer, function (EVC_Member_Lock $lock) use ($actor, $order, $marker, &$steps) {
                return $this->pipeline($lock, $actor, $order, self::whole_second(($this->now)()), $marker, $steps);
            }) ?? new EVC_Wc_Completion_Result(EVC_Wc_Completion_Result::UNAVAILABLE, 'member_locked');
        }
        $r->steps = $steps;
        self::settle($r);
        $this->next_followup($r, $order_id, $attempts);
        $this->audit_completion($r, $read->marker !== null ? $read->marker->actor_id() : null, $order_id, 'recovery');
        if ($r->followup !== null && strpos($r->followup, EVC_Wc_Completion_Markers::MANAGER_PREFIX) === 0) {
            $this->note($order_id, $r); // a manager must look: visible on the order itself
        }
        return $r;
    }

    /**
     * Everything under ONE member lock: pre-flight, signed intent, armed issuance, re-read,
     * `issued`, record_expecting, `recorded`. $marker: null in the completion hook (PMPro WooCommerce
     * has not issued anything for this completion yet), the signed marker during recovery.
     *
     * @param string[] $steps
     */
    private function pipeline(EVC_Member_Lock $lock, int $actor, WC_Order $order, DateTimeImmutable $now, ?EVC_Wc_Completion_Marker $marker, array &$steps): EVC_Wc_Completion_Result {
        $order_id = (int) $order->get_id();
        $site = EVC_Bacs_Payment_Issuer::site_problem($this->config);
        if ($site !== null) {
            return new EVC_Wc_Completion_Result(EVC_Wc_Completion_Result::MANAGER_REQUIRED, $site);
        }
        $store = $this->store();
        if ($store === null) {
            return new EVC_Wc_Completion_Result(EVC_Wc_Completion_Result::UNAVAILABLE, 'club_unavailable');
        }
        $inspector = ($this->inspector_for)($store);
        if (!$inspector instanceof EVC_Bacs_Recovery_Inspector || !$inspector->reads($store, $this->intents)) {
            throw new LogicException('The inspector must read the same Club store and intent store.');
        }
        $recorder = self::recorder_for($store, $this->config);
        $acted = false;
        $unguarded = false;
        $risk = false;
        for ($step = 0; $step < self::MAX_STEPS; $step++) {
            if (!$lock->is_held()) {
                return new EVC_Wc_Completion_Result(EVC_Wc_Completion_Result::UNAVAILABLE, 'lock_lost');
            }
            $facts = $inspector->facts($actor, $order_id);
            $this->completion_context($facts);
            if ($facts->intent->outcome === EVC_Bacs_Intent_Read::MISSING) {
                if ($step > 0) {
                    return new EVC_Wc_Completion_Result(EVC_Wc_Completion_Result::UNAVAILABLE, 'intent_not_found_after_create');
                }
                $stop = $this->plan_intent($lock, $facts, $actor, $now, $store, $recorder);
                if ($stop !== null) {
                    return $stop;
                }
                $steps[] = 'planned';
                continue;
            }
            $decision = $this->policy->decide($facts);
            $risk = $decision->paid_time_at_risk;
            $read = $facts->intent;
            if ($read->outcome !== EVC_Bacs_Intent_Read::VALID || $read->intent === null) {
                return self::from_decision($decision);
            }
            $intent = $read->intent;
            $done = $decision->outcome === EVC_Bacs_Recovery_Decision::ALREADY_COMPLETE;
            if (!$done && !hash_equals($this->movement_id($order_id), $intent->movement_id())) {
                // An attempt of the bank-statement path is in progress for this order: never mixed.
                return $this->stopped(EVC_Wc_Completion_Result::MANAGER_REQUIRED, 'intent_from_bank_confirmation', $risk);
            }
            if ($unguarded && ($done || ($decision->outcome === EVC_Bacs_Recovery_Decision::RESUME && in_array($decision->step, array(EVC_Bacs_Recovery_Decision::STEP_RECORD_MOVEMENT, EVC_Bacs_Recovery_Decision::STEP_CONFIRM_ISSUANCE), true)))) {
                return $this->stopped(EVC_Wc_Completion_Result::MANAGER_REQUIRED, 'issuance_not_guarded_once', $risk);
            }
            if ($done) {
                if ($intent->state() === EVC_Bacs_Intent::STATE_RECORDED) {
                    return new EVC_Wc_Completion_Result(EVC_Wc_Completion_Result::ALREADY_RECORDED, null, $facts->recorded_period);
                }
                if ($intent->state() !== EVC_Bacs_Intent::STATE_ISSUED) {
                    return new EVC_Wc_Completion_Result(EVC_Wc_Completion_Result::MANAGER_REQUIRED, 'intent_not_issued');
                }
                $steps[] = 'finish_intent';
                return $this->finish_intent($lock, $intent, $facts->recorded_period, EVC_Wc_Completion_Result::ALREADY_RECORDED);
            }
            if ($decision->outcome === EVC_Bacs_Recovery_Decision::RESUME && $decision->step === EVC_Bacs_Recovery_Decision::STEP_RECORD_MOVEMENT) {
                $steps[] = 'record';
                $result = $this->record($lock, $store, $recorder, $intent, $decision);
                $result->paid_time_at_risk = $risk && !in_array($result->outcome, array(EVC_Wc_Completion_Result::RECORDED, EVC_Wc_Completion_Result::ALREADY_RECORDED), true);
                return $result;
            }
            if ($decision->outcome === EVC_Bacs_Recovery_Decision::RESUME && $decision->step === EVC_Bacs_Recovery_Decision::STEP_CONFIRM_ISSUANCE) {
                $write = $this->intents->transition($lock, $intent, $intent->issued((string) $decision->row_ref));
                if (!in_array($write->outcome, array(EVC_Bacs_Intent_Write::WRITTEN, EVC_Bacs_Intent_Write::ALREADY), true)) {
                    return $this->stopped(EVC_Wc_Completion_Result::UNAVAILABLE, 'intent_issued_' . $write->outcome, $risk);
                }
                $steps[] = 'issued';
                continue;
            }
            if ($decision->outcome === EVC_Bacs_Recovery_Decision::RESUME && $decision->step === EVC_Bacs_Recovery_Decision::STEP_COMPLETE_ORDER) {
                // This path never completes orders: the shop does. The order is no longer completed.
                return $this->stopped(EVC_Wc_Completion_Result::MANAGER_REQUIRED, 'order_not_completed', $risk);
            }
            if ($decision->outcome === EVC_Bacs_Recovery_Decision::MANAGER_REQUIRED && $decision->reason === 'membership_not_reissued'
                && $intent->state() === EVC_Bacs_Intent::STATE_PLANNED && !$acted) {
                // Issue it ourselves, armed, by calling the add-on's own function (it reads the order and
                // calls the same PMPro shim; it does not check the status). The guard is told the order.
                if (!function_exists('pmprowoo_add_membership_from_order')) {
                    return $this->stopped(EVC_Wc_Completion_Result::MANAGER_REQUIRED, 'membership_issuance_unavailable', $risk);
                }
                $acted = true;
                $steps[] = 'issue';
                $stop = $this->armed($lock, $intent, $order_id, function () use ($order_id) {
                    $this->guard->completing($order_id);
                    pmprowoo_add_membership_from_order($order_id);
                }, $unguarded);
                if ($stop !== null) {
                    return $stop;
                }
                continue;
            }
            if ($decision->outcome === EVC_Bacs_Recovery_Decision::MANAGER_REQUIRED && $decision->reason === 'issuance_mismatch'
                && $intent->state() === EVC_Bacs_Intent::STATE_PLANNED) {
                $write = $this->intents->transition($lock, $intent, $intent->issuance_mismatch());
                $steps[] = 'issuance_mismatch';
                $written = in_array($write->outcome, array(EVC_Bacs_Intent_Write::WRITTEN, EVC_Bacs_Intent_Write::ALREADY), true);
                return $this->stopped(EVC_Wc_Completion_Result::MANAGER_REQUIRED, $written ? 'issuance_mismatch' : 'issuance_mismatch_intent_' . $write->outcome, $risk);
            }
            $result = self::from_decision($decision);
            $result->paid_time_at_risk = $risk;
            return $result;
        }
        return $this->stopped(EVC_Wc_Completion_Result::MANAGER_REQUIRED, 'too_many_steps', $risk);
    }

    /**
     * What PMPro WooCommerce did for this completion, for the recovery policy: nothing. Its
     * completion callback is withheld for every claimed completion, so no row of its own ever exists
     * for it; a completed order with no row yet is issued by this path (armed).
     */
    private function completion_context(EVC_Bacs_Recovery_Facts $facts): void {
        $facts->reissue_if_missing = true;
        $facts->replaceable_row_ref = null;
    }


    /** The first, durable record of this confirmation. Null when the signed `planned` intent is stored. */
    private function plan_intent(EVC_Member_Lock $lock, EVC_Bacs_Recovery_Facts $facts, int $actor, DateTimeImmutable $now, EVC_Provenance_Store $store, EVC_Provenance_Recorder $recorder): ?EVC_Wc_Completion_Result {
        $decision = $this->policy->decide($facts);
        if ($decision->outcome !== EVC_Bacs_Recovery_Decision::MANAGER_REQUIRED) {
            return self::from_decision($decision); // refused (actor) or unavailable (order / intent unreadable)
        }
        $o = $facts->order;
        if ($o->customer_id === $actor) {
            return new EVC_Wc_Completion_Result(EVC_Wc_Completion_Result::REFUSED, 'own_order');
        }
        if ($o->payment_method !== EVC_Bacs_Recovery_Policy::PAYMENT_METHOD || $o->currency !== EVC_Bacs_Recovery_Policy::CURRENCY
            || $o->total !== EVC_Bacs_Recovery_Policy::PRICE || !$o->single_club_item) {
            return new EVC_Wc_Completion_Result(EVC_Wc_Completion_Result::CONFLICT, 'order_not_a_club_bank_transfer');
        }
        if ($o->status !== 'completed') {
            // Only «Ολοκληρωμένη» confirms (never processing, on-hold or pending).
            return new EVC_Wc_Completion_Result(EVC_Wc_Completion_Result::MANAGER_REQUIRED, 'order_not_completed');
        }
        if ($facts->pmpro_rows === null) {
            return new EVC_Wc_Completion_Result(EVC_Wc_Completion_Result::UNAVAILABLE, 'membership_unreadable');
        }
        $verified_at = self::whole_second($now);
        $movement = $this->movement_id($o->order_id);
        $anchor = $this->config->ref('wc_order', (string) $o->order_id);
        $plan = $recorder->plan(new EVC_Prospective_Payment(
            $movement,
            EVC_Bacs_Payment_Confirmation::ENVIRONMENT,
            $o->customer_id,
            $this->config->level_id,
            $verified_at,
            EVC_Bacs_Payment_Confirmation::AMOUNT_MINOR,
            EVC_Commerce_Source_Config::CURRENCY,
            array(new EVC_Movement_Alias(EVC_Movement_Alias::NS_WC_ORDER, $anchor)),
            EVC_Verified_Movement::AUTHORITY_MANUAL_ADMIN,
            EVC_Payment_Evidence::KIND_WC_ORDER
        ));
        switch ($plan->outcome()) {
            case EVC_Period_Plan::PLANNED:
                break;
            case EVC_Period_Plan::ALREADY_RECORDED:
                return new EVC_Wc_Completion_Result(EVC_Wc_Completion_Result::CONFLICT, 'movement_recorded_without_intent');
            case EVC_Period_Plan::CONFLICT:
                return new EVC_Wc_Completion_Result(EVC_Wc_Completion_Result::CONFLICT, 'plan_' . (string) $plan->reason());
            case EVC_Period_Plan::UNAVAILABLE:
                return new EVC_Wc_Completion_Result(EVC_Wc_Completion_Result::UNAVAILABLE, 'plan_unavailable');
            default:
                return new EVC_Wc_Completion_Result(EVC_Wc_Completion_Result::MANAGER_REQUIRED, 'plan_' . (string) $plan->reason());
        }
        // The add-on's own row of a deferred completion (identified from the signed marker) is not a
        // row the plan has to continue: it is replaced by the armed issuance.
        $rows = array_values(array_filter($facts->pmpro_rows, function (EVC_Pmpro_Membership_Row $r) use ($facts) {
            return $facts->replaceable_row_ref === null || $r->row_ref !== $facts->replaceable_row_ref;
        }));
        $problem = EVC_Bacs_Payment_Issuer::membership_problem($this->config, $store, $plan, $rows, $run_start, $previous_row_ref);
        if ($problem !== null) {
            if ($problem->reason === 'unexpected_membership_row' && $this->late_before_expiry($plan, $rows, $store, $verified_at)) {
                // PMPro's own expiry has not run yet for the ended paid row: retried by the recovery step.
                return new EVC_Wc_Completion_Result(EVC_Wc_Completion_Result::MANAGER_REQUIRED, 'late_renewal_before_pmpro_expiry');
            }
            return new EVC_Wc_Completion_Result($problem->outcome, $problem->reason);
        }
        try {
            $intent = EVC_Bacs_Intent::planned(EVC_Bacs_Payment_Confirmation::ENVIRONMENT, $o->order_id, $o->customer_id, $this->config->level_id, $movement, $anchor, $actor, $verified_at, $plan, $run_start, $previous_row_ref);
        } catch (InvalidArgumentException $e) {
            return new EVC_Wc_Completion_Result(EVC_Wc_Completion_Result::CONFLICT, 'intent_invalid');
        }
        $write = $this->intents->create($lock, $intent);
        if (in_array($write->outcome, array(EVC_Bacs_Intent_Write::WRITTEN, EVC_Bacs_Intent_Write::ALREADY), true)) {
            return null;
        }
        return $write->outcome === EVC_Bacs_Intent_Write::CONFLICT
            ? new EVC_Wc_Completion_Result(EVC_Wc_Completion_Result::CONFLICT, 'intent_' . (string) $write->reason)
            : new EVC_Wc_Completion_Result(EVC_Wc_Completion_Result::UNAVAILABLE, 'intent_create_' . $write->outcome);
    }

    /**
     * A LATE renewal whose only active Club row is the already ended row of the last recorded payment:
     * PMPro's own expiry (quarter-hourly) has not marked it `expired` yet.
     *
     * @param EVC_Pmpro_Membership_Row[] $rows
     */
    private function late_before_expiry(EVC_Period_Plan $plan, array $rows, EVC_Provenance_Store $store, DateTimeImmutable $verified_at): bool {
        if ($plan->kind() !== EVC_Funded_Period::KIND_LATE || $plan->predecessor_movement_id() === null) {
            return false;
        }
        $active = array_values(array_filter($rows, function (EVC_Pmpro_Membership_Row $r) {
            return $r->level_id === $this->config->level_id && $r->status === 'active';
        }));
        if (count($active) !== 1) {
            return false;
        }
        try {
            $previous = $store->period_for_movement((string) $plan->predecessor_movement_id());
        } catch (Throwable $e) {
            return false;
        }
        $end = EVC_Pmpro_Local_Time::instant($active[0]->enddate_local, EVC_Membership_Calendar::EARLIEST);
        return $previous !== null && $previous->pmpro_row_ref() === $active[0]->row_ref && $end !== null && $end <= $verified_at;
    }

    /**
     * ONE issuance action with the guard armed for the intent's order, customer, level and exact
     * dates; always disarmed in `finally` (same rules as the BACS issuer's re-issue path).
     */
    private function armed(EVC_Member_Lock $lock, EVC_Bacs_Intent $intent, int $order_id, callable $act, bool &$unguarded): ?EVC_Wc_Completion_Result {
        if ($lock->wp_user_id() !== $intent->customer_id()) {
            return new EVC_Wc_Completion_Result(EVC_Wc_Completion_Result::MANAGER_REQUIRED, 'lock_not_for_intent_customer');
        }
        if (!$lock->is_held()) {
            return new EVC_Wc_Completion_Result(EVC_Wc_Completion_Result::UNAVAILABLE, 'lock_lost');
        }
        $start = EVC_Pmpro_Local_Time::exact($intent->run_start_utc(), EVC_Membership_Calendar::LATEST);
        $end = EVC_Pmpro_Local_Time::exact($intent->period_end_utc(), EVC_Membership_Calendar::EARLIEST);
        if ($start === null || $end === null) {
            return new EVC_Wc_Completion_Result(EVC_Wc_Completion_Result::MANAGER_REQUIRED, 'period_not_representable_in_pmpro');
        }
        $environment = EVC_Bacs_Payment_Issuer::environment_problem($this->config);
        if ($environment !== null) {
            return new EVC_Wc_Completion_Result(EVC_Wc_Completion_Result::MANAGER_REQUIRED, $environment);
        }
        $this->guard->arm($order_id, $intent->customer_id(), $intent->level_id(), $start, $end);
        try {
            $act();
        } catch (Throwable $e) {
            // Outcome unknown: the fresh re-read decides, never this exception.
        } finally {
            $this->guard->disarm();
        }
        $unguarded = $this->guard->applied() !== 1;
        return null;
    }

    /** record_expecting() of the issued intent's movement, verified by a trusted read-back, then `recorded`. */
    private function record(EVC_Member_Lock $lock, EVC_Provenance_Store $store, EVC_Provenance_Recorder $recorder, EVC_Bacs_Intent $intent, EVC_Bacs_Recovery_Decision $decision): EVC_Wc_Completion_Result {
        if ($intent->state() !== EVC_Bacs_Intent::STATE_ISSUED || $decision->row_ref === null || $decision->row_ref !== $intent->new_row_ref()) {
            return new EVC_Wc_Completion_Result(EVC_Wc_Completion_Result::MANAGER_REQUIRED, 'intent_not_issued');
        }
        if (!$lock->is_held()) {
            return new EVC_Wc_Completion_Result(EVC_Wc_Completion_Result::UNAVAILABLE, 'lock_lost');
        }
        $movement = $this->movement_for($intent);
        $outcome = $recorder->record_expecting($movement, $intent->as_plan());
        switch ($outcome->outcome()) {
            case EVC_Record_Outcome::NEWLY_RECORDED:
            case EVC_Record_Outcome::ALREADY_RECORDED:
                break;
            case EVC_Record_Outcome::UNAVAILABLE:
                if ($outcome->reason() !== 'commit_outcome_unknown') {
                    return new EVC_Wc_Completion_Result(EVC_Wc_Completion_Result::UNAVAILABLE, 'club_record_unavailable');
                }
                break; // resolved by the trusted read-back below
            case EVC_Record_Outcome::CONFLICT:
                return new EVC_Wc_Completion_Result(EVC_Wc_Completion_Result::CONFLICT, 'club_' . (string) $outcome->reason());
            default:
                return new EVC_Wc_Completion_Result(EVC_Wc_Completion_Result::MANAGER_REQUIRED, 'club_' . (string) $outcome->reason());
        }
        try {
            $stored = $store->movement($movement->movement_id());
            $period = $stored === null ? null : $store->period_for_movement($movement->movement_id());
        } catch (Throwable $e) {
            return new EVC_Wc_Completion_Result(EVC_Wc_Completion_Result::UNAVAILABLE, 'club_commit_unknown');
        }
        if ($stored === null) {
            return new EVC_Wc_Completion_Result(EVC_Wc_Completion_Result::UNAVAILABLE, 'club_commit_not_found');
        }
        if ($period === null || !$stored->same_facts_as($movement) || !$intent->as_plan()->matches_recorded($period) || $period->pmpro_row_ref() !== $intent->new_row_ref()) {
            return new EVC_Wc_Completion_Result(EVC_Wc_Completion_Result::CONFLICT, 'club_read_back_differs');
        }
        return $this->finish_intent($lock, $intent, $period, $outcome->outcome() === EVC_Record_Outcome::ALREADY_RECORDED ? EVC_Wc_Completion_Result::ALREADY_RECORDED : EVC_Wc_Completion_Result::RECORDED);
    }

    private function finish_intent(EVC_Member_Lock $lock, EVC_Bacs_Intent $issued, ?EVC_Funded_Period $period, string $outcome): EVC_Wc_Completion_Result {
        $write = $this->intents->transition($lock, $issued, $issued->recorded());
        $done = in_array($write->outcome, array(EVC_Bacs_Intent_Write::WRITTEN, EVC_Bacs_Intent_Write::ALREADY), true);
        // The payment is recorded either way; a lost intent transition is finished by the next run.
        return new EVC_Wc_Completion_Result($outcome, $done ? null : 'intent_transition_' . $write->outcome, $period);
    }

    private function movement_for(EVC_Bacs_Intent $i): EVC_Verified_Movement {
        return new EVC_Verified_Movement(
            $i->movement_id(),
            $i->environment(),
            $i->customer_id(),
            $i->level_id(),
            (string) $i->new_row_ref(),
            $i->verified_at_utc(),
            EVC_Bacs_Payment_Confirmation::AMOUNT_MINOR,
            EVC_Commerce_Source_Config::CURRENCY,
            array(new EVC_Movement_Alias(EVC_Movement_Alias::NS_WC_ORDER, $i->order_anchor())),
            EVC_Verified_Movement::AUTHORITY_MANUAL_ADMIN,
            EVC_Payment_Evidence::KIND_WC_ORDER,
            EVC_Verified_Movement::STATE_CONFIRMED
        );
    }

    /** The movement id of this path: keyed HMAC of the ORDER in its own namespace. */
    public function movement_id(int $order_id): string {
        return $this->config->ref(self::MOVEMENT_NAMESPACE, (string) $order_id);
    }

    // ---- cancellation / refund (owner decision 2) -----------------------------------------------

    /**
     * A cancelled / failed (voided) or refunded order: the Club payment recorded for it gets a
     * correction, so the card is frozen until a new paid order. $kind null = from the order status.
     */
    public function correct(int $order_id, ?string $kind): EVC_Wc_Completion_Result {
        $order = $order_id > 0 ? wc_get_order($order_id) : false;
        if (!$order instanceof WC_Order || $order instanceof WC_Order_Refund || !$this->has_club_item($order)) {
            return new EVC_Wc_Completion_Result(EVC_Wc_Completion_Result::IGNORED, 'not_a_club_order');
        }
        $status = (string) $order->get_status();
        if ($kind === null) {
            if (!in_array($status, self::ENDED, true)) {
                return new EVC_Wc_Completion_Result(EVC_Wc_Completion_Result::IGNORED, 'order_not_ended');
            }
            $kind = $status === 'refunded' ? EVC_Movement_Correction::KIND_REFUNDED : EVC_Movement_Correction::KIND_VOIDED;
        }
        // A later completion of this order is never a payment confirmation.
        $this->markers->mark_ended($order_id);
        $r = $this->record_correction($order, $kind);
        if ($r->outcome === EVC_Wc_Completion_Result::DEFERRED && EVC_Wc_Completion_Markers::open_attempts($this->markers->followup($order_id)) === null) {
            $this->markers->set_followup($order_id, EVC_Wc_Completion_Markers::OPEN_PREFIX . '0');
            $r->followup = EVC_Wc_Completion_Markers::OPEN_PREFIX . '0';
        }
        $this->audit_correction($r, $order_id, $status);
        return $r;
    }

    /** `woocommerce_order_refunded`: a FULL refund is a correction; a partial one is only flagged. */
    public function refund(int $order_id): EVC_Wc_Completion_Result {
        $order = $order_id > 0 ? wc_get_order($order_id) : false;
        if (!$order instanceof WC_Order || $order instanceof WC_Order_Refund || !$this->has_club_item($order)) {
            return new EVC_Wc_Completion_Result(EVC_Wc_Completion_Result::IGNORED, 'not_a_club_order');
        }
        $remaining = (float) $order->get_total() - (float) $order->get_total_refunded();
        if ($remaining > 0.004) {
            // Partial refund: no automatic change (owner decision); flagged for a manager.
            $r = new EVC_Wc_Completion_Result(EVC_Wc_Completion_Result::MANAGER_REQUIRED, 'partial_refund');
            $this->audit_correction($r, $order_id, 'partial_refund');
            return $r;
        }
        return $this->correct($order_id, EVC_Movement_Correction::KIND_REFUNDED);
    }

    private function record_correction(WC_Order $order, string $kind): EVC_Wc_Completion_Result {
        $order_id = (int) $order->get_id();
        try {
            $r = $this->with_member_lock((int) $order->get_customer_id(), function (EVC_Member_Lock $lock) use ($order_id, $kind) {
                $store = $this->store();
                if ($store === null) {
                    return new EVC_Wc_Completion_Result(EVC_Wc_Completion_Result::UNAVAILABLE, 'club_unavailable');
                }
                $anchor = new EVC_Movement_Alias(EVC_Movement_Alias::NS_WC_ORDER, $this->config->ref('wc_order', (string) $order_id));
                $movement_id = $store->movement_for_alias($anchor->key());
                if ($movement_id === null) {
                    return new EVC_Wc_Completion_Result(EVC_Wc_Completion_Result::NOT_RECORDED, 'no_recorded_payment');
                }
                $correction = new EVC_Movement_Correction(
                    $this->config->ref(self::CORRECTION_NAMESPACE, $order_id . ':' . $kind),
                    $movement_id,
                    $kind,
                    EVC_Verified_Movement::AUTHORITY_ORDER_STATE,
                    self::whole_second(($this->now)())
                );
                $outcome = self::recorder_for($store, $this->config)->correct($correction);
                switch ($outcome->outcome()) {
                    case EVC_Record_Outcome::NEWLY_RECORDED:
                        return new EVC_Wc_Completion_Result(EVC_Wc_Completion_Result::CORRECTED, $kind);
                    case EVC_Record_Outcome::ALREADY_RECORDED:
                        return new EVC_Wc_Completion_Result(EVC_Wc_Completion_Result::ALREADY_CORRECTED, $kind);
                    case EVC_Record_Outcome::UNAVAILABLE:
                        return new EVC_Wc_Completion_Result(EVC_Wc_Completion_Result::UNAVAILABLE, 'club_record_unavailable');
                    case EVC_Record_Outcome::CONFLICT:
                        return new EVC_Wc_Completion_Result(EVC_Wc_Completion_Result::CONFLICT, 'club_' . (string) $outcome->reason());
                    default:
                        return new EVC_Wc_Completion_Result(EVC_Wc_Completion_Result::MANAGER_REQUIRED, 'club_' . (string) $outcome->reason());
                }
            }) ?? new EVC_Wc_Completion_Result(EVC_Wc_Completion_Result::UNAVAILABLE, 'member_locked');
        } catch (Throwable $e) {
            $r = new EVC_Wc_Completion_Result(EVC_Wc_Completion_Result::UNAVAILABLE, 'unexpected_error');
        }
        self::settle($r);
        return $r;
    }

    // ---- shared helpers ------------------------------------------------------------------------

    /** @return mixed|null $work's result, or null when another session holds the lock past the bounded wait */
    private function with_member_lock(int $customer_id, callable $work) {
        $lock = EVC_Member_Lock::acquire($this->db, $customer_id, $this->lock_wait);
        if ($lock === null) {
            return null;
        }
        try {
            return $work($lock);
        } finally {
            $lock->release();
        }
    }

    private function store(): ?EVC_Provenance_Store {
        if ($this->store === null) {
            try {
                $store = ($this->open_store)();
                $this->store = $store instanceof EVC_Provenance_Store ? $store : null;
            } catch (Throwable $e) {
                $this->store = null;
            }
        }
        return $this->store;
    }

    private static function recorder_for(EVC_Provenance_Store $store, EVC_Commerce_Source_Config $config): EVC_Provenance_Recorder {
        return new EVC_Provenance_Recorder(
            $store,
            new EVC_Pmpro_Mapper_Config(array($config->level_id), EVC_Commerce_Source_Config::PMPRO_VERSION, EVC_Commerce_Source_Config::PMPRO_VERSION_EXCLUSIVE_MAX),
            EVC_Bacs_Payment_Confirmation::ENVIRONMENT,
            EVC_Bacs_Payment_Confirmation::AMOUNT_MINOR,
            EVC_Commerce_Source_Config::CURRENCY
        );
    }

    /** Why $actor's completion is not a payment confirmation; null when it is. $customer 0 = not checked. */
    private static function actor_problem(int $actor, int $customer): ?string {
        $user = $actor > 0 ? get_userdata($actor) : false;
        if (!$user instanceof WP_User) {
            return 'no_user'; // REST API keys, cron, automation
        }
        if ($customer > 0 && $actor === $customer) {
            return 'customer';
        }
        if (EVC_Staff_Role::has_staff_role($user)) {
            return 'club_staff_account';
        }
        if (!user_can($user, self::CAPABILITY)) {
            return 'no_capability';
        }
        return null;
    }

    private function has_club_item(WC_Order $order): bool {
        foreach ($order->get_items('line_item') as $item) {
            if ($item instanceof WC_Order_Item_Product && (int) $item->get_product_id() === $this->config->product_id) {
                return true;
            }
        }
        return false;
    }

    /**
     * A Club bank-transfer order exactly as the reader accepts it, else why not (a reason the manager
     * can act on: a total that differs from 20.00 because of tax or fees says so).
     */
    private function order_problem(WC_Order $order): ?string {
        if ((int) $order->get_customer_id() < 1) {
            return 'order_has_no_customer';
        }
        if ((string) $order->get_payment_method() !== EVC_Commerce_Source_Config::PAYMENT_METHOD) {
            return 'order_not_bank_transfer';
        }
        if ((string) $order->get_currency() !== EVC_Commerce_Source_Config::CURRENCY) {
            return 'order_not_eur';
        }
        $items = array_values($order->get_items('line_item'));
        if (count($items) !== 1 || (int) $items[0]->get_product_id() !== $this->config->product_id || (int) $items[0]->get_quantity() !== 1) {
            return 'order_not_a_single_club_item';
        }
        if ((string) wc_format_decimal($order->get_total(), 2) !== EVC_Commerce_Source_Config::PRICE) {
            return (float) $order->get_total_tax() > 0 || (float) $order->get_total_discount() > 0 ? 'order_total_changed_by_tax_or_discount' : 'order_total_not_20_eur';
        }
        return null;
    }

    /**
     * Active Club rows of the customer, read now with a plain SELECT ($floor: the highest PMPro
     * membership row id of the customer, any level and status). 0 when unreadable (never suppress then).
     */
    private function active_club_rows(int $customer, ?int &$floor = null): int {
        $floor = 0;
        try {
            $this->db->last_error = '';
            $row = $this->db->get_row($this->db->prepare(
                "SELECT COALESCE(SUM(membership_id = %d AND status = 'active'), 0) AS active, COALESCE(MAX(id), 0) AS floor FROM {$this->db->pmpro_memberships_users} WHERE user_id = %d",
                $this->config->level_id,
                $customer
            ), ARRAY_A);
            if (!is_array($row) || $this->db->last_error !== '') {
                return 0;
            }
            $floor = (int) $row['floor'];
            return (int) $row['active'];
        } catch (Throwable $e) {
            return 0;
        }
    }


    /** Follow-up after a recovery attempt: done → removed; deferred → one more attempt (bounded); else a manager. */
    private function next_followup(EVC_Wc_Completion_Result $r, int $order_id, int $attempts = 0): void {
        try {
            if (in_array($r->outcome, array(EVC_Wc_Completion_Result::RECORDED, EVC_Wc_Completion_Result::ALREADY_RECORDED, EVC_Wc_Completion_Result::CORRECTED, EVC_Wc_Completion_Result::ALREADY_CORRECTED, EVC_Wc_Completion_Result::NOT_RECORDED), true)) {
                $value = null;
            } elseif ($r->outcome === EVC_Wc_Completion_Result::DEFERRED) {
                // A tick during an outage of a source (lock held elsewhere, Club database down) is not an attempt.
                $used = in_array((string) $r->reason, self::OUTAGE_REASONS, true) ? $attempts : $attempts + 1;
                $value = $used < self::MAX_ATTEMPTS ? EVC_Wc_Completion_Markers::OPEN_PREFIX . $used : EVC_Wc_Completion_Markers::MANAGER_PREFIX . 'attempts_exhausted';
            } else {
                $value = EVC_Wc_Completion_Markers::MANAGER_PREFIX . self::code((string) $r->reason);
            }
            $r->followup = $this->markers->set_followup($order_id, $value) ? $value : 'followup_not_written';
        } catch (Throwable $e) {
            $r->followup = 'followup_not_written';
        }
    }

    /** UNAVAILABLE (a source failed) and the self-clearing reasons are retried later: DEFERRED. */
    private static function settle(EVC_Wc_Completion_Result $r): void {
        if ($r->outcome === EVC_Wc_Completion_Result::UNAVAILABLE
            || ($r->outcome === EVC_Wc_Completion_Result::MANAGER_REQUIRED && in_array($r->reason, self::RETRY_REASONS, true))) {
            $r->outcome = EVC_Wc_Completion_Result::DEFERRED;
        }
    }

    private function stopped(string $outcome, string $reason, bool $risk): EVC_Wc_Completion_Result {
        $r = new EVC_Wc_Completion_Result($outcome, $reason);
        $r->paid_time_at_risk = $risk;
        return $r;
    }

    private static function from_decision(EVC_Bacs_Recovery_Decision $d): EVC_Wc_Completion_Result {
        $map = array(
            EVC_Bacs_Recovery_Decision::REFUSED => EVC_Wc_Completion_Result::REFUSED,
            EVC_Bacs_Recovery_Decision::CONFLICT => EVC_Wc_Completion_Result::CONFLICT,
            EVC_Bacs_Recovery_Decision::UNAVAILABLE => EVC_Wc_Completion_Result::UNAVAILABLE,
        );
        $r = new EVC_Wc_Completion_Result($map[$d->outcome] ?? EVC_Wc_Completion_Result::MANAGER_REQUIRED, $d->reason ?? $d->step);
        $r->paid_time_at_risk = $d->paid_time_at_risk;
        return $r;
    }

    private static function whole_second(DateTimeImmutable $t): DateTimeImmutable {
        return new DateTimeImmutable($t->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'), new DateTimeZone('UTC'));
    }

    /** A reason as a marker / index code: lower-case [a-z0-9_], at most 64 characters. */
    private static function code(string $reason): string {
        $code = trim((string) preg_replace('/[^a-z0-9_]+/', '_', strtolower($reason)), '_');
        $code = substr($code === '' || !preg_match('/^[a-z]/', $code) ? 'r_' . $code : $code, 0, 64);
        return rtrim($code, '_') === '' ? 'unknown' : $code;
    }

    // ---- audit (opaque references and numeric ids only; a failure never changes the result) ----

    private function audit_completion(EVC_Wc_Completion_Result $r, ?int $actor, int $order_id, string $trigger, ?DateTimeImmutable $completed_at = null, ?string $channel = null): void {
        $this->audit_event(self::EVENT, $r, $actor, array(
            'trigger' => $trigger,
            'channel' => $channel,
            'reason' => $r->reason,
            'order' => $order_id > 0 ? $this->config->ref('wc_order', (string) $order_id) : null,
            'movement' => $order_id > 0 ? $this->movement_id($order_id) : null,
            'steps' => $r->steps === array() ? null : implode(',', $r->steps),
            'addon' => $r->addon,
            'followup' => $r->followup,
            'completed_at_utc' => $completed_at ? $completed_at->format('Y-m-d H:i:s') : null,
            'paid_time_at_risk' => $r->paid_time_at_risk ? 'yes' : 'no',
            'period_end_utc' => $r->period ? $r->period->end_utc()->format('Y-m-d H:i:s') : null,
        ));
    }

    /**
     * A short private note on the order for the shop manager (Greek, reason code only: no personal
     * data, never shown to the customer). Best effort; never throws.
     */
    private function note(int $order_id, EVC_Wc_Completion_Result $r): void {
        try {
            $order = $order_id > 0 ? wc_get_order($order_id) : false;
            if ($order instanceof WC_Order && !$order instanceof WC_Order_Refund) {
                $order->add_order_note('Club: η ολοκλήρωση της παραγγελίας ΔΕΝ επιβεβαίωσε πληρωμή αυτόματα και χρειάζεται έλεγχο από υπεύθυνο (κωδικός: ' . self::code((string) $r->reason) . ').', 0, false);
            }
        } catch (Throwable $e) {
            // The audit event and the follow-up index remain.
        }
    }

    private function audit_correction(EVC_Wc_Completion_Result $r, int $order_id, string $trigger): void {
        $actor = (int) get_current_user_id();
        $this->audit_event(self::CORRECTION_EVENT, $r, $actor > 0 ? $actor : null, array(
            'trigger' => $trigger,
            'reason' => $r->reason,
            'order' => $this->config->ref('wc_order', (string) $order_id),
            'followup' => $r->followup,
        ));
    }

    /** @param array<string,string|null> $details */
    private function audit_event(string $type, EVC_Wc_Completion_Result $r, ?int $actor, array $details): void {
        if ($this->audit === null || $this->open_audit_db === null) {
            return;
        }
        try {
            $db = ($this->open_audit_db)();
            if (!$db instanceof EVC_Club_Db) {
                return;
            }
            $this->audit->record($db, array(
                'event_type' => $type,
                'outcome' => $r->outcome,
                'member_id' => null,
                'redemption_id' => null,
                'request_id' => null,
                'staff_wp_user_id' => $actor !== null && $actor > 0 ? $actor : null,
                'occurred_at_utc' => (($this->now)())->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
                'details' => array_filter($details, function ($v) {
                    return $v !== null;
                }),
            ));
            $r->audited = true;
        } catch (Throwable $e) {
            $r->audited = false;
        }
    }
}
