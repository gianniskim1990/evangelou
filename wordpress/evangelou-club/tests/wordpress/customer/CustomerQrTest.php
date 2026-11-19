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
require_once dirname(__DIR__, 2) . '/support/class-evc-faulty-pdo.php';

final class CustomerQrTest extends EVC_WP_Test_Case {
    const QR = '/evangelou-club-customer/v1/me/qr';
    /** Owner decision D23: the member card's own coffee figures. */
    const COFFEES = '/evangelou-club-customer/v1/me/coffees';
    /** Test-only derivation secret (D22); a real site keeps its own in wp-config.php. */
    const SECRET = 'evc-test-only-qr-secret-0123456789abcdef';
    const LOOKUP = 'evc-test-only-lookup-key-0123456789abcdef';
    /** The placeholder of docs/member-qr.md (round 8, NIT-1: refused). */
    const DOCS_PLACEHOLDER = '...τουλάχιστον 32 τυχαίοι χαρακτήρες, ΔΙΑΦΟΡΕΤΙΚΟ από το EVC_CLUB_LOOKUP_KEY...';

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

    private function use_backend(?array $environment = null, ?callable $connect = null): EVC_Customer_Qr_Backend {
        $environment = $environment ?? $this->environment();
        $backend = new EVC_Customer_Qr_Backend(
            function () use ($environment) {
                return $environment;
            },
            function (EVC_Db_Config $config) use ($connect) {
                $this->connects++;
                return $connect === null ? EVC_Club_Db::connect($config) : $connect($config);
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
        foreach (array(array('qr_secret' => null), array('qr_secret' => ''), array('qr_secret' => str_repeat('k', 31)), array('qr_secret' => '0123456789abcdefghijklmnopqrstu'), array('qr_secret' => 12345678901234567890123456789012), array('qr_secret' => self::LOOKUP), array('qr_secret' => str_repeat('k', 64)), array('qr_secret' => self::DOCS_PLACEHOLDER)) as $override) {
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
        $this->assertTrue(EVC_Qr_Token::is_derivation_secret('0123456789abcdef0123456789abcdef'), '32 bytes, 16 different');
        $this->assertTrue(EVC_Qr_Token::is_derivation_secret(bin2hex(random_bytes(32))), 'the generation command of go-live.md');
        // Round 8 (NIT-1): obviously non-random values fail closed.
        foreach (array(str_repeat('k', 32), str_repeat('k', 200), str_repeat('abc', 20), str_repeat('0123456', 10), self::DOCS_PLACEHOLDER, 'evc-test-only-qr-secret-0123456789abcdef...') as $weak) {
            $this->assertFalse(EVC_Qr_Token::is_derivation_secret($weak), $weak);
        }
        $this->assertTrue(EVC_Qr_Token::is_derivation_secret('01234567' . str_repeat('k', 24)), 'eight different bytes are enough');
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

    // ---- STAGE-08 round 8 -------------------------------------------------------------------------------------

    /** A connection whose FIRST statement matching $pattern lets $other act first (another device, another writer). */
    private function connect_with_writer_before(string $pattern, callable $other): callable {
        return function (EVC_Db_Config $config) use ($pattern, $other) {
            $pdo = $this->club->faulty_pdo();
            $pdo->before_prepare_matching = $pattern;
            $pdo->before_prepare = $other;
            return new EVC_Club_Db($pdo);
        };
    }

    /** One QR rotation by tests/bin/qr-token-worker.php: its own PHP process and connection, committed when it returns. */
    private function worker_rotate(int $member_id, ?string $expected, string $new_hash): string {
        $job = array('database' => $this->club->name(), 'op' => 'rotate', 'member_id' => $member_id, 'expected' => $expected, 'new_hash' => $new_hash, 'start_at' => 0);
        $proc = proc_open(array(PHP_BINARY, dirname(__DIR__, 2) . '/bin/qr-token-worker.php', json_encode($job)), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
        $this->assertIsResource($proc);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame(0, proc_close($proc), 'worker stderr: ' . $err);
        return (string) (json_decode(trim((string) $out), true)['outcome'] ?? ('bad output: ' . $out . $err));
    }

    /** LOW-1: two devices ask for the FIRST QR at the same moment; the one that loses the race gets the same token. */
    public function test_round8_a_concurrent_first_issue_by_another_device_gives_this_device_the_same_token(): void {
        $user = $this->customer();
        $member = $this->member($user);
        $stable = EVC_Qr_Token::derive(self::SECRET, $this->public_id($member), 1);
        $outcome = null;
        // This device read "no QR" and derived generation 1; before its rotation locks the row, the other device's
        // process stores the same generation-1 token and commits.
        $this->use_backend(null, $this->connect_with_writer_before('/FOR UPDATE/', function () use ($member, $stable, &$outcome) {
            $outcome = $this->worker_rotate($member, null, EVC_Qr_Token::digest($stable));
        }));
        $this->login_as($user);
        $r = $this->rest('POST', self::QR, array(), $this->nonce());
        $this->assertSame('rotated', $outcome, 'the other device won the race');
        $this->assertSame(200, $r->get_status(), 'the loser reads again (STABLE_TRIES = 2): ' . wp_json_encode($r->get_data()));
        $this->assertSame($stable, $r->get_data()['qr']['token'], 'the SAME token as the other device');
        $this->assertSame(array(array('token_hash' => EVC_Qr_Token::digest($stable), 'status' => 'active')), $this->rows($member), 'one row, nothing replaced');
    }

    /** LOW-1: a stored active row is handed out only if it is the member's own row (checked, never assumed). */
    public function test_round8_an_active_row_that_is_not_the_members_own_is_never_handed_out(): void {
        $this->use_backend();
        $a = $this->customer();
        $ma = $this->member($a);
        $mb = $this->member($this->customer());
        $token = $this->device_token($a);
        // Between the active-row read and the row check, the row moves to another member (a writer outside the
        // plugin: nothing shipped changes member_id; the check must still hold).
        $this->use_backend(null, $this->connect_with_writer_before('/WHERE token_hash = \?/', function () use ($mb, $token) {
            $this->db()->execute('UPDATE evc_qr_tokens SET member_id = ? WHERE token_hash = ?', array($mb, EVC_Qr_Token::digest($token)));
        }));
        $r = $this->rest('POST', self::QR, array(), $this->nonce());
        $this->assertSame(409, $r->get_status(), wp_json_encode($r->get_data()));
        $this->assertStringNotContainsString($token, wp_json_encode($r->get_data()), 'the token of a row that is not A\'s own is never returned');
        $this->assertSame(array(), $this->rows($ma));
    }

    /** NIT-2: status() does not keep the derivation secret after the request, as issue() does not. */
    public function test_round8_the_secret_is_not_kept_after_a_get_or_a_post(): void {
        $backend = $this->use_backend();
        $user = $this->customer();
        $this->member($user);
        $this->login_as($user);
        $secret = new ReflectionProperty(EVC_Customer_Qr_Backend::class, 'secret');
        $secret->setAccessible(true);
        $this->assertSame('none', $this->rest('GET', self::QR, null, $this->nonce())->get_data()['qr']['state']);
        $this->assertNull($secret->getValue($backend), 'after GET /me/qr');
        $this->assertSame(200, $this->rest('POST', self::QR, array(), $this->nonce())->get_status());
        $this->assertNull($secret->getValue($backend), 'after POST /me/qr');
        $this->assertSame(200, $this->rest('GET', self::COFFEES, null, $this->nonce())->get_status());
        $this->assertNull($secret->getValue($backend), 'after GET /me/coffees');
    }

    private function delete_row(string $token): void {
        $this->assertSame(1, $this->db()->execute('DELETE FROM evc_qr_tokens WHERE token_hash = ?', array(EVC_Qr_Token::digest($token))));
    }

    /** LOW-2: rows must never be deleted, but if some were, the token stays the same and no revoked code comes back. */
    public function test_round8_deleted_rows_neither_change_the_stable_token_nor_bring_back_a_revoked_code(): void {
        $this->use_backend();
        $user = $this->customer();
        $member = $this->member($user);
        $store = new EVC_Sql_Qr_Token_Store($this->db());
        $tokens = array();
        for ($g = 1; $g <= 3; $g++) {
            $tokens[$g] = $this->device_token($user);
            $this->assertSame(EVC_Qr_Token::derive(self::SECRET, $this->public_id($member), $g), $tokens[$g]);
            if ($g < 3) {
                $store->revoke($member, EVC_Qr_Token::digest($tokens[$g]), '2026-11-04 10:3' . $g . ':00.000000');
            }
        }
        $this->delete_row($tokens[1]); // someone deleted an old row: the row count (2) is now below the generation (3)
        $this->assertSame($tokens[3], $this->device_token($user), 'the SAME token as before the deletion');
        $this->assertSame($tokens[3], $this->device_token($user));
        $this->assertCount(2, $this->rows($member), 'nothing written');

        $store->revoke($member, EVC_Qr_Token::digest($tokens[3]), '2026-11-04 11:00:00.000000');
        $next = $this->device_token($user);
        $this->assertSame(EVC_Qr_Token::derive(self::SECRET, $this->public_id($member), 4), $next, 'generation 3 (revoked, still stored) is skipped');
        $this->assertNotContains($next, $tokens, 'never a revoked code again');
        $this->assertSame($next, $this->device_token($user), 'and stable from then on');
        $this->assertSame(array('revoked', 'revoked', 'active'), array_column($this->rows($member), 'status'));
    }

    /** LOW-2: beyond GENERATION_PROBE deleted rows the member is stuck: 503 and a fixed log reason, never a loop of 409s. */
    public function test_round8_too_many_deleted_rows_are_reported_as_the_stuck_state(): void {
        $backend = $this->use_backend();
        $user = $this->customer();
        $member = $this->member($user);
        $public = $this->public_id($member);
        $db = $this->db();
        $n = 2 * (EVC_Customer_Qr_Backend::GENERATION_PROBE + 1);
        for ($g = 1; $g <= $n; $g++) {
            $db->execute("INSERT INTO evc_qr_tokens (member_id, token_hash, status, issued_at_utc, revoked_at_utc) VALUES (?, ?, 'revoked', ?, ?)",
                array($member, EVC_Qr_Token::digest(EVC_Qr_Token::derive(self::SECRET, $public, $g)), '2026-11-01 00:00:00.000000', '2026-11-01 00:00:01.000000'));
        }
        for ($g = 1; $g <= EVC_Customer_Qr_Backend::GENERATION_PROBE + 1; $g++) {
            $this->delete_row(EVC_Qr_Token::derive(self::SECRET, $public, $g));
        }
        $this->login_as($user);
        $r = $this->rest('POST', self::QR, array(), $this->nonce());
        $this->assertSame(503, $r->get_status(), wp_json_encode($r->get_data()));
        $this->assertSame(array('result' => 'unavailable'), $r->get_data());
        $this->assertSame(EVC_Customer_Qr_Backend::REASON_GENERATION, $backend->last_reason());
        $this->assertContains('Evangelou Club: customer QR unavailable (qr_generation_stuck).', $this->log);
        $this->assertCount(EVC_Customer_Qr_Backend::GENERATION_PROBE + 1, $this->rows($member), 'nothing written');
    }

    // ---- Owner decision D23: the member card's own coffee figures ---------------------------------------------

    /** One ledger row as the redeem engine writes it: business_date = the Athens day of the redemption instant. */
    private function coffee(int $member_id, string $redeemed_at_utc, string $benefit = 'free_coffee'): void {
        $instant = new DateTimeImmutable($redeemed_at_utc, new DateTimeZone('UTC'));
        $this->db()->execute(
            'INSERT INTO evc_redemptions (member_id, benefit_type, business_date, coffee_code, request_id, request_fingerprint, staff_wp_user_id,'
            . ' membership_source, membership_status, membership_level_ref, membership_expires_at_utc, redeemed_at_utc) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            array($member_id, $benefit, EVC_Clock::business_date($instant), 'espresso', wp_generate_uuid4(), hash('sha256', uniqid('', true)), 1,
                'pmpro', 'active', null, '2027-01-01 00:00:00.000000', $instant->format('Y-m-d H:i:s.u'))
        );
    }

    private function coffees_of(int $user): array {
        wp_set_current_user(0);
        $this->login_as($user);
        $r = $this->rest('GET', self::COFFEES, null, $this->nonce());
        $this->assertSame(200, $r->get_status(), wp_json_encode($r->get_data()));
        $this->assertSame('no-store, private, max-age=0', $r->get_headers()['Cache-Control']);
        return $r->get_data();
    }

    private static function figures(string $today, bool $taken, int $month): array {
        return array('result' => 'ok', 'coffees' => array('state' => 'member', 'today' => $today, 'taken_today' => $taken, 'this_month' => $month));
    }

    public function test_d23_the_members_own_coffees_of_this_athens_day_and_month(): void {
        $this->use_backend();
        $a = $this->customer();
        $b = $this->customer();
        $ma = $this->member($a);
        $mb = $this->member($b);
        $this->coffee($ma, '2026-10-31 20:00:00'); // 22:00 Athens on 31 October: last month
        $this->coffee($ma, '2026-10-31 22:30:00'); // 00:30 Athens on 1 November (UTC still in October): this month
        $this->coffee($ma, '2026-11-18 07:00:00');
        $this->coffee($ma, '2026-11-19 06:15:00'); // today, 08:15 Athens
        $this->coffee($ma, '2026-11-19 06:20:00', 'other_benefit'); // not the free coffee: never counted
        $this->coffee($mb, '2026-11-10 09:00:00');
        $this->coffee($mb, '2026-11-12 09:00:00');
        $this->coffee($mb, '2026-11-14 09:00:00');

        $this->clock->set('2026-11-19T08:00:00Z');
        $this->assertSame(self::figures('2026-11-19', true, 3), $this->coffees_of($a));
        $this->assertSame(self::figures('2026-11-19', false, 3), $this->coffees_of($b), 'B\'s own figures only');

        // Athens midnight while UTC is still the 19th.
        $this->clock->set('2026-11-19T21:59:59Z');
        $this->assertSame(self::figures('2026-11-19', true, 3), $this->coffees_of($a), '23:59:59 Athens: still today');
        $this->clock->set('2026-11-19T22:00:00Z');
        $this->assertSame(self::figures('2026-11-20', false, 3), $this->coffees_of($a), '00:00 Athens: a new day, the same month');

        // Month boundaries, in Athens time.
        $this->clock->set('2026-10-31T21:59:59Z');
        $this->assertSame(self::figures('2026-10-31', true, 1), $this->coffees_of($a), '31 October Athens: only October');
        $this->clock->set('2026-10-31T22:45:00Z');
        $this->assertSame(self::figures('2026-11-01', true, 3), $this->coffees_of($a), '1 November 00:45 Athens (UTC still 31 October): the 00:30 coffee is today\'s; the month is November (counted by business date)');
        $this->clock->set('2026-11-30T22:00:00Z');
        $this->assertSame(self::figures('2026-12-01', false, 0), $this->coffees_of($a), '1 December Athens: a new month');
        $this->assertSame(array(), array_filter($this->log, function ($l) {
            return strpos($l, 'coffees') !== false;
        }), 'nothing failed');
    }

    public function test_d23_every_free_coffee_row_counts_as_the_engine_counts_it_and_the_route_is_read_only(): void {
        $this->use_backend();
        $user = $this->customer();
        $member = $this->member($user);
        $this->clock->set('2026-11-19T08:00:00Z');
        $this->assertSame(self::figures('2026-11-19', false, 0), $this->coffees_of($user), 'a member without coffees');
        for ($d = 1; $d <= 19; $d++) {
            $this->coffee($member, sprintf('2026-11-%02d 09:00:00', $d));
        }
        // The ledger has no reversal or void (no status column; the runtime user never deletes): the engine's
        // one-per-day key counts every row, and so does the card.
        $this->assertSame(self::figures('2026-11-19', true, 19), $this->coffees_of($user));
        $before = $this->db()->fetch_one('SELECT COUNT(*) AS n FROM evc_redemptions')['n'];
        foreach (array('POST', 'PUT', 'DELETE') as $method) {
            $this->assertSame(404, $this->rest($method, self::COFFEES, array(), $this->nonce())->get_status(), $method . ': no write route');
        }
        $this->assertSame($before, $this->db()->fetch_one('SELECT COUNT(*) AS n FROM evc_redemptions')['n']);
    }

    public function test_d23_not_a_member_disabled_signed_out_and_fail_soft(): void {
        $this->use_backend();
        $stranger = $this->customer();
        $this->assertSame(array('result' => 'ok', 'coffees' => array('state' => 'not_member')), $this->coffees_of($stranger));
        $disabled = $this->customer();
        $md = $this->member($disabled, 'disabled');
        $this->coffee($md, '2026-11-04 08:00:00');
        $this->assertSame(array('result' => 'ok', 'coffees' => array('state' => 'member_disabled')), $this->coffees_of($disabled));

        wp_set_current_user(0);
        $this->assertEnvelope($this->rest('GET', self::COFFEES), 401, 'unauthorized');
        $this->login_as($this->create_staff_user());
        $this->assertEnvelope($this->rest('GET', self::COFFEES, null, $this->nonce()), 403, 'forbidden');

        // No QR secret: the QR fails closed, the coffee figures do not need it.
        $user = $this->customer();
        $member = $this->member($user);
        $this->coffee($member, '2026-11-04 08:00:00');
        $this->use_backend(array_merge($this->environment(), array('qr_secret' => null)));
        $this->login_as($user);
        $this->assertSame(503, $this->rest('GET', self::QR, null, $this->nonce())->get_status());
        $this->assertSame(self::figures('2026-11-04', true, 1), $this->coffees_of($user));

        // The Club DB unreachable, too old, or a failing read: 503 {"result":"unavailable"} (the card shows «—»).
        $this->login_as($user);
        $cases = array(
            EVC_Customer_Qr_Backend::REASON_CONNECT => array(null, function () {
                throw new EVC_Db_Exception('Club database is unavailable.');
            }),
            EVC_Customer_Qr_Backend::REASON_SCHEMA => array($this->environment($this->database(array('001_initial.sql'))->config()), null),
            EVC_Customer_Qr_Backend::REASON_COFFEES => array(null, function (EVC_Db_Config $config) {
                $pdo = $this->club->faulty_pdo();
                $pdo->fail_prepare_matching = '/FROM evc_redemptions/';
                return new EVC_Club_Db($pdo);
            }),
            EVC_Customer_Qr_Backend::REASON_PROVENANCE => array(array_merge($this->environment(), array('provenance' => null)), null),
        );
        foreach ($cases as $reason => $case) {
            $backend = $this->use_backend($case[0], $case[1]);
            $r = $this->rest('GET', self::COFFEES, null, $this->nonce());
            $this->assertSame(503, $r->get_status(), $reason);
            $this->assertSame(array('result' => 'unavailable'), $r->get_data(), $reason);
            $this->assertSame($reason, $backend->last_reason());
        }
    }
}
