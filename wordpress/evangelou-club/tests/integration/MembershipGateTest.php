<?php

final class MembershipGateTest extends EVC_Db_Test_Case {
    /** @return array<string,array{string,bool,?string,string,string}> status, paid, expiry, reason, public status */
    public static function ineligible(): array {
        return array(
            'pending payment (bank transfer / cash not confirmed)' => array('pending_payment', false, '2026-11-09T10:00:00Z', 'pending_payment', 'inactive'),
            'active but payment unverified' => array('active', false, '2026-11-09T10:00:00Z', 'payment_unverified', 'inactive'),
            'expired' => array('expired', true, '2026-10-01T00:00:00Z', 'expired', 'expired'),
            'cancelled' => array('cancelled', true, '2026-11-09T10:00:00Z', 'cancelled', 'cancelled'),
            'payment failed' => array('payment_failed', false, '2026-11-09T10:00:00Z', 'payment_failed', 'inactive'),
            'refunded' => array('refunded', false, '2026-11-09T10:00:00Z', 'refunded', 'inactive'),
            'active with unknown expiry fails closed' => array('active', true, null, 'expiry_unknown', 'inactive'),
            'active but end date passed' => array('active', true, '2026-10-09T09:59:59Z', 'expired', 'expired'),
            'expiring exactly at the evaluation instant' => array('active', true, self::DEFAULT_NOW, 'expired', 'expired'),
        );
    }

    /** @dataProvider ineligible */
    public function test_ineligible_memberships_never_redeem(string $status, bool $paid, ?string $expiry, string $reason, string $public): void {
        $this->adapter->set(4001, EVC_Mock_Membership_Adapter::with($status, $paid, $expiry));
        $member = $this->create_member(4001);
        $request_id = self::uuid4();

        $result = $this->service()->redeem($this->request($member, 'espresso', $request_id));

        $this->assertSame(EVC_Redemption_Result::MEMBERSHIP_INACTIVE, $result->outcome());
        $this->assertSame(array('status' => $public, 'reason' => $reason), $result->details());
        $this->assertSame(0, $this->count_rows('evc_redemptions'));
        $audit = $this->db->fetch_one('SELECT * FROM evc_audit_events');
        $this->assertSame('membership_inactive', $audit['outcome']);
        $this->assertNull($audit['redemption_id']);
        $this->assertSame($request_id, $audit['request_id']);
        $this->assertSame(array('reason' => $reason, 'source' => 'mock'), json_decode($audit['details_json'], true));
    }

    public function test_member_without_any_membership_cannot_redeem(): void {
        $member = $this->create_member(4002); // no adapter entry => "none"
        $result = $this->service()->redeem($this->request($member));
        $this->assertSame(EVC_Redemption_Result::MEMBERSHIP_INACTIVE, $result->outcome());
        $this->assertSame('none', $result->details()['reason']);
        $this->assertSame(0, $this->count_rows('evc_redemptions'));
    }

    public function test_one_microsecond_before_expiry_is_still_eligible(): void {
        $member = $this->create_active_member(4003, '2026-10-09T10:00:00.000001Z');
        $this->assertSame(EVC_Redemption_Result::REDEEMED, $this->service()->redeem($this->request($member))->outcome());
    }

    public function test_membership_is_rechecked_at_redemption_time(): void {
        $member = $this->create_active_member(4004, '2026-10-09T12:00:00Z');
        $service = $this->service();
        // An earlier lookup at 11:59 would have shown "active"; the redemption
        // happens after expiry and must be refused on the server.
        $this->clock->set('2026-10-09T12:00:30Z');
        $this->assertSame(EVC_Redemption_Result::MEMBERSHIP_INACTIVE, $service->redeem($this->request($member))->outcome());
        // Renewal (manual) is reflected at the next attempt.
        $this->adapter->set(4004, EVC_Mock_Membership_Adapter::active_until('2026-11-09T12:00:00Z'));
        $this->assertSame(EVC_Redemption_Result::REDEEMED, $service->redeem($this->request($member))->outcome());
    }

    public function test_adapter_receives_the_same_instant_used_for_the_business_date(): void {
        $member = $this->create_active_member(4005);
        // A clock that jumps a whole day on every read: if the service read it
        // twice, the business date would not match the adapter's instant.
        $this->clock = new EVC_Fixed_Clock('2026-10-09T20:59:59Z', '+1 day');

        $result = $this->service()->redeem($this->request($member));

        $this->assertSame(1, $this->clock->reads, 'clock read exactly once');
        $this->assertSame(array(array('wp_user_id' => 4005, 'at' => '2026-10-09 20:59:59.000000')), $this->adapter->calls);
        $this->assertSame('2026-10-09', $result->redemption()['business_date']);
        $this->assertSame('2026-10-09 20:59:59.000000', $result->redemption()['redeemed_at_utc']);
    }

    public function test_adapter_failure_fails_closed(): void {
        $this->adapter->set(4006, new RuntimeException('PMPro lookup failed for user 4006 <secret>'));
        $member = $this->create_member(4006);

        $result = $this->service()->redeem($this->request($member));

        $this->assertSame(EVC_Redemption_Result::SERVER_ERROR, $result->outcome());
        $this->assertSame(array(), $result->details());
        $this->assertSame(0, $this->count_rows('evc_redemptions'));
        $this->assertSame(array(array('stage' => 'redeem', 'exception' => 'RuntimeException', 'sql_state' => null, 'driver_code' => null)), $this->reported);
        $this->assertStringNotContainsString('secret', json_encode($this->reported));
    }
}
