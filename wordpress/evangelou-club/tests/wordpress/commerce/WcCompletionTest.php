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
        $GLOBALS['wpdb']->suppress_errors(false);
        wp_set_current_user(0);
        parent::tear_down();
    }

    // ---- helpers -------------------------------------------------------------------------------

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

    /** The shop user sets the order to «Ολοκληρωμένη» (WooCommerce's own status change). */
    private function complete(int $order_id, int $actor): ?EVC_Wc_Completion_Result {
        wp_set_current_user($actor);
        wc_get_order($order_id)->update_status('completed');
        wp_set_current_user(0);
        return $this->parts['confirmer']->last_result();
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

    // ---- the constant (OFF by default) ---------------------------------------------------------

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
        $this->assertNotFalse(has_filter('pmpro_change_level', array($c, 'suppress_add_on')));
        foreach (array('cancelled', 'refunded', 'failed') as $status) {
            $this->assertLessThan(10, has_action('woocommerce_order_status_' . $status, array($c, 'on_ended')), 'before the add-on\'s own cancellation');
        }
        $this->assertNotFalse(has_action('woocommerce_order_refunded', array($c, 'on_refund')));
        $c->unregister();
        $this->assertFalse(has_action('woocommerce_order_status_completed', array($c, 'on_completed')));
        $this->assertFalse(has_filter('pmpro_change_level', array($c, 'suppress_add_on')));
        $this->assertSame(array('interval' => 900, 'display' => 'Evangelou Club: every 15 minutes'), EVC_Wc_Completion_Plugin::schedules(array())[EVC_Wc_Completion_Plugin::CRON_SCHEDULE]);
        $this->parts = null;
    }

    // ---- confirmations ---------------------------------------------------------------------------

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
        $this->assertSame(1, $this->parts['confirmer']->suppressed_runs(), 'the add-on\'s own run was suppressed');
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
        $this->assertSame(EVC_Wc_Completion_Marker_Read::MISSING, $this->marker($order->get_id())->outcome, 'nothing deferred');
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
        $this->assertSame(1, count(array_keys($writes, 'INSERT')), 'one new row: the add-on\'s own run was suppressed');
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

    // ---- idempotency ---------------------------------------------------------------------------

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
        wp_set_current_user($staff);
        $a->update_status('completed');
        $first = $this->parts['confirmer']->last_result();
        $b->update_status('completed');
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
        wp_set_current_user($staff);
        $results = array();
        foreach (array($o1, $o2) as $o) {
            wc_get_order($o->get_id())->update_status('completed');
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
            wc_get_order($o->get_id())->update_status('completed');
            $results[] = $this->parts['confirmer']->last_result();
        }
        wp_set_current_user(0);
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

    // ---- who completed it ------------------------------------------------------------------------

    public function test_completion_by_nobody_the_customer_the_club_account_or_without_capability_is_not_a_confirmation(): void {
        $this->setExpectedDeprecated('pmprommpu_addMembershipLevel');
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
            $this->assertNull($r->addon, 'not claimed: the add-on behaves as shipped');
            $rows = $this->rows($customer);
            $this->assertCount(1, $rows, $reason);
            $this->assertAddOnRow($rows[0]);
            $this->assertSame(EVC_Bacs_Intent_Read::MISSING, $this->intent($order->get_id())->outcome);
            $this->assertSame(EVC_Wc_Completion_Marker_Read::MISSING, $this->marker($order->get_id())->outcome);
            $this->assertSame(array(), $this->club()->member_movements($customer));
            $this->assertSame('unverified', $this->me($customer)['state'], $reason);
            $events = $this->audit_events(EVC_Wc_Completion_Confirmer::EVENT, $this->config->ref('wc_order', (string) $order->get_id()));
            $this->assertCount(1, $events);
            $this->assertSame('not_attested', $events[0]['outcome']);
            $this->assertNull($events[0]['staff_wp_user_id']);
        }
        $this->assertSame(0, $this->parts['confirmer']->suppressed_runs());
    }

    // ---- deferred completions and the recovery step -------------------------------------------------

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
        $this->assertSame(EVC_Wc_Completion_Marker::ADDON_SHIPPED, $r->addon, 'no active Club row: the add-on ran as shipped');
        $rows = $this->rows($customer);
        $this->assertCount(1, $rows);
        $this->assertAddOnRow($rows[0]);
        $marker = $this->marker($order->get_id());
        $this->assertSame(EVC_Wc_Completion_Marker_Read::VALID, $marker->outcome, (string) $marker->reason);
        $this->assertSame('member_locked', $marker->marker->reason());
        $this->assertSame(EVC_Wc_Completion_Marker::ADDON_SHIPPED, $marker->marker->addon());
        $this->assertLessThan((int) $rows[0]['id'], $marker->marker->row_floor());
        $this->assertSame('open:0', $this->followup($order->get_id()));
        $this->assertSame('unverified', $this->me($customer)['state'], 'fail closed until confirmed');

        // Still busy: one more attempt counted.
        $again = $this->recover_at($at->modify('+1 minute'));
        $this->assertResult($again[$order->get_id()], EVC_Wc_Completion_Result::DEFERRED, 'member_locked');
        $this->assertSame('open:1', $this->followup($order->get_id()));

        array_pop($this->held)->release();
        $later = $at->modify('+5 minutes');
        $done = $this->recover_at($later);
        $this->assertResult($done[$order->get_id()], EVC_Wc_Completion_Result::RECORDED);
        $this->assertSame(array('planned', 'issue', 'issued', 'record'), $done[$order->get_id()]->steps);
        $this->assertNull($this->followup($order->get_id()));
        $rows = $this->rows($customer);
        $this->assertCount(2, $rows);
        $this->assertSame('changed', $rows[0]['status'], 'the add-on\'s own row was replaced');
        $this->assertSame(self::local($later), $rows[1]['startdate'], 'confirmed at the recovery instant');
        $this->assertSame(self::local(EVC_Membership_Calendar::add_calendar_month($later)), $rows[1]['enddate']);
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
        $this->assertSame(array(), $writes, 'the add-on\'s own run was suppressed: the paid row stays as it is');
        $this->assertSame(1, $this->parts['confirmer']->suppressed_runs());
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
        $at = self::whole_second(self::at7('-2 minutes'));
        $this->wire($at, null, 0);
        $order = $this->order($customer);
        $this->held[] = EVC_Member_Lock::acquire($this->second_connection(), $customer, 0);
        $this->assertResult($this->complete($order->get_id(), $this->staff()), EVC_Wc_Completion_Result::DEFERRED, 'member_locked');
        $this->assertTrue($this->parts['markers']->set_followup($order->get_id(), 'open:' . (EVC_Wc_Completion_Confirmer::MAX_ATTEMPTS - 1)));
        $r = $this->recover_at($at->modify('+1 minute'))[$order->get_id()];
        $this->assertResult($r, EVC_Wc_Completion_Result::DEFERRED, 'member_locked');
        $this->assertSame('manager:attempts_exhausted', $this->followup($order->get_id()));
        $this->assertSame(array(), $this->parts['recovery']->run(), 'a manager must look now');
    }

    // ---- cancellation / refund (owner decision 2) --------------------------------------------------

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
        $this->assertNull($again->addon, 'not claimed');
        $this->assertCount(1, $this->periods($customer), 'nothing recorded again');
        $this->assertNotSame('active', $this->me($customer)['state'], 'the card stays frozen');

        // Never recorded, cancelled, put back on hold, then completed: still not a confirmation.
        $other = $this->customer();
        $o = $this->order($other);
        wp_set_current_user($staff);
        wc_get_order($o->get_id())->update_status('cancelled');
        wc_get_order($o->get_id())->update_status('on-hold');
        $flagged = $this->complete($o->get_id(), $staff);
        $this->assertResult($flagged, EVC_Wc_Completion_Result::MANAGER_REQUIRED, 'completed_after_cancellation_or_refund');
        $this->assertSame(array(), $this->club()->member_movements($other));
        $this->assertSame('unverified', $this->me($other)['state']);
        $events = $this->audit_events(EVC_Wc_Completion_Confirmer::EVENT, $this->config->ref('wc_order', (string) $o->get_id()));
        $this->assertSame(array('manager_required'), array_column($events, 'outcome'));
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

    // ---- exceptions never abort WooCommerce ---------------------------------------------------------

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
        $rows = $this->rows($customer);
        $this->assertCount(1, $rows, 'the add-on still ran (no active Club row: as shipped)');
        $this->assertAddOnRow($rows[0]);
        $this->assertGuardInert();
        $this->assertSame('open:0', $this->followup($order->get_id()));
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

    public function test_an_exception_during_an_early_renewal_still_suppresses_the_add_on(): void {
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
        $this->assertSame(array(), $writes, 'suppressed as designed: no month from the add-on');
        $this->assertSame(EVC_Membership_Calendar::add_calendar_month($first_at)->format(DATE_ATOM), $this->me($customer)['paid_until_utc']);
    }

    public function test_a_renewal_completed_by_nobody_lets_the_add_on_run_as_shipped(): void {
        $this->setExpectedDeprecated('pmprommpu_addMembershipLevel');
        $customer = $this->customer();
        list(, $first) = $this->purchase($customer, $this->staff(), self::at7('-10 days'));
        $this->assertResult($first, EVC_Wc_Completion_Result::RECORDED);
        $order = $this->order($customer);
        $before = $this->parts['confirmer']->suppressed_runs();
        $r = $this->complete($order->get_id(), 0); // e.g. a REST API key or an automation
        $this->assertResult($r, EVC_Wc_Completion_Result::NOT_ATTESTED, 'no_user');
        $this->assertSame($before, $this->parts['confirmer']->suppressed_runs(), 'never suppressed when not claimed');
        $rows = $this->rows($customer);
        $this->assertCount(2, $rows, 'the add-on extended the membership exactly as shipped');
        $this->assertAddOnRow($rows[1]);
        $this->assertCount(1, $this->periods($customer), 'nothing recorded for it');
        $this->assertSame('unverified', $this->me($customer)['state']);
    }

    public function test_a_deferred_completion_whose_add_on_row_vanished_stops_for_a_manager(): void {
        $this->setExpectedDeprecated('pmprommpu_addMembershipLevel');
        $customer = $this->customer();
        $at = self::whole_second(self::at7('-10 minutes'));
        $this->wire($at, null, 0);
        $order = $this->order($customer);
        $this->held[] = EVC_Member_Lock::acquire($this->second_connection(), $customer, 0);
        $r = $this->complete($order->get_id(), $this->staff());
        $this->assertResult($r, EVC_Wc_Completion_Result::DEFERRED, 'member_locked');
        $this->assertSame(EVC_Wc_Completion_Marker::ADDON_SHIPPED, $r->addon);
        array_pop($this->held)->release();
        pmpro_cancelMembershipLevel($this->config->level_id, $customer, 'admin_cancelled'); // someone removed the add-on's row
        $done = $this->recover_at($at->modify('+1 minute'));
        $this->assertResult($done[$order->get_id()], EVC_Wc_Completion_Result::MANAGER_REQUIRED, 'membership_row_missing');
        $this->assertSame('manager:membership_row_missing', $this->followup($order->get_id()));
        $this->assertSame(array(), $this->active_rows($customer), 'never re-issued when the add-on did issue for this completion');
        $this->assertSame(array(), $this->club()->member_movements($customer));
    }

    public function test_an_add_on_call_for_another_order_is_never_suppressed(): void {
        $this->setExpectedDeprecated('pmprommpu_addMembershipLevel');
        $customer = $this->customer();
        $staff = $this->staff();
        $this->wire(self::whole_second(self::at7('-30 seconds')));
        $order = $this->order($customer);
        $other = $this->order($customer);
        $confirmer = $this->parts['confirmer'];
        $probe = array();
        // Another plugin issues the customer's OTHER Club order between the Club (5) and the add-on (10).
        add_action('woocommerce_order_status_completed', function ($order_id) use ($order, $other, $confirmer, &$probe) {
            if ((int) $order_id === (int) $order->get_id() && $probe === array()) {
                $probe['before'] = $confirmer->suppressed_runs();
                pmprowoo_add_membership_from_order($other->get_id());
                $probe['after'] = $confirmer->suppressed_runs();
            }
        }, 9);
        $r = $this->complete($order->get_id(), $staff);
        $this->assertResult($r, EVC_Wc_Completion_Result::RECORDED);
        $this->assertSame($probe['before'], $probe['after'], 'the other order\'s issuance was not suppressed');
        $this->assertSame(1, $confirmer->suppressed_runs(), 'the add-on\'s own run for THIS order was');
        $this->assertCount(2, $this->rows($customer), 'the Club\'s row + the other order\'s add-on row; none for the add-on\'s run of this order');
    }

    public function test_an_error_before_the_completion_is_claimed_never_escapes_into_woocommerce(): void {
        $this->setExpectedDeprecated('pmprommpu_addMembershipLevel');
        $customer = $this->customer();
        $this->wire(self::whole_second(self::at7('-30 seconds')));
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
            $r = $this->complete($order->get_id(), $this->staff());
        } catch (Throwable $e) {
            $this->fail('an error escaped into WooCommerce: ' . get_class($e));
        }
        $this->assertResult($r, EVC_Wc_Completion_Result::UNAVAILABLE, 'unexpected_error');
        $this->assertSame('completed', wc_get_order($order->get_id())->get_status());
        $rows = $this->rows($customer);
        $this->assertCount(1, $rows, 'the add-on still ran');
        $this->assertAddOnRow($rows[0]);
        $this->assertSame(0, $this->parts['confirmer']->suppressed_runs());
        $this->assertGuardInert();
    }

    // ---- the suppression itself --------------------------------------------------------------------

    public function test_the_add_on_is_never_suppressed_once_the_customer_has_no_active_club_row(): void {
        $this->setExpectedDeprecated('pmprommpu_addMembershipLevel');
        $customer = $this->customer();
        $staff = $this->staff();
        $this->wire(self::whole_second(self::at7('-30 seconds')));
        $order = $this->order($customer);
        // Between the Club's confirmation (5) and the add-on (10) something ends the active row.
        add_action('woocommerce_order_status_completed', function () use ($customer) {
            pmpro_cancelMembershipLevel($this->config->level_id, $customer, 'admin_cancelled');
        }, 9);
        $r = $this->complete($order->get_id(), $staff);
        $this->assertResult($r, EVC_Wc_Completion_Result::RECORDED);
        $this->assertSame(EVC_Wc_Completion_Marker::ADDON_SUPPRESSED, $r->addon, 'armed while the row was active');
        $this->assertSame(0, $this->parts['confirmer']->suppressed_runs(), 'not applied: no active row any more');
        $rows = $this->rows($customer);
        $this->assertCount(2, $rows);
        $this->assertAddOnRow($rows[1]); // the add-on's own dates, never PMPro's endless integer-level row
    }

    public function test_the_suppression_applies_only_to_the_add_on_run_of_that_order_and_customer(): void {
        global $wpdb;
        $c = $this->wire(new DateTimeImmutable('now'));
        $customer = $this->customer();
        // An active Club row exists, so only the scoping below decides.
        $wpdb->insert($wpdb->pmpro_memberships_users, array('user_id' => $customer, 'membership_id' => $this->config->level_id, 'status' => 'active', 'startdate' => '2026-10-01 10:00:00', 'enddate' => '2026-11-01 10:00:00'));
        $state = new ReflectionProperty(EVC_Wc_Completion_Confirmer::class, 'suppress');
        $state->setAccessible(true);
        $level = array('user_id' => $customer, 'membership_id' => (string) $this->config->level_id, 'startdate' => "'2026-01-01 00:00:00'", 'enddate' => '2026-02-01 00:00:00');
        $state->setValue($c, array(501 => array($customer, $this->config->level_id)));
        $this->assertSame($level, $c->suppress_add_on($level, $customer), 'not called from the add-on\'s own run');
        $this->assertSame(array(501 => array($customer, $this->config->level_id)), $state->getValue($c), 'still armed for the add-on\'s run');
        $c->after_add_on(502);
        $this->assertSame(array(501 => array($customer, $this->config->level_id)), $state->getValue($c), 'another order\'s run ends nothing');
        $c->after_add_on(501);
        $this->assertSame(array(), $state->getValue($c), 'cleared after the add-on\'s run of that order');
        $state->setValue($c, array(501 => array($customer, $this->config->level_id)));
        $c->forget();
        $this->assertSame(array(), $state->getValue($c), 'nothing survives the request');
        $this->assertSame(0, $c->suppressed_runs());
    }

    // ---- the add-on's row of a deferred completion ---------------------------------------------------

    public function test_only_a_row_newer_than_the_completion_and_unpaid_is_taken_for_the_add_ons_own(): void {
        global $wpdb;
        $c = $this->wire(new DateTimeImmutable('now'));
        $customer = $this->customer();
        $wpdb->insert($wpdb->pmpro_memberships_users, array('user_id' => $customer, 'membership_id' => $this->config->level_id, 'status' => 'active', 'startdate' => '2026-10-01 10:00:00', 'enddate' => '2026-11-01 10:00:00'));
        $id = (int) $wpdb->insert_id;
        $method = new ReflectionMethod(EVC_Wc_Completion_Confirmer::class, 'addon_row');
        $method->setAccessible(true);
        $this->assertNull($method->invoke($c, $customer, $id, $this->club()), 'not newer than the rows that existed at the completion');
        $this->assertSame($this->config->ref('pmpro_membership_row', (string) $id), $method->invoke($c, $customer, $id - 1, $this->club()));
        $wpdb->insert($wpdb->pmpro_memberships_users, array('user_id' => $customer, 'membership_id' => $this->config->level_id, 'status' => 'active', 'startdate' => '2026-10-01 10:00:00', 'enddate' => '2026-11-01 10:00:00'));
        $this->assertNull($method->invoke($c, $customer, $id - 1, $this->club()), 'two active rows are never resolved by guessing');
    }
}
