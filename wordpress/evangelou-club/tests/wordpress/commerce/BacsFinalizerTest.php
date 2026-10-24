<?php

/**
 * STAGE-03B.3a: the INTERNAL issued → recorded finalization over the REAL
 * WooCommerce 11.2.1 / PMPro 3.8.9 / PMPro WooCommerce 1.11 (HPOS or legacy,
 * per EVC_TEST_WC_ORDER_STORAGE) and the REAL SQL provenance store on the
 * disposable Club database. Nothing here is a real payment.
 *
 * TEST-ONLY: issued() completes the order while a test filter gives PMPro
 * the plan's dates (the STAGE-03B.3b issuance step, NOT implemented). The
 * finalizer itself never completes orders or touches PMPro.
 */
final class BacsFinalizerTest extends EVC_WP_Test_Case {
    const ME = '/evangelou-club-customer/v1/me';

    /** @var EVC_Commerce_Source_Config */
    private $config;
    /** @var string[] raw references used (must never be stored anywhere) */
    private $references = array();
    /** @var wpdb[] */
    private $connections = array();

    public function set_up() {
        parent::set_up();
        evc_test_commerce_configure();
        $this->config = EVC_Commerce_Source_Config::from_constants();
    }

    public function tear_down() {
        foreach ($this->connections as $db) {
            $db->close();
        }
        remove_all_filters('query');
        remove_all_filters('pmprowoo_checkout_level', 20);
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

    private function finalizer(?EVC_Provenance_Store $store = null, ?wpdb $db = null): EVC_Bacs_Payment_Finalizer {
        $store = $store ?? $this->club();
        $intents = $this->intents();
        return new EVC_Bacs_Payment_Finalizer($this->config, $store, $intents, new EVC_Bacs_Recovery_Inspector($this->config, $store, $intents), $db ?? $GLOBALS['wpdb'], function () {
            return new DateTimeImmutable('now', new DateTimeZone('UTC'));
        }, new EVC_Db_Audit_Log(), EVC_Club_Db::connect(EVC_Db_Config::from_constants()), 1);
    }

    public function test_preconditions_and_recording_must_use_the_same_club_record(): void {
        $intents = $this->intents();
        $this->expectException(InvalidArgumentException::class);
        new EVC_Bacs_Payment_Finalizer($this->config, $this->club(), $intents, new EVC_Bacs_Recovery_Inspector($this->config, $this->club(), $intents), $GLOBALS['wpdb'], function () {
            return new DateTimeImmutable('now', new DateTimeZone('UTC'));
        });
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

    private function order(int $user_id, ?DateTimeImmutable $created = null): WC_Order {
        $order = wc_create_order(array('customer_id' => $user_id));
        $order->add_product(wc_get_product(EVC_TEST_CLUB_PRODUCT), 1);
        $order->set_payment_method('bacs');
        $order->set_currency('EUR');
        $order->set_billing_email('club.billing@example.org');
        if ($created !== null) {
            $order->set_date_created($created->getTimestamp());
        }
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

    private static function whole_second(DateTimeImmutable $t): DateTimeImmutable {
        return new DateTimeImmutable($t->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'), new DateTimeZone('UTC'));
    }

    private static function athens_date(DateTimeImmutable $t): string {
        return $t->setTimezone(new DateTimeZone('Europe/Athens'))->format('Y-m-d');
    }

    private function active_row(int $user_id): ?array {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare("SELECT id, startdate, enddate FROM {$wpdb->pmpro_memberships_users} WHERE user_id = %d AND membership_id = %d AND status = 'active'", $user_id, $this->config->level_id), ARRAY_A);
        $this->assertLessThanOrEqual(1, count($rows));
        return $rows === array() ? null : $rows[0];
    }

    private function active_row_ref(int $user_id): ?string {
        $row = $this->active_row($user_id);
        return $row === null ? null : $this->config->ref('pmpro_membership_row', (string) $row['id']);
    }

    private static function local(DateTimeImmutable $i): string {
        return $i->setTimezone(wp_timezone())->format('Y-m-d H:i:s');
    }

    /**
     * An ISSUED intent: the planned attempt, the order completed with the plan's dates
     * (TEST-ONLY stand-in for STAGE-03B.3b), and the intent moved to "issued".
     *
     * @return array{0:WC_Order,1:EVC_Bacs_Intent,2:array} order, issued intent, attestation
     */
    private function issued(int $customer, int $actor, ?DateTimeImmutable $at = null): array {
        $at = self::whole_second($at ?? new DateTimeImmutable('-30 seconds', new DateTimeZone('UTC')));
        $order = $this->order($customer, $at->modify('-1 hour'));
        $reference = $this->reference();
        $movement = $this->config->ref('bacs_transfer', $reference);
        $anchor = $this->config->ref('wc_order', (string) $order->get_id());
        $club = $this->club();
        $plan = $this->recorder($club)->plan(new EVC_Prospective_Payment($movement, 'live', $customer, $this->config->level_id, $at, 2000, 'EUR', array(new EVC_Movement_Alias(EVC_Movement_Alias::NS_WC_ORDER, $anchor)), EVC_Verified_Movement::AUTHORITY_MANUAL_ADMIN, EVC_Payment_Evidence::KIND_MANUAL_CONFIRMATION));
        $this->assertSame(EVC_Period_Plan::PLANNED, $plan->outcome(), (string) $plan->reason());
        $planned = EVC_Bacs_Intent::planned('live', (int) $order->get_id(), $customer, $this->config->level_id, $movement, $anchor, $actor, $at, $plan, EVC_Paid_Run::start_for($club, $plan), $plan->kind() === EVC_Funded_Period::KIND_EARLY ? $this->active_row_ref($customer) : null);
        $lock = EVC_Member_Lock::acquire($GLOBALS['wpdb'], $customer, 1);
        $this->assertSame(EVC_Bacs_Intent_Write::WRITTEN, $this->intents()->create($lock, $planned)->outcome);
        $filter = function (array $level) use ($planned) {
            $level['startdate'] = "'" . self::local($planned->run_start_utc()) . "'";
            $level['enddate'] = self::local($planned->period_end_utc());
            return $level;
        };
        add_filter('pmprowoo_checkout_level', $filter, 20);
        $this->setExpectedDeprecated('pmprommpu_addMembershipLevel');
        wc_get_order($order->get_id())->update_status('completed');
        remove_filter('pmprowoo_checkout_level', $filter, 20);
        $issued = $planned->issued($this->active_row_ref($customer));
        $this->assertSame(EVC_Bacs_Intent_Write::WRITTEN, $this->intents()->transition($lock, $planned, $issued)->outcome);
        $lock->release();
        $attestation = array('bank_reference' => $reference, 'amount_minor' => 2000, 'currency' => 'EUR', 'received_on' => self::athens_date($at));
        return array(wc_get_order($order->get_id()), $issued, $attestation);
    }

    private function me(int $user_id): array {
        $this->login_as($user_id);
        $r = $this->rest('GET', self::ME, null, $this->nonce());
        $this->assertSame(200, $r->get_status(), wp_json_encode($r->get_data()));
        wp_set_current_user(0);
        return $r->get_data()['membership'];
    }

    private function second_connection(): wpdb {
        $db = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
        $db->set_prefix($GLOBALS['wpdb']->prefix);
        $this->connections[] = $db;
        return $db;
    }

    private function periods(int $user_id): array {
        $club = $this->club();
        return array_map(function (EVC_Verified_Movement $m) use ($club) {
            return $club->period_for_movement($m->movement_id());
        }, $club->member_movements($user_id));
    }

    private function intent_state(WC_Order $order): string {
        $read = $this->intents()->read($order->get_id());
        $this->assertSame(EVC_Bacs_Intent_Read::VALID, $read->outcome, (string) $read->reason);
        return $read->intent->state();
    }

    private function assertResult(EVC_Bacs_Finalization_Result $r, string $outcome, ?string $reason = null): void {
        $this->assertSame($outcome, $r->outcome, 'reason: ' . (string) $r->reason);
        if ($reason !== null) {
            $this->assertSame($reason, $r->reason);
        }
    }

    private function assertNothingFunded(int $customer, WC_Order $order): void {
        $this->assertSame(array(), $this->club()->member_movements($customer));
        $this->assertSame(EVC_Bacs_Intent::STATE_ISSUED, $this->intent_state($order));
        $this->assertNotSame('active', $this->me($customer)['state']);
    }

    // ---- 1, 2, 20: success, repeated retries, one funded period ----------------------------------------

    public function test_issued_intent_is_recorded_once_and_retries_add_nothing(): void {
        $customer = $this->customer();
        $actor = $this->manager();
        list($order, $issued, $attestation) = $this->issued($customer, $actor);
        $this->assertSame('unverified', $this->me($customer)['state'], 'issued but unrecorded is not paid');

        $r = $this->finalizer()->finalize($actor, $order->get_id(), $attestation);
        $this->assertResult($r, EVC_Bacs_Finalization_Result::RECORDED);
        $this->assertNull($r->reason);
        $this->assertTrue($r->intent_finalized);
        $this->assertTrue($r->audited);
        $this->assertTrue($issued->as_plan()->matches_recorded($r->period));
        $this->assertSame(EVC_Bacs_Intent::STATE_RECORDED, $this->intent_state($order));
        $me = $this->me($customer);
        $this->assertSame('active', $me['state']);
        $this->assertSame($issued->period_end_utc()->format(DATE_ATOM), $me['paid_until_utc']);

        foreach (array($actor, $this->manager(), $actor) as $who) {
            $again = $this->finalizer()->finalize($who, $order->get_id(), $attestation);
            $this->assertResult($again, EVC_Bacs_Finalization_Result::ALREADY_RECORDED);
            $this->assertTrue($again->intent_finalized);
        }
        $this->assertCount(1, $this->periods($customer), 'one genuine payment, one funded period');
    }

    public function test_statement_formatting_is_normalised_like_the_existing_rule(): void {
        $customer = $this->customer();
        $actor = $this->manager();
        list($order, , $attestation) = $this->issued($customer, $actor);
        $attestation['bank_reference'] = ' ' . strtolower(substr($attestation['bank_reference'], 0, 5)) . '-' . substr($attestation['bank_reference'], 5) . ' ';
        $this->assertResult($this->finalizer()->finalize($actor, $order->get_id(), $attestation), EVC_Bacs_Finalization_Result::RECORDED);
    }

    // ---- 3: two managers ---------------------------------------------------------------------------------

    public function test_two_concurrent_managers_record_at_most_one_period(): void {
        $customer = $this->customer();
        list($order, , $attestation) = $this->issued($customer, $this->manager());
        $a = $this->manager();
        $b = $this->manager();
        $other_session = $this->second_connection();
        $held = EVC_Member_Lock::acquire($other_session, $customer, 0);
        $this->assertNotNull($held, 'manager A is mid-finalization');
        $this->assertResult($this->finalizer()->finalize($b, $order->get_id(), $attestation), EVC_Bacs_Finalization_Result::BUSY, 'member_locked');
        $this->assertSame(array(), $this->club()->member_movements($customer));
        $held->release();

        $this->assertResult($this->finalizer(null, $other_session)->finalize($a, $order->get_id(), $attestation), EVC_Bacs_Finalization_Result::RECORDED);
        $this->assertResult($this->finalizer()->finalize($b, $order->get_id(), $attestation), EVC_Bacs_Finalization_Result::ALREADY_RECORDED);
        $this->assertCount(1, $this->periods($customer));
    }

    // ---- 4, 21: authorisation -----------------------------------------------------------------------------

    public function test_only_an_authorised_manager_may_finalize(): void {
        $customer = $this->customer();
        list($order, , $attestation) = $this->issued($customer, $this->manager());
        $staff = $this->create_staff_user();
        get_userdata($staff)->add_cap(EVC_Bacs_Payment_Confirmation::CAPABILITY);
        foreach (array((int) self::factory()->user->create(array('role' => 'shop_manager')), (int) self::factory()->user->create(array('role' => 'administrator')), $staff, 0, 999999) as $actor) {
            $this->assertResult($this->finalizer()->finalize($actor, $order->get_id(), $attestation), EVC_Bacs_Finalization_Result::REFUSED, 'not_authorised');
        }
        get_userdata($customer)->add_cap(EVC_Bacs_Payment_Confirmation::CAPABILITY);
        $this->assertResult($this->finalizer()->finalize($customer, $order->get_id(), $attestation), EVC_Bacs_Finalization_Result::REFUSED, 'own_order');
        $this->assertNothingFunded($customer, $order);
    }

    // ---- 5: bank attestation ------------------------------------------------------------------------------

    public function test_the_attestation_must_describe_exactly_this_transfer(): void {
        $customer = $this->customer();
        $actor = $this->manager();
        list($order, $issued, $attestation) = $this->issued($customer, $actor);
        $cases = array(
            'attestation_does_not_match_intent' => array('bank_reference' => $this->reference()),
            'invalid_attestation' => array('bank_reference' => 'x'),
            'amount_not_20_eur' => array('amount_minor' => 1999),
        );
        foreach ($cases as $reason => $change) {
            $this->assertResult($this->finalizer()->finalize($actor, $order->get_id(), $change + $attestation), EVC_Bacs_Finalization_Result::REFUSED, $reason);
        }
        $this->assertResult($this->finalizer()->finalize($actor, $order->get_id(), array('currency' => 'USD') + $attestation), EVC_Bacs_Finalization_Result::REFUSED, 'amount_not_20_eur');
        $this->assertResult($this->finalizer()->finalize($actor, $order->get_id(), array('amount_minor' => '2000') + $attestation), EVC_Bacs_Finalization_Result::REFUSED, 'amount_not_20_eur');
        $missing = $attestation;
        unset($missing['bank_reference']);
        $this->assertResult($this->finalizer()->finalize($actor, $order->get_id(), $missing), EVC_Bacs_Finalization_Result::REFUSED, 'invalid_attestation');
        $zone = new DateTimeZone('Europe/Athens');
        foreach (array(
            (new DateTimeImmutable('-5 days', $zone))->format('Y-m-d'),  // before the order
            (new DateTimeImmutable('+2 days', $zone))->format('Y-m-d'),  // in the future
            '2026-02-30',
        ) as $date) {
            $r = $this->finalizer()->finalize($actor, $order->get_id(), array('received_on' => $date) + $attestation);
            $this->assertSame(EVC_Bacs_Finalization_Result::REFUSED, $r->outcome, $date);
        }
        $this->assertNothingFunded($customer, $order);
    }

    public function test_money_received_after_the_signed_confirmation_instant_is_refused(): void {
        $customer = $this->customer();
        $actor = $this->manager();
        list($order, , $attestation) = $this->issued($customer, $actor, new DateTimeImmutable('-3 days'));
        $attestation['received_on'] = (new DateTimeImmutable('now', new DateTimeZone('Europe/Athens')))->format('Y-m-d');
        $this->assertResult($this->finalizer()->finalize($actor, $order->get_id(), $attestation), EVC_Bacs_Finalization_Result::REFUSED, 'received_date_outside_order_window');
        $this->assertNothingFunded($customer, $order);
    }

    public function test_a_reference_already_recorded_for_another_order_is_a_conflict(): void {
        $customer = $this->customer();
        $actor = $this->manager();
        list($first, , $attestation) = $this->issued($customer, $actor, new DateTimeImmutable('-1 hour'));
        $this->assertResult($this->finalizer()->finalize($actor, $first->get_id(), $attestation), EVC_Bacs_Finalization_Result::RECORDED);
        // The same transfer can never even be planned for another order...
        $reuse = new EVC_Prospective_Payment($this->config->ref('bacs_transfer', $attestation['bank_reference']), 'live', $customer, $this->config->level_id, self::whole_second(new DateTimeImmutable('now', new DateTimeZone('UTC'))), 2000, 'EUR', array(new EVC_Movement_Alias(EVC_Movement_Alias::NS_WC_ORDER, $this->config->ref('wc_order', 'another-order'))), EVC_Verified_Movement::AUTHORITY_MANUAL_ADMIN, EVC_Payment_Evidence::KIND_MANUAL_CONFIRMATION);
        $this->assertSame('order_anchor_conflict', $this->recorder($this->club())->plan($reuse)->reason());
        // ...and attesting it for another customer's issued attempt is refused.
        $other = $this->customer();
        list($second, $issued2) = $this->issued($other, $actor);
        $this->assertResult($this->finalizer()->finalize($actor, $second->get_id(), $attestation), EVC_Bacs_Finalization_Result::REFUSED, 'attestation_does_not_match_intent');
        $this->assertSame(array(), $this->club()->member_movements($other));
        $this->assertNotSame($issued2->movement_id(), $this->config->ref('bacs_transfer', $attestation['bank_reference']));
    }

    // ---- 6, 7: forged intent, changed order -----------------------------------------------------------------

    public function test_a_tampered_intent_or_a_changed_order_is_never_finalized(): void {
        global $wpdb;
        $customer = $this->customer();
        $actor = $this->manager();
        list($order, , $attestation) = $this->issued($customer, $actor);
        $table = \Automattic\WooCommerce\Utilities\OrderUtil::get_table_for_order_meta();
        $id_column = $table === $wpdb->postmeta ? 'post_id' : 'order_id';
        $stored = $wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$table} WHERE {$id_column} = %d AND meta_key = %s", $order->get_id(), EVC_Wc_Bacs_Intent_Store::META_KEY));
        $wpdb->update($table, array('meta_value' => str_replace('"revision":2', '"revision":3', $stored)), array($id_column => $order->get_id(), 'meta_key' => EVC_Wc_Bacs_Intent_Store::META_KEY));
        $this->assertResult($this->finalizer()->finalize($actor, $order->get_id(), $attestation), EVC_Bacs_Finalization_Result::CONFLICT, 'intent_signature_mismatch');
        $wpdb->update($table, array('meta_value' => $stored), array($id_column => $order->get_id(), 'meta_key' => EVC_Wc_Bacs_Intent_Store::META_KEY));

        $o = wc_get_order($order->get_id());
        $o->set_total('19.00');
        $o->save();
        $this->assertResult($this->finalizer()->finalize($actor, $order->get_id(), $attestation), EVC_Bacs_Finalization_Result::CONFLICT, 'order_changed');
        $o->set_total('20.00');
        $o->set_payment_method('cod');
        $o->save();
        $this->assertResult($this->finalizer()->finalize($actor, $order->get_id(), $attestation), EVC_Bacs_Finalization_Result::CONFLICT, 'order_changed');
        $o->set_payment_method('bacs');
        $o->set_customer_id($this->customer());
        $o->save();
        $this->assertResult($this->finalizer()->finalize($actor, $order->get_id(), $attestation), EVC_Bacs_Finalization_Result::CONFLICT, 'intent_cross_customer');
        $this->assertSame(array(), $this->club()->member_movements($customer));
    }

    // ---- 8, 9: PMPro row missing or wrong dates ----------------------------------------------------------------

    public function test_the_issued_pmpro_row_must_exist_with_the_exact_athens_dates(): void {
        global $wpdb;
        $customer = $this->customer();
        $actor = $this->manager();
        list($order, , $attestation) = $this->issued($customer, $actor);
        $row = $this->active_row($customer);
        foreach (array(array('enddate' => date('Y-m-d H:i:s', strtotime($row['enddate'] . ' +1 hour'))), array('startdate' => date('Y-m-d H:i:s', strtotime($row['startdate'] . ' -1 second')))) as $shift) {
            $wpdb->update($wpdb->pmpro_memberships_users, $shift, array('id' => $row['id']));
            $this->assertResult($this->finalizer()->finalize($actor, $order->get_id(), $attestation), EVC_Bacs_Finalization_Result::MANAGER_REQUIRED, 'issuance_mismatch');
            $wpdb->update($wpdb->pmpro_memberships_users, array('startdate' => $row['startdate'], 'enddate' => $row['enddate']), array('id' => $row['id']));
        }
        $wpdb->update($wpdb->pmpro_memberships_users, array('status' => 'inactive'), array('id' => $row['id']));
        $this->assertResult($this->finalizer()->finalize($actor, $order->get_id(), $attestation), EVC_Bacs_Finalization_Result::MANAGER_REQUIRED, 'membership_row_missing');
        $this->assertNothingFunded($customer, $order);
    }

    // ---- 10, 16, 18: already recorded / crash after commit / restart --------------------------------------------

    public function test_a_payment_recorded_by_a_crashed_run_is_finished_without_a_second_month(): void {
        $customer = $this->customer();
        $actor = $this->manager();
        list($order, $issued, $attestation) = $this->issued($customer, $actor);
        // The earlier run committed to the Club database and died before the intent transition.
        $m = new EVC_Verified_Movement($issued->movement_id(), 'live', $customer, $this->config->level_id, $issued->new_row_ref(), $issued->verified_at_utc(), 2000, 'EUR', array(new EVC_Movement_Alias(EVC_Movement_Alias::NS_WC_ORDER, $issued->order_anchor())), EVC_Verified_Movement::AUTHORITY_MANUAL_ADMIN, EVC_Payment_Evidence::KIND_MANUAL_CONFIRMATION, EVC_Verified_Movement::STATE_CONFIRMED);
        $this->assertSame(EVC_Record_Outcome::NEWLY_RECORDED, $this->recorder($this->club())->record_expecting($m, $issued->as_plan())->outcome());
        $this->assertSame(EVC_Bacs_Intent::STATE_ISSUED, $this->intent_state($order));
        wp_cache_flush(); // a new request after the restart

        $r = $this->finalizer()->finalize($actor, $order->get_id(), $attestation);
        $this->assertResult($r, EVC_Bacs_Finalization_Result::ALREADY_RECORDED);
        $this->assertTrue($r->intent_finalized);
        $this->assertSame(EVC_Bacs_Intent::STATE_RECORDED, $this->intent_state($order));
        $this->assertCount(1, $this->periods($customer));
        $this->assertSame('active', $this->me($customer)['state']);
    }

    // ---- 11: refunds / reversals ---------------------------------------------------------------------------------

    public function test_a_recorded_refund_or_reversal_stops_finalization(): void {
        $customer = $this->customer();
        $actor = $this->manager();
        list($order, $issued, $attestation) = $this->issued($customer, $actor);
        // Refund verified BEFORE the payment is recorded: an unresolved obligation.
        $refund = new EVC_Movement_Correction($this->config->ref('bacs_refund', $this->reference()), $issued->movement_id(), EVC_Movement_Correction::KIND_REFUNDED, EVC_Verified_Movement::AUTHORITY_MANUAL_ADMIN, self::whole_second(new DateTimeImmutable('now', new DateTimeZone('UTC'))));
        $this->assertSame(EVC_Record_Outcome::QUARANTINED, $this->recorder($this->club())->correct($refund)->outcome());
        $this->assertResult($this->finalizer()->finalize($actor, $order->get_id(), $attestation), EVC_Bacs_Finalization_Result::MANAGER_REQUIRED, 'correction_recorded_for_transfer');
        $this->assertNothingFunded($customer, $order);

        // Reversal AFTER recording: replays stop for a manager and the member is not paid.
        $other = $this->customer();
        list($order2, $issued2, $attestation2) = $this->issued($other, $actor);
        $this->assertResult($this->finalizer()->finalize($actor, $order2->get_id(), $attestation2), EVC_Bacs_Finalization_Result::RECORDED);
        $reversal = new EVC_Movement_Correction($this->config->ref('bacs_refund', $this->reference()), $issued2->movement_id(), EVC_Movement_Correction::KIND_REVERSED, EVC_Verified_Movement::AUTHORITY_MANUAL_ADMIN, self::whole_second(new DateTimeImmutable('now', new DateTimeZone('UTC'))));
        $this->assertSame(EVC_Record_Outcome::NEWLY_RECORDED, $this->recorder($this->club())->correct($reversal)->outcome());
        $this->assertResult($this->finalizer()->finalize($actor, $order2->get_id(), $attestation2), EVC_Bacs_Finalization_Result::MANAGER_REQUIRED, 'recorded_payment_corrected');
        $this->assertNotSame('active', $this->me($other)['state']);
        $this->assertCount(1, $this->periods($other));
    }

    // ---- 12, 13: stale plan, changed chain (including the race) ------------------------------------------------

    public function test_a_payment_recorded_after_the_intent_makes_it_stale(): void {
        $customer = $this->customer();
        $actor = $this->manager();
        list($order, , $attestation) = $this->issued($customer, $actor, new DateTimeImmutable('-20 seconds'));
        $later = new EVC_Verified_Movement($this->config->ref('bacs_transfer', $this->reference()), 'live', $customer, $this->config->level_id, $this->config->ref('pmpro_membership_row', 'x'), self::whole_second(new DateTimeImmutable('-5 seconds')), 2000, 'EUR', array(new EVC_Movement_Alias(EVC_Movement_Alias::NS_WC_ORDER, $this->config->ref('wc_order', 'other'))), EVC_Verified_Movement::AUTHORITY_MANUAL_ADMIN, EVC_Payment_Evidence::KIND_MANUAL_CONFIRMATION, EVC_Verified_Movement::STATE_CONFIRMED);
        $this->assertSame(EVC_Record_Outcome::NEWLY_RECORDED, $this->recorder($this->club())->record($later)->outcome());
        $this->assertResult($this->finalizer()->finalize($actor, $order->get_id(), $attestation), EVC_Bacs_Finalization_Result::MANAGER_REQUIRED, 'plan_out_of_order_payment');
        $this->assertCount(1, $this->periods($customer), 'nothing of ours committed');
    }

    /** The chain changes AFTER every precondition passed: the signed period is still the only one that can commit. */
    public function test_a_chain_change_during_finalization_never_commits_a_different_period(): void {
        $customer = $this->customer();
        $actor = $this->manager();
        list($order, $issued, $attestation) = $this->issued($customer, $actor);
        $competitor = function () use ($customer, $issued) {
            $m = new EVC_Verified_Movement($this->config->ref('bacs_transfer', $this->reference()), 'live', $customer, $this->config->level_id, $this->config->ref('pmpro_membership_row', 'race'), $issued->verified_at_utc()->modify('-1 second'), 2000, 'EUR', array(new EVC_Movement_Alias(EVC_Movement_Alias::NS_WC_ORDER, $this->config->ref('wc_order', 'race-' . bin2hex(random_bytes(3))))), EVC_Verified_Movement::AUTHORITY_MANUAL_ADMIN, EVC_Payment_Evidence::KIND_MANUAL_CONFIRMATION, EVC_Verified_Movement::STATE_CONFIRMED);
            $this->assertSame(EVC_Record_Outcome::NEWLY_RECORDED, $this->recorder($this->club())->record($m)->outcome());
        };

        // (a) after the recovery policy's fresh plan, before record_expecting() reads the chain.
        $hooked = new EVC_Hooked_Provenance_Store($this->club());
        $hooked->before_call('member_movements', 2, $competitor);
        $r = $this->finalizer($hooked)->finalize($actor, $order->get_id(), $attestation);
        $this->assertResult($r, EVC_Bacs_Finalization_Result::CONFLICT, 'club_expected_period_changed');
        $this->assertNull($this->club()->movement($issued->movement_id()), 'the changed period was NOT committed');
        $this->assertSame(EVC_Bacs_Intent::STATE_ISSUED, $this->intent_state($order));

        // (b) after record_expecting() compared the period, right before COMMIT: the store's compare-and-set refuses.
        $customer2 = $this->customer();
        list($order2, $issued2, $attestation2) = $this->issued($customer2, $actor);
        $hooked = new EVC_Hooked_Provenance_Store($this->club());
        $hooked->before_commit = function () use ($customer2, $issued2) {
            $m = new EVC_Verified_Movement($this->config->ref('bacs_transfer', $this->reference()), 'live', $customer2, $this->config->level_id, $this->config->ref('pmpro_membership_row', 'race2'), $issued2->verified_at_utc()->modify('-1 second'), 2000, 'EUR', array(new EVC_Movement_Alias(EVC_Movement_Alias::NS_WC_ORDER, $this->config->ref('wc_order', 'race2-' . bin2hex(random_bytes(3))))), EVC_Verified_Movement::AUTHORITY_MANUAL_ADMIN, EVC_Payment_Evidence::KIND_MANUAL_CONFIRMATION, EVC_Verified_Movement::STATE_CONFIRMED);
            $this->assertSame(EVC_Record_Outcome::NEWLY_RECORDED, $this->recorder($this->club())->record($m)->outcome());
        };
        $r = $this->finalizer($hooked)->finalize($actor, $order2->get_id(), $attestation2);
        $this->assertResult($r, EVC_Bacs_Finalization_Result::CONFLICT, 'club_concurrent_update');
        $this->assertNull($this->club()->movement($issued2->movement_id()));
        $this->assertCount(1, $this->periods($customer2), 'only the racer\'s period exists');
    }

    // ---- 14, 15: failure before commit, unknown commit outcome ------------------------------------------------

    public function test_a_failed_or_unknown_commit_is_resolved_by_read_back_never_guessed(): void {
        $customer = $this->customer();
        $actor = $this->manager();
        list($order, , $attestation) = $this->issued($customer, $actor);

        $hooked = new EVC_Hooked_Provenance_Store($this->club());
        $hooked->commit_mode = EVC_Hooked_Provenance_Store::COMMIT_FAIL_BEFORE;
        $this->assertResult($this->finalizer($hooked)->finalize($actor, $order->get_id(), $attestation), EVC_Bacs_Finalization_Result::UNAVAILABLE, 'club_record_unavailable');
        $this->assertNothingFunded($customer, $order);

        $hooked = new EVC_Hooked_Provenance_Store($this->club());
        $hooked->commit_mode = EVC_Hooked_Provenance_Store::COMMIT_UNKNOWN_WITHOUT;
        $this->assertResult($this->finalizer($hooked)->finalize($actor, $order->get_id(), $attestation), EVC_Bacs_Finalization_Result::UNAVAILABLE, 'club_commit_not_found');
        $this->assertNothingFunded($customer, $order);

        $hooked = new EVC_Hooked_Provenance_Store($this->club());
        $hooked->commit_mode = EVC_Hooked_Provenance_Store::COMMIT_UNKNOWN_AFTER;
        $r = $this->finalizer($hooked)->finalize($actor, $order->get_id(), $attestation);
        $this->assertResult($r, EVC_Bacs_Finalization_Result::RECORDED);
        $this->assertTrue($r->intent_finalized, 'read-back proved the commit; the intent was finished');
        $this->assertCount(1, $this->periods($customer));
    }

    public function test_an_unreadable_club_record_after_an_unknown_commit_stays_unknown(): void {
        $customer = $this->customer();
        $actor = $this->manager();
        list($order, , $attestation) = $this->issued($customer, $actor);
        $hooked = new EVC_Hooked_Provenance_Store($this->club());
        $hooked->commit_mode = EVC_Hooked_Provenance_Store::COMMIT_UNKNOWN_AFTER;
        $hooked->before_call('period_for_movement', 1, function () use ($hooked) {
            $hooked->reads_fail = true; // the read-back after the unknown commit fails
        });
        $r = $this->finalizer($hooked)->finalize($actor, $order->get_id(), $attestation);
        $this->assertSame(EVC_Bacs_Finalization_Result::UNAVAILABLE, $r->outcome, (string) $r->reason);
        $this->assertSame(EVC_Bacs_Intent::STATE_ISSUED, $this->intent_state($order), 'never assumed either way');
        wp_cache_flush();
        $again = $this->finalizer()->finalize($actor, $order->get_id(), $attestation);
        $this->assertContains($again->outcome, array(EVC_Bacs_Finalization_Result::RECORDED, EVC_Bacs_Finalization_Result::ALREADY_RECORDED));
        $this->assertTrue($again->intent_finalized);
        $this->assertCount(1, $this->periods($customer));
    }

    /** The commit outcome is unknown and another path funded THIS transfer with another period: never "recorded". */
    public function test_a_read_back_finding_this_transfer_stored_differently_is_a_conflict(): void {
        $customer = $this->customer();
        $actor = $this->manager();
        list($order, $issued, $attestation) = $this->issued($customer, $actor);
        $hooked = new EVC_Hooked_Provenance_Store($this->club());
        $hooked->commit_mode = EVC_Hooked_Provenance_Store::COMMIT_UNKNOWN_WITHOUT;
        $hooked->before_commit = function () use ($customer, $issued) {
            $earlier = new EVC_Verified_Movement($this->config->ref('bacs_transfer', $this->reference()), 'live', $customer, $this->config->level_id, $this->config->ref('pmpro_membership_row', 'other-path'), $issued->verified_at_utc()->modify('-1 second'), 2000, 'EUR', array(new EVC_Movement_Alias(EVC_Movement_Alias::NS_WC_ORDER, $this->config->ref('wc_order', 'other-path-' . bin2hex(random_bytes(3))))), EVC_Verified_Movement::AUTHORITY_MANUAL_ADMIN, EVC_Payment_Evidence::KIND_MANUAL_CONFIRMATION, EVC_Verified_Movement::STATE_CONFIRMED);
            $this->recorder($this->club())->record($earlier);
            // The same transfer, same facts, recorded through plain record(): a DIFFERENT (early) period.
            $same = new EVC_Verified_Movement($issued->movement_id(), 'live', $customer, $this->config->level_id, $issued->new_row_ref(), $issued->verified_at_utc(), 2000, 'EUR', array(new EVC_Movement_Alias(EVC_Movement_Alias::NS_WC_ORDER, $issued->order_anchor())), EVC_Verified_Movement::AUTHORITY_MANUAL_ADMIN, EVC_Payment_Evidence::KIND_MANUAL_CONFIRMATION, EVC_Verified_Movement::STATE_CONFIRMED);
            $this->assertSame(EVC_Record_Outcome::NEWLY_RECORDED, $this->recorder($this->club())->record($same)->outcome());
        };
        $r = $this->finalizer($hooked)->finalize($actor, $order->get_id(), $attestation);
        $this->assertResult($r, EVC_Bacs_Finalization_Result::CONFLICT, 'club_read_back_differs');
        $this->assertSame(EVC_Bacs_Intent::STATE_ISSUED, $this->intent_state($order), 'suspicious state is left untouched');
        // A plain retry sees the contradiction too and never adds or rewrites anything.
        $this->assertResult($this->finalizer()->finalize($actor, $order->get_id(), $attestation), EVC_Bacs_Finalization_Result::CONFLICT, 'recorded_period_differs');
        $this->assertCount(2, $this->periods($customer));
    }

    // ---- 17, 18: intent transition failure, lock loss --------------------------------------------------------

    public function test_a_failed_intent_transition_is_finished_by_the_next_retry(): void {
        $customer = $this->customer();
        $actor = $this->manager();
        list($order, , $attestation) = $this->issued($customer, $actor);
        add_filter('query', function ($sql) {
            return (preg_match('/^\s*(INSERT|UPDATE)\b/i', $sql) && strpos($sql, EVC_Wc_Bacs_Intent_Store::META_KEY) !== false) ? 'UPDATE evc_no_such_table_for_test SET x = 1' : $sql;
        });
        $GLOBALS['wpdb']->suppress_errors(true);
        $r = $this->finalizer()->finalize($actor, $order->get_id(), $attestation);
        remove_all_filters('query');
        $GLOBALS['wpdb']->suppress_errors(false);
        $this->assertResult($r, EVC_Bacs_Finalization_Result::RECORDED, 'intent_transition_write_failed');
        $this->assertFalse($r->intent_finalized);
        $this->assertSame(EVC_Bacs_Intent::STATE_ISSUED, $this->intent_state($order));
        $this->assertSame('active', $this->me($customer)['state'], 'the money is recorded; the intent is only a resumption hint');

        wp_cache_flush();
        $again = $this->finalizer()->finalize($actor, $order->get_id(), $attestation);
        $this->assertResult($again, EVC_Bacs_Finalization_Result::ALREADY_RECORDED);
        $this->assertTrue($again->intent_finalized);
        $this->assertSame(EVC_Bacs_Intent::STATE_RECORDED, $this->intent_state($order));
        $this->assertCount(1, $this->periods($customer));
    }

    public function test_a_lost_member_lock_stops_before_recording_or_leaves_the_intent_for_retry(): void {
        $customer = $this->customer();
        $actor = $this->manager();
        list($order, , $attestation) = $this->issued($customer, $actor);
        $session = $this->second_connection();
        $kill = function () use ($session) {
            $GLOBALS['wpdb']->query('KILL ' . (int) $session->get_var('SELECT CONNECTION_ID()'));
            $session->suppress_errors(true);
        };
        // Lost while the preconditions are read: nothing is recorded.
        $hooked = new EVC_Hooked_Provenance_Store($this->club());
        $hooked->before_call('movement', 1, $kill);
        $this->assertResult($this->finalizer($hooked, $session)->finalize($actor, $order->get_id(), $attestation), EVC_Bacs_Finalization_Result::UNAVAILABLE, 'lock_lost');
        $this->assertNothingFunded($customer, $order);

        // Lost during the commit: the payment is recorded; the intent waits for a retry.
        $session = $this->second_connection();
        $kill = function () use ($session) {
            $GLOBALS['wpdb']->query('KILL ' . (int) $session->get_var('SELECT CONNECTION_ID()'));
            $session->suppress_errors(true);
        };
        $hooked = new EVC_Hooked_Provenance_Store($this->club());
        $hooked->before_commit = $kill;
        $r = $this->finalizer($hooked, $session)->finalize($actor, $order->get_id(), $attestation);
        $this->assertResult($r, EVC_Bacs_Finalization_Result::RECORDED, 'intent_transition_lock_not_held');
        $this->assertSame(EVC_Bacs_Intent::STATE_ISSUED, $this->intent_state($order));
        $again = $this->finalizer()->finalize($actor, $order->get_id(), $attestation);
        $this->assertResult($again, EVC_Bacs_Finalization_Result::ALREADY_RECORDED);
        $this->assertTrue($again->intent_finalized);
        $this->assertCount(1, $this->periods($customer));
    }

    public function test_an_unavailable_club_database_records_nothing(): void {
        $customer = $this->customer();
        $actor = $this->manager();
        list($order, , $attestation) = $this->issued($customer, $actor);
        $hooked = new EVC_Hooked_Provenance_Store($this->club());
        $hooked->reads_fail = true;
        $this->assertResult($this->finalizer($hooked)->finalize($actor, $order->get_id(), $attestation), EVC_Bacs_Finalization_Result::UNAVAILABLE, 'club_record_unreadable');
        $this->assertNothingFunded($customer, $order);
    }

    public function test_only_issued_intents_are_finalized(): void {
        $customer = $this->customer();
        $actor = $this->manager();
        $order = $this->order($customer, new DateTimeImmutable('-1 hour'));
        $reference = $this->reference();
        $at = self::whole_second(new DateTimeImmutable('-10 seconds'));
        $movement = $this->config->ref('bacs_transfer', $reference);
        $anchor = $this->config->ref('wc_order', (string) $order->get_id());
        $plan = $this->recorder($this->club())->plan(new EVC_Prospective_Payment($movement, 'live', $customer, $this->config->level_id, $at, 2000, 'EUR', array(new EVC_Movement_Alias(EVC_Movement_Alias::NS_WC_ORDER, $anchor)), EVC_Verified_Movement::AUTHORITY_MANUAL_ADMIN, EVC_Payment_Evidence::KIND_MANUAL_CONFIRMATION));
        $lock = EVC_Member_Lock::acquire($GLOBALS['wpdb'], $customer, 1);
        $this->intents()->create($lock, EVC_Bacs_Intent::planned('live', (int) $order->get_id(), $customer, $this->config->level_id, $movement, $anchor, $actor, $at, $plan, $at, null));
        $lock->release();
        $attestation = array('bank_reference' => $reference, 'amount_minor' => 2000, 'currency' => 'EUR', 'received_on' => self::athens_date($at));
        $this->assertResult($this->finalizer()->finalize($actor, $order->get_id(), $attestation), EVC_Bacs_Finalization_Result::MANAGER_REQUIRED, 'intent_not_issued');
        $this->assertSame('on-hold', wc_get_order($order->get_id())->get_status(), 'the finalizer never completes orders');
        $this->assertNull($this->active_row($customer), 'nor issues PMPro memberships');
        $this->assertSame(array(), $this->club()->member_movements($customer));
        $other = $this->order($this->customer());
        $this->assertResult($this->finalizer()->finalize($actor, $other->get_id(), $attestation), EVC_Bacs_Finalization_Result::MANAGER_REQUIRED, 'no_intent');
    }

    // ---- 19: early renewal ------------------------------------------------------------------------------------

    public function test_finalizing_an_issued_early_renewal_keeps_all_paid_time_and_adds_one_month(): void {
        $customer = $this->customer();
        $actor = $this->manager();
        list($first_order, $first, $first_attestation) = $this->issued($customer, $actor, new DateTimeImmutable('-10 days'));
        $this->assertResult($this->finalizer()->finalize($actor, $first_order->get_id(), $first_attestation), EVC_Bacs_Finalization_Result::RECORDED);
        $this->assertSame($first->period_end_utc()->format(DATE_ATOM), $this->me($customer)['paid_until_utc']);

        list($order, $renewal, $attestation) = $this->issued($customer, $actor, new DateTimeImmutable('-30 seconds'));
        $this->assertSame(EVC_Funded_Period::KIND_EARLY, $renewal->period_kind());
        $this->assertEquals($first->period_end_utc(), $renewal->period_start_utc());
        // Known, UNSOLVED window: PMPro replaced the paid row at issuance; not paid until recorded.
        $this->assertSame('unverified', $this->me($customer)['state']);
        $decision = (new EVC_Bacs_Recovery_Inspector($this->config, $this->club(), $this->intents()))->inspect($actor, $order->get_id());
        $this->assertTrue($decision->paid_time_at_risk, 'detected and flagged');

        // A failed finalization is detected, flagged and leaves the window open (fail closed).
        $hooked = new EVC_Hooked_Provenance_Store($this->club());
        $hooked->commit_mode = EVC_Hooked_Provenance_Store::COMMIT_FAIL_BEFORE;
        $this->assertResult($this->finalizer($hooked)->finalize($actor, $order->get_id(), $attestation), EVC_Bacs_Finalization_Result::UNAVAILABLE);
        $this->assertTrue((new EVC_Bacs_Recovery_Inspector($this->config, $this->club(), $this->intents()))->inspect($actor, $order->get_id())->paid_time_at_risk);
        $this->assertSame('unverified', $this->me($customer)['state']);

        $r = $this->finalizer()->finalize($actor, $order->get_id(), $attestation);
        $this->assertResult($r, EVC_Bacs_Finalization_Result::RECORDED);
        $me = $this->me($customer);
        $this->assertSame('active', $me['state']);
        $this->assertSame(EVC_Membership_Calendar::add_calendar_month($first->period_end_utc())->format(DATE_ATOM), $me['paid_until_utc'], 'previous paid end + exactly one approved calendar month');
        $periods = $this->periods($customer);
        $this->assertCount(2, $periods);
        $this->assertEquals($periods[0]->end_utc(), $periods[1]->start_utc(), 'contiguous: no paid time lost or added');
        $this->assertEquals($first->period_start_utc(), $periods[0]->start_utc());
    }

    // ---- 22: no leakage ------------------------------------------------------------------------------------------

    public function test_no_bank_or_personal_data_reaches_club_records_intents_audit_or_results(): void {
        global $wpdb;
        $customer = $this->customer();
        $actor = $this->manager();
        list($order, , $attestation) = $this->issued($customer, $actor);
        $results = array(
            $this->finalizer()->finalize($actor, $order->get_id(), array('bank_reference' => $this->reference()) + $attestation),
            $this->finalizer()->finalize($actor, $order->get_id(), $attestation),
        );
        $user = get_userdata($customer);
        $secrets = array_merge($this->references, array($user->user_email, $user->display_name, 'club.billing@example.org', EVC_CLUB_EVIDENCE_KEY));
        $club = EVC_Club_Db::connect(EVC_Db_Config::from_constants());
        $dump = '';
        foreach (array('evc_pay_movements', 'evc_pay_aliases', 'evc_pay_periods', 'evc_pay_corrections', 'evc_audit_events') as $table) {
            $dump .= wp_json_encode($club->fetch_all('SELECT * FROM ' . $table));
        }
        $dump .= wp_json_encode($wpdb->get_col($wpdb->prepare('SELECT meta_value FROM ' . \Automattic\WooCommerce\Utilities\OrderUtil::get_table_for_order_meta() . ' WHERE meta_key = %s', EVC_Wc_Bacs_Intent_Store::META_KEY)));
        $dump .= wp_json_encode(array_map(function (EVC_Bacs_Finalization_Result $r) {
            return array($r->outcome, $r->reason);
        }, $results));
        foreach ($secrets as $secret) {
            $this->assertStringNotContainsString($secret, $dump);
        }
        $events = $club->fetch_all("SELECT details_json FROM evc_audit_events WHERE event_type = 'bacs_payment_finalization' AND staff_wp_user_id = ?", array($actor));
        $this->assertCount(2, $events, 'every attempt is audited');
    }
}
