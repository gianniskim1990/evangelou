<?php
/**
 * STAGE-07: with EVC_CLUB_ONBOARDING_ENABLED undefined (every suite but the onboarding ones) nothing of the
 * onboarding exists: no query variable, no rewrite rule, no page, no route, and its classes refuse construction.
 */
final class OnboardingOffTest extends EVC_WP_Test_Case {
    public function test_off_by_default_nothing_exists(): void {
        if (defined('EVC_CLUB_ONBOARDING_ENABLED')) {
            $this->markTestSkipped('an onboarding suite defines the constant');
        }
        $this->assertFalse(EVC_Onboarding_Feature::enabled());
        $log = (string) tempnam(sys_get_temp_dir(), 'evc-log');
        ini_set('error_log', $log);
        try {
            EVC_Onboarding_Plugin::boot('');
            EVC_Onboarding_Plugin::boot(array('membership' => new EVC_Unavailable_Membership_Adapter()));
        } finally {
            ini_restore('error_log');
        }
        $this->assertSame('', (string) file_get_contents($log), 'returned at the gate: not even a wiring attempt');
        @unlink($log);
        $this->assertNull(EVC_Onboarding_Plugin::parts());
        foreach (array('template_redirect' => 'serve', 'rest_api_init' => 'register_routes', 'init' => 'rewrite') as $hook => $method) {
            $this->assertFalse(has_action($hook, array('EVC_Onboarding_Plugin', $method)), $hook);
        }
        $this->assertNotContains(EVC_Onboarding_Shell::QUERY_VAR, apply_filters('query_vars', array()));
        foreach (array('woocommerce_add_to_cart_validation', 'woocommerce_after_checkout_validation', 'woocommerce_available_payment_gateways', 'woocommerce_no_available_payment_methods_message') as $hook) {
            $this->assertFalse(has_filter($hook), $hook . ': the Club cart and checkout rules exist only with the flag');
        }
        do_action('rest_api_init', rest_get_server());
        foreach (array_keys(rest_get_server()->get_routes()) as $route) {
            $this->assertStringNotContainsString('/join', $route);
        }
        $this->assertFalse(get_option(EVC_Onboarding_Plugin::REWRITE_STATE_OPTION, false));
    }

    public function test_the_classes_refuse_construction_while_off(): void {
        $this->expectException(LogicException::class);
        new EVC_Onboarding_Registration();
    }

    public function test_the_checkout_guard_refuses_construction_while_off(): void {
        $this->expectException(LogicException::class);
        // A hand-off cannot be constructed while off either, so the guard gets one made without its constructor.
        $handoff = (new ReflectionClass(EVC_Onboarding_Handoff::class))->newInstanceWithoutConstructor();
        new EVC_Onboarding_Checkout_Guard($handoff);
    }

    public function test_the_handoff_refuses_construction_while_off(): void {
        $this->expectException(LogicException::class);
        new EVC_Onboarding_Handoff(null, new EVC_Unavailable_Membership_Adapter(), new EVC_System_Clock());
    }
}
