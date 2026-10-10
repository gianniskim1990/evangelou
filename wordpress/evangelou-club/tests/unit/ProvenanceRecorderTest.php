<?php
use PHPUnit\Framework\TestCase;

/**
 * Task 1D-E: deterministic payment-provenance recorder core (scenarios A-V).
 * Synthetic members, payments and references only. Expected periods are
 * written out by hand (Europe/Athens wall-clock) per test.
 */
final class ProvenanceRecorderTest extends TestCase {
    const USER = 42;

    private static function ref(string $label): string {
        return EVC_Pmpro_Fixture::ref($label);
    }

    private static function local(DateTimeImmutable $instant): string {
        return $instant->setTimezone(new DateTimeZone('Europe/Athens'))->format('Y-m-d H:i:s');
    }

    /**
     * A verified movement: canonical id mv:<label>, order anchor wc_order:<label>,
     * Viva alias viva:<label>, EUR 20.00, live, member 42, Club level 7.
     *
     * @param array<string,mixed> $o overrides: id, env, user, level, row, at (Athens local), amount, currency, aliases, authority, kind, state
     */
    private static function movement(string $label, string $at_athens, array $o = array()): EVC_Verified_Movement {
        return new EVC_Verified_Movement(
            $o['id'] ?? self::ref('mv:' . $label),
            $o['env'] ?? 'live',
            $o['user'] ?? self::USER,
            $o['level'] ?? EVC_Pmpro_Fixture::CLUB_LEVEL,
            $o['row'] ?? self::ref('row1'),
            EVC_Pmpro_Fixture::athens($at_athens),
            $o['amount'] ?? 2000,
            $o['currency'] ?? 'EUR',
            $o['aliases'] ?? array(
                new EVC_Movement_Alias(EVC_Movement_Alias::NS_WC_ORDER, self::ref('order:' . $label)),
                new EVC_Movement_Alias(EVC_Movement_Alias::NS_VIVA_TRANSACTION, self::ref('viva:' . $label)),
            ),
            $o['authority'] ?? EVC_Verified_Movement::AUTHORITY_GATEWAY_API,
            $o['kind'] ?? EVC_Payment_Evidence::KIND_WC_ORDER,
            $o['state'] ?? EVC_Verified_Movement::STATE_CONFIRMED
        );
    }

    private static function recorder(EVC_In_Memory_Provenance_Store $store, ?EVC_Pmpro_Mapper_Config $levels = null): EVC_Provenance_Recorder {
        return new EVC_Provenance_Recorder($store, $levels ?? EVC_Pmpro_Fixture::config(), 'live', 2000, 'EUR');
    }

    private function assertOutcome(EVC_Record_Outcome $o, string $outcome, ?string $reason = null): void {
        $this->assertSame($outcome, $o->outcome(), 'reason: ' . (string) $o->reason());
        if ($reason !== null) {
            $this->assertSame($reason, $o->reason());
        }
    }

    private function assertNothingRecorded(EVC_In_Memory_Provenance_Store $store): void {
        $this->assertSame(array('movements' => 0, 'aliases' => 0, 'periods' => 0, 'corrections' => 0), $store->counts());
    }

    // ---- A / B: first payment, exact repeat ---------------------------------

    public function test_A_first_verified_payment_creates_exactly_one_period(): void {
        $store = new EVC_In_Memory_Provenance_Store();
        $o = self::recorder($store)->record(self::movement('oct', '2026-10-15 12:00:00'));
        $this->assertOutcome($o, EVC_Record_Outcome::NEWLY_RECORDED);
        $p = $o->period();
        $this->assertSame('initial', $p->kind());
        $this->assertNull($p->predecessor_movement_id());
        $this->assertSame('2026-10-15 12:00:00', self::local($p->start_utc()));
        $this->assertSame('2026-11-15 12:00:00', self::local($p->end_utc()));
        $this->assertSame(self::ref('mv:oct'), $p->movement_id());
        $this->assertSame(array('movements' => 1, 'aliases' => 2, 'periods' => 1, 'corrections' => 0), $store->counts());
    }

    public function test_B_same_movement_repeated_creates_no_second_period(): void {
        $store = new EVC_In_Memory_Provenance_Store();
        $r = self::recorder($store);
        $first = $r->record(self::movement('oct', '2026-10-15 12:00:00'));
        $commits = $store->commits;
        for ($i = 0; $i < 3; $i++) {
            $again = $r->record(self::movement('oct', '2026-10-15 12:00:00'));
            $this->assertOutcome($again, EVC_Record_Outcome::ALREADY_RECORDED);
            $this->assertEquals($first->period()->end_utc(), $again->period()->end_utc());
        }
        $this->assertSame($commits, $store->commits, 'no write for an identical repeat');
        $this->assertSame(1, $store->counts()['periods']);
    }

    // ---- C / D / E: cross-source identity ------------------------------------

    public function test_C_viva_and_woocommerce_signals_for_one_payment_fund_one_period(): void {
        $store = new EVC_In_Memory_Provenance_Store();
        $r = self::recorder($store);
        // Viva adapter: knows the gateway transaction and the order it settles.
        $viva = self::movement('oct', '2026-10-15 12:00:00');
        $this->assertOutcome($r->record($viva), EVC_Record_Outcome::NEWLY_RECORDED);
        // WooCommerce adapter, same canonical movement, knows only the order.
        $woo = self::movement('oct', '2026-10-15 12:00:00', array('aliases' => array(
            new EVC_Movement_Alias(EVC_Movement_Alias::NS_WC_ORDER, self::ref('order:oct')),
        )));
        $this->assertOutcome($r->record($woo), EVC_Record_Outcome::ALREADY_RECORDED);
        // Later signal adds a verified PayPal-style alias to the SAME movement: alias added, still one period.
        $more = self::movement('oct', '2026-10-15 12:00:00', array('aliases' => array(
            new EVC_Movement_Alias(EVC_Movement_Alias::NS_WC_ORDER, self::ref('order:oct')),
            new EVC_Movement_Alias(EVC_Movement_Alias::NS_PAYPAL_TRANSACTION, self::ref('pp:oct')),
        )));
        $this->assertOutcome($r->record($more), EVC_Record_Outcome::ALREADY_RECORDED);
        $this->assertSame(self::ref('mv:oct'), $store->movement_for_alias('paypal_transaction:' . self::ref('pp:oct')));
        $this->assertSame(array('movements' => 1, 'aliases' => 3, 'periods' => 1, 'corrections' => 0), $store->counts());
    }

    public function test_C_same_order_under_a_different_canonical_id_is_a_conflict_not_a_second_month(): void {
        $store = new EVC_In_Memory_Provenance_Store();
        $r = self::recorder($store);
        $r->record(self::movement('oct', '2026-10-15 12:00:00'));
        // A badly derived canonical id for the same WooCommerce order.
        $dup = self::movement('oct-dup', '2026-10-15 12:00:05', array('aliases' => array(
            new EVC_Movement_Alias(EVC_Movement_Alias::NS_WC_ORDER, self::ref('order:oct')),
        )));
        $this->assertOutcome($r->record($dup), EVC_Record_Outcome::CONFLICT, 'alias_conflict');
        $this->assertSame(1, $store->counts()['periods']);
    }

    public function test_D_one_alias_linked_to_two_movements_is_a_conflict(): void {
        $store = new EVC_In_Memory_Provenance_Store();
        $r = self::recorder($store);
        $r->record(self::movement('oct', '2026-10-15 12:00:00'));
        $reuse = self::movement('nov', '2026-11-01 09:00:00', array('aliases' => array(
            new EVC_Movement_Alias(EVC_Movement_Alias::NS_WC_ORDER, self::ref('order:nov')),
            new EVC_Movement_Alias(EVC_Movement_Alias::NS_VIVA_TRANSACTION, self::ref('viva:oct')), // Viva txn of October
        )));
        $this->assertOutcome($r->record($reuse), EVC_Record_Outcome::CONFLICT, 'alias_conflict');
        $this->assertSame(1, $store->counts()['movements']);
        // Also on the repeat path: the recorded movement claiming another movement's alias.
        $r->record(self::movement('dec', '2026-12-01 09:00:00', array('row' => self::ref('row1'))));
        $steal = self::movement('oct', '2026-10-15 12:00:00', array('aliases' => array(
            new EVC_Movement_Alias(EVC_Movement_Alias::NS_WC_ORDER, self::ref('order:oct')),
            new EVC_Movement_Alias(EVC_Movement_Alias::NS_VIVA_TRANSACTION, self::ref('viva:dec')),
        )));
        $this->assertOutcome($r->record($steal), EVC_Record_Outcome::CONFLICT, 'alias_conflict');
    }

    public function test_D_repeat_with_a_different_order_anchor_is_a_conflict(): void {
        $store = new EVC_In_Memory_Provenance_Store();
        $r = self::recorder($store);
        $r->record(self::movement('oct', '2026-10-15 12:00:00'));
        $other_order = self::movement('oct', '2026-10-15 12:00:00', array('aliases' => array(
            new EVC_Movement_Alias(EVC_Movement_Alias::NS_PMPRO_ORDER, self::ref('pmpro-order:x')),
        )));
        $this->assertOutcome($r->record($other_order), EVC_Record_Outcome::CONFLICT, 'order_anchor_conflict');
        $this->assertSame(2, $store->counts()['aliases']);
    }

    public function test_E_unlinked_gateway_only_signal_is_not_credited(): void {
        $store = new EVC_In_Memory_Provenance_Store();
        $r = self::recorder($store);
        $r->record(self::movement('oct', '2026-10-15 12:00:00'));
        // A Viva notification whose order cannot be established: never guessed from amount/time.
        $orphan = self::movement('viva-only', '2026-10-15 12:00:03', array('aliases' => array(
            new EVC_Movement_Alias(EVC_Movement_Alias::NS_VIVA_TRANSACTION, self::ref('viva:unknown')),
        )));
        $this->assertOutcome($r->record($orphan), EVC_Record_Outcome::INDETERMINATE, 'order_anchor_missing');
        $this->assertSame(1, $store->counts()['periods']);
    }

    // ---- F / G / H: genuine payments and renewals ------------------------------

    public function test_F_two_genuine_payments_with_equal_amount_fund_two_chained_periods(): void {
        $store = new EVC_In_Memory_Provenance_Store();
        $r = self::recorder($store);
        $r->record(self::movement('a', '2026-10-15 12:00:00'));
        $second = $r->record(self::movement('b', '2026-10-15 12:00:02')); // same amount, two seconds later
        $this->assertOutcome($second, EVC_Record_Outcome::NEWLY_RECORDED);
        $this->assertSame('early', $second->period()->kind());
        $this->assertSame('2026-11-15 12:00:00', self::local($second->period()->start_utc()));
        $this->assertSame('2026-12-15 12:00:00', self::local($second->period()->end_utc()));
        $this->assertSame(self::ref('mv:a'), $second->period()->predecessor_movement_id());
    }

    public function test_G_early_renewal_keeps_remaining_paid_time(): void {
        $store = new EVC_In_Memory_Provenance_Store();
        $r = self::recorder($store);
        $r->record(self::movement('oct', '2026-10-15 12:00:00'));
        $p = $r->record(self::movement('nov', '2026-11-01 09:00:00'))->period();
        $this->assertSame('early', $p->kind());
        $this->assertSame('2026-11-15 12:00:00', self::local($p->start_utc()));
        $this->assertSame('2026-12-15 12:00:00', self::local($p->end_utc()));
    }

    public function test_H_late_renewal_starts_at_the_given_verification_instant(): void {
        $store = new EVC_In_Memory_Provenance_Store();
        $r = self::recorder($store);
        $r->record(self::movement('oct', '2026-10-15 12:00:00'));
        $p = $r->record(self::movement('nov', '2026-11-20 10:00:00'))->period();
        $this->assertSame('late', $p->kind());
        $this->assertSame('2026-11-20 10:00:00', self::local($p->start_utc()));
        $this->assertSame('2026-12-20 10:00:00', self::local($p->end_utc()));
        // Exactly at the old end is late too (end is exclusive).
        $store2 = new EVC_In_Memory_Provenance_Store();
        $r2 = self::recorder($store2);
        $r2->record(self::movement('oct', '2026-10-15 12:00:00'));
        $edge = $r2->record(self::movement('nov', '2026-11-15 12:00:00'))->period();
        $this->assertSame('late', $edge->kind());
        $this->assertSame('2026-12-15 12:00:00', self::local($edge->end_utc()));
    }

    // ---- I: pending / unverified ------------------------------------------------

    public function test_I_pending_failed_or_unknown_payments_record_nothing(): void {
        foreach (array('pending', 'failed', 'unknown') as $state) {
            $store = new EVC_In_Memory_Provenance_Store();
            $o = self::recorder($store)->record(self::movement('p', '2026-10-15 12:00:00', array('state' => $state)));
            $this->assertOutcome($o, EVC_Record_Outcome::INDETERMINATE, 'movement_not_confirmed');
            $this->assertNull($o->period());
            $this->assertNothingRecorded($store);
        }
    }

    public function test_I_a_pending_renewal_does_not_extend_the_chain(): void {
        $store = new EVC_In_Memory_Provenance_Store();
        $r = self::recorder($store);
        $r->record(self::movement('oct', '2026-10-15 12:00:00'));
        $r->record(self::movement('nov', '2026-11-01 09:00:00', array('state' => 'pending')));
        $this->assertSame('2026-11-15 12:00:00', self::local($store->latest_period(self::USER)->end_utc()));
    }

    public function test_wrong_amount_currency_or_environment_records_nothing(): void {
        $cases = array(
            array(array('amount' => 1999), 'amount_mismatch'),
            array(array('amount' => 4000), 'amount_mismatch'),
            array(array('currency' => 'USD'), 'amount_mismatch'),
            array(array('env' => 'sandbox'), 'environment_mismatch'),
        );
        foreach ($cases as $case) {
            $store = new EVC_In_Memory_Provenance_Store();
            $this->assertOutcome(self::recorder($store)->record(self::movement('x', '2026-10-15 12:00:00', $case[0])), EVC_Record_Outcome::INDETERMINATE, $case[1]);
            $this->assertNothingRecorded($store);
        }
    }

    // ---- K / L: calendar --------------------------------------------------------

    public function test_K_month_end_clamp_and_chain(): void {
        $store = new EVC_In_Memory_Provenance_Store();
        $r = self::recorder($store);
        $this->assertSame('2027-02-28 12:00:00', self::local($r->record(self::movement('jan', '2027-01-31 12:00:00'))->period()->end_utc()));
        $this->assertSame('2027-03-28 12:00:00', self::local($r->record(self::movement('feb', '2027-02-20 10:00:00'))->period()->end_utc()));
        $this->assertSame('2027-04-28 12:00:00', self::local($r->record(self::movement('mar', '2027-03-20 10:00:00'))->period()->end_utc()));
    }

    public function test_L_leap_year_and_athens_dst(): void {
        $store = new EVC_In_Memory_Provenance_Store();
        $this->assertSame('2028-02-29 07:00:00', self::local(self::recorder($store)->record(self::movement('leap', '2028-01-31 07:00:00'))->period()->end_utc()));

        // Spring: 28 Feb 03:30 + 1 month = 28 Mar 03:30, which does not exist -> transition 01:00Z.
        $store = new EVC_In_Memory_Provenance_Store();
        $end = self::recorder($store)->record(self::movement('spring', '2027-02-28 03:30:00'))->period()->end_utc();
        $this->assertSame('2027-03-28T01:00:00Z', $end->format('Y-m-d\TH:i:s\Z'));

        // Autumn: 25 Sep 03:30 + 1 month = 25 Oct 03:30, which happens twice -> first (00:30Z).
        $store = new EVC_In_Memory_Provenance_Store();
        $end = self::recorder($store)->record(self::movement('autumn', '2026-09-25 03:30:00'))->period()->end_utc();
        $this->assertSame('2026-10-25T00:30:00Z', $end->format('Y-m-d\TH:i:s\Z'));
    }

    // ---- M / U: ordering -------------------------------------------------------

    public function test_M_two_payments_at_the_same_instant_are_not_silently_ordered(): void {
        $store = new EVC_In_Memory_Provenance_Store();
        $r = self::recorder($store);
        $r->record(self::movement('a', '2026-10-15 12:00:00'));
        $o = $r->record(self::movement('b', '2026-10-15 12:00:00'));
        $this->assertOutcome($o, EVC_Record_Outcome::INDETERMINATE, 'same_instant_payment');
        $this->assertSame(1, $store->counts()['movements']);
    }

    public function test_U_payment_arriving_out_of_order_is_not_appended_or_rewritten(): void {
        $store = new EVC_In_Memory_Provenance_Store();
        $r = self::recorder($store);
        $r->record(self::movement('nov', '2026-11-01 09:00:00'));
        $before = $store->latest_period(self::USER);
        $o = $r->record(self::movement('oct', '2026-10-15 12:00:00'));
        $this->assertOutcome($o, EVC_Record_Outcome::INDETERMINATE, 'out_of_order_payment');
        $this->assertSame($before, $store->latest_period(self::USER), 'existing periods unchanged');
        $this->assertSame(1, $store->counts()['periods']);
    }

    // ---- N / O / P: identity, member and level ---------------------------------

    public function test_N_same_movement_for_another_member_is_a_conflict(): void {
        $store = new EVC_In_Memory_Provenance_Store();
        $r = self::recorder($store);
        $r->record(self::movement('oct', '2026-10-15 12:00:00'));
        $this->assertOutcome($r->record(self::movement('oct', '2026-10-15 12:00:00', array('user' => 43))), EVC_Record_Outcome::CONFLICT, 'movement_fact_conflict');
        // Another member's movement reusing this member's order anchor.
        $other = self::movement('oct-43', '2026-10-15 12:00:00', array('user' => 43, 'aliases' => array(
            new EVC_Movement_Alias(EVC_Movement_Alias::NS_WC_ORDER, self::ref('order:oct')),
        )));
        $this->assertOutcome($r->record($other), EVC_Record_Outcome::CONFLICT, 'alias_conflict');
        $this->assertSame(array(), $store->member_movements(43));
    }

    public function test_repeated_movement_with_any_changed_fact_is_a_conflict(): void {
        $changes = array(
            array('at' => '2026-10-15 12:00:01'),
            array('row' => self::ref('row9')),
            array('authority' => EVC_Verified_Movement::AUTHORITY_ORDER_STATE),
            array('kind' => EVC_Payment_Evidence::KIND_PMPRO_ORDER),
        );
        foreach ($changes as $change) {
            $store = new EVC_In_Memory_Provenance_Store();
            $r = self::recorder($store);
            $r->record(self::movement('oct', '2026-10-15 12:00:00'));
            $at = $change['at'] ?? '2026-10-15 12:00:00';
            unset($change['at']);
            $this->assertOutcome($r->record(self::movement('oct', $at, $change)), EVC_Record_Outcome::CONFLICT, 'movement_fact_conflict');
            $this->assertSame(1, $store->counts()['periods']);
        }
    }

    public function test_O_level_not_approved_or_changed_level(): void {
        $store = new EVC_In_Memory_Provenance_Store();
        $r = self::recorder($store);
        $this->assertOutcome($r->record(self::movement('x', '2026-10-15 12:00:00', array('level' => EVC_Pmpro_Fixture::OTHER_LEVEL))), EVC_Record_Outcome::INDETERMINATE, 'level_not_approved');
        $this->assertNothingRecorded($store);
        $two = self::recorder($store, new EVC_Pmpro_Mapper_Config(array(7, 8), '3.0', '4.0'));
        $two->record(self::movement('oct', '2026-10-15 12:00:00'));
        $this->assertOutcome($two->record(self::movement('oct', '2026-10-15 12:00:00', array('level' => 8))), EVC_Record_Outcome::CONFLICT, 'movement_fact_conflict');
    }

    public function test_P_invalid_or_missing_identity_is_rejected(): void {
        $bad = array(
            function () { self::movement('x', '2026-10-15 12:00:00', array('id' => '12345')); },
            function () { self::movement('x', '2026-10-15 12:00:00', array('row' => 'row1')); },
            function () { new EVC_Movement_Alias('fluentcrm_contact', self::ref('a')); },
            function () { new EVC_Movement_Alias(EVC_Movement_Alias::NS_WC_ORDER, '1001'); },
            function () { self::movement('x', '2026-10-15 12:00:00', array('user' => 0)); },
            function () { self::movement('x', '2026-10-15 12:00:00', array('authority' => 'webhook')); },
        );
        foreach ($bad as $i => $make) {
            try {
                $make();
                $this->fail('accepted invalid identity #' . $i);
            } catch (InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
        $store = new EVC_In_Memory_Provenance_Store();
        $two_orders = self::movement('x', '2026-10-15 12:00:00', array('aliases' => array(
            new EVC_Movement_Alias(EVC_Movement_Alias::NS_WC_ORDER, self::ref('o1')),
            new EVC_Movement_Alias(EVC_Movement_Alias::NS_PMPRO_ORDER, self::ref('o2')),
        )));
        $this->assertOutcome(self::recorder($store)->record($two_orders), EVC_Record_Outcome::CONFLICT, 'multiple_order_anchors');
        $this->assertNothingRecorded($store);
    }

    public function test_P_verification_instant_must_be_explicit_whole_second_utc(): void {
        $base = array(self::ref('mv:t'), 'live', self::USER, 7, self::ref('row1'));
        $tail = array(2000, 'EUR', array(new EVC_Movement_Alias(EVC_Movement_Alias::NS_WC_ORDER, self::ref('order:t'))), 'gateway_api', 'wc_order', 'confirmed');
        foreach (array(
            new DateTimeImmutable('2026-10-15 12:00:00', new DateTimeZone('Europe/Athens')),
            new DateTimeImmutable('2026-10-15T09:00:00+00:00'),
            new DateTimeImmutable('2026-10-15T09:00:00Z'), // "Z" designator, not the UTC zone object
            new DateTimeImmutable('2026-10-15 09:00:00.500000', new DateTimeZone('UTC')), // UTC but fractional
            new DateTimeImmutable('2026-10-15 09:00:00.000001', new DateTimeZone('UTC')),
        ) as $instant) {
            try {
                new EVC_Verified_Movement(...array_merge($base, array($instant), $tail));
                $this->fail('accepted ' . $instant->format(DATE_RFC3339_EXTENDED) . ' ' . $instant->getTimezone()->getName());
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('Verification instant', $e->getMessage());
            }
        }
        $ok = new EVC_Verified_Movement(...array_merge($base, array(new DateTimeImmutable('2026-10-15 09:00:00', new DateTimeZone('UTC'))), $tail));
        $this->assertSame('2026-10-15T09:00:00Z', $ok->verified_at_utc()->format('Y-m-d\TH:i:s\Z'), 'kept exactly, never rounded');
    }

    // ---- Q: corrections ---------------------------------------------------------

    private static function correction(string $label, string $movement_label, string $kind): EVC_Movement_Correction {
        return new EVC_Movement_Correction(self::ref('corr:' . $label), self::ref('mv:' . $movement_label), $kind, EVC_Verified_Movement::AUTHORITY_GATEWAY_API, EVC_Pmpro_Fixture::athens('2026-10-20 10:00:00'));
    }

    public function test_Q_refund_is_appended_idempotently_and_keeps_history(): void {
        $store = new EVC_In_Memory_Provenance_Store();
        $r = self::recorder($store);
        $r->record(self::movement('oct', '2026-10-15 12:00:00'));
        $this->assertOutcome($r->correct(self::correction('r1', 'oct', 'refunded')), EVC_Record_Outcome::NEWLY_RECORDED);
        $this->assertOutcome($r->correct(self::correction('r1', 'oct', 'refunded')), EVC_Record_Outcome::ALREADY_RECORDED);
        $this->assertOutcome($r->correct(self::correction('r1', 'oct', 'reversed')), EVC_Record_Outcome::CONFLICT, 'correction_conflict');
        $this->assertOutcome($r->correct(self::correction('r2', 'oct', 'refunded')), EVC_Record_Outcome::CONFLICT, 'correction_state_regression');
        $this->assertSame(1, $store->counts()['corrections']);
        $this->assertNotNull($store->period_for_movement(self::ref('mv:oct')), 'period kept: history is never erased');
        $this->assertNotNull($store->movement(self::ref('mv:oct')));
    }

    public function test_Q_partial_refund_may_progress_and_every_other_state_is_final(): void {
        $store = new EVC_In_Memory_Provenance_Store();
        $r = self::recorder($store);
        $r->record(self::movement('oct', '2026-10-15 12:00:00'));
        $this->assertOutcome($r->correct(self::correction('p1', 'oct', 'partially_refunded')), EVC_Record_Outcome::NEWLY_RECORDED);
        $this->assertOutcome($r->correct(self::correction('p2', 'oct', 'partially_refunded')), EVC_Record_Outcome::CONFLICT, 'correction_state_regression');
        $this->assertOutcome($r->correct(self::correction('f1', 'oct', 'refunded')), EVC_Record_Outcome::NEWLY_RECORDED);
        foreach (array('voided', 'reversed', 'partially_refunded') as $kind) {
            $this->assertOutcome($r->correct(self::correction('late-' . $kind, 'oct', $kind)), EVC_Record_Outcome::CONFLICT, 'correction_state_regression');
        }
        $this->assertSame(array('partially_refunded', 'refunded'), array_map(function (EVC_Movement_Correction $c) {
            return $c->kind();
        }, $store->corrections_for(self::ref('mv:oct'))));
    }

    public function test_Q_refund_before_its_payment_is_not_attached_to_anything(): void {
        $store = new EVC_In_Memory_Provenance_Store();
        $this->assertOutcome(self::recorder($store)->correct(self::correction('r1', 'unknown', 'refunded')), EVC_Record_Outcome::INDETERMINATE, 'unknown_movement');
        $this->assertNothingRecorded($store);
    }

    // ---- S / T: failures, retries, atomicity ------------------------------------

    public function test_S_retry_after_a_store_failure_records_exactly_once(): void {
        $store = new EVC_In_Memory_Provenance_Store();
        $r = self::recorder($store);
        $store->fail_next_commit_after_apply = true;
        $this->assertOutcome($r->record(self::movement('oct', '2026-10-15 12:00:00')), EVC_Record_Outcome::UNAVAILABLE, 'store_unavailable');
        $this->assertNothingRecorded($store);
        $this->assertOutcome($r->record(self::movement('oct', '2026-10-15 12:00:00')), EVC_Record_Outcome::NEWLY_RECORDED);
        $this->assertOutcome($r->record(self::movement('oct', '2026-10-15 12:00:00')), EVC_Record_Outcome::ALREADY_RECORDED);
        $this->assertSame(1, $store->counts()['periods']);
    }

    public function test_S_unavailable_store_never_reports_success(): void {
        $store = new EVC_In_Memory_Provenance_Store();
        $store->unavailable = true;
        $r = self::recorder($store);
        $this->assertOutcome($r->record(self::movement('oct', '2026-10-15 12:00:00')), EVC_Record_Outcome::UNAVAILABLE);
        $this->assertOutcome($r->correct(self::correction('r1', 'oct', 'refunded')), EVC_Record_Outcome::UNAVAILABLE);
        $store->unavailable = false;
        $this->assertNothingRecorded($store);
    }

    public function test_T_failures_leave_no_partial_writes(): void {
        $store = new EVC_In_Memory_Provenance_Store();
        $r = self::recorder($store);
        $r->record(self::movement('oct', '2026-10-15 12:00:00'));
        $snapshot = $store->counts();

        $store->fail_next_commit_after_apply = true; // new movement + aliases + period
        $this->assertOutcome($r->record(self::movement('nov', '2026-11-01 09:00:00')), EVC_Record_Outcome::UNAVAILABLE);
        $this->assertSame($snapshot, $store->counts());
        $this->assertSame(self::ref('mv:oct'), $store->latest_period(self::USER)->movement_id());

        $store->fail_next_commit_after_apply = true; // alias addition
        $more = self::movement('oct', '2026-10-15 12:00:00', array('aliases' => array(
            new EVC_Movement_Alias(EVC_Movement_Alias::NS_WC_ORDER, self::ref('order:oct')),
            new EVC_Movement_Alias(EVC_Movement_Alias::NS_PAYPAL_TRANSACTION, self::ref('pp:oct')),
        )));
        $this->assertOutcome($r->record($more), EVC_Record_Outcome::UNAVAILABLE);
        $this->assertSame($snapshot, $store->counts());

        $store->fail_next_commit_after_apply = true; // correction
        $this->assertOutcome($r->correct(self::correction('r1', 'oct', 'refunded')), EVC_Record_Outcome::UNAVAILABLE);
        $this->assertSame($snapshot, $store->counts());
    }

    public function test_concurrent_writer_detected_by_the_store_compare_and_set(): void {
        $store = new EVC_In_Memory_Provenance_Store();
        $r = self::recorder($store);
        $r->record(self::movement('oct', '2026-10-15 12:00:00'));
        // Between the recorder's reads and its commit, another process appends a period.
        $store->before_next_commit = function (EVC_In_Memory_Provenance_Store $s) {
            $s->before_next_commit = null;
            self::recorder($s)->record(self::movement('other', '2026-11-01 08:00:00'));
        };
        $o = $r->record(self::movement('nov', '2026-11-01 09:00:00'));
        $this->assertOutcome($o, EVC_Record_Outcome::CONFLICT, 'concurrent_update');
        $this->assertNull($store->movement(self::ref('mv:nov')), 'stale write rejected entirely');
        $this->assertSame(2, $store->counts()['periods']);
        // A retry sees the new chain and appends correctly.
        $retry = $r->record(self::movement('nov', '2026-11-01 09:00:00'));
        $this->assertOutcome($retry, EVC_Record_Outcome::NEWLY_RECORDED);
        $this->assertSame(self::ref('mv:other'), $retry->period()->predecessor_movement_id());
        $this->assertSame('2027-01-15 12:00:00', self::local($retry->period()->end_utc()));
    }

    public function test_recorder_configuration_has_no_permissive_defaults(): void {
        foreach (array(array('live', 0, 'EUR'), array('live', 2000, 'eur'), array('', 2000, 'EUR'), array('LIVE', 2000, 'EUR')) as $cfg) {
            try {
                new EVC_Provenance_Recorder(new EVC_In_Memory_Provenance_Store(), EVC_Pmpro_Fixture::config(), ...$cfg);
                $this->fail('accepted ' . json_encode($cfg));
            } catch (InvalidArgumentException $e) {
                $this->assertSame('Invalid recorder configuration.', $e->getMessage());
            }
        }
    }

    public function test_recorder_reads_no_clock(): void {
        // Same input, hostile and changing default timezones: identical output.
        $results = array();
        $previous = date_default_timezone_get();
        try {
            foreach (array('UTC', 'Pacific/Kiritimati', 'America/Los_Angeles') as $zone) {
                date_default_timezone_set($zone);
                $store = new EVC_In_Memory_Provenance_Store();
                $results[] = self::recorder($store)->record(self::movement('jan', '2027-01-31 12:00:00'))->period()->end_utc()->format('U.u');
            }
        } finally {
            date_default_timezone_set($previous);
        }
        $this->assertCount(1, array_unique($results));
    }
}
