<?php

/**
 * STAGE-01, customer flag OFF (the default; runs with the staff flag both
 * disabled and enabled): no customer route, no query var, no rewrite rule,
 * the page answers 404 without any bootstrap, and the staff flag never turns
 * the customer surface on.
 */
final class CustomerFeatureOffTest extends EVC_WP_Test_Case {
    public function test_the_customer_surface_does_not_exist(): void {
        $this->assertFalse(EVC_Customer_Feature::enabled());
        foreach (array_keys(rest_get_server()->get_routes()) as $route) {
            $this->assertStringNotContainsString('evangelou-club-customer', $route);
        }
        $this->assertNotContains(EVC_Customer_Shell::QUERY_VAR, apply_filters('query_vars', array()));
        $this->assertSame(EVC_Customer_Shell::REWRITE_STATE_OFF, EVC_Customer_Shell::desired_rewrite_state());

        $id = (int) self::factory()->user->create(array('role' => 'subscriber', 'user_pass' => self::PASSWORD));
        $this->login_as($id);
        $page = EVC_Customer_Shell::respond(wp_get_current_user(), new EVC_Staff_Assets(sys_get_temp_dir(), 'https://example.org/x', EVC_Customer_Shell::ASSET_ENTRY));
        $this->assertSame(404, $page['status']);
        $this->assertStringNotContainsString('nonce', $page['body']);

        $r = $this->rest('GET', '/evangelou-club-customer/v1/me', null, $this->nonce());
        $this->assertSame(404, $r->get_status());
        $this->assertInstanceOf(WP_Error::class, EVC_Customer_Auth::authorize(new WP_REST_Request('GET', '/evangelou-club-customer/v1/me')));
    }
}
