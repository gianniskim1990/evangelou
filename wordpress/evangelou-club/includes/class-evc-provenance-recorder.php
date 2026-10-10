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
            return EVC_Record_Outcome::unavailable();
        }
    }

    public function correct(EVC_Movement_Correction $correction): EVC_Record_Outcome {
        try {
            return $this->correct_or_throw($correction);
        } catch (EVC_Provenance_Conflict_Exception $e) {
            return EVC_Record_Outcome::conflict('concurrent_update');
        } catch (Throwable $e) {
            return EVC_Record_Outcome::unavailable();
        }
    }

    private function record_or_throw(EVC_Verified_Movement $m): EVC_Record_Outcome {
        if ($m->state() !== EVC_Verified_Movement::STATE_CONFIRMED) {
            return EVC_Record_Outcome::indeterminate('movement_not_confirmed');
        }
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

        $existing = $this->store->movement($m->movement_id());
        if ($existing !== null) {
            return $this->repeat_of($existing, $m);
        }

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
        $period = new EVC_Funded_Period(
            $m->movement_id(),
            $m->wp_user_id(),
            $m->level_id(),
            $m->pmpro_row_ref(),
            $next['start'],
            $next['end'],
            $latest === null ? EVC_Funded_Period::KIND_INITIAL : $next['renewal'],
            $latest === null ? null : $latest->movement_id()
        );
        $this->store->commit(EVC_Provenance_Write::new_movement($m, $period));
        return EVC_Record_Outcome::recorded($period);
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

    private function correct_or_throw(EVC_Movement_Correction $c): EVC_Record_Outcome {
        if ($this->store->movement($c->movement_id()) === null) {
            // E.g. a refund seen before its payment: nothing to attach it to.
            return EVC_Record_Outcome::indeterminate('unknown_movement');
        }
        $existing = $this->store->correction($c->correction_id());
        if ($existing !== null) {
            return $existing->same_as($c) ? EVC_Record_Outcome::already(null) : EVC_Record_Outcome::conflict('correction_conflict');
        }
        $history = $this->store->corrections_for($c->movement_id());
        $current = $history === array() ? null : $history[count($history) - 1]->kind();
        if (!EVC_Movement_Correction::allowed_after($current, $c->kind())) {
            return EVC_Record_Outcome::conflict('correction_state_regression');
        }
        $this->store->commit(EVC_Provenance_Write::correction($c));
        return EVC_Record_Outcome::recorded(null);
    }
}
