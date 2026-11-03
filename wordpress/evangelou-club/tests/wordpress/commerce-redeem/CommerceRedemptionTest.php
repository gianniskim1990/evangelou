<?php

/**
 * STAGE-05A: the staff redeem route (v1) on the REAL production wiring — staff flag ON,
 * EVC_CLUB_REDEMPTION_SOURCE = 'club_db_v1', the commerce source, EVC_CLUB_PAYMENT_PROVENANCE =
 * 'club_db_v1' and the disposable Club DB (migrations 001 + 002) of
 * tests/wordpress/support/commerce-site.php — over the REAL WooCommerce 11.2.1 / Paid Memberships
 * Pro 3.8.9 / PMPro WooCommerce 1.11, in both order storages (CI matrix). CI only.
 *
 * A paid member is made the way the existing provenance tests make one: a completed €20 bank-transfer
 * order, the Club's own recorded confirmation (EVC_Bacs_Payment_Confirmation, test-constructed) and
 * the PMPro row aligned to the recorded Athens run (TEST-ONLY stand-in, see BacsConfirmationTest).
 * The evc_members row is seeded directly (member creation / sync is a later task). Nothing here is a
 * real payment.
 */
final class CommerceRedemptionTest extends EVC_WP_Test_Case {
    /** @var EVC_Commerce_Source_Config */
    private $config;
    /** @var EVC_Club_Db */
    private $club;

    public function set_up() {
        parent::set_up();
        $this->assertTrue(defined('WC_VERSION') && defined('PMPRO_VERSION') && defined('PMPROWC_DIR'), 'run with EVC_TEST_COMMERCE_PLUGINS');
        evc_test_commerce_configure();
        $this->config = EVC_Commerce_Source_Config::from_constants();
        $this->club = EVC_Club_Db::connect(EVC_Db_Config::from_constants());
    }

    // ---- helpers ----------------------------------------------------------------------------------

    private function customer(): int {
        return (int) self::factory()->user->create(array('role' => 'customer', 'user_pass' => self::PASSWORD, 'user_email' => 'club.' . wp_generate_password(6, false, false) . '@example.org'));
    }

    /** TEST-ONLY grant: the plugin gives this capability to no role. */
    private function manager(): int {
        $id = (int) self::factory()->user->create(array('role' => 'shop_manager'));
        get_userdata($id)->add_cap(EVC_Bacs_Payment_Confirmation::CAPABILITY);
        return $id;
    }

    private function order(int $user_id, string $status, ?DateTimeImmutable $created = null): WC_Order {
        $order = wc_create_order(array('customer_id' => $user_id));
        $order->add_product(wc_get_product(EVC_TEST_CLUB_PRODUCT), 1);
        $order->set_payment_method('bacs');
        $order->set_currency('EUR');
        if ($created !== null) {
            $order->set_date_created($created->getTimestamp());
        }
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

    private function store(): EVC_Sql_Provenance_Store {
        $db = EVC_Db_Config::from_constants();
        return new EVC_Sql_Provenance_Store(EVC_Club_Db::connect($db), function () use ($db) {
            return EVC_Club_Db::connect($db);
        }, 'live');
    }

    /** Seeds the Club member row (member creation / sync is out of scope). */
    private function member(int $wp_user_id, string $status = 'enabled'): string {
        $id = EVC_Member_Id::mint();
        $this->club->execute(
            'INSERT INTO evc_members (member_public_id, wp_user_id, status, created_at_utc, updated_at_utc) VALUES (?, ?, ?, ?, ?)',
            array($id, $wp_user_id, $status, '2026-09-01 00:00:00.000000', '2026-09-01 00:00:00.000000')
        );
        return $id;
    }

    /**
     * A customer with a recorded, confirmed Club payment verified at $at (default: now), and PMPro
     * aligned to the recorded run. @return array{0:int,1:string,2:EVC_Funded_Period} user, member id, period
     */
    private function paid_member(?DateTimeImmutable $at = null): array {
        $customer = $this->customer();
        $at = $at ?? new DateTimeImmutable((new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s'), new DateTimeZone('UTC'));
        $order = $this->order($customer, 'completed', $at->modify('-2 hours'));
        $confirmation = new EVC_Bacs_Payment_Confirmation($this->config, $this->store(), function () use ($at) {
            return $at;
        }, new EVC_Db_Audit_Log(), $this->club);
        $result = $confirmation->confirm($this->manager(), array(
            'order_id' => $order->get_id(),
            'bank_reference' => 'TRF' . strtoupper(bin2hex(random_bytes(5))),
            'amount_minor' => 2000,
            'currency' => 'EUR',
            'received_on' => $at->setTimezone(new DateTimeZone('Europe/Athens'))->format('Y-m-d'),
        ));
        $this->assertSame(EVC_Record_Outcome::NEWLY_RECORDED, $result->outcome, (string) $result->reason);
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT id FROM {$wpdb->pmpro_memberships_users} WHERE user_id = %d AND status = 'active' ORDER BY id DESC LIMIT 1", $customer), ARRAY_A);
        $athens = new DateTimeZone('Europe/Athens');
        $wpdb->update($wpdb->pmpro_memberships_users, array(
            'startdate' => $result->period->start_utc()->setTimezone($athens)->format('Y-m-d H:i:s'),
            'enddate' => $result->period->end_utc()->setTimezone($athens)->format('Y-m-d H:i:s'),
        ), array('id' => $row['id']));
        return array($customer, $this->member($customer), $result->period);
    }

    private function redeem(string $member, string $coffee = 'espresso', ?string $request_id = null): WP_REST_Response {
        return $this->rest('POST', $this->redeem_route($member), array('request_id' => $request_id ?? self::uuid4(), 'coffee_code' => $coffee), $this->nonce());
    }

    private function ledger(string $member): array {
        return $this->club->fetch_all('SELECT r.business_date, r.coffee_code, r.staff_wp_user_id, r.membership_source, r.membership_status FROM evc_redemptions r JOIN evc_members m ON m.id = r.member_id WHERE m.member_public_id = ? ORDER BY r.id', array($member));
    }

    private function registered_backend(): EVC_Redemption_Backend {
        $routes = rest_get_server()->get_routes();
        $controller = $routes['/' . EVC_Rest_Security::NAMESPACE_V1 . EVC_Rest_Redeem_Controller::ROUTE][0]['callback'][0];
        $property = new ReflectionProperty($controller, 'backend');
        $property->setAccessible(true);
        return $property->getValue($controller);
    }

    /** The real backend with a fixed clock (Athens midnight / DST); everything else from the constants. */
    private function with_clock(EVC_Fixed_Clock $clock): void {
        (new EVC_Rest_Redeem_Controller(new EVC_Club_Redemption_Backend(new EVC_Approved_Coffee_Catalogue_Source(), null, null, $clock)))->register_routes(true);
    }

    // ---- the configured production wiring -----------------------------------------------------------

    public function test_the_configured_route_redeems_a_paid_member_through_the_real_sources(): void {
        $this->assertSame('club_db_v1', EVC_CLUB_REDEMPTION_SOURCE);
        $this->assertSame('club_db_v1', EVC_CLUB_PAYMENT_PROVENANCE);
        $this->assertInstanceOf(EVC_Club_Redemption_Backend::class, $this->registered_backend());
        list(, $member) = $this->paid_member();
        $staff = $this->create_staff_user();
        $this->login_as($staff);
        $request_id = self::uuid4();

        $athens = new DateTimeZone('Europe/Athens');
        $day_before = (new DateTimeImmutable('now', $athens))->format('Y-m-d');
        $response = $this->redeem($member, 'other_coffee', $request_id);
        $day_after = (new DateTimeImmutable('now', $athens))->format('Y-m-d');

        $this->assertSame(200, $response->get_status(), wp_json_encode($response->get_data()));
        $data = $response->get_data();
        $this->assertSame($member, $data['member_id']);
        $this->assertSame('used', $data['state']);
        $this->assertSame('other_coffee', $data['coffee_code'], 'the catch-all coffee is accepted (owner decision D3)');
        $this->assertSame($request_id, $data['request_id']);
        $this->assertFalse($data['replayed']);
        // The real system clock: the Athens day read before or after the request (never flaky at midnight).
        $this->assertContains($data['business_date'], array($day_before, $day_after));
        $ledger = $this->ledger($member);
        $this->assertCount(1, $ledger);
        $this->assertSame($staff, (int) $ledger[0]['staff_wp_user_id']);
        $this->assertSame('pmpro', $ledger[0]['membership_source']);
        $this->assertSame('active', $ledger[0]['membership_status']);
        $this->assertNull($this->registered_backend()->last_reason());
    }

    public function test_a_replay_of_the_same_request_returns_the_original_result(): void {
        list(, $member) = $this->paid_member();
        $this->login_as($this->create_staff_user());
        $request_id = self::uuid4();
        $first = $this->redeem($member, 'cappuccino', $request_id);
        $this->assertSame(200, $first->get_status(), wp_json_encode($first->get_data()));
        $again = $this->redeem($member, 'cappuccino', $request_id);
        $this->assertSame(200, $again->get_status());
        $this->assertTrue($again->get_data()['replayed']);
        $this->assertSame($first->get_data()['redeemed_at'], $again->get_data()['redeemed_at']);
        $this->assertCount(1, $this->ledger($member));
        $this->assertEnvelope($this->redeem($member, 'espresso', $request_id), 409, 'idempotency_key_reused');
    }

    public function test_one_coffee_per_athens_day_across_midnight_and_the_autumn_dst_change(): void {
        // Paid run 2026-10-10 10:00:07Z .. 2026-11-10; the clocks go back on Sunday 2026-10-25 (04:00 EEST -> 03:00 EET).
        list(, $member, $period) = $this->paid_member(new DateTimeImmutable('2026-10-10T10:00:07Z'));
        $this->login_as($this->create_staff_user());
        $clock = new EVC_Fixed_Clock('2026-10-24T20:59:59Z');
        $this->with_clock($clock);

        $r = $this->redeem($member);
        $this->assertSame(200, $r->get_status(), wp_json_encode($r->get_data()));
        $this->assertSame('2026-10-24', $r->get_data()['business_date']);
        $this->assertSame('2026-10-24T23:59:59+03:00', $r->get_data()['redeemed_at']);

        $clock->set('2026-10-24T21:00:00Z'); // Athens midnight (EEST)
        $r = $this->redeem($member, 'greek_coffee');
        $this->assertSame(200, $r->get_status(), wp_json_encode($r->get_data()));
        $this->assertSame('2026-10-25', $r->get_data()['business_date']);
        $this->assertSame('2026-10-25T00:00:00+03:00', $r->get_data()['redeemed_at']);

        $clock->set('2026-10-25T21:59:59Z'); // 23:59:59 EET: the same 25-hour Athens day
        $same_day = $this->redeem($member, 'filter_coffee');
        $this->assertEnvelope($same_day, 409, 'benefit_already_redeemed');
        $this->assertSame(array('business_date' => '2026-10-25', 'redeemed_at' => '2026-10-25T00:00:00+03:00'), $same_day->get_data()['error']['details']);

        $clock->set('2026-10-25T22:00:00Z'); // Athens midnight (EET)
        $r = $this->redeem($member, 'freddo_espresso');
        $this->assertSame(200, $r->get_status(), wp_json_encode($r->get_data()));
        $this->assertSame('2026-10-26', $r->get_data()['business_date']);
        $this->assertSame('2026-10-26T00:00:00+02:00', $r->get_data()['redeemed_at']);

        $this->assertSame(array('2026-10-24', '2026-10-25', '2026-10-26'), array_column($this->ledger($member), 'business_date'));

        // The recorded run is one Athens calendar month: 13:00:07 EEST on 10 October to 13:00:07 EET on
        // 10 November = 11:00:07Z (one hour later in UTC across the DST change).
        $this->assertSame('2026-11-10T11:00:07+00:00', $period->end_utc()->format(DATE_ATOM));
        // D1: the end is exclusive. EXACTLY at the recorded end, on a day with nothing redeemed yet: expired
        // (membership_inactive, not benefit_already_redeemed: membership is checked before the daily limit).
        $clock->set('2026-11-10T11:00:07Z');
        $at_end = $this->redeem($member);
        $this->assertEnvelope($at_end, 409, 'membership_inactive');
        $this->assertSame(array('status' => 'expired'), $at_end->get_data()['error']['details']);
        $this->assertCount(3, $this->ledger($member));
        $clock->set('2026-11-10T11:00:06Z'); // one second before the end, same (still unused) Athens day: allowed
        $last = $this->redeem($member);
        $this->assertSame(200, $last->get_status(), wp_json_encode($last->get_data()));
        $this->assertSame('2026-11-10', $last->get_data()['business_date']);
        $this->assertSame('2026-11-10T13:00:06+02:00', $last->get_data()['redeemed_at']);
        $clock->set('2026-11-11T11:00:07Z'); // the next Athens day, after the paid run's end: expired
        $expired = $this->redeem($member);
        $this->assertEnvelope($expired, 409, 'membership_inactive');
        $this->assertSame(array('status' => 'expired'), $expired->get_data()['error']['details']);
        $this->assertCount(4, $this->ledger($member));
    }

    public function test_a_second_request_on_the_same_athens_day_is_already_redeemed(): void {
        list(, $member) = $this->paid_member(new DateTimeImmutable('2026-10-10T10:00:07Z'));
        $this->login_as($this->create_staff_user());
        $clock = new EVC_Fixed_Clock('2026-10-12T06:00:00Z');
        $this->with_clock($clock);
        $this->assertSame(200, $this->redeem($member)->get_status());
        $clock->set('2026-10-12T18:00:00Z');
        $again = $this->redeem($member, 'cappuccino');
        $this->assertEnvelope($again, 409, 'benefit_already_redeemed');
        $this->assertSame('2026-10-12', $again->get_data()['error']['details']['business_date']);
        $this->assertCount(1, $this->ledger($member));
    }

    // ---- never eligible ------------------------------------------------------------------------------

    public function test_pending_or_unconfirmed_payments_never_grant_a_coffee(): void {
        $this->login_as($this->create_staff_user());
        $cases = array();
        $none = $this->customer();
        $cases['no order'] = $this->member($none);
        foreach (array('on-hold', 'pending', 'processing') as $status) {
            $user = $this->customer();
            $this->order($user, $status);
            $cases[$status] = $this->member($user);
        }
        $completed = $this->customer();
        $this->order($completed, 'completed');
        $cases['completed without recorded Club payment'] = $this->member($completed);
        foreach ($cases as $label => $member) {
            $response = $this->redeem($member);
            $this->assertEnvelope($response, 409, 'membership_inactive');
            $this->assertSame(array('status' => 'inactive'), $response->get_data()['error']['details'], $label);
            $this->assertSame(array(), $this->ledger($member), $label);
        }
    }

    public function test_a_recorded_refund_or_reversal_freezes_the_card(): void {
        $this->login_as($this->create_staff_user());
        foreach (array(EVC_Movement_Correction::KIND_REFUNDED, EVC_Movement_Correction::KIND_REVERSED) as $kind) {
            list($customer, $member) = $this->paid_member(new DateTimeImmutable('2026-10-10T10:00:07Z'));
            $clock = new EVC_Fixed_Clock('2026-10-12T08:00:00Z');
            $this->with_clock($clock);
            $before = $this->redeem($member);
            $this->assertSame(200, $before->get_status(), $kind . ': paid before the correction ' . wp_json_encode($before->get_data()));

            // The cancellation / refund as the Club records it (EVC_Provenance_Recorder::correct, the path
            // STAGE-03D uses for a WooCommerce cancel / refund).
            $movement = $this->store()->member_movements($customer)[0];
            $recorder = new EVC_Provenance_Recorder($this->store(), new EVC_Pmpro_Mapper_Config(array(EVC_TEST_CLUB_LEVEL), '3.8.9', '3.8.10'), 'live', 2000, 'EUR');
            $correction = new EVC_Movement_Correction(hash('sha256', 'stage05a-corr-' . $kind . $customer), $movement->movement_id(), $kind, EVC_Verified_Movement::AUTHORITY_MANUAL_ADMIN, new DateTimeImmutable('2026-10-12 09:00:00', new DateTimeZone('UTC')));
            $this->assertSame(EVC_Record_Outcome::NEWLY_RECORDED, $recorder->correct($correction)->outcome(), $kind);

            $clock->set('2026-10-13T08:00:00Z'); // the next Athens day, inside the (now voided) paid run
            $after = $this->redeem($member);
            $this->assertEnvelope($after, 409, 'membership_inactive');
            $this->assertSame(array('status' => 'inactive'), $after->get_data()['error']['details'], $kind);
            $this->assertCount(1, $this->ledger($member), $kind . ': nothing granted after the correction');
        }
    }

    public function test_unknown_or_disabled_members_are_not_found_and_unknown_coffee_is_refused(): void {
        $this->login_as($this->create_staff_user());
        $this->assertEnvelope($this->redeem(EVC_Member_Id::mint()), 404, 'member_not_found');
        list($customer) = $this->paid_member();
        $this->club->execute("UPDATE evc_members SET status = 'disabled' WHERE wp_user_id = ?", array($customer));
        $disabled = $this->club->fetch_one('SELECT member_public_id FROM evc_members WHERE wp_user_id = ?', array($customer))['member_public_id'];
        $this->assertEnvelope($this->redeem($disabled), 404, 'member_not_found');
        list(, $member) = $this->paid_member();
        $this->assertEnvelope($this->redeem($member, 'mocha_latte'), 400, 'invalid_coffee');
        $this->assertSame(array(), $this->ledger($member));
    }

    // ---- Club DB down ---------------------------------------------------------------------------------

    public function test_club_db_down_is_503_and_logs_no_secret(): void {
        list(, $member) = $this->paid_member();
        $this->login_as($this->create_staff_user());
        $lines = array();
        $backend = new EVC_Club_Redemption_Backend(new EVC_Approved_Coffee_Catalogue_Source(), function () {
            $env = EVC_Club_Redemption_Backend::constants();
            $env['db'] = new EVC_Db_Config(EVC_CLUB_DB_HOST, EVC_CLUB_DB_PORT, 'evc_test_missing_club_db', EVC_CLUB_DB_USER, EVC_CLUB_DB_PASSWORD);
            return $env;
        }, null, null, function (string $line) use (&$lines) {
            $lines[] = $line;
        });
        (new EVC_Rest_Redeem_Controller($backend))->register_routes(true);
        $this->assertEnvelope($this->redeem($member), 503, 'server_error');
        $this->assertSame(EVC_Club_Redemption_Backend::REASON_CONNECT, $backend->last_reason());
        $this->assertCount(1, $lines);
        $this->assertSame('Evangelou Club: staff redemption unavailable (club_db_unavailable, EVC_Db_Exception).', $lines[0], 'a fixed reason and an exception class only');
        $this->assertSame(array(), $this->ledger($member));
    }

    public function test_the_provenance_store_failing_mid_request_never_grants(): void {
        list(, $member) = $this->paid_member();
        $this->login_as($this->create_staff_user());
        // The configured route's backend connects and finds schema 002; the provenance read then fails.
        $this->assertInstanceOf(EVC_Club_Redemption_Backend::class, $this->registered_backend());
        $this->club->execute('RENAME TABLE evc_pay_movements TO evc_pay_movements_hidden');
        try {
            $response = $this->redeem($member);
        } finally {
            $this->club->execute('RENAME TABLE evc_pay_movements_hidden TO evc_pay_movements');
        }
        $this->assertEnvelope($response, 500, 'server_error');
        $this->assertSame(array(), $this->ledger($member));
    }
}
