<?php
use PHPUnit\Framework\TestCase;

/**
 * UI-03E — usual coffee derived from verified redemption history. History is
 * built through the REAL UI-03D.A redemption service into the in-memory store
 * (genuine snapshots and hashes), then read through a fake read-only source.
 */
final class UsualCoffeeTest extends TestCase {
    const MEMBER_A = 'mem_0123456789abcdef0123456789abcdef';
    const MEMBER_B = 'mem_fedcba9876543210fedcba9876543210';
    const STAFF = 7;
    const SESSION = '0123456789abcdef0123456789abcdef';
    const LOOKUP_A = 'lookup-a';
    const LOOKUP_B = 'lookup-b';
    const TODAY = '2026-10-14T09:00:00Z';

    const ESPRESSO_DOUBLE = array('espresso', array(array('group' => 'shots', 'option' => 'double'), array('group' => 'sweetness', 'option' => 'none')), array());
    const ESPRESSO_STEVIA = array('espresso', array(array('group' => 'shots', 'option' => 'single'), array('group' => 'sweetness', 'option' => 'medium'), array('group' => 'sweetener', 'option' => 'stevia')), array('very_hot'));
    const CAPPUCCINO_PLANT = array('cappuccino', array(array('group' => 'shots', 'option' => 'single'), array('group' => 'sweetness', 'option' => 'none'), array('group' => 'milk', 'option' => 'plant')), array('cinnamon'));
    const FREDDO = array('freddo_espresso', array(array('group' => 'shots', 'option' => 'double'), array('group' => 'sweetness', 'option' => 'little'), array('group' => 'sweetener', 'option' => 'white'), array('group' => 'milk', 'option' => 'none'), array('group' => 'ice', 'option' => 'little')), array('takeaway'));
    const GREEK = array('greek_coffee', array(array('group' => 'shots', 'option' => 'single'), array('group' => 'sweetness', 'option' => 'medium'), array('group' => 'sweetener', 'option' => 'white')), array());

    /** @var EVC_In_Memory_Prepared_Redemption_Store */
    private $store;
    /** @var EVC_Fake_Redemption_History */
    private $history;
    /** @var EVC_Fake_Prep_Catalogue_Authority */
    private $authority;
    /** @var EVC_Mock_Membership_Adapter */
    private $membership;
    /** @var EVC_Fixed_Clock */
    private $clock;
    /** @var EVC_Fake_Club_Staff_Authorizer */
    private $authorizer;
    /** @var EVC_Fake_Member_Context_Verifier */
    private $context;
    /** @var int */
    private $ids = 0;
    /** @var array[] */
    private $reports = array();

    protected function setUp(): void {
        $this->store = new EVC_In_Memory_Prepared_Redemption_Store();
        $this->store->add_member(self::MEMBER_A, 501);
        $this->store->add_member(self::MEMBER_B, 502);
        $this->store->add_member('mem_00000000000000000000000000000000', 503, 'disabled');
        $this->history = new EVC_Fake_Redemption_History($this->store);
        $this->authority = new EVC_Fake_Prep_Catalogue_Authority(self::catalogue_data());
        $this->membership = new EVC_Mock_Membership_Adapter();
        foreach (array(501, 502, 503) as $user) {
            $this->membership->set($user, EVC_Mock_Membership_Adapter::active_until('2027-12-31T00:00:00Z'));
        }
        $this->clock = new EVC_Fixed_Clock(self::TODAY);
        $this->authorizer = new EVC_Fake_Club_Staff_Authorizer();
        $this->authorizer->grants[self::STAFF] = array(EVC_Usual_Coffee_Service::CAPABILITY);
        $this->context = new EVC_Fake_Member_Context_Verifier();
        $this->context->lookups[self::LOOKUP_A] = array(self::STAFF, self::SESSION, self::MEMBER_A);
        $this->context->lookups[self::LOOKUP_B] = array(self::STAFF, self::SESSION, self::MEMBER_B);
        $this->reports = array();
    }

    // ---- helpers ---------------------------------------------------------------------------

    private static function catalogue_data(): array {
        return json_decode((string) file_get_contents(dirname(__DIR__) . '/fixtures/preparation/catalogue-illustrative.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    private function request_id(): string {
        $this->ids++;
        return sprintf('00000000-0000-4000-8000-%012x', $this->ids);
    }

    /** Redeems through the real service on the Athens day of $days_ago (09:00 UTC). */
    private function redeem_days_ago(int $days_ago, array $order, string $member = self::MEMBER_A): string {
        $this->clock->set((new DateTimeImmutable(self::TODAY))->modify('-' . $days_ago . ' days')->format('Y-m-d\TH:i:s\Z'));
        $request_id = $this->request_id();
        $service = new EVC_Prepared_Redemption_Service($this->store, $this->authority, $this->membership, $this->clock, EVC_Prepared_Redemption_Service::ALLOW_ILLUSTRATIVE_CATALOGUE_FOR_TESTS);
        $outcome = $service->redeem($member, 'free_coffee', new EVC_Staff_Actor(self::STAFF), array(
            'request_id' => $request_id,
            'catalogue_version' => $this->authority->data['catalogue_version'],
            'coffee_code' => $order[0],
            'preparation' => array('modifiers' => $order[1], 'quick_notes' => $order[2]),
        ));
        $this->assertSame(EVC_Prepared_Redemption_Outcome::REDEEMED, $outcome->kind(), 'fixture redemption');
        $this->clock->set(self::TODAY);
        return $request_id;
    }

    /** @param array<int,array> $sequence oldest first, one per day, ending $offset + 1 days ago */
    private function history_of(array $sequence, string $member = self::MEMBER_A, int $offset = 0): array {
        $ids = array();
        $n = count($sequence);
        foreach ($sequence as $i => $order) {
            $ids[] = $this->redeem_days_ago($offset + $n - $i, $order, $member);
        }
        return $ids;
    }

    private function service(?EVC_Usual_Coffee_Policy $policy = null, ?string $opt_in = EVC_Usual_Coffee_Service::ALLOW_ILLUSTRATIVE_CATALOGUE_FOR_TESTS): EVC_Usual_Coffee_Service {
        $reports = &$this->reports;
        return new EVC_Usual_Coffee_Service($this->history, $this->authority, $this->authorizer, $this->context, $this->clock, $policy ?? EVC_Usual_Coffee_Policy::provisional(), $opt_in, function (array $d) use (&$reports) {
            $reports[] = $d;
        });
    }

    private function suggest(string $member = self::MEMBER_A, ?string $lookup = self::LOOKUP_A, ?EVC_Usual_Coffee_Policy $policy = null): EVC_Usual_Coffee_Result {
        return $this->service($policy)->suggest(new EVC_Staff_Actor(self::STAFF, self::SESSION), $member, $lookup);
    }

    private function assertSuggests(array $order, EVC_Usual_Coffee_Result $result, ?string $message = null): void {
        $this->assertSame(EVC_Usual_Coffee_Result::SUGGESTED, $result->kind(), (string) $message);
        $s = $result->suggestion();
        $this->assertSame($order[0], $s['coffee_code'], (string) $message);
        $expected = array();
        foreach ($order[1] as $m) {
            $expected[$m['group']] = $m['option'];
        }
        ksort($expected);
        $this->assertSame($expected, array_column($s['modifiers'], 'option', 'group'), (string) $message);
        $notes = $order[2];
        sort($notes);
        $this->assertSame($notes, array_column($s['quick_notes'], 'id'), (string) $message);
        $this->assertFalse($result->is_confirmed_order());
    }

    private function assertNone(string $reason, EVC_Usual_Coffee_Result $result): void {
        $this->assertSame(EVC_Usual_Coffee_Result::NO_SUGGESTION, $result->kind());
        $this->assertSame($reason, $result->reason());
        $this->assertNull($result->suggestion());
    }

    private static function repeat(array $order, int $n): array {
        return array_fill(0, $n, $order);
    }

    // ---- history sufficiency ------------------------------------------------------------------

    public function test_no_history_gives_no_suggestion(): void {
        $this->assertNone(EVC_Usual_Coffee_Result::REASON_INSUFFICIENT_HISTORY, $this->suggest());
    }

    public function test_one_or_two_redemptions_are_not_enough(): void {
        $this->history_of(array(self::ESPRESSO_DOUBLE));
        $this->assertNone(EVC_Usual_Coffee_Result::REASON_INSUFFICIENT_HISTORY, $this->suggest());
        $this->redeem_days_ago(5, self::ESPRESSO_DOUBLE);
        $this->assertNone(EVC_Usual_Coffee_Result::REASON_INSUFFICIENT_HISTORY, $this->suggest());
    }

    public function test_a_frequently_repeated_preparation_is_suggested_with_current_labels_and_minimal_data(): void {
        $this->history_of(self::repeat(self::ESPRESSO_STEVIA, 5));
        $result = $this->suggest();
        $this->assertSuggests(self::ESPRESSO_STEVIA, $result);
        $s = $result->suggestion();
        $this->assertSame(array('catalogue_version', 'coffee_code', 'coffee_label', 'modifiers', 'quick_notes', 'basis'), array_keys($s));
        $this->assertSame(array('occurrences' => 5, 'considered' => 5), $s['basis']);
        $this->assertSame('Espresso', $s['coffee_label']);
        $this->assertSame(array('group' => 'sweetener', 'option' => 'stevia', 'group_label' => 'Γλυκαντικό', 'label' => 'Στέβια'), $s['modifiers'][1]);
        $flat = json_encode($s, JSON_UNESCAPED_UNICODE);
        foreach (array('mem_', 'request_id', 'business_date', 'redeemed_at', 'staff', 'session', 'prep_hash', 'snapshot_hash', 'note"') as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $flat);
        }
    }

    public function test_the_most_frequent_of_several_coffees_wins(): void {
        $this->history_of(array(self::ESPRESSO_DOUBLE, self::CAPPUCCINO_PLANT, self::ESPRESSO_DOUBLE, self::CAPPUCCINO_PLANT, self::ESPRESSO_DOUBLE));
        $this->assertSuggests(self::ESPRESSO_DOUBLE, $this->suggest());
        $this->assertSame(array('occurrences' => 3, 'considered' => 5), $this->suggest()->suggestion()['basis']);
    }

    public function test_preparations_of_the_same_coffee_are_never_mixed(): void {
        // Espresso 3x but in two different preparations; cappuccino 2x identical.
        $this->history_of(array(self::ESPRESSO_DOUBLE, self::CAPPUCCINO_PLANT, self::ESPRESSO_STEVIA, self::CAPPUCCINO_PLANT, self::ESPRESSO_DOUBLE, self::GREEK));
        $result = $this->suggest(self::MEMBER_A, self::LOOKUP_A, new EVC_Usual_Coffee_Policy(90, 10, 3, 2, 30));
        // Two complete preparations tie at 2: the most recent occurrence (espresso double, 2 days ago) wins.
        $this->assertSuggests(self::ESPRESSO_DOUBLE, $result);
        $this->assertSame(2, $result->suggestion()['basis']['occurrences'], 'a coffee type is never summed across preparations');
    }

    public function test_without_a_clear_usual_nothing_is_suggested(): void {
        $this->history_of(array(self::ESPRESSO_DOUBLE, self::ESPRESSO_STEVIA, self::CAPPUCCINO_PLANT, self::FREDDO, self::GREEK));
        $this->assertNone(EVC_Usual_Coffee_Result::REASON_NO_CLEAR_USUAL, $this->suggest());

        $this->setUp();
        $this->history_of(array(self::ESPRESSO_DOUBLE, self::ESPRESSO_STEVIA, self::CAPPUCCINO_PLANT, self::FREDDO, self::ESPRESSO_DOUBLE));
        $this->assertNone(EVC_Usual_Coffee_Result::REASON_NO_CLEAR_USUAL, $this->suggest(), '2 of 5 is below the provisional 50 % share');
    }

    public function test_a_single_occurrence_is_never_a_usual_even_when_it_is_the_whole_history(): void {
        $this->history_of(array(self::FREDDO));
        $this->assertNone(EVC_Usual_Coffee_Result::REASON_NO_CLEAR_USUAL, $this->suggest(self::MEMBER_A, self::LOOKUP_A, new EVC_Usual_Coffee_Policy(90, 10, 1, 2, 30)));
    }

    public function test_ties_are_broken_by_the_most_recent_occurrence(): void {
        $this->history_of(array(self::CAPPUCCINO_PLANT, self::ESPRESSO_DOUBLE, self::CAPPUCCINO_PLANT, self::ESPRESSO_DOUBLE));
        $this->assertSuggests(self::ESPRESSO_DOUBLE, $this->suggest());

        $this->setUp();
        $this->history_of(array(self::ESPRESSO_DOUBLE, self::CAPPUCCINO_PLANT, self::ESPRESSO_DOUBLE, self::CAPPUCCINO_PLANT));
        $this->assertSuggests(self::CAPPUCCINO_PLANT, $this->suggest());
    }

    public function test_a_recent_change_of_habit_wins_over_old_history(): void {
        $this->history_of(array_merge(self::repeat(self::ESPRESSO_DOUBLE, 15), self::repeat(self::FREDDO, 6)));
        $this->assertSuggests(self::FREDDO, $this->suggest(), 'only the 10 most recent valid redemptions are considered');
        $this->assertSame(array('occurrences' => 6, 'considered' => 10), $this->suggest()->suggestion()['basis']);
    }

    // ---- what is excluded ---------------------------------------------------------------------

    /** @return array<string,array{callable}> */
    public static function damages(): array {
        return array(
            'snapshot missing (incomplete transaction)' => array(function (EVC_In_Memory_Prepared_Redemption_Store $s, string $rid) {
                $s->tamper_snapshots($rid, array());
            }),
            'snapshot carries free text' => array(function (EVC_In_Memory_Prepared_Redemption_Store $s, string $rid) {
                $snapshot = $s->find_redemption($rid)['snapshots'][0];
                $snapshot['note'] = 'χωρίς ζάχαρη';
                $s->tamper_snapshots($rid, array($snapshot));
            }),
            'snapshot label corrupted' => array(function (EVC_In_Memory_Prepared_Redemption_Store $s, string $rid) {
                $snapshot = $s->find_redemption($rid)['snapshots'][0];
                $snapshot['coffee_label'] = 'Mocha';
                $s->tamper_snapshots($rid, array($snapshot));
            }),
            'two snapshots' => array(function (EVC_In_Memory_Prepared_Redemption_Store $s, string $rid) {
                $snapshot = $s->find_redemption($rid)['snapshots'][0];
                $s->tamper_snapshots($rid, array($snapshot, $snapshot));
            }),
            'ledger hash conflicts with the snapshot' => array(function (EVC_In_Memory_Prepared_Redemption_Store $s, string $rid) {
                $s->tamper_ledger($rid, array('prep_hash' => str_repeat('0', 64)));
            }),
            'damaged handoff record' => array(function (EVC_In_Memory_Prepared_Redemption_Store $s, string $rid) {
                $s->tamper_handoffs($rid, array(array('id' => 99, 'redemption_id' => $s->find_redemption($rid)['ledger']['id'], 'claim' => 'probably')));
            }),
        );
    }

    /** @dataProvider damages */
    public function test_damaged_or_incomplete_records_are_excluded(callable $damage): void {
        $this->history_of(array(self::ESPRESSO_DOUBLE, self::ESPRESSO_DOUBLE, self::ESPRESSO_DOUBLE), self::MEMBER_A, 10);
        $cappuccinos = $this->history_of(self::repeat(self::CAPPUCCINO_PLANT, 4), self::MEMBER_A);
        $this->assertSuggests(self::CAPPUCCINO_PLANT, $this->suggest(), 'sanity: intact cappuccinos would win');
        foreach ($cappuccinos as $rid) {
            $damage($this->store, $rid);
        }
        $this->assertSuggests(self::ESPRESSO_DOUBLE, $this->suggest());
    }

    public function test_legacy_v1_rows_have_no_preparation_and_are_ignored(): void {
        for ($d = 1; $d <= 6; $d++) {
            $date = (new DateTimeImmutable(self::TODAY))->modify("-$d days")->format('Y-m-d');
            $this->store->insert_legacy_v1(1, $date, sprintf('10000000-0000-4000-8000-%012x', $d), 'espresso');
        }
        $this->assertNone(EVC_Usual_Coffee_Result::REASON_INSUFFICIENT_HISTORY, $this->suggest());
        $this->redeem_days_ago(10, self::FREDDO);
        $this->redeem_days_ago(11, self::FREDDO);
        $this->redeem_days_ago(12, self::FREDDO);
        $this->assertSuggests(self::FREDDO, $this->suggest());
    }

    public function test_failed_and_refused_redemptions_leave_no_history(): void {
        $this->history_of(self::repeat(self::GREEK, 3));
        // A refused second coffee on the same day, and a transaction that failed and rolled back.
        $service = new EVC_Prepared_Redemption_Service($this->store, $this->authority, $this->membership, $this->clock, EVC_Prepared_Redemption_Service::ALLOW_ILLUSTRATIVE_CATALOGUE_FOR_TESTS);
        $body = function (array $order) {
            return array('request_id' => $this->request_id(), 'catalogue_version' => 1, 'coffee_code' => $order[0], 'preparation' => array('modifiers' => $order[1], 'quick_notes' => $order[2]));
        };
        $this->assertSame(EVC_Prepared_Redemption_Outcome::REDEEMED, $service->redeem(self::MEMBER_A, 'free_coffee', new EVC_Staff_Actor(self::STAFF), $body(self::GREEK))->kind());
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(EVC_Prepared_Redemption_Outcome::ALREADY_REDEEMED, $service->redeem(self::MEMBER_A, 'free_coffee', new EVC_Staff_Actor(self::STAFF), $body(self::FREDDO))->kind());
        }
        $this->clock->set('2026-10-15T09:00:00Z');
        $this->store->failures = array(array('after_snapshot', new EVC_Db_Exception('Club database query failed.', 'HY000', 2006)));
        $this->assertSame(EVC_Prepared_Redemption_Outcome::SERVER_ERROR, $service->redeem(self::MEMBER_A, 'free_coffee', new EVC_Staff_Actor(self::STAFF), $body(self::FREDDO))->kind());
        $this->assertSuggests(self::GREEK, $this->suggest());
        $this->assertSame(4, $this->suggest()->suggestion()['basis']['considered']);
    }

    public function test_a_request_id_returned_twice_by_the_reader_is_dropped_as_conflicting(): void {
        $this->history_of(self::repeat(self::ESPRESSO_DOUBLE, 3), self::MEMBER_A, 10);
        $cappuccinos = $this->history_of(self::repeat(self::CAPPUCCINO_PLANT, 2));
        foreach ($cappuccinos as $rid) {
            $this->history->extra_rows[] = $this->store->find_redemption($rid);
            $this->history->extra_rows[] = $this->store->find_redemption($rid);
        }
        $result = $this->suggest();
        $this->assertSuggests(self::ESPRESSO_DOUBLE, $result);
        $this->assertSame(array('occurrences' => 3, 'considered' => 3), $result->suggestion()['basis']);
    }

    public function test_another_members_history_never_influences_a_suggestion(): void {
        $this->history_of(self::repeat(self::FREDDO, 8), self::MEMBER_B);
        $this->history_of(self::repeat(self::GREEK, 3), self::MEMBER_A);
        $this->history->leak_other_members = true; // a faulty reader returning everyone's rows
        $this->assertSuggests(self::GREEK, $this->suggest());
        $this->assertSuggests(self::FREDDO, $this->suggest(self::MEMBER_B, self::LOOKUP_B));

        // A row re-labelled with A's public id but B's internal id is still foreign.
        $foreign = $this->store->all_redemptions()[0];
        $foreign['ledger']['member_public_id'] = self::MEMBER_A;
        $this->history->extra_rows = array($foreign, $foreign, $foreign);
        $this->assertSame(array('occurrences' => 3, 'considered' => 3), $this->suggest()->suggestion()['basis']);
    }

    // ---- current catalogue validity ----------------------------------------------------------

    private function catalogue_v2(callable $edit): void {
        $v2 = self::catalogue_data();
        $v2['catalogue_version'] = 2;
        $edit($v2);
        $this->authority->data = $v2;
    }

    private static function coffee_index(array $data, string $code): int {
        foreach ($data['coffees'] as $i => $c) {
            if ($c['code'] === $code) {
                return $i;
            }
        }
        throw new LogicException($code);
    }

    /** @return array<string,array{callable}> */
    public static function catalogue_changes(): array {
        return array(
            'coffee retired' => array(function (array &$d) {
                array_splice($d['coffees'], self::coffee_index($d, 'espresso'), 1);
            }),
            'modifier option removed' => array(function (array &$d) {
                $e = self::coffee_index($d, 'espresso');
                foreach ($d['coffees'][$e]['groups'] as $g => $group) {
                    if ($group['id'] === 'sweetener') {
                        $d['coffees'][$e]['groups'][$g]['options'] = array_values(array_filter($group['options'], function ($o) {
                            return $o['id'] !== 'stevia';
                        }));
                    }
                }
            }),
            'quick note no longer offered for the coffee' => array(function (array &$d) {
                $e = self::coffee_index($d, 'espresso');
                $d['coffees'][$e]['quick_notes'] = array_values(array_filter($d['coffees'][$e]['quick_notes'], function ($q) {
                    return $q !== 'very_hot';
                }));
            }),
            'preparation rule tightened (new required group)' => array(function (array &$d) {
                $e = self::coffee_index($d, 'espresso');
                foreach ($d['coffees'][$e]['groups'] as $g => $group) {
                    if ($group['id'] === 'caffeine') {
                        $d['coffees'][$e]['groups'][$g]['required'] = true;
                    }
                }
            }),
        );
    }

    /** @dataProvider catalogue_changes */
    public function test_a_usual_that_the_current_catalogue_cannot_offer_exactly_is_not_suggested_or_substituted(callable $change): void {
        // Usual: espresso with stevia (4x). Runner-up cappuccino (3x) must NOT be offered instead.
        $this->history_of(array(self::CAPPUCCINO_PLANT, self::ESPRESSO_STEVIA, self::CAPPUCCINO_PLANT, self::ESPRESSO_STEVIA, self::CAPPUCCINO_PLANT, self::ESPRESSO_STEVIA, self::ESPRESSO_STEVIA));
        $this->assertSuggests(self::ESPRESSO_STEVIA, $this->suggest(self::MEMBER_A, self::LOOKUP_A, new EVC_Usual_Coffee_Policy(90, 10, 3, 2, 40)));
        $this->catalogue_v2($change);
        $this->assertNone(EVC_Usual_Coffee_Result::REASON_NOT_OFFERABLE, $this->suggest(self::MEMBER_A, self::LOOKUP_A, new EVC_Usual_Coffee_Policy(90, 10, 3, 2, 40)));
    }

    public function test_renamed_labels_and_a_new_catalogue_version_use_the_current_catalogue(): void {
        $this->history_of(self::repeat(self::ESPRESSO_STEVIA, 4));
        $this->catalogue_v2(function (array &$d) {
            $e = self::coffee_index($d, 'espresso');
            $d['coffees'][$e]['label'] = 'Εσπρέσο';
            foreach ($d['coffees'][$e]['groups'] as $g => $group) {
                if ($group['id'] === 'sweetener') {
                    foreach ($group['options'] as $o => $option) {
                        if ($option['id'] === 'stevia') {
                            $d['coffees'][$e]['groups'][$g]['options'][$o]['label'] = 'Στέβια (φυσική)';
                        }
                    }
                }
            }
        });
        $result = $this->suggest();
        $this->assertSuggests(self::ESPRESSO_STEVIA, $result);
        $s = $result->suggestion();
        $this->assertSame(2, $s['catalogue_version']);
        $this->assertSame('Εσπρέσο', $s['coffee_label']);
        $this->assertSame('Στέβια (φυσική)', $s['modifiers'][1]['label']);
    }

    public function test_the_same_preparation_across_catalogue_versions_is_one_habit(): void {
        $this->history_of(self::repeat(self::FREDDO, 2), self::MEMBER_A, 10);
        $this->catalogue_v2(function (array &$d) {
        });
        $this->history_of(self::repeat(self::FREDDO, 2));
        $this->assertSame(array('occurrences' => 4, 'considered' => 4), $this->suggest()->suggestion()['basis']);
    }

    public function test_an_unauthorised_catalogue_gives_no_suggestion(): void {
        $this->history_of(self::repeat(self::FREDDO, 4));
        $approved = self::catalogue_data();
        $approved['status'] = 'approved';
        $this->authority->data = $approved;
        $this->assertNone(EVC_Usual_Coffee_Result::REASON_CATALOGUE_NOT_AUTHORISED, $this->suggest());
        $this->authority->approvals[1] = EVC_Prep_Catalogue_Digest::of($approved);
        $this->assertSuggests(self::FREDDO, $this->service(null, null)->suggest(new EVC_Staff_Actor(self::STAFF, self::SESSION), self::MEMBER_A, self::LOOKUP_A));
        $this->authority->data = self::catalogue_data();
        $this->assertNone(EVC_Usual_Coffee_Result::REASON_CATALOGUE_NOT_AUTHORISED, $this->service(null, null)->suggest(new EVC_Staff_Actor(self::STAFF, self::SESSION), self::MEMBER_A, self::LOOKUP_A));
    }

    // ---- determinism, size and dates ---------------------------------------------------------

    public function test_the_result_does_not_depend_on_row_order(): void {
        $this->history_of(array(self::CAPPUCCINO_PLANT, self::ESPRESSO_DOUBLE, self::FREDDO, self::CAPPUCCINO_PLANT, self::ESPRESSO_DOUBLE, self::FREDDO, self::GREEK, self::ESPRESSO_DOUBLE, self::CAPPUCCINO_PLANT));
        $results = array();
        foreach (array('asc', 'desc', 'shuffle') as $order) {
            foreach (array(1, 2, 3, 42) as $seed) {
                $this->history->order = $order;
                $this->history->seed = $seed;
                $results[] = json_encode($this->suggest(self::MEMBER_A, self::LOOKUP_A, new EVC_Usual_Coffee_Policy(90, 10, 3, 2, 30))->suggestion());
            }
        }
        $this->assertCount(1, array_unique($results));
        $this->assertSuggests(self::CAPPUCCINO_PLANT, $this->suggest(self::MEMBER_A, self::LOOKUP_A, new EVC_Usual_Coffee_Policy(90, 10, 3, 2, 30)), 'cappuccino and espresso tie at 3; cappuccino was most recent');
    }

    public function test_a_large_history_is_bounded_and_deterministic(): void {
        $orders = array(self::ESPRESSO_DOUBLE, self::CAPPUCCINO_PLANT, self::FREDDO, self::GREEK, self::ESPRESSO_STEVIA);
        $sequence = array();
        for ($i = 0; $i < 300; $i++) {
            $sequence[] = $orders[($i * 7) % 5];
        }
        for ($i = 0; $i < 7; $i++) {
            $sequence[] = self::GREEK;
        }
        $this->history_of($sequence);
        $policy = new EVC_Usual_Coffee_Policy(366, 10, 3, 2, 50);
        $this->history->order = 'shuffle';
        $started = microtime(true);
        $result = $this->suggest(self::MEMBER_A, self::LOOKUP_A, $policy);
        $this->assertLessThan(2.0, microtime(true) - $started);
        $this->assertSuggests(self::GREEK, $result);
        $this->assertSame(10, $result->suggestion()['basis']['considered']);
        $this->assertSame(array('history', 1, '2025-10-14', '2026-10-14'), end($this->history->calls), 'only the lookback window is requested');
    }

    public function test_the_lookback_window_uses_europe_athens_business_dates(): void {
        // Today 00:30 Athens on the 15th (21:30 UTC on the 14th): the 90-day window is 2026-07-18 .. 2026-10-15.
        $this->clock->set('2026-10-14T21:30:00Z');
        $this->suggest();
        $this->assertSame(array('history', 1, '2026-07-18', '2026-10-15'), end($this->history->calls));

        // Rows outside the window are ignored even when a faulty reader returns them.
        $this->clock->set(self::TODAY);
        $this->history_of(self::repeat(self::GREEK, 3));
        $original = $this->store->all_redemptions()[0];
        $redated = function (string $date, string $prefix) use ($original): array {
            $copy = $original;
            // Re-dated CONSISTENTLY: 09:00 UTC is on the same Athens business date.
            $copy['ledger']['business_date'] = $date;
            $copy['ledger']['redeemed_at_utc'] = $date . ' 09:00:00.000000';
            $copy['ledger']['request_id'] = $prefix . substr($original['ledger']['request_id'], 1);
            return $copy;
        };
        // UI-04B: a row whose instant is not on its stated Athens date is damaged and never counted.
        $inconsistent = $redated('2026-09-01', 'c');
        $inconsistent['ledger']['redeemed_at_utc'] = '2026-08-31 20:59:59.999999'; // 23:59:59.999999 Athens on 31 August
        $this->history->extra_rows = array($inconsistent);
        $this->assertSame(array('occurrences' => 3, 'considered' => 3), $this->suggest()->suggestion()['basis'], 'inconsistent ledger time is excluded');
        $this->history->ignore_window = true;
        $this->history->extra_rows = array($redated('2026-07-17', 'e'));
        $this->clock->set('2026-10-14T21:30:00Z');
        $this->assertSame(array('occurrences' => 3, 'considered' => 3), $this->suggest()->suggestion()['basis'], '2026-07-17 is outside the Athens window');
        $this->history->extra_rows[] = $redated('2026-07-18', 'f');
        $this->assertSame(array('occurrences' => 4, 'considered' => 4), $this->suggest()->suggestion()['basis'], '2026-07-18 is its first day');
        $this->history->extra_rows[] = $redated('2026-10-16', 'd');
        $this->assertSame(4, $this->suggest()->suggestion()['basis']['considered'], 'a future business date is ignored');
    }

    public function test_the_provisional_policy_and_its_limits(): void {
        $p = EVC_Usual_Coffee_Policy::provisional();
        $this->assertSame(array(90, 10, 3, 2, 50), array($p->lookback_days(), $p->max_considered(), $p->min_valid(), $p->min_occurrences(), $p->min_share_percent()));
        // UI-04B: immutable after construction (existing and dynamic properties alike).
        foreach (array('lookback_days', 'max_considered', 'min_valid', 'min_occurrences', 'min_share_percent', 'anything_else') as $field) {
            try {
                $p->$field = 1;
                $this->fail('mutated ' . $field);
            } catch (LogicException $e) {
                $this->assertSame('EVC_Usual_Coffee_Policy is immutable.', $e->getMessage());
            }
        }
        $this->assertSame(array(90, 10, 3, 2, 50), array($p->lookback_days(), $p->max_considered(), $p->min_valid(), $p->min_occurrences(), $p->min_share_percent()));
        $reflection = new ReflectionClass('EVC_Usual_Coffee_Policy');
        foreach ($reflection->getProperties() as $property) {
            $this->assertTrue($property->isPrivate(), $property->getName() . ' must be private');
        }
        foreach (array(array(0, 10, 3, 2, 50), array(90, 0, 3, 2, 50), array(90, 10, 11, 2, 50), array(90, 10, 3, 11, 50), array(90, 10, 3, 2, 0), array(90, 10, 3, 2, 101), array(400, 10, 3, 2, 50)) as $bad) {
            try {
                new EVC_Usual_Coffee_Policy(...$bad);
                $this->fail('accepted ' . json_encode($bad));
            } catch (InvalidArgumentException $e) {
                $this->assertSame('Invalid usual-coffee policy.', $e->getMessage());
            }
        }
    }

    // ---- authorisation, privacy, read-only ----------------------------------------------------

    public function test_staff_need_the_capability_and_a_lookup_of_exactly_this_member(): void {
        $this->history_of(self::repeat(self::FREDDO, 4));
        $this->context->lookups['lookup-by-8'] = array(8, self::SESSION, self::MEMBER_A);
        $cases = array(
            'no capability' => array(new EVC_Staff_Actor(8, self::SESSION), self::MEMBER_A, self::LOOKUP_A, EVC_Usual_Coffee_Result::FORBIDDEN),
            'a genuine lookup but no capability' => array(new EVC_Staff_Actor(8, self::SESSION), self::MEMBER_A, 'lookup-by-8', EVC_Usual_Coffee_Result::FORBIDDEN),
            'no lookup' => array(new EVC_Staff_Actor(self::STAFF, self::SESSION), self::MEMBER_A, null, EVC_Usual_Coffee_Result::FORBIDDEN),
            'lookup of another member (enumeration)' => array(new EVC_Staff_Actor(self::STAFF, self::SESSION), self::MEMBER_A, self::LOOKUP_B, EVC_Usual_Coffee_Result::FORBIDDEN),
            'lookup of another session of the shared account' => array(new EVC_Staff_Actor(self::STAFF, 'ffffffffffffffffffffffffffffffff'), self::MEMBER_A, self::LOOKUP_A, EVC_Usual_Coffee_Result::FORBIDDEN),
            'malformed member id' => array(new EVC_Staff_Actor(self::STAFF, self::SESSION), 'mem_1', self::LOOKUP_A, EVC_Usual_Coffee_Result::INVALID_REQUEST),
        );
        foreach ($cases as $name => $c) {
            $calls = count($this->history->calls);
            $result = $this->service()->suggest($c[0], $c[1], $c[2]);
            $this->assertSame($c[3], $result->kind(), $name);
            $this->assertNull($result->suggestion(), $name);
            $this->assertSame($calls, count($this->history->calls), $name . ': no history read');
        }
    }

    public function test_unknown_or_disabled_members_get_nothing(): void {
        $this->context->lookups['lookup-x'] = array(self::STAFF, self::SESSION, 'mem_11111111111111111111111111111111');
        $this->context->lookups['lookup-d'] = array(self::STAFF, self::SESSION, 'mem_00000000000000000000000000000000');
        $this->assertSame(EVC_Usual_Coffee_Result::MEMBER_NOT_FOUND, $this->suggest('mem_11111111111111111111111111111111', 'lookup-x')->kind());
        $this->assertSame(EVC_Usual_Coffee_Result::MEMBER_NOT_FOUND, $this->suggest('mem_00000000000000000000000000000000', 'lookup-d')->kind());
    }

    public function test_a_suggestion_writes_nothing_and_decides_no_entitlement(): void {
        $this->history_of(self::repeat(self::FREDDO, 4));
        $before = $this->store->snapshot_of_state();
        $membership_calls = count($this->membership->calls);
        $result = $this->suggest();
        $this->assertSuggests(self::FREDDO, $result);
        $this->assertSame($before, $this->store->snapshot_of_state(), 'no ledger, snapshot, audit, handoff or rejection write');
        $this->assertSame($membership_calls, count($this->membership->calls), 'no eligibility decision');
        $this->assertFalse($result->is_confirmed_order());
        foreach (array('redeem', 'confirm', 'commit', 'write', 'save', 'record') as $verb) {
            foreach (get_class_methods('EVC_Usual_Coffee_Service') as $method) {
                $this->assertStringNotContainsString($verb, $method, 'the service has no mutating operation');
            }
        }
        // Using the suggestion is a NEW redemption that the normal flow fully re-validates.
        $s = $result->suggestion();
        $service = new EVC_Prepared_Redemption_Service($this->store, $this->authority, $this->membership, $this->clock, EVC_Prepared_Redemption_Service::ALLOW_ILLUSTRATIVE_CATALOGUE_FOR_TESTS);
        $outcome = $service->redeem(self::MEMBER_A, 'free_coffee', new EVC_Staff_Actor(self::STAFF), array(
            'request_id' => $this->request_id(),
            'catalogue_version' => $s['catalogue_version'],
            'coffee_code' => $s['coffee_code'],
            'preparation' => array('modifiers' => array_map(function ($m) {
                return array('group' => $m['group'], 'option' => $m['option']);
            }, $s['modifiers']), 'quick_notes' => array_column($s['quick_notes'], 'id')),
        ));
        $this->assertSame(EVC_Prepared_Redemption_Outcome::REDEEMED, $outcome->kind());
        $this->assertSame(EVC_Prepared_Redemption_Outcome::ALREADY_REDEEMED, $service->redeem(self::MEMBER_A, 'free_coffee', new EVC_Staff_Actor(self::STAFF), array(
            'request_id' => $this->request_id(),
            'catalogue_version' => $s['catalogue_version'],
            'coffee_code' => $s['coffee_code'],
            'preparation' => array('modifiers' => array_map(function ($m) {
                return array('group' => $m['group'], 'option' => $m['option']);
            }, $s['modifiers']), 'quick_notes' => array_column($s['quick_notes'], 'id')),
        ))->kind(), 'a suggestion never grants another coffee');
    }

    public function test_failures_are_reported_without_any_history_or_member_data(): void {
        $this->history_of(self::repeat(self::FREDDO, 4));
        $this->history->fail = true;
        $result = $this->suggest();
        $this->assertSame(EVC_Usual_Coffee_Result::SERVER_ERROR, $result->kind());
        $this->assertNull($result->suggestion());
        $this->assertSame(array(array('stage' => 'usual_coffee', 'exception' => 'EVC_Db_Exception')), $this->reports);
    }

    public function test_refusal_results_cannot_carry_a_suggestion(): void {
        $this->expectException(InvalidArgumentException::class);
        EVC_Usual_Coffee_Result::refused(EVC_Usual_Coffee_Result::SUGGESTED);
    }
}
