<?php

final class IdempotencyTest extends EVC_Db_Test_Case {
    public function test_same_key_same_payload_replays_original_result(): void {
        $member = $this->create_active_member(3001);
        $request_id = self::uuid4();
        $first = $this->service()->redeem($this->request($member, 'espresso', $request_id));
        $this->clock->set('2026-10-09T10:00:05Z');

        $retry = $this->service()->redeem($this->request($member, 'espresso', $request_id));

        $this->assertSame(EVC_Redemption_Result::REPLAYED, $retry->outcome());
        $this->assertTrue($retry->is_success());
        $this->assertTrue($retry->is_replay());
        $this->assertSame($first->redemption(), $retry->redemption(), 'replay returns the ORIGINAL result');
        $this->assertSame(1, $this->count_rows('evc_redemptions'));
        $this->assertSame(1, $this->count_rows('evc_audit_events', 'outcome = ?', array('redeemed')));
    }

    public function test_same_key_different_coffee_is_a_conflict(): void {
        $member = $this->create_active_member(3002);
        $request_id = self::uuid4();
        $this->service()->redeem($this->request($member, 'espresso', $request_id));

        $reuse = $this->service()->redeem($this->request($member, 'cappuccino', $request_id));

        $this->assertSame(EVC_Redemption_Result::IDEMPOTENCY_CONFLICT, $reuse->outcome());
        $this->assertNull($reuse->redemption());
        $this->assertSame(array(), $reuse->details());
        $this->assertSame('espresso', $this->db->fetch_one('SELECT coffee_code FROM evc_redemptions')['coffee_code']);
        $this->assertSame(1, $this->count_rows('evc_redemptions'));
    }

    public function test_same_key_for_another_member_never_reveals_the_first_member(): void {
        $alice = $this->create_active_member(3003);
        $bob = $this->create_active_member(3004);
        $request_id = self::uuid4();
        $this->service()->redeem($this->request($alice, 'espresso', $request_id));

        $reuse = $this->service()->redeem($this->request($bob, 'espresso', $request_id));

        $this->assertSame(EVC_Redemption_Result::IDEMPOTENCY_CONFLICT, $reuse->outcome());
        $this->assertNull($reuse->redemption());
        $this->assertSame(array(), $reuse->details());
        $this->assertStringNotContainsString($alice, serialize($reuse));
        // Bob is unaffected and can still redeem with a fresh key.
        $this->assertSame('redeemed', $this->service()->redeem($this->request($bob))->outcome());
        $this->assertSame(2, $this->count_rows('evc_redemptions'));
    }

    public function test_same_key_from_another_staff_actor_is_a_conflict(): void {
        $member = $this->create_active_member(3005);
        $request_id = self::uuid4();
        $this->service()->redeem($this->request($member, 'espresso', $request_id, 501));
        $this->assertSame(
            EVC_Redemption_Result::IDEMPOTENCY_CONFLICT,
            $this->service()->redeem($this->request($member, 'espresso', $request_id, 502))->outcome()
        );
    }

    public function test_retry_after_athens_midnight_replays_the_original_day(): void {
        $member = $this->create_active_member(3006);
        $request_id = self::uuid4();
        $this->clock->set('2026-10-09T20:59:00Z'); // 23:59 Athens, 9 Oct
        $first = $this->service()->redeem($this->request($member, 'espresso', $request_id));
        $this->clock->set('2026-10-09T21:01:00Z'); // 00:01 Athens, 10 Oct

        $retry = $this->service()->redeem($this->request($member, 'espresso', $request_id));

        $this->assertSame(EVC_Redemption_Result::REPLAYED, $retry->outcome());
        $this->assertSame('2026-10-09', $retry->redemption()['business_date']);
        $this->assertSame($first->redemption(), $retry->redemption());
        $this->assertSame(1, $this->count_rows('evc_redemptions'));
        // A genuinely new request after midnight is a new day's coffee.
        $this->assertSame('2026-10-10', $this->service()->redeem($this->request($member))->redemption()['business_date']);
    }

    public function test_retry_after_membership_lapsed_still_replays_committed_success(): void {
        $member = $this->create_active_member(3007, '2026-10-09T10:00:01Z');
        $request_id = self::uuid4();
        $this->assertSame('redeemed', $this->service()->redeem($this->request($member, 'espresso', $request_id))->outcome());
        $this->clock->set('2026-10-09T10:00:05Z');
        $this->assertSame('replayed', $this->service()->redeem($this->request($member, 'espresso', $request_id))->outcome());
        $this->assertSame('membership_inactive', $this->service()->redeem($this->request($member))->outcome());
    }

    public function test_lost_commit_response_is_recovered_by_retrying_same_key(): void {
        $member = $this->create_active_member(3008);
        $request_id = self::uuid4();
        $pdo = $this->database->faulty_pdo();
        $pdo->fail_commit_after = true;

        $lost = $this->service(new EVC_Club_Db($pdo))->redeem($this->request($member, 'espresso', $request_id));

        $this->assertSame(EVC_Redemption_Result::SERVER_ERROR, $lost->outcome(), 'caller cannot know the outcome');
        $this->assertSame(1, $this->count_rows('evc_redemptions'), 'but the redemption did commit');

        $retry = $this->service()->redeem($this->request($member, 'espresso', $request_id));
        $this->assertSame(EVC_Redemption_Result::REPLAYED, $retry->outcome());
        $this->assertSame(1, $this->count_rows('evc_redemptions'));
        $this->assertSame(1, $this->count_rows('evc_audit_events', 'outcome = ?', array('redeemed')));
    }

    public function test_failed_commit_leaves_nothing_and_retry_succeeds(): void {
        $member = $this->create_active_member(3009);
        $request_id = self::uuid4();
        $pdo = $this->database->faulty_pdo();
        $pdo->fail_commit_before = true;

        $failed = $this->service(new EVC_Club_Db($pdo))->redeem($this->request($member, 'espresso', $request_id));

        $this->assertSame(EVC_Redemption_Result::SERVER_ERROR, $failed->outcome());
        $this->assertSame(0, $this->count_rows('evc_redemptions'));
        $this->assertSame(0, $this->count_rows('evc_audit_events'));
        $this->assertSame(EVC_Redemption_Result::REDEEMED, $this->service()->redeem($this->request($member, 'espresso', $request_id))->outcome());
        $this->assertSame(1, $this->count_rows('evc_redemptions'));
    }

    public function test_many_sequential_retries_create_exactly_one_row(): void {
        $member = $this->create_active_member(3010);
        $request_id = self::uuid4();
        $outcomes = array();
        for ($i = 0; $i < 10; $i++) {
            $outcomes[] = $this->service()->redeem($this->request($member, 'espresso', $request_id))->outcome();
        }
        $this->assertSame(array_merge(array('redeemed'), array_fill(0, 9, 'replayed')), $outcomes);
        $this->assertSame(1, $this->count_rows('evc_redemptions'));
    }
}
