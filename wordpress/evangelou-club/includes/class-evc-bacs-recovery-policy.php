<?php
defined('ABSPATH') || (defined('EVC_STANDALONE_TEST') && EVC_STANDALONE_TEST) || exit;

/**
 * STAGE-03B.2: the WooCommerce order as the recovery policy sees it (read
 * by EVC_Bacs_Recovery_Inspector; plain data, no WooCommerce object).
 */
final class EVC_Bacs_Order_Facts {
    /** @var int */
    public $order_id;
    /** @var int */
    public $customer_id;
    /** @var string */
    public $status;
    /** @var string */
    public $payment_method;
    /** @var string */
    public $currency;
    /** @var string total as WooCommerce formats it, e.g. "20.00" */
    public $total;
    /** @var bool exactly one Club line item, quantity 1 */
    public $single_club_item;

    public function __construct(int $order_id, int $customer_id, string $status, string $payment_method, string $currency, string $total, bool $single_club_item) {
        $this->order_id = $order_id;
        $this->customer_id = $customer_id;
        $this->status = $status;
        $this->payment_method = $payment_method;
        $this->currency = $currency;
        $this->total = $total;
        $this->single_club_item = $single_club_item;
    }
}

/**
 * Everything recovery is allowed to look at, gathered fresh for every
 * decision. A null source means "could not be read" (fail closed).
 */
final class EVC_Bacs_Recovery_Facts {
    /** @var string */
    public $environment;
    /** @var int */
    public $level_id;
    /** @var int */
    public $actor_id;
    /** @var bool capability AND not the shared staff account (checked by the inspector) */
    public $actor_authorized;
    /** @var EVC_Bacs_Intent_Read */
    public $intent;
    /** @var EVC_Bacs_Order_Facts|null */
    public $order;
    /** @var EVC_Pmpro_Membership_Row[]|null all PMPro rows of the customer */
    public $pmpro_rows;
    /** @var bool */
    public $club_readable;
    /** @var EVC_Verified_Movement|null recorded movement for the intent's movement id */
    public $recorded_movement;
    /** @var EVC_Funded_Period|null its recorded period */
    public $recorded_period;
    /** @var EVC_Movement_Correction[] recorded corrections of that movement id */
    public $corrections;
    /** @var string|null movement id that owns the intent's order anchor, if any */
    public $order_anchor_owner;
    /** @var EVC_Period_Plan|null a FRESH plan for the intent's payment (only when not recorded) */
    public $fresh_plan;
    /**
     * STAGE-03D (WooCommerce completion by shop staff): the order is ALREADY completed by the shop
     * and PMPro WooCommerce has NOT issued a row for it (the hook runs before the add-on, or the
     * add-on's own run was suppressed and a signed completion marker says so). Only then may a
     * completed order without any active Club row be (re-)issued. Default false = unchanged rules.
     * @var bool
     */
    public $reissue_if_missing = false;
    /**
     * STAGE-03D: the ONE active Club row PMPro WooCommerce issued with its OWN dates for this
     * completion while the Club confirmation was deferred (identified by the caller from a signed
     * completion marker; never bound to a recorded payment). Only an initial / late intent may
     * replace it. Default null = unchanged rules.
     * @var string|null
     */
    public $replaceable_row_ref = null;

    /**
     * @param EVC_Pmpro_Membership_Row[]|null $pmpro_rows
     * @param EVC_Movement_Correction[]       $corrections
     */
    public function __construct(
        string $environment,
        int $level_id,
        int $actor_id,
        bool $actor_authorized,
        EVC_Bacs_Intent_Read $intent,
        ?EVC_Bacs_Order_Facts $order,
        ?array $pmpro_rows,
        bool $club_readable,
        ?EVC_Verified_Movement $recorded_movement,
        ?EVC_Funded_Period $recorded_period,
        array $corrections,
        ?string $order_anchor_owner,
        ?EVC_Period_Plan $fresh_plan
    ) {
        $this->environment = $environment;
        $this->level_id = $level_id;
        $this->actor_id = $actor_id;
        $this->actor_authorized = $actor_authorized;
        $this->intent = $intent;
        $this->order = $order;
        $this->pmpro_rows = $pmpro_rows;
        $this->club_readable = $club_readable;
        $this->recorded_movement = $recorded_movement;
        $this->recorded_period = $recorded_period;
        $this->corrections = $corrections;
        $this->order_anchor_owner = $order_anchor_owner;
        $this->fresh_plan = $fresh_plan;
    }
}

/**
 * What recovery may do next. NOTHING here is executed in STAGE-03B.2; a
 * decision grants nothing and is never shown to the customer.
 *
 *  resume            the attempt may continue with `step`:
 *                      complete_order    order still awaiting payment, nothing issued
 *                      confirm_issuance  PMPro issued `row_ref` with the plan's dates;
 *                                        intent may move planned -> issued
 *                      record_movement   intent issued; record the movement bound to `row_ref`
 *  already_complete  the Club movement, its period and the active PMPro row
 *                    all match the intent (reason "intent_state_behind" when
 *                    only the intent's own state still has to catch up)
 *  manager_required  stop; a manager must look (reason)
 *  conflict          the facts contradict each other or the intent (reason)
 *  unavailable       a source could not be read; retry later (reason)
 *  refused           the actor may not do this at all
 * `paid_time_at_risk`: an EARLY renewal whose already paid membership row is
 * no longer active while the new payment is not yet recorded — the member is
 * shown "unverified" right now (STAGE-03B.1 finding; see docs).
 */
final class EVC_Bacs_Recovery_Decision {
    const RESUME = 'resume';
    const ALREADY_COMPLETE = 'already_complete';
    const MANAGER_REQUIRED = 'manager_required';
    const CONFLICT = 'conflict';
    const UNAVAILABLE = 'unavailable';
    const REFUSED = 'refused';

    const STEP_COMPLETE_ORDER = 'complete_order';
    const STEP_CONFIRM_ISSUANCE = 'confirm_issuance';
    const STEP_RECORD_MOVEMENT = 'record_movement';

    /** @var string */
    public $outcome;
    /** @var string|null */
    public $reason;
    /** @var string|null */
    public $step;
    /** @var string|null */
    public $row_ref;
    /** @var bool */
    public $paid_time_at_risk;

    private function __construct(string $outcome, ?string $reason, ?string $step, ?string $row_ref, bool $paid_time_at_risk) {
        $this->outcome = $outcome;
        $this->reason = $reason;
        $this->step = $step;
        $this->row_ref = $row_ref;
        $this->paid_time_at_risk = $paid_time_at_risk;
    }

    public static function resume(string $step, ?string $row_ref, bool $paid_time_at_risk = false): self {
        return new self(self::RESUME, null, $step, $row_ref, $paid_time_at_risk);
    }

    public static function already_complete(?string $reason = null): self {
        return new self(self::ALREADY_COMPLETE, $reason, null, null, false);
    }

    public static function manager(string $reason, bool $paid_time_at_risk = false): self {
        return new self(self::MANAGER_REQUIRED, $reason, null, null, $paid_time_at_risk);
    }

    public static function conflict(string $reason, bool $paid_time_at_risk = false): self {
        return new self(self::CONFLICT, $reason, null, null, $paid_time_at_risk);
    }

    public static function unavailable(string $reason): self {
        return new self(self::UNAVAILABLE, $reason, null, null, false);
    }

    public static function refused(string $reason): self {
        return new self(self::REFUSED, $reason, null, null, false);
    }
}

/**
 * STAGE-03B.2: deterministic recovery decision for a durable BACS intent.
 * Pure: no clock, no I/O. Never trusts the intent's own state: it is
 * re-derived from the order, PMPro and the Club record every time. A signed
 * intent or a completed WooCommerce order is never payment evidence; the
 * only "done" is a recorded Club movement + period that equal the intent
 * AND an active PMPro row bound to it with exactly the planned dates.
 */
final class EVC_Bacs_Recovery_Policy {
    const PRICE = '20.00';
    const CURRENCY = 'EUR';
    const PAYMENT_METHOD = 'bacs';
    const AMOUNT_MINOR = 2000;

    /**
     * STAGE-03D: `processing` is awaiting payment here too (same as EVC_Wp_Commerce_Pmpro_Reader).
     * Awaiting is not a confirmation: EVC_Bacs_Payment_Issuer still completes only pending /
     * on-hold orders, and a completion by shop staff counts only as `completed`.
     */
    const AWAITING = array('pending', 'on-hold', 'processing');
    const ENDED = array('cancelled', 'refunded', 'failed');

    public function decide(EVC_Bacs_Recovery_Facts $f): EVC_Bacs_Recovery_Decision {
        if (!$f->actor_authorized) {
            return EVC_Bacs_Recovery_Decision::refused('actor_not_authorized');
        }
        if ($f->intent->outcome === EVC_Bacs_Intent_Read::UNAVAILABLE) {
            return EVC_Bacs_Recovery_Decision::unavailable('intent_unreadable');
        }
        if ($f->order === null) {
            return EVC_Bacs_Recovery_Decision::unavailable('order_unreadable');
        }
        if ($f->intent->outcome === EVC_Bacs_Intent_Read::MISSING) {
            // Nothing to resume. A completed order without an intent is never treated as paid.
            return EVC_Bacs_Recovery_Decision::manager($f->order->status === 'completed' ? 'completed_order_without_intent' : 'no_intent');
        }
        if ($f->intent->outcome !== EVC_Bacs_Intent_Read::VALID || $f->intent->intent === null) {
            return EVC_Bacs_Recovery_Decision::conflict('intent_' . (string) $f->intent->reason);
        }
        if ($f->pmpro_rows === null) {
            return EVC_Bacs_Recovery_Decision::unavailable('membership_unreadable');
        }
        if (!$f->club_readable) {
            return EVC_Bacs_Recovery_Decision::unavailable('club_record_unreadable');
        }
        $i = $f->intent->intent;
        $o = $f->order;

        if ($i->environment() !== $f->environment || $i->level_id() !== $f->level_id
            || $i->order_id() !== $o->order_id || $i->customer_id() !== $o->customer_id) {
            return EVC_Bacs_Recovery_Decision::conflict('intent_binding_mismatch');
        }
        if ($f->actor_id === $o->customer_id) {
            return EVC_Bacs_Recovery_Decision::refused('own_order');
        }
        if ($o->payment_method !== self::PAYMENT_METHOD || $o->currency !== self::CURRENCY || $o->total !== self::PRICE || !$o->single_club_item) {
            return EVC_Bacs_Recovery_Decision::conflict('order_changed');
        }
        if (in_array($o->status, self::ENDED, true)) {
            return EVC_Bacs_Recovery_Decision::manager('order_ended');
        }
        $awaiting = in_array($o->status, self::AWAITING, true);
        if (!$awaiting && $o->status !== 'completed') {
            return EVC_Bacs_Recovery_Decision::conflict('order_status_unknown');
        }
        if ($f->order_anchor_owner !== null && $f->order_anchor_owner !== $i->movement_id()) {
            return EVC_Bacs_Recovery_Decision::conflict('order_funded_by_another_transfer');
        }

        $active = $this->active_club_rows($f->pmpro_rows, $f->level_id);
        $previous_active = $i->previous_row_ref() !== null && isset($active[$i->previous_row_ref()]);
        $at_risk = $i->period_kind() === EVC_Funded_Period::KIND_EARLY && !$previous_active;

        if ($f->recorded_movement !== null) {
            return $this->recorded($f, $i, $o, $active);
        }
        if ($i->state() === EVC_Bacs_Intent::STATE_RECORDED) {
            return EVC_Bacs_Recovery_Decision::conflict('intent_claims_recorded_without_provenance', $at_risk);
        }
        if (in_array($i->state(), array(EVC_Bacs_Intent::STATE_ISSUANCE_MISMATCH, EVC_Bacs_Intent::STATE_RECOVERY_REQUIRED), true)) {
            return EVC_Bacs_Recovery_Decision::manager('intent_' . $i->state(), $at_risk);
        }
        if ($f->corrections !== array()) {
            return EVC_Bacs_Recovery_Decision::manager('correction_recorded_for_transfer', $at_risk);
        }

        // Not recorded yet: the plan must STILL be exactly what the intent fixed.
        $plan = $f->fresh_plan;
        if ($plan === null || $plan->outcome() === EVC_Period_Plan::UNAVAILABLE) {
            return EVC_Bacs_Recovery_Decision::unavailable('plan_unavailable');
        }
        if ($plan->outcome() === EVC_Period_Plan::CONFLICT) {
            return EVC_Bacs_Recovery_Decision::conflict('plan_' . (string) $plan->reason(), $at_risk);
        }
        if ($plan->outcome() !== EVC_Period_Plan::PLANNED) {
            return EVC_Bacs_Recovery_Decision::manager('plan_' . (string) ($plan->reason() ?? $plan->outcome()), $at_risk);
        }
        if (!self::same_period($plan, $i)) {
            return EVC_Bacs_Recovery_Decision::manager('plan_stale', $at_risk);
        }

        if ($awaiting) {
            if ($i->state() !== EVC_Bacs_Intent::STATE_PLANNED) {
                return EVC_Bacs_Recovery_Decision::conflict('intent_issued_but_order_not_completed', $at_risk);
            }
            $expected = $i->previous_row_ref() === null ? array() : array($i->previous_row_ref());
            if (array_keys($active) !== $expected) {
                // An early renewal needs its paid row still active; anything else is unexpected.
                return $at_risk
                    ? EVC_Bacs_Recovery_Decision::manager('paid_membership_row_missing', true)
                    : EVC_Bacs_Recovery_Decision::conflict('unexpected_membership_row');
            }
            return EVC_Bacs_Recovery_Decision::resume(EVC_Bacs_Recovery_Decision::STEP_COMPLETE_ORDER, null);
        }

        // Completed order, nothing recorded: PMPro must show exactly the plan on a NEW row.
        if (count($active) === 0) {
            if ($f->reissue_if_missing && $i->previous_row_ref() === null && $i->state() === EVC_Bacs_Intent::STATE_PLANNED) {
                // STAGE-03D: completed by the shop, nothing issued for it yet: issue it (armed).
                return EVC_Bacs_Recovery_Decision::manager('membership_not_reissued', $at_risk);
            }
            return EVC_Bacs_Recovery_Decision::manager('membership_row_missing', $at_risk);
        }
        if (count($active) > 1) {
            return EVC_Bacs_Recovery_Decision::conflict('multiple_active_membership_rows', $at_risk);
        }
        $ref = array_keys($active)[0];
        $row = $active[$ref];
        if ($ref === $i->previous_row_ref()) {
            return EVC_Bacs_Recovery_Decision::manager('membership_not_reissued', $at_risk);
        }
        if ($f->replaceable_row_ref !== null && $ref === $f->replaceable_row_ref && $i->previous_row_ref() === null && $i->state() === EVC_Bacs_Intent::STATE_PLANNED) {
            // STAGE-03D: the add-on's own (unverified) row of a deferred completion: re-issue it, armed.
            return EVC_Bacs_Recovery_Decision::manager('membership_not_reissued', $at_risk);
        }
        if (!$this->row_matches($row, $i->run_start_utc(), $i->period_end_utc())) {
            return EVC_Bacs_Recovery_Decision::manager('issuance_mismatch', $at_risk);
        }
        if ($i->state() === EVC_Bacs_Intent::STATE_ISSUED) {
            if ($i->new_row_ref() !== $ref) {
                return EVC_Bacs_Recovery_Decision::conflict('issued_row_changed', $at_risk);
            }
            return EVC_Bacs_Recovery_Decision::resume(EVC_Bacs_Recovery_Decision::STEP_RECORD_MOVEMENT, $ref, $at_risk);
        }
        return EVC_Bacs_Recovery_Decision::resume(EVC_Bacs_Recovery_Decision::STEP_CONFIRM_ISSUANCE, $ref, $at_risk);
    }

    /** @param array<string,EVC_Pmpro_Membership_Row> $active */
    private function recorded(EVC_Bacs_Recovery_Facts $f, EVC_Bacs_Intent $i, EVC_Bacs_Order_Facts $o, array $active): EVC_Bacs_Recovery_Decision {
        $m = $f->recorded_movement;
        $anchors = $m->order_anchors();
        if ($m->movement_id() !== $i->movement_id() || $m->wp_user_id() !== $i->customer_id() || $m->level_id() !== $i->level_id()
            || $m->environment() !== $i->environment() || $m->verified_at_utc() != $i->verified_at_utc()
            || $m->amount_minor() !== self::AMOUNT_MINOR || $m->currency() !== self::CURRENCY
            || $m->state() !== EVC_Verified_Movement::STATE_CONFIRMED
            || count($anchors) !== 1 || $anchors[0]->namespace_name() !== EVC_Movement_Alias::NS_WC_ORDER || $anchors[0]->ref() !== $i->order_anchor()) {
            return EVC_Bacs_Recovery_Decision::conflict('recorded_movement_differs');
        }
        $p = $f->recorded_period;
        if ($p === null || $p->pmpro_row_ref() !== $m->pmpro_row_ref() || !$i->as_plan()->matches_recorded($p)) {
            return EVC_Bacs_Recovery_Decision::conflict('recorded_period_differs');
        }
        if ($i->new_row_ref() !== null && $i->new_row_ref() !== $m->pmpro_row_ref()) {
            return EVC_Bacs_Recovery_Decision::conflict('recorded_row_differs_from_intent');
        }
        if ($f->corrections !== array()) {
            return EVC_Bacs_Recovery_Decision::manager('recorded_payment_corrected');
        }
        if ($o->status !== 'completed') {
            return EVC_Bacs_Recovery_Decision::conflict('recorded_but_order_not_completed');
        }
        if (array_keys($active) !== array($m->pmpro_row_ref()) || !$this->row_matches($active[$m->pmpro_row_ref()], $i->run_start_utc(), $i->period_end_utc())) {
            return EVC_Bacs_Recovery_Decision::manager('recorded_but_membership_differs');
        }
        return EVC_Bacs_Recovery_Decision::already_complete($i->state() === EVC_Bacs_Intent::STATE_RECORDED ? null : 'intent_state_behind');
    }

    /**
     * @param EVC_Pmpro_Membership_Row[] $rows
     * @return array<string,EVC_Pmpro_Membership_Row> active rows of the Club level, by ref, sorted
     */
    private function active_club_rows(array $rows, int $level_id): array {
        $out = array();
        foreach ($rows as $r) {
            if ($r->level_id === $level_id && $r->status === 'active') {
                $out[$r->row_ref] = $r;
            }
        }
        ksort($out);
        return $out;
    }

    /** The PMPro row shows EXACTLY the paid run start and the planned end (same resolution as the mapper). */
    private function row_matches(EVC_Pmpro_Membership_Row $row, DateTimeImmutable $run_start, DateTimeImmutable $end): bool {
        $start = self::local($row->startdate_local, EVC_Membership_Calendar::LATEST);
        $finish = self::local($row->enddate_local, EVC_Membership_Calendar::EARLIEST);
        return $start !== null && $finish !== null && $start == $run_start && $finish == $end;
    }

    private static function same_period(EVC_Period_Plan $plan, EVC_Bacs_Intent $i): bool {
        return $plan->movement_id() === $i->movement_id()
            && $plan->start_utc() == $i->period_start_utc()
            && $plan->end_utc() == $i->period_end_utc()
            && $plan->kind() === $i->period_kind()
            && $plan->predecessor_movement_id() === $i->predecessor_movement_id();
    }

    private static function local(?string $value, string $mode): ?DateTimeImmutable {
        if ($value === null || !preg_match('/^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})$/D', $value, $m) || (int) $m[1] < 2000) {
            return null;
        }
        try {
            return EVC_Membership_Calendar::resolve_local_time((int) $m[1], (int) $m[2], (int) $m[3], (int) $m[4], (int) $m[5], (int) $m[6], 0, $mode)['instant'];
        } catch (Throwable $e) {
            return null;
        }
    }
}
