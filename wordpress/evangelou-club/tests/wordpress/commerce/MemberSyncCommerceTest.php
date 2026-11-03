<?php

/**
 * STAGE-05B: a recorded Club payment confirmation gives the paying customer exactly
 * one Club member row (evc_members), over the REAL WooCommerce 11.2.1 / PMPro 3.8.9 /
 * PMPro WooCommerce 1.11 (HPOS or legacy storage) and the disposable Club database.
 * CI only.
 *
 * The WooCommerce-completion path is built through its bootstrap factory
 * (EVC_Wc_Completion_Plugin::build, the only place that builds EVC_Club_Member_Sync)
 * with a fixed clock in 2030, so the recovery run's reconcile scan (customers whose
 * latest recorded paid period has not ended and who have no member row) sees only the
 * customers of this file: earlier test files recorded periods that end long before.
 * Nothing here is a real payment.
 */
final class MemberSyncCommerceTest extends EVC_WP_Test_Case {
    /** A fixed instant at :07 seconds (never on the add-on's minute-rounded :00), no DST change nearby. */
    const BASE = '2030-03-12 10:00:07';

    /** @var EVC_Commerce_Source_Config */
    private $config;
    /** @var array<string,mixed>|null */
    private $parts;
    /** @var EVC_Club_Db */
    private $club;
    /** @var EVC_Bacs_Issuance_Guard|null */
    private $guard;

    public function set_up() {
        parent::set_up();
        evc_test_commerce_configure();
        $this->config = EVC_Commerce_Source_Config::from_constants();
        $this->club = EVC_Club_Db::connect(EVC_Db_Config::from_constants());
    }

    public function tear_down() {
        if ($this->parts !== null) {
            $this->parts['confirmer']->unregister();
            $this->parts['guard']->disarm();
        }
        if ($this->guard !== null) {
            $this->guard->disarm();
        }
        unset($_REQUEST['_wpnonce'], $GLOBALS['current_screen']);
        wp_set_current_user(0);
        parent::tear_down();
    }

    // ---- helpers ----------------------------------------------------------------------------------

    private static function utc(string $s): DateTimeImmutable {
        return new DateTimeImmutable($s, new DateTimeZone('UTC'));
    }

    private static function clock(DateTimeImmutable $at): callable {
        return function () use ($at) {
            return $at;
        };
    }

    private static function members_down(): callable {
        return function () {
            throw new EVC_Db_Exception('simulated: Club database unreachable for member rows', 'HY000', 2002);
        };
    }

    private function wire(DateTimeImmutable $now, ?callable $open_members_db = null): array {
        if ($this->parts !== null) {
            $this->parts['confirmer']->unregister();
        }
        $this->parts = EVC_Wc_Completion_Plugin::build($this->config, EVC_Db_Config::from_constants(), self::clock($now), null, 1, $open_members_db);
        $this->assertNotNull($this->parts);
        $this->assertInstanceOf(EVC_Club_Member_Sync::class, $this->parts['members']);
        $this->parts['confirmer']->register();
        return $this->parts;
    }

    private function customer(string $role = 'customer'): int {
        return (int) self::factory()->user->create(array('role' => $role, 'user_email' => 'club.' . wp_generate_password(6, false, false) . '@example.org', 'display_name' => 'Synthetic Customer'));
    }

    private function staff(): int {
        return (int) self::factory()->user->create(array('role' => 'shop_manager', 'display_name' => 'Synthetic Shop Staff'));
    }

    private function order(int $user_id, DateTimeImmutable $created, string $status = 'on-hold'): WC_Order {
        $order = wc_create_order(array('customer_id' => $user_id));
        $order->add_product(wc_get_product(EVC_TEST_CLUB_PRODUCT), 1);
        $order->set_payment_method('bacs');
        $order->set_currency('EUR');
        $order->set_billing_email('club.billing@example.org');
        $order->set_date_created($created->getTimestamp());
        $order->calculate_totals();
        $order->save();
        if ($status !== 'pending') {
            $order->update_status($status);
        }
        return wc_get_order($order->get_id());
    }

    /** The shop user sets the order to «Ολοκληρωμένη» on the WooCommerce order edit screen. */
    private function complete(int $order_id, int $actor): EVC_Wc_Completion_Result {
        wp_set_current_user($actor);
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
            wp_set_current_user(0);
            $this->parts['confirmer']->forget();
        }
        $r = $this->parts['confirmer']->last_result();
        $this->assertNotNull($r);
        return $r;
    }

    /** @return array{0:WC_Order,1:EVC_Wc_Completion_Result} a Club order completed by shop staff at $at */
    private function purchase(int $customer, int $staff, DateTimeImmutable $at, ?callable $open_members_db = null): array {
        $this->wire($at, $open_members_db);
        $order = $this->order($customer, $at->modify('-1 hour'));
        $r = $this->complete($order->get_id(), $staff);
        return array(wc_get_order($order->get_id()), $r);
    }

    /** @return array<int,array<string,string>> the customer's evc_members rows */
    private function members(int $wp_user_id): array {
        return $this->club->fetch_all('SELECT id, member_public_id, wp_user_id, status FROM evc_members WHERE wp_user_id = ? ORDER BY id', array($wp_user_id));
    }

    private function sync_events(?int $member_id, ?int $customer = null): array {
        if ($member_id !== null) {
            return $this->club->fetch_all('SELECT outcome, member_id, staff_wp_user_id, details_json FROM evc_audit_events WHERE event_type = ? AND member_id = ? ORDER BY id', array(EVC_Club_Member_Sync::EVENT, $member_id));
        }
        return $this->club->fetch_all('SELECT outcome, member_id, staff_wp_user_id, details_json FROM evc_audit_events WHERE event_type = ? AND details_json LIKE ? ORDER BY id', array(EVC_Club_Member_Sync::EVENT, '%"customer":' . $customer . '}%'));
    }

    private function movements(int $wp_user_id): array {
        $db = EVC_Db_Config::from_constants();
        $store = new EVC_Sql_Provenance_Store(EVC_Club_Db::connect($db), function () use ($db) {
            return EVC_Club_Db::connect($db);
        }, 'live');
        return $store->member_movements($wp_user_id);
    }

    private function assertOutcome(EVC_Wc_Completion_Result $r, string $outcome): void {
        $this->assertSame($outcome, $r->outcome, 'reason: ' . (string) $r->reason . ' steps: ' . implode(',', $r->steps));
    }

    // ---- confirmation → one member row ------------------------------------------------------------

    public function test_a_recorded_completion_gives_the_customer_exactly_one_enabled_member_row(): void {
        $this->setExpectedDeprecated('pmprommpu_addMembershipLevel');
        $customer = $this->customer();
        $staff = $this->staff();
        list($order, $r) = $this->purchase($customer, $staff, self::utc(self::BASE));

        $this->assertOutcome($r, EVC_Wc_Completion_Result::RECORDED);
        $this->assertNull($r->reason, 'the confirmation itself is unchanged');
        $this->assertSame(array('planned', 'issue', 'issued', 'record'), $r->steps);
        $this->assertNull($this->parts['markers']->followup($order->get_id()), 'follow-up closed as before');
        $this->assertSame(EVC_Member_Sync_Result::PROVISIONED, $r->member);

        $rows = $this->members($customer);
        $this->assertCount(1, $rows);
        $this->assertSame('enabled', $rows[0]['status']);
        $this->assertTrue(EVC_Member_Id::is_valid_format($rows[0]['member_public_id']));
        $found = (new EVC_Redemption_Store($this->club))->find_member_by_public_id($rows[0]['member_public_id']);
        $this->assertSame($customer, $found['wp_user_id']);

        $events = $this->sync_events((int) $rows[0]['id']);
        $this->assertCount(1, $events);
        $this->assertSame(EVC_Member_Sync_Result::PROVISIONED, $events[0]['outcome']);
        $this->assertNull($events[0]['staff_wp_user_id']);
        $this->assertSame(array('trigger' => 'confirmation'), json_decode($events[0]['details_json'], true));
        $user = get_userdata($customer);
        foreach (array($user->user_email, $user->display_name, 'club.billing@example.org', EVC_CLUB_EVIDENCE_KEY) as $secret) {
            $this->assertStringNotContainsString($secret, $events[0]['details_json']);
        }
    }

    public function test_replays_a_double_submit_and_an_early_renewal_keep_exactly_one_row(): void {
        $this->setExpectedDeprecated('pmprommpu_addMembershipLevel');
        $customer = $this->customer();
        $staff = $this->staff();
        $at = self::utc(self::BASE)->modify('+1 day');
        list($order, $first) = $this->purchase($customer, $staff, $at);
        $this->assertSame(EVC_Member_Sync_Result::PROVISIONED, $first->member);
        $id = $this->members($customer)[0]['member_public_id'];

        // The same order completed again (after being put back on hold): already recorded, same row.
        wp_set_current_user($staff);
        wc_get_order($order->get_id())->update_status('on-hold');
        $again = $this->complete($order->get_id(), $this->staff());
        $this->assertOutcome($again, EVC_Wc_Completion_Result::ALREADY_RECORDED);
        $this->assertSame(EVC_Member_Sync_Result::ALREADY_MEMBER, $again->member);

        // Direct replays of the member step.
        $this->assertSame(EVC_Member_Sync_Result::ALREADY_MEMBER, $this->parts['members']->after_payment($customer));
        $this->assertSame(EVC_Member_Sync_Result::ALREADY_MEMBER, $this->wire($at)['members']->after_payment($customer), 'a second instance, its own connection');

        // Early renewal three days later.
        list(, $renewal) = $this->purchase($customer, $staff, $at->modify('+3 days'));
        $this->assertOutcome($renewal, EVC_Wc_Completion_Result::RECORDED);
        $this->assertSame(EVC_Member_Sync_Result::ALREADY_MEMBER, $renewal->member);

        $rows = $this->members($customer);
        $this->assertCount(1, $rows);
        $this->assertSame($id, $rows[0]['member_public_id'], 'the member id never changes');
        $this->parts['recovery']->run();
        $this->assertArrayNotHasKey($customer, $this->parts['recovery']->last_members(), 'the recovery run has nothing to do for this customer');
    }

    // ---- failure isolation + recovery ------------------------------------------------------------

    public function test_a_member_row_failure_never_touches_the_recorded_payment_and_the_recovery_run_creates_it(): void {
        $this->setExpectedDeprecated('pmprommpu_addMembershipLevel');
        $customer = $this->customer();
        $staff = $this->staff();
        $at = self::utc(self::BASE)->modify('+2 days');
        list($order, $r) = $this->purchase($customer, $staff, $at, self::members_down());

        $this->assertOutcome($r, EVC_Wc_Completion_Result::RECORDED);
        $this->assertNull($r->reason);
        $this->assertSame(EVC_Club_Member_Sync::UNAVAILABLE, $r->member);
        $this->assertNull($this->parts['markers']->followup($order->get_id()), 'the confirmation is final; nothing reopened');
        $this->assertSame(EVC_Bacs_Intent::STATE_RECORDED, $this->parts['intents']->read($order->get_id())->intent->state());
        $this->assertCount(1, $this->movements($customer), 'the payment stays recorded');
        $this->assertSame('completed', wc_get_order($order->get_id())->get_status());
        $this->assertSame(array(), $this->members($customer));

        // A recovery run after the paid period ended never selects the customer (nothing to be a member for).
        $this->wire($at->modify('+40 days'));
        $this->parts['recovery']->run();
        $this->assertArrayNotHasKey($customer, $this->parts['recovery']->last_members());
        $this->assertSame(array(), $this->members($customer));

        // Still down at the next recovery run: nothing changes, nothing is guessed.
        $this->wire($at->modify('+15 minutes'), self::members_down());
        $this->parts['recovery']->run();
        $this->assertSame(array(), $this->parts['recovery']->last_members());
        $this->assertSame(array(), $this->members($customer));

        // Back: the recovery run creates the row.
        $this->wire($at->modify('+30 minutes'));
        $this->parts['recovery']->run();
        $this->assertSame(EVC_Member_Sync_Result::PROVISIONED, $this->parts['recovery']->last_members()[$customer] ?? null);
        $rows = $this->members($customer);
        $this->assertCount(1, $rows);
        $events = $this->sync_events((int) $rows[0]['id']);
        $this->assertSame(array('trigger' => 'reconcile'), json_decode(end($events)['details_json'], true));

        // And never twice.
        $this->parts['recovery']->run();
        $this->assertArrayNotHasKey($customer, $this->parts['recovery']->last_members());
        $this->assertCount(1, $this->members($customer));
    }

    public function test_a_confirmation_and_two_recovery_runs_for_the_same_customer_leave_one_row(): void {
        $this->setExpectedDeprecated('pmprommpu_addMembershipLevel');
        $customer = $this->customer();
        $staff = $this->staff();
        $at = self::utc(self::BASE)->modify('+3 days');
        list(, $r) = $this->purchase($customer, $staff, $at, self::members_down());
        $this->assertSame(EVC_Club_Member_Sync::UNAVAILABLE, $r->member);

        // Three independent instances with their own connections, one after the other (true simultaneity:
        // SqlMemberIdentityStoreTest, separate processes): the unique key and the scan keep one row.
        $a = $this->wire($at)['members'];
        $b = $this->wire($at)['members'];
        $c = $this->wire($at)['members'];
        $results = array($a->after_payment($customer), $b->reconcile()[$customer] ?? null, $c->reconcile()[$customer] ?? 'not_selected');
        $this->assertSame(EVC_Member_Sync_Result::PROVISIONED, $results[0]);
        $this->assertNull($results[1], 'the row exists: the scan no longer selects the customer');
        $this->assertSame('not_selected', $results[2]);
        $this->assertSame(EVC_Member_Sync_Result::ALREADY_MEMBER, $b->after_payment($customer));
        $this->assertCount(1, $this->members($customer));
    }

    public function test_the_member_step_logs_only_a_fixed_reason_and_an_exception_class(): void {
        $lines = array();
        $results = array();
        $sync = new EVC_Club_Member_Sync($this->config, function () {
            throw new EVC_Db_Exception('host=db.example password=hunter2 user=club', 'HY000', 2002);
        }, self::clock(self::utc(self::BASE)), function (string $line) use (&$lines) {
            $lines[] = $line;
        });
        foreach (array('after_payment', 'reconcile') as $step) {
            try {
                $results[$step] = $step === 'after_payment' ? $sync->after_payment($this->customer()) : $sync->reconcile();
            } catch (Throwable $e) {
                $results[$step] = 'threw ' . get_class($e); // never: the member step must not throw
            }
        }
        $this->assertSame(array('after_payment' => EVC_Club_Member_Sync::UNAVAILABLE, 'reconcile' => array()), $results);
        $this->assertSame(array('Evangelou Club: member sync unavailable (EVC_Db_Exception).', 'Evangelou Club: member sync reconcile_unavailable (EVC_Db_Exception).'), $lines);
    }

    // ---- nothing for the unpaid ------------------------------------------------------------------

    public function test_unpaid_unconfirmed_or_refused_orders_never_get_a_member_row(): void {
        $at = self::utc(self::BASE)->modify('+4 days');
        $this->wire($at);
        $pending = $this->customer();
        $this->order($pending, $at->modify('-1 hour'), 'pending');
        $on_hold = $this->customer();
        $this->order($on_hold, $at->modify('-1 hour'), 'on-hold');
        $processing = $this->customer();
        $this->order($processing, $at->modify('-1 hour'), 'processing');

        // Completed by the customer themself: not a confirmation; nothing issued, recorded or created.
        $own = $this->customer();
        $order = $this->order($own, $at->modify('-1 hour'));
        $r = $this->complete($order->get_id(), $own);
        $this->assertOutcome($r, EVC_Wc_Completion_Result::NOT_ATTESTED);
        $this->assertNull($r->member);

        // A refused shape (wrong total).
        $wrong = $this->customer();
        $o = $this->order($wrong, $at->modify('-1 hour'), 'pending');
        $o->set_total('15.00');
        $o->save();
        $o->update_status('on-hold');
        $refused = $this->complete($o->get_id(), $this->staff());
        $this->assertOutcome($refused, EVC_Wc_Completion_Result::CONFLICT);
        $this->assertNull($refused->member);

        $this->parts['recovery']->run();
        foreach (array($pending, $on_hold, $processing, $own, $wrong) as $u) {
            $this->assertSame(array(), $this->members($u), (string) $u);
            $this->assertSame(array(), $this->movements($u));
            $this->assertArrayNotHasKey($u, $this->parts['recovery']->last_members());
            $this->assertSame(EVC_Member_Sync_Result::NOT_ELIGIBLE, $this->parts['members']->after_payment($u), 'even asked directly: no recorded payment, no row');
            $this->assertSame(array(), $this->members($u));
        }
    }

    public function test_a_privileged_account_that_pays_gets_no_member_row(): void {
        $this->setExpectedDeprecated('pmprommpu_addMembershipLevel');
        $customer = $this->customer('subscriber');
        get_userdata($customer)->add_role('editor');
        list(, $r) = $this->purchase($customer, $this->staff(), self::utc(self::BASE)->modify('+5 days'));
        $this->assertOutcome($r, EVC_Wc_Completion_Result::RECORDED);
        $this->assertSame(EVC_Member_Sync_Result::REFUSED_ACCOUNT, $r->member);
        $this->assertSame(array(), $this->members($customer));
        $events = $this->sync_events(null, $customer);
        $this->assertSame(array(EVC_Member_Sync_Result::REFUSED_ACCOUNT), array_column($events, 'outcome'));
        $this->assertSame(array('trigger' => 'confirmation', 'customer' => $customer), json_decode($events[0]['details_json'], true));
    }

    // ---- refund freeze ---------------------------------------------------------------------------

    public function test_cancelling_the_order_freezes_the_card_but_never_removes_or_disables_the_row(): void {
        $this->setExpectedDeprecated('pmprommpu_addMembershipLevel');
        $customer = $this->customer();
        $staff = $this->staff();
        $at = self::utc(self::BASE)->modify('+6 days');
        list($order, $r) = $this->purchase($customer, $staff, $at);
        $this->assertSame(EVC_Member_Sync_Result::PROVISIONED, $r->member);
        $before = $this->members($customer);

        wp_set_current_user($staff);
        wc_get_order($order->get_id())->update_status('cancelled');
        wp_set_current_user(0);
        $this->assertOutcome($this->parts['confirmer']->last_result(), EVC_Wc_Completion_Result::CORRECTED);
        $this->assertNull($this->parts['confirmer']->last_result()->member, 'a correction never syncs');

        $this->assertSame($before, $this->members($customer), 'identity kept; the card is frozen by the recorded correction');
        $adapter = new EVC_Pmpro_Membership_Adapter(new EVC_Wp_Commerce_Pmpro_Reader($this->config, new EVC_Store_Club_Payment_Provenance(function () {
            $db = EVC_Db_Config::from_constants();
            return new EVC_Sql_Provenance_Store(EVC_Club_Db::connect($db), function () use ($db) {
                return EVC_Club_Db::connect($db);
            }, 'live');
        }, $this->config)), $this->config->mapper());
        $later = $at->modify('+1 hour');
        $this->assertFalse($adapter->entitlement_for($customer, $later)->is_eligible_at($later), 'no free coffee');
        $this->assertSame(EVC_Member_Sync_Result::ALREADY_MEMBER, $this->parts['members']->after_payment($customer));
        $this->assertSame($before, $this->members($customer));
    }

    // ---- the manager BACS paths (never constructed by shipped code) -------------------------------

    private function issuer(DateTimeImmutable $now, ?EVC_Club_Member_Sync $members): EVC_Bacs_Payment_Issuer {
        $db = EVC_Db_Config::from_constants();
        $store = new EVC_Sql_Provenance_Store(EVC_Club_Db::connect($db), function () use ($db) {
            return EVC_Club_Db::connect($db);
        }, 'live');
        $intents = new EVC_Wc_Bacs_Intent_Store($this->config->intent_codec(), 'live');
        $inspector = new EVC_Bacs_Recovery_Inspector($this->config, $store, $intents);
        $audit_db = EVC_Club_Db::connect($db);
        $finalizer = new EVC_Bacs_Payment_Finalizer($this->config, $store, $intents, $inspector, $GLOBALS['wpdb'], self::clock($now), new EVC_Db_Audit_Log(), $audit_db, 1, $members);
        $this->guard = new EVC_Bacs_Issuance_Guard();
        return new EVC_Bacs_Payment_Issuer($this->config, $store, $intents, $inspector, $finalizer, $this->guard, self::clock($now), new EVC_Db_Audit_Log(), $audit_db);
    }

    public function test_the_manager_issuer_path_creates_the_row_after_recording_and_survives_a_member_failure(): void {
        $this->setExpectedDeprecated('pmprommpu_addMembershipLevel');
        $manager = (int) self::factory()->user->create(array('role' => 'shop_manager'));
        get_userdata($manager)->add_cap(EVC_Bacs_Payment_Confirmation::CAPABILITY); // TEST-ONLY grant
        // The real recent clock: the BACS value date must not be in the future.
        $at = self::utc((new DateTimeImmutable('-30 seconds', new DateTimeZone('UTC')))->format('Y-m-d H:i:07'));
        $attest = function () use ($at) {
            return array('bank_reference' => 'TRF' . strtoupper(bin2hex(random_bytes(5))), 'amount_minor' => 2000, 'currency' => 'EUR', 'received_on' => $at->setTimezone(new DateTimeZone('Europe/Athens'))->format('Y-m-d'));
        };
        $members = function (?callable $open = null) use ($at) {
            return new EVC_Club_Member_Sync($this->config, $open ?? function () {
                return EVC_Club_Db::connect(EVC_Db_Config::from_constants());
            }, self::clock($at));
        };

        $ok = $this->customer();
        $order = $this->order($ok, $at->modify('-1 hour'));
        $r = $this->issuer($at, $members())->issue($manager, $order->get_id(), $attest());
        $this->assertSame(EVC_Bacs_Issuance_Result::RECORDED, $r->outcome, (string) $r->reason . ' ' . implode(',', $r->steps));
        $this->assertSame(EVC_Member_Sync_Result::PROVISIONED, $r->member);
        $this->assertCount(1, $this->members($ok));
        $this->guard->disarm();

        $down = $this->customer();
        $order = $this->order($down, $at->modify('-1 hour'));
        $r = $this->issuer($at, $members(self::members_down()))->issue($manager, $order->get_id(), $attest());
        $this->assertSame(EVC_Bacs_Issuance_Result::RECORDED, $r->outcome, 'the member step never changes the issuance');
        $this->assertTrue($r->intent_finalized);
        $this->assertSame(EVC_Club_Member_Sync::UNAVAILABLE, $r->member);
        $this->assertSame(array(), $this->members($down));
        $this->assertCount(1, $this->movements($down));
        $this->guard->disarm();

        // Refused (not a manager): no member step at all.
        $refused = $this->customer();
        $order = $this->order($refused, $at->modify('-1 hour'));
        $r = $this->issuer($at, $members())->issue($this->staff(), $order->get_id(), $attest());
        $this->assertSame(EVC_Bacs_Issuance_Result::REFUSED, $r->outcome);
        $this->assertNull($r->member);
        $this->guard->disarm();

        $none = $this->customer();
        $order = $this->order($none, $at->modify('-1 hour'));
        $r = $this->issuer($at, null)->issue($manager, $order->get_id(), $attest());
        $this->assertSame(EVC_Bacs_Issuance_Result::RECORDED, $r->outcome);
        $this->assertNull($r->member, 'without a member sync nothing is attempted (as shipped: never constructed)');
        $this->assertSame(array(), $this->members($none));
    }

    public function test_the_legacy_manager_confirmation_runs_the_member_step_only_after_recording(): void {
        $this->setExpectedDeprecated('pmprommpu_addMembershipLevel');
        $manager = (int) self::factory()->user->create(array('role' => 'shop_manager'));
        get_userdata($manager)->add_cap(EVC_Bacs_Payment_Confirmation::CAPABILITY); // TEST-ONLY grant
        $at = self::utc((new DateTimeImmutable('-30 seconds', new DateTimeZone('UTC')))->format('Y-m-d H:i:07'));
        $db = EVC_Db_Config::from_constants();
        $store = new EVC_Sql_Provenance_Store(EVC_Club_Db::connect($db), function () use ($db) {
            return EVC_Club_Db::connect($db);
        }, 'live');
        $members = new EVC_Club_Member_Sync($this->config, function () use ($db) {
            return EVC_Club_Db::connect($db);
        }, self::clock($at));
        $confirmation = new EVC_Bacs_Payment_Confirmation($this->config, $store, self::clock($at), null, null, $members);
        $customer = $this->customer();
        $order = $this->order($customer, $at->modify('-2 hours'), 'completed'); // the add-on issued PMPro as shipped
        $input = array('order_id' => $order->get_id(), 'bank_reference' => 'TRF' . strtoupper(bin2hex(random_bytes(5))), 'amount_minor' => 2000, 'currency' => 'EUR', 'received_on' => $at->setTimezone(new DateTimeZone('Europe/Athens'))->format('Y-m-d'));

        $refused = $confirmation->confirm($this->staff(), $input); // no Club capability
        $this->assertSame(EVC_Bacs_Confirmation_Result::REFUSED, $refused->outcome);
        $this->assertNull($refused->member, 'nothing recorded: no member step');

        $r = $confirmation->confirm($manager, $input);
        $this->assertTrue($r->funded(), (string) $r->reason);
        $this->assertNotNull($r->member, 'recorded: the member step ran');
        $this->assertContains($r->member, array(EVC_Member_Sync_Result::PROVISIONED, EVC_Member_Sync_Result::NOT_ELIGIBLE));
        $this->assertLessThanOrEqual(1, count($this->members($customer)));
    }
}
