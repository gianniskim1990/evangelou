<?php
use PHPUnit\Framework\TestCase;

final class EntitlementTest extends TestCase {
    const NOW = '2026-10-09T10:00:00Z';
    const FUTURE = '2026-11-09T10:00:00Z';
    const PAST = '2026-10-01T00:00:00Z';

    private static function at(string $instant): DateTimeImmutable {
        return EVC_Clock::to_utc(new DateTimeImmutable($instant));
    }

    /** @return array<string,array{string,bool,?string,?string,string}> status, paid, expiry, expected denial, public status */
    public static function scenarios(): array {
        return array(
            'active, paid, unexpired' => array('active', true, self::FUTURE, null, 'active'),
            'active but payment not verified' => array('active', false, self::FUTURE, 'payment_unverified', 'inactive'),
            'pending payment (bank/cash not confirmed)' => array('pending_payment', false, self::FUTURE, 'pending_payment', 'inactive'),
            'pending payment even if flagged paid' => array('pending_payment', true, self::FUTURE, 'pending_payment', 'inactive'),
            'expired status' => array('expired', true, self::PAST, 'expired', 'expired'),
            'cancelled' => array('cancelled', true, self::FUTURE, 'cancelled', 'cancelled'),
            'payment failed' => array('payment_failed', false, self::FUTURE, 'payment_failed', 'inactive'),
            'refunded' => array('refunded', false, self::FUTURE, 'refunded', 'inactive'),
            'no membership' => array('none', false, null, 'none', 'inactive'),
            'active but expiry unknown fails closed' => array('active', true, null, 'expiry_unknown', 'inactive'),
            'active status but end date already passed' => array('active', true, self::PAST, 'expired', 'expired'),
        );
    }

    /** @dataProvider scenarios */
    public function test_eligibility_matrix(string $status, bool $paid, ?string $expiry, ?string $denial, string $public): void {
        $e = new EVC_Entitlement($status, $paid, $expiry === null ? null : new DateTimeImmutable($expiry), 'club_monthly', 'mock');
        $now = self::at(self::NOW);
        $this->assertSame($denial, $e->denial_reason($now));
        $this->assertSame($denial === null, $e->is_eligible_at($now));
        $this->assertSame($public, $e->public_status($now));
    }

    public function test_expiry_instant_is_exclusive(): void {
        $e = new EVC_Entitlement('active', true, new DateTimeImmutable('2026-10-15T21:00:00Z'), null, 'mock');
        $this->assertTrue($e->is_eligible_at(self::at('2026-10-15T20:59:59.999999Z')));
        $this->assertFalse($e->is_eligible_at(self::at('2026-10-15T21:00:00Z')), 'expiring exactly now is NOT eligible');
        $this->assertSame('expired', $e->denial_reason(self::at('2026-10-15T21:00:00Z')));
        $this->assertFalse($e->is_eligible_at(self::at('2026-10-15T21:00:01Z')));
    }

    public function test_expiry_compares_instants_across_timezones(): void {
        // 23:59:59 Athens (+03:00) on 15 Oct == 20:59:59Z.
        $e = new EVC_Entitlement('active', true, new DateTimeImmutable('2026-10-15 23:59:59', new DateTimeZone('Europe/Athens')), null, 'mock');
        $this->assertSame('2026-10-15 20:59:59.000000', $e->expires_at_utc()->format('Y-m-d H:i:s.u'));
        $this->assertTrue($e->is_eligible_at(self::at('2026-10-15T20:59:58Z')));
        $this->assertFalse($e->is_eligible_at(self::at('2026-10-15T20:59:59Z')));
    }

    public function test_unknown_status_is_rejected(): void {
        $this->expectException(InvalidArgumentException::class);
        new EVC_Entitlement('newsletter_tag', true, new DateTimeImmutable(self::FUTURE), null, 'mock');
    }

    public function test_invalid_source_is_rejected(): void {
        $this->expectException(InvalidArgumentException::class);
        new EVC_Entitlement('active', true, new DateTimeImmutable(self::FUTURE), null, 'FluentCRM tag');
    }

    public function test_invalid_level_ref_is_rejected(): void {
        $this->expectException(InvalidArgumentException::class);
        new EVC_Entitlement('active', true, new DateTimeImmutable(self::FUTURE), "level\n1", 'mock');
    }

    public function test_none_factory_is_never_eligible(): void {
        $e = EVC_Entitlement::none('mock');
        $this->assertFalse($e->is_eligible_at(self::at(self::NOW)));
        $this->assertNull($e->expires_at_utc());
    }

    public function test_mock_adapter_fails_closed_for_unknown_users(): void {
        $adapter = new EVC_Mock_Membership_Adapter();
        $this->assertSame('none', $adapter->entitlement_for(42, self::at(self::NOW))->status());
        $this->assertSame(array(array('wp_user_id' => 42, 'at' => '2026-10-09 10:00:00.000000')), $adapter->calls);
    }
}
