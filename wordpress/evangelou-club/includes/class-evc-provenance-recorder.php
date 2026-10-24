<?php
defined('ABSPATH') || (defined('EVC_STANDALONE_TEST') && EVC_STANDALONE_TEST) || exit;

/**
 * Deterministic payment-provenance recorder core (Task 1D-E). Pure PHP:
 * reads no clock, calls no gateway, WordPress, PMPro or WooCommerce code and
 * is NOT wired to anything in production.
 *
 * Input is an EVC_Verified_Movement that a FUTURE trusted adapter has
 * already verified independently. The recorder decides only whether that
 * fact can be appended to the member's paid-period chain, and computes the
 * period with the owner-approved rules (EVC_Membership_Calendar: D1 exact
 * Athens instant, exclusive end; D2 early renewal from the paid end, late
 * renewal from the confirmation instant; D5 clamped calendar month).
 *
 * Identity (one real payment = one movement = at most one period):
 *  - movement_id is the canonical identity, independent of which signal
 *    (gateway, WooCommerce, PMPro, manual) arrived first;
 *  - every alias key belongs to at most one movement;
 *  - every movement carries exactly ONE order anchor (the order it settles)
 *    and an order is settled by at most one movement. So a Viva callback
 *    and a WooCommerce completion for the same order collapse onto one
 *    movement, and a signal that cannot be tied to an order is not
 *    recordable (indeterminate) rather than guessed. Amounts, names and
 *    times are NEVER used to merge or split payments.
 *
 * Ordering: periods are only ever APPENDED. A movement verified at the same
 * instant as, or earlier than, one already recorded for the member is not
 * recorded (indeterminate) because the mapper replays payments in
 * confirmation order; the instant is never shifted to make it fit.
 *
 * Corrections (Task 1D-E.R1): forward-only and compare-and-set at commit
 * time. A verified correction for a movement that is not recorded (refund
 * seen before its payment) is kept as an UNRESOLVED obligation; the payment
 * is then refused by record() and can only enter through reconcile(), which
 * a trusted adapter calls after re-verifying the current source state.
 *
 * Every refusal leaves the store untouched. Recording is not eligibility.
 */
final class EVC_Provenance_Recorder {
    /** @var EVC_Provenance_Store */
    private $store;
    /** @var EVC_Pmpro_Mapper_Config */
    private $levels;
    /** @var string */
    private $environment;
    /** @var int */
    private $amount_minor;
    /** @var string */
    private $currency;

    /**
     * @param string $environment the ONLY environment this deployment may record (e.g. "live")
     * @param int    $amount_minor price of one paid month (owner rule: 2000 = EUR 20.00)
     */
    public function __construct(EVC_Provenance_Store $store, EVC_Pmpro_Mapper_Config $levels, string $environment, int $amount_minor, string $currency) {
        if ($amount_minor < 1 || !preg_match(EVC_Verified_Movement::CURRENCY_PATTERN, $currency)
            || !preg_match(EVC_Verified_Movement::ENVIRONMENT_PATTERN, $environment)) {
            throw new InvalidArgumentException('Invalid recorder configuration.');
        }
        $this->store = $store;
        $this->levels = $levels;
        $this->environment = $environment;
        $this->amount_minor = $amount_minor;
        $this->currency = $currency;
    }

    public function record(EVC_Verified_Movement $movement): EVC_Record_Outcome {
        try {
            return $this->record_or_throw($movement);
        } catch (EVC_Provenance_Conflict_Exception $e) {
            return EVC_Record_Outcome::conflict('concurrent_update');
        } catch (Throwable $e) {
            return EVC_Record_Outcome::unavailable($e instanceof EVC_Provenance_Commit_Unknown_Exception ? 'commit_outcome_unknown' : 'store_unavailable');
        }
    }

    public function correct(EVC_Movement_Correction $correction): EVC_Record_Outcome {
        try {
            return $this->correct_or_throw($correction);
        } catch (EVC_Provenance_Conflict_Exception $e) {
            return EVC_Record_Outcome::conflict('concurrent_update');
        } catch (Throwable $e) {
            return EVC_Record_Outcome::unavailable($e instanceof EVC_Provenance_Commit_Unknown_Exception ? 'commit_outcome_unknown' : 'store_unavailable');
        }
    }

    /**
     * Trusted resolution of corrections that arrived before their payment
     * (Task 1D-E.R1): records the movement, its period and the acknowledged
     * corrections in ONE commit, so the payment never exists without them.
     */
    public function reconcile(EVC_Movement_Reconciliation $reconciliation): EVC_Record_Outcome {
        try {
            return $this->reconcile_or_throw($reconciliation);
        } catch (EVC_Provenance_Conflict_Exception $e) {
            return EVC_Record_Outcome::conflict('concurrent_update');
        } catch (Throwable $e) {
            return EVC_Record_Outcome::unavailable($e instanceof EVC_Provenance_Commit_Unknown_Exception ? 'commit_outcome_unknown' : 'store_unavailable');
        }
    }

    /**
     * STAGE-03B.1: ADVISORY, READ-ONLY planning of the period a payment would
     * fund BEFORE PMPro has issued its membership row. Same rules, in the same
     * order, as record() (shared code: candidate_refusal(), chain_position()),
     * but it never calls commit(), never adds aliases and never confirms a
     * payment. The plan may be stale by the time it is used: record() and the
     * store re-check everything again at commit time.
     */
    public function plan(EVC_Prospective_Payment $payment): EVC_Period_Plan {
        try {
            return $this->plan_or_throw($payment);
        } catch (Throwable $e) {
            return EVC_Period_Plan::unavailable('store_unavailable');
        }
    }

    private function plan_or_throw(EVC_Prospective_Payment $p): EVC_Period_Plan {
        $refusal = $this->candidate_refusal($p);
        if ($refusal !== null) {
            return EVC_Period_Plan::refused($refusal);
        }
        $existing = $this->store->movement($p->movement_id());
        if ($existing !== null) {
            return $this->planned_repeat($existing, $p);
        }
        if ($this->store->corrections_for($p->movement_id()) !== array()) {
            return EVC_Period_Plan::refused(EVC_Record_Outcome::indeterminate('unresolved_correction'));
        }
        $position = $this->chain_position($p);
        if ($position instanceof EVC_Record_Outcome) {
            return EVC_Period_Plan::refused($position);
        }
        $latest = $position['latest'];
        if ($latest !== null && ($latest->wp_user_id() !== $p->wp_user_id() || $this->store->movement($latest->movement_id()) === null)) {
            return EVC_Period_Plan::unavailable('store_inconsistent');
        }
        return EVC_Period_Plan::planned($p->movement_id(), $position['next']['start'], $position['next']['end'], $position['kind'], $position['predecessor']);
    }

    /** The prospective payment is already recorded: same facts -> its recorded period; otherwise a conflict. Writes nothing. */
    private function planned_repeat(EVC_Verified_Movement $existing, EVC_Prospective_Payment $p): EVC_Period_Plan {
        if ($existing->order_anchors()[0]->key() !== $p->order_anchors()[0]->key()) {
            return EVC_Period_Plan::refused(EVC_Record_Outcome::conflict('order_anchor_conflict'));
        }
        if (!$p->matches($existing)) {
            return EVC_Period_Plan::refused(EVC_Record_Outcome::conflict('movement_fact_conflict'));
        }
        foreach ($p->aliases() as $alias) {
            $owner = $this->store->movement_for_alias($alias->key());
            if ($owner !== null && $owner !== $p->movement_id()) {
                return EVC_Period_Plan::refused(EVC_Record_Outcome::conflict('alias_conflict'));
            }
        }
        $period = $this->store->period_for_movement($p->movement_id());
        if ($period === null || $period->pmpro_row_ref() !== $existing->pmpro_row_ref() || $period->wp_user_id() !== $existing->wp_user_id()) {
            return EVC_Period_Plan::unavailable('store_inconsistent');
        }
        return EVC_Period_Plan::already($period);
    }

    /** Checks that do not depend on the store; null when the movement may be recorded. */
    private function refusal(EVC_Verified_Movement $m): ?EVC_Record_Outcome {
        if ($m->state() !== EVC_Verified_Movement::STATE_CONFIRMED) {
            return EVC_Record_Outcome::indeterminate('movement_not_confirmed');
        }
        return $this->candidate_refusal($m);
    }

    /** The store-independent rules shared by record() and plan(). */
    private function candidate_refusal(EVC_Payment_Candidate $m): ?EVC_Record_Outcome {
        if ($m->environment() !== $this->environment) {
            return EVC_Record_Outcome::indeterminate('environment_mismatch');
        }
        if (!$this->levels->is_club_level($m->level_id())) {
            return EVC_Record_Outcome::indeterminate('level_not_approved');
        }
        if ($m->amount_minor() !== $this->amount_minor || $m->currency() !== $this->currency) {
            return EVC_Record_Outcome::indeterminate('amount_mismatch');
        }
        $anchors = $m->order_anchors();
        if (count($anchors) === 0) {
            return EVC_Record_Outcome::indeterminate('order_anchor_missing');
        }
        if (count($anchors) > 1) {
            return EVC_Record_Outcome::conflict('multiple_order_anchors');
        }
        return null;
    }

    private function record_or_throw(EVC_Verified_Movement $m): EVC_Record_Outcome {
        $refusal = $this->refusal($m);
        if ($refusal !== null) {
            return $refusal;
        }
        $existing = $this->store->movement($m->movement_id());
        if ($existing !== null) {
            return $this->repeat_of($existing, $m);
        }
        if ($this->store->corrections_for($m->movement_id()) !== array()) {
            // A verified refund/reversal arrived first: these facts may be stale.
            return EVC_Record_Outcome::indeterminate('unresolved_correction');
        }
        return $this->append($m, array());
    }

    private function reconcile_or_throw(EVC_Movement_Reconciliation $r): EVC_Record_Outcome {
        $m = $r->movement();
        $refusal = $this->refusal($m);
        if ($refusal !== null) {
            return $refusal;
        }
        $held = array_map(function (EVC_Movement_Correction $c) {
            return $c->correction_id();
        }, $this->store->corrections_for($m->movement_id()));
        $acknowledged = $r->acknowledged_correction_ids();
        $existing = $this->store->movement($m->movement_id());
        if ($existing !== null) {
            // Replay: the acknowledged list must still be the start of the history.
            if (array_slice($held, 0, count($acknowledged)) !== $acknowledged) {
                return EVC_Record_Outcome::conflict('reconciliation_mismatch');
            }
            return $this->repeat_of($existing, $m);
        }
        if ($held !== $acknowledged) {
            return EVC_Record_Outcome::conflict('reconciliation_mismatch');
        }
        return $this->append($m, $held);
    }

    /** @param string[] $expected_unresolved */
    private function append(EVC_Verified_Movement $m, array $expected_unresolved): EVC_Record_Outcome {
        $position = $this->chain_position($m);
        if ($position instanceof EVC_Record_Outcome) {
            return $position;
        }
        $next = $position['next'];
        $period = new EVC_Funded_Period(
            $m->movement_id(),
            $m->wp_user_id(),
            $m->level_id(),
            $m->pmpro_row_ref(),
            $next['start'],
            $next['end'],
            $position['kind'],
            $position['predecessor']
        );
        $this->store->commit(EVC_Provenance_Write::new_movement($m, $period, $expected_unresolved));
        return EVC_Record_Outcome::recorded($period);
    }

    /**
     * Where a not-yet-recorded payment would join the member's chain (shared
     * by record() and plan()): a refusal, or the latest period, the D1/D2/D5
     * next period, its kind and its predecessor. Reads only.
     *
     * @return EVC_Record_Outcome|array{latest:?EVC_Funded_Period,next:array{start:DateTimeImmutable,end:DateTimeImmutable,renewal:string},kind:string,predecessor:?string}
     */
    private function chain_position(EVC_Payment_Candidate $m) {
        foreach ($m->aliases() as $alias) {
            if ($this->store->movement_for_alias($alias->key()) !== null) {
                return EVC_Record_Outcome::conflict('alias_conflict');
            }
        }

        foreach ($this->store->member_movements($m->wp_user_id()) as $other) {
            if ($other->verified_at_utc() == $m->verified_at_utc()) {
                return EVC_Record_Outcome::indeterminate('same_instant_payment');
            }
            if ($other->verified_at_utc() > $m->verified_at_utc()) {
                return EVC_Record_Outcome::indeterminate('out_of_order_payment');
            }
        }

        $latest = $this->store->latest_period($m->wp_user_id());
        $next = EVC_Membership_Calendar::next_period($latest === null ? null : $latest->end_utc(), $m->verified_at_utc());
        return array(
            'latest' => $latest,
            'next' => $next,
            'kind' => $latest === null ? EVC_Funded_Period::KIND_INITIAL : $next['renewal'],
            'predecessor' => $latest === null ? null : $latest->movement_id(),
        );
    }

    /** Same canonical movement seen again (retry or another source path). */
    private function repeat_of(EVC_Verified_Movement $existing, EVC_Verified_Movement $m): EVC_Record_Outcome {
        if (!$existing->same_facts_as($m)) {
            return EVC_Record_Outcome::conflict('movement_fact_conflict');
        }
        $known_anchor = $existing->order_anchors()[0];
        if ($m->order_anchors()[0]->key() !== $known_anchor->key()) {
            return EVC_Record_Outcome::conflict('order_anchor_conflict');
        }
        $new = array();
        foreach ($m->aliases() as $alias) {
            $owner = $this->store->movement_for_alias($alias->key());
            if ($owner !== null && $owner !== $m->movement_id()) {
                return EVC_Record_Outcome::conflict('alias_conflict');
            }
            if ($owner === null) {
                $new[] = $alias;
            }
        }
        if ($new !== array()) {
            $this->store->commit(EVC_Provenance_Write::aliases_for($m->movement_id(), $new));
        }
        return EVC_Record_Outcome::already($this->store->period_for_movement($m->movement_id()));
    }

    /**
     * The write carries what was read (latest correction id, whether the
     * movement is recorded); the store re-checks both and the transition at
     * commit time, so a concurrent writer makes this a conflict, never a
     * silently reordered or regressed history.
     */
    private function correct_or_throw(EVC_Movement_Correction $c): EVC_Record_Outcome {
        $existing = $this->store->correction($c->correction_id());
        if ($existing !== null) {
            return $existing->same_as($c) ? EVC_Record_Outcome::already(null) : EVC_Record_Outcome::conflict('correction_conflict');
        }
        $recorded = $this->store->movement($c->movement_id()) !== null;
        $history = $this->store->corrections_for($c->movement_id());
        $prior = $history === array() ? null : $history[count($history) - 1];
        if (!EVC_Movement_Correction::allowed_after($prior === null ? null : $prior->kind(), $c->kind())) {
            return EVC_Record_Outcome::conflict('correction_state_regression');
        }
        $this->store->commit(EVC_Provenance_Write::correction($c, $prior === null ? null : $prior->correction_id(), $recorded));
        // Refund seen before its payment: kept as an unresolved obligation that
        // blocks the movement until a trusted reconciliation (never dropped).
        return $recorded ? EVC_Record_Outcome::recorded(null) : EVC_Record_Outcome::quarantined('unknown_movement');
    }
}
