<?php

/**
 * STAGE-03: the trusted BACS confirmation boundary + the provenance read
 * adapter, end to end over the REAL WooCommerce 11.2.1 / PMPro 3.8.9 / PMPro
 * WooCommerce 1.11 and the REAL EVC_Sql_Provenance_Store on a disposable
 * Club database (tests/wordpress/support/commerce-site.php). Customer flag
 * ON, staff flag OFF. Nothing here is a real payment.
 */
final class BacsConfirmationTest extends EVC_WP_Test_Case {
    const ME = '/evangelou-club-customer/v1/me';

    /** @var EVC_Commerce_Source_Config */
    private $config;
    /** @var EVC_Club_Db */
    private $club;

    public function set_up() {
        parent::set_up();
        evc_test_commerce_configure();
        $this->config = EVC_Commerce_Source_Config::from_constants();
        $this->club = EVC_Club_Db::connect(EVC_Db_Config::from_constants());
    }

    private function store(): EVC_Sql_Provenance_Store {
        $db = EVC_Db_Config::from_constants();
        return new EVC_Sql_Provenance_Store(EVC_Club_Db::connect($db), function () use ($db) {
            return EVC_Club_Db::connect($db);
        }, 'live');
    }

    private function service(?callable $now = null): EVC_Bacs_Payment_Confirmation {
        return new EVC_Bacs_Payment_Confirmation($this->config, $this->store(), $now ?? function () {
            return new DateTimeImmutable('now', new DateTimeZone('UTC'));
        }, new EVC_Db_Audit_Log(), $this->club);
    }

    private function customer(): int {
        return (int) self::factory()->user->create(array('role' => 'customer', 'user_pass' => self::PASSWORD, 'user_email' => 'club.' . wp_generate_password(6, false, false) . '@example.org'));
    }

    /** TEST-ONLY grant: the plugin gives this capability to no role (owner decision open). */
    private function manager(): int {
        $id = (int) self::factory()->user->create(array('role' => 'shop_manager'));
        get_userdata($id)->add_cap(EVC_Bacs_Payment_Confirmation::CAPABILITY);
        return $id;
    }

    private function order(int $user_id, string $status, string $method = 'bacs'): WC_Order {
        $order = wc_create_order(array('customer_id' => $user_id));
        $order->add_product(wc_get_product(EVC_TEST_CLUB_PRODUCT), 1);
        $order->set_payment_method($method);
        $order->set_currency('EUR');
        $order->calculate_totals();
        $order->save();
        if ($status !== 'pending') {
            if ($status === 'completed') {
                $this->setExpectedDeprecated('pmprommpu_addMembershipLevel'); // verified add-on behaviour with PMPro 3.x
            }
            $order->update_status($status);
        }
        return wc_get_order($order->get_id());
    }

    private static function reference(): string {
        return 'TRF' . strtoupper(bin2hex(random_bytes(5)));
    }

    private static function today(): string {
        return (new DateTimeImmutable('now', new DateTimeZone('Europe/Athens')))->format('Y-m-d');
    }

    private function confirm(int $manager, WC_Order $order, string $reference, array $override = array()): EVC_Bacs_Confirmation_Result {
        return $this->service()->confirm($manager, $override + array(
            'order_id' => $order->get_id(),
            'bank_reference' => $reference,
            'amount_minor' => 2000,
            'currency' => 'EUR',
            'received_on' => self::today(),
        ));
    }

    /** GET /me through the REAL production wiring (constants -> SQL provenance -> reader -> mapper). */
    private function me(int $user_id): array {
        $this->login_as($user_id);
        $r = $this->rest('GET', self::ME, null, $this->nonce());
        $this->assertSame(200, $r->get_status(), wp_json_encode($r->get_data()));
        return $r->get_data()['membership'];
    }

    /** The same real source evaluated at an arbitrary instant (expiry, renewal). */
    private function state_at(int $user_id, DateTimeImmutable $at): array {
        $at = $at->setTimezone(new DateTimeZone('UTC'));
        return EVC_Customer_Membership::describe(EVC_Customer_Plugin::membership_adapter()->entitlement_for($user_id, $at), $at);
    }

    private function pmpro_row(int $user_id): array {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT id, status, startdate, enddate FROM {$wpdb->pmpro_memberships_users} WHERE user_id = %d AND status = 'active' ORDER BY id DESC LIMIT 1", $user_id), ARRAY_A);
    }

    private function movements(int $user_id): array {
        return $this->store()->member_movements($user_id);
    }

    private static function athens_string(DateTimeImmutable $instant): string {
        return $instant->setTimezone(new DateTimeZone('Europe/Athens'))->format('Y-m-d H:i:s');
    }

    /**
     * TEST-ONLY, disposable test database: sets the active PMPro row to EXACTLY the recorded paid
     * run. This stands in for the PMPro date alignment that needs OWNER APPROVAL (see
     * docs/commerce-membership-source.md "Date conflict"); no Club code does this.
     */
    private function align_pmpro_to_recorded_run(int $user_id, DateTimeImmutable $run_start, DateTimeImmutable $run_end): void {
        global $wpdb;
        $row = $this->pmpro_row($user_id);
        $wpdb->update($wpdb->pmpro_memberships_users, array('startdate' => self::athens_string($run_start), 'enddate' => self::athens_string($run_end)), array('id' => $row['id']));
    }

    /** Internal diagnostic flags of the real source (never part of the public /me response). */
    private function flags_now(int $user_id): array {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        return EVC_Customer_Plugin::membership_adapter()->entitlement_for($user_id, $now)->diagnostic_flags();
    }

    // ---- the funded path ------------------------------------------------------------------------

    public function test_manager_confirmation_records_the_athens_period_and_activates_only_when_pmpro_dates_match(): void {
        $customer = $this->customer();
        $manager = $this->manager();
        $order = $this->order($customer, 'on-hold');
        $this->assertSame('pending_payment', $this->me($customer)['state']);

        // The order status alone is never proof; confirming an unpaid order is refused, nothing written.
        $this->assertSame('order_not_completed', $this->confirm($manager, $order, self::reference())->reason);
        $this->assertSame(array(), $this->movements($customer));

        $order->update_status('completed');
        $this->setExpectedDeprecated('pmprommpu_addMembershipLevel');
        $this->assertSame('unverified', $this->me($customer)['state'], 'completed but not confirmed: never active');
        $row_before = $this->pmpro_row($customer);

        $result = $this->confirm($manager, wc_get_order($order->get_id()), self::reference());
        $this->assertSame(EVC_Record_Outcome::NEWLY_RECORDED, $result->outcome, (string) $result->reason);
        $this->assertTrue($result->funded());
        $this->assertTrue($result->audited);

        // The recorded period is the approved Athens month from the verification instant (D1, D5).
        $movement = $this->movements($customer)[0];
        $expected = EVC_Membership_Calendar::next_period(null, $movement->verified_at_utc());
        $this->assertEquals($expected['start'], $result->period->start_utc());
        $this->assertEquals($expected['end'], $result->period->end_utc());
        $this->assertSame(EVC_Funded_Period::KIND_INITIAL, $result->period->kind());

        // Approved rules unchanged: the add-on's own PMPro dates disagree with the recorded period,
        // so the VERIFIED period is reported as a conflict (never shortened, extended or guessed).
        $this->assertSame($row_before, $this->pmpro_row($customer), 'the confirmation never writes PMPro');
        $this->assertSame(array('state' => 'unverified', 'paid_until_utc' => null), $this->me($customer));
        $this->assertContains('verified_paid_period_conflict', $this->flags_now($customer));
        $this->assertNotContains('no_payment_evidence', $this->flags_now($customer));

        // When PMPro's dates equal the recorded run (pending owner-approved alignment), /me is active
        // with the EXACT approved Athens end.
        $this->align_pmpro_to_recorded_run($customer, $expected['start'], $expected['end']);
        $this->assertSame(array('state' => 'active', 'paid_until_utc' => $expected['end']->format('Y-m-d\TH:i:sP')), $this->me($customer));
    }

    public function test_the_known_add_on_date_discrepancy_never_shortens_or_extends_paid_time(): void {
        $customer = $this->customer();
        $order = $this->order($customer, 'completed');
        $result = $this->confirm($this->manager(), $order, self::reference());
        $row = $this->pmpro_row($customer);
        // VERIFIED add-on formula: minute-rounded PHP "+1 month" from its own completion time,
        // started at completion (before the manager's confirmation).
        $this->assertMatchesRegularExpression('/:00$/', $row['enddate']);
        $paid = $result->period;
        $pmpro_end = (new DateTimeImmutable($row['enddate'], new DateTimeZone('Europe/Athens')))->setTimezone(new DateTimeZone('UTC'));
        $this->assertNotEquals($paid->end_utc(), $pmpro_end, 'the two calendars disagree');
        // At every instant around both ends the verified period is reported as a conflict, never as
        // active until the earlier end nor as expired at it: no paid time is silently removed.
        foreach (array(min($paid->end_utc(), $pmpro_end)->modify('-1 second'), min($paid->end_utc(), $pmpro_end), max($paid->end_utc(), $pmpro_end)->modify('-1 second')) as $at) {
            $this->assertSame('unverified', $this->state_at($customer, $at)['state'], $at->format('c'));
        }
    }

    // ---- idempotency and one-payment-one-period ---------------------------------------------------

    public function test_retries_are_idempotent_and_never_fund_a_second_period(): void {
        $customer = $this->customer();
        $manager = $this->manager();
        $order = $this->order($customer, 'completed');
        $reference = self::reference();
        $first = $this->confirm($manager, $order, $reference);
        $later = $this->service(function () {
            return new DateTimeImmutable('+5 minutes', new DateTimeZone('UTC'));
        });
        foreach (array($reference, strtolower($reference), substr($reference, 0, 3) . ' ' . substr($reference, 3)) as $same) {
            // A retry minutes later reuses the RECORDED verification instant: same facts, nothing new.
            $again = $later->confirm($manager, array('order_id' => $order->get_id(), 'bank_reference' => $same, 'amount_minor' => 2000, 'currency' => 'EUR', 'received_on' => self::today()));
            $this->assertSame(EVC_Record_Outcome::ALREADY_RECORDED, $again->outcome, $same);
            $this->assertEquals($first->period->end_utc(), $again->period->end_utc());
        }
        $this->assertCount(1, $this->movements($customer));
    }

    public function test_one_bank_transfer_cannot_fund_two_orders(): void {
        $manager = $this->manager();
        $reference = self::reference();
        $a = $this->customer();
        $this->confirm($manager, $this->order($a, 'completed'), $reference);
        $b = $this->customer();
        $result = $this->confirm($manager, $this->order($b, 'completed'), $reference);
        $this->assertSame(EVC_Record_Outcome::CONFLICT, $result->outcome);
        $this->assertFalse($result->funded());
        $this->assertSame(array(), $this->movements($b));
        $this->assertSame('unverified', $this->me($b)['state']);
    }

    public function test_one_order_cannot_be_funded_by_two_transfers(): void {
        $customer = $this->customer();
        $manager = $this->manager();
        $order = $this->order($customer, 'completed');
        $this->confirm($manager, $order, self::reference());
        $second = $this->confirm($manager, $order, self::reference());
        $this->assertSame(EVC_Record_Outcome::CONFLICT, $second->outcome);
        $this->assertSame('alias_conflict', $second->reason);
        $this->assertCount(1, $this->movements($customer));
    }

    // ---- who may confirm and what ----------------------------------------------------------------

    public function test_only_an_authorised_manager_may_confirm_and_never_their_own_order(): void {
        $customer = $this->customer();
        $order = $this->order($customer, 'completed');
        $staff = $this->create_staff_user();
        get_userdata($staff)->add_cap(EVC_Bacs_Payment_Confirmation::CAPABILITY);
        $self = $customer;
        get_userdata($self)->add_cap(EVC_Bacs_Payment_Confirmation::CAPABILITY);
        $cases = array(
            'shop manager without the capability' => array((int) self::factory()->user->create(array('role' => 'shop_manager')), 'not_authorised'),
            'administrator without the capability' => array((int) self::factory()->user->create(array('role' => 'administrator')), 'not_authorised'),
            'shared Club staff account, even with the capability' => array($staff, 'not_authorised'),
            'the customer on their own order' => array($self, 'own_order'),
            'no user' => array(0, 'not_authorised'),
        );
        foreach ($cases as $label => list($actor, $reason)) {
            $result = $this->confirm($actor, $order, self::reference());
            $this->assertSame(EVC_Bacs_Confirmation_Result::REFUSED, $result->outcome, $label);
            $this->assertSame($reason, $result->reason, $label);
        }
        $this->assertSame(array(), $this->movements($customer));
        $this->assertFalse(get_role('shop_manager')->has_cap(EVC_Bacs_Payment_Confirmation::CAPABILITY), 'no role is given the capability');
        $this->assertFalse(get_role('administrator')->has_cap(EVC_Bacs_Payment_Confirmation::CAPABILITY));
    }

    public function test_the_statement_must_match_a_single_20_eur_bank_transfer_order(): void {
        $manager = $this->manager();
        $customer = $this->customer();
        $order = $this->order($customer, 'completed');
        $tomorrow = (new DateTimeImmutable('tomorrow', new DateTimeZone('Europe/Athens')))->format('Y-m-d');
        $cases = array(
            'amount' => array(array('amount_minor' => 1999), 'amount_not_20_eur'),
            'currency' => array(array('currency' => 'USD'), 'amount_not_20_eur'),
            'reference too short' => array(array('bank_reference' => 'AB12'), 'invalid_request'),
            'reference with symbols only' => array(array('bank_reference' => '----'), 'invalid_request'),
            'value date in the future' => array(array('received_on' => $tomorrow), 'received_date_outside_order_window'),
            'value date before the order' => array(array('received_on' => '2020-01-01'), 'received_date_outside_order_window'),
            'impossible date' => array(array('received_on' => '2026-02-30'), 'invalid_received_date'),
            'order id as text' => array(array('order_id' => (string) $order->get_id()), 'invalid_request'),
        );
        foreach ($cases as $label => list($override, $reason)) {
            $this->assertSame($reason, $this->confirm($manager, $order, self::reference(), $override)->reason, $label);
        }
        $card = $this->customer();
        $this->assertSame('order_not_bank_transfer', $this->confirm($manager, $this->order($card, 'completed', 'paypal'), self::reference())->reason);
        $this->assertSame(array(), $this->movements($customer));
        $this->assertSame(array(), $this->movements($card));
    }

    // ---- corrections, expiry, renewal, failures ---------------------------------------------------

    public function test_a_recorded_refund_or_reversal_ends_the_paid_state(): void {
        $manager = $this->manager();
        foreach (array(EVC_Movement_Correction::KIND_REFUNDED, EVC_Movement_Correction::KIND_REVERSED) as $kind) {
            $customer = $this->customer();
            $paid = $this->confirm($manager, $this->order($customer, 'completed'), self::reference())->period;
            $this->align_pmpro_to_recorded_run($customer, $paid->start_utc(), $paid->end_utc());
            $this->assertSame('active', $this->me($customer)['state']);
            $movement = $this->movements($customer)[0];
            $recorder = new EVC_Provenance_Recorder($this->store(), new EVC_Pmpro_Mapper_Config(array(EVC_TEST_CLUB_LEVEL), '3.8.9', '3.8.10'), 'live', 2000, 'EUR');
            $correction = new EVC_Movement_Correction(hash('sha256', 'corr-' . $kind . $customer), $movement->movement_id(), $kind, EVC_Verified_Movement::AUTHORITY_MANUAL_ADMIN, new DateTimeImmutable('now', new DateTimeZone('UTC')));
            $this->assertSame(EVC_Record_Outcome::NEWLY_RECORDED, $recorder->correct($correction)->outcome());
            $this->assertSame(array('state' => 'unverified', 'paid_until_utc' => null), $this->me($customer), $kind);
        }
    }

    public function test_expiry_and_an_early_renewal_follow_the_athens_chain(): void {
        $customer = $this->customer();
        $manager = $this->manager();
        $first = $this->confirm($manager, $this->order($customer, 'completed'), self::reference());
        $first_end = $first->period->end_utc();

        // Early renewal: a second order, completed and confirmed while the first month runs.
        $renewal = $this->order($customer, 'on-hold');
        $renewal->update_status('completed');
        $renewal = wc_get_order($renewal->get_id());
        $input = array('order_id' => $renewal->get_id(), 'bank_reference' => self::reference(), 'amount_minor' => 2000, 'currency' => 'EUR', 'received_on' => self::today());
        // Ambiguous: two payments verified in the SAME second cannot be ordered -> nothing recorded.
        $same_second = $this->service(function () use ($first) {
            return $this->movements_first_verified_at($first);
        })->confirm($manager, $input);
        $this->assertSame(EVC_Record_Outcome::INDETERMINATE, $same_second->outcome);
        $this->assertSame('same_instant_payment', $same_second->reason);
        $this->assertCount(1, $this->movements($customer));
        $second = $this->service(function () {
            return new DateTimeImmutable('+1 minute', new DateTimeZone('UTC'));
        })->confirm($manager, $input);
        $this->assertSame(EVC_Record_Outcome::NEWLY_RECORDED, $second->outcome, (string) $second->reason);
        $this->assertSame(EVC_Funded_Period::KIND_EARLY, $second->period->kind());
        $this->assertEquals($first_end, $second->period->start_utc(), 'D2: an early renewal starts at the paid end');
        $this->assertEquals(EVC_Membership_Calendar::next_period($first_end, $first_end)['end'], $second->period->end_utc());

        // Without alignment the verified chain conflicts with the add-on's extended dates.
        $this->assertSame('unverified', $this->state_at($customer, $first_end->modify('+10 days'))['state']);
        $this->align_pmpro_to_recorded_run($customer, $first->period->start_utc(), $second->period->end_utc());
        $this->assertSame('active', $this->state_at($customer, $first_end->modify('-1 day'))['state']);
        $in_second_month = $first_end->modify('+10 days');
        $this->assertSame('active', $this->state_at($customer, $in_second_month)['state']);
        $this->assertSame('expired', $this->state_at($customer, $second->period->end_utc())['state']);
    }

    private function movements_first_verified_at(EVC_Bacs_Confirmation_Result $first): DateTimeImmutable {
        return $this->store()->movement($first->period->movement_id())->verified_at_utc();
    }

    public function test_store_failures_never_grant(): void {
        $customer = $this->customer();
        $order = $this->order($customer, 'completed');
        $broken = new EVC_Db_Config(EVC_CLUB_DB_HOST, EVC_CLUB_DB_PORT, 'evc_test_missing_club_db', EVC_CLUB_DB_USER, EVC_CLUB_DB_PASSWORD);
        $provenance = new EVC_Store_Club_Payment_Provenance(function () use ($broken) {
            return new EVC_Sql_Provenance_Store(EVC_Club_Db::connect($broken), function () use ($broken) {
                return EVC_Club_Db::connect($broken);
            }, 'live');
        }, $this->config);
        $adapter = new EVC_Pmpro_Membership_Adapter(new EVC_Wp_Commerce_Pmpro_Reader($this->config, $provenance), $this->config->mapper());
        try {
            $adapter->entitlement_for($customer, new DateTimeImmutable('now', new DateTimeZone('UTC')));
            $this->fail('an unreadable Club database must not produce a membership state');
        } catch (EVC_Membership_Source_Exception $e) {
            $this->assertSame('Membership source unavailable.', $e->getMessage());
        }
        $this->assertSame('unverified', $this->me($customer)['state'], 'the real store is fine and holds nothing for this order');
    }

    public function test_every_attempt_is_audited_without_personal_data(): void {
        $customer = $this->customer();
        $manager = $this->manager();
        $order = $this->order($customer, 'completed');
        $reference = self::reference();
        $this->confirm($manager, $order, $reference, array('amount_minor' => 1));
        $this->confirm($manager, $order, $reference);
        $rows = $this->club->fetch_all("SELECT event_type, outcome, staff_wp_user_id, details_json FROM evc_audit_events WHERE event_type = 'bacs_payment_confirmation' AND staff_wp_user_id = ? ORDER BY id", array($manager));
        $this->assertSame(array('refused', 'newly_recorded'), array_column($rows, 'outcome'));
        $u = get_userdata($customer);
        foreach ($rows as $row) {
            foreach (array($reference, $u->user_email, $u->user_login, (string) $order->get_id() . '"') as $needle) {
                $this->assertStringNotContainsString($needle, (string) $row['details_json']);
            }
        }
    }

    public function test_the_order_lookup_is_bounded_to_the_customer(): void {
        $customer = $this->customer();
        $own = $this->order($customer, 'on-hold');
        for ($i = 0; $i < 5; $i++) {
            $this->order($this->customer(), 'on-hold');
        }
        $sql = array();
        $spy = function ($q) use (&$sql) {
            if (strpos($q, 'woocommerce_order_items') !== false && strpos($q, 'SELECT DISTINCT oi.order_id') !== false) {
                $sql[] = $q;
            }
            return $q;
        };
        add_filter('query', $spy);
        (new EVC_Wp_Commerce_Pmpro_Reader($this->config, new EVC_Unrecorded_Club_Payment_Provenance()))->snapshot($customer);
        remove_filter('query', $spy);
        $this->assertCount(1, $sql);
        global $wpdb;
        $this->assertSame(array((string) $own->get_id()), $wpdb->get_col($sql[0]), 'only the customer\'s own Club orders are loaded');
    }
}
