<?php
/**
 * Store contract suite (Task 1D-G): the SAME recorder-level behaviour run
 * against every EVC_Provenance_Store implementation (in-memory: unit suite;
 * SQL: integration suite on real MySQL/MariaDB). Uses only the interface,
 * so a store that silently diverges from the reviewed in-memory semantics
 * fails here. Sequential behaviour only; real concurrency is proven
 * separately by SqlProvenanceConcurrencyTest.
 */
trait EVC_Provenance_Store_Contract {
    abstract protected function contract_store(): EVC_Provenance_Store;

    private function c_rec(): EVC_Provenance_Recorder {
        return EVC_Provenance_Test_Fixture::recorder($this->contract_store());
    }

    private static function c_mv(string $label, string $at, array $o = array()): EVC_Verified_Movement {
        return EVC_Provenance_Test_Fixture::movement($label, $at, $o);
    }

    private static function c_corr(string $label, string $movement, string $kind): EVC_Movement_Correction {
        return EVC_Provenance_Test_Fixture::correction($label, $movement, $kind);
    }

    private static function c_ref(string $label): string {
        return EVC_Provenance_Test_Fixture::ref($label);
    }

    private static function c_local(DateTimeImmutable $t): string {
        return $t->setTimezone(new DateTimeZone('Europe/Athens'))->format('Y-m-d H:i:s');
    }

    private function c_outcome(EVC_Record_Outcome $o, string $outcome, ?string $reason = null): void {
        $this->assertSame($outcome, $o->outcome(), 'reason: ' . (string) $o->reason());
        if ($reason !== null) {
            $this->assertSame($reason, $o->reason());
        }
    }

    /** @return string[] */
    private function c_kinds(string $movement_label): array {
        return array_map(function (EVC_Movement_Correction $c) {
            return $c->kind();
        }, $this->contract_store()->corrections_for(self::c_ref('mv:' . $movement_label)));
    }

    public function test_contract_initial_early_and_late_periods(): void {
        $r = $this->c_rec();
        $p = $r->record(self::c_mv('oct', '2026-10-15 12:00:00'))->period();
        $this->assertSame(array('initial', '2026-10-15 12:00:00', '2026-11-15 12:00:00'), array($p->kind(), self::c_local($p->start_utc()), self::c_local($p->end_utc())));
        $e = $r->record(self::c_mv('nov', '2026-11-01 09:00:00'))->period();
        $this->assertSame(array('early', '2026-11-15 12:00:00', '2026-12-15 12:00:00'), array($e->kind(), self::c_local($e->start_utc()), self::c_local($e->end_utc())));
        $l = $r->record(self::c_mv('jan', '2027-01-20 10:00:00'))->period();
        $this->assertSame(array('late', '2027-01-20 10:00:00', '2027-02-20 10:00:00'), array($l->kind(), self::c_local($l->start_utc()), self::c_local($l->end_utc())));
        $this->assertSame(self::c_ref('mv:nov'), $l->predecessor_movement_id());
        $this->assertSame(self::c_ref('mv:jan'), $this->contract_store()->latest_period(EVC_Provenance_Test_Fixture::USER)->movement_id());
        $this->assertCount(3, $this->contract_store()->member_movements(EVC_Provenance_Test_Fixture::USER));
        // Month-end clamp (D5) through the store.
        $c = $r->record(self::c_mv('jan31', '2027-03-31 12:00:00', array('user' => 77)))->period();
        $this->assertSame('2027-04-30 12:00:00', self::c_local($c->end_utc()));
    }

    public function test_contract_duplicate_and_cross_source_signals_fund_one_period(): void {
        $r = $this->c_rec();
        $first = $r->record(self::c_mv('oct', '2026-10-15 12:00:00'));
        $this->c_outcome($first, EVC_Record_Outcome::NEWLY_RECORDED);
        $this->c_outcome($r->record(self::c_mv('oct', '2026-10-15 12:00:00')), EVC_Record_Outcome::ALREADY_RECORDED);
        $more = self::c_mv('oct', '2026-10-15 12:00:00', array('aliases' => array(
            new EVC_Movement_Alias(EVC_Movement_Alias::NS_WC_ORDER, self::c_ref('order:oct')),
            new EVC_Movement_Alias(EVC_Movement_Alias::NS_PAYPAL_TRANSACTION, self::c_ref('pp:oct')),
        )));
        $again = $r->record($more);
        $this->c_outcome($again, EVC_Record_Outcome::ALREADY_RECORDED);
        $this->assertEquals($first->period(), $again->period());
        $this->assertSame(self::c_ref('mv:oct'), $this->contract_store()->movement_for_alias('paypal_transaction:' . self::c_ref('pp:oct')));
        // Alias ownership is read through movement_for_alias(); movement()->aliases() is
        // not part of the contract (the in-memory store keeps the first object).
        $this->assertSame(self::c_ref('mv:oct'), $this->contract_store()->movement_for_alias('viva_transaction:' . self::c_ref('viva:oct')));
        // Same order under another canonical id, and an alias of another movement: conflicts.
        $dup = self::c_mv('oct-dup', '2026-10-15 12:00:05', array('aliases' => array(new EVC_Movement_Alias(EVC_Movement_Alias::NS_WC_ORDER, self::c_ref('order:oct')))));
        $this->c_outcome($r->record($dup), EVC_Record_Outcome::CONFLICT, 'alias_conflict');
        $this->assertCount(1, $this->contract_store()->member_movements(EVC_Provenance_Test_Fixture::USER));
    }

    public function test_contract_changed_facts_order_and_instant_are_refused(): void {
        $r = $this->c_rec();
        $r->record(self::c_mv('nov', '2026-11-01 09:00:00'));
        $this->c_outcome($r->record(self::c_mv('nov', '2026-11-01 09:00:00', array('row' => self::c_ref('row9')))), EVC_Record_Outcome::CONFLICT, 'movement_fact_conflict');
        $this->c_outcome($r->record(self::c_mv('oct', '2026-10-15 12:00:00')), EVC_Record_Outcome::INDETERMINATE, 'out_of_order_payment');
        $this->c_outcome($r->record(self::c_mv('same', '2026-11-01 09:00:00')), EVC_Record_Outcome::INDETERMINATE, 'same_instant_payment');
        $this->assertCount(1, $this->contract_store()->member_movements(EVC_Provenance_Test_Fixture::USER));
    }

    public function test_contract_corrections_are_forward_only_and_idempotent(): void {
        $r = $this->c_rec();
        $r->record(self::c_mv('oct', '2026-10-15 12:00:00'));
        $this->c_outcome($r->correct(self::c_corr('p1', 'oct', 'partially_refunded')), EVC_Record_Outcome::NEWLY_RECORDED);
        $this->c_outcome($r->correct(self::c_corr('p1', 'oct', 'partially_refunded')), EVC_Record_Outcome::ALREADY_RECORDED);
        $this->c_outcome($r->correct(self::c_corr('p1', 'oct', 'refunded')), EVC_Record_Outcome::CONFLICT, 'correction_conflict');
        $this->c_outcome($r->correct(self::c_corr('p2', 'oct', 'partially_refunded')), EVC_Record_Outcome::CONFLICT, 'correction_state_regression');
        $this->c_outcome($r->correct(self::c_corr('f1', 'oct', 'refunded')), EVC_Record_Outcome::NEWLY_RECORDED);
        $this->c_outcome($r->correct(self::c_corr('v1', 'oct', 'voided')), EVC_Record_Outcome::CONFLICT, 'correction_state_regression');
        $this->assertSame(array('partially_refunded', 'refunded'), $this->c_kinds('oct'));
        $this->assertSame(self::c_ref('mv:oct'), $this->contract_store()->correction(self::c_ref('corr:f1'))->movement_id());
        $this->assertNotNull($this->contract_store()->period_for_movement(self::c_ref('mv:oct')), 'history never erased');
    }

    public function test_contract_refund_first_quarantine_blocks_until_exact_reconciliation(): void {
        $r = $this->c_rec();
        $this->c_outcome($r->correct(self::c_corr('r1', 'oct', 'refunded')), EVC_Record_Outcome::QUARANTINED, 'unknown_movement');
        $this->assertSame(array(self::c_ref('mv:oct')), array_keys($this->contract_store()->unresolved_corrections()));
        $this->c_outcome($r->record(self::c_mv('oct', '2026-10-15 12:00:00')), EVC_Record_Outcome::INDETERMINATE, 'unresolved_correction');
        $this->assertNull($this->contract_store()->movement(self::c_ref('mv:oct')));
        $m = self::c_mv('oct', '2026-10-15 12:00:00');
        $this->c_outcome($r->reconcile(new EVC_Movement_Reconciliation($m, array(self::c_ref('corr:x')))), EVC_Record_Outcome::CONFLICT, 'reconciliation_mismatch');
        $this->c_outcome($r->reconcile(new EVC_Movement_Reconciliation($m, array(self::c_ref('corr:r1')))), EVC_Record_Outcome::NEWLY_RECORDED);
        $this->assertSame(array(), $this->contract_store()->unresolved_corrections());
        $this->assertSame(array('refunded'), $this->c_kinds('oct'));
        $this->c_outcome($r->reconcile(new EVC_Movement_Reconciliation($m, array(self::c_ref('corr:r1')))), EVC_Record_Outcome::ALREADY_RECORDED);
        $this->c_outcome($r->record($m), EVC_Record_Outcome::ALREADY_RECORDED);
        // Forward-only also for corrections held before the payment.
        $this->c_outcome($r->correct(self::c_corr('q1', 'dec', 'refunded')), EVC_Record_Outcome::QUARANTINED);
        $this->c_outcome($r->correct(self::c_corr('q2', 'dec', 'partially_refunded')), EVC_Record_Outcome::CONFLICT, 'correction_state_regression');
    }

    /** Task 1D-I identity core (synthetic verifier) on top of this store. */
    public function test_contract_identity_core_round_trip(): void {
        $verifier = new EVC_Fake_Payment_Source_Verifier();
        $gate = EVC_Payment_Identity_Fixture::gate($verifier);
        $capture = EVC_Payment_Identity_Fixture::paypal_capture('CONTRACT-CAP-1');
        $verifier->put($capture, 'captured', 2000, array(EVC_Payment_Identity_Fixture::wc_order('5001')));
        $v1 = EVC_Payment_Identity_Fixture::ring('live', array(1));
        $resolver = new EVC_Payment_Identity_Resolver(new EVC_Payment_Identity($v1), $this->contract_store());
        // Refund verified first: quarantined under the id the payment will have.
        $verifier->put(EVC_Payment_Identity_Fixture::paypal_refund('CONTRACT-RF-1'), 'completed', 2000, array(), array($capture), 'partially_refunded');
        $refund = $gate->verify_refund(EVC_Payment_Identity_Fixture::paypal_refund('CONTRACT-RF-1'))->refund();
        $rr = $resolver->resolve_refund($refund);
        $this->c_outcome($this->c_rec()->correct($resolver->build_correction($refund, $rr, EVC_Pmpro_Fixture::athens('2026-10-16 10:00'))), EVC_Record_Outcome::QUARANTINED);
        $payment = $gate->verify_payment($capture)->payment();
        $pr = $resolver->resolve_payment($payment);
        $this->assertSame($rr->movement_id(), $pr->movement_id());
        $m = $resolver->build_movement($payment, $pr, EVC_Provenance_Test_Fixture::USER, 7, self::c_ref('row1'), EVC_Pmpro_Fixture::athens('2026-10-15 12:00:00'));
        $this->c_outcome($this->c_rec()->record($m), EVC_Record_Outcome::INDETERMINATE, 'unresolved_correction');
        $this->c_outcome($this->c_rec()->reconcile(new EVC_Movement_Reconciliation($m, array($rr->correction_id()))), EVC_Record_Outcome::NEWLY_RECORDED);
        // After an alias-key rotation the same payment is found, not duplicated.
        $v2 = EVC_Payment_Identity_Fixture::ring('live', array(1, 2), 1, 2, array(1));
        $again = (new EVC_Payment_Identity_Resolver(new EVC_Payment_Identity($v2), $this->contract_store()))->resolve_payment($payment);
        $this->assertSame(array(true, $pr->movement_id(), 1), array($again->existing(), $again->movement_id(), $again->alias_version()));
        $this->assertSame($pr->movement_id(), $this->contract_store()->movement_for_alias('wc_order:' . (new EVC_Payment_Identity($v1))->alias_ref(EVC_Payment_Identity_Fixture::wc_order('5001'), 1)));
        // A second capture on the same order is refused.
        $verifier->put(EVC_Payment_Identity_Fixture::paypal_capture('CONTRACT-CAP-2'), 'captured', 2000, array(EVC_Payment_Identity_Fixture::wc_order('5001')));
        $this->assertSame('alias_owned_by_other_movement', $resolver->resolve_payment($gate->verify_payment(EVC_Payment_Identity_Fixture::paypal_capture('CONTRACT-CAP-2'))->payment())->reason());
    }

    /** Task 1D-I.R1-A helper: gate + verifier with one captured PayPal capture linked to an order. */
    private function c_identity_world(string $capture_id, string $order): array {
        $verifier = new EVC_Fake_Payment_Source_Verifier();
        $verifier->put(EVC_Payment_Identity_Fixture::paypal_capture($capture_id), 'captured', 2000, array(EVC_Payment_Identity_Fixture::wc_order($order)));
        return array($verifier, EVC_Payment_Identity_Fixture::gate($verifier));
    }

    private function c_resolver(EVC_Payment_Key_Ring $ring): EVC_Payment_Identity_Resolver {
        return new EVC_Payment_Identity_Resolver(new EVC_Payment_Identity($ring), $this->contract_store());
    }

    /** Refund-first quarantined under identity v1 must never be bypassed after v2 is pinned. */
    public function test_contract_r1a_cross_version_refund_first_is_never_bypassed(): void {
        list($verifier, $gate) = $this->c_identity_world('XV-CAP-1', '6001');
        $capture = EVC_Payment_Identity_Fixture::paypal_capture('XV-CAP-1');
        $v1 = EVC_Payment_Identity_Fixture::ring('live', array(1));
        $v2 = EVC_Payment_Identity_Fixture::ring('live', array(1, 2), 2, 2, array(1, 2));
        $v1_id = (new EVC_Payment_Identity($v1))->movement_id($capture, 1);
        // 1-3. Identity v1: verified refund before payment -> quarantined under the v1 id.
        $verifier->put(EVC_Payment_Identity_Fixture::paypal_refund('XV-RF-1'), 'completed', 2000, array(), array($capture), 'refunded');
        $refund = $gate->verify_refund(EVC_Payment_Identity_Fixture::paypal_refund('XV-RF-1'))->refund();
        $rv1 = $this->c_resolver($v1)->resolve_refund($refund);
        $this->assertSame($v1_id, $rv1->movement_id());
        $this->c_outcome($this->c_rec()->correct($this->c_resolver($v1)->build_correction($refund, $rv1, EVC_Pmpro_Fixture::athens('2026-10-16 10:00'))), EVC_Record_Outcome::QUARANTINED);
        // 4-6. v2 pinned, v1 retained; a stale "captured" read resolves to the HELD v1 id, not a clean v2 id.
        $payment = $gate->verify_payment($capture)->payment();
        $res = $this->c_resolver($v2)->resolve_payment($payment);
        $this->assertSame(array(EVC_Payment_Identity_Resolution::RESOLVED, $v1_id, false), array($res->status(), $res->movement_id(), $res->existing()));
        $m = $this->c_resolver($v2)->build_movement($payment, $res, EVC_Provenance_Test_Fixture::USER, 7, self::c_ref('row1'), EVC_Pmpro_Fixture::athens('2026-10-15 12:00:00'));
        $this->c_outcome($this->c_rec()->record($m), EVC_Record_Outcome::INDETERMINATE, 'unresolved_correction');
        $this->assertCount(0, $this->contract_store()->member_movements(EVC_Provenance_Test_Fixture::USER), 'no paid period');
        $rows = array(EVC_Pmpro_Fixture::row('row1', 'active', '2026-11-15 12:00:00', 7, false, '2026-10-15 12:00:00'));
        $e = EVC_Pmpro_Fixture::mapper()->map(EVC_Provenance_Fact_Builder::snapshot($this->contract_store(), EVC_Provenance_Test_Fixture::USER, $rows), EVC_Pmpro_Fixture::athens('2026-10-20 09:00'));
        $this->assertFalse($e->is_eligible_at(EVC_Pmpro_Fixture::athens('2026-10-20 09:00')), 'no clean entitlement after quarantine');
        // Repeated refund under the v2 ring: same (v1) correction, idempotent.
        $again = $this->c_resolver($v2)->resolve_refund($refund);
        $this->assertSame(array($v1_id, $rv1->correction_id(), true), array($again->movement_id(), $again->correction_id(), $again->correction_existing()));
        $this->c_outcome($this->c_rec()->correct($this->c_resolver($v2)->build_correction($refund, $again, EVC_Pmpro_Fixture::athens('2026-10-16 10:00'))), EVC_Record_Outcome::ALREADY_RECORDED);
        // Reconciliation still works on the held id; afterwards the payment is found as existing.
        $this->c_outcome($this->c_rec()->reconcile(new EVC_Movement_Reconciliation($m, array($rv1->correction_id()))), EVC_Record_Outcome::NEWLY_RECORDED);
        $after = $this->c_resolver($v2)->resolve_payment($payment);
        $this->assertSame(array($v1_id, true), array($after->movement_id(), $after->existing()));
        $this->assertCount(1, $this->contract_store()->member_movements(EVC_Provenance_Test_Fixture::USER), 'exactly one period');
        $e2 = EVC_Pmpro_Fixture::mapper()->map(EVC_Provenance_Fact_Builder::snapshot($this->contract_store(), EVC_Provenance_Test_Fixture::USER, $rows), EVC_Pmpro_Fixture::athens('2026-10-20 09:00'));
        $this->assertSame('refunded', $e2->status());
    }

    /** Payment-first under v1, refund after v2 is pinned: attaches to the v1 movement. */
    public function test_contract_r1a_cross_version_payment_first_then_refund(): void {
        list($verifier, $gate) = $this->c_identity_world('XV-CAP-2', '6002');
        $capture = EVC_Payment_Identity_Fixture::paypal_capture('XV-CAP-2');
        $v1 = EVC_Payment_Identity_Fixture::ring('live', array(1));
        $v2 = EVC_Payment_Identity_Fixture::ring('live', array(1, 2), 2, 2, array(1, 2));
        $payment = $gate->verify_payment($capture)->payment();
        $res = $this->c_resolver($v1)->resolve_payment($payment);
        $this->c_rec()->record($this->c_resolver($v1)->build_movement($payment, $res, EVC_Provenance_Test_Fixture::USER, 7, self::c_ref('row1'), EVC_Pmpro_Fixture::athens('2026-10-15 12:00:00')));
        $verifier->put(EVC_Payment_Identity_Fixture::paypal_refund('XV-RF-2'), 'completed', 2000, array(), array($capture), 'refunded');
        $refund = $gate->verify_refund(EVC_Payment_Identity_Fixture::paypal_refund('XV-RF-2'))->refund();
        $rr = $this->c_resolver($v2)->resolve_refund($refund);
        $this->assertSame(array($res->movement_id(), true), array($rr->movement_id(), $rr->existing()));
        $this->c_outcome($this->c_rec()->correct($this->c_resolver($v2)->build_correction($refund, $rr, EVC_Pmpro_Fixture::athens('2026-10-16 10:00'))), EVC_Record_Outcome::NEWLY_RECORDED);
        $this->assertSame(array('refunded'), $this->c_kinds_of($res->movement_id()));
        $this->assertSame(array(), $this->contract_store()->unresolved_corrections());
    }

    /** Obligations under two identity versions of one payment: fail closed. */
    public function test_contract_r1a_competing_candidate_identities_fail_closed(): void {
        list($verifier, $gate) = $this->c_identity_world('XV-CAP-3', '6003');
        $capture = EVC_Payment_Identity_Fixture::paypal_capture('XV-CAP-3');
        $both = EVC_Payment_Identity_Fixture::ring('live', array(1, 2), 2, 2, array(1, 2));
        $identity = new EVC_Payment_Identity($both);
        // Corrections held under BOTH the v1 and the v2 candidate ids (no movement anywhere).
        foreach (array(1, 2) as $v) {
            $this->c_rec()->correct(new EVC_Movement_Correction(self::c_ref('xv-corr-' . $v), $identity->movement_id($capture, $v), 'refunded', 'gateway_api', EVC_Pmpro_Fixture::athens('2026-10-16 10:00')));
        }
        $payment = $gate->verify_payment($capture)->payment();
        $res = $this->c_resolver($both)->resolve_payment($payment);
        $this->assertSame(array(EVC_Payment_Identity_Resolution::CONFLICT, 'identity_versions_disagree'), array($res->status(), $res->reason()));
        $verifier->put(EVC_Payment_Identity_Fixture::paypal_refund('XV-RF-3'), 'completed', 2000, array(), array($capture), 'refunded');
        $rr = $this->c_resolver($both)->resolve_refund($gate->verify_refund(EVC_Payment_Identity_Fixture::paypal_refund('XV-RF-3'))->refund());
        $this->assertSame('identity_versions_disagree', $rr->reason());
        $this->assertCount(0, $this->contract_store()->member_movements(EVC_Provenance_Test_Fixture::USER));
    }

    /**
     * Task 1D-I.R2: a reconstructed ring whose historical v1 secret is WRONG and
     * has no expected fingerprint, while the current v2 key is correct and pinned
     * (fingerprint as it would have been stored independently at first use).
     */
    private static function c_ring_with_corrupt_v1(int $identity, array $issued): EVC_Payment_Key_Ring {
        $v2_fingerprint = EVC_Payment_Identity_Fixture::ring('live', array(2), 2, 2, array(2))->fingerprint(2);
        return new EVC_Payment_Key_Ring('live', array(1 => EVC_Payment_Identity_Fixture::secret(9), 2 => EVC_Payment_Identity_Fixture::secret(2)), $identity, 2, $issued, array(2 => $v2_fingerprint));
    }

    /** R2-A: corrupted historical identity key must not hide a v1 refund-first quarantine. */
    public function test_contract_r2a_corrupted_historical_identity_key_fails_closed(): void {
        list($verifier, $gate) = $this->c_identity_world('R2-CAP-1', '7001');
        $capture = EVC_Payment_Identity_Fixture::paypal_capture('R2-CAP-1');
        $real_v1 = EVC_Payment_Identity_Fixture::ring('live', array(1));
        $verifier->put(EVC_Payment_Identity_Fixture::paypal_refund('R2-RF-1'), 'completed', 2000, array(), array($capture), 'refunded');
        $refund = $gate->verify_refund(EVC_Payment_Identity_Fixture::paypal_refund('R2-RF-1'))->refund();
        $rv1 = $this->c_resolver($real_v1)->resolve_refund($refund);
        $this->c_outcome($this->c_rec()->correct($this->c_resolver($real_v1)->build_correction($refund, $rv1, EVC_Pmpro_Fixture::athens('2026-10-16 10:00'))), EVC_Record_Outcome::QUARANTINED);
        $corrupt = self::c_ring_with_corrupt_v1(2, array(1, 2));
        $this->assertSame(array('unverified', 'verified'), array($corrupt->key_status(1), $corrupt->key_status(2)));
        $res = $this->c_resolver($corrupt)->resolve_payment($gate->verify_payment($capture)->payment());
        $this->assertSame(array(EVC_Payment_Identity_Resolution::UNRESOLVED, 'historical_key_unverified'), array($res->status(), $res->reason()));
        $this->assertNull($res->movement_id(), 'no clean v2 id');
        // The refund is not re-minted either.
        $this->assertSame('historical_key_unverified', $this->c_resolver($corrupt)->resolve_refund($refund)->reason());
        $this->assertCount(0, $this->contract_store()->member_movements(EVC_Provenance_Test_Fixture::USER), 'no paid period');
        $rows = array(EVC_Pmpro_Fixture::row('row1', 'active', '2026-11-15 12:00:00', 7, false, '2026-10-15 12:00:00'));
        $e = EVC_Pmpro_Fixture::mapper()->map(EVC_Provenance_Fact_Builder::snapshot($this->contract_store(), EVC_Provenance_Test_Fixture::USER, $rows), EVC_Pmpro_Fixture::athens('2026-10-20 09:00'));
        $this->assertFalse($e->is_eligible_at(EVC_Pmpro_Fixture::athens('2026-10-20 09:00')), 'no clean entitlement');
        $this->assertArrayHasKey($rv1->movement_id(), $this->contract_store()->unresolved_corrections(), 'quarantine untouched');
    }

    /** R2-B: corrupted historical ALIAS key must not let a second capture bypass the order owner. */
    public function test_contract_r2b_corrupted_historical_alias_key_fails_closed(): void {
        list($verifier, $gate) = $this->c_identity_world('R2-CAP-2', '7002');
        // Original deployment: identity v2 (always), aliases written with v1.
        $original = EVC_Payment_Identity_Fixture::ring('live', array(1, 2), 2, 1, array(2));
        $first = $gate->verify_payment(EVC_Payment_Identity_Fixture::paypal_capture('R2-CAP-2'))->payment();
        $res = $this->c_resolver($original)->resolve_payment($first);
        $this->c_outcome($this->c_rec()->record($this->c_resolver($original)->build_movement($first, $res, EVC_Provenance_Test_Fixture::USER, 7, self::c_ref('row1'), EVC_Pmpro_Fixture::athens('2026-10-15 12:00:00'))), EVC_Record_Outcome::NEWLY_RECORDED);
        // A second capture claims the same commerce order; the new ring writes aliases with v2
        // and its retained historical v1 alias key is WRONG and unpinned.
        $verifier->put(EVC_Payment_Identity_Fixture::paypal_capture('R2-CAP-3'), 'captured', 2000, array(EVC_Payment_Identity_Fixture::wc_order('7002')));
        $second = $gate->verify_payment(EVC_Payment_Identity_Fixture::paypal_capture('R2-CAP-3'))->payment();
        $corrupt = self::c_ring_with_corrupt_v1(2, array(2));
        $r2 = $this->c_resolver($corrupt)->resolve_payment($second);
        $this->assertSame(array(EVC_Payment_Identity_Resolution::UNRESOLVED, 'historical_key_unverified'), array($r2->status(), $r2->reason()));
        $this->assertCount(1, $this->contract_store()->member_movements(EVC_Provenance_Test_Fixture::USER), 'no second paid period');
        // With every historical key correct and pinned, the owner is found: conflict, never a second period.
        $valid = EVC_Payment_Identity_Fixture::ring('live', array(1, 2), 2, 2, array(2));
        $this->assertSame('alias_owned_by_other_movement', $this->c_resolver($valid)->resolve_payment($second)->reason());
    }

    /** R2-C: with all historical keys verified, R1-A behaviour, idempotency and reconciliation stay intact. */
    public function test_contract_r2c_verified_historical_keys_keep_idempotency_and_reconciliation(): void {
        list($verifier, $gate) = $this->c_identity_world('R2-CAP-4', '7004');
        $capture = EVC_Payment_Identity_Fixture::paypal_capture('R2-CAP-4');
        $v1 = EVC_Payment_Identity_Fixture::ring('live', array(1));
        $v2 = EVC_Payment_Identity_Fixture::ring('live', array(1, 2), 2, 2, array(1, 2));
        $this->assertSame(array('verified', 'verified'), array($v2->key_status(1), $v2->key_status(2)));
        $verifier->put(EVC_Payment_Identity_Fixture::paypal_refund('R2-RF-4'), 'completed', 2000, array(), array($capture), 'refunded');
        $refund = $gate->verify_refund(EVC_Payment_Identity_Fixture::paypal_refund('R2-RF-4'))->refund();
        $rv1 = $this->c_resolver($v1)->resolve_refund($refund);
        $this->c_rec()->correct($this->c_resolver($v1)->build_correction($refund, $rv1, EVC_Pmpro_Fixture::athens('2026-10-16 10:00')));
        $payment = $gate->verify_payment($capture)->payment();
        for ($i = 0; $i < 2; $i++) {
            $res = $this->c_resolver($v2)->resolve_payment($payment);
            $this->assertSame($rv1->movement_id(), $res->movement_id());
            $again = $this->c_resolver($v2)->resolve_refund($refund);
            $this->assertSame(array($rv1->correction_id(), true), array($again->correction_id(), $again->correction_existing()));
        }
        $m = $this->c_resolver($v2)->build_movement($payment, $res, EVC_Provenance_Test_Fixture::USER, 7, self::c_ref('row1'), EVC_Pmpro_Fixture::athens('2026-10-15 12:00:00'));
        $this->c_outcome($this->c_rec()->record($m), EVC_Record_Outcome::INDETERMINATE, 'unresolved_correction');
        $this->c_outcome($this->c_rec()->reconcile(new EVC_Movement_Reconciliation($m, array($rv1->correction_id()))), EVC_Record_Outcome::NEWLY_RECORDED);
        $this->c_outcome($this->c_rec()->reconcile(new EVC_Movement_Reconciliation($m, array($rv1->correction_id()))), EVC_Record_Outcome::ALREADY_RECORDED);
        $this->assertCount(1, $this->contract_store()->member_movements(EVC_Provenance_Test_Fixture::USER));
    }

    /** @return string[] */
    private function c_kinds_of(string $movement_id): array {
        return array_map(function (EVC_Movement_Correction $c) {
            return $c->kind();
        }, $this->contract_store()->corrections_for($movement_id));
    }

    public function test_contract_mapper_compatibility(): void {
        $r = $this->c_rec();
        $r->record(self::c_mv('oct', '2026-10-15 12:00:00'));
        $rows = array(EVC_Pmpro_Fixture::row('row1', 'active', '2026-11-15 12:00:00', EVC_Pmpro_Fixture::CLUB_LEVEL, false, '2026-10-15 12:00:00'));
        $map = function () use ($rows) {
            return EVC_Pmpro_Fixture::mapper()->map(EVC_Provenance_Fact_Builder::snapshot($this->contract_store(), EVC_Provenance_Test_Fixture::USER, $rows), EVC_Pmpro_Fixture::athens('2026-10-20 09:00'));
        };
        $e = $map();
        $this->assertSame('active', $e->status(), implode(',', $e->diagnostic_flags()));
        $this->assertSame(self::c_ref('mv:oct'), $e->payment_evidence()->reference());
        $r->correct(self::c_corr('r1', 'oct', 'refunded'));
        $this->assertSame('refunded', $map()->status());
        // A PMPro row claiming another membership row than the paid one: not eligible.
        $other = array(EVC_Pmpro_Fixture::row('row2', 'active', '2026-11-15 12:00:00', EVC_Pmpro_Fixture::CLUB_LEVEL, false, '2026-10-15 12:00:00'));
        $e2 = EVC_Pmpro_Fixture::mapper()->map(EVC_Provenance_Fact_Builder::snapshot($this->contract_store(), EVC_Provenance_Test_Fixture::USER, $other), EVC_Pmpro_Fixture::athens('2026-10-20 09:00'));
        $this->assertFalse($e2->is_eligible_at(EVC_Pmpro_Fixture::athens('2026-10-20 09:00')));
    }
}
