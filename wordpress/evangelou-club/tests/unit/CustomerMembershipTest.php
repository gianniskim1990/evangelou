<?php

use PHPUnit\Framework\TestCase;

/**
 * STAGE-01: EVC_Customer_Membership turns an authoritative entitlement into
 * what the customer may see. The entitlements come from the REAL
 * EVC_Pmpro_Membership_Adapter + EVC_Pmpro_Entitlement_Mapper over the
 * existing test-only PMPro fixtures, so the customer view inherits every
 * fail-closed rule of the mapper (verified payment, recorded provenance,
 * exact Athens expiry). Only a verified current paid period is "active".
 */
final class CustomerMembershipTest extends TestCase {
    private static function when_athens(string $athens_local): DateTimeImmutable {
        return EVC_Pmpro_Fixture::athens($athens_local);
    }

    /** @param EVC_Pmpro_Snapshot|Throwable $result */
    private static function adapter($result): EVC_Pmpro_Membership_Adapter {
        return new EVC_Pmpro_Membership_Adapter(new EVC_Fake_Pmpro_Reader($result), EVC_Pmpro_Fixture::mapper());
    }

    private static function view($snapshot, string $athens_local): array {
        $at = self::when_athens($athens_local);
        return EVC_Customer_Membership::describe(self::adapter($snapshot)->entitlement_for(4242, $at), $at);
    }

    public function test_a_verified_current_paid_period_is_active_with_its_exact_end(): void {
        $this->assertSame(
            array('state' => 'active', 'paid_until_utc' => '2026-11-15T10:00:00+00:00'),
            self::view(EVC_Pmpro_Fixture::single_paid_month(), '2026-10-20 09:00')
        );
    }

    public function test_the_end_instant_is_exclusive(): void {
        $this->assertSame('active', self::view(EVC_Pmpro_Fixture::single_paid_month(), '2026-11-15 11:59')['state']);
        $this->assertSame(
            array('state' => 'expired', 'paid_until_utc' => '2026-11-15T10:00:00+00:00'),
            self::view(EVC_Pmpro_Fixture::single_paid_month(), '2026-11-15 12:00')
        );
    }

    public function test_an_active_pmpro_row_without_verified_payment_is_never_active(): void {
        $s = EVC_Pmpro_Fixture::snapshot(array(EVC_Pmpro_Fixture::row()), array());
        $this->assertSame(array('state' => 'unverified', 'paid_until_utc' => null), self::view($s, '2026-10-20 09:00'));
    }

    public function test_pending_cash_or_bank_payment_is_pending_payment(): void {
        $pending = EVC_Pmpro_Fixture::payment('pend', 'pending', null, null, null);
        $s = EVC_Pmpro_Fixture::snapshot(array(EVC_Pmpro_Fixture::row()), array($pending));
        $this->assertSame(array('state' => 'pending_payment', 'paid_until_utc' => null), self::view($s, '2026-10-20 09:00'));
    }

    public function test_cancelled_and_expired_rows(): void {
        $cancelled = EVC_Pmpro_Fixture::snapshot(array(EVC_Pmpro_Fixture::row('row1', 'cancelled')), array());
        $this->assertSame(array('state' => 'cancelled', 'paid_until_utc' => null), self::view($cancelled, '2026-10-20 09:00'));
        $expired = EVC_Pmpro_Fixture::snapshot(array(EVC_Pmpro_Fixture::row('row1', 'expired')), array());
        $this->assertSame('expired', self::view($expired, '2026-10-20 09:00')['state']);
    }

    public function test_no_club_membership_is_none(): void {
        $this->assertSame(array('state' => 'none', 'paid_until_utc' => null), self::view(EVC_Pmpro_Fixture::snapshot(array(), array()), '2026-10-20 09:00'));
    }

    public function test_untrusted_facts_are_unverified_never_paid(): void {
        $cases = array(
            'unsupported PMPro version' => array('pmpro_version' => '9.0'),
            'site timezone is a fixed offset, not Europe/Athens' => array('site_timezone' => '+03:00'),
            'source reports itself unavailable' => array('source_available' => false),
            'cross-system conflict' => array('source_conflicts' => array('woocommerce_pmpro_mismatch')),
        );
        foreach ($cases as $label => $override) {
            $s = EVC_Pmpro_Fixture::single_paid_month();
            foreach ($override as $property => $value) {
                $s->$property = $value;
            }
            $this->assertSame(array('state' => 'unverified', 'paid_until_utc' => null), self::view($s, '2026-10-20 09:00'), $label);
        }
    }

    public function test_refunded_payment_is_unverified(): void {
        $s = EVC_Pmpro_Fixture::snapshot(
            array(EVC_Pmpro_Fixture::row()),
            array(EVC_Pmpro_Fixture::paid('oct', '2026-10-15 12:00', '2026-10-15 12:00', '2026-11-15 12:00', 'row1', array('status' => 'refunded')))
        );
        $this->assertSame(array('state' => 'unverified', 'paid_until_utc' => null), self::view($s, '2026-10-20 09:00'));
    }

    public function test_every_entitlement_status_maps_to_a_known_state_and_only_active_carries_a_current_end(): void {
        $at = new DateTimeImmutable('2026-10-20T07:00:00Z');
        $future = new DateTimeImmutable('2026-11-15T10:00:00Z');
        foreach (EVC_Entitlement::statuses() as $status) {
            foreach (array(true, false) as $verified) {
                if ($status === EVC_Entitlement::STATUS_INDETERMINATE && $verified) {
                    continue;
                }
                $e = new EVC_Entitlement($status, $verified, $future, null, 'pmpro');
                $v = EVC_Customer_Membership::describe($e, $at);
                $this->assertContains($v['state'], EVC_Customer_Membership::states(), $status);
                $this->assertSame($status === 'active' && $verified, $v['state'] === 'active', "$status verified=" . var_export($verified, true));
                if ($v['state'] !== 'active' && $v['state'] !== 'expired') {
                    $this->assertNull($v['paid_until_utc'], "$status: no end date unless active or expired, even if the entitlement carries one");
                }
            }
        }
        $no_expiry = new EVC_Entitlement('active', true, null, null, 'pmpro');
        $this->assertSame(array('state' => 'unverified', 'paid_until_utc' => null), EVC_Customer_Membership::describe($no_expiry, $at), 'unknown expiry is never active');
    }

    public function test_a_reader_failure_is_a_source_exception_not_a_state(): void {
        $this->expectException(EVC_Membership_Source_Exception::class);
        self::adapter(new RuntimeException('db down'))->entitlement_for(4242, self::when_athens('2026-10-20 09:00'));
    }
}
