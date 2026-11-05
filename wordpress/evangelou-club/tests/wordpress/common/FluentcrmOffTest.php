<?php
/**
 * STAGE-06: with EVC_CLUB_FLUENTCRM_SYNC_ENABLED undefined (every suite but wp-fluentcrm), nothing of the
 * FluentCRM tag sync is hooked, scheduled or constructible, and FluentCRM is never reached.
 */
final class FluentcrmOffTest extends EVC_WP_Test_Case {
    public function test_off_by_default_nothing_is_hooked_or_scheduled(): void {
        if (defined('EVC_CLUB_FLUENTCRM_SYNC_ENABLED')) {
            $this->markTestSkipped('the wp-fluentcrm suite defines the constant');
        }
        $this->assertFalse(EVC_Fluentcrm_Feature::enabled());
        EVC_Fluentcrm_Plugin::boot('');
        EVC_Fluentcrm_Plugin::boot(array('membership' => new EVC_Unavailable_Membership_Adapter(), 'candidates' => function () {
            return array(1);
        }));
        foreach (array('admin_notices' => 'notice', 'admin_menu' => 'add_menu', 'shutdown' => 'flush', 'pmpro_after_change_membership_level' => 'note_level_change', 'woocommerce_order_status_completed' => 'note_order', 'woocommerce_order_refunded' => 'note_order', EVC_Fluentcrm_Plugin::CRON_HOOK => 'reconcile', 'init' => 'schedule') as $hook => $method) {
            $this->assertFalse(has_action($hook, array('EVC_Fluentcrm_Plugin', $method)), $hook);
        }
        do_action('init');
        $this->assertFalse(wp_next_scheduled(EVC_Fluentcrm_Plugin::CRON_HOOK));
        $this->assertSame(0, EVC_Fluentcrm_Plugin::reconcile());
        $this->assertFalse(function_exists('FluentCrmApi'), 'no FluentCRM on this test site');
    }

    public function test_the_gateway_refuses_construction_while_off(): void {
        if (defined('EVC_CLUB_FLUENTCRM_SYNC_ENABLED')) {
            $this->markTestSkipped('the wp-fluentcrm suite defines the constant');
        }
        $this->expectException(LogicException::class);
        new EVC_Fluentcrm_Api_Gateway();
    }

    public function test_the_tag_constants_have_no_defaults(): void {
        foreach (EVC_Fluentcrm_Feature::TAG_CONSTANTS as $constants) {
            foreach ($constants as $constant) {
                $this->assertFalse(defined($constant), $constant);
            }
        }
        list($config, $problem) = EVC_Fluentcrm_Feature::tag_config();
        $this->assertNull($config);
        $this->assertSame(EVC_Fluentcrm_Tag_Config::PROBLEM_NOT_CONFIGURED, $problem);
    }
}
