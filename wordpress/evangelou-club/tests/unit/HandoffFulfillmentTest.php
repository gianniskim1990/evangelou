<?php
use PHPUnit\Framework\TestCase;

/**
 * UI-03D.C — safe fulfillment contract: a recorded redemption is not a
 * physical handoff, a replay never instructs preparation, and a handoff
 * confirmation is a separate atomic statement that never touches the
 * entitlement. In-memory store only (failure injection, interleavings,
 * tampering); no database.
 */
final class HandoffFulfillmentTest extends TestCase {
    const MEMBER_A = 'mem_0123456789abcdef0123456789abcdef';
    const MEMBER_B = 'mem_fedcba9876543210fedcba9876543210';
    const R1 = '3fa85f64-5717-4562-b3fc-2c963f66afa6';
    const R2 = '6f9619ff-8b86-4011-b42d-00c04fc964ff';
    const H1 = '7c9e6679-7425-40de-944b-e07fc1f90ae7';
    const H2 = 'a3bb189e-8bf9-4888-9912-ace4e6543002';
    const H3 = 'c56a4180-65aa-42ec-a945-5fd21dec0538';
    /** One shared WordPress staff account (D2) used by every tablet. */
    const SHARED_STAFF = 7;
    const MANAGER = 20;
    const OUTSIDER = 30;
    const TILL_1_SESSION = '11111111111111111111111111111111';
    const TILL_2_SESSION = '22222222222222222222222222222222';
    const DEVICE_1 = 'dddddddddddddddddddddddddddddd01';
    const DEVICE_2 = 'dddddddddddddddddddddddddddddd02';
    /** 10:30 Athens (EEST). */
    const MORNING = '2026-10-14T07:30:00Z';

    /** @var EVC_In_Memory_Prepared_Redemption_Store */
    private $store;
    /** @var EVC_Fake_Prep_Catalogue_Authority */
    private $authority;
    /** @var EVC_Mock_Membership_Adapter */
    private $membership;
    /** @var EVC_Fixed_Clock */
    private $clock;
    /** @var EVC_Fake_Club_Staff_Authorizer */
    private $authorizer;
    /** @var EVC_Late_Handoff_Policy */
    private $late;
    /** @var array[] */
    private $reports = array();

    protected function setUp(): void {
        $this->store = new EVC_In_Memory_Prepared_Redemption_Store();
        $this->store->add_member(self::MEMBER_A, 501);
        $this->store->add_member(self::MEMBER_B, 502);
        $this->authority = new EVC_Fake_Prep_Catalogue_Authority(json_decode((string) file_get_contents(dirname(__DIR__) . '/fixtures/preparation/catalogue-illustrative.json'), true, 512, JSON_THROW_ON_ERROR));
        $this->membership = new EVC_Mock_Membership_Adapter();
        $this->membership->set(501, EVC_Mock_Membership_Adapter::active_until('2026-12-31T00:00:00Z'));
        $this->membership->set(502, EVC_Mock_Membership_Adapter::active_until('2026-12-31T00:00:00Z'));
        $this->clock = new EVC_Fixed_Clock(self::MORNING);
        $this->authorizer = new EVC_Fake_Club_Staff_Authorizer();
        $this->authorizer->grants[self::SHARED_STAFF] = array(EVC_Club_Staff_Authorizer::CONFIRM_HANDOFF, EVC_Club_Staff_Authorizer::VIEW_REDEMPTION_STATUS);
        $this->authorizer->grants[self::MANAGER] = array(EVC_Club_Staff_Authorizer::VIEW_REDEMPTION_STATUS, EVC_Club_Staff_Authorizer::REVIEW_FULFILLMENT);
        $this->late = new EVC_No_Late_Handoff_Policy();
        $this->reports = array();
    }

    // ---- helpers ---------------------------------------------------------------------------

    private static function till(int $n = 1): EVC_Staff_Actor {
        return new EVC_Staff_Actor(self::SHARED_STAFF, $n === 1 ? self::TILL_1_SESSION : self::TILL_2_SESSION);
    }

    private static function order(string $request_id = self::R1): array {
        return array(
            'request_id' => $request_id,
            'catalogue_version' => 1,
            'coffee_code' => 'espresso',
            'preparation' => array(
                'modifiers' => array(array('group' => 'shots', 'option' => 'double'), array('group' => 'sweetness', 'option' => 'none')),
                'quick_notes' => array('takeaway'),
            ),
        );
    }

    private function redeem(string $request_id = self::R1, string $member = self::MEMBER_A, int $till = 1): EVC_Prepared_Redemption_Outcome {
        $service = new EVC_Prepared_Redemption_Service($this->store, $this->authority, $this->membership, $this->clock, EVC_Prepared_Redemption_Service::ALLOW_ILLUSTRATIVE_CATALOGUE_FOR_TESTS);
        return $service->redeem($member, 'free_coffee', self::till($till), self::order($request_id));
    }

    private function handoffs(): EVC_Handoff_Service {
        $reports = &$this->reports;
        return new EVC_Handoff_Service($this->store, $this->authorizer, $this->late, $this->clock, function (array $d) use (&$reports) {
            $reports[] = $d;
        });
    }

    private static function confirmation(array $overrides = array()): array {
        return array_merge(array('handoff_request_id' => self::H1, 'redemption_request_id' => self::R1, 'claim' => 'handed_now'), $overrides);
    }

    private function confirm(array $overrides = array(), string $member = self::MEMBER_A, ?EVC_Staff_Actor $actor = null): EVC_Handoff_Outcome {
        return $this->handoffs()->confirm($member, $actor !== null ? $actor : self::till(), self::confirmation($overrides));
    }

    private function reader(): EVC_Fulfillment_Read_Model {
        return new EVC_Fulfillment_Read_Model($this->store, $this->authorizer, $this->clock);
    }

    private function assertNoHandoff(): void {
        $this->assertSame(array(), $this->store->handoff_rows());
        foreach ($this->store->audit_events() as $event) {
            $this->assertNotSame('handoff', $event['event_type']);
        }
    }

    private function assertOneHandoff(): void {
        $this->assertCount(1, $this->store->handoff_rows());
        $this->assertSame(array(), $this->store->violations());
    }

    // ---- 1-3. recording vs fulfillment vs instruction ---------------------------------------

    public function test_a_fresh_commit_is_the_only_result_that_instructs_preparation(): void {
        $fresh = $this->redeem();
        $this->assertSame(EVC_Prepared_Redemption_Outcome::PREPARE, $fresh->instruction());
        $this->assertSame(EVC_Prepared_Redemption_Outcome::RECORDED_NOW, $fresh->recording());
        $this->assertSame(EVC_Prepared_Redemption_Outcome::FULFILLMENT_UNCONFIRMED, $fresh->fulfillment());
        $this->assertTrue($fresh->entitlement_consumed());
        $this->assertNull($fresh->handoff());

        foreach (array(1, 2, 3) as $retry) {
            $replay = $this->redeem(self::R1, self::MEMBER_A, $retry % 2 + 1);
            $this->assertSame(EVC_Prepared_Redemption_Outcome::REPLAYED, $replay->kind());
            $this->assertSame(EVC_Prepared_Redemption_Outcome::PREVIOUSLY_RECORDED, $replay->recording());
            $this->assertSame(EVC_Prepared_Redemption_Outcome::VERIFY_HANDOFF, $replay->instruction());
            $this->assertTrue($replay->entitlement_consumed());
        }
        $this->assertCount(1, $this->store->ledger_rows());
    }

    /** @return array<string,array{?string,string,string,string}> */
    public static function replay_states(): array {
        return array(
            'today, nothing confirmed' => array(null, self::MORNING, EVC_Prepared_Redemption_Outcome::VERIFY_HANDOFF, EVC_Prepared_Redemption_Outcome::FULFILLMENT_UNCONFIRMED),
            'today, handed now' => array('handed_now', self::MORNING, EVC_Prepared_Redemption_Outcome::DO_NOT_PREPARE, EVC_Prepared_Redemption_Outcome::FULFILLMENT_CONFIRMED),
            'today, handed earlier' => array('already_handed_earlier', self::MORNING, EVC_Prepared_Redemption_Outcome::DO_NOT_PREPARE, EVC_Prepared_Redemption_Outcome::FULFILLMENT_CONFIRMED),
            'next Athens day, nothing confirmed' => array(null, '2026-10-14T21:00:00Z', EVC_Prepared_Redemption_Outcome::DO_NOT_PREPARE_FOR_TODAY, EVC_Prepared_Redemption_Outcome::FULFILLMENT_UNCONFIRMED),
            'next Athens day, confirmed' => array('handed_now', '2026-10-15T07:00:00Z', EVC_Prepared_Redemption_Outcome::DO_NOT_PREPARE_FOR_TODAY, EVC_Prepared_Redemption_Outcome::FULFILLMENT_CONFIRMED),
            'weeks later' => array(null, '2026-11-20T09:00:00Z', EVC_Prepared_Redemption_Outcome::DO_NOT_PREPARE_FOR_TODAY, EVC_Prepared_Redemption_Outcome::FULFILLMENT_UNCONFIRMED),
        );
    }

    /** @dataProvider replay_states */
    public function test_a_replay_never_instructs_preparation(?string $claim, string $retry_at, string $instruction, string $fulfillment): void {
        $this->redeem();
        if ($claim !== null) {
            $this->assertSame(EVC_Handoff_Outcome::CONFIRMED, $this->confirm(array('claim' => $claim))->kind());
        }
        $this->clock->set($retry_at);
        $replay = $this->redeem(self::R1, self::MEMBER_A, 2);

        $this->assertSame(EVC_Prepared_Redemption_Outcome::REPLAYED, $replay->kind());
        $this->assertSame($instruction, $replay->instruction());
        $this->assertNotSame(EVC_Prepared_Redemption_Outcome::PREPARE, $replay->instruction());
        $this->assertSame($fulfillment, $replay->fulfillment());
        $this->assertSame($claim, $replay->handoff() === null ? null : $replay->handoff()['claim']);
        $this->assertSame('2026-10-14', $replay->redemption()['business_date']);
        $this->assertCount(1, $this->store->ledger_rows());
    }

    public function test_a_duplicate_submit_from_a_second_till_is_a_replay_that_does_not_instruct_preparation(): void {
        // Till 2 commits the same request id between till 1's lookup and commit.
        $second = null;
        $this->store->after_request_lookup = function () use (&$second) {
            $second = $this->redeem(self::R1, self::MEMBER_A, 2);
        };
        $first = $this->redeem(self::R1, self::MEMBER_A, 1);
        $this->assertSame(EVC_Prepared_Redemption_Outcome::PREPARE, $second->instruction());
        $this->assertSame(EVC_Prepared_Redemption_Outcome::REPLAYED, $first->kind());
        $this->assertSame(EVC_Prepared_Redemption_Outcome::VERIFY_HANDOFF, $first->instruction());
        $this->assertCount(1, $this->store->ledger_rows());
    }

    public function test_unconfirmed_is_never_treated_as_proof_that_no_coffee_was_handed_over(): void {
        $this->redeem();
        $status = $this->reader()->redemption_status(self::till(2), self::MEMBER_A, self::R1)->data();
        $this->assertSame(EVC_Prepared_Redemption_Outcome::FULFILLMENT_UNCONFIRMED, $status['fulfillment']);
        $this->assertNull($status['handoff']);
        // Neither a replay nor a new intent can turn "unconfirmed" into a new coffee.
        $this->assertSame(EVC_Prepared_Redemption_Outcome::VERIFY_HANDOFF, $this->redeem(self::R1, self::MEMBER_A, 2)->instruction());
        $again = $this->redeem(self::R2, self::MEMBER_A, 2);
        $this->assertSame(EVC_Prepared_Redemption_Outcome::ALREADY_REDEEMED, $again->kind());
        $this->assertSame(EVC_Prepared_Redemption_Outcome::DO_NOT_PREPARE, $again->instruction());
        $this->assertFalse($again->entitlement_consumed());
        $this->assertCount(1, $this->store->ledger_rows());
    }

    // ---- 4-6. one atomic confirmation, never touching the entitlement -----------------------

    public function test_a_confirmation_records_one_handoff_and_one_audit_event_and_nothing_else(): void {
        $this->redeem();
        $before = $this->store->redemption_state();
        $outcome = $this->confirm(array('device_ref' => self::DEVICE_1));

        $this->assertSame(EVC_Handoff_Outcome::CONFIRMED, $outcome->kind());
        $this->assertSame(EVC_Prepared_Redemption_Outcome::FULFILLMENT_CONFIRMED, $outcome->fulfillment());
        $this->assertSame($before, $this->store->redemption_state(), 'ledger, snapshots and unique keys untouched');
        $this->assertOneHandoff();
        $audit = array_values(array_filter($this->store->audit_events(), function (array $e): bool {
            return $e['event_type'] === 'handoff';
        }));
        $this->assertCount(1, $audit);
        $this->assertSame(self::H1, $audit[0]['request_id']);
        $this->assertSame(self::DEVICE_1, $audit[0]['details']['device_ref']);
        $this->assertSame(self::TILL_1_SESSION, $audit[0]['details']['session_ref']);
        $record = $this->store->handoff_rows()[0];
        $this->assertArrayNotHasKey('device_ref', $record, 'a device reference is audit-only, never part of the record');
        $this->assertSame(array(
            'handoff_request_id' => self::H1,
            'redemption_request_id' => self::R1,
            'claim' => 'handed_now',
            'confirmation_recorded_at_utc' => '2026-10-14 07:30:00.000000',
            'handoff_at_utc' => '2026-10-14 07:30:00.000000',
            'handoff_time' => 'stated_at_recording',
        ), $outcome->handoff());
    }

    public function test_a_late_statement_never_invents_the_real_handoff_time(): void {
        $this->redeem();
        $this->clock->set('2026-10-14T08:45:00Z');
        $outcome = $this->confirm(array('claim' => 'already_handed_earlier'));
        $view = $outcome->handoff();
        $this->assertNull($view['handoff_at_utc']);
        $this->assertSame('not_recorded', $view['handoff_time']);
        $this->assertSame('2026-10-14 08:45:00.000000', $view['confirmation_recorded_at_utc'], 'when the STATEMENT was recorded');
        $this->assertNull($this->store->handoff_rows()[0]['handoff_at_utc']);
        $this->assertNull($this->redeem(self::R1, self::MEMBER_A, 2)->handoff()['handoff_at_utc']);
        $this->assertNull($this->reader()->redemption_status(self::till(), self::MEMBER_A, self::R1)->data()['handoff']['handoff_at_utc']);
    }

    /** @return array<string,array{string}> */
    public static function handoff_failure_points(): array {
        $out = array();
        foreach (array('before_handoff', 'after_handoff', 'after_handoff_audit', 'handoff_commit') as $p) {
            $out[$p] = array($p);
        }
        return $out;
    }

    /** @dataProvider handoff_failure_points */
    public function test_a_failed_confirmation_writes_nothing_and_never_alters_the_entitlement(string $point): void {
        $this->redeem();
        $before = $this->store->redemption_state();
        $this->store->failures = array(array($point, new EVC_Db_Exception('Club database query failed.', 'HY000', 2006)));
        $failed = $this->confirm();

        $this->assertSame(EVC_Handoff_Outcome::SERVER_ERROR, $failed->kind());
        $this->assertNull($failed->fulfillment());
        $this->assertSame(self::H1, $failed->retry_handoff_request_id());
        $this->assertNoHandoff();
        $this->assertSame($before, $this->store->redemption_state());
        $this->assertContains($point, $this->store->points_reached);

        $this->assertSame(EVC_Handoff_Outcome::CONFIRMED, $this->confirm()->kind());
        $this->assertOneHandoff();
        $this->assertSame($before, $this->store->redemption_state());
    }

    public function test_deadlocks_retry_the_whole_confirmation_and_exhaustion_writes_nothing(): void {
        $this->redeem();
        $deadlock = new EVC_Db_Retryable_Exception('Club database deadlock.', '40001', 1213);
        $this->store->failures = array(array('after_handoff', $deadlock), array('after_handoff_audit', $deadlock));
        $this->assertSame(EVC_Handoff_Outcome::CONFIRMED, $this->confirm()->kind());
        $this->assertSame(3, $this->store->handoff_commit_attempts);
        $this->assertOneHandoff();

        $this->setUp();
        $this->redeem();
        $this->store->failures = array(array('after_handoff', $deadlock), array('after_handoff', $deadlock), array('after_handoff', $deadlock));
        $outcome = $this->confirm();
        $this->assertSame(EVC_Handoff_Outcome::SERVER_ERROR, $outcome->kind());
        $this->assertSame(array('reason' => 'transaction_retry_exhausted'), $outcome->details());
        $this->assertNoHandoff();
    }

    public function test_a_handoff_write_whose_records_disagree_cannot_be_built(): void {
        $this->redeem();
        $this->confirm();
        $record = $this->store->handoff_rows()[0];
        unset($record['id']);
        $audit = null;
        foreach ($this->store->audit_events() as $event) {
            if ($event['event_type'] === 'handoff') {
                $audit = $event;
            }
        }
        new EVC_Handoff_Write($record, $audit);
        $cases = array(
            array('handoff_at_utc', '2026-10-14 07:00:00.000000', 'record'),
            array('claim', 'maybe', 'record'),
            array('note', 'x', 'record'),
            array('member_id', 99, 'audit'),
            array('redemption_id', 99, 'audit'),
            array('request_id', self::H2, 'audit'),
            array('occurred_at_utc', '2026-10-14 07:30:01.000000', 'audit'),
            array('outcome', 'handoff_replayed', 'audit'),
        );
        foreach ($cases as $c) {
            $r = $record;
            $a = $audit;
            if ($c[2] === 'record') {
                $r[$c[0]] = $c[1];
            } else {
                $a[$c[0]] = $c[1];
            }
            try {
                new EVC_Handoff_Write($r, $a);
                $this->fail('accepted ' . $c[0]);
            } catch (InvalidArgumentException $e) {
                $this->assertStringStartsWith('Inconsistent handoff write', $e->getMessage());
            }
        }
    }

    // ---- 4. never confirmed twice; idempotency ----------------------------------------------

    public function test_an_exact_retry_replays_even_after_midnight_a_new_login_or_from_another_tablet(): void {
        $this->redeem();
        $first = $this->confirm(array('device_ref' => self::DEVICE_1));
        $this->clock->set('2026-10-15T09:00:00Z'); // next Athens day; no late policy needed for a replay
        $retry = $this->confirm(array('device_ref' => self::DEVICE_2), self::MEMBER_A, self::till(2));
        $this->assertSame(EVC_Handoff_Outcome::REPLAYED, $retry->kind());
        $this->assertSame($first->handoff(), $retry->handoff());
        $this->assertOneHandoff();
    }

    /** @return array<string,array{array,string,int}> */
    public static function second_confirmations(): array {
        return array(
            'other id, same claim' => array(array('handoff_request_id' => self::H2), self::MEMBER_A, self::SHARED_STAFF),
            'other id, other claim' => array(array('handoff_request_id' => self::H2, 'claim' => 'already_handed_earlier'), self::MEMBER_A, self::SHARED_STAFF),
        );
    }

    /** @dataProvider second_confirmations */
    public function test_a_redemption_is_never_confirmed_twice(array $override, string $member, int $staff): void {
        $this->redeem();
        $first = $this->confirm();
        $second = $this->confirm($override, $member, new EVC_Staff_Actor($staff, self::TILL_2_SESSION));
        $this->assertSame(EVC_Handoff_Outcome::ALREADY_CONFIRMED, $second->kind());
        $this->assertSame($first->handoff(), $second->handoff(), 'the ORIGINAL statement is reported');
        $this->assertOneHandoff();
        $this->assertSame(EVC_Handoff_Outcome::ALREADY_CONFIRMED, end($this->store->rejections)['outcome']);
    }

    /** @return array<string,array{array,string,?int}> */
    public static function changed_payloads(): array {
        return array(
            'other claim' => array(array('claim' => 'already_handed_earlier'), self::MEMBER_A, null),
            'other redemption' => array(array('redemption_request_id' => self::R2), self::MEMBER_A, null),
            'other member' => array(array(), self::MEMBER_B, null),
            'other staff account' => array(array(), self::MEMBER_A, 8),
        );
    }

    /** @dataProvider changed_payloads */
    public function test_reusing_a_handoff_request_id_with_a_changed_payload_is_a_conflict_that_discloses_nothing(array $override, string $member, ?int $staff): void {
        $this->redeem();
        $this->confirm();
        if ($staff !== null) {
            $this->authorizer->grants[$staff] = array(EVC_Club_Staff_Authorizer::CONFIRM_HANDOFF);
        }
        $outcome = $this->confirm($override, $member, $staff === null ? self::till(2) : new EVC_Staff_Actor($staff));
        $this->assertSame(EVC_Handoff_Outcome::IDEMPOTENCY_CONFLICT, $outcome->kind());
        $this->assertNull($outcome->handoff());
        $this->assertSame(array(), $outcome->details());
        $this->assertOneHandoff();
    }

    // ---- 8. unknown outcomes ------------------------------------------------------------------

    public function test_an_unknown_handoff_commit_is_never_success_and_is_recovered_with_the_same_id(): void {
        $this->redeem();
        $this->store->handoff_commit_unknown = 'committed';
        $unknown = $this->confirm();
        $this->assertSame(EVC_Handoff_Outcome::OUTCOME_UNKNOWN, $unknown->kind());
        $this->assertNull($unknown->fulfillment());
        $this->assertNull($unknown->handoff());
        $this->assertSame(self::H1, $unknown->retry_handoff_request_id());
        $this->assertSame(1, $this->store->handoff_commit_attempts, 'never retried blindly');
        $this->assertSame('commit_unknown', $this->reports[0]['stage']);

        // Device lost: the other tablet reads the authoritative server state...
        $status = $this->reader()->redemption_status(self::till(2), self::MEMBER_A, self::R1)->data();
        $this->assertSame(EVC_Prepared_Redemption_Outcome::FULFILLMENT_CONFIRMED, $status['fulfillment']);
        // ...and a new handoff id never creates a second record or a second redemption.
        $this->assertSame(EVC_Handoff_Outcome::ALREADY_CONFIRMED, $this->confirm(array('handoff_request_id' => self::H2), self::MEMBER_A, self::till(2))->kind());
        $this->assertSame(EVC_Handoff_Outcome::REPLAYED, $this->confirm(array('handoff_request_id' => $unknown->retry_handoff_request_id()))->kind());
        $this->assertOneHandoff();
        $this->assertCount(1, $this->store->ledger_rows());
    }

    public function test_an_unknown_handoff_commit_that_did_not_commit_leaves_the_redemption_unconfirmed(): void {
        $this->redeem();
        $this->store->handoff_commit_unknown = 'not_committed';
        $this->assertSame(EVC_Handoff_Outcome::OUTCOME_UNKNOWN, $this->confirm()->kind());
        $this->assertNoHandoff();
        $this->assertSame(EVC_Prepared_Redemption_Outcome::VERIFY_HANDOFF, $this->redeem(self::R1, self::MEMBER_A, 2)->instruction());
        $this->assertSame(EVC_Handoff_Outcome::CONFIRMED, $this->confirm()->kind());
        $this->assertOneHandoff();
    }

    public function test_unresolved_handoff_outcomes_can_never_be_built_as_recorded(): void {
        foreach (array(EVC_Handoff_Outcome::OUTCOME_UNKNOWN, EVC_Handoff_Outcome::SERVER_ERROR) as $kind) {
            try {
                EVC_Handoff_Outcome::recorded($kind, array());
                $this->fail($kind . ' built as recorded');
            } catch (InvalidArgumentException $e) {
                $this->assertSame('Not a recorded handoff outcome.', $e->getMessage());
            }
            try {
                EVC_Handoff_Outcome::failure($kind);
                $this->fail($kind . ' built as definitive');
            } catch (InvalidArgumentException $e) {
                $this->assertSame('Not a definitive handoff failure.', $e->getMessage());
            }
            $this->assertNull(EVC_Handoff_Outcome::unresolved($kind, self::H1)->fulfillment());
        }
    }

    public function test_an_unavailable_store_fails_closed_without_messages(): void {
        $this->redeem();
        $this->store->unavailable = true;
        $outcome = $this->confirm();
        $this->assertSame(EVC_Handoff_Outcome::SERVER_ERROR, $outcome->kind());
        $this->assertSame(self::H1, $outcome->retry_handoff_request_id());
        $this->assertSame(array('stage' => 'confirm', 'exception' => 'EVC_Db_Exception', 'sql_state' => 'HY000', 'driver_code' => 2002), $this->reports[0]);
        $this->assertSame(EVC_Fulfillment_Read_Result::SERVER_ERROR, $this->reader()->redemption_status(self::till(), self::MEMBER_A, self::R1)->kind());
    }

    // ---- 7. Europe/Athens dates and the (unapproved) late policy ----------------------------

    public function test_a_late_confirmation_is_refused_without_an_explicit_policy(): void {
        $this->clock->set('2026-10-14T20:59:59Z'); // 23:59:59 Athens
        $this->redeem();
        $this->clock->set('2026-10-14T20:59:59.999999Z');
        $this->assertSame(EVC_Handoff_Outcome::CONFIRMED, $this->confirm()->kind(), 'same Athens day');

        $this->setUp();
        $this->clock->set('2026-10-14T20:59:59Z');
        $this->redeem();
        $this->clock->set('2026-10-14T21:00:00Z'); // 00:00 Athens, still the 14th in UTC
        $late = $this->confirm();
        $this->assertSame(EVC_Handoff_Outcome::LATE_CONFIRMATION_NOT_AUTHORISED, $late->kind());
        $this->assertSame(array('business_date' => '2026-10-14'), $late->details());
        $this->assertNoHandoff();
        $this->assertFalse((new EVC_No_Late_Handoff_Policy())->allows_late_confirmation('2026-10-14', new DateTimeImmutable('2026-10-14T21:00:01Z')));
    }

    public function test_an_injected_late_policy_is_consulted_only_for_an_earlier_athens_day(): void {
        $policy = new EVC_Test_Window_Late_Handoff_Policy('+2 hours');
        $this->late = $policy;
        $this->redeem();
        $this->confirm(array('handoff_request_id' => self::H3, 'redemption_request_id' => self::R2)); // unknown redemption
        $this->assertSame(array(), $policy->calls, 'not consulted before the redemption is found');

        $this->clock->set('2026-10-14T22:59:59Z'); // 01:59:59 Athens on the 15th: inside the test window
        $this->assertSame(EVC_Handoff_Outcome::CONFIRMED, $this->confirm()->kind());
        $this->assertSame(array(array('2026-10-14', '2026-10-14 22:59:59.000000')), $policy->calls);

        $this->setUp();
        $this->late = new EVC_Test_Window_Late_Handoff_Policy('+2 hours');
        $this->redeem();
        $this->clock->set('2026-10-14T23:00:00Z'); // 02:00 Athens: outside
        $this->assertSame(EVC_Handoff_Outcome::LATE_CONFIRMATION_NOT_AUTHORISED, $this->confirm()->kind());
    }

    public function test_an_earlier_day_redemption_never_becomes_todays_coffee(): void {
        $this->redeem();
        $this->clock->set('2026-10-15T07:30:00Z');
        $replay = $this->redeem(self::R1);
        $this->assertSame(EVC_Prepared_Redemption_Outcome::DO_NOT_PREPARE_FOR_TODAY, $replay->instruction());
        $this->assertSame('2026-10-14', $replay->redemption()['business_date']);
        $this->assertFalse($this->reader()->redemption_status(self::till(), self::MEMBER_A, self::R1)->data()['is_current_business_date']);
        // Today's coffee needs a NEW intent, decided on its own.
        $today = $this->redeem(self::R2);
        $this->assertSame(EVC_Prepared_Redemption_Outcome::PREPARE, $today->instruction());
        $this->assertSame('2026-10-15', $today->redemption()['business_date']);
    }

    // ---- 9. one shared staff account on several tablets ----------------------------------------

    /** @return array<string,array{string,string,string}> */
    public static function shared_account_races(): array {
        $out = array();
        foreach (array('serial', 'after_handoff_lookup', 'before_handoff_commit') as $schedule) {
            $out['same handoff id / ' . $schedule] = array($schedule, self::H1, EVC_Handoff_Outcome::REPLAYED);
            $out['other handoff id / ' . $schedule] = array($schedule, self::H2, EVC_Handoff_Outcome::ALREADY_CONFIRMED);
        }
        return $out;
    }

    /** @dataProvider shared_account_races */
    public function test_two_tablets_on_the_shared_account_record_one_handoff(string $schedule, string $second_id, string $second_kind): void {
        $this->redeem();
        $tablet_2 = null;
        $run_2 = function () use (&$tablet_2, $second_id) {
            $tablet_2 = $this->confirm(array('handoff_request_id' => $second_id, 'device_ref' => self::DEVICE_2), self::MEMBER_A, self::till(2));
        };
        if ($schedule === 'serial') {
            $first = $this->confirm(array('device_ref' => self::DEVICE_1));
            $run_2();
            $second = $tablet_2;
        } else {
            if ($schedule === 'after_handoff_lookup') {
                $this->store->after_handoff_lookup = $run_2;
            } else {
                $this->store->before_handoff_commit = $run_2;
            }
            $second = $this->confirm(array('device_ref' => self::DEVICE_1));
            $first = $tablet_2;
        }
        $this->assertSame(EVC_Handoff_Outcome::CONFIRMED, $first->kind());
        $this->assertSame($second_kind, $second->kind());
        $this->assertSame($first->handoff(), $second->handoff());
        $this->assertOneHandoff();
        $this->assertCount(1, $this->store->ledger_rows());
    }

    public function test_a_stale_status_never_authorises_a_new_redemption_or_a_second_handoff(): void {
        $this->redeem();
        $stale = $this->reader()->redemption_status(self::till(1), self::MEMBER_A, self::R1)->data();
        $this->assertSame('unconfirmed', $stale['fulfillment']);
        $this->confirm(array('handoff_request_id' => self::H2), self::MEMBER_A, self::till(2)); // tablet 2 confirms meanwhile

        $this->assertSame(EVC_Handoff_Outcome::ALREADY_CONFIRMED, $this->confirm()->kind());
        $this->assertSame(EVC_Prepared_Redemption_Outcome::ALREADY_REDEEMED, $this->redeem(self::R2)->kind());
        $this->assertOneHandoff();
        $this->assertCount(1, $this->store->ledger_rows());
    }

    public function test_a_stale_status_cannot_bypass_integrity_checks(): void {
        $this->redeem();
        $this->assertSame(EVC_Fulfillment_Read_Result::FOUND, $this->reader()->redemption_status(self::till(), self::MEMBER_A, self::R1)->kind());
        $this->store->tamper_snapshots(self::R1, array());
        $this->assertSame(EVC_Handoff_Outcome::RECORD_INTEGRITY_FAILURE, $this->confirm()->kind());
        $this->assertNoHandoff();
    }

    // ---- 10. corrupted records fail closed ------------------------------------------------------

    /** @return array<string,array{callable,string}> */
    public static function corruptions(): array {
        $handoff = function (callable $edit) {
            return function (HandoffFulfillmentTest $t, EVC_In_Memory_Prepared_Redemption_Store $s) use ($edit) {
                $row = $s->handoff_rows()[0];
                $s->tamper_handoffs(self::R1, $edit($row));
            };
        };
        return array(
            'snapshot missing' => array(function ($t, $s) {
                $s->tamper_snapshots(self::R1, array());
            }, 'snapshot_missing'),
            'ledger prep hash altered' => array(function ($t, $s) {
                $s->tamper_ledger(self::R1, array('prep_hash' => str_repeat('0', 64)));
            }, 'snapshot_conflict'),
            'handoff gained a note' => array($handoff(function (array $h) {
                $h['note'] = 'δόθηκε';
                return array($h);
            }), 'handoff_corrupt'),
            'invented time for an earlier claim' => array($handoff(function (array $h) {
                $h['claim'] = 'already_handed_earlier';
                return array($h);
            }), 'handoff_corrupt'),
            'unknown claim' => array($handoff(function (array $h) {
                $h['claim'] = 'probably';
                return array($h);
            }), 'handoff_corrupt'),
            'two handoffs' => array($handoff(function (array $h) {
                $other = $h;
                $other['handoff_request_id'] = self::H3;
                unset($other['id']);
                return array($h, $other);
            }), 'handoff_conflict'),
            'handoff moved to another member' => array($handoff(function (array $h) {
                $h['member_id'] = 2;
                return array($h);
            }), 'handoff_conflict'),
            'handoff of another redemption request' => array($handoff(function (array $h) {
                $h['redemption_request_id'] = self::R2;
                return array($h);
            }), 'handoff_conflict'),
            'claim rewritten without its fingerprint' => array($handoff(function (array $h) {
                $h['claim'] = 'already_handed_earlier';
                $h['handoff_at_utc'] = null;
                return array($h);
            }), 'handoff_conflict'),
        );
    }

    /** @dataProvider corruptions */
    public function test_a_corrupted_record_fails_closed_everywhere(callable $corrupt, string $reason): void {
        $this->redeem();
        $this->confirm();
        $corrupt($this, $this->store);
        $before = array($this->store->redemption_state(), $this->store->handoff_rows());

        $replay = $this->redeem(self::R1, self::MEMBER_A, 2);
        $this->assertSame(EVC_Prepared_Redemption_Outcome::RECORD_INTEGRITY_FAILURE, $replay->kind());
        $this->assertSame(EVC_Prepared_Redemption_Outcome::DO_NOT_PREPARE, $replay->instruction());
        $this->assertNull($replay->entitlement_consumed());
        $this->assertSame(array('reason' => $reason), $replay->details());

        $confirm = $this->confirm(array('handoff_request_id' => self::H2), self::MEMBER_A, self::till(2));
        $this->assertSame(EVC_Handoff_Outcome::RECORD_INTEGRITY_FAILURE, $confirm->kind());
        $this->assertSame(array('reason' => $reason), $confirm->details());
        $this->assertNull($confirm->fulfillment());

        $this->assertSame(EVC_Fulfillment_Read_Result::RECORD_INTEGRITY_FAILURE, $this->reader()->redemption_status(self::till(), self::MEMBER_A, self::R1)->kind());
        $this->assertNull($this->reader()->redemption_status(self::till(), self::MEMBER_A, self::R1)->data());
        $this->assertSame(1, $this->reader()->unconfirmed_for_review(new EVC_Staff_Actor(self::MANAGER), '2026-10-14')->data()['integrity_failures']);
        $this->assertSame($before, array($this->store->redemption_state(), $this->store->handoff_rows()), 'nothing written or repaired');
    }

    public function test_replaying_a_handoff_whose_record_was_damaged_fails_closed(): void {
        $this->redeem();
        $this->confirm();
        $row = $this->store->handoff_rows()[0];
        $row['staff_wp_user_id'] = 99;
        $this->store->tamper_handoffs(self::R1, array($row));
        $outcome = $this->confirm();
        $this->assertSame(EVC_Handoff_Outcome::RECORD_INTEGRITY_FAILURE, $outcome->kind(), 'the stored statement no longer matches its fingerprint');
        $this->assertSame(array('reason' => 'handoff_conflict'), $outcome->details());
        $this->assertNull($outcome->handoff());
        $this->store->tamper_handoffs(self::R1, array());
        $this->assertSame(EVC_Handoff_Outcome::CONFIRMED, $this->confirm()->kind(), 'a fresh statement after the bad row was removed');
    }

    public function test_a_legacy_v1_redemption_cannot_be_confirmed(): void {
        $this->store->insert_legacy_v1(1, '2026-10-14', self::R1, 'espresso');
        $outcome = $this->confirm();
        $this->assertSame(EVC_Handoff_Outcome::RECORD_INTEGRITY_FAILURE, $outcome->kind());
        $this->assertSame(array('reason' => 'fingerprint_version'), $outcome->details());
        $review = $this->reader()->unconfirmed_for_review(new EVC_Staff_Actor(self::MANAGER), '2026-10-14')->data();
        $this->assertSame(1, $review['legacy_without_preparation']);
        $this->assertSame(0, $review['integrity_failures']);
        $this->assertSame(array(), $review['unconfirmed']);
    }

    // ---- 11. authorisation, member binding, data minimisation ----------------------------------

    public function test_staff_without_permission_get_nothing_and_cause_no_read(): void {
        $this->redeem();
        $reads = $this->store->reads;
        $outsider = new EVC_Staff_Actor(self::OUTSIDER);
        $outcome = $this->handoffs()->confirm(self::MEMBER_A, $outsider, self::confirmation());
        $this->assertSame(EVC_Handoff_Outcome::FORBIDDEN, $outcome->kind());
        $this->assertNull($outcome->handoff());
        $this->assertSame(EVC_Fulfillment_Read_Result::FORBIDDEN, $this->reader()->redemption_status($outsider, self::MEMBER_A, self::R1)->kind());
        $this->assertSame(EVC_Fulfillment_Read_Result::FORBIDDEN, $this->reader()->unconfirmed_today($outsider)->kind());
        $this->assertSame(EVC_Fulfillment_Read_Result::FORBIDDEN, $this->reader()->unconfirmed_for_review(self::till(), '2026-10-14')->kind(), 'tills cannot review');
        $this->assertNull($this->reader()->unconfirmed_for_review(self::till(), '2026-10-14')->data());
        $this->assertSame($reads, $this->store->reads);
        $this->assertNoHandoff();
    }

    public function test_another_members_redemption_is_indistinguishable_from_an_unknown_one(): void {
        $this->redeem();
        $wrong_member = $this->confirm(array(), self::MEMBER_B);
        $unknown = $this->confirm(array('handoff_request_id' => self::H2, 'redemption_request_id' => self::R2));
        foreach (array($wrong_member, $unknown) as $outcome) {
            $this->assertSame(EVC_Handoff_Outcome::REDEMPTION_NOT_FOUND, $outcome->kind());
            $this->assertSame(array(), $outcome->details());
            $this->assertNull($outcome->handoff());
        }
        $this->assertSame(EVC_Fulfillment_Read_Result::NOT_FOUND, $this->reader()->redemption_status(self::till(), self::MEMBER_B, self::R1)->kind());
        $this->assertNull($this->reader()->redemption_status(self::till(), self::MEMBER_B, self::R1)->data());
        $this->assertNoHandoff();
    }

    /** @return array<string,array{string,mixed}> */
    public static function client_authority(): array {
        return array(
            'confirmation time' => array('recorded_at_utc', '2026-10-14 07:00:00'),
            'handoff time' => array('handoff_at_utc', '2026-10-14 07:00:00'),
            'staff actor' => array('staff_wp_user_id', 7),
            'member id' => array('member_id', 1),
            'business date' => array('business_date', '2026-10-14'),
            'entitlement' => array('entitlement_consumed', false),
            'fulfillment' => array('fulfillment', 'confirmed'),
            'free text' => array('note', 'δόθηκε στο τραπέζι'),
            'bad device ref' => array('device_ref', 'tablet-1'),
        );
    }

    /** @dataProvider client_authority */
    public function test_time_actor_member_status_or_free_text_in_the_body_are_rejected_before_any_read(string $key, $value): void {
        $this->redeem();
        $reads = $this->store->reads;
        $body = self::confirmation();
        $body[$key] = $value;
        $this->assertSame(EVC_Handoff_Outcome::INVALID_REQUEST, $this->handoffs()->confirm(self::MEMBER_A, self::till(), $body)->kind());
        $this->assertSame($reads, $this->store->reads);
        $this->assertNoHandoff();
    }

    public function test_status_views_are_minimised(): void {
        $this->redeem();
        $this->redeem(self::R2, self::MEMBER_B);
        $this->confirm(array('handoff_request_id' => self::H2, 'redemption_request_id' => self::R2), self::MEMBER_B);

        $status = $this->reader()->redemption_status(self::till(), self::MEMBER_A, self::R1)->data();
        $this->assertSame(array('redemption_request_id', 'business_date', 'redeemed_at_utc', 'is_current_business_date', 'coffee_label', 'modifiers', 'quick_notes', 'fulfillment', 'handoff'), array_keys($status));
        $this->assertSame(array('Δόση' => 'Διπλός', 'Ζάχαρη' => 'Σκέτος'), $status['modifiers']);

        $today = $this->reader()->unconfirmed_today(self::till())->data();
        $this->assertSame(array(array('redemption_request_id' => self::R1, 'redeemed_at_utc' => '2026-10-14 07:30:00.000000', 'coffee_code' => 'espresso')), $today['unconfirmed']);

        $review = $this->reader()->unconfirmed_for_review(new EVC_Staff_Actor(self::MANAGER), '2026-10-14')->data();
        $this->assertSame(self::MEMBER_A, $review['unconfirmed'][0]['member_public_id']);
        $this->assertCount(1, $review['unconfirmed']);
        $flat = json_encode(array($status, $today, $review));
        foreach (array('"id"', 'staff_wp_user_id', 'session_ref', 'device_ref', 'member_id"', 'wp_user_id', 'fingerprint') as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $flat);
        }
        foreach (array('2026-14-01', '2026-02-30', '20261014', 1) as $bad) {
            $this->assertSame(EVC_Fulfillment_Read_Result::INVALID_REQUEST, $this->reader()->unconfirmed_for_review(new EVC_Staff_Actor(self::MANAGER), $bad)->kind());
        }
    }
}
