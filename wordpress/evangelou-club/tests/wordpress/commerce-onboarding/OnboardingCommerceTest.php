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
    /** @var int[]|null the product ids the checkout guard sees (the boot 'cart' seam); null = the real WooCommerce cart */
    private $cart = array();

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
        // The cart the checkout guard reads is a seam (the WooCommerce session cookie cannot be set under PHPUnit); the
        // guard's own reader of WC()->cart is checked separately below.
        $this->cart = array();
        EVC_Onboarding_Plugin::boot(array(
            'assets' => new EVC_Staff_Assets($this->asset_dir, 'https://cdn.example.test/customer-app', EVC_Customer_Shell::ASSET_ENTRY),
            'cart' => function (): array {
                return $this->cart ?? EVC_Onboarding_Checkout_Guard::wc_cart_products();
            },
        ));
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
        $this->assertSame(array('result' => 'ok'), $this->post('/join/account', array('name' => 'Νίκος Γεωργίου', 'phone' => '6977000111', 'email' => $email, 'password' => 'ena-kafe-tin-imera', 'evc_extra' => ''))->get_data());
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
        $r = $this->post('/join/account', array('name' => 'Άλλος Άνθρωπος', 'phone' => '6900000001', 'email' => strtoupper($email), 'password' => 'diaforetikos-kodikos', 'evc_extra' => ''));
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

    private function coffee(): WC_Product_Simple {
        $coffee = new WC_Product_Simple();
        $coffee->set_name('Synthetic freddo');
        $coffee->set_regular_price('3');
        $coffee->set_status('publish');
        $coffee->save();
        return $coffee;
    }

    private function guard(): EVC_Onboarding_Checkout_Guard {
        return EVC_Onboarding_Plugin::parts()['guard'];
    }

    /** WooCommerce's add-to-cart validation (every plugin's filter on it), with WC()->cart holding $this->cart. */
    private function can_add(int $product_id): bool {
        $saved = WC()->cart;
        WC()->cart = self::fake_cart($this->cart);
        try {
            return (bool) apply_filters('woocommerce_add_to_cart_validation', true, $product_id, 1);
        } finally {
            WC()->cart = $saved;
        }
    }

    /**
     * A WC_Cart already loaded with these products (under PHPUnit no session cookie can be set, so the real cart is
     * never loaded from a session): get_cart() / get_cart_contents() answer the lines as the real cart does.
     */
    private static function fake_cart(array $product_ids): WC_Cart {
        $lines = array();
        foreach ($product_ids as $i => $id) {
            $lines['line' . $i] = array('key' => 'line' . $i, 'product_id' => $id, 'variation_id' => 0, 'variation' => array(), 'quantity' => 1, 'data' => wc_get_product($id));
        }
        return new class($lines) extends WC_Cart {
            public function __construct(array $lines) {
                $this->cart_contents = $lines;
            }

            public function get_cart() {
                return $this->get_cart_contents();
            }
        };
    }

    /**
     * The guard's own answer for this cart (the full WooCommerce chain may already refuse before it: PMPro WooCommerce
     * also has rules on membership products, and the guard never overrides an earlier refusal).
     */
    private function guard_refuses(int $product_id, string $message): void {
        $this->assertFalse($this->can_add($product_id), 'the shop refuses it (' . self::validators() . ')');
        $this->guard()->last_message = null;
        $this->assertFalse($this->guard()->add_to_cart(true, $product_id, 1), 'the Club guard refuses it on its own');
        $this->assertSame($message, $this->guard()->last_message);
    }

    private static function validators(): string {
        $names = array();
        $hook = $GLOBALS['wp_filter']['woocommerce_add_to_cart_validation'] ?? null;
        foreach ($hook instanceof WP_Hook ? $hook->callbacks : array() as $priority => $callbacks) {
            foreach ($callbacks as $cb) {
                $f = $cb['function'];
                $names[] = $priority . ':' . (is_string($f) ? $f : (is_array($f) ? (is_object($f[0]) ? get_class($f[0]) : $f[0]) . '::' . $f[1] : 'closure'));
            }
        }
        return implode(', ', $names);
    }

    /** @return string[] the Club's error codes among those WooCommerce's checkout validation collected */
    private function checkout_errors(array $data = array()): array {
        $errors = new WP_Error();
        do_action('woocommerce_after_checkout_validation', $data, $errors);
        return array_values(array_filter($errors->get_error_codes(), function ($code) {
            return strpos((string) $code, 'evc_') === 0;
        }));
    }

    /** @return string[] the gateways WooCommerce offers right now */
    private function offered(): array {
        return array_keys(WC()->payment_gateways()->get_available_payment_gateways());
    }

    // ---- round 2: the Club rules on the shop's own cart and checkout ----------------------------------

    public function test_the_cart_takes_the_club_product_alone_and_never_twice(): void {
        $this->setExpectedDeprecated('pmprommpu_addMembershipLevel');
        $club = EVC_TEST_CLUB_PRODUCT;
        $coffee = $this->coffee()->get_id();
        $this->assertSame(5, has_filter('woocommerce_add_to_cart_validation', array($this->guard(), 'add_to_cart')), 'hooked by boot(), before other validators');
        $this->assertTrue($this->can_add($club), 'a guest with an empty cart (a saved ?add-to-cart= link): the checkout asks for an account');
        $this->assertTrue($this->can_add($coffee), 'other products are untouched');
        $this->cart = array($coffee);
        $this->guard_refuses($club, EVC_Onboarding_Checkout_Guard::MIXED); // not into a cart with other products
        $this->assertTrue($this->can_add($coffee));
        $this->cart = array($club);
        $this->guard_refuses($coffee, EVC_Onboarding_Checkout_Guard::MIXED); // nothing else into a cart with the Club product
        $this->cart = array();
        // A second device / a saved link while an order waits for its transfer.
        $user = $this->customer();
        $order = $this->order($user);
        $this->login_as($user);
        $this->guard_refuses($club, EVC_Onboarding_Checkout_Guard::DUPLICATE); // an open Club order: no second one
        $this->assertTrue($this->can_add($coffee), 'the same customer can still buy coffee');
        // Paid and confirmed: an active member may renew early (owner decision D2).
        $this->confirm($order->get_id());
        $this->assertSame('active', $this->me());
        $this->assertTrue($this->can_add($club), 'early renewal stays possible');
        $other = $this->customer();
        $this->login_as($other);
        $this->assertTrue($this->can_add($club), 'a new customer');
    }

    public function test_the_checkout_refuses_a_mixed_cart_a_guest_and_a_second_order(): void {
        $club = EVC_TEST_CLUB_PRODUCT;
        $coffee = $this->coffee()->get_id();
        $this->cart = array($coffee);
        $this->assertSame(array(), $this->checkout_errors(), 'a coffee checkout is untouched, even for a guest');
        $this->cart = array($club);
        $this->assertSame(array('evc_club_account_required'), $this->checkout_errors(array('createaccount' => 0)), 'a guest Club order could never be attributed');
        $this->assertSame(EVC_Onboarding_Checkout_Guard::ACCOUNT, $this->guard()->last_message);
        $this->assertSame(array(), $this->checkout_errors(array('createaccount' => 1)), 'creating the account at the checkout is fine');
        $user = $this->customer();
        $this->login_as($user);
        $this->assertSame(array(), $this->checkout_errors(), 'one Club item, a signed-in customer');
        $this->cart = array($club, $coffee);
        $this->assertSame(array('evc_club_mixed_cart'), $this->checkout_errors(), 'bought alone');
        $this->cart = array($club);
        $this->order($user, 'pending'); // the first device left an unpaid order
        $this->assertSame(array('evc_club_duplicate_order'), $this->checkout_errors(), 'the second device');
        $this->assertSame(EVC_Onboarding_Checkout_Guard::DUPLICATE, $this->guard()->last_message);
    }

    public function test_bank_transfer_is_the_only_gateway_for_the_club_and_none_without_it(): void {
        $gateways = WC()->payment_gateways()->payment_gateways();
        $this->assertArrayHasKey('bacs', $gateways);
        $this->assertArrayHasKey('cheque', $gateways);
        $was = array($gateways['bacs']->enabled, $gateways['cheque']->enabled);
        $gateways['bacs']->enabled = 'yes';
        $gateways['cheque']->enabled = 'yes';
        try {
            $this->cart = array($this->coffee()->get_id());
            $this->assertContains('bacs', $this->offered());
            $this->assertContains('cheque', $this->offered(), 'other products keep every gateway');
            $this->assertSame('Χωρίς τρόπο', apply_filters('woocommerce_no_available_payment_methods_message', 'Χωρίς τρόπο'));
            $this->cart = array(EVC_TEST_CLUB_PRODUCT);
            $this->assertSame(array('bacs'), $this->offered(), 'owner decision D15: only bank transfer');
            $gateways['bacs']->enabled = 'no';
            $this->assertSame(array(), $this->offered(), 'fail closed: no other gateway takes its place');
            $this->assertSame(EVC_Onboarding_Checkout_Guard::NO_BACS, apply_filters('woocommerce_no_available_payment_methods_message', 'Χωρίς τρόπο'), 'and the customer is told why, in Greek');
            $this->cart = array();
            $this->assertContains('cheque', $this->offered(), 'without the Club product nothing changes');
            $this->assertNotContains('bacs', $this->offered());
        } finally {
            $gateways['bacs']->enabled = $was[0];
            $gateways['cheque']->enabled = $was[1];
        }
    }

    public function test_the_guard_reads_the_real_woocommerce_cart(): void {
        $saved = WC()->cart;
        try {
            $coffee = $this->coffee()->get_id();
            WC()->cart = self::fake_cart(array(EVC_TEST_CLUB_PRODUCT, $coffee));
            $this->assertSame(array(EVC_TEST_CLUB_PRODUCT, $coffee), EVC_Onboarding_Checkout_Guard::wc_cart_products());
            WC()->cart = null;
            $this->assertSame(array(), EVC_Onboarding_Checkout_Guard::wc_cart_products(), 'no cart: nothing');
        } finally {
            WC()->cart = $saved;
        }
    }

    /** Round 2 (finding 3): the open-order check reads ALL the customer's open orders, never one page of the shop's. */
    public function test_fifty_newer_open_orders_of_other_customers_never_hide_an_older_club_order(): void {
        $user = $this->customer();
        $this->order($user, 'on-hold', null, new DateTimeImmutable('-20 days'));
        $others = array($this->customer(), $this->customer(), $this->customer());
        for ($i = 0; $i < 55; $i++) {
            $this->order($others[$i % 3], 'on-hold', null, new DateTimeImmutable('-' . (60 - $i) . ' minutes'));
        }
        $this->login_as($user);
        EVC_Onboarding_Handoff::accept_terms($user);
        $this->assertSame('member', $this->step($user), 'the older Club order is found');
        $this->assertTrue(EVC_Onboarding_Plugin::parts()['handoff']->blocks_new_order($user));
        $fresh = $this->customer();
        $this->login_as($fresh);
        EVC_Onboarding_Handoff::accept_terms($fresh);
        $this->assertSame('offer', $this->step($fresh), "55 other customers' Club orders do not count for anyone else");
        $this->assertFalse(EVC_Onboarding_Plugin::parts()['handoff']->blocks_new_order($fresh));
    }

    /**
     * An on-hold Club order alone already reads as "pending payment" through the membership, so the open-order check
     * is what matters when the membership reads ENDED and a renewal waits: that renewal must be found behind 51 newer
     * open orders of other products (round 2, finding 3).
     */
    public function test_a_waiting_renewal_behind_fifty_open_orders_of_other_products_is_found(): void {
        $this->setExpectedDeprecated('pmprommpu_addMembershipLevel');
        $user = $this->customer();
        $at = self::at7('-40 days');
        $first = $this->order($user, 'on-hold', null, $at->modify('-1 hour'));
        $this->confirm($first->get_id(), $at);
        $this->login_as($user);
        $this->assertSame('expired', $this->me(), 'the paid month ended');
        $this->order($user, 'on-hold', null, new DateTimeImmutable('-3 days')); // the renewal by bank transfer, waiting
        $coffee = $this->coffee();
        for ($i = 0; $i < 51; $i++) {
            $this->order($user, 'processing', $coffee, new DateTimeImmutable('-' . (60 - $i) . ' minutes'));
        }
        $this->assertCount(52, wc_get_orders(array('customer_id' => $user, 'status' => EVC_Onboarding_Handoff::OPEN_STATUSES, 'limit' => -1, 'return' => 'ids')));
        EVC_Onboarding_Handoff::accept_terms($user);
        $this->assertSame('member', $this->step($user), 'the oldest of 52 open orders is the waiting renewal');
        $this->assertSame('member', $this->post('/join/checkout', array())->get_data()['next']);
        $this->assertTrue(EVC_Onboarding_Plugin::parts()['handoff']->blocks_new_order($user), 'and the cart / checkout refuse a second one');
        $this->guard()->last_message = null;
        $this->assertFalse($this->guard()->add_to_cart(true, EVC_TEST_CLUB_PRODUCT, 1));
        $this->assertSame(EVC_Onboarding_Checkout_Guard::DUPLICATE, $this->guard()->last_message);
    }

    // ---- round 3 (R1): the Block Checkout, through the real Store API ---------------------------------

    /** The Store API with the REAL WooCommerce cart and session (nonce check and cookies off: no browser here). */
    private function store_api(?int $user): void {
        add_filter('woocommerce_store_api_disable_nonce_check', '__return_true');
        add_filter('woocommerce_set_cookie_enabled', '__return_false');
        if ($user !== null) {
            $this->login_as($user);
        } else {
            wp_set_current_user(0);
        }
        if (WC()->cart === null || WC()->session === null) {
            wc_load_cart();
        }
        WC()->customer = new WC_Customer(get_current_user_id(), true);
        WC()->cart->empty_cart();
        $this->cart = null; // the guard reads the real cart
    }

    private function store(string $method, string $route, ?array $body = null): WP_REST_Response {
        $request = new WP_REST_Request($method, '/wc/store/v1' . $route);
        if ($body !== null) {
            $request->set_header('Content-Type', 'application/json');
            $request->set_body(wp_json_encode($body));
        }
        return rest_ensure_response(rest_do_request($request));
    }

    private function add_item(int $product_id): WP_REST_Response {
        return $this->store('POST', '/cart/add-item', array('id' => $product_id, 'quantity' => 1));
    }

    /** @return int[] the product ids of the Store API cart */
    private function store_cart(): array {
        $r = $this->store('GET', '/cart');
        $this->assertSame(200, $r->get_status(), wp_json_encode($r->get_data()));
        return array_map('intval', array_column($r->get_data()['items'], 'id'));
    }

    private function place_order(string $email, string $method = 'bacs', array $extra = array()): WP_REST_Response {
        // A browser places each order in a new PHP request. Here the Store API's checkout route object lives on between
        // requests and a POST reuses the order it last held; its GET re-reads the session's draft, as a new request does.
        // (test_a_fresh_server_places_the_order_without_a_preceding_get shows the same without the GET.)
        $this->store('GET', '/checkout');
        return $this->store('POST', '/checkout', self::checkout_body($email, $method, $extra));
    }

    private static function checkout_body(string $email, string $method = 'bacs', array $extra = array()): array {
        $address = array('first_name' => 'Ελένη', 'last_name' => 'Δοκιμή', 'company' => '', 'address_1' => 'Ομονοίας 1', 'address_2' => '', 'city' => 'Καβάλα', 'state' => '', 'postcode' => '65403', 'country' => 'GR', 'phone' => '6900000000');
        return array_merge(array('billing_address' => $address + array('email' => $email), 'shipping_address' => $address, 'payment_method' => $method), $extra);
    }

    private static function open_club_orders(int $user): int {
        return count(wc_get_orders(array('customer_id' => $user, 'status' => EVC_Onboarding_Handoff::OPEN_STATUSES, 'limit' => -1, 'return' => 'ids')));
    }

    public function test_the_store_api_cart_keeps_the_club_product_alone_and_never_twice(): void {
        $user = $this->customer();
        $this->store_api($user);
        $coffee = $this->coffee()->get_id();
        $this->assertSame(201, $this->add_item($coffee)->get_status());
        $r = $this->add_item(EVC_TEST_CLUB_PRODUCT);
        $this->assertSame(400, $r->get_status(), wp_json_encode($r->get_data()));
        $this->assertSame(EVC_Onboarding_Checkout_Guard::MIXED, $r->get_data()['message'], 'Store API add-item applies woocommerce_add_to_cart_validation and shows our reason');
        $this->assertSame(array($coffee), $this->store_cart());
        WC()->cart->empty_cart();
        $this->assertSame(201, $this->add_item(EVC_TEST_CLUB_PRODUCT)->get_status());
        $r = $this->add_item($coffee);
        $this->assertSame(400, $r->get_status());
        $this->assertSame(EVC_Onboarding_Checkout_Guard::MIXED, $r->get_data()['message']);
        $this->assertSame(array(EVC_TEST_CLUB_PRODUCT), $this->store_cart());
        WC()->cart->empty_cart();
        $this->order($user); // waiting for its transfer (another device)
        $r = $this->add_item(EVC_TEST_CLUB_PRODUCT);
        $this->assertSame(400, $r->get_status());
        $this->assertSame(EVC_Onboarding_Checkout_Guard::DUPLICATE, $r->get_data()['message']);
        $this->assertSame(array(), $this->store_cart());
    }

    public function test_the_block_checkout_refuses_a_mixed_cart_a_second_order_and_a_guest_without_an_account(): void {
        $user = $this->customer();
        $this->store_api($user);
        // A cart that bypassed the add-to-cart rules (e.g. filled before the flag was on): the checkout still refuses.
        remove_all_filters('woocommerce_add_to_cart_validation');
        $coffee = $this->coffee()->get_id();
        $this->add_item($coffee);
        $this->add_item(EVC_TEST_CLUB_PRODUCT);
        $this->assertSame(array($coffee, EVC_TEST_CLUB_PRODUCT), $this->store_cart());
        $email = get_userdata($user)->user_email;
        $r = $this->place_order($email);
        $this->assertSame(409, $r->get_status(), wp_json_encode($r->get_data()));
        $this->assertStringContainsString('evc_club_mixed_cart', (string) wp_json_encode($r->get_data()));
        $this->assertSame(0, self::open_club_orders($user));
        // The Club product alone, signed in: the order is placed by bank transfer (on-hold).
        WC()->cart->empty_cart();
        $this->add_item(EVC_TEST_CLUB_PRODUCT);
        $r = $this->place_order($email);
        $this->assertSame(200, $r->get_status(), wp_json_encode($r->get_data()));
        $this->assertSame('on-hold', $r->get_data()['status']);
        $this->assertSame(1, self::open_club_orders($user));
        // A second Club order while the first waits: refused before any order exists.
        $this->add_item(EVC_TEST_CLUB_PRODUCT);
        $r = $this->place_order($email);
        $this->assertSame(409, $r->get_status(), wp_json_encode($r->get_data()));
        $this->assertStringContainsString('evc_club_duplicate_order', (string) wp_json_encode($r->get_data()));
        $this->assertSame(1, self::open_club_orders($user));
        // A guest whose checkout creates no account: refused with the Greek reason.
        $this->store_api(null);
        remove_all_filters('woocommerce_add_to_cart_validation');
        update_option('woocommerce_enable_guest_checkout', 'yes');
        update_option('woocommerce_enable_signup_and_login_from_checkout', 'no');
        update_option('woocommerce_registration_generate_username', 'yes');
        update_option('woocommerce_registration_generate_password', 'no');
        add_filter('woocommerce_checkout_registration_required', '__return_false', 999);
        $this->add_item(EVC_TEST_CLUB_PRODUCT);
        $guest = 'guest.' . wp_generate_password(6, false, false) . '@example.org';
        $r = $this->place_order($guest);
        $this->assertSame(400, $r->get_status(), wp_json_encode($r->get_data()));
        $this->assertSame('evc_club_account_required', $r->get_data()['code']);
        $this->assertSame(EVC_Onboarding_Checkout_Guard::ACCOUNT, $r->get_data()['message']);
        // No order was placed: only the Store API's own draft is left, exactly in "checkout-draft".
        $this->assertSame(array(), wc_get_orders(array('billing_email' => $guest, 'status' => array('pending', 'on-hold', 'processing', 'completed', 'cancelled', 'failed', 'refunded'), 'limit' => -1, 'return' => 'ids')));
        $draft = wc_get_order((int) WC()->session->get('store_api_draft_order', 0));
        $this->assertInstanceOf(WC_Order::class, $draft);
        $this->assertSame('checkout-draft', $draft->get_status());
        $this->assertSame(0, $draft->get_customer_id());
        // The same guest creating the account at this checkout: accepted, and the order belongs to that account.
        update_option('woocommerce_enable_signup_and_login_from_checkout', 'yes');
        $r = $this->place_order($guest, 'bacs', array('create_account' => true, 'customer_password' => 'Ena-kafe-tin-imera-7'));
        $this->assertSame(200, $r->get_status(), wp_json_encode($r->get_data()));
        $account = get_user_by('email', $guest);
        $this->assertInstanceOf(WP_User::class, $account);
        $this->assertSame((int) $account->ID, wc_get_order((int) $r->get_data()['order_id'])->get_customer_id());
    }

    /** Round 4 (T1): a fresh REST server (new route objects, as in a new request) places the order with no GET first. */
    public function test_a_fresh_server_places_the_order_without_a_preceding_get(): void {
        $user = $this->customer();
        $this->store_api($user);
        $this->add_item(EVC_TEST_CLUB_PRODUCT);
        $GLOBALS['wp_rest_server'] = null;
        rest_get_server();
        $r = $this->store('POST', '/checkout', self::checkout_body(get_userdata($user)->user_email));
        $this->assertSame(200, $r->get_status(), wp_json_encode($r->get_data()));
        $this->assertSame('on-hold', $r->get_data()['status']);
        $this->assertSame(1, self::open_club_orders($user));
    }

    /**
     * Round 4 (G1): paying an EXISTING order through the Store API (POST /wc/store/v1/checkout/{id}) reads that order's
     * items, not the session cart: a Club order is paid by bank transfer only, other orders keep every gateway.
     */
    public function test_paying_an_existing_club_order_through_the_store_api_is_bank_transfer_only(): void {
        $user = $this->customer();
        $this->store_api($user);
        $gateways = WC()->payment_gateways()->payment_gateways();
        $was = array($gateways['bacs']->enabled, $gateways['cheque']->enabled);
        $gateways['bacs']->enabled = 'yes';
        $gateways['cheque']->enabled = 'yes';
        try {
            $club = $this->order($user, 'pending');
            $coffee = $this->order($user, 'pending', $this->coffee());
            $this->assertSame(array(), $this->store_cart(), 'the session cart is empty');
            $pay = function (WC_Order $order, string $method): WP_REST_Response {
                return $this->store('POST', '/checkout/' . $order->get_id(), self::checkout_body('club.billing@example.org', $method));
            };
            $r = $pay($club, 'cheque');
            $this->assertSame(400, $r->get_status(), wp_json_encode($r->get_data()));
            $this->assertSame('woocommerce_rest_checkout_payment_method_disabled', $r->get_data()['code']);
            $this->assertSame('pending', wc_get_order($club->get_id())->get_status());
            $r = $pay($coffee, 'cheque');
            $this->assertSame(200, $r->get_status(), wp_json_encode($r->get_data()));
            $this->assertSame('on-hold', wc_get_order($coffee->get_id())->get_status(), 'another order keeps its gateways');
            $r = $pay($club, 'bacs');
            $this->assertSame(200, $r->get_status(), wp_json_encode($r->get_data()));
            $this->assertSame('on-hold', wc_get_order($club->get_id())->get_status());
            $this->add_item($this->coffee()->get_id());
            $this->assertContains('cheque', $this->store('GET', '/cart')->get_data()['payment_methods'], 'the order context ended with its request');
        } finally {
            $gateways['bacs']->enabled = $was[0];
            $gateways['cheque']->enabled = $was[1];
        }
    }

    public function test_the_block_checkout_offers_only_bank_transfer_for_the_club(): void {
        $user = $this->customer();
        $this->store_api($user);
        $gateways = WC()->payment_gateways()->payment_gateways();
        $was = array($gateways['bacs']->enabled, $gateways['cheque']->enabled);
        $gateways['bacs']->enabled = 'yes';
        $gateways['cheque']->enabled = 'yes';
        try {
            $this->add_item($this->coffee()->get_id());
            $methods = $this->store('GET', '/cart')->get_data()['payment_methods'];
            $this->assertContains('bacs', $methods);
            $this->assertContains('cheque', $methods, 'other products keep every gateway');
            WC()->cart->empty_cart();
            $this->add_item(EVC_TEST_CLUB_PRODUCT);
            $this->assertSame(array('bacs'), $this->store('GET', '/cart')->get_data()['payment_methods'], 'D15 in the Block Checkout too');
            $email = get_userdata($user)->user_email;
            $r = $this->place_order($email, 'cheque');
            $this->assertSame(400, $r->get_status(), wp_json_encode($r->get_data()));
            $this->assertSame('woocommerce_rest_checkout_payment_method_disabled', $r->get_data()['code']);
            $this->assertSame(0, self::open_club_orders($user));
            $gateways['bacs']->enabled = 'no';
            $this->assertSame(array(), $this->store('GET', '/cart')->get_data()['payment_methods'], 'fail closed');
            $r = $this->place_order($email, 'bacs');
            $this->assertSame(400, $r->get_status(), wp_json_encode($r->get_data()));
            $this->assertSame(0, self::open_club_orders($user));
        } finally {
            $gateways['bacs']->enabled = $was[0];
            $gateways['cheque']->enabled = $was[1];
        }
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
