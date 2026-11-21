<?php

/**
 * STAGE-08 round 12 (owner decision D26 part 2), the shipped default: EVC_CLUB_PUSH_ENABLED undefined (this process has the
 * member page ON). Nothing of push exists: no /me/push route (404), no public key in the member page, no wiring, no
 * menu, no scheduled event, and the automatic notifications are off.
 */
final class PushOffTest extends EVC_WP_Test_Case {
    public function test_without_the_flag_there_is_no_push_at_all(): void {
        $this->assertTrue(EVC_Customer_Feature::enabled());
        $this->assertFalse(defined('EVC_CLUB_PUSH_ENABLED'));
        $this->assertFalse(EVC_Push_Feature::enabled());
        $this->assertFalse(EVC_Push_Feature::ready());
        $this->assertFalse(EVC_Push_Feature::auto_activated());
        $this->assertFalse(EVC_Push_Feature::auto_expiry());
        $this->assertNull(EVC_Push_Plugin::parts(), 'nothing wired');
        $this->assertSame(array(), EVC_Push_Feature::page_config(), 'no public key in the member page');
        $this->assertArrayNotHasKey('/evangelou-club-customer/v1/me/push', rest_get_server()->get_routes());
        if (!get_role('customer')) {
            add_role('customer', 'Customer', array('read' => true));
        }
        $this->login_as((int) self::factory()->user->create(array('role' => 'customer', 'user_pass' => self::PASSWORD)));
        $this->assertSame(404, $this->rest('GET', '/evangelou-club-customer/v1/me/push', null, $this->nonce())->get_status());
        $this->assertFalse(has_action(EVC_Push_Campaign::CRON_HOOK));
        $this->assertFalse(has_action(EVC_Push_Auto::EXPIRY_HOOK));
        $this->assertFalse(has_action('woocommerce_order_status_completed'), 'no automatic notification hook');
        foreach (array(EVC_Push_Campaign::CRON_HOOK, EVC_Push_Auto::EXPIRY_HOOK, EVC_Push_Auto::ACTIVATED_HOOK) as $hook) {
            $this->assertFalse(wp_next_scheduled($hook), $hook);
        }
        try {
            new EVC_Push_Sender();
            $this->fail('constructed while off');
        } catch (LogicException $e) {
            $this->assertStringContainsString('EVC_CLUB_PUSH_ENABLED', $e->getMessage());
        }
    }

    public function test_the_flag_parsers_take_only_the_boolean_true(): void {
        foreach (array(true) as $on) {
            $this->assertTrue(EVC_Push_Feature::is_enabled_value($on));
        }
        foreach (array(1, '1', 'true', 'yes', 'on', array(true), null, false) as $off) {
            $this->assertFalse(EVC_Push_Feature::is_enabled_value($off), var_export($off, true));
        }
    }
}
