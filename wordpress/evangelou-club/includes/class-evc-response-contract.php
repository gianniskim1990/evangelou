<?php
defined('ABSPATH') || (defined('EVC_STANDALONE_TEST') && EVC_STANDALONE_TEST) || exit;

/**
 * UI-04G — pure JSON response contracts for the future v2 staff REST API
 * (definitions only, NOT wired: no route, no WP_REST_Response, no hook).
 *
 * Payload creation and HTTP status recommendation are SEPARATE:
 *   EVC_*_Response::payload(...)   exact snake_case JSON body
 *   EVC_Contract_Http::status(...) recommended status for that body
 *
 * The redemption, handoff and status bodies are exactly what the strict
 * TypeScript parsers in app/src/staff/redemptionRecovery/wire.ts accept;
 * cross-language vectors (tests/fixtures/contract/vectors.json) generated
 * from these mappers are parsed by the real TypeScript code in CI.
 *
 * Source of truth = the domain outcome objects. A mapper never invents a
 * success: an outcome whose own facts disagree with its kind (or a
 * timestamp/date that is not canonical) is mapped to `server_error` with
 * the ORIGINAL request id for a safe same-id retry (fail closed).
 *
 * Envelope boundary (EVC_Contract_Envelope_Rule): transport/auth failures
 * before any service ran keep the existing v1 `{"error":{code,message}}`
 * envelope; v2 domain bodies are recognised by their exact shape and must
 * pass the security filter untouched (wiring task).
 */
final class EVC_Contract_Ids {
    const UUID_V4 = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D';

    /** The client's id echoed back only when it is a well-formed UUID v4 (never arbitrary input). @param mixed $id */
    public static function echo_id($id): ?string {
        return is_string($id) && preg_match(self::UUID_V4, $id) ? $id : null;
    }
}

final class EVC_Redemption_Response {
    const REFUSALS = array(
        EVC_Prepared_Redemption_Outcome::INVALID_REQUEST,
        EVC_Prepared_Redemption_Outcome::INVALID_PREPARATION,
        EVC_Prepared_Redemption_Outcome::INVALID_COFFEE,
        EVC_Prepared_Redemption_Outcome::CATALOGUE_VERSION_STALE,
        EVC_Prepared_Redemption_Outcome::CATALOGUE_NOT_AUTHORISED,
        EVC_Prepared_Redemption_Outcome::MEMBER_NOT_FOUND,
        EVC_Prepared_Redemption_Outcome::MEMBERSHIP_INACTIVE,
        EVC_Prepared_Redemption_Outcome::ALREADY_REDEEMED,
        EVC_Prepared_Redemption_Outcome::IDEMPOTENCY_CONFLICT,
    );

    /**
     * @param mixed $sent_request_id the request_id of the request body (echoed only if a UUID v4)
     * @param EVC_Clock_Source $clock the AUTHORITATIVE server clock (never the client)
     */
    public static function payload(EVC_Prepared_Redemption_Outcome $o, $sent_request_id, EVC_Clock_Source $clock): array {
        $request_id = EVC_Contract_Ids::echo_id($sent_request_id);
        $today = EVC_Clock::business_date(EVC_Clock::to_utc($clock->now()));
        $kind = $o->kind();

        if ($kind === EVC_Prepared_Redemption_Outcome::REDEEMED || $kind === EVC_Prepared_Redemption_Outcome::REPLAYED) {
            $record = self::record($o->redemption(), $request_id);
            if ($record === null) {
                return self::unresolved(EVC_Prepared_Redemption_Outcome::SERVER_ERROR, $request_id, $today);
            }
            $handoff = $o->handoff();
            if ($kind === EVC_Prepared_Redemption_Outcome::REDEEMED) {
                // A fresh commit: the ONLY body that may carry "prepare", on the commit's own date.
                if ($o->recording() !== EVC_Prepared_Redemption_Outcome::RECORDED_NOW || $o->instruction() !== EVC_Prepared_Redemption_Outcome::PREPARE
                    || $o->fulfillment() !== EVC_Prepared_Redemption_Outcome::FULFILLMENT_UNCONFIRMED || $handoff !== null || $o->entitlement_consumed() !== true) {
                    return self::unresolved(EVC_Prepared_Redemption_Outcome::SERVER_ERROR, $request_id, $today);
                }
                return self::body('redeemed', $request_id, 'recorded_now', 'unconfirmed', 'prepare', true, $record['business_date'], $record, null);
            }
            $instruction = $o->instruction();
            $expected_fulfillment = $handoff !== null ? 'confirmed' : 'unconfirmed';
            if ($o->recording() !== EVC_Prepared_Redemption_Outcome::PREVIOUSLY_RECORDED || $o->fulfillment() !== $expected_fulfillment || $o->entitlement_consumed() !== true
                || !in_array($instruction, array('verify_handoff', 'do_not_prepare', 'do_not_prepare_for_today'), true)) {
                return self::unresolved(EVC_Prepared_Redemption_Outcome::SERVER_ERROR, $request_id, $today);
            }
            if ($instruction === 'do_not_prepare_for_today') {
                // An earlier Athens day: the server's current date must be later than the record's.
                if (strcmp($today, $record['business_date']) <= 0) {
                    return self::unresolved(EVC_Prepared_Redemption_Outcome::SERVER_ERROR, $request_id, $today);
                }
                $server_date = $today;
            } else {
                // Same day as decided by the service: the record's own date.
                if ($instruction !== ($handoff !== null ? 'do_not_prepare' : 'verify_handoff')) {
                    return self::unresolved(EVC_Prepared_Redemption_Outcome::SERVER_ERROR, $request_id, $today);
                }
                $server_date = $record['business_date'];
            }
            return self::body('replayed', $request_id, 'previously_recorded', $expected_fulfillment, $instruction, true, $server_date, $record, $handoff);
        }
        if (in_array($kind, self::REFUSALS, true)) {
            return self::body($kind, $request_id, null, null, 'do_not_prepare', false, $today, null, null);
        }
        if ($kind === EVC_Prepared_Redemption_Outcome::RECORD_INTEGRITY_FAILURE) {
            return self::body($kind, $request_id, null, null, 'do_not_prepare', null, $today, null, null);
        }
        if ($kind === EVC_Prepared_Redemption_Outcome::OUTCOME_UNKNOWN) {
            // NOT a failure: the coffee may be recorded. Same-id retry only.
            return self::unresolved($kind, $request_id, $today);
        }
        return self::unresolved(EVC_Prepared_Redemption_Outcome::SERVER_ERROR, $request_id, $today);
    }

    /** @return array{request_id:string,member_id:string,business_date:string,redeemed_at_utc:string}|null */
    private static function record(?array $view, ?string $request_id): ?array {
        if ($view === null || $request_id === null || !isset($view['request_id'], $view['member_public_id'], $view['business_date'], $view['redeemed_at_utc'])
            || $view['request_id'] !== $request_id || !EVC_Member_Id::is_valid_format($view['member_public_id'])
            || !EVC_Utc_Timestamp::is_canonical($view['redeemed_at_utc'])
            || EVC_Utc_Timestamp::athens_date($view['redeemed_at_utc']) !== $view['business_date']) {
            return null;
        }
        return array(
            'request_id' => $view['request_id'],
            'member_id' => $view['member_public_id'],
            'business_date' => $view['business_date'],
            'redeemed_at_utc' => $view['redeemed_at_utc'],
        );
    }

    private static function body(string $outcome, ?string $request_id, ?string $recording, ?string $fulfillment, string $instruction, ?bool $consumed, string $server_date, ?array $redemption, ?array $handoff): array {
        return array(
            'outcome' => $outcome,
            'request_id' => $request_id,
            'recording' => $recording,
            'fulfillment' => $fulfillment,
            'instruction' => $instruction,
            'entitlement_consumed' => $consumed,
            'server_business_date' => $server_date,
            'redemption' => $redemption,
            'handoff' => $handoff,
        );
    }

    /** Nothing is known to be recorded: the ORIGINAL request id is the only valid retry. */
    private static function unresolved(string $kind, ?string $request_id, string $today): array {
        $body = self::body($kind, $request_id, null, null, 'do_not_prepare', null, $today, null, null);
        $body['retry_request_id'] = $request_id;
        return $body;
    }
}

final class EVC_Handoff_Response {
    const RECORDED = array(EVC_Handoff_Outcome::CONFIRMED, EVC_Handoff_Outcome::REPLAYED, EVC_Handoff_Outcome::ALREADY_CONFIRMED);
    const REFUSALS = array(
        EVC_Handoff_Outcome::FORBIDDEN,
        EVC_Handoff_Outcome::INVALID_REQUEST,
        EVC_Handoff_Outcome::REDEMPTION_NOT_FOUND,
        EVC_Handoff_Outcome::LATE_CONFIRMATION_NOT_AUTHORISED,
        EVC_Handoff_Outcome::IDEMPOTENCY_CONFLICT,
        EVC_Handoff_Outcome::RECORD_INTEGRITY_FAILURE,
    );
    const VIEW_KEYS = array('handoff_request_id', 'redemption_request_id', 'claim', 'confirmation_recorded_at_utc', 'handoff_at_utc', 'handoff_time');

    /** @param mixed $sent_handoff_request_id */
    public static function payload(EVC_Handoff_Outcome $o, $sent_handoff_request_id): array {
        $hid = EVC_Contract_Ids::echo_id($sent_handoff_request_id);
        $kind = $o->kind();
        if (in_array($kind, self::RECORDED, true)) {
            $view = $o->handoff();
            if ($hid === null || !self::valid_view($view)
                || ($kind === EVC_Handoff_Outcome::ALREADY_CONFIRMED) !== ($view['handoff_request_id'] !== $hid)) {
                // "already confirmed" must carry ANOTHER statement; confirmed/replayed must carry ours.
                return self::unresolved(EVC_Handoff_Outcome::SERVER_ERROR, $hid);
            }
            return array('outcome' => $kind, 'handoff_request_id' => $hid, 'fulfillment' => 'confirmed', 'handoff' => $view);
        }
        if (in_array($kind, self::REFUSALS, true)) {
            return array('outcome' => $kind, 'handoff_request_id' => $hid, 'fulfillment' => null, 'handoff' => null);
        }
        return self::unresolved($kind === EVC_Handoff_Outcome::OUTCOME_UNKNOWN ? $kind : EVC_Handoff_Outcome::SERVER_ERROR, $hid);
    }

    /** @param mixed $v */
    private static function valid_view($v): bool {
        if (!is_array($v) || array_keys($v) !== self::VIEW_KEYS || !EVC_Utc_Timestamp::is_canonical($v['confirmation_recorded_at_utc'])) {
            return false;
        }
        if ($v['claim'] === EVC_Handoff_Record::CLAIM_HANDED_NOW) {
            return $v['handoff_at_utc'] === $v['confirmation_recorded_at_utc'] && $v['handoff_time'] === EVC_Handoff_Record::TIME_STATED_AT_RECORDING;
        }
        // An earlier handoff: never a time.
        return $v['claim'] === EVC_Handoff_Record::CLAIM_ALREADY_HANDED_EARLIER && $v['handoff_at_utc'] === null && $v['handoff_time'] === EVC_Handoff_Record::TIME_NOT_RECORDED;
    }

    private static function unresolved(string $kind, ?string $hid): array {
        return array('outcome' => $kind, 'handoff_request_id' => $hid, 'fulfillment' => null, 'handoff' => null, 'retry_handoff_request_id' => $hid);
    }
}

final class EVC_Status_Response {
    const DATA_KEYS = array('redemption_request_id', 'business_date', 'redeemed_at_utc', 'is_current_business_date', 'coffee_label', 'modifiers', 'quick_notes', 'fulfillment', 'handoff');

    public static function payload(EVC_Fulfillment_Read_Result $r): array {
        if ($r->kind() !== EVC_Fulfillment_Read_Result::FOUND) {
            return array('result' => $r->kind(), 'data' => null);
        }
        $d = $r->data();
        if (!is_array($d) || array_keys($d) !== self::DATA_KEYS || !EVC_Utc_Timestamp::is_canonical($d['redeemed_at_utc'])
            || EVC_Utc_Timestamp::athens_date($d['redeemed_at_utc']) !== $d['business_date']
            || $d['fulfillment'] !== ($d['handoff'] === null ? 'unconfirmed' : 'confirmed')) {
            return array('result' => EVC_Fulfillment_Read_Result::SERVER_ERROR, 'data' => null);
        }
        // Display maps are JSON objects even when empty.
        $d['modifiers'] = (object) $d['modifiers'];
        return array('result' => 'found', 'data' => $d);
    }
}

final class EVC_Lookup_Response {
    /** Identity only: never eligibility, payment, history or personal data. */
    public static function payload(EVC_Member_Lookup_Result $r): array {
        if ($r->kind() !== EVC_Member_Lookup_Result::FOUND) {
            return array('result' => $r->kind(), 'member' => null);
        }
        $m = $r->member();
        if (!is_array($m) || array_keys($m) !== array('member_public_id', 'lookup_ref', 'lookup_ref_expires_at_utc')
            || !EVC_Member_Id::is_valid_format($m['member_public_id']) || !EVC_Utc_Timestamp::is_canonical($m['lookup_ref_expires_at_utc'])) {
            return array('result' => EVC_Member_Lookup_Result::UNAVAILABLE, 'member' => null);
        }
        return array('result' => 'found', 'member' => $m);
    }
}

final class EVC_Usual_Coffee_Response {
    const SUGGESTION_KEYS = array('catalogue_version', 'coffee_code', 'coffee_label', 'modifiers', 'quick_notes', 'basis');

    public static function payload(EVC_Usual_Coffee_Result $r): array {
        $s = $r->suggestion();
        if ($r->kind() === EVC_Usual_Coffee_Result::SUGGESTED) {
            if (!is_array($s) || array_keys($s) !== self::SUGGESTION_KEYS) {
                return self::body(EVC_Usual_Coffee_Result::SERVER_ERROR, null, null);
            }
            return self::body('suggested', null, $s);
        }
        return self::body($r->kind(), $r->kind() === EVC_Usual_Coffee_Result::NO_SUGGESTION ? $r->reason() : null, null);
    }

    private static function body(string $result, ?string $reason, ?array $suggestion): array {
        // A suggestion is never a confirmed order: the client must review and submit it.
        return array('result' => $result, 'reason' => $reason, 'suggestion' => $suggestion, 'confirmed_order' => false);
    }
}

/**
 * Recommended HTTP statuses for v2 DOMAIN bodies (the body stays the source
 * of truth). An uncertain commit is 503 + retry with the same id, never a
 * definite 4xx failure; record integrity failure is 500 (manager review).
 */
final class EVC_Contract_Http {
    const REDEMPTION = array(
        'redeemed' => 201, 'replayed' => 200,
        'invalid_request' => 400, 'invalid_preparation' => 400, 'invalid_coffee' => 400,
        'catalogue_version_stale' => 409, 'catalogue_not_authorised' => 503,
        'member_not_found' => 404, 'membership_inactive' => 409, 'benefit_already_redeemed' => 409, 'idempotency_key_reused' => 409,
        'record_integrity_failure' => 500, 'outcome_unknown' => 503, 'server_error' => 503,
    );
    const HANDOFF = array(
        'handoff_confirmed' => 201, 'handoff_replayed' => 200, 'handoff_already_confirmed' => 200,
        'invalid_request' => 400, 'forbidden' => 403, 'redemption_not_found' => 404,
        'late_confirmation_not_authorised' => 409, 'idempotency_key_reused' => 409,
        'record_integrity_failure' => 500, 'outcome_unknown' => 503, 'server_error' => 503,
    );
    const STATUS = array('found' => 200, 'not_found' => 404, 'forbidden' => 403, 'invalid_request' => 400, 'record_integrity_failure' => 500, 'server_error' => 503);
    const LOOKUP = array('found' => 200, 'not_found' => 404, 'ambiguous' => 409, 'invalid_phone' => 400, 'forbidden' => 403, 'rate_limited' => 429, 'unavailable' => 503);
    const USUAL = array('suggested' => 200, 'no_suggestion' => 200, 'forbidden' => 403, 'invalid_request' => 400, 'member_not_found' => 404, 'server_error' => 503);

    /** @param array $body a payload produced by one of the response mappers */
    public static function status(string $surface, array $body): int {
        $map = array('redemption' => self::REDEMPTION, 'handoff' => self::HANDOFF, 'status' => self::STATUS, 'lookup' => self::LOOKUP, 'usual' => self::USUAL);
        if (!isset($map[$surface])) {
            return 500;
        }
        $key = isset($body['outcome']) ? $body['outcome'] : (isset($body['result']) ? $body['result'] : null);
        return is_string($key) && isset($map[$surface][$key]) ? $map[$surface][$key] : 500;
    }

    /**
     * UI-04H: the body's outcome/result is one this surface can produce (an
     * unknown key maps to 500 above, which must never be mistaken for the
     * legitimate 500 of record_integrity_failure).
     */
    public static function knows(string $surface, array $body): bool {
        $map = array('redemption' => self::REDEMPTION, 'handoff' => self::HANDOFF, 'status' => self::STATUS, 'lookup' => self::LOOKUP, 'usual' => self::USUAL);
        $field = $surface === 'redemption' || $surface === 'handoff' ? 'outcome' : 'result';
        return isset($map[$surface]) && isset($body[$field]) && is_string($body[$field]) && isset($map[$surface][$body[$field]]);
    }
}

/**
 * Which bodies the (future) security post-dispatch filter must leave
 * untouched: exactly-shaped v2 domain bodies. Everything else at >= 400
 * keeps the existing v1 `{"error":{code,message}}` envelope rewrite.
 */
final class EVC_Contract_Envelope_Rule {
    /** @param mixed $data */
    public static function is_domain_body($data): bool {
        if (!is_array($data) || isset($data['error'])) {
            return false;
        }
        $keys = array_keys($data);
        sort($keys);
        $shapes = array(
            array('entitlement_consumed', 'fulfillment', 'handoff', 'instruction', 'outcome', 'recording', 'redemption', 'request_id', 'server_business_date'),
            array('entitlement_consumed', 'fulfillment', 'handoff', 'instruction', 'outcome', 'recording', 'redemption', 'request_id', 'retry_request_id', 'server_business_date'),
            array('fulfillment', 'handoff', 'handoff_request_id', 'outcome'),
            array('fulfillment', 'handoff', 'handoff_request_id', 'outcome', 'retry_handoff_request_id'),
            array('data', 'result'),
            array('member', 'result'),
            array('confirmed_order', 'reason', 'result', 'suggestion'),
        );
        return in_array($keys, $shapes, true);
    }

    /**
     * UI-04H: the exact shape(s) of ONE surface. A shape match alone is never
     * authorization (EVC_Rest_V2_Envelope_Policy also binds route, method,
     * sealed source and status); it only rules out a body of another surface.
     *
     * @param mixed $data
     */
    public static function is_domain_body_for(string $surface, $data): bool {
        if (!self::is_domain_body($data)) {
            return false;
        }
        $keys = array_keys($data);
        sort($keys);
        $shapes = array(
            'redemption' => array(
                array('entitlement_consumed', 'fulfillment', 'handoff', 'instruction', 'outcome', 'recording', 'redemption', 'request_id', 'server_business_date'),
                array('entitlement_consumed', 'fulfillment', 'handoff', 'instruction', 'outcome', 'recording', 'redemption', 'request_id', 'retry_request_id', 'server_business_date'),
            ),
            'handoff' => array(
                array('fulfillment', 'handoff', 'handoff_request_id', 'outcome'),
                array('fulfillment', 'handoff', 'handoff_request_id', 'outcome', 'retry_handoff_request_id'),
            ),
            'status' => array(array('data', 'result')),
            'lookup' => array(array('member', 'result')),
            'usual' => array(array('confirmed_order', 'reason', 'result', 'suggestion')),
        );
        return isset($shapes[$surface]) && in_array($keys, $shapes[$surface], true) && EVC_Contract_Http::knows($surface, $data);
    }
}
