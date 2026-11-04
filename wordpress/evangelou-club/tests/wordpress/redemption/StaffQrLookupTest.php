<?php

/**
 * STAGE-05C: the staff QR scan (POST /members/lookup) and the administrator QR revocation
 * (POST /admin/members/qr/revoke) in real WordPress, staff flag ON and EVC_CLUB_REDEMPTION_SOURCE =
 * 'club_db_v1' (this process), no other Club constants:
 *  - the shipped wiring registers both routes and answers 503 until every other piece is configured;
 *  - through the backend's TEST seams (environment / connect / clock / log / first name / membership)
 *    on a disposable, fully migrated Club DB: every gate, the D8 answer (member id + first name +
 *    status), the generic not-found, the D9 failed-scan limit, the D6 revocation, the logs.
 * Real WooCommerce / PMPro memberships end to end: tests/wordpress/commerce-redeem (CI only).
 */
final class StaffQrLookupTest extends EVC_WP_Test_Case {
    const LOOKUP = '/evangelou-club/v1/members/lookup';
    const REVOKE = '/evangelou-club/v1/admin/members/qr/revoke';
    const SECRET = 'evc-test-only-lookup-key-0123456789abcdef';
    const KEY = 'evc-test-only-evidence-key-0123456789abcdef';

    /** @var EVC_Test_Database[] */
    private $databases = array();
    /** @var EVC_Test_Database */
    private $club;
    /** @var EVC_Mock_Membership_Adapter */
    private $adapter;
    /** @var EVC_Fixed_Clock */
    private $clock;
    /** @var string[] */
    private $log = array();
    /** @var int */
    private $connects = 0;

    public function set_up() {
        parent::set_up();
        $this->adapter = new EVC_Mock_Membership_Adapter();
        $this->clock = new EVC_Fixed_Clock('2026-11-04T10:00:10Z');
        $this->log = array();
    }

    public function tear_down() {
        foreach ($this->databases as $db) {
            $db->drop();
        }
        $this->databases = array();
        parent::tear_down();
    }

    private function database(?array $only = null): EVC_Test_Database {
        $db = EVC_Test_Database::create();
        $this->databases[] = $db;
        $dir = EVC_Test_Database::migrations_dir();
        if ($only !== null) {
            $dir = sys_get_temp_dir() . '/evc-migrations-' . bin2hex(random_bytes(4));
            mkdir($dir);
            foreach ($only as $file) {
                copy(EVC_Test_Database::migrations_dir() . '/' . $file, $dir . '/' . $file);
            }
        }
        (new EVC_Migrator($db->connect(), $dir))->migrate();
        return $db;
    }

    private function environment(?EVC_Db_Config $db = null): array {
        if ($db === null) {
            $this->club = $this->club ?? $this->database();
            $db = $this->club->config();
        }
        return array(
            'staff' => true,
            'redemption' => 'club_db_v1',
            'provenance' => 'club_db_v1',
            'commerce' => EVC_Commerce_Source_Config::parse('pmpro_woocommerce_bacs_v1', 1, 24435, self::KEY),
            'db' => $db,
            'lookup_key' => self::SECRET,
        );
    }

    private function backend(?array $environment = null): EVC_Club_Lookup_Backend {
        $environment = $environment ?? $this->environment();
        return new EVC_Club_Lookup_Backend(
            function () use ($environment) {
                return $environment;
            },
            function (EVC_Db_Config $config) {
                $this->connects++;
                return EVC_Club_Db::connect($config);
            },
            $this->clock,
            function (string $line) {
                $this->log[] = $line;
            },
            null,
            function () {
                return $this->adapter;
            }
        );
    }

    private function use_backend(?array $environment = null): EVC_Club_Lookup_Backend {
        $backend = $this->backend($environment);
        (new EVC_Rest_Lookup_Controller($backend))->register_routes(true);
        return $backend;
    }

    private function db(): EVC_Club_Db {
        $this->club = $this->club ?? $this->database();
        return $this->club->connect();
    }

    private function customer(string $first = 'Ελένη', string $last = 'Μαρκοπούλου'): int {
        if (!get_role('customer')) {
            add_role('customer', 'Customer', array('read' => true));
        }
        return (int) self::factory()->user->create(array('role' => 'customer', 'first_name' => $first, 'last_name' => $last, 'user_email' => 'c.' . wp_generate_password(6, false, false) . '@example.org'));
    }

    /** A Club member with a fresh QR: [wp user id, member public id, plaintext token]. */
    private function member_with_qr(string $first = 'Ελένη', string $status = 'enabled'): array {
        $user = $this->customer($first);
        $public = EVC_Member_Id::mint();
        $db = $this->db();
        $db->execute('INSERT INTO evc_members (member_public_id, wp_user_id, status, created_at_utc, updated_at_utc) VALUES (?, ?, ?, ?, ?)', array($public, $user, $status, '2026-11-01 00:00:00.000000', '2026-11-01 00:00:00.000000'));
        $id = (int) $db->fetch_one('SELECT id FROM evc_members WHERE member_public_id = ?', array($public))['id'];
        $token = EVC_Qr_Token::mint();
        (new EVC_Sql_Qr_Token_Store($db))->rotate($id, null, EVC_Qr_Token::digest($token), '2026-11-01 00:00:00.000000');
        return array($user, $public, $token);
    }

    private function scan(string $token, ?string $nonce = null): WP_REST_Response {
        return $this->rest('POST', self::LOOKUP, array('method' => 'qr', 'qr_token' => $token), $nonce ?? $this->nonce());
    }

    // ---- shipped wiring ---------------------------------------------------------------------------

    public function test_the_opt_in_registers_both_routes_which_answer_503_without_the_other_pieces(): void {
        $file = tempnam(sys_get_temp_dir(), 'evc-qr-log-');
        $previous = ini_get('error_log');
        ini_set('error_log', $file);
        $routes = rest_get_server()->get_routes();
        $this->assertArrayHasKey(self::LOOKUP, $routes);
        $this->assertArrayHasKey(self::REVOKE, $routes);
        $this->login_as($this->create_staff_user());
        $response = $this->scan(EVC_Qr_Token::mint());
        $this->assertEnvelope($response, 503, 'server_error');
        $backend = (new ReflectionProperty($routes[self::LOOKUP][0]['callback'][0], 'backend'));
        $backend->setAccessible(true);
        $this->assertSame(EVC_Club_Lookup_Backend::REASON_COMMERCE, $backend->getValue($routes[self::LOOKUP][0]['callback'][0])->last_reason());
        ini_set('error_log', (string) $previous);
        $this->assertSame("Evangelou Club: staff QR unavailable (commerce_source_not_configured).\n", preg_replace('/^\[[^\]]*\] /m', '', (string) file_get_contents($file)));
        @unlink($file);
    }

    public function test_only_the_staff_account_with_a_nonce_may_scan(): void {
        $this->use_backend();
        list(, , $token) = $this->member_with_qr();
        $this->assertEnvelope($this->scan($token, ''), 401, 'unauthorized');
        $this->login_as($this->customer());
        $this->assertEnvelope($this->scan($token), 403, 'forbidden');
        $this->login_as($this->create_user_with_role('administrator'));
        $this->assertEnvelope($this->scan($token), 403, 'forbidden');
        $staff = $this->create_staff_user();
        $this->login_as($staff);
        $this->assertEnvelope($this->rest('POST', self::LOOKUP, array('method' => 'qr', 'qr_token' => $token)), 401, 'unauthorized');
        $this->login_as($staff);
        $this->assertSame(200, $this->scan($token)->get_status());
    }

    // ---- gates (no connection before the last one) -------------------------------------------------

    public function test_every_missing_piece_is_unavailable_without_a_connection(): void {
        $this->login_as($this->create_staff_user());
        $cases = array(
            EVC_Club_Lookup_Backend::REASON_STAFF => array('staff' => 1),
            EVC_Club_Lookup_Backend::REASON_REDEMPTION => array('redemption' => 'CLUB_DB_V1'),
            EVC_Club_Lookup_Backend::REASON_COMMERCE => array('commerce' => null),
            EVC_Club_Lookup_Backend::REASON_PROVENANCE => array('provenance' => true),
            EVC_Club_Lookup_Backend::REASON_DB_CONFIG => array('db' => null),
            EVC_Club_Lookup_Backend::REASON_KEY => array('lookup_key' => str_repeat('k', 31)),
        );
        $base = $this->environment();
        foreach ($cases as $reason => $override) {
            $this->connects = 0;
            $backend = $this->use_backend(array_merge($base, $override));
            $this->assertEnvelope($this->scan(EVC_Qr_Token::mint()), 503, 'server_error');
            $this->assertSame($reason, $backend->last_reason(), $reason);
            $this->assertSame(0, $this->connects, $reason . ': no connection');
        }
        $this->connects = 0;
        $backend = $this->use_backend($this->environment($this->database(array('001_initial.sql', '002_payment_provenance.sql'))->config()));
        $this->assertEnvelope($this->scan(EVC_Qr_Token::mint()), 503, 'server_error');
        $this->assertSame(EVC_Club_Lookup_Backend::REASON_SCHEMA, $backend->last_reason(), 'without migration 003');
        $this->assertSame(1, $this->connects);
        foreach ($this->log as $line) {
            $this->assertStringNotContainsString(self::SECRET, $line);
            $this->assertMatchesRegularExpression('/^Evangelou Club: staff QR unavailable \([a-z_]+(, [A-Za-z0-9_]+)?\)\.$/', $line);
        }
    }

    // ---- D8 answers ---------------------------------------------------------------------------------

    public function test_a_scan_answers_member_id_first_name_and_status_only(): void {
        $this->use_backend();
        list($user, $public, $token) = $this->member_with_qr('Ελένη');
        $this->adapter->set($user, EVC_Mock_Membership_Adapter::active_until('2026-12-01T10:00:00Z'));
        $this->login_as($this->create_staff_user());
        $r = $this->scan($token);
        $this->assertSame(200, $r->get_status(), wp_json_encode($r->get_data()));
        $this->assertSame(array('member' => array('member_id' => $public, 'first_name' => 'Ελένη'), 'status' => 'active'), $r->get_data(), 'nothing else: no surname, phone, e-mail or dates');
        $this->assertSame('no-store, private, max-age=0', $r->get_headers()['Cache-Control']);
        $json = wp_json_encode($r->get_data());
        $this->assertStringNotContainsString('Μαρκοπούλου', $json);
        $this->assertStringNotContainsString($token, $json);
    }

    public function test_only_the_first_word_of_the_first_name_and_any_key_order(): void {
        $this->use_backend();
        list($user, $public, $token) = $this->member_with_qr('  Μαρία   Ελένη ');
        $this->adapter->set($user, EVC_Mock_Membership_Adapter::active_until('2026-12-01T10:00:00Z'));
        $this->login_as($this->create_staff_user());
        $r = $this->rest('POST', self::LOOKUP, array('qr_token' => $token, 'method' => 'qr'), $this->nonce());
        $this->assertSame(200, $r->get_status(), 'key order does not matter');
        $this->assertSame(array('member_id' => $public, 'first_name' => 'Μαρία'), $r->get_data()['member'], 'D8 «μικρό όνομα»: the first word only');
        list(, , $long) = $this->member_with_qr(str_repeat('Α', 60));
        $this->assertSame(40, mb_strlen($this->scan($long)->get_data()['member']['first_name']));
    }

    public function test_frozen_expired_and_unverified_cards_are_not_active_and_todays_coffee_shows_as_taken(): void {
        $this->use_backend();
        list($user, $public, $token) = $this->member_with_qr();
        $this->login_as($this->create_staff_user());
        $cases = array(
            'cancelled (refund / cancellation freezes the card)' => EVC_Mock_Membership_Adapter::with(EVC_Entitlement::STATUS_CANCELLED, true, '2026-12-01T10:00:00Z'),
            'refunded' => EVC_Mock_Membership_Adapter::with(EVC_Entitlement::STATUS_REFUNDED, true, '2026-12-01T10:00:00Z'),
            'ends exactly now (exclusive end)' => EVC_Mock_Membership_Adapter::active_until('2026-11-04T10:00:10Z'),
            'unverified payment' => EVC_Mock_Membership_Adapter::with(EVC_Entitlement::STATUS_ACTIVE, false, '2026-12-01T10:00:00Z'),
            'pending payment' => EVC_Mock_Membership_Adapter::with(EVC_Entitlement::STATUS_PENDING_PAYMENT, false, null),
        );
        foreach ($cases as $label => $entitlement) {
            $this->adapter->set($user, $entitlement);
            $this->assertSame('inactive', $this->scan($token)->get_data()['status'] ?? null, $label);
        }
        $this->adapter->set($user, EVC_Mock_Membership_Adapter::active_until('2026-11-04T10:00:11Z'));
        $this->assertSame('active', $this->scan($token)->get_data()['status'], 'one second before the end');
        $db = $this->db();
        $member_id = (int) $db->fetch_one('SELECT id FROM evc_members WHERE member_public_id = ?', array($public))['id'];
        $db->execute("INSERT INTO evc_redemptions (member_id, benefit_type, business_date, coffee_code, request_id, request_fingerprint, staff_wp_user_id, membership_source, membership_status, membership_expires_at_utc, redeemed_at_utc) VALUES (?, 'free_coffee', '2026-11-04', 'espresso', ?, ?, 7, 'mock', 'active', '2026-12-01 10:00:00.000000', '2026-11-03 22:30:00.000000')", array($member_id, self::uuid4(), str_repeat('f', 64)));
        $this->adapter->set($user, EVC_Mock_Membership_Adapter::active_until('2026-12-01T10:00:00Z'));
        $this->assertSame('used_today', $this->scan($token)->get_data()['status'], 'a coffee at 00:30 Athens counts for that Athens day');
        $this->clock->set('2026-11-04T21:59:59Z');
        $this->assertSame('used_today', $this->scan($token)->get_data()['status'], '23:59:59 Athens');
        $this->clock->set('2026-11-04T22:00:00Z');
        $this->assertSame('active', $this->scan($token)->get_data()['status'], 'Athens midnight: a new day');
        $this->adapter->set($user, EVC_Mock_Membership_Adapter::with(EVC_Entitlement::STATUS_CANCELLED, true, '2026-12-01T10:00:00Z'));
        $this->clock->set('2026-11-04T12:00:00Z');
        $this->assertSame('inactive', $this->scan($token)->get_data()['status'], 'a frozen card is not active even after today\'s coffee');
    }

    public function test_one_generic_not_found_never_reveals_whether_a_token_existed(): void {
        $this->use_backend();
        list($user, $public, $token) = $this->member_with_qr();
        list(, , $disabled_token) = $this->member_with_qr('Νίκος', 'disabled');
        $this->login_as($this->create_staff_user());
        $unknown = $this->scan(EVC_Qr_Token::mint());
        $this->assertEnvelope($unknown, 404, 'member_not_found');
        $db = $this->db();
        $id = (int) $db->fetch_one('SELECT id FROM evc_members WHERE member_public_id = ?', array($public))['id'];
        $store = new EVC_Sql_Qr_Token_Store($db);
        $store->rotate($id, EVC_Qr_Token::digest($token), EVC_Qr_Token::digest(EVC_Qr_Token::mint()), '2026-11-02 00:00:00.000000');
        $replaced = $this->scan($token);
        $store->revoke($id, (string) $store->active_token_hash($id), '2026-11-03 00:00:00.000000');
        $disabled = $this->scan($disabled_token);
        foreach (array('replaced' => $replaced, 'disabled member' => $disabled) as $label => $r) {
            $this->assertSame($unknown->get_status(), $r->get_status(), $label);
            $this->assertSame($unknown->get_data(), $r->get_data(), $label . ': byte-identical body');
        }
        $this->assertEnvelope($this->scan('not-a-club-qr'), 400, 'invalid_qr');
        foreach (array(array('method' => 'phone', 'phone' => '6900000001'), array('method' => 'qr'), array('method' => 'qr', 'qr_token' => $token, 'x' => 1), array('method' => 'qr', 'qr_token' => 5)) as $body) {
            $this->assertEnvelope($this->rest('POST', self::LOOKUP, $body, $this->nonce()), 400, 'invalid_request');
        }
    }

    // ---- D9 failed-scan limit ---------------------------------------------------------------------------

    public function test_ten_failed_scans_block_this_tablet_for_the_window_but_not_another_tablet(): void {
        $backend = $this->use_backend();
        list($user, , $token) = $this->member_with_qr();
        $this->adapter->set($user, EVC_Mock_Membership_Adapter::active_until('2026-12-01T10:00:00Z'));
        $staff = $this->create_staff_user();
        $this->login_as($staff);
        for ($i = 0; $i < 10; $i++) {
            $this->assertSame(404, $this->scan(EVC_Qr_Token::mint())->get_status());
        }
        $blocked = $this->scan($token);
        $this->assertEnvelope($blocked, 429, 'rate_limited');
        $this->assertSame('300', $blocked->get_headers()['Retry-After']);
        $this->assertSame(array('retry_after_seconds' => 300), $blocked->get_data()['error']['details']);
        $this->login_as($staff); // a second tablet: another session of the same shared account
        $this->assertSame(200, $this->scan($token)->get_status(), 'per tablet (D9)');
        $this->clock->set('2026-11-04T10:05:00Z');
        $other = $this->backend()->lookup(new EVC_Staff_Actor($staff, str_repeat('a', 32)), $token);
        $this->assertSame('found', $other['result']);
        $this->assertSame(0, count(array_filter($this->log, function ($l) use ($token) {
            return strpos($l, $token) !== false;
        })));
        unset($backend);
    }

    public function test_the_wordpress_authorizer_answers_only_the_two_qr_capabilities(): void {
        $authorizer = EVC_Club_Qr_Services::authorizer();
        $staff = $this->create_staff_user();
        $admin = $this->create_user_with_role('administrator');
        $customer = $this->customer();
        $this->assertTrue($authorizer->allows(new EVC_Staff_Actor($staff, str_repeat('a', 32)), EVC_Staff_Role::CAP_LOOKUP));
        $this->assertFalse($authorizer->allows(new EVC_Staff_Actor($staff), EVC_Staff_Role::CAP_MANAGE), 'the shared tablet account never manages QR codes');
        $this->assertTrue($authorizer->allows(new EVC_Staff_Actor($admin), EVC_Staff_Role::CAP_MANAGE));
        $this->assertFalse($authorizer->allows(new EVC_Staff_Actor($customer), EVC_Staff_Role::CAP_MANAGE));
        $this->assertFalse($authorizer->allows(new EVC_Staff_Actor($customer), EVC_Staff_Role::CAP_LOOKUP));
        $user = new WP_User($admin);
        $user->add_cap(EVC_Club_Staff_Authorizer::CONFIRM_HANDOFF);
        $this->assertTrue(user_can($admin, EVC_Club_Staff_Authorizer::CONFIRM_HANDOFF));
        $this->assertFalse($authorizer->allows(new EVC_Staff_Actor($admin), EVC_Club_Staff_Authorizer::CONFIRM_HANDOFF), 'no other capability, even when the user has it');
        $this->assertFalse($authorizer->allows(new EVC_Staff_Actor($admin), 'manage_options'));
    }

    // ---- D6 administrator revocation ---------------------------------------------------------------------

    /**
     * STAGE-05C review (MINOR 2): the route's own refusals are NOT re-checked by the service's
     * authorizer (an administrator who also carries the Club staff role does hold evc_manage_club), so
     * each one is tested on its own.
     */
    public function test_revocation_refuses_a_staff_role_administrator_and_an_application_password_call(): void {
        $this->use_backend();
        list($user, , $token) = $this->member_with_qr();
        $mixed = $this->create_user_with_role('administrator');
        (new WP_User($mixed))->add_role(EVC_Staff_Role::ROLE);
        $this->assertTrue(user_can($mixed, EVC_Staff_Role::CAP_MANAGE), 'the service authorizer alone would allow it');
        $this->login_as($mixed);
        $this->assertEnvelope($this->rest('POST', self::REVOKE, array('wp_user_id' => $user), $this->nonce()), 403, 'forbidden');

        $admin = $this->create_user_with_role('administrator');
        $this->login_as($admin);
        do_action('application_password_did_authenticate', new WP_User($admin), array('uuid' => 'test-only'));
        $this->assertEnvelope($this->rest('POST', self::REVOKE, array('wp_user_id' => $user), $this->nonce()), 401, 'unauthorized');

        $store = new EVC_Sql_Qr_Token_Store($this->db());
        $member = $store->find_member_by_wp_user($user);
        $this->assertSame(EVC_Qr_Token::digest($token), $store->active_token_hash($member['id']), 'nothing was revoked');
    }

    public function test_an_administrator_revokes_a_customers_qr_and_the_old_qr_stops_working(): void {
        $this->use_backend();
        list($user, , $token) = $this->member_with_qr();
        $this->adapter->set($user, EVC_Mock_Membership_Adapter::active_until('2026-12-01T10:00:00Z'));
        $staff = $this->create_staff_user();
        $admin = $this->create_user_with_role('administrator');
        $this->assertTrue(user_can($admin, EVC_Staff_Role::CAP_MANAGE));

        $this->login_as($staff);
        $this->assertEnvelope($this->rest('POST', self::REVOKE, array('wp_user_id' => $user), $this->nonce()), 403, 'forbidden');
        $this->login_as($this->customer());
        $this->assertEnvelope($this->rest('POST', self::REVOKE, array('wp_user_id' => $user), $this->nonce()), 403, 'forbidden');
        $this->login_as($admin);
        $this->assertEnvelope($this->rest('POST', self::REVOKE, array('wp_user_id' => $user)), 401, 'unauthorized');
        $this->login_as($admin);
        foreach (array(array(), array('wp_user_id' => '5'), array('wp_user_id' => 0), array('wp_user_id' => $user, 'x' => 1)) as $body) {
            $this->assertEnvelope($this->rest('POST', self::REVOKE, $body, $this->nonce()), 400, 'invalid_request');
        }
        $this->assertEnvelope($this->rest('POST', self::REVOKE, array('wp_user_id' => $this->customer()), $this->nonce()), 404, 'member_not_found');
        $r = $this->rest('POST', self::REVOKE, array('wp_user_id' => $user), $this->nonce());
        $this->assertSame(array('revoked' => true), $r->get_data());
        $this->assertSame(array('revoked' => false), $this->rest('POST', self::REVOKE, array('wp_user_id' => $user), $this->nonce())->get_data());

        $this->login_as($staff);
        $this->assertEnvelope($this->scan($token), 404, 'member_not_found');
    }
}
