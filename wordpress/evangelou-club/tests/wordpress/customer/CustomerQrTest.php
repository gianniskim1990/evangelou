<?php

/**
 * STAGE-05C: the customer's own QR (GET / POST evangelou-club-customer/v1/me/qr) in real WordPress,
 * customer flag ON, EVC_CLUB_PAYMENT_PROVENANCE UNDEFINED (this process, the shipped default):
 *  - the shipped wiring registers no QR route at all;
 *  - through the backend's TEST seams (environment / connect / clock / log) on a disposable, migrated
 *    Club DB: every gate, own-account only, first QR, D6 "same until a new one is asked for",
 *    replacement, the plaintext only at issue, no token in logs.
 * The real wiring with the constant set is in tests/wordpress/commerce (CI only).
 */
final class CustomerQrTest extends EVC_WP_Test_Case {
    const QR = '/evangelou-club-customer/v1/me/qr';

    /** @var EVC_Test_Database[] */
    private $databases = array();
    /** @var EVC_Test_Database|null */
    private $club;
    /** @var string[] */
    private $log = array();
    /** @var int */
    private $connects = 0;
    /** @var EVC_Fixed_Clock */
    private $clock;

    public function set_up() {
        parent::set_up();
        $this->clock = new EVC_Fixed_Clock('2026-11-04T10:00:00Z');
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
        return array('customer' => true, 'provenance' => 'club_db_v1', 'db' => $db);
    }

    private function use_backend(?array $environment = null): EVC_Customer_Qr_Backend {
        $environment = $environment ?? $this->environment();
        $backend = new EVC_Customer_Qr_Backend(
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
            }
        );
        (new EVC_Rest_Me_Qr_Controller($backend))->register_routes(true);
        return $backend;
    }

    private function db(): EVC_Club_Db {
        $this->club = $this->club ?? $this->database();
        return $this->club->connect();
    }

    private function customer(): int {
        if (!get_role('customer')) {
            add_role('customer', 'Customer', array('read' => true));
        }
        return (int) self::factory()->user->create(array('role' => 'customer', 'user_pass' => self::PASSWORD, 'first_name' => 'Ελένη', 'user_email' => 'q.' . wp_generate_password(6, false, false) . '@example.org'));
    }

    private function member(int $wp_user_id, string $status = 'enabled'): int {
        $db = $this->db();
        $db->execute('INSERT INTO evc_members (member_public_id, wp_user_id, status, created_at_utc, updated_at_utc) VALUES (?, ?, ?, ?, ?)', array(EVC_Member_Id::mint(), $wp_user_id, $status, '2026-11-01 00:00:00.000000', '2026-11-01 00:00:00.000000'));
        return (int) $db->fetch_one('SELECT id FROM evc_members WHERE wp_user_id = ?', array($wp_user_id))['id'];
    }

    private function rows(int $member_id): array {
        return $this->db()->fetch_all('SELECT token_hash, status FROM evc_qr_tokens WHERE member_id = ? ORDER BY id', array($member_id));
    }

    public function test_without_the_provenance_constant_no_qr_route_exists(): void {
        $this->assertFalse(defined('EVC_CLUB_PAYMENT_PROVENANCE'));
        $this->assertFalse(EVC_Customer_Qr_Backend::provenance_recorded());
        $this->assertArrayNotHasKey(self::QR, rest_get_server()->get_routes());
        $this->login_as($this->customer());
        $this->assertSame(404, $this->rest('POST', self::QR, array(), $this->nonce())->get_status());
    }

    public function test_the_first_qr_is_issued_once_kept_until_a_new_one_is_asked_for_and_never_read_back(): void {
        $this->use_backend();
        $user = $this->customer();
        $member = $this->member($user);
        $this->login_as($user);

        $none = $this->rest('GET', self::QR, null, $this->nonce());
        $this->assertSame(array('result' => 'ok', 'qr' => array('state' => 'none')), $none->get_data());

        $first = $this->rest('POST', self::QR, array(), $this->nonce());
        $this->assertSame(200, $first->get_status(), wp_json_encode($first->get_data()));
        $qr = $first->get_data()['qr'];
        $this->assertSame(array('state', 'token', 'issued_at'), array_keys($qr));
        $this->assertTrue(EVC_Qr_Token::is_valid_format($qr['token']));
        $this->assertSame('2026-11-04T12:00:00+02:00', $qr['issued_at'], 'Athens time with offset');
        $this->assertSame('no-store, private, max-age=0', $first->get_headers()['Cache-Control']);
        $this->assertSame(array(array('token_hash' => EVC_Qr_Token::digest($qr['token']), 'status' => 'active')), $this->rows($member), 'only the digest is stored');

        $status = $this->rest('GET', self::QR, null, $this->nonce());
        $this->assertSame(array('result' => 'ok', 'qr' => array('state' => 'active', 'issued_at' => '2026-11-04T12:00:00+02:00')), $status->get_data(), 'never the token again');

        $this->clock->set('2027-11-04T10:00:00Z'); // D6: no age limit
        $again = $this->rest('POST', self::QR, array(), $this->nonce());
        $this->assertEnvelope($again, 409, 'qr_exists');
        $this->assertCount(1, $this->rows($member), 'nothing replaced without asking');
        $this->assertSame('active', $this->rest('GET', self::QR, null, $this->nonce())->get_data()['qr']['state']);

        $new = $this->rest('POST', self::QR, array('replace' => true), $this->nonce());
        $this->assertSame(200, $new->get_status());
        $token = $new->get_data()['qr']['token'];
        $this->assertNotSame($qr['token'], $token);
        $rows = $this->rows($member);
        $this->assertSame(array('revoked', 'active'), array_column($rows, 'status'));
        $this->assertSame(EVC_Qr_Token::digest($token), $rows[1]['token_hash']);

        $false = $this->rest('POST', self::QR, array('replace' => false), $this->nonce());
        $this->assertEnvelope($false, 409, 'qr_exists');
        $dump = implode("\n", $this->log);
        $this->assertStringNotContainsString($qr['token'], $dump);
        $this->assertStringNotContainsString($token, $dump);
    }

    public function test_after_a_revocation_the_next_request_gets_a_new_qr_without_replace(): void {
        $this->use_backend();
        $user = $this->customer();
        $member = $this->member($user);
        $this->login_as($user);
        $first = $this->rest('POST', self::QR, array(), $this->nonce())->get_data()['qr']['token'];
        (new EVC_Sql_Qr_Token_Store($this->db()))->revoke($member, EVC_Qr_Token::digest($first), '2026-11-04 10:30:00.000000');
        $this->assertSame('none', $this->rest('GET', self::QR, null, $this->nonce())->get_data()['qr']['state']);
        $second = $this->rest('POST', self::QR, array(), $this->nonce());
        $this->assertSame(200, $second->get_status());
        $this->assertNotSame($first, $second->get_data()['qr']['token']);
    }

    public function test_only_an_existing_enabled_member_and_only_their_own_qr(): void {
        $this->use_backend();
        $stranger = $this->customer();
        $this->login_as($stranger);
        $this->assertSame(array('result' => 'ok', 'qr' => array('state' => 'not_member')), $this->rest('GET', self::QR, null, $this->nonce())->get_data());
        $this->assertEnvelope($this->rest('POST', self::QR, array(), $this->nonce()), 404, 'not_a_member');

        $disabled = $this->customer();
        $this->member($disabled, 'disabled');
        $this->login_as($disabled);
        $this->assertSame('member_disabled', $this->rest('GET', self::QR, null, $this->nonce())->get_data()['qr']['state']);
        $this->assertEnvelope($this->rest('POST', self::QR, array('replace' => true), $this->nonce()), 403, 'member_disabled');

        $owner = $this->customer();
        $member = $this->member($owner);
        $this->login_as($stranger);
        foreach (array(array('wp_user_id' => $owner), array('member_id' => 'x'), array('replace' => 'yes'), array('replace' => 1)) as $body) {
            $this->assertEnvelope($this->rest('POST', self::QR, $body, $this->nonce()), 400, 'invalid_request');
        }
        $this->assertEnvelope($this->rest('POST', self::QR, '{not json', $this->nonce()), 400, 'invalid_request');
        $this->assertSame(array(), $this->rows($member), 'nobody else can create or replace the owner\'s QR');
    }

    public function test_only_customer_accounts_with_a_session_and_nonce(): void {
        $this->use_backend();
        $this->assertEnvelope($this->rest('GET', self::QR), 401, 'unauthorized');
        $user = $this->customer();
        $this->member($user);
        $this->login_as($user);
        $this->assertEnvelope($this->rest('POST', self::QR, array()), 401, 'unauthorized');
        $this->login_as($this->create_staff_user());
        $this->assertEnvelope($this->rest('POST', self::QR, array(), $this->nonce()), 403, 'forbidden');
        $this->login_as($this->create_user_with_role('administrator'));
        $this->assertEnvelope($this->rest('GET', self::QR, null, $this->nonce()), 403, 'forbidden');
    }

    public function test_every_missing_piece_is_unavailable_without_a_connection(): void {
        $user = $this->customer();
        $this->member($user);
        $this->login_as($user);
        $base = $this->environment();
        foreach (array(
            EVC_Customer_Qr_Backend::REASON_CUSTOMER => array('customer' => 1),
            EVC_Customer_Qr_Backend::REASON_PROVENANCE => array('provenance' => 'CLUB_DB_V1'),
            EVC_Customer_Qr_Backend::REASON_DB_CONFIG => array('db' => null),
        ) as $reason => $override) {
            $this->connects = 0;
            $backend = $this->use_backend(array_merge($base, $override));
            foreach (array(array('GET', null), array('POST', array())) as $call) {
                $r = $this->rest($call[0], self::QR, $call[1], $this->nonce());
                $this->assertSame(503, $r->get_status(), $reason);
                $this->assertSame(array('result' => 'unavailable'), $r->get_data(), $reason);
            }
            $this->assertSame($reason, $backend->last_reason());
            $this->assertSame(0, $this->connects, $reason . ': no connection');
        }
        $backend = $this->use_backend($this->environment($this->database(array('001_initial.sql'))->config()));
        $this->assertSame(503, $this->rest('POST', self::QR, array(), $this->nonce())->get_status());
        $this->assertSame(EVC_Customer_Qr_Backend::REASON_SCHEMA, $backend->last_reason());
        foreach ($this->log as $line) {
            $this->assertMatchesRegularExpression('/^Evangelou Club: customer QR unavailable \([a-z_]+(, [A-Za-z0-9_]+)?\)\.$/', $line);
        }
    }
}
