<?php
use PHPUnit\Framework\TestCase;

/** Entitlement v2 additions, payment evidence and keyed references (Task 1D-B, Z). */
final class EntitlementV2Test extends TestCase {
    private static function evidence(?string $reference = null): EVC_Payment_Evidence {
        return new EVC_Payment_Evidence(
            EVC_Payment_Evidence::KIND_PMPRO_ORDER,
            $reference ?? str_repeat('ab', 32),
            EVC_Pmpro_Fixture::utc('2026-10-15T09:00:00Z'),
            EVC_Pmpro_Fixture::utc('2026-10-15T09:00:00Z'),
            EVC_Pmpro_Fixture::utc('2026-11-15T10:00:00Z')
        );
    }

    public function test_v1_five_argument_constructor_is_unchanged(): void {
        $e = new EVC_Entitlement('active', true, new DateTimeImmutable('2026-11-09T10:00:00Z'), 'club_monthly', 'mock');
        $this->assertNull($e->started_at_utc());
        $this->assertNull($e->payment_evidence());
        $this->assertSame(array(), $e->diagnostic_flags());
        $this->assertTrue($e->is_eligible_at(EVC_Pmpro_Fixture::utc('2026-10-09T10:00:00Z')));
        $this->assertSame('active', $e->public_status(EVC_Pmpro_Fixture::utc('2026-10-09T10:00:00Z')));
        $none = EVC_Entitlement::none('mock');
        $this->assertSame('none', $none->denial_reason(EVC_Pmpro_Fixture::utc('2026-10-09T10:00:00Z')));
    }

    public function test_indeterminate_is_never_eligible_and_maps_to_inactive(): void {
        $e = EVC_Entitlement::indeterminate('pmpro', array('source_conflict'));
        $at = EVC_Pmpro_Fixture::utc('2026-10-20T10:00:00Z');
        $this->assertFalse($e->is_eligible_at($at));
        $this->assertSame('indeterminate', $e->denial_reason($at));
        $this->assertSame('inactive', $e->public_status($at));
        $this->assertFalse($e->payment_verified());
        $this->assertNull($e->expires_at_utc());
        $this->assertSame(array('source_conflict'), $e->diagnostic_flags());
        $this->assertContains('indeterminate', EVC_Entitlement::statuses());
    }

    public function test_v2_fields_are_stored_in_utc(): void {
        $start = new DateTimeImmutable('2026-10-15 12:00:00', new DateTimeZone('Europe/Athens'));
        $e = new EVC_Entitlement('active', true, EVC_Pmpro_Fixture::utc('2026-11-15T10:00:00Z'), 'pmpro:7', 'pmpro', $start, self::evidence(), array('renewal_pending', 'renewal_pending'));
        $this->assertSame('2026-10-15T09:00:00Z', $e->started_at_utc()->format('Y-m-d\TH:i:s\Z'));
        $this->assertSame('UTC', $e->started_at_utc()->getTimezone()->getName());
        $this->assertSame(str_repeat('ab', 32), $e->payment_evidence()->reference());
        $this->assertSame(array('renewal_pending'), $e->diagnostic_flags(), 'flags de-duplicated');
    }

    public function test_evidence_cannot_accompany_an_unverified_payment(): void {
        $this->expectException(InvalidArgumentException::class);
        new EVC_Entitlement('active', false, EVC_Pmpro_Fixture::utc('2026-11-15T10:00:00Z'), null, 'pmpro', null, self::evidence());
    }

    public function test_start_must_precede_expiry(): void {
        $this->expectException(InvalidArgumentException::class);
        $t = EVC_Pmpro_Fixture::utc('2026-11-15T10:00:00Z');
        new EVC_Entitlement('active', true, $t, null, 'pmpro', $t);
    }

    public function test_diagnostic_flags_are_restricted_codes(): void {
        foreach (array('Has Space', 'x@example.com', '+306900000001', '', str_repeat('a', 49)) as $bad) {
            try {
                new EVC_Entitlement('none', false, null, null, 'pmpro', null, null, array($bad));
                $this->fail('accepted flag ' . $bad);
            } catch (InvalidArgumentException $e) {
                $this->assertSame('Invalid diagnostic flag.', $e->getMessage());
            }
        }
    }

    public function test_evidence_reference_must_be_opaque(): void {
        // Raw order numbers, transaction ids, e-mails or phones can never be evidence references.
        foreach (array('12345', 'ch_3Nabc', 'pay@example.com', '+306900000001', strtoupper(str_repeat('ab', 32)), str_repeat('a', 63)) as $bad) {
            try {
                self::evidence($bad);
                $this->fail('accepted reference ' . $bad);
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('opaque', $e->getMessage());
            }
        }
    }

    public function test_evidence_validates_kind_and_period(): void {
        try {
            new EVC_Payment_Evidence('newsletter', str_repeat('ab', 32), new DateTimeImmutable(), new DateTimeImmutable('2026-01-01Z'), new DateTimeImmutable('2026-02-01Z'));
            $this->fail('unknown kind accepted');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('Unknown payment evidence kind.', $e->getMessage());
        }
        $this->expectException(InvalidArgumentException::class);
        new EVC_Payment_Evidence('pmpro_order', str_repeat('ab', 32), new DateTimeImmutable(), new DateTimeImmutable('2026-02-01Z'), new DateTimeImmutable('2026-02-01Z'));
    }

    public function test_hmac_reference_is_keyed_and_namespaced(): void {
        $secret = str_repeat('k', 32);
        $a = EVC_Evidence_Ref::hmac($secret, 'pmpro_order', '1001');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $a);
        $this->assertSame($a, EVC_Evidence_Ref::hmac($secret, 'pmpro_order', '1001'), 'deterministic');
        $this->assertNotSame($a, EVC_Evidence_Ref::hmac(str_repeat('j', 32), 'pmpro_order', '1001'), 'depends on the secret');
        $this->assertNotSame($a, EVC_Evidence_Ref::hmac($secret, 'wc_order', '1001'), 'namespaced');
        $this->assertNotSame(hash('sha256', 'pmpro_order' . "\0" . '1001'), $a, 'not an unkeyed hash');
    }

    public function test_hmac_requires_a_real_secret_and_identifier(): void {
        foreach (array(array('short', 'pmpro_order', '1'), array(str_repeat('k', 32), 'Bad NS', '1'), array(str_repeat('k', 32), 'pmpro_order', '')) as $args) {
            try {
                EVC_Evidence_Ref::hmac(...$args);
                $this->fail('accepted ' . json_encode($args));
            } catch (InvalidArgumentException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
    }
}
