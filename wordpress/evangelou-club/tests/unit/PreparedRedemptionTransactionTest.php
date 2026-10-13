<?php
use PHPUnit\Framework\TestCase;

/**
 * UI-03D.A — atomic redemption + preparation transaction model, exercised
 * against the in-memory store with failure injection and deterministic
 * two-till interleavings. No database: the SQL transaction that must satisfy
 * the same contract is documented, not implemented.
 */
final class PreparedRedemptionTransactionTest extends TestCase {
    const MEMBER_A = 'mem_0123456789abcdef0123456789abcdef';
    const MEMBER_B = 'mem_fedcba9876543210fedcba9876543210';
    const R1 = '3fa85f64-5717-4562-b3fc-2c963f66afa6';
    const R2 = '6f9619ff-8b86-4011-b42d-00c04fc964ff';
    const R3 = '1b4e28ba-2fa1-41d2-883f-0016d3cca427';
    const R4 = '9a0c3e3c-5d1a-4d6e-a7c2-4f1c2b3a4d5e';
    const STAFF = 7;
    const SESSION = '0123456789abcdef0123456789abcdef';
    /** 10:30 Athens (EEST, UTC+3). */
    const MORNING = '2026-10-14T07:30:00Z';

    /** @var EVC_In_Memory_Prepared_Redemption_Store */
    private $store;
    /** @var EVC_Fake_Prep_Catalogue_Authority */
    private $authority;
    /** @var EVC_Mock_Membership_Adapter */
    private $membership;
    /** @var EVC_Fixed_Clock */
    private $clock;
    /** @var array[] */
    private $reports = array();
    /** @var int */
    private $member_a_id;

    protected function setUp(): void {
        $this->store = new EVC_In_Memory_Prepared_Redemption_Store();
        $this->member_a_id = $this->store->add_member(self::MEMBER_A, 501);
        $this->store->add_member(self::MEMBER_B, 502);
        $this->store->add_member('mem_00000000000000000000000000000000', 503, 'disabled');
        $this->authority = new EVC_Fake_Prep_Catalogue_Authority(self::catalogue_data());
        $this->membership = new EVC_Mock_Membership_Adapter();
        $this->membership->set(501, EVC_Mock_Membership_Adapter::active_until('2026-11-14T00:00:00Z'));
        $this->membership->set(502, EVC_Mock_Membership_Adapter::active_until('2026-11-14T00:00:00Z'));
        $this->clock = new EVC_Fixed_Clock(self::MORNING);
        $this->reports = array();
    }

    // ---- helpers ---------------------------------------------------------------------------

    private static function catalogue_data(): array {
        return json_decode((string) file_get_contents(dirname(__DIR__) . '/fixtures/preparation/catalogue-illustrative.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    private static function vectors(): array {
        return json_decode((string) file_get_contents(dirname(__DIR__) . '/fixtures/preparation/vectors.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    private function service(?string $opt_in = EVC_Prepared_Redemption_Service::ALLOW_ILLUSTRATIVE_CATALOGUE_FOR_TESTS): EVC_Prepared_Redemption_Service {
        $reports = &$this->reports;
        return new EVC_Prepared_Redemption_Service($this->store, $this->authority, $this->membership, $this->clock, $opt_in, function (array $d) use (&$reports) {
            $reports[] = $d;
        });
    }

    private static function actor(int $staff = self::STAFF, ?string $session = self::SESSION): EVC_Staff_Actor {
        return new EVC_Staff_Actor($staff, $session);
    }

    private static function body(array $overrides = array()): array {
        return array_merge(array(
            'request_id' => self::R1,
            'catalogue_version' => 1,
            'coffee_code' => 'espresso',
            'preparation' => array(
                'modifiers' => array(array('group' => 'shots', 'option' => 'double'), array('group' => 'sweetness', 'option' => 'medium'), array('group' => 'sweetener', 'option' => 'stevia')),
                'quick_notes' => array('very_hot', 'takeaway'),
            ),
        ), $overrides);
    }

    private static function cappuccino(string $request_id): array {
        return self::body(array('request_id' => $request_id, 'coffee_code' => 'cappuccino', 'preparation' => array(
            'modifiers' => array(array('group' => 'shots', 'option' => 'single'), array('group' => 'sweetness', 'option' => 'none'), array('group' => 'milk', 'option' => 'plant')),
            'quick_notes' => array('cinnamon'),
        )));
    }

    private function redeem(array $body, string $member = self::MEMBER_A, ?EVC_Staff_Actor $actor = null): EVC_Prepared_Redemption_Outcome {
        return $this->service()->redeem($member, 'free_coffee', $actor !== null ? $actor : self::actor(), $body);
    }

    private function assertNothingWritten(): void {
        $this->assertSame(array(), $this->store->ledger_rows());
        $this->assertSame(array(), $this->store->snapshots());
        $this->assertSame(array(), $this->store->audit_events());
    }

    private function assertConsistent(int $rows): void {
        $this->assertSame(array(), $this->store->violations());
        $this->assertCount($rows, $this->store->ledger_rows());
        $this->assertCount($rows, $this->store->snapshots());
        $this->assertCount($rows, $this->store->audit_events());
    }

    /** A catalogue version 2 that relabels espresso, retires the stevia sweetener and drops greek coffee. */
    private static function catalogue_v2(): array {
        $v2 = self::catalogue_data();
        $v2['catalogue_version'] = 2;
        foreach ($v2['coffees'] as $i => $coffee) {
            if ($coffee['code'] === 'espresso') {
                $v2['coffees'][$i]['label'] = 'Espresso (νέο)';
                foreach ($coffee['groups'] as $g => $group) {
                    if ($group['id'] === 'sweetener') {
                        $v2['coffees'][$i]['groups'][$g]['options'] = array_values(array_filter($group['options'], function (array $o): bool {
                            return $o['id'] !== 'stevia';
                        }));
                    }
                }
            }
        }
        $v2['coffees'] = array_values(array_filter($v2['coffees'], function (array $c): bool {
            return $c['code'] !== 'greek_coffee';
        }));
        return $v2;
    }

    // ---- 1-2. one redemption = exactly three records, together ------------------------------

    public function test_an_eligible_redemption_writes_exactly_one_ledger_row_snapshot_and_audit_event(): void {
        $outcome = $this->redeem(self::body());

        $this->assertSame(EVC_Prepared_Redemption_Outcome::REDEEMED, $outcome->kind());
        $this->assertTrue($outcome->may_serve());
        $this->assertNull($outcome->retry_request_id());
        $this->assertConsistent(1);

        $row = $this->store->ledger_rows()[0];
        $snapshot = $this->store->snapshots()[$row['id']][0];
        $audit = $this->store->audit_events()[0];
        $vector = self::vectors()['fingerprints'][0];
        $this->assertSame($vector['prep_hash'], $row['prep_hash']);
        $this->assertSame($vector['fingerprint_v2'], $row['request_fingerprint']);
        $this->assertSame('evc-redeem-v2', $row['fingerprint_version']);
        $this->assertSame($row['prep_hash'], $snapshot['prep_hash']);
        $this->assertSame($row['snapshot_hash'], $snapshot['snapshot_hash']);
        $this->assertSame($row['id'], $audit['redemption_id']);
        $this->assertSame(EVC_Prepared_Redemption_Outcome::REDEEMED, $audit['outcome']);
        $this->assertSame(self::SESSION, $audit['details']['session_ref']);
        $this->assertSame($row['prep_hash'], $audit['details']['prep_hash']);
        $this->assertSame(array(), $this->store->rejections);

        $redemption = $outcome->redemption();
        $this->assertSame($row['id'], $redemption['redemption_id']);
        $this->assertSame($snapshot, $redemption['preparation']);
        $this->assertSame('2026-10-14', $redemption['business_date']);
    }

    public function test_the_snapshot_is_structured_with_label_copies_and_no_free_text(): void {
        $snapshot = $this->redeem(self::body())->redemption()['preparation'];
        $this->assertSame(array('schema', 'prep_version', 'catalogue_version', 'coffee_code', 'coffee_label', 'modifiers', 'quick_notes', 'prep_hash', 'snapshot_hash'), array_keys($snapshot));
        $this->assertSame(array('group' => 'shots', 'option' => 'double', 'group_label' => 'Δόση', 'label' => 'Διπλός'), $snapshot['modifiers'][0]);
        $this->assertSame(array('takeaway', 'very_hot'), array_column($snapshot['quick_notes'], 'id'));
        $this->assertArrayNotHasKey('note', $snapshot);
        $this->assertSame(EVC_Prep_Canonical::canonical(1, 'espresso', array('shots' => 'double', 'sweetener' => 'stevia', 'sweetness' => 'medium'), array('takeaway', 'very_hot')), EVC_Prep_Snapshot::verify($snapshot));
    }

    /** @return array<string,array{string}> */
    public static function failure_points(): array {
        $out = array();
        foreach (array('before_ledger', 'after_ledger', 'after_snapshot', 'after_audit', 'commit') as $point) {
            $out[$point] = array($point);
        }
        return $out;
    }

    /** @dataProvider failure_points */
    public function test_a_failure_at_any_step_writes_nothing_and_the_same_request_id_then_succeeds_once(string $point): void {
        $this->store->failures = array(array($point, new EVC_Db_Exception('Club database query failed.', 'HY000', 2006)));
        $failed = $this->redeem(self::body());

        $this->assertSame(EVC_Prepared_Redemption_Outcome::SERVER_ERROR, $failed->kind());
        $this->assertFalse($failed->may_serve());
        $this->assertSame(self::R1, $failed->retry_request_id());
        $this->assertNothingWritten();
        $this->assertContains($point, $this->store->points_reached);

        $retry = $this->redeem(self::body());
        $this->assertSame(EVC_Prepared_Redemption_Outcome::REDEEMED, $retry->kind());
        $this->assertConsistent(1);
    }

    public function test_a_non_database_error_inside_the_transaction_also_rolls_everything_back(): void {
        $this->store->failures = array(array('after_snapshot', new RuntimeException('boom')));
        $this->assertSame(EVC_Prepared_Redemption_Outcome::SERVER_ERROR, $this->redeem(self::body())->kind());
        $this->assertNothingWritten();
        $this->assertSame('RuntimeException', $this->reports[0]['exception']);
        $this->assertArrayNotHasKey('message', $this->reports[0]);
    }

    /** @return array<string,array{callable}> */
    public static function inconsistent_writes(): array {
        return array(
            'ledger prep_hash differs' => array(function (array &$l, array &$s, array &$a) {
                $l['prep_hash'] = str_repeat('0', 64);
            }),
            'ledger snapshot_hash differs' => array(function (array &$l, array &$s, array &$a) {
                $l['snapshot_hash'] = str_repeat('0', 64);
            }),
            'ledger coffee differs' => array(function (array &$l, array &$s, array &$a) {
                $l['coffee_code'] = 'cappuccino';
            }),
            'ledger catalogue version differs' => array(function (array &$l, array &$s, array &$a) {
                $l['catalogue_version'] = 2;
            }),
            'v1 fingerprint version' => array(function (array &$l, array &$s, array &$a) {
                $l['fingerprint_version'] = 'evc-redeem-v1';
            }),
            'snapshot carries a note' => array(function (array &$l, array &$s, array &$a) {
                $s['note'] = 'x';
            }),
            'snapshot label edited' => array(function (array &$l, array &$s, array &$a) {
                $s['coffee_label'] = 'Ristretto';
            }),
            'ledger has an extra key' => array(function (array &$l, array &$s, array &$a) {
                $l['note'] = null;
            }),
            'audit for another request' => array(function (array &$l, array &$s, array &$a) {
                $a['request_id'] = PreparedRedemptionTransactionTest::R2;
            }),
            'audit for another member' => array(function (array &$l, array &$s, array &$a) {
                $a['member_id'] = 99;
            }),
            'audit by another actor' => array(function (array &$l, array &$s, array &$a) {
                $a['staff_wp_user_id'] = 8;
            }),
            'audit at another time' => array(function (array &$l, array &$s, array &$a) {
                $a['occurred_at_utc'] = '2026-10-14 07:30:01.000000';
            }),
            'audit is not a success' => array(function (array &$l, array &$s, array &$a) {
                $a['outcome'] = 'benefit_already_redeemed';
            }),
        );
    }

    /** @dataProvider inconsistent_writes */
    public function test_a_write_set_whose_three_records_disagree_cannot_be_built(callable $tamper): void {
        $this->redeem(self::body());
        $row = $this->store->ledger_rows()[0];
        $snapshot = $this->store->snapshots()[$row['id']][0];
        $audit = $this->store->audit_events()[0];
        unset($row['id'], $audit['redemption_id']);
        new EVC_Prepared_Redemption_Write($row, $snapshot, $audit); // the untampered triple is accepted

        $tamper($row, $snapshot, $audit);
        $this->expectException(InvalidArgumentException::class);
        new EVC_Prepared_Redemption_Write($row, $snapshot, $audit);
    }

    // ---- 3. one redemption per member, benefit and Athens day -------------------------------

    public function test_a_second_coffee_on_the_same_athens_day_is_refused_whatever_the_preparation(): void {
        $this->redeem(self::body());
        $this->clock->set('2026-10-14T20:59:59.999999Z'); // 23:59:59.999999 Athens
        $second = $this->redeem(self::cappuccino(self::R2));

        $this->assertSame(EVC_Prepared_Redemption_Outcome::ALREADY_REDEEMED, $second->kind());
        $this->assertFalse($second->may_serve());
        $this->assertSame('2026-10-14', $second->details()['business_date']);
        $this->assertConsistent(1);
        $this->assertSame(EVC_Prepared_Redemption_Outcome::ALREADY_REDEEMED, $this->store->rejections[0]['outcome']);
    }

    public function test_the_next_athens_day_starts_at_local_midnight_not_utc_midnight(): void {
        $this->clock->set('2026-10-14T20:59:59.999999Z');
        $this->assertSame('2026-10-14', $this->redeem(self::body())->redemption()['business_date']);
        $this->clock->set('2026-10-14T21:00:00Z'); // 00:00 Athens, still 14 October in UTC
        $next = $this->redeem(self::cappuccino(self::R2));
        $this->assertSame(EVC_Prepared_Redemption_Outcome::REDEEMED, $next->kind());
        $this->assertSame('2026-10-15', $next->redemption()['business_date']);
        $this->assertConsistent(2);
    }

    public function test_the_athens_day_follows_winter_time_after_the_dst_change(): void {
        $this->clock->set('2026-10-25T21:59:59Z'); // 23:59:59 EET (UTC+2)
        $this->assertSame('2026-10-25', $this->redeem(self::body())->redemption()['business_date']);
        $this->clock->set('2026-10-25T22:00:00Z');
        $this->assertSame('2026-10-26', $this->redeem(self::cappuccino(self::R2))->redemption()['business_date']);
    }

    public function test_a_legacy_v1_redemption_on_the_same_day_blocks_a_prepared_redemption(): void {
        $this->store->insert_legacy_v1($this->member_a_id, '2026-10-14', self::R4, 'espresso');
        $outcome = $this->redeem(self::body());
        $this->assertSame(EVC_Prepared_Redemption_Outcome::ALREADY_REDEEMED, $outcome->kind());
        $this->assertCount(1, $this->store->ledger_rows());
    }

    public function test_another_member_on_the_same_day_is_independent(): void {
        $this->redeem(self::body());
        $this->assertSame(EVC_Prepared_Redemption_Outcome::REDEEMED, $this->redeem(self::body(array('request_id' => self::R2)), self::MEMBER_B)->kind());
        $this->assertConsistent(2);
    }

    // ---- 4. exact idempotent replay ----------------------------------------------------------

    public function test_an_exact_retry_replays_the_stored_record_without_new_writes_or_new_decisions(): void {
        $first = $this->redeem(self::body());
        $clock_reads = $this->clock->reads;
        $membership_calls = count($this->membership->calls);
        $catalogue_reads = $this->authority->reads;

        $retry = $this->redeem(self::body());
        $this->assertSame(EVC_Prepared_Redemption_Outcome::REPLAYED, $retry->kind());
        $this->assertTrue($retry->may_serve());
        $this->assertSame($first->redemption(), $retry->redemption());
        $this->assertConsistent(1);
        $this->assertSame($clock_reads, $this->clock->reads, 'no clock read on replay');
        $this->assertSame($membership_calls, count($this->membership->calls), 'no membership decision on replay');
        $this->assertSame($catalogue_reads, $this->authority->reads, 'no catalogue decision on replay');
    }

    public function test_replay_survives_midnight_membership_expiry_and_a_catalogue_update(): void {
        $first = $this->redeem(self::body());

        $this->clock->set('2026-11-20T09:00:00Z'); // weeks later, another business date
        $this->membership->set(501, EVC_Mock_Membership_Adapter::with(EVC_Entitlement::STATUS_EXPIRED, true, '2026-11-14T00:00:00Z'));
        $this->authority->data = self::catalogue_v2(); // relabelled espresso, stevia retired

        $retry = $this->redeem(self::body());
        $this->assertSame(EVC_Prepared_Redemption_Outcome::REPLAYED, $retry->kind());
        $this->assertSame($first->redemption(), $retry->redemption());
        $this->assertSame('Espresso', $retry->redemption()['preparation']['coffee_label']);
        $this->assertSame('2026-10-14', $retry->redemption()['business_date']);
        $this->assertConsistent(1);
    }

    public function test_replay_works_even_while_the_active_catalogue_is_not_authorised(): void {
        $first = $this->redeem(self::body());
        $unapproved = self::catalogue_data();
        $unapproved['status'] = 'approved'; // claims approval, no owner approval record
        $this->authority->data = $unapproved;
        $this->assertSame($first->redemption(), $this->redeem(self::body())->redemption());
        $this->assertSame(EVC_Prepared_Redemption_Outcome::CATALOGUE_NOT_AUTHORISED, $this->redeem(self::cappuccino(self::R2), self::MEMBER_B)->kind());
    }

    public function test_reordered_modifiers_and_notes_and_a_new_login_session_still_replay(): void {
        $first = $this->redeem(self::body());
        $shuffled = self::body(array('preparation' => array(
            'modifiers' => array(array('group' => 'sweetener', 'option' => 'stevia'), array('group' => 'shots', 'option' => 'double'), array('group' => 'sweetness', 'option' => 'medium')),
            'quick_notes' => array('takeaway', 'very_hot'),
            'note' => null,
        )));
        $retry = $this->redeem($shuffled, self::MEMBER_A, self::actor(self::STAFF, 'ffffffffffffffffffffffffffffffff'));
        $this->assertSame(EVC_Prepared_Redemption_Outcome::REPLAYED, $retry->kind());
        $this->assertSame($first->redemption(), $retry->redemption());
    }

    // ---- 5. same request id, different payload -----------------------------------------------

    /** @return array<string,array{array,string,int}> */
    public static function different_payloads(): array {
        $mods = function (string $sweetener): array {
            return array('modifiers' => array(array('group' => 'shots', 'option' => 'double'), array('group' => 'sweetness', 'option' => 'medium'), array('group' => 'sweetener', 'option' => $sweetener)), 'quick_notes' => array('very_hot', 'takeaway'));
        };
        return array(
            'other sweetener' => array(array('preparation' => $mods('brown')), self::MEMBER_A, self::STAFF),
            'one quick note less' => array(array('preparation' => array('modifiers' => $mods('stevia')['modifiers'], 'quick_notes' => array('takeaway'))), self::MEMBER_A, self::STAFF),
            'other quick note' => array(array('preparation' => array('modifiers' => $mods('stevia')['modifiers'], 'quick_notes' => array('lukewarm', 'takeaway'))), self::MEMBER_A, self::STAFF),
            'decaf added' => array(array('preparation' => array('modifiers' => array_merge($mods('stevia')['modifiers'], array(array('group' => 'caffeine', 'option' => 'decaf'))), 'quick_notes' => array('very_hot', 'takeaway'))), self::MEMBER_A, self::STAFF),
            'other coffee' => array(array('coffee_code' => 'greek_coffee'), self::MEMBER_A, self::STAFF),
            'other catalogue version' => array(array('catalogue_version' => 2), self::MEMBER_A, self::STAFF),
            'other member' => array(array(), self::MEMBER_B, self::STAFF),
            'other staff actor' => array(array(), self::MEMBER_A, 8),
        );
    }

    /** @dataProvider different_payloads */
    public function test_reusing_a_request_id_with_a_different_payload_is_rejected_without_revealing_the_original(array $override, string $member, int $staff): void {
        $this->redeem(self::body());
        $outcome = $this->redeem(self::body($override), $member, self::actor($staff));

        $this->assertSame(EVC_Prepared_Redemption_Outcome::IDEMPOTENCY_CONFLICT, $outcome->kind());
        $this->assertFalse($outcome->may_serve());
        $this->assertNull($outcome->redemption());
        $this->assertSame(array(), $outcome->details());
        $this->assertConsistent(1);
        $this->assertSame(EVC_Prepared_Redemption_Outcome::IDEMPOTENCY_CONFLICT, $this->store->rejections[0]['outcome']);
    }

    public function test_a_request_id_used_by_the_v1_engine_never_replays_a_prepared_request(): void {
        $this->store->insert_legacy_v1($this->member_a_id, '2026-10-13', self::R1, 'espresso');
        $this->assertSame(EVC_Prepared_Redemption_Outcome::IDEMPOTENCY_CONFLICT, $this->redeem(self::body())->kind());
    }

    // ---- 6. unknown COMMIT outcome -----------------------------------------------------------

    public function test_an_unknown_commit_that_did_commit_never_authorises_a_second_coffee(): void {
        $this->store->commit_unknown = 'committed';
        $unknown = $this->redeem(self::body());

        $this->assertSame(EVC_Prepared_Redemption_Outcome::OUTCOME_UNKNOWN, $unknown->kind());
        $this->assertFalse($unknown->may_serve());
        $this->assertNull($unknown->redemption());
        $this->assertSame(self::R1, $unknown->retry_request_id());
        $this->assertSame(1, $this->store->commit_attempts, 'an unknown commit is never retried blindly');
        $this->assertConsistent(1);

        // A NEW intent the same day cannot produce a second coffee.
        $this->assertSame(EVC_Prepared_Redemption_Outcome::ALREADY_REDEEMED, $this->redeem(self::cappuccino(self::R2))->kind());

        // Recovery with the ORIGINAL id, after midnight, expiry and a catalogue update: the same single coffee.
        $this->clock->set('2026-11-20T09:00:00Z');
        $this->membership->set(501, EVC_Mock_Membership_Adapter::with(EVC_Entitlement::STATUS_EXPIRED, true, '2026-11-14T00:00:00Z'));
        $this->authority->data = self::catalogue_v2();
        $recovered = $this->redeem(self::body(array('request_id' => $unknown->retry_request_id())));
        $this->assertSame(EVC_Prepared_Redemption_Outcome::REPLAYED, $recovered->kind());
        $this->assertSame('2026-10-14', $recovered->redemption()['business_date']);
        $this->assertConsistent(1);
        $this->assertSame('commit_unknown', $this->reports[0]['stage']);
    }

    public function test_an_unknown_commit_that_did_not_commit_is_resolved_by_the_same_request_id_as_the_first_coffee(): void {
        $this->store->commit_unknown = 'not_committed';
        $unknown = $this->redeem(self::body());
        $this->assertSame(EVC_Prepared_Redemption_Outcome::OUTCOME_UNKNOWN, $unknown->kind());
        $this->assertNothingWritten();

        $recovered = $this->redeem(self::body(array('request_id' => $unknown->retry_request_id())));
        $this->assertSame(EVC_Prepared_Redemption_Outcome::REDEEMED, $recovered->kind());
        $this->assertSame(EVC_Prepared_Redemption_Outcome::REPLAYED, $this->redeem(self::body())->kind());
        $this->assertConsistent(1);
    }

    public function test_unresolved_outcomes_can_never_be_built_as_a_success_or_a_definitive_failure(): void {
        foreach (array(EVC_Prepared_Redemption_Outcome::OUTCOME_UNKNOWN, EVC_Prepared_Redemption_Outcome::SERVER_ERROR) as $kind) {
            try {
                EVC_Prepared_Redemption_Outcome::failure($kind);
                $this->fail($kind . ' accepted as a definitive failure');
            } catch (InvalidArgumentException $e) {
                $this->assertSame('Not a definitive failure outcome.', $e->getMessage());
            }
            $this->assertFalse(EVC_Prepared_Redemption_Outcome::unresolved($kind, self::R1)->may_serve());
        }
        $this->expectException(InvalidArgumentException::class);
        EVC_Prepared_Redemption_Outcome::unresolved(EVC_Prepared_Redemption_Outcome::REDEEMED, self::R1);
    }

    // ---- 11. retryable failures ----------------------------------------------------------------

    public function test_deadlocks_are_retried_as_a_whole_transaction_within_the_bound(): void {
        $deadlock = new EVC_Db_Retryable_Exception('Club database deadlock.', '40001', 1213);
        $this->store->failures = array(array('after_ledger', $deadlock), array('after_audit', $deadlock));
        $this->assertSame(EVC_Prepared_Redemption_Outcome::REDEEMED, $this->redeem(self::body())->kind());
        $this->assertSame(3, $this->store->commit_attempts);
        $this->assertConsistent(1);
    }

    public function test_exhausted_retries_write_nothing_and_the_same_request_id_succeeds_later(): void {
        $wait = new EVC_Db_Retryable_Exception('Club database lock wait timeout.', 'HY000', 1205);
        $this->store->failures = array(array('after_snapshot', $wait), array('after_snapshot', $wait), array('after_snapshot', $wait));
        $outcome = $this->redeem(self::body());
        $this->assertSame(EVC_Prepared_Redemption_Outcome::SERVER_ERROR, $outcome->kind());
        $this->assertSame(array('reason' => 'transaction_retry_exhausted'), $outcome->details());
        $this->assertSame(self::R1, $outcome->retry_request_id());
        $this->assertSame(EVC_Prepared_Redemption_Service::MAX_TRANSACTION_ATTEMPTS, $this->store->commit_attempts);
        $this->assertNothingWritten();
        $this->assertSame(EVC_Prepared_Redemption_Outcome::REDEEMED, $this->redeem(self::body())->kind());
        $this->assertConsistent(1);
    }

    public function test_a_deadlock_retry_that_meets_a_committed_competitor_reports_already_redeemed(): void {
        $store = $this->store;
        $competitor = function () {
            $this->assertSame(EVC_Prepared_Redemption_Outcome::REDEEMED, $this->redeem(self::cappuccino(self::R2))->kind());
        };
        $store->failures = array(array('after_ledger', new EVC_Db_Retryable_Exception('Club database deadlock.', '40001', 1213)));
        $store->before_commit = function () use ($store, $competitor) {
            $store->before_commit = $competitor; // the other till commits before our SECOND attempt
        };
        $this->assertSame(EVC_Prepared_Redemption_Outcome::ALREADY_REDEEMED, $this->redeem(self::body())->kind());
        $this->assertConsistent(1);
    }

    // ---- 11. two simultaneous tills and duplicate requests -----------------------------------

    /** @return array<string,array{string,string,array<string,string>}> */
    public static function two_till_cases(): array {
        $out = array();
        // scenario => [till B member, till B body, expected [first committer, second]]
        $scenarios = array(
            'other request same member' => array(self::MEMBER_A, 'other', array(EVC_Prepared_Redemption_Outcome::REDEEMED, EVC_Prepared_Redemption_Outcome::ALREADY_REDEEMED)),
            'duplicate submit same request' => array(self::MEMBER_A, 'same', array(EVC_Prepared_Redemption_Outcome::REDEEMED, EVC_Prepared_Redemption_Outcome::REPLAYED)),
            'same request other preparation' => array(self::MEMBER_A, 'same_id_other_prep', array(EVC_Prepared_Redemption_Outcome::REDEEMED, EVC_Prepared_Redemption_Outcome::IDEMPOTENCY_CONFLICT)),
            'other member' => array(self::MEMBER_B, 'other', array(EVC_Prepared_Redemption_Outcome::REDEEMED, EVC_Prepared_Redemption_Outcome::REDEEMED)),
        );
        foreach ($scenarios as $name => $s) {
            foreach (array('serial', 'after_request_lookup', 'before_commit') as $schedule) {
                $out[$name . ' / ' . $schedule] = array($schedule, $s[0], $s[1], $s[2]);
            }
        }
        return $out;
    }

    /** @dataProvider two_till_cases */
    public function test_two_tills_never_record_two_coffees_for_one_member_day(string $schedule, string $member_b, string $body_b, array $expected): void {
        $bodies = array(
            'same' => self::body(),
            'other' => self::cappuccino(self::R2),
            'same_id_other_prep' => self::cappuccino(self::R1),
        );
        $outcome_b = null;
        $till_b = function () use (&$outcome_b, $member_b, $bodies, $body_b) {
            $outcome_b = $this->redeem($bodies[$body_b], $member_b);
        };

        if ($schedule === 'serial') {
            $outcome_a = $this->redeem(self::body());
            $till_b();
            $first = $outcome_a;
            $second = $outcome_b;
        } else {
            // Till B runs completely between till A's request-id lookup (or
            // eligibility check) and till A's commit: B commits first.
            if ($schedule === 'after_request_lookup') {
                $this->store->after_request_lookup = $till_b;
            } else {
                $this->store->before_commit = $till_b;
            }
            $outcome_a = $this->redeem(self::body());
            $first = $outcome_b;
            $second = $outcome_a;
        }

        $this->assertSame($expected, array($first->kind(), $second->kind()));
        $this->assertSame(array(), $this->store->violations());
        $rows = $member_b === self::MEMBER_A ? 1 : 2;
        $this->assertCount($rows, $this->store->ledger_rows());
        if ($second->kind() === EVC_Prepared_Redemption_Outcome::REPLAYED) {
            $this->assertSame($first->redemption(), $second->redemption(), 'a replay is the SAME redemption, not another coffee');
        }
        if ($rows === 1) {
            $served = array_filter(array($first, $second), function (EVC_Prepared_Redemption_Outcome $o): bool {
                return $o->kind() === EVC_Prepared_Redemption_Outcome::REDEEMED;
            });
            $this->assertCount(1, $served, 'exactly one REDEEMED outcome for the member-day');
        }
    }

    public function test_three_tills_racing_for_one_member_produce_one_coffee(): void {
        $outcomes = array();
        $till = function (string $request_id) use (&$outcomes) {
            return function () use (&$outcomes, $request_id) {
                $outcomes[$request_id] = $this->redeem(self::cappuccino($request_id))->kind();
            };
        };
        // A looks up, then B runs; B looks up, then C runs and commits; B then A collide.
        $this->store->after_request_lookup = function () use ($till) {
            $this->store->after_request_lookup = $till(self::R3);
            $till(self::R2)();
        };
        $outcomes[self::R1] = $this->redeem(self::cappuccino(self::R1))->kind();

        $this->assertSame(EVC_Prepared_Redemption_Outcome::REDEEMED, $outcomes[self::R3]);
        $this->assertSame(EVC_Prepared_Redemption_Outcome::ALREADY_REDEEMED, $outcomes[self::R2]);
        $this->assertSame(EVC_Prepared_Redemption_Outcome::ALREADY_REDEEMED, $outcomes[self::R1]);
        $this->assertConsistent(1);
    }

    public function test_a_duplicate_key_without_a_visible_winner_fails_closed(): void {
        $this->store->failures = array(array('before_ledger', new EVC_Db_Duplicate_Key_Exception('uq_redemptions_one_per_day', '23000', 1062)));
        $outcome = $this->redeem(self::body());
        $this->assertSame(EVC_Prepared_Redemption_Outcome::SERVER_ERROR, $outcome->kind());
        $this->assertFalse($outcome->may_serve());
        $this->assertNothingWritten();
    }

    // ---- 10. missing, corrupt and conflicting snapshots fail closed --------------------------

    /** @return array<string,array{callable,string}> */
    public static function damaged_records(): array {
        $relabel = function (array $s): array {
            $s['modifiers'][0]['label'] = 'Μονός';
            return $s;
        };
        $rehash = function (array $s): array {
            // A self-consistent rewrite of the snapshot alone (both hashes recomputed).
            $codes = array();
            foreach ($s['modifiers'] as $m) {
                $codes[$m['group']] = $m['option'];
            }
            $s['prep_hash'] = EVC_Prep_Canonical::prep_hash(EVC_Prep_Canonical::canonical($s['catalogue_version'], $s['coffee_code'], $codes, array_column($s['quick_notes'], 'id')));
            $hash = new ReflectionMethod('EVC_Prep_Snapshot', 'snapshot_hash');
            $hash->setAccessible(true);
            unset($s['snapshot_hash']);
            $s['snapshot_hash'] = $hash->invoke(null, $s);
            return $s;
        };
        return array(
            'missing snapshot' => array(function (array $s) {
                return array('snapshots' => array());
            }, 'snapshot_missing'),
            'duplicated snapshot' => array(function (array $s) {
                return array('snapshots' => array($s, $s));
            }, 'snapshot_conflict'),
            'two different snapshots' => array(function (array $s) use ($relabel) {
                return array('snapshots' => array($s, $relabel($s)));
            }, 'snapshot_conflict'),
            'snapshot gained a note' => array(function (array $s) {
                $s['note'] = 'χωρίς ζάχαρη';
                return array('snapshots' => array($s));
            }, 'snapshot_corrupt'),
            'snapshot lost a field' => array(function (array $s) {
                unset($s['coffee_label']);
                return array('snapshots' => array($s));
            }, 'snapshot_corrupt'),
            'unknown schema' => array(function (array $s) {
                $s['schema'] = 'evc-prep-snapshot-v0';
                return array('snapshots' => array($s));
            }, 'snapshot_corrupt'),
            'label corrupted' => array(function (array $s) use ($relabel) {
                return array('snapshots' => array($relabel($s)));
            }, 'snapshot_corrupt'),
            'prep hash forged, snapshot hash recomputed' => array(function (array $s) {
                $s['prep_hash'] = str_repeat('0', 64);
                $hash = new ReflectionMethod('EVC_Prep_Snapshot', 'snapshot_hash');
                $hash->setAccessible(true);
                unset($s['snapshot_hash']);
                $s['snapshot_hash'] = $hash->invoke(null, $s);
                return array('snapshots' => array($s));
            }, 'snapshot_corrupt'),
            'option code corrupted' => array(function (array $s) {
                $s['modifiers'][0]['option'] = 'single';
                return array('snapshots' => array($s));
            }, 'snapshot_corrupt'),
            'modifiers out of order' => array(function (array $s) {
                $s['modifiers'] = array_reverse($s['modifiers']);
                return array('snapshots' => array($s));
            }, 'snapshot_corrupt'),
            'not a list' => array(function (array $s) {
                return array('snapshots' => array('x'));
            }, 'snapshot_corrupt'),
            'self-consistent relabel' => array(function (array $s) use ($relabel, $rehash) {
                return array('snapshots' => array($rehash($relabel($s))));
            }, 'snapshot_conflict'),
            'self-consistent other option' => array(function (array $s) use ($rehash) {
                $s['modifiers'][0]['option'] = 'single';
                $s['modifiers'][0]['label'] = 'Μονός';
                return array('snapshots' => array($rehash($s)));
            }, 'snapshot_conflict'),
            'ledger prep hash altered' => array(function (array $s) {
                return array('ledger' => array('prep_hash' => str_repeat('0', 64)));
            }, 'snapshot_conflict'),
            'ledger snapshot hash altered' => array(function (array $s) {
                return array('ledger' => array('snapshot_hash' => str_repeat('0', 64)));
            }, 'snapshot_conflict'),
            'ledger coffee altered' => array(function (array $s) {
                return array('ledger' => array('coffee_code' => 'cappuccino'));
            }, 'snapshot_conflict'),
            'ledger catalogue version altered' => array(function (array $s) {
                return array('ledger' => array('catalogue_version' => 2));
            }, 'snapshot_conflict'),
            'ledger fingerprint version altered' => array(function (array $s) {
                return array('ledger' => array('fingerprint_version' => 'evc-redeem-v1'));
            }, 'fingerprint_version'),
        );
    }

    /** @dataProvider damaged_records */
    public function test_a_damaged_stored_record_fails_closed_and_is_never_replayed_or_redeemed_again(callable $damage, string $reason): void {
        $this->redeem(self::body());
        $row = $this->store->ledger_rows()[0];
        $change = $damage($this->store->snapshots()[$row['id']][0]);
        if (isset($change['snapshots'])) {
            $this->store->tamper_snapshots(self::R1, $change['snapshots']);
        }
        if (isset($change['ledger'])) {
            $this->store->tamper_ledger(self::R1, $change['ledger']);
        }
        $before = array($this->store->ledger_rows(), $this->store->snapshots(), $this->store->audit_events());

        foreach (array(1, 2) as $attempt) {
            $outcome = $this->redeem(self::body());
            $this->assertSame(EVC_Prepared_Redemption_Outcome::RECORD_INTEGRITY_FAILURE, $outcome->kind(), 'attempt ' . $attempt);
            $this->assertFalse($outcome->may_serve());
            $this->assertNull($outcome->redemption());
            $this->assertNull($outcome->retry_request_id());
            $this->assertSame(array('reason' => $reason), $outcome->details());
        }
        $this->assertSame($before, array($this->store->ledger_rows(), $this->store->snapshots(), $this->store->audit_events()), 'nothing written or repaired');
        $this->assertSame(EVC_Prepared_Redemption_Outcome::RECORD_INTEGRITY_FAILURE, $this->store->rejections[0]['outcome']);
        $this->assertSame($reason, $this->store->rejections[0]['details']['reason']);
        // The damaged member-day still blocks another coffee.
        $this->assertSame(EVC_Prepared_Redemption_Outcome::ALREADY_REDEEMED, $this->redeem(self::cappuccino(self::R2))->kind());
    }

    public function test_a_competing_winner_with_a_damaged_snapshot_is_not_replayed(): void {
        $this->store->after_request_lookup = function () {
            $this->redeem(self::body());
            $this->store->tamper_snapshots(self::R1, array());
        };
        $outcome = $this->redeem(self::body());
        $this->assertSame(EVC_Prepared_Redemption_Outcome::RECORD_INTEGRITY_FAILURE, $outcome->kind());
        $this->assertSame(array('reason' => 'snapshot_missing'), $outcome->details());
    }

    public function test_snapshot_verification_never_consults_the_current_catalogue(): void {
        $snapshot = $this->redeem(self::body())->redemption()['preparation'];
        $this->authority->data = self::catalogue_v2(); // stevia no longer exists
        $this->assertSame('stevia', $snapshot['modifiers'][1]['option']);
        $this->assertIsString(EVC_Prep_Snapshot::verify($snapshot));
        $this->assertSame($snapshot, $this->store->snapshots()[1][0], 'stored snapshot unchanged by catalogue updates');
    }

    // ---- 7. nothing trusted from the client --------------------------------------------------

    /** @return array<string,array{string,mixed}> */
    public static function client_supplied_authority(): array {
        return array(
            'member id' => array('member_id', 1),
            'member public id' => array('member_public_id', self::MEMBER_B),
            'wp user' => array('wp_user_id', 501),
            'staff actor' => array('staff_wp_user_id', 7),
            'actor object' => array('actor', array('id' => 7)),
            'session' => array('session_ref', self::SESSION),
            'timestamp' => array('redeemed_at_utc', '2026-10-13 07:30:00.000000'),
            'business date' => array('business_date', '2026-10-13'),
            'eligibility' => array('eligible', true),
            'membership status' => array('membership_status', 'active'),
            'expiry' => array('membership_expires_at_utc', '2099-01-01 00:00:00'),
            'catalogue status' => array('catalogue_status', 'approved'),
            'approval' => array('approved', true),
            'fingerprint' => array('request_fingerprint', str_repeat('a', 64)),
            'top-level note' => array('note', null),
        );
    }

    /** @dataProvider client_supplied_authority */
    public function test_identity_actor_time_eligibility_or_approval_in_the_body_is_rejected_before_any_read(string $key, $value): void {
        $body = self::body();
        $body[$key] = $value;
        $outcome = $this->redeem($body);
        $this->assertSame(EVC_Prepared_Redemption_Outcome::INVALID_REQUEST, $outcome->kind());
        $this->assertSame(array('reason' => 'shape'), $outcome->details());
        $this->assertSame(0, $this->store->reads);
        $this->assertSame(0, $this->clock->reads);
        $this->assertSame(array(), $this->membership->calls);
        $this->assertNothingWritten();
    }

    public function test_member_actor_time_and_eligibility_come_only_from_server_sources(): void {
        $this->clock->set('2026-10-14T21:30:00Z'); // 00:30 Athens on the 15th
        $outcome = $this->redeem(self::body(), self::MEMBER_A, self::actor(9));
        $row = $this->store->ledger_rows()[0];
        $this->assertSame($this->member_a_id, $row['member_id']);
        $this->assertSame(array(array('wp_user_id' => 501, 'at' => '2026-10-14 21:30:00.000000')), $this->membership->calls);
        $this->assertSame(9, $row['staff_wp_user_id']);
        $this->assertSame('2026-10-14 21:30:00.000000', $row['redeemed_at_utc']);
        $this->assertSame('2026-10-15', $row['business_date']);
        $this->assertSame('mock', $row['membership_source']);
        $this->assertSame('2026-11-14 00:00:00.000000', $row['membership_expires_at_utc']);
        $this->assertSame(1, $this->clock->reads, 'the clock is read exactly once');
        $this->assertSame(EVC_Prepared_Redemption_Outcome::REDEEMED, $outcome->kind());
    }

    /** @return array<string,array{mixed,mixed}> */
    public static function bad_url_identity(): array {
        return array(
            'malformed member' => array('mem_123', 'free_coffee'),
            'internal id' => array(1, 'free_coffee'),
            'other benefit' => array(self::MEMBER_A, 'free_cake'),
        );
    }

    /** @dataProvider bad_url_identity */
    public function test_malformed_url_identity_is_rejected($member, $benefit): void {
        $outcome = $this->service()->redeem($member, $benefit, self::actor(), self::body());
        $this->assertSame(EVC_Prepared_Redemption_Outcome::INVALID_REQUEST, $outcome->kind());
        $this->assertSame(0, $this->store->reads);
    }

    public function test_unknown_or_disabled_members_are_refused_without_writes(): void {
        $this->assertSame(EVC_Prepared_Redemption_Outcome::MEMBER_NOT_FOUND, $this->redeem(self::body(), 'mem_11111111111111111111111111111111')->kind());
        $this->assertSame(EVC_Prepared_Redemption_Outcome::MEMBER_NOT_FOUND, $this->redeem(self::body(array('request_id' => self::R2)), 'mem_00000000000000000000000000000000')->kind());
        $this->assertNothingWritten();
    }

    /** @return array<string,array{EVC_Entitlement|Throwable,string}> */
    public static function ineligible(): array {
        return array(
            'expired' => array(EVC_Mock_Membership_Adapter::with(EVC_Entitlement::STATUS_EXPIRED, true, '2026-10-01T00:00:00Z'), EVC_Prepared_Redemption_Outcome::MEMBERSHIP_INACTIVE),
            'expiry instant is exclusive' => array(EVC_Mock_Membership_Adapter::active_until(self::MORNING), EVC_Prepared_Redemption_Outcome::MEMBERSHIP_INACTIVE),
            'payment unverified' => array(EVC_Mock_Membership_Adapter::with(EVC_Entitlement::STATUS_ACTIVE, false, '2026-11-14T00:00:00Z'), EVC_Prepared_Redemption_Outcome::MEMBERSHIP_INACTIVE),
            'expiry unknown' => array(EVC_Mock_Membership_Adapter::with(EVC_Entitlement::STATUS_ACTIVE, true, null), EVC_Prepared_Redemption_Outcome::MEMBERSHIP_INACTIVE),
            'adapter failure' => array(new EVC_Membership_Source_Exception('reader unavailable'), EVC_Prepared_Redemption_Outcome::SERVER_ERROR),
        );
    }

    /** @dataProvider ineligible */
    public function test_ineligible_members_get_no_coffee_and_no_records($entitlement, string $kind): void {
        $this->membership->set(501, $entitlement);
        $outcome = $this->redeem(self::body());
        $this->assertSame($kind, $outcome->kind());
        $this->assertFalse($outcome->may_serve());
        $this->assertNothingWritten();
    }

    public function test_staff_actor_must_be_a_real_session_identity(): void {
        foreach (array(array(0, null), array(-1, null), array(7, 'ABC'), array(7, str_repeat('g', 32))) as $case) {
            try {
                new EVC_Staff_Actor($case[0], $case[1]);
                $this->fail('accepted ' . json_encode($case));
            } catch (InvalidArgumentException $e) {
                $this->assertSame('Invalid staff actor.', $e->getMessage());
            }
        }
        $this->assertNull((new EVC_Staff_Actor(7))->session_ref());
    }

    // ---- 8. structured only --------------------------------------------------------------------

    /** @return array<string,array{mixed}> */
    public static function free_text(): array {
        return array('text' => array('χωρίς ζάχαρη'), 'empty' => array(''), 'zero' => array(0), 'false' => array(false), 'list' => array(array('x')));
    }

    /** @dataProvider free_text */
    public function test_any_free_text_note_is_rejected_before_any_read($note): void {
        $body = self::body();
        $body['preparation']['note'] = $note;
        $outcome = $this->redeem($body);
        $this->assertSame(EVC_Prepared_Redemption_Outcome::INVALID_PREPARATION, $outcome->kind());
        $this->assertSame(array('reason' => 'free_text_not_supported'), $outcome->details());
        $this->assertSame(0, $this->store->reads);
        $this->assertNothingWritten();
    }

    public function test_unknown_preparation_fields_are_rejected(): void {
        $body = self::body();
        $body['preparation']['comment'] = 'x';
        $this->assertSame(EVC_Prepared_Redemption_Outcome::INVALID_REQUEST, $this->redeem($body)->kind());
        $this->assertNothingWritten();
    }

    public function test_semantic_errors_write_nothing(): void {
        $body = self::body();
        $body['preparation']['quick_notes'] = array('very_hot', 'lukewarm');
        $outcome = $this->redeem($body);
        $this->assertSame(EVC_Prepared_Redemption_Outcome::INVALID_PREPARATION, $outcome->kind());
        $this->assertSame(array('reason' => 'exclusive_conflict'), $outcome->details());
        $this->assertSame(EVC_Prepared_Redemption_Outcome::INVALID_COFFEE, $this->redeem(self::body(array('request_id' => self::R2, 'coffee_code' => 'mocha')))->kind());
        $this->assertNothingWritten();
    }

    // ---- 13. catalogue authorisation is a server-side decision -------------------------------

    public function test_the_illustrative_catalogue_needs_the_explicit_test_opt_in(): void {
        foreach (array(null, 'yes', 'allow-illustrative-catalogue') as $opt_in) {
            $outcome = $this->service($opt_in)->redeem(self::MEMBER_A, 'free_coffee', self::actor(), self::body());
            $this->assertSame(EVC_Prepared_Redemption_Outcome::CATALOGUE_NOT_AUTHORISED, $outcome->kind());
        }
        $this->assertNothingWritten();
    }

    /** @return array<string,array{callable}> */
    public static function unauthorised_approved_catalogues(): array {
        return array(
            'status says approved, no approval record' => array(function (array $data, EVC_Fake_Prep_Catalogue_Authority $a) {
            }),
            'approval for another version' => array(function (array $data, EVC_Fake_Prep_Catalogue_Authority $a) {
                $a->approvals[2] = EVC_Prep_Catalogue_Digest::of($data);
            }),
            'document edited after approval' => array(function (array $data, EVC_Fake_Prep_Catalogue_Authority $a) {
                $a->approvals[1] = EVC_Prep_Catalogue_Digest::of($data);
                $a->data['coffees'][0]['label'] = 'Espresso Lungo';
            }),
            'option added after approval' => array(function (array $data, EVC_Fake_Prep_Catalogue_Authority $a) {
                $a->approvals[1] = EVC_Prep_Catalogue_Digest::of($data);
                $a->data['coffees'][0]['groups'][0]['options'][] = array('id' => 'triple', 'label' => 'Τριπλός');
            }),
            'approval digest of the illustrative document' => array(function (array $data, EVC_Fake_Prep_Catalogue_Authority $a) {
                $illustrative = $data;
                $illustrative['status'] = 'illustrative';
                $a->approvals[1] = EVC_Prep_Catalogue_Digest::of($illustrative);
            }),
        );
    }

    /** @dataProvider unauthorised_approved_catalogues */
    public function test_a_catalogue_claiming_approved_is_not_enough_without_a_matching_owner_approval(callable $setup): void {
        $data = self::catalogue_data();
        $data['status'] = 'approved';
        $this->authority->data = $data;
        $setup($data, $this->authority);
        foreach (array(null, EVC_Prepared_Redemption_Service::ALLOW_ILLUSTRATIVE_CATALOGUE_FOR_TESTS) as $opt_in) {
            $outcome = $this->service($opt_in)->redeem(self::MEMBER_A, 'free_coffee', self::actor(), self::body());
            $this->assertSame(EVC_Prepared_Redemption_Outcome::CATALOGUE_NOT_AUTHORISED, $outcome->kind());
        }
        $this->assertNothingWritten();
    }

    public function test_an_approved_catalogue_with_a_matching_pinned_digest_is_authorised_without_opt_in(): void {
        $data = self::catalogue_data();
        $data['status'] = 'approved';
        $this->authority->data = $data;
        $this->authority->approvals[1] = EVC_Prep_Catalogue_Digest::of($data);
        $outcome = $this->service(null)->redeem(self::MEMBER_A, 'free_coffee', self::actor(), self::body());
        $this->assertSame(EVC_Prepared_Redemption_Outcome::REDEEMED, $outcome->kind());
    }

    public function test_the_catalogue_digest_ignores_key_order_but_not_list_order_or_content(): void {
        $data = self::catalogue_data();
        $reordered = array_reverse($data, true);
        $this->assertSame(EVC_Prep_Catalogue_Digest::of($data), EVC_Prep_Catalogue_Digest::of($reordered));
        $swapped = $data;
        $swapped['coffees'] = array_reverse($swapped['coffees']);
        $this->assertNotSame(EVC_Prep_Catalogue_Digest::of($data), EVC_Prep_Catalogue_Digest::of($swapped));
        $relabelled = $data;
        $relabelled['quick_notes'][0]['label'] = 'για έξω';
        $this->assertNotSame(EVC_Prep_Catalogue_Digest::of($data), EVC_Prep_Catalogue_Digest::of($relabelled));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', EVC_Prep_Catalogue_Digest::of($data));
    }

    public function test_an_invalid_active_catalogue_is_never_authorised(): void {
        $data = self::catalogue_data();
        $data['free_text_note'] = true;
        $this->authority->data = $data;
        $this->assertSame(EVC_Prepared_Redemption_Outcome::CATALOGUE_NOT_AUTHORISED, $this->redeem(self::body())->kind());
        $this->assertSame('catalogue_invalid', $this->reports[0]['stage']);
        $this->assertNothingWritten();
    }

    public function test_a_new_intent_against_an_old_catalogue_version_is_stale_and_writes_nothing(): void {
        $this->authority->data = self::catalogue_v2();
        $outcome = $this->redeem(self::body());
        $this->assertSame(EVC_Prepared_Redemption_Outcome::CATALOGUE_VERSION_STALE, $outcome->kind());
        $this->assertSame(array('active_version' => 2), $outcome->details());
        $this->assertNothingWritten();
        // A retired option is refused under the new version.
        $this->assertSame(array('reason' => 'unknown_option'), $this->redeem(self::body(array('request_id' => self::R2, 'catalogue_version' => 2)))->details());
    }

    // ---- 9. versioned fingerprint and immutable semantics -----------------------------------

    public function test_the_fingerprint_is_versioned_and_never_equal_to_a_v1_fingerprint(): void {
        $this->redeem(self::body());
        $row = $this->store->ledger_rows()[0];
        $this->assertSame(EVC_Prep_Canonical::FINGERPRINT_VERSION, $row['fingerprint_version']);
        $this->assertNotSame(EVC_Request_Fingerprint::compute(self::MEMBER_A, 'free_coffee', 'espresso', self::STAFF), $row['request_fingerprint']);
        $this->assertSame(EVC_Prep_Canonical::fingerprint_v2(self::MEMBER_A, 'free_coffee', self::STAFF, $row['prep_hash']), $row['request_fingerprint']);
    }

    // ---- failure handling ---------------------------------------------------------------------

    public function test_an_unavailable_store_fails_closed_with_a_safe_retry(): void {
        $this->store->unavailable = true;
        $outcome = $this->redeem(self::body());
        $this->assertSame(EVC_Prepared_Redemption_Outcome::SERVER_ERROR, $outcome->kind());
        $this->assertSame(self::R1, $outcome->retry_request_id());
        $this->assertSame(array('stage' => 'redeem', 'exception' => 'EVC_Db_Exception', 'sql_state' => 'HY000', 'driver_code' => 2002), $this->reports[0]);
    }

    public function test_a_failing_rejection_audit_never_changes_the_outcome(): void {
        $this->redeem(self::body());
        $this->store->rejections_fail = true;
        $this->assertSame(EVC_Prepared_Redemption_Outcome::ALREADY_REDEEMED, $this->redeem(self::cappuccino(self::R2))->kind());
        $this->assertSame('audit_rejection', $this->reports[0]['stage']);
    }

    public function test_a_throwing_reporter_does_not_change_the_outcome(): void {
        $this->store->unavailable = true;
        $service = new EVC_Prepared_Redemption_Service($this->store, $this->authority, $this->membership, $this->clock, EVC_Prepared_Redemption_Service::ALLOW_ILLUSTRATIVE_CATALOGUE_FOR_TESTS, function () {
            throw new RuntimeException('reporter down');
        });
        $this->assertSame(EVC_Prepared_Redemption_Outcome::SERVER_ERROR, $service->redeem(self::MEMBER_A, 'free_coffee', self::actor(), self::body())->kind());
    }

    // ---- agreement with the UI-03C precheck ----------------------------------------------------

    /** @return array<string,array{array}> */
    public static function shared_vectors(): array {
        $out = array();
        foreach (self::vectors()['cases'] as $case) {
            $out[$case['name']] = array($case);
        }
        return $out;
    }

    /** @dataProvider shared_vectors */
    public function test_new_requests_are_decided_exactly_like_the_ui03c_precheck(array $case): void {
        $body = self::body(array('coffee_code' => $case['coffee_code'], 'preparation' => $case['preparation']));
        $precheck = new EVC_Prepared_Redeem_Precheck(
            EVC_Prep_Catalogue::from_array(self::catalogue_data()),
            new class() implements EVC_Prep_Request_Lookup {
                public function fingerprint_for_request(string $request_id): ?string {
                    return null;
                }
            },
            EVC_Prepared_Redeem_Precheck::ALLOW_ILLUSTRATIVE_CATALOGUE_FOR_TESTS
        );
        $decision = $precheck->decide(self::MEMBER_A, 'free_coffee', self::STAFF, $body);
        $outcome = $this->redeem($body);

        if ($decision->kind() === EVC_Prep_Decision::PROCEED) {
            $this->assertSame(EVC_Prepared_Redemption_Outcome::REDEEMED, $outcome->kind());
            $this->assertSame($decision->fingerprint(), $this->store->ledger_rows()[0]['request_fingerprint']);
            $this->assertSame($decision->order()->prep_hash(), $outcome->redemption()['preparation']['prep_hash']);
        } else {
            $this->assertSame($decision->kind(), $outcome->kind());
            $this->assertSame($decision->reason() === null ? array() : array('reason' => $decision->reason()), $outcome->details());
            $this->assertNothingWritten();
        }
    }
}
