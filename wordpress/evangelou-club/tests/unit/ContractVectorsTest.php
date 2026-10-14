<?php
use PHPUnit\Framework\TestCase;

/**
 * UI-04G — pure REST response contracts. The committed cross-language
 * fixture must be exactly what the real mappers produce today (drift fails
 * here; the TypeScript parsers consume the same file), every vector's
 * recommended status must come from EVC_Contract_Http, and contradictory
 * outcomes must fail closed (server_error + the ORIGINAL id for retry).
 */
final class ContractVectorsTest extends TestCase {
    const RID = '00000000-0000-4000-8000-0000000000aa';
    const HID = '00000000-0000-4000-8000-0000000000bb';
    const OTHER = '6f9619ff-8b86-4011-b42d-00c04fc964ff';
    const MEMBER = 'mem_0123456789abcdef0123456789abcdef';

    private function fixture_path(): string {
        return dirname(__DIR__) . '/fixtures/contract/vectors.json';
    }

    /** @return array<int,array> */
    private function vectors(): array {
        $data = json_decode((string) file_get_contents($this->fixture_path()), true);
        $this->assertIsArray($data);
        return $data['vectors'];
    }

    private function clock(string $instant = '2026-10-14T07:40:00Z'): EVC_Fixed_Clock {
        return new EVC_Fixed_Clock($instant);
    }

    private function view(array $overrides = array()): array {
        return array_merge(array(
            'request_id' => self::RID,
            'member_public_id' => self::MEMBER,
            'business_date' => '2026-10-14',
            'redeemed_at_utc' => '2026-10-14 07:30:00.000000',
        ), $overrides);
    }

    /** Builds an outcome whose own facts disagree (impossible through the factories). */
    private function forged_outcome(string $kind, ?array $redemption, ?string $fulfillment, string $instruction, ?array $handoff): EVC_Prepared_Redemption_Outcome {
        $make = function () use ($kind, $redemption, $fulfillment, $instruction, $handoff) {
            return new self($kind, $redemption, array(), null, $fulfillment, $instruction, $handoff);
        };
        return Closure::bind($make, null, EVC_Prepared_Redemption_Outcome::class)();
    }

    private function handoff_view(string $hid, string $claim = 'handed_now'): array {
        $at = '2026-10-14 07:35:00.000000';
        return array(
            'handoff_request_id' => $hid,
            'redemption_request_id' => self::RID,
            'claim' => $claim,
            'confirmation_recorded_at_utc' => $at,
            'handoff_at_utc' => $claim === 'handed_now' ? $at : null,
            'handoff_time' => $claim === 'handed_now' ? 'stated_at_recording' : 'not_recorded',
        );
    }

    private function assertUnresolvedServerError(array $body): void {
        $this->assertSame('server_error', $body['outcome']);
        $this->assertSame(self::RID, $body['retry_request_id']);
        $this->assertSame(self::RID, $body['request_id']);
        $this->assertSame('do_not_prepare', $body['instruction']);
        $this->assertNull($body['entitlement_consumed']);
        $this->assertNull($body['redemption']);
        $this->assertSame(503, EVC_Contract_Http::status('redemption', $body));
    }

    public function test_committed_fixture_matches_the_real_mappers(): void {
        $this->assertSame(
            EVC_Contract_Vector_Builder::encode(),
            str_replace("\r\n", "\n", (string) file_get_contents($this->fixture_path())),
            'Regenerate with: php tests/bin/write-contract-vectors.php'
        );
    }

    public function test_every_vector_carries_the_recommended_status_and_a_domain_shape(): void {
        $vectors = $this->vectors();
        $this->assertGreaterThanOrEqual(59, count($vectors));
        foreach ($vectors as $v) {
            if ($v['expect']['kind'] === 'invalid') {
                continue;
            }
            $this->assertSame(EVC_Contract_Http::status($v['surface'], $v['body']), $v['http_status'], $v['name']);
            $this->assertTrue(EVC_Contract_Envelope_Rule::is_domain_body($v['body']), $v['name']);
        }
    }

    public function test_status_map_distinguishes_failure_classes(): void {
        $r = function (string $outcome): int {
            return EVC_Contract_Http::status('redemption', array('outcome' => $outcome));
        };
        $this->assertSame(201, $r('redeemed'));
        $this->assertSame(200, $r('replayed'));
        $this->assertSame(400, $r('invalid_coffee'));
        $this->assertSame(404, $r('member_not_found'));
        $this->assertSame(409, $r('benefit_already_redeemed'));
        $this->assertSame(409, $r('membership_inactive'));
        $this->assertSame(409, $r('idempotency_key_reused'));
        $this->assertSame(500, $r('record_integrity_failure'));
        // An uncertain commit is never a definite failure.
        $this->assertSame(503, $r('outcome_unknown'));
        $this->assertSame(503, $r('server_error'));
        $this->assertSame(500, $r('something_new'));
        $this->assertSame(500, EVC_Contract_Http::status('nope', array('outcome' => 'redeemed')));
        $this->assertSame(500, EVC_Contract_Http::status('redemption', array()));
        $this->assertSame(403, EVC_Contract_Http::status('handoff', array('outcome' => 'forbidden')));
        $this->assertSame(503, EVC_Contract_Http::status('handoff', array('outcome' => 'outcome_unknown')));
        $this->assertSame(429, EVC_Contract_Http::status('lookup', array('result' => 'rate_limited')));
        $this->assertSame(403, EVC_Contract_Http::status('lookup', array('result' => 'forbidden')));
        $this->assertSame(503, EVC_Contract_Http::status('lookup', array('result' => 'unavailable')));
        $this->assertSame(404, EVC_Contract_Http::status('status', array('result' => 'not_found')));
        $this->assertSame(200, EVC_Contract_Http::status('usual', array('result' => 'no_suggestion')));
    }

    public function test_v1_error_envelope_is_never_a_domain_body(): void {
        $this->assertFalse(EVC_Contract_Envelope_Rule::is_domain_body(array('error' => array('code' => 'forbidden', 'message' => 'x'))));
        $this->assertFalse(EVC_Contract_Envelope_Rule::is_domain_body(array('result' => 'found', 'data' => null, 'error' => array())));
        $this->assertFalse(EVC_Contract_Envelope_Rule::is_domain_body(array('result' => 'found', 'data' => null, 'extra' => 1)));
        $this->assertFalse(EVC_Contract_Envelope_Rule::is_domain_body(array('code' => 'rest_no_route')));
        $this->assertFalse(EVC_Contract_Envelope_Rule::is_domain_body('found'));
        $this->assertTrue(EVC_Contract_Envelope_Rule::is_domain_body(array('member' => null, 'result' => 'not_found')));
    }

    public function test_fresh_commit_uses_the_record_date_and_echoes_only_a_uuid(): void {
        $body = EVC_Redemption_Response::payload(EVC_Prepared_Redemption_Outcome::redeemed($this->view()), self::RID, $this->clock());
        $this->assertSame('redeemed', $body['outcome']);
        $this->assertSame('prepare', $body['instruction']);
        $this->assertSame('2026-10-14', $body['server_business_date']);
        $this->assertSame(self::MEMBER, $body['redemption']['member_id']);
        $this->assertArrayNotHasKey('retry_request_id', $body);

        $refused = EVC_Redemption_Response::payload(EVC_Prepared_Redemption_Outcome::failure('invalid_request'), "<script>", $this->clock());
        $this->assertNull($refused['request_id']);
    }

    public function test_fresh_commit_answered_after_athens_midnight_keeps_the_commit_date(): void {
        // Committed at 23:59:59 Athens; the response is built after midnight.
        $view = $this->view(array('redeemed_at_utc' => '2026-10-14 20:59:59.999999'));
        $body = EVC_Redemption_Response::payload(EVC_Prepared_Redemption_Outcome::redeemed($view), self::RID, $this->clock('2026-10-14T21:00:01Z'));
        $this->assertSame('redeemed', $body['outcome']);
        $this->assertSame('2026-10-14', $body['server_business_date']);
    }

    public function test_redeemed_with_an_inconsistent_record_fails_closed(): void {
        foreach (array(
            $this->view(array('request_id' => self::OTHER)),
            $this->view(array('member_public_id' => 'mem_X')),
            $this->view(array('redeemed_at_utc' => '2026-10-14 07:30:00')),
            $this->view(array('business_date' => '2026-10-13')),
            array('request_id' => self::RID),
        ) as $view) {
            $this->assertUnresolvedServerError(EVC_Redemption_Response::payload(EVC_Prepared_Redemption_Outcome::redeemed($view), self::RID, $this->clock()));
        }
        // A non-UUID sent id can never be matched to a record.
        $body = EVC_Redemption_Response::payload(EVC_Prepared_Redemption_Outcome::redeemed($this->view()), 'not-a-uuid', $this->clock());
        $this->assertSame('server_error', $body['outcome']);
        $this->assertNull($body['retry_request_id']);
    }

    public function test_contradictory_outcomes_fail_closed(): void {
        $hv = $this->handoff_view(self::HID);
        $cases = array(
            // redeemed carrying a handoff / a non-prepare instruction
            $this->forged_outcome('redeemed', $this->view(), 'unconfirmed', 'prepare', $hv),
            $this->forged_outcome('redeemed', $this->view(), 'unconfirmed', 'verify_handoff', null),
            $this->forged_outcome('redeemed', $this->view(), 'confirmed', 'prepare', null),
            // replay that says prepare
            $this->forged_outcome('replayed', $this->view(), 'unconfirmed', 'prepare', null),
            // replay: fulfillment contradicts the handoff
            $this->forged_outcome('replayed', $this->view(), 'confirmed', 'do_not_prepare', null),
            $this->forged_outcome('replayed', $this->view(), 'unconfirmed', 'verify_handoff', $hv),
            // same-day instruction contradicts the handoff
            $this->forged_outcome('replayed', $this->view(), 'confirmed', 'verify_handoff', $hv),
            $this->forged_outcome('replayed', $this->view(), 'unconfirmed', 'do_not_prepare', null),
            // fulfillment flag contradicts a recorded handoff (instruction alone agrees)
            $this->forged_outcome('replayed', $this->view(), 'unconfirmed', 'do_not_prepare', $hv),
        );
        foreach ($cases as $i => $o) {
            $this->assertUnresolvedServerError(EVC_Redemption_Response::payload($o, self::RID, $this->clock()));
        }
        // "earlier day" while the server clock is still on the record's day.
        $earlier = EVC_Prepared_Redemption_Outcome::replayed($this->view(), false, null);
        $this->assertUnresolvedServerError(EVC_Redemption_Response::payload($earlier, self::RID, $this->clock()));
        $later = EVC_Redemption_Response::payload($earlier, self::RID, $this->clock('2026-10-15T07:40:00Z'));
        $this->assertSame('replayed', $later['outcome']);
        $this->assertSame('do_not_prepare_for_today', $later['instruction']);
        $this->assertSame('2026-10-15', $later['server_business_date']);
    }

    public function test_uncertain_outcomes_keep_the_original_id(): void {
        $u = EVC_Redemption_Response::payload(EVC_Prepared_Redemption_Outcome::unresolved('outcome_unknown', self::RID), self::RID, $this->clock());
        $this->assertSame('outcome_unknown', $u['outcome']);
        $this->assertSame(self::RID, $u['retry_request_id']);
        $this->assertSame('2026-10-14', $u['server_business_date']);
        // The retry id is the SENT id, even when the outcome names another one.
        $other = EVC_Redemption_Response::payload(EVC_Prepared_Redemption_Outcome::unresolved('outcome_unknown', self::OTHER), self::RID, $this->clock());
        $this->assertSame(self::RID, $other['retry_request_id']);

        $h = EVC_Handoff_Response::payload(EVC_Handoff_Outcome::unresolved('outcome_unknown', self::HID), self::HID);
        $this->assertSame(array('outcome' => 'outcome_unknown', 'handoff_request_id' => self::HID, 'fulfillment' => null, 'handoff' => null, 'retry_handoff_request_id' => self::HID), $h);
    }

    public function test_refusals_are_minimal(): void {
        foreach (EVC_Redemption_Response::REFUSALS as $kind) {
            $body = EVC_Redemption_Response::payload(EVC_Prepared_Redemption_Outcome::failure($kind, array('member' => self::MEMBER, 'phone' => '+306900000000')), self::RID, $this->clock());
            $this->assertSame(array('outcome', 'request_id', 'recording', 'fulfillment', 'instruction', 'entitlement_consumed', 'server_business_date', 'redemption', 'handoff'), array_keys($body));
            $this->assertFalse($body['entitlement_consumed']);
            $this->assertSame('do_not_prepare', $body['instruction']);
            $json = json_encode($body);
            $this->assertStringNotContainsString('mem_', $json);
            $this->assertStringNotContainsString('+30', $json);
        }
        $integrity = EVC_Redemption_Response::payload(EVC_Prepared_Redemption_Outcome::failure('record_integrity_failure'), self::RID, $this->clock());
        $this->assertNull($integrity['entitlement_consumed']);
        $this->assertSame(500, EVC_Contract_Http::status('redemption', $integrity));
    }

    public function test_handoff_mapper_never_fabricates_a_time_and_checks_ownership(): void {
        $ours = $this->handoff_view(self::HID);
        $this->assertSame('handoff_confirmed', EVC_Handoff_Response::payload(EVC_Handoff_Outcome::recorded('handoff_confirmed', $ours), self::HID)['outcome']);
        // confirmed carrying another statement / already_confirmed carrying ours
        $this->assertSame('server_error', EVC_Handoff_Response::payload(EVC_Handoff_Outcome::recorded('handoff_confirmed', $this->handoff_view(self::OTHER)), self::HID)['outcome']);
        $this->assertSame('server_error', EVC_Handoff_Response::payload(EVC_Handoff_Outcome::recorded('handoff_already_confirmed', $ours), self::HID)['outcome']);
        $this->assertSame('handoff_already_confirmed', EVC_Handoff_Response::payload(EVC_Handoff_Outcome::recorded('handoff_already_confirmed', $this->handoff_view(self::OTHER)), self::HID)['outcome']);
        // An earlier claim with an invented time.
        $invented = $this->handoff_view(self::HID, 'already_handed_earlier');
        $invented['handoff_at_utc'] = $invented['confirmation_recorded_at_utc'];
        $this->assertSame('server_error', EVC_Handoff_Response::payload(EVC_Handoff_Outcome::recorded('handoff_replayed', $invented), self::HID)['outcome']);
        // handed_now whose time differs from the recording time.
        $skewed = $ours;
        $skewed['handoff_at_utc'] = '2026-10-14 07:00:00.000000';
        $this->assertSame('server_error', EVC_Handoff_Response::payload(EVC_Handoff_Outcome::recorded('handoff_replayed', $skewed), self::HID)['outcome']);
        $extra = $ours + array('staff_wp_user_id' => 7);
        $this->assertSame('server_error', EVC_Handoff_Response::payload(EVC_Handoff_Outcome::recorded('handoff_replayed', $extra), self::HID)['outcome']);
        $refused = EVC_Handoff_Response::payload(EVC_Handoff_Outcome::failure('forbidden'), self::HID);
        $this->assertSame(array('outcome' => 'forbidden', 'handoff_request_id' => self::HID, 'fulfillment' => null, 'handoff' => null), $refused);
    }

    public function test_status_lookup_and_usual_mappers_fail_closed(): void {
        $data = array(
            'redemption_request_id' => self::RID, 'business_date' => '2026-10-14', 'redeemed_at_utc' => '2026-10-14 07:30:00.000000',
            'is_current_business_date' => true, 'coffee_label' => 'Espresso', 'modifiers' => array(), 'quick_notes' => array(),
            'fulfillment' => 'unconfirmed', 'handoff' => null,
        );
        $ok = EVC_Status_Response::payload(new EVC_Fulfillment_Read_Result('found', $data));
        $this->assertSame('{}', json_encode($ok['data']['modifiers']));
        $bad = array_merge($data, array('fulfillment' => 'confirmed'));
        $this->assertSame(array('result' => 'server_error', 'data' => null), EVC_Status_Response::payload(new EVC_Fulfillment_Read_Result('found', $bad)));
        $bad = array_merge($data, array('business_date' => '2026-10-13'));
        $this->assertSame('server_error', EVC_Status_Response::payload(new EVC_Fulfillment_Read_Result('found', $bad))['result']);
        $this->assertSame('server_error', EVC_Status_Response::payload(new EVC_Fulfillment_Read_Result('found', $data + array('staff_wp_user_id' => 7)))['result']);

        $leak = EVC_Member_Lookup_Result::found(array('member_public_id' => self::MEMBER, 'lookup_ref' => 'x', 'lookup_ref_expires_at_utc' => '2026-10-14 07:35:00.000000', 'phone' => '+306900000000'));
        $this->assertSame(array('result' => 'unavailable', 'member' => null), EVC_Lookup_Response::payload($leak));
        $bad_expiry = EVC_Member_Lookup_Result::found(array('member_public_id' => self::MEMBER, 'lookup_ref' => 'x', 'lookup_ref_expires_at_utc' => '2026-10-14T07:35:00Z'));
        $this->assertSame('unavailable', EVC_Lookup_Response::payload($bad_expiry)['result']);
        $this->assertSame(array('result' => 'rate_limited', 'member' => null), EVC_Lookup_Response::payload(EVC_Member_Lookup_Result::of('rate_limited')));

        $usual = EVC_Usual_Coffee_Response::payload(EVC_Usual_Coffee_Result::suggested(array('coffee_code' => 'espresso')));
        $this->assertSame(array('result' => 'server_error', 'reason' => null, 'suggestion' => null, 'confirmed_order' => false), $usual);
        $none = EVC_Usual_Coffee_Response::payload(EVC_Usual_Coffee_Result::none('insufficient_history'));
        $this->assertSame('insufficient_history', $none['reason']);
        $this->assertFalse($none['confirmed_order']);
    }
}
