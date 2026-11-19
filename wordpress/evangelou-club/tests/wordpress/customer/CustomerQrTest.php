<?php

/**
 * STAGE-05C: the customer's own QR (GET / POST evangelou-club-customer/v1/me/qr) in real WordPress,
 * customer flag ON, EVC_CLUB_PAYMENT_PROVENANCE UNDEFINED (this process, the shipped default):
 *  - the shipped wiring registers no QR route at all;
 *  - through the backend's TEST seams (environment / connect / clock / log) on a disposable, migrated
 *    Club DB: every gate, own-account only, owner decision D22 (the SAME token on every device, never
 *    replaced by the customer; a new one only after an administrator's revocation; an older random
 *    token replaced once), fail closed without EVC_CLUB_QR_SECRET, the plaintext only at POST, no token
 *    or secret in logs.
 * The real wiring with the constant set is in tests/wordpress/commerce (CI only).
 */
final class CustomerQrTest extends EVC_WP_Test_Case {
    const QR = '/evangelou-club-customer/v1/me/qr';
    /** Test-only derivation secret (D22); a real site keeps its own in wp-config.php. */
    const SECRET = 'evc-test-only-qr-secret-0123456789abcdef';
    const LOOKUP = 'evc-test-only-lookup-key-0123456789abcdef';

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
        (new EVC_Migrator($db->connect(), $dir))->migrate(PHP_INT_MAX);
        return $db;
    }

    private function environment(?EVC_Db_Config $db = null): array {
        if ($db === null) {
            $this->club = $this->club ?? $this->database();
            $db = $this->club->config();
        }
        return array('customer' => true, 'provenance' => 'club_db_v1', 'db' => $db, 'qr_secret' => self::SECRET, 'lookup_key' => self::LOOKUP);
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

    private function public_id(int $member_id): string {
        return (string) $this->db()->fetch_one('SELECT member_public_id FROM evc_members WHERE id = ?', array($member_id))['member_public_id'];
    }

    /** POST /me/qr as a fresh "device": a new login session and its own nonce. */
    private function device_token(int $user): string {
        wp_set_current_user(0);
        $this->login_as($user);
        $r = $this->rest('POST', self::QR, array(), $this->nonce());
        $this->assertSame(200, $r->get_status(), wp_json_encode($r->get_data()));
        return $r->get_data()['qr']['token'];
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

    public function test_d22_the_same_token_on_every_device_and_never_read_back_by_get(): void {
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
        $this->assertSame(EVC_Qr_Token::derive(self::SECRET, $this->public_id($member), 1), $qr['token'], 'derived: generation 1');
        $this->assertSame('2026-11-04T12:00:00+02:00', $qr['issued_at'], 'Athens time with offset');
        $this->assertSame('no-store, private, max-age=0', $first->get_headers()['Cache-Control']);
        $this->assertSame(array(array('token_hash' => EVC_Qr_Token::digest($qr['token']), 'status' => 'active')), $this->rows($member), 'only the digest is stored');

        $status = $this->rest('GET', self::QR, null, $this->nonce());
        $this->assertSame(array('result' => 'ok', 'qr' => array('state' => 'active', 'issued_at' => '2026-11-04T12:00:00+02:00')), $status->get_data(), 'never the token in a GET');

        $this->clock->set('2027-11-04T10:00:00Z'); // D6: no age limit
        $phone = $this->device_token($user);
        $laptop = $this->device_token($user);
        $this->assertSame($qr['token'], $phone, 'a second device gets the SAME token');
        $this->assertSame($qr['token'], $laptop, 'and a third');
        // Older cached apps may still send replace: accepted, ignored (a customer never revokes).
        foreach (array(array('replace' => true), array('replace' => false)) as $body) {
            $r = $this->rest('POST', self::QR, $body, $this->nonce());
            $this->assertSame(200, $r->get_status());
            $this->assertSame($qr['token'], $r->get_data()['qr']['token'], wp_json_encode($body));
            $this->assertSame('2026-11-04T12:00:00+02:00', $r->get_data()['qr']['issued_at'], 'the issue time of the stored row');
        }
        $this->assertSame(array(array('token_hash' => EVC_Qr_Token::digest($qr['token']), 'status' => 'active')), $this->rows($member), 'nothing was written again');
        $dump = implode("\n", $this->log);
        $this->assertStringNotContainsString($qr['token'], $dump);
        $this->assertStringNotContainsString(self::SECRET, $dump);
    }

    public function test_d22_only_an_administrators_revocation_gives_a_new_token_and_it_then_stays(): void {
        $this->use_backend();
        $user = $this->customer();
        $member = $this->member($user);
        $first = $this->device_token($user);
        (new EVC_Sql_Qr_Token_Store($this->db()))->revoke($member, EVC_Qr_Token::digest($first), '2026-11-04 10:30:00.000000');
        $this->assertSame('none', $this->rest('GET', self::QR, null, $this->nonce())->get_data()['qr']['state']);
        $second = $this->device_token($user);
        $this->assertNotSame($first, $second, 'a new code after the revocation');
        $this->assertSame(EVC_Qr_Token::derive(self::SECRET, $this->public_id($member), 2), $second, 'generation 2');
        $this->assertSame($second, $this->device_token($user), 'and then the same on every device again');
        $this->assertSame(array('revoked', 'active'), array_column($this->rows($member), 'status'));
    }

    public function test_d22_an_older_random_token_is_replaced_by_the_stable_one_exactly_once(): void {
        $this->use_backend();
        $user = $this->customer();
        $member = $this->member($user);
        $legacy = EVC_Qr_Token::mint();
        (new EVC_Sql_Qr_Token_Store($this->db()))->rotate($member, null, EVC_Qr_Token::digest($legacy), '2026-11-03 08:00:00.000000');
        $stable = $this->device_token($user);
        $this->assertNotSame($legacy, $stable);
        $this->assertSame(EVC_Qr_Token::derive(self::SECRET, $this->public_id($member), 2), $stable);
        $this->assertSame(array(array('token_hash' => EVC_Qr_Token::digest($legacy), 'status' => 'revoked'), array('token_hash' => EVC_Qr_Token::digest($stable), 'status' => 'active')), $this->rows($member));
        $this->assertSame($stable, $this->device_token($user));
        $this->assertSame($stable, $this->device_token($user));
        $this->assertCount(2, $this->rows($member), 'replaced once, never again');
    }

    public function test_d22_a_member_never_receives_another_members_token(): void {
        $this->use_backend();
        $a = $this->customer();
        $b = $this->customer();
        $ma = $this->member($a);
        $mb = $this->member($b);
        $ta = $this->device_token($a);
        $tb = $this->device_token($b);
        $this->assertNotSame($ta, $tb);
        $this->assertSame(EVC_Qr_Token::derive(self::SECRET, $this->public_id($ma), 1), $ta);
        $this->assertSame(EVC_Qr_Token::derive(self::SECRET, $this->public_id($mb), 1), $tb);
        $this->assertSame($tb, $this->device_token($b), 'B again: still B\'s own');
        $this->assertSame(array(array('token_hash' => EVC_Qr_Token::digest($ta), 'status' => 'active')), $this->rows($ma), 'A untouched by B');
    }

    public function test_d22_without_a_valid_secret_the_route_fails_closed_before_any_connection(): void {
        $user = $this->customer();
        $member = $this->member($user);
        $this->login_as($user);
        $base = $this->environment();
        foreach (array(array('qr_secret' => null), array('qr_secret' => ''), array('qr_secret' => str_repeat('k', 31)), array('qr_secret' => 12345678901234567890123456789012), array('qr_secret' => self::LOOKUP)) as $override) {
            $this->connects = 0;
            $backend = $this->use_backend(array_merge($base, $override));
            foreach (array(array('GET', null), array('POST', array())) as $call) {
                $r = $this->rest($call[0], self::QR, $call[1], $this->nonce());
                $this->assertSame(503, $r->get_status(), wp_json_encode($override));
                $this->assertSame(array('result' => 'unavailable'), $r->get_data());
            }
            $this->assertSame(EVC_Customer_Qr_Backend::REASON_QR_SECRET, $backend->last_reason());
            $this->assertSame(0, $this->connects, 'no connection without the secret');
        }
        $this->assertSame(array(), $this->rows($member), 'nothing written');
        $this->assertTrue(EVC_Qr_Token::is_derivation_secret(str_repeat('k', 32)));
        $this->assertTrue(EVC_Qr_Token::is_derivation_secret(self::SECRET, self::LOOKUP));
        $this->assertFalse(EVC_Qr_Token::is_derivation_secret(self::LOOKUP, self::LOOKUP), 'never the lookup key');
        $dump = implode("\n", $this->log);
        $this->assertStringNotContainsString(self::LOOKUP, $dump);
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
        $this->assertEnvelope($this->rest('POST', self::QR, array(), $this->nonce()), 403, 'member_disabled');

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
