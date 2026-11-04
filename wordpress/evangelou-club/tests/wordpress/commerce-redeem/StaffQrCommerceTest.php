<?php

/**
 * STAGE-05C end to end on the REAL WooCommerce 11.2.1 / PMPro 3.8.9 / PMPro WooCommerce 1.11 (HPOS
 * and posts in CI), staff flag ON, EVC_CLUB_REDEMPTION_SOURCE = 'club_db_v1', recorded provenance,
 * the disposable Club DB (migrations 001-003) and EVC_CLUB_LOOKUP_KEY: shop staff complete the
 * Club order → the Club records the payment and creates the member row (STAGE-05B) → the customer
 * gets a QR (STAGE-05C customer backend, real constants) → the tablet scans it and sees the first
 * name + status (D8) → the redeem route records one coffee → the scan shows "used today" → a
 * cancellation freezes the card and the scan shows "not active". CI only.
 *
 * Every piece is the shipped code with the real configuration; only the clocks are fixed (test
 * seams), and the customer backend's environment adds the customer flag (this suite runs with it
 * off).
 */
final class StaffQrCommerceTest extends EVC_WP_Test_Case {
    const LOOKUP = '/evangelou-club/v1/members/lookup';

    /** @var EVC_Commerce_Source_Config */
    private $config;
    /** @var array<string,mixed>|null */
    private $parts;
    /** @var EVC_Club_Db */
    private $club;
    /** @var string[] */
    private $log = array();

    public function set_up() {
        parent::set_up();
        $this->assertTrue(defined('WC_VERSION') && defined('PMPRO_VERSION') && defined('PMPROWC_DIR'), 'run with EVC_TEST_COMMERCE_PLUGINS');
        evc_test_commerce_configure();
        $this->config = EVC_Commerce_Source_Config::from_constants();
        $this->club = EVC_Club_Db::connect(EVC_Db_Config::from_constants());
        $this->log = array();
    }

    public function tear_down() {
        if ($this->parts !== null) {
            $this->parts['confirmer']->unregister();
            $this->parts['guard']->disarm();
        }
        unset($_REQUEST['_wpnonce'], $GLOBALS['current_screen']);
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
        return (int) self::factory()->user->create(array('role' => 'customer', 'first_name' => 'Μαρία', 'last_name' => 'Παπαδοπούλου', 'user_email' => 'qr.' . wp_generate_password(6, false, false) . '@example.org'));
    }

    private function order(int $user_id, DateTimeImmutable $created): WC_Order {
        $order = wc_create_order(array('customer_id' => $user_id));
        $order->add_product(wc_get_product(EVC_TEST_CLUB_PRODUCT), 1);
        $order->set_payment_method('bacs');
        $order->set_currency('EUR');
        $order->set_date_created($created->getTimestamp());
        $order->calculate_totals();
        $order->save();
        $order->update_status('on-hold');
        return wc_get_order($order->get_id());
    }

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

    /** The customer's QR from the shipped customer backend with the real Club DB constants. */
    private function issue_qr(int $wp_user_id, DateTimeImmutable $at): string {
        $backend = new EVC_Customer_Qr_Backend(function () {
            return array('customer' => true) + EVC_Customer_Qr_Backend::constants();
        }, null, new EVC_Fixed_Clock($at->format('Y-m-d\TH:i:s\Z')), function (string $line) {
            $this->log[] = $line;
        });
        $r = $backend->issue($wp_user_id, false);
        $this->assertSame('issued', $r['result'], (string) $backend->last_reason());
        return $r['token'];
    }

    private function scan_at(EVC_Fixed_Clock $clock, string $token): WP_REST_Response {
        (new EVC_Rest_Lookup_Controller(new EVC_Club_Lookup_Backend(null, null, $clock, function (string $line) {
            $this->log[] = $line;
        })))->register_routes(true);
        return $this->rest('POST', self::LOOKUP, array('method' => 'qr', 'qr_token' => $token), $this->nonce());
    }

    private function redeem_at(EVC_Fixed_Clock $clock, string $member): WP_REST_Response {
        (new EVC_Rest_Redeem_Controller(new EVC_Club_Redemption_Backend(new EVC_Approved_Coffee_Catalogue_Source(), null, null, $clock)))->register_routes(true);
        return $this->rest('POST', $this->redeem_route($member), array('request_id' => self::uuid4(), 'coffee_code' => 'espresso'), $this->nonce());
    }

    public function test_the_shipped_routes_are_registered_with_the_real_configuration(): void {
        $routes = rest_get_server()->get_routes();
        $this->assertArrayHasKey(self::LOOKUP, $routes);
        $this->assertSame('club_db_v1', EVC_CLUB_REDEMPTION_SOURCE);
        $this->assertNotNull(EVC_Club_Qr_Services::lookup_secret_from_constants());
        $this->login_as($this->create_staff_user());
        $this->assertEnvelope($this->rest('POST', self::LOOKUP, array('method' => 'qr', 'qr_token' => EVC_Qr_Token::mint()), $this->nonce()), 404, 'member_not_found');
    }

    public function test_scan_shows_first_name_and_status_through_payment_coffee_and_cancellation(): void {
        $this->setExpectedDeprecated('pmprommpu_addMembershipLevel');
        $at = self::utc('2030-03-16 10:00:07');
        $customer = $this->customer();
        $this->wire($at);
        $order = $this->order($customer, $at->modify('-1 hour'));
        $done = $this->complete_in_admin($order->get_id());
        $this->assertSame(EVC_Wc_Completion_Result::RECORDED, $done->outcome, (string) $done->reason);
        $this->assertSame(EVC_Member_Sync_Result::PROVISIONED, $done->member);
        $member = $this->club->fetch_one('SELECT member_public_id FROM evc_members WHERE wp_user_id = ?', array($customer))['member_public_id'];

        $token = $this->issue_qr($customer, $at->modify('+1 minute'));
        $this->login_as($this->create_staff_user());
        $clock = new EVC_Fixed_Clock('2030-03-16T10:05:00Z');

        $scan = $this->scan_at($clock, $token);
        $this->assertSame(200, $scan->get_status(), wp_json_encode($scan->get_data()));
        $this->assertSame(array('member' => array('member_id' => $member, 'first_name' => 'Μαρία'), 'status' => 'active'), $scan->get_data());

        $this->assertSame(200, $this->redeem_at($clock, $scan->get_data()['member']['member_id'])->get_status());
        $this->assertSame('used_today', $this->scan_at($clock, $token)->get_data()['status']);
        $clock->set('2030-03-16T22:00:00Z'); // Athens midnight
        $this->assertSame('active', $this->scan_at($clock, $token)->get_data()['status']);

        $staff = get_current_user_id();
        wp_set_current_user(0);
        wc_get_order($order->get_id())->update_status('cancelled');
        wp_set_current_user($staff);
        $this->assertSame(EVC_Wc_Completion_Result::CORRECTED, $this->parts['confirmer']->last_result()->outcome);
        $clock->set('2030-03-17T10:05:00Z');
        $frozen = $this->scan_at($clock, $token);
        $this->assertSame(200, $frozen->get_status());
        $this->assertSame('inactive', $frozen->get_data()['status'], 'a cancellation freezes the card');
        $this->assertEnvelope($this->redeem_at($clock, $member), 409, 'membership_inactive');

        foreach ($this->log as $line) {
            $this->assertStringNotContainsString($token, $line);
            $this->assertStringNotContainsString('Μαρία', $line);
        }
    }

    public function test_an_unpaid_customer_with_a_member_row_scans_as_not_active(): void {
        $customer = $this->customer();
        $this->club->execute('INSERT INTO evc_members (member_public_id, wp_user_id, status, created_at_utc, updated_at_utc) VALUES (?, ?, ?, ?, ?)', array(EVC_Member_Id::mint(), $customer, 'enabled', '2030-03-18 09:00:00.000000', '2030-03-18 09:00:00.000000'));
        $token = $this->issue_qr($customer, self::utc('2030-03-18 09:00:00'));
        $this->login_as($this->create_staff_user());
        $scan = $this->scan_at(new EVC_Fixed_Clock('2030-03-18T10:00:00Z'), $token);
        $this->assertSame(200, $scan->get_status(), wp_json_encode($scan->get_data()));
        $this->assertSame('inactive', $scan->get_data()['status'], 'no recorded payment: never active');
    }
}
