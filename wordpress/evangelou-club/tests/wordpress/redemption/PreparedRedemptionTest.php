<?php

/**
 * Round 9 (owner decision D24) in real WordPress, staff flag ON and EVC_CLUB_REDEMPTION_SOURCE = 'club_db_v1':
 *  - the shipped wiring registers POST …/prepared-redeem and GET /coffee-catalogue (and nothing else is
 *    configured, so the redeem answers 503 and builds nothing);
 *  - through the backend's TEST seams on a disposable Club DB: the full v2 redemption over REST (always HTTP 200
 *    with the UI-04G domain body), replay / conflict / outcome rules, the staff note, schema below 4 = 409
 *    prepared_unavailable (graceful: the till uses v1), auth;
 *  - the lookup reply with "history": true (prepared flag + THIS member's last coffees, never another member's,
 *    never a staff id), and the unchanged reply for older tills.
 * Real WooCommerce / PMPro end to end: tests/wordpress/commerce-redeem (CI only).
 */
final class PreparedRedemptionTest extends EVC_WP_Test_Case {
    const CATALOGUE = '/evangelou-club/v1/coffee-catalogue';
    const LOOKUP = '/evangelou-club/v1/members/lookup';
    const SECRET = 'evc-test-only-lookup-key-0123456789abcdef';
    const KEY = 'evc-test-only-evidence-key-0123456789abcdef';

    /** @var EVC_Test_Database[] */
    private $databases = array();
    /** @var EVC_Test_Database|null */
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
        $this->clock = new EVC_Fixed_Clock('2026-11-20T10:00:10Z');
        $this->log = array();
        $this->club = null;
    }

    public function tear_down() {
        foreach ($this->databases as $db) {
            $db->drop();
        }
        $this->databases = array();
        parent::tear_down();
    }

    private static function route(string $member, string $benefit = 'free_coffee'): string {
        return '/evangelou-club/v1/members/' . $member . '/benefits/' . $benefit . '/prepared-redeem';
    }

    private function database(int $up_to = PHP_INT_MAX): EVC_Test_Database {
        $db = EVC_Test_Database::create();
        $this->databases[] = $db;
        (new EVC_Migrator($db->connect(), EVC_Test_Database::migrations_dir()))->migrate($up_to);
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

    private function use_backend(?array $environment = null): EVC_Club_Prepared_Redemption_Backend {
        $environment = $environment ?? $this->environment();
        $backend = new EVC_Club_Prepared_Redemption_Backend(
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
            function () {
                return $this->adapter;
            }
        );
        (new EVC_Rest_Prepared_Redeem_Controller($backend))->register_routes(true);
        return $backend;
    }

    private function use_lookup(?array $environment = null): void {
        $environment = $environment ?? $this->environment();
        (new EVC_Rest_Lookup_Controller(new EVC_Club_Lookup_Backend(
            function () use ($environment) {
                return $environment;
            },
            null,
            $this->clock,
            function (string $line) {
                $this->log[] = $line;
            },
            null,
            function () {
                return $this->adapter;
            }
        )))->register_routes(true);
    }

    private function db(): EVC_Club_Db {
        $this->club = $this->club ?? $this->database();
        return $this->club->connect();
    }

    /** An enabled member with an active membership: [wp user id, member public id, QR token]. */
    private function member(): array {
        if (!get_role('customer')) {
            add_role('customer', 'Customer', array('read' => true));
        }
        $user = (int) self::factory()->user->create(array('role' => 'customer', 'first_name' => 'Ελένη', 'user_email' => 'c.' . wp_generate_password(6, false, false) . '@example.org'));
        $this->adapter->set($user, EVC_Mock_Membership_Adapter::active_until('2026-12-20T00:00:00Z'));
        $public = EVC_Member_Id::mint();
        $db = $this->db();
        $db->execute('INSERT INTO evc_members (member_public_id, wp_user_id, status, created_at_utc, updated_at_utc) VALUES (?, ?, ?, ?, ?)', array($public, $user, 'enabled', '2026-11-01 00:00:00.000000', '2026-11-01 00:00:00.000000'));
        $id = (int) $db->fetch_one('SELECT id FROM evc_members WHERE member_public_id = ?', array($public))['id'];
        $token = EVC_Qr_Token::mint();
        (new EVC_Sql_Qr_Token_Store($db))->rotate($id, null, EVC_Qr_Token::digest($token), '2026-11-01 00:00:00.000000');
        return array($user, $public, $token);
    }

    private static function body(string $request_id, ?string $note = null): array {
        $preparation = array(
            'modifiers' => array(
                array('group' => 'shots', 'option' => 'triple'),
                array('group' => 'sweetness', 'option' => 'sweet'),
                array('group' => 'sweetener', 'option' => 'white'),
            ),
            'quick_notes' => array('very_hot'),
        );
        if ($note !== null) {
            $preparation['note'] = $note;
        }
        return array('request_id' => $request_id, 'catalogue_version' => 2, 'coffee_code' => 'greek_coffee', 'preparation' => $preparation);
    }

    private function redeem(string $member, array $body): WP_REST_Response {
        return $this->rest('POST', self::route($member), $body, $this->nonce());
    }

    // ---- shipped wiring -------------------------------------------------------------------------------------------

    public function test_the_opt_in_registers_both_routes_and_the_redeem_answers_503_without_the_other_pieces(): void {
        $routes = rest_get_server()->get_routes();
        $this->assertArrayHasKey('/evangelou-club/v1/members/(?P<member_id>[A-Za-z0-9_-]{1,64})/benefits/(?P<benefit_type>[a-z_]{1,32})/prepared-redeem', $routes);
        $this->assertArrayHasKey(self::CATALOGUE, $routes);
        $file = tempnam(sys_get_temp_dir(), 'evc-prep-log-');
        $previous = ini_get('error_log');
        ini_set('error_log', $file);
        $this->login_as($this->create_staff_user());
        $this->assertEnvelope($this->redeem(EVC_Member_Id::mint(), self::body(self::uuid4())), 503, 'server_error');
        ini_set('error_log', (string) $previous);
        $this->assertSame("Evangelou Club: prepared redemption unavailable (commerce_source_not_configured).\n", preg_replace('/^\[[^\]]*\] /m', '', (string) file_get_contents($file)));
        @unlink($file);
    }

    public function test_the_catalogue_route_serves_the_approved_document_to_the_staff_account_only(): void {
        $this->assertEnvelope($this->rest('GET', self::CATALOGUE, null, ''), 401, 'unauthorized');
        $this->login_as($this->create_user_with_role('administrator'));
        $this->assertEnvelope($this->rest('GET', self::CATALOGUE, null, $this->nonce()), 403, 'forbidden');
        $this->login_as($this->create_staff_user());
        $response = $this->rest('GET', self::CATALOGUE, null, $this->nonce());
        $this->assertSame(200, $response->get_status());
        $data = $response->get_data();
        $this->assertSame(array('catalogue_version', 'version_label', 'catalogue'), array_keys($data));
        $this->assertSame(2, $data['catalogue_version']);
        $this->assertSame('evc-coffee-v2-2026-11-20', $data['version_label']);
        $this->assertSame(EVC_Club_Prep_Catalogue_Authority::document(), $data['catalogue']);
        $this->assertSame(EVC_Club_Prep_Catalogue_Authority::APPROVED_DIGESTS[2], EVC_Prep_Catalogue_Digest::of($data['catalogue']));
    }

    // ---- prepared redemption over REST ------------------------------------------------------------------------------

    public function test_a_prepared_redemption_over_rest_records_once_and_replays_with_the_same_id(): void {
        $this->use_backend();
        list(, $member) = $this->member();
        $this->login_as($this->create_staff_user());
        $id = self::uuid4();
        $first = $this->redeem($member, self::body($id, '  στο   δεύτερο τραπέζι '));
        $this->assertSame(200, $first->get_status(), wp_json_encode($first->get_data()));
        $data = $first->get_data();
        $this->assertSame('redeemed', $data['outcome']);
        $this->assertSame('prepare', $data['instruction']);
        $this->assertSame($id, $data['request_id']);
        $this->assertSame('2026-11-20', $data['server_business_date']);
        $this->assertSame(array('request_id', 'member_id', 'business_date', 'redeemed_at_utc'), array_keys($data['redemption']));
        $this->assertSame($member, $data['redemption']['member_id']);
        $this->assertStringNotContainsString('τραπέζι', wp_json_encode($data, JSON_UNESCAPED_UNICODE), 'the reply never echoes the note');
        $this->assertSame('στο δεύτερο τραπέζι', $this->db()->fetch_one('SELECT note_text FROM evc_redemption_preparations')['note_text']);

        $replay = $this->redeem($member, self::body($id, 'στο δεύτερο τραπέζι'))->get_data();
        $this->assertSame('replayed', $replay['outcome']);
        $this->assertSame('verify_handoff', $replay['instruction']);
        $this->assertSame('idempotency_key_reused', $this->redeem($member, self::body($id, 'άλλη σημείωση'))->get_data()['outcome']);
        $again = $this->redeem($member, self::body(self::uuid4()));
        $this->assertSame(200, $again->get_status());
        $this->assertSame('benefit_already_redeemed', $again->get_data()['outcome']);
        $this->assertSame('do_not_prepare', $again->get_data()['instruction']);
        $this->assertSame(1, (int) $this->db()->fetch_one('SELECT COUNT(*) AS n FROM evc_redemptions')['n']);
        foreach ($this->log as $line) {
            $this->assertStringNotContainsString('τραπέζι', $line);
        }
    }

    public function test_refusals_are_domain_bodies_and_body_keys_never_carry_authority(): void {
        $this->use_backend();
        list(, $member) = $this->member();
        $this->login_as($this->create_staff_user());
        $cases = array(
            'note too long' => array(self::body(self::uuid4(), str_repeat('α', 81)), 'invalid_preparation'),
            'html note' => array(self::body(self::uuid4(), '<b>x</b>'), 'invalid_preparation'),
            'staff id in the body' => array(self::body(self::uuid4()) + array('staff_wp_user_id' => 1), 'invalid_request'),
            'date in the body' => array(self::body(self::uuid4()) + array('business_date' => '2026-11-19'), 'invalid_request'),
            'stale catalogue' => array(array('catalogue_version' => 1) + self::body(self::uuid4()), 'catalogue_version_stale'),
            'size on a Greek coffee' => array(array('coffee_code' => 'greek_coffee', 'preparation' => array('modifiers' => array(array('group' => 'size', 'option' => 'large'), array('group' => 'shots', 'option' => 'single'), array('group' => 'sweetness', 'option' => 'none')), 'quick_notes' => array())) + self::body(self::uuid4()), 'invalid_preparation'),
            'unknown coffee' => array(array('coffee_code' => 'latte') + self::body(self::uuid4()), 'invalid_coffee'),
        );
        foreach ($cases as $label => list($body, $outcome)) {
            $response = $this->redeem($member, $body);
            $this->assertSame(200, $response->get_status(), $label);
            $this->assertSame($outcome, $response->get_data()['outcome'], $label);
            $this->assertSame('do_not_prepare', $response->get_data()['instruction'], $label);
        }
        $this->assertSame('member_not_found', $this->redeem(EVC_Member_Id::mint(), self::body(self::uuid4()))->get_data()['outcome']);
        $this->assertSame('invalid_request', $this->redeem($member, array('nope'))->get_data()['outcome']);
        $this->assertSame(0, (int) $this->db()->fetch_one('SELECT COUNT(*) AS n FROM evc_redemptions')['n']);
    }

    public function test_below_schema_4_the_route_answers_prepared_unavailable_and_writes_nothing(): void {
        $old = $this->database(3);
        $backend = $this->use_backend($this->environment($old->config()));
        $this->club = $old;
        list(, $member) = $this->member();
        $this->login_as($this->create_staff_user());
        $this->assertEnvelope($this->redeem($member, self::body(self::uuid4())), 409, 'prepared_unavailable');
        $this->assertSame(EVC_Club_Prepared_Redemption_Backend::REASON_SCHEMA, $backend->last_reason());
        $this->assertSame(0, (int) $old->connect()->fetch_one('SELECT COUNT(*) AS n FROM evc_redemptions')['n']);
    }

    public function test_only_the_staff_account_with_a_nonce_may_redeem(): void {
        $this->use_backend();
        list($user, $member) = $this->member();
        $this->assertEnvelope($this->rest('POST', self::route($member), self::body(self::uuid4()), ''), 401, 'unauthorized');
        $this->login_as($user);
        $this->assertEnvelope($this->redeem($member, self::body(self::uuid4())), 403, 'forbidden');
        $this->login_as($this->create_user_with_role('administrator'));
        $this->assertEnvelope($this->redeem($member, self::body(self::uuid4())), 403, 'forbidden');
        $this->assertSame(0, $this->connects, 'nothing is built before the staff check');
    }

    // ---- the lookup reply with history ----------------------------------------------------------------------------

    public function test_the_lookup_with_history_shows_only_this_members_coffees_and_the_old_reply_is_unchanged(): void {
        $this->use_backend();
        $this->use_lookup();
        list(, $a, $token_a) = $this->member();
        list(, $b, $token_b) = $this->member();
        $this->login_as($this->create_staff_user());
        $this->assertSame('redeemed', $this->redeem($b, self::body(self::uuid4(), 'σημείωση του Β'))->get_data()['outcome']);
        $this->clock->set('2026-11-19T10:00:00Z');
        $this->assertSame('redeemed', $this->redeem($a, self::body(self::uuid4(), 'για την Ελένη'))->get_data()['outcome']);
        $this->clock->set('2026-11-20T10:00:00Z');

        $old = $this->rest('POST', self::LOOKUP, array('method' => 'qr', 'qr_token' => $token_a), $this->nonce());
        $this->assertSame(array('member', 'status'), array_keys($old->get_data()), 'older tills get exactly the old reply');

        $new = $this->rest('POST', self::LOOKUP, array('method' => 'qr', 'qr_token' => $token_a, 'history' => true), $this->nonce());
        $this->assertSame(200, $new->get_status(), wp_json_encode($new->get_data()));
        $data = $new->get_data();
        $this->assertSame(array('member', 'status', 'prepared', 'recent_coffees'), array_keys($data));
        $this->assertTrue($data['prepared']);
        $this->assertSame('active', $data['status']);
        $this->assertCount(1, $data['recent_coffees']);
        $item = $data['recent_coffees'][0];
        $this->assertSame('2026-11-19', $item['business_date']);
        $this->assertSame('Ελληνικός', $item['coffee_label']);
        $this->assertSame(array('Τριπλός', 'Γλυκός', 'Λευκή ζάχαρη', 'Πολύ ζεστός'), $item['details']);
        $this->assertSame('για την Ελένη', $item['note']);
        $this->assertSame('greek_coffee', $item['prefill']['coffee_code']);
        $encoded = wp_json_encode($data, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('του Β', $encoded);
        $this->assertStringNotContainsString($b, $encoded);
        $this->assertStringNotContainsString('staff', $encoded);

        foreach (array(false, 'true', 1, null) as $bad) {
            $this->assertEnvelope($this->rest('POST', self::LOOKUP, array('method' => 'qr', 'qr_token' => $token_a, 'history' => $bad), $this->nonce()), 400, 'invalid_request');
        }
        $this->assertSame(array('member', 'status', 'prepared', 'recent_coffees'), array_keys($this->rest('POST', self::LOOKUP, array('method' => 'qr', 'qr_token' => $token_b, 'history' => true), $this->nonce())->get_data()));
    }

    public function test_below_schema_4_the_lookup_says_not_prepared_and_shows_coffee_names_only(): void {
        $old = $this->database(3);
        $this->club = $old;
        $this->use_lookup($this->environment($old->config()));
        list(, $member, $token) = $this->member();
        $member_id = (int) $old->connect()->fetch_one('SELECT id FROM evc_members WHERE member_public_id = ?', array($member))['id'];
        $old->connect()->execute(
            'INSERT INTO evc_redemptions (member_id, benefit_type, business_date, coffee_code, request_id, request_fingerprint, staff_wp_user_id,'
            . ' membership_source, membership_status, membership_level_ref, membership_expires_at_utc, redeemed_at_utc) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            array($member_id, 'free_coffee', '2026-11-18', 'other_coffee', self::uuid4(), str_repeat('a', 64), 77, 'mock', 'active', null, '2026-12-20 00:00:00.000000', '2026-11-18 09:00:00.000000')
        );
        $this->login_as($this->create_staff_user());
        $data = $this->rest('POST', self::LOOKUP, array('method' => 'qr', 'qr_token' => $token, 'history' => true), $this->nonce())->get_data();
        $this->assertFalse($data['prepared']);
        $this->assertSame(array(array('business_date' => '2026-11-18', 'coffee_label' => 'Άλλος καφές', 'details' => array(), 'note' => null,
            'prefill' => array('catalogue_version' => 2, 'coffee_code' => 'other_coffee', 'modifiers' => array(), 'quick_notes' => array(), 'note' => null),
            'prefill_partial' => false)), $data['recent_coffees']);
    }
}
