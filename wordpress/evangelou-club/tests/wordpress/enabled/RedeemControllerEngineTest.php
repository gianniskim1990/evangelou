<?php

/** TEST-ONLY backend: real Task 1B engine + disposable Club DB + mock membership. */
final class EVC_Test_Redemption_Backend implements EVC_Redemption_Backend {
    /** @var EVC_Redemption_Service|null */
    public $service;
    /** @var int */
    public $calls = 0;

    public function service(): ?EVC_Redemption_Service {
        $this->calls++;
        return $this->service;
    }
}

/**
 * Feature ENABLED, controller wired to the REAL engine through a test-only
 * backend: permission callback, validation and translation end to end.
 */
final class RedeemControllerEngineTest extends EVC_WP_Test_Case {
    /** @var EVC_Test_Database */
    private $club_db_handle;
    /** @var EVC_Club_Db */
    private $club_db;
    /** @var EVC_Test_Redemption_Backend */
    private $backend;
    /** @var EVC_Mock_Membership_Adapter */
    private $adapter;
    /** @var EVC_Fixed_Clock */
    private $clock;
    /** @var string */
    private $member;

    public function set_up() {
        parent::set_up();
        $this->club_db_handle = EVC_Test_Database::create();
        $this->club_db = $this->club_db_handle->connect();
        (new EVC_Migrator($this->club_db, EVC_Test_Database::migrations_dir()))->migrate();

        $this->member = EVC_Member_Id::mint();
        $this->club_db->execute(
            'INSERT INTO evc_members (member_public_id, wp_user_id, status, created_at_utc, updated_at_utc) VALUES (?, ?, ?, ?, ?)',
            array($this->member, 4242, 'enabled', '2026-09-01 00:00:00.000000', '2026-09-01 00:00:00.000000')
        );
        $this->adapter = new EVC_Mock_Membership_Adapter();
        $this->adapter->set(4242, EVC_Mock_Membership_Adapter::active_until('2026-11-09T10:00:00Z'));
        $this->clock = new EVC_Fixed_Clock('2026-10-09T10:00:00Z');

        $this->backend = new EVC_Test_Redemption_Backend();
        $this->backend->service = new EVC_Redemption_Service(
            new EVC_Redemption_Store($this->club_db),
            new EVC_Db_Audit_Log(),
            $this->adapter,
            new EVC_Coffee_Catalog(require dirname(__DIR__, 2) . '/fixtures/coffee-catalog.php'),
            $this->clock
        );
        (new EVC_Rest_Redeem_Controller($this->backend))->register_routes(true);
    }

    public function tear_down() {
        $this->club_db = null;
        if ($this->club_db_handle) {
            $this->club_db_handle->drop();
        }
        parent::tear_down();
    }

    private function redeem(array $body, ?string $member = null): WP_REST_Response {
        return $this->rest('POST', $this->redeem_route($member ?? $this->member), $body, $this->nonce());
    }

    public function test_success_translates_engine_result_and_uses_server_side_staff_identity(): void {
        $staff = $this->create_staff_user();
        $token = $this->login_as($staff);
        $request_id = self::uuid4();

        $response = $this->redeem(array('request_id' => $request_id, 'coffee_code' => 'freddo_espresso', 'staff_wp_user_id' => 999, 'member_id' => 'mem_spoofed'));

        $this->assertSame(200, $response->get_status(), wp_json_encode($response->get_data()));
        $this->assertSame(array(
            'member_id' => $this->member,
            'benefit_type' => 'free_coffee',
            'state' => 'used',
            'business_date' => '2026-10-09',
            'redeemed_at' => '2026-10-09T13:00:00+03:00',
            'coffee_code' => 'freddo_espresso',
            'request_id' => $request_id,
            'replayed' => false,
        ), $response->get_data());

        $row = $this->club_db->fetch_one('SELECT staff_wp_user_id FROM evc_redemptions');
        $this->assertSame($staff, (int) $row['staff_wp_user_id'], 'staff id comes from WordPress, not the body');

        $audit = json_decode($this->club_db->fetch_one('SELECT details_json FROM evc_audit_events')['details_json'], true);
        $this->assertSame(EVC_Staff_Session::session_ref($token), $audit['session_ref']);
        $this->assertStringNotContainsString($token, wp_json_encode($audit), 'raw session token never stored');
        $this->assertArrayHasKey('Cache-Control', $response->get_headers());
    }

    public function test_replay_after_relogin_returns_the_original_result(): void {
        $staff = $this->create_staff_user();
        $this->login_as($staff);
        $body = array('request_id' => self::uuid4(), 'coffee_code' => 'espresso');
        $first = $this->redeem($body)->get_data();

        $this->login_as($staff); // session expired / re-login: new token, same shared account
        $this->clock->set('2026-10-09T21:05:00Z'); // after Athens midnight
        $again = $this->redeem($body);

        $this->assertSame(200, $again->get_status());
        $this->assertTrue($again->get_data()['replayed']);
        $this->assertSame($first['redeemed_at'], $again->get_data()['redeemed_at']);
        $this->assertSame('2026-10-09', $again->get_data()['business_date']);
        $this->assertSame(1, (int) $this->club_db->fetch_one('SELECT COUNT(*) AS n FROM evc_redemptions')['n']);
    }

    public function test_business_failures_map_to_contract_envelopes(): void {
        $this->login_as($this->create_staff_user());
        $first = $this->redeem(array('request_id' => self::uuid4(), 'coffee_code' => 'espresso'))->get_data();

        $again = $this->redeem(array('request_id' => self::uuid4(), 'coffee_code' => 'cappuccino'));
        $this->assertEnvelope($again, 409, 'benefit_already_redeemed');
        $this->assertSame(array('business_date' => '2026-10-09', 'redeemed_at' => $first['redeemed_at']), $again->get_data()['error']['details']);

        $reuse = $this->redeem(array('request_id' => $first['request_id'], 'coffee_code' => 'cappuccino'));
        $this->assertEnvelope($reuse, 409, 'idempotency_key_reused');
        $this->assertArrayNotHasKey('details', $reuse->get_data()['error']);

        $this->assertEnvelope($this->redeem(array('request_id' => self::uuid4(), 'coffee_code' => 'mocha_latte')), 400, 'invalid_coffee');
        $this->assertEnvelope($this->redeem(array('request_id' => 'NOT-A-UUID', 'coffee_code' => 'espresso')), 400, 'invalid_request');
        $this->assertEnvelope($this->redeem(array('request_id' => self::uuid4(), 'coffee_code' => 'espresso'), EVC_Member_Id::mint()), 404, 'member_not_found');
    }

    public function test_inactive_membership_exposes_public_status_only(): void {
        $this->adapter->set(4242, EVC_Mock_Membership_Adapter::with('pending_payment', false, '2026-11-09T10:00:00Z'));
        $this->login_as($this->create_staff_user());
        $response = $this->redeem(array('request_id' => self::uuid4(), 'coffee_code' => 'espresso'));
        $this->assertEnvelope($response, 409, 'membership_inactive');
        $this->assertSame(array('status' => 'inactive'), $response->get_data()['error']['details']);
        $this->assertStringNotContainsString('pending_payment', wp_json_encode($response->get_data()));
        $this->assertSame(0, (int) $this->club_db->fetch_one('SELECT COUNT(*) AS n FROM evc_redemptions')['n']);
    }

    public function test_winter_timestamps_carry_plus_two_offset(): void {
        $this->assertSame('2026-01-15T12:00:00+02:00', EVC_Rest_Redeem_Controller::rfc3339('2026-01-15 10:00:00.123456'));
        $this->assertSame('2026-07-15T13:00:00+03:00', EVC_Rest_Redeem_Controller::rfc3339('2026-07-15 10:00:00'));
    }

    public function test_engine_exception_never_leaks(): void {
        $this->adapter->set(4242, new RuntimeException('PMPro said SQLSTATE secret'));
        $this->login_as($this->create_staff_user());
        $response = $this->redeem(array('request_id' => self::uuid4(), 'coffee_code' => 'espresso'));
        $this->assertEnvelope($response, 500, 'server_error');
        $this->assertStringNotContainsString('SQLSTATE', wp_json_encode($response->get_data()));
    }

    public function test_unauthorized_requests_never_reach_the_engine(): void {
        $this->backend->calls = 0;
        $this->assertEnvelope($this->rest('POST', $this->redeem_route($this->member), array('request_id' => self::uuid4(), 'coffee_code' => 'espresso')), 401, 'unauthorized');
        $this->login_as($this->create_user_with_role('editor'));
        $this->assertEnvelope($this->redeem(array('request_id' => self::uuid4(), 'coffee_code' => 'espresso')), 403, 'forbidden');
        $this->assertSame(0, $this->backend->calls);
        $this->assertSame(0, (int) $this->club_db->fetch_one('SELECT COUNT(*) AS n FROM evc_redemptions')['n']);
    }
}
