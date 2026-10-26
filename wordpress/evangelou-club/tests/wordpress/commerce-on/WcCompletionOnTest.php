<?php

/**
 * STAGE-03D with EVC_CLUB_WC_COMPLETION_ENABLED = true (EVC_TEST_WC_COMPLETION_FLAG=enabled): the REAL
 * boot() (not the test factory), the WP-Cron schedule, run_recovery(), deactivate() and one end-to-end
 * completion on the REAL clock. The OFF behaviour is covered in tests/wordpress/commerce.
 */
final class WcCompletionOnTest extends EVC_WP_Test_Case {
    /** @var wpdb[] */
    private $connections = array();
    /** @var EVC_Member_Lock[] */
    private $held = array();

    public function set_up() {
        parent::set_up();
        evc_test_commerce_configure();
    }

    public function tear_down() {
        foreach ($this->held as $lock) {
            $lock->release();
        }
        foreach ($this->connections as $db) {
            $db->close();
        }
        unset($GLOBALS['current_screen']);
        wp_set_current_user(0);
        wp_clear_scheduled_hook(EVC_Wc_Completion_Plugin::CRON_HOOK);
        parent::tear_down();
    }

    private function customer(): int {
        return (int) self::factory()->user->create(array('role' => 'customer', 'user_pass' => self::PASSWORD, 'user_email' => 'club.' . wp_generate_password(6, false, false) . '@example.org'));
    }

    private function staff(): int {
        return (int) self::factory()->user->create(array('role' => 'shop_manager'));
    }

    private function order(int $customer): WC_Order {
        $order = wc_create_order(array('customer_id' => $customer));
        $order->add_product(wc_get_product(EVC_TEST_CLUB_PRODUCT), 1);
        $order->set_payment_method('bacs');
        $order->set_currency('EUR');
        $order->set_billing_email('club.billing@example.org');
        $order->calculate_totals();
        $order->save();
        $order->update_status('on-hold');
        return wc_get_order($order->get_id());
    }

    private function complete_in_admin(int $order_id, int $staff): void {
        if (!class_exists('WP_Screen')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
        }
        wp_set_current_user($staff);
        $GLOBALS['current_screen'] = WP_Screen::get('dashboard');
        try {
            wc_get_order($order_id)->update_status('completed', '', true);
        } finally {
            unset($GLOBALS['current_screen']);
            wp_set_current_user(0);
        }
    }

    private function rows(int $user_id): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare("SELECT id, status, startdate, enddate FROM {$wpdb->pmpro_memberships_users} WHERE user_id = %d AND membership_id = %d ORDER BY id", $user_id, EVC_TEST_CLUB_LEVEL), ARRAY_A);
    }

    private function followup(int $order_id): ?string {
        $config = EVC_Commerce_Source_Config::from_constants();
        return (new EVC_Wc_Completion_Markers($config->completion_marker_codec(), 'live'))->followup($order_id);
    }

    /** How many confirmers are hooked (the real plugins_loaded boot already ran when the site was configured at load). */
    private function hooked_on_completed(): int {
        global $wp_filter;
        $n = 0;
        foreach ($wp_filter['woocommerce_order_status_completed']->callbacks[EVC_Wc_Completion_Confirmer::COMPLETION_PRIORITY] ?? array() as $cb) {
            if (is_array($cb['function']) && $cb['function'][0] instanceof EVC_Wc_Completion_Confirmer && $cb['function'][1] === 'on_completed') {
                $n++;
            }
        }
        return $n;
    }

    public function test_the_constant_is_on_and_boot_hooks_schedules_and_runs_the_recovery(): void {
        $this->setExpectedDeprecated('pmprommpu_addMembershipLevel');
        $this->assertTrue(defined(EVC_Wc_Completion_Feature::CONSTANT));
        $this->assertTrue(EVC_Wc_Completion_Feature::enabled());
        if ($this->hooked_on_completed() === 0) {
            EVC_Wc_Completion_Plugin::boot(); // the site constants were not there at plugins_loaded: boot as it would
        }
        $this->assertSame(1, $this->hooked_on_completed(), 'exactly one confirmer, hooked by the real boot()');
        $this->assertSame(10, has_action('woocommerce_order_status_completed', 'pmprowoo_add_membership_from_order'));
        $this->assertNotFalse(has_filter('cron_schedules', array('EVC_Wc_Completion_Plugin', 'schedules')));
        $this->assertNotFalse(has_action(EVC_Wc_Completion_Plugin::CRON_HOOK, array('EVC_Wc_Completion_Plugin', 'run_recovery')));
        $this->assertNotFalse(has_action('init', array('EVC_Wc_Completion_Plugin', 'schedule')));
        $this->assertSame(900, wp_get_schedules()[EVC_Wc_Completion_Plugin::CRON_SCHEDULE]['interval']);

        // The schedule: once, on the 15-minute interval; a second call adds nothing.
        wp_clear_scheduled_hook(EVC_Wc_Completion_Plugin::CRON_HOOK); // `init` of the real boot may already have scheduled it
        $this->assertFalse(wp_next_scheduled(EVC_Wc_Completion_Plugin::CRON_HOOK));
        EVC_Wc_Completion_Plugin::schedule();
        $first = wp_next_scheduled(EVC_Wc_Completion_Plugin::CRON_HOOK);
        $this->assertNotFalse($first);
        EVC_Wc_Completion_Plugin::schedule();
        $this->assertSame($first, wp_next_scheduled(EVC_Wc_Completion_Plugin::CRON_HOOK));
        $this->assertSame(EVC_Wc_Completion_Plugin::CRON_SCHEDULE, wp_get_scheduled_event(EVC_Wc_Completion_Plugin::CRON_HOOK)->schedule);

        // End to end on the real clock: a staff completion in the admin is recorded.
        $customer = $this->customer();
        $order = $this->order($customer);
        $this->complete_in_admin($order->get_id(), $this->staff());
        $rows = $this->rows($customer);
        $this->assertCount(1, $rows);
        $this->assertSame('active', $rows[0]['status']);
        $this->assertNull($this->followup($order->get_id()));

        // A completion that is deferred (member lock held elsewhere) is finished by the cron callback.
        $other = $this->customer();
        $deferred = $this->order($other);
        $db = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
        $db->set_prefix($GLOBALS['wpdb']->prefix);
        $this->connections[] = $db;
        $lock = EVC_Member_Lock::acquire($db, $other, 0);
        $this->assertNotNull($lock);
        $this->held[] = $lock;
        $this->complete_in_admin($deferred->get_id(), $this->staff());
        $this->assertSame(array(), $this->rows($other));
        $this->assertSame('open:0', $this->followup($deferred->get_id()));
        $lock->release();
        array_pop($this->held);
        EVC_Wc_Completion_Plugin::run_recovery();
        $this->assertCount(1, $this->rows($other), 'the recovery step of the cron callback finished it');
        $this->assertNull($this->followup($deferred->get_id()));
    }

    public function test_deactivation_clears_the_schedule(): void {
        EVC_Wc_Completion_Plugin::schedule();
        $this->assertNotFalse(wp_next_scheduled(EVC_Wc_Completion_Plugin::CRON_HOOK));
        EVC_Wc_Completion_Plugin::deactivate();
        $this->assertFalse(wp_next_scheduled(EVC_Wc_Completion_Plugin::CRON_HOOK));
    }
}
