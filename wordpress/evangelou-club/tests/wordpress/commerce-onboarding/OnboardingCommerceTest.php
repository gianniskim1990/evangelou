<?php
/**
 * STAGE-07 on the REAL stack: WooCommerce 11.2.1 + PMPro 3.8.9 + PMPro WooCommerce 1.11 (HPOS or posts storage), the
 * Club database, the real reader / mapper / member sync / QR route, with EVC_CLUB_ONBOARDING_ENABLED and the customer
 * flag ON. The WooCommerce checkout page itself is not driven here: its result (a Club order paid by bank transfer,
 * "on-hold") is created with WooCommerce's own API, exactly as WcCompletionTest does. Nothing here is a real payment.
 */
final class OnboardingCommerceTest extends EVC_WP_Test_Case {
    const ME = '/evangelou-club-customer/v1/me';
    const QR = '/evangelou-club-customer/v1/me/qr';

    /** @var EVC_Commerce_Source_Config */
    private $config;
    /** @var array<string,mixed>|null */
    private $parts;
    /** @var string */
    private $asset_dir;

    public function set_up() {
        parent::set_up();
        $this->assertTrue(defined('WC_VERSION') && defined('PMPRO_VERSION'), 'run with EVC_TEST_COMMERCE_PLUGINS');
        evc_test_commerce_configure();
        $this->config = EVC_Commerce_Source_Config::from_constants();
        add_filter('send_auth_cookies', '__return_false');
        $_SERVER['REMOTE_ADDR'] = '203.0.113.' . wp_rand(10, 250);
        $this->asset_dir = sys_get_temp_dir() . '/evc-join-assets-' . wp_generate_password(8, false, false);
        mkdir($this->asset_dir . '/assets', 0777, true);
        file_put_contents($this->asset_dir . '/assets/customer-Ab12Cd34.js', '/* test asset */');
        file_put_contents($this->asset_dir . '/manifest.json', wp_json_encode(array('customer.html' => array('file' => 'assets/customer-Ab12Cd34.js', 'isEntry' => true))));
        // The production wiring (real membership adapter, real hand-off over the configured Club product); only the
        // built assets are a stand-in.
        EVC_Onboarding_Plugin::boot(array('assets' => new EVC_Staff_Assets($this->asset_dir, 'https://cdn.example.test/customer-app', EVC_Customer_Shell::ASSET_ENTRY)));
        rest_get_server();
        EVC_Onboarding_Plugin::parts()['controller']->register_routes(true);
    }

    public function tear_down() {
        if ($this->parts !== null) {
            $this->parts['confirmer']->unregister();
            $this->parts['guard']->disarm();
        }
        $this->parts = null;
        unset($_REQUEST['_wpnonce'], $GLOBALS['current_screen']);
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->asset_dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($this->asset_dir);
        wp_set_current_user(0);
        parent::tear_down();
    }

    // ---- helpers ------------------------------------------------------------------------------------

    private static function at7(string $relative): DateTimeImmutable {
        $t = new DateTimeImmutable($relative, new DateTimeZone('UTC'));
        return $t->setTime((int) $t->format('H'), (int) $t->format('i'), 7);
    }

    private function post(string $route, $body): WP_REST_Response {
        $server = rest_get_server();
        $request = new WP_REST_Request('POST', '/' . EVC_Customer_Rest_Security::NAMESPACE_V1 . $route);
        $request->set_header('Content-Type', 'application/json');
        $request->set_body(wp_json_encode($body));
        $nonce = wp_create_nonce('wp_rest');
        $request->set_header('X-WP-Nonce', $nonce);
        $_SERVER['HTTP_X_WP_NONCE'] = $nonce;
        $request->set_header('Origin', EVC_Rest_Join_Controller::site_origin());
        $auth = $server->check_authentication();
        $result = is_wp_error($auth) ? rest_convert_error_to_response($auth) : $server->dispatch($request);
        return apply_filters('rest_post_dispatch', rest_ensure_response($result), $server, $request);
    }

    private function step(int $user): string {
        return EVC_Onboarding_Plugin::parts()['shell']->step(get_userdata($user));
    }

    private function customer(): int {
        return (int) self::factory()->user->create(array('role' => 'customer', 'user_email' => 'ob.' . wp_generate_password(8, false, false) . '@example.org'));
    }

    private function staff(): int {
        return (int) self::factory()->user->create(array('role' => 'shop_manager', 'display_name' => 'Synthetic Shop Staff'));
    }

    /** What the WooCommerce checkout leaves behind for a Club purchase by bank transfer ($product: the Club product by default). */
    private function order(int $user, string $status = 'on-hold', ?WC_Product $product = null, ?DateTimeImmutable $created = null): WC_Order {
        $order = wc_create_order(array('customer_id' => $user));
        $order->add_product($product ?? wc_get_product(EVC_TEST_CLUB_PRODUCT), 1);
        $order->set_payment_method('bacs');
        $order->set_currency('EUR');
        $order->set_billing_email('club.billing@example.org');
        $order->set_date_created(($created ?? new DateTimeImmutable('-2 hours'))->getTimestamp());
        $order->calculate_totals();
        $order->save();
        if ($status !== 'pending') {
            $order->update_status($status);
        }
        return wc_get_order($order->get_id());
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

    /** The shop confirms the bank transfer: «Ολοκληρωμένη» on the order edit screen (the STAGE-03D path). */
    private function confirm(int $order_id, ?DateTimeImmutable $at = null): void {
        $this->wire($at ?? self::at7('-30 seconds'));
        $previous = get_current_user_id();
        wp_set_current_user($this->staff());
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
    }

    private function me(): string {
        $r = $this->rest('GET', self::ME, null, $this->nonce());
        $this->assertSame(200, $r->get_status(), wp_json_encode($r->get_data()));
        return $r->get_data()['membership']['state'];
    }

    // ---- the whole journey -------------------------------------------------------------------------

    public function test_from_the_tablet_qr_to_the_member_qr(): void {
        $this->setExpectedDeprecated('pmprommpu_addMembershipLevel');
        $email = 'tablet.' . wp_generate_password(6, false, false) . '@example.org';
        // 1. A new account from the public join page.
        $this->assertSame('register', EVC_Onboarding_Plugin::parts()['shell']->step(new WP_User(0)));
        $this->assertSame(array('result' => 'ok'), $this->post('/join/account', array('name' => 'Νίκος Γεωργίου', 'phone' => '6977000111', 'email' => $email, 'password' => 'ena-kafe-tin-imera', 'website' => ''))->get_data());
        $user = (int) get_user_by('email', $email)->ID;
        $this->login_as($user); // the browser now carries the new session
        $this->assertSame('terms', $this->step($user));
        // 2. The Club terms (no marketing consent at all: owner decision D12).
        $this->assertSame(array('result' => 'terms_required'), $this->post('/join/checkout', array())->get_data(), 'no checkout before the terms');
        $this->assertSame(array('result' => 'ok'), $this->post('/join/terms', array('accept' => true))->get_data());
        $this->assertSame('offer', $this->step($user));
        // 3. The offer: the EXISTING WooCommerce checkout with the EXISTING Club product.
        $handoff = $this->post('/join/checkout', array())->get_data();
        $this->assertSame('checkout', $handoff['next']);
        $this->assertSame(add_query_arg('add-to-cart', (string) EVC_TEST_CLUB_PRODUCT, wc_get_checkout_url()), $handoff['url']);
        $this->assertSame(array(), wc_get_orders(array('customer_id' => $user, 'limit' => -1)), 'the hand-off itself creates no order');
        // The customer completes the WooCommerce checkout with bank transfer: an on-hold Club order.
        $order = $this->order($user);
        // 4. Back on the join page (or pressing pay again): never a second order, straight to the member area.
        $again = $this->post('/join/checkout', array())->get_data();
        $this->assertSame(array('result' => 'ok', 'next' => 'member', 'url' => EVC_Customer_Shell::shell_url()), $again);
        $this->assertSame('member', $this->step($user));
        $this->assertSame('pending_payment', $this->me(), 'the member area shows pending payment');
        $this->assertSame('not_member', $this->rest('GET', self::QR, null, $this->nonce())->get_data()['qr']['state'], 'no card before the shop confirms');
        // The shop confirms the transfer; the member area shows the membership and the member's own QR.
        $this->confirm($order->get_id());
        $this->assertSame('active', $this->me());
        $qr = $this->rest('POST', self::QR, array(), $this->nonce());
        $this->assertSame(200, $qr->get_status(), wp_json_encode($qr->get_data()));
        $this->assertTrue(EVC_Qr_Token::is_valid_format($qr->get_data()['qr']['token']));
        $this->assertSame('member', $this->post('/join/checkout', array())->get_data()['next'], 'an active member is never sent to pay again from here');
        $this->assertCount(1, wc_get_orders(array('customer_id' => $user, 'limit' => -1)), 'exactly one order');
    }

    public function test_an_existing_email_on_the_real_shop_creates_nothing(): void {
        $existing = $this->customer();
        $email = get_userdata($existing)->user_email;
        $this->order($existing);
        $r = $this->post('/join/account', array('name' => 'Άλλος Άνθρωπος', 'phone' => '6900000001', 'email' => strtoupper($email), 'password' => 'diaforetikos-kodikos', 'website' => ''));
        $this->assertSame(array('result' => 'check_account'), $r->get_data());
        $this->assertSame(0, get_current_user_id());
        $this->assertCount(1, wc_get_orders(array('customer_id' => $existing, 'limit' => -1)));
    }

    public function test_a_signed_in_active_member_goes_straight_to_the_member_area(): void {
        $this->setExpectedDeprecated('pmprommpu_addMembershipLevel');
        $user = $this->customer();
        $order = $this->order($user);
        $this->confirm($order->get_id());
        $this->login_as($user);
        $this->assertSame('active', $this->me());
        $this->assertFalse(EVC_Onboarding_Handoff::terms_accepted($user));
        $this->assertSame('member', $this->step($user), 'no terms step, no offer for a member');
        $this->assertSame('member', $this->post('/join/checkout', array())->get_data()['next']);
    }

    public function test_an_open_club_order_is_never_doubled(): void {
        foreach (array('pending', 'on-hold', 'processing') as $status) {
            $user = $this->customer();
            $this->order($user, $status);
            $this->login_as($user);
            EVC_Onboarding_Handoff::accept_terms($user);
            $this->assertSame('member', $this->step($user), $status);
            $this->assertSame(array('result' => 'ok', 'next' => 'member', 'url' => EVC_Customer_Shell::shell_url()), $this->post('/join/checkout', array())->get_data(), $status);
            $this->assertCount(1, wc_get_orders(array('customer_id' => $user, 'limit' => -1)), $status);
        }
    }

    /** The paid month is over (PMPro's expiry cron has not run yet) and a renewal waits for its transfer: no second order. */
    public function test_an_ended_membership_with_a_waiting_renewal_order_is_not_sent_to_pay_twice(): void {
        $this->setExpectedDeprecated('pmprommpu_addMembershipLevel');
        $user = $this->customer();
        $at = self::at7('-40 days');
        $first = $this->order($user, 'on-hold', null, $at->modify('-1 hour'));
        $this->confirm($first->get_id(), $at);
        $this->login_as($user);
        $this->assertSame('expired', $this->me(), 'the paid month ended');
        EVC_Onboarding_Handoff::accept_terms($user);
        $this->assertSame('offer', $this->step($user), 'an ended membership may be renewed from here');
        $this->order($user); // the renewal by bank transfer, waiting
        $this->assertSame('member', $this->step($user));
        $this->assertSame('member', $this->post('/join/checkout', array())->get_data()['next']);
        $this->assertCount(2, wc_get_orders(array('customer_id' => $user, 'limit' => -1)));
    }

    public function test_a_cancelled_order_or_an_order_of_something_else_leaves_the_offer_open(): void {
        $user = $this->customer();
        $this->order($user)->update_status('cancelled');
        $coffee = new WC_Product_Simple();
        $coffee->set_name('Synthetic espresso');
        $coffee->set_regular_price('3');
        $coffee->set_status('publish');
        $coffee->save();
        $this->order($user, 'on-hold', $coffee);
        $this->login_as($user);
        EVC_Onboarding_Handoff::accept_terms($user);
        $this->assertSame('offer', $this->step($user));
        $this->assertSame('checkout', $this->post('/join/checkout', array())->get_data()['next']);
    }

    public function test_another_customers_open_order_never_counts(): void {
        $other = $this->customer();
        $this->order($other);
        $user = $this->customer();
        $this->login_as($user);
        EVC_Onboarding_Handoff::accept_terms($user);
        $this->assertSame('checkout', $this->post('/join/checkout', array())->get_data()['next']);
    }
}
