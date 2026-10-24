<?php
use PHPUnit\Framework\TestCase;

/**
 * STAGE-03B.2: pure recovery decisions over a durable BACS intent. Every
 * branch is driven from facts, never from the intent's own state alone.
 */
final class BacsRecoveryPolicyTest extends TestCase {
    const LEVEL = EVC_Pmpro_Fixture::CLUB_LEVEL;

    private static function ref(string $l): string {
        return EVC_Bacs_Intent_Fixture::ref($l);
    }

    private static function codec(): EVC_Bacs_Intent_Codec {
        return EVC_Bacs_Intent_Fixture::codec();
    }

    private static function read(EVC_Bacs_Intent $i): EVC_Bacs_Intent_Read {
        return self::codec()->decode(self::codec()->encode($i), $i->order_id(), $i->customer_id(), 'live');
    }

    private static function order(int $id = 100, string $status = 'on-hold', array $o = array()): EVC_Bacs_Order_Facts {
        return new EVC_Bacs_Order_Facts($id, $o['customer'] ?? 42, $status, $o['method'] ?? 'bacs', $o['currency'] ?? 'EUR', $o['total'] ?? '20.00', $o['single'] ?? true);
    }

    /** Active Club row showing [start, end) in Athens wall clock. */
    private static function row(string $label, string $start_local, string $end_local, string $status = 'active', int $level = self::LEVEL): EVC_Pmpro_Membership_Row {
        return new EVC_Pmpro_Membership_Row(self::ref($label), $level, $status, $start_local, $end_local);
    }

    private static function athens(DateTimeImmutable $i): string {
        return $i->setTimezone(new DateTimeZone('Europe/Athens'))->format('Y-m-d H:i:s');
    }

    /** The row PMPro shows when it issued EXACTLY the intent's plan. */
    private static function issued_row(EVC_Bacs_Intent $i, string $label = 'row:new'): EVC_Pmpro_Membership_Row {
        return self::row($label, self::athens($i->run_start_utc()), self::athens($i->period_end_utc()));
    }

    private static function recorded_movement(EVC_Bacs_Intent $i, string $row_label = 'row:new', array $o = array()): EVC_Verified_Movement {
        return new EVC_Verified_Movement(
            $i->movement_id(),
            'live',
            $o['user'] ?? $i->customer_id(),
            $i->level_id(),
            self::ref($row_label),
            $o['at'] ?? $i->verified_at_utc(),
            2000,
            'EUR',
            array(new EVC_Movement_Alias(EVC_Movement_Alias::NS_WC_ORDER, $o['anchor'] ?? $i->order_anchor())),
            EVC_Verified_Movement::AUTHORITY_MANUAL_ADMIN,
            EVC_Payment_Evidence::KIND_MANUAL_CONFIRMATION,
            EVC_Verified_Movement::STATE_CONFIRMED
        );
    }

    private static function recorded_period(EVC_Bacs_Intent $i, string $row_label = 'row:new', ?DateTimeImmutable $end = null): EVC_Funded_Period {
        return new EVC_Funded_Period($i->movement_id(), $i->customer_id(), $i->level_id(), self::ref($row_label), $i->period_start_utc(), $end ?? $i->period_end_utc(), $i->period_kind(), $i->predecessor_movement_id());
    }

    /** @param array<string,mixed> $o */
    private static function facts(EVC_Bacs_Intent $i, array $o = array()): EVC_Bacs_Recovery_Facts {
        return new EVC_Bacs_Recovery_Facts(
            'live',
            self::LEVEL,
            $o['actor'] ?? EVC_Bacs_Intent_Fixture::ACTOR,
            $o['authorized'] ?? true,
            $o['read'] ?? self::read($i),
            array_key_exists('order', $o) ? $o['order'] : self::order($i->order_id()),
            array_key_exists('rows', $o) ? $o['rows'] : array(),
            $o['club'] ?? true,
            $o['movement'] ?? null,
            $o['period'] ?? null,
            $o['corrections'] ?? array(),
            $o['owner'] ?? null,
            array_key_exists('plan', $o) ? $o['plan'] : $i->as_plan()
        );
    }

    private function decide(EVC_Bacs_Recovery_Facts $f): EVC_Bacs_Recovery_Decision {
        return (new EVC_Bacs_Recovery_Policy())->decide($f);
    }

    private function assertDecision(EVC_Bacs_Recovery_Decision $d, string $outcome, ?string $reason = null, ?string $step = null): void {
        $this->assertSame($outcome, $d->outcome, 'reason: ' . (string) $d->reason);
        if ($reason !== null) {
            $this->assertSame($reason, $d->reason);
        }
        if ($step !== null) {
            $this->assertSame($step, $d->step);
        }
    }

    // ---- resumable states ---------------------------------------------------------------------

    public function test_planned_and_awaiting_order_resumes_with_completion(): void {
        $i = EVC_Bacs_Intent_Fixture::initial();
        $d = $this->decide(self::facts($i));
        $this->assertDecision($d, EVC_Bacs_Recovery_Decision::RESUME, null, EVC_Bacs_Recovery_Decision::STEP_COMPLETE_ORDER);
        $this->assertFalse($d->paid_time_at_risk);
    }

    public function test_crash_after_issuance_resumes_by_confirming_the_verified_row(): void {
        $i = EVC_Bacs_Intent_Fixture::initial();
        $d = $this->decide(self::facts($i, array('order' => self::order(100, 'completed'), 'rows' => array(self::issued_row($i)))));
        $this->assertDecision($d, EVC_Bacs_Recovery_Decision::RESUME, null, EVC_Bacs_Recovery_Decision::STEP_CONFIRM_ISSUANCE);
        $this->assertSame(self::ref('row:new'), $d->row_ref);
    }

    public function test_issued_intent_resumes_by_recording_the_movement_on_that_row(): void {
        $i = EVC_Bacs_Intent_Fixture::initial()->issued(self::ref('row:new'));
        $d = $this->decide(self::facts($i, array('order' => self::order(100, 'completed'), 'rows' => array(self::issued_row($i)))));
        $this->assertDecision($d, EVC_Bacs_Recovery_Decision::RESUME, null, EVC_Bacs_Recovery_Decision::STEP_RECORD_MOVEMENT);
        $this->assertSame(self::ref('row:new'), $d->row_ref);
    }

    // ---- 15: already recorded (replay creates no second month) ----------------------------------

    public function test_recorded_movement_period_and_row_is_already_complete(): void {
        $i = EVC_Bacs_Intent_Fixture::initial()->issued(self::ref('row:new'));
        $base = array('order' => self::order(100, 'completed'), 'rows' => array(self::issued_row($i)), 'movement' => self::recorded_movement($i), 'period' => self::recorded_period($i), 'owner' => $i->movement_id(), 'plan' => null);
        $this->assertDecision($this->decide(self::facts($i->recorded(), $base)), EVC_Bacs_Recovery_Decision::ALREADY_COMPLETE);
        $this->assertNull($this->decide(self::facts($i->recorded(), $base))->reason);
        // Crash after recording, before the intent caught up: still complete, nothing to redo.
        $this->assertDecision($this->decide(self::facts($i, $base)), EVC_Bacs_Recovery_Decision::ALREADY_COMPLETE, 'intent_state_behind');
        $this->assertDecision($this->decide(self::facts(EVC_Bacs_Intent_Fixture::initial(), $base)), EVC_Bacs_Recovery_Decision::ALREADY_COMPLETE, 'intent_state_behind');
    }

    public function test_recorded_but_contradicting_the_intent_is_a_conflict(): void {
        $i = EVC_Bacs_Intent_Fixture::initial()->issued(self::ref('row:new'));
        $base = array('order' => self::order(100, 'completed'), 'rows' => array(self::issued_row($i)), 'owner' => $i->movement_id(), 'plan' => null);
        $cases = array(
            'recorded_movement_differs' => array('movement' => self::recorded_movement($i, 'row:new', array('at' => $i->verified_at_utc()->modify('+1 second'))), 'period' => self::recorded_period($i)),
            'recorded_period_differs' => array('movement' => self::recorded_movement($i), 'period' => self::recorded_period($i, 'row:new', $i->period_end_utc()->modify('-1 second'))),
            'recorded_row_differs_from_intent' => array('movement' => self::recorded_movement($i, 'row:other'), 'period' => self::recorded_period($i, 'row:other')),
        );
        foreach ($cases as $reason => $o) {
            $this->assertDecision($this->decide(self::facts($i, $o + $base)), EVC_Bacs_Recovery_Decision::CONFLICT, $reason);
        }
        $this->assertDecision($this->decide(self::facts($i, array('movement' => self::recorded_movement($i, 'row:new', array('anchor' => self::ref('anchor:x'))), 'period' => self::recorded_period($i)) + $base)), EVC_Bacs_Recovery_Decision::CONFLICT, 'recorded_movement_differs');
    }

    public function test_recorded_but_corrected_or_membership_changed_needs_a_manager(): void {
        $i = EVC_Bacs_Intent_Fixture::initial()->issued(self::ref('row:new'));
        $base = array('order' => self::order(100, 'completed'), 'movement' => self::recorded_movement($i), 'period' => self::recorded_period($i), 'owner' => $i->movement_id(), 'plan' => null);
        $refund = new EVC_Movement_Correction(self::ref('corr:1'), $i->movement_id(), EVC_Movement_Correction::KIND_REFUNDED, EVC_Verified_Movement::AUTHORITY_MANUAL_ADMIN, EVC_Pmpro_Fixture::athens('2026-10-20 10:00:00'));
        $this->assertDecision($this->decide(self::facts($i, array('rows' => array(self::issued_row($i)), 'corrections' => array($refund)) + $base)), EVC_Bacs_Recovery_Decision::MANAGER_REQUIRED, 'recorded_payment_corrected');
        $this->assertDecision($this->decide(self::facts($i, array('rows' => array()) + $base)), EVC_Bacs_Recovery_Decision::MANAGER_REQUIRED, 'recorded_but_membership_differs');
        $shifted = self::row('row:new', self::athens($i->run_start_utc()), '2026-11-15 13:00:00');
        $this->assertDecision($this->decide(self::facts($i, array('rows' => array($shifted)) + $base)), EVC_Bacs_Recovery_Decision::MANAGER_REQUIRED, 'recorded_but_membership_differs');
        $this->assertDecision($this->decide(self::facts($i, array('rows' => array(self::issued_row($i)), 'order' => self::order(100, 'on-hold')) + $base)), EVC_Bacs_Recovery_Decision::CONFLICT, 'recorded_but_order_not_completed');
    }

    // ---- 12/13: stale plan ------------------------------------------------------------------------

    public function test_a_plan_that_changed_since_the_intent_stops_for_a_manager(): void {
        $i = EVC_Bacs_Intent_Fixture::initial();
        $later = EVC_Pmpro_Fixture::athens('2026-10-16 12:00:00');
        $other = EVC_Period_Plan::planned($i->movement_id(), $later, EVC_Membership_Calendar::add_calendar_month($later), 'initial', null);
        $this->assertDecision($this->decide(self::facts($i, array('plan' => $other))), EVC_Bacs_Recovery_Decision::MANAGER_REQUIRED, 'plan_stale');
        $early = EVC_Period_Plan::planned($i->movement_id(), $i->period_start_utc(), $i->period_end_utc(), 'late', self::ref('mv:someone'));
        $this->assertDecision($this->decide(self::facts($i, array('plan' => $early))), EVC_Bacs_Recovery_Decision::MANAGER_REQUIRED, 'plan_stale');
        $this->assertDecision($this->decide(self::facts($i, array('plan' => EVC_Period_Plan::refused(EVC_Record_Outcome::indeterminate('same_instant_payment'))))), EVC_Bacs_Recovery_Decision::MANAGER_REQUIRED, 'plan_same_instant_payment');
        $this->assertDecision($this->decide(self::facts($i, array('plan' => EVC_Period_Plan::refused(EVC_Record_Outcome::conflict('alias_conflict'))))), EVC_Bacs_Recovery_Decision::CONFLICT, 'plan_alias_conflict');
        $this->assertDecision($this->decide(self::facts($i, array('plan' => EVC_Period_Plan::unavailable('store_unavailable')))), EVC_Bacs_Recovery_Decision::UNAVAILABLE, 'plan_unavailable');
        $this->assertDecision($this->decide(self::facts($i, array('plan' => null))), EVC_Bacs_Recovery_Decision::UNAVAILABLE, 'plan_unavailable');
    }

    // ---- 14: PMPro row missing / mismatching -----------------------------------------------------

    public function test_completed_order_without_the_planned_row_needs_a_manager(): void {
        $i = EVC_Bacs_Intent_Fixture::initial();
        $completed = array('order' => self::order(100, 'completed'));
        $this->assertDecision($this->decide(self::facts($i, array('rows' => array()) + $completed)), EVC_Bacs_Recovery_Decision::MANAGER_REQUIRED, 'membership_row_missing');
        // The add-on's own dates (minute-rounded "+1 month" from completion): never accepted.
        $addon = self::row('row:new', '2026-10-15 12:03:00', '2026-11-15 12:03:00');
        $this->assertDecision($this->decide(self::facts($i, array('rows' => array($addon)) + $completed)), EVC_Bacs_Recovery_Decision::MANAGER_REQUIRED, 'issuance_mismatch');
        $this->assertDecision($this->decide(self::facts($i, array('rows' => array(self::issued_row($i), self::issued_row($i, 'row:dup'))) + $completed)), EVC_Bacs_Recovery_Decision::CONFLICT, 'multiple_active_membership_rows');
        // Rows of another level, or inactive rows, are not the Club membership.
        $other_level = self::row('row:x', self::athens($i->run_start_utc()), self::athens($i->period_end_utc()), 'active', EVC_Pmpro_Fixture::OTHER_LEVEL);
        $old = self::row('row:y', self::athens($i->run_start_utc()), self::athens($i->period_end_utc()), 'changed');
        $this->assertDecision($this->decide(self::facts($i, array('rows' => array($other_level, $old)) + $completed)), EVC_Bacs_Recovery_Decision::MANAGER_REQUIRED, 'membership_row_missing');
    }

    public function test_issued_intent_whose_row_was_replaced_is_a_conflict(): void {
        $i = EVC_Bacs_Intent_Fixture::initial()->issued(self::ref('row:new'));
        $this->assertDecision($this->decide(self::facts($i, array('order' => self::order(100, 'completed'), 'rows' => array(self::issued_row($i, 'row:other'))))), EVC_Bacs_Recovery_Decision::CONFLICT, 'issued_row_changed');
    }

    public function test_awaiting_order_with_an_unexpected_row_is_a_conflict(): void {
        $i = EVC_Bacs_Intent_Fixture::initial();
        $this->assertDecision($this->decide(self::facts($i, array('rows' => array(self::issued_row($i))))), EVC_Bacs_Recovery_Decision::CONFLICT, 'unexpected_membership_row');
        $this->assertDecision($this->decide(self::facts($i->issued(self::ref('row:new')), array('rows' => array()))), EVC_Bacs_Recovery_Decision::CONFLICT, 'intent_issued_but_order_not_completed');
    }

    // ---- 16: completed order without provenance -----------------------------------------------

    public function test_completed_order_without_an_intent_is_never_paid(): void {
        $i = EVC_Bacs_Intent_Fixture::initial();
        $this->assertDecision($this->decide(self::facts($i, array('read' => EVC_Bacs_Intent_Read::missing(), 'order' => self::order(100, 'completed'), 'rows' => array(self::issued_row($i))))), EVC_Bacs_Recovery_Decision::MANAGER_REQUIRED, 'completed_order_without_intent');
        $this->assertDecision($this->decide(self::facts($i, array('read' => EVC_Bacs_Intent_Read::missing()))), EVC_Bacs_Recovery_Decision::MANAGER_REQUIRED, 'no_intent');
    }

    public function test_stored_lifecycle_state_alone_is_never_trusted(): void {
        $recorded = EVC_Bacs_Intent_Fixture::initial()->issued(self::ref('row:new'))->recorded();
        // Says "recorded", but the Club has nothing.
        $this->assertDecision($this->decide(self::facts($recorded, array('order' => self::order(100, 'completed'), 'rows' => array(self::issued_row($recorded))))), EVC_Bacs_Recovery_Decision::CONFLICT, 'intent_claims_recorded_without_provenance');
        foreach (array(EVC_Bacs_Intent_Fixture::initial()->issuance_mismatch(), EVC_Bacs_Intent_Fixture::initial()->recovery_required()) as $sticky) {
            $d = $this->decide(self::facts($sticky, array('order' => self::order(100, 'completed'), 'rows' => array(self::issued_row($sticky)))));
            $this->assertDecision($d, EVC_Bacs_Recovery_Decision::MANAGER_REQUIRED, 'intent_' . $sticky->state());
        }
    }

    // ---- 17: early renewal ----------------------------------------------------------------------

    public function test_early_renewal_with_its_paid_row_still_active_resumes(): void {
        $i = EVC_Bacs_Intent_Fixture::early('2026-11-10 09:00:00', '2026-10-15 12:00:00', '2026-11-15 12:00:00');
        $paid = self::row('row:old', '2026-10-15 12:00:00', '2026-11-15 12:00:00');
        $d = $this->decide(self::facts($i, array('order' => self::order(101), 'rows' => array($paid))));
        $this->assertDecision($d, EVC_Bacs_Recovery_Decision::RESUME, null, EVC_Bacs_Recovery_Decision::STEP_COMPLETE_ORDER);
        $this->assertFalse($d->paid_time_at_risk);
    }

    public function test_early_renewal_after_pmpro_replaced_the_paid_row_flags_paid_time_at_risk(): void {
        $i = EVC_Bacs_Intent_Fixture::early('2026-11-10 09:00:00', '2026-10-15 12:00:00', '2026-11-15 12:00:00');
        $replaced = self::row('row:old', '2026-10-15 12:00:00', '2026-11-10 09:00:05', 'changed');
        $new = self::row('row:new', '2026-10-15 12:00:00', self::athens($i->period_end_utc()));
        $d = $this->decide(self::facts($i, array('order' => self::order(101, 'completed'), 'rows' => array($replaced, $new))));
        $this->assertDecision($d, EVC_Bacs_Recovery_Decision::RESUME, null, EVC_Bacs_Recovery_Decision::STEP_CONFIRM_ISSUANCE);
        $this->assertTrue($d->paid_time_at_risk, 'the member is "unverified" until the movement is recorded');
        // PMPro cancelled the paid row and inserted nothing: paid time lost, a manager must act.
        $d = $this->decide(self::facts($i, array('order' => self::order(101, 'completed'), 'rows' => array($replaced))));
        $this->assertDecision($d, EVC_Bacs_Recovery_Decision::MANAGER_REQUIRED, 'membership_row_missing');
        $this->assertTrue($d->paid_time_at_risk);
        // The paid row vanished while the order still awaits payment.
        $d = $this->decide(self::facts($i, array('order' => self::order(101), 'rows' => array($replaced))));
        $this->assertDecision($d, EVC_Bacs_Recovery_Decision::MANAGER_REQUIRED, 'paid_membership_row_missing');
        $this->assertTrue($d->paid_time_at_risk);
    }

    public function test_early_renewal_new_row_must_keep_the_paid_run_start(): void {
        $i = EVC_Bacs_Intent_Fixture::early('2026-11-10 09:00:00', '2026-10-15 12:00:00', '2026-11-15 12:00:00');
        $restarted = self::row('row:new', '2026-11-15 12:00:00', self::athens($i->period_end_utc()));
        $this->assertDecision($this->decide(self::facts($i, array('order' => self::order(101, 'completed'), 'rows' => array($restarted)))), EVC_Bacs_Recovery_Decision::MANAGER_REQUIRED, 'issuance_mismatch');
        $same = self::row('row:old', '2026-10-15 12:00:00', self::athens($i->period_end_utc()));
        $this->assertDecision($this->decide(self::facts($i, array('order' => self::order(101, 'completed'), 'rows' => array($same)))), EVC_Bacs_Recovery_Decision::MANAGER_REQUIRED, 'membership_not_reissued');
    }

    // ---- 18: authorisation, bindings, order --------------------------------------------------

    public function test_unauthorised_actor_or_own_order_is_refused_before_anything_else(): void {
        $i = EVC_Bacs_Intent_Fixture::initial();
        $this->assertDecision($this->decide(self::facts($i, array('authorized' => false, 'read' => EVC_Bacs_Intent_Read::unavailable('x'), 'order' => null))), EVC_Bacs_Recovery_Decision::REFUSED, 'actor_not_authorized');
        $this->assertDecision($this->decide(self::facts($i, array('actor' => 42))), EVC_Bacs_Recovery_Decision::REFUSED, 'own_order');
    }

    public function test_bindings_and_order_facts_are_rechecked(): void {
        $i = EVC_Bacs_Intent_Fixture::initial();
        $this->assertDecision($this->decide(self::facts($i, array('order' => self::order(100, 'on-hold', array('customer' => 43))))), EVC_Bacs_Recovery_Decision::CONFLICT, 'intent_binding_mismatch');
        $this->assertDecision($this->decide(self::facts($i, array('order' => self::order(999)))), EVC_Bacs_Recovery_Decision::CONFLICT, 'intent_binding_mismatch');
        foreach (array(array('method' => 'cod'), array('currency' => 'USD'), array('total' => '20.01'), array('single' => false)) as $o) {
            $this->assertDecision($this->decide(self::facts($i, array('order' => self::order(100, 'on-hold', $o)))), EVC_Bacs_Recovery_Decision::CONFLICT, 'order_changed');
        }
        foreach (array('cancelled', 'refunded', 'failed') as $status) {
            $this->assertDecision($this->decide(self::facts($i, array('order' => self::order(100, $status)))), EVC_Bacs_Recovery_Decision::MANAGER_REQUIRED, 'order_ended');
        }
        $this->assertDecision($this->decide(self::facts($i, array('order' => self::order(100, 'processing')))), EVC_Bacs_Recovery_Decision::CONFLICT, 'order_status_unknown');
        $this->assertDecision($this->decide(self::facts($i, array('owner' => self::ref('mv:another-transfer')))), EVC_Bacs_Recovery_Decision::CONFLICT, 'order_funded_by_another_transfer');
    }

    public function test_corrections_recorded_for_an_unrecorded_transfer_stop_recovery(): void {
        $i = EVC_Bacs_Intent_Fixture::initial();
        $refund = new EVC_Movement_Correction(self::ref('corr:1'), $i->movement_id(), EVC_Movement_Correction::KIND_REFUNDED, EVC_Verified_Movement::AUTHORITY_MANUAL_ADMIN, EVC_Pmpro_Fixture::athens('2026-10-20 10:00:00'));
        $this->assertDecision($this->decide(self::facts($i, array('corrections' => array($refund)))), EVC_Bacs_Recovery_Decision::MANAGER_REQUIRED, 'correction_recorded_for_transfer');
    }

    // ---- invalid intent / unavailable sources ---------------------------------------------------

    public function test_invalid_intents_are_conflicts_and_unreadable_sources_unavailable(): void {
        $i = EVC_Bacs_Intent_Fixture::initial();
        $this->assertDecision($this->decide(self::facts($i, array('read' => EVC_Bacs_Intent_Read::invalid('signature_mismatch')))), EVC_Bacs_Recovery_Decision::CONFLICT, 'intent_signature_mismatch');
        $this->assertDecision($this->decide(self::facts($i, array('read' => EVC_Bacs_Intent_Read::unavailable('read_failed')))), EVC_Bacs_Recovery_Decision::UNAVAILABLE, 'intent_unreadable');
        $this->assertDecision($this->decide(self::facts($i, array('order' => null))), EVC_Bacs_Recovery_Decision::UNAVAILABLE, 'order_unreadable');
        $this->assertDecision($this->decide(self::facts($i, array('rows' => null))), EVC_Bacs_Recovery_Decision::UNAVAILABLE, 'membership_unreadable');
        $this->assertDecision($this->decide(self::facts($i, array('club' => false))), EVC_Bacs_Recovery_Decision::UNAVAILABLE, 'club_record_unreadable');
    }

    // ---- 20: a decision grants nothing ----------------------------------------------------------

    public function test_a_decision_has_no_eligibility_or_activation_api(): void {
        foreach (array(EVC_Bacs_Recovery_Decision::class, EVC_Bacs_Intent::class, EVC_Bacs_Recovery_Policy::class) as $class) {
            foreach (array('is_eligible_at', 'payment_verified', 'activate', 'grant', 'entitlement', 'complete_order', 'issue') as $method) {
                $this->assertFalse((new ReflectionClass($class))->hasMethod($method), $class . '::' . $method);
            }
        }
    }
}
