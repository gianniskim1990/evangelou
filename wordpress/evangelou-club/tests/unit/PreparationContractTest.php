<?php
use PHPUnit\Framework\TestCase;

/**
 * UI-03C — preparation contract core. The cases come from the SHARED vectors
 * (tests/fixtures/preparation/vectors.json) that the TypeScript client
 * implementation must reproduce as well.
 */
final class PreparationContractTest extends TestCase {
    const MEMBER = 'mem_0123456789abcdef0123456789abcdef';
    const REQUEST_ID = '3fa85f64-5717-4562-b3fc-2c963f66afa6';

    private static function fixture_dir(): string {
        return dirname(__DIR__) . '/fixtures/preparation';
    }

    private static function catalogue_data(): array {
        return json_decode((string) file_get_contents(self::fixture_dir() . '/catalogue-illustrative.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    private static function catalogue(): EVC_Prep_Catalogue {
        return EVC_Prep_Catalogue::from_array(self::catalogue_data());
    }

    private static function vectors(): array {
        return json_decode((string) file_get_contents(self::fixture_dir() . '/vectors.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array{kind:string,reason:?string,canonical?:string,prep_hash?:string} */
    private static function evaluate(EVC_Prep_Catalogue $catalogue, string $coffee, $preparation): array {
        try {
            $parsed = EVC_Prep_Canonical::parse($preparation);
            $order = EVC_Prep_Validator::validate($catalogue, $coffee, $parsed['modifiers'], $parsed['quick_notes']);
            return array('kind' => 'ok', 'reason' => null, 'canonical' => $order->canonical(), 'prep_hash' => $order->prep_hash());
        } catch (EVC_Prep_Rejection $r) {
            return array('kind' => $r->kind(), 'reason' => $r->reason());
        }
    }

    // ---- shared vectors -------------------------------------------------------------------

    /** @return array<string,array{array}> */
    public static function vector_cases(): array {
        $out = array();
        foreach (self::vectors()['cases'] as $case) {
            $out[$case['name']] = array($case);
        }
        return $out;
    }

    /** @dataProvider vector_cases */
    public function test_shared_vector(array $case): void {
        $got = self::evaluate(self::catalogue(), $case['coffee_code'], $case['preparation']);
        $this->assertSame($case['expect']['kind'], $got['kind']);
        if ($case['expect']['kind'] === 'ok') {
            $this->assertSame($case['expect']['canonical'], $got['canonical']);
            $this->assertSame($case['expect']['prep_hash'], $got['prep_hash']);
            $this->assertSame(hash('sha256', $case['expect']['canonical']), $got['prep_hash']);
        } else {
            $this->assertSame(isset($case['expect']['reason']) ? $case['expect']['reason'] : null, $got['reason']);
        }
    }

    public function test_vector_set_covers_every_rejection_reason(): void {
        $reasons = array();
        foreach (self::vectors()['cases'] as $case) {
            $reasons[$case['expect']['kind'] . '/' . (isset($case['expect']['reason']) ? $case['expect']['reason'] : '')] = true;
        }
        foreach (array(
            'invalid_preparation/free_text_not_supported', 'invalid_preparation/duplicate_group', 'invalid_preparation/duplicate_quick_note',
            'invalid_coffee/', 'invalid_preparation/unknown_group', 'invalid_preparation/unknown_option', 'invalid_preparation/hidden_group_present',
            'invalid_preparation/required_missing', 'invalid_preparation/too_many_quick_notes', 'invalid_preparation/unknown_quick_note',
            'invalid_preparation/quick_note_not_applicable', 'invalid_preparation/exclusive_conflict', 'invalid_request/modifier_shape',
            'invalid_request/preparation_shape', 'invalid_request/quick_note_shape', 'ok/',
        ) as $expected) {
            $this->assertArrayHasKey($expected, $reasons, $expected);
        }
    }

    public function test_equivalent_requests_share_one_canonical_form(): void {
        $v = self::vectors();
        $hash = array();
        foreach ($v['cases'] as $case) {
            if ($case['expect']['kind'] === 'ok') {
                $hash[$case['name']] = self::evaluate(self::catalogue(), $case['coffee_code'], $case['preparation'])['prep_hash'];
            }
        }
        foreach ($v['equivalent'] as $pair) {
            $this->assertSame($hash[$pair[0]], $hash[$pair[1]], implode(' = ', $pair));
        }
    }

    public function test_fingerprint_v2_vectors_and_domain_separation_from_v1(): void {
        $v = self::vectors();
        foreach ($v['fingerprints'] as $f) {
            $this->assertSame($f['fingerprint_v2'], EVC_Prep_Canonical::fingerprint_v2($f['member_public_id'], $f['benefit_type'], $f['staff_wp_user_id'], $f['prep_hash']));
        }
        $ref = $v['fingerprint_v1_reference'];
        $v1 = EVC_Request_Fingerprint::compute($ref['member_public_id'], $ref['benefit_type'], $ref['coffee_code'], $ref['staff_wp_user_id']);
        $this->assertSame($ref['fingerprint_v1'], $v1, 'the v1 engine fingerprint is unchanged');
        foreach ($v['fingerprints'] as $f) {
            $this->assertNotSame($v1, $f['fingerprint_v2']);
        }
    }

    // ---- catalogue model ------------------------------------------------------------------

    /** @return array<string,array{array}> */
    public static function catalogue_rejections(): array {
        $out = array();
        foreach (self::vectors()['catalogue_rejections'] as $r) {
            $out[$r['name']] = array($r);
        }
        return $out;
    }

    /** @dataProvider catalogue_rejections */
    public function test_shared_catalogue_rejection(array $r): void {
        $data = self::catalogue_data();
        $target = &$data;
        foreach (array_slice($r['path'], 0, -1) as $key) {
            $target = &$target[$key];
        }
        $target[$r['path'][count($r['path']) - 1]] = $r['value'];
        unset($target);
        try {
            EVC_Prep_Catalogue::from_array($data);
            $this->fail('catalogue accepted: ' . $r['name']);
        } catch (InvalidArgumentException $e) {
            $this->assertSame('Invalid preparation catalogue: ' . $r['reason'], $e->getMessage());
        }
    }

    public function test_illustrative_fixture_is_not_approved_and_has_no_free_text_or_prices(): void {
        $data = self::catalogue_data();
        $catalogue = EVC_Prep_Catalogue::from_array($data);
        $this->assertSame(1, $catalogue->version());
        $this->assertFalse($catalogue->is_approved());
        $this->assertSame('undecided', $data['pricing_policy']);
        $this->assertFalse($data['free_text_note']);
        $this->assertStringNotContainsString('price', (string) json_encode($data));
    }

    public function test_canonical_ids_use_underscores_never_hyphens(): void {
        foreach (array('freddo_espresso', 'lactose_free', 'very_hot') as $ok) {
            $this->assertTrue(EVC_Prep_Catalogue::is_id($ok), $ok);
        }
        foreach (array('freddo-espresso', 'Freddo_espresso', 'f', '1abc', 'espresso ', str_repeat('a', 33), 7, null) as $bad) {
            $this->assertFalse(EVC_Prep_Catalogue::is_id($bad), var_export($bad, true));
        }
    }

    public function test_label_length_counts_code_points_not_bytes(): void {
        $this->assertTrue(EVC_Prep_Catalogue::is_label(str_repeat('α', 64)), '64 Greek letters = 128 bytes');
        $this->assertFalse(EVC_Prep_Catalogue::is_label(str_repeat('α', 65)));
        $this->assertFalse(EVC_Prep_Catalogue::is_label("\xff\xfe"), 'invalid UTF-8');
        $this->assertFalse(EVC_Prep_Catalogue::is_label(' Δόση'));
    }

    public function test_snapshot_carries_labels_and_no_free_text(): void {
        $parsed = EVC_Prep_Canonical::parse(array('modifiers' => array(array('group' => 'shots', 'option' => 'double'), array('group' => 'sweetness', 'option' => 'none')), 'quick_notes' => array('takeaway'), 'note' => null));
        $order = EVC_Prep_Validator::validate(self::catalogue(), 'espresso', $parsed['modifiers'], $parsed['quick_notes']);
        $snapshot = $order->snapshot();
        $this->assertSame(array('catalogue_version', 'coffee_code', 'coffee_label', 'modifiers', 'quick_notes', 'prep_hash'), array_keys($snapshot));
        $this->assertSame('Espresso', $snapshot['coffee_label']);
        $this->assertSame(array('group' => 'shots', 'option' => 'double', 'group_label' => 'Δόση', 'label' => 'Διπλός'), $snapshot['modifiers'][0]);
        $this->assertSame(array(array('id' => 'takeaway', 'label' => 'σε ποτήρι για έξω')), $snapshot['quick_notes']);
        $this->assertArrayNotHasKey('note', $snapshot);
        $this->assertArrayNotHasKey('note_text', $snapshot);
    }

    // ---- decision order (replay before catalogue) ---------------------------------------

    private static function lookup(?string $stored, array &$calls): EVC_Prep_Request_Lookup {
        return new class($stored, $calls) implements EVC_Prep_Request_Lookup {
            private $stored;
            private $calls;
            public function __construct(?string $stored, array &$calls) {
                $this->stored = $stored;
                $this->calls = &$calls;
            }
            public function fingerprint_for_request(string $request_id): ?string {
                $this->calls[] = $request_id;
                return $this->stored;
            }
        };
    }

    private static function precheck(?string $stored, array &$calls, ?array $catalogue_data = null): EVC_Prepared_Redeem_Precheck {
        return new EVC_Prepared_Redeem_Precheck(
            EVC_Prep_Catalogue::from_array($catalogue_data !== null ? $catalogue_data : self::catalogue_data()),
            self::lookup($stored, $calls),
            EVC_Prepared_Redeem_Precheck::ALLOW_ILLUSTRATIVE_CATALOGUE_FOR_TESTS
        );
    }

    private static function body(array $overrides = array()): array {
        return array_merge(array(
            'request_id' => self::REQUEST_ID,
            'catalogue_version' => 1,
            'coffee_code' => 'espresso',
            'preparation' => array(
                'modifiers' => array(array('group' => 'shots', 'option' => 'double'), array('group' => 'sweetness', 'option' => 'medium'), array('group' => 'sweetener', 'option' => 'stevia')),
                'quick_notes' => array('very_hot', 'takeaway'),
            ),
        ), $overrides);
    }

    public function test_illustrative_catalogue_cannot_be_activated_without_test_opt_in(): void {
        $calls = array();
        $this->expectException(InvalidArgumentException::class);
        new EVC_Prepared_Redeem_Precheck(self::catalogue(), self::lookup(null, $calls));
    }

    public function test_illustrative_catalogue_rejects_any_other_opt_in_value(): void {
        $calls = array();
        $this->expectException(InvalidArgumentException::class);
        new EVC_Prepared_Redeem_Precheck(self::catalogue(), self::lookup(null, $calls), 'yes');
    }

    public function test_approved_catalogue_needs_no_opt_in(): void {
        $data = self::catalogue_data();
        $data['status'] = 'approved';
        $calls = array();
        $precheck = new EVC_Prepared_Redeem_Precheck(EVC_Prep_Catalogue::from_array($data), self::lookup(null, $calls));
        $this->assertSame(EVC_Prep_Decision::PROCEED, $precheck->decide(self::MEMBER, 'free_coffee', 7, self::body())->kind());
    }

    public function test_new_valid_request_proceeds_with_vector_fingerprint(): void {
        $calls = array();
        $d = self::precheck(null, $calls)->decide(self::MEMBER, 'free_coffee', 7, self::body());
        $this->assertSame(EVC_Prep_Decision::PROCEED, $d->kind());
        $expected = self::vectors()['fingerprints'][0];
        $this->assertSame('ok_espresso_sweet_stevia', $expected['case']);
        $this->assertSame($expected['fingerprint_v2'], $d->fingerprint());
        $this->assertSame($expected['prep_hash'], $d->order()->prep_hash());
        $this->assertSame(array(self::REQUEST_ID), $calls, 'exactly one lookup by request id');
    }

    public function test_exact_retry_replays_even_after_the_catalogue_changed(): void {
        $calls = array();
        $first = self::precheck(null, $calls)->decide(self::MEMBER, 'free_coffee', 7, self::body());
        // Catalogue v2 no longer offers espresso at all.
        $v2 = self::catalogue_data();
        $v2['catalogue_version'] = 2;
        array_shift($v2['coffees']);
        $retry = self::precheck($first->fingerprint(), $calls, $v2)->decide(self::MEMBER, 'free_coffee', 7, self::body());
        $this->assertSame(EVC_Prep_Decision::REPLAY, $retry->kind());
        $this->assertSame($first->fingerprint(), $retry->fingerprint());
    }

    public function test_request_order_of_modifiers_does_not_break_replay(): void {
        $calls = array();
        $first = self::precheck(null, $calls)->decide(self::MEMBER, 'free_coffee', 7, self::body());
        $shuffled = self::body(array('preparation' => array(
            'modifiers' => array(array('group' => 'sweetener', 'option' => 'stevia'), array('group' => 'shots', 'option' => 'double'), array('group' => 'sweetness', 'option' => 'medium')),
            'quick_notes' => array('takeaway', 'very_hot'),
        )));
        $this->assertSame(EVC_Prep_Decision::REPLAY, self::precheck($first->fingerprint(), $calls)->decide(self::MEMBER, 'free_coffee', 7, $shuffled)->kind());
    }

    /** @return array<string,array{array}> */
    public static function different_payloads(): array {
        return array(
            'other option' => array(array('preparation' => array('modifiers' => array(array('group' => 'shots', 'option' => 'single'), array('group' => 'sweetness', 'option' => 'medium'), array('group' => 'sweetener', 'option' => 'stevia')), 'quick_notes' => array('very_hot', 'takeaway')))),
            'quick note removed' => array(array('preparation' => array('modifiers' => array(array('group' => 'shots', 'option' => 'double'), array('group' => 'sweetness', 'option' => 'medium'), array('group' => 'sweetener', 'option' => 'stevia')), 'quick_notes' => array('very_hot')))),
            'other temperature note' => array(array('preparation' => array('modifiers' => array(array('group' => 'shots', 'option' => 'double'), array('group' => 'sweetness', 'option' => 'medium'), array('group' => 'sweetener', 'option' => 'stevia')), 'quick_notes' => array('lukewarm', 'takeaway')))),
            'optional modifier added' => array(array('preparation' => array('modifiers' => array(array('group' => 'shots', 'option' => 'double'), array('group' => 'sweetness', 'option' => 'medium'), array('group' => 'sweetener', 'option' => 'stevia'), array('group' => 'caffeine', 'option' => 'decaf')), 'quick_notes' => array('very_hot', 'takeaway')))),
            'other coffee' => array(array('coffee_code' => 'greek_coffee')),
            'other catalogue version' => array(array('catalogue_version' => 2)),
        );
    }

    /** @dataProvider different_payloads */
    public function test_same_request_id_with_a_different_preparation_is_a_conflict(array $override): void {
        $calls = array();
        $first = self::precheck(null, $calls)->decide(self::MEMBER, 'free_coffee', 7, self::body());
        $d = self::precheck($first->fingerprint(), $calls)->decide(self::MEMBER, 'free_coffee', 7, self::body($override));
        $this->assertSame(EVC_Prep_Decision::IDEMPOTENCY_CONFLICT, $d->kind());
        $this->assertNull($d->order(), 'a conflict never exposes another request\'s data');
    }

    public function test_same_request_id_for_another_member_or_actor_is_a_conflict(): void {
        $calls = array();
        $first = self::precheck(null, $calls)->decide(self::MEMBER, 'free_coffee', 7, self::body());
        $this->assertSame(EVC_Prep_Decision::IDEMPOTENCY_CONFLICT, self::precheck($first->fingerprint(), $calls)->decide('mem_ffffffffffffffffffffffffffffffff', 'free_coffee', 7, self::body())->kind());
        $this->assertSame(EVC_Prep_Decision::IDEMPOTENCY_CONFLICT, self::precheck($first->fingerprint(), $calls)->decide(self::MEMBER, 'free_coffee', 8, self::body())->kind());
    }

    public function test_a_stored_v1_fingerprint_never_replays_a_v2_request(): void {
        $calls = array();
        $v1 = EVC_Request_Fingerprint::compute(self::MEMBER, 'free_coffee', 'espresso', 7);
        $this->assertSame(EVC_Prep_Decision::IDEMPOTENCY_CONFLICT, self::precheck($v1, $calls)->decide(self::MEMBER, 'free_coffee', 7, self::body())->kind());
    }

    public function test_uncommitted_request_with_old_catalogue_version_is_stale(): void {
        $v2 = self::catalogue_data();
        $v2['catalogue_version'] = 2;
        $calls = array();
        $d = self::precheck(null, $calls, $v2)->decide(self::MEMBER, 'free_coffee', 7, self::body());
        $this->assertSame(EVC_Prep_Decision::CATALOGUE_VERSION_STALE, $d->kind());
        $this->assertSame(2, $d->active_version());
        $this->assertSame(array(self::REQUEST_ID), $calls, 'the replay lookup happens before the version check');
    }

    public function test_semantic_errors_are_reported_only_after_the_replay_lookup(): void {
        $calls = array();
        $d = self::precheck(null, $calls)->decide(self::MEMBER, 'free_coffee', 7, self::body(array('preparation' => array(
            'modifiers' => array(array('group' => 'shots', 'option' => 'double'), array('group' => 'sweetness', 'option' => 'none'), array('group' => 'sweetener', 'option' => 'stevia')),
            'quick_notes' => array(),
        ))));
        $this->assertSame(EVC_Prep_Decision::INVALID_PREPARATION, $d->kind());
        $this->assertSame('hidden_group_present', $d->reason());
        $this->assertCount(1, $calls);
    }

    /** @return array<string,array{mixed,mixed,mixed,array}> */
    public static function malformed_requests(): array {
        return array(
            'hyphenated coffee code' => array(self::MEMBER, 'free_coffee', 7, self::body(array('coffee_code' => 'freddo-espresso'))),
            'uppercase request id' => array(self::MEMBER, 'free_coffee', 7, self::body(array('request_id' => strtoupper(self::REQUEST_ID)))),
            'version as string' => array(self::MEMBER, 'free_coffee', 7, self::body(array('catalogue_version' => '1'))),
            'version as float' => array(self::MEMBER, 'free_coffee', 7, self::body(array('catalogue_version' => 1.0))),
            'version zero' => array(self::MEMBER, 'free_coffee', 7, self::body(array('catalogue_version' => 0))),
            'unknown body key' => array(self::MEMBER, 'free_coffee', 7, self::body(array('price' => 0))),
            'member id in body is an unknown key' => array(self::MEMBER, 'free_coffee', 7, self::body(array('member_id' => self::MEMBER))),
            'missing preparation' => array(self::MEMBER, 'free_coffee', 7, array('request_id' => self::REQUEST_ID, 'catalogue_version' => 1, 'coffee_code' => 'espresso')),
            'bad member id' => array('mem_X', 'free_coffee', 7, self::body()),
            'other benefit' => array(self::MEMBER, 'free_drink', 7, self::body()),
            'staff id zero' => array(self::MEMBER, 'free_coffee', 0, self::body()),
            'staff id as string' => array(self::MEMBER, 'free_coffee', '7', self::body()),
            'body not an object' => array(self::MEMBER, 'free_coffee', 7, array()),
        );
    }

    /** @dataProvider malformed_requests */
    public function test_malformed_requests_are_rejected_before_any_lookup($member, $benefit, $staff, $body): void {
        $calls = array();
        $d = self::precheck(null, $calls)->decide($member, $benefit, $staff, $body);
        $this->assertSame(EVC_Prep_Decision::INVALID_REQUEST, $d->kind());
        $this->assertSame(array(), $calls);
    }

    /** @return array<string,array{mixed}> */
    public static function free_text_notes(): array {
        return array('text' => array('λίγο πάγο'), 'empty string' => array(''), 'whitespace' => array(' '), 'number' => array(0), 'false' => array(false), 'object' => array(array('text' => 'x')));
    }

    /** @dataProvider free_text_notes */
    public function test_any_free_text_note_is_rejected_before_any_lookup($note): void {
        $calls = array();
        $body = self::body();
        $body['preparation']['note'] = $note;
        $d = self::precheck(null, $calls)->decide(self::MEMBER, 'free_coffee', 7, $body);
        $this->assertSame(EVC_Prep_Decision::INVALID_PREPARATION, $d->kind());
        $this->assertSame('free_text_not_supported', $d->reason());
        $this->assertSame(array(), $calls);
    }

    public function test_null_note_is_tolerated_and_does_not_change_the_fingerprint(): void {
        $calls = array();
        $with = self::body();
        $with['preparation']['note'] = null;
        $a = self::precheck(null, $calls)->decide(self::MEMBER, 'free_coffee', 7, self::body());
        $b = self::precheck(null, $calls)->decide(self::MEMBER, 'free_coffee', 7, $with);
        $this->assertSame(EVC_Prep_Decision::PROCEED, $b->kind());
        $this->assertSame($a->fingerprint(), $b->fingerprint());
    }

    public function test_fingerprint_is_independent_of_the_php_default_timezone_and_date(): void {
        $calls = array();
        $previous = date_default_timezone_get();
        try {
            date_default_timezone_set('Europe/Athens');
            $athens = self::precheck(null, $calls)->decide(self::MEMBER, 'free_coffee', 7, self::body())->fingerprint();
            date_default_timezone_set('UTC');
            $utc = self::precheck(null, $calls)->decide(self::MEMBER, 'free_coffee', 7, self::body())->fingerprint();
        } finally {
            date_default_timezone_set($previous);
        }
        $this->assertSame($athens, $utc);
        $this->assertSame(self::vectors()['fingerprints'][0]['fingerprint_v2'], $utc);
    }
}
