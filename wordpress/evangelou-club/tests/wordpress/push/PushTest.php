<?php

/**
 * STAGE-08 round 12 (owner decision D26 part 2): member web push in real WordPress (customer flag ON, EVC_CLUB_PUSH_ENABLED
 * ON with the TEST-ONLY VAPID keys of tests/wordpress/bootstrap.php), over a disposable, migrated Club DB through the
 * plugin's TEST seams (environment / connect / clock / log / HTTP / membership / timer):
 *  - /me/push: customer authorization, own-member only (A never touches B), the endpoint allowlist (SSRF), consent,
 *    schema below 5 and unusable keys answer 503 (never 500), no-store;
 *  - the sender: the RFC 8030 request (aes128gcm body that the subscriber's key decrypts, VAPID header), never a
 *    stored endpoint outside the allowlist, 404 / 410 revoke;
 *  - the administrator page: capability (never the till account), preview, confirmation, batches under a time budget,
 *    the lock, the rate limit, the audit (no endpoint);
 *  - the automatic notifications: once per member per period, the Athens 10:00 schedule.
 * The flag OFF case is in tests/wordpress/customer/PushOffTest.php.
 */
final class PushTest extends EVC_WP_Test_Case {
    const ME = '/evangelou-club-customer/v1/me/push';
    const PUBLIC = 'BP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8';
    const PRIVATE = 'yfWPiYE-n46HLnH0KqZOF1fJJU3MYrct3AELtAQ-oRw';
    const SUBJECT = 'mailto:club@evangelou-tests.gr';
    const FCM = 'https://fcm.googleapis.com/fcm/send/';

    /** @var EVC_Test_Database[] */
    private $databases = array();
    /** @var EVC_Test_Database|null */
    private $club;
    /** @var array<string,mixed> */
    private $env;
    /** @var string[] */
    private $log = array();
    /** @var array<int,array{url:string,headers:array,body:string}> */
    private $requests = array();
    /** @var array<string,int> endpoint => status */
    private $statuses = array();
    /** @var array<int,array{state:string,paid_until_utc:?string}> */
    private $memberships = array();
    /** @var float */
    private $now_seconds = 0.0;
    /** @var float seconds the timer moves on each call */
    private $tick = 0.0;
    /** @var EVC_Fixed_Clock */
    private $clock;
    /** @var array<string,string> endpoint => UA private scalar (for decryption) */
    private $ua_keys = array();
    /** @var array */
    private $parts;
    /** @var string[] */
    private $redirects = array();

    public function set_up() {
        parent::set_up();
        $this->clock = new EVC_Fixed_Clock('2026-11-21T08:00:00Z');
        $this->log = array();
        $this->requests = array();
        $this->statuses = array();
        $this->memberships = array();
        $this->tick = 0.0;
        $this->now_seconds = 1000.0;
        $this->redirects = array();
        $this->club = $this->database();
        $this->env = array(
            'push' => true,
            'customer' => true,
            'provenance' => 'club_db_v1',
            'db' => $this->club->config(),
            'vapid' => array('public' => self::PUBLIC, 'private' => self::PRIVATE, 'subject' => self::SUBJECT),
        );
        $this->wire();
        delete_option(EVC_Push_Campaign::JOB_OPTION);
        delete_option(EVC_Push_Campaign::AUDIT_OPTION);
        delete_option(EVC_Push_Auto::RUN_OPTION);
    }

    public function tear_down() {
        foreach ($this->databases as $db) {
            $db->drop();
        }
        $this->databases = array();
        parent::tear_down();
    }

    private function database(int $up_to = PHP_INT_MAX): EVC_Test_Database {
        $db = EVC_Test_Database::create();
        $this->databases[] = $db;
        (new EVC_Migrator($db->connect(), EVC_Test_Database::migrations_dir()))->migrate($up_to);
        return $db;
    }

    private function wire(): void {
        $this->parts = EVC_Push_Plugin::build(array(
            'environment' => function () {
                return $this->env;
            },
            'connect' => function (EVC_Db_Config $config) {
                return EVC_Club_Db::connect($config);
            },
            'clock' => $this->clock,
            'log' => function (string $line) {
                $this->log[] = $line;
            },
            'http' => function (string $url, array $headers, string $body): int {
                $this->requests[] = array('url' => $url, 'headers' => $headers, 'body' => $body);
                return $this->statuses[$url] ?? 201;
            },
            'time' => function (): int {
                return 1795000000;
            },
            'membership' => function (int $wp_user_id, DateTimeImmutable $now): array {
                return $this->memberships[$wp_user_id] ?? array('state' => 'none', 'paid_until_utc' => null);
            },
            'timer' => function (): float {
                $this->now_seconds += $this->tick;
                return $this->now_seconds;
            },
            'redirect' => function (string $url): void {
                $this->redirects[] = $url;
            },
        ));
        $this->parts['controller']->register_routes(true);
    }

    private function db(): EVC_Club_Db {
        return $this->club->connect();
    }

    private function customer(): int {
        if (!get_role('customer')) {
            add_role('customer', 'Customer', array('read' => true));
        }
        return (int) self::factory()->user->create(array('role' => 'customer', 'user_pass' => self::PASSWORD, 'user_email' => 'p.' . wp_generate_password(6, false, false) . '@example.org'));
    }

    private function member(int $wp_user_id, string $status = 'enabled'): int {
        $db = $this->db();
        $db->execute('INSERT INTO evc_members (member_public_id, wp_user_id, status, created_at_utc, updated_at_utc) VALUES (?, ?, ?, ?, ?)', array(EVC_Member_Id::mint(), $wp_user_id, $status, '2026-11-01 00:00:00.000000', '2026-11-01 00:00:00.000000'));
        return (int) $db->fetch_one('SELECT id FROM evc_members WHERE wp_user_id = ?', array($wp_user_id))['id'];
    }

    /** A real browser-like subscription: a fresh P-256 key pair and auth secret. */
    private function subscription(?string $endpoint = null): array {
        $key = openssl_pkey_new(array('curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC));
        $endpoint = $endpoint ?? self::FCM . bin2hex(random_bytes(12));
        $this->ua_keys[$endpoint] = str_pad(openssl_pkey_get_details($key)['ec']['d'], 32, "\0", STR_PAD_LEFT);
        return array('endpoint' => $endpoint, 'keys' => array('p256dh' => EVC_Push_Crypto::b64u_encode(EVC_Push_Crypto::point_of($key)), 'auth' => EVC_Push_Crypto::b64u_encode(random_bytes(16))));
    }

    private function subscribe_as(int $user, array $sub): WP_REST_Response {
        wp_set_current_user(0);
        $this->login_as($user);
        return $this->rest('POST', self::ME, $sub + array('consent' => true), $this->nonce());
    }

    private function rows(): array {
        return $this->db()->fetch_all('SELECT * FROM evc_push_subscriptions ORDER BY id');
    }

    private function admin(): int {
        $id = (int) self::factory()->user->create(array('role' => 'administrator'));
        get_userdata($id)->add_cap(EVC_Staff_Role::CAP_MANAGE);
        return $id;
    }

    private function page_post(array $fields): array {
        return $this->parts['page']->process($fields + array('_wpnonce' => wp_create_nonce(EVC_Push_Admin_Page::nonce_action((string) $fields['evc_push_action']))));
    }

    /** What the member's browser does with a delivered body (RFC 8291), for the tests only. */
    private function decrypt(string $endpoint, string $body, string $auth_b64): ?string {
        $salt = substr($body, 0, 16);
        $as_public = substr($body, 21, ord($body[20]));
        $rest = substr($body, 21 + ord($body[20]));
        $ua = EVC_Push_Crypto::private_key($this->ua_keys[$endpoint]);
        $ecdh = openssl_pkey_derive(EVC_Push_Crypto::public_key($as_public), $ua, 0);
        $ikm = hash_hkdf('sha256', $ecdh, 32, "WebPush: info\0" . EVC_Push_Crypto::point_of($ua) . $as_public, EVC_Push_Crypto::b64u_decode($auth_b64));
        $plain = openssl_decrypt(substr($rest, 0, -16), 'aes-128-gcm', hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt), OPENSSL_RAW_DATA, hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt), substr($rest, -16));
        return is_string($plain) && substr($plain, -1) === "\x02" ? substr($plain, 0, -1) : null;
    }

    // ---- wiring -----------------------------------------------------------------------------------------------

    public function test_the_flag_wires_the_route_the_menu_and_the_batch_hook(): void {
        $this->assertTrue(EVC_Push_Feature::enabled());
        $this->assertTrue(EVC_Push_Feature::ready(), 'the test keys are a usable pair');
        $parts = EVC_Push_Plugin::parts();
        $this->assertIsArray($parts, 'booted by plugins_loaded');
        $this->assertNotFalse(has_action('rest_api_init', array($parts['controller'], 'register_routes')));
        $this->assertNotFalse(has_action('admin_menu', array($parts['page'], 'add_menu')));
        $this->assertNotFalse(has_action(EVC_Push_Campaign::CRON_HOOK, array($parts['campaign'], 'cron')));
        $this->assertArrayHasKey(self::ME, rest_get_server()->get_routes());
        $this->assertSame(array('pushPublicKey' => self::PUBLIC), EVC_Push_Feature::page_config());
    }

    public function test_the_member_page_sends_the_public_key_and_never_the_private_one(): void {
        $user = $this->customer();
        wp_set_current_user($user);
        $dir = sys_get_temp_dir() . '/evc-push-assets-' . wp_generate_password(8, false, false);
        mkdir($dir . '/assets', 0777, true);
        file_put_contents($dir . '/assets/customer-Ab12Cd34.js', '/* test asset */');
        file_put_contents($dir . '/manifest.json', wp_json_encode(array('customer.html' => array('file' => 'assets/customer-Ab12Cd34.js', 'isEntry' => true))));
        try {
            $response = EVC_Customer_Shell::respond(wp_get_current_user(), new EVC_Staff_Assets($dir, 'https://example.org/x', EVC_Customer_Shell::ASSET_ENTRY));
        } finally {
            unlink($dir . '/assets/customer-Ab12Cd34.js');
            unlink($dir . '/manifest.json');
            rmdir($dir . '/assets');
            rmdir($dir);
        }
        $this->assertSame(200, $response['status']);
        $this->assertSame(1, preg_match('#<script type="application/json" id="evc-customer-config">(.*?)</script>#s', $response['body'], $m));
        $this->assertSame(self::PUBLIC, json_decode($m[1], true)['pushPublicKey']);
        $this->assertStringNotContainsString(self::PRIVATE, $response['body']);
    }

    // ---- /me/push: authorization and ownership ------------------------------------------------------------------

    public function test_only_a_signed_in_customer_with_a_valid_nonce_reaches_the_route(): void {
        $sub = $this->subscription();
        $this->assertSame(401, $this->rest('POST', self::ME, $sub + array('consent' => true))->get_status(), 'signed out');
        $user = $this->customer();
        $this->member($user);
        $this->login_as($user);
        $this->assertSame(401, $this->rest('POST', self::ME, $sub + array('consent' => true), 'bad-nonce')->get_status(), 'bad nonce');
        wp_set_current_user(0);
        $this->login_as($this->create_staff_user());
        $this->assertSame(403, $this->rest('POST', self::ME, $sub + array('consent' => true), $this->nonce())->get_status(), 'the till account');
        wp_set_current_user(0);
        $this->login_as($this->admin());
        $this->assertSame(403, $this->rest('GET', self::ME, null, $this->nonce())->get_status(), 'an administrator is not a customer');
        $this->assertSame(array(), $this->rows());
    }

    public function test_a_member_subscribes_with_consent_and_the_answer_is_no_store(): void {
        $user = $this->customer();
        $member = $this->member($user);
        $this->login_as($user);
        $status = $this->rest('GET', self::ME, null, $this->nonce());
        $this->assertSame(array('result' => 'ok', 'push' => array('state' => 'ready')), $status->get_data());
        $sub = $this->subscription();
        $request = new WP_REST_Request('POST', self::ME);
        $request->set_header('Content-Type', 'application/json');
        $request->set_header('X-WP-Nonce', $this->nonce());
        $request->set_header('User-Agent', 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Mobile Safari/537.36');
        $request->set_body(wp_json_encode($sub + array('consent' => true)));
        $_SERVER['HTTP_X_WP_NONCE'] = $this->nonce();
        $r = apply_filters('rest_post_dispatch', rest_ensure_response(rest_get_server()->dispatch($request)), rest_get_server(), $request);
        $this->assertSame(200, $r->get_status(), wp_json_encode($r->get_data()));
        $this->assertSame(array('result' => 'ok', 'push' => array('state' => 'subscribed')), $r->get_data());
        $this->assertSame('no-store, private, max-age=0', $r->get_headers()['Cache-Control']);
        $rows = $this->rows();
        $this->assertCount(1, $rows);
        $this->assertSame((string) $member, (string) $rows[0]['member_id']);
        $this->assertSame($sub['endpoint'], $rows[0]['endpoint']);
        $this->assertSame('fcm', $rows[0]['push_service']);
        $this->assertSame('chrome', $rows[0]['agent_family'], 'a coarse family, never the header');
        $this->assertSame('2026-11-21 08:00:00.000000', $rows[0]['consent_at_utc'], 'the tap is recorded');
        $this->assertStringNotContainsString($sub['endpoint'], implode("\n", $this->log));
    }

    public function test_member_a_cannot_take_or_stop_member_bs_subscription(): void {
        $a = $this->customer();
        $b = $this->customer();
        $this->member($a);
        $mb = $this->member($b);
        $sub = $this->subscription();
        $this->assertSame(200, $this->subscribe_as($b, $sub)->get_status());
        $taken = $this->subscribe_as($a, $this->subscription($sub['endpoint']));
        $this->assertEnvelope($taken, 409, 'endpoint_in_use');
        $this->assertSame((string) $mb, (string) $this->rows()[0]['member_id']);
        $this->assertSame($sub['keys']['p256dh'], $this->rows()[0]['p256dh'], 'B\'s keys untouched');
        // A's DELETE of B's endpoint answers "stopped" (it reveals nothing) and changes nothing.
        $stop = $this->rest('DELETE', self::ME, array('endpoint' => $sub['endpoint']), $this->nonce());
        $this->assertSame(array('result' => 'ok', 'push' => array('state' => 'stopped')), $stop->get_data());
        $this->assertNull($this->rows()[0]['revoked_at_utc'], 'A cannot stop B\'s notifications');
        wp_set_current_user(0);
        $this->login_as($b);
        $this->assertSame(200, $this->rest('DELETE', self::ME, array('endpoint' => $sub['endpoint']), $this->nonce())->get_status());
        $this->assertSame('member', $this->rows()[0]['revoked_reason']);
    }

    public function test_only_https_endpoints_of_the_push_services_and_valid_keys_are_accepted(): void {
        $user = $this->customer();
        $this->member($user);
        $this->login_as($user);
        $good = $this->subscription();
        $bad = array(
            'http' => array('endpoint' => 'http://fcm.googleapis.com/fcm/send/x') + $good,
            'other host' => array('endpoint' => 'https://attacker.example/fcm/send/x') + $good,
            'metadata address' => array('endpoint' => 'https://169.254.169.254/latest/meta-data') + $good,
            'local' => array('endpoint' => 'https://localhost/x/y') + $good,
            'look-alike' => array('endpoint' => 'https://fcm.googleapis.com.attacker.example/x') + $good,
            'credentials' => array('endpoint' => 'https://u:p@fcm.googleapis.com/fcm/send/x') + $good,
            'bad key' => array('keys' => array('p256dh' => substr($good['keys']['p256dh'], 0, 40), 'auth' => $good['keys']['auth'])) + $good,
            'short auth' => array('keys' => array('p256dh' => $good['keys']['p256dh'], 'auth' => 'AAAA')) + $good,
        );
        foreach ($bad as $label => $sub) {
            $this->assertEnvelope($this->rest('POST', self::ME, $sub + array('consent' => true), $this->nonce()), 400, 'invalid_request');
            unset($label);
        }
        foreach (array($good, $good + array('consent' => false), $good + array('consent' => 'yes'), $good + array('consent' => true, 'extra' => 1), array('endpoint' => $good['endpoint'], 'consent' => true)) as $i => $body) {
            $this->assertEnvelope($this->rest('POST', self::ME, $body, $this->nonce()), 400, 'invalid_request');
            unset($i);
        }
        $this->assertEnvelope($this->rest('POST', self::ME, 'not json', $this->nonce()), 400, 'invalid_request');
        $this->assertEnvelope($this->rest('DELETE', self::ME, array('endpoint' => 'https://attacker.example/x/y'), $this->nonce()), 400, 'invalid_request');
        $this->assertSame(array(), $this->rows());
        $this->assertSame(array(), $this->requests, 'nothing was ever called');
    }

    public function test_not_a_member_and_a_disabled_member(): void {
        $user = $this->customer();
        $this->login_as($user);
        $this->assertSame(array('result' => 'ok', 'push' => array('state' => 'not_member')), $this->rest('GET', self::ME, null, $this->nonce())->get_data());
        $this->assertEnvelope($this->rest('POST', self::ME, $this->subscription() + array('consent' => true), $this->nonce()), 404, 'not_a_member');
        $other = $this->customer();
        $this->member($other, 'disabled');
        $this->assertEnvelope($this->subscribe_as($other, $this->subscription()), 403, 'member_disabled');
        $this->assertSame('member_disabled', $this->rest('GET', self::ME, null, $this->nonce())->get_data()['push']['state']);
        $this->assertSame(array(), $this->rows());
    }

    public function test_schema_below_5_hides_push_without_any_error(): void {
        $old = $this->database(4);
        $this->env['db'] = $old->config();
        $user = $this->customer();
        $this->login_as($user);
        foreach (array(array('GET', null), array('POST', $this->subscription() + array('consent' => true))) as $call) {
            $r = $this->rest($call[0], self::ME, $call[1], $this->nonce());
            $this->assertSame(503, $r->get_status(), $call[0]);
            $this->assertSame(array('result' => 'unavailable'), $r->get_data(), 'the domain body, never a 500');
        }
        $this->assertSame(EVC_Push_Backend::REASON_SCHEMA, $this->parts['backend']->last_reason());
        $this->assertSame(array('result' => EVC_Push_Campaign::UNAVAILABLE), $this->parts['campaign']->preview('all', 0));
        $tables = $old->connect()->fetch_all("SELECT TABLE_NAME AS t FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'evc_push_subscriptions'");
        $this->assertSame(array(), $tables);
    }

    public function test_unusable_keys_fail_closed_and_are_never_logged(): void {
        $user = $this->customer();
        $this->member($user);
        $this->login_as($user);
        foreach (array(
            array('public' => self::PUBLIC, 'private' => 'AAAA', 'subject' => self::SUBJECT),
            array('public' => 'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4', 'private' => self::PRIVATE, 'subject' => self::SUBJECT),
            array('public' => self::PUBLIC, 'private' => self::PRIVATE, 'subject' => 'shop'),
            array('public' => null, 'private' => null, 'subject' => null),
        ) as $vapid) {
            $this->env['vapid'] = $vapid;
            $this->assertSame(503, $this->rest('GET', self::ME, null, $this->nonce())->get_status());
            $this->assertSame(503, $this->rest('POST', self::ME, $this->subscription() + array('consent' => true), $this->nonce())->get_status());
            $this->assertSame(EVC_Push_Backend::REASON_VAPID, $this->parts['backend']->last_reason());
        }
        $this->assertSame(array(), $this->rows());
        $this->assertStringNotContainsString(self::PRIVATE, implode("\n", $this->log));
        // A member can always stop them, even without keys.
        $this->assertSame(200, $this->rest('DELETE', self::ME, array('endpoint' => self::FCM . 'x'), $this->nonce())->get_status());
        $this->env['push'] = 1;
        $this->assertSame(503, $this->rest('GET', self::ME, null, $this->nonce())->get_status(), 'the flag is the exact boolean true');
        $this->assertSame(EVC_Push_Backend::REASON_FLAG, $this->parts['backend']->last_reason());
    }

    // ---- the sender -------------------------------------------------------------------------------------------

    public function test_a_send_is_an_rfc_8030_request_the_subscriber_can_decrypt(): void {
        $user = $this->customer();
        $this->member($user);
        $sub = $this->subscription();
        $this->subscribe_as($user, $sub);
        $sending = $this->parts['backend']->sending();
        $row = $sending['store']->batch(0, 5)[0];
        $payload = EVC_Push_Message::payload('Νέο γλυκό', 'Δοκιμάστε το σήμερα στο κατάστημα.');
        $this->assertSame(EVC_Push_Sender::SENT, $this->parts['sender']->send($row, $payload, $sending['keys']));
        $this->assertCount(1, $this->requests);
        $req = $this->requests[0];
        $this->assertSame($sub['endpoint'], $req['url']);
        $this->assertSame('86400', $req['headers']['TTL']);
        $this->assertSame('aes128gcm', $req['headers']['Content-Encoding']);
        $this->assertSame('normal', $req['headers']['Urgency']);
        $this->assertSame(1, preg_match('/^vapid t=([^,]+), k=' . preg_quote(self::PUBLIC, '/') . '$/D', $req['headers']['Authorization'], $m));
        $claims = json_decode(EVC_Push_Crypto::b64u_decode(explode('.', $m[1])[1]), true);
        $this->assertSame('https://fcm.googleapis.com', $claims['aud']);
        $this->assertSame(self::SUBJECT, $claims['sub']);
        $this->assertSame($payload, $this->decrypt($sub['endpoint'], $req['body'], $sub['keys']['auth']));
    }

    public function test_a_stored_endpoint_outside_the_allowlist_is_never_called(): void {
        $sending = $this->parts['backend']->sending();
        $keys = $sending['keys'];
        $good = $this->subscription();
        foreach (array('https://attacker.example/x/y', 'http://fcm.googleapis.com/x/y', 'https://127.0.0.1/x/y') as $endpoint) {
            $row = array('endpoint' => $endpoint, 'p256dh' => $good['keys']['p256dh'], 'auth' => $good['keys']['auth']);
            $this->assertSame(EVC_Push_Sender::REFUSED, $this->parts['sender']->send($row, '{"title":"a","body":"b"}', $keys), $endpoint);
        }
        $this->assertSame(EVC_Push_Sender::REFUSED, $this->parts['sender']->send(array('endpoint' => $good['endpoint'], 'p256dh' => 'AAAA', 'auth' => $good['keys']['auth']), '{}', $keys));
        $this->assertSame(array(), $this->requests);
    }

    public function test_404_and_410_revoke_and_other_failures_only_count(): void {
        $ids = array();
        foreach (array(404, 410, 500, 413, 201) as $status) {
            $user = $this->customer();
            $this->member($user);
            $sub = $this->subscription();
            $this->subscribe_as($user, $sub);
            $this->statuses[$sub['endpoint']] = $status;
            $ids[$status] = $sub['endpoint'];
        }
        $this->assertSame(EVC_Push_Campaign::OK, $this->parts['campaign']->start(get_userdata($this->admin()), 'Τίτλος', 'Κείμενο', 'all', 0, 5)['result']);
        $this->assertSame('done', $this->parts['campaign']->step());
        $job = EVC_Push_Campaign::job();
        $this->assertSame(array(1, 2, 2), array((int) $job['sent'], (int) $job['removed'], (int) $job['failed']));
        $by = array();
        foreach ($this->rows() as $row) {
            $by[$row['endpoint']] = $row;
        }
        $this->assertSame('gone', $by[$ids[404]]['revoked_reason']);
        $this->assertSame('gone', $by[$ids[410]]['revoked_reason']);
        $this->assertNull($by[$ids[500]]['revoked_at_utc']);
        $this->assertSame('1', (string) $by[$ids[500]]['failure_count']);
        $this->assertSame('1', (string) $by[$ids[201]]['success_count']);
    }

    public function test_the_real_request_follows_no_redirect_verifies_tls_and_refuses_unsafe_addresses(): void {
        $seen = array();
        $filter = function ($pre, $args, $url) use (&$seen) {
            $seen[] = array('url' => $url, 'args' => $args);
            return array('headers' => array(), 'body' => '', 'response' => array('code' => 201, 'message' => 'Created'), 'cookies' => array(), 'filename' => null);
        };
        add_filter('pre_http_request', $filter, 10, 3);
        try {
            $this->assertSame(201, EVC_Push_Sender::post(self::FCM . 'x', array('TTL' => '86400'), 'body'));
        } finally {
            remove_filter('pre_http_request', $filter, 10);
        }
        $this->assertCount(1, $seen);
        $this->assertSame('POST', $seen[0]['args']['method']);
        $this->assertSame(0, $seen[0]['args']['redirection'], 'no redirect is followed');
        $this->assertTrue($seen[0]['args']['reject_unsafe_urls'], 'no private or local address');
        $this->assertTrue($seen[0]['args']['sslverify'], 'TLS verified');
        $this->assertSame(EVC_Push_Sender::TIMEOUT_SECONDS, $seen[0]['args']['timeout']);
        $this->assertSame(5, EVC_Push_Sender::TIMEOUT_SECONDS);
    }

    // ---- the administrator page -------------------------------------------------------------------------------

    public function test_only_administrators_with_the_club_capability_may_send_never_the_till_account(): void {
        $admin = $this->admin();
        $this->assertTrue(EVC_Push_Admin_Page::may_send(get_userdata($admin)));
        $staff = $this->create_staff_user();
        $this->assertFalse(EVC_Push_Admin_Page::may_send(get_userdata($staff)), 'the shared till account');
        $plain_admin = (int) self::factory()->user->create(array('role' => 'administrator'));
        get_userdata($plain_admin)->add_cap(EVC_Staff_Role::CAP_MANAGE, false);
        $this->assertFalse(EVC_Push_Admin_Page::may_send(get_userdata($plain_admin)), 'without evc_manage_club');
        $this->assertFalse(EVC_Push_Admin_Page::may_send(get_userdata($this->create_user_with_role('editor'))));
        $this->assertFalse(EVC_Push_Admin_Page::may_send(null));
        wp_set_current_user($staff);
        $r = $this->page_post(array('evc_push_action' => 'preview', 'evc_push_title' => 'a', 'evc_push_body' => 'b', 'evc_push_audience' => 'all'));
        $this->assertSame('forbidden', $r['code']);
        global $submenu;
        $submenu = array();
        $this->parts['page']->add_menu();
        $this->assertSame(array(), (array) $submenu, 'no menu for the till account');
        wp_set_current_user($admin);
        $this->assertSame(array('code' => 'bad_nonce'), $this->parts['page']->process(array('evc_push_action' => 'send', '_wpnonce' => wp_create_nonce(EVC_Push_Admin_Page::nonce_action('preview')))), 'the nonce of another action');
        $this->assertSame(array('code' => 'bad_nonce'), $this->parts['page']->process(array('evc_push_action' => 'step')), 'no nonce');
        global $submenu;
        $submenu = array();
        $this->parts['page']->add_menu();
        $this->assertSame('evc-club-push', $submenu['tools.php'][0][2] ?? null, 'without the Club admin pages the page sits under Tools');
    }

    public function test_preview_counts_by_audience_and_sends_nothing(): void {
        $now = $this->clock->now()->getTimestamp();
        $specs = array(
            array('active', gmdate('Y-m-d\TH:i:sP', $now + 2 * 86400)),
            array('active', gmdate('Y-m-d\TH:i:sP', $now + 20 * 86400)),
            array('expired', gmdate('Y-m-d\TH:i:sP', $now - 86400)),
            array('none', null),
        );
        foreach ($specs as $spec) {
            $user = $this->customer();
            $this->member($user);
            $this->memberships[$user] = array('state' => $spec[0], 'paid_until_utc' => $spec[1]);
            $this->subscribe_as($user, $this->subscription());
        }
        $c = $this->parts['campaign'];
        $this->assertSame(array('result' => 'ok', 'members' => 4, 'subscriptions' => 4), $c->preview('all', 0));
        $this->assertSame(array('result' => 'ok', 'members' => 2, 'subscriptions' => 2), $c->preview('active', 0));
        $this->assertSame(array('result' => 'ok', 'members' => 1, 'subscriptions' => 1), $c->preview('expiring', 7));
        $this->assertSame(array('result' => 'ok', 'members' => 2, 'subscriptions' => 2), $c->preview('expiring', 30));
        $this->assertSame(array('result' => 'invalid'), $c->preview('expiring', 31));
        $this->assertSame(array('result' => 'invalid'), $c->preview('everyone', 0));
        wp_set_current_user($this->admin());
        $r = $this->page_post(array('evc_push_action' => 'preview', 'evc_push_title' => 'Νέα γεύση', 'evc_push_body' => 'Ελάτε να τη δοκιμάσετε.', 'evc_push_audience' => 'active', 'evc_push_days' => '7'));
        $this->assertSame('preview', $r['code']);
        $this->assertSame(2, $r['subscriptions']);
        $this->assertSame(array(), $this->requests, 'a preview sends nothing');
        $this->assertNull(EVC_Push_Campaign::job());
        foreach (array(array('evc_push_title' => str_repeat('α', 51), 'evc_push_body' => 'x', 'evc_push_audience' => 'all'), array('evc_push_title' => 'x', 'evc_push_body' => str_repeat('β', 151), 'evc_push_audience' => 'all'), array('evc_push_title' => 'x', 'evc_push_body' => 'y', 'evc_push_audience' => 'expiring', 'evc_push_days' => '0')) as $fields) {
            $this->assertSame('problem', $this->page_post(array('evc_push_action' => 'preview') + $fields)['code']);
        }
    }

    public function test_a_confirmed_send_runs_in_batches_under_a_time_budget_and_is_audited_without_endpoints(): void {
        $endpoints = array();
        for ($i = 0; $i < 30; $i++) {
            $user = $this->customer();
            $this->member($user);
            $sub = $this->subscription();
            $endpoints[] = $sub['endpoint'];
            $this->subscribe_as($user, $sub);
        }
        $admin = $this->admin();
        wp_set_current_user($admin);
        $r = $this->page_post(array('evc_push_action' => 'send', 'evc_push_title' => 'Καλημέρα', 'evc_push_body' => 'Ο καφές σας περιμένει.', 'evc_push_audience' => 'all'));
        $this->assertSame('started', $r['code']);
        $this->assertSame(array(), $this->requests, 'the confirmation only creates the job');
        $this->assertNotFalse(wp_next_scheduled(EVC_Push_Campaign::CRON_HOOK, array(EVC_Push_Campaign::job()['id'])), 'WP-Cron continues if the page is closed');
        $this->assertSame('running', $this->parts['campaign']->step());
        $this->assertCount(EVC_Push_Campaign::BATCH_SIZE, $this->requests, 'one batch');
        // The time budget: each timer read moves 6 seconds, so the next batch stops after a few messages.
        $this->tick = 6.0;
        $this->assertSame('running', $this->parts['campaign']->step());
        $this->assertLessThan(30, count($this->requests));
        $this->tick = 0.0;
        $this->assertSame('done', $this->parts['campaign']->step());
        $this->assertCount(30, $this->requests, 'every subscription exactly once');
        $this->assertSame($endpoints, array_column($this->requests, 'url'));
        $job = EVC_Push_Campaign::job();
        $this->assertSame('done', $job['state']);
        $this->assertSame(30, (int) $job['sent']);
        $this->assertSame('', $job['title'], 'the text is dropped once the job ended');
        $this->assertSame(EVC_Push_Campaign::NONE, $this->parts['campaign']->step(), 'nothing more to send');
        $audit = EVC_Push_Campaign::audit();
        $this->assertSame(array('done', 'started'), array_column($audit, 'event'));
        $this->assertSame($admin, $audit[0]['user_id']);
        $this->assertSame(30, $audit[0]['sent']);
        $this->assertSame('all', $audit[0]['audience']);
        $dump = wp_json_encode(array($audit, get_option(EVC_Push_Campaign::JOB_OPTION)), JSON_UNESCAPED_SLASHES);
        foreach (array('endpoint', 'fcm.googleapis.com', 'p256dh', 'Καλημέρα', 'example.org') as $needle) {
            $this->assertStringNotContainsString($needle, $dump, $needle);
        }
        $this->assertSame(array(), array_diff(array_keys($audit[0]), array('event', 'at', 'job', 'user_id', 'user_login', 'audience', 'days', 'planned', 'sent', 'failed', 'removed', 'skipped')));
    }

    public function test_the_audience_is_applied_when_each_batch_runs(): void {
        $active = $this->customer();
        $other = $this->customer();
        foreach (array($active, $other) as $user) {
            $this->member($user);
            $this->subscribe_as($user, $this->subscription());
        }
        $this->memberships[$active] = array('state' => 'active', 'paid_until_utc' => '2026-12-20T00:00:00+00:00');
        $this->parts['campaign']->start(get_userdata($this->admin()), 'Τ', 'Κ', 'active', 0, 1);
        $this->assertSame('done', $this->parts['campaign']->step());
        $this->assertCount(1, $this->requests);
        $this->assertSame(1, (int) EVC_Push_Campaign::job()['skipped']);
    }

    public function test_one_job_at_a_time_and_at_most_one_send_per_15_minutes_and_4_a_day(): void {
        $user = $this->customer();
        $this->member($user);
        $this->subscribe_as($user, $this->subscription());
        $admin = get_userdata($this->admin());
        $c = $this->parts['campaign'];
        $this->assertSame('ok', $c->start($admin, 'a', 'b', 'all', 0, 1)['result']);
        $this->assertSame('busy', $c->start($admin, 'a', 'b', 'all', 0, 1)['result'], 'while one runs');
        $c->step();
        $limited = $c->start($admin, 'a', 'b', 'all', 0, 1);
        $this->assertSame('rate_limited', $limited['result']);
        $this->assertGreaterThan(800, $limited['wait']);
        // Four starts in the last 24 hours (16 minutes apart) block a fifth.
        $audit = array();
        foreach (array(16, 32, 48, 64) as $minutes) {
            $audit[] = array('event' => 'started', 'at' => time() - $minutes * 60);
        }
        update_option(EVC_Push_Campaign::AUDIT_OPTION, $audit);
        delete_option(EVC_Push_Campaign::JOB_OPTION);
        $this->assertSame('rate_limited', $c->start($admin, 'a', 'b', 'all', 0, 1)['result']);
        update_option(EVC_Push_Campaign::AUDIT_OPTION, array_slice($audit, 0, 3));
        $this->assertSame('ok', $c->start($admin, 'a', 'b', 'all', 0, 1)['result']);
        $this->assertSame('ok', $c->cancel($admin));
        $this->assertSame('cancelled', EVC_Push_Campaign::job()['state']);
        $this->assertSame('none', $c->cancel($admin));
    }

    public function test_a_second_runner_never_sends_the_same_batch(): void {
        $user = $this->customer();
        $this->member($user);
        $this->subscribe_as($user, $this->subscription());
        $this->parts['campaign']->start(get_userdata($this->admin()), 'a', 'b', 'all', 0, 1);
        global $wpdb;
        $other = new PDO('mysql:host=' . EVC_Test_Database::host() . ';port=' . EVC_Test_Database::port() . ';dbname=' . DB_NAME, DB_USER, DB_PASSWORD);
        $held = $other->query("SELECT GET_LOCK('" . EVC_Push_Campaign::lock_name() . "', 0)")->fetchColumn();
        $this->assertSame('1', (string) $held);
        $this->assertSame('busy', $this->parts['campaign']->step());
        $this->assertSame(array(), $this->requests);
        $other->query("SELECT RELEASE_LOCK('" . EVC_Push_Campaign::lock_name() . "')");
        $this->assertSame('done', $this->parts['campaign']->step());
        $this->assertCount(1, $this->requests);
        unset($wpdb);
    }

    // ---- automatic notifications --------------------------------------------------------------------------------

    public function test_the_activated_notification_goes_once_per_paid_period_to_an_active_member_only(): void {
        $user = $this->customer();
        $this->member($user);
        $this->subscribe_as($user, $this->subscription());
        $auto = $this->parts['auto'];
        $this->memberships[$user] = array('state' => 'pending_payment', 'paid_until_utc' => null);
        $this->assertSame('not_active', $auto->activated($user));
        $this->memberships[$user] = array('state' => 'expired', 'paid_until_utc' => '2026-11-01T08:00:00+00:00');
        $this->assertSame('not_active', $auto->activated($user), 'an expired period is never «activated»');
        $this->memberships[$user] = array('state' => 'active', 'paid_until_utc' => '2026-12-21T08:00:00+00:00');
        $this->assertSame('sent', $auto->activated($user));
        $this->assertSame('already', $auto->activated($user), 'once per period');
        $this->assertCount(1, $this->requests);
        $this->assertSame(EVC_Push_Message::payload(EVC_Push_Auto::ACTIVATED_TITLE, EVC_Push_Auto::ACTIVATED_BODY), $this->decrypt($this->requests[0]['url'], $this->requests[0]['body'], $this->rows()[0]['auth']));
        $this->memberships[$user] = array('state' => 'active', 'paid_until_utc' => '2027-01-21T08:00:00+00:00');
        $this->assertSame('sent', $auto->activated($user), 'a renewal is a new period');
        $this->assertCount(2, $this->requests);
        $this->assertSame('auto_activated', EVC_Push_Campaign::audit()[0]['event']);
    }

    public function test_the_expiry_notification_goes_to_members_ending_in_3_athens_days_once(): void {
        // 2026-11-21 08:00 UTC = 10:00 Athens; three Athens days ahead = 2026-11-24.
        $cases = array(
            'ends 24th 23:30 Athens' => array('2026-11-24T21:30:00+00:00', true),
            'ends 24th 00:30 Athens' => array('2026-11-23T22:30:00+00:00', true),
            'ends 25th 00:30 Athens' => array('2026-11-24T22:30:00+00:00', false),
            'ends 23rd' => array('2026-11-23T10:00:00+00:00', false),
        );
        $expected = array();
        foreach ($cases as $label => $case) {
            $user = $this->customer();
            $this->member($user);
            $sub = $this->subscription();
            $this->subscribe_as($user, $sub);
            $this->memberships[$user] = array('state' => 'active', 'paid_until_utc' => $case[0]);
            if ($case[1]) {
                $expected[] = $sub['endpoint'];
            }
            unset($label);
        }
        $this->assertSame('done', $this->parts['auto']->expiry());
        $this->assertSame($expected, array_column($this->requests, 'url'));
        $this->assertSame(EVC_Push_Message::payload(EVC_Push_Auto::EXPIRY_TITLE, EVC_Push_Auto::EXPIRY_BODY), $this->decrypt($this->requests[0]['url'], $this->requests[0]['body'], $this->rows()[0]['auth']));
        delete_option(EVC_Push_Auto::RUN_OPTION);
        $this->assertSame('done', $this->parts['auto']->expiry());
        $this->assertCount(2, $this->requests, 'at most once per member per period');
        $next = wp_next_scheduled(EVC_Push_Auto::EXPIRY_HOOK);
        $this->assertSame(EVC_Push_Auto::next_run(time()), $next);
    }

    public function test_a_long_expiry_run_continues_where_it_stopped(): void {
        for ($i = 0; $i < 3; $i++) {
            $user = $this->customer();
            $this->member($user);
            $this->subscribe_as($user, $this->subscription());
            $this->memberships[$user] = array('state' => 'active', 'paid_until_utc' => '2026-11-24T12:00:00+00:00');
        }
        $this->tick = 8.0;
        $this->assertSame('continued', $this->parts['auto']->expiry());
        $first = count($this->requests);
        $this->assertLessThan(3, $first);
        $this->tick = 0.0;
        $this->assertSame('done', $this->parts['auto']->expiry());
        $this->assertCount(3, $this->requests, 'each member once across the two runs');
    }

    public function test_the_daily_run_is_at_10_athens_time_in_summer_and_winter(): void {
        $this->assertSame(strtotime('2026-11-21T08:00:00Z'), EVC_Push_Auto::next_run(strtotime('2026-11-21T07:59:00Z')), 'winter: 10:00 = 08:00 UTC');
        $this->assertSame(strtotime('2026-11-22T08:00:00Z'), EVC_Push_Auto::next_run(strtotime('2026-11-21T08:00:00Z')), 'strictly after');
        $this->assertSame(strtotime('2026-07-01T07:00:00Z'), EVC_Push_Auto::next_run(strtotime('2026-06-30T12:00:00Z')), 'summer: 10:00 = 07:00 UTC');
        $this->assertSame(strtotime('2027-03-28T07:00:00Z'), EVC_Push_Auto::next_run(strtotime('2027-03-27T09:00:00Z')), 'the night the clocks go forward');
    }

    public function test_the_automatic_notifications_are_hooked_only_by_their_own_constants(): void {
        $auto = $this->parts['auto'];
        $on = getenv('EVC_TEST_PUSH_FLAG') === 'auto';
        $this->assertSame($on, EVC_Push_Feature::auto_activated());
        $this->assertSame($on, EVC_Push_Feature::auto_expiry());
        $auto->register();
        $this->assertSame($on, has_action('woocommerce_order_status_completed', array($auto, 'on_completed')) !== false);
        $this->assertSame($on, has_action(EVC_Push_Auto::EXPIRY_HOOK, array($auto, 'expiry')) !== false);
        $this->assertSame($on, has_action(EVC_Push_Auto::ACTIVATED_HOOK, array($auto, 'activated')) !== false);
        if (!$on) {
            wp_schedule_single_event(time() + 3600, EVC_Push_Auto::EXPIRY_HOOK);
            $auto->register();
            $this->assertFalse(wp_next_scheduled(EVC_Push_Auto::EXPIRY_HOOK), 'an event left from before is cleared when the constant is off');
        } else {
            $auto->schedule_expiry();
            $this->assertSame(EVC_Push_Auto::next_run(time()), wp_next_scheduled(EVC_Push_Auto::EXPIRY_HOOK));
        }
        remove_all_actions('woocommerce_order_status_completed');
        remove_all_actions(EVC_Push_Auto::EXPIRY_HOOK);
        remove_all_actions(EVC_Push_Auto::ACTIVATED_HOOK);
        wp_clear_scheduled_hook(EVC_Push_Auto::EXPIRY_HOOK);
    }
}
