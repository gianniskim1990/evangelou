<?php
use PHPUnit\Framework\TestCase;

/**
 * STAGE-03D: where a WooCommerce «Completed» came from. Only a manual status change in the WooCommerce
 * admin (order edit screen, bulk action, list button) can be a payment confirmation; REST (API keys,
 * application passwords, the mobile app), WP-CLI, WP-Cron, automation and the storefront never are.
 */
final class WcCompletionContextTest extends TestCase {
    private static function manual(): array {
        return array('from' => 'on-hold', 'to' => 'completed', 'note' => '', 'manual' => true);
    }

    public function test_the_channel_is_the_most_specific_non_admin_channel_first(): void {
        // cli, cron, rest, ajax, admin
        $this->assertSame('cli', EVC_Wc_Completion_Context::channel(true, true, true, true, true));
        $this->assertSame('cli', EVC_Wc_Completion_Context::channel(true, false, false, false, false));
        $this->assertSame('cron', EVC_Wc_Completion_Context::channel(false, true, true, true, true));
        $this->assertSame('cron', EVC_Wc_Completion_Context::channel(false, true, false, false, false));
        $this->assertSame('rest', EVC_Wc_Completion_Context::channel(false, false, true, true, true));
        $this->assertSame('rest', EVC_Wc_Completion_Context::channel(false, false, true, false, false));
        $this->assertSame('admin_ajax', EVC_Wc_Completion_Context::channel(false, false, false, true, true));
        $this->assertSame('ajax', EVC_Wc_Completion_Context::channel(false, false, false, true, false));
        $this->assertSame('admin', EVC_Wc_Completion_Context::channel(false, false, false, false, true));
        $this->assertSame('other', EVC_Wc_Completion_Context::channel(false, false, false, false, false));
    }

    public function test_only_a_manual_status_change_in_the_admin_is_attested(): void {
        $this->assertNull(EVC_Wc_Completion_Context::problem('admin', self::manual()));
        $this->assertNull(EVC_Wc_Completion_Context::problem('admin_ajax', self::manual()));
        foreach (array('rest', 'cli', 'cron', 'ajax', 'other') as $channel) {
            $this->assertSame('channel_' . $channel, EVC_Wc_Completion_Context::problem($channel, self::manual()), $channel);
        }
    }

    public function test_the_manual_flag_must_be_exactly_true_in_an_array(): void {
        foreach (array(null, 'manual', 1, '1', array(), array('manual' => false), array('manual' => 1), array('manual' => 'true'), array('from' => 'on-hold')) as $transition) {
            $this->assertSame('not_a_manual_status_change', EVC_Wc_Completion_Context::problem('admin', $transition), var_export($transition, true));
            $this->assertSame('not_a_manual_status_change', EVC_Wc_Completion_Context::problem('admin_ajax', $transition), var_export($transition, true));
        }
    }

    // ---- detect(): the request facts WordPress exposes (each in its own process: constants cannot be undefined) ----

    /** @runInSeparateProcess
     *  @preserveGlobalState disabled */
    public function test_wp_cli_is_the_cli_channel_even_in_the_admin(): void {
        define('WP_CLI', true);
        $this->assertSame('cli', EVC_Wc_Completion_Context::detect(0, true));
        $this->assertSame('cli', EVC_Wc_Completion_Context::detect(0, false));
    }

    /** @runInSeparateProcess
     *  @preserveGlobalState disabled */
    public function test_a_rest_request_constant_or_a_running_dispatch_is_the_rest_channel(): void {
        $this->assertSame('admin', EVC_Wc_Completion_Context::detect(0, true));
        $this->assertSame('rest', EVC_Wc_Completion_Context::detect(1, true), 'a running dispatch counted by the caller');
        define('REST_REQUEST', true);
        $this->assertSame('rest', EVC_Wc_Completion_Context::detect(0, true));
        $this->assertSame('rest', EVC_Wc_Completion_Context::detect(0, false));
    }

    /** @runInSeparateProcess
     *  @preserveGlobalState disabled */
    public function test_cron_ajax_and_the_helpers_wordpress_provides(): void {
        $GLOBALS['evc_ctx'] = array('cron' => false, 'ajax' => false, 'serving_rest' => false);
        eval('function wp_doing_cron() { return $GLOBALS["evc_ctx"]["cron"]; }
              function wp_doing_ajax() { return $GLOBALS["evc_ctx"]["ajax"]; }
              function wp_is_serving_rest_request() { return $GLOBALS["evc_ctx"]["serving_rest"]; }');
        $this->assertSame('admin', EVC_Wc_Completion_Context::detect(0, true));
        $this->assertSame('other', EVC_Wc_Completion_Context::detect(0, false));
        $GLOBALS['evc_ctx']['ajax'] = true;
        $this->assertSame('admin_ajax', EVC_Wc_Completion_Context::detect(0, true));
        $this->assertSame('ajax', EVC_Wc_Completion_Context::detect(0, false), 'a storefront AJAX call is never an admin click');
        $GLOBALS['evc_ctx']['serving_rest'] = true;
        $this->assertSame('rest', EVC_Wc_Completion_Context::detect(0, true));
        $GLOBALS['evc_ctx']['cron'] = true;
        $this->assertSame('cron', EVC_Wc_Completion_Context::detect(0, true));
    }

    public function test_only_the_three_woocommerce_admin_entry_points_for_this_order_attest(): void {
        $ok = function ($channel, $verified, $request, $order = 42) {
            return EVC_Wc_Completion_Context::entry_problem($channel, $order, $verified, $request);
        };
        // The order edit screen, HPOS and posts storage.
        $this->assertNull($ok('admin', array('update-order_42'), array()));
        $this->assertNull($ok('admin', array('update-post_42'), array()));
        $this->assertSame('not_a_woocommerce_admin_action', $ok('admin', array('update-order_43'), array()), 'another order\'s edit screen');
        $this->assertSame('not_a_woocommerce_admin_action', $ok('admin', array('update-order_420'), array()));
        // Bulk actions, HPOS and posts storage, the order in the request.
        $this->assertNull($ok('admin', array('bulk-orders'), array('id' => array('7', '42'))));
        $this->assertNull($ok('admin', array('bulk-posts'), array('post' => array(42))));
        $this->assertSame('not_a_woocommerce_admin_action', $ok('admin', array('bulk-orders'), array('id' => array('7'))));
        $this->assertSame('not_a_woocommerce_admin_action', $ok('admin', array('bulk-posts'), array('id' => array('42'))), 'posts bulk reads post[]');
        $this->assertSame('not_a_woocommerce_admin_action', $ok('admin', array('bulk-orders'), array('id' => array('42abc'))));
        $this->assertSame('not_a_woocommerce_admin_action', $ok('admin', array(), array('id' => array('42'), 'post' => array('42'))), 'no verified nonce: a plugin\'s update_status(..., true) during an admin page');
        $this->assertSame('not_a_woocommerce_admin_action', $ok('admin', array('woocommerce-mark-order-status'), array('order_id' => '42')), 'the list-button nonce outside admin-ajax');
        // The order-list button (admin-ajax).
        $this->assertNull($ok('admin_ajax', array('woocommerce-mark-order-status'), array('action' => 'woocommerce_mark_order_status', 'order_id' => '42')));
        $this->assertSame('not_a_woocommerce_admin_action', $ok('admin_ajax', array('woocommerce-mark-order-status'), array('action' => 'some_plugin_action', 'order_id' => '42')), 'another admin-ajax action');
        $this->assertSame('not_a_woocommerce_admin_action', $ok('admin_ajax', array(), array('action' => 'woocommerce_mark_order_status', 'order_id' => '42')), 'nonce not verified');
        $this->assertSame('not_a_woocommerce_admin_action', $ok('admin_ajax', array('woocommerce-mark-order-status'), array('action' => 'woocommerce_mark_order_status', 'order_id' => '43')), 'another order');
        $this->assertSame('not_a_woocommerce_admin_action', $ok('admin_ajax', array('woocommerce-mark-order-status'), array('action' => 'woocommerce_mark_order_status', 'order_id' => array('42'))));
        $this->assertSame('not_a_woocommerce_admin_action', $ok('admin_ajax', array('update-order_42', 'bulk-orders'), array('action' => 'x', 'id' => array('42'))), 'admin-ajax never uses the screen nonces');
        // Other channels are refused by problem(), always checked first; a missing order never attests.
        foreach (array('rest', 'cli', 'cron', 'ajax', 'other') as $channel) {
            $this->assertSame('channel_' . $channel, EVC_Wc_Completion_Context::problem($channel, array('manual' => true)));
        }
        $this->assertSame('not_a_woocommerce_admin_action', $ok('admin', array('update-order_0'), array(), 0));
    }
}
