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
    /** @var callable|null round 13: runs inside each push request (a slow push service: other requests act meanwhile) */
    private $on_request = null;
    /** @var callable|null round 14: runs on each timer read (a failure the batch does not catch) */
    private $on_timer = null;

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
        $this->on_request = null;
        $this->on_timer = null;
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
        delete_option(EVC_Push_Campaign::CANCEL_OPTION);
        delete_option('evc_club_push_starts'); // D29: the old start-time store, no longer read or written
        delete_option(EVC_Push_Campaign::AUTO_AUDIT_OPTION);
        unset($_GET['evc_push']);
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
                if ($this->on_request !== null) {
                    ($this->on_request)(count($this->requests));
                }
                return $this->statuses[$url] ?? 201;
            },
            'time' => function (): int {
                return 1795000000;
            },
            'membership' => function (int $wp_user_id, DateTimeImmutable $now): array {
                return $this->memberships[$wp_user_id] ?? array('state' => 'none', 'paid_until_utc' => null);
            },
            'timer' => function (): float {
                if ($this->on_timer !== null) {
                    ($this->on_timer)();
                }
                $this->now_seconds += $this->tick;
                return $this->now_seconds;
            },
            'redirect' => function (string $url): void {
                $this->redirects[] = $url;
            },
        ));
        $this->parts['controller']->register_routes(true);
        $this->parts['messages']->register_routes(true);
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
        $this->assertSame(array('clubMessages' => true, 'pushPublicKey' => self::PUBLIC), EVC_Push_Feature::page_config());
        // Round 18 (D30): the in-app messages: the private type and the member's own read routes.
        $this->assertNotFalse(has_action('rest_api_init', array($parts['messages'], 'register_routes')));
        $this->assertTrue(post_type_exists(EVC_Club_Messages::POST_TYPE));
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

    /** Owner decision D29 (round 17): one send at a time stays; there is NO rate limit on administrators' sends. */
    public function test_d29_one_job_at_a_time_and_no_rate_limit_for_administrators(): void {
        $user = $this->customer();
        $this->member($user);
        $this->subscribe_as($user, $this->subscription());
        $admin = get_userdata($this->admin());
        $c = $this->parts['campaign'];
        $this->assertSame(array('result' => 'ok'), $c->start($admin, 'a', 'b', 'all', 0, 1));
        $this->assertSame('busy', $c->start($admin, 'a', 'b', 'all', 0, 1)['result'], 'while one runs');
        $this->assertSame('done', $c->step());
        // Six sends in a row, seconds apart (more than the old 15-minute gap and 4-a-day cap allowed): every one starts.
        for ($i = 0; $i < 6; $i++) {
            $this->assertSame(array('result' => 'ok'), $c->start($admin, 'a' . $i, 'b', 'all', 0, 1), "send $i starts at once");
            $this->assertSame('done', $c->step());
        }
        // An audit full of recent starts (what the old cap counted after an upgrade) blocks nothing either.
        $entry = function (int $minutes_ago): array {
            return array('event' => 'started', 'at' => time() - $minutes_ago * 60, 'job' => 'j' . $minutes_ago, 'user_id' => 1, 'user_login' => 'a', 'audience' => 'all', 'days' => 0, 'planned' => 1, 'sent' => 1, 'failed' => 0, 'removed' => 0, 'skipped' => 0);
        };
        update_option(EVC_Push_Campaign::AUDIT_OPTION, array($entry(1), $entry(2), $entry(3), $entry(4), $entry(5)), false);
        update_option('evc_club_push_starts', array(time() - 5, time() - 10, time() - 15, time() - 20), false);
        $this->assertSame(array('result' => 'ok'), $c->start($admin, 'a', 'b', 'all', 0, 1));
        $this->assertCount(4, get_option('evc_club_push_starts'), 'the old start-time option is neither read nor written');
        $this->assertSame('ok', $c->cancel($admin));
        $this->assertSame('cancelled', EVC_Push_Campaign::job()['state']);
        $this->assertSame('none', $c->cancel($admin));
        $page = (string) file_get_contents(dirname(__DIR__, 3) . '/includes/push/class-evc-push-admin-page.php');
        $this->assertStringNotContainsString('15 λεπτά', $page, 'the Greek rate-limit message is gone');
        $this->assertFalse(defined('EVC_Push_Campaign::RATE_LIMITED'));
        $this->assertFalse(method_exists('EVC_Push_Campaign', 'wait_seconds'));
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

    // ---- round 13: the stop, the lock, the rate-limit store, the till role ------------------------------------------

    /** $n members, each with one subscription. */
    private function subscribers(int $n): void {
        for ($i = 0; $i < $n; $i++) {
            $user = $this->customer();
            $this->member($user);
            $this->subscribe_as($user, $this->subscription());
        }
    }

    /** A second MySQL connection to the WordPress database (another PHP process, as far as named locks go). */
    private function other_connection(): PDO {
        return new PDO('mysql:host=' . EVC_Test_Database::host() . ';port=' . EVC_Test_Database::port() . ';dbname=' . DB_NAME, DB_USER, DB_PASSWORD);
    }

    /** One administrator's page request as the browser sends it (handle_load(), then the page body). */
    private function page_request(array $fields): string {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = wp_slash($fields + array('_wpnonce' => wp_create_nonce(EVC_Push_Admin_Page::nonce_action((string) $fields['evc_push_action']))));
        try {
            $this->parts['page']->handle_load();
            return $this->parts['page']->html();
        } finally {
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_POST = array();
        }
    }

    public function test_round13_a_stop_during_a_batch_is_recorded_and_the_batch_ends_before_its_next_message(): void {
        $this->subscribers(10);
        $admin = get_userdata($this->admin());
        $c = $this->parts['campaign'];
        $this->assertSame('ok', $c->start($admin, 'Τ', 'Κ', 'all', 0, 10)['result']);
        $answers = array();
        $this->on_request = function (int $n) use ($c, $admin, &$answers) {
            if ($n === 2) {
                // A slow push service: while message 2 is in flight the administrator presses «Διακοπή αποστολής».
                usleep(50000);
                $answers[] = $c->cancel($admin);
                $answers[] = EVC_Push_Campaign::fresh_job()['state'];
            }
        };
        $this->assertSame('cancelled', $c->step());
        $this->assertSame(array('cancel_pending', 'running'), $answers, 'while a batch holds the lock the stop is only recorded');
        $this->assertCount(2, $this->requests, 'not one message after the one in flight');
        $job = EVC_Push_Campaign::fresh_job();
        $this->assertSame('cancelled', $job['state']);
        $this->assertSame((int) $admin->ID, $job['cancelled_by']);
        $this->assertSame(2, (int) $job['sent']);
        $this->assertNull(EVC_Push_Campaign::fresh_option(EVC_Push_Campaign::CANCEL_OPTION), 'the request ends with the job');
        $this->on_request = null;
        $this->assertSame('none', $c->step(), 'WP-Cron or the page coming back later sends nothing');
        $this->assertSame('none', $c->cancel($admin));
        $this->assertCount(2, $this->requests);
        $this->assertSame(array('cancelled', 'started'), array_column(EVC_Push_Campaign::audit(), 'event'), 'never «cancelled» followed by «done»');
    }

    public function test_round13_the_batch_reads_the_stop_request_from_the_database_before_every_message(): void {
        $this->subscribers(6);
        $c = $this->parts['campaign'];
        $c->start(get_userdata($this->admin()), 'Τ', 'Κ', 'all', 0, 6);
        $id = EVC_Push_Campaign::fresh_job()['id'];
        global $wpdb;
        $write = function (string $value) use ($wpdb) {
            // Another PHP process (with its own object cache) records a stop: only the database row changes here.
            $wpdb->query($wpdb->prepare("INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no') ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)", EVC_Push_Campaign::CANCEL_OPTION, $value));
        };
        $write('0123456789abcdef:5');
        $this->on_request = function (int $n) use ($write, $id) {
            if ($n === 3) {
                $write($id . ':7');
            }
        };
        $this->assertSame('cancelled', $c->step(), 'a stop recorded for another job never stops this one; this job\'s does');
        $this->assertCount(3, $this->requests);
        $this->assertSame(7, EVC_Push_Campaign::fresh_job()['cancelled_by']);
    }

    public function test_round13_the_page_says_the_stop_is_pending_until_the_job_really_stopped(): void {
        $this->subscribers(2);
        $admin = $this->admin();
        wp_set_current_user($admin);
        $this->assertSame('started', $this->page_post(array('evc_push_action' => 'send', 'evc_push_title' => 'Καλημέρα', 'evc_push_body' => 'Κείμενο.', 'evc_push_audience' => 'all'))['code']);
        $other = $this->other_connection();
        $this->assertSame('1', (string) $other->query("SELECT GET_LOCK('" . EVC_Push_Campaign::lock_name() . "', 0)")->fetchColumn(), 'a batch is sending');
        $this->assertSame(array('code' => 'cancel_pending'), $this->page_post(array('evc_push_action' => 'cancel')));
        $this->redirects = array();
        $this->page_request(array('evc_push_action' => 'cancel'));
        $this->assertStringContainsString('evc_push=cancel_pending', $this->redirects[0] ?? '');
        $_GET['evc_push'] = 'cancel_pending';
        $html = $this->parts['page']->html();
        $this->assertStringContainsString('Η διακοπή καταχωρήθηκε, η αποστολή σταματά σε λίγα δευτερόλεπτα.', $html);
        $this->assertStringNotContainsString('Η αποστολή διακόπηκε', $html, 'not «stopped» while it is still sending');
        $this->assertSame('running', EVC_Push_Campaign::fresh_job()['state']);
        $other->query("SELECT RELEASE_LOCK('" . EVC_Push_Campaign::lock_name() . "')");
        $this->assertSame('cancelled', $this->parts['campaign']->step());
        $this->assertSame(array(), $this->requests, 'not one message after the stop');
        $html = $this->parts['page']->html();
        $this->assertStringContainsString('Η αποστολή διακόπηκε.', $html);
        $this->assertStringNotContainsString('Η διακοπή καταχωρήθηκε', $html);
        $this->assertSame(array('cancelled', 'started'), array_column(EVC_Push_Campaign::audit(), 'event'));
        // With the lock free the stop ends the job at once.
        $this->assertSame('started', $this->page_post(array('evc_push_action' => 'send', 'evc_push_title' => 'Καλημέρα', 'evc_push_body' => 'Κείμενο.', 'evc_push_audience' => 'all'))['code']);
        $this->assertSame(array('code' => 'cancelled_ok'), $this->page_post(array('evc_push_action' => 'cancel')));
        $this->assertSame('cancelled', EVC_Push_Campaign::fresh_job()['state']);
        // The job ended by itself before the stop was applied: the page says so, never «stopped».
        $this->page_post(array('evc_push_action' => 'send', 'evc_push_title' => 'Καλημέρα', 'evc_push_body' => 'Κείμενο.', 'evc_push_audience' => 'all'));
        $this->assertSame('done', $this->parts['campaign']->step());
        $html = $this->parts['page']->html();
        $this->assertStringContainsString('Η αποστολή είχε ήδη ολοκληρωθεί πριν από τη διακοπή.', $html);
        $this->assertStringNotContainsString('Η αποστολή διακόπηκε', $html);
    }

    public function test_round13_without_a_named_lock_nothing_is_sent_and_the_page_says_why(): void {
        $this->subscribers(2);
        $admin = $this->admin();
        wp_set_current_user($admin);
        $c = $this->parts['campaign'];
        $this->assertSame('ok', $c->start(get_userdata($admin), 'Τ', 'Κ', 'all', 0, 2)['result']);
        global $wpdb;
        $null_lock = function ($query) {
            return stripos($query, 'GET_LOCK(') !== false ? 'SELECT NULL' : $query;
        };
        add_filter('query', $null_lock);
        try {
            $this->assertSame('no_lock', $c->step(), 'GET_LOCK answered NULL');
            $html = $this->page_request(array('evc_push_action' => 'step'));
            $this->assertStringContainsString('δεν επέτρεψε το κλείδωμα που χρειάζεται για ασφαλή αποστολή. Για ασφάλεια δεν στάλθηκε τίποτα.', $html);
            $this->assertSame(array(), $this->redirects, 'the page stays on the explanation');
            // Round 15 (L1): without any named lock the stop ends the job by the conditional write (same job, still running).
            $this->assertSame('ok', $c->cancel(get_userdata($admin)), 'a stop without the lock still stops the job');
            $job = EVC_Push_Campaign::fresh_job();
            $this->assertSame('cancelled', $job['state']);
            $this->assertSame('', $job['title'], 'the text is cleared');
            $this->assertNull(EVC_Push_Campaign::fresh_option(EVC_Push_Campaign::CANCEL_OPTION));
            $this->assertSame(array('cancelled', 'started'), array_column(EVC_Push_Campaign::audit(), 'event'), 'audited once');
            $this->assertCount(1, EVC_Push_Campaign::fresh_option(EVC_Push_Campaign::STOP_AUDIT_OPTION), 'in the store of the lock-free stops');
                $this->assertSame('no_lock', $c->start(get_userdata($admin), 'Τ', 'Κ', 'all', 0, 2)['result']);
        } finally {
            remove_filter('query', $null_lock);
        }
        $broken = function ($query) {
            return stripos($query, 'GET_LOCK(') !== false ? 'SELECT GET_LOCK_NOT_A_FUNCTION()' : $query;
        };
        add_filter('query', $broken);
        $quiet = $wpdb->suppress_errors(true);
        try {
            $this->assertSame('no_lock', $c->step(), 'GET_LOCK failed');
        } finally {
            $wpdb->suppress_errors($quiet);
            remove_filter('query', $broken);
        }
        $this->assertSame(array(), $this->requests, 'nothing was ever sent unlocked');
        $this->assertSame('none', $c->step(), 'with the lock back, nothing is left to send');
        $this->assertSame(array(), $this->requests);
    }

    public function test_round13_two_cron_runners_never_send_an_automatic_notification_twice(): void {
        $user = $this->customer();
        $this->member($user);
        $this->subscribe_as($user, $this->subscription());
        // Active, paid period ending on the Athens day three days ahead (both automatic notifications apply).
        $this->memberships[$user] = array('state' => 'active', 'paid_until_utc' => '2026-11-24T12:00:00+00:00');
        $auto = $this->parts['auto'];
        $other = $this->other_connection();
        $this->assertSame('1', (string) $other->query("SELECT GET_LOCK('" . EVC_Push_Campaign::auto_lock_name() . "', 0)")->fetchColumn());
        $this->assertSame('busy', $auto->activated($user), 'another runner holds the automatic lock');
        $this->assertSame('busy', $auto->expiry());
        $this->assertSame(array(), $this->requests);
        $this->assertNotFalse(wp_next_scheduled(EVC_Push_Auto::ACTIVATED_HOOK, array($user, 0)), 'the refused one comes back later (round 15: with its try number)');
        $this->assertNotFalse(wp_next_scheduled(EVC_Push_Auto::EXPIRY_HOOK));
        $other->query("SELECT RELEASE_LOCK('" . EVC_Push_Campaign::auto_lock_name() . "')");
        wp_clear_scheduled_hook(EVC_Push_Auto::ACTIVATED_HOOK, array($user, 0));
        // A running campaign's lock does not hold the automatic notifications up.
        $other->query("SELECT GET_LOCK('" . EVC_Push_Campaign::lock_name() . "', 0)");
        $second = array();
        $this->on_request = function () use ($auto, $user, &$second) {
            // A slow push service: a second WP-Cron runner starts the same events meanwhile.
            $second[] = $auto->activated($user);
            $second[] = $auto->expiry();
        };
        $this->assertSame('sent', $auto->activated($user));
        $this->assertSame(array('busy', 'busy'), $second);
        $this->assertCount(1, $this->requests);
        $second = array();
        $this->assertSame('done', $auto->expiry());
        $this->assertSame(array('busy', 'busy'), $second);
        $this->assertCount(2, $this->requests, 'each automatic notification exactly once');
        $other->query("SELECT RELEASE_LOCK('" . EVC_Push_Campaign::lock_name() . "')");
        $this->on_request = null;
        // No named lock: nothing is sent.
        $this->memberships[$user] = array('state' => 'active', 'paid_until_utc' => '2027-01-24T12:00:00+00:00');
        $null_lock = function ($query) {
            return stripos($query, 'GET_LOCK(') !== false ? 'SELECT NULL' : $query;
        };
        add_filter('query', $null_lock);
        try {
            $this->assertSame('no_lock', $auto->activated($user));
            $this->assertSame('no_lock', $auto->expiry());
        } finally {
            remove_filter('query', $null_lock);
        }
        $this->assertCount(2, $this->requests);
        $this->assertSame('sent', $auto->activated($user), 'a renewal still goes once the lock is back');
    }


    public function test_round13_an_administrator_who_also_holds_the_till_role_is_refused(): void {
        $id = $this->admin();
        get_userdata($id)->add_role(EVC_Staff_Role::ROLE);
        clean_user_cache($id);
        $user = get_userdata($id);
        $this->assertTrue(user_can($user, 'manage_options') && user_can($user, EVC_Staff_Role::CAP_MANAGE), 'still an administrator with the Club capability');
        $this->assertTrue(EVC_Staff_Role::has_staff_role($user));
        $this->assertFalse(EVC_Push_Admin_Page::may_send($user));
        wp_set_current_user($id);
        $this->assertSame('forbidden', $this->page_post(array('evc_push_action' => 'preview', 'evc_push_title' => 'a', 'evc_push_body' => 'b', 'evc_push_audience' => 'all'))['code']);
        $this->assertSame('forbidden', $this->page_post(array('evc_push_action' => 'step'))['code']);
        global $submenu;
        $submenu = array();
        $this->parts['page']->add_menu();
        $this->assertSame(array(), (array) $submenu, 'no menu');
        try {
            $this->parts['page']->render();
            $this->fail('the page rendered');
        } catch (WPDieException $e) {
            $this->assertStringContainsString(EVC_Push_Admin_Page::NOT_ALLOWED, $e->getMessage());
        }
    }

    // ---- round 14: the job never stalls, the audit keeps every entry, the round-13 nits ------------------------------

    /** WP-Cron takes an event off the queue before it calls the hook: the same here. */
    private function pop_batch_event(string $id): void {
        wp_clear_scheduled_hook(EVC_Push_Campaign::CRON_HOOK, array($id));
    }

    /** One member with one subscription and an active membership ending on $until. */
    private function active_subscriber(string $until): int {
        $user = $this->customer();
        $this->member($user);
        $this->subscribe_as($user, $this->subscription());
        $this->memberships[$user] = array('state' => 'active', 'paid_until_utc' => $until);
        return $user;
    }

    public function test_round14_a_cron_runner_that_finds_the_lock_busy_queues_itself_again(): void {
        $this->subscribers(3);
        $c = $this->parts['campaign'];
        $this->assertSame('ok', $c->start(get_userdata($this->admin()), 'Τ', 'Κ', 'all', 0, 3)['result']);
        $id = EVC_Push_Campaign::fresh_job()['id'];
        // Runner 1 (the administrator's page, another PHP process) holds the lock and already looked for a queued event;
        // runner 2 (WP-Cron) has just taken that event off the queue and finds the lock busy.
        $other = $this->other_connection();
        $this->assertSame('1', (string) $other->query("SELECT GET_LOCK('" . EVC_Push_Campaign::lock_name() . "', 0)")->fetchColumn());
        $this->pop_batch_event($id);
        $c->cron($id);
        $this->assertSame(array(), $this->requests);
        $next = wp_next_scheduled(EVC_Push_Campaign::CRON_HOOK, array($id));
        $this->assertNotFalse($next, 'the job is never left running with no event queued');
        $this->assertEqualsWithDelta(time() + EVC_Push_Campaign::RETRY_SECONDS, $next, 2);
        $this->assertSame('running', EVC_Push_Campaign::fresh_job()['state']);
        // No lock at all: the same (nothing is sent, the job is not forgotten).
        $other->query("SELECT RELEASE_LOCK('" . EVC_Push_Campaign::lock_name() . "')");
        $this->pop_batch_event($id);
        $null_lock = function ($query) {
            return stripos($query, 'GET_LOCK(') !== false ? 'SELECT NULL' : $query;
        };
        add_filter('query', $null_lock);
        try {
            $c->cron($id);
        } finally {
            remove_filter('query', $null_lock);
        }
        $this->assertNotFalse(wp_next_scheduled(EVC_Push_Campaign::CRON_HOOK, array($id)));
        $this->assertSame(array(), $this->requests);
        // Two runners in this process: the page's batch is sending when WP-Cron starts the same job.
        $this->pop_batch_event($id);
        $this->on_request = function (int $n) use ($c, $id) {
            if ($n === 1) {
                $this->pop_batch_event($id);
                $c->cron($id);
            }
        };
        $this->assertSame('done', $c->step());
        $this->assertCount(3, $this->requests, 'every subscription once');
        $this->assertFalse(wp_next_scheduled(EVC_Push_Campaign::CRON_HOOK, array($id)), 'a finished job leaves no event');
    }

    public function test_round14_a_running_job_always_has_its_next_event_queued_after_the_lock_is_released(): void {
        $this->subscribers(4);
        $c = $this->parts['campaign'];
        $c->start(get_userdata($this->admin()), 'Τ', 'Κ', 'all', 0, 4);
        $id = EVC_Push_Campaign::fresh_job()['id'];
        $this->pop_batch_event($id);
        $this->tick = 6.0; // the time budget ends the batch after two messages
        $this->assertSame('running', $c->step());
        $this->assertCount(2, $this->requests);
        $next = wp_next_scheduled(EVC_Push_Campaign::CRON_HOOK, array($id));
        $this->assertNotFalse($next, 'the next batch is queued');
        $this->assertEqualsWithDelta(time(), $next, 2);
        // A batch that throws half-way (an error nothing in the batch catches): the lock is released and the job still
        // has its event.
        $this->tick = 0.0;
        $this->pop_batch_event($id);
        $this->on_timer = function () {
            if (count($this->requests) === 3) {
                throw new RuntimeException('push library failure');
            }
        };
        try {
            $c->step();
            $this->fail('the batch did not throw');
        } catch (RuntimeException $e) {
            $this->assertSame('push library failure', $e->getMessage());
        }
        $this->assertSame('running', EVC_Push_Campaign::fresh_job()['state']);
        $this->assertNotFalse(wp_next_scheduled(EVC_Push_Campaign::CRON_HOOK, array($id)), 'not stalled by an exception');
        $this->on_timer = null;
        $this->assertSame('done', $c->step(), 'the lock was released');
    }

    public function test_round14_concurrent_audit_appends_keep_both_entries(): void {
        $user = $this->active_subscriber('2026-12-21T08:00:00+00:00');
        $c = $this->parts['campaign'];
        $auto = $this->parts['auto'];
        $c->start(get_userdata($this->admin()), 'Τ', 'Κ', 'all', 0, 1);
        // While the campaign writes «done» (its store already read), the automatic notification runs in another process
        // under its own lock and writes its entry.
        $once = false;
        $during = function ($value) use ($auto, $user, &$once) {
            if (!$once) {
                $once = true;
                $this->assertSame('sent', $auto->activated($user));
            }
            return $value;
        };
        add_filter('pre_update_option_' . EVC_Push_Campaign::AUDIT_OPTION, $during);
        try {
            $this->assertSame('done', $c->step());
        } finally {
            remove_filter('pre_update_option_' . EVC_Push_Campaign::AUDIT_OPTION, $during);
        }
        $this->assertTrue($once);
        $events = array_column(EVC_Push_Campaign::audit(), 'event');
        sort($events);
        $this->assertSame(array('auto_activated', 'done', 'started'), $events, 'both entries are kept');
        // An entry another process wrote a moment ago (this request's cache never saw it) is kept by the next append.
        $this->assertCount(2, get_option(EVC_Push_Campaign::AUDIT_OPTION), 'this request has the store cached');
        global $wpdb;
        $stored = array_merge(array(array('event' => 'cancelled', 'at' => time(), 'job' => 'other', 'user_id' => 9, 'user_login' => 'x', 'audience' => 'all', 'days' => 0, 'planned' => 0, 'sent' => 0, 'failed' => 0, 'removed' => 0, 'skipped' => 0)), get_option(EVC_Push_Campaign::AUDIT_OPTION));
        $wpdb->update($wpdb->options, array('option_value' => maybe_serialize($stored)), array('option_name' => EVC_Push_Campaign::AUDIT_OPTION));
        EVC_Push_Campaign::append_audit(array('event' => 'started', 'at' => time(), 'job' => 'mine', 'user_id' => 1, 'user_login' => 'y', 'audience' => 'all', 'days' => 0, 'planned' => 0, 'sent' => 0, 'failed' => 0, 'removed' => 0, 'skipped' => 0));
        $this->assertSame(array('mine', 'other'), array_slice(array_column(EVC_Push_Campaign::fresh_option(EVC_Push_Campaign::AUDIT_OPTION), 'job'), 0, 2), 'the other process\'s entry survives');
    }

    public function test_round14_a_stop_arriving_as_the_job_finishes_says_it_had_already_finished(): void {
        $this->subscribers(1);
        $admin = $this->admin();
        wp_set_current_user($admin);
        $c = $this->parts['campaign'];
        // The batch finishes the job between the stop's first read and the stop taking the lock.
        $finish_first = function () use ($c) {
            $once = false;
            return function ($query) use ($c, &$once) {
                if (!$once && stripos($query, 'INSERT INTO') !== false && strpos($query, EVC_Push_Campaign::CANCEL_OPTION) !== false) {
                    $once = true;
                    $this->assertSame('done', $c->step());
                }
                return $query;
            };
        };
        $c->start(get_userdata($admin), 'Τ', 'Κ', 'all', 0, 1);
        $filter = $finish_first();
        add_filter('query', $filter);
        try {
            $this->assertSame(EVC_Push_Campaign::DONE_BEFORE_CANCEL, $c->cancel(get_userdata($admin)));
        } finally {
            remove_filter('query', $filter);
        }
        $this->assertNull(EVC_Push_Campaign::fresh_option(EVC_Push_Campaign::CANCEL_OPTION), 'the stale stop request is cleared');
        $this->assertSame('done', EVC_Push_Campaign::fresh_job()['state']);
        $this->assertSame(array('done', 'started'), array_column(EVC_Push_Campaign::audit(), 'event'));
        // The same through the page.
        $c->start(get_userdata($admin), 'Τ', 'Κ', 'all', 0, 1);
        $filter = $finish_first();
        add_filter('query', $filter);
        $this->redirects = array();
        try {
            $this->page_request(array('evc_push_action' => 'cancel'));
        } finally {
            remove_filter('query', $filter);
        }
        $this->assertStringContainsString('evc_push=done_before_cancel', $this->redirects[0] ?? '');
        $_GET['evc_push'] = 'done_before_cancel';
        $html = $this->parts['page']->html();
        $this->assertStringContainsString('Η αποστολή είχε ήδη ολοκληρωθεί πριν από τη διακοπή.', $html);
        $this->assertStringNotContainsString('Η αποστολή διακόπηκε', $html);
        $this->assertStringNotContainsString('Δεν υπάρχει αποστολή σε εξέλιξη', $html);
        $this->assertNull(EVC_Push_Campaign::fresh_option(EVC_Push_Campaign::CANCEL_OPTION));
    }

    public function test_round14_after_a_batch_without_a_lock_the_page_does_not_continue_by_itself(): void {
        $this->subscribers(30);
        $admin = $this->admin();
        wp_set_current_user($admin);
        $this->parts['campaign']->start(get_userdata($admin), 'Τ', 'Κ', 'all', 0, 30);
        $null_lock = function ($query) {
            return stripos($query, 'GET_LOCK(') !== false ? 'SELECT NULL' : $query;
        };
        add_filter('query', $null_lock);
        try {
            $html = $this->page_request(array('evc_push_action' => 'step'));
        } finally {
            remove_filter('query', $null_lock);
        }
        $this->assertStringContainsString('Για ασφάλεια δεν στάλθηκε τίποτα.', $html);
        $this->assertStringNotContainsString('<script', $html, 'no automatic next batch against a database that refuses the lock');
        $this->assertStringContainsString('Συνέχεια αποστολής', $html, 'the administrator can still try again');
        $this->assertStringContainsString('Διακοπή αποστολής', $html);
        $html = $this->page_request(array('evc_push_action' => 'step'));
        $this->assertCount(EVC_Push_Campaign::BATCH_SIZE, $this->requests);
        $this->assertStringContainsString('<script', $html, 'with the lock the page continues by itself');
    }

    public function test_round14_the_activated_notification_without_a_lock_comes_back_later_a_bounded_number_of_times(): void {
        $user = $this->active_subscriber('2026-12-21T08:00:00+00:00');
        $auto = $this->parts['auto'];
        $null_lock = function ($query) {
            return stripos($query, 'GET_LOCK(') !== false ? 'SELECT NULL' : $query;
        };
        add_filter('query', $null_lock);
        try {
            $this->assertSame('no_lock', $auto->activated($user));
            $next = wp_next_scheduled(EVC_Push_Auto::ACTIVATED_HOOK, array($user, 1));
            $this->assertNotFalse($next, 'not lost: it comes back');
            $this->assertEqualsWithDelta(time() + EVC_Push_Auto::NO_LOCK_RETRY_SECONDS, $next, 2);
            for ($try = 1; $try < EVC_Push_Auto::NO_LOCK_TRIES; $try++) {
                $this->assertSame('no_lock', $auto->activated($user, $try));
            }
            $this->assertNotFalse(wp_next_scheduled(EVC_Push_Auto::ACTIVATED_HOOK, array($user, EVC_Push_Auto::NO_LOCK_TRIES - 1)));
            $this->assertFalse(wp_next_scheduled(EVC_Push_Auto::ACTIVATED_HOOK, array($user, EVC_Push_Auto::NO_LOCK_TRIES)), 'bounded: no try after the last');
        } finally {
            remove_filter('query', $null_lock);
        }
        $this->assertSame(array(), $this->requests, 'nothing sent without the lock');
        $this->assertSame('sent', $auto->activated($user, 2), 'a later try with the lock sends it');
        $this->assertCount(1, $this->requests);
        wp_unschedule_hook(EVC_Push_Auto::ACTIVATED_HOOK);
    }

    public function test_round14_a_run_whose_connection_lost_the_lock_stops_and_another_runner_continues(): void {
        $this->subscribers(6);
        $c = $this->parts['campaign'];
        $c->start(get_userdata($this->admin()), 'Τ', 'Κ', 'all', 0, 6);
        $id = EVC_Push_Campaign::fresh_job()['id'];
        global $wpdb;
        $other = $this->other_connection();
        $lock = EVC_Push_Campaign::lock_name();
        $reconnect_at = function (int $at) use ($wpdb, $other, $lock) {
            return function (int $n) use ($at, $wpdb, $other, $lock) {
                if ($n === $at) {
                    // A silent reconnect: this connection no longer holds the lock, and another runner takes it.
                    $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
                    $this->assertSame('1', (string) $other->query("SELECT GET_LOCK('" . $lock . "', 0)")->fetchColumn());
                }
            };
        };
        $this->on_request = $reconnect_at(2);
        $this->assertSame(EVC_Push_Campaign::LOST_LOCK, $c->step());
        $this->assertCount(2, $this->requests, 'not one message after the lock was lost');
        $job = EVC_Push_Campaign::fresh_job();
        $this->assertSame('running', $job['state']);
        // Round 15: the cursor was saved before message 2 went; its counts are lost with the lock (never a second send).
        $this->assertSame(1, (int) $job['sent'], 'the counts saved by the lock holder are kept');
        $this->assertSame((int) $this->rows()[1]['id'], (int) $job['cursor'], 'the cursor is past the message in flight');
        $this->assertNotFalse(wp_next_scheduled(EVC_Push_Campaign::CRON_HOOK, array($id)));
        $this->assertSame('1', (string) $other->query("SELECT IS_USED_LOCK('" . $lock . "') IS NOT NULL")->fetchColumn(), 'the other runner\'s lock was left alone');
        $other->query("SELECT RELEASE_LOCK('" . $lock . "')");
        // Lost during the last message: the job is not written as done by a runner without the lock.
        $this->on_request = $reconnect_at(6);
        $this->assertSame(EVC_Push_Campaign::LOST_LOCK, $c->step());
        $this->assertSame('running', EVC_Push_Campaign::fresh_job()['state']);
        $other->query("SELECT RELEASE_LOCK('" . $lock . "')");
        $this->on_request = null;
        $this->assertSame('done', $c->step());
        $urls = array_column($this->requests, 'url');
        $this->assertCount(6, $urls);
        $this->assertSame($urls, array_values(array_unique($urls)), 'nothing sent twice');
        // The automatic notifications: lost just before sending = nothing sent.
        $user = $this->active_subscriber('2026-11-24T12:00:00+00:00');
        $auto_lock = EVC_Push_Campaign::auto_lock_name();
        $drop = function ($query) use ($wpdb, $auto_lock) {
            static $inside = false;
            if (!$inside && stripos($query, 'IS_USED_LOCK(') !== false) {
                $inside = true;
                $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $auto_lock));
                $inside = false;
            }
            return $query;
        };
        add_filter('query', $drop);
        try {
            $this->assertSame('lost_lock', $this->parts['auto']->activated($user));
            $this->assertSame('lost_lock', $this->parts['auto']->expiry());
        } finally {
            remove_filter('query', $drop);
        }
        $this->assertCount(6, $this->requests);
        $this->assertSame('', (string) get_user_meta($user, EVC_Push_Auto::ACTIVATED_META, true), 'the marker is not written either');
        $this->assertSame('sent', $this->parts['auto']->activated($user));
    }


    public function test_round14_the_activated_notification_reads_its_marker_fresh_not_from_a_stale_cache(): void {
        $user = $this->active_subscriber('2026-12-21T08:00:00+00:00');
        // This request has the member's meta cached (no marker) ...
        $this->assertSame('', (string) get_user_meta($user, EVC_Push_Auto::ACTIVATED_META, true));
        // ... when another WP-Cron runner (another process, its own cache) sends and writes the marker.
        global $wpdb;
        $wpdb->insert($wpdb->usermeta, array('user_id' => $user, 'meta_key' => EVC_Push_Auto::ACTIVATED_META, 'meta_value' => '2026-12-21T08:00:00+00:00'));
        $this->assertSame('', (string) get_user_meta($user, EVC_Push_Auto::ACTIVATED_META, true), 'the cache is stale');
        $this->assertSame('already', $this->parts['auto']->activated($user));
        $this->assertSame(array(), $this->requests, 'never twice');
    }

    // ---- round 15: a stale lock holder never brings a job back; no endless retries; lost lock like busy ---------------

    /**
     * Runs $fn as another PHP process would, as far as this class's in-request lock map goes (another process has its own
     * map; the MySQL named lock itself is shared through the server). The map is put back afterwards.
     */
    private function as_another_process(callable $fn) {
        $held = new ReflectionProperty('EVC_Push_Campaign', 'held');
        $held->setAccessible(true);
        $saved = $held->getValue();
        $held->setValue(null, array());
        try {
            return $fn();
        } finally {
            $held->setValue(null, $saved);
        }
    }

    private function null_lock_filter(): callable {
        return function ($query) {
            return stripos($query, 'GET_LOCK(') !== false ? 'SELECT NULL' : $query;
        };
    }

    /** The reviewer's scenario: A loses the lock during a slow message; B sends the rest and finishes; A comes back. */
    public function test_round15_a_runner_that_lost_the_lock_mid_message_never_brings_the_finished_job_back(): void {
        $this->subscribers(3);
        $c = $this->parts['campaign'];
        $this->assertSame('ok', $c->start(get_userdata($this->admin()), 'Τ', 'Κ', 'all', 0, 3)['result']);
        $id = EVC_Push_Campaign::fresh_job()['id'];
        global $wpdb;
        $b = array();
        $this->on_request = function (int $n) use ($c, $wpdb, &$b) {
            if ($n !== 1) {
                return;
            }
            // Runner A's MySQL connection drops (and with it the lock) while message 1 is in flight ...
            usleep(20000);
            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', EVC_Push_Campaign::lock_name()));
            // ... and runner B (WP-Cron, another process) takes the lock, sends rows 2 and 3 and finishes the job.
            $b[] = $this->as_another_process(function () use ($c) {
                return $c->step();
            });
        };
        $this->assertSame(EVC_Push_Campaign::LOST_LOCK, $c->step(), 'A stops: its connection no longer holds the lock');
        $this->assertSame(array('done'), $b);
        $urls = array_column($this->requests, 'url');
        $this->assertCount(3, $urls, 'three subscriptions, three messages');
        $this->assertSame($urls, array_values(array_unique($urls)), 'no row was sent twice');
        $job = EVC_Push_Campaign::fresh_job();
        $this->assertSame('done', $job['state'], 'A did not bring the finished job back');
        $this->assertSame('', $job['title']);
        $this->assertSame(array('done', 'started'), array_column(EVC_Push_Campaign::audit(), 'event'), 'exactly one «done»');
        $this->assertFalse(wp_next_scheduled(EVC_Push_Campaign::CRON_HOOK, array($id)), 'a finished job has no next batch');
        $this->on_request = null;
        $this->assertSame('none', $c->step(), 'the next batch sends nothing');
        $this->assertCount(3, $this->requests);
        $this->assertSame(array('done', 'started'), array_column(EVC_Push_Campaign::audit(), 'event'));
    }

    public function test_round15_a_job_ended_without_the_lock_is_never_written_back_by_the_batch(): void {
        $this->subscribers(3);
        $admin = get_userdata($this->admin());
        $c = $this->parts['campaign'];
        $c->start($admin, 'Τ', 'Κ', 'all', 0, 3);
        $answers = array();
        $this->on_request = function (int $n) use ($c, $admin, &$answers) {
            if ($n !== 1) {
                return;
            }
            // Meanwhile another process gets NO named lock at all and stops the job through the lock-free conditional write.
            $answers[] = $this->as_another_process(function () use ($c, $admin) {
                $null_lock = $this->null_lock_filter();
                add_filter('query', $null_lock);
                try {
                    return $c->cancel($admin);
                } finally {
                    remove_filter('query', $null_lock);
                }
            });
        };
        $this->assertSame('none', $c->step(), 'the batch still holds the lock, but the job is no longer running');
        $this->assertSame(array('ok'), $answers);
        $this->assertCount(1, $this->requests, 'not one message after the stop');
        $this->assertSame('cancelled', EVC_Push_Campaign::fresh_job()['state'], 'never written back as running');
        $this->assertSame(array('cancelled', 'started'), array_column(EVC_Push_Campaign::audit(), 'event'));
        $this->on_request = null;
        $this->assertSame('none', $c->step());
        $this->assertCount(1, $this->requests);
    }

    public function test_round15_a_job_replaced_meanwhile_is_never_overwritten(): void {
        $this->subscribers(3);
        $c = $this->parts['campaign'];
        $c->start(get_userdata($this->admin()), 'Τ', 'Κ', 'all', 0, 3);
        global $wpdb;
        $newer = array('id' => 'fedcba9876543210', 'state' => 'running', 'title' => 'Ν', 'body' => 'Ν', 'audience' => 'all', 'days' => 0, 'planned' => 0, 'user_id' => 1, 'user_login' => 'x', 'started' => time(), 'touched' => time(), 'cursor' => 0, 'steps' => 0, 'sent' => 0, 'failed' => 0, 'removed' => 0, 'skipped' => 0);
        $this->on_request = function (int $n) use ($wpdb, $newer) {
            if ($n === 1) {
                // A newer job is stored meanwhile (a writer this batch's lock check could not see).
                $wpdb->update($wpdb->options, array('option_value' => maybe_serialize($newer)), array('option_name' => EVC_Push_Campaign::JOB_OPTION));
            }
        };
        $this->assertSame('none', $c->step());
        $this->assertCount(1, $this->requests);
        $job = EVC_Push_Campaign::fresh_job();
        $this->assertSame('fedcba9876543210', $job['id'], 'the newer job is left alone');
        $this->assertSame(0, (int) $job['cursor']);
        $this->assertSame(array('started'), array_column(EVC_Push_Campaign::audit(), 'event'));
    }

    public function test_round15_the_job_write_compares_and_replaces_in_one_statement(): void {
        $this->subscribers(2);
        $c = $this->parts['campaign'];
        $c->start(get_userdata($this->admin()), 'Τ', 'Κ', 'all', 0, 2);
        global $wpdb;
        $once = false;
        $between = function ($query) use ($wpdb, &$once) {
            if (!$once && stripos($query, 'UPDATE') === 0 && strpos($query, EVC_Push_Campaign::JOB_OPTION) !== false) {
                // Between the batch's read and its write, another writer ends the job.
                $once = true;
                $job = EVC_Push_Campaign::fresh_job();
                $job['state'] = 'cancelled';
                $wpdb->update($wpdb->options, array('option_value' => maybe_serialize($job)), array('option_name' => EVC_Push_Campaign::JOB_OPTION));
            }
            return $query;
        };
        add_filter('query', $between);
        try {
            $this->assertSame('none', $c->step(), 'the write matched nothing: the job had changed');
        } finally {
            remove_filter('query', $between);
        }
        $this->assertTrue($once);
        $this->assertSame(array(), $this->requests, 'the first row\'s cursor was never saved, so it was never sent');
        $this->assertSame('cancelled', EVC_Push_Campaign::fresh_job()['state']);
    }

    public function test_round15_a_batch_that_loses_the_lock_just_before_finishing_writes_nothing_and_retries_later(): void {
        $this->subscribers(1);
        $c = $this->parts['campaign'];
        $c->start(get_userdata($this->admin()), 'Τ', 'Κ', 'all', 0, 1);
        $id = EVC_Push_Campaign::fresh_job()['id'];
        $this->pop_batch_event($id);
        global $wpdb;
        $checks = 0;
        $inside = false;
        // Checks of the lock in this batch: before the message (1), after it (2), in finish() (3): the lock goes before 3.
        $drop = function ($query) use ($wpdb, &$checks, &$inside) {
            if (!$inside && stripos($query, 'IS_USED_LOCK(') !== false && ++$checks === 3) {
                $inside = true;
                $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', EVC_Push_Campaign::lock_name()));
                $inside = false;
            }
            return $query;
        };
        add_filter('query', $drop);
        try {
            $c->cron($id);
        } finally {
            remove_filter('query', $drop);
        }
        $this->assertGreaterThanOrEqual(3, $checks);
        $this->assertCount(1, $this->requests);
        $job = EVC_Push_Campaign::fresh_job();
        $this->assertSame('running', $job['state'], 'not written as done by a runner without the lock');
        $this->assertSame(1, (int) $job['sent']);
        $this->assertSame(array('started'), array_column(EVC_Push_Campaign::audit(), 'event'), 'no «done» without the write');
        $next = wp_next_scheduled(EVC_Push_Campaign::CRON_HOOK, array($id));
        $this->assertNotFalse($next, 'the job keeps its next batch');
        $this->assertEqualsWithDelta(time() + EVC_Push_Campaign::RETRY_SECONDS, $next, 2, 'a lost lock is retried like a busy one, not at once');
        $this->assertSame('done', $c->step());
        $this->assertCount(1, $this->requests, 'the saved cursor: nothing sent twice');
        $this->assertSame(array('done', 'started'), array_column(EVC_Push_Campaign::audit(), 'event'));
    }

    public function test_round15_cron_stops_a_job_that_never_gets_a_named_lock_and_the_club_pages_say_so(): void {
        $this->subscribers(3);
        $admin = $this->admin();
        wp_set_current_user($admin);
        $c = $this->parts['campaign'];
        $c->start(get_userdata($admin), 'Τ', 'Κ', 'all', 0, 3);
        $id = EVC_Push_Campaign::fresh_job()['id'];
        $null_lock = $this->null_lock_filter();
        $no_lock_crons = function (int $n) use ($c, $id, $null_lock) {
            add_filter('query', $null_lock);
            try {
                for ($i = 0; $i < $n; $i++) {
                    $this->pop_batch_event($id);
                    $c->cron($id);
                }
            } finally {
                remove_filter('query', $null_lock);
            }
        };
        $this->assertSame('', EVC_Push_Admin_Page::club_notice_html());
        $no_lock_crons(EVC_Push_Campaign::NO_LOCK_TRIES - 1);
        $job = EVC_Push_Campaign::fresh_job();
        $this->assertSame('running', $job['state']);
        $this->assertSame(EVC_Push_Campaign::NO_LOCK_TRIES - 1, EVC_Push_Campaign::no_lock_count($job));
        $this->assertNotFalse(wp_next_scheduled(EVC_Push_Campaign::CRON_HOOK, array($id)), 'retried while it may still get a lock');
        $this->assertStringContainsString(EVC_Push_Admin_Page::NO_LOCK_NOTICES['stuck'], EVC_Push_Admin_Page::club_notice_html());
        // A batch that gets the lock ends the run of refusals (the count starts again).
        $this->tick = 8.0; // one message, then the time budget ends the batch
        $this->pop_batch_event($id);
        $c->cron($id);
        $this->tick = 0.0;
        $this->assertCount(1, $this->requests);
        $this->assertSame(0, EVC_Push_Campaign::no_lock_count(EVC_Push_Campaign::fresh_job()));
        $this->assertSame('', EVC_Push_Admin_Page::club_notice_html(), 'no notice once batches get the lock again');
        $no_lock_crons(EVC_Push_Campaign::NO_LOCK_TRIES - 1);
        $this->assertSame('running', EVC_Push_Campaign::fresh_job()['state'], 'counted again from 0');
        $no_lock_crons(1);
        $job = EVC_Push_Campaign::fresh_job();
        $this->assertSame('stopped', $job['state'], 'bounded: not retried for ever');
        $this->assertSame(EVC_Push_Campaign::REASON_NO_LOCK, $job['reason']);
        $this->assertSame('', $job['title'], 'the text is cleared');
        $this->assertSame('', $job['body']);
        $this->assertFalse(wp_next_scheduled(EVC_Push_Campaign::CRON_HOOK, array($id)), 'no further batch');
        $this->assertCount(1, $this->requests, 'nothing was sent without the lock');
        $audit = EVC_Push_Campaign::audit();
        $this->assertSame(array('stopped', 'started'), array_column($audit, 'event'));
        $this->assertSame(EVC_Push_Campaign::REASON_NO_LOCK, $audit[0]['reason']);
        // The persistent notice on the Club pages (and only there, only for administrators who may send).
        $this->assertStringContainsString(EVC_Push_Admin_Page::NO_LOCK_NOTICES['stopped'], EVC_Push_Admin_Page::club_notice_html());
        $print = function (string $page): string {
            $_GET['page'] = $page;
            ob_start();
            try {
                $this->parts['page']->club_notice();
            } finally {
                unset($_GET['page']);
            }
            return (string) ob_get_clean();
        };
        $this->assertStringContainsString(EVC_Push_Admin_Page::NO_LOCK_NOTICES['stopped'], $print('evc-club-readiness'));
        $this->assertStringContainsString(EVC_Push_Admin_Page::NO_LOCK_NOTICES['stopped'], $print(EVC_Push_Admin_Page::MENU_SLUG));
        $this->assertSame('', $print('woocommerce'), 'not on other pages');
        wp_set_current_user($this->customer());
        $this->assertSame('', $print('evc-club'), 'not for a user who may not send');
        wp_set_current_user($admin);
        $this->assertStringContainsString(EVC_Push_Admin_Page::NO_LOCK_NOTICES['stopped'], $print('evc-club'), 'still there on the next visit');
        $this->assertNotFalse(has_action('admin_notices', array(EVC_Push_Plugin::parts()['page'], 'club_notice')), 'wired');
        // A new send replaces the stopped job; the notice goes.
        $this->assertSame('ok', $c->start(get_userdata($admin), 'Τ', 'Κ', 'all', 0, 3)['result']);
        $this->assertSame('', EVC_Push_Admin_Page::club_notice_html());
    }

    public function test_round15_the_page_says_when_a_batch_lost_the_lock_and_stops_continuing_after_three_in_a_row(): void {
        $this->subscribers(30);
        $admin = $this->admin();
        wp_set_current_user($admin);
        $this->parts['campaign']->start(get_userdata($admin), 'Τ', 'Κ', 'all', 0, 30);
        global $wpdb;
        $drop = true;
        $this->on_request = function () use ($wpdb, &$drop) {
            if ($drop) {
                $drop = false;
                $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', EVC_Push_Campaign::lock_name()));
            }
        };
        $lost = '';
        for ($round = 1; $round <= EVC_Push_Admin_Page::LOST_LOCK_STOP; $round++) {
            $drop = true;
            $this->redirects = array();
            $this->page_request(array('evc_push_action' => 'step') + ($lost === '' ? array() : array(EVC_Push_Admin_Page::FIELD_LOST => $lost)));
            $this->assertStringContainsString('evc_push=lost_lock', $this->redirects[0] ?? '');
            $this->assertStringContainsString(EVC_Push_Admin_Page::FIELD_LOST . '=' . $round, $this->redirects[0] ?? '', 'counted in a row');
            $_GET['evc_push'] = 'lost_lock';
            $_GET[EVC_Push_Admin_Page::FIELD_LOST] = (string) $round;
            $html = $this->parts['page']->html();
            $lost = (string) $round;
            $this->assertStringContainsString('name="' . EVC_Push_Admin_Page::FIELD_LOST . '" value="' . $round . '"', $html, 'the count travels with the next step');
            if ($round < EVC_Push_Admin_Page::LOST_LOCK_STOP) {
                $this->assertStringContainsString(EVC_Push_Admin_Page::RESULTS['lost_lock_step'], $html);
                $this->assertStringContainsString('<script', $html, 'the page goes on by itself');
            } else {
                $this->assertStringContainsString(EVC_Push_Admin_Page::RESULTS['lost_lock_repeated'], $html);
                $this->assertStringNotContainsString('<script', $html, 'no more automatic batches after three lost locks in a row');
                $this->assertStringContainsString('Συνέχεια αποστολής', $html);
            }
        }
        unset($_GET['evc_push'], $_GET[EVC_Push_Admin_Page::FIELD_LOST]);
        $this->assertCount(EVC_Push_Admin_Page::LOST_LOCK_STOP, $this->requests, 'one message per lost batch');
        // The administrator presses «Συνέχεια αποστολής»: a batch with the lock starts the count again.
        $this->on_request = null;
        $this->redirects = array();
        $this->page_request(array('evc_push_action' => 'step', EVC_Push_Admin_Page::FIELD_LOST => $lost));
        $this->assertStringContainsString('evc_push=ok', $this->redirects[0] ?? '');
        $this->assertStringNotContainsString(EVC_Push_Admin_Page::FIELD_LOST, $this->redirects[0] ?? '');
        $urls = array_column($this->requests, 'url');
        $this->assertSame($urls, array_values(array_unique($urls)), 'nothing sent twice');
    }

    public function test_round15_the_activated_notification_keeps_its_try_number_when_busy_and_retries_a_lost_lock(): void {
        $user = $this->active_subscriber('2026-12-21T08:00:00+00:00');
        $auto = $this->parts['auto'];
        $other = $this->other_connection();
        $lock = EVC_Push_Campaign::auto_lock_name();
        $this->assertSame('1', (string) $other->query("SELECT GET_LOCK('" . $lock . "', 0)")->fetchColumn());
        $this->assertSame('busy', $auto->activated($user, 3));
        $next = wp_next_scheduled(EVC_Push_Auto::ACTIVATED_HOOK, array($user, 3));
        $this->assertNotFalse($next, 'busy keeps the try number');
        $this->assertEqualsWithDelta(time() + 60, $next, 2);
        $this->assertFalse(wp_next_scheduled(EVC_Push_Auto::ACTIVATED_HOOK, array($user)), 'not a new first try');
        $this->assertFalse(wp_next_scheduled(EVC_Push_Auto::ACTIVATED_HOOK, array($user, 0)));
        $other->query("SELECT RELEASE_LOCK('" . $lock . "')");
        wp_unschedule_hook(EVC_Push_Auto::ACTIVATED_HOOK);
        // The connection loses the lock before the marker: nothing is sent, and the notification comes back like NO_LOCK.
        global $wpdb;
        $inside = false;
        $drop = function ($query) use ($wpdb, $lock, &$inside) {
            if (!$inside && stripos($query, 'IS_USED_LOCK(') !== false) {
                $inside = true;
                $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
                $inside = false;
            }
            return $query;
        };
        add_filter('query', $drop);
        try {
            $this->assertSame('lost_lock', $auto->activated($user, 2));
            $next = wp_next_scheduled(EVC_Push_Auto::ACTIVATED_HOOK, array($user, 3));
            $this->assertNotFalse($next, 'a lost lock is retried, not dropped');
            $this->assertEqualsWithDelta(time() + EVC_Push_Auto::NO_LOCK_RETRY_SECONDS, $next, 2);
            $this->assertSame('lost_lock', $auto->activated($user, EVC_Push_Auto::NO_LOCK_TRIES - 1));
            $this->assertFalse(wp_next_scheduled(EVC_Push_Auto::ACTIVATED_HOOK, array($user, EVC_Push_Auto::NO_LOCK_TRIES)), 'bounded like NO_LOCK');
        } finally {
            remove_filter('query', $drop);
        }
        $this->assertSame(array(), $this->requests);
        $this->assertSame('', (string) get_user_meta($user, EVC_Push_Auto::ACTIVATED_META, true));
        $this->assertSame('sent', $auto->activated($user, 3), 'the retry sends it once the lock holds');
        $this->assertCount(1, $this->requests);
        wp_unschedule_hook(EVC_Push_Auto::ACTIVATED_HOOK);
    }

    public function test_round15_the_expiry_run_state_is_written_only_by_the_lock_holder_over_what_it_read(): void {
        $first = $this->active_subscriber('2026-11-24T12:00:00+00:00');
        $auto = $this->parts['auto'];
        $lock = EVC_Push_Campaign::auto_lock_name();
        global $wpdb;
        // The lock goes just before the first (and only) member: nothing is sent, no marker, no run state.
        $dropped = false;
        $drop_first = function ($query) use ($wpdb, $lock, &$dropped) {
            if (!$dropped && stripos($query, 'IS_USED_LOCK(') !== false) {
                $dropped = true;
                $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
            }
            return $query;
        };
        add_filter('query', $drop_first);
        try {
            $this->assertSame('lost_lock', $auto->expiry());
        } finally {
            remove_filter('query', $drop_first);
        }
        $this->assertSame(array(), $this->requests, 'checked before the member, not only when the run state is written');
        $this->assertSame('', (string) get_user_meta($first, EVC_Push_Auto::EXPIRY_META, true));
        wp_clear_scheduled_hook(EVC_Push_Auto::EXPIRY_HOOK);
        $other = $this->other_connection();
        $this->on_request = function () use ($wpdb, $other, $lock) {
            // The connection drops during the message and another runner takes the automatic lock.
            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
            $this->assertSame('1', (string) $other->query("SELECT GET_LOCK('" . $lock . "', 0)")->fetchColumn());
        };
        $this->assertSame('lost_lock', $auto->expiry());
        $this->assertCount(1, $this->requests, 'the member got the notice once (the marker came first)');
        $this->assertNull(EVC_Push_Campaign::fresh_raw(EVC_Push_Auto::RUN_OPTION), 'a runner without the lock wrote no run state');
        $this->assertSame(array(), EVC_Push_Campaign::audit());
        $other->query("SELECT RELEASE_LOCK('" . $lock . "')");
        // Another runner's state appears between this run's read and its write: it is not overwritten.
        $this->active_subscriber('2026-11-24T15:00:00+00:00');
        $theirs = maybe_serialize(array('date' => '2026-11-21', 'after' => 999999, 'done' => true, 'sent' => 5, 'failed' => 0, 'removed' => 0, 'members' => 5));
        $this->on_request = function () use ($wpdb, $theirs) {
            $wpdb->update($wpdb->options, array('option_value' => $theirs), array('option_name' => EVC_Push_Auto::RUN_OPTION));
        };
        $this->assertSame('lost_lock', $auto->expiry());
        $this->assertCount(2, $this->requests, 'the second member once; the first member is not sent again');
        $this->assertSame($theirs, EVC_Push_Campaign::fresh_raw(EVC_Push_Auto::RUN_OPTION), 'the other runner\'s state is kept');
        $this->on_request = null;
        $this->assertSame('done', $auto->expiry(), 'that run was done');
        $this->assertCount(2, $this->requests);
    }

    public function test_round15_the_audit_merges_the_stores_newest_first(): void {
        $e = function (string $event, int $at): array {
            return array('event' => $event, 'at' => $at, 'job' => '', 'user_id' => 0, 'user_login' => '', 'audience' => 'all', 'days' => 0, 'planned' => 0, 'sent' => 0, 'failed' => 0, 'removed' => 0, 'skipped' => 0);
        };
        update_option(EVC_Push_Campaign::AUDIT_OPTION, array($e('done', 100), $e('started', 50)), false);
        update_option(EVC_Push_Campaign::AUTO_AUDIT_OPTION, array($e('auto_activated', 100), $e('auto_expiry', 75), $e('auto_activated', 20)), false);
        update_option(EVC_Push_Campaign::STOP_AUDIT_OPTION, array($e('stopped', 100), $e('cancelled', 60)), false);
        $merged = EVC_Push_Campaign::audit();
        $this->assertSame(array('stopped', 'done', 'auto_activated', 'auto_expiry', 'cancelled', 'started', 'auto_activated'), array_column($merged, 'event'), 'newest first; equal times: stops, then the administrators, then the automatic ones');
        $this->assertSame(array(100, 100, 100, 75, 60, 50, 20), array_map('intval', array_column($merged, 'at')));
        $many = array();
        for ($i = 0; $i < 80; $i++) {
            $many[] = $e('auto_activated', 1000 - $i);
        }
        update_option(EVC_Push_Campaign::AUTO_AUDIT_OPTION, $many, false);
        update_option(EVC_Push_Campaign::AUDIT_OPTION, array_slice($many, 0, 40), false);
        $this->assertCount(EVC_Push_Campaign::AUDIT_KEEP, EVC_Push_Campaign::audit(), 'at most AUDIT_KEEP entries');
    }

    // ---- automatic notifications --------------------------------------------------------------------------------

    // ---- round 16: the revision is compared; a refused cancel / takeover; a lock lost in every batch ends; starts first ---

    /** The reviewer's probe RV-A: a stale runner writes the job after the holder read it; the holder's next save is refused. */
    public function test_round16_a_write_by_a_stale_runner_refuses_the_holders_next_save_and_no_row_goes_twice(): void {
        $this->subscribers(3);
        $c = $this->parts['campaign'];
        $this->assertSame('ok', $c->start(get_userdata($this->admin()), 'Τ', 'Κ', 'all', 0, 3)['result']);
        $stale = array();
        $this->on_request = function (int $n) use ($c, &$stale) {
            if ($n !== 1) {
                return;
            }
            // While the holder's message 1 is in flight, a stale runner (its lock check passed just before it lost the lock)
            // sends row 2 and writes the job (still running, cursor past row 2) over the value the holder last wrote.
            $seconds = $this->now_seconds;
            $this->tick = 8.0; // one message, then the stale runner's time budget ends its batch
            try {
                $stale[] = $this->as_another_process(function () use ($c) {
                    return $c->step();
                });
            } finally {
                $this->tick = 0.0;
                $this->now_seconds = $seconds;
            }
        };
        $this->assertSame(EVC_Push_Campaign::NONE, $c->step(), 'the holder\'s save after message 1 is refused: another runner wrote the job');
        $this->assertSame(array('running'), $stale);
        $this->assertCount(2, $this->requests, 'the holder sent nothing after the refusal');
        $job = EVC_Push_Campaign::fresh_job();
        $this->assertSame('running', $job['state']);
        $this->assertSame((int) $this->rows()[1]['id'], (int) $job['cursor'], 'the newer cursor is kept, never put back');
        $this->on_request = null;
        $this->assertSame('done', $c->step());
        $urls = array_column($this->requests, 'url');
        $this->assertCount(3, $urls, 'three subscriptions, three messages');
        $this->assertSame($urls, array_values(array_unique($urls)), 'no row was sent twice');
        $this->assertSame(array('done', 'started'), array_column(EVC_Push_Campaign::audit(), 'event'));
    }

    /** Round 16: the revision alone does not decide: another job, or an ended one, with the same revision is never overwritten. */
    public function test_round16_the_job_id_and_the_running_state_are_still_compared_when_the_revision_matches(): void {
        $this->subscribers(3);
        $admin = get_userdata($this->admin());
        $c = $this->parts['campaign'];
        $c->start($admin, 'Τ', 'Κ', 'all', 0, 3);
        global $wpdb;
        $newer = array('id' => 'fedcba9876543210', 'state' => 'running', 'title' => 'Ν', 'body' => 'Ν', 'audience' => 'all', 'days' => 0, 'planned' => 0, 'user_id' => 1, 'user_login' => 'x', 'started' => time(), 'touched' => time(), 'cursor' => 0, 'steps' => 0, 'sent' => 0, 'failed' => 0, 'removed' => 0, 'skipped' => 0);
        $this->on_request = function (int $n) use ($wpdb, $newer) {
            if ($n === 1) {
                // Another running job, stored with the very revision the batch holds.
                $newer['rev'] = (int) EVC_Push_Campaign::fresh_job()['rev'];
                $wpdb->update($wpdb->options, array('option_value' => maybe_serialize($newer)), array('option_name' => EVC_Push_Campaign::JOB_OPTION));
            }
        };
        $this->assertSame('none', $c->step());
        $this->assertCount(1, $this->requests);
        $job = EVC_Push_Campaign::fresh_job();
        $this->assertSame('fedcba9876543210', $job['id'], 'the other job is left alone');
        $this->assertSame(0, (int) $job['cursor']);
        // The same job, ended by a write that kept its revision: never written back as running.
        delete_option(EVC_Push_Campaign::JOB_OPTION);
        $this->assertSame('ok', $c->start($admin, 'Τ', 'Κ', 'all', 0, 3)['result']);
        $this->on_request = function (int $n) use ($wpdb) {
            if ($n === 2) {
                $job = EVC_Push_Campaign::fresh_job();
                $job['state'] = 'cancelled';
                $wpdb->update($wpdb->options, array('option_value' => maybe_serialize($job)), array('option_name' => EVC_Push_Campaign::JOB_OPTION));
            }
        };
        $this->assertSame('none', $c->step());
        $this->assertCount(2, $this->requests, 'not one message after the job ended');
        $this->assertSame('cancelled', EVC_Push_Campaign::fresh_job()['state']);
    }

    public function test_round16_the_compare_and_replace_compares_the_stored_value_byte_for_byte(): void {
        $this->subscribers(2);
        $c = $this->parts['campaign'];
        $c->start(get_userdata($this->admin()), 'Τ', 'Κ', 'all', 0, 2);
        $login = (string) EVC_Push_Campaign::fresh_job()['user_login'];
        $this->assertNotSame(strtoupper($login), $login);
        global $wpdb;
        $once = false;
        $between = function ($query) use ($wpdb, $login, &$once) {
            if (!$once && stripos($query, 'UPDATE') === 0 && strpos($query, EVC_Push_Campaign::JOB_OPTION) !== false) {
                // Between the batch's read and its write another writer changes only the letter case of one value (same
                // length, same revision): the table's case-insensitive collation would still call the two values equal.
                $once = true;
                $raw = EVC_Push_Campaign::fresh_raw(EVC_Push_Campaign::JOB_OPTION);
                $wpdb->update($wpdb->options, array('option_value' => str_replace('"' . $login . '"', '"' . strtoupper($login) . '"', $raw)), array('option_name' => EVC_Push_Campaign::JOB_OPTION));
            }
            return $query;
        };
        add_filter('query', $between);
        try {
            $this->assertSame('none', $c->step(), 'the write matched nothing: the stored bytes had changed');
        } finally {
            remove_filter('query', $between);
        }
        $this->assertTrue($once);
        $this->assertSame(array(), $this->requests, 'the first row\'s cursor was never saved, so it was never sent');
        $this->assertSame(strtoupper($login), EVC_Push_Campaign::fresh_job()['user_login'], 'the other writer\'s value is kept');
    }

    /** Round 16 (L-b): RELEASE_LOCK at the first lock check of cancel() / of start()'s stale takeover. */
    public function test_round16_a_cancel_or_a_takeover_whose_write_under_the_lock_is_refused_does_not_say_it_happened(): void {
        $this->subscribers(2);
        $admin = get_userdata($this->admin());
        $c = $this->parts['campaign'];
        $this->assertSame('ok', $c->start($admin, 'Τ', 'Κ', 'all', 0, 2)['result']);
        $id = EVC_Push_Campaign::fresh_job()['id'];
        global $wpdb;
        $release_at_first_check = function () use ($wpdb) {
            $checks = 0;
            $inside = false;
            return function ($query) use ($wpdb, &$checks, &$inside) {
                if (!$inside && stripos($query, 'IS_USED_LOCK(') !== false && ++$checks === 1) {
                    $inside = true;
                    $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', EVC_Push_Campaign::lock_name()));
                    $inside = false;
                }
                return $query;
            };
        };
        $drop = $release_at_first_check();
        add_filter('query', $drop);
        try {
            $answer = $c->cancel($admin);
        } finally {
            remove_filter('query', $drop);
        }
        $this->assertSame(EVC_Push_Campaign::CANCEL_PENDING, $answer, 'not «stopped»: the write under the lock was refused');
        $job = EVC_Push_Campaign::fresh_job();
        $this->assertSame('running', $job['state']);
        $this->assertTrue(EVC_Push_Campaign::cancel_pending_for($job), 'the request stays recorded');
        $this->assertSame(array('started'), array_column(EVC_Push_Campaign::audit(), 'event'));
        $this->assertSame('cancelled', $c->step(), 'the next batch ends the job');
        $this->assertSame(array(), $this->requests);
        $this->assertSame(array('cancelled', 'started'), array_column(EVC_Push_Campaign::audit(), 'event'));
        // start(): a stale running job whose takeover is refused: nothing is created, and the answer says why.
        $this->assertSame('ok', $c->start($admin, 'Τ', 'Κ', 'all', 0, 2)['result']);
        $stale = EVC_Push_Campaign::fresh_job();
        $stale['touched'] = time() - EVC_Push_Campaign::STALE_SECONDS - 1;
        update_option(EVC_Push_Campaign::JOB_OPTION, $stale, false);
        $drop = $release_at_first_check();
        add_filter('query', $drop);
        try {
            $result = $c->start($admin, 'Ν', 'Ν', 'all', 0, 2);
        } finally {
            remove_filter('query', $drop);
        }
        $this->assertSame(EVC_Push_Campaign::LOST_LOCK, $result['result'], 'stops at the refused takeover');
        $job = EVC_Push_Campaign::fresh_job();
        $this->assertSame($stale['id'], $job['id'], 'the stale job was neither ended nor replaced');
        $this->assertSame('running', $job['state']);
        $this->assertSame(array('started', 'cancelled', 'started'), array_column(EVC_Push_Campaign::audit(), 'event'));
        $this->assertSame('ok', $c->start($admin, 'Ν', 'Ν', 'all', 0, 2)['result'], 'with the lock the takeover goes through');
        $this->assertSame(array('started', 'stopped', 'started', 'cancelled', 'started'), array_column(EVC_Push_Campaign::audit(), 'event'));
        $this->assertNotSame($id, EVC_Push_Campaign::fresh_job()['id']);
    }

    /** The reviewer's probe RV-C: GET_LOCK grants the lock, but IS_USED_LOCK never equals CONNECTION_ID (LOST_LOCK always). */
    public function test_round16_a_lock_lost_in_every_batch_ends_the_job_after_the_same_bound_and_cancel_can_end_it(): void {
        $this->subscribers(3);
        $admin = $this->admin();
        wp_set_current_user($admin);
        $c = $this->parts['campaign'];
        $this->assertSame('ok', $c->start(get_userdata($admin), 'Τ', 'Κ', 'all', 0, 3)['result']);
        $id = EVC_Push_Campaign::fresh_job()['id'];
        $never_mine = function ($query) {
            return stripos($query, 'IS_USED_LOCK(') !== false ? 'SELECT 0' : $query;
        };
        $lost_crons = function (int $n, string $job_id) use ($c, $never_mine) {
            add_filter('query', $never_mine);
            try {
                for ($i = 0; $i < $n; $i++) {
                    $this->pop_batch_event($job_id);
                    $c->cron($job_id);
                }
            } finally {
                remove_filter('query', $never_mine);
            }
        };
        $lost_crons(EVC_Push_Campaign::NO_LOCK_TRIES - 1, $id);
        $job = EVC_Push_Campaign::fresh_job();
        $this->assertSame('running', $job['state']);
        $this->assertSame(EVC_Push_Campaign::NO_LOCK_TRIES - 1, EVC_Push_Campaign::no_lock_count($job), 'each lost-lock batch counts');
        $this->assertNotFalse(wp_next_scheduled(EVC_Push_Campaign::CRON_HOOK, array($id)));
        $this->assertStringContainsString(EVC_Push_Admin_Page::NO_LOCK_NOTICES['stuck'], EVC_Push_Admin_Page::club_notice_html());
        $lost_crons(1, $id);
        $job = EVC_Push_Campaign::fresh_job();
        $this->assertSame('stopped', $job['state'], 'bounded: the same NO_LOCK_TRIES');
        $this->assertSame(EVC_Push_Campaign::REASON_NO_LOCK, $job['reason']);
        $this->assertSame('', $job['title']);
        $this->assertFalse(wp_next_scheduled(EVC_Push_Campaign::CRON_HOOK, array($id)), 'no further batch');
        $this->assertStringContainsString(EVC_Push_Admin_Page::NO_LOCK_NOTICES['stopped'], EVC_Push_Admin_Page::club_notice_html());
        $audit = EVC_Push_Campaign::audit();
        $this->assertSame(array('stopped', 'started'), array_column($audit, 'event'));
        $this->assertSame(EVC_Push_Campaign::REASON_NO_LOCK, $audit[0]['reason']);
        $this->assertSame(array(), $this->requests, 'nothing was sent without a usable lock');
        // «Διακοπή αποστολής» on such a job: its write under the lock is refused, so the lock-free conditional stop ends it.
        $this->assertSame('ok', $c->start(get_userdata($admin), 'Τ', 'Κ', 'all', 0, 3)['result']);
        $second = EVC_Push_Campaign::fresh_job()['id'];
        $lost_crons(1, $second);
        add_filter('query', $never_mine);
        try {
            $answer = $c->cancel(get_userdata($admin));
        } finally {
            remove_filter('query', $never_mine);
        }
        $this->assertSame(EVC_Push_Campaign::OK, $answer, 'ended now, not left pending for a batch that never keeps the lock');
        $job = EVC_Push_Campaign::fresh_job();
        $this->assertSame($second, $job['id']);
        $this->assertSame('cancelled', $job['state']);
        $this->assertSame($admin, (int) $job['cancelled_by']);
        $this->assertSame('', $job['title']);
        $this->assertFalse(wp_next_scheduled(EVC_Push_Campaign::CRON_HOOK, array($second)));
        $this->assertSame('cancelled', EVC_Push_Campaign::audit()[0]['event']);
        $this->assertSame(array(), $this->requests);
    }

    /**
     * Round 17 (D29 follow-up): without the rate limit, PN87 (a refused takeover ignored) was only caught when the lock was
     * gone. A takeover refused while this connection STILL holds the lock (the stale job changed between the read and
     * the conditional write) must answer BUSY and create nothing; it must never overwrite the other writer's job.
     */
    public function test_round17_a_takeover_refused_while_holding_the_lock_answers_busy_and_creates_nothing(): void {
        $this->subscribers(1);
        $admin = get_userdata($this->admin());
        $c = $this->parts['campaign'];
        $this->assertSame(array('result' => 'ok'), $c->start($admin, 'Τ', 'Κ', 'all', 0, 1));
        $stale = EVC_Push_Campaign::fresh_job();
        $stale['touched'] = time() - EVC_Push_Campaign::STALE_SECONDS - 1;
        update_option(EVC_Push_Campaign::JOB_OPTION, $stale, false);
        global $wpdb;
        $once = false;
        $between = function ($query) use ($wpdb, &$once) {
            if (!$once && stripos($query, 'UPDATE') === 0 && strpos($query, EVC_Push_Campaign::JOB_OPTION) !== false) {
                // Between start()'s read of the stale job and its conditional write, another writer moves that job on.
                $once = true;
                $job = EVC_Push_Campaign::fresh_job();
                $job['rev'] = (int) $job['rev'] + 1;
                $job['touched'] = time();
                $wpdb->update($wpdb->options, array('option_value' => maybe_serialize($job)), array('option_name' => EVC_Push_Campaign::JOB_OPTION));
            }
            return $query;
        };
        add_filter('query', $between);
        try {
            $result = $c->start($admin, 'Ν', 'Ν', 'all', 0, 1);
        } finally {
            remove_filter('query', $between);
        }
        $this->assertTrue($once);
        $this->assertSame(array('result' => EVC_Push_Campaign::BUSY), $result, 'the lock is still held: BUSY, not a new send');
        $job = EVC_Push_Campaign::fresh_job();
        $this->assertSame($stale['id'], $job['id'], 'the other writer\'s job is neither ended nor replaced');
        $this->assertSame('running', $job['state']);
        $this->assertSame((int) $stale['rev'] + 1, (int) $job['rev']);
        $this->assertSame(array('started'), array_column(EVC_Push_Campaign::audit(), 'event'));
    }

    /**
     * Round 17 (round-16 review N2): a compare-and-replace whose statement FAILS (a database error: wpdb->query() answers
     * false, not 0) is a refused write, never a written one; also through a batch, which then sends nothing.
     */
    public function test_round17_a_compare_and_replace_that_fails_with_a_database_error_counts_as_not_written(): void {
        global $wpdb;
        $broken = function ($query) use ($wpdb) {
            if ((stripos($query, 'UPDATE') === 0 || stripos($query, 'INSERT IGNORE') === 0) && (strpos($query, 'evc_round17_probe') !== false || strpos($query, EVC_Push_Campaign::JOB_OPTION) !== false)) {
                return "UPDATE {$wpdb->prefix}evc_round17_no_such_table SET x = 1";
            }
            return $query;
        };
        update_option('evc_round17_probe', 'before', false);
        $raw = EVC_Push_Campaign::fresh_raw('evc_round17_probe');
        $quiet = $wpdb->suppress_errors(true);
        add_filter('query', $broken);
        try {
            $this->assertFalse(EVC_Push_Campaign::replace_option('evc_round17_probe', $raw, 'after'), 'UPDATE failed: not written');
            $this->assertFalse(EVC_Push_Campaign::replace_option('evc_round17_probe_new', null, 'x'), 'INSERT failed: not written');
        } finally {
            remove_filter('query', $broken);
            $wpdb->suppress_errors($quiet);
        }
        $this->assertSame($raw, EVC_Push_Campaign::fresh_raw('evc_round17_probe'), 'the stored value is unchanged');
        $this->assertNull(EVC_Push_Campaign::fresh_raw('evc_round17_probe_new'));
        // Through a batch: its first save (the cursor before the send) fails with a database error: nothing is sent.
        $this->subscribers(2);
        $c = $this->parts['campaign'];
        $this->assertSame(array('result' => 'ok'), $c->start(get_userdata($this->admin()), 'Τ', 'Κ', 'all', 0, 2));
        $before = EVC_Push_Campaign::fresh_raw(EVC_Push_Campaign::JOB_OPTION);
        $quiet = $wpdb->suppress_errors(true);
        add_filter('query', $broken);
        try {
            $this->assertSame('none', $c->step(), 'the failed write is refused, the batch stops');
        } finally {
            remove_filter('query', $broken);
            $wpdb->suppress_errors($quiet);
        }
        $this->assertSame(array(), $this->requests, 'no message without a saved cursor');
        $this->assertSame($before, EVC_Push_Campaign::fresh_raw(EVC_Push_Campaign::JOB_OPTION));
        delete_option('evc_round17_probe');
    }

    /** D29 (replaces round 16's «a start is counted before its job»): a failed job write never blocks the next start. */
    public function test_d29_a_failed_job_write_leaves_nothing_behind_that_blocks_the_next_start(): void {
        $this->subscribers(1);
        $admin = get_userdata($this->admin());
        $c = $this->parts['campaign'];
        $fail = function () {
            throw new RuntimeException('the job write fails');
        };
        add_filter('pre_update_option_' . EVC_Push_Campaign::JOB_OPTION, $fail);
        try {
            $c->start($admin, 'Τ', 'Κ', 'all', 0, 1);
            $this->fail('the job write was expected to fail');
        } catch (RuntimeException $e) {
            $this->assertSame('the job write fails', $e->getMessage());
        } finally {
            remove_filter('pre_update_option_' . EVC_Push_Campaign::JOB_OPTION, $fail);
        }
        $this->assertNull(EVC_Push_Campaign::fresh_job(), 'no job');
        $this->assertFalse(get_option('evc_club_push_starts'), 'no start-time store is written any more');
        $this->assertSame(array('result' => 'ok'), $c->start($admin, 'Τ', 'Κ', 'all', 0, 1), 'the next start goes through at once');
    }

    // ---- round 18: owner decision D30 (rich messages inside the app) and round-17 review Low 2 ---------------------

    const MESSAGES = '/evangelou-club-customer/v1/me/messages';

    /** A GET of the member's messages as the browser sends it (through rest_post_dispatch: no-store, mapped errors). */
    private function messages_as(int $user, string $route = self::MESSAGES): WP_REST_Response {
        wp_set_current_user(0);
        $this->login_as($user);
        $request = new WP_REST_Request('GET', $route);
        $request->set_header('X-WP-Nonce', $this->nonce());
        $_SERVER['HTTP_X_WP_NONCE'] = $this->nonce();
        return apply_filters('rest_post_dispatch', rest_ensure_response(rest_get_server()->dispatch($request)), rest_get_server(), $request);
    }

    /** Every evc_club_message post of any status. */
    private function message_posts(): array {
        return get_posts(array('post_type' => EVC_Club_Messages::POST_TYPE, 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids', 'suppress_filters' => true));
    }

    private function rich_fixture(): string {
        $uploads = wp_get_upload_dir()['baseurl'];
        return '<h2 style="color: #e03e2d; position: fixed">Νέα γεύση</h2><p onclick="alert(1)">Δοκιμάστε <strong>σήμερα</strong> το <em>νέο</em> γλυκό.</p>'
            . '<script>alert(1)</script><iframe src="https://evil.example/x"></iframe>'
            . '<ul><li style="text-align: center; background: url(https://evil.example/t.png)">ένα</li><li>δύο</li></ul>'
            . '<p><a href="javascript:alert(1)">κακό</a> <a href="https://evangelou.gr/gluka" onmouseover="x()" target="_self" class="c">καλό</a></p>'
            . '<img src="https://evil.example/track.png" alt="ξένη"><img src="' . $uploads . '/2026/11/glyko.jpg" alt="γλυκό" width="600" height="400" srcset="https://evil.example/a.jpg 2x" onerror="x()">'
            . '<form action="https://evil.example"><input name="p"></form><span style="font-size: 18px; font-size: 400px">μέγεθος</span>';
    }

    public function test_round18_d30_the_body_is_sanitised_by_an_explicit_allowlist(): void {
        $uploads = wp_get_upload_dir()['baseurl'];
        $html = EVC_Club_Messages::sanitize_html($this->rich_fixture());
        foreach (array('<script', 'alert(', '<iframe', 'onclick', 'onmouseover', 'onerror', 'javascript:', 'position', 'background', 'evil.example', '<form', '<input', 'class=', 'target=', 'srcset', '400px') as $bad) {
            $this->assertStringNotContainsString($bad, $html, $bad);
        }
        foreach (array('<h2 style="color: #e03e2d">Νέα γεύση</h2>', '<strong>σήμερα</strong>', '<em>νέο</em>', '<li style="text-align: center">ένα</li>', '<a href="https://evangelou.gr/gluka">καλό</a>', '<span style="font-size: 18px">μέγεθος</span>', 'src="' . $uploads . '/2026/11/glyko.jpg"', 'alt="γλυκό"', 'width="600"') as $good) {
            $this->assertStringContainsString($good, $html, $good);
        }
        $this->assertSame(1, substr_count($html, '<img'), 'only the media-library image');
        $this->assertSame('', EVC_Club_Messages::sanitize_html('<script>x</script>'), 'nothing left = empty');
        $this->assertSame('', EVC_Club_Messages::sanitize_html("\xff\xfe"), 'invalid UTF-8');
        $this->assertSame('color: rgb(1, 2, 3); text-decoration: underline', EVC_Club_Messages::clean_style('COLOR: RGB(1, 2, 3); text-decoration: underline; color: red; behavior: url(x)'));
        $this->assertTrue(EVC_Club_Messages::allowed_link('mailto:club@evangelou.gr'));
        $this->assertTrue(EVC_Club_Messages::allowed_link('tel:+302510000000'));
        foreach (array('javascript:alert(1)', 'data:text/html,x', '/relative', '//evil.example/x', 'https://ev"il.example') as $bad) {
            $this->assertFalse(EVC_Club_Messages::allowed_link($bad), $bad);
        }
        foreach (array($uploads . '/../wp-config.php', 'https://evil.example/wp-content/uploads/a.jpg', $uploads . 'x/a.jpg', 'data:image/png;base64,AAAA') as $bad) {
            $this->assertFalse(EVC_Club_Messages::allowed_image($bad), $bad);
        }
        $this->assertTrue(EVC_Club_Messages::allowed_image($uploads . '/2026/11/a.jpg'));
    }

    public function test_round18_d30_a_send_leaves_one_message_that_only_its_audience_reads(): void {
        $now = $this->clock->now()->getTimestamp();
        $active = $this->customer();
        $this->member($active);
        $this->memberships[$active] = array('state' => 'active', 'paid_until_utc' => gmdate('Y-m-d\TH:i:sP', $now + 20 * 86400));
        $sub = $this->subscription();
        $this->subscribe_as($active, $sub);
        $quiet = $this->customer();
        $this->member($quiet);
        $this->memberships[$quiet] = array('state' => 'active', 'paid_until_utc' => gmdate('Y-m-d\TH:i:sP', $now + 5 * 86400));
        $expired = $this->customer();
        $this->member($expired);
        $this->memberships[$expired] = array('state' => 'expired', 'paid_until_utc' => gmdate('Y-m-d\TH:i:sP', $now - 86400));
        $this->subscribe_as($expired, $this->subscription());
        $stranger = $this->customer();
        $admin = $this->admin();
        wp_set_current_user($admin);
        $preview = $this->page_post(array('evc_push_action' => 'preview', 'evc_push_title' => 'Νέα γεύση', 'evc_push_body' => 'Ελάτε να τη δοκιμάσετε.', 'evc_push_audience' => 'active', EVC_Push_Admin_Page::FIELD_MESSAGE => $this->rich_fixture()));
        $this->assertSame('preview', $preview['code']);
        $this->assertSame(1, $preview['subscriptions']);
        $this->assertSame(2, $preview['app_members'], 'the app reaches the active member without a subscription too');
        $this->assertStringNotContainsString('<script', $preview['form']['message']);
        $this->assertSame(array(), $this->message_posts(), 'a preview creates nothing');
        $r = $this->page_post(array('evc_push_action' => 'send', 'evc_push_title' => 'Νέα γεύση', 'evc_push_body' => 'Ελάτε να τη δοκιμάσετε.', 'evc_push_audience' => 'active', EVC_Push_Admin_Page::FIELD_MESSAGE => $preview['form']['message']));
        $this->assertSame('started', $r['code']);
        $job = EVC_Push_Campaign::fresh_job();
        $id = (int) $job['message'];
        $this->assertGreaterThan(0, $id);
        $this->assertSame(array($id), $this->message_posts());
        $this->assertSame('private', get_post_status($id));
        $this->assertSame('done', $this->parts['campaign']->step());
        $this->assertCount(1, $this->requests, 'only the active subscriber');
        $plain = json_decode((string) $this->decrypt($sub['endpoint'], $this->requests[0]['body'], $sub['keys']['auth']), true);
        $this->assertSame(array('title' => 'Νέα γεύση', 'body' => 'Ελάτε να τη δοκιμάσετε.', 'message' => $id), $plain, 'the notification names its message');
        $this->assertSame($id, (int) EVC_Push_Campaign::fresh_job()['message'], 'the job keeps the number, not the text');

        $list = $this->messages_as($active);
        $this->assertSame(200, $list->get_status());
        $this->assertSame('no-store, private, max-age=0', $list->get_headers()['Cache-Control']);
        $data = $list->get_data();
        $this->assertSame(1, $data['unread']);
        $this->assertSame(array('id', 'title', 'summary', 'sent_at', 'unread'), array_keys($data['messages'][0]));
        $this->assertSame(array($id, 'Νέα γεύση', 'Ελάτε να τη δοκιμάσετε.', true), array($data['messages'][0]['id'], $data['messages'][0]['title'], $data['messages'][0]['summary'], $data['messages'][0]['unread']));
        $this->assertSame(1, preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $data['messages'][0]['sent_at']));
        $this->assertCount(1, $this->messages_as($quiet)->get_data()['messages'], 'in the audience without a subscription');
        $this->assertSame(array(), $this->messages_as($expired)->get_data()['messages'], 'not in the audience when it was sent');
        $this->assertSame(404, $this->messages_as($expired, self::MESSAGES . '/' . $id)->get_status(), 'not in the audience: not found');
        $this->assertSame('message_not_found', $this->messages_as($expired, self::MESSAGES . '/' . $id)->get_data()['error']['code'] ?? null);
        $this->assertSame(404, $this->messages_as($stranger)->get_status(), 'no Club member row');
        $this->assertSame('not_a_member', $this->messages_as($stranger)->get_data()['error']['code'] ?? null);
        $this->assertSame('message_not_found', $this->messages_as($active, self::MESSAGES . '/' . ($id + 1000))->get_data()['error']['code'] ?? null, 'no such message: the same answer');

        $one = $this->messages_as($active, self::MESSAGES . '/' . $id);
        $this->assertSame(200, $one->get_status());
        $this->assertSame('no-store, private, max-age=0', $one->get_headers()['Cache-Control']);
        $message = $one->get_data()['message'];
        $this->assertSame(array('id', 'title', 'summary', 'sent_at', 'html'), array_keys($message));
        $this->assertStringContainsString('<strong>σήμερα</strong>', $message['html']);
        foreach (array('<script', 'onclick', 'javascript:', 'evil.example') as $bad) {
            $this->assertStringNotContainsString($bad, $message['html'], $bad);
        }
        $this->assertSame(0, $this->messages_as($active)->get_data()['unread'], 'opening it marks it read');
        $this->assertSame($id, EVC_Club_Messages::last_read($active));
    }

    public function test_round18_d30_a_message_to_all_reaches_every_enabled_member_and_is_sanitised_again_on_the_way_out(): void {
        $a = $this->customer();
        $this->member($a);
        $b = $this->customer();
        $this->member($b);
        $off = $this->customer();
        $this->member($off, 'disabled');
        $this->subscribe_as($a, $this->subscription());
        $c = $this->parts['campaign'];
        $this->assertSame(array('result' => 'ok'), $c->start(get_userdata($this->admin()), 'Για όλους', 'Καλή εβδομάδα.', 'all', 0, 1, '<p>Γεια</p>'));
        $id = (int) EVC_Push_Campaign::fresh_job()['message'];
        $this->assertSame(array(), get_post_meta($id, EVC_Club_Messages::META_RECIPIENT, false), 'no list for «all»');
        foreach (array($a, $b) as $user) {
            $this->assertSame($id, $this->messages_as($user)->get_data()['messages'][0]['id']);
        }
        $this->assertSame(403, $this->messages_as($off)->get_status());
        $this->assertSame('member_disabled', $this->messages_as($off)->get_data()['error']['code']);
        // Something written past the allowlist straight into the database is still cleaned when it is read.
        global $wpdb;
        $wpdb->update($wpdb->posts, array('post_content' => '<p>Γεια</p><script>alert(1)</script><img src="https://evil.example/x.png">'), array('ID' => $id));
        clean_post_cache($id);
        $html = $this->messages_as($b, self::MESSAGES . '/' . $id)->get_data()['message']['html'];
        $this->assertSame('<p>Γεια</p>', $html);
        // A draft (a send that was never confirmed) is nobody's.
        $draft = EVC_Club_Messages::create_draft('Πρόχειρο', 'Όχι ακόμη.', '', 'all', 0, null, 1);
        $this->assertSame(404, $this->messages_as($a, self::MESSAGES . '/' . $draft)->get_status());
        $this->assertCount(1, $this->messages_as($a)->get_data()['messages']);
    }

    public function test_round18_d30_the_message_type_is_private_everywhere_and_never_the_till_accounts(): void {
        $type = get_post_type_object(EVC_Club_Messages::POST_TYPE);
        $this->assertNotNull($type);
        $this->assertFalse($type->public);
        $this->assertFalse($type->publicly_queryable);
        $this->assertTrue($type->exclude_from_search);
        $this->assertFalse($type->show_in_rest);
        $this->assertFalse($type->show_ui);
        $this->assertFalse($type->query_var);
        $this->assertFalse($type->rewrite);
        $this->assertFalse(is_post_type_viewable($type));
        $this->assertNotContains(EVC_Club_Messages::POST_TYPE, get_post_types(array('public' => true)));
        $this->assertArrayNotHasKey(EVC_Club_Messages::POST_TYPE, apply_filters('wp_sitemaps_post_types', array(EVC_Club_Messages::POST_TYPE => $type)));
        $routes = implode("\n", array_keys(rest_get_server()->get_routes()));
        $this->assertStringNotContainsString(EVC_Club_Messages::POST_TYPE, $routes);
        $user = $this->customer();
        $this->member($user);
        $this->assertSame(array('result' => 'ok'), $this->parts['campaign']->start(get_userdata($this->admin()), 'Τ', 'Κ', 'all', 0, 0, '<p>κρυφό κείμενο</p>'));
        $id = (int) EVC_Push_Campaign::fresh_job()['message'];
        // The front end and the search never find it, for a visitor or a signed-in customer.
        foreach (array(0, $user) as $who) {
            wp_set_current_user($who);
            $this->assertSame(array(), (new WP_Query(array('p' => $id)))->posts);
            $this->assertSame(array(), (new WP_Query(array('s' => 'κρυφό')))->posts);
            $this->assertSame(array(), (new WP_Query(array('post_type' => 'any', 'post_status' => 'publish')))->posts);
            $this->assertFalse(current_user_can('read_post', $id));
        }
        $till = (int) self::factory()->user->create(array('role' => EVC_Staff_Role::ROLE));
        get_userdata($till)->add_cap(EVC_Staff_Role::CAP_MANAGE);
        foreach (array('edit_post', 'read_post', 'delete_post') as $cap) {
            $this->assertFalse(user_can($till, $cap, $id), 'till: ' . $cap);
        }
        $admin = $this->admin();
        $this->assertTrue(user_can($admin, 'edit_post', $id), 'an administrator with the Club capability');
        $plain = (int) self::factory()->user->create(array('role' => 'editor'));
        $this->assertFalse(user_can($plain, 'edit_post', $id), 'an editor without the Club capability');
    }

    /**
     * Round-17 review Low 2 (mutant R17W-3): the lock is lost between start()'s acquire_lock() and its job write: LOST_LOCK,
     * no job, and (D30) no message left behind either.
     */
    public function test_round18_a_lock_lost_before_the_job_write_creates_neither_a_job_nor_a_message(): void {
        $this->subscribers(1);
        $admin = get_userdata($this->admin());
        $c = $this->parts['campaign'];
        $lost = function ($query) {
            return stripos($query, 'IS_USED_LOCK(') !== false ? 'SELECT 0' : $query;
        };
        add_filter('query', $lost);
        try {
            $result = $c->start($admin, 'Τ', 'Κ', 'all', 0, 1, '<p>κείμενο</p>');
        } finally {
            remove_filter('query', $lost);
        }
        $this->assertSame(array('result' => EVC_Push_Campaign::LOST_LOCK), $result);
        $this->assertNull(EVC_Push_Campaign::fresh_job(), 'no job');
        $this->assertSame(array(), $this->message_posts(), 'no message, not even a draft');
        $this->assertSame(array(), EVC_Push_Campaign::audit());
        // A failing job write (an exception) leaves no message either.
        $fail = function () {
            throw new RuntimeException('the job write fails');
        };
        add_filter('pre_update_option_' . EVC_Push_Campaign::JOB_OPTION, $fail);
        try {
            $c->start($admin, 'Τ', 'Κ', 'all', 0, 1);
            $this->fail('expected the job write to fail');
        } catch (RuntimeException $e) {
            $this->assertSame('the job write fails', $e->getMessage());
        } finally {
            remove_filter('pre_update_option_' . EVC_Push_Campaign::JOB_OPTION, $fail);
        }
        $this->assertSame(array(), $this->message_posts());
        $this->assertSame(array('result' => 'ok'), $c->start($admin, 'Τ', 'Κ', 'all', 0, 1));
        $this->assertCount(1, $this->message_posts());
    }

    public function test_round18_d30_the_page_writes_with_the_editor_and_previews_the_formatted_message(): void {
        $user = $this->customer();
        $this->member($user);
        $this->subscribe_as($user, $this->subscription());
        wp_set_current_user($this->admin());
        $html = $this->page_request(array('evc_push_action' => 'preview', 'evc_push_title' => 'Νέα γεύση', 'evc_push_body' => 'Ελάτε.', 'evc_push_audience' => 'all', EVC_Push_Admin_Page::FIELD_MESSAGE => '<p><strong>Έντονο</strong></p><script>x()</script>'));
        $this->assertStringContainsString('Το μήνυμα στην εφαρμογή', $html);
        $this->assertStringContainsString('<strong>Έντονο</strong>', $html);
        $this->assertStringNotContainsString('<script>x()', $html);
        $this->assertStringContainsString('σε 1 μέλη', $html);
        $this->assertStringContainsString('name="' . EVC_Push_Admin_Page::FIELD_MESSAGE . '"', $html);
        $this->assertSame(1, preg_match('/<input type="hidden" name="evc_push_message" value="([^"]*)"/', $html, $m));
        $this->assertSame('<p><strong>Έντονο</strong></p>', html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'), 'the confirmation carries the sanitised body');
        $this->assertStringContainsString('Πλήρες μήνυμα (προαιρετικό)', $html);
        $too_long = str_repeat('<p>' . str_repeat('α', 100) . '</p>', 120);
        $long = $this->page_post(array('evc_push_action' => 'preview', 'evc_push_title' => 'Τ', 'evc_push_body' => 'Κ', 'evc_push_audience' => 'all', EVC_Push_Admin_Page::FIELD_MESSAGE => $too_long));
        $this->assertSame('problem', $long['code'], 'a message past the limit is refused');
        $this->assertSame('message_long', $long['problem'] ?? null);
    }

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
