<?php

/**
 * Task 1D-B scenario Z: the existing redemption engine, unchanged, driven by
 * the PMPro adapter + mapper over a FAKE reader (synthetic data, real
 * disposable Club database). Proves the v2 entitlement is compatible with the
 * ledger snapshot, CHECK constraints, one-per-Athens-day invariant and failure
 * handling. Production still has no backend (503); nothing here wires one.
 */
final class PmproAdapterEngineTest extends EVC_Db_Test_Case {
    const NOW = '2026-10-20T06:00:00Z'; // 09:00 Athens

    protected function setUp(): void {
        parent::setUp();
        $this->clock->set(self::NOW);
    }

    private function pmpro_service($reader_result): EVC_Redemption_Service {
        $reported = &$this->reported;
        return new EVC_Redemption_Service(
            new EVC_Redemption_Store($this->db),
            new EVC_Db_Audit_Log(),
            new EVC_Pmpro_Membership_Adapter(new EVC_Fake_Pmpro_Reader($reader_result), EVC_Pmpro_Fixture::mapper()),
            self::catalog(),
            $this->clock,
            function (array $diagnostic) use (&$reported) {
                $reported[] = $diagnostic;
            }
        );
    }

    public function test_verified_period_bound_payment_redeems_and_snapshots_the_entitlement(): void {
        $member = $this->create_member(7001);
        $service = $this->pmpro_service(EVC_Pmpro_Fixture::single_paid_month());

        $result = $service->redeem($this->request($member));
        $this->assertSame(EVC_Redemption_Result::REDEEMED, $result->outcome());

        $row = $this->db->fetch_one('SELECT * FROM evc_redemptions');
        $this->assertSame('pmpro', $row['membership_source']);
        $this->assertSame('active', $row['membership_status']);
        $this->assertSame('pmpro:7', $row['membership_level_ref']);
        $this->assertSame('2026-11-15 10:00:00.000000', $row['membership_expires_at_utc'], '15 Nov 12:00 Athens');
        $this->assertSame('2026-10-20', $row['business_date']);

        // The one-per-Athens-day invariant is untouched.
        $again = $service->redeem($this->request($member));
        $this->assertSame(EVC_Redemption_Result::ALREADY_REDEEMED, $again->outcome());
        $this->assertSame(1, $this->count_rows('evc_redemptions'));
    }

    public function test_indeterminate_entitlement_is_refused_without_leaking_diagnostics(): void {
        $member = $this->create_member(7002);
        $no_evidence = EVC_Pmpro_Fixture::snapshot(array(EVC_Pmpro_Fixture::row()), array());

        $result = $this->pmpro_service($no_evidence)->redeem($this->request($member));

        $this->assertSame(EVC_Redemption_Result::MEMBERSHIP_INACTIVE, $result->outcome());
        $this->assertSame(array('status' => 'inactive', 'reason' => 'indeterminate'), $result->details());
        $this->assertSame(0, $this->count_rows('evc_redemptions'));
        $audit = $this->db->fetch_one('SELECT * FROM evc_audit_events');
        $this->assertSame(array('reason' => 'indeterminate', 'source' => 'pmpro'), json_decode($audit['details_json'], true));
        $this->assertStringNotContainsString('no_payment_evidence', (string) $audit['details_json']);
    }

    public function test_pending_manual_payment_is_refused(): void {
        $member = $this->create_member(7003);
        $pending = EVC_Pmpro_Fixture::snapshot(array(EVC_Pmpro_Fixture::row()), array(
            EVC_Pmpro_Fixture::payment('cash', 'pending', null, null, null, 'row1', array('kind' => EVC_Payment_Evidence::KIND_MANUAL_CONFIRMATION)),
        ));
        $result = $this->pmpro_service($pending)->redeem($this->request($member));
        $this->assertSame(EVC_Redemption_Result::MEMBERSHIP_INACTIVE, $result->outcome());
        $this->assertSame(array('status' => 'inactive', 'reason' => 'pending_payment'), $result->details());
        $this->assertSame(0, $this->count_rows('evc_redemptions'));
    }

    public function test_exact_expiry_instant_is_refused(): void {
        $member = $this->create_member(7004);
        $this->clock->set('2026-11-15T10:00:00Z'); // exactly 15 Nov 12:00 Athens
        $result = $this->pmpro_service(EVC_Pmpro_Fixture::single_paid_month())->redeem($this->request($member));
        $this->assertSame(EVC_Redemption_Result::MEMBERSHIP_INACTIVE, $result->outcome());
        $this->assertSame(array('status' => 'expired', 'reason' => 'expired'), $result->details());

        $this->clock->set('2026-11-15T09:59:59Z');
        $ok = $this->pmpro_service(EVC_Pmpro_Fixture::single_paid_month())->redeem($this->request($member));
        $this->assertSame(EVC_Redemption_Result::REDEEMED, $ok->outcome());
    }

    public function test_reader_failure_fails_closed_as_server_error(): void {
        $member = $this->create_member(7005);
        $result = $this->pmpro_service(new RuntimeException('connection refused'))->redeem($this->request($member));
        $this->assertSame(EVC_Redemption_Result::SERVER_ERROR, $result->outcome());
        $this->assertSame(0, $this->count_rows('evc_redemptions'));
        $this->assertSame('EVC_Membership_Source_Exception', $this->reported[0]['exception']);
    }

    public function test_committed_redemption_still_replays_after_a_later_refund(): void {
        // Idempotent replay is decided before any membership check (existing
        // engine rule); a refund recorded afterwards does not undo it (owner
        // decision D4 on served coffees remains open).
        $member = $this->create_member(7006);
        $request_id = self::uuid4();
        $this->assertSame(EVC_Redemption_Result::REDEEMED, $this->pmpro_service(EVC_Pmpro_Fixture::single_paid_month())->redeem($this->request($member, 'espresso', $request_id))->outcome());

        $refunded = EVC_Pmpro_Fixture::single_paid_month();
        $refunded->payments[0]->status = 'refunded';
        $replay = $this->pmpro_service($refunded)->redeem($this->request($member, 'espresso', $request_id));
        $this->assertSame(EVC_Redemption_Result::REPLAYED, $replay->outcome());
        $fresh = $this->pmpro_service($refunded)->redeem($this->request($member));
        $this->assertSame(EVC_Redemption_Result::MEMBERSHIP_INACTIVE, $fresh->outcome());
        $this->assertSame(1, $this->count_rows('evc_redemptions'));
    }
}
