<?php
use PHPUnit\Framework\TestCase;

/**
 * Task 1D-E scenario W (TEST-ONLY): recorder output -> EVC_Pmpro_Payment_Fact
 * -> unchanged EVC_Pmpro_Entitlement_Mapper. Synthetic PMPro rows are written
 * by hand; nothing here is wired to production or the redemption backend.
 */
final class ProvenanceMapperIntegrationTest extends TestCase {
    const USER = 42;

    private static function ref(string $label): string {
        return EVC_Pmpro_Fixture::ref($label);
    }

    private static function movement(string $label, string $at_athens, string $row = 'row1', int $level = EVC_Pmpro_Fixture::CLUB_LEVEL): EVC_Verified_Movement {
        return new EVC_Verified_Movement(
            self::ref('mv:' . $label),
            'live',
            self::USER,
            $level,
            self::ref($row),
            EVC_Pmpro_Fixture::athens($at_athens),
            2000,
            'EUR',
            array(new EVC_Movement_Alias(EVC_Movement_Alias::NS_WC_ORDER, self::ref('order:' . $label))),
            EVC_Verified_Movement::AUTHORITY_GATEWAY_API,
            EVC_Payment_Evidence::KIND_WC_ORDER,
            EVC_Verified_Movement::STATE_CONFIRMED
        );
    }

    private static function recorder(EVC_In_Memory_Provenance_Store $store, array $levels = array(EVC_Pmpro_Fixture::CLUB_LEVEL)): EVC_Provenance_Recorder {
        return new EVC_Provenance_Recorder($store, new EVC_Pmpro_Mapper_Config($levels, '3.0', '4.0'), 'live', 2000, 'EUR');
    }

    private static function map(EVC_In_Memory_Provenance_Store $store, array $rows, string $at_athens, ?EVC_Pmpro_Entitlement_Mapper $mapper = null): EVC_Entitlement {
        $snapshot = EVC_Provenance_Fact_Builder::snapshot($store, self::USER, $rows);
        return ($mapper ?? EVC_Pmpro_Fixture::mapper())->map($snapshot, EVC_Pmpro_Fixture::athens($at_athens));
    }

    private static function row(string $label, string $status, string $start, string $end, int $level = EVC_Pmpro_Fixture::CLUB_LEVEL): EVC_Pmpro_Membership_Row {
        return EVC_Pmpro_Fixture::row($label, $status, $end, $level, false, $start);
    }

    private static function one_month(): EVC_In_Memory_Provenance_Store {
        $store = new EVC_In_Memory_Provenance_Store();
        self::recorder($store)->record(self::movement('oct', '2026-10-15 12:00:00'));
        return $store;
    }

    public function test_recorded_payment_is_accepted_by_the_unchanged_mapper(): void {
        $store = self::one_month();
        $e = self::map($store, array(self::row('row1', 'active', '2026-10-15 12:00:00', '2026-11-15 12:00:00')), '2026-10-20 09:00');
        $this->assertSame('active', $e->status(), implode(',', $e->diagnostic_flags()));
        $this->assertSame(self::ref('mv:oct'), $e->payment_evidence()->reference());
        $this->assertSame('2026-11-15T10:00:00Z', $e->expires_at_utc()->format('Y-m-d\TH:i:s\Z'));
        // Exact, exclusive end.
        $this->assertSame('expired', self::map($store, array(self::row('row1', 'active', '2026-10-15 12:00:00', '2026-11-15 12:00:00')), '2026-11-15 12:00')->status());
    }

    public function test_early_renewal_chain_is_accepted(): void {
        $store = new EVC_In_Memory_Provenance_Store();
        $r = self::recorder($store);
        $r->record(self::movement('oct', '2026-10-15 12:00:00', 'row1'));
        $r->record(self::movement('nov', '2026-11-01 09:00:00', 'row2'));
        $rows = array(
            self::row('row1', 'changed', '2026-10-15 12:00:00', '2026-11-15 12:00:00'),
            self::row('row2', 'active', '2026-10-15 12:00:00', '2026-12-15 12:00:00'),
        );
        $e = self::map($store, $rows, '2026-11-20 09:00');
        $this->assertSame('active', $e->status(), implode(',', $e->diagnostic_flags()));
        $this->assertSame(self::ref('mv:nov'), $e->payment_evidence()->reference());
    }

    public function test_pmpro_claiming_an_unpaid_later_period_is_rejected(): void {
        // Only October was paid; PMPro says active until 15 December.
        $e = self::map(self::one_month(), array(self::row('row1', 'active', '2026-10-15 12:00:00', '2026-12-15 12:00:00')), '2026-10-20 09:00');
        $this->assertSame('indeterminate', $e->status());
        $this->assertContains('pmpro_period_mismatch', $e->diagnostic_flags());
    }

    public function test_level_mismatch_is_rejected(): void {
        $store = new EVC_In_Memory_Provenance_Store();
        self::recorder($store, array(7, 8))->record(self::movement('oct', '2026-10-15 12:00:00', 'row8', 7));
        $rows = array(self::row('row8', 'active', '2026-10-15 12:00:00', '2026-11-15 12:00:00', 8));
        $e = self::map($store, $rows, '2026-10-20 09:00', EVC_Pmpro_Fixture::two_level_mapper());
        $this->assertSame('indeterminate', $e->status());
        $this->assertContains('payment_level_mismatch', $e->diagnostic_flags());
    }

    public function test_corrections_are_never_presented_as_valid_payment(): void {
        $rows = array(self::row('row1', 'active', '2026-10-15 12:00:00', '2026-11-15 12:00:00'));
        foreach (array(
            'refunded' => array('refunded', 'payment_refunded'),
            'partially_refunded' => array('indeterminate', 'partial_refund_policy_unresolved'),
            'reversed' => array('indeterminate', 'payment_reversed'),
            'voided' => array('indeterminate', 'payment_reversed'),
        ) as $kind => list($status, $flag)) {
            $store = self::one_month();
            self::recorder($store)->correct(new EVC_Movement_Correction(self::ref('c:' . $kind), self::ref('mv:oct'), $kind, EVC_Verified_Movement::AUTHORITY_MANUAL_ADMIN, EVC_Pmpro_Fixture::athens('2026-10-18 10:00')));
            $e = self::map($store, $rows, '2026-10-20 09:00');
            $this->assertSame($status, $e->status(), $kind);
            $this->assertContains($flag, $e->diagnostic_flags(), $kind);
            $this->assertFalse($e->is_eligible_at(EVC_Pmpro_Fixture::athens('2026-10-20 09:00')));
        }
    }

    public function test_invalid_pmpro_dates_are_still_rejected(): void {
        $store = self::one_month();
        $e = self::map($store, array(self::row('row1', 'active', '2026-11-15 12:00:00', '2026-11-15 12:00:00')), '2026-10-20 09:00');
        $this->assertSame('indeterminate', $e->status());
        $this->assertContains('membership_start_after_end', $e->diagnostic_flags());
    }

    public function test_V_a_rejected_conflicting_signal_cannot_extend_entitlement(): void {
        $store = self::one_month();
        // Same WooCommerce order under a second canonical id: recorder refuses it.
        $dup = new EVC_Verified_Movement(self::ref('mv:dup'), 'live', self::USER, 7, self::ref('row1'), EVC_Pmpro_Fixture::athens('2026-10-15 12:00:05'), 2000, 'EUR',
            array(new EVC_Movement_Alias(EVC_Movement_Alias::NS_WC_ORDER, self::ref('order:oct'))), 'gateway_api', 'wc_order', 'confirmed');
        $this->assertSame(EVC_Record_Outcome::CONFLICT, self::recorder($store)->record($dup)->outcome());
        // PMPro (e.g. a non-deduplicating add-on) stacked a second month: no provenance for it.
        $e = self::map($store, array(self::row('row1', 'active', '2026-10-15 12:00:00', '2026-12-15 12:00:00')), '2026-11-20 09:00');
        $this->assertFalse($e->is_eligible_at(EVC_Pmpro_Fixture::athens('2026-11-20 09:00')));
        $this->assertSame('expired', self::map($store, array(self::row('row1', 'active', '2026-10-15 12:00:00', '2026-11-15 12:00:00')), '2026-11-20 09:00')->status());
    }

    public function test_no_recorded_payment_means_no_paid_evidence(): void {
        $e = self::map(new EVC_In_Memory_Provenance_Store(), array(self::row('row1', 'active', '2026-10-15 12:00:00', '2026-11-15 12:00:00')), '2026-10-20 09:00');
        $this->assertSame('indeterminate', $e->status());
        $this->assertContains('no_payment_evidence', $e->diagnostic_flags());
    }
}
