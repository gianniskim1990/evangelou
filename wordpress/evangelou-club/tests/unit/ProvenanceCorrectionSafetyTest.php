<?php
use PHPUnit\Framework\TestCase;

/**
 * Task 1D-E.R1: correction atomicity (compare-and-set and forward-only at
 * commit time) and refund-before-payment safety (unresolved corrections and
 * trusted reconciliation). Scenarios A-M of the R1 brief. Synthetic members,
 * payments and references only; concurrency is simulated with the in-memory
 * store's before_next_commit hook (a writer that runs between the
 * recorder's reads and its commit). Nothing here says anything about real
 * database concurrency.
 */
final class ProvenanceCorrectionSafetyTest extends TestCase {
    const USER = 42;

    private static function ref(string $label): string {
        return EVC_Pmpro_Fixture::ref($label);
    }

    private static function movement(string $label, string $at_athens, string $state = EVC_Verified_Movement::STATE_CONFIRMED): EVC_Verified_Movement {
        return new EVC_Verified_Movement(
            self::ref('mv:' . $label),
            'live',
            self::USER,
            EVC_Pmpro_Fixture::CLUB_LEVEL,
            self::ref('row1'),
            EVC_Pmpro_Fixture::athens($at_athens),
            2000,
            'EUR',
            array(
                new EVC_Movement_Alias(EVC_Movement_Alias::NS_WC_ORDER, self::ref('order:' . $label)),
                new EVC_Movement_Alias(EVC_Movement_Alias::NS_VIVA_TRANSACTION, self::ref('viva:' . $label)),
            ),
            EVC_Verified_Movement::AUTHORITY_GATEWAY_API,
            EVC_Payment_Evidence::KIND_WC_ORDER,
            $state
        );
    }

    private static function correction(string $label, string $movement_label, string $kind, string $at_athens = '2026-10-20 10:00:00'): EVC_Movement_Correction {
        return new EVC_Movement_Correction(self::ref('corr:' . $label), self::ref('mv:' . $movement_label), $kind, EVC_Verified_Movement::AUTHORITY_GATEWAY_API, EVC_Pmpro_Fixture::athens($at_athens));
    }

    private static function recorder(EVC_In_Memory_Provenance_Store $store): EVC_Provenance_Recorder {
        return new EVC_Provenance_Recorder($store, EVC_Pmpro_Fixture::config(), 'live', 2000, 'EUR');
    }

    /** A writer that runs once, between the recorder's reads and its commit. */
    private static function meanwhile(EVC_In_Memory_Provenance_Store $store, callable $write): void {
        $store->before_next_commit = function (EVC_In_Memory_Provenance_Store $s) use ($write) {
            $s->before_next_commit = null;
            $write(self::recorder($s));
        };
    }

    private function assertOutcome(EVC_Record_Outcome $o, string $outcome, ?string $reason = null): void {
        $this->assertSame($outcome, $o->outcome(), 'reason: ' . (string) $o->reason());
        if ($reason !== null) {
            $this->assertSame($reason, $o->reason());
        }
    }

    /** @return string[] correction kinds of the movement, oldest first */
    private static function kinds(EVC_In_Memory_Provenance_Store $store, string $movement_label): array {
        return array_map(function (EVC_Movement_Correction $c) {
            return $c->kind();
        }, $store->corrections_for(self::ref('mv:' . $movement_label)));
    }

    private static function map(EVC_In_Memory_Provenance_Store $store, string $at_athens): EVC_Entitlement {
        $rows = array(EVC_Pmpro_Fixture::row('row1', 'active', '2026-11-15 12:00:00', EVC_Pmpro_Fixture::CLUB_LEVEL, false, '2026-10-15 12:00:00'));
        return EVC_Pmpro_Fixture::mapper()->map(EVC_Provenance_Fact_Builder::snapshot($store, self::USER, $rows), EVC_Pmpro_Fixture::athens($at_athens));
    }

    private static function recorded_oct(): EVC_In_Memory_Provenance_Store {
        $store = new EVC_In_Memory_Provenance_Store();
        self::recorder($store)->record(self::movement('oct', '2026-10-15 12:00:00'));
        return $store;
    }

    // ---- A / B / C: atomic correction transitions ------------------------------

    public function test_A_two_competing_corrections_to_one_movement(): void {
        $store = self::recorded_oct();
        $r = self::recorder($store);
        // This writer read "no corrections yet"; another writer records a partial refund first.
        self::meanwhile($store, function (EVC_Provenance_Recorder $other) {
            $other->correct(self::correction('partial-other', 'oct', 'partially_refunded'));
        });
        $o = $r->correct(self::correction('full-ours', 'oct', 'refunded'));
        $this->assertOutcome($o, EVC_Record_Outcome::CONFLICT, 'concurrent_update');
        $this->assertNull($store->correction(self::ref('corr:full-ours')), 'not appended on a stale read');
        $this->assertSame(array('partially_refunded'), self::kinds($store, 'oct'));
        // A retry re-reads the history and appends in a valid order.
        $this->assertOutcome($r->correct(self::correction('full-ours', 'oct', 'refunded')), EVC_Record_Outcome::NEWLY_RECORDED);
        $this->assertSame(array('partially_refunded', 'refunded'), self::kinds($store, 'oct'));
    }

    public function test_B_stale_correction_after_another_writer_finalised_the_movement(): void {
        $store = self::recorded_oct();
        $r = self::recorder($store);
        $r->correct(self::correction('p1', 'oct', 'partially_refunded'));
        self::meanwhile($store, function (EVC_Provenance_Recorder $other) {
            $other->correct(self::correction('f1', 'oct', 'refunded'));
        });
        // Allowed after the partial refund this writer saw, but no longer after the final refund.
        $this->assertOutcome($r->correct(self::correction('rev1', 'oct', 'reversed')), EVC_Record_Outcome::CONFLICT, 'concurrent_update');
        $this->assertOutcome($r->correct(self::correction('rev1', 'oct', 'reversed')), EVC_Record_Outcome::CONFLICT, 'correction_state_regression');
        $this->assertSame(array('partially_refunded', 'refunded'), self::kinds($store, 'oct'));
        $this->assertNull($store->correction(self::ref('corr:rev1')));
    }

    public function test_C_atomic_rejection_leaves_no_partial_writes(): void {
        $store = self::recorded_oct();
        $r = self::recorder($store);
        self::meanwhile($store, function (EVC_Provenance_Recorder $other) {
            $other->correct(self::correction('x', 'oct', 'refunded'));
        });
        $r->correct(self::correction('y', 'oct', 'reversed'));
        $after_race = $store->counts();
        $this->assertSame(1, $after_race['corrections'], 'only the winning writer');

        // Store failures part-way through each new kind of write change nothing.
        $store2 = new EVC_In_Memory_Provenance_Store();
        $r2 = self::recorder($store2);
        $store2->fail_next_commit_after_apply = true;
        $this->assertOutcome($r2->correct(self::correction('r1', 'oct', 'refunded')), EVC_Record_Outcome::UNAVAILABLE);
        $this->assertSame(array(), $store2->unresolved_corrections());
        $this->assertSame(0, $store2->counts()['corrections']);

        $r2->correct(self::correction('r1', 'oct', 'refunded'));
        $before = $store2->counts();
        $store2->fail_next_commit_after_apply = true;
        $this->assertOutcome($r2->reconcile(new EVC_Movement_Reconciliation(self::movement('oct', '2026-10-15 12:00:00'), array(self::ref('corr:r1')))), EVC_Record_Outcome::UNAVAILABLE);
        $this->assertSame($before, $store2->counts());
        $this->assertNull($store2->movement(self::ref('mv:oct')));
        $this->assertArrayHasKey(self::ref('mv:oct'), $store2->unresolved_corrections(), 'obligation kept');
    }

    // ---- D / E / F: legitimate progress, forbidden regression, replay ----------

    public function test_D_partial_refund_followed_by_full_refund(): void {
        $store = self::recorded_oct();
        $r = self::recorder($store);
        $this->assertOutcome($r->correct(self::correction('p1', 'oct', 'partially_refunded')), EVC_Record_Outcome::NEWLY_RECORDED);
        $this->assertSame('indeterminate', self::map($store, '2026-10-20 09:00')->status());
        $this->assertOutcome($r->correct(self::correction('f1', 'oct', 'refunded', '2026-10-21 10:00:00')), EVC_Record_Outcome::NEWLY_RECORDED);
        $this->assertSame(array('partially_refunded', 'refunded'), self::kinds($store, 'oct'));
        $this->assertSame('refunded', self::map($store, '2026-10-22 09:00')->status());
    }

    public function test_E_forbidden_state_regression_is_refused_by_the_store_itself(): void {
        $store = self::recorded_oct();
        self::recorder($store)->correct(self::correction('f1', 'oct', 'refunded'));
        $before = $store->counts();
        // A writer that skips the recorder's own check but has an up-to-date read.
        foreach (array('partially_refunded', 'refunded', 'reversed', 'voided') as $kind) {
            try {
                $store->commit(EVC_Provenance_Write::correction(self::correction('late-' . $kind, 'oct', $kind), self::ref('corr:f1'), true));
                $this->fail('store accepted ' . $kind . ' after a final refund');
            } catch (EVC_Provenance_Conflict_Exception $e) {
                $this->assertStringContainsString('forbidden correction transition', $e->getMessage());
            }
        }
        // Same for a stale expected predecessor and a wrong recorded-state expectation.
        $cases = array(
            array(null, true, 'compare-and-set'),
            array(self::ref('corr:other'), true, 'compare-and-set'),
            array(self::ref('corr:f1'), false, 'recorded state'),
        );
        foreach ($cases as list($prior, $recorded, $message)) {
            try {
                $store->commit(EVC_Provenance_Write::correction(self::correction('stale', 'oct', 'reversed'), $prior, $recorded));
                $this->fail('store accepted a stale correction write');
            } catch (EVC_Provenance_Conflict_Exception $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }
        $this->assertSame($before, $store->counts());
        $this->assertSame(array('refunded'), self::kinds($store, 'oct'));
        // And through the recorder.
        $this->assertOutcome(self::recorder($store)->correct(self::correction('p9', 'oct', 'partially_refunded')), EVC_Record_Outcome::CONFLICT, 'correction_state_regression');
    }

    public function test_F_identical_correction_replay_is_idempotent(): void {
        $store = self::recorded_oct();
        $r = self::recorder($store);
        $r->correct(self::correction('r1', 'oct', 'refunded'));
        $r->correct(self::correction('q1', 'nov', 'refunded')); // unresolved
        $commits = $store->commits;
        for ($i = 0; $i < 3; $i++) {
            $this->assertOutcome($r->correct(self::correction('r1', 'oct', 'refunded')), EVC_Record_Outcome::ALREADY_RECORDED);
            $this->assertOutcome($r->correct(self::correction('q1', 'nov', 'refunded')), EVC_Record_Outcome::ALREADY_RECORDED);
        }
        $this->assertSame($commits, $store->commits, 'no write for a replay');
        // Same id, different content: conflict, never an overwrite.
        $this->assertOutcome($r->correct(self::correction('r1', 'oct', 'reversed')), EVC_Record_Outcome::CONFLICT, 'correction_conflict');
        $this->assertOutcome($r->correct(self::correction('q1', 'oct', 'refunded')), EVC_Record_Outcome::CONFLICT, 'correction_conflict');
        $this->assertSame(2, $store->counts()['corrections']);
    }

    // ---- G / H / I / J: refund arriving before its payment ---------------------

    public function test_G_refund_signal_before_the_original_payment_is_kept_as_an_obligation(): void {
        $store = new EVC_In_Memory_Provenance_Store();
        $o = self::recorder($store)->correct(self::correction('r1', 'oct', 'refunded'));
        $this->assertOutcome($o, EVC_Record_Outcome::QUARANTINED, 'unknown_movement');
        $this->assertNull($o->period());
        $this->assertSame(array('movements' => 0, 'aliases' => 0, 'periods' => 0, 'corrections' => 1), $store->counts());
        $unresolved = $store->unresolved_corrections();
        $this->assertSame(array(self::ref('mv:oct')), array_keys($unresolved));
        $this->assertSame(self::ref('corr:r1'), $unresolved[self::ref('mv:oct')][0]->correction_id());
        // Corrections for unrecorded movements still follow the forward-only rule.
        $this->assertOutcome(self::recorder($store)->correct(self::correction('p1', 'oct', 'partially_refunded')), EVC_Record_Outcome::CONFLICT, 'correction_state_regression');
    }

    public function test_H_stale_confirmed_payment_after_the_refund_is_not_recorded(): void {
        $store = new EVC_In_Memory_Provenance_Store();
        $r = self::recorder($store);
        $r->correct(self::correction('r1', 'oct', 'refunded'));
        $before = $store->counts();
        $commits = $store->commits;
        for ($i = 0; $i < 2; $i++) {
            $o = $r->record(self::movement('oct', '2026-10-15 12:00:00'));
            $this->assertOutcome($o, EVC_Record_Outcome::INDETERMINATE, 'unresolved_correction');
            $this->assertNull($o->period());
        }
        $this->assertSame($before, $store->counts());
        $this->assertSame($commits, $store->commits);
        $this->assertNull($store->movement_for_alias('wc_order:' . self::ref('order:oct')));
        $this->assertArrayHasKey(self::ref('mv:oct'), $store->unresolved_corrections(), 'never silently deleted');
    }

    public function test_H_refund_arriving_while_the_payment_is_being_recorded(): void {
        // The recorder read "no corrections", then a refund was quarantined before its commit.
        $store = new EVC_In_Memory_Provenance_Store();
        $r = self::recorder($store);
        self::meanwhile($store, function (EVC_Provenance_Recorder $other) {
            $other->correct(self::correction('r1', 'oct', 'refunded'));
        });
        $this->assertOutcome($r->record(self::movement('oct', '2026-10-15 12:00:00')), EVC_Record_Outcome::CONFLICT, 'concurrent_update');
        $this->assertNull($store->movement(self::ref('mv:oct')));
        $this->assertNull($store->latest_period(self::USER));
        $this->assertOutcome($r->record(self::movement('oct', '2026-10-15 12:00:00')), EVC_Record_Outcome::INDETERMINATE, 'unresolved_correction');
        // And the opposite race: the payment is recorded while the refund is being quarantined.
        $store2 = new EVC_In_Memory_Provenance_Store();
        $r2 = self::recorder($store2);
        self::meanwhile($store2, function (EVC_Provenance_Recorder $other) {
            $other->record(self::movement('oct', '2026-10-15 12:00:00'));
        });
        $this->assertOutcome($r2->correct(self::correction('r1', 'oct', 'refunded')), EVC_Record_Outcome::CONFLICT, 'concurrent_update');
        $this->assertOutcome($r2->correct(self::correction('r1', 'oct', 'refunded')), EVC_Record_Outcome::NEWLY_RECORDED, null);
        $this->assertSame(array('refunded'), self::kinds($store2, 'oct'));
        $this->assertSame(array(), $store2->unresolved_corrections());
    }

    public function test_H_store_refuses_an_unacknowledged_movement_directly(): void {
        $store = new EVC_In_Memory_Provenance_Store();
        self::recorder($store)->correct(self::correction('r1', 'oct', 'refunded'));
        $m = self::movement('oct', '2026-10-15 12:00:00');
        $next = EVC_Membership_Calendar::next_period(null, $m->verified_at_utc());
        $period = new EVC_Funded_Period($m->movement_id(), self::USER, EVC_Pmpro_Fixture::CLUB_LEVEL, self::ref('row1'), $next['start'], $next['end'], EVC_Funded_Period::KIND_INITIAL, null);
        foreach (array(array(), array(self::ref('corr:other')), array(self::ref('corr:r1'), self::ref('corr:r1b'))) as $expected) {
            try {
                $store->commit(EVC_Provenance_Write::new_movement($m, $period, $expected));
                $this->fail('store recorded a movement past an unacknowledged refund');
            } catch (EVC_Provenance_Conflict_Exception $e) {
                $this->assertStringContainsString('I7', $e->getMessage());
            }
        }
        $this->assertNull($store->movement(self::ref('mv:oct')));
    }

    public function test_I_such_a_payment_cannot_produce_eligible_membership(): void {
        $store = new EVC_In_Memory_Provenance_Store();
        $r = self::recorder($store);
        $r->correct(self::correction('r1', 'oct', 'refunded'));
        $r->record(self::movement('oct', '2026-10-15 12:00:00'));
        // PMPro (stale) says active; there is no recorded provenance, so no paid evidence.
        $e = self::map($store, '2026-10-20 09:00');
        $this->assertSame('indeterminate', $e->status());
        $this->assertContains('no_payment_evidence', $e->diagnostic_flags());
        $this->assertFalse($e->is_eligible_at(EVC_Pmpro_Fixture::athens('2026-10-20 09:00')));
    }

    public function test_J_trusted_reconciliation_resolves_the_obligation_atomically(): void {
        $store = new EVC_In_Memory_Provenance_Store();
        $r = self::recorder($store);
        $r->correct(self::correction('r1', 'oct', 'refunded'));
        $m = self::movement('oct', '2026-10-15 12:00:00');
        $o = $r->reconcile(new EVC_Movement_Reconciliation($m, array(self::ref('corr:r1'))));
        $this->assertOutcome($o, EVC_Record_Outcome::NEWLY_RECORDED);
        $this->assertSame(array(), $store->unresolved_corrections());
        $this->assertSame(array('refunded'), self::kinds($store, 'oct'), 'refund kept as the movement history');
        $this->assertSame(array('movements' => 1, 'aliases' => 2, 'periods' => 1, 'corrections' => 1), $store->counts());
        // The payment never appears without its refund: the mapper sees a refunded payment.
        $e = self::map($store, '2026-10-20 09:00');
        $this->assertSame('refunded', $e->status());
        $this->assertFalse($e->is_eligible_at(EVC_Pmpro_Fixture::athens('2026-10-20 09:00')));
        // Replays are idempotent; plain record() sees the same movement.
        $commits = $store->commits;
        $this->assertOutcome($r->reconcile(new EVC_Movement_Reconciliation($m, array(self::ref('corr:r1')))), EVC_Record_Outcome::ALREADY_RECORDED);
        $this->assertOutcome($r->record($m), EVC_Record_Outcome::ALREADY_RECORDED);
        $this->assertSame($commits, $store->commits);
    }

    public function test_J_reconciliation_must_acknowledge_exactly_the_held_corrections(): void {
        $store = new EVC_In_Memory_Provenance_Store();
        $r = self::recorder($store);
        $r->correct(self::correction('p1', 'oct', 'partially_refunded'));
        $r->correct(self::correction('f1', 'oct', 'refunded'));
        $m = self::movement('oct', '2026-10-15 12:00:00');
        $before = $store->counts();
        foreach (array(
            array(self::ref('corr:p1')),                              // incomplete
            array(self::ref('corr:f1'), self::ref('corr:p1')),        // reordered
            array(self::ref('corr:p1'), self::ref('corr:f1'), self::ref('corr:x')), // invented
        ) as $ack) {
            $this->assertOutcome($r->reconcile(new EVC_Movement_Reconciliation($m, $ack)), EVC_Record_Outcome::CONFLICT, 'reconciliation_mismatch');
        }
        // Nothing held for this movement: nothing to reconcile.
        $this->assertOutcome($r->reconcile(new EVC_Movement_Reconciliation(self::movement('nov', '2026-11-01 09:00:00'), array(self::ref('corr:p1')))), EVC_Record_Outcome::CONFLICT, 'reconciliation_mismatch');
        // Reconciliation is no shortcut past the ordinary recording rules.
        $this->assertOutcome($r->reconcile(new EVC_Movement_Reconciliation(self::movement('oct', '2026-10-15 12:00:00', 'pending'), array(self::ref('corr:p1'), self::ref('corr:f1')))), EVC_Record_Outcome::INDETERMINATE, 'movement_not_confirmed');
        $this->assertSame($before, $store->counts());
        $this->assertOutcome($r->reconcile(new EVC_Movement_Reconciliation($m, array(self::ref('corr:p1'), self::ref('corr:f1')))), EVC_Record_Outcome::NEWLY_RECORDED);
        $this->assertSame(array('partially_refunded', 'refunded'), self::kinds($store, 'oct'));
    }

    public function test_J_correction_arriving_during_reconciliation_aborts_it(): void {
        $store = new EVC_In_Memory_Provenance_Store();
        $r = self::recorder($store);
        $r->correct(self::correction('p1', 'oct', 'partially_refunded'));
        self::meanwhile($store, function (EVC_Provenance_Recorder $other) {
            $other->correct(self::correction('f1', 'oct', 'refunded'));
        });
        $m = self::movement('oct', '2026-10-15 12:00:00');
        $this->assertOutcome($r->reconcile(new EVC_Movement_Reconciliation($m, array(self::ref('corr:p1')))), EVC_Record_Outcome::CONFLICT, 'concurrent_update');
        $this->assertNull($store->movement(self::ref('mv:oct')));
        $this->assertSame(array('partially_refunded', 'refunded'), self::kinds($store, 'oct'));
        $this->assertOutcome($r->reconcile(new EVC_Movement_Reconciliation($m, array(self::ref('corr:p1')))), EVC_Record_Outcome::CONFLICT, 'reconciliation_mismatch');
    }

    // ---- K: invalid identity ---------------------------------------------------

    public function test_K_invalid_correction_or_reconciliation_identity_changes_nothing(): void {
        $store = self::recorded_oct();
        $before = $store->counts();
        $bad = array(
            function () { return new EVC_Movement_Correction('r1', self::ref('mv:oct'), 'refunded', 'gateway_api', EVC_Pmpro_Fixture::athens('2026-10-20 10:00')); },
            function () { return new EVC_Movement_Correction(self::ref('corr:r1'), '1001', 'refunded', 'gateway_api', EVC_Pmpro_Fixture::athens('2026-10-20 10:00')); },
            function () { return new EVC_Movement_Correction(self::ref('corr:r1'), self::ref('mv:oct'), 'chargeback_won', 'gateway_api', EVC_Pmpro_Fixture::athens('2026-10-20 10:00')); },
            function () { return new EVC_Movement_Correction(self::ref('corr:r1'), self::ref('mv:oct'), 'refunded', 'webhook', EVC_Pmpro_Fixture::athens('2026-10-20 10:00')); },
            function () { return new EVC_Movement_Correction(self::ref('corr:r1'), self::ref('mv:oct'), 'refunded', 'gateway_api', new DateTimeImmutable('2026-10-20 10:00', new DateTimeZone('Europe/Athens'))); },
            function () { return new EVC_Movement_Reconciliation(self::movement('oct', '2026-10-15 12:00:00'), array()); },
            function () { return new EVC_Movement_Reconciliation(self::movement('oct', '2026-10-15 12:00:00'), array('r1')); },
            function () { return new EVC_Movement_Reconciliation(self::movement('oct', '2026-10-15 12:00:00'), array(self::ref('corr:r1'), self::ref('corr:r1'))); },
        );
        foreach ($bad as $i => $make) {
            try {
                $make();
                $this->fail('accepted invalid input #' . $i);
            } catch (InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
        $this->assertSame($before, $store->counts());
        $this->assertSame(array(), $store->unresolved_corrections());
    }

    // ---- L: existing chain and idempotency rules -------------------------------

    public function test_L_unresolved_correction_does_not_disturb_other_movements_or_chain_rules(): void {
        $store = new EVC_In_Memory_Provenance_Store();
        $r = self::recorder($store);
        $r->correct(self::correction('r1', 'oct', 'refunded'));
        // A different, genuine payment is unaffected.
        $nov = $r->record(self::movement('nov', '2026-11-01 09:00:00'));
        $this->assertOutcome($nov, EVC_Record_Outcome::NEWLY_RECORDED);
        $this->assertSame('initial', $nov->period()->kind());
        $this->assertOutcome($r->record(self::movement('nov', '2026-11-01 09:00:00')), EVC_Record_Outcome::ALREADY_RECORDED);
        // Reconciling the older October payment cannot be inserted before November.
        $o = $r->reconcile(new EVC_Movement_Reconciliation(self::movement('oct', '2026-10-15 12:00:00'), array(self::ref('corr:r1'))));
        $this->assertOutcome($o, EVC_Record_Outcome::INDETERMINATE, 'out_of_order_payment');
        $this->assertSame(1, $store->counts()['periods']);
        $this->assertArrayHasKey(self::ref('mv:oct'), $store->unresolved_corrections());
    }
}
