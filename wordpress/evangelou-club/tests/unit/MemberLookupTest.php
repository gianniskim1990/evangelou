<?php
use PHPUnit\Framework\TestCase;

/**
 * UI-04C — QR tokens, secure member lookup and signed lookup-context proof,
 * with pure fakes (token store, phone directory, rate limiter, authorizer,
 * clock). No database, no keys outside the test.
 */
final class MemberLookupTest extends TestCase {
    const A = 'mem_0123456789abcdef0123456789abcdef';
    const B = 'mem_fedcba9876543210fedcba9876543210';
    const C = 'mem_00000000000000000000000000000000';
    const STAFF = 7;
    const ADMIN = 20;
    const TABLET_1 = '11111111111111111111111111111111';
    const TABLET_2 = '22222222222222222222222222222222';
    const PURPOSE = 'member_view';

    /** @var EVC_Fake_Qr_Token_Store */
    private $store;
    /** @var EVC_Fake_Member_Phone_Directory */
    private $phones;
    /** @var EVC_Fake_Club_Staff_Authorizer */
    private $authorizer;
    /** @var EVC_Fake_Lookup_Rate_Limiter */
    private $limiter;
    /** @var EVC_Lookup_Key_Ring */
    private $keys;
    /** @var EVC_Fixed_Clock */
    private $clock;
    /** @var array[] */
    private $reports = array();
    /** @var array<string,int> */
    private $ids = array();

    protected function setUp(): void {
        $this->store = new EVC_Fake_Qr_Token_Store();
        $this->ids['A'] = $this->store->add_member(self::A, 501);
        $this->ids['B'] = $this->store->add_member(self::B, 502);
        $this->ids['C'] = $this->store->add_member(self::C, 503, 'disabled');
        $this->phones = new EVC_Fake_Member_Phone_Directory();
        $this->authorizer = new EVC_Fake_Club_Staff_Authorizer();
        $this->authorizer->grants[self::STAFF] = array(EVC_Member_Lookup_Service::CAPABILITY);
        $this->authorizer->grants[self::ADMIN] = array(EVC_Qr_Token_Service::CAPABILITY_MANAGE);
        $this->limiter = new EVC_Fake_Lookup_Rate_Limiter();
        $this->keys = new EVC_Lookup_Key_Ring(array('k1' => str_repeat("\x01", 32)), 'k1');
        $this->clock = new EVC_Fixed_Clock('2026-10-14T07:30:00Z');
        $this->reports = array();
    }

    // ---- helpers ---------------------------------------------------------------------------

    private static function tablet(int $n = 1): EVC_Staff_Actor {
        return new EVC_Staff_Actor(self::STAFF, $n === 1 ? self::TABLET_1 : self::TABLET_2);
    }

    private function tokens(?callable $mint = null): EVC_Qr_Token_Service {
        $reports = &$this->reports;
        return new EVC_Qr_Token_Service($this->store, $this->authorizer, $this->clock, $mint, function (array $d) use (&$reports) {
            $reports[] = $d;
        });
    }

    private function lookup(?EVC_Qr_Token_Policy $token_policy = null, int $ttl = 300): EVC_Member_Lookup_Service {
        $reports = &$this->reports;
        return new EVC_Member_Lookup_Service(
            $this->store,
            $this->phones,
            $this->authorizer,
            $this->limiter,
            $this->keys,
            new EVC_Lookup_Context_Policy($ttl),
            $token_policy ?? EVC_Qr_Token_Policy::without_expiry(),
            $this->clock,
            self::PURPOSE,
            null,
            function (array $d) use (&$reports) {
                $reports[] = $d;
            }
        );
    }

    private function verifier(int $ttl = 300, ?EVC_Lookup_Key_Ring $keys = null): EVC_Signed_Member_Context_Verifier {
        return new EVC_Signed_Member_Context_Verifier($keys ?? $this->keys, new EVC_Lookup_Context_Policy($ttl), $this->clock, self::PURPOSE);
    }

    private function issue(string $member = self::A, int $wp_user = 501): string {
        $result = $this->tokens()->issue(EVC_Qr_Issuance_Requester::member_self($wp_user), $member);
        $this->assertSame(EVC_Qr_Token_Result::ISSUED, $result->kind());
        return (string) $result->token();
    }

    // ---- tokens ------------------------------------------------------------------------------

    public function test_tokens_are_opaque_high_entropy_and_stored_as_hashes_only(): void {
        $seen = array();
        for ($i = 0; $i < 200; $i++) {
            $t = EVC_Qr_Token::mint();
            $this->assertMatchesRegularExpression('/^evc_[0-9a-f]{48}$/', $t, '192 random bits, no personal data');
            $seen[$t] = true;
        }
        $this->assertCount(200, $seen);

        $token = $this->issue();
        $rows = $this->store->token_rows();
        $this->assertCount(1, $rows);
        $this->assertSame(hash('sha256', $token), $rows[0]['token_hash']);
        $this->assertStringNotContainsString(substr($token, 4), json_encode($rows), 'the plaintext token is never stored');
        $this->assertStringNotContainsString(self::A, $token);
        $this->assertSame(array(), $this->store->violations());
    }

    public function test_first_issuance_reissuance_and_rotation_keep_exactly_one_active_token(): void {
        $first = $this->issue();
        $second = $this->issue();
        $third = $this->tokens()->issue(EVC_Qr_Issuance_Requester::administrator(new EVC_Staff_Actor(self::ADMIN)), self::A);
        $this->assertSame(EVC_Qr_Token_Result::ISSUED, $third->kind());
        $this->assertSame(array(), $this->store->violations());
        $this->assertSame(array('revoked', 'revoked', 'active'), array_column($this->store->token_rows(), 'status'));

        $lookup = $this->lookup();
        foreach (array($first, $second) as $old) {
            $this->assertSame(EVC_Member_Lookup_Result::NOT_FOUND, $lookup->by_qr(self::tablet(), $old)->kind(), 'a rotated token no longer works');
        }
        $this->assertSame(EVC_Member_Lookup_Result::FOUND, $lookup->by_qr(self::tablet(), $third->token())->kind());
    }

    public function test_revocation(): void {
        $token = $this->issue();
        $member = EVC_Qr_Issuance_Requester::member_self(501);
        $this->assertSame(EVC_Qr_Token_Result::REVOKED, $this->tokens()->revoke($member, self::A)->kind());
        $this->assertSame(EVC_Qr_Token_Result::NOTHING_TO_REVOKE, $this->tokens()->revoke($member, self::A)->kind());
        $this->assertSame(EVC_Member_Lookup_Result::NOT_FOUND, $this->lookup()->by_qr(self::tablet(), $token)->kind());
        $this->assertNull($this->tokens()->revoke($member, self::A)->token());
    }

    /** @return array<string,array{EVC_Qr_Issuance_Requester,string,string}> */
    public static function issuance_permissions(): array {
        return array(
            'another member\'s record' => array(EVC_Qr_Issuance_Requester::member_self(501), self::B, EVC_Qr_Token_Result::FORBIDDEN),
            'an unknown member (no existence leak)' => array(EVC_Qr_Issuance_Requester::member_self(501), 'mem_11111111111111111111111111111111', EVC_Qr_Token_Result::FORBIDDEN),
            'the shared staff account' => array(EVC_Qr_Issuance_Requester::administrator(new EVC_Staff_Actor(self::STAFF, self::TABLET_1)), self::A, EVC_Qr_Token_Result::FORBIDDEN),
            'an administrator, unknown member' => array(EVC_Qr_Issuance_Requester::administrator(new EVC_Staff_Actor(self::ADMIN)), 'mem_11111111111111111111111111111111', EVC_Qr_Token_Result::MEMBER_NOT_FOUND),
            'a disabled member' => array(EVC_Qr_Issuance_Requester::member_self(503), self::C, EVC_Qr_Token_Result::MEMBER_DISABLED),
            'malformed member id' => array(EVC_Qr_Issuance_Requester::member_self(501), 'mem_1', EVC_Qr_Token_Result::INVALID_REQUEST),
        );
    }

    /** @dataProvider issuance_permissions */
    public function test_only_the_member_or_an_authorised_administrator_may_issue(EVC_Qr_Issuance_Requester $who, string $member, string $kind): void {
        $result = $this->tokens()->issue($who, $member);
        $this->assertSame($kind, $result->kind());
        $this->assertNull($result->token());
        $this->assertSame(array(), $this->store->token_rows());
        // Revocation follows the same permission rules (a disabled member may still revoke, there is just nothing active).
        $expected = $kind === EVC_Qr_Token_Result::MEMBER_DISABLED ? EVC_Qr_Token_Result::NOTHING_TO_REVOKE : $kind;
        $this->assertSame($expected, $this->tokens()->revoke($who, $member)->kind());
    }

    public function test_an_administrator_is_checked_before_any_read(): void {
        $reads = $this->store->reads;
        $this->tokens()->issue(EVC_Qr_Issuance_Requester::administrator(new EVC_Staff_Actor(8)), self::A);
        $this->assertSame($reads, $this->store->reads);
    }

    public function test_concurrent_rotation_leaves_exactly_one_winner(): void {
        $this->issue();
        $other = null;
        $this->store->before_write = function () use (&$other) {
            $other = $this->tokens()->issue(EVC_Qr_Issuance_Requester::member_self(501), self::A); // second device rotates first
        };
        $loser = $this->tokens()->issue(EVC_Qr_Issuance_Requester::member_self(501), self::A);
        $this->assertSame(EVC_Qr_Token_Result::ISSUED, $other->kind());
        $this->assertSame(EVC_Qr_Token_Result::CONCURRENT_CHANGE, $loser->kind());
        $this->assertNull($loser->token(), 'the losing token never reaches anyone');
        $this->assertSame(array(), $this->store->violations());
        $this->assertSame(EVC_Member_Lookup_Result::FOUND, $this->lookup()->by_qr(self::tablet(), $other->token())->kind());

        // A first issuance racing another first issuance.
        $this->setUp();
        $this->store->before_write = function () use (&$other) {
            $other = $this->tokens()->issue(EVC_Qr_Issuance_Requester::member_self(501), self::A);
        };
        $this->assertSame(EVC_Qr_Token_Result::CONCURRENT_CHANGE, $this->tokens()->issue(EVC_Qr_Issuance_Requester::member_self(501), self::A)->kind());
        $this->assertCount(1, $this->store->token_rows());

        // A revocation racing a rotation.
        $this->setUp();
        $this->issue();
        $this->store->before_write = function () {
            $this->issue();
        };
        $this->assertSame(EVC_Qr_Token_Result::CONCURRENT_CHANGE, $this->tokens()->revoke(EVC_Qr_Issuance_Requester::member_self(501), self::A)->kind());
        $this->assertSame(array(), $this->store->violations());
    }

    public function test_an_uncertain_storage_outcome_never_returns_the_token_and_reissuing_recovers(): void {
        foreach (array('committed', 'not_committed') as $mode) {
            $this->setUp();
            $this->store->commit_unknown = $mode;
            $result = $this->tokens()->issue(EVC_Qr_Issuance_Requester::member_self(501), self::A);
            $this->assertSame(EVC_Qr_Token_Result::OUTCOME_UNKNOWN, $result->kind());
            $this->assertNull($result->token());
            $this->assertSame('qr_token', $this->reports[0]['stage']);
            $recovered = $this->issue();
            $this->assertSame(EVC_Member_Lookup_Result::FOUND, $this->lookup()->by_qr(self::tablet(), $recovered)->kind());
            $this->assertSame(array(), $this->store->violations());
            $this->assertCount(1, array_filter($this->store->token_rows(), function (array $t): bool {
                return $t['status'] === 'active';
            }));
        }
    }

    public function test_a_hash_collision_or_bad_generator_never_issues(): void {
        $this->issue();
        $fixed = 'evc_' . str_repeat('ab', 24);
        $this->assertSame(EVC_Qr_Token_Result::ISSUED, $this->tokens(function () use ($fixed) {
            return $fixed;
        })->issue(EVC_Qr_Issuance_Requester::member_self(502), self::B)->kind());
        $this->assertSame(EVC_Qr_Token_Result::CONCURRENT_CHANGE, $this->tokens(function () use ($fixed) {
            return $fixed;
        })->issue(EVC_Qr_Issuance_Requester::member_self(501), self::A)->kind());
        $this->assertSame(EVC_Qr_Token_Result::SERVER_ERROR, $this->tokens(function () {
            return 'evc_short';
        })->issue(EVC_Qr_Issuance_Requester::member_self(501), self::A)->kind());
        $this->assertSame(array(), $this->store->violations());
    }

    public function test_results_never_carry_a_hash(): void {
        $result = $this->tokens()->issue(EVC_Qr_Issuance_Requester::member_self(501), self::A);
        $hash = hash('sha256', (string) $result->token());
        $this->assertStringNotContainsString($hash, print_r($result, true));
        $found = $this->lookup()->by_qr(self::tablet(), $result->token());
        $this->assertStringNotContainsString($hash, json_encode($found->member()));
        $this->assertSame(array('member_public_id', 'lookup_ref', 'lookup_ref_expires_at_utc'), array_keys($found->member()));
    }

    // ---- QR lookup -----------------------------------------------------------------------------

    public function test_a_qr_lookup_establishes_identity_only(): void {
        $token = $this->issue();
        $result = $this->lookup()->by_qr(self::tablet(), $token);
        $this->assertSame(EVC_Member_Lookup_Result::FOUND, $result->kind());
        $this->assertSame(self::A, $result->member()['member_public_id']);
        $this->assertSame('2026-10-14 07:35:00.000000', $result->member()['lookup_ref_expires_at_utc']);
        $this->assertFalse($result->grants_entitlement());
        foreach (array('status', 'paid', 'payment', 'eligible', 'expires', 'redeem', 'history', 'phone', 'name', 'wp_user') as $absent) {
            foreach (array_keys($result->member()) as $key) {
                $this->assertStringNotContainsString($absent, str_replace('lookup_ref_expires', '', $key));
            }
        }
    }

    /** @return array<string,array{mixed}> */
    public static function unusable_tokens(): array {
        return array(
            'unknown' => array('evc_' . str_repeat('0', 48)),
            'uppercase' => array('evc_' . str_repeat('A', 48)),
            'too short' => array('evc_1234'),
            'url form' => array('https://example.invalid/qr?evc_' . str_repeat('0', 48)),
            'member id instead of a token' => array(self::A),
            'not a string' => array(array('evc_x')),
            'null' => array(null),
        );
    }

    /** @dataProvider unusable_tokens */
    public function test_malformed_and_unknown_tokens_get_the_same_answer($token): void {
        $this->issue();
        $result = $this->lookup()->by_qr(self::tablet(), $token);
        $this->assertSame(EVC_Member_Lookup_Result::NOT_FOUND, $result->kind());
        $this->assertNull($result->member());
        $this->assertSame(1, $this->limiter->failures['qr_lookup|session:' . self::TABLET_1], 'a failure is recorded for abuse limiting');
    }

    public function test_a_disabled_member_or_an_expired_token_is_not_found(): void {
        $token = $this->issue();
        $this->store->set_member_status($this->ids['A'], 'disabled');
        $this->assertSame(EVC_Member_Lookup_Result::NOT_FOUND, $this->lookup()->by_qr(self::tablet(), $token)->kind());
        $this->store->set_member_status($this->ids['A'], 'enabled');

        $this->clock->set('2026-11-13T07:29:59Z');
        $this->assertSame(EVC_Member_Lookup_Result::FOUND, $this->lookup(EVC_Qr_Token_Policy::max_age_days(30))->by_qr(self::tablet(), $token)->kind());
        $this->clock->set('2026-11-13T07:30:00Z');
        $this->assertSame(EVC_Member_Lookup_Result::NOT_FOUND, $this->lookup(EVC_Qr_Token_Policy::max_age_days(30))->by_qr(self::tablet(), $token)->kind());
        $this->assertSame(EVC_Member_Lookup_Result::FOUND, $this->lookup()->by_qr(self::tablet(), $token)->kind(), 'no age limit unless the owner decides one');
    }

    /** @return array<string,array{EVC_Staff_Actor}> */
    public static function unauthorised_actors(): array {
        return array(
            'no capability' => array(new EVC_Staff_Actor(8, self::TABLET_1)),
            'administrator without the lookup capability' => array(new EVC_Staff_Actor(self::ADMIN, self::TABLET_1)),
            'no session binding' => array(new EVC_Staff_Actor(self::STAFF)),
        );
    }

    /** @dataProvider unauthorised_actors */
    public function test_unauthorised_callers_learn_nothing_and_cause_no_read(EVC_Staff_Actor $actor): void {
        $token = $this->issue();
        $this->phones->numbers['+306912345678'] = array($this->ids['A']);
        $reads = $this->store->reads;
        foreach (array($this->lookup()->by_qr($actor, $token), $this->lookup()->by_qr($actor, 'evc_' . str_repeat('0', 48)), $this->lookup()->by_phone($actor, '6912345678'), $this->lookup()->by_phone($actor, '6900000000')) as $r) {
            $this->assertSame(EVC_Member_Lookup_Result::FORBIDDEN, $r->kind());
            $this->assertNull($r->member());
        }
        $this->assertSame($reads, $this->store->reads);
        $this->assertSame(array(), $this->phones->queries);
        $this->assertSame(array(), $this->limiter->seen, 'not even the rate limiter is consulted');
    }

    // ---- phone lookup --------------------------------------------------------------------------

    /** @return array<string,array{mixed,?string}> */
    public static function phones(): array {
        return array(
            'mobile' => array('6912345678', '+306912345678'),
            'spaced' => array('691 234 5678', '+306912345678'),
            'dashed and dotted' => array('691-234.56.78', '+306912345678'),
            '+30' => array('+30 6912345678', '+306912345678'),
            '0030' => array('0030 691 2345678', '+306912345678'),
            'Athens landline' => array('210 1234567', '+302101234567'),
            'parentheses' => array('(210) 123 4567', '+302101234567'),
            'nine digits' => array('691234567', null),
            'eleven digits' => array('69123456789', null),
            'not a Greek mobile or landline' => array('5912345678', null),
            'foreign number' => array('+44 7700 900123', null),
            'letters' => array('69l2345678', null),
            'non-ASCII digits' => array('٦٩١٢٣٤٥٦٧٨', null),
            'too long' => array(str_repeat(' ', 30) . '6912345678', null),
            'not a string' => array(6912345678, null),
        );
    }

    /** @dataProvider phones */
    public function test_phone_canonicalisation($input, ?string $canonical): void {
        $this->assertSame($canonical, EVC_Phone_Canonical::canonical($input));
    }

    public function test_a_unique_phone_finds_one_member(): void {
        $this->phones->numbers['+306912345678'] = array($this->ids['A']);
        $result = $this->lookup()->by_phone(self::tablet(), '691 234 5678');
        $this->assertSame(EVC_Member_Lookup_Result::FOUND, $result->kind());
        $this->assertSame(self::A, $result->member()['member_public_id']);
        $this->assertSame(array('+306912345678'), $this->phones->queries, 'only the canonical number is used');
    }

    public function test_a_shared_phone_is_ambiguous_and_reveals_nobody(): void {
        $this->phones->numbers['+306912345678'] = array($this->ids['A'], $this->ids['B']);
        $result = $this->lookup()->by_phone(self::tablet(), '6912345678');
        $this->assertSame(EVC_Member_Lookup_Result::AMBIGUOUS, $result->kind());
        $this->assertNull($result->member());
        // A disabled member never counts: with B disabled the phone is unique again.
        $this->store->set_member_status($this->ids['B'], 'disabled');
        $this->assertSame(self::A, $this->lookup()->by_phone(self::tablet(), '6912345678')->member()['member_public_id']);
        // Duplicate ids from the directory are one member.
        $this->phones->numbers['+302101234567'] = array($this->ids['A'], $this->ids['A']);
        $this->assertSame(EVC_Member_Lookup_Result::FOUND, $this->lookup()->by_phone(self::tablet(), '2101234567')->kind());
    }

    public function test_unknown_and_disabled_phones_get_the_same_answer_and_invalid_input_reads_nothing(): void {
        $this->phones->numbers['+306900000003'] = array($this->ids['C']);
        $this->assertSame(EVC_Member_Lookup_Result::NOT_FOUND, $this->lookup()->by_phone(self::tablet(), '6900000003')->kind());
        $this->assertSame(EVC_Member_Lookup_Result::NOT_FOUND, $this->lookup()->by_phone(self::tablet(), '6900000099')->kind());
        $queries = count($this->phones->queries);
        $this->assertSame(EVC_Member_Lookup_Result::INVALID_PHONE, $this->lookup()->by_phone(self::tablet(), '12345')->kind());
        $this->assertSame($queries, count($this->phones->queries));
    }

    public function test_phone_numbers_never_reach_logs_or_rate_limit_keys_in_plaintext(): void {
        $this->phones->numbers['+306912345678'] = array($this->ids['A']);
        $this->lookup()->by_phone(self::tablet(), '6912345678');
        $this->lookup()->by_phone(self::tablet(), '6987654321');
        $this->store->unavailable = true;
        $this->lookup()->by_phone(self::tablet(), '6912345678');
        $everything = json_encode(array($this->limiter->seen, $this->limiter->failures, $this->reports));
        foreach (array('6912345678', '912345678', '6987654321', '+30') as $digits) {
            $this->assertStringNotContainsString($digits, $everything);
        }
        $pseudo = EVC_Phone_Canonical::pseudonym($this->keys, '+306912345678');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $pseudo);
        $this->assertContains('phone_lookup|phone:' . $pseudo, $this->limiter->seen);
        $other_keys = new EVC_Lookup_Key_Ring(array('k1' => str_repeat("\x02", 32)), 'k1');
        $this->assertNotSame($pseudo, EVC_Phone_Canonical::pseudonym($other_keys, '+306912345678'), 'keyed, not a plain hash');
        $this->assertSame(array(array('stage' => 'member_lookup', 'exception' => 'EVC_Db_Exception')), $this->reports);
    }

    // ---- abuse limiting ----------------------------------------------------------------------

    public function test_rate_limits_stop_token_guessing_and_phone_enumeration(): void {
        $token = $this->issue();
        $this->limiter->max_failures = 5;
        $lookup = $this->lookup();
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(EVC_Member_Lookup_Result::NOT_FOUND, $lookup->by_qr(self::tablet(), sprintf('evc_%048x', $i))->kind());
        }
        $reads = $this->store->reads;
        $this->assertSame(EVC_Member_Lookup_Result::RATE_LIMITED, $lookup->by_qr(self::tablet(), $token)->kind(), 'even a valid token once the session is over its failures');
        $this->assertSame($reads, $this->store->reads, 'no read when limited');

        // Phone enumeration from the other tablet is limited per session AND per actor.
        $this->limiter->max_failures = 1000;
        $this->limiter->max_attempts = 3;
        $results = array();
        for ($i = 0; $i < 4; $i++) {
            $results[] = $lookup->by_phone(self::tablet(2), sprintf('69000000%02d', $i))->kind();
        }
        $this->assertSame(EVC_Member_Lookup_Result::RATE_LIMITED, end($results));
    }

    public function test_the_shared_account_on_several_tablets_shares_the_actor_limit(): void {
        $this->limiter->max_attempts = 4;
        $lookup = $this->lookup();
        $kinds = array();
        for ($i = 0; $i < 3; $i++) {
            $kinds[] = $lookup->by_phone(self::tablet(1), '6900000001')->kind();
            $kinds[] = $lookup->by_phone(self::tablet(2), '6900000002')->kind();
        }
        $this->assertContains(EVC_Member_Lookup_Result::RATE_LIMITED, $kinds, 'the actor key spans every tablet of the shared account');
        $this->assertContains('phone_lookup|actor:7', $this->limiter->seen);
    }

    public function test_an_unavailable_rate_limiter_fails_closed_without_reading(): void {
        $token = $this->issue();
        $this->limiter->unavailable = true;
        $reads = $this->store->reads;
        $this->assertSame(EVC_Member_Lookup_Result::UNAVAILABLE, $this->lookup()->by_qr(self::tablet(), $token)->kind());
        $this->assertSame(EVC_Member_Lookup_Result::UNAVAILABLE, $this->lookup()->by_phone(self::tablet(), '6912345678')->kind());
        $this->assertSame($reads, $this->store->reads);
        $this->assertSame(array(), $this->phones->queries);
    }

    public function test_an_unavailable_store_fails_closed(): void {
        $token = $this->issue();
        $this->store->unavailable = true;
        $this->assertSame(EVC_Member_Lookup_Result::UNAVAILABLE, $this->lookup()->by_qr(self::tablet(), $token)->kind());
        $this->assertSame(EVC_Qr_Token_Result::SERVER_ERROR, $this->tokens()->issue(EVC_Qr_Issuance_Requester::member_self(501), self::A)->kind());
    }

    // ---- lookup context proof ------------------------------------------------------------------

    public function test_a_lookup_proof_verifies_only_for_the_same_actor_session_member_and_purpose(): void {
        $ref = $this->lookup()->by_qr(self::tablet(), $this->issue())->member()['lookup_ref'];
        $this->assertTrue($this->verifier()->verify(self::tablet(), self::A, $ref));
        $this->assertFalse($this->verifier()->verify(self::tablet(), self::B, $ref), 'member A never authorises member B');
        $this->assertFalse($this->verifier()->verify(self::tablet(2), self::A, $ref), 'another tablet of the shared account does not inherit it');
        $this->assertFalse($this->verifier()->verify(new EVC_Staff_Actor(8, self::TABLET_1), self::A, $ref), 'another staff user');
        $this->assertFalse($this->verifier()->verify(new EVC_Staff_Actor(self::STAFF), self::A, $ref), 'no session');
        $other_purpose = new EVC_Signed_Member_Context_Verifier($this->keys, new EVC_Lookup_Context_Policy(300), $this->clock, 'redemption_history');
        $this->assertFalse($other_purpose->verify(self::tablet(), self::A, $ref), 'scope');
        $this->assertTrue($this->verifier()->verify(self::tablet(), self::A, $ref), 'reusable within its lifetime by the same session');
    }

    public function test_a_lookup_proof_expires_and_cannot_outlive_the_current_policy(): void {
        $ref = $this->lookup(null, 300)->by_qr(self::tablet(), $this->issue())->member()['lookup_ref'];
        $this->clock->set('2026-10-14T07:34:59Z');
        $this->assertTrue($this->verifier(300)->verify(self::tablet(), self::A, $ref));
        $this->clock->set('2026-10-14T07:35:00Z');
        $this->assertFalse($this->verifier(300)->verify(self::tablet(), self::A, $ref), 'expiry is exclusive');
        $this->clock->set('2026-10-14T07:31:00Z');
        $this->assertFalse($this->verifier(120)->verify(self::tablet(), self::A, $ref), 'a policy shortened later also shortens existing proofs');
        $this->clock->set('2026-10-14T07:29:59Z');
        $this->assertFalse($this->verifier(300)->verify(self::tablet(), self::A, $ref), 'not before its issue time');
    }

    /** @return array<string,array{callable}> */
    public static function forgeries(): array {
        $b64 = function (string $raw): string {
            return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
        };
        return array(
            'payload swapped to another member' => array(function (string $ref) use ($b64) {
                $p = explode('.', $ref);
                $payload = json_decode(base64_decode(strtr($p[2], '-_', '+/')), true);
                $payload['member'] = MemberLookupTest::B;
                $p[2] = $b64(json_encode($payload));
                return implode('.', $p);
            }),
            'expiry extended' => array(function (string $ref) use ($b64) {
                $p = explode('.', $ref);
                $payload = json_decode(base64_decode(strtr($p[2], '-_', '+/')), true);
                $payload['exp'] += 86400;
                $p[2] = $b64(json_encode($payload));
                return implode('.', $p);
            }),
            'MAC flipped' => array(function (string $ref) {
                return substr($ref, 0, -1) . (substr($ref, -1) === 'A' ? 'B' : 'A');
            }),
            'MAC removed' => array(function (string $ref) {
                return substr($ref, 0, strrpos($ref, '.'));
            }),
            'unknown key id' => array(function (string $ref) {
                return str_replace('lkp1.k1.', 'lkp1.k9.', $ref);
            }),
            'other prefix' => array(function (string $ref) {
                return 'lkp2' . substr($ref, 4);
            }),
            'trailing data' => array(function (string $ref) {
                return $ref . '.x';
            }),
            'oversized' => array(function (string $ref) {
                return $ref . str_repeat('A', 600);
            }),
            'empty' => array(function (string $ref) {
                return '';
            }),
            'not a string' => array(function (string $ref) {
                return array($ref);
            }),
        );
    }

    /** @dataProvider forgeries */
    public function test_forged_or_altered_proofs_are_rejected(callable $forge): void {
        $ref = $this->lookup()->by_qr(self::tablet(), $this->issue())->member()['lookup_ref'];
        $this->assertFalse($this->verifier()->verify(self::tablet(), self::A, $forge($ref)));
        $this->assertFalse($this->verifier()->verify(self::tablet(), self::B, $forge($ref)));
    }

    public function test_a_proof_signed_with_another_key_is_rejected_and_old_keys_still_verify(): void {
        $ref = $this->lookup()->by_qr(self::tablet(), $this->issue())->member()['lookup_ref'];
        $stranger = new EVC_Lookup_Key_Ring(array('k1' => str_repeat("\x09", 32)), 'k1');
        $this->assertFalse($this->verifier(300, $stranger)->verify(self::tablet(), self::A, $ref), 'same key id, different secret');
        $rotated = new EVC_Lookup_Key_Ring(array('k1' => str_repeat("\x01", 32), 'k2' => str_repeat("\x03", 32)), 'k2');
        $this->assertTrue($this->verifier(300, $rotated)->verify(self::tablet(), self::A, $ref), 'a previous key still verifies after rotation');
        $retired = new EVC_Lookup_Key_Ring(array('k2' => str_repeat("\x03", 32)), 'k2');
        $this->assertFalse($this->verifier(300, $retired)->verify(self::tablet(), self::A, $ref), 'a removed key no longer verifies');
    }

    public function test_key_ring_and_policies_refuse_weak_or_missing_configuration(): void {
        $bad = array(
            function () {
                return new EVC_Lookup_Key_Ring(array('k1' => str_repeat('x', 31)), 'k1');
            },
            function () {
                return new EVC_Lookup_Key_Ring(array('k1' => str_repeat('x', 32)), 'k2');
            },
            function () {
                return new EVC_Lookup_Key_Ring(array('K-1' => str_repeat('x', 32)), 'K-1');
            },
            function () {
                return new EVC_Lookup_Context_Policy(0);
            },
            function () {
                return new EVC_Lookup_Context_Policy(3601);
            },
            function () {
                return EVC_Qr_Token_Policy::max_age_days(0);
            },
            function () {
                return new EVC_Signed_Member_Context_Verifier($this->keys, new EVC_Lookup_Context_Policy(60), $this->clock, 'Bad Purpose');
            },
        );
        foreach ($bad as $i => $make) {
            try {
                $make();
                $this->fail('accepted configuration #' . $i);
            } catch (InvalidArgumentException $e) {
                $this->assertStringStartsWith('Invalid', $e->getMessage());
            }
        }
        $this->assertStringNotContainsString("\x01\x01\x01", print_r($this->keys, true), 'secrets never dumped');
    }

    public function test_the_proof_satisfies_the_usual_coffee_verifier_contract_end_to_end(): void {
        // A real member history, read only for the member this tablet looked up.
        $redemptions = new EVC_In_Memory_Prepared_Redemption_Store();
        $redemptions->add_member(self::A, 501);
        $redemptions->add_member(self::B, 502);
        $authority = new EVC_Fake_Prep_Catalogue_Authority(json_decode((string) file_get_contents(dirname(__DIR__) . '/fixtures/preparation/catalogue-illustrative.json'), true, 512, JSON_THROW_ON_ERROR));
        $membership = new EVC_Mock_Membership_Adapter();
        $membership->set(501, EVC_Mock_Membership_Adapter::active_until('2030-01-01T00:00:00Z'));
        $membership->set(502, EVC_Mock_Membership_Adapter::active_until('2030-01-01T00:00:00Z'));
        foreach (array(self::A, self::B) as $m => $member) {
            for ($d = 1; $d <= 3; $d++) {
                $this->clock->set(sprintf('2026-10-%02dT08:00:00Z', 10 + $d));
                (new EVC_Prepared_Redemption_Service($redemptions, $authority, $membership, $this->clock, EVC_Prepared_Redemption_Service::ALLOW_ILLUSTRATIVE_CATALOGUE_FOR_TESTS))->redeem($member, 'free_coffee', new EVC_Staff_Actor(self::STAFF), array(
                    'request_id' => sprintf('00000000-0000-4000-8000-%012x', $m * 10 + $d),
                    'catalogue_version' => 1,
                    'coffee_code' => 'espresso',
                    'preparation' => array('modifiers' => array(array('group' => 'shots', 'option' => 'double'), array('group' => 'sweetness', 'option' => 'none')), 'quick_notes' => array()),
                ));
            }
        }
        $this->clock->set('2026-10-14T07:30:00Z');
        $ref = $this->lookup()->by_qr(self::tablet(), $this->issue())->member()['lookup_ref'];
        $this->authorizer->grants[self::STAFF][] = EVC_Usual_Coffee_Service::CAPABILITY;
        $usual = new EVC_Usual_Coffee_Service(new EVC_Fake_Redemption_History($redemptions), $authority, $this->authorizer, $this->verifier(), $this->clock, EVC_Usual_Coffee_Policy::provisional(), EVC_Usual_Coffee_Service::ALLOW_ILLUSTRATIVE_CATALOGUE_FOR_TESTS);
        $this->assertSame(EVC_Usual_Coffee_Result::SUGGESTED, $usual->suggest(self::tablet(), self::A, $ref)->kind());
        $this->assertSame(EVC_Usual_Coffee_Result::FORBIDDEN, $usual->suggest(self::tablet(), self::B, $ref)->kind(), 'A\'s lookup never opens B\'s history');
        $this->assertSame(EVC_Usual_Coffee_Result::FORBIDDEN, $usual->suggest(self::tablet(2), self::A, $ref)->kind(), 'another tablet of the shared account');
    }

    // ---- identity is not eligibility ------------------------------------------------------------

    public function test_a_lookup_never_redeems_and_never_bypasses_the_daily_rule(): void {
        $token = $this->issue();
        $redemptions = new EVC_In_Memory_Prepared_Redemption_Store();
        $redemptions->add_member(self::A, 501);
        $authority = new EVC_Fake_Prep_Catalogue_Authority(json_decode((string) file_get_contents(dirname(__DIR__) . '/fixtures/preparation/catalogue-illustrative.json'), true, 512, JSON_THROW_ON_ERROR));
        $membership = new EVC_Mock_Membership_Adapter(); // no verified payment at all
        $engine = new EVC_Prepared_Redemption_Service($redemptions, $authority, $membership, $this->clock, EVC_Prepared_Redemption_Service::ALLOW_ILLUSTRATIVE_CATALOGUE_FOR_TESTS);
        $body = function (string $rid): array {
            return array('request_id' => $rid, 'catalogue_version' => 1, 'coffee_code' => 'espresso', 'preparation' => array('modifiers' => array(array('group' => 'shots', 'option' => 'double'), array('group' => 'sweetness', 'option' => 'none')), 'quick_notes' => array()));
        };

        $this->assertSame(EVC_Member_Lookup_Result::FOUND, $this->lookup()->by_qr(self::tablet(), $token)->kind(), 'identity is found');
        $this->assertSame(EVC_Prepared_Redemption_Outcome::MEMBERSHIP_INACTIVE, $engine->redeem(self::A, 'free_coffee', self::tablet(), $body('00000000-0000-4000-8000-000000000001'))->kind(), 'but no payment, no coffee');

        $membership->set(501, EVC_Mock_Membership_Adapter::active_until('2030-01-01T00:00:00Z'));
        $this->assertSame(EVC_Prepared_Redemption_Outcome::REDEEMED, $engine->redeem(self::A, 'free_coffee', self::tablet(), $body('00000000-0000-4000-8000-000000000002'))->kind());
        $ledger_before = $redemptions->ledger_rows();
        $again = $this->lookup()->by_qr(self::tablet(), $token);
        $this->assertSame(EVC_Member_Lookup_Result::FOUND, $again->kind(), 'a QR scan after today\'s coffee still identifies the member');
        $this->assertSame($ledger_before, $redemptions->ledger_rows(), 'and consumes nothing');
        $this->assertSame(EVC_Prepared_Redemption_Outcome::ALREADY_REDEEMED, $engine->redeem(self::A, 'free_coffee', self::tablet(), $body('00000000-0000-4000-8000-000000000003'))->kind());
    }

    public function test_the_lookup_capability_matches_the_staff_role(): void {
        $role = (string) file_get_contents(dirname(__DIR__, 2) . '/includes/staff/class-evc-staff-role.php');
        $this->assertStringContainsString("const CAP_LOOKUP = '" . EVC_Member_Lookup_Service::CAPABILITY . "';", $role);
        $this->assertStringContainsString("const CAP_MANAGE = '" . EVC_Qr_Token_Service::CAPABILITY_MANAGE . "';", $role);
        $this->assertStringContainsString('return array(self::CAP_LOOKUP, self::CAP_REDEEM, self::CAP_HISTORY);', $role, 'the shared staff role cannot manage tokens');
    }
}
