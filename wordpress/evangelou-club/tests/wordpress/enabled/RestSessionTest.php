<?php

/** Feature ENABLED: GET /session and POST /session/end in real WordPress. */
final class RestSessionTest extends EVC_WP_Test_Case {
    const STATUS = '/evangelou-club/v1/session';
    const END = '/evangelou-club/v1/session/end';

    public function test_status_reports_session_without_any_personal_data(): void {
        $staff = $this->create_staff_user();
        $this->login_as($staff);
        $r = $this->rest('GET', self::STATUS, null, $this->nonce());

        $this->assertSame(200, $r->get_status(), wp_json_encode($r->get_data()));
        $data = $r->get_data();
        $this->assertTrue($data['authenticated']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $data['session']['expires_at']);
        $this->assertSame(1800, $data['session']['idle_timeout_seconds']);
        $this->assertSame(43200, $data['session']['absolute_lifetime_seconds']);
        $this->assertSame(300, $data['session']['idle_lock_seconds']);
        $this->assertSame(array('qr_lookup' => false, 'phone_lookup' => false, 'coffee_redemption' => false, 'history' => false), $data['features']);
        $json = wp_json_encode($data);
        $user = get_userdata($staff);
        foreach (array($user->user_login, $user->user_email, 'nonce', 'token') as $needle) {
            $this->assertStringNotContainsString($needle, $json);
        }
        $this->assertStringContainsString('no-store', $r->get_headers()['Cache-Control']);
        $this->assertArrayHasKey('X-WP-Nonce', rest_get_server()->sent_headers, 'core refreshes the nonce via header only');
    }

    public function test_status_never_extends_the_inactivity_window(): void {
        $staff = $this->create_staff_user();
        $token = $this->login_as($staff);
        $manager = WP_Session_Tokens::get_instance($staff);
        $session = $manager->get($token);
        $session['login'] = time() - 600;
        $session['expiration'] = $session['login'] + EVC_Staff_Session::ABSOLUTE_SECONDS;
        $manager->update($token, $session);
        $key = EVC_Staff_Session::activity_key($token);
        update_user_meta($staff, $key, (string) (time() - 500));

        $this->assertSame(200, $this->rest('GET', self::STATUS, null, $this->nonce())->get_status());
        $this->assertSame((string) (time() - 500), get_user_meta($staff, $key, true), 'status checks are not activity');

        $this->rest('POST', $this->redeem_route('mem_0123456789abcdef0123456789abcdef'), array('request_id' => self::uuid4(), 'coffee_code' => 'espresso'), $this->nonce());
        $this->assertGreaterThanOrEqual(time() - 2, (int) get_user_meta($staff, $key, true), 'real Club actions are');
    }

    public function test_status_enforces_the_same_account_and_session_policy(): void {
        $this->assertEnvelope($this->rest('GET', self::STATUS), 401, 'unauthorized');
        $this->assertArrayNotHasKey('X-WP-Nonce', rest_get_server()->sent_headers, 'no nonce for anonymous callers');

        $staff = $this->create_staff_user();
        $this->login_as($staff);
        $this->assertEnvelope($this->rest('GET', self::STATUS, null, null), 401, 'unauthorized');
        $this->assertEnvelope($this->rest('GET', self::STATUS, null, 'bad-nonce'), 401, 'unauthorized');

        $this->login_as($this->create_user_with_role('administrator'));
        $this->assertEnvelope($this->rest('GET', self::STATUS, null, $this->nonce()), 403, 'forbidden');

        $multi = $this->create_staff_user();
        (new WP_User($multi))->add_role('administrator');
        clean_user_cache($multi);
        $this->login_as($multi);
        $this->assertEnvelope($this->rest('GET', self::STATUS, null, $this->nonce()), 403, 'forbidden');

        $this->login_as($staff);
        update_user_meta($staff, EVC_Staff_Auth::DISABLED_META, '1');
        $this->assertEnvelope($this->rest('GET', self::STATUS, null, $this->nonce()), 403, 'forbidden');
    }

    public function test_end_destroys_only_this_session_and_clears_the_cookie(): void {
        $staff = $this->create_staff_user();
        $other = $this->login_as($staff);
        $mine = $this->login_as($staff);
        $nonce = $this->nonce();
        $cleared = did_action('clear_auth_cookie');

        $r = $this->rest('POST', self::END, null, $nonce);

        $this->assertSame(200, $r->get_status());
        $this->assertSame(array('ended' => true), $r->get_data());
        $manager = WP_Session_Tokens::get_instance($staff);
        $this->assertNull($manager->get($mine), 'this tablet is logged out server-side');
        $this->assertNotNull($manager->get($other), 'other tablets keep working');
        $this->assertSame($cleared + 1, did_action('clear_auth_cookie'), 'auth cookie cleared');

        $this->use_session($staff, $mine, time() + 3600);
        $this->assertEnvelope($this->rest('GET', self::STATUS, null, $nonce), 401, 'unauthorized');
    }

    public function test_end_requires_the_nonce_so_foreign_sites_cannot_log_tablets_out(): void {
        $staff = $this->create_staff_user();
        $token = $this->login_as($staff);
        $this->assertEnvelope($this->rest('POST', self::END), 401, 'unauthorized');
        $this->assertNotNull(WP_Session_Tokens::get_instance($staff)->get($token));
    }

    public function test_routes_are_registered_with_permission_callbacks(): void {
        $routes = rest_get_server()->get_routes();
        foreach (array(self::STATUS, self::END) as $route) {
            $this->assertArrayHasKey($route, $routes);
            foreach ($routes[$route] as $handler) {
                $this->assertIsCallable($handler['permission_callback']);
            }
        }
    }
}
