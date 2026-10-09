<?php

/**
 * Feature ENABLED, production wiring (EVC_Unavailable_Redemption_Backend):
 * authentication/authorization/session enforcement and fail-closed 503.
 */
final class RestAuthTest extends EVC_WP_Test_Case {
    const MEMBER = 'mem_0123456789abcdef0123456789abcdef';

    private function body(): array {
        return array('request_id' => self::uuid4(), 'coffee_code' => 'espresso');
    }

    public function test_flag_is_enabled_and_route_registered(): void {
        $this->assertTrue(EVC_Staff_Feature::enabled());
        $this->assertArrayHasKey('/evangelou-club/v1' . EVC_Rest_Redeem_Controller::ROUTE, rest_get_server()->get_routes());
        $this->assertSame(99, has_filter('rest_post_dispatch', array('EVC_Rest_Security', 'filter_post_dispatch')));
    }

    public function test_anonymous_request_is_rejected(): void {
        $this->assertEnvelope($this->rest('POST', $this->redeem_route(self::MEMBER), $this->body()), 401, 'unauthorized');
    }

    public function test_logged_in_staff_without_nonce_is_unauthenticated(): void {
        $this->login_as($this->create_staff_user());
        $response = $this->rest('POST', $this->redeem_route(self::MEMBER), $this->body(), null);
        $this->assertEnvelope($response, 401, 'unauthorized');
        $this->assertSame(0, get_current_user_id(), 'core dropped the cookie user without a nonce');
    }

    public function test_invalid_nonce_is_rejected_with_safe_envelope(): void {
        $this->login_as($this->create_staff_user());
        $response = $this->rest('POST', $this->redeem_route(self::MEMBER), $this->body(), 'not-a-valid-nonce');
        $this->assertEnvelope($response, 401, 'unauthorized');
        $this->assertStringNotContainsString('rest_cookie_invalid_nonce', wp_json_encode($response->get_data()));
    }

    public function test_nonce_from_another_session_is_rejected(): void {
        $staff = $this->create_staff_user();
        $this->login_as($staff);
        $foreign_nonce = $this->nonce();
        $this->login_as($staff);
        $this->assertEnvelope($this->rest('POST', $this->redeem_route(self::MEMBER), $this->body(), $foreign_nonce), 401, 'unauthorized');
    }

    public function test_authorized_staff_reaches_fail_closed_backend(): void {
        $this->login_as($this->create_staff_user());
        $response = $this->rest('POST', $this->redeem_route(self::MEMBER), $this->body(), $this->nonce());
        $this->assertEnvelope($response, 503, 'server_error');
        $this->assertArrayNotHasKey('details', $response->get_data()['error']);
        $this->assertArrayHasKey('X-WP-Nonce', rest_get_server()->sent_headers, 'core refreshes the nonce for valid cookie requests');
    }

    public function test_other_roles_are_forbidden_even_with_valid_nonce(): void {
        foreach (array('subscriber', 'editor', 'administrator') as $role) {
            $this->login_as($this->create_user_with_role($role));
            $this->assertEnvelope($this->rest('POST', $this->redeem_route(self::MEMBER), $this->body(), $this->nonce()), 403, 'forbidden');
        }
    }

    public function test_privileged_staff_tagged_accounts_are_forbidden_at_the_endpoint(): void {
        $variants = array(
            'staff + administrator role' => function (WP_User $u) {
                $u->add_role('administrator');
            },
            'staff + editor role' => function (WP_User $u) {
                $u->add_role('editor');
            },
            'staff + direct manage_options' => function (WP_User $u) {
                $u->add_cap('manage_options');
            },
            'staff + direct plugin cap' => function (WP_User $u) {
                $u->add_cap('manage_woocommerce');
            },
        );
        foreach ($variants as $label => $mutate) {
            $id = $this->create_staff_user();
            $mutate(new WP_User($id));
            clean_user_cache($id);
            $this->login_as($id);
            $response = $this->rest('POST', $this->redeem_route(self::MEMBER), $this->body(), $this->nonce());
            $this->assertSame(403, $response->get_status(), $label);
            $this->assertSame('forbidden', $response->get_data()['error']['code'], $label);
        }
        // The properly restricted shared account still passes authorization
        // (and then hits the fail-closed production backend).
        $this->login_as($this->create_staff_user());
        $this->assertEnvelope($this->rest('POST', $this->redeem_route(self::MEMBER), $this->body(), $this->nonce()), 503, 'server_error');
    }

    public function test_disabled_shared_account_is_forbidden(): void {
        $staff = $this->create_staff_user();
        $this->login_as($staff);
        update_user_meta($staff, EVC_Staff_Auth::DISABLED_META, '1');
        $this->assertEnvelope($this->rest('POST', $this->redeem_route(self::MEMBER), $this->body(), $this->nonce()), 403, 'forbidden');
    }

    public function test_idle_and_expired_sessions_are_rejected(): void {
        $staff = $this->create_staff_user();
        $token = $this->login_as($staff);
        $manager = WP_Session_Tokens::get_instance($staff);
        $session = $manager->get($token);
        $session['login'] = time() - 3600;
        $session['expiration'] = $session['login'] + EVC_Staff_Session::ABSOLUTE_SECONDS;
        $manager->update($token, $session);
        update_user_meta($staff, EVC_Staff_Session::activity_key($token), (string) (time() - 1801));
        $this->assertEnvelope($this->rest('POST', $this->redeem_route(self::MEMBER), $this->body(), $this->nonce()), 401, 'unauthorized');
        $this->assertNull($manager->get($token));

        $token = $this->login_as($staff);
        $session = $manager->get($token);
        $session['login'] = time() - 43200;
        $manager->update($token, $session);
        $this->assertEnvelope($this->rest('POST', $this->redeem_route(self::MEMBER), $this->body(), $this->nonce()), 401, 'unauthorized');
    }

    public function test_controller_rechecks_authorization_when_called_directly(): void {
        $this->login_as($this->create_staff_user());
        $request = new WP_REST_Request('POST', $this->redeem_route(self::MEMBER));
        $request->set_body(wp_json_encode($this->body()));
        $request->set_header('Content-Type', 'application/json');
        $controller = new EVC_Rest_Redeem_Controller(new EVC_Unavailable_Redemption_Backend());
        $this->assertWPError($controller->permission($request), 'no X-WP-Nonce header');
        $this->assertWPError($controller->redeem($request), 'callback re-checks, never trusts the permission callback');
        wp_set_current_user(0);
        $this->assertWPError($controller->permission(new WP_REST_Request('POST', '/x')));
    }

    public function test_missing_or_malformed_body_is_invalid_request(): void {
        $this->login_as($this->create_staff_user());
        $this->assertEnvelope($this->rest('POST', $this->redeem_route(self::MEMBER), array('request_id' => self::uuid4()), $this->nonce()), 400, 'invalid_request');
        $this->assertEnvelope($this->rest('POST', $this->redeem_route(self::MEMBER), array('request_id' => array('x'), 'coffee_code' => 'espresso'), $this->nonce()), 400, 'invalid_request');
    }

    public function test_security_headers_on_club_responses_only(): void {
        $club = $this->rest('POST', $this->redeem_route(self::MEMBER), $this->body());
        $headers = $club->get_headers();
        foreach (EVC_Rest_Security::security_headers() as $name => $value) {
            $this->assertSame($value, $headers[$name], $name);
        }
        $this->assertStringContainsString('no-store', $headers['Cache-Control']);

        $core = $this->rest('GET', '/wp/v2/users/me');
        $this->assertSame(401, $core->get_status());
        $this->assertSame('rest_not_logged_in', $core->get_data()['code'], 'unrelated core routes keep their own error shape');
        $this->assertArrayNotHasKey('Content-Security-Policy', $core->get_headers());
    }

    public function test_cors_is_stripped_only_inside_the_club_namespace(): void {
        $club = new WP_REST_Request('POST', $this->redeem_route(self::MEMBER));
        $other = new WP_REST_Request('GET', '/wp/v2/posts');
        $this->assertSame(EVC_Rest_Security::CORS_HEADERS, EVC_Rest_Security::cors_headers_to_strip($club));
        $this->assertSame(array(), EVC_Rest_Security::cors_headers_to_strip($other));
        $this->assertSame(array(), EVC_Rest_Security::cors_headers_to_strip(new WP_REST_Request('GET', '/evangelou-club-other/v1/x')));
        $this->assertSame(11, has_filter('rest_pre_serve_request', array('EVC_Rest_Security', 'strip_cors')));
    }

    public function test_core_error_mapping_table(): void {
        $this->assertSame(array('unauthorized', 401), EVC_Rest_Security::map_core_error('rest_cookie_invalid_nonce', 403));
        $this->assertSame(array('unauthorized', 401), EVC_Rest_Security::map_core_error('rest_forbidden', 401));
        $this->assertSame(array('forbidden', 403), EVC_Rest_Security::map_core_error('rest_forbidden', 403));
        $this->assertSame(array('invalid_request', 400), EVC_Rest_Security::map_core_error('rest_missing_callback_param', 400));
        $this->assertSame(array('server_error', 500), EVC_Rest_Security::map_core_error('db_failure', 503));
        $this->assertNull(EVC_Rest_Security::map_core_error('rest_no_route', 404));
    }
}
