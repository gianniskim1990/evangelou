<?php

/**
 * STAGE-03B.3b (owner decision 2026-10-26, Option 2): the INTERNAL issuance
 * orchestrator EVC_Bacs_Payment_Issuer and its request-scoped guard over the
 * REAL WooCommerce 11.2.1 / PMPro 3.8.9 / PMPro WooCommerce 1.11 (HPOS or
 * legacy storage, per EVC_TEST_WC_ORDER_STORAGE) and the REAL SQL provenance
 * store on the disposable Club database. Nothing here is a real payment.
 *
 * The issuer is unreachable in the plugin (no route, hook, page, role); these
 * tests construct it directly, and grant the capability to test users only.
 */
final class BacsIssuerTest extends EVC_WP_Test_Case {
    const ME = '/evangelou-club-customer/v1/me';

    /** @var EVC_Commerce_Source_Config */
    private $config;
    /** @var string[] raw references used (must never be stored anywhere) */
    private $references = array();
    /** @var wpdb[] */
    private $connections = array();
    /** @var EVC_Bacs_Issuance_Guard|null the guard of the last issuer built */
    private $guard;
    /** @var callable[] [hook, callback, priority] registered by a test */
    private $hooks = array();

    public function set_up() {
        parent::set_up();
        evc_test_commerce_configure();
        $this->config = EVC_Commerce_Source_Config::from_constants();
    }

    public function tear_down() {
        foreach ($this->hooks as $h) {
            remove_filter($h[0], $h[1], $h[2]);
        }
        if ($this->guard !== null) {
            $this->guard->disarm();
        }
        foreach ($this->connections as $db) {
            $db->close();
        }
        remove_all_filters('query');
        $GLOBALS['wpdb']->suppress_errors(false);
        parent::tear_down();
    }

    // ---- helpers -------------------------------------------------------------------------------

    private function club(): EVC_Sql_Provenance_Store {
        $db = EVC_Db_Config::from_constants();
        return new EVC_Sql_Provenance_Store(EVC_Club_Db::connect($db), function () use ($db) {
            return EVC_Club_Db::connect($db);
        }, 'live');
    }

    private function recorder(EVC_Provenance_Store $store): EVC_Provenance_Recorder {
        return new EVC_Provenance_Recorder($store, new EVC_Pmpro_Mapper_Config(array($this->config->level_id), EVC_Commerce_Source_Config::PMPRO_VERSION, EVC_Commerce_Source_Config::PMPRO_VERSION_EXCLUSIVE_MAX), 'live', 2000, 'EUR');
    }

    private function intents(): EVC_Wc_Bacs_Intent_Store {
        return new EVC_Wc_Bacs_Intent_Store($this->config->intent_codec(), 'live');
    }

    private static function clock(DateTimeImmutable $at): callable {
        return function () use ($at) {
            return $at;
        };
    }

    private function issuer(DateTimeImmutable $now, ?EVC_Provenance_Store $store = null, ?wpdb $db = null): EVC_Bacs_Payment_Issuer {
        $store = $store ?? $this->club();
        $intents = $this->intents();
        $inspector = new EVC_Bacs_Recovery_Inspector($this->config, $store, $intents);
        $audit_db = EVC_Club_Db::connect(EVC_Db_Config::from_constants());
        $finalizer = new EVC_Bacs_Payment_Finalizer($this->config, $store, $intents, $inspector, $db ?? $GLOBALS['wpdb'], self::clock($now), new EVC_Db_Audit_Log(), $audit_db, 1);
        $this->guard = new EVC_Bacs_Issuance_Guard();
        return new EVC_Bacs_Payment_Issuer($this->config, $store, $intents, $inspector, $finalizer, $this->guard, self::clock($now), new EVC_Db_Audit_Log(), $audit_db);
    }

    private function hook(string $name, callable $callback, int $priority): void {
        add_filter($name, $callback, $priority, 3);
        $this->hooks[] = array($name, $callback, $priority);
    }

    private function customer(): int {
        return (int) self::factory()->user->create(array('role' => 'customer', 'user_pass' => self::PASSWORD, 'user_email' => 'club.' . wp_generate_password(6, false, false) . '@example.org', 'display_name' => 'Synthetic Customer'));
    }

    /** TEST-ONLY grant: the plugin gives this capability to no role. */
    private function manager(): int {
        $id = (int) self::factory()->user->create(array('role' => 'shop_manager'));
        get_userdata($id)->add_cap(EVC_Bacs_Payment_Confirmation::CAPABILITY);
        return $id;
    }

    private function order(int $user_id, DateTimeImmutable $created): WC_Order {
        $order = wc_create_order(array('customer_id' => $user_id));
        $order->add_product(wc_get_product(EVC_TEST_CLUB_PRODUCT), 1);
        $order->set_payment_method('bacs');
        $order->set_currency('EUR');
        $order->set_billing_email('club.billing@example.org');
        $order->set_date_created($created->getTimestamp());
        $order->calculate_totals();
        $order->save();
        $order->update_status('on-hold');
        return wc_get_order($order->get_id());
    }

    private function reference(): string {
        $ref = 'TRF' . strtoupper(bin2hex(random_bytes(5)));
        $this->references[] = $ref;
        return $ref;
    }

    private static function utc(string $s): DateTimeImmutable {
        return new DateTimeImmutable($s, new DateTimeZone('UTC'));
    }

    private static function whole_second(DateTimeImmutable $t): DateTimeImmutable {
        return self::utc($t->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
    }

    private static function athens_date(DateTimeImmutable $t): string {
        return $t->setTimezone(new DateTimeZone('Europe/Athens'))->format('Y-m-d');
    }

    private static function local(DateTimeImmutable $i): string {
        return $i->setTimezone(new DateTimeZone('Europe/Athens'))->format('Y-m-d H:i:s');
    }

    private static function attestation(string $reference, DateTimeImmutable $at): array {
        return array('bank_reference' => $reference, 'amount_minor' => 2000, 'currency' => 'EUR', 'received_on' => self::athens_date($at));
    }

    /** @return array<int,array{id:string,status:string,startdate:string,enddate:string}> the customer's Club rows, oldest first */
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
        return $this->intents()->read($order_id);
    }

    private function intent_state(int $order_id): string {
        $read = $this->intent($order_id);
        $this->assertSame(EVC_Bacs_Intent_Read::VALID, $read->outcome, (string) $read->reason);
        return $read->intent->state();
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

    private function periods(int $user_id): array {
        $club = $this->club();
        return array_map(function (EVC_Verified_Movement $m) use ($club) {
            return $club->period_for_movement($m->movement_id());
        }, $club->member_movements($user_id));
    }

    private function second_connection(): wpdb {
        $db = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
        $db->set_prefix($GLOBALS['wpdb']->prefix);
        $this->connections[] = $db;
        return $db;
    }

    private function assertResult(EVC_Bacs_Issuance_Result $r, string $outcome, ?string $reason = null): void {
        $this->assertSame($outcome, $r->outcome, 'reason: ' . (string) $r->reason . ' steps: ' . implode(',', $r->steps));
        if ($reason !== null) {
            $this->assertSame($reason, $r->reason, 'steps: ' . implode(',', $r->steps));
        }
    }

    private function assertGuardInert(): void {
        $this->assertNotNull($this->guard);
        $this->assertFalse($this->guard->is_armed(), 'the guard is disarmed after the request');
        $this->assertFalse(has_filter('pmprowoo_checkout_level', array($this->guard, 'checkout_level')));
        $this->assertFalse(has_action('pmpro_before_change_membership_level', array($this->guard, 'before_change')));
        $this->assertFalse(has_filter('pmpro_deactivate_old_levels', array($this->guard, 'deactivate_old_levels')));
    }

    /** Nothing was written for this order: no intent, still awaiting payment, no PMPro row, no Club record. */
    private function assertUntouched(int $customer, WC_Order $order): void {
        $this->assertSame(EVC_Bacs_Intent_Read::MISSING, $this->intent($order->get_id())->outcome);
        $this->assertSame('on-hold', wc_get_order($order->get_id())->get_status());
        $this->assertSame(array(), $this->rows($customer));
        $this->assertSame(array(), $this->club()->member_movements($customer));
    }

    /**
     * A complete initial purchase through the issuer at $at (whole second).
     *
     * @return array{0:WC_Order,1:EVC_Bacs_Issuance_Result,2:string,3:DateTimeImmutable} order, result, reference, verification instant
     */
    private function purchase(int $customer, int $actor, DateTimeImmutable $at): array {
        $at = self::whole_second($at);
        $order = $this->order($customer, $at->modify('-1 hour'));
        $reference = $this->reference();
        $r = $this->issuer($at)->issue($actor, $order->get_id(), self::attestation($reference, $at));
        return array(wc_get_order($order->get_id()), $r, $reference, $at);
    }

    /** A durable `planned` intent as a crashed earlier run left it (nothing completed or issued). */
    private function planned_only(int $customer, int $actor, DateTimeImmutable $at): array {
        $at = self::whole_second($at);
        $order = $this->order($customer, $at->modify('-1 hour'));
        $reference = $this->reference();
        $movement = $this->config->ref('bacs_transfer', $reference);
        $anchor = $this->config->ref('wc_order', (string) $order->get_id());
        $club = $this->club();
        $plan = $this->recorder($club)->plan(new EVC_Prospective_Payment($movement, 'live', $customer, $this->config->level_id, $at, 2000, 'EUR', array(new EVC_Movement_Alias(EVC_Movement_Alias::NS_WC_ORDER, $anchor)), EVC_Verified_Movement::AUTHORITY_MANUAL_ADMIN, EVC_Payment_Evidence::KIND_MANUAL_CONFIRMATION));
        $this->assertSame(EVC_Period_Plan::PLANNED, $plan->outcome(), (string) $plan->reason());
        $planned = EVC_Bacs_Intent::planned('live', (int) $order->get_id(), $customer, $this->config->level_id, $movement, $anchor, $actor, $at, $plan, EVC_Paid_Run::start_for($club, $plan), null);
        $lock = EVC_Member_Lock::acquire($GLOBALS['wpdb'], $customer, 1);
        $this->assertSame(EVC_Bacs_Intent_Write::WRITTEN, $this->intents()->create($lock, $planned)->outcome);
        $lock->release();
        return array($order, $planned, $reference, $at);
    }

    // ---- wiring ----------------------------------------------------------------------------------

    public function test_the_issuer_and_its_parts_must_share_one_club_record_and_intent_store(): void {
        $store = $this->club();
        $intents = $this->intents();
        $inspector = new EVC_Bacs_Recovery_Inspector($this->config, $store, $intents);
        $other = $this->club(); // a second, different Club store instance
        $finalizer = new EVC_Bacs_Payment_Finalizer($this->config, $other, $intents, new EVC_Bacs_Recovery_Inspector($this->config, $other, $intents), $GLOBALS['wpdb'], self::clock(self::utc('now')));
        $this->expectException(InvalidArgumentException::class);
        new EVC_Bacs_Payment_Issuer($this->config, $store, $intents, $inspector, $finalizer, new EVC_Bacs_Issuance_Guard(), self::clock(self::utc('now')));
    }

    // ---- initial purchase ------------------------------------------------------------------------

    public function test_initial_purchase_is_issued_with_the_exact_athens_period_and_recorded(): void {
        $this->setExpectedDeprecated('pmprommpu_addMembershipLevel');
        $customer = $this->customer();
        $actor = $this->manager();
        list($order, $r, $reference, $at) = $this->purchase($customer, $actor, new DateTimeImmutable('-30 seconds'));

        $this->assertResult($r, EVC_Bacs_Issuance_Result::RECORDED);
        $this->assertNull($r->reason);
        $this->assertSame(array('planned', 'complete_order', 'issued', 'finalize'), $r->steps);
        $this->assertTrue($r->intent_finalized);
        $this->assertTrue($r->audited);
        $this->assertFalse($r->paid_time_at_risk);
        $this->assertGuardInert();

        $end = EVC_Membership_Calendar::add_calendar_month($at);
        $this->assertSame('completed', wc_get_order($order->get_id())->get_status());
        $rows = $this->rows($customer);
        $this->assertCount(1, $rows);
        $this->assertSame('active', $rows[0]['status']);
        $this->assertSame(self::local($at), $rows[0]['startdate'], 'PMPro shows the confirmation instant, not its own');
        $this->assertSame(self::local($end), $rows[0]['enddate'], 'PMPro shows the exact Athens calendar month');
        $read = $this->intent($order->get_id());
        $this->assertSame(EVC_Bacs_Intent::STATE_RECORDED, $read->intent->state());
        $this->assertSame($this->row_ref($rows[0]), $read->intent->new_row_ref());
        $this->assertEquals($end, $r->period->end_utc());

        $me = $this->me($customer);
        $this->assertSame('active', $me['state']);
        $this->assertSame($end->format(DATE_ATOM), $me['paid_until_utc']);

        // A replay (same or another manager) finalizes nothing new and issues nothing.
        foreach (array($actor, $this->manager()) as $who) {
            $again = $this->issuer(self::whole_second(new DateTimeImmutable('now')))->issue($who, $order->get_id(), self::attestation($reference, $at));
            $this->assertResult($again, EVC_Bacs_Issuance_Result::ALREADY_RECORDED);
            $this->assertSame(array('finalize'), $again->steps);
        }
        $this->assertCount(1, $this->rows($customer));
        $this->assertCount(1, $this->periods($customer));
    }

    // ---- early renewal (Option 2) -----------------------------------------------------------------

    public function test_early_renewal_keeps_the_paid_row_active_until_the_new_row_exists(): void {
        $this->setExpectedDeprecated('pmprommpu_addMembershipLevel');
        $customer = $this->customer();
        $actor = $this->manager();
        list(, $first, , $first_at) = $this->purchase($customer, $actor, new DateTimeImmutable('-10 days'));
        $this->assertResult($first, EVC_Bacs_Issuance_Result::RECORDED);
        $first_end = EVC_Membership_Calendar::add_calendar_month($first_at);
        $old = $this->active_rows($customer)[0];

        $writes = array();
        $this->hook('query', function ($sql) use (&$writes) {
            if (preg_match('/^\s*(INSERT|UPDATE)\b.*pmpro_memberships_users/is', $sql, $m)) {
                $writes[] = strtoupper($m[1]);
            }
            return $sql;
        }, 10);
        list($order, $r, , $at) = $this->purchase($customer, $actor, new DateTimeImmutable('-30 seconds'));
        remove_all_filters('query');

        $this->assertResult($r, EVC_Bacs_Issuance_Result::RECORDED);
        $this->assertSame(array('planned', 'complete_order', 'issued', 'finalize'), $r->steps);
        $this->assertGuardInert();
        $this->assertSame('INSERT', $writes[0] ?? null, 'the paid row was not touched before the new row existed');
        $this->assertContains('UPDATE', array_slice($writes, 1), 'PMPro\'s own duplicate removal retired the old row afterwards');

        $new_end = EVC_Membership_Calendar::add_calendar_month($first_end);
        $rows = $this->rows($customer);
        $this->assertCount(2, $rows);
        $this->assertSame($old['id'], $rows[0]['id']);
        $this->assertSame('changed', $rows[0]['status']);
        $this->assertSame('active', $rows[1]['status']);
        $this->assertSame(self::local($first_at), $rows[1]['startdate'], 'the paid run continues');
        $this->assertSame(self::local($new_end), $rows[1]['enddate'], 'previous paid end + one approved calendar month');
        $read = $this->intent($order->get_id());
        $this->assertSame(EVC_Funded_Period::KIND_EARLY, $read->intent->period_kind());
        $this->assertSame($this->row_ref($old), $read->intent->previous_row_ref());

        $me = $this->me($customer);
        $this->assertSame('active', $me['state']);
        $this->assertSame($new_end->format(DATE_ATOM), $me['paid_until_utc']);
        $periods = $this->periods($customer);
        $this->assertCount(2, $periods);
        $this->assertEquals($periods[0]->end_utc(), $periods[1]->start_utc(), 'no paid time lost or added');
        $this->assertTrue($at < $first_end);
    }

    public function test_an_early_renewal_needs_the_paid_row_exactly_as_recorded(): void {
        global $wpdb;
        $this->setExpectedDeprecated('pmprommpu_addMembershipLevel');
        $customer = $this->customer();
        $actor = $this->manager();
        list(, $first) = $this->purchase($customer, $actor, new DateTimeImmutable('-10 days'));
        $this->assertResult($first, EVC_Bacs_Issuance_Result::RECORDED);
        $row = $this->active_rows($customer)[0];
        $wpdb->update($wpdb->pmpro_memberships_users, array('enddate' => date('Y-m-d H:i:s', strtotime($row['enddate'] . ' +1 hour'))), array('id' => $row['id']));

        list($order, $r) = $this->purchase($customer, $actor, new DateTimeImmutable('-30 seconds'));
        $this->assertResult($r, EVC_Bacs_Issuance_Result::MANAGER_REQUIRED, 'paid_membership_row_differs');
        $this->assertSame(EVC_Bacs_Intent_Read::MISSING, $this->intent($order->get_id())->outcome);
        $this->assertSame('on-hold', wc_get_order($order->get_id())->get_status());
        $this->assertCount(1, $this->rows($customer));
        $this->assertCount(1, $this->periods($customer));
    }

    // ---- failures inside PMPro ----------------------------------------------------------------------

    public function test_a_failed_pmpro_insert_keeps_the_paid_membership_and_the_resume_reissues(): void {
        $this->setExpectedDeprecated('pmprommpu_addMembershipLevel');
        $customer = $this->customer();
        $actor = $this->manager();
        list(, $first, , $first_at) = $this->purchase($customer, $actor, new DateTimeImmutable('-10 days'));
        $this->assertResult($first, EVC_Bacs_Issuance_Result::RECORDED);
        $first_end = EVC_Membership_Calendar::add_calendar_month($first_at);

        $GLOBALS['wpdb']->suppress_errors(true);
        add_filter('query', function ($sql) {
            return preg_match('/^\s*INSERT\s+INTO\s+`?\w*pmpro_memberships_users`?\s/i', $sql) ? 'INSERT INTO evc_no_such_table_for_test VALUES (1)' : $sql;
        });
        $at = self::whole_second(new DateTimeImmutable('-30 seconds'));
        $order = $this->order($customer, $at->modify('-1 hour'));
        $reference = $this->reference();
        $r = $this->issuer($at)->issue($actor, $order->get_id(), self::attestation($reference, $at));
        remove_all_filters('query');
        $GLOBALS['wpdb']->suppress_errors(false);

        $this->assertResult($r, EVC_Bacs_Issuance_Result::MANAGER_REQUIRED, 'membership_not_reissued');
        $this->assertSame(array('planned', 'complete_order'), $r->steps, 'one issuance attempt per request');
        $this->assertFalse($r->paid_time_at_risk);
        $this->assertGuardInert();
        $active = $this->active_rows($customer);
        $this->assertCount(1, $active, 'the already paid row is still ACTIVE');
        $this->assertSame(self::local($first_end), $active[0]['enddate']);
        $this->assertSame(EVC_Bacs_Intent::STATE_PLANNED, $this->intent_state($order->get_id()));
        $this->assertSame('completed', wc_get_order($order->get_id())->get_status());
        $this->assertCount(1, $this->periods($customer), 'nothing recorded for the renewal');
        $me = $this->me($customer);
        $this->assertSame('active', $me['state'], 'the member keeps the time already paid');
        $this->assertSame($first_end->format(DATE_ATOM), $me['paid_until_utc']);

        // Resume: the add-on does not fire again on a completed order, so the issuer re-issues it, armed.
        $again = $this->issuer(self::whole_second(new DateTimeImmutable('now')))->issue($actor, $order->get_id(), self::attestation($reference, $at));
        $this->assertResult($again, EVC_Bacs_Issuance_Result::RECORDED);
        $this->assertSame(array('reissue', 'issued', 'finalize'), $again->steps);
        $this->assertGuardInert();
        $new_end = EVC_Membership_Calendar::add_calendar_month($first_end);
        $active = $this->active_rows($customer);
        $this->assertCount(1, $active);
        $this->assertSame(self::local($first_at), $active[0]['startdate']);
        $this->assertSame(self::local($new_end), $active[0]['enddate']);
        $this->assertSame($new_end->format(DATE_ATOM), $this->me($customer)['paid_until_utc']);
        $this->assertCount(2, $this->periods($customer));
    }

    public function test_a_failed_duplicate_removal_fails_closed(): void {
        $this->setExpectedDeprecated('pmprommpu_addMembershipLevel');
        $customer = $this->customer();
        $actor = $this->manager();
        list(, $first) = $this->purchase($customer, $actor, new DateTimeImmutable('-10 days'));
        $this->assertResult($first, EVC_Bacs_Issuance_Result::RECORDED);

        $GLOBALS['wpdb']->suppress_errors(true);
        add_filter('query', function ($sql) {
            return preg_match("/^\\s*UPDATE\\s+`?\\w*pmpro_memberships_users`?\\s+SET\\s+status\\s*=\\s*'changed'.*\\bid\\s*!=/is", $sql) ? 'UPDATE evc_no_such_table_for_test SET x = 1' : $sql;
        });
        list($order, $r) = $this->purchase($customer, $actor, new DateTimeImmutable('-30 seconds'));
        remove_all_filters('query');
        $GLOBALS['wpdb']->suppress_errors(false);

        $this->assertResult($r, EVC_Bacs_Issuance_Result::CONFLICT, 'multiple_active_membership_rows');
        $this->assertGuardInert();
        $this->assertCount(2, $this->active_rows($customer));
        $this->assertSame(EVC_Bacs_Intent::STATE_PLANNED, $this->intent_state($order->get_id()));
        $this->assertCount(1, $this->periods($customer), 'nothing recorded');
        $this->assertSame('unverified', $this->me($customer)['state'], 'two active rows are never guessed');
    }

    public function test_a_mismatching_issuance_is_never_confirmed(): void {
        $this->setExpectedDeprecated('pmprommpu_addMembershipLevel');
        $customer = $this->customer();
        $actor = $this->manager();
        // Something after the guard changes PMPro's end date.
        $this->hook('pmprowoo_checkout_level', function ($level) {
            $level['enddate'] = date('Y-m-d H:i:s', strtotime($level['enddate'] . ' +1 hour'));
            return $level;
        }, 2000);
        list($order, $r, $reference, $at) = $this->purchase($customer, $actor, new DateTimeImmutable('-30 seconds'));
        $this->assertResult($r, EVC_Bacs_Issuance_Result::MANAGER_REQUIRED, 'issuance_mismatch');
        $this->assertSame(array('planned', 'complete_order', 'issuance_mismatch'), $r->steps);
        $this->assertSame(EVC_Bacs_Intent::STATE_ISSUANCE_MISMATCH, $this->intent_state($order->get_id()), 'never "issued" on a row with other dates');
        $this->assertSame(array(), $this->club()->member_movements($customer));
        $this->assertNotSame('active', $this->me($customer)['state']);

        $again = $this->issuer(self::whole_second(new DateTimeImmutable('now')))->issue($actor, $order->get_id(), self::attestation($reference, $at));
        $this->assertResult($again, EVC_Bacs_Issuance_Result::MANAGER_REQUIRED, 'intent_issuance_mismatch');
        $this->assertCount(1, $this->rows($customer), 'nothing re-issued on a mismatch');
    }

    // ---- crash / resume ----------------------------------------------------------------------------

    public function test_a_planned_intent_left_by_a_crash_is_resumed_from_completion(): void {
        $this->setExpectedDeprecated('pmprommpu_addMembershipLevel');
        $customer = $this->customer();
        $actor = $this->manager();
        list($order, $planned, $reference, $at) = $this->planned_only($customer, $actor, new DateTimeImmutable('-2 minutes'));
        wp_cache_flush();
        $r = $this->issuer(self::whole_second(new DateTimeImmutable('now')))->issue($this->manager(), $order->get_id(), self::attestation($reference, $at));
        $this->assertResult($r, EVC_Bacs_Issuance_Result::RECORDED);
        $this->assertSame(array('complete_order', 'issued', 'finalize'), $r->steps, 'resumed with the stored plan, not a new one');
        $this->assertEquals($planned->period_end_utc(), $r->period->end_utc());
        $this->assertSame($planned->period_end_utc()->format(DATE_ATOM), $this->me($customer)['paid_until_utc']);
    }

    public function test_a_crash_before_the_issued_transition_or_before_recording_is_resumed(): void {
        $this->setExpectedDeprecated('pmprommpu_addMembershipLevel');
        $actor = $this->manager();

        // (a) PMPro issued, the planned -> issued write is lost.
        $customer = $this->customer();
        $this->hook('woocommerce_order_status_completed', function () {
            add_filter('query', function ($sql) {
                return (preg_match('/^\s*(INSERT|UPDATE)\b/i', $sql) && strpos($sql, EVC_Wc_Bacs_Intent_Store::META_KEY) !== false) ? 'UPDATE evc_no_such_table_for_test SET x = 1' : $sql;
            });
            $GLOBALS['wpdb']->suppress_errors(true);
        }, 100);
        list($order, $r, $reference, $at) = $this->purchase($customer, $actor, new DateTimeImmutable('-30 seconds'));
        remove_all_filters('query');
        remove_all_actions('woocommerce_order_status_completed', 100);
        $GLOBALS['wpdb']->suppress_errors(false);
        $this->assertResult($r, EVC_Bacs_Issuance_Result::UNAVAILABLE, 'intent_issued_write_failed');
        $this->assertSame(EVC_Bacs_Intent::STATE_PLANNED, $this->intent_state($order->get_id()));
        $this->assertCount(1, $this->active_rows($customer));
        $this->assertSame('unverified', $this->me($customer)['state'], 'issued but unrecorded is not paid');
        wp_cache_flush();
        $again = $this->issuer(self::whole_second(new DateTimeImmutable('now')))->issue($actor, $order->get_id(), self::attestation($reference, $at));
        $this->assertResult($again, EVC_Bacs_Issuance_Result::RECORDED);
        $this->assertSame(array('issued', 'finalize'), $again->steps);
        $this->assertCount(1, $this->rows($customer), 'never issued twice');

        // (b) issued, the Club commit fails.
        $customer2 = $this->customer();
        $at2 = self::whole_second(new DateTimeImmutable('-30 seconds'));
        $order2 = $this->order($customer2, $at2->modify('-1 hour'));
        $reference2 = $this->reference();
        $hooked = new EVC_Hooked_Provenance_Store($this->club());
        $hooked->commit_mode = EVC_Hooked_Provenance_Store::COMMIT_FAIL_BEFORE;
        $r2 = $this->issuer($at2, $hooked)->issue($actor, $order2->get_id(), self::attestation($reference2, $at2));
        $this->assertResult($r2, EVC_Bacs_Issuance_Result::UNAVAILABLE, 'club_record_unavailable');
        $this->assertSame(EVC_Bacs_Intent::STATE_ISSUED, $this->intent_state($order2->get_id()));
        $this->assertSame(array(), $this->club()->member_movements($customer2));
        $again2 = $this->issuer(self::whole_second(new DateTimeImmutable('now')))->issue($actor, $order2->get_id(), self::attestation($reference2, $at2));
        $this->assertResult($again2, EVC_Bacs_Issuance_Result::RECORDED);
        $this->assertSame(array('finalize'), $again2->steps);
        $this->assertSame('active', $this->me($customer2)['state']);
    }

    public function test_the_guard_is_disarmed_even_when_completion_throws(): void {
        $this->setExpectedDeprecated('pmprommpu_addMembershipLevel');
        $customer = $this->customer();
        $actor = $this->manager();
        list(, $first) = $this->purchase($customer, $actor, new DateTimeImmutable('-10 days'));
        $this->assertResult($first, EVC_Bacs_Issuance_Result::RECORDED);

        // A crash inside the completion, before the add-on issues anything.
        $this->hook('woocommerce_order_status_completed', function () {
            throw new Error('simulated crash during completion');
        }, 5);
        list($order, $r, $reference, $at) = $this->purchase($customer, $actor, new DateTimeImmutable('-30 seconds'));
        remove_all_actions('woocommerce_order_status_completed', 5);
        $this->assertGuardInert();
        $this->assertResult($r, EVC_Bacs_Issuance_Result::MANAGER_REQUIRED, 'membership_not_reissued');
        $this->assertCount(1, $this->active_rows($customer), 'the paid row is still active');
        $this->assertSame('active', $this->me($customer)['state']);

        $again = $this->issuer(self::whole_second(new DateTimeImmutable('now')))->issue($actor, $order->get_id(), self::attestation($reference, $at));
        $this->assertResult($again, EVC_Bacs_Issuance_Result::RECORDED);
        $this->assertSame(array('reissue', 'issued', 'finalize'), $again->steps);
        $this->assertGuardInert();
        $this->assertCount(2, $this->periods($customer));
    }

    // ---- the guard itself ---------------------------------------------------------------------------

    private static function level(int $user, int $level): array {
        return array('user_id' => $user, 'membership_id' => (string) $level, 'startdate' => "'2026-01-01 00:00:00'", 'enddate' => '2026-02-01 00:00:00');
    }

    public function test_the_guard_changes_dates_only_for_the_armed_customer_and_level(): void {
        $g = new EVC_Bacs_Issuance_Guard();
        $this->assertSame(self::level(11, 1), $g->checkout_level(self::level(11, 1)), 'unarmed: untouched');
        $g->arm(11, 1, '2026-10-15 12:00:07', '2026-11-15 12:00:07');
        try {
            $this->assertSame(self::level(12, 1), $g->checkout_level(self::level(12, 1)), 'another customer');
            $this->assertSame(self::level(11, 2), $g->checkout_level(self::level(11, 2)), 'another level');
            $this->assertSame('not-a-level', $g->checkout_level('not-a-level'));
            $mine = $g->checkout_level(self::level(11, 1));
            $this->assertSame("'2026-10-15 12:00:07'", $mine['startdate'], 'quoted like the add-on\'s own start');
            $this->assertSame('2026-11-15 12:00:07', $mine['enddate']);
            $this->assertSame(1, $g->applied());
        } finally {
            $g->disarm();
        }
        $this->assertSame(self::level(11, 1), $g->checkout_level(self::level(11, 1)), 'disarmed: untouched');
    }

    public function test_the_guard_keeps_the_paid_row_only_for_the_armed_change(): void {
        $g = new EVC_Bacs_Issuance_Guard();
        $g->arm(11, 1, '2026-10-15 12:00:07', '2026-11-15 12:00:07');
        try {
            $this->assertTrue($g->deactivate_old_levels(true), 'no armed level passed through the add-on');
            $g->checkout_level(self::level(12, 1));
            $g->before_change(1, 12);
            $this->assertTrue($g->deactivate_old_levels(true), 'another customer\'s change');
            $g->checkout_level(self::level(11, 1));
            $g->before_change(2, 11);
            $this->assertTrue($g->deactivate_old_levels(true), 'another level of the armed customer');
            $g->before_change(1, 11);
            $this->assertFalse($g->deactivate_old_levels(true), 'the armed change keeps the paid row until the insert');
            $this->assertTrue($g->deactivate_old_levels(true), 'only once');
        } finally {
            $g->disarm();
        }
        $this->assertTrue($g->deactivate_old_levels(true));
    }

    public function test_disarming_removes_every_guard_callback(): void {
        $g = new EVC_Bacs_Issuance_Guard();
        $this->assertFalse(has_filter('pmpro_deactivate_old_levels', array($g, 'deactivate_old_levels')));
        $g->arm(11, 1, '2026-10-15 12:00:07', '2026-11-15 12:00:07');
        $this->assertGreaterThan(10, has_filter('pmprowoo_checkout_level', array($g, 'checkout_level')), 'after the add-on\'s own priority-10 extension');
        $this->assertSame(EVC_Bacs_Issuance_Guard::PRIORITY, has_filter('pmpro_deactivate_old_levels', array($g, 'deactivate_old_levels')));
        try {
            $g->arm(11, 1, '2026-10-15 12:00:07', '2026-11-15 12:00:07');
            $this->fail('a guard is armed once');
        } catch (LogicException $e) {
            $this->assertTrue($g->is_armed());
        }
        $g->disarm();
        $this->assertFalse($g->is_armed());
        $this->assertFalse(has_filter('pmprowoo_checkout_level', array($g, 'checkout_level')));
        $this->assertFalse(has_action('pmpro_before_change_membership_level', array($g, 'before_change')));
        $this->assertFalse(has_filter('pmpro_deactivate_old_levels', array($g, 'deactivate_old_levels')));
        foreach (array(array(0, 1, '2026-10-15 12:00:07', '2026-11-15 12:00:07'), array(11, 1, "2026-10-15 12:00:07'", '2026-11-15 12:00:07'), array(11, 1, '2026-11-15 12:00:07', '2026-10-15 12:00:07')) as $bad) {
            try {
                $g->arm(...$bad);
                $this->fail('invalid arm accepted');
            } catch (InvalidArgumentException $e) {
                $this->assertFalse($g->is_armed());
            }
        }
    }

    public function test_an_unarmed_completion_is_untouched(): void {
        $this->setExpectedDeprecated('pmprommpu_addMembershipLevel');
        list(, $r) = $this->purchase($this->customer(), $this->manager(), new DateTimeImmutable('-30 seconds'));
        $this->assertResult($r, EVC_Bacs_Issuance_Result::RECORDED);
        $this->assertGuardInert();

        // A manual "Completed" in the admin: the add-on's own dates and PMPro's own ordering.
        $customer = $this->customer();
        $before = current_time('timestamp');
        $this->order($customer, new DateTimeImmutable('-1 hour'))->update_status('completed');
        $rows = $this->rows($customer);
        $this->assertCount(1, $rows);
        $this->assertMatchesRegularExpression('/:00$/', $rows[0]['enddate'], 'minute-rounded add-on end');
        $this->assertLessThanOrEqual(120, abs(strtotime($rows[0]['startdate']) - $before), 'start = completion time');

        $writes = array();
        $this->hook('query', function ($sql) use (&$writes) {
            if (preg_match('/^\s*(INSERT|UPDATE)\b.*pmpro_memberships_users/is', $sql, $m)) {
                $writes[] = strtoupper($m[1]);
            }
            return $sql;
        }, 10);
        $this->order($customer, new DateTimeImmutable('-1 minute'))->update_status('completed');
        remove_all_filters('query');
        $this->assertSame('UPDATE', $writes[0] ?? null, 'unarmed: PMPro retires the old row BEFORE inserting (shipped behaviour)');
        $this->assertContains('INSERT', $writes);
    }

    // ---- concurrency and authorisation ----------------------------------------------------------------

    public function test_a_second_concurrent_manager_is_busy_and_writes_nothing(): void {
        $this->setExpectedDeprecated('pmprommpu_addMembershipLevel');
        $customer = $this->customer();
        $at = self::whole_second(new DateTimeImmutable('-30 seconds'));
        $order = $this->order($customer, $at->modify('-1 hour'));
        $reference = $this->reference();
        $held = EVC_Member_Lock::acquire($this->second_connection(), $customer, 0);
        $this->assertNotNull($held, 'manager A is mid-request');
        $r = $this->issuer($at)->issue($this->manager(), $order->get_id(), self::attestation($reference, $at));
        $this->assertResult($r, EVC_Bacs_Issuance_Result::BUSY, 'member_locked');
        $this->assertUntouched($customer, $order);
        $held->release();
        $this->assertResult($this->issuer($at)->issue($this->manager(), $order->get_id(), self::attestation($reference, $at)), EVC_Bacs_Issuance_Result::RECORDED);
    }

    public function test_the_member_lock_is_held_through_the_club_commit(): void {
        $this->setExpectedDeprecated('pmprommpu_addMembershipLevel');
        $customer = $this->customer();
        $at = self::whole_second(new DateTimeImmutable('-30 seconds'));
        $order = $this->order($customer, $at->modify('-1 hour'));
        $other = $this->second_connection();
        $grabbed = null;
        $hooked = new EVC_Hooked_Provenance_Store($this->club());
        $hooked->before_commit = function () use ($other, $customer, &$grabbed) {
            $lock = EVC_Member_Lock::acquire($other, $customer, 0);
            $grabbed = $lock !== null;
            if ($lock !== null) {
                $lock->release();
            }
        };
        $r = $this->issuer($at, $hooked)->issue($this->manager(), $order->get_id(), self::attestation($this->reference(), $at));
        $this->assertResult($r, EVC_Bacs_Issuance_Result::RECORDED);
        $this->assertFalse($grabbed, 'no other session could take the member while the Club recorded');
    }

    public function test_only_an_authorised_manager_may_issue(): void {
        $customer = $this->customer();
        $at = self::whole_second(new DateTimeImmutable('-30 seconds'));
        $order = $this->order($customer, $at->modify('-1 hour'));
        $attestation = self::attestation($this->reference(), $at);
        $staff = $this->create_staff_user();
        get_userdata($staff)->add_cap(EVC_Bacs_Payment_Confirmation::CAPABILITY);
        foreach (array((int) self::factory()->user->create(array('role' => 'shop_manager')), (int) self::factory()->user->create(array('role' => 'administrator')), $staff, 0, 999999) as $actor) {
            $this->assertResult($this->issuer($at)->issue($actor, $order->get_id(), $attestation), EVC_Bacs_Issuance_Result::REFUSED, 'not_authorised');
        }
        get_userdata($customer)->add_cap(EVC_Bacs_Payment_Confirmation::CAPABILITY);
        $this->assertResult($this->issuer($at)->issue($customer, $order->get_id(), $attestation), EVC_Bacs_Issuance_Result::REFUSED, 'own_order');
        $this->assertUntouched($customer, $order);
    }

    public function test_the_attestation_must_be_valid_and_match_the_attempt(): void {
        $customer = $this->customer();
        $actor = $this->manager();
        $at = self::whole_second(new DateTimeImmutable('-30 seconds'));
        $order = $this->order($customer, $at->modify('-1 hour'));
        $good = self::attestation($this->reference(), $at);
        $zone = new DateTimeZone('Europe/Athens');
        $cases = array(
            'invalid_attestation' => array('bank_reference' => 'x'),
            'amount_not_20_eur' => array('amount_minor' => 1999),
        );
        foreach ($cases as $reason => $change) {
            $this->assertResult($this->issuer($at)->issue($actor, $order->get_id(), $change + $good), EVC_Bacs_Issuance_Result::REFUSED, $reason);
        }
        $this->assertResult($this->issuer($at)->issue($actor, $order->get_id(), array('currency' => 'USD') + $good), EVC_Bacs_Issuance_Result::REFUSED, 'amount_not_20_eur');
        foreach (array((new DateTimeImmutable('-5 days', $zone))->format('Y-m-d'), (new DateTimeImmutable('+2 days', $zone))->format('Y-m-d')) as $date) {
            $this->assertResult($this->issuer($at)->issue($actor, $order->get_id(), array('received_on' => $date) + $good), EVC_Bacs_Issuance_Result::REFUSED, 'received_date_outside_order_window');
        }
        $this->assertUntouched($customer, $order);

        // An attempt already made durable with one transfer can never continue with another.
        $customer2 = $this->customer();
        list($order2, , , $at2) = $this->planned_only($customer2, $actor, new DateTimeImmutable('-2 minutes'));
        $r = $this->issuer(self::whole_second(new DateTimeImmutable('now')))->issue($actor, $order2->get_id(), self::attestation($this->reference(), $at2));
        $this->assertResult($r, EVC_Bacs_Issuance_Result::REFUSED, 'attestation_does_not_match_intent');
        $this->assertSame('on-hold', wc_get_order($order2->get_id())->get_status());
        $this->assertSame(EVC_Bacs_Intent::STATE_PLANNED, $this->intent_state($order2->get_id()));
        $this->assertSame(array(), $this->rows($customer2));
    }

    public function test_orders_not_awaiting_payment_are_not_issued(): void {
        $this->setExpectedDeprecated('pmprommpu_addMembershipLevel');
        $actor = $this->manager();
        $at = self::whole_second(new DateTimeImmutable('-30 seconds'));
        $customer = $this->customer();
        $completed = $this->order($customer, $at->modify('-1 hour'));
        $completed->update_status('completed');
        $this->assertResult($this->issuer($at)->issue($actor, $completed->get_id(), self::attestation($this->reference(), $at)), EVC_Bacs_Issuance_Result::MANAGER_REQUIRED, 'completed_order_without_intent');
        $this->assertSame(EVC_Bacs_Intent_Read::MISSING, $this->intent($completed->get_id())->outcome);
        $cancelled = $this->order($this->customer(), $at->modify('-1 hour'));
        $cancelled->update_status('cancelled');
        $this->assertResult($this->issuer($at)->issue($actor, $cancelled->get_id(), self::attestation($this->reference(), $at)), EVC_Bacs_Issuance_Result::MANAGER_REQUIRED, 'order_ended');
        $this->assertSame(array(), $this->club()->member_movements($customer));
    }

    // ---- configuration assumption: the Club level alone in its PMPro level group ---------------------

    public function test_the_club_level_must_not_share_a_single_selection_level_group(): void {
        global $wpdb;
        $this->setExpectedDeprecated('pmprommpu_addMembershipLevel');
        $wpdb->insert($wpdb->pmpro_groups, array('name' => 'Single selection', 'allow_multiple_selections' => 0, 'displayorder' => 1));
        $group = (int) $wpdb->insert_id;
        $wpdb->delete($wpdb->pmpro_membership_levels_groups, array('level' => $this->config->level_id));
        $wpdb->insert($wpdb->pmpro_membership_levels_groups, array('level' => $this->config->level_id, 'group' => $group));
        $wpdb->insert($wpdb->pmpro_membership_levels_groups, array('level' => $this->config->level_id + 1, 'group' => $group));

        $customer = $this->customer();
        $at = self::whole_second(new DateTimeImmutable('-30 seconds'));
        $order = $this->order($customer, $at->modify('-1 hour'));
        $attestation = self::attestation($this->reference(), $at);
        $this->assertResult($this->issuer($at)->issue($this->manager(), $order->get_id(), $attestation), EVC_Bacs_Issuance_Result::MANAGER_REQUIRED, 'club_level_group_not_exclusive');
        $this->assertUntouched($customer, $order);

        // Alone in a single-selection group: PMPro keeps no other level to cancel; issuance proceeds.
        $wpdb->delete($wpdb->pmpro_membership_levels_groups, array('level' => $this->config->level_id + 1));
        $this->assertResult($this->issuer($at)->issue($this->manager(), $order->get_id(), $attestation), EVC_Bacs_Issuance_Result::RECORDED);
        $this->assertSame('active', $this->me($customer)['state']);
    }

    // ---- DST and month-end plans --------------------------------------------------------------------

    public function test_a_plan_crossing_the_autumn_change_is_issued_with_exact_athens_dates(): void {
        $this->setExpectedDeprecated('pmprommpu_addMembershipLevel');
        $customer = $this->customer();
        list(, $r) = $this->purchase($customer, $this->manager(), self::utc('2026-10-10 10:00:07')); // 13:00:07 EEST
        $this->assertResult($r, EVC_Bacs_Issuance_Result::RECORDED);
        $row = $this->active_rows($customer)[0];
        $this->assertSame('2026-10-10 13:00:07', $row['startdate']);
        $this->assertSame('2026-11-10 13:00:07', $row['enddate'], 'same Athens wall clock, now in EET');
        $e = $this->entitlement($customer, self::utc('2026-10-20 12:00:00'));
        $this->assertTrue($e->is_eligible_at(self::utc('2026-10-20 12:00:00')));
        $this->assertSame('2026-11-10T11:00:07+00:00', $e->expires_at_utc()->format(DATE_ATOM));
    }

    public function test_a_month_end_plan_is_issued_with_the_clamped_athens_day(): void {
        $this->setExpectedDeprecated('pmprommpu_addMembershipLevel');
        $customer = $this->customer();
        list(, $r) = $this->purchase($customer, $this->manager(), self::utc('2026-01-31 08:00:07')); // 10:00:07 EET
        $this->assertResult($r, EVC_Bacs_Issuance_Result::RECORDED);
        $row = $this->active_rows($customer)[0];
        $this->assertSame('2026-01-31 10:00:07', $row['startdate']);
        $this->assertSame('2026-02-28 10:00:07', $row['enddate']);
        $this->assertTrue($this->entitlement($customer, self::utc('2026-02-28 08:00:06'))->is_eligible_at(self::utc('2026-02-28 08:00:06')));
        $this->assertFalse($this->entitlement($customer, self::utc('2026-02-28 08:00:07'))->is_eligible_at(self::utc('2026-02-28 08:00:07')), 'exclusive end');
    }

    public function test_a_confirmation_in_the_repeated_autumn_hour_is_refused_before_anything_is_written(): void {
        $customer = $this->customer();
        $at = self::utc('2026-10-25 00:30:00'); // 03:30 EEST, the FIRST of two 03:30s in Athens
        $order = $this->order($customer, self::utc('2026-10-24 20:00:00'));
        $r = $this->issuer($at)->issue($this->manager(), $order->get_id(), self::attestation($this->reference(), $at));
        $this->assertResult($r, EVC_Bacs_Issuance_Result::MANAGER_REQUIRED, 'period_not_representable_in_pmpro');
        $this->assertUntouched($customer, $order);
    }

    // ---- no leakage --------------------------------------------------------------------------------

    public function test_no_bank_or_personal_data_reaches_audit_intents_or_results(): void {
        global $wpdb;
        $this->setExpectedDeprecated('pmprommpu_addMembershipLevel');
        $customer = $this->customer();
        $actor = $this->manager();
        list($order, $r, $reference, $at) = $this->purchase($customer, $actor, new DateTimeImmutable('-30 seconds'));
        $results = array(
            $r,
            $this->issuer($at)->issue($actor, $order->get_id(), self::attestation($this->reference(), $at)),
            $this->issuer($at)->issue($actor, $order->get_id(), self::attestation($reference, $at)),
        );
        $this->assertSame(EVC_Bacs_Issuance_Result::REFUSED, $results[1]->outcome);
        $user = get_userdata($customer);
        $secrets = array_merge($this->references, array($user->user_email, $user->display_name, 'club.billing@example.org', EVC_CLUB_EVIDENCE_KEY));
        $club = EVC_Club_Db::connect(EVC_Db_Config::from_constants());
        $dump = '';
        foreach (array('evc_pay_movements', 'evc_pay_aliases', 'evc_pay_periods', 'evc_audit_events') as $table) {
            $dump .= wp_json_encode($club->fetch_all('SELECT * FROM ' . $table));
        }
        $dump .= wp_json_encode($wpdb->get_col($wpdb->prepare('SELECT meta_value FROM ' . \Automattic\WooCommerce\Utilities\OrderUtil::get_table_for_order_meta() . ' WHERE meta_key = %s', EVC_Wc_Bacs_Intent_Store::META_KEY)));
        $dump .= wp_json_encode(array_map(function (EVC_Bacs_Issuance_Result $x) {
            return array($x->outcome, $x->reason, $x->steps);
        }, $results));
        foreach ($secrets as $secret) {
            $this->assertStringNotContainsString($secret, $dump);
        }
        $events = $club->fetch_all("SELECT outcome, details_json FROM evc_audit_events WHERE event_type = 'bacs_payment_issuance' AND staff_wp_user_id = ? ORDER BY id", array($actor));
        $this->assertCount(3, $events, 'every attempt is audited');
        $this->assertSame(array('recorded', 'refused', 'already_recorded'), array_column($events, 'outcome'));
        $this->assertSame(0, (int) $club->fetch_all("SELECT COUNT(*) AS n FROM evc_audit_events WHERE event_type = 'bacs_payment_finalization' AND staff_wp_user_id = ?", array($actor))[0]['n'], 'one audit row per request');
    }
}
