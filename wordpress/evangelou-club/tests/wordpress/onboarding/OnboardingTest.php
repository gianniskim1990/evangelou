<?php
/**
 * STAGE-07 wp-onboarding suite: EVC_CLUB_ONBOARDING_ENABLED + EVC_CLUB_CUSTOMER_ENABLED (both exact true), staff
 * flag OFF, no commerce plugins (so the hand-off answers "unavailable"; the checkout path is the commerce suite).
 * Real WordPress: users, nonces, transients, REST dispatch.
 */
final class OnboardingTest extends EVC_WP_Test_Case {
    const ASSET_URL = 'https://cdn.example.test/customer-app';

    /** @var string */
    private $asset_dir;
    /** @var string */
    private $log;

    public function set_up() {
        parent::set_up();
        if (get_role('customer') === null) {
            add_role('customer', 'Customer', array('read' => true)); // WooCommerce's role (no WooCommerce in this suite)
        }
        add_filter('send_auth_cookies', '__return_false'); // the CLI test run cannot send cookies; the user is still set
        $_SERVER['REMOTE_ADDR'] = '203.0.113.' . wp_rand(10, 250);
        $this->asset_dir = sys_get_temp_dir() . '/evc-join-assets-' . wp_generate_password(8, false, false);
        mkdir($this->asset_dir . '/assets', 0777, true);
        foreach (array('assets/customer-Ab12Cd34.js', 'assets/customer-Ef56Gh78.css') as $file) {
            file_put_contents($this->asset_dir . '/' . $file, '/* test asset */');
        }
        file_put_contents($this->asset_dir . '/manifest.json', wp_json_encode(array(
            'customer.html' => array('file' => 'assets/customer-Ab12Cd34.js', 'isEntry' => true, 'css' => array('assets/customer-Ef56Gh78.css')),
        )));
        $this->log = (string) tempnam(sys_get_temp_dir(), 'evc-log');
        ini_set('error_log', $this->log);
        $this->boot();
    }

    public function tear_down() {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->asset_dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->asset_dir);
        $log = (string) file_get_contents($this->log);
        ini_restore('error_log');
        @unlink($this->log);
        $this->assertStringNotContainsString('@example', $log, 'no personal data in the log');
        parent::tear_down();
    }

    private function boot(?callable $now = null, ?int $row_ceiling = null): void {
        EVC_Onboarding_Plugin::boot(array('assets' => new EVC_Staff_Assets($this->asset_dir, self::ASSET_URL, EVC_Customer_Shell::ASSET_ENTRY), 'now' => $now, 'row_ceiling' => $row_ceiling));
        rest_get_server();
        $this->parts()['controller']->register_routes(true); // these parts answer (a test may boot again with its own clock)
    }

    private function parts(): array {
        $parts = EVC_Onboarding_Plugin::parts();
        $this->assertIsArray($parts);
        return $parts;
    }

    private static function form(array $override = array()): array {
        return array_merge(array('name' => 'Μαρία Παπαδοπούλου', 'phone' => '694 123 4567', 'email' => 'maria.' . wp_generate_password(6, false, false) . '@example.gr', 'password' => 'kafes-sto-limani-7', 'evc_extra' => ''), $override);
    }

    /** A POST from the join page (its own Origin, the page's nonce unless given). */
    /** $nonce: null = the page's nonce, 'none' = no X-WP-Nonce header at all. */
    private function post(string $route, $body, ?string $nonce = null, ?string $origin = 'self'): WP_REST_Response {
        $server = rest_get_server();
        $request = new WP_REST_Request('POST', '/' . EVC_Customer_Rest_Security::NAMESPACE_V1 . $route);
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(is_string($body) ? $body : wp_json_encode($body));
        $nonce = $nonce ?? wp_create_nonce('wp_rest');
        if ($nonce === 'none') {
            unset($_SERVER['HTTP_X_WP_NONCE']);
        } else {
            $request->set_header('X-WP-Nonce', $nonce);
            $_SERVER['HTTP_X_WP_NONCE'] = $nonce;
        }
        if ($origin !== null) {
            $request->set_header('Origin', $origin === 'self' ? EVC_Rest_Join_Controller::site_origin() : $origin);
        }
        $auth = $server->check_authentication();
        $result = is_wp_error($auth) ? rest_convert_error_to_response($auth) : $server->dispatch($request);
        return apply_filters('rest_post_dispatch', rest_ensure_response($result), $server, $request);
    }

    private function config(array $page): array {
        $this->assertSame(200, $page['status'], $page['body']);
        $this->assertSame(1, preg_match('#<script type="application/json" id="evc-customer-config">(.*?)</script>#s', $page['body'], $m));
        $config = json_decode($m[1], true);
        $this->assertIsArray($config);
        return $config;
    }

    // ---- flag and pages -----------------------------------------------------------------------------

    public function test_the_flag_needs_both_constants_as_boolean_true(): void {
        $this->assertTrue(EVC_Onboarding_Feature::enabled());
        foreach (array(1, '1', 'true', 'yes', array(true), null, false) as $value) {
            $this->assertFalse(EVC_Onboarding_Feature::is_enabled_value($value), var_export($value, true));
        }
        $this->assertNotFalse(has_action('template_redirect', array('EVC_Onboarding_Plugin', 'serve')));
        $this->assertContains(EVC_Onboarding_Shell::QUERY_VAR, apply_filters('query_vars', array()));
        $routes = rest_get_server()->get_routes();
        foreach (array('/account', '/terms', '/checkout') as $route) {
            $this->assertArrayHasKey('/' . EVC_Customer_Rest_Security::NAMESPACE_V1 . '/join' . $route, $routes, $route);
        }
    }

    public function test_the_join_page_is_public_and_starts_with_the_account(): void {
        $config = $this->config($this->parts()['shell']->join(new WP_User(0)));
        $this->assertSame('join', $config['mode']);
        $this->assertSame('register', $config['step']);
        $this->assertTrue(wp_verify_nonce($config['nonce'], 'wp_rest') !== false, 'the nonce of the signed-out visitor');
        $home = wp_parse_url(home_url('/'), PHP_URL_HOST);
        foreach (array('restBase', 'joinUrl', 'memberUrl', 'loginUrl', 'logoutUrl', 'lostPasswordUrl') as $key) {
            $this->assertSame($home, wp_parse_url($config[$key], PHP_URL_HOST), $key);
        }
        $this->assertStringContainsString(rawurlencode(EVC_Onboarding_Shell::join_url()), $config['loginUrl'], 'sign-in returns to the join page');
        $this->assertSame(EVC_Customer_Shell::shell_url(), $config['memberUrl']);
    }

    public function test_redirect_parameters_are_never_used(): void {
        $_GET['redirect_to'] = 'https://evil.example/steal';
        $_GET['next'] = '//evil.example';
        $_REQUEST = array_merge($_REQUEST, $_GET);
        try {
            $page = $this->parts()['shell']->join(new WP_User(0));
        } finally {
            unset($_GET['redirect_to'], $_GET['next'], $_REQUEST['redirect_to'], $_REQUEST['next']);
        }
        $this->assertStringNotContainsString('evil.example', $page['body']);
        $customer = $this->factory()->user->create(array('role' => 'customer'));
        wp_set_current_user($customer);
        $r = $this->post('/join/checkout', array('url' => 'https://evil.example', 'redirect_to' => 'https://evil.example'));
        $this->assertStringNotContainsString('evil.example', (string) wp_json_encode($r->get_data()));
        $this->assertSame('', EVC_Onboarding_Shell::same_site_url('https://evil.example/terms'));
        $this->assertSame('', EVC_Onboarding_Shell::same_site_url('javascript:alert(1)'));
        $this->assertSame('', EVC_Onboarding_Shell::same_site_url('//evil.example/x'));
        $host = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
        $this->assertSame('', EVC_Onboarding_Shell::same_site_url('https://' . $host . '.evil.example/x'), 'a look-alike host');
        $this->assertSame('', EVC_Onboarding_Shell::same_site_url('https://' . $host . ':8443/x'), 'another port');
        $this->assertSame(home_url('/terms/'), EVC_Onboarding_Shell::same_site_url(home_url('/terms/')));
    }

    public function test_the_steps_of_a_signed_in_account(): void {
        $shell = $this->parts()['shell'];
        $admin = $this->factory()->user->create(array('role' => 'administrator'));
        $this->assertSame('not_customer', $shell->step(get_userdata($admin)));
        $this->assertSame('not_customer', $shell->step(get_userdata($this->create_staff_user())));
        $customer = $this->factory()->user->create(array('role' => 'customer'));
        $this->assertSame('unavailable', $shell->step(get_userdata($customer)), 'no Club product configured in this suite: never a checkout');
    }

    public function test_the_poster_is_for_the_shop_only_and_encodes_only_the_join_url(): void {
        $shell = $this->parts()['shell'];
        $anon = $shell->poster(new WP_User(0));
        $this->assertSame(302, $anon['status']);
        $this->assertStringStartsWith(wp_login_url(), (string) $anon['location']);
        $this->assertSame(403, $shell->poster(get_userdata($this->factory()->user->create(array('role' => 'customer'))))['status']);
        $this->assertSame(403, $shell->poster(get_userdata($this->factory()->user->create(array('role' => 'subscriber'))))['status']);
        foreach (array(get_userdata($this->factory()->user->create(array('role' => 'administrator'))), get_userdata($this->create_staff_user())) as $user) {
            $config = $this->config($shell->poster($user));
            $this->assertSame(array('mode', 'joinUrl', 'appVersion'), array_keys($config), 'no nonce, no REST base, nothing about anyone');
            $this->assertSame(EVC_Onboarding_Shell::join_url(true), $config['joinUrl']);
        }
        $this->assertSame(add_query_arg('src', 'store', EVC_Onboarding_Shell::join_url()), EVC_Onboarding_Shell::join_url(true));
    }

    public function test_pages_are_private_and_framed_by_nobody(): void {
        $page = $this->parts()['shell']->join(new WP_User(0));
        $this->assertSame('no-cache, must-revalidate, max-age=0, no-store, private', $page['headers']['Cache-Control'], "WordPress's own no-cache headers");
        $this->assertSame('noindex, nofollow', $page['headers']['X-Robots-Tag']);
        $this->assertStringContainsString("frame-ancestors 'none'", $page['headers']['Content-Security-Policy']);
        $this->assertStringContainsString('<meta name="referrer" content="no-referrer">', $page['body']);
    }

    // ---- account creation --------------------------------------------------------------------------

    public function test_a_new_account_is_a_signed_in_customer_with_exactly_what_was_typed(): void {
        $form = self::form(array('email' => '  New.Member@Example.GR '));
        $r = $this->post('/join/account', $form);
        $this->assertSame(200, $r->get_status());
        $this->assertSame(array('result' => 'ok'), $r->get_data());
        $user = get_user_by('email', 'new.member@example.gr');
        $this->assertInstanceOf(WP_User::class, $user);
        $this->assertSame(array('customer'), array_values($user->roles));
        $this->assertSame($user->ID, get_current_user_id(), 'signed in');
        $this->assertNull(EVC_Customer_Auth::check_customer($user), 'a customer account of the member area');
        $this->assertTrue(wp_check_password($form['password'], $user->user_pass, $user->ID));
        $this->assertSame('Μαρία', get_user_meta($user->ID, 'first_name', true));
        $this->assertSame('Παπαδοπούλου', get_user_meta($user->ID, 'last_name', true));
        $this->assertSame('+306941234567', get_user_meta($user->ID, 'billing_phone', true));
        $this->assertSame('new.member@example.gr', get_user_meta($user->ID, 'billing_email', true));
        $this->assertMatchesRegularExpression('/^club_[0-9a-f]{12}$/D', $user->user_login, 'the login name reveals nothing');
        $this->assertFalse(EVC_Onboarding_Handoff::terms_accepted($user->ID), 'the terms are a separate step');
        // Signed in now: a second registration is refused (no second account from the same session).
        $this->assertSame(array('result' => 'signed_in'), $this->post('/join/account', self::form())->get_data());
    }

    public function test_an_existing_email_is_never_attached_or_revealed(): void {
        $existing = $this->factory()->user->create(array('role' => 'customer', 'user_email' => 'known@example.gr', 'user_pass' => 'their-own-password-1'));
        $before = get_userdata($existing);
        $answers = array();
        foreach (array('known@example.gr', ' KNOWN@example.gr ', 'known@example.gr') as $email) {
            $r = $this->post('/join/account', self::form(array('email' => $email, 'password' => 'attacker-pass-99')));
            $answers[] = array($r->get_status(), $r->get_data());
        }
        $this->assertSame(array_fill(0, 3, array(200, array('result' => 'check_account'))), $answers);
        $this->assertSame(0, get_current_user_id(), 'never signed in to it');
        $after = get_userdata($existing);
        $this->assertSame($before->user_pass, $after->user_pass, 'never changed');
        $this->assertSame('', (string) get_user_meta($existing, 'billing_phone', true));
        $this->assertCount(1, get_users(array('search' => '*known@example.gr*', 'search_columns' => array('user_email'))));
        // The same answer when the e-mail is taken as a USER NAME: nothing tells the two apart.
        $this->factory()->user->create(array('user_login' => 'other@example.gr', 'user_email' => 'unrelated@example.gr'));
        $this->assertSame(array('result' => 'check_account'), $this->post('/join/account', self::form(array('email' => 'other@example.gr')))->get_data());
    }

    public function test_invalid_input_answers_with_field_codes_only(): void {
        $r = $this->post('/join/account', self::form(array('name' => 'Μαρία', 'phone' => '2510123456', 'email' => 'x@y', 'password' => 'short')));
        $this->assertSame(array('result' => 'invalid', 'fields' => array('name' => 'name_one_word', 'phone' => 'phone_invalid', 'email' => 'email_invalid', 'password' => 'password_short')), $r->get_data());
        $this->assertSame(0, get_current_user_id());
        $this->assertEnvelope($this->post('/join/account', '{not json'), 400, 'invalid_request');
        $this->assertSame(array('result' => 'invalid', 'fields' => array('name' => 'name_missing', 'phone' => 'phone_missing', 'email' => 'email_missing', 'password' => 'password_short')), $this->post('/join/account', '[]')->get_data());
    }

    public function test_the_honeypot_creates_nothing(): void {
        $form = self::form(array('evc_extra' => 'https://spam.example'));
        $this->assertSame(array('result' => 'not_completed'), $this->post('/join/account', $form)->get_data());
        $this->assertFalse(get_user_by('email', $form['email']));
        $this->assertSame(array('result' => 'not_completed'), $this->post('/join/account', self::form(array('evc_extra' => array('x'))))->get_data());
        $this->assertSame(2, $this->parts()['registration']->failures($_SERVER['REMOTE_ADDR']));
    }

    public function test_a_bad_nonce_or_another_origin_is_refused(): void {
        $form = self::form();
        // A wrong or empty nonce: WordPress core refuses it before the route runs (rest_cookie_invalid_nonce -> 401 envelope).
        $this->assertEnvelope($this->post('/join/account', $form, 'deadbeef00'), 401, 'unauthorized');
        $this->assertEnvelope($this->post('/join/account', $form, ''), 401, 'unauthorized');
        $this->assertFalse(get_user_by('email', $form['email']));
        // Another origin (a cross-site form or script carrying a stolen page nonce): refused by the route itself.
        $this->assertEnvelope($this->post('/join/account', $form, null, 'https://evil.example'), 403, 'forbidden');
        $this->assertEnvelope($this->post('/join/account', $form, null, 'null'), 403, 'forbidden');
        $this->assertFalse(get_user_by('email', $form['email']));
        $this->assertSame(0, $this->parts()['registration']->failures($_SERVER['REMOTE_ADDR']), 'round 3 (R4): an origin refusal is not counted');
        // No nonce at all: refused by the route, and NOT counted (anyone could otherwise spend a client's budget).
        $this->assertEnvelope($this->post('/join/account', $form, 'none'), 403, 'forbidden');
        $this->assertSame(0, $this->parts()['registration']->failures($_SERVER['REMOTE_ADDR']), 'a request without a nonce is not counted');
        $this->post('/join/account', self::form(array('phone' => '')));
        $this->assertSame(1, $this->parts()['registration']->failures($_SERVER['REMOTE_ADDR']), 'while invalid input still is');
        $this->assertSame(array('result' => 'ok'), $this->post('/join/account', $form, null, null)->get_data(), 'no Origin header: the nonce alone binds it');
    }

    public function test_ten_failures_in_five_minutes_block_the_address(): void {
        $clock = 1_900_000_000;
        $this->boot(function () use (&$clock) {
            return $clock;
        });
        for ($i = 0; $i < EVC_Onboarding_Registration::MAX_FAILURES; $i++) {
            $this->assertSame('invalid', $this->post('/join/account', self::form(array('phone' => '')))->get_data()['result']);
            $clock += 20;
        }
        $form = self::form();
        $this->assertEnvelope($this->post('/join/account', $form), 429, 'rate_limited');
        $this->assertFalse(get_user_by('email', $form['email']), 'even a valid form waits');
        $other = $_SERVER['REMOTE_ADDR'];
        $_SERVER['REMOTE_ADDR'] = '198.51.100.7';
        $this->assertSame(array('result' => 'ok'), $this->post('/join/account', self::form())->get_data(), 'another address is not affected');
        wp_set_current_user(0);
        $_SERVER['REMOTE_ADDR'] = $other;
        $clock += EVC_Onboarding_Registration::WINDOW_SECONDS;
        $this->assertSame(array('result' => 'ok'), $this->post('/join/account', $form)->get_data(), 'after the window');
        wp_set_current_user(0);
        $this->post('/join/account', self::form(array('phone' => '')));
        $this->assertSame(1, $this->parts()['registration']->failures($other), 'a failure after the window starts a new window');
        $clock += 60;
        $this->post('/join/account', self::form(array('phone' => '')));
        $this->assertSame(2, $this->parts()['registration']->failures($other));
        $_SERVER['REMOTE_ADDR'] = '';
        wp_set_current_user(0);
        $this->assertSame(array('result' => 'unavailable'), $this->post('/join/account', self::form())->get_data(), 'no address: fail closed');
    }

    public function test_successes_are_capped_per_client_and_in_total(): void {
        $this->assertSame(10, EVC_Onboarding_Registration::max_per_client_hour(), 'owner decision D17');
        $this->assertSame(30, EVC_Onboarding_Registration::max_per_network_hour(), 'round 4 (N1)');
        $this->assertSame(100, EVC_Onboarding_Registration::max_per_hour());
        for ($i = 1; $i <= 10; $i++) {
            $this->assertSame(array('result' => 'ok'), $this->post('/join/account', self::form())->get_data(), "account $i");
            wp_set_current_user(0);
        }
        $form = self::form();
        $this->assertSame(array('result' => 'try_later'), $this->post('/join/account', $form)->get_data(), 'the 11th account from one client this hour');
        $this->assertFalse(get_user_by('email', $form['email']), 'nothing created');
        $this->assertSame(0, $this->parts()['registration']->failures($_SERVER['REMOTE_ADDR']), 'a success cap is not a failure');
        $this->assertSame('', EVC_Onboarding_Registration::admin_notice_html(time()), 'a client cap alerts nobody');
        // The total cap: 100 accounts an hour from all clients together.
        for ($i = 11; $i <= 100; $i++) {
            $_SERVER['REMOTE_ADDR'] = '198.51.' . intdiv($i, 200) . '.' . $i;
            $this->assertSame(array('result' => 'ok'), $this->post('/join/account', self::form())->get_data(), "account $i");
            wp_set_current_user(0);
        }
        $_SERVER['REMOTE_ADDR'] = '192.0.2.1';
        $this->assertSame(array('result' => 'try_later'), $this->post('/join/account', self::form())->get_data(), 'the 101st account of the hour: still blocking');
        $_SERVER['REMOTE_ADDR'] = '192.0.2.2';
        $this->assertSame(array('result' => 'try_later'), $this->post('/join/account', self::form())->get_data());
        // One PII-free log line per hour, and the admin notice.
        $log = (string) file_get_contents($this->log);
        $this->assertSame(1, substr_count($log, 'the hourly limit of new Club accounts'), 'logged once, not per refusal');
        $this->assertStringNotContainsString('192.0.2', $log);
        $notice = EVC_Onboarding_Registration::admin_notice_html(time());
        $this->assertStringContainsString('EVC_CLUB_JOIN_MAX_PER_HOUR', $notice);
        $this->assertStringContainsString('100 ανά ώρα', $notice);
        $this->assertSame('', EVC_Onboarding_Registration::admin_notice_html(time() + DAY_IN_SECONDS + 1), 'gone after a day');
        $this->assertNotFalse(has_action('admin_notices', array('EVC_Onboarding_Registration', 'admin_notice')));
        wp_set_current_user(self::factory()->user->create(array('role' => 'administrator')));
        ob_start();
        EVC_Onboarding_Registration::admin_notice();
        $this->assertSame($notice, ob_get_clean(), 'administrators see it');
        wp_set_current_user(self::factory()->user->create(array('role' => 'customer')));
        ob_start();
        EVC_Onboarding_Registration::admin_notice();
        $this->assertSame('', ob_get_clean(), 'nobody else');
        $this->assertSame(EVC_Onboarding_Rules::cap(0, 3), 3);
        $this->assertSame(EVC_Onboarding_Rules::cap('10', 3), 3, 'only an int configures it');
        $this->assertSame(EVC_Onboarding_Rules::cap(1001, 30), 30);
        $this->assertSame(EVC_Onboarding_Rules::cap(12, 30), 12);
    }

    public function test_an_ipv6_48_has_its_own_aggregate_cap(): void {
        for ($i = 1; $i <= 30; $i++) {
            $_SERVER['REMOTE_ADDR'] = '2001:db8:aa:' . dechex($i) . '::1'; // thirty different /64s of one /48
            $this->assertSame(array('result' => 'ok'), $this->post('/join/account', self::form())->get_data(), "account $i");
            wp_set_current_user(0);
        }
        $this->assertSame(30, $this->parts()['registration']->network_successes('2001:db8:aa:ffff::9'));
        $_SERVER['REMOTE_ADDR'] = '2001:db8:aa:ff::1'; // a 31st /64 of the same /48
        $this->assertSame(array('result' => 'try_later'), $this->post('/join/account', self::form())->get_data(), 'the /48 is full');
        $_SERVER['REMOTE_ADDR'] = '2001:db8:ab:1::1'; // another /48
        $this->assertSame(array('result' => 'ok'), $this->post('/join/account', self::form())->get_data());
        wp_set_current_user(0);
        $this->assertSame(0, $this->parts()['registration']->network_successes('192.0.2.5'), 'IPv4 has no /48 bucket');
        $this->assertSame('', EVC_Onboarding_Registration::network_key_of('::ffff:192.0.2.5'));
        $this->assertSame(EVC_Onboarding_Registration::network_key_of('2001:db8:aa::1'), EVC_Onboarding_Registration::network_key_of('2001:db8:aa:ffff:1:2:3:4'));
        $this->assertNotSame(EVC_Onboarding_Registration::network_key_of('2001:db8:aa::1'), EVC_Onboarding_Registration::key_of('2001:db8:aa::1'), 'a separate bucket');
    }

    /** Round 4 (B1): failures are bounded per IPv6 /48 too (30 per 5 minutes in aggregate). */
    public function test_failures_are_also_limited_per_ipv6_48(): void {
        $clock = 1_900_000_000;
        $this->boot(function () use (&$clock) {
            return $clock;
        });
        $this->assertSame(30, EVC_Onboarding_Registration::MAX_NETWORK_FAILURES);
        for ($i = 1; $i <= 30; $i++) {
            $_SERVER['REMOTE_ADDR'] = '2001:db8:bb:' . dechex($i) . '::1'; // one failure from each of 30 /64s
            $this->assertSame('invalid', $this->post('/join/account', self::form(array('phone' => '')))->get_data()['result'], "failure $i");
        }
        $this->assertSame(30, $this->parts()['registration']->network_failures('2001:db8:bb::9'));
        $this->assertSame(1, $this->parts()['registration']->failures('2001:db8:bb:1::2'), 'each /64 has one');
        $_SERVER['REMOTE_ADDR'] = '2001:db8:bb:ff::1'; // a fresh /64 of the same /48, with a valid form
        $form = self::form();
        $this->assertEnvelope($this->post('/join/account', $form), 429, 'rate_limited');
        $this->assertFalse(get_user_by('email', $form['email']));
        $_SERVER['REMOTE_ADDR'] = '2001:db8:bc::1'; // another /48
        $this->assertSame(array('result' => 'ok'), $this->post('/join/account', self::form())->get_data());
        wp_set_current_user(0);
        $_SERVER['REMOTE_ADDR'] = '192.0.2.44';
        $this->post('/join/account', self::form(array('phone' => '')));
        $this->assertSame(0, $this->parts()['registration']->network_failures('192.0.2.44'), 'IPv4 has no /48 bucket');
        $clock += EVC_Onboarding_Registration::WINDOW_SECONDS;
        $_SERVER['REMOTE_ADDR'] = '2001:db8:bb:ff::1';
        $this->assertSame(array('result' => 'ok'), $this->post('/join/account', $form)->get_data(), 'after the window');
    }

    /**
     * Round 4 (B1): the failure rows are bounded, and the ceiling can NEVER close sign-up. At the ceiling no new failure
     * row is written (existing rows still count), the shop is told once an hour, and failure rows are deleted as soon
     * as their own 5-minute window is over.
     */
    public function test_the_failure_rows_are_bounded_and_never_close_sign_up(): void {
        global $wpdb;
        $clock = 1_900_000_000;
        $this->boot(function () use (&$clock) {
            return $clock;
        }, 3);
        $rows = function () use ($wpdb): int {
            return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like('evc_join_rl_f_') . '%', $wpdb->esc_like('evc_join_rl_m_') . '%'));
        };
        foreach (array('198.51.100.1', '198.51.100.2', '198.51.100.3') as $ip) {
            $_SERVER['REMOTE_ADDR'] = $ip;
            $this->post('/join/account', self::form(array('phone' => '')));
        }
        $this->assertSame(3, $rows());
        $this->assertSame('', EVC_Onboarding_Registration::admin_notice_html($clock));
        $clock += 10;
        $_SERVER['REMOTE_ADDR'] = '198.51.100.4';
        $this->post('/join/account', self::form(array('phone' => '')));
        $this->assertSame(3, $rows(), 'no new failure row at the ceiling');
        $this->assertSame(0, $this->parts()['registration']->failures('198.51.100.4'));
        $_SERVER['REMOTE_ADDR'] = '198.51.100.1';
        $this->post('/join/account', self::form(array('phone' => '')));
        $this->assertSame(2, $this->parts()['registration']->failures('198.51.100.1'), 'an existing row still counts (one atomic UPDATE)');
        // Sign-up stays OPEN at the ceiling.
        $_SERVER['REMOTE_ADDR'] = '198.51.100.9';
        $form = self::form();
        $this->assertSame(array('result' => 'ok'), $this->post('/join/account', $form)->get_data(), 'the ceiling never refuses an account');
        $this->assertInstanceOf(WP_User::class, get_user_by('email', $form['email']));
        wp_set_current_user(0);
        // The shop is told: one log line (no personal data) and the admin notice.
        $log = (string) file_get_contents($this->log);
        $this->assertSame(1, substr_count($log, 'reached the row ceiling'), 'logged once, not per failure');
        $this->assertStringNotContainsString('198.51.100', $log);
        $notice = EVC_Onboarding_Registration::admin_notice_html($clock);
        $this->assertStringContainsString('Οι εγγραφές συνεχίζουν κανονικά', $notice);
        $this->assertSame(1, substr_count($notice, 'notice-warning'), 'only the row notice');
        // Five minutes after they started, the failure rows are deleted at the next ceiling decision, even when a
        // cleanup failed before (a deadlock is ignored and never logged).
        $clock += EVC_Onboarding_Registration::WINDOW_SECONDS;
        $deadlock = function ($sql) {
            return strpos($sql, 'DELETE FROM') === 0 && strpos($sql, 'SUBSTRING_INDEX(option_value') !== false ? 'DELETE FROM evc_no_such_table_deadlock' : $sql;
        };
        add_filter('query', $deadlock);
        try {
            $_SERVER['REMOTE_ADDR'] = '198.51.100.5';
            $this->post('/join/account', self::form(array('phone' => '')));
        } finally {
            remove_filter('query', $deadlock);
        }
        $this->assertSame(3, $rows(), 'the cleanup failed: still full, nothing new written');
        $this->assertStringNotContainsString('evc_no_such_table', (string) file_get_contents($this->log), 'a failed cleanup is not logged');
        $_SERVER['REMOTE_ADDR'] = '198.51.100.6';
        $this->post('/join/account', self::form(array('phone' => '')));
        $this->assertSame(1, $rows(), 'the expired failure rows are gone; the new failure is recorded');
        $this->assertSame(1, $this->parts()['registration']->failures('198.51.100.6'));
        $this->assertSame(0, (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s", 'evc_join_rl_f_' . EVC_Onboarding_Registration::key_of('198.51.100.1'))));
    }

    public function test_an_ipv6_client_counts_by_its_64_network(): void {
        $_SERVER['REMOTE_ADDR'] = '2001:db8:aa:bb::1';
        $this->post('/join/account', self::form(array('phone' => '')));
        $this->post('/join/account', self::form(array('phone' => '')));
        $this->assertSame(2, $this->parts()['registration']->failures('2001:db8:aa:bb:ffff:ffff:ffff:fffe'), 'the same /64');
        $this->assertSame(0, $this->parts()['registration']->failures('2001:db8:aa:bc::1'), 'another /64');
        $this->assertSame(EVC_Onboarding_Registration::key_of('192.0.2.7'), EVC_Onboarding_Registration::key_of('::ffff:192.0.2.7'), 'IPv4-mapped = IPv4');
    }

    public function test_each_counted_event_is_one_atomic_statement_never_a_transient(): void {
        global $wpdb;
        $seen = array();
        $log = function ($sql) use (&$seen) {
            $seen[] = $sql;
            return $sql;
        };
        add_filter('query', $log);
        try {
            $this->post('/join/account', self::form(array('phone' => '')));
            $this->post('/join/account', self::form(array('phone' => ''))); // the second event finds the row
        } finally {
            remove_filter('query', $log);
        }
        $writes = array_values(array_filter($seen, function ($q) {
            return strpos($q, 'evc_join_rl_') !== false && preg_match('/^\s*(INSERT|UPDATE|REPLACE|DELETE)/i', $q) === 1;
        }));
        $this->assertCount(2, array_filter($writes, function ($q) {
            return stripos($q, 'ON DUPLICATE KEY UPDATE') !== false;
        }), 'one INSERT … ON DUPLICATE KEY UPDATE per event');
        foreach ($writes as $q) {
            $this->assertMatchesRegularExpression('/^\s*(INSERT|DELETE)/i', $q, 'no read-modify-write UPDATE');
        }
        $this->assertSame(array(), array_values(array_filter($seen, function ($q) {
            return stripos($q, '_transient_evc_join') !== false;
        })), 'no transient');
        $row = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'evc_join_rl_f_' . EVC_Onboarding_Registration::key_of($_SERVER['REMOTE_ADDR'])));
        $this->assertMatchesRegularExpression('/^2\|\d+$/D', (string) $row, 'count and window start only, no address');
        $this->assertSame(0, (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value LIKE %s", 'evc_join_rl_%', '%203.0.113%')));
    }

    public function test_the_pages_are_never_page_cached(): void {
        $called = 0;
        $count = function ($headers) use (&$called) {
            $called++;
            return $headers;
        };
        add_filter('nocache_headers', $count);
        $this->assertNull($this->parts()['shell']->handle('', new WP_User(0)), 'not our page: nothing done');
        $this->assertSame(0, $called);
        $page = $this->parts()['shell']->handle('1', new WP_User(0));
        $this->assertSame(200, $page['status']);
        $this->assertTrue(defined('DONOTCACHEPAGE') && DONOTCACHEPAGE === true, 'caching plugins are told');
        $this->assertGreaterThanOrEqual(1, $called, "WordPress's own no-cache headers");
        $this->assertStringContainsString('no-store', $page['headers']['Cache-Control']);
        $this->assertStringContainsString('max-age=0', $page['headers']['Cache-Control']);
        $this->assertArrayHasKey('Expires', $page['headers']);
        $poster = $this->parts()['shell']->handle('poster', new WP_User(0));
        $this->assertSame(302, $poster['status']);
        $this->assertStringContainsString('no-store', $poster['headers']['Cache-Control'], 'the sign-in redirect too');
    }

    public function test_the_registration_logs_nothing_about_the_person(): void {
        $form = self::form(array('email' => 'private.person@example.gr'));
        $this->post('/join/account', $form);
        $this->assertStringNotContainsString('private.person', (string) file_get_contents($this->log));
        $this->assertSame(0, (int) $GLOBALS['wpdb']->get_var($GLOBALS['wpdb']->prepare("SELECT COUNT(*) FROM {$GLOBALS['wpdb']->options} WHERE option_value LIKE %s", '%private.person%')), 'not in any option or transient');
    }

    // ---- terms and hand-off ------------------------------------------------------------------------

    public function test_terms_are_recorded_with_their_version_only_for_the_customer_themself(): void {
        $customer = $this->factory()->user->create(array('role' => 'customer'));
        $this->login_as($customer);
        $this->assertSame(array('result' => 'invalid'), $this->post('/join/terms', array('accept' => 'yes'))->get_data());
        $this->assertSame(array('result' => 'invalid'), $this->post('/join/terms', array())->get_data());
        $this->assertFalse(EVC_Onboarding_Handoff::terms_accepted($customer));
        $this->assertSame(array('result' => 'ok'), $this->post('/join/terms', array('accept' => true, 'user_id' => 1))->get_data());
        $this->assertTrue(EVC_Onboarding_Handoff::terms_accepted($customer));
        $meta = get_user_meta($customer, EVC_Onboarding_Handoff::TERMS_META, true);
        $this->assertSame(array('version', 'accepted_at'), array_keys($meta));
        $this->assertSame(EVC_Onboarding_Rules::TERMS_VERSION, $meta['version']);
        $this->assertFalse(EVC_Onboarding_Handoff::terms_accepted(1), 'never someone else');
        update_user_meta($customer, EVC_Onboarding_Handoff::TERMS_META, array('version' => 'club-terms-v0', 'accepted_at' => 'x'));
        $this->assertFalse(EVC_Onboarding_Handoff::terms_accepted($customer), 'an older version is asked again');
    }

    public function test_the_signed_in_routes_need_a_customer_with_a_nonce(): void {
        wp_set_current_user(0);
        $this->assertEnvelope($this->post('/join/terms', array('accept' => true)), 401, 'unauthorized');
        $this->assertEnvelope($this->post('/join/checkout', array()), 401, 'unauthorized');
        $admin = $this->factory()->user->create(array('role' => 'administrator'));
        $this->login_as($admin);
        $this->assertEnvelope($this->post('/join/terms', array('accept' => true)), 403, 'forbidden');
        $customer = $this->factory()->user->create(array('role' => 'customer'));
        $this->login_as($customer);
        $this->assertEnvelope($this->post('/join/terms', array('accept' => true), 'deadbeef00'), 401, 'unauthorized');
        $this->assertSame(array('result' => 'ok'), $this->post('/join/terms', array('accept' => true))->get_data());
        $r = $this->post('/join/checkout', array());
        $this->assertSame(503, $r->get_status());
        $this->assertSame(array('result' => 'unavailable'), $r->get_data(), 'no Club product configured: never a checkout');
    }
}
