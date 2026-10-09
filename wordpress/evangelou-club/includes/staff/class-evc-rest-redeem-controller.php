<?php
defined('ABSPATH') || exit;

/**
 * POST /wp-json/evangelou-club/v1/members/{member_id}/benefits/{benefit_type}/redeem
 *
 * Registered only when the staff feature flag is enabled. Translates the
 * Task 1B engine result into the public v1 JSON (app/docs/evangelou-club-api.md §12):
 *  - staff id = get_current_user_id() (never from the request);
 *  - session_ref = keyed hash of the WP session token, audit-only;
 *  - redeemed_at: engine UTC "Y-m-d H:i:s.u" -> RFC 3339 Europe/Athens with offset;
 *  - no SQL, exception text, membership objects or internal ids are returned.
 * With the production backend (always unavailable in this task) every
 * authorized call returns 503 server_error.
 */
final class EVC_Rest_Redeem_Controller {
    const ROUTE = '/members/(?P<member_id>[A-Za-z0-9_-]{1,64})/benefits/(?P<benefit_type>[a-z_]{1,32})/redeem';

    const STATUS = array(
        EVC_Redemption_Result::INVALID_REQUEST => 400,
        EVC_Redemption_Result::INVALID_COFFEE => 400,
        EVC_Redemption_Result::MEMBER_NOT_FOUND => 404,
        EVC_Redemption_Result::MEMBERSHIP_INACTIVE => 409,
        EVC_Redemption_Result::ALREADY_REDEEMED => 409,
        EVC_Redemption_Result::IDEMPOTENCY_CONFLICT => 409,
        EVC_Redemption_Result::SERVER_ERROR => 500,
    );

    /** @var EVC_Redemption_Backend */
    private $backend;

    public function __construct(EVC_Redemption_Backend $backend) {
        $this->backend = $backend;
    }

    public function register_routes(bool $override = false): void {
        register_rest_route(EVC_Rest_Security::NAMESPACE_V1, self::ROUTE, array(
            'methods' => 'POST',
            'callback' => array($this, 'redeem'),
            'permission_callback' => array($this, 'permission'),
            'args' => array(
                'request_id' => array('type' => 'string', 'required' => true),
                'coffee_code' => array('type' => 'string', 'required' => true),
            ),
        ), $override);
    }

    public function permission(WP_REST_Request $request) {
        return EVC_Staff_Auth::authorize($request, EVC_Staff_Role::CAP_REDEEM);
    }

    public function redeem(WP_REST_Request $request) {
        // Defense in depth: never trust that the permission callback ran.
        $auth = EVC_Staff_Auth::authorize($request, EVC_Staff_Role::CAP_REDEEM);
        if (is_wp_error($auth)) {
            return $auth;
        }
        try {
            $body = $request->get_json_params();
            if (!is_array($body) || !isset($body['request_id'], $body['coffee_code'])
                || !is_string($body['request_id']) || !is_string($body['coffee_code'])) {
                return EVC_Rest_Security::error_response('invalid_request', 400);
            }
            $service = $this->backend->service();
            if ($service === null) {
                return EVC_Rest_Security::error_response('server_error', 503);
            }
            // Path parameters come ONLY from the URL: get_param() would let a
            // JSON body field override them.
            $url = $request->get_url_params();
            $token = wp_get_session_token();
            $result = $service->redeem(new EVC_Redemption_Request(
                isset($url['member_id']) ? (string) $url['member_id'] : '',
                isset($url['benefit_type']) ? (string) $url['benefit_type'] : '',
                $body['coffee_code'],
                $body['request_id'],
                get_current_user_id(),
                (is_string($token) && $token !== '') ? EVC_Staff_Session::session_ref($token) : null
            ));
            return self::translate($result);
        } catch (Throwable $e) {
            return EVC_Rest_Security::error_response('server_error', 500);
        }
    }

    public static function translate(EVC_Redemption_Result $result): WP_REST_Response {
        if ($result->is_success()) {
            $r = $result->redemption();
            return new WP_REST_Response(array(
                'member_id' => $r['member_public_id'],
                'benefit_type' => $r['benefit_type'],
                'state' => 'used',
                'business_date' => $r['business_date'],
                'redeemed_at' => self::rfc3339($r['redeemed_at_utc']),
                'coffee_code' => $r['coffee_code'],
                'request_id' => $r['request_id'],
                'replayed' => $result->is_replay(),
            ), 200);
        }
        $code = $result->outcome();
        $status = isset(self::STATUS[$code]) ? self::STATUS[$code] : 500;
        $d = $result->details();
        $details = null;
        if ($code === EVC_Redemption_Result::MEMBERSHIP_INACTIVE && isset($d['status'])) {
            $details = array('status' => $d['status']); // engine `reason` deliberately dropped
        } elseif ($code === EVC_Redemption_Result::ALREADY_REDEEMED && isset($d['business_date'], $d['redeemed_at_utc'])) {
            $details = array('business_date' => $d['business_date'], 'redeemed_at' => self::rfc3339($d['redeemed_at_utc']));
        }
        if (!isset(self::STATUS[$code])) {
            $code = 'server_error';
        }
        return EVC_Rest_Security::error_response($code, $status, $details);
    }

    /** Engine UTC "Y-m-d H:i:s[.u]" (no zone) -> "Y-m-d\TH:i:sP" in Europe/Athens. Deterministic. */
    public static function rfc3339(string $utc): string {
        $utc_zone = new DateTimeZone('UTC');
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $utc, $utc_zone)
            ?: DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $utc, $utc_zone);
        if (!$dt) {
            throw new UnexpectedValueException('Invalid engine timestamp.');
        }
        return $dt->setTimezone(new DateTimeZone(EVC_Clock::BUSINESS_TIMEZONE))->format('Y-m-d\TH:i:sP');
    }
}
