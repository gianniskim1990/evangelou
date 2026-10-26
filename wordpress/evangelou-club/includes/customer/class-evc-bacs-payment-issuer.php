<?php
defined('ABSPATH') || exit;

/** Result of one issuance attempt (internal; never shown to customers). */
final class EVC_Bacs_Issuance_Result {
    /** the Club recorded the movement now (the member is paid through the period) */
    const RECORDED = 'recorded';
    /** the movement was already recorded with exactly the signed period */
    const ALREADY_RECORDED = 'already_recorded';
    const REFUSED = 'refused';
    const CONFLICT = 'conflict';
    const MANAGER_REQUIRED = 'manager_required';
    /** a source failed or an outcome is still unknown: retry later */
    const UNAVAILABLE = 'unavailable';
    /** another session holds the member lock: retry later */
    const BUSY = 'busy';

    /** @var string */
    public $outcome;
    /** @var string|null internal reason code (no personal or bank data) */
    public $reason;
    /** @var EVC_Funded_Period|null */
    public $period;
    /** @var bool the signed intent is now "recorded" */
    public $intent_finalized;
    /** @var bool an early renewal whose paid row is no longer active while the new payment is unrecorded */
    public $paid_time_at_risk = false;
    /** @var string[] steps taken in this request (planned, complete_order, reissue, issued, issuance_mismatch, finalize) */
    public $steps = array();
    /** @var bool */
    public $audited = false;

    public function __construct(string $outcome, ?string $reason = null, ?EVC_Funded_Period $period = null, bool $intent_finalized = false) {
        $this->outcome = $outcome;
        $this->reason = $reason;
        $this->period = $period;
        $this->intent_finalized = $intent_finalized;
    }
}

/**
 * STAGE-03B.3b (owner decision 2026-10-26, Option 2): the INTERNAL BACS
 * issuance orchestrator. One call = ONE manager attestation, handled under
 * ONE member lock held for the whole request:
 *
 *   authorise + validate the attestation (shared with the finalizer)
 *   → [no intent yet] fresh facts, fresh plan → signed `planned` intent
 *   → armed EVC_Bacs_Issuance_Guard → $order->update_status('completed')
 *     (PMPro WooCommerce issues the row with the plan's dates; the paid row
 *     stays active until the new row exists) → guard disarmed in `finally`
 *   → FRESH re-read (intent, order, PMPro rows, Club record) → recovery policy
 *     → only `confirm_issuance` moves the intent planned → issued (row ref
 *     from the re-read, compare-and-set); `issuance_mismatch` → intent
 *     issuance_mismatch (manager)
 *   → the existing finalization step (record_expecting + issued → recorded),
 *     run by EVC_Bacs_Payment_Finalizer::finalize_holding() inside the SAME
 *     lock.
 * Resume: with an intent already stored, every step follows the recovery
 * policy on freshly read facts (complete_order / confirm_issuance /
 * record_movement / already_complete), plus one re-issue path: a completed
 * order whose PMPro row was NOT re-issued (`membership_not_reissued`, the
 * add-on does not fire again on an already completed order) is re-issued by
 * calling the add-on's own pmprowoo_add_membership_from_order() under the
 * armed guard. At most ONE issuance action (completion or re-issue) per
 * request; anything else, or anything ambiguous, stops for a manager.
 * Upstream return values are never trusted: only the re-read decides.
 *
 * Every attempt is audited in evc_audit_events with opaque references only.
 * Unreachable: no route, hook, cron, page or role; nothing in the plugin
 * constructs it or its guard (the packager forbids constructing either).
 */
final class EVC_Bacs_Payment_Issuer {
    const EVENT = 'bacs_payment_issuance';
    /**
     * STAGE-03D: the order statuses this issuer completes. `processing` counts as awaiting payment
     * in the recovery policy (like the reader), but the issuer still never treats it as a state it
     * may confirm and complete (unchanged behaviour: order_status_unknown).
     */
    const AWAITING = array('pending', 'on-hold');
    /** create, complete, confirm, finalize (+ slack); a loop never runs unbounded */
    const MAX_STEPS = 6;

    /** @var EVC_Commerce_Source_Config */
    private $config;
    /** @var EVC_Provenance_Store */
    private $store;
    /** @var EVC_Wc_Bacs_Intent_Store */
    private $intents;
    /** @var EVC_Bacs_Recovery_Inspector */
    private $inspector;
    /** @var EVC_Bacs_Payment_Finalizer */
    private $finalizer;
    /** @var EVC_Bacs_Issuance_Guard */
    private $guard;
    /** @var callable(): DateTimeImmutable */
    private $now;
    /** @var EVC_Audit_Log|null */
    private $audit;
    /** @var EVC_Club_Db|null */
    private $audit_db;
    /** @var EVC_Provenance_Recorder */
    private $recorder;
    /** @var EVC_Bacs_Recovery_Policy */
    private $policy;

    public function __construct(
        EVC_Commerce_Source_Config $config,
        EVC_Provenance_Store $store,
        EVC_Wc_Bacs_Intent_Store $intents,
        EVC_Bacs_Recovery_Inspector $inspector,
        EVC_Bacs_Payment_Finalizer $finalizer,
        EVC_Bacs_Issuance_Guard $guard,
        callable $now,
        ?EVC_Audit_Log $audit = null,
        ?EVC_Club_Db $audit_db = null
    ) {
        if (!$inspector->reads($store, $intents) || !$finalizer->uses($store, $intents, $inspector)) {
            throw new InvalidArgumentException('The issuer, the recovery inspector and the finalizer must use the same Club store and intent store.');
        }
        $this->config = $config;
        $this->store = $store;
        $this->intents = $intents;
        $this->inspector = $inspector;
        $this->finalizer = $finalizer;
        $this->guard = $guard;
        $this->now = $now;
        $this->audit = $audit;
        $this->audit_db = $audit_db;
        $this->recorder = new EVC_Provenance_Recorder(
            $store,
            new EVC_Pmpro_Mapper_Config(array($config->level_id), EVC_Commerce_Source_Config::PMPRO_VERSION, EVC_Commerce_Source_Config::PMPRO_VERSION_EXCLUSIVE_MAX),
            EVC_Bacs_Payment_Confirmation::ENVIRONMENT,
            EVC_Bacs_Payment_Confirmation::AMOUNT_MINOR,
            EVC_Commerce_Source_Config::CURRENCY
        );
        $this->policy = new EVC_Bacs_Recovery_Policy();
    }

    /**
     * @param array{bank_reference?:mixed,amount_minor?:mixed,currency?:mixed,received_on?:mixed} $attestation
     *        the manager's reading of the bank statement for THIS transfer
     */
    public function issue(int $actor_user_id, int $order_id, array $attestation): EVC_Bacs_Issuance_Result {
        $order_ref = $order_id > 0 ? $this->config->ref('wc_order', (string) $order_id) : null;
        $steps = array();
        $movement_id = null;
        try {
            $result = $this->decide($actor_user_id, $order_id, $attestation, $movement_id, $steps);
        } catch (Throwable $e) {
            // STAGE-03B.3b review (L2): anything unexpected fails closed and is still audited
            // (opaque references only; the exception and its message are never stored).
            $this->guard->disarm();
            $result = new EVC_Bacs_Issuance_Result(EVC_Bacs_Issuance_Result::UNAVAILABLE, 'unexpected_error');
        }
        $result->steps = $steps;
        $this->audit_attempt($actor_user_id, $result, $movement_id, $order_ref);
        return $result;
    }

    /** @param string[] $steps */
    private function decide(int $actor_user_id, int $order_id, array $attestation, ?string &$movement_id, array &$steps): EVC_Bacs_Issuance_Result {
        $refusal = $this->finalizer->admit($actor_user_id, $order_id, $attestation, $attested_movement, $received_on, $order);
        if ($refusal !== null) {
            return self::from_finalization($refusal);
        }
        $result = $this->finalizer->with_member_lock((int) $order->get_customer_id(), function (EVC_Member_Lock $lock) use ($actor_user_id, $order, $attested_movement, $received_on, &$movement_id, &$steps) {
            return $this->under_lock($lock, $actor_user_id, $order, $attested_movement, $received_on, $movement_id, $steps);
        });
        return $result ?? new EVC_Bacs_Issuance_Result(EVC_Bacs_Issuance_Result::BUSY, 'member_locked');
    }

    /** @param string[] $steps */
    private function under_lock(EVC_Member_Lock $lock, int $actor_user_id, WC_Order $order, string $attested_movement, string $received_on, ?string &$movement_id, array &$steps): EVC_Bacs_Issuance_Result {
        $order_id = (int) $order->get_id();
        $site = self::site_problem($this->config);
        if ($site !== null) {
            return new EVC_Bacs_Issuance_Result(EVC_Bacs_Issuance_Result::MANAGER_REQUIRED, $site);
        }
        $acted = false;
        $risk = false;
        // The last issuance action did not replace the dates exactly once (see armed()).
        $unguarded = false;
        for ($step = 0; $step < self::MAX_STEPS; $step++) {
            if (!$lock->is_held()) {
                return new EVC_Bacs_Issuance_Result(EVC_Bacs_Issuance_Result::UNAVAILABLE, 'lock_lost');
            }
            // Every step starts from facts read NOW: signed intent, actor, order, PMPro rows, Club record.
            $facts = $this->inspector->facts($actor_user_id, $order_id);
            if ($facts->intent->outcome === EVC_Bacs_Intent_Read::MISSING) {
                if ($step > 0) {
                    return new EVC_Bacs_Issuance_Result(EVC_Bacs_Issuance_Result::UNAVAILABLE, 'intent_not_found_after_create');
                }
                $stop = $this->plan_intent($lock, $facts, $actor_user_id, $order, $attested_movement, $received_on);
                if ($stop !== null) {
                    return $stop;
                }
                $movement_id = $attested_movement;
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
            $movement_id = $intent->movement_id();
            // The manager's statement must describe exactly the transfer this attempt is for.
            if (!hash_equals($intent->movement_id(), $attested_movement)) {
                return new EVC_Bacs_Issuance_Result(EVC_Bacs_Issuance_Result::REFUSED, 'attestation_does_not_match_intent');
            }
            $window = $this->finalizer->value_date_problem($order, $intent->verified_at_utc(), $received_on);
            if ($window !== null) {
                return new EVC_Bacs_Issuance_Result(EVC_Bacs_Issuance_Result::REFUSED, $window);
            }

            if ($unguarded && ($decision->outcome === EVC_Bacs_Recovery_Decision::ALREADY_COMPLETE
                || ($decision->outcome === EVC_Bacs_Recovery_Decision::RESUME && in_array($decision->step, array(EVC_Bacs_Recovery_Decision::STEP_RECORD_MOVEMENT, EVC_Bacs_Recovery_Decision::STEP_CONFIRM_ISSUANCE), true)))) {
                // The re-read was done, but this request's issuance was not exactly the armed one
                // (dates replaced never or more than once): never confirm or record it here.
                return $this->stopped(EVC_Bacs_Issuance_Result::MANAGER_REQUIRED, 'issuance_not_guarded_once', $risk);
            }
            if ($decision->outcome === EVC_Bacs_Recovery_Decision::ALREADY_COMPLETE
                || ($decision->outcome === EVC_Bacs_Recovery_Decision::RESUME && $decision->step === EVC_Bacs_Recovery_Decision::STEP_RECORD_MOVEMENT)) {
                // The existing finalization step, inside THIS request's lock (it never takes the lock itself).
                $steps[] = 'finalize';
                $done = $this->finalizer->finalize_holding($lock, $actor_user_id, $order, $attested_movement, $received_on, $finalized_movement);
                $result = self::from_finalization($done);
                $result->paid_time_at_risk = $risk && !in_array($done->outcome, array(EVC_Bacs_Finalization_Result::RECORDED, EVC_Bacs_Finalization_Result::ALREADY_RECORDED), true);
                return $result;
            }
            if ($decision->outcome === EVC_Bacs_Recovery_Decision::RESUME && $decision->step === EVC_Bacs_Recovery_Decision::STEP_CONFIRM_ISSUANCE) {
                // The re-read shows PMPro's NEW row with exactly the planned dates: bind the intent to it.
                $write = $this->intents->transition($lock, $intent, $intent->issued((string) $decision->row_ref));
                if (!in_array($write->outcome, array(EVC_Bacs_Intent_Write::WRITTEN, EVC_Bacs_Intent_Write::ALREADY), true)) {
                    return $this->stopped(EVC_Bacs_Issuance_Result::UNAVAILABLE, 'intent_issued_' . $write->outcome, $risk);
                }
                $steps[] = 'issued';
                continue;
            }
            if ($decision->outcome === EVC_Bacs_Recovery_Decision::RESUME && $decision->step === EVC_Bacs_Recovery_Decision::STEP_COMPLETE_ORDER) {
                if ($facts->order === null || !in_array($facts->order->status, self::AWAITING, true)) {
                    // STAGE-03D: the policy now treats `processing` as awaiting; this issuer does not complete it.
                    return $this->stopped(EVC_Bacs_Issuance_Result::CONFLICT, 'order_status_unknown', $risk);
                }
                if ($acted) {
                    return $this->stopped(EVC_Bacs_Issuance_Result::MANAGER_REQUIRED, 'order_not_completed', $risk);
                }
                $acted = true;
                $steps[] = 'complete_order';
                $stop = $this->armed($lock, $intent, $order_id, function () use ($order_id) {
                    $current = wc_get_order($order_id);
                    if ($current instanceof WC_Order) {
                        $current->update_status('completed');
                    }
                }, $unguarded);
                if ($stop !== null) {
                    return $stop;
                }
                continue;
            }
            if ($decision->outcome === EVC_Bacs_Recovery_Decision::MANAGER_REQUIRED && $decision->reason === 'membership_not_reissued'
                && $intent->state() === EVC_Bacs_Intent::STATE_PLANNED && !$acted) {
                // Completed order, the already paid row still the only active one: the add-on never
                // issued (it does not fire again on a completed order). Issue it explicitly, armed.
                if (!function_exists('pmprowoo_add_membership_from_order')) {
                    return $this->stopped(EVC_Bacs_Issuance_Result::MANAGER_REQUIRED, 'membership_issuance_unavailable', $risk);
                }
                $acted = true;
                $steps[] = 'reissue';
                $stop = $this->armed($lock, $intent, $order_id, function () use ($order_id) {
                    // Called directly (no WooCommerce status change): name the order ourselves.
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
                return $this->stopped(EVC_Bacs_Issuance_Result::MANAGER_REQUIRED, $written ? 'issuance_mismatch' : 'issuance_mismatch_intent_' . $write->outcome, $risk);
            }
            $result = self::from_decision($decision);
            $result->paid_time_at_risk = $risk;
            return $result;
        }
        return $this->stopped(EVC_Bacs_Issuance_Result::MANAGER_REQUIRED, 'too_many_steps', $risk);
    }

    /**
     * The first, durable record of this attempt (nothing is completed or issued yet). Returns
     * null when the signed `planned` intent is stored (read back), else why it was not.
     */
    private function plan_intent(EVC_Member_Lock $lock, EVC_Bacs_Recovery_Facts $facts, int $actor_user_id, WC_Order $order, string $attested_movement, string $received_on): ?EVC_Bacs_Issuance_Result {
        $decision = $this->policy->decide($facts);
        if ($decision->outcome !== EVC_Bacs_Recovery_Decision::MANAGER_REQUIRED) {
            // refused (actor) or unavailable (order / intent unreadable)
            return self::from_decision($decision);
        }
        $o = $facts->order;
        if ($o->customer_id === $actor_user_id) {
            return new EVC_Bacs_Issuance_Result(EVC_Bacs_Issuance_Result::REFUSED, 'own_order');
        }
        if ($o->payment_method !== EVC_Bacs_Recovery_Policy::PAYMENT_METHOD || $o->currency !== EVC_Bacs_Recovery_Policy::CURRENCY
            || $o->total !== EVC_Bacs_Recovery_Policy::PRICE || !$o->single_club_item) {
            return new EVC_Bacs_Issuance_Result(EVC_Bacs_Issuance_Result::CONFLICT, 'order_not_a_club_bank_transfer');
        }
        if (in_array($o->status, EVC_Bacs_Recovery_Policy::ENDED, true)) {
            return new EVC_Bacs_Issuance_Result(EVC_Bacs_Issuance_Result::MANAGER_REQUIRED, 'order_ended');
        }
        if (!in_array($o->status, self::AWAITING, true)) {
            // A completed order without an intent is never treated as paid; any other status is unknown.
            return $o->status === 'completed'
                ? new EVC_Bacs_Issuance_Result(EVC_Bacs_Issuance_Result::MANAGER_REQUIRED, 'completed_order_without_intent')
                : new EVC_Bacs_Issuance_Result(EVC_Bacs_Issuance_Result::CONFLICT, 'order_status_unknown');
        }
        if ($facts->pmpro_rows === null) {
            return new EVC_Bacs_Issuance_Result(EVC_Bacs_Issuance_Result::UNAVAILABLE, 'membership_unreadable');
        }

        $verified_at = self::whole_second(($this->now)());
        $window = $this->finalizer->value_date_problem($order, $verified_at, $received_on);
        if ($window !== null) {
            return new EVC_Bacs_Issuance_Result(EVC_Bacs_Issuance_Result::REFUSED, $window);
        }
        $anchor = $this->config->ref('wc_order', (string) $o->order_id);
        $plan = $this->recorder->plan(new EVC_Prospective_Payment(
            $attested_movement,
            EVC_Bacs_Payment_Confirmation::ENVIRONMENT,
            $o->customer_id,
            $this->config->level_id,
            $verified_at,
            EVC_Bacs_Payment_Confirmation::AMOUNT_MINOR,
            EVC_Commerce_Source_Config::CURRENCY,
            array(new EVC_Movement_Alias(EVC_Movement_Alias::NS_WC_ORDER, $anchor)),
            EVC_Verified_Movement::AUTHORITY_MANUAL_ADMIN,
            EVC_Payment_Evidence::KIND_MANUAL_CONFIRMATION
        ));
        switch ($plan->outcome()) {
            case EVC_Period_Plan::PLANNED:
                break;
            case EVC_Period_Plan::ALREADY_RECORDED:
                return new EVC_Bacs_Issuance_Result(EVC_Bacs_Issuance_Result::CONFLICT, 'transfer_already_recorded');
            case EVC_Period_Plan::CONFLICT:
                return new EVC_Bacs_Issuance_Result(EVC_Bacs_Issuance_Result::CONFLICT, 'plan_' . (string) $plan->reason());
            case EVC_Period_Plan::UNAVAILABLE:
                return new EVC_Bacs_Issuance_Result(EVC_Bacs_Issuance_Result::UNAVAILABLE, 'plan_unavailable');
            default:
                return new EVC_Bacs_Issuance_Result(EVC_Bacs_Issuance_Result::MANAGER_REQUIRED, 'plan_' . (string) $plan->reason());
        }
        $problem = self::membership_problem($this->config, $this->store, $plan, $facts->pmpro_rows, $run_start, $previous_row_ref);
        if ($problem !== null) {
            return $problem;
        }

        try {
            $intent = EVC_Bacs_Intent::planned(EVC_Bacs_Payment_Confirmation::ENVIRONMENT, $o->order_id, $o->customer_id, $this->config->level_id, $attested_movement, $anchor, $actor_user_id, $verified_at, $plan, $run_start, $previous_row_ref);
        } catch (InvalidArgumentException $e) {
            return new EVC_Bacs_Issuance_Result(EVC_Bacs_Issuance_Result::CONFLICT, 'intent_invalid');
        }
        $write = $this->intents->create($lock, $intent);
        if (in_array($write->outcome, array(EVC_Bacs_Intent_Write::WRITTEN, EVC_Bacs_Intent_Write::ALREADY), true)) {
            return null;
        }
        return $write->outcome === EVC_Bacs_Intent_Write::CONFLICT
            ? new EVC_Bacs_Issuance_Result(EVC_Bacs_Issuance_Result::CONFLICT, 'intent_' . (string) $write->reason)
            : new EVC_Bacs_Issuance_Result(EVC_Bacs_Issuance_Result::UNAVAILABLE, 'intent_create_' . $write->outcome);
    }

    /**
     * PMPro must show exactly what the plan expects BEFORE anything is written: the paid-run start
     * ($run_start, out), and for an early renewal exactly one active Club row = the row the last
     * recorded payment is bound to, with start = run start and end = plan start ($previous_row_ref,
     * out); initial / late: no active Club row. Both instants must be exactly writable as PMPro
     * local dates. Null when it does; else why not. Reads only. STAGE-03D: shared (verbatim) with
     * EVC_Wc_Completion_Confirmer.
     *
     * @param EVC_Pmpro_Membership_Row[] $pmpro_rows
     */
    public static function membership_problem(EVC_Commerce_Source_Config $config, EVC_Provenance_Store $store, EVC_Period_Plan $plan, array $pmpro_rows, ?DateTimeImmutable &$run_start, ?string &$previous_row_ref): ?EVC_Bacs_Issuance_Result {
        $run_start = null;
        $previous_row_ref = null;
        try {
            $run_start = EVC_Paid_Run::start_for($store, $plan);
            $predecessor = $plan->kind() === EVC_Funded_Period::KIND_EARLY ? $store->period_for_movement((string) $plan->predecessor_movement_id()) : null;
        } catch (UnexpectedValueException $e) {
            return new EVC_Bacs_Issuance_Result(EVC_Bacs_Issuance_Result::MANAGER_REQUIRED, 'paid_chain_not_contiguous');
        } catch (Throwable $e) {
            return new EVC_Bacs_Issuance_Result(EVC_Bacs_Issuance_Result::UNAVAILABLE, 'club_record_unreadable');
        }

        // PMPro must show exactly what the plan expects BEFORE anything is written.
        $active = array();
        foreach ($pmpro_rows as $row) {
            if ($row->level_id === $config->level_id && $row->status === 'active') {
                $active[] = $row;
            }
        }
        if ($plan->kind() === EVC_Funded_Period::KIND_EARLY) {
            if (count($active) !== 1) {
                return count($active) === 0
                    ? new EVC_Bacs_Issuance_Result(EVC_Bacs_Issuance_Result::MANAGER_REQUIRED, 'paid_membership_row_missing')
                    : new EVC_Bacs_Issuance_Result(EVC_Bacs_Issuance_Result::CONFLICT, 'multiple_active_membership_rows');
            }
            // The running row must be the one the last recorded payment is bound to, with its dates.
            $row = $active[0];
            if ($predecessor === null || $predecessor->pmpro_row_ref() !== $row->row_ref
                || EVC_Pmpro_Local_Time::instant($row->startdate_local, EVC_Membership_Calendar::LATEST) != $run_start
                || EVC_Pmpro_Local_Time::instant($row->enddate_local, EVC_Membership_Calendar::EARLIEST) != $plan->start_utc()) {
                return new EVC_Bacs_Issuance_Result(EVC_Bacs_Issuance_Result::MANAGER_REQUIRED, 'paid_membership_row_differs');
            }
            $previous_row_ref = $row->row_ref;
        } elseif ($active !== array()) {
            return new EVC_Bacs_Issuance_Result(EVC_Bacs_Issuance_Result::CONFLICT, 'unexpected_membership_row');
        }
        if (EVC_Pmpro_Local_Time::exact($run_start, EVC_Membership_Calendar::LATEST) === null
            || EVC_Pmpro_Local_Time::exact($plan->end_utc(), EVC_Membership_Calendar::EARLIEST) === null) {
            // e.g. a confirmation inside the first repeated autumn hour: PMPro cannot show it exactly.
            return new EVC_Bacs_Issuance_Result(EVC_Bacs_Issuance_Result::MANAGER_REQUIRED, 'period_not_representable_in_pmpro');
        }

        return null;
    }

    /**
     * Runs ONE issuance action ($act) with the guard armed for the intent's order, customer,
     * level and exact dates; the guard is ALWAYS disarmed in `finally`. Whatever $act returns or
     * throws is ignored: the caller re-reads every fact. $unguarded is set when the guard did not
     * replace the dates exactly once (the caller then stops after the re-read). Null, or why
     * nothing was attempted.
     */
    private function armed(EVC_Member_Lock $lock, EVC_Bacs_Intent $intent, int $order_id, callable $act, bool &$unguarded): ?EVC_Bacs_Issuance_Result {
        // STAGE-03B.3b review (L1): only while THIS request still holds THIS customer's lock.
        if ($lock->wp_user_id() !== $intent->customer_id()) {
            return new EVC_Bacs_Issuance_Result(EVC_Bacs_Issuance_Result::MANAGER_REQUIRED, 'lock_not_for_intent_customer');
        }
        if (!$lock->is_held()) {
            return new EVC_Bacs_Issuance_Result(EVC_Bacs_Issuance_Result::UNAVAILABLE, 'lock_lost');
        }
        $start = EVC_Pmpro_Local_Time::exact($intent->run_start_utc(), EVC_Membership_Calendar::LATEST);
        $end = EVC_Pmpro_Local_Time::exact($intent->period_end_utc(), EVC_Membership_Calendar::EARLIEST);
        if ($start === null || $end === null) {
            return new EVC_Bacs_Issuance_Result(EVC_Bacs_Issuance_Result::MANAGER_REQUIRED, 'period_not_representable_in_pmpro');
        }
        // STAGE-03B.3b review (M1): the pinned environment again, immediately before writing.
        $environment = self::environment_problem($this->config);
        if ($environment !== null) {
            return new EVC_Bacs_Issuance_Result(EVC_Bacs_Issuance_Result::MANAGER_REQUIRED, $environment);
        }
        $this->guard->arm($order_id, $intent->customer_id(), $intent->level_id(), $start, $end);
        try {
            $act();
        } catch (Throwable $e) {
            // WooCommerce / PMPro outcome unknown: the fresh re-read decides, never this exception.
        } finally {
            $this->guard->disarm();
        }
        // STAGE-03B.3b review (M2): exactly one replacement for exactly this order, else stop.
        $unguarded = $this->guard->applied() !== 1;
        return null;
    }

    /**
     * Configuration the guard's `pmpro_deactivate_old_levels` = false relies on: the site clock is
     * Athens (PMPro stores site-local dates), the commerce environment is exactly the pinned one
     * (environment_problem()), and the Club level shares no single-selection PMPro level group
     * with another level (else PMPro would no longer cancel that other level).
     * Read with plain SELECTs: PMPro's own group getters may create a default group (a write).
     */
    public static function site_problem(EVC_Commerce_Source_Config $config): ?string {
        global $wpdb;
        if ((string) get_option('timezone_string', '') !== EVC_Membership_Calendar::ZONE) {
            return 'site_timezone_not_athens';
        }
        if (!EVC_Wp_Commerce_Pmpro_Reader::commerce_plugins_loaded() || !isset($wpdb->pmpro_membership_levels_groups, $wpdb->pmpro_groups)) {
            return 'membership_plugin_unavailable';
        }
        $environment = self::environment_problem($config);
        if ($environment !== null) {
            return $environment;
        }
        $wpdb->last_error = '';
        $group = $wpdb->get_var($wpdb->prepare("SELECT `group` FROM {$wpdb->pmpro_membership_levels_groups} WHERE `level` = %d LIMIT 1", $config->level_id));
        if ($wpdb->last_error !== '') {
            return 'level_group_unreadable';
        }
        if ($group === null) {
            return null;
        }
        $allow = $wpdb->get_var($wpdb->prepare("SELECT allow_multiple_selections FROM {$wpdb->pmpro_groups} WHERE id = %d", (int) $group));
        $others = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->pmpro_membership_levels_groups} WHERE `group` = %d AND `level` <> %d", (int) $group, $config->level_id));
        if ($wpdb->last_error !== '') {
            return 'level_group_unreadable';
        }
        return $allow !== null && (int) $allow === 0 && (int) $others > 0 ? 'club_level_group_not_exclusive' : null;
    }

    /**
     * STAGE-03B.3b review (M1): the pinned commerce environment the reader verifies (exact plugin
     * versions, the product -> Club level mapping with no other product granting the level, the
     * level = 1 Month without recurring billing, no autocomplete, ...). The issuer never writes to
     * WooCommerce / PMPro unless it is exactly as pinned: a product mapped to another level would
     * otherwise make every completion or re-issue grant THAT level with the add-on's own dates.
     */
    public static function environment_problem(EVC_Commerce_Source_Config $config): ?string {
        return EVC_Wp_Commerce_Pmpro_Reader::environment_conflicts($config) === array() ? null : 'commerce_environment_conflict';
    }

    private function stopped(string $outcome, string $reason, bool $risk): EVC_Bacs_Issuance_Result {
        $result = new EVC_Bacs_Issuance_Result($outcome, $reason);
        $result->paid_time_at_risk = $risk;
        return $result;
    }

    private static function whole_second(DateTimeImmutable $t): DateTimeImmutable {
        $utc = $t->setTimezone(new DateTimeZone('UTC'));
        return new DateTimeImmutable($utc->format('Y-m-d H:i:s'), new DateTimeZone('UTC'));
    }

    private static function from_decision(EVC_Bacs_Recovery_Decision $d): EVC_Bacs_Issuance_Result {
        $map = array(
            EVC_Bacs_Recovery_Decision::REFUSED => EVC_Bacs_Issuance_Result::REFUSED,
            EVC_Bacs_Recovery_Decision::CONFLICT => EVC_Bacs_Issuance_Result::CONFLICT,
            EVC_Bacs_Recovery_Decision::UNAVAILABLE => EVC_Bacs_Issuance_Result::UNAVAILABLE,
        );
        $result = new EVC_Bacs_Issuance_Result($map[$d->outcome] ?? EVC_Bacs_Issuance_Result::MANAGER_REQUIRED, $d->reason ?? $d->step);
        $result->paid_time_at_risk = $d->paid_time_at_risk;
        return $result;
    }

    private static function from_finalization(EVC_Bacs_Finalization_Result $f): EVC_Bacs_Issuance_Result {
        $map = array(
            EVC_Bacs_Finalization_Result::RECORDED => EVC_Bacs_Issuance_Result::RECORDED,
            EVC_Bacs_Finalization_Result::ALREADY_RECORDED => EVC_Bacs_Issuance_Result::ALREADY_RECORDED,
            EVC_Bacs_Finalization_Result::REFUSED => EVC_Bacs_Issuance_Result::REFUSED,
            EVC_Bacs_Finalization_Result::CONFLICT => EVC_Bacs_Issuance_Result::CONFLICT,
            EVC_Bacs_Finalization_Result::UNAVAILABLE => EVC_Bacs_Issuance_Result::UNAVAILABLE,
            EVC_Bacs_Finalization_Result::BUSY => EVC_Bacs_Issuance_Result::BUSY,
        );
        return new EVC_Bacs_Issuance_Result($map[$f->outcome] ?? EVC_Bacs_Issuance_Result::MANAGER_REQUIRED, $f->reason, $f->period, $f->intent_finalized);
    }

    /** Every attempt is audited with opaque references only. An audit failure never changes the result. */
    private function audit_attempt(int $actor_user_id, EVC_Bacs_Issuance_Result $result, ?string $movement_id, ?string $order_ref): void {
        if ($this->audit === null || $this->audit_db === null) {
            return;
        }
        try {
            $this->audit->record($this->audit_db, array(
                'event_type' => self::EVENT,
                'outcome' => $result->outcome,
                'member_id' => null,
                'redemption_id' => null,
                'request_id' => null,
                'staff_wp_user_id' => $actor_user_id > 0 ? $actor_user_id : null,
                'occurred_at_utc' => (($this->now)())->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
                'details' => array_filter(array(
                    'reason' => $result->reason,
                    'movement' => $movement_id,
                    'order' => $order_ref,
                    'steps' => $result->steps === array() ? null : implode(',', $result->steps),
                    'intent_finalized' => $result->intent_finalized ? 'yes' : 'no',
                    'paid_time_at_risk' => $result->paid_time_at_risk ? 'yes' : 'no',
                    'period_end_utc' => $result->period ? $result->period->end_utc()->format('Y-m-d H:i:s') : null,
                ), function ($v) {
                    return $v !== null;
                }),
            ));
            $result->audited = true;
        } catch (Throwable $e) {
            $result->audited = false;
        }
    }
}
