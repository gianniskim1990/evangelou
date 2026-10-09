<?php

/** Optional session reference: audit-only, never part of idempotency. */
final class SessionRefAuditTest extends EVC_Db_Test_Case {
    const REF_A = '0123456789abcdef0123456789abcdef';
    const REF_B = 'fedcba9876543210fedcba9876543210';

    private function req(string $member, string $request_id, $ref): EVC_Redemption_Request {
        return new EVC_Redemption_Request($member, 'free_coffee', 'espresso', $request_id, self::STAFF_ID, $ref);
    }

    public function test_session_ref_is_recorded_in_success_audit_details(): void {
        $member = $this->create_active_member(8001);
        $result = $this->service()->redeem($this->req($member, self::uuid4(), self::REF_A));
        $this->assertSame('redeemed', $result->outcome());
        $details = json_decode($this->db->fetch_one('SELECT details_json FROM evc_audit_events')['details_json'], true);
        $this->assertSame(self::REF_A, $details['session_ref']);
        $this->assertSame('espresso', $details['coffee_code']);
    }

    public function test_retry_from_a_new_session_still_replays(): void {
        $member = $this->create_active_member(8002);
        $id = self::uuid4();
        $this->assertSame('redeemed', $this->service()->redeem($this->req($member, $id, self::REF_A))->outcome());
        $this->assertSame('replayed', $this->service()->redeem($this->req($member, $id, self::REF_B))->outcome());
        $this->assertSame('replayed', $this->service()->redeem($this->req($member, $id, null))->outcome());
        $this->assertSame(1, $this->count_rows('evc_redemptions'));
    }

    public function test_rejection_audits_carry_the_session_ref(): void {
        $member = $this->create_active_member(8003);
        $this->service()->redeem($this->req($member, self::uuid4(), self::REF_A));
        $this->service()->redeem($this->req($member, self::uuid4(), self::REF_B));
        $row = $this->db->fetch_one("SELECT details_json FROM evc_audit_events WHERE outcome = 'benefit_already_redeemed'");
        $this->assertSame(self::REF_B, json_decode($row['details_json'], true)['session_ref']);
    }

    public function test_malformed_session_ref_is_rejected_and_never_stored(): void {
        $member = $this->create_active_member(8004);
        foreach (array('raw-session-token-value', strtoupper(self::REF_A), self::REF_A . "\n", 123, array()) as $bad) {
            $this->assertSame('invalid_request', $this->service()->redeem($this->req($member, self::uuid4(), $bad))->outcome(), var_export($bad, true));
        }
        $this->assertSame(0, $this->count_rows('evc_audit_events'));
    }

    public function test_no_session_ref_keeps_previous_audit_shape(): void {
        $member = $this->create_active_member(8005);
        $this->service()->redeem($this->req($member, self::uuid4(), null));
        $details = json_decode($this->db->fetch_one('SELECT details_json FROM evc_audit_events')['details_json'], true);
        $this->assertArrayNotHasKey('session_ref', $details);
    }
}
