<?php
use PHPUnit\Framework\TestCase;

/**
 * STAGE-03B.1: advisory, read-only pre-issuance period planning
 * (EVC_Provenance_Recorder::plan over EVC_Prospective_Payment). Synthetic
 * members and opaque references only. Expected periods are written out by
 * hand (Europe/Athens wall-clock, or UTC where DST matters).
 */
final class PeriodPlanTest extends TestCase {
    const USER = EVC_Provenance_Test_Fixture::USER;

    private static function ref(string $label): string {
        return EVC_Pmpro_Fixture::ref($label);
    }

    private static function local(DateTimeImmutable $instant): string {
        return $instant->setTimezone(new DateTimeZone('Europe/Athens'))->format('Y-m-d H:i:s');
    }

    private static function utc(DateTimeImmutable $instant): string {
        return $instant->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** The prospective twin of EVC_Provenance_Test_Fixture::movement() (no PMPro row, no state). */
    private static function prospective(string $label, string $at_athens, array $o = array()): EVC_Prospective_Payment {
        return new EVC_Prospective_Payment(
            $o['id'] ?? self::ref('mv:' . $label),
            $o['env'] ?? 'live',
            $o['user'] ?? self::USER,
            $o['level'] ?? EVC_Pmpro_Fixture::CLUB_LEVEL,
            $o['at'] ?? EVC_Pmpro_Fixture::athens($at_athens),
            $o['amount'] ?? 2000,
            $o['currency'] ?? 'EUR',
            $o['aliases'] ?? array(
                new EVC_Movement_Alias(EVC_Movement_Alias::NS_WC_ORDER, self::ref('order:' . $label)),
                new EVC_Movement_Alias(EVC_Movement_Alias::NS_VIVA_TRANSACTION, self::ref('viva:' . $label)),
            ),
            $o['authority'] ?? EVC_Verified_Movement::AUTHORITY_GATEWAY_API,
            $o['kind'] ?? EVC_Payment_Evidence::KIND_WC_ORDER
        );
    }

    private static function movement(string $label, string $at_athens, array $o = array()): EVC_Verified_Movement {
        return EVC_Provenance_Test_Fixture::movement($label, $at_athens, $o);
    }

    private static function recorder(EVC_Provenance_Store $store): EVC_Provenance_Recorder {
        return EVC_Provenance_Test_Fixture::recorder($store);
    }

    /** Plans through a spy that fails on any write, then checks the wrapped store is byte-for-byte as before. */
    private function plan_read_only(EVC_In_Memory_Provenance_Store $store, EVC_Prospective_Payment $p): EVC_Period_Plan {
        $before = serialize($store);
        $commits = $store->commits;
        $spy = new EVC_Read_Only_Provenance_Store_Spy($store);
        $plan = self::recorder($spy)->plan($p);
        $this->assertSame(0, $spy->write_attempts, 'plan() attempted a write');
        $this->assertSame($commits, $store->commits);
        $this->assertSame($before, serialize($store), 'plan() changed the store');
        return $plan;
    }

    private function assertPlan(EVC_Period_Plan $plan, string $outcome, ?string $reason = null): void {
        $this->assertSame($outcome, $plan->outcome(), 'reason: ' . (string) $plan->reason());
        if ($reason !== null) {
            $this->assertSame($reason, $plan->reason());
        }
    }

    private function assertPlanned(EVC_Period_Plan $plan, string $kind, string $start_local, string $end_local, ?string $predecessor_label): void {
        $this->assertPlan($plan, EVC_Period_Plan::PLANNED);
        $this->assertSame($kind, $plan->kind());
        $this->assertSame($start_local, self::local($plan->start_utc()));
        $this->assertSame($end_local, self::local($plan->end_utc()));
        $this->assertSame($predecessor_label === null ? null : self::ref('mv:' . $predecessor_label), $plan->predecessor_movement_id());
        $this->assertNull($plan->recorded_period());
    }

    private function assertNothingRecorded(EVC_In_Memory_Provenance_Store $store): void {
        $this->assertSame(array('movements' => 0, 'aliases' => 0, 'periods' => 0, 'corrections' => 0), $store->counts());
        $this->assertSame(0, $store->commits);
    }

    // ---- 1-3: first month, early and late renewal ---------------------------

    public function test_first_paid_month_is_planned_from_the_confirmation_instant(): void {
        $store = new EVC_In_Memory_Provenance_Store();
        $plan = $this->plan_read_only($store, self::prospective('oct', '2026-10-15 12:00:00'));
        $this->assertPlanned($plan, EVC_Funded_Period::KIND_INITIAL, '2026-10-15 12:00:00', '2026-11-15 12:00:00', null);
        $this->assertSame(self::ref('mv:oct'), $plan->movement_id());
        $this->assertNothingRecorded($store);
    }

    public function test_early_renewal_starts_at_the_current_paid_end_and_keeps_paid_time(): void {
        $store = new EVC_In_Memory_Provenance_Store();
        self::recorder($store)->record(self::movement('oct', '2026-10-15 12:00:00'));
        $plan = $this->plan_read_only($store, self::prospective('nov', '2026-11-10 09:00:00'));
        $this->assertPlanned($plan, EVC_Funded_Period::KIND_EARLY, '2026-11-15 12:00:00', '2026-12-15 12:00:00', 'oct');
    }

    public function test_late_renewal_after_expiry_starts_at_the_confirmation_instant(): void {
        $store = new EVC_In_Memory_Provenance_Store();
        self::recorder($store)->record(self::movement('oct', '2026-10-15 12:00:00'));
        $plan = $this->plan_read_only($store, self::prospective('nov', '2026-11-20 08:15:00'));
        $this->assertPlanned($plan, EVC_Funded_Period::KIND_LATE, '2026-11-20 08:15:00', '2026-12-20 08:15:00', 'oct');
    }

    public function test_renewal_exactly_at_the_exclusive_paid_end_is_late(): void {
        $store = new EVC_In_Memory_Provenance_Store();
        self::recorder($store)->record(self::movement('oct', '2026-10-15 12:00:00'));
        $plan = $this->plan_read_only($store, self::prospective('nov', '2026-11-15 12:00:00'));
        $this->assertPlanned($plan, EVC_Funded_Period::KIND_LATE, '2026-11-15 12:00:00', '2026-12-15 12:00:00', 'oct');
    }

    // ---- 4-6: calendar (existing D5 + DST rules, unchanged) -----------------

    public function test_january_31_is_clamped_to_february_and_the_anniversary_drifts(): void {
        $store = new EVC_In_Memory_Provenance_Store();
        $first = $this->plan_read_only($store, self::prospective('jan', '2027-01-31 10:00:00'));
        $this->assertPlanned($first, EVC_Funded_Period::KIND_INITIAL, '2027-01-31 10:00:00', '2027-02-28 10:00:00', null);

        self::recorder($store)->record(self::movement('jan', '2027-01-31 10:00:00'));
        $renewal = $this->plan_read_only($store, self::prospective('feb', '2027-02-20 10:00:00'));
        $this->assertPlanned($renewal, EVC_Funded_Period::KIND_EARLY, '2027-02-28 10:00:00', '2027-03-28 10:00:00', 'jan');
    }

    public function test_leap_year_february_has_29_days(): void {
        $store = new EVC_In_Memory_Provenance_Store();
        $this->assertPlanned($this->plan_read_only($store, self::prospective('jan', '2028-01-31 10:00:00')), EVC_Funded_Period::KIND_INITIAL, '2028-01-31 10:00:00', '2028-02-29 10:00:00', null);
        $this->assertPlanned($this->plan_read_only($store, self::prospective('feb', '2028-02-29 10:00:00')), EVC_Funded_Period::KIND_INITIAL, '2028-02-29 10:00:00', '2028-03-29 10:00:00', null);
    }

    /** End in the repeated autumn hour: the EXISTING rule takes its first occurrence (EEST). */
    public function test_autumn_dst_end_uses_the_existing_first_occurrence_rule(): void {
        $store = new EVC_In_Memory_Provenance_Store();
        $start = EVC_Pmpro_Fixture::utc('2026-09-25T00:30:00Z'); // 03:30 EEST
        $plan = $this->plan_read_only($store, self::prospective('sep', '', array('at' => $start)));
        $this->assertPlan($plan, EVC_Period_Plan::PLANNED);
        $this->assertSame('2026-10-25 00:30:00', self::utc($plan->end_utc())); // 03:30 EEST, not 03:30 EET (01:30 UTC)
        $this->assertSame(EVC_Membership_Calendar::RESOLVED_REPEATED_FIRST, EVC_Membership_Calendar::add_calendar_month_resolved($start)['resolution']);
        $this->assertEquals(EVC_Membership_Calendar::add_calendar_month($start), $plan->end_utc());
    }

    /** End in the skipped spring hour: the EXISTING rule takes the transition instant. */
    public function test_spring_dst_end_uses_the_existing_transition_rule(): void {
        $store = new EVC_In_Memory_Provenance_Store();
        $start = EVC_Pmpro_Fixture::athens('2027-02-28 03:30:00');
        $plan = $this->plan_read_only($store, self::prospective('feb', '', array('at' => $start)));
        $this->assertPlan($plan, EVC_Period_Plan::PLANNED);
        $this->assertSame('2027-03-28 01:00:00', self::utc($plan->end_utc())); // 04:00 EEST, never 04:30
        $this->assertSame(EVC_Membership_Calendar::RESOLVED_SKIPPED_TRANSITION, EVC_Membership_Calendar::add_calendar_month_resolved($start)['resolution']);
    }

    /** The plan and the later recording compute the SAME period (one shared implementation). */
    public function test_plan_equals_what_record_computes_for_dst_month_end_and_renewals(): void {
        $cases = array(
            array(array(), 'x', EVC_Pmpro_Fixture::utc('2026-09-25T00:30:00Z')),
            array(array(), 'x', EVC_Pmpro_Fixture::athens('2027-02-28 03:30:00')),
            array(array(), 'x', EVC_Pmpro_Fixture::athens('2027-01-31 10:00:00')),
            array(array(array('a', '2026-10-15 12:00:00')), 'x', EVC_Pmpro_Fixture::athens('2026-11-10 09:00:00')),
            array(array(array('a', '2026-10-15 12:00:00')), 'x', EVC_Pmpro_Fixture::athens('2026-11-20 09:00:00')),
        );
        foreach ($cases as $i => $case) {
            $store = new EVC_In_Memory_Provenance_Store();
            foreach ($case[0] as $prior) {
                self::recorder($store)->record(self::movement($prior[0], $prior[1]));
            }
            $p = self::prospective($case[1], '', array('at' => $case[2]));
            $plan = $this->plan_read_only($store, $p);
            // The row PMPro would issue afterwards; the plan never knew it.
            $m = new EVC_Verified_Movement($p->movement_id(), 'live', self::USER, EVC_Pmpro_Fixture::CLUB_LEVEL, self::ref('row-new'), $case[2], 2000, 'EUR', $p->aliases(), $p->authority(), $p->evidence_kind(), EVC_Verified_Movement::STATE_CONFIRMED);
            $this->assertTrue($p->matches($m));
            $recorded = self::recorder($store)->record($m);
            $this->assertSame(EVC_Record_Outcome::NEWLY_RECORDED, $recorded->outcome(), 'case ' . $i);
            $this->assertTrue($plan->matches_recorded($recorded->period()), 'case ' . $i);
        }
    }

    // ---- 7-9: identity and ordering -----------------------------------------

    public function test_duplicate_transfer_identity_for_another_order_is_a_conflict(): void {
        $store = new EVC_In_Memory_Provenance_Store();
        self::recorder($store)->record(self::movement('oct', '2026-10-15 12:00:00'));
        $other_order = array(new EVC_Movement_Alias(EVC_Movement_Alias::NS_WC_ORDER, self::ref('order:other')));
        $plan = $this->plan_read_only($store, self::prospective('oct', '2026-10-15 12:00:00', array('aliases' => $other_order)));
        $this->assertPlan($plan, EVC_Period_Plan::CONFLICT, 'order_anchor_conflict');
    }

    public function test_same_transfer_with_changed_facts_is_a_conflict(): void {
        $store = new EVC_In_Memory_Provenance_Store();
        self::recorder($store)->record(self::movement('oct', '2026-10-15 12:00:00'));
        $plan = $this->plan_read_only($store, self::prospective('oct', '2026-10-15 12:05:00'));
        $this->assertPlan($plan, EVC_Period_Plan::CONFLICT, 'movement_fact_conflict');
        $plan = $this->plan_read_only($store, self::prospective('oct', '2026-10-15 12:00:00', array('authority' => EVC_Verified_Movement::AUTHORITY_MANUAL_ADMIN)));
        $this->assertPlan($plan, EVC_Period_Plan::CONFLICT, 'movement_fact_conflict');
    }

    public function test_already_recorded_transfer_returns_its_recorded_period_without_writing(): void {
        $store = new EVC_In_Memory_Provenance_Store();
        $recorded = self::recorder($store)->record(self::movement('oct', '2026-10-15 12:00:00'))->period();
        // Fewer aliases than recorded, plus nothing new: still the same payment.
        $plan = $this->plan_read_only($store, self::prospective('oct', '2026-10-15 12:00:00', array('aliases' => array(new EVC_Movement_Alias(EVC_Movement_Alias::NS_WC_ORDER, self::ref('order:oct'))))));
        $this->assertPlan($plan, EVC_Period_Plan::ALREADY_RECORDED);
        $this->assertSame($recorded, $plan->recorded_period());
        $this->assertSame(self::ref('row1'), $plan->recorded_period()->pmpro_row_ref());
        $this->assertTrue($plan->matches_recorded($recorded));
    }

    public function test_already_recorded_transfer_with_a_new_alias_owned_elsewhere_is_a_conflict_and_adds_nothing(): void {
        $store = new EVC_In_Memory_Provenance_Store();
        self::recorder($store)->record(self::movement('oct', '2026-10-15 12:00:00'));
        self::recorder($store)->record(self::movement('nov', '2026-11-10 12:00:00'));
        $aliases = array(
            new EVC_Movement_Alias(EVC_Movement_Alias::NS_WC_ORDER, self::ref('order:oct')),
            new EVC_Movement_Alias(EVC_Movement_Alias::NS_VIVA_TRANSACTION, self::ref('viva:nov')),
        );
        $this->assertPlan($this->plan_read_only($store, self::prospective('oct', '2026-10-15 12:00:00', array('aliases' => $aliases))), EVC_Period_Plan::CONFLICT, 'alias_conflict');
    }

    public function test_already_recorded_transfer_never_records_new_aliases_while_planning(): void {
        $store = new EVC_In_Memory_Provenance_Store();
        self::recorder($store)->record(self::movement('oct', '2026-10-15 12:00:00'));
        $aliases = array(
            new EVC_Movement_Alias(EVC_Movement_Alias::NS_WC_ORDER, self::ref('order:oct')),
            new EVC_Movement_Alias(EVC_Movement_Alias::NS_PAYPAL_TRANSACTION, self::ref('paypal:new')),
        );
        $plan = $this->plan_read_only($store, self::prospective('oct', '2026-10-15 12:00:00', array('aliases' => $aliases)));
        $this->assertPlan($plan, EVC_Period_Plan::ALREADY_RECORDED);
        $this->assertNull($store->movement_for_alias((new EVC_Movement_Alias(EVC_Movement_Alias::NS_PAYPAL_TRANSACTION, self::ref('paypal:new')))->key()));
    }

    public function test_same_order_funded_twice_is_an_alias_conflict(): void {
        $store = new EVC_In_Memory_Provenance_Store();
        self::recorder($store)->record(self::movement('oct', '2026-10-15 12:00:00'));
        $same_order = array(new EVC_Movement_Alias(EVC_Movement_Alias::NS_WC_ORDER, self::ref('order:oct')));
        $plan = $this->plan_read_only($store, self::prospective('second-transfer', '2026-10-20 12:00:00', array('aliases' => $same_order)));
        $this->assertPlan($plan, EVC_Period_Plan::CONFLICT, 'alias_conflict');
    }

    public function test_same_second_confirmation_is_indeterminate_never_silently_ordered(): void {
        $store = new EVC_In_Memory_Provenance_Store();
        self::recorder($store)->record(self::movement('oct', '2026-10-15 12:00:00'));
        $this->assertPlan($this->plan_read_only($store, self::prospective('twin', '2026-10-15 12:00:00')), EVC_Period_Plan::INDETERMINATE, 'same_instant_payment');
    }

    public function test_out_of_order_confirmation_is_indeterminate(): void {
        $store = new EVC_In_Memory_Provenance_Store();
        self::recorder($store)->record(self::movement('oct', '2026-10-15 12:00:00'));
        $this->assertPlan($this->plan_read_only($store, self::prospective('earlier', '2026-10-15 11:59:59')), EVC_Period_Plan::INDETERMINATE, 'out_of_order_payment');
    }

    // ---- 10: corrections -----------------------------------------------------

    public function test_unresolved_refund_that_arrived_first_blocks_planning(): void {
        $store = new EVC_In_Memory_Provenance_Store();
        $q = self::recorder($store)->correct(EVC_Provenance_Test_Fixture::correction('r1', 'oct', EVC_Movement_Correction::KIND_REFUNDED));
        $this->assertSame(EVC_Record_Outcome::QUARANTINED, $q->outcome());
        $this->assertPlan($this->plan_read_only($store, self::prospective('oct', '2026-10-15 12:00:00')), EVC_Period_Plan::INDETERMINATE, 'unresolved_correction');
    }

    // ---- store-independent rules shared with record() ----------------------

    public function test_store_independent_refusals_match_record(): void {
        $cases = array(
            'environment_mismatch' => array('env' => 'sandbox'),
            'level_not_approved' => array('level' => EVC_Pmpro_Fixture::OTHER_LEVEL),
            'amount_mismatch' => array('amount' => 1999),
            'order_anchor_missing' => array('aliases' => array(new EVC_Movement_Alias(EVC_Movement_Alias::NS_VIVA_TRANSACTION, self::ref('viva:x')))),
        );
        foreach ($cases as $reason => $o) {
            $store = new EVC_In_Memory_Provenance_Store();
            $this->assertPlan($this->plan_read_only($store, self::prospective('x', '2026-10-15 12:00:00', $o)), EVC_Period_Plan::INDETERMINATE, $reason);
            $this->assertSame($reason, self::recorder($store)->record(self::movement('x', '2026-10-15 12:00:00', $o))->reason());
        }
        $two_orders = array(
            new EVC_Movement_Alias(EVC_Movement_Alias::NS_WC_ORDER, self::ref('order:a')),
            new EVC_Movement_Alias(EVC_Movement_Alias::NS_PMPRO_ORDER, self::ref('pmpro:b')),
        );
        $this->assertPlan($this->plan_read_only(new EVC_In_Memory_Provenance_Store(), self::prospective('x', '2026-10-15 12:00:00', array('aliases' => $two_orders))), EVC_Period_Plan::CONFLICT, 'multiple_order_anchors');
    }

    // ---- 11: unavailable / inconsistent store -------------------------------

    public function test_unavailable_store_makes_the_plan_unavailable(): void {
        $store = new EVC_In_Memory_Provenance_Store();
        $store->unavailable = true;
        $this->assertPlan(self::recorder($store)->plan(self::prospective('oct', '2026-10-15 12:00:00')), EVC_Period_Plan::UNAVAILABLE, 'store_unavailable');
    }

    public function test_recorded_movement_without_its_period_is_inconsistent(): void {
        $store = $this->stub_store(array(
            'movement' => self::movement('oct', '2026-10-15 12:00:00'),
            'period_for_movement' => null,
        ));
        $this->assertPlan(self::recorder($store)->plan(self::prospective('oct', '2026-10-15 12:00:00')), EVC_Period_Plan::UNAVAILABLE, 'store_inconsistent');
    }

    public function test_latest_period_of_another_member_is_inconsistent(): void {
        $foreign = new EVC_Funded_Period(self::ref('mv:foreign'), 99, EVC_Pmpro_Fixture::CLUB_LEVEL, self::ref('row9'), EVC_Pmpro_Fixture::athens('2026-10-01 10:00:00'), EVC_Pmpro_Fixture::athens('2026-11-01 10:00:00'), EVC_Funded_Period::KIND_INITIAL, null);
        $store = $this->stub_store(array('latest_period' => $foreign));
        $this->assertPlan(self::recorder($store)->plan(self::prospective('oct', '2026-10-15 12:00:00')), EVC_Period_Plan::UNAVAILABLE, 'store_inconsistent');
    }

    public function test_latest_period_whose_movement_is_missing_is_inconsistent(): void {
        $orphan = new EVC_Funded_Period(self::ref('mv:gone'), self::USER, EVC_Pmpro_Fixture::CLUB_LEVEL, self::ref('row1'), EVC_Pmpro_Fixture::athens('2026-10-01 10:00:00'), EVC_Pmpro_Fixture::athens('2026-11-01 10:00:00'), EVC_Funded_Period::KIND_INITIAL, null);
        $store = $this->stub_store(array('latest_period' => $orphan));
        $this->assertPlan(self::recorder($store)->plan(self::prospective('oct', '2026-10-15 12:00:00')), EVC_Period_Plan::UNAVAILABLE, 'store_inconsistent');
    }

    // ---- 12: repeated planning ----------------------------------------------

    public function test_repeated_planning_is_deterministic_and_writes_nothing(): void {
        $store = new EVC_In_Memory_Provenance_Store();
        self::recorder($store)->record(self::movement('oct', '2026-10-15 12:00:00'));
        $counts = $store->counts();
        $commits = $store->commits;
        $plans = array();
        for ($i = 0; $i < 3; $i++) {
            $plans[] = $this->plan_read_only($store, self::prospective('nov', '2026-11-10 09:00:00'));
        }
        $this->assertEquals($plans[0], $plans[1]);
        $this->assertEquals($plans[0], $plans[2]);
        $this->assertSame($counts, $store->counts());
        $this->assertSame($commits, $store->commits);
        $this->assertSame(array(), $store->unresolved_corrections());
    }

    // ---- 13: before the PMPro row exists ------------------------------------

    public function test_prospective_payment_carries_no_pmpro_row_and_no_confirmation_state(): void {
        $class = new ReflectionClass(EVC_Prospective_Payment::class);
        $params = array_map(function (ReflectionParameter $p) {
            return $p->getName();
        }, $class->getConstructor()->getParameters());
        $this->assertNotContains('pmpro_row_ref', $params);
        $this->assertNotContains('state', $params);
        $this->assertFalse($class->hasMethod('pmpro_row_ref'));
        $this->assertFalse($class->hasMethod('state'));
        $this->assertFalse((new ReflectionClass(EVC_Period_Plan::class))->hasMethod('pmpro_row_ref'));
        $this->assertFalse((new ReflectionClass(EVC_Payment_Candidate::class))->hasMethod('pmpro_row_ref'));
        // Only a row-bound EVC_Verified_Movement can be recorded.
        foreach (array('record', 'reconcile') as $method) {
            $type = (new ReflectionMethod(EVC_Provenance_Recorder::class, $method))->getParameters()[0]->getType();
            $this->assertNotSame(EVC_Prospective_Payment::class, $type->getName());
            $this->assertNotSame(EVC_Payment_Candidate::class, $type->getName());
        }
        $this->assertSame(EVC_Verified_Movement::class, (new ReflectionMethod(EVC_Provenance_Recorder::class, 'record'))->getParameters()[0]->getType()->getName());
    }

    public function test_planning_works_with_no_pmpro_row_at_all(): void {
        $store = new EVC_In_Memory_Provenance_Store();
        $plan = $this->plan_read_only($store, self::prospective('oct', '2026-10-15 12:00:00'));
        $this->assertPlan($plan, EVC_Period_Plan::PLANNED);
        $this->assertNull($plan->recorded_period());
        // Nothing the mapper reads exists: no payment fact, no period.
        $this->assertSame(array(), EVC_Provenance_Fact_Builder::facts($store, self::USER));
    }

    public function test_prospective_payment_uses_the_movement_shape_rules(): void {
        $bad = array(
            'id' => array('id' => 'not-hex'),
            'env' => array('env' => 'Live!'),
            'user' => array('user' => 0),
            'fraction' => array('at' => new DateTimeImmutable('2026-10-15 09:00:00.5', new DateTimeZone('UTC'))),
            'zone' => array('at' => new DateTimeImmutable('2026-10-15 12:00:00', new DateTimeZone('Europe/Athens'))),
            'amount' => array('amount' => 0),
            'currency' => array('currency' => 'eur'),
            'authority' => array('authority' => 'customer'),
            'kind' => array('kind' => 'bank_slip'),
            'alias' => array('aliases' => array('wc_order:1')),
        );
        foreach ($bad as $label => $o) {
            try {
                self::prospective('x', '2026-10-15 12:00:00', $o);
                $this->fail('accepted invalid ' . $label);
            } catch (InvalidArgumentException $e) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_matches_requires_a_confirmed_movement_with_the_same_facts_and_order(): void {
        $p = self::prospective('oct', '2026-10-15 12:00:00');
        $this->assertTrue($p->matches(self::movement('oct', '2026-10-15 12:00:00', array('row' => self::ref('row-any')))));
        $this->assertFalse($p->matches(self::movement('oct', '2026-10-15 12:00:00', array('state' => EVC_Verified_Movement::STATE_PENDING))));
        $this->assertFalse($p->matches(self::movement('oct', '2026-10-15 12:00:01')));
        $this->assertFalse($p->matches(self::movement('oct', '2026-10-15 12:00:00', array('user' => 43))));
        $this->assertFalse($p->matches(self::movement('oct', '2026-10-15 12:00:00', array('aliases' => array(new EVC_Movement_Alias(EVC_Movement_Alias::NS_WC_ORDER, self::ref('order:other')))))));
        $this->assertFalse($p->matches(self::movement('oct', '2026-10-15 12:00:00', array('kind' => EVC_Payment_Evidence::KIND_MANUAL_CONFIRMATION))));
    }

    // ---- 14: a stale plan is never a confirmed entitlement -------------------

    public function test_stale_plan_is_detected_and_record_revalidates_independently(): void {
        $store = new EVC_In_Memory_Provenance_Store();
        self::recorder($store)->record(self::movement('oct', '2026-10-15 12:00:00'));
        $plan = $this->plan_read_only($store, self::prospective('dec', '2026-12-01 10:00:00'));
        $this->assertPlanned($plan, EVC_Funded_Period::KIND_LATE, '2026-12-01 10:00:00', '2027-01-01 10:00:00', 'oct');

        // Meanwhile another verified renewal is recorded first.
        self::recorder($store)->record(self::movement('nov', '2026-11-20 10:00:00', array('row' => self::ref('row2'))));

        $o = self::recorder($store)->record(self::movement('dec', '2026-12-01 10:00:00', array('row' => self::ref('row3'))));
        $this->assertSame(EVC_Record_Outcome::NEWLY_RECORDED, $o->outcome());
        // record() ignored the plan: it computed from the CURRENT chain (early, after nov).
        $this->assertSame(EVC_Funded_Period::KIND_EARLY, $o->period()->kind());
        $this->assertSame('2026-12-20 10:00:00', self::local($o->period()->start_utc()));
        $this->assertFalse($plan->matches_recorded($o->period()));
    }

    public function test_stale_plan_cannot_bypass_commit_time_revalidation(): void {
        $store = new EVC_In_Memory_Provenance_Store();
        $plan = $this->plan_read_only($store, self::prospective('oct', '2026-10-15 12:00:00'));
        $this->assertPlan($plan, EVC_Period_Plan::PLANNED);
        // A refund for this transfer arrives after planning: recording is refused.
        self::recorder($store)->correct(EVC_Provenance_Test_Fixture::correction('r1', 'oct', EVC_Movement_Correction::KIND_REFUNDED));
        $o = self::recorder($store)->record(self::movement('oct', '2026-10-15 12:00:00'));
        $this->assertSame(EVC_Record_Outcome::INDETERMINATE, $o->outcome());
        $this->assertSame('unresolved_correction', $o->reason());
        $this->assertSame(0, $store->counts()['movements']);
    }

    public function test_a_plan_alone_never_makes_the_member_eligible(): void {
        $store = new EVC_In_Memory_Provenance_Store();
        $plan = $this->plan_read_only($store, self::prospective('oct', '2026-10-15 12:00:00'));
        // Even a PMPro row whose dates equal the plan exactly is not paid membership.
        $row = EVC_Pmpro_Fixture::row('row1', 'active', self::local($plan->end_utc()), EVC_Pmpro_Fixture::CLUB_LEVEL, false, self::local($plan->start_utc()));
        $at = EVC_Pmpro_Fixture::athens('2026-10-20 12:00:00');
        $entitlement = EVC_Pmpro_Fixture::mapper()->map(EVC_Provenance_Fact_Builder::snapshot($store, self::USER, array($row)), $at);
        $this->assertFalse($entitlement->is_eligible_at($at));
        $this->assertFalse($entitlement->payment_verified());

        $plan_class = new ReflectionClass(EVC_Period_Plan::class);
        foreach (array('is_eligible_at', 'payment_verified', 'confirm', 'confirmed', 'record', 'entitlement') as $method) {
            $this->assertFalse($plan_class->hasMethod($method), $method);
        }
    }

    public function test_matches_recorded_rejects_any_difference(): void {
        $plan = EVC_Period_Plan::planned(self::ref('mv:a'), EVC_Pmpro_Fixture::athens('2026-10-15 12:00:00'), EVC_Pmpro_Fixture::athens('2026-11-15 12:00:00'), EVC_Funded_Period::KIND_INITIAL, null);
        $period = function (array $o) {
            return new EVC_Funded_Period(
                $o['mv'] ?? self::ref('mv:a'),
                self::USER,
                EVC_Pmpro_Fixture::CLUB_LEVEL,
                self::ref('row1'),
                EVC_Pmpro_Fixture::athens($o['start'] ?? '2026-10-15 12:00:00'),
                EVC_Pmpro_Fixture::athens($o['end'] ?? '2026-11-15 12:00:00'),
                $o['kind'] ?? EVC_Funded_Period::KIND_INITIAL,
                $o['pred'] ?? null
            );
        };
        $this->assertTrue($plan->matches_recorded($period(array())));
        $this->assertFalse($plan->matches_recorded($period(array('mv' => self::ref('mv:b')))));
        $this->assertFalse($plan->matches_recorded($period(array('start' => '2026-10-15 12:00:01'))));
        $this->assertFalse($plan->matches_recorded($period(array('end' => '2026-11-15 11:59:59'))));
        $this->assertFalse($plan->matches_recorded($period(array('kind' => EVC_Funded_Period::KIND_LATE, 'pred' => self::ref('mv:z')))));
        // Same dates and kind, but the chain moved on: another predecessor is a stale plan.
        $early = EVC_Period_Plan::planned(self::ref('mv:a'), EVC_Pmpro_Fixture::athens('2026-10-15 12:00:00'), EVC_Pmpro_Fixture::athens('2026-11-15 12:00:00'), EVC_Funded_Period::KIND_EARLY, self::ref('mv:p1'));
        $this->assertTrue($early->matches_recorded($period(array('kind' => EVC_Funded_Period::KIND_EARLY, 'pred' => self::ref('mv:p1')))));
        $this->assertFalse($early->matches_recorded($period(array('kind' => EVC_Funded_Period::KIND_EARLY, 'pred' => self::ref('mv:p2')))));
        $refused = EVC_Period_Plan::refused(EVC_Record_Outcome::conflict('alias_conflict'));
        $this->assertFalse($refused->matches_recorded($period(array())));
    }

    public function test_only_refusals_become_refused_plans(): void {
        $this->expectException(LogicException::class);
        EVC_Period_Plan::refused(EVC_Record_Outcome::recorded(null));
    }

    /** @param array<string,mixed> $answers method => return value; unspecified reads return "nothing" */
    private function stub_store(array $answers): EVC_Provenance_Store {
        return new class($answers) implements EVC_Provenance_Store {
            private $a;

            public function __construct(array $a) {
                $this->a = $a;
            }

            public function movement(string $movement_id): ?EVC_Verified_Movement {
                $m = $this->a['movement'] ?? null;
                return $m !== null && $m->movement_id() === $movement_id ? $m : null;
            }

            public function movement_for_alias(string $alias_key): ?string {
                return null;
            }

            public function member_movements(int $wp_user_id): array {
                return array();
            }

            public function latest_period(int $wp_user_id): ?EVC_Funded_Period {
                return $this->a['latest_period'] ?? null;
            }

            public function period_for_movement(string $movement_id): ?EVC_Funded_Period {
                return $this->a['period_for_movement'] ?? null;
            }

            public function correction(string $correction_id): ?EVC_Movement_Correction {
                return null;
            }

            public function corrections_for(string $movement_id): array {
                return array();
            }

            public function unresolved_corrections(): array {
                return array();
            }

            public function commit(EVC_Provenance_Write $write): void {
                throw new LogicException('stub store is read-only');
            }
        };
    }
}
