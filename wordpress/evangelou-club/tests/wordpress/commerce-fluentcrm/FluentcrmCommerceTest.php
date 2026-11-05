<?php
/**
 * STAGE-06 round 2: the FluentCRM tag sync over the REAL stack — WooCommerce 11.2.1 + PMPro 3.8.9 + PMPro
 * WooCommerce 1.11 (HPOS or legacy storage, per EVC_TEST_WC_ORDER_STORAGE), the Club database with the recorded
 * payments, the real reader / mapper, and the DEFAULT member query of the daily reconcile. Only FluentCRM is the
 * TEST-ONLY fake (tests/wordpress/support/fake-fluentcrm.php). CI commerce job; nothing here is a real payment.
 *
 * The plugin is booted with its production wiring (no seams): EVC_CLUB_FLUENTCRM_SYNC_ENABLED and the tag
 * constants come from EVC_TEST_FLUENTCRM_FLAG=enabled, the commerce / provenance / Club DB constants from the
 * commerce test site. Shop confirmations go through the STAGE-03D confirmer (built per test, as in
 * WcCompletionTest), which issues PMPro's dates from the recorded payment itself.
 */
final class FluentcrmCommerceTest extends EVC_WP_Test_Case {
    /** @var EVC_Commerce_Source_Config */
    private $config;
    /** @var array<string,mixed>|null */
    private $parts;

    public function set_up() {
        parent::set_up();
        evc_test_commerce_configure();
        $this->config = EVC_Commerce_Source_Config::from_constants();
        EVC_Test_Fluentcrm::reset();
        delete_option(EVC_Fluentcrm_Plugin::STATUS_OPTION);
        delete_option(EVC_Fluentcrm_Plugin::CURSOR_OPTION);
        EVC_Fluentcrm_Plugin::boot('');
    }

    public function tear_down() {
        if ($this->parts !== null) {
            $this->parts['confirmer']->unregister();
            $this->parts['guard']->disarm();
        }
        $this->parts = null;
        unset($_REQUEST['_wpnonce'], $GLOBALS['current_screen']);
        wp_set_current_user(0);
        $this->assertSame(array(), EVC_Test_Fluentcrm::$forbidden, 'no FluentCRM method outside the documented, allowed set');
        parent::tear_down();
    }

    // ---- helpers (the WcCompletionTest patterns) --------------------------------------------------

    /** A real-time instant at whole second :07 (never on the add-on's minute-rounded :00). */
    private static function at7(string $relative): DateTimeImmutable {
        $t = new DateTimeImmutable($relative, new DateTimeZone('UTC'));
        return $t->setTime((int) $t->format('H'), (int) $t->format('i'), 7);
    }

    private function customer(): int {
        return (int) self::factory()->user->create(array('role' => 'customer', 'user_email' => 'club.' . wp_generate_password(8, false, false) . '@example.org', 'display_name' => 'Synthetic Customer'));
    }

    private function staff(): int {
        return (int) self::factory()->user->create(array('role' => 'shop_manager', 'display_name' => 'Synthetic Shop Staff'));
    }

    private static function email(int $user): string {
        return (string) get_userdata($user)->user_email;
    }

    private function wire(DateTimeImmutable $now): void {
        if ($this->parts !== null) {
            $this->parts['confirmer']->unregister();
        }
        $this->parts = EVC_Wc_Completion_Plugin::build($this->config, EVC_Db_Config::from_constants(), function () use ($now) {
            return $now;
        }, null, 1);
        $this->assertNotNull($this->parts);
        $this->parts['confirmer']->register();
    }

    /** A Club bank-transfer order put on hold (awaiting the transfer). */
    private function on_hold(int $user): WC_Order {
        $order = wc_create_order(array('customer_id' => $user));
        $order->add_product(wc_get_product(EVC_TEST_CLUB_PRODUCT), 1);
        $order->set_payment_method('bacs');
        $order->set_currency('EUR');
        $order->set_billing_email('club.billing@example.org');
        $order->set_date_created((new DateTimeImmutable('-2 hours'))->getTimestamp());
        $order->calculate_totals();
        $order->save();
        $order->update_status('on-hold');
        return wc_get_order($order->get_id());
    }

    /** The shop user sets the order to «Ολοκληρωμένη» on the order edit screen. */
    private function complete(int $order_id, int $actor): void {
        wp_set_current_user($actor);
        if (!class_exists('WP_Screen')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
        }
        $GLOBALS['current_screen'] = WP_Screen::get('dashboard');
        try {
            if ($this->parts !== null) {
                $action = (\Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? 'update-order_' : 'update-post_') . $order_id;
                $_REQUEST['_wpnonce'] = wp_create_nonce($action);
                $this->assertSame(1, check_admin_referer($action));
            }
            wc_get_order($order_id)->update_status('completed', '', true);
        } finally {
            unset($_REQUEST['_wpnonce'], $GLOBALS['current_screen']);
            wp_set_current_user(0);
            if ($this->parts !== null) {
                $this->parts['confirmer']->forget();
            }
        }
    }

    /** The end of the request: the noted customers are synced. @return array<string,int> this flush's outcomes */
    private function end_of_request(): array {
        $before = EVC_Fluentcrm_Plugin::status()['events'];
        EVC_Fluentcrm_Plugin::flush();
        $after = EVC_Fluentcrm_Plugin::status()['events'];
        $delta = array();
        foreach ($after as $outcome => $n) {
            if ($n - ($before[$outcome] ?? 0) > 0) {
                $delta[$outcome] = $n - ($before[$outcome] ?? 0);
            }
        }
        return $delta;
    }

    // ---- the lifecycle on real orders --------------------------------------------------------------

    public function test_an_on_hold_bacs_order_is_pending_and_its_cancellation_leaves_inactive_not_pending(): void {
        $this->assertNull(EVC_Fluentcrm_Plugin::problem(), 'production wiring, verified tags');
        $user = $this->customer();
        EVC_Test_Fluentcrm::contact(31, strtoupper(self::email($user)), null, array(1));
        $order = $this->on_hold($user);
        $this->assertSame(array(EVC_Fluentcrm_Tag_Sync::OUTCOME_PENDING => 1), $this->end_of_request());
        $this->assertSame(array(1, 4), EVC_Test_Fluentcrm::tags_of(31), 'Pending payment; the newsletter tag kept');

        // HIGH 1 (round 2): cancelled unpaid, the mapper says "none" — the Pending tag must not stay behind.
        $order->update_status('cancelled');
        $this->assertSame(EVC_Entitlement::STATUS_NONE, EVC_Customer_Plugin::membership_adapter()->entitlement_for($user, new DateTimeImmutable('now', new DateTimeZone('UTC')))->status());
        $this->assertSame(array(EVC_Fluentcrm_Tag_Sync::OUTCOME_INACTIVE => 1), $this->end_of_request());
        $this->assertSame(array(1, 3), EVC_Test_Fluentcrm::tags_of(31));
        $this->assertSame('subscribed', EVC_Test_Fluentcrm::$contacts[31]['status']);
    }

    public function test_a_confirmed_payment_is_active_and_a_full_refund_makes_it_inactive(): void {
        $this->setExpectedDeprecated('pmprommpu_addMembershipLevel');
        $user = $this->customer();
        $staff = $this->staff();
        EVC_Test_Fluentcrm::contact(32, self::email($user), null, array());
        $order = $this->on_hold($user);
        $this->end_of_request();
        $this->assertSame(array(4), EVC_Test_Fluentcrm::tags_of(32));

        // The STAGE-03D confirmation (shop staff completes the order): the Club records the payment and issues
        // PMPro's dates from it, so the member is verified and current.
        $this->wire(self::at7('-30 seconds'));
        $this->complete($order->get_id(), $staff);
        $this->assertSame(array(EVC_Fluentcrm_Tag_Sync::OUTCOME_ACTIVE => 1), $this->end_of_request());
        $this->assertSame(array(2), EVC_Test_Fluentcrm::tags_of(32));

        wp_set_current_user($staff);
        $refund = wc_create_refund(array('order_id' => $order->get_id(), 'amount' => '20.00', 'reason' => 'synthetic'));
        wp_set_current_user(0);
        $this->assertInstanceOf(WC_Order_Refund::class, $refund);
        $this->assertSame('refunded', wc_get_order($order->get_id())->get_status());
        $this->assertSame(array(EVC_Fluentcrm_Tag_Sync::OUTCOME_INACTIVE => 1), $this->end_of_request());
        $this->assertSame(array(3), EVC_Test_Fluentcrm::tags_of(32));
    }

    public function test_a_completion_without_the_club_confirmation_writes_nothing(): void {
        // The add-on alone (no recorded Club payment): PMPro says active, the Club cannot verify it.
        $this->setExpectedDeprecated('pmprommpu_addMembershipLevel');
        $user = $this->customer();
        EVC_Test_Fluentcrm::contact(33, self::email($user), null, array(4));
        $order = $this->on_hold($user);
        $this->end_of_request();
        $this->complete($order->get_id(), $this->staff());
        $this->assertSame(array(EVC_Fluentcrm_Tag_Sync::OUTCOME_STATE_UNKNOWN => 1), $this->end_of_request());
        $this->assertSame(array(4), EVC_Test_Fluentcrm::tags_of(33), 'never guessed Active');
    }

    // ---- the daily reconcile with the default member query ---------------------------------------

    public function test_the_reconcile_finds_order_only_customers_and_paying_members(): void {
        $this->setExpectedDeprecated('pmprommpu_addMembershipLevel');
        $waiting = $this->customer();
        $paying = $this->customer();
        $outsider = $this->customer(); // no Club order, no PMPro row: never listed
        EVC_Test_Fluentcrm::contact(41, self::email($waiting), null, array(1));
        EVC_Test_Fluentcrm::contact(42, self::email($paying), null, array());
        EVC_Test_Fluentcrm::contact(43, self::email($outsider), null, array(2));
        $this->on_hold($waiting);
        $order = $this->on_hold($paying);
        $this->wire(self::at7('-30 seconds'));
        $this->complete($order->get_id(), $this->staff());

        EVC_Fluentcrm_Plugin::boot(''); // a fresh request: nothing noted, only the cron run
        $this->assertGreaterThanOrEqual(2, EVC_Fluentcrm_Plugin::reconcile());
        $this->assertSame(array(1, 4), EVC_Test_Fluentcrm::tags_of(41), 'an unpaid order alone makes a member: Pending');
        $this->assertSame(array(2), EVC_Test_Fluentcrm::tags_of(42), 'a PMPro member: Active');
        $this->assertSame(array(2), EVC_Test_Fluentcrm::tags_of(43), 'not listed, not touched');
        $last = EVC_Fluentcrm_Plugin::status()['last'];
        $this->assertSame(1, $last[EVC_Fluentcrm_Tag_Sync::OUTCOME_PENDING] ?? 0);
        $this->assertSame(1, $last[EVC_Fluentcrm_Tag_Sync::OUTCOME_ACTIVE] ?? 0);
        $this->assertNull(EVC_Fluentcrm_Plugin::lock_value());
        // Idempotent: the next pass writes nothing.
        EVC_Test_Fluentcrm::$calls = array();
        EVC_Fluentcrm_Plugin::reconcile();
        $this->assertNotContains('attachTags', EVC_Test_Fluentcrm::$calls);
        $this->assertNotContains('detachTags', EVC_Test_Fluentcrm::$calls);
    }
}
