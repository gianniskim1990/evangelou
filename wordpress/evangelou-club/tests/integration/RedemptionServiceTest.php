<?php

final class RedemptionServiceTest extends EVC_Db_Test_Case {
    public function test_first_valid_redemption_succeeds_with_coffee_snapshot_and_audit(): void {
        $member = $this->create_active_member(2001, '2026-11-09T10:00:00Z');
        $request_id = self::uuid4();

        $result = $this->service()->redeem($this->request($member, 'freddo_espresso', $request_id));

        $this->assertSame(EVC_Redemption_Result::REDEEMED, $result->outcome());
        $this->assertTrue($result->is_success());
        $this->assertSame(array(
            'member_public_id' => $member,
            'benefit_type' => 'free_coffee',
            'coffee_code' => 'freddo_espresso',
            'business_date' => '2026-10-09',
            'redeemed_at_utc' => '2026-10-09 10:00:00.000000',
            'request_id' => $request_id,
        ), $result->redemption());

        $row = $this->db->fetch_one('SELECT * FROM evc_redemptions');
        $this->assertSame('freddo_espresso', $row['coffee_code']);
        $this->assertSame(self::STAFF_ID, (int) $row['staff_wp_user_id']);
        $this->assertSame('2026-10-09', $row['business_date']);
        $this->assertSame('mock', $row['membership_source']);
        $this->assertSame('active', $row['membership_status']);
        $this->assertSame('club_monthly', $row['membership_level_ref']);
        $this->assertSame('2026-11-09 10:00:00.000000', EVC_Redemption_Store::normalise_datetime($row['membership_expires_at_utc']));
        $this->assertSame(
            EVC_Request_Fingerprint::compute($member, 'free_coffee', 'freddo_espresso', self::STAFF_ID),
            $row['request_fingerprint']
        );

        $audit = $this->db->fetch_one('SELECT * FROM evc_audit_events');
        $this->assertSame('redemption', $audit['event_type']);
        $this->assertSame('redeemed', $audit['outcome']);
        $this->assertSame((int) $row['id'], (int) $audit['redemption_id']);
        $this->assertSame($request_id, $audit['request_id']);
        $this->assertSame(
            array('benefit_type' => 'free_coffee', 'coffee_code' => 'freddo_espresso', 'business_date' => '2026-10-09'),
            json_decode($audit['details_json'], true)
        );
        $this->assertSame(1, $this->count_rows('evc_audit_events'));
        $this->assertSame(array(), $this->reported);
    }

    public function test_second_request_same_business_day_is_already_redeemed(): void {
        $member = $this->create_active_member(2002);
        $service = $this->service();
        $first = $service->redeem($this->request($member, 'espresso'));
        $this->clock->set('2026-10-09T15:30:00Z');

        $second = $service->redeem($this->request($member, 'cappuccino'));

        $this->assertSame(EVC_Redemption_Result::ALREADY_REDEEMED, $second->outcome());
        $this->assertFalse($second->is_success());
        $this->assertNull($second->redemption());
        $this->assertSame(array(
            'business_date' => '2026-10-09',
            'redeemed_at_utc' => $first->redemption()['redeemed_at_utc'],
        ), $second->details());
        $this->assertSame(1, $this->count_rows('evc_redemptions'));
        $this->assertSame('espresso', $this->db->fetch_one('SELECT coffee_code FROM evc_redemptions')['coffee_code']);
        $this->assertSame(1, $this->count_rows('evc_audit_events', 'outcome = ?', array('benefit_already_redeemed')));
    }

    public function test_next_athens_day_allows_a_new_redemption(): void {
        $member = $this->create_active_member(2003);
        $service = $this->service();
        $this->clock->set('2026-10-09T20:59:59Z'); // 23:59:59 Athens (+03:00)
        $this->assertSame('redeemed', $service->redeem($this->request($member))->outcome());
        $this->clock->set('2026-10-09T21:00:00Z'); // 00:00 Athens, 10 Oct
        $next = $service->redeem($this->request($member));
        $this->assertSame('redeemed', $next->outcome());
        $this->assertSame('2026-10-10', $next->redemption()['business_date']);
        $this->assertSame(2, $this->count_rows('evc_redemptions'));
    }

    public function test_business_day_is_athens_not_utc_in_winter(): void {
        $member = $this->create_active_member(2004, '2027-01-31T00:00:00Z');
        $service = $this->service();
        // Same UTC date (1 Jan), different Athens dates (1 Jan / 2 Jan).
        $this->clock->set('2026-01-01T21:30:00Z');
        $this->assertSame('2026-01-01', $service->redeem($this->request($member))->redemption()['business_date']);
        $this->clock->set('2026-01-01T22:30:00Z');
        $second = $service->redeem($this->request($member));
        $this->assertSame('redeemed', $second->outcome());
        $this->assertSame('2026-01-02', $second->redemption()['business_date']);
        // Different UTC date (2 Jan) but the same Athens date (2 Jan).
        $this->clock->set('2026-01-02T09:00:00Z');
        $this->assertSame('benefit_already_redeemed', $service->redeem($this->request($member))->outcome());
        $this->assertSame(2, $this->count_rows('evc_redemptions'));
    }

    public function test_dst_transition_days_count_as_single_business_days(): void {
        $member = $this->create_active_member(2005, '2027-01-31T00:00:00Z');
        $service = $this->service();
        // 29 Mar 2026: 00:30 (+02:00) and 23:30 (+03:00) are the same Athens day.
        $this->clock->set('2026-03-28T22:30:00Z');
        $this->assertSame('2026-03-29', $service->redeem($this->request($member))->redemption()['business_date']);
        $this->clock->set('2026-03-29T20:30:00Z');
        $this->assertSame('benefit_already_redeemed', $service->redeem($this->request($member))->outcome());
        // 25 Oct 2026: 00:30 (+03:00) and 23:30 (+02:00) are the same Athens day.
        $this->clock->set('2026-10-24T21:30:00Z');
        $this->assertSame('2026-10-25', $service->redeem($this->request($member))->redemption()['business_date']);
        $this->clock->set('2026-10-25T21:30:00Z');
        $this->assertSame('benefit_already_redeemed', $service->redeem($this->request($member))->outcome());
        $this->clock->set('2026-10-25T22:00:00Z');
        $this->assertSame('2026-10-26', $service->redeem($this->request($member))->redemption()['business_date']);
        $this->assertSame(3, $this->count_rows('evc_redemptions'));
    }

    public function test_result_is_independent_of_php_default_timezone(): void {
        $original = date_default_timezone_get();
        try {
            foreach (array('UTC', 'America/New_York', 'Etc/GMT-3', 'Asia/Tokyo') as $i => $tz) {
                date_default_timezone_set($tz);
                $member = $this->create_active_member(2100 + $i);
                $this->clock->set('2026-10-09T21:30:00Z'); // 00:30 Athens on 10 Oct
                $this->assertSame('2026-10-10', $this->service()->redeem($this->request($member))->redemption()['business_date'], $tz);
            }
        } finally {
            date_default_timezone_set($original);
        }
    }

    public function test_invalid_coffee_is_rejected_before_any_lookup(): void {
        $member = $this->create_active_member(2006);
        foreach (array('mocha_latte', 'ESPRESSO', "espresso'; DROP TABLE evc_redemptions; --", '') as $coffee) {
            $result = $this->service()->redeem(new EVC_Redemption_Request($member, 'free_coffee', $coffee, self::uuid4(), self::STAFF_ID));
            $this->assertSame(EVC_Redemption_Result::INVALID_COFFEE, $result->outcome(), var_export($coffee, true));
        }
        $this->assertSame(0, $this->count_rows('evc_redemptions'));
        $this->assertSame(array(), $this->adapter->calls, 'membership is not consulted for invalid input');
    }

    /** @return array<string,array> */
    public static function invalid_requests(): array {
        $member = 'mem_0123456789abcdef0123456789abcdef';
        $uuid = '3fa85f64-5717-4562-b3fc-2c963f66afa6';
        return array(
            'legacy demo member id' => array('member-1', 'free_coffee', 'espresso', $uuid, 501),
            'member id not a string' => array(42, 'free_coffee', 'espresso', $uuid, 501),
            'unknown benefit' => array($member, 'free_cake', 'espresso', $uuid, 501),
            'uppercase uuid' => array($member, 'free_coffee', 'espresso', strtoupper($uuid), 501),
            'uuid v1' => array($member, 'free_coffee', 'espresso', '3fa85f64-5717-1562-b3fc-2c963f66afa6', 501),
            'request id missing' => array($member, 'free_coffee', 'espresso', null, 501),
            'request id with newline' => array($member, 'free_coffee', 'espresso', $uuid . "\n", 501),
            'staff id zero' => array($member, 'free_coffee', 'espresso', $uuid, 0),
            'staff id as string' => array($member, 'free_coffee', 'espresso', $uuid, '501'),
            'coffee not a string' => array($member, 'free_coffee', array('espresso'), $uuid, 501),
        );
    }

    /** @dataProvider invalid_requests */
    public function test_malformed_requests_are_rejected_without_writes($member, $benefit, $coffee, $request_id, $staff): void {
        $result = $this->service()->redeem(new EVC_Redemption_Request($member, $benefit, $coffee, $request_id, $staff));
        $this->assertSame(EVC_Redemption_Result::INVALID_REQUEST, $result->outcome());
        $this->assertSame(array(), $result->details());
        $this->assertSame(0, $this->count_rows('evc_redemptions'));
        $this->assertSame(0, $this->count_rows('evc_audit_events'));
    }

    public function test_unknown_and_disabled_members_are_not_found(): void {
        $this->adapter->set(2007, EVC_Mock_Membership_Adapter::active_until(self::DEFAULT_EXPIRY));
        $disabled = $this->create_member(2007, 'disabled');
        $unknown = EVC_Member_Id::mint();
        foreach (array($disabled, $unknown) as $member) {
            $this->assertSame(EVC_Redemption_Result::MEMBER_NOT_FOUND, $this->service()->redeem($this->request($member))->outcome());
        }
        $this->assertSame(0, $this->count_rows('evc_redemptions'));
        $this->assertSame(array(), $this->adapter->calls);
    }

    public function test_members_are_independent(): void {
        $a = $this->create_active_member(2008);
        $b = $this->create_active_member(2009);
        $this->assertSame('redeemed', $this->service()->redeem($this->request($a))->outcome());
        $this->assertSame('redeemed', $this->service()->redeem($this->request($b))->outcome());
        $this->assertSame(2, $this->count_rows('evc_redemptions'));
    }
}
