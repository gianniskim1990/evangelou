<?php

/** TEST-ONLY adapter wrapper: counts calls and records the user id it was asked about. */
final class EVC_Test_Recording_Adapter implements EVC_Membership_Adapter {
    /** @var EVC_Membership_Adapter */
    private $inner;
    /** @var int[] */
    public $users = array();

    public function __construct(EVC_Membership_Adapter $inner) {
        $this->inner = $inner;
    }

    public function entitlement_for(int $wp_user_id, DateTimeImmutable $at_utc): EVC_Entitlement {
        $this->users[] = $wp_user_id;
        return $this->inner->entitlement_for($wp_user_id, $at_utc);
    }
}

/**
 * STAGE-01, customer flag ENABLED (staff flag DISABLED): GET
 * evangelou-club-customer/v1/me in real WordPress. Native cookie session +
 * wp_rest nonce, customer accounts only, user from the server session only.
 * Production wiring answers 503 "unavailable" (no verified reader exists);
 * the states are checked by re-registering the route with the REAL PMPro
 * adapter + mapper over test-only fixtures.
 */
final class CustomerMeTest extends EVC_WP_Test_Case {
    const ME = '/evangelou-club-customer/v1/me';

    private function customer(string $role = 'customer'): int {
        if (!get_role('customer')) {
            add_role('customer', 'Customer', array('read' => true));
        }
        return (int) self::factory()->user->create(array('role' => $role, 'user_pass' => self::PASSWORD, 'first_name' => 'Ελένη', 'last_name' => 'Μαρκοπούλου', 'user_email' => 'eleni.' . wp_generate_password(6, false, false) . '@example.org'));
    }

    /** @param EVC_Pmpro_Snapshot|Throwable $result */
    private function use_source($result, string $athens_now = '2026-10-20 09:00'): EVC_Test_Recording_Adapter {
        $adapter = new EVC_Test_Recording_Adapter(new EVC_Pmpro_Membership_Adapter(new EVC_Fake_Pmpro_Reader($result), EVC_Pmpro_Fixture::mapper()));
        (new EVC_Rest_Me_Controller($adapter, new EVC_Fixed_Clock(EVC_Pmpro_Fixture::athens($athens_now)->format('Y-m-d\TH:i:s\Z'))))->register_routes(true);
        return $adapter;
    }

    public function test_flags_are_independent(): void {
        $this->assertTrue(EVC_Customer_Feature::enabled());
        $this->assertFalse(EVC_Staff_Feature::enabled(), 'the customer flag never enables the staff surface');
        $routes = rest_get_server()->get_routes();
        $this->assertArrayHasKey(self::ME, $routes);
        $this->assertArrayNotHasKey('/evangelou-club/v1/session', $routes);
        $this->assertFalse(EVC_Customer_Feature::is_enabled_value(1));
        $this->assertFalse(EVC_Customer_Feature::is_enabled_value('true'));
    }

    public function test_production_wiring_fails_closed_with_unavailable(): void {
        $this->login_as($this->customer());
        $r = $this->rest('GET', self::ME, null, $this->nonce());
        $this->assertSame(503, $r->get_status());
        $this->assertSame(array('result' => 'unavailable'), $r->get_data(), 'no membership is guessed without a verified reader');
        $this->assertStringContainsString('no-store', $r->get_headers()['Cache-Control']);
    }

    public function test_requires_a_signed_in_cookie_session_and_a_valid_nonce(): void {
        $this->assertEnvelope($this->rest('GET', self::ME), 401, 'unauthorized');
        $this->login_as($this->customer());
        $this->assertEnvelope($this->rest('GET', self::ME, null, null), 401, 'unauthorized');
        $this->assertEnvelope($this->rest('GET', self::ME, null, 'bad-nonce'), 401, 'unauthorized');
    }

    public function test_own_nonce_check_is_independent_of_core(): void {
        // Defence in depth: even if core's cookie/nonce check were skipped, the route refuses
        // a signed-in customer whose request carries no valid wp_rest nonce.
        $this->login_as($this->customer());
        $request = new WP_REST_Request('GET', self::ME);
        $this->assertInstanceOf(WP_Error::class, EVC_Customer_Auth::authorize($request), 'no nonce');
        $request->set_header('X-WP-Nonce', 'bad-nonce');
        $this->assertInstanceOf(WP_Error::class, EVC_Customer_Auth::authorize($request), 'invalid nonce');
        $this->assertSame(401, EVC_Customer_Auth::authorize($request)->get_error_data()['status']);
        $request->set_header('X-WP-Nonce', $this->nonce());
        $this->assertTrue(EVC_Customer_Auth::authorize($request));

        $unchecked = new WP_REST_Request('GET', self::ME);
        $this->assertSame(401, rest_ensure_response(rest_get_server()->dispatch($unchecked))->get_status(), 'dispatch without core authentication');
    }

    public function test_only_customer_accounts(): void {
        $this->use_source(EVC_Pmpro_Fixture::single_paid_month());
        foreach (array('administrator', 'editor', 'author', 'contributor', 'shop_manager') as $role) {
            if (!get_role($role)) {
                continue;
            }
            $this->login_as($this->create_user_with_role($role));
            $this->assertEnvelope($this->rest('GET', self::ME, null, $this->nonce()), 403, 'forbidden');
        }
        $this->login_as($this->create_staff_user());
        $this->assertEnvelope($this->rest('GET', self::ME, null, $this->nonce()), 403, 'forbidden');

        $sneaky = $this->customer();
        get_userdata($sneaky)->add_cap('evc_lookup_member');
        $this->login_as($sneaky);
        $this->assertEnvelope($this->rest('GET', self::ME, null, $this->nonce()), 403, 'forbidden');

        foreach (array('customer', 'subscriber') as $role) {
            $this->login_as($this->customer($role));
            $this->assertSame(200, $this->rest('GET', self::ME, null, $this->nonce())->get_status(), $role);
        }
    }

    public function test_active_only_with_a_verified_current_paid_period(): void {
        $adapter = $this->use_source(EVC_Pmpro_Fixture::single_paid_month());
        $id = $this->customer();
        $this->login_as($id);
        $r = $this->rest('GET', self::ME, null, $this->nonce());
        $this->assertSame(200, $r->get_status(), wp_json_encode($r->get_data()));
        $data = $r->get_data();
        $this->assertSame('ok', $data['result']);
        $this->assertSame('customer-me-v1', $data['contract']);
        $this->assertSame(array('state' => 'active', 'paid_until_utc' => '2026-11-15T10:00:00+00:00'), $data['membership']);
        $this->assertSame('Ελένη Μαρκοπούλου', $data['account']['display_name']);
        $this->assertSame(get_userdata($id)->user_email, $data['account']['email']);
        $this->assertSame(array('coffee_today' => 'not_connected', 'card' => 'sample_only'), $data['club']);
        $this->assertSame(array($id), $adapter->users);
        $json = wp_json_encode($data);
        foreach (array('diagnostic', 'level', 'evidence', 'ref', 'user_login', (string) $id, 'nonce') as $needle) {
            $this->assertStringNotContainsString($needle, $json, "no internal or identifying field: $needle");
        }
    }

    public function test_the_user_comes_only_from_the_server_session(): void {
        $adapter = $this->use_source(EVC_Pmpro_Fixture::single_paid_month());
        $other = $this->customer();
        $me = $this->customer();
        $this->login_as($me);
        $server = rest_get_server();
        $request = new WP_REST_Request('GET', self::ME);
        $request->set_query_params(array('user_id' => $other, 'user' => $other, 'wp_user_id' => $other, 'member_id' => $other));
        $request->set_header('X-WP-Nonce', $this->nonce());
        $_SERVER['HTTP_X_WP_NONCE'] = $this->nonce();
        $this->assertFalse(is_wp_error($server->check_authentication()));
        $response = apply_filters('rest_post_dispatch', rest_ensure_response($server->dispatch($request)), $server, $request);
        $this->assertSame(200, $response->get_status());
        $this->assertSame(array($me), $adapter->users, 'request parameters can never select another user');
        $this->assertSame(get_userdata($me)->user_email, $response->get_data()['account']['email']);
    }

    public function test_each_membership_state(): void {
        $id = $this->customer();
        $cases = array(
            'pending_payment' => array(EVC_Pmpro_Fixture::snapshot(array(EVC_Pmpro_Fixture::row()), array(EVC_Pmpro_Fixture::payment('p', 'pending', null, null, null))), '2026-10-20 09:00', null),
            'expired' => array(EVC_Pmpro_Fixture::single_paid_month(), '2026-11-15 12:00', '2026-11-15T10:00:00+00:00'),
            'cancelled' => array(EVC_Pmpro_Fixture::snapshot(array(EVC_Pmpro_Fixture::row('row1', 'cancelled')), array()), '2026-10-20 09:00', null),
            'none' => array(EVC_Pmpro_Fixture::snapshot(array(), array()), '2026-10-20 09:00', null),
            'unverified' => array(EVC_Pmpro_Fixture::snapshot(array(EVC_Pmpro_Fixture::row()), array()), '2026-10-20 09:00', null),
        );
        foreach ($cases as $state => list($snapshot, $now, $until)) {
            $this->use_source($snapshot, $now);
            $this->login_as($id);
            $r = $this->rest('GET', self::ME, null, $this->nonce());
            $this->assertSame(200, $r->get_status(), $state);
            $this->assertSame(array('state' => $state, 'paid_until_utc' => $until), $r->get_data()['membership'], $state);
        }
    }

    public function test_an_unreadable_source_is_unavailable_never_a_state(): void {
        $this->use_source(new RuntimeException('SQLSTATE secret detail'));
        $this->login_as($this->customer());
        $r = $this->rest('GET', self::ME, null, $this->nonce());
        $this->assertSame(503, $r->get_status());
        $this->assertSame(array('result' => 'unavailable'), $r->get_data());
        $this->assertStringNotContainsString('SQLSTATE', wp_json_encode($r->get_data()));
    }

    public function test_namespace_hardening_and_cors_scope(): void {
        $this->use_source(EVC_Pmpro_Fixture::single_paid_month());
        $this->login_as($this->customer());
        $r = $this->rest('GET', self::ME, null, $this->nonce());
        foreach (EVC_Rest_Security::security_headers() as $name => $value) {
            $this->assertSame($value, $r->get_headers()[$name], $name);
        }
        $this->assertSame(EVC_Rest_Security::CORS_HEADERS, EVC_Customer_Rest_Security::cors_headers_to_strip(new WP_REST_Request('GET', self::ME)));
        $this->assertSame(array(), EVC_Customer_Rest_Security::cors_headers_to_strip(new WP_REST_Request('GET', '/wp/v2/posts')));
        $this->assertFalse(EVC_Customer_Rest_Security::is_domain_body(array('result' => 'unavailable', 'x' => 1), 503));
        $this->assertFalse(EVC_Customer_Rest_Security::is_domain_body(array('result' => 'ok'), 500));
    }

    public function test_only_get_is_routed(): void {
        $this->login_as($this->customer());
        $r = $this->rest('POST', self::ME, array('user_id' => 1), $this->nonce());
        $this->assertSame(404, $r->get_status(), 'no write method exists');
    }
}
