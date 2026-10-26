<?php
defined('ABSPATH') || exit;

/** Result of one finalization attempt (internal; never shown to customers). */
final class EVC_Bacs_Finalization_Result {
    /** the Club recorded the movement now (intent_finalized says whether the intent caught up) */
    const RECORDED = 'recorded';
    /** the movement was already recorded with exactly the signed period; nothing new funded */
    const ALREADY_RECORDED = 'already_recorded';
    const REFUSED = 'refused';
    const CONFLICT = 'conflict';
    const MANAGER_REQUIRED = 'manager_required';
    /** a source failed; nothing was recorded, or the outcome is still being resolved: retry */
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
 * STAGE-03B.3a: INTERNAL finalization of an ISSUED BACS intent:
 *   issued → (verify everything) → record the movement → verify → intent "recorded".
 *
 * Payment evidence is a TRUSTED MANUAL BANK-STATEMENT ATTESTATION by an
 * authorised manager, supplied anew for this finalization: the transfer
 * reference as printed on the statement, amount, currency and value date.
 * It is NOT a verification against the bank. Neither the signed intent nor
 * a completed WooCommerce order is evidence of money. The attestation is
 * bound to the attempt by recomputing the reference's HMAC and requiring it
 * to equal the signed intent's movement id; the raw reference is used only
 * for that HMAC and never stored, logged or returned.
 *
 * Every precondition is re-read under the member lock (EVC_Member_Lock):
 * signed intent from order meta, actor, order, PMPro rows and Club record
 * (EVC_Bacs_Recovery_Inspector → EVC_Bacs_Recovery_Policy). Only a "resume:
 * record_movement" decision (or an already recorded payment whose intent is
 * still "issued") proceeds. The movement is recorded with
 * EVC_Provenance_Recorder::record_expecting(): the signed period is the only
 * period that can be committed (store-side I4 compare-and-set + calendar
 * recomputation under lock). An unknown commit outcome is resolved by
 * reading the Club record back, never guessed.
 *
 * Unreachable: no route, hook, cron, page or role; nothing in the plugin
 * constructs it (the packager forbids it). It never completes orders and
 * never issues, cancels or edits PMPro memberships.
 */
final class EVC_Bacs_Payment_Finalizer {
    const EVENT = 'bacs_payment_finalization';
    const ABSENT = 'absent';
    const DIFFERS = 'differs';

    /** @var EVC_Commerce_Source_Config */
    private $config;
    /** @var EVC_Provenance_Store */
    private $store;
    /** @var EVC_Wc_Bacs_Intent_Store */
    private $intents;
    /** @var wpdb */
    private $db;
    /** @var callable(): DateTimeImmutable */
    private $now;
    /** @var EVC_Audit_Log|null */
    private $audit;
    /** @var EVC_Club_Db|null */
    private $audit_db;
    /** @var int */
    private $lock_wait;
    /** @var EVC_Provenance_Recorder */
    private $recorder;
    /** @var EVC_Bacs_Recovery_Inspector */
    private $inspector;
    /** @var EVC_Bacs_Recovery_Policy */
    private $policy;

    /** @param EVC_Bacs_Recovery_Inspector $inspector built over the SAME $store and $intents */
    public function __construct(
        EVC_Commerce_Source_Config $config,
        EVC_Provenance_Store $store,
        EVC_Wc_Bacs_Intent_Store $intents,
        EVC_Bacs_Recovery_Inspector $inspector,
        wpdb $db,
        callable $now,
        ?EVC_Audit_Log $audit = null,
        ?EVC_Club_Db $audit_db = null,
        int $lock_wait_seconds = 3
    ) {
        if (!$inspector->reads($store, $intents)) {
            // Preconditions read from one Club record and the payment written to another would bind nothing.
            throw new InvalidArgumentException('The recovery inspector must read the same Club store and intent store.');
        }
        $this->config = $config;
        $this->store = $store;
        $this->intents = $intents;
        $this->db = $db;
        $this->now = $now;
        $this->audit = $audit;
        $this->audit_db = $audit_db;
        $this->lock_wait = $lock_wait_seconds;
        $this->recorder = new EVC_Provenance_Recorder(
            $store,
            new EVC_Pmpro_Mapper_Config(array($config->level_id), EVC_Commerce_Source_Config::PMPRO_VERSION, EVC_Commerce_Source_Config::PMPRO_VERSION_EXCLUSIVE_MAX),
            EVC_Bacs_Payment_Confirmation::ENVIRONMENT,
            EVC_Bacs_Payment_Confirmation::AMOUNT_MINOR,
            EVC_Commerce_Source_Config::CURRENCY
        );
        $this->inspector = $inspector;
        $this->policy = new EVC_Bacs_Recovery_Policy();
    }

    /**
     * @param array{bank_reference?:mixed,amount_minor?:mixed,currency?:mixed,received_on?:mixed} $attestation
     *        the manager's reading of the bank statement for THIS transfer
     */
    public function finalize(int $actor_user_id, int $order_id, array $attestation): EVC_Bacs_Finalization_Result {
        $order_ref = $order_id > 0 ? $this->config->ref('wc_order', (string) $order_id) : null;
        $result = $this->decide($actor_user_id, $order_id, $attestation, $movement_id);
        $this->audit_attempt($actor_user_id, $result, $movement_id, $order_ref);
        return $result;
    }

    private function decide(int $actor_user_id, int $order_id, array $attestation, ?string &$movement_id): EVC_Bacs_Finalization_Result {
        $movement_id = null;
        $refusal = $this->admit($actor_user_id, $order_id, $attestation, $attested_movement, $received_on, $order);
        if ($refusal !== null) {
            return $refusal;
        }
        $result = $this->with_member_lock((int) $order->get_customer_id(), function (EVC_Member_Lock $lock) use ($actor_user_id, $order, $attested_movement, $received_on, &$movement_id) {
            return $this->finalize_holding($lock, $actor_user_id, $order, $attested_movement, $received_on, $movement_id);
        });
        return $result ?? new EVC_Bacs_Finalization_Result(EVC_Bacs_Finalization_Result::BUSY, 'member_locked');
    }

    /**
     * STAGE-03B.3b (shared with EVC_Bacs_Payment_Issuer): the checks made BEFORE the member lock —
     * the actor holds the capability and is not the shared staff account, the attestation is
     * well-formed (reference normalised and turned into its HMAC at once; 2000 cents EUR; a real
     * value date) and the order exists with a customer account. Null when admitted (out-params
     * set), else the refusal. Reads only; writes nothing.
     */
    public function admit(int $actor_user_id, int $order_id, array $attestation, ?string &$attested_movement, ?string &$received_on, ?WC_Order &$order): ?EVC_Bacs_Finalization_Result {
        $attested_movement = null;
        $received_on = null;
        $order = null;
        $actor = $actor_user_id > 0 ? get_userdata($actor_user_id) : false;
        if (!$actor instanceof WP_User || !user_can($actor, EVC_Bacs_Payment_Confirmation::CAPABILITY) || EVC_Staff_Role::has_staff_role($actor)) {
            return new EVC_Bacs_Finalization_Result(EVC_Bacs_Finalization_Result::REFUSED, 'not_authorised');
        }
        $reference = EVC_Bacs_Payment_Confirmation::normalise_reference($attestation['bank_reference'] ?? null);
        $date = $attestation['received_on'] ?? null;
        if ($reference === null || !is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)) {
            return new EVC_Bacs_Finalization_Result(EVC_Bacs_Finalization_Result::REFUSED, 'invalid_attestation');
        }
        if (($attestation['amount_minor'] ?? null) !== EVC_Bacs_Payment_Confirmation::AMOUNT_MINOR || ($attestation['currency'] ?? null) !== EVC_Commerce_Source_Config::CURRENCY) {
            return new EVC_Bacs_Finalization_Result(EVC_Bacs_Finalization_Result::REFUSED, 'amount_not_20_eur');
        }
        $movement = $this->config->ref('bacs_transfer', $reference);
        unset($reference);

        $found = $order_id > 0 ? wc_get_order($order_id) : false;
        if (!$found instanceof WC_Order || $found instanceof WC_Order_Refund) {
            return new EVC_Bacs_Finalization_Result(EVC_Bacs_Finalization_Result::REFUSED, 'order_not_found');
        }
        if ((int) $found->get_customer_id() < 1) {
            return new EVC_Bacs_Finalization_Result(EVC_Bacs_Finalization_Result::REFUSED, 'order_without_customer_account');
        }
        $attested_movement = $movement;
        $received_on = $date;
        $order = $found;
        return null;
    }

    /**
     * STAGE-03B.3b: runs $work($lock) holding the member's lock, released in `finally`. Returns
     * $work's result, or null when another session holds the lock past the bounded wait. The
     * ONLY place in the plugin that acquires EVC_Member_Lock (the packager enforces it), so one
     * request holds exactly one lock and the finalization step can run inside the issuer's lock.
     *
     * @return mixed|null
     */
    public function with_member_lock(int $customer_id, callable $work) {
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

    /** True when this finalizer records into exactly these stores and decides with exactly this inspector. */
    public function uses(EVC_Provenance_Store $store, EVC_Wc_Bacs_Intent_Store $intents, EVC_Bacs_Recovery_Inspector $inspector): bool {
        return $this->store === $store && $this->intents === $intents && $this->inspector === $inspector;
    }

    /**
     * The finalization step itself, for a caller that ALREADY holds this member's lock
     * (finalize() above, or EVC_Bacs_Payment_Issuer inside its single locked request).
     * It never acquires or releases the lock; ownership is re-checked here and before recording.
     */
    public function finalize_holding(EVC_Member_Lock $lock, int $actor_user_id, WC_Order $order, string $attested_movement, string $received_on, ?string &$movement_id): EVC_Bacs_Finalization_Result {
        $movement_id = null;
        if ($lock->wp_user_id() !== (int) $order->get_customer_id() || !$lock->is_held()) {
            return new EVC_Bacs_Finalization_Result(EVC_Bacs_Finalization_Result::UNAVAILABLE, 'lock_lost');
        }
        // Every fact re-read now, under the lock: signed intent, actor, order, PMPro rows, Club record.
        $facts = $this->inspector->facts($actor_user_id, (int) $order->get_id());
        $decision = $this->policy->decide($facts);
        $read = $facts->intent;
        if ($read->outcome !== EVC_Bacs_Intent_Read::VALID || $read->intent === null) {
            return self::from_decision($decision);
        }
        $intent = $read->intent;
        $movement_id = $intent->movement_id();

        // The manager's statement must describe exactly the transfer this attempt was made for.
        if (!hash_equals($intent->movement_id(), $attested_movement)) {
            return new EVC_Bacs_Finalization_Result(EVC_Bacs_Finalization_Result::REFUSED, 'attestation_does_not_match_intent');
        }
        $window = $this->value_date_problem($order, $intent->verified_at_utc(), $received_on);
        if ($window !== null) {
            return new EVC_Bacs_Finalization_Result(EVC_Bacs_Finalization_Result::REFUSED, $window);
        }

        $behind = $decision->outcome === EVC_Bacs_Recovery_Decision::ALREADY_COMPLETE;
        if ($behind) {
            if ($intent->state() === EVC_Bacs_Intent::STATE_RECORDED) {
                return new EVC_Bacs_Finalization_Result(EVC_Bacs_Finalization_Result::ALREADY_RECORDED, null, $facts->recorded_period, true);
            }
            if ($intent->state() !== EVC_Bacs_Intent::STATE_ISSUED) {
                return new EVC_Bacs_Finalization_Result(EVC_Bacs_Finalization_Result::MANAGER_REQUIRED, 'intent_not_issued');
            }
            // Recorded earlier, intent transition lost: finish the intent, record nothing.
            return $this->finish_intent($lock, $intent, $facts->recorded_period, EVC_Bacs_Finalization_Result::ALREADY_RECORDED);
        }
        if ($decision->outcome !== EVC_Bacs_Recovery_Decision::RESUME) {
            return self::from_decision($decision);
        }
        if ($decision->step !== EVC_Bacs_Recovery_Decision::STEP_RECORD_MOVEMENT || $intent->state() !== EVC_Bacs_Intent::STATE_ISSUED
            || $decision->row_ref === null || $decision->row_ref !== $intent->new_row_ref()) {
            // This service finalizes ISSUED intents only; completion and issuance are not its job.
            return new EVC_Bacs_Finalization_Result(EVC_Bacs_Finalization_Result::MANAGER_REQUIRED, 'intent_not_issued');
        }

        if (!$lock->is_held()) {
            return new EVC_Bacs_Finalization_Result(EVC_Bacs_Finalization_Result::UNAVAILABLE, 'lock_lost');
        }
        $movement = $this->movement_for($intent);
        $outcome = $this->recorder->record_expecting($movement, $intent->as_plan());
        switch ($outcome->outcome()) {
            case EVC_Record_Outcome::NEWLY_RECORDED:
            case EVC_Record_Outcome::ALREADY_RECORDED:
                break;
            case EVC_Record_Outcome::UNAVAILABLE:
                if ($outcome->reason() !== 'commit_outcome_unknown') {
                    return new EVC_Bacs_Finalization_Result(EVC_Bacs_Finalization_Result::UNAVAILABLE, 'club_record_unavailable');
                }
                // Resolve by trusted read-back below; never guess.
                break;
            case EVC_Record_Outcome::CONFLICT:
                return new EVC_Bacs_Finalization_Result(EVC_Bacs_Finalization_Result::CONFLICT, 'club_' . (string) $outcome->reason());
            default:
                return new EVC_Bacs_Finalization_Result(EVC_Bacs_Finalization_Result::MANAGER_REQUIRED, 'club_' . (string) $outcome->reason());
        }

        $period = $this->recorded_exactly($movement, $intent);
        if ($period === false) {
            return new EVC_Bacs_Finalization_Result(EVC_Bacs_Finalization_Result::UNAVAILABLE, 'club_commit_unknown');
        }
        if ($period === self::ABSENT) {
            return new EVC_Bacs_Finalization_Result(EVC_Bacs_Finalization_Result::UNAVAILABLE, 'club_commit_not_found');
        }
        if (!$period instanceof EVC_Funded_Period) {
            // Stored, but not exactly as signed (e.g. funded by another path): stop, never overwrite.
            return new EVC_Bacs_Finalization_Result(EVC_Bacs_Finalization_Result::CONFLICT, 'club_read_back_differs');
        }
        return $this->finish_intent($lock, $intent, $period, $outcome->outcome() === EVC_Record_Outcome::ALREADY_RECORDED ? EVC_Bacs_Finalization_Result::ALREADY_RECORDED : EVC_Bacs_Finalization_Result::RECORDED);
    }

    /**
     * Trusted read-back of the Club record: the period when the movement is stored with exactly
     * these facts and the signed period; ABSENT when it is not stored; DIFFERS when something
     * else is stored for this transfer; false when the Club record cannot be read.
     *
     * @return EVC_Funded_Period|string|false
     */
    private function recorded_exactly(EVC_Verified_Movement $movement, EVC_Bacs_Intent $intent) {
        try {
            $stored = $this->store->movement($movement->movement_id());
            $period = $stored === null ? null : $this->store->period_for_movement($movement->movement_id());
        } catch (Throwable $e) {
            return false;
        }
        if ($stored === null) {
            return self::ABSENT;
        }
        if ($period === null || !$stored->same_facts_as($movement) || !$intent->as_plan()->matches_recorded($period)
            || $period->pmpro_row_ref() !== $intent->new_row_ref()) {
            return self::DIFFERS;
        }
        return $period;
    }

    private function finish_intent(EVC_Member_Lock $lock, EVC_Bacs_Intent $issued, ?EVC_Funded_Period $period, string $outcome): EVC_Bacs_Finalization_Result {
        $write = $this->intents->transition($lock, $issued, $issued->recorded());
        $done = in_array($write->outcome, array(EVC_Bacs_Intent_Write::WRITTEN, EVC_Bacs_Intent_Write::ALREADY), true);
        // The payment is recorded either way; a lost intent transition is finished by the next retry.
        return new EVC_Bacs_Finalization_Result($outcome, $done ? null : 'intent_transition_' . $write->outcome, $period, $done);
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
            EVC_Payment_Evidence::KIND_MANUAL_CONFIRMATION,
            EVC_Verified_Movement::STATE_CONFIRMED
        );
    }

    /**
     * Existing BACS rule (value date between the order date and Athens today), and the money
     * must have arrived no later than the confirmation instant (the signed intent's, or for a
     * new attempt the instant about to be signed). Shared with EVC_Bacs_Payment_Issuer.
     */
    public function value_date_problem(WC_Order $order, DateTimeImmutable $confirmed_at_utc, string $received_on): ?string {
        $zone = new DateTimeZone(EVC_Bacs_Payment_Confirmation::BUSINESS_TIMEZONE);
        $received = DateTimeImmutable::createFromFormat('!Y-m-d', $received_on, $zone);
        if ($received === false || $received->format('Y-m-d') !== $received_on) {
            return 'invalid_attestation';
        }
        $created = $order->get_date_created();
        $today = (($this->now)())->setTimezone($zone)->format('Y-m-d');
        $confirmed = $confirmed_at_utc->setTimezone($zone)->format('Y-m-d');
        if ($created === null || $received_on < $created->setTimezone($zone)->format('Y-m-d') || $received_on > $today || $received_on > $confirmed) {
            return 'received_date_outside_order_window';
        }
        return null;
    }

    private static function from_decision(EVC_Bacs_Recovery_Decision $d): EVC_Bacs_Finalization_Result {
        $map = array(
            EVC_Bacs_Recovery_Decision::REFUSED => EVC_Bacs_Finalization_Result::REFUSED,
            EVC_Bacs_Recovery_Decision::CONFLICT => EVC_Bacs_Finalization_Result::CONFLICT,
            EVC_Bacs_Recovery_Decision::UNAVAILABLE => EVC_Bacs_Finalization_Result::UNAVAILABLE,
        );
        return new EVC_Bacs_Finalization_Result($map[$d->outcome] ?? EVC_Bacs_Finalization_Result::MANAGER_REQUIRED, $d->reason ?? $d->step);
    }

    /** Every attempt is audited with opaque references only. An audit failure never changes the result. */
    private function audit_attempt(int $actor_user_id, EVC_Bacs_Finalization_Result $result, ?string $movement_id, ?string $order_ref): void {
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
                    'intent_finalized' => $result->intent_finalized ? 'yes' : 'no',
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
