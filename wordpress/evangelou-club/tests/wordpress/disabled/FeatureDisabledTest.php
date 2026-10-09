<?php

/** Never-called backend: proves disabled mode cannot reach any engine. */
final class EVC_Tripwire_Backend implements EVC_Redemption_Backend {
    /** @var int */
    public $calls = 0;

    public function service(): ?EVC_Redemption_Service {
        $this->calls++;
        throw new LogicException('Backend must never be reached while the feature is disabled.');
    }
}

/** Feature flag NOT defined (default): no Club REST surface at all. */
final class FeatureDisabledTest extends EVC_WP_Test_Case {
    const MEMBER = 'mem_0123456789abcdef0123456789abcdef';

    public function test_flag_is_disabled_by_default(): void {
        $this->assertFalse(defined('EVC_CLUB_STAFF_ENABLED'), 'test process mirrors a default wp-config.php');
        $this->assertFalse(EVC_Staff_Feature::enabled());
    }

    public function test_no_club_routes_or_rest_filters_are_registered(): void {
        foreach (array_keys(rest_get_server()->get_routes()) as $route) {
            $this->assertStringStartsNotWith('/evangelou-club', $route);
        }
        $this->assertNotContains('evangelou-club/v1', rest_get_server()->get_namespaces());
        $this->assertFalse(has_filter('rest_post_dispatch', array('EVC_Rest_Security', 'filter_post_dispatch')));
        $this->assertFalse(has_filter('rest_pre_serve_request', array('EVC_Rest_Security', 'strip_cors')));
        $this->assertFalse(has_action('rest_api_init', array('EVC_Staff_Plugin', 'register_routes')));
    }

    public function test_even_an_authorized_staff_session_gets_no_route(): void {
        $this->login_as($this->create_staff_user());
        $response = $this->rest('POST', $this->redeem_route(self::MEMBER), array('request_id' => self::uuid4(), 'coffee_code' => 'espresso'), $this->nonce());
        $this->assertSame(404, $response->get_status());
        $this->assertSame('rest_no_route', $response->get_data()['code']);
    }

    public function test_direct_invocation_cannot_bypass_the_flag(): void {
        $this->login_as($this->create_staff_user());
        $request = new WP_REST_Request('POST', $this->redeem_route(self::MEMBER));
        $request->set_header('X-WP-Nonce', $this->nonce());
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(wp_json_encode(array('request_id' => self::uuid4(), 'coffee_code' => 'espresso')));

        $auth = EVC_Staff_Auth::authorize($request, EVC_Staff_Role::CAP_REDEEM);
        $this->assertWPError($auth);
        $this->assertSame('evc_feature_disabled', $auth->get_error_code());

        $backend = new EVC_Tripwire_Backend();
        $controller = new EVC_Rest_Redeem_Controller($backend);
        $this->assertWPError($controller->permission($request));
        $this->assertWPError($controller->redeem($request));
        $this->assertSame(0, $backend->calls);

        EVC_Staff_Plugin::register_routes();
        $this->assertArrayNotHasKey('/evangelou-club/v1' . EVC_Rest_Redeem_Controller::ROUTE, rest_get_server()->get_routes());
    }

    public function test_role_scoped_protections_stay_active_while_disabled(): void {
        $staff = $this->create_staff_user();
        $this->assertSame(43200, apply_filters('auth_cookie_expiration', 2 * DAY_IN_SECONDS, $staff, true));
        $this->assertSame(1, has_action('admin_init', array('EVC_Staff_Admin_Guard', 'maybe_redirect')));
        $this->assertSame(99, has_filter('authenticate', array('EVC_Login_Throttle', 'filter_authenticate')));
    }
}
