<?php

/**
 * STAGE-03D (owner decision 2026-10-26, Option A): «Ολοκληρωμένη» (Completed)
 * in WooCommerce by shop staff = the Club payment confirmation; cancellation /
 * refund freezes the card. REAL WooCommerce 11.2.1 / PMPro 3.8.9 / PMPro
 * WooCommerce 1.11 (HPOS or legacy storage, per EVC_TEST_WC_ORDER_STORAGE) and
 * the REAL SQL provenance store on the disposable Club database.
 *
 * The suite runs with EVC_CLUB_WC_COMPLETION_ENABLED undefined (OFF), so
 * nothing is hooked at load; each test builds the confirmer through the
 * bootstrap's own factory (EVC_Wc_Completion_Plugin::build) with its own clock
 * and registers it for that test only. Nothing here is a real payment.
 */
final class WcCompletionTest extends EVC_WP_Test_Case {
    const ME = '/evangelou-club-customer/v1/me';

    /** @var EVC_Commerce_Source_Config */
    private $config;
    /** @var array<string,mixed>|null the parts of the last wiring */
    private $parts;
    /** @var wpdb[] */
    private $connections = array();
    /** @var EVC_Member_Lock[] */
    private $held = array();

    public function set_up() {
        parent::set_up();
        evc_test_commerce_configure();
        $this->config = EVC_Commerce_Source_Config::from_constants();
    }

    public function tear_down() {
        if ($this->parts !== null) {
            $this->parts['confirmer']->unregister();
            $this->parts['guard']->disarm();
        }
        foreach ($this->held as $lock) {
            $lock->release();
        }
        foreach ($this->connections as $db) {
            $db->close();
        }
        remove_all_filters('query');
        unset($_REQUEST['_wpnonce'], $_REQUEST['action'], $_REQUEST['order_id'], $_REQUEST['id'], $_REQUEST['post']);
        remove_all_filters('wp_doing_ajax');
        remove_all_filters('wp_doing_cron');
        unset($GLOBALS['current_screen']);
        $GLOBALS['wpdb']->suppress_errors(false);
        wp_set_current_user(0);
        parent::tear_down();
    }

    private static function clock(DateTimeImmutable $at): callable {
        return function () use ($at) {
            return $at;
        };
    }

    private static function utc(string $s): DateTimeImmutable {
        return new DateTimeImmutable($s, new DateTimeZone('UTC'));
    }

    private static function whole_second(DateTimeImmutable $t): DateTimeImmutable {
        return self::utc($t->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
    }

    /** A real-time instant pinned to :07 seconds (never on the add-on's minute-rounded :00). */
    private static function at7(string $relative): DateTimeImmutable {
        $t = new DateTimeImmutable($relative, new DateTimeZone('UTC'));
        return $t->setTime((int) $t->format('H'), (int) $t->format('i'), 7);
    }

    private static function local(DateTimeImmutable $i): string {
        return $i->setTimezone(new DateTimeZone('Europe/Athens'))->format('Y-m-d H:i:s');
    }

    /** Builds the confirmer through the bootstrap factory and registers its hooks for this test. */
    private function wire(DateTimeImmutable $now, ?callable $open_store = null, int $lock_wait = 1): EVC_Wc_Completion_Confirmer {
        if ($this->parts !== null) {
            $this->parts['confirmer']->unregister();
        }
        $this->parts = EVC_Wc_Completion_Plugin::build($this->config, EVC_Db_Config::from_constants(), self::clock($now), $open_store, $lock_wait);
        $this->assertNotNull($this->parts);
        $this->parts['confirmer']->register();
        return $this->parts['confirmer'];
    }

    private function recover_at(DateTimeImmutable $now, ?callable $open_store = null): array {
        $this->wire($now, $open_store);
        return $this->parts['recovery']->run();
    }

    private function club(): EVC_Sql_Provenance_Store {
        $db = EVC_Db_Config::from_constants();
        return new EVC_Sql_Provenance_Store(EVC_Club_Db::connect($db), function () use ($db) {
            return EVC_Club_Db::connect($db);
        }, 'live');
    }

    private static function club_down(): callable {
        return function () {
            throw new RuntimeException('simulated: Club database unreachable');
        };
    }

    private function customer(): int {
        return (int) self::factory()->user->create(array('role' => 'customer', 'user_pass' => self::PASSWORD, 'user_email' => 'club.' . wp_generate_password(6, false, false) . '@example.org', 'display_name' => 'Synthetic Customer'));
    }

    /** A shop user with WooCommerce's own order capability (no Club capability at all). */
    private function staff(): int {
        $id = (int) self::factory()->user->create(array('role' => 'shop_manager', 'display_name' => 'Synthetic Shop Staff'));
        $this->assertTrue(user_can($id, 'edit_shop_orders'));
        $this->assertFalse(user_can($id, EVC_Bacs_Payment_Confirmation::CAPABILITY), 'this path needs no Club capability');
        return $id;
    }

    private function order(int $user_id, ?DateTimeImmutable $created = null, string $total = ''): WC_Order {
        $order = wc_create_order(array('customer_id' => $user_id));
        $order->add_product(wc_get_product(EVC_TEST_CLUB_PRODUCT), 1);
        $order->set_payment_method('bacs');
        $order->set_currency('EUR');
        $order->set_billing_email('club.billing@example.org');
        $order->set_date_created(($created ?? new DateTimeImmutable('-2 hours'))->getTimestamp());
        $order->calculate_totals();
        if ($total !== '') {
            $order->set_total($total);
        }
        $order->save();
        $order->update_status('on-hold');
        return wc_get_order($order->get_id());
    }

    /** The shop user sets the order to «Ολοκληρωμένη» on the WooCommerce order edit screen (a manual status change). */
    private function complete(int $order_id, int $actor, bool $manual = true): ?EVC_Wc_Completion_Result {
        return $this->complete_via('admin', $order_id, $actor, $manual);
    }

    /**
     * The same status change from another channel: admin (the order edit screen: its nonce verified),
     * admin_ajax (the order-list button: action woocommerce_mark_order_status + its nonce), ajax
     * (storefront), cron, other (front end). $entry false: the same channel WITHOUT the WooCommerce
     * entry point (no verified nonce; for admin_ajax another action). REST: complete_by_rest().
     */
    private function complete_via(string $channel, int $order_id, int $actor, bool $manual = true, bool $entry = true): ?EVC_Wc_Completion_Result {
        wp_set_current_user($actor);
        $this->screen(in_array($channel, array('admin', 'admin_ajax'), true));
        if (in_array($channel, array('admin_ajax', 'ajax'), true)) {
            add_filter('wp_doing_ajax', '__return_true');
        }
        if ($channel === 'cron') {
            add_filter('wp_doing_cron', '__return_true');
        }
        try {
            if ($channel === 'admin' && $entry) {
                $this->verify_referer(self::edit_screen_nonce($order_id));
            }
            if ($channel === 'admin_ajax') {
                // Without the entry point: another admin-ajax action (even carrying the list button's nonce).
                $_REQUEST['action'] = $entry ? 'woocommerce_mark_order_status' : 'some_plugin_action';
                $_REQUEST['order_id'] = (string) $order_id;
                $this->verify_referer('woocommerce-mark-order-status');
            }
            wc_get_order($order_id)->update_status('completed', '', $manual);
        } finally {
            $this->end_request();
        }
        return $this->parts['confirmer']->last_result();
    }

    /**
     * Runs $work as a user in the WooCommerce admin (is_admin() true) and restores the front end.
     * $bulk: the order ids of a bulk action of the order list (its nonce verified, as WordPress does).
     *
     * @param int[] $bulk
     */
    private function in_admin(int $actor, callable $work, array $bulk = array()) {
        wp_set_current_user($actor);
        $this->screen(true);
        try {
            if ($bulk !== array()) {
                $hpos = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
                $_REQUEST[$hpos ? 'id' : 'post'] = array_map('strval', $bulk);
                $this->verify_referer($hpos ? 'bulk-orders' : 'bulk-posts');
            }
            return $work();
        } finally {
            $this->end_request();
        }
    }

    /** The order edit screen's nonce action: HPOS (wc-orders) or posts storage (post.php). */
    private static function edit_screen_nonce(int $order_id): string {
        return (\Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? 'update-order_' : 'update-post_') . $order_id;
    }

    /** check_admin_referer() exactly as WooCommerce / WordPress call it before changing an order (fires `check_admin_referer`). */
    private function verify_referer(string $action): void {
        $_REQUEST['_wpnonce'] = wp_create_nonce($action);
        $this->assertSame(1, check_admin_referer($action));
    }

    /** The request is over: request parameters, filters, screen, user and the confirmer's per-request state. */
    private function end_request(): void {
        unset($_REQUEST['_wpnonce'], $_REQUEST['action'], $_REQUEST['order_id'], $_REQUEST['id'], $_REQUEST['post']);
        remove_all_filters('wp_doing_ajax');
        remove_all_filters('wp_doing_cron');
        $this->screen(false);
        wp_set_current_user(0);
        if ($this->parts !== null) {
            $this->parts['confirmer']->forget();
        }
    }

    /** is_admin() without firing `current_screen` (admin-only handlers are not under test). */
    private function screen(bool $admin): void {
        if ($admin) {
            if (!class_exists('WP_Screen')) {
                require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
            }
            $GLOBALS['current_screen'] = WP_Screen::get('dashboard');
        } else {
            unset($GLOBALS['current_screen']);
        }
    }

    /** A REST completion (PUT /wc/v3/orders/{id}) by an authenticated user: the application-password / API-key / mobile-app path. */
    private function complete_by_rest(int $order_id, int $actor): array {
        wp_set_current_user($actor);
        $request = new WP_REST_Request('PUT', '/wc/v3/orders/' . $order_id);
        $request->set_param('status', 'completed');
        $response = rest_do_request($request);
        wp_set_current_user(0);
        return array($response, $this->parts['confirmer']->last_result());
    }

    /** @return string[] the Club's private notes on the order (the shop manager sees them on the order screen) */
    private function notes(int $order_id): array {
        $notes = array_map(function ($n) {
            return (string) $n->content;
        }, wc_get_order_notes(array('order_id' => $order_id, 'type' => 'internal')));
        return array_values(array_filter($notes, function ($n) {
            return strpos($n, 'Club:') === 0;
        }));
    }

    /** An order with the Club product, changed by $mutate before it is saved and put on hold. */
    private function order_with(int $user_id, callable $mutate): WC_Order {
        $order = wc_create_order(array('customer_id' => $user_id));
        $order->add_product(wc_get_product(EVC_TEST_CLUB_PRODUCT), 1);
        $order->set_payment_method('bacs');
        $order->set_currency('EUR');
        $order->set_billing_email('club.billing@example.org');
        $order->set_date_created((new DateTimeImmutable('-2 hours'))->getTimestamp());
        $order->calculate_totals();
        $mutate($order);
        $order->save();
        $order->update_status('on-hold');
        return wc_get_order($order->get_id());
    }

    /** @return array{0:WC_Order,1:EVC_Wc_Completion_Result,2:DateTimeImmutable} */
    private function purchase(int $customer, int $actor, DateTimeImmutable $at): array {
        $at = self::whole_second($at);
        $this->wire($at);
        $order = $this->order($customer, $at->modify('-1 hour'));
        $r = $this->complete($order->get_id(), $actor);
        $this->assertNotNull($r);
        return array(wc_get_order($order->get_id()), $r, $at);
    }

    /** @return array<int,array{id:string,status:string,startdate:string,enddate:string}> */
    private function rows(int $user_id): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare("SELECT id, status, startdate, enddate FROM {$wpdb->pmpro_memberships_users} WHERE user_id = %d AND membership_id = %d ORDER BY id ASC", $user_id, $this->config->level_id), ARRAY_A);
    }

    private function active_rows(int $user_id): array {
        return array_values(array_filter($this->rows($user_id), function ($r) {
            return $r['status'] === 'active';
        }));
    }

    private function row_ref(array $row): string {
        return $this->config->ref('pmpro_membership_row', (string) $row['id']);
    }

    private function intent(int $order_id): EVC_Bacs_Intent_Read {
        return $this->parts['intents']->read($order_id);
    }

    private function marker(int $order_id): EVC_Wc_Completion_Marker_Read {
        return $this->parts['markers']->read($order_id);
    }

    private function followup(int $order_id): ?string {
        return $this->parts['markers']->followup($order_id);
    }

    private function periods(int $user_id): array {
        $club = $this->club();
        return array_map(function (EVC_Verified_Movement $m) use ($club) {
            return $club->period_for_movement($m->movement_id());
        }, $club->member_movements($user_id));
    }

    private function me(int $user_id): array {
        $this->login_as($user_id);
        $r = $this->rest('GET', self::ME, null, $this->nonce());
        $this->assertSame(200, $r->get_status(), wp_json_encode($r->get_data()));
        wp_set_current_user(0);
        return $r->get_data()['membership'];
    }

    private function entitlement(int $user_id, DateTimeImmutable $at): EVC_Entitlement {
        return EVC_Customer_Plugin::membership_adapter()->entitlement_for($user_id, $at);
    }

    private function second_connection(): wpdb {
        $db = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
        $db->set_prefix($GLOBALS['wpdb']->prefix);
        $this->connections[] = $db;
        return $db;
    }

    /** Collects INSERT / UPDATE statements on PMPro's membership rows. */
    private function watch_rows(array &$writes): void {
        add_filter('query', function ($sql) use (&$writes) {
            if (preg_match('/^\s*(INSERT|UPDATE)\b.*pmpro_memberships_users/is', $sql, $m)) {
                $writes[] = strtoupper($m[1]);
            }
            return $sql;
        });
    }

    private function audit_events(string $type, string $order_ref): array {
        $club = EVC_Club_Db::connect(EVC_Db_Config::from_constants());
        return $club->fetch_all('SELECT outcome, staff_wp_user_id, details_json FROM evc_audit_events WHERE event_type = ? AND details_json LIKE ? ORDER BY id', array($type, '%' . $order_ref . '%'));
    }

    private function assertResult(?EVC_Wc_Completion_Result $r, string $outcome, ?string $reason = null): void {
        $this->assertNotNull($r);
        $this->assertSame($outcome, $r->outcome, 'reason: ' . (string) $r->reason . ' steps: ' . implode(',', $r->steps));
        if ($reason !== null) {
            $this->assertSame($reason, $r->reason, 'steps: ' . implode(',', $r->steps));
        }
    }

    private function assertGuardInert(): void {
        $g = $this->parts['guard'];
        $this->assertFalse($g->is_armed());
        $this->assertFalse(has_filter('pmprowoo_checkout_level', array($g, 'checkout_level')));
        $this->assertFalse(has_filter('pmpro_deactivate_old_levels', array($g, 'deactivate_old_levels')));
    }

    /** The add-on's own row: minute-rounded end, never PMPro's endless placeholder. */
    private function assertAddOnRow(array $row): void {
        $this->assertSame('active', $row['status']);
        $this->assertMatchesRegularExpression('/:00$/', $row['enddate'], 'the add-on\'s own minute-rounded end');
        $this->assertNotSame('0000-00-00 00:00:00', $row['enddate']);
    }

    public function test_with_the_constant_off_nothing_is_hooked_scheduled_or_written(): void {
        global $wp_filter;
        $this->setExpectedDeprecated('pmprommpu_addMembershipLevel');
        $this->assertFalse(defined(EVC_Wc_Completion_Feature::CONSTANT), 'the commerce suite runs with the constant undefined');
        $this->assertFalse(EVC_Wc_Completion_Feature::enabled());
        foreach (array(false, 1, '1', 'true', 'yes', array(true), null) as $value) {
            $this->assertFalse(EVC_Wc_Completion_Feature::is_enabled_value($value), var_export($value, true));
        }
        $this->assertTrue(EVC_Wc_Completion_Feature::is_enabled_value(true));
        EVC_Wc_Completion_Plugin::boot(); // as plugins_loaded did: OFF returns before anything
        $ours = array();
        foreach ($wp_filter as $hook => $callbacks) {
            foreach ($callbacks->callbacks as $priority => $list) {
                foreach ($list as $cb) {
                    $f = $cb['function'];
                    $owner = is_array($f) ? (is_object($f[0]) ? get_class($f[0]) : (string) $f[0]) : (is_object($f) ? get_class($f) : (string) $f);
                    if (strpos($owner, 'EVC_Wc_Completion') === 0 || strpos($owner, 'EVC_Bacs_Issuance_Guard') === 0) {
                        if ($owner === 'EVC_Wc_Completion_Plugin' && is_array($f) && in_array($f[1], array('boot', 'deactivate'), true)) {
                            continue; // the inert boot gate and the cleanup hook, like every other stage
                        }
                        $ours[] = $hook . '@' . $priority . ':' . $owner;
                    }
                }
            }
        }
        $this->assertSame(array(), $ours, 'nothing of STAGE-03D is hooked at load');
        $this->assertFalse(wp_next_scheduled(EVC_Wc_Completion_Plugin::CRON_HOOK));
        $this->assertArrayNotHasKey(EVC_Wc_Completion_Plugin::CRON_SCHEDULE, wp_get_schedules());

        // A staff completion with the constant OFF: the add-on as shipped, nothing of the Club written.
        $customer = $this->customer();
        $order = $this->order($customer);
        wp_set_current_user($this->staff());
        $order->update_status('completed');
        wp_set_current_user(0);
        $this->assertCount(1, $this->rows($customer));
        $this->assertAddOnRow($this->rows($customer)[0]);
        $markers = new EVC_Wc_Completion_Markers($this->config->completion_marker_codec(), 'live');
        $this->assertSame(EVC_Wc_Completion_Marker_Read::MISSING, $markers->read($order->get_id())->outcome);
        $this->assertNull($markers->followup($order->get_id()));
        $this->assertSame(EVC_Bacs_Intent_Read::MISSING, (new EVC_Wc_Bacs_Intent_Store($this->config->intent_codec(), 'live'))->read($order->get_id())->outcome);
        $this->assertSame(array(), $this->club()->member_movements($customer));
        $this->assertSame('unverified', $this->me($customer)['state']);
    }

    public function test_the_bootstrap_builds_and_hooks_only_when_configured(): void {
        $now = self::clock(new DateTimeImmutable('now'));
        $this->assertNull(EVC_Wc_Completion_Plugin::build(null, EVC_Db_Config::from_constants(), $now));
        $this->assertNull(EVC_Wc_Completion_Plugin::build($this->config, null, $now));
        $c = $this->wire(new DateTimeImmutable('now'));
        $this->assertSame(EVC_Wc_Completion_Confirmer::COMPLETION_PRIORITY, has_action('woocommerce_order_status_completed', array($c, 'on_completed')));
        $this->assertLessThan(10, EVC_Wc_Completion_Confirmer::COMPLETION_PRIORITY, 'before the add-on');
        $this->assertSame(10, has_action('woocommerce_order_status_completed', 'pmprowoo_add_membership_from_order'), 'the add-on issues at priority 10');
        $this->assertSame(EVC_Wc_Completion_Confirmer::AFTER_ADDON_PRIORITY, has_action('woocommerce_order_status_completed', array($c, 'after_add_on')));
        $this->assertGreaterThan(10, EVC_Wc_Completion_Confirmer::AFTER_ADDON_PRIORITY);
        $this->assertFalse(has_filter('pmpro_change_level', array($c, 'suppress_add_on')), 'the integer-level trick is gone');
        $this->assertNotFalse(has_filter('rest_request_before_callbacks', array($c, 'rest_begin')));
        $this->assertNotFalse(has_filter('rest_request_after_callbacks', array($c, 'rest_end')));
        foreach (array('cancelled', 'refunded', 'failed') as $status) {
            $this->assertLessThan(10, has_action('woocommerce_order_status_' . $status, array($c, 'on_ended')), 'before the add-on\'s own cancellation');
        }
        $this->assertNotFalse(has_action('woocommerce_order_refunded', array($c, 'on_refund')));
        $c->unregister();
        $this->assertFalse(has_action('woocommerce_order_status_completed', array($c, 'on_completed')));
        $this->assertFalse(has_filter('rest_request_before_callbacks', array($c, 'rest_begin')));
        $this->assertSame(array('interval' => 900, 'display' => 'Evangelou Club: every 15 minutes'), EVC_Wc_Completion_Plugin::schedules(array())[EVC_Wc_Completion_Plugin::CRON_SCHEDULE]);
        $this->parts = null;
    }

    public function test_building_never_throws_into_the_site(): void {
        $log = tempnam(sys_get_temp_dir(), 'evc-log-');
        $old = ini_set('error_log', $log);
        $wpdb = $GLOBALS['wpdb'];
        $GLOBALS['wpdb'] = null; // the wiring then fails with a TypeError
        try {
            $this->assertNull(EVC_Wc_Completion_Plugin::build($this->config, EVC_Db_Config::from_constants(), self::clock(new DateTimeImmutable('now'))));
        } finally {
            $GLOBALS['wpdb'] = $wpdb;
            ini_set('error_log', (string) $old);
        }
        $this->assertStringContainsString('could not be built (TypeError)', (string) file_get_contents($log));
        unlink($log);
    }

    public function test_initial_purchase_completed_by_shop_staff_is_recorded_with_the_exact_athens_period(): void {
        $this->setExpectedDeprecated('pmprommpu_addMembershipLevel');
        $customer = $this->customer();
        $staff = $this->staff();
        $writes = array();
        $this->watch_rows($writes);
        list($order, $r, $at) = $this->purchase($customer, $staff, self::at7('-30 seconds'));
        remove_all_filters('query');

        $this->assertResult($r, EVC_Wc_Completion_Result::RECORDED);
        $this->assertNull($r->reason);
        $this->assertSame(array('planned', 'issue', 'issued', 'record'), $r->steps);
        $this->assertSame(EVC_Wc_Completion_Marker::ADDON_SUPPRESSED, $r->addon);
        $this->assertTrue($r->audited);
        $this->assertSame(1, $this->parts['confirmer']->withheld_runs(), 'the add-on\'s own run was withheld');
        $this->assertSame('INSERT', $writes[0] ?? null);
        $this->assertSame(1, count(array_keys($writes, 'INSERT')), 'exactly one membership row was inserted: the Club\'s own');
        $this->assertGuardInert();

        $end = EVC_Membership_Calendar::add_calendar_month($at);
        $rows = $this->rows($customer);
        $this->assertCount(1, $rows);
        $this->assertSame(self::local($at), $rows[0]['startdate'], 'PMPro shows the confirmation instant');
        $this->assertSame(self::local($end), $rows[0]['enddate'], 'PMPro shows the exact Athens calendar month');
        $read = $this->intent($order->get_id());
        $this->assertSame(EVC_Bacs_Intent::STATE_RECORDED, $read->intent->state());
        $this->assertSame($staff, $read->intent->actor_id());
        $this->assertSame($this->config->ref('wc_order_completion', (string) $order->get_id()), $read->intent->movement_id(), 'movement id = keyed HMAC of the ORDER');
        $movement = $this->club()->movement($read->intent->movement_id());
        $this->assertSame(EVC_Verified_Movement::AUTHORITY_MANUAL_ADMIN, $movement->authority());
        $this->assertSame(EVC_Payment_Evidence::KIND_WC_ORDER, $movement->evidence_kind());
        $this->assertSame($this->row_ref($rows[0]), $movement->pmpro_row_ref());
        $this->assertSame(EVC_Wc_Completion_Marker_Read::VALID, $this->marker($order->get_id())->outcome, 'the signed claim stays (immutable); only the open follow-up is closed');
        $this->assertNull($this->followup($order->get_id()));

        $me = $this->me($customer);
        $this->assertSame('active', $me['state']);
        $this->assertSame($end->format(DATE_ATOM), $me['paid_until_utc']);

        $events = $this->audit_events(EVC_Wc_Completion_Confirmer::EVENT, $this->config->ref('wc_order', (string) $order->get_id()));
        $this->assertCount(1, $events);
        $this->assertSame('recorded', $events[0]['outcome']);
        $this->assertSame($staff, (int) $events[0]['staff_wp_user_id'], 'which staff user completed it');
        $user = get_userdata($customer);
        foreach (array($user->user_email, $user->display_name, 'club.billing@example.org', 'Synthetic Shop Staff', EVC_CLUB_EVIDENCE_KEY) as $secret) {
            $this->assertStringNotContainsString($secret, $events[0]['details_json']);
        }
    }

    public function test_early_renewal_keeps_the_paid_row_active_until_the_new_row_exists(): void {
        $this->setExpectedDeprecated('pmprommpu_addMembershipLevel');
        $customer = $this->customer();
        $staff = $this->staff();
        list(, $first, $first_at) = $this->purchase($customer, $staff, self::at7('-10 days'));
        $this->assertResult($first, EVC_Wc_Completion_Result::RECORDED);
        $first_end = EVC_Membership_Calendar::add_calendar_month($first_at);
        $old = $this->active_rows($customer)[0];

        $writes = array();
        $this->watch_rows($writes);
        list($order, $r) = $this->purchase($customer, $staff, self::at7('-30 seconds'));
        remove_all_filters('query');

        $this->assertResult($r, EVC_Wc_Completion_Result::RECORDED);
        $this->assertSame(array('planned', 'issue', 'issued', 'record'), $r->steps);
        $this->assertSame('INSERT', $writes[0] ?? null, 'the paid row was not touched before the new row existed');
        $this->assertSame(1, count(array_keys($writes, 'INSERT')), 'one new row: the add-on\'s own run was withheld');
        $this->assertContains('UPDATE', array_slice($writes, 1), 'PMPro\'s own duplicate removal retired the old row afterwards');
        $new_end = EVC_Membership_Calendar::add_calendar_month($first_end);
        $rows = $this->rows($customer);
        $this->assertCount(2, $rows);
        $this->assertSame($old['id'], $rows[0]['id']);
        $this->assertSame('changed', $rows[0]['status']);
        $this->assertSame(self::local($first_at), $rows[1]['startdate'], 'the paid run continues');
        $this->assertSame(self::local($new_end), $rows[1]['enddate'], 'previous paid end + one approved calendar month');
        $this->assertSame(EVC_Funded_Period::KIND_EARLY, $this->intent($order->get_id())->intent->period_kind());
        $me = $this->me($customer);
        $this->assertSame('active', $me['state']);
        $this->assertSame($new_end->format(DATE_ATOM), $me['paid_until_utc']);
        $periods = $this->periods($customer);
        $this->assertCount(2, $periods);
        $this->assertEquals($periods[0]->end_utc(), $periods[1]->start_utc(), 'no paid time lost or added');
    }

    public function test_early_renewal_of_a_period_ending_on_31_january_ends_on_28_february(): void {
        $this->setExpectedDeprecated('pmprommpu_addMembershipLevel');
        $customer = $this->customer();
        $staff = $this->staff();
        list(, $first) = $this->purchase($customer, $staff, self::utc('2026-12-31 08:00:07')); // 10:00:07 EET
        $this->assertResult($first, EVC_Wc_Completion_Result::RECORDED);
        $this->assertSame('2027-01-31 10:00:07', $this->active_rows($customer)[0]['enddate']);
        list(, $r) = $this->purchase($customer, $staff, self::utc('2027-01-20 09:00:07'));
        $this->assertResult($r, EVC_Wc_Completion_Result::RECORDED);
        $row = $this->active_rows($customer)[0];
        $this->assertSame('2026-12-31 10:00:07', $row['startdate']);
        $this->assertSame('2027-02-28 10:00:07', $row['enddate'], '31 January + one calendar month = 28 February (clamped)');
        $e = $this->entitlement($customer, self::utc('2027-02-10 12:00:00'));
        $this->assertTrue($e->is_eligible_at(self::utc('2027-02-10 12:00:00')));
        $this->assertSame('2027-02-28T08:00:07+00:00', $e->expires_at_utc()->format(DATE_ATOM));
    }

    public function test_late_renewal_after_pmpro_expired_the_row_starts_a_new_paid_run(): void {
        $this->setExpectedDeprecated('pmprommpu_addMembershipLevel');
        $customer = $this->customer();
        $staff = $this->staff();
        list(, $first) = $this->purchase($customer, $staff, self::at7('-40 days'));
        $this->assertResult($first, EVC_Wc_Completion_Result::RECORDED);
        pmpro_cancelMembershipLevel($this->config->level_id, $customer, 'expired'); // PMPro's own expiry
        $this->assertSame(array(), $this->active_rows($customer));

        list($order, $r, $at) = $this->purchase($customer, $staff, self::at7('-30 seconds'));
        $this->assertResult($r, EVC_Wc_Completion_Result::RECORDED);
        $this->assertSame(EVC_Funded_Period::KIND_LATE, $this->intent($order->get_id())->intent->period_kind());
        $row = $this->active_rows($customer)[0];
        $this->assertSame(self::local($at), $row['startdate'], 'a late renewal starts at its confirmation');
        $this->assertSame(self::local(EVC_Membership_Calendar::add_calendar_month($at)), $row['enddate']);
        $this->assertSame('active', $this->me($customer)['state']);
    }

    public function test_completing_an_already_recorded_order_again_never_adds_a_month(): void {
        $this->setExpectedDeprecated('pmprommpu_addMembershipLevel');
        $customer = $this->customer();
        $staff = $this->staff();
        list($order, $r) = $this->purchase($customer, $staff, self::at7('-30 seconds'));
        $this->assertResult($r, EVC_Wc_Completion_Result::RECORDED);
        $id = $order->get_id();
        $end = $this->active_rows($customer)[0]['enddate'];

        $writes = array();
        $this->watch_rows($writes);
        foreach (array('processing', 'on-hold', 'pending') as $back) {
            wp_set_current_user($staff);
            wc_get_order($id)->update_status($back);
            $again = $this->complete($id, $this->staff()); // also another staff user
            $this->assertResult($again, EVC_Wc_Completion_Result::ALREADY_RECORDED);
            $this->assertSame(EVC_Wc_Completion_Marker::ADDON_SUPPRESSED, $again->addon);
        }
        // Double submit: two stale order objects loaded while the order awaited payment.
        $second = $this->order($this->customer());
        $a = wc_get_order($second->get_id());
        $b = wc_get_order($second->get_id());
        $this->in_admin($staff, function () use ($a) {
            $a->update_status('completed', '', true);
        }, array($second->get_id()));
        $first = $this->parts['confirmer']->last_result();
        $this->in_admin($staff, function () use ($b) {
            $b->update_status('completed', '', true);
        }, array($second->get_id()));
        $double = $this->parts['confirmer']->last_result();
        remove_all_filters('query');
        $this->assertResult($first, EVC_Wc_Completion_Result::RECORDED);
        $this->assertResult($double, EVC_Wc_Completion_Result::ALREADY_RECORDED);

        $this->assertSame(array('INSERT'), array_values(array_unique(array_filter($writes, function ($w) {
            return $w === 'INSERT';
        }))), 'only the second customer\'s first row was inserted');
        $this->assertSame(1, count(array_keys($writes, 'INSERT')));
        $this->assertCount(1, $this->rows($customer), 'no second month after toggles');
        $this->assertSame($end, $this->active_rows($customer)[0]['enddate']);
        $this->assertCount(1, $this->periods($customer), 'nothing recorded twice');
        $this->assertCount(1, $this->periods((int) $second->get_customer_id()));
        $this->assertSame('active', $this->me($customer)['state']);
    }

    public function test_bulk_completion_of_two_orders(): void {
        $this->setExpectedDeprecated('pmprommpu_addMembershipLevel');
        $staff = $this->staff();
        $at = self::whole_second(self::at7('-5 minutes'));
        $this->wire($at);
        // Two customers: both confirmed.
        $c1 = $this->customer();
        $c2 = $this->customer();
        $o1 = $this->order($c1);
        $o2 = $this->order($c2);
        $results = array();
        foreach (array($o1, $o2) as $o) {
            $this->in_admin($staff, function () use ($o) {
                wc_get_order($o->get_id())->update_status('completed', '', true); // the bulk action: manual
            }, array($o1->get_id(), $o2->get_id()));
            $results[] = $this->parts['confirmer']->last_result();
        }
        $this->assertResult($results[0], EVC_Wc_Completion_Result::RECORDED);
        $this->assertResult($results[1], EVC_Wc_Completion_Result::RECORDED);
        $this->assertCount(1, $this->rows($c1));
        $this->assertCount(1, $this->rows($c2));

        // One customer, two orders, the same second: the second is deferred, nothing added by the add-on.
        $c = $this->customer();
        $x = $this->order($c);
        $y = $this->order($c);
        foreach (array($x, $y) as $o) {
            $this->in_admin($staff, function () use ($o) {
                wc_get_order($o->get_id())->update_status('completed', '', true);
            }, array($x->get_id(), $y->get_id()));
            $results[] = $this->parts['confirmer']->last_result();
        }
        $this->assertResult($results[2], EVC_Wc_Completion_Result::RECORDED);
        $this->assertResult($results[3], EVC_Wc_Completion_Result::DEFERRED, 'plan_same_instant_payment');
        $this->assertSame(EVC_Wc_Completion_Marker::ADDON_SUPPRESSED, $results[3]->addon);
        $this->assertCount(1, $this->rows($c), 'the add-on did not add a month for the deferred order');
        $marker = $this->marker($y->get_id());
        $this->assertSame(EVC_Wc_Completion_Marker_Read::VALID, $marker->outcome, (string) $marker->reason);
        $this->assertSame($staff, $marker->marker->actor_id());
        $this->assertEquals($at, $marker->marker->completed_at_utc());
        $this->assertSame('open:0', $this->followup($y->get_id()));
        $first_end = EVC_Membership_Calendar::add_calendar_month($at);
        $this->assertSame($first_end->format(DATE_ATOM), $this->me($c)['paid_until_utc'], 'the first order is paid and visible');

        // The recovery step finishes it as an early renewal after the first.
        $done = $this->recover_at($at->modify('+1 minute'));
        $this->assertArrayHasKey($y->get_id(), $done);
        $this->assertResult($done[$y->get_id()], EVC_Wc_Completion_Result::RECORDED);
        $this->assertNull($this->followup($y->get_id()));
        $row = $this->active_rows($c)[0];
        $this->assertSame(self::local(EVC_Membership_Calendar::add_calendar_month($first_end)), $row['enddate']);
        $this->assertCount(2, $this->periods($c));
        $this->assertSame('active', $this->me($c)['state']);
        $this->assertSame(array(), $this->parts['recovery']->run(), 'nothing left open');
    }

    public function test_completion_by_nobody_the_customer_the_club_account_or_without_capability_is_not_a_confirmation(): void {
        $this->wire(self::whole_second(new DateTimeImmutable('-30 seconds')));
        $club_account = $this->create_staff_user();
        get_userdata($club_account)->add_cap('edit_shop_orders'); // TEST-ONLY: even WITH the capability
        $subscriber = (int) self::factory()->user->create(array('role' => 'subscriber'));
        foreach (array('no_user' => 0, 'customer' => -1, 'club_staff_account' => $club_account, 'no_capability' => $subscriber) as $reason => $actor) {
            $customer = $this->customer();
            if ($actor === -1) {
                $actor = $customer;
                get_userdata($customer)->add_cap('edit_shop_orders'); // TEST-ONLY: even WITH the capability
            }
            $order = $this->order($customer);
            $r = $this->complete($order->get_id(), $actor);
            $this->assertResult($r, EVC_Wc_Completion_Result::NOT_ATTESTED, $reason);
            $this->assertSame(array(), $this->rows($customer), $reason . ': no membership at all (the add-on is withheld too)');
            $this->assertSame(EVC_Bacs_Intent_Read::MISSING, $this->intent($order->get_id())->outcome);
            $this->assertSame(EVC_Wc_Completion_Marker_Read::MISSING, $this->marker($order->get_id())->outcome);
            $this->assertNull($this->followup($order->get_id()), 'nothing to recover: it was never a confirmation');
            $this->assertSame(array(), $this->club()->member_movements($customer));
            $this->assertSame('none', $this->me($customer)['state'], $reason);
            $events = $this->audit_events(EVC_Wc_Completion_Confirmer::EVENT, $this->config->ref('wc_order', (string) $order->get_id()));
            $this->assertCount(1, $events);
            $this->assertSame('not_attested', $events[0]['outcome']);
            $this->assertSame($actor > 0 ? $actor : null, $events[0]['staff_wp_user_id'] === null ? null : (int) $events[0]['staff_wp_user_id'], 'the attempting user is recorded');
            $this->assertStringContainsString('"channel":"admin"', $events[0]['details_json']);
            $this->assertStringContainsString('"reason":"' . $reason . '"', $events[0]['details_json']);
            $this->assertCount(1, $this->notes($order->get_id()), 'the shop sees on the order that it confirmed nothing');
        }
    }

    public function test_only_a_manual_completion_in_the_woocommerce_admin_is_a_confirmation(): void {
        $this->setExpectedDeprecated('pmprommpu_addMembershipLevel');
        $staff = $this->staff();
        $this->wire(self::whole_second(self::at7('-5 minutes')));
        $cases = array(
            // case => [channel, manual flag, expected reason, channel logged]
            'rest' => array('rest', true, 'channel_rest', 'rest'),
            'cron' => array('cron', true, 'channel_cron', 'cron'),
            'ajax' => array('ajax', true, 'channel_ajax', 'ajax'),
            'other' => array('other', true, 'channel_other', 'other'),
            'admin_not_manual' => array('admin', false, 'not_a_manual_status_change', 'admin'),
            // review M-C: the channel alone is not enough, the WooCommerce entry point must be identified.
            'admin_page_plugin_call' => array('admin', true, 'not_a_woocommerce_admin_action', 'admin', false),
            'admin_ajax_other_action' => array('admin_ajax', true, 'not_a_woocommerce_admin_action', 'admin_ajax', false),
        );
        foreach ($cases as $name => $case) {
            list($channel, $manual, $reason, $logged) = $case;
            $entry = $case[4] ?? true;
            $customer = $this->customer();
            $order = $this->order($customer);
            if ($channel === 'rest') {
                list($response, $r) = $this->complete_by_rest($order->get_id(), $staff);
                $this->assertSame(200, $response->get_status(), wp_json_encode($response->get_data()));
            } else {
                $r = $this->complete_via($channel, $order->get_id(), $staff, $manual, $entry);
            }
            $this->assertSame('completed', wc_get_order($order->get_id())->get_status(), $name);
            $this->assertResult($r, EVC_Wc_Completion_Result::NOT_ATTESTED, $reason);
            $this->assertSame(array(), $this->rows($customer), $name . ': not a confirmation and no membership from the add-on either');
            $this->assertSame('none', $this->me($customer)['state'], $name);
            $this->assertSame(array(), $this->club()->member_movements($customer));
            $events = $this->audit_events(EVC_Wc_Completion_Confirmer::EVENT, $this->config->ref('wc_order', (string) $order->get_id()));
            $this->assertCount(1, $events, $name);
            $this->assertSame($staff, (int) $events[0]['staff_wp_user_id'], 'the attempting user is audited');
            $this->assertStringContainsString('"channel":"' . $logged . '"', $events[0]['details_json'], $name);
        }
        // A verified nonce of ANOTHER order's edit screen / list button is not this order's entry point.
        $customer = $this->customer();
        $order = $this->order($customer);
        $other = $this->order($this->customer());
        $r = $this->in_admin($staff, function () use ($order, $other) {
            $this->verify_referer(self::edit_screen_nonce($other->get_id()));
            wc_get_order($order->get_id())->update_status('completed', '', true);
            return $this->parts['confirmer']->last_result();
        });
        $this->assertResult($r, EVC_Wc_Completion_Result::NOT_ATTESTED, 'not_a_woocommerce_admin_action');
        $this->assertSame(array(), $this->rows($customer));
        // The bulk action naming other orders only.
        $r = $this->in_admin($staff, function () use ($other) {
            wc_get_order($other->get_id())->update_status('completed', '', true);
            return $this->parts['confirmer']->last_result();
        }, array($order->get_id()));
        $this->assertResult($r, EVC_Wc_Completion_Result::NOT_ATTESTED, 'not_a_woocommerce_admin_action');

        // The entry points that ARE a confirmation: the order edit screen, the list button, the bulk action (HPOS or posts storage per run).
        foreach (array('admin', 'admin_ajax') as $channel) {
            $customer = $this->customer();
            $order = $this->order($customer);
            $r = $this->complete_via($channel, $order->get_id(), $staff);
            $this->assertResult($r, EVC_Wc_Completion_Result::RECORDED);
            $this->assertCount(1, $this->rows($customer), $channel);
            $events = $this->audit_events(EVC_Wc_Completion_Confirmer::EVENT, $this->config->ref('wc_order', (string) $order->get_id()));
            $this->assertStringContainsString('"channel":"' . $channel . '"', $events[0]['details_json']);
        }
    }

    /** What this path relies on, checked against the REAL WooCommerce: `manual` in the third hook argument. */
    public function test_woocommerce_marks_manual_status_changes_and_not_rest_ones(): void {
        $this->setExpectedDeprecated('pmprommpu_addMembershipLevel');
        $staff = $this->staff();
        $this->wire(self::whole_second(self::at7('-5 minutes')));
        $seen = array();
        $probe = function ($order_id, $order = null, $transition = null) use (&$seen) {
            $seen[] = is_array($transition) ? ($transition['manual'] ?? 'absent') : 'no_transition_argument';
        };
        add_action('woocommerce_order_status_completed', $probe, 1, 3);
        $a = $this->order($this->customer());
        $this->complete($a->get_id(), $staff, true);
        $b = $this->order($this->customer());
        $this->complete($b->get_id(), $staff, false);
        $c = $this->order($this->customer());
        $this->complete_by_rest($c->get_id(), $staff);
        remove_action('woocommerce_order_status_completed', $probe, 1);
        $this->assertSame(array(true, false, false), $seen, 'update_status(..., true) is manual; programmatic and REST changes are not');
    }

    /** The admin entry points of the pinned WooCommerce all pass $manual = true (read from its source, with diagnostics). */
    public function test_the_woocommerce_admin_entry_points_pass_manual_true(): void {
        $root = (string) getenv('EVC_TEST_COMMERCE_PLUGINS') . '/woocommerce';
        $checks = array(
            'mark_order_status (list button, admin-ajax)' => array('/function\s+mark_order_status\b.{0,2500}?update_status\(\s*\$\w+\s*,\s*[\'"]{2}\s*,\s*true\s*\)/s', 'mark_order_status'),
            'bulk action' => array('/Order status changed by bulk edit.{0,120}?true\s*\)/s', 'Order status changed by bulk edit'),
            'order edit screen (meta box)' => array('/class\s+WC_Meta_Box_Order_Data\b.*?set_status\([^;]{0,300}?,\s*[\'"]{2}\s*,\s*true\s*\)/s', 'class WC_Meta_Box_Order_Data'),
            // review M-C: the nonces the Club recognises each entry point by.
            'list button nonce (admin-ajax)' => array('/function\s+mark_order_status\b.{0,600}?check_admin_referer\(\s*\'woocommerce-mark-order-status\'\s*\)/s', 'function mark_order_status'),
            'HPOS edit screen nonce' => array('/function\s+get_order_edit_nonce_action\b.{0,200}?\'update-order_\'\s*\.\s*\$this->order->get_id\(\)/s', 'function get_order_edit_nonce_action'),
            'HPOS edit screen verifies it' => array('/function\s+handle_order_update\b.{0,600}?check_admin_referer\(\s*\$this->get_order_edit_nonce_action\(\)\s*\)/s', 'function handle_order_update'),
            'HPOS bulk nonce and ids' => array('/check_admin_referer\(\s*\'bulk-orders\'\s*\).{0,900}?\$_REQUEST\[\s*\'id\'\s*\]/s', "check_admin_referer( 'bulk-orders' )"),
        );
        $found = array_fill_keys(array_keys($checks), false);
        $diagnostics = array();
        foreach (array('/includes', '/src') as $sub) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . $sub, FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if (substr((string) $file, -4) !== '.php') {
                    continue;
                }
                $text = (string) file_get_contents((string) $file);
                foreach ($checks as $name => list($pattern, $anchor)) {
                    if (strpos($text, $anchor) === false) {
                        continue;
                    }
                    if (preg_match($pattern, $text)) {
                        $found[$name] = true;
                    } else {
                        preg_match_all('/^.*(?:update_status|set_status)\(.*$/m', $text, $m);
                        $diagnostics[$name][] = basename((string) $file) . ': ' . implode(' | ', array_map('trim', array_slice($m[0], 0, 6)));
                    }
                }
            }
        }
        $this->assertSame(array_fill_keys(array_keys($checks), true), $found, wp_json_encode($diagnostics));
        // WordPress core (posts storage): post.php verifies update-post_<id> before the order meta box saves,
        // edit.php verifies bulk-posts and reads post[] before handle_bulk_actions-edit-shop_order.
        $post = (string) file_get_contents(ABSPATH . 'wp-admin/post.php');
        $edit = (string) file_get_contents(ABSPATH . 'wp-admin/edit.php');
        $this->assertMatchesRegularExpression("/case 'editpost':.{0,400}?check_admin_referer\\(\\s*'update-post_'\\s*\\.\\s*\\\$post_id\\s*\\)/s", $post);
        $this->assertMatchesRegularExpression("/check_admin_referer\\(\\s*'bulk-posts'\\s*\\)/", $edit);
        $this->assertMatchesRegularExpression("/\\\$_REQUEST\\['post'\\]/", $edit);
    }

    public function test_a_busy_member_lock_defers_and_the_recovery_finishes_it(): void {
        $this->setExpectedDeprecated('pmprommpu_addMembershipLevel');
        $customer = $this->customer();
        $staff = $this->staff();
        $at = self::whole_second(self::at7('-10 minutes'));
        $this->wire($at, null, 0);
        $order = $this->order($customer);
        $this->held[] = EVC_Member_Lock::acquire($this->second_connection(), $customer, 0);
        $r = $this->complete($order->get_id(), $staff);
        $this->assertResult($r, EVC_Wc_Completion_Result::DEFERRED, 'member_locked');
        $this->assertSame(EVC_Wc_Completion_Marker::ADDON_SUPPRESSED, $r->addon, 'the add-on is withheld even when the Club cannot confirm yet');
        $this->assertSame(array(), $this->rows($customer), 'no membership until the Club confirms: nothing stray for a later confirmation to trip over');
        $marker = $this->marker($order->get_id());
        $this->assertSame(EVC_Wc_Completion_Marker_Read::VALID, $marker->outcome, (string) $marker->reason);
        $this->assertSame('claimed', $marker->marker->reason(), 'written BEFORE the pipeline ran');
        $this->assertSame($staff, $marker->marker->actor_id());
        $this->assertSame('open:0', $this->followup($order->get_id()));
        $this->assertSame('none', $this->me($customer)['state'], 'fail closed until confirmed');

        // Still busy: ticks during the outage are not attempts.
        for ($i = 1; $i <= 3; $i++) {
            $again = $this->recover_at($at->modify('+' . $i . ' minute'));
            $this->assertResult($again[$order->get_id()], EVC_Wc_Completion_Result::DEFERRED, 'member_locked');
            $this->assertSame('open:0', $this->followup($order->get_id()));
        }

        array_pop($this->held)->release();
        $later = $at->modify('+5 minutes');
        $done = $this->recover_at($later);
        $this->assertResult($done[$order->get_id()], EVC_Wc_Completion_Result::RECORDED);
        $this->assertSame(array('planned', 'issue', 'issued', 'record'), $done[$order->get_id()]->steps);
        $this->assertNull($this->followup($order->get_id()));
        $rows = $this->rows($customer);
        $this->assertCount(1, $rows);
        $this->assertSame(self::local($later), $rows[0]['startdate'], 'confirmed at the recovery instant');
        $this->assertSame(self::local(EVC_Membership_Calendar::add_calendar_month($later)), $rows[0]['enddate']);
        $this->assertSame($staff, $this->intent($order->get_id())->intent->actor_id(), 'the staff user who completed it');
        $this->assertSame('active', $this->me($customer)['state']);
        $this->assertSame(array(), $this->parts['recovery']->run());
    }

    public function test_club_database_down_defers_and_keeps_the_paid_time_visible(): void {
        $this->setExpectedDeprecated('pmprommpu_addMembershipLevel');
        $customer = $this->customer();
        $staff = $this->staff();
        list(, $first, $first_at) = $this->purchase($customer, $staff, self::at7('-10 days'));
        $this->assertResult($first, EVC_Wc_Completion_Result::RECORDED);
        $first_end = EVC_Membership_Calendar::add_calendar_month($first_at);

        $at = self::whole_second(self::at7('-10 minutes'));
        $this->wire($at, self::club_down());
        $order = $this->order($customer);
        $writes = array();
        $this->watch_rows($writes);
        $r = $this->complete($order->get_id(), $staff);
        remove_all_filters('query');
        $this->assertResult($r, EVC_Wc_Completion_Result::DEFERRED, 'club_unavailable');
        $this->assertSame(EVC_Wc_Completion_Marker::ADDON_SUPPRESSED, $r->addon);
        $this->assertSame(array(), $writes, 'the add-on\'s own run was withheld: the paid row stays as it is');
        $this->assertSame(1, $this->parts['confirmer']->withheld_runs());
        $me = $this->me($customer);
        $this->assertSame('active', $me['state'], 'the time already paid stays visible');
        $this->assertSame($first_end->format(DATE_ATOM), $me['paid_until_utc']);
        $this->assertSame('open:0', $this->followup($order->get_id()));

        $done = $this->recover_at($at->modify('+3 minutes'));
        $this->assertResult($done[$order->get_id()], EVC_Wc_Completion_Result::RECORDED);
        $new_end = EVC_Membership_Calendar::add_calendar_month($first_end);
        $this->assertSame(self::local($new_end), $this->active_rows($customer)[0]['enddate']);
        $this->assertSame($new_end->format(DATE_ATOM), $this->me($customer)['paid_until_utc']);
    }

    public function test_a_confirmation_in_the_repeated_autumn_hour_is_deferred_then_recovered(): void {
        $this->setExpectedDeprecated('pmprommpu_addMembershipLevel');
        $customer = $this->customer();
        $staff = $this->staff();
        $this->wire(self::utc('2026-10-25 00:30:00')); // 03:30 EEST, the FIRST of two 03:30s in Athens
        $order = $this->order($customer, self::utc('2026-10-24 20:00:00'));
        $r = $this->complete($order->get_id(), $staff);
        $this->assertResult($r, EVC_Wc_Completion_Result::DEFERRED, 'period_not_representable_in_pmpro');
        $this->assertSame(EVC_Bacs_Intent_Read::MISSING, $this->intent($order->get_id())->outcome, 'nothing planned that PMPro cannot show');
        $this->assertSame(array(), $this->club()->member_movements($customer));
        $this->assertSame('open:0', $this->followup($order->get_id()));

        $done = $this->recover_at(self::utc('2026-10-25 02:00:00')); // 04:00 EET, after the repeated hour
        $this->assertResult($done[$order->get_id()], EVC_Wc_Completion_Result::RECORDED);
        $row = $this->active_rows($customer)[0];
        $this->assertSame('2026-10-25 04:00:00', $row['startdate']);
        $this->assertSame('2026-11-25 04:00:00', $row['enddate']);
        $e = $this->entitlement($customer, self::utc('2026-11-01 12:00:00'));
        $this->assertTrue($e->is_eligible_at(self::utc('2026-11-01 12:00:00')));
        $this->assertSame('2026-11-25T02:00:00+00:00', $e->expires_at_utc()->format(DATE_ATOM));
    }

    public function test_a_late_renewal_before_pmpro_expired_the_old_row_waits_for_that_expiry(): void {
        $this->setExpectedDeprecated('pmprommpu_addMembershipLevel');
        $customer = $this->customer();
        $staff = $this->staff();
        list(, $first, $first_at) = $this->purchase($customer, $staff, self::at7('-40 days'));
        $this->assertResult($first, EVC_Wc_Completion_Result::RECORDED);
        $this->assertCount(1, $this->active_rows($customer), 'PMPro\'s expiry has not run yet');

        $at = self::whole_second(self::at7('-3 minutes'));
        $this->wire($at);
        $order = $this->order($customer);
        $writes = array();
        $this->watch_rows($writes);
        $r = $this->complete($order->get_id(), $staff);
        remove_all_filters('query');
        $this->assertResult($r, EVC_Wc_Completion_Result::DEFERRED, 'late_renewal_before_pmpro_expiry');
        $this->assertSame(EVC_Wc_Completion_Marker::ADDON_SUPPRESSED, $r->addon);
        $this->assertSame(array(), $writes, 'no month added by the add-on');
        $this->assertSame('expired', $this->me($customer)['state']);

        $this->assertResult($this->recover_at($at->modify('+1 minute'))[$order->get_id()], EVC_Wc_Completion_Result::DEFERRED, 'late_renewal_before_pmpro_expiry');
        $this->assertSame('open:1', $this->followup($order->get_id()));
        pmpro_cancelMembershipLevel($this->config->level_id, $customer, 'expired'); // PMPro's own expiry runs
        $later = $at->modify('+2 minutes');
        $done = $this->recover_at($later);
        $this->assertResult($done[$order->get_id()], EVC_Wc_Completion_Result::RECORDED);
        $this->assertSame(EVC_Funded_Period::KIND_LATE, $this->intent($order->get_id())->intent->period_kind());
        $row = $this->active_rows($customer)[0];
        $this->assertSame(self::local($later), $row['startdate']);
        $this->assertSame('active', $this->me($customer)['state']);
        $this->assertTrue($first_at < $later);
    }

    public function test_automatic_retries_are_bounded(): void {
        $this->setExpectedDeprecated('pmprommpu_addMembershipLevel');
        $customer = $this->customer();
        $staff = $this->staff();
        list(, $first) = $this->purchase($customer, $staff, self::at7('-40 days'));
        $this->assertResult($first, EVC_Wc_Completion_Result::RECORDED);
        $at = self::whole_second(self::at7('-2 minutes'));
        $this->wire($at);
        $order = $this->order($customer);
        // PMPro's expiry has not run: a LATE renewal waits for it (a real attempt each time, not an outage).
        $this->assertResult($this->complete($order->get_id(), $staff), EVC_Wc_Completion_Result::DEFERRED, 'late_renewal_before_pmpro_expiry');
        $this->assertTrue($this->parts['markers']->set_followup($order->get_id(), 'open:' . (EVC_Wc_Completion_Confirmer::MAX_ATTEMPTS - 1)));
        $r = $this->recover_at($at->modify('+1 minute'))[$order->get_id()];
        $this->assertResult($r, EVC_Wc_Completion_Result::DEFERRED, 'late_renewal_before_pmpro_expiry');
        $this->assertSame('manager:attempts_exhausted', $this->followup($order->get_id()));
        $this->assertSame(array(), $this->parts['recovery']->run(), 'a manager must look now');
        $this->assertNotEmpty(array_filter($this->notes($order->get_id()), function ($n) {
            return strpos($n, 'late_renewal_before_pmpro_expiry') !== false;
        }), 'visible on the order');
        // Reset path: completing it again (on-hold, then Completed) opens a fresh recovery.
        $this->in_admin($staff, function () use ($order) {
            wc_get_order($order->get_id())->update_status('on-hold', '', true);
        });
        $this->complete($order->get_id(), $staff);
        $this->assertSame('open:0', $this->followup($order->get_id()));
    }

    public function test_ticks_during_an_outage_never_exhaust_the_attempts(): void {
        $this->setExpectedDeprecated('pmprommpu_addMembershipLevel');
        $customer = $this->customer();
        $at = self::whole_second(self::at7('-2 minutes'));
        $this->wire($at, null, 0);
        $order = $this->order($customer);
        $this->held[] = EVC_Member_Lock::acquire($this->second_connection(), $customer, 0);
        $this->assertResult($this->complete($order->get_id(), $this->staff()), EVC_Wc_Completion_Result::DEFERRED, 'member_locked');
        $this->assertTrue($this->parts['markers']->set_followup($order->get_id(), 'open:' . (EVC_Wc_Completion_Confirmer::MAX_ATTEMPTS - 1)));
        $r = $this->recover_at($at->modify('+1 minute'))[$order->get_id()];
        $this->assertResult($r, EVC_Wc_Completion_Result::DEFERRED, 'member_locked');
        $this->assertSame('open:' . (EVC_Wc_Completion_Confirmer::MAX_ATTEMPTS - 1), $this->followup($order->get_id()), 'the outage did not use up an attempt');
        array_pop($this->held)->release();
        $done = $this->recover_at($at->modify('+2 minutes'));
        $this->assertResult($done[$order->get_id()], EVC_Wc_Completion_Result::RECORDED);
    }

    public function test_cancelling_a_recorded_order_freezes_the_card(): void {
        $this->setExpectedDeprecated('pmprommpu_addMembershipLevel');
        $customer = $this->customer();
        $staff = $this->staff();
        list($order, $r) = $this->purchase($customer, $staff, self::at7('-30 seconds'));
        $this->assertResult($r, EVC_Wc_Completion_Result::RECORDED);
        $movement = $this->intent($order->get_id())->intent->movement_id();

        wp_set_current_user($staff);
        wc_get_order($order->get_id())->update_status('cancelled');
        wp_set_current_user(0);
        $c = $this->parts['confirmer']->last_result();
        $this->assertResult($c, EVC_Wc_Completion_Result::CORRECTED, EVC_Movement_Correction::KIND_VOIDED);
        $corrections = $this->club()->corrections_for($movement);
        $this->assertCount(1, $corrections);
        $this->assertSame(EVC_Movement_Correction::KIND_VOIDED, $corrections[0]->kind());
        $this->assertSame(EVC_Verified_Movement::AUTHORITY_ORDER_STATE, $corrections[0]->authority());
        $this->assertTrue($this->parts['markers']->was_ended($order->get_id()));
        $this->assertNotSame('active', $this->me($customer)['state']);
        $this->assertFalse($this->entitlement($customer, new DateTimeImmutable('now'))->is_eligible_at(new DateTimeImmutable('now')), 'no free coffee');
        $this->assertCount(1, $this->periods($customer), 'history kept, nothing deleted');
        $events = $this->audit_events(EVC_Wc_Completion_Confirmer::CORRECTION_EVENT, $this->config->ref('wc_order', (string) $order->get_id()));
        $this->assertSame(array('corrected'), array_column($events, 'outcome'));
    }

    public function test_a_full_refund_of_a_renewal_freezes_the_card_although_pmpro_keeps_the_row(): void {
        $this->setExpectedDeprecated('pmprommpu_addMembershipLevel');
        $customer = $this->customer();
        $staff = $this->staff();
        list(, $first) = $this->purchase($customer, $staff, self::at7('-10 days'));
        $this->assertResult($first, EVC_Wc_Completion_Result::RECORDED);
        list($renewal, $r) = $this->purchase($customer, $staff, self::at7('-30 seconds'));
        $this->assertResult($r, EVC_Wc_Completion_Result::RECORDED);
        $this->assertSame('active', $this->me($customer)['state']);

        wp_set_current_user($staff);
        $refund = wc_create_refund(array('order_id' => $renewal->get_id(), 'amount' => '20.00', 'reason' => 'synthetic'));
        wp_set_current_user(0);
        $this->assertInstanceOf(WC_Order_Refund::class, $refund);
        $this->assertSame('refunded', wc_get_order($renewal->get_id())->get_status());
        $this->assertResult($this->parts['confirmer']->last_result(), EVC_Wc_Completion_Result::ALREADY_CORRECTED, EVC_Movement_Correction::KIND_REFUNDED);
        $corrections = $this->club()->corrections_for($this->intent($renewal->get_id())->intent->movement_id());
        $this->assertCount(1, $corrections, 'recorded once (status hook), confirmed again by the refund hook');
        $this->assertSame(EVC_Movement_Correction::KIND_REFUNDED, $corrections[0]->kind());
        $this->assertCount(1, $this->active_rows($customer), 'the add-on keeps a renewing customer\'s PMPro row');
        $e = $this->entitlement($customer, new DateTimeImmutable('now'));
        $this->assertSame(EVC_Entitlement::STATUS_REFUNDED, $e->status());
        $this->assertFalse($e->is_eligible_at(new DateTimeImmutable('now')), 'card frozen');
        $this->assertNotSame('active', $this->me($customer)['state']);
    }

    public function test_a_partial_refund_is_only_flagged_for_a_manager(): void {
        $this->setExpectedDeprecated('pmprommpu_addMembershipLevel');
        $customer = $this->customer();
        list($order, $r) = $this->purchase($customer, $this->staff(), self::at7('-30 seconds'));
        $this->assertResult($r, EVC_Wc_Completion_Result::RECORDED);
        $refund = wc_create_refund(array('order_id' => $order->get_id(), 'amount' => '5.00', 'reason' => 'synthetic'));
        $this->assertInstanceOf(WC_Order_Refund::class, $refund);
        $this->assertSame('completed', wc_get_order($order->get_id())->get_status());
        $this->assertResult($this->parts['confirmer']->last_result(), EVC_Wc_Completion_Result::MANAGER_REQUIRED, 'partial_refund');
        $this->assertSame(array(), $this->club()->corrections_for($this->intent($order->get_id())->intent->movement_id()), 'no automatic change');
        $this->assertSame('active', $this->me($customer)['state']);
        $events = $this->audit_events(EVC_Wc_Completion_Confirmer::CORRECTION_EVENT, $this->config->ref('wc_order', (string) $order->get_id()));
        $this->assertSame(array('manager_required'), array_column($events, 'outcome'));
    }

    public function test_completing_again_after_a_cancellation_or_refund_is_never_a_confirmation(): void {
        $this->setExpectedDeprecated('pmprommpu_addMembershipLevel');
        $customer = $this->customer();
        $staff = $this->staff();
        list($order, $r) = $this->purchase($customer, $staff, self::at7('-30 seconds'));
        $this->assertResult($r, EVC_Wc_Completion_Result::RECORDED);
        wp_set_current_user($staff);
        wc_get_order($order->get_id())->update_status('cancelled');
        $again = $this->complete($order->get_id(), $staff);
        $this->assertResult($again, EVC_Wc_Completion_Result::MANAGER_REQUIRED, 'completed_after_cancellation_or_refund');
        $this->assertCount(1, $this->rows($customer), 'the add-on added nothing');
        $this->assertCount(1, $this->periods($customer), 'nothing recorded again');
        $this->assertNotSame('active', $this->me($customer)['state'], 'the card stays frozen');

        // Never recorded, cancelled, put back on hold, then completed: still not a confirmation, and no stray row.
        $other = $this->customer();
        $o = $this->order($other);
        wp_set_current_user($staff);
        wc_get_order($o->get_id())->update_status('cancelled');
        wc_get_order($o->get_id())->update_status('on-hold');
        $flagged = $this->complete($o->get_id(), $staff);
        $this->assertResult($flagged, EVC_Wc_Completion_Result::MANAGER_REQUIRED, 'completed_after_cancellation_or_refund');
        $this->assertSame(array(), $this->club()->member_movements($other));
        $this->assertSame(array(), $this->rows($other), 'no row from the add-on for a completion the Club refused');
        $this->assertSame('none', $this->me($other)['state']);
        $events = $this->audit_events(EVC_Wc_Completion_Confirmer::EVENT, $this->config->ref('wc_order', (string) $o->get_id()));
        $this->assertSame(array('manager_required'), array_column($events, 'outcome'));
        $this->assertCount(1, array_filter($this->notes($o->get_id()), function ($n) {
            return strpos($n, 'completed_after_cancellation_or_refund') !== false;
        }));

        // The customer pays again with a NEW order: that one is confirmed normally.
        $fresh = $this->order($other);
        $this->assertResult($this->complete($fresh->get_id(), $staff), EVC_Wc_Completion_Result::RECORDED);
        $this->assertCount(1, $this->rows($other));
        $this->assertSame('active', $this->me($other)['state']);
    }

    public function test_a_partial_refund_followed_by_a_second_completion_is_flagged_and_adds_nothing(): void {
        $this->setExpectedDeprecated('pmprommpu_addMembershipLevel');
        $customer = $this->customer();
        $staff = $this->staff();
        list($order, $r) = $this->purchase($customer, $staff, self::at7('-30 seconds'));
        $this->assertResult($r, EVC_Wc_Completion_Result::RECORDED);
        wc_create_refund(array('order_id' => $order->get_id(), 'amount' => '5.00', 'reason' => 'synthetic'));
        $this->in_admin($staff, function () use ($order) {
            wc_get_order($order->get_id())->update_status('on-hold', '', true);
        });
        $again = $this->complete($order->get_id(), $staff);
        $this->assertResult($again, EVC_Wc_Completion_Result::MANAGER_REQUIRED, 'completed_after_cancellation_or_refund');
        $this->assertCount(1, $this->rows($customer));
        $this->assertCount(1, $this->periods($customer));
    }

    public function test_a_correction_that_cannot_be_recorded_now_is_finished_by_the_recovery(): void {
        $this->setExpectedDeprecated('pmprommpu_addMembershipLevel');
        $customer = $this->customer();
        $staff = $this->staff();
        list(, $first) = $this->purchase($customer, $staff, self::at7('-10 days'));
        $this->assertResult($first, EVC_Wc_Completion_Result::RECORDED);
        // A renewal: PMPro WooCommerce keeps a renewing customer's row when the order is cancelled.
        list($order, $r, $at) = $this->purchase($customer, $staff, self::at7('-5 minutes'));
        $this->assertResult($r, EVC_Wc_Completion_Result::RECORDED);
        $movement = $this->intent($order->get_id())->intent->movement_id();
        $this->wire($at, self::club_down());
        wc_get_order($order->get_id())->update_status('cancelled');
        $this->assertResult($this->parts['confirmer']->last_result(), EVC_Wc_Completion_Result::DEFERRED, 'club_unavailable');
        $this->assertSame('open:0', $this->followup($order->get_id()));
        $this->assertSame(array(), $this->club()->corrections_for($movement));
        $this->assertCount(1, $this->active_rows($customer), 'PMPro still shows the renewed row');
        $this->assertSame('unverified', $this->me($customer)['state'], 'frozen even before the correction is recorded (reader conflict)');

        $done = $this->recover_at($at->modify('+1 minute'));
        $this->assertResult($done[$order->get_id()], EVC_Wc_Completion_Result::CORRECTED, EVC_Movement_Correction::KIND_VOIDED);
        $this->assertNull($this->followup($order->get_id()));
        $this->assertCount(1, $this->club()->corrections_for($movement));
    }

    public function test_an_exception_inside_the_confirmation_never_aborts_woocommerce(): void {
        $this->setExpectedDeprecated('pmprommpu_addMembershipLevel');
        $customer = $this->customer();
        $staff = $this->staff();
        $at = self::whole_second(self::at7('-5 minutes'));
        $this->wire($at);
        $order = $this->order($customer);
        $secret = 'evc-secret-exception-text-' . wp_generate_password(8, false, false);
        $throw = function () use ($secret) {
            throw new RuntimeException($secret);
        };
        add_filter('pre_option__pmprowoo_product_levels', $throw);
        try {
            $r = $this->complete($order->get_id(), $staff);
        } catch (Throwable $e) {
            $this->fail('an exception escaped into WooCommerce: ' . get_class($e));
        }
        remove_filter('pre_option__pmprowoo_product_levels', $throw);
        $this->assertResult($r, EVC_Wc_Completion_Result::DEFERRED, 'unexpected_error');
        $this->assertSame('completed', wc_get_order($order->get_id())->get_status());
        $this->assertSame(array(), $this->rows($customer), 'the add-on is withheld; the Club confirms at the recovery');
        $this->assertGuardInert();
        $this->assertSame('open:0', $this->followup($order->get_id()));
        $this->assertSame(EVC_Wc_Completion_Marker_Read::VALID, $this->marker($order->get_id())->outcome);
        $events = $this->audit_events(EVC_Wc_Completion_Confirmer::EVENT, $this->config->ref('wc_order', (string) $order->get_id()));
        $this->assertCount(1, $events);
        $this->assertStringNotContainsString($secret, $events[0]['details_json']);
        $lock = EVC_Member_Lock::acquire($this->second_connection(), $customer, 0);
        $this->assertNotNull($lock, 'the member lock was released');
        $lock->release();

        $done = $this->recover_at($at->modify('+1 minute'));
        $this->assertResult($done[$order->get_id()], EVC_Wc_Completion_Result::RECORDED);
        $this->assertSame('active', $this->me($customer)['state']);
    }

    public function test_an_exception_during_an_early_renewal_never_lets_the_add_on_add_a_month(): void {
        $this->setExpectedDeprecated('pmprommpu_addMembershipLevel');
        $customer = $this->customer();
        $staff = $this->staff();
        list(, $first, $first_at) = $this->purchase($customer, $staff, self::at7('-10 days'));
        $this->assertResult($first, EVC_Wc_Completion_Result::RECORDED);
        $this->wire(self::whole_second(self::at7('-2 minutes')));
        $order = $this->order($customer);
        $throw = function () {
            throw new Error('simulated fatal error inside the Club confirmation');
        };
        add_filter('pre_option__pmprowoo_product_levels', $throw);
        $writes = array();
        $this->watch_rows($writes);
        $r = $this->complete($order->get_id(), $staff);
        remove_all_filters('query');
        remove_filter('pre_option__pmprowoo_product_levels', $throw);
        $this->assertResult($r, EVC_Wc_Completion_Result::DEFERRED, 'unexpected_error');
        $this->assertSame(EVC_Wc_Completion_Marker::ADDON_SUPPRESSED, $r->addon);
        $this->assertSame(array(), $writes, 'withheld as designed: no month from the add-on');
        $this->assertSame(EVC_Membership_Calendar::add_calendar_month($first_at)->format(DATE_ATOM), $this->me($customer)['paid_until_utc']);
    }

    public function test_an_error_before_the_completion_is_claimed_never_escapes_into_woocommerce(): void {
        $this->setExpectedDeprecated('pmprommpu_addMembershipLevel');
        $customer = $this->customer();
        $staff = $this->staff();
        $at = self::whole_second(self::at7('-30 seconds'));
        $this->wire($at);
        $order = $this->order($customer);
        $armed = false;
        add_action('woocommerce_order_status_completed', function () use (&$armed) {
            $armed = true;
        }, 4);
        add_filter('woocommerce_order_get_items', function ($items) use (&$armed) {
            if ($armed) {
                $armed = false;
                throw new Error('simulated fatal error while the Club reads the order');
            }
            return $items;
        });
        try {
            $r = $this->complete($order->get_id(), $staff);
        } catch (Throwable $e) {
            $this->fail('an error escaped into WooCommerce: ' . get_class($e));
        }
        $this->assertResult($r, EVC_Wc_Completion_Result::UNAVAILABLE, 'unexpected_error');
        $this->assertSame('completed', wc_get_order($order->get_id())->get_status());
        $this->assertSame(array(), $this->rows($customer), 'the add-on was withheld before anything could fail');
        $this->assertSame(10, has_action('woocommerce_order_status_completed', 'pmprowoo_add_membership_from_order'), 'put back after the run');
        $this->assertGuardInert();
        // The catch wrote the signed marker and the open index (an attested manual completion of a Club order):
        $this->assertSame(EVC_Wc_Completion_Marker_Read::VALID, $this->marker($order->get_id())->outcome);
        $this->assertSame('open:0', $this->followup($order->get_id()));
        $done = $this->recover_at($at->modify('+1 minute'));
        $this->assertResult($done[$order->get_id()], EVC_Wc_Completion_Result::RECORDED);
        $this->assertCount(1, $this->rows($customer));
    }

    // ---- the add-on is withheld, never "suppressed afterwards" --------------------------------------------

    public function test_the_add_on_is_withheld_for_a_club_order_and_put_back_for_the_next_one(): void {
        $this->setExpectedDeprecated('pmprommpu_addMembershipLevel');
        $staff = $this->staff();
        $c = $this->wire(self::whole_second(self::at7('-5 minutes')));
        $seen = array();
        foreach (array(4 => 'before', 9 => 'between', 12 => 'after') as $priority => $label) {
            add_action('woocommerce_order_status_completed', function ($id) use (&$seen, $label) {
                $seen[(int) $id][$label] = has_action('woocommerce_order_status_completed', 'pmprowoo_add_membership_from_order');
            }, $priority);
        }
        $club = $this->order($this->customer());
        $this->assertResult($this->complete($club->get_id(), $staff), EVC_Wc_Completion_Result::RECORDED);
        $this->assertSame(array('before' => 10, 'between' => false, 'after' => 10), $seen[$club->get_id()], 'removed for the run of the Club order, back before the next order');
        $this->assertSame(1, $c->withheld_runs());
        $this->assertSame(10, has_action('woocommerce_order_status_completed', 'pmprowoo_add_membership_from_order'));

        // An ordinary order (not the Club product): the add-on is back at once and behaves as shipped.
        $product = new WC_Product_Simple();
        $product->set_regular_price('3');
        $product->set_virtual(true);
        $product->save();
        $ordinary = wc_create_order(array('customer_id' => $this->customer()));
        $ordinary->add_product($product, 1);
        $ordinary->set_payment_method('bacs');
        $ordinary->calculate_totals();
        $ordinary->save();
        $r = $this->complete($ordinary->get_id(), $staff);
        $this->assertResult($r, EVC_Wc_Completion_Result::IGNORED, 'not_a_club_order');
        $this->assertSame(array('before' => 10, 'between' => 10, 'after' => 10), $seen[$ordinary->get_id()]);
        $this->assertSame(1, $c->withheld_runs(), 'the add-on was not withheld for the ordinary order');
        $this->assertSame(array(), $this->audit_events(EVC_Wc_Completion_Confirmer::EVENT, $this->config->ref('wc_order', (string) $ordinary->get_id())));
    }

    public function test_a_toggle_after_the_paid_period_ended_adds_no_stray_row_and_a_later_renewal_is_confirmed(): void {
        $this->setExpectedDeprecated('pmprommpu_addMembershipLevel');
        $customer = $this->customer();
        $staff = $this->staff();
        list($order, $first) = $this->purchase($customer, $staff, self::at7('-40 days'));
        $this->assertResult($first, EVC_Wc_Completion_Result::RECORDED);
        pmpro_cancelMembershipLevel($this->config->level_id, $customer, 'expired'); // PMPro's own expiry
        $this->assertSame(array(), $this->active_rows($customer));
        $this->in_admin($staff, function () use ($order) {
            wc_get_order($order->get_id())->update_status('on-hold', '', true);
        });
        $again = $this->complete($order->get_id(), $staff);
        $this->assertResult($again, EVC_Wc_Completion_Result::MANAGER_REQUIRED, 'recorded_but_membership_differs');
        $this->assertCount(1, $this->rows($customer), 'no new row');
        $this->assertSame(array(), $this->active_rows($customer), 'nothing stray is active that would block the next confirmation');
        $this->assertSame('expired', $this->me($customer)['state']);

        // The shop confirms the customer's next payment: a normal LATE renewal.
        list($next, $r, $at) = $this->purchase($customer, $staff, self::at7('-30 seconds'));
        $this->assertResult($r, EVC_Wc_Completion_Result::RECORDED);
        $this->assertSame(EVC_Funded_Period::KIND_LATE, $this->intent($next->get_id())->intent->period_kind());
        $this->assertCount(2, $this->rows($customer));
        $this->assertSame(self::local($at), $this->active_rows($customer)[0]['startdate']);
        $this->assertSame('active', $this->me($customer)['state']);
    }

    public function test_a_refused_completion_leaves_nothing_behind_and_a_corrected_order_is_confirmed_later(): void {
        $this->setExpectedDeprecated('pmprommpu_addMembershipLevel');
        $staff = $this->staff();
        $this->wire(self::whole_second(self::at7('-5 minutes')));
        $extra = new WC_Product_Simple();
        $extra->set_regular_price('2');
        $extra->set_virtual(true);
        $extra->save();
        $club = wc_get_product(EVC_TEST_CLUB_PRODUCT);
        $cases = array(
            'order_not_bank_transfer' => function (WC_Order $o) {
                $o->set_payment_method('cod');
            },
            'order_not_eur' => function (WC_Order $o) {
                $o->set_currency('USD');
            },
            'order_total_not_20_eur' => function (WC_Order $o) {
                $o->set_total('15.00');
            },
            'order_total_changed_by_tax_or_discount' => function (WC_Order $o) {
                $o->set_cart_tax('3.20');
                $o->set_total('23.20');
            },
            'order_not_a_single_club_item' => function (WC_Order $o) use ($club) {
                $o->remove_order_items();
                $o->add_product($club, 2);
                $o->calculate_totals();
            },
            'order_not_a_single_club_item_extra' => function (WC_Order $o) use ($extra) {
                $o->add_product($extra, 1);
                $o->calculate_totals();
            },
        );
        foreach ($cases as $name => $mutate) {
            $expected = $name === 'order_not_a_single_club_item_extra' ? 'order_not_a_single_club_item' : $name;
            $customer = $this->customer();
            $order = $this->order_with($customer, $mutate);
            $r = $this->complete($order->get_id(), $staff);
            $this->assertResult($r, EVC_Wc_Completion_Result::CONFLICT, $expected);
            $this->assertSame(array(), $this->rows($customer), $name . ': no row from the add-on');
            $this->assertSame(EVC_Wc_Completion_Marker_Read::MISSING, $this->marker($order->get_id())->outcome, $name);
            $this->assertSame(EVC_Bacs_Intent_Read::MISSING, $this->intent($order->get_id())->outcome, $name);
            $this->assertSame('unverified', $this->me($customer)['state'], $name);
            $this->assertCount(1, $this->notes($order->get_id()), $name);
        }

        // Refused (cash on delivery), corrected to a bank transfer, completed again: confirmed normally.
        $customer = $this->customer();
        $order = $this->order_with($customer, function (WC_Order $o) {
            $o->set_payment_method('cod');
        });
        $this->assertResult($this->complete($order->get_id(), $staff), EVC_Wc_Completion_Result::CONFLICT, 'order_not_bank_transfer');
        $this->in_admin($staff, function () use ($order) {
            $o = wc_get_order($order->get_id());
            $o->set_payment_method('bacs');
            $o->update_status('on-hold', '', true);
        });
        $r = $this->complete($order->get_id(), $staff);
        $this->assertResult($r, EVC_Wc_Completion_Result::RECORDED);
        $this->assertCount(1, $this->rows($customer));
        $this->assertSame('active', $this->me($customer)['state']);
    }

    // ---- gate on the pinned environment, tripwire ---------------------------------------------------------

    public function test_outside_the_pinned_environment_the_club_does_not_take_over(): void {
        $this->setExpectedDeprecated('pmprommpu_addMembershipLevel');
        $customer = $this->customer();
        $staff = $this->staff();
        $this->wire(self::whole_second(self::at7('-5 minutes')));
        $order = $this->order($customer);
        $other_level = function () {
            return array(EVC_TEST_CLUB_PRODUCT => 99); // the product is mapped to another level than the Club's
        };
        add_filter('pre_option__pmprowoo_product_levels', $other_level);
        $r = $this->complete($order->get_id(), $staff);
        remove_filter('pre_option__pmprowoo_product_levels', $other_level);
        $this->assertResult($r, EVC_Wc_Completion_Result::MANAGER_REQUIRED, 'commerce_environment_conflict');
        $this->assertSame(EVC_Wc_Completion_Marker::ADDON_SHIPPED, $r->addon, 'not withheld: the add-on behaves exactly as shipped');
        $this->assertSame(0, $this->parts['confirmer']->withheld_runs());
        $rows = $this->rows($customer);
        $this->assertCount(1, $rows);
        $this->assertAddOnRow($rows[0]);
        $this->assertSame(EVC_Wc_Completion_Marker_Read::MISSING, $this->marker($order->get_id())->outcome);
        $this->assertSame(EVC_Bacs_Intent_Read::MISSING, $this->intent($order->get_id())->outcome);
        $this->assertSame(array(), $this->club()->member_movements($customer));
        $this->assertSame('unverified', $this->me($customer)['state']);
        $this->assertCount(1, $this->notes($order->get_id()));
    }

    public function test_an_endless_active_club_row_after_the_add_on_is_flagged_for_a_manager(): void {
        global $wpdb;
        $this->setExpectedDeprecated('pmprommpu_addMembershipLevel');
        $customer = $this->customer();
        $staff = $this->staff();
        $this->wire(self::whole_second(self::at7('-5 minutes')));
        $order = $this->order($customer);
        add_action('woocommerce_order_status_completed', function () use ($wpdb, $customer) {
            // Something else gives the customer the Club level without an end date (the shape of PMPro's integer-level row).
            $wpdb->insert($wpdb->pmpro_memberships_users, array('user_id' => $customer, 'membership_id' => $this->config->level_id, 'status' => 'active', 'startdate' => '2026-01-01 10:00:00', 'enddate' => null));
        }, 10);
        $this->assertResult($this->complete($order->get_id(), $staff), EVC_Wc_Completion_Result::RECORDED);
        $this->assertSame('manager:endless_membership_row', $this->followup($order->get_id()));
        $events = $this->audit_events(EVC_Wc_Completion_Confirmer::EVENT, $this->config->ref('wc_order', (string) $order->get_id()));
        $this->assertContains('manager_required', array_column($events, 'outcome'));
        $this->assertStringContainsString('endless_membership_row', implode('', array_column($events, 'details_json')));
        $this->assertNotEmpty(array_filter($this->notes($order->get_id()), function ($n) {
            return strpos($n, 'endless_membership_row') !== false;
        }));
    }

    // ---- crashes, locks, markers ------------------------------------------------------------------------

    public function test_a_crash_after_the_issuance_is_finished_by_the_recovery_without_a_second_month(): void {
        $this->setExpectedDeprecated('pmprommpu_addMembershipLevel');
        $staff = $this->staff();
        foreach (array('commit_fails' => null, 'fatal_error_in_the_commit' => 'throw') as $name => $mode) {
            $customer = $this->customer();
            $at = self::whole_second(self::at7('-20 minutes'));
            $hooked = new EVC_Hooked_Provenance_Store($this->club());
            if ($mode === null) {
                $hooked->commit_mode = EVC_Hooked_Provenance_Store::COMMIT_FAIL_BEFORE;
            } else {
                $hooked->before_commit = function () {
                    throw new Error('simulated fatal error right after the issuance');
                };
            }
            $this->wire($at, function () use ($hooked) {
                return $hooked;
            });
            $order = $this->order($customer);
            try {
                $r = $this->complete($order->get_id(), $staff);
            } catch (Throwable $e) {
                $this->fail($name . ': an error escaped into WooCommerce: ' . get_class($e));
            }
            $this->assertSame(EVC_Wc_Completion_Result::DEFERRED, $r->outcome, $name . ' ' . (string) $r->reason);
            $rows = $this->rows($customer);
            $this->assertCount(1, $rows, $name . ': the Club\'s own row was issued before the crash');
            $this->assertSame(self::local($at), $rows[0]['startdate'], $name);
            $this->assertSame(EVC_Bacs_Intent::STATE_ISSUED, $this->intent($order->get_id())->intent->state(), $name);
            $this->assertSame(EVC_Wc_Completion_Marker_Read::VALID, $this->marker($order->get_id())->outcome, $name);
            $this->assertSame('open:0', $this->followup($order->get_id()), $name . ': found by the recovery');
            $this->assertSame(array(), $this->club()->member_movements($customer), $name);
            $this->assertSame('unverified', $this->me($customer)['state'], $name);

            $done = $this->recover_at($at->modify('+1 minute'));
            $this->assertResult($done[$order->get_id()], EVC_Wc_Completion_Result::RECORDED);
            $this->assertCount(1, $this->rows($customer), $name . ': no second month');
            $this->assertCount(1, $this->periods($customer), $name);
            $this->assertNull($this->followup($order->get_id()), $name);
            $this->assertSame('active', $this->me($customer)['state'], $name);
        }
    }

    public function test_a_member_lock_lost_after_the_issuance_stops_the_pipeline_and_the_recovery_finishes_it(): void {
        $this->setExpectedDeprecated('pmprommpu_addMembershipLevel');
        $customer = $this->customer();
        $staff = $this->staff();
        $at = self::whole_second(self::at7('-20 minutes'));
        $this->wire($at);
        $order = $this->order($customer);
        $release = function () use ($customer) {
            $db = $GLOBALS['wpdb'];
            $db->get_var($db->prepare('SELECT RELEASE_LOCK(%s)', EVC_Member_Lock::name_for($db, $customer))); // e.g. a reconnect
        };
        add_action('pmpro_after_change_membership_level', $release, 10, 0); // right after the Club's own armed issuance
        $r = $this->complete($order->get_id(), $staff);
        remove_action('pmpro_after_change_membership_level', $release, 10);
        $this->assertSame(EVC_Wc_Completion_Result::DEFERRED, $r->outcome, (string) $r->reason);
        $this->assertSame('lock_lost', $r->reason);
        $this->assertCount(1, $this->rows($customer), 'the issuance happened before the lock was lost');
        $this->assertCount(0, $this->periods($customer), 'nothing recorded without the lock');
        $this->assertGuardInert();
        $this->assertSame('open:0', $this->followup($order->get_id()), 'an outage, not an attempt');
        $done = $this->recover_at($at->modify('+1 minute'));
        $this->assertResult($done[$order->get_id()], EVC_Wc_Completion_Result::RECORDED);
        $this->assertCount(1, $this->rows($customer), 'no second month');
        $this->assertCount(1, $this->periods($customer));
        $this->assertSame('active', $this->me($customer)['state']);
    }

    public function test_a_second_application_of_the_guard_is_never_confirmed(): void {
        $this->setExpectedDeprecated('pmprommpu_addMembershipLevel');
        $customer = $this->customer();
        $staff = $this->staff();
        $this->wire(self::whole_second(self::at7('-5 minutes')));
        $order = $this->order($customer);
        $once = false;
        $level = $this->config->level_id;
        add_action('pmpro_after_change_membership_level', function () use (&$once, $customer, $level) {
            if (!$once) {
                $once = true;
                apply_filters('pmprowoo_checkout_level', array('user_id' => $customer, 'membership_id' => $level)); // the guard applies a second time
            }
        }, 10, 0);
        $r = $this->complete($order->get_id(), $staff);
        $this->assertResult($r, EVC_Wc_Completion_Result::MANAGER_REQUIRED, 'issuance_not_guarded_once');
        $this->assertSame('manager:issuance_not_guarded_once', $this->followup($order->get_id()));
        $this->assertSame(array(), $this->club()->member_movements($customer), 'never confirmed');
        $this->assertGuardInert();
    }

    public function test_an_intent_of_the_bank_statement_path_is_never_mixed_with_this_one(): void {
        $customer = $this->customer();
        $staff = $this->staff();
        $at = self::whole_second(self::at7('-5 minutes'));
        $this->wire($at);
        $order = $this->order($customer);
        $club = $this->club();
        $recorder_for = new ReflectionMethod('EVC_Wc_Completion_Confirmer', 'recorder_for');
        $recorder_for->setAccessible(true);
        $recorder = $recorder_for->invoke(null, $club, $this->config);
        $movement = $this->config->ref('bacs_transfer', 'TRF' . strtoupper(bin2hex(random_bytes(5))));
        $anchor = $this->config->ref('wc_order', (string) $order->get_id());
        $plan = $recorder->plan(new EVC_Prospective_Payment($movement, 'live', $customer, $this->config->level_id, $at, 2000, 'EUR', array(new EVC_Movement_Alias(EVC_Movement_Alias::NS_WC_ORDER, $anchor)), EVC_Verified_Movement::AUTHORITY_MANUAL_ADMIN, EVC_Payment_Evidence::KIND_MANUAL_CONFIRMATION));
        $this->assertSame(EVC_Period_Plan::PLANNED, $plan->outcome(), (string) $plan->reason());
        $intent = EVC_Bacs_Intent::planned('live', (int) $order->get_id(), $customer, $this->config->level_id, $movement, $anchor, $staff, $at, $plan, EVC_Paid_Run::start_for($club, $plan), null);
        $lock = EVC_Member_Lock::acquire($GLOBALS['wpdb'], $customer, 0);
        $this->assertNotNull($lock);
        $this->assertSame(EVC_Bacs_Intent_Write::WRITTEN, $this->parts['intents']->create($lock, $intent)->outcome);
        $lock->release();

        $r = $this->complete($order->get_id(), $staff);
        $this->assertResult($r, EVC_Wc_Completion_Result::MANAGER_REQUIRED, 'intent_from_bank_confirmation');
        $this->assertSame(array(), $this->rows($customer), 'nothing issued');
        $this->assertSame(array(), $this->club()->member_movements($customer));
    }

    public function test_a_tampered_marker_stops_the_confirmation_before_anything_is_issued(): void {
        $customer = $this->customer();
        $staff = $this->staff();
        $this->wire(self::whole_second(self::at7('-5 minutes')));
        $order = $this->order($customer);
        $o = wc_get_order($order->get_id());
        $o->update_meta_data(EVC_Wc_Completion_Markers::META_KEY, '{"v":1,"not":"a signed marker"}');
        $o->save();
        $r = $this->complete($order->get_id(), $staff);
        $this->assertResult($r, EVC_Wc_Completion_Result::MANAGER_REQUIRED, 'marker_conflict');
        $this->assertSame(array(), $this->rows($customer));
        $this->assertSame(EVC_Bacs_Intent_Read::MISSING, $this->intent($order->get_id())->outcome);
        $this->assertSame('manager:marker_conflict', $this->followup($order->get_id()));
    }

    public function test_a_stored_marker_is_immutable_and_the_recovery_uses_the_first_staff_user(): void {
        $this->setExpectedDeprecated('pmprommpu_addMembershipLevel');
        $customer = $this->customer();
        $first_staff = $this->staff();
        $second_staff = $this->staff();
        $at = self::whole_second(self::at7('-10 minutes'));
        $this->wire($at, null, 0);
        $order = $this->order($customer);
        $this->held[] = EVC_Member_Lock::acquire($this->second_connection(), $customer, 0);
        $this->assertResult($this->complete($order->get_id(), $first_staff), EVC_Wc_Completion_Result::DEFERRED, 'member_locked');
        $this->in_admin($first_staff, function () use ($order) {
            wc_get_order($order->get_id())->update_status('on-hold', '', true);
        });
        $this->assertResult($this->complete($order->get_id(), $second_staff), EVC_Wc_Completion_Result::DEFERRED, 'member_locked');
        $this->assertSame($first_staff, $this->marker($order->get_id())->marker->actor_id(), 'the marker is never replaced');
        array_pop($this->held)->release();
        $done = $this->recover_at($at->modify('+5 minutes'));
        $this->assertResult($done[$order->get_id()], EVC_Wc_Completion_Result::RECORDED);
        $this->assertSame($first_staff, $this->intent($order->get_id())->intent->actor_id());
    }

    public function test_a_staff_user_who_lost_the_right_before_the_recovery_stops_it_visibly(): void {
        $customer = $this->customer();
        $staff = $this->staff();
        $at = self::whole_second(self::at7('-10 minutes'));
        $this->wire($at, null, 0);
        $order = $this->order($customer);
        $this->held[] = EVC_Member_Lock::acquire($this->second_connection(), $customer, 0);
        $this->assertResult($this->complete($order->get_id(), $staff), EVC_Wc_Completion_Result::DEFERRED, 'member_locked');
        array_pop($this->held)->release();
        (new WP_User($staff))->set_role('subscriber'); // demoted before the recovery ran
        $done = $this->recover_at($at->modify('+5 minutes'));
        $this->assertContains($done[$order->get_id()]->outcome, array(EVC_Wc_Completion_Result::REFUSED, EVC_Wc_Completion_Result::CONFLICT, EVC_Wc_Completion_Result::MANAGER_REQUIRED), (string) $done[$order->get_id()]->reason);
        $this->assertStringStartsWith('manager:', (string) $this->followup($order->get_id()));
        $this->assertSame(array(), $this->rows($customer));
        $this->assertSame(array(), $this->club()->member_movements($customer));
        $this->assertCount(2, $this->notes($order->get_id()), 'visible on the order for the shop manager: the deferral, then the refusal');
    }

    public function test_the_add_on_is_put_back_at_shutdown_even_when_nothing_after_it_ran(): void {
        $c = $this->wire(self::whole_second(self::at7('-5 minutes')));
        $this->assertSame(0, has_action('shutdown', array($c, 'forget')));
        $order = $this->order($this->customer());
        remove_action('woocommerce_order_status_completed', array($c, 'after_add_on'), EVC_Wc_Completion_Confirmer::AFTER_ADDON_PRIORITY);
        $c->on_completed($order->get_id(), $order, array('from' => 'on-hold', 'to' => 'completed', 'note' => '', 'manual' => true));
        $this->assertFalse(has_action('woocommerce_order_status_completed', 'pmprowoo_add_membership_from_order'), 'withheld');
        $c->forget();
        $this->assertSame(10, has_action('woocommerce_order_status_completed', 'pmprowoo_add_membership_from_order'), 'put back, whatever happened in between');
        $c->forget();
        $this->assertSame(10, has_action('woocommerce_order_status_completed', 'pmprowoo_add_membership_from_order'));
    }

    public function test_a_completion_right_after_a_cancellation_the_club_did_not_see_is_not_a_confirmation(): void {
        $customer = $this->customer();
        $staff = $this->staff();
        $order = $this->order($customer);
        wc_get_order($order->get_id())->update_status('cancelled'); // before the Club path was hooked: no ended marker exists
        $this->wire(self::whole_second(self::at7('-5 minutes')));
        $this->assertFalse($this->parts['markers']->was_ended($order->get_id()));
        $r = $this->complete($order->get_id(), $staff);
        $this->assertResult($r, EVC_Wc_Completion_Result::MANAGER_REQUIRED, 'completed_after_cancellation_or_refund');
        $this->assertSame(array(), $this->rows($customer));
        $this->assertSame(array(), $this->club()->member_movements($customer));
    }

    // ---- re-review M-A: an order that ended or was refunded is never confirmed later ----------------------

    public function test_a_deferred_completion_cancelled_then_completed_again_is_never_confirmed(): void {
        $customer = $this->customer();
        $staff = $this->staff();
        $at = self::whole_second(self::at7('-10 minutes'));
        $this->wire($at, null, 0);
        $order = $this->order($customer);
        $this->held[] = EVC_Member_Lock::acquire($this->second_connection(), $customer, 0);
        $this->assertResult($this->complete($order->get_id(), $staff), EVC_Wc_Completion_Result::DEFERRED, 'member_locked');
        $this->assertSame('open:0', $this->followup($order->get_id()));
        array_pop($this->held)->release();
        $this->in_admin($staff, function () use ($order) {
            wc_get_order($order->get_id())->update_status('cancelled', '', true);
        });
        $this->assertSame('manager:ended_before_confirmation', $this->followup($order->get_id()), 'the open claim is closed for a manager, never finished');
        $this->in_admin($staff, function () use ($order) {
            wc_get_order($order->get_id())->update_status('on-hold', '', true);
        });
        $again = $this->complete($order->get_id(), $staff);
        $this->assertResult($again, EVC_Wc_Completion_Result::MANAGER_REQUIRED, 'completed_after_cancellation_or_refund');
        $this->assertSame('manager:completed_after_cancellation_or_refund', $this->followup($order->get_id()));
        $this->assertSame(array(), $this->recover_at($at->modify('+30 minutes')), 'nothing left for the recovery');
        $this->assertSame(array(), $this->rows($customer));
        $this->assertSame(array(), $this->club()->member_movements($customer), 'no month issued or recorded');
    }

    public function test_a_claim_whose_order_ended_while_the_club_database_was_down_is_never_confirmed(): void {
        $customer = $this->customer();
        $staff = $this->staff();
        $at = self::whole_second(self::at7('-10 minutes'));
        $this->wire($at, self::club_down());
        $order = $this->order($customer);
        $this->assertResult($this->complete($order->get_id(), $staff), EVC_Wc_Completion_Result::DEFERRED, 'club_unavailable');
        $this->in_admin($staff, function () use ($order) {
            wc_get_order($order->get_id())->update_status('cancelled', '', true); // correction deferred: the claim stays open
        });
        $this->assertSame('open:0', $this->followup($order->get_id()));
        $this->assertTrue($this->parts['markers']->was_ended($order->get_id()));

        // (a) still cancelled when the database is back: closed for a manager, nothing recorded.
        $done = $this->recover_at($at->modify('+5 minutes'));
        $this->assertResult($done[$order->get_id()], EVC_Wc_Completion_Result::MANAGER_REQUIRED, 'ended_before_confirmation');
        $this->assertSame('manager:ended_before_confirmation', $this->followup($order->get_id()));

        // (b) set back to completed without the Club seeing it (e.g. the constant was off for a moment): the recovery refuses it.
        $this->assertTrue($this->parts['markers']->set_followup($order->get_id(), 'open:0'));
        $this->parts['confirmer']->unregister();
        remove_action('woocommerce_order_status_completed', 'pmprowoo_add_membership_from_order', 10);
        $o = wc_get_order($order->get_id());
        $o->set_status('completed');
        $o->save();
        add_action('woocommerce_order_status_completed', 'pmprowoo_add_membership_from_order', 10, 1);
        $done = $this->recover_at($at->modify('+10 minutes'));
        $this->assertResult($done[$order->get_id()], EVC_Wc_Completion_Result::MANAGER_REQUIRED, 'completed_after_cancellation_or_refund');
        $this->assertSame('manager:completed_after_cancellation_or_refund', $this->followup($order->get_id()));
        $this->assertSame(array(), $this->rows($customer));
        $this->assertSame(array(), $this->club()->member_movements($customer));
    }

    public function test_a_partial_refund_of_a_deferred_completion_is_never_recorded_as_20_eur(): void {
        $customer = $this->customer();
        $staff = $this->staff();
        $at = self::whole_second(self::at7('-10 minutes'));
        $this->wire($at, null, 0);
        $order = $this->order($customer);
        $this->held[] = EVC_Member_Lock::acquire($this->second_connection(), $customer, 0);
        $this->assertResult($this->complete($order->get_id(), $staff), EVC_Wc_Completion_Result::DEFERRED, 'member_locked');
        $this->assertInstanceOf(WC_Order_Refund::class, wc_create_refund(array('order_id' => $order->get_id(), 'amount' => '5.00', 'reason' => 'synthetic')));
        $this->assertSame('completed', wc_get_order($order->get_id())->get_status());
        array_pop($this->held)->release();
        $done = $this->recover_at($at->modify('+5 minutes'));
        $this->assertResult($done[$order->get_id()], EVC_Wc_Completion_Result::MANAGER_REQUIRED, 'refunded_before_confirmation');
        $this->assertSame('manager:refunded_before_confirmation', $this->followup($order->get_id()));
        $this->assertSame(array(), $this->rows($customer));
        $this->assertSame(array(), $this->club()->member_movements($customer));
        $this->assertNotEmpty(array_filter($this->notes($order->get_id()), function ($n) {
            return strpos($n, 'refunded_before_confirmation') !== false;
        }));
    }

    // ---- re-review M-B: the add-on is put back on every path ---------------------------------------------

    public function test_a_later_callback_that_throws_never_leaves_the_add_on_removed_for_the_next_orders(): void {
        $this->setExpectedDeprecated('pmprommpu_addMembershipLevel');
        $staff = $this->staff();
        $this->wire(self::whole_second(self::at7('-5 minutes')));
        $first = $this->order($c1 = $this->customer());
        $second = $this->order($c2 = $this->customer());
        $product = new WC_Product_Simple();
        $product->set_regular_price('3');
        $product->set_virtual(true);
        $product->save();
        $ordinary = wc_create_order(array('customer_id' => $this->customer()));
        $ordinary->add_product($product, 1);
        $ordinary->calculate_totals();
        $ordinary->save();
        $boom = $first->get_id();
        add_action('woocommerce_order_status_completed', function ($id) use ($boom) {
            if ((int) $id === $boom) {
                throw new Exception('simulated failure of another plugin at priority 7');
            }
        }, 7);
        $seen = array();
        add_action('woocommerce_order_status_completed', function ($id) use (&$seen) {
            $seen[(int) $id] = has_action('woocommerce_order_status_completed', 'pmprowoo_add_membership_from_order');
        }, 9);
        $results = $this->in_admin($staff, function () use ($first, $second, $ordinary) {
            $out = array();
            wc_get_order($first->get_id())->update_status('completed', '', true); // WooCommerce catches the Exception: priority 11 never runs
            $out['first'] = $this->parts['confirmer']->last_result();
            $out['left_removed'] = has_action('woocommerce_order_status_completed', 'pmprowoo_add_membership_from_order');
            wc_get_order($ordinary->get_id())->update_status('completed', '', true);
            wc_get_order($second->get_id())->update_status('completed', '', true);
            $out['second'] = $this->parts['confirmer']->last_result();
            return $out;
        }, array($first->get_id(), $second->get_id(), $ordinary->get_id()));
        $this->assertResult($results['first'], EVC_Wc_Completion_Result::RECORDED);
        $this->assertFalse($results['left_removed'], 'the hold of the first order is left behind by the aborted hook run');
        $this->assertSame(10, $seen[$ordinary->get_id()], 'the next (non-Club) order gets the add-on as shipped');
        $this->assertResult($results['second'], EVC_Wc_Completion_Result::RECORDED);
        $this->assertSame(EVC_Wc_Completion_Marker::ADDON_SUPPRESSED, $results['second']->addon);
        $this->assertFalse($seen[$second->get_id()], 'withheld for the next Club order');
        $this->assertCount(1, $this->rows($c1));
        $this->assertCount(1, $this->rows($c2), 'one month, the Club\'s own');
        $this->assertSame(10, has_action('woocommerce_order_status_completed', 'pmprowoo_add_membership_from_order'));
    }

    public function test_a_completion_nested_inside_another_shares_the_withheld_add_on(): void {
        $this->setExpectedDeprecated('pmprommpu_addMembershipLevel');
        $staff = $this->staff();
        $this->wire(self::whole_second(self::at7('-5 minutes')));
        $a = $this->order($ca = $this->customer());
        $b = $this->order($cb = $this->customer());
        $first = null;
        $seen = array();
        add_action('woocommerce_order_status_completed', function ($id) use ($a, $b, &$first) {
            if ((int) $id === $a->get_id()) {
                $first = $this->parts['confirmer']->last_result();
                wc_get_order($b->get_id())->update_status('completed', '', true); // e.g. a plugin completing a linked order
            }
        }, 7);
        add_action('woocommerce_order_status_completed', function ($id) use (&$seen) {
            $seen[(int) $id] = has_action('woocommerce_order_status_completed', 'pmprowoo_add_membership_from_order');
        }, 9);
        $this->in_admin($staff, function () use ($a) {
            wc_get_order($a->get_id())->update_status('completed', '', true);
        }, array($a->get_id(), $b->get_id()));
        $this->assertResult($first, EVC_Wc_Completion_Result::RECORDED);
        $this->assertResult($this->parts['confirmer']->last_result(), EVC_Wc_Completion_Result::RECORDED);
        $this->assertSame(array($b->get_id() => false, $a->get_id() => false), $seen, 'withheld for both, also after the nested one ended');
        $this->assertCount(1, $this->rows($ca));
        $this->assertCount(1, $this->rows($cb));
        $this->assertSame(2, $this->parts['confirmer']->withheld_runs());
        $this->assertSame(10, has_action('woocommerce_order_status_completed', 'pmprowoo_add_membership_from_order'));
    }

    public function test_an_error_before_the_club_check_of_an_ordinary_order_gives_it_the_add_on_as_shipped(): void {
        $staff = $this->staff();
        $this->wire(self::whole_second(self::at7('-5 minutes')));
        $product = new WC_Product_Simple();
        $product->set_regular_price('3');
        $product->set_virtual(true);
        $product->save();
        $ordinary = wc_create_order(array('customer_id' => $this->customer()));
        $ordinary->add_product($product, 1);
        $ordinary->calculate_totals();
        $ordinary->save();
        $armed = false;
        add_action('woocommerce_order_status_completed', function () use (&$armed) {
            $armed = true;
        }, 4);
        add_filter('woocommerce_order_get_items', function ($items) use (&$armed) {
            if ($armed) {
                $armed = false;
                throw new Error('simulated fatal error while the Club reads the order');
            }
            return $items;
        });
        $seen = null;
        add_action('woocommerce_order_status_completed', function () use (&$seen) {
            $seen = has_action('woocommerce_order_status_completed', 'pmprowoo_add_membership_from_order');
        }, 9);
        $r = $this->complete($ordinary->get_id(), $staff);
        $this->assertResult($r, EVC_Wc_Completion_Result::UNAVAILABLE, 'unexpected_error');
        $this->assertSame(10, $seen, 'not a Club order: put back before the add-on\'s priority');
    }

    // ---- re-review L-B / L-D ------------------------------------------------------------------------------

    public function test_a_claim_that_loses_a_race_with_the_recorded_confirmation_is_not_a_false_alarm(): void {
        $this->setExpectedDeprecated('pmprommpu_addMembershipLevel');
        $customer = $this->customer();
        $staff = $this->staff();
        list($order, $r) = $this->purchase($customer, $staff, self::at7('-30 seconds'));
        $this->assertResult($r, EVC_Wc_Completion_Result::RECORDED);
        // What a double submit racing the marker write leaves behind: a second marker row.
        $o = wc_get_order($order->get_id());
        $o->add_meta_data(EVC_Wc_Completion_Markers::META_KEY, 'second-row-of-a-racing-request', false);
        $o->save();
        $this->in_admin($staff, function () use ($order) {
            wc_get_order($order->get_id())->update_status('on-hold', '', true);
        });
        $again = $this->complete($order->get_id(), $staff);
        $this->assertResult($again, EVC_Wc_Completion_Result::ALREADY_RECORDED);
        $this->assertNull($this->followup($order->get_id()), 'no manager flag for an order that is paid and recorded');
        $this->assertSame(array(), $this->notes($order->get_id()), 'no «not confirmed» note on it either');
        $this->assertCount(1, $this->periods($customer));
    }

    public function test_a_tripwire_that_cannot_read_the_rows_flags_a_manager(): void {
        $this->setExpectedDeprecated('pmprommpu_addMembershipLevel');
        $customer = $this->customer();
        $staff = $this->staff();
        $this->wire(self::whole_second(self::at7('-5 minutes')));
        $order = $this->order($customer);
        $GLOBALS['wpdb']->suppress_errors(true);
        add_filter('query', function ($sql) {
            return strpos($sql, "(enddate IS NULL OR enddate = '0000-00-00 00:00:00')") !== false ? 'SELECT COUNT(*) FROM evc_no_such_table_for_the_tripwire' : $sql;
        });
        $r = $this->complete($order->get_id(), $staff);
        remove_all_filters('query');
        $this->assertResult($r, EVC_Wc_Completion_Result::RECORDED);
        $this->assertSame('manager:membership_rows_unreadable', $this->followup($order->get_id()), 'a read error is not "no endless row"');
    }

    // ---- re-review test gaps: each lock check on its own, cancellation priority, REST depth --------------

    public function test_a_member_lock_lost_before_the_issuance_or_before_the_record_stops_there(): void {
        $staff = $this->staff();
        foreach (array('before_the_issuance' => 1, 'before_the_record' => 3) as $name => $call) {
            $customer = $this->customer();
            $at = self::whole_second(self::at7('-20 minutes'));
            $hooked = new EVC_Hooked_Provenance_Store($this->club());
            $hooked->before_call('corrections_for', $call, function () use ($customer) {
                $db = $GLOBALS['wpdb'];
                $db->get_var($db->prepare('SELECT RELEASE_LOCK(%s)', EVC_Member_Lock::name_for($db, $customer)));
            });
            $this->wire($at, function () use ($hooked) {
                return $hooked;
            });
            $order = $this->order($customer);
            $r = $this->complete($order->get_id(), $staff);
            $this->assertSame(EVC_Wc_Completion_Result::DEFERRED, $r->outcome, $name . ' ' . (string) $r->reason . ' ' . implode(',', $r->steps));
            $this->assertSame('lock_lost', $r->reason, $name);
            $this->assertCount($name === 'before_the_issuance' ? 0 : 1, $this->rows($customer), $name . ': nothing issued without the lock');
            $this->assertCount(0, $this->periods($customer), $name . ': nothing recorded without the lock');
            $this->assertGuardInert();
            $done = $this->recover_at($at->modify('+1 minute'));
            $this->assertResult($done[$order->get_id()], EVC_Wc_Completion_Result::RECORDED);
            $this->assertCount(1, $this->rows($customer), $name);
            $this->assertCount(1, $this->periods($customer), $name);
        }
        $this->setExpectedDeprecated('pmprommpu_addMembershipLevel');
    }

    public function test_a_cancellation_is_recorded_even_when_a_later_cancellation_handler_fails(): void {
        $this->setExpectedDeprecated('pmprommpu_addMembershipLevel');
        $customer = $this->customer();
        $staff = $this->staff();
        list($order, $r) = $this->purchase($customer, $staff, self::at7('-30 seconds'));
        $this->assertResult($r, EVC_Wc_Completion_Result::RECORDED);
        add_action('woocommerce_order_status_cancelled', function () {
            throw new Exception('simulated failure of another cancellation handler');
        }, 10);
        $this->in_admin($staff, function () use ($order) {
            wc_get_order($order->get_id())->update_status('cancelled', '', true); // WooCommerce catches it
        });
        $corrections = $this->club()->corrections_for($this->intent($order->get_id())->intent->movement_id());
        $this->assertCount(1, $corrections, 'the Club runs before the other handlers of the cancellation');
        $this->assertSame(EVC_Movement_Correction::KIND_VOIDED, $corrections[0]->kind());
        $this->assertNotSame('active', $this->me($customer)['state']);
    }

    public function test_the_rest_depth_survives_nested_requests_and_an_exception_fails_closed_until_the_request_ends(): void {
        $this->setExpectedDeprecated('pmprommpu_addMembershipLevel');
        $staff = $this->staff();
        $this->wire(self::whole_second(self::at7('-5 minutes')));
        rest_get_server();
        register_rest_route('evc-test/v1', '/nested/(?P<id>\d+)', array('methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => function ($request) {
            $inner = new WP_REST_Request('PUT', '/wc/v3/orders/' . (int) $request['id']);
            $inner->set_param('status', 'completed');
            return rest_do_request($inner);
        }));
        register_rest_route('evc-test/v1', '/boom', array('methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => function () {
            throw new RuntimeException('simulated exception inside a REST callback');
        }));
        // Nested: the inner completion is REST; after both ended the depth is back to zero.
        $inner = $this->order($ci = $this->customer());
        wp_set_current_user($staff);
        $response = rest_do_request(new WP_REST_Request('POST', '/evc-test/v1/nested/' . $inner->get_id()));
        wp_set_current_user(0);
        $this->assertSame(200, $response->get_status(), wp_json_encode($response->get_data()));
        $this->assertResult($this->parts['confirmer']->last_result(), EVC_Wc_Completion_Result::NOT_ATTESTED, 'channel_rest');
        $this->assertSame(array(), $this->rows($ci));
        $after = $this->order($ca = $this->customer());
        $this->assertResult($this->complete($after->get_id(), $staff), EVC_Wc_Completion_Result::RECORDED, null);
        // An exception escapes a REST callback: the rest of THAT request stays REST (fail closed) ...
        wp_set_current_user($staff);
        try {
            rest_do_request(new WP_REST_Request('POST', '/evc-test/v1/boom'));
            $this->fail('the exception was expected to escape rest_do_request()');
        } catch (RuntimeException $e) {
            $this->assertSame('simulated exception inside a REST callback', $e->getMessage());
        }
        $leaked = $this->order($cl = $this->customer());
        $r = $this->in_admin($staff, function () use ($leaked) {
            $this->verify_referer(self::edit_screen_nonce($leaked->get_id()));
            wc_get_order($leaked->get_id())->update_status('completed', '', true);
            return $this->parts['confirmer']->last_result();
        }); // in_admin ends the request (the confirmer's `shutdown` handler)
        $this->assertResult($r, EVC_Wc_Completion_Result::NOT_ATTESTED, 'channel_rest');
        $this->assertSame(array(), $this->rows($cl));
        // ... and the next request is attested again.
        $next = $this->order($cn = $this->customer());
        $this->assertResult($this->complete($next->get_id(), $staff), EVC_Wc_Completion_Result::RECORDED);
        $this->assertCount(1, $this->rows($cn));
    }
}
