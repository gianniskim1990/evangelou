<?php

/**
 * STAGE-05B end to end on the real production wiring of the staff redeem route
 * (staff flag ON, EVC_CLUB_REDEMPTION_SOURCE = 'club_db_v1', recorded provenance, the
 * disposable Club DB) and the REAL WooCommerce 11.2.1 / PMPro 3.8.9 / PMPro WooCommerce
 * 1.11 (HPOS and posts in CI): shop staff complete the Club order in the WooCommerce
 * admin → the Club records the payment → the customer gets ONE member row → staff redeem
 * one coffee per Athens day for that member. No row is seeded by hand. CI only.
 *
 * The confirmation path is built through EVC_Wc_Completion_Plugin::build (the only
 * builder of EVC_Club_Member_Sync) with a fixed clock; the route uses the real backend
 * with a fixed clock (EVC_Club_Redemption_Backend test seam), both in 2030.
 */
final class MemberSyncRedeemTest extends EVC_WP_Test_Case {
    /** @var EVC_Commerce_Source_Config */
    private $config;
    /** @var array<string,mixed>|null */
    private $parts;
    /** @var EVC_Club_Db */
    private $club;

    public function set_up() {
        parent::set_up();
        $this->assertTrue(defined('WC_VERSION') && defined('PMPRO_VERSION') && defined('PMPROWC_DIR'), 'run with EVC_TEST_COMMERCE_PLUGINS');
        evc_test_commerce_configure();
        $this->config = EVC_Commerce_Source_Config::from_constants();
        $this->club = EVC_Club_Db::connect(EVC_Db_Config::from_constants());
    }

    public function tear_down() {
        if ($this->parts !== null) {
            $this->parts['confirmer']->unregister();
            $this->parts['guard']->disarm();
        }
        unset($_REQUEST['_wpnonce'], $GLOBALS['current_screen']);
        // PHPUnit keeps every test object until the end of the run: release the Club DB connections now.
        $this->parts = null;
        $this->club = null;
        parent::tear_down();
    }

    private static function utc(string $s): DateTimeImmutable {
        return new DateTimeImmutable($s, new DateTimeZone('UTC'));
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

    private function customer(): int {
        return (int) self::factory()->user->create(array('role' => 'customer', 'user_email' => 'club.' . wp_generate_password(6, false, false) . '@example.org'));
    }

    private function order(int $user_id, DateTimeImmutable $created, string $status): WC_Order {
        $order = wc_create_order(array('customer_id' => $user_id));
        $order->add_product(wc_get_product(EVC_TEST_CLUB_PRODUCT), 1);
        $order->set_payment_method('bacs');
        $order->set_currency('EUR');
        $order->set_date_created($created->getTimestamp());
        $order->calculate_totals();
        $order->save();
        if ($status !== 'pending') {
            $order->update_status($status);
        }
        return wc_get_order($order->get_id());
    }

    /** Shop staff (their own account, WooCommerce capability only) set «Ολοκληρωμένη» on the order edit screen. */
    private function complete_in_admin(int $order_id): EVC_Wc_Completion_Result {
        $staff = (int) self::factory()->user->create(array('role' => 'shop_manager'));
        $previous = get_current_user_id();
        wp_set_current_user($staff);
        if (!class_exists('WP_Screen')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
        }
        $GLOBALS['current_screen'] = WP_Screen::get('dashboard');
        try {
            $action = (\Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? 'update-order_' : 'update-post_') . $order_id;
            $_REQUEST['_wpnonce'] = wp_create_nonce($action);
            $this->assertSame(1, check_admin_referer($action));
            wc_get_order($order_id)->update_status('completed', '', true);
        } finally {
            unset($_REQUEST['_wpnonce'], $GLOBALS['current_screen']);
            wp_set_current_user($previous);
            $this->parts['confirmer']->forget();
        }
        return $this->parts['confirmer']->last_result();
    }

    /** The only member id the Club created for this customer (asserts exactly one). */
    private function member_of(int $wp_user_id): string {
        $rows = $this->club->fetch_all('SELECT member_public_id FROM evc_members WHERE wp_user_id = ?', array($wp_user_id));
        $this->assertCount(1, $rows, 'exactly one member row');
        return $rows[0]['member_public_id'];
    }

    private function redeem_at(EVC_Fixed_Clock $clock, string $member, string $coffee = 'espresso'): WP_REST_Response {
        (new EVC_Rest_Redeem_Controller(new EVC_Club_Redemption_Backend(new EVC_Approved_Coffee_Catalogue_Source(), null, null, $clock)))->register_routes(true);
        return $this->rest('POST', $this->redeem_route($member), array('request_id' => self::uuid4(), 'coffee_code' => $coffee), $this->nonce());
    }

    private function ledger(string $member): array {
        return $this->club->fetch_all('SELECT r.business_date FROM evc_redemptions r JOIN evc_members m ON m.id = r.member_id WHERE m.member_public_id = ? ORDER BY r.id', array($member));
    }

    /** @return array{0:int,1:WC_Order,2:EVC_Wc_Completion_Result} */
    private function confirmed_customer(DateTimeImmutable $at): array {
        $this->setExpectedDeprecated('pmprommpu_addMembershipLevel');
        $customer = $this->customer();
        $this->wire($at);
        $order = $this->order($customer, $at->modify('-1 hour'), 'on-hold');
        $r = $this->complete_in_admin($order->get_id());
        $this->assertSame(EVC_Wc_Completion_Result::RECORDED, $r->outcome, (string) $r->reason . ' ' . implode(',', $r->steps));
        $this->assertSame(EVC_Member_Sync_Result::PROVISIONED, $r->member);
        return array($customer, $order, $r);
    }

    public function test_a_completion_confirmed_in_woocommerce_lets_staff_redeem_once_per_athens_day(): void {
        list($customer) = $this->confirmed_customer(self::utc('2030-03-12 10:00:07'));
        $member = $this->member_of($customer);
        $this->login_as($this->create_staff_user());

        $clock = new EVC_Fixed_Clock('2030-03-12T10:05:00Z');
        $first = $this->redeem_at($clock, $member, 'other_coffee');
        $this->assertSame(200, $first->get_status(), wp_json_encode($first->get_data()));
        $this->assertSame($member, $first->get_data()['member_id']);
        $this->assertSame('2030-03-12', $first->get_data()['business_date']);

        $clock->set('2030-03-12T21:59:59Z'); // 23:59:59 Athens (EET), same Athens day
        $again = $this->redeem_at($clock, $member, 'cappuccino');
        $this->assertEnvelope($again, 409, 'benefit_already_redeemed');
        $this->assertCount(1, $this->ledger($member));

        $clock->set('2030-03-12T22:00:00Z'); // Athens midnight: a new day
        $this->assertSame(200, $this->redeem_at($clock, $member)->get_status());
        $this->assertCount(2, $this->ledger($member));
        $this->member_of($customer); // still exactly one row
    }

    public function test_an_unconfirmed_order_never_creates_a_member_and_an_unknown_member_is_404(): void {
        $at = self::utc('2030-03-13 10:00:07');
        $this->wire($at);
        foreach (array('pending', 'on-hold', 'processing') as $status) {
            $customer = $this->customer();
            $this->order($customer, $at->modify('-1 hour'), $status);
            $this->parts['recovery']->run();
            $this->assertSame(array(), $this->club->fetch_all('SELECT id FROM evc_members WHERE wp_user_id = ?', array($customer)), $status);
        }
        $this->login_as($this->create_staff_user());
        $this->assertEnvelope($this->redeem_at(new EVC_Fixed_Clock('2030-03-13T10:05:00Z'), EVC_Member_Id::mint()), 404, 'member_not_found');
    }

    public function test_cancelling_the_confirmed_order_freezes_the_card_for_the_synced_member(): void {
        $at = self::utc('2030-03-14 10:00:07');
        list($customer, $order) = $this->confirmed_customer($at);
        $member = $this->member_of($customer);
        $this->login_as($this->create_staff_user());
        $this->assertSame(200, $this->redeem_at(new EVC_Fixed_Clock('2030-03-14T10:05:00Z'), $member)->get_status());

        $staff = get_current_user_id();
        wp_set_current_user(0); // cancelled by the shop (any channel freezes the card)
        wc_get_order($order->get_id())->update_status('cancelled');
        wp_set_current_user($staff);
        $this->assertSame(EVC_Wc_Completion_Result::CORRECTED, $this->parts['confirmer']->last_result()->outcome);

        $frozen = $this->redeem_at(new EVC_Fixed_Clock('2030-03-15T10:05:00Z'), $member);
        $this->assertEnvelope($frozen, 409, 'membership_inactive');
        $this->assertCount(1, $this->ledger($member), 'nothing granted after the cancellation');
        $this->assertSame($member, $this->member_of($customer), 'the identity stays; only the card is frozen');
    }
}
