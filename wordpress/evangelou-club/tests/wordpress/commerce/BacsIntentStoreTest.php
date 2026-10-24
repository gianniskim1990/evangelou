<?php

/**
 * STAGE-03B.2: durable signed BACS intents in private WooCommerce order meta,
 * the member lock and the read-only recovery inspector — over the REAL
 * WooCommerce 11.2.1 / PMPro 3.8.9 / PMPro WooCommerce 1.11 (HPOS or legacy
 * storage, per EVC_TEST_WC_ORDER_STORAGE) and the REAL SQL provenance store
 * on the disposable Club database. Nothing here is a real payment.
 *
 * TEST-ONLY simulations of later steps (STAGE-03B.3, not implemented):
 * issue_with_plan() completes the order while a test filter gives PMPro the
 * plan's dates, and record() records the movement with the recorder. No
 * plugin code performs either.
 */
final class BacsIntentStoreTest extends EVC_WP_Test_Case {
    const ME = '/evangelou-club-customer/v1/me';

    /** @var EVC_Commerce_Source_Config */
    private $config;
    /** @var string[] raw bank references used (must never be stored anywhere) */
    private $references = array();
    /** @var wpdb[] */
    private $connections = array();
    /** @var resource[] */
    private $children = array();

    public function set_up() {
        parent::set_up();
        evc_test_commerce_configure();
        $this->config = EVC_Commerce_Source_Config::from_constants();
    }

    public function tear_down() {
        foreach ($this->children as $proc) {
            @proc_terminate($proc);
            @proc_close($proc);
        }
        foreach ($this->connections as $db) {
            $db->close();
        }
        remove_all_filters('query');
        remove_all_filters('pmprowoo_checkout_level', 20);
        parent::tear_down();
    }

    // ---- helpers ---------------------------------------------------------------------------------

    private function club(): EVC_Sql_Provenance_Store {
        $db = EVC_Db_Config::from_constants();
        return new EVC_Sql_Provenance_Store(EVC_Club_Db::connect($db), function () use ($db) {
            return EVC_Club_Db::connect($db);
        }, 'live');
    }

    private function recorder(EVC_Provenance_Store $store): EVC_Provenance_Recorder {
        return new EVC_Provenance_Recorder($store, new EVC_Pmpro_Mapper_Config(array($this->config->level_id), EVC_Commerce_Source_Config::PMPRO_VERSION, EVC_Commerce_Source_Config::PMPRO_VERSION_EXCLUSIVE_MAX), 'live', 2000, 'EUR');
    }

    private function intents(string $environment = 'live'): EVC_Wc_Bacs_Intent_Store {
        return new EVC_Wc_Bacs_Intent_Store($this->config->intent_codec(), $environment);
    }

    private function inspector(): EVC_Bacs_Recovery_Inspector {
        return new EVC_Bacs_Recovery_Inspector($this->config, $this->club(), $this->intents());
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

    private function order(int $user_id, string $status = 'on-hold'): WC_Order {
        $order = wc_create_order(array('customer_id' => $user_id));
        $order->add_product(wc_get_product(EVC_TEST_CLUB_PRODUCT), 1);
        $order->set_payment_method('bacs');
        $order->set_currency('EUR');
        $order->set_billing_email('club.billing@example.org');
        $order->calculate_totals();
        $order->save();
        if ($status !== 'pending') {
            if ($status === 'completed') {
                $this->setExpectedDeprecated('pmprommpu_addMembershipLevel');
            }
            $order->update_status($status);
        }
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

    private function active_row_ref(int $user_id): ?string {
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$wpdb->pmpro_memberships_users} WHERE user_id = %d AND membership_id = %d AND status = 'active'", $user_id, $this->config->level_id));
        $this->assertLessThanOrEqual(1, count($ids));
        return $ids === array() ? null : $this->config->ref('pmpro_membership_row', (string) $ids[0]);
    }

    private function pmpro_rows(int $user_id): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare("SELECT id, status, startdate, enddate FROM {$wpdb->pmpro_memberships_users} WHERE user_id = %d ORDER BY id", $user_id), ARRAY_A);
    }

    /** What a future confirmation would make durable FIRST: the plan fixed with its instant. */
    private function planned_intent(int $customer, WC_Order $order, int $actor, ?DateTimeImmutable $at = null, ?string $reference = null): EVC_Bacs_Intent {
        $at = self::whole_second($at ?? new DateTimeImmutable('now', new DateTimeZone('UTC')));
        $movement = $this->config->ref('bacs_transfer', $reference ?? $this->reference());
        $anchor = $this->config->ref('wc_order', (string) $order->get_id());
        $club = $this->club();
        $prospective = new EVC_Prospective_Payment($movement, 'live', $customer, $this->config->level_id, $at, 2000, 'EUR', array(new EVC_Movement_Alias(EVC_Movement_Alias::NS_WC_ORDER, $anchor)), EVC_Verified_Movement::AUTHORITY_MANUAL_ADMIN, EVC_Payment_Evidence::KIND_MANUAL_CONFIRMATION);
        $plan = $this->recorder($club)->plan($prospective);
        $this->assertSame(EVC_Period_Plan::PLANNED, $plan->outcome(), (string) $plan->reason());
        return EVC_Bacs_Intent::planned('live', (int) $order->get_id(), $customer, $this->config->level_id, $movement, $anchor, $actor, $at, $plan, EVC_Paid_Run::start_for($club, $plan), $plan->kind() === EVC_Funded_Period::KIND_EARLY ? $this->active_row_ref($customer) : null);
    }

    private function lock(int $customer, ?wpdb $db = null, int $wait = 0): EVC_Member_Lock {
        global $wpdb;
        $lock = EVC_Member_Lock::acquire($db ?? $wpdb, $customer, $wait);
        $this->assertNotNull($lock, 'member lock must be free');
        return $lock;
    }

    private function second_connection(): wpdb {
        $db = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
        $db->set_prefix($GLOBALS['wpdb']->prefix);
        $this->connections[] = $db;
        return $db;
    }

    private static function local(DateTimeImmutable $i): string {
        return $i->setTimezone(wp_timezone())->format('Y-m-d H:i:s');
    }

    /** TEST-ONLY stand-in for STAGE-03B.3: complete the order while PMPro is given the plan's dates. */
    private function issue_with_plan(WC_Order $order, EVC_Bacs_Intent $i): void {
        $filter = function (array $level) use ($i) {
            $level['startdate'] = "'" . self::local($i->run_start_utc()) . "'";
            $level['enddate'] = self::local($i->period_end_utc());
            return $level;
        };
        add_filter('pmprowoo_checkout_level', $filter, 20);
        $this->setExpectedDeprecated('pmprommpu_addMembershipLevel');
        wc_get_order($order->get_id())->update_status('completed');
        remove_filter('pmprowoo_checkout_level', $filter, 20);
    }

    /** TEST-ONLY stand-in for STAGE-03B.3: record the movement bound to the issued row. */
    private function record(EVC_Bacs_Intent $i, string $row_ref): EVC_Record_Outcome {
        $m = new EVC_Verified_Movement($i->movement_id(), 'live', $i->customer_id(), $i->level_id(), $row_ref, $i->verified_at_utc(), 2000, 'EUR', array(new EVC_Movement_Alias(EVC_Movement_Alias::NS_WC_ORDER, $i->order_anchor())), EVC_Verified_Movement::AUTHORITY_MANUAL_ADMIN, EVC_Payment_Evidence::KIND_MANUAL_CONFIRMATION, EVC_Verified_Movement::STATE_CONFIRMED);
        return $this->recorder($this->club())->record($m);
    }

    /** A whole, verified month (TEST-ONLY simulation of the future orchestrator). */
    private function fund(int $customer, int $actor, ?DateTimeImmutable $at = null): array {
        $order = $this->order($customer);
        $planned = $this->planned_intent($customer, $order, $actor, $at);
        $lock = $this->lock($customer);
        $this->assertSame(EVC_Bacs_Intent_Write::WRITTEN, $this->intents()->create($lock, $planned)->outcome);
        $this->issue_with_plan($order, $planned);
        $row = $this->active_row_ref($customer);
        $issued = $planned->issued($row);
        $this->assertSame(EVC_Bacs_Intent_Write::WRITTEN, $this->intents()->transition($lock, $planned, $issued)->outcome);
        $this->assertSame(EVC_Record_Outcome::NEWLY_RECORDED, $this->record($issued, $row)->outcome());
        $recorded = $issued->recorded();
        $this->assertSame(EVC_Bacs_Intent_Write::WRITTEN, $this->intents()->transition($lock, $issued, $recorded)->outcome);
        $lock->release();
        return array($order, $recorded);
    }

    private function me(int $user_id): array {
        $this->login_as($user_id);
        $r = $this->rest('GET', self::ME, null, $this->nonce());
        $this->assertSame(200, $r->get_status(), wp_json_encode($r->get_data()));
        $this->assertStringNotContainsString('evc_club_bacs_intent', wp_json_encode($r->get_data()));
        wp_set_current_user(0);
        return $r->get_data()['membership'];
    }

    private function meta_table(): string {
        return \Automattic\WooCommerce\Utilities\OrderUtil::get_table_for_order_meta();
    }

    private function meta_rows(int $order_id): array {
        global $wpdb;
        $id_column = $this->meta_table() === $wpdb->postmeta ? 'post_id' : 'order_id';
        return $wpdb->get_col($wpdb->prepare("SELECT meta_value FROM {$this->meta_table()} WHERE {$id_column} = %d AND meta_key = %s", $order_id, EVC_Wc_Bacs_Intent_Store::META_KEY));
    }

    private function overwrite_meta(int $order_id, string $value): void {
        global $wpdb;
        $id_column = $this->meta_table() === $wpdb->postmeta ? 'post_id' : 'order_id';
        $wpdb->update($this->meta_table(), array('meta_value' => $value), array($id_column => $order_id, 'meta_key' => EVC_Wc_Bacs_Intent_Store::META_KEY));
        clean_post_cache($order_id);
    }

    private function assertDecision(EVC_Bacs_Recovery_Decision $d, string $outcome, ?string $reason = null, ?string $step = null): void {
        $this->assertSame($outcome, $d->outcome, 'reason: ' . (string) $d->reason);
        if ($reason !== null) {
            $this->assertSame($reason, $d->reason);
        }
        if ($step !== null) {
            $this->assertSame($step, $d->step);
        }
    }

    // ---- 1: round trip ------------------------------------------------------------------------------

    public function test_signed_intent_round_trips_through_private_order_meta(): void {
        $customer = $this->customer();
        $order = $this->order($customer);
        $intent = $this->planned_intent($customer, $order, $this->manager());
        $lock = $this->lock($customer);
        $this->assertSame(EVC_Bacs_Intent_Write::WRITTEN, $this->intents()->create($lock, $intent)->outcome);
        $lock->release();
        wp_cache_flush();

        $read = $this->intents()->read($order->get_id());
        $this->assertSame(EVC_Bacs_Intent_Read::VALID, $read->outcome, (string) $read->reason);
        $this->assertTrue($read->intent->same_as($intent));
        $rows = $this->meta_rows($order->get_id());
        $this->assertCount(1, $rows, 'exactly one meta row in ' . $this->meta_table());
        $this->assertSame($this->config->intent_codec()->encode($intent), $rows[0]);
        $this->assertSame('_', EVC_Wc_Bacs_Intent_Store::META_KEY[0], 'private meta key');
        $this->assertSame((string) getenv('EVC_TEST_WC_ORDER_STORAGE') === 'posts' ? $GLOBALS['wpdb']->postmeta : $GLOBALS['wpdb']->prefix . 'wc_orders_meta', $this->meta_table());
    }

    // ---- 2, 3, 4: tampered / foreign / unsigned ----------------------------------------------------------

    public function test_tampered_meta_is_rejected_and_never_overwritten(): void {
        $customer = $this->customer();
        $order = $this->order($customer);
        $actor = $this->manager();
        $intent = $this->planned_intent($customer, $order, $actor);
        $lock = $this->lock($customer);
        $this->intents()->create($lock, $intent);
        $stored = $this->meta_rows($order->get_id())[0];
        $tampered = str_replace('"actor_id":' . $actor, '"actor_id":' . ($actor + 1), $stored);
        $this->assertNotSame($stored, $tampered);
        $this->overwrite_meta($order->get_id(), $tampered);

        $read = $this->intents()->read($order->get_id());
        $this->assertSame(EVC_Bacs_Intent_Read::INVALID, $read->outcome);
        $this->assertSame('signature_mismatch', $read->reason);
        $w = $this->intents()->create($lock, $intent);
        $this->assertSame(EVC_Bacs_Intent_Write::CONFLICT, $w->outcome);
        $this->assertSame('stored_intent_invalid:signature_mismatch', $w->reason);
        $this->assertSame(array($tampered), $this->meta_rows($order->get_id()), 'uncertain state is kept, not cleaned up');
        $this->assertDecision($this->inspector()->inspect($actor, $order->get_id()), EVC_Bacs_Recovery_Decision::CONFLICT, 'intent_signature_mismatch');
        $lock->release();
    }

    public function test_a_valid_intent_copied_to_another_order_or_read_in_another_environment_is_rejected(): void {
        $customer = $this->customer();
        $a = $this->order($customer);
        $b = $this->order($customer);
        $lock = $this->lock($customer);
        $this->intents()->create($lock, $this->planned_intent($customer, $a, $this->manager()));
        $copy = wc_get_order($b->get_id());
        $copy->update_meta_data(EVC_Wc_Bacs_Intent_Store::META_KEY, $this->meta_rows($a->get_id())[0]);
        $copy->save();
        $this->assertSame('cross_order', $this->intents()->read($b->get_id())->reason);
        $this->assertSame('cross_environment', $this->intents('staging')->read($a->get_id())->reason);
        $lock->release();
    }

    public function test_unsigned_or_duplicated_meta_is_rejected(): void {
        $customer = $this->customer();
        $order = $this->order($customer);
        $lock = $this->lock($customer);
        $this->intents()->create($lock, $this->planned_intent($customer, $order, $this->manager()));
        $data = json_decode($this->meta_rows($order->get_id())[0], true);
        unset($data['sig']);
        $this->overwrite_meta($order->get_id(), wp_json_encode($data));
        $this->assertSame('signature_missing', $this->intents()->read($order->get_id())->reason);

        // A second row with the same key (an uncoordinated writer) is never resolved by guessing.
        $other = $this->order($customer);
        $this->intents()->create($lock, $this->planned_intent($customer, $other, $this->manager()));
        $o = wc_get_order($other->get_id());
        $o->add_meta_data(EVC_Wc_Bacs_Intent_Store::META_KEY, $this->meta_rows($other->get_id())[0], false);
        $o->save();
        $this->assertSame('duplicate_intent_rows', $this->intents()->read($other->get_id())->reason);
        $lock->release();
    }

    // ---- 5, 6: duplicate and conflicting submissions --------------------------------------------------

    public function test_identical_resubmission_is_idempotent_and_a_second_reference_never_replaces_the_first(): void {
        $customer = $this->customer();
        $order = $this->order($customer);
        $actor = $this->manager();
        $intent = $this->planned_intent($customer, $order, $actor);
        $lock = $this->lock($customer);
        $this->assertSame(EVC_Bacs_Intent_Write::WRITTEN, $this->intents()->create($lock, $intent)->outcome);
        $this->assertSame(EVC_Bacs_Intent_Write::ALREADY, $this->intents()->create($lock, $intent)->outcome);
        $other = $this->planned_intent($customer, $order, $actor);
        $w = $this->intents()->create($lock, $other);
        $this->assertSame(EVC_Bacs_Intent_Write::CONFLICT, $w->outcome);
        $this->assertSame('different_intent_stored', $w->reason);
        $this->assertSame(array($this->config->intent_codec()->encode($intent)), $this->meta_rows($order->get_id()));
        $lock->release();
    }

    // ---- 7: concurrency -----------------------------------------------------------------------------------

    public function test_two_managers_are_serialised_by_the_member_lock(): void {
        $customer = $this->customer();
        $order = $this->order($customer);
        $a = $this->planned_intent($customer, $order, $this->manager());
        $b = $this->planned_intent($customer, $order, $this->manager());
        $second = $this->second_connection();

        $lock_a = $this->lock($customer);
        $this->assertNull(EVC_Member_Lock::acquire($second, $customer, 0), 'manager B cannot enter while A holds the lock');
        $t = microtime(true);
        $this->assertNull(EVC_Member_Lock::acquire($second, $customer, 1));
        $this->assertGreaterThanOrEqual(0.9, microtime(true) - $t, 'GET_LOCK waited for the bounded timeout');
        $this->assertLessThan(5, microtime(true) - $t);
        $this->assertNotNull(EVC_Member_Lock::acquire($second, $this->customer(), 0), 'other members are not blocked');

        $this->assertSame(EVC_Bacs_Intent_Write::WRITTEN, $this->intents()->create($lock_a, $a)->outcome);
        $this->assertTrue($lock_a->release());
        $this->assertFalse($lock_a->is_held());
        $this->assertSame(EVC_Bacs_Intent_Write::LOCK_NOT_HELD, $this->intents()->create($lock_a, $a)->outcome, 'a released lock authorises nothing');

        $lock_b = EVC_Member_Lock::acquire($second, $customer, 1);
        $this->assertNotNull($lock_b);
        $this->assertTrue($lock_b->is_held());
        $this->assertSame(EVC_Bacs_Intent_Write::CONFLICT, $this->intents()->create($lock_b, $b)->outcome, 'B sees A\'s intent and cannot replace it');
        $this->assertSame(EVC_Bacs_Intent_Write::ALREADY, $this->intents()->create($lock_b, $a)->outcome);
        $this->assertSame(EVC_Bacs_Intent_Write::LOCK_NOT_HELD, $this->intents()->create($this->lock($this->customer()), $a)->outcome, 'a lock for another member authorises nothing');
        $lock_b->release();
    }

    public function test_a_crashed_process_releases_the_lock_with_its_connection(): void {
        $customer = $this->customer();
        list($host, $port) = array_pad(explode(':', DB_HOST, 2), 2, '3306');
        $name = EVC_Member_Lock::name_for($GLOBALS['wpdb'], $customer);
        $code = '$m = new mysqli(' . var_export($host, true) . ', ' . var_export(DB_USER, true) . ', ' . var_export(DB_PASSWORD, true) . ', ' . var_export(DB_NAME, true) . ', ' . (int) $port . ');'
            . '$r = $m->query("SELECT GET_LOCK(" . "\'" . $m->real_escape_string(' . var_export($name, true) . ') . "\'" . ", 0)")->fetch_row();'
            . 'echo $r[0] === "1" ? "locked\n" : "busy\n"; fflush(STDOUT); sleep(60);';
        $proc = proc_open(array(PHP_BINARY, '-r', $code), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
        $this->assertIsResource($proc);
        $this->children[] = $proc;
        $this->assertSame("locked\n", fgets($pipes[1]), 'child process took the member lock');
        $this->assertNull(EVC_Member_Lock::acquire($GLOBALS['wpdb'], $customer, 0), 'held by the other process');

        proc_terminate($proc, 9); // the "manager's request" dies without releasing anything
        proc_close($proc);
        $this->children = array();
        $lock = EVC_Member_Lock::acquire($GLOBALS['wpdb'], $customer, 5);
        $this->assertNotNull($lock, 'the server released the dead connection\'s lock');
        $lock->release();
    }

    public function test_a_lost_connection_is_detected_as_a_lost_lock(): void {
        $customer = $this->customer();
        $order = $this->order($customer);
        $second = $this->second_connection();
        $lock = $this->lock($customer, $second);
        $this->assertTrue($lock->is_held());
        $id = (int) $second->get_var('SELECT CONNECTION_ID()');
        $GLOBALS['wpdb']->query('KILL ' . $id); // e.g. wait_timeout, network drop; wpdb silently reconnects
        $second->suppress_errors(true);
        $this->assertFalse($lock->is_held(), 'a reconnected session does not own the lock');
        $this->assertSame(EVC_Bacs_Intent_Write::LOCK_NOT_HELD, $this->intents()->create($lock, $this->planned_intent($customer, $order, $this->manager()))->outcome);
        $this->assertSame(array(), $this->meta_rows($order->get_id()));
    }

    public function test_lock_names_are_scoped_and_waits_bounded(): void {
        $name = EVC_Member_Lock::name_for($GLOBALS['wpdb'], 123456789);
        $this->assertLessThanOrEqual(64, strlen($name));
        $this->assertStringEndsWith(':123456789', $name);
        $this->assertStringContainsString(substr(hash('sha256', DB_NAME . '|' . $GLOBALS['wpdb']->prefix), 0, 16), $name);
        foreach (array(array(0, 1), array(1, -1), array(1, 11)) as $bad) {
            try {
                EVC_Member_Lock::acquire($GLOBALS['wpdb'], $bad[0], $bad[1]);
                $this->fail('accepted ' . json_encode($bad));
            } catch (InvalidArgumentException $e) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // ---- 8, 12: crash after persistence, recovery after reload -------------------------------------------

    public function test_after_a_crash_the_durable_intent_is_found_and_only_resumption_is_proposed(): void {
        $customer = $this->customer();
        $order = $this->order($customer);
        $actor = $this->manager();
        $lock = $this->lock($customer);
        $this->intents()->create($lock, $this->planned_intent($customer, $order, $actor));
        unset($lock); // the request dies; the lock goes with its connection
        wp_cache_flush();

        $d = $this->inspector()->inspect($actor, $order->get_id());
        $this->assertDecision($d, EVC_Bacs_Recovery_Decision::RESUME, null, EVC_Bacs_Recovery_Decision::STEP_COMPLETE_ORDER);
        // Recovery decided; it executed nothing.
        $this->assertSame('on-hold', wc_get_order($order->get_id())->get_status());
        $this->assertSame(array(), $this->pmpro_rows($customer));
        $this->assertSame(array(), $this->club()->member_movements($customer));
        $this->assertSame(array('state' => 'pending_payment', 'paid_until_utc' => null), $this->me($customer));
    }

    // ---- 9: interrupted update -------------------------------------------------------------------------------

    public function test_an_interrupted_transition_is_resolved_from_the_stored_bytes(): void {
        $customer = $this->customer();
        $order = $this->order($customer);
        $actor = $this->manager();
        $planned = $this->planned_intent($customer, $order, $actor);
        $lock = $this->lock($customer);
        $this->intents()->create($lock, $planned);
        $this->issue_with_plan($order, $planned);
        $issued = $planned->issued($this->active_row_ref($customer));

        // The process wrote "issued" and died before its read-back.
        $o = wc_get_order($order->get_id());
        $o->update_meta_data(EVC_Wc_Bacs_Intent_Store::META_KEY, $this->config->intent_codec()->encode($issued));
        $o->save();
        wp_cache_flush();

        $this->assertSame(EVC_Bacs_Intent_Write::ALREADY, $this->intents()->transition($lock, $planned, $issued)->outcome, 'the retry recognises its own completed write');
        $w = $this->intents()->transition($lock, $planned, $planned->issuance_mismatch());
        $this->assertSame(EVC_Bacs_Intent_Write::STALE, $w->outcome, 'a writer working from the old value cannot overwrite');
        $this->assertDecision($this->inspector()->inspect($actor, $order->get_id()), EVC_Bacs_Recovery_Decision::RESUME, null, EVC_Bacs_Recovery_Decision::STEP_RECORD_MOVEMENT);
        $this->assertSame(EVC_Bacs_Intent_Write::UNAVAILABLE, $this->intents()->transition($lock, $planned, $issued->recorded())->outcome, 'not one lifecycle step');
        $lock->release();
        $this->assertSame(EVC_Bacs_Intent_Write::LOCK_NOT_HELD, $this->intents()->transition($lock, $issued, $issued->recorded())->outcome, 'no transition without the member lock');
        $this->assertTrue($this->intents()->read($order->get_id())->intent->same_as($issued));
    }

    // ---- 10, 11: write failure, unknown outcome ------------------------------------------------------------

    public function test_a_failed_meta_write_is_reported_and_nothing_is_stored(): void {
        $customer = $this->customer();
        $order = $this->order($customer);
        $intent = $this->planned_intent($customer, $order, $this->manager());
        $lock = $this->lock($customer);
        add_filter('query', function ($sql) {
            return (preg_match('/^\s*(INSERT|UPDATE)\b/i', $sql) && strpos($sql, EVC_Wc_Bacs_Intent_Store::META_KEY) !== false) ? 'INSERT INTO evc_no_such_table_for_test VALUES (1)' : $sql;
        });
        $GLOBALS['wpdb']->suppress_errors(true);
        $w = $this->intents()->create($lock, $intent);
        remove_all_filters('query');
        $GLOBALS['wpdb']->suppress_errors(false);
        $this->assertSame(EVC_Bacs_Intent_Write::WRITE_FAILED, $w->outcome, (string) $w->reason);
        $this->assertSame(array(), $this->meta_rows($order->get_id()));
        $this->assertSame(EVC_Bacs_Intent_Read::MISSING, $this->intents()->read($order->get_id())->outcome);
        $this->assertSame(EVC_Bacs_Intent_Write::WRITTEN, $this->intents()->create($lock, $intent)->outcome, 'a retry succeeds');
        $lock->release();
    }

    public function test_an_unverifiable_write_is_reported_as_unknown_and_resolved_by_a_later_read(): void {
        $customer = $this->customer();
        $order = $this->order($customer);
        $intent = $this->planned_intent($customer, $order, $this->manager());
        $lock = $this->lock($customer);
        $written = false;
        add_filter('query', function ($sql) use (&$written) {
            if (preg_match('/^\s*INSERT\b/i', $sql) && strpos($sql, EVC_Wc_Bacs_Intent_Store::META_KEY) !== false) {
                $written = true;
                return $sql;
            }
            // After the write, every metadata read fails (connection trouble during the read-back).
            return ($written && preg_match('/^\s*SELECT\b.*meta_value/is', $sql)) ? 'SELECT * FROM evc_no_such_table_for_test' : $sql;
        });
        $GLOBALS['wpdb']->suppress_errors(true);
        $w = $this->intents()->create($lock, $intent);
        remove_all_filters('query');
        $GLOBALS['wpdb']->suppress_errors(false);
        $this->assertTrue($written);
        $this->assertSame(EVC_Bacs_Intent_Write::OUTCOME_UNKNOWN, $w->outcome, (string) $w->reason);
        $this->assertSame('read_back_failed', $w->reason);
        wp_cache_flush();
        $this->assertSame(EVC_Bacs_Intent_Read::VALID, $this->intents()->read($order->get_id())->outcome, 'the write had in fact persisted');
        $this->assertSame(EVC_Bacs_Intent_Write::ALREADY, $this->intents()->create($lock, $intent)->outcome);
        $lock->release();
    }

    public function test_a_failed_read_is_never_mistaken_for_a_missing_intent(): void {
        $customer = $this->customer();
        $order = $this->order($customer);
        $lock = $this->lock($customer);
        $this->intents()->create($lock, $this->planned_intent($customer, $order, $this->manager()));
        add_filter('query', function ($sql) {
            return preg_match('/^\s*SELECT\b.*meta_value/is', $sql) ? 'SELECT * FROM evc_no_such_table_for_test' : $sql;
        });
        $GLOBALS['wpdb']->suppress_errors(true);
        $read = $this->intents()->read($order->get_id());
        $create = $this->intents()->create($lock, $this->planned_intent($customer, $order, $this->manager()));
        remove_all_filters('query');
        $GLOBALS['wpdb']->suppress_errors(false);
        $this->assertSame(EVC_Bacs_Intent_Read::UNAVAILABLE, $read->outcome);
        $this->assertSame(EVC_Bacs_Intent_Write::UNAVAILABLE, $create->outcome, 'nothing is written over a state that could not be read');
        $this->assertCount(1, $this->meta_rows($order->get_id()));
        $lock->release();
    }

    // ---- 13: stale plan --------------------------------------------------------------------------------------

    public function test_a_payment_recorded_after_the_intent_makes_its_plan_stale(): void {
        $customer = $this->customer();
        $actor = $this->manager();
        $order = $this->order($customer);
        $lock = $this->lock($customer);
        $planned = $this->planned_intent($customer, $order, $actor, new DateTimeImmutable('-20 seconds'));
        $this->intents()->create($lock, $planned);
        $lock->release();
        // Meanwhile another transfer of this member was fully recorded (later instant).
        $this->fund($customer, $actor, new DateTimeImmutable('-10 seconds'));

        $d = $this->inspector()->inspect($actor, $order->get_id());
        $this->assertSame(EVC_Bacs_Recovery_Decision::MANAGER_REQUIRED, $d->outcome, (string) $d->reason);
        $this->assertSame('plan_out_of_order_payment', $d->reason);
    }

    // ---- 14: PMPro row missing or mismatching ----------------------------------------------------------------

    public function test_the_add_on_own_dates_or_a_missing_row_stop_recovery(): void {
        $customer = $this->customer();
        $actor = $this->manager();
        $order = $this->order($customer);
        $lock = $this->lock($customer);
        $this->intents()->create($lock, $this->planned_intent($customer, $order, $actor, new DateTimeImmutable('-1 hour')));
        $lock->release();
        $this->setExpectedDeprecated('pmprommpu_addMembershipLevel');
        wc_get_order($order->get_id())->update_status('completed'); // without the plan's dates
        $this->assertDecision($this->inspector()->inspect($actor, $order->get_id()), EVC_Bacs_Recovery_Decision::MANAGER_REQUIRED, 'issuance_mismatch');

        pmpro_cancelMembershipLevel($this->config->level_id, $customer, 'inactive');
        $this->assertDecision($this->inspector()->inspect($actor, $order->get_id()), EVC_Bacs_Recovery_Decision::MANAGER_REQUIRED, 'membership_row_missing');
        $this->assertNotSame('active', $this->me($customer)['state']);
    }

    // ---- 15: already recorded ---------------------------------------------------------------------------------

    public function test_a_fully_recorded_month_is_already_complete_and_a_replay_adds_nothing(): void {
        $customer = $this->customer();
        $actor = $this->manager();
        list($order, $recorded) = $this->fund($customer, $actor);
        $this->assertDecision($this->inspector()->inspect($actor, $order->get_id()), EVC_Bacs_Recovery_Decision::ALREADY_COMPLETE);
        $this->assertNull($this->inspector()->inspect($actor, $order->get_id())->reason);
        $me = $this->me($customer);
        $this->assertSame('active', $me['state']);
        $this->assertSame($recorded->period_end_utc()->format(DATE_ATOM), $me['paid_until_utc']);

        $this->assertTrue($this->intents()->read($order->get_id())->intent->same_as($recorded));
        $this->assertSame(EVC_Record_Outcome::ALREADY_RECORDED, $this->record($recorded, $recorded->new_row_ref())->outcome(), 'replay records no second month');
        $this->assertCount(1, $this->club()->member_movements($customer));
    }

    // ---- 16: completed order without provenance ----------------------------------------------------------------

    public function test_a_completed_order_without_intent_or_provenance_is_never_paid(): void {
        $customer = $this->customer();
        $actor = $this->manager();
        $order = $this->order($customer, 'completed');
        $this->assertDecision($this->inspector()->inspect($actor, $order->get_id()), EVC_Bacs_Recovery_Decision::MANAGER_REQUIRED, 'completed_order_without_intent');
        $this->assertSame('unverified', $this->me($customer)['state']);
    }

    // ---- 17: early renewal --------------------------------------------------------------------------------------

    public function test_early_renewal_keeps_paid_time_only_once_recorded_and_the_risk_window_is_flagged(): void {
        $customer = $this->customer();
        $actor = $this->manager();
        list(, $first) = $this->fund($customer, $actor, new DateTimeImmutable('-10 days'));
        $paid_until = $this->me($customer)['paid_until_utc'];
        $this->assertSame($first->period_end_utc()->format(DATE_ATOM), $paid_until);
        $old_row = $this->active_row_ref($customer);

        $order = $this->order($customer);
        $renewal = $this->planned_intent($customer, $order, $actor, new DateTimeImmutable('-5 seconds'));
        $this->assertSame(EVC_Funded_Period::KIND_EARLY, $renewal->period_kind());
        $this->assertEquals($first->period_end_utc(), $renewal->period_start_utc(), 'D2: continues at the paid end');
        $this->assertEquals($first->run_start_utc(), $renewal->run_start_utc());
        $this->assertSame($old_row, $renewal->previous_row_ref());
        $lock = $this->lock($customer);
        $this->intents()->create($lock, $renewal);

        $d = $this->inspector()->inspect($actor, $order->get_id());
        $this->assertDecision($d, EVC_Bacs_Recovery_Decision::RESUME, null, EVC_Bacs_Recovery_Decision::STEP_COMPLETE_ORDER);
        $this->assertFalse($d->paid_time_at_risk);
        $this->assertSame('active', $this->me($customer)['state'], 'an intent changes nothing');

        // STAGE-03B.1 finding, reproduced with the real plugins: PMPro replaces the paid row first.
        $this->issue_with_plan($order, $renewal);
        $rows = $this->pmpro_rows($customer);
        $this->assertSame('changed', $rows[0]['status'], 'PMPro deactivated the already paid row');
        $this->assertSame('unverified', $this->me($customer)['state'], 'paid time is NOT shown until the Club records the renewal');
        $d = $this->inspector()->inspect($actor, $order->get_id());
        $this->assertDecision($d, EVC_Bacs_Recovery_Decision::RESUME, null, EVC_Bacs_Recovery_Decision::STEP_CONFIRM_ISSUANCE);
        $this->assertTrue($d->paid_time_at_risk);

        $new_row = $this->active_row_ref($customer);
        $issued = $renewal->issued($new_row);
        $this->intents()->transition($lock, $renewal, $issued);
        $this->assertSame(EVC_Record_Outcome::NEWLY_RECORDED, $this->record($issued, $new_row)->outcome());
        $me = $this->me($customer);
        $this->assertSame('active', $me['state']);
        $this->assertSame($renewal->period_end_utc()->format(DATE_ATOM), $me['paid_until_utc'], 'the remaining paid time is preserved and one month added');
        $this->assertDecision($this->inspector()->inspect($actor, $order->get_id()), EVC_Bacs_Recovery_Decision::ALREADY_COMPLETE, 'intent_state_behind');
        $lock->release();
    }

    // ---- 18: authorisation ------------------------------------------------------------------------------------

    public function test_only_an_authorised_manager_may_inspect_and_never_the_shared_staff_account(): void {
        $customer = $this->customer();
        $order = $this->order($customer);
        $manager = $this->manager();
        $lock = $this->lock($customer);
        $this->intents()->create($lock, $this->planned_intent($customer, $order, $manager));
        $lock->release();
        $shop_manager = (int) self::factory()->user->create(array('role' => 'shop_manager'));
        $admin = (int) self::factory()->user->create(array('role' => 'administrator'));
        $staff = $this->create_staff_user();
        get_userdata($staff)->add_cap(EVC_Bacs_Payment_Confirmation::CAPABILITY);
        foreach (array($shop_manager, $admin, $staff, 0, 999999) as $actor) {
            $this->assertDecision($this->inspector()->inspect($actor, $order->get_id()), EVC_Bacs_Recovery_Decision::REFUSED, 'actor_not_authorized');
        }
        get_userdata($customer)->add_cap(EVC_Bacs_Payment_Confirmation::CAPABILITY);
        $this->assertDecision($this->inspector()->inspect($customer, $order->get_id()), EVC_Bacs_Recovery_Decision::REFUSED, 'own_order');
        $this->assertDecision($this->inspector()->inspect($manager, $order->get_id()), EVC_Bacs_Recovery_Decision::RESUME);
        $this->assertFalse(get_role('shop_manager')->has_cap(EVC_Bacs_Payment_Confirmation::CAPABILITY));
        $this->assertFalse(get_role('administrator')->has_cap(EVC_Bacs_Payment_Confirmation::CAPABILITY));
    }

    // ---- 19: no sensitive information ----------------------------------------------------------------------------

    public function test_no_bank_reference_or_personal_data_is_stored_or_exposed_to_the_customer(): void {
        global $wpdb;
        $customer = $this->customer();
        $actor = $this->manager();
        list($order) = $this->fund($customer, $actor);
        $value = $this->meta_rows($order->get_id())[0];
        $user = get_userdata($customer);
        foreach (array_merge($this->references, array($user->user_email, $user->display_name, 'club.billing@example.org', EVC_CLUB_EVIDENCE_KEY)) as $secret) {
            $this->assertStringNotContainsString($secret, $value);
        }
        foreach ($this->references as $ref) {
            $like = '%' . $wpdb->esc_like($ref) . '%';
            foreach (array($wpdb->postmeta => 'meta_value', $wpdb->prefix . 'wc_orders_meta' => 'meta_value', $wpdb->comments => 'comment_content', $wpdb->options => 'option_value') as $table => $column) {
                if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table) {
                    $this->assertSame('0', (string) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE {$column} LIKE %s", $like)), $table);
                }
            }
        }

        // Customer-facing REST never shows it: the admin orders API refuses customers, /me omits it.
        $this->login_as($customer);
        $r = $this->rest('GET', '/wc/v3/orders/' . $order->get_id(), null, $this->nonce());
        $this->assertContains($r->get_status(), array(401, 403));
        $this->assertStringNotContainsString(EVC_Wc_Bacs_Intent_Store::META_KEY, wp_json_encode($r->get_data()));
        $store = $this->rest('GET', '/wc/store/v1/order/' . $order->get_id() . '?key=' . $order->get_order_key() . '&billing_email=club.billing@example.org', null, $this->nonce());
        $this->assertStringNotContainsString(EVC_Wc_Bacs_Intent_Store::META_KEY, wp_json_encode($store->get_data()));
        $this->assertStringNotContainsString(json_decode($value, true)['sig'], wp_json_encode($store->get_data()));
        wp_set_current_user(0);
        $this->me($customer);
    }

    // ---- 20: no membership from an intent -------------------------------------------------------------------------

    public function test_planned_or_issued_intents_never_activate_a_membership(): void {
        $customer = $this->customer();
        $actor = $this->manager();
        $order = $this->order($customer);
        $planned = $this->planned_intent($customer, $order, $actor);
        $lock = $this->lock($customer);
        $this->intents()->create($lock, $planned);
        $this->assertSame('pending_payment', $this->me($customer)['state']);
        $this->issue_with_plan($order, $planned);
        $issued = $planned->issued($this->active_row_ref($customer));
        $this->intents()->transition($lock, $planned, $issued);
        $this->assertSame('unverified', $this->me($customer)['state'], 'PMPro row with the exact plan + signed issued intent is still not paid');
        // Even an intent CLAIMING "recorded" grants nothing without the Club record.
        $this->intents()->transition($lock, $issued, $issued->recorded());
        $this->assertSame('unverified', $this->me($customer)['state']);
        $this->assertDecision($this->inspector()->inspect($actor, $order->get_id()), EVC_Bacs_Recovery_Decision::CONFLICT, 'intent_claims_recorded_without_provenance');
        $lock->release();
    }
}
