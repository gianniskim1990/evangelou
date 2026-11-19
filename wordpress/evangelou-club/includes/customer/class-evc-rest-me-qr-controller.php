<?php
defined('ABSPATH') || exit;
/**
 * STAGE-05C: the signed-in customer's own Club QR. evangelou-club-customer/v1:
 *
 *   GET  /me/qr   200 {"result":"ok","qr":{"state":"active","issued_at":"<RFC 3339 Athens>"}}
 *                 200 {"result":"ok","qr":{"state":"none"}}           member without an active QR
 *                 200 {"result":"ok","qr":{"state":"not_member"}}     no Club member row (yet)
 *                 200 {"result":"ok","qr":{"state":"member_disabled"}}
 *                 NEVER a token (only its digest is stored).
 *   POST /me/qr   body {} (or {"replace": true|false} from older cached apps: accepted and IGNORED, owner
 *                 decision D22; anything else: 400 invalid_request)
 *                 200 {"result":"ok","qr":{"state":"active","token":"evc_…","issued_at":"…"}}  ONLY here:
 *                     the member's stable token, the SAME on every device and every call (D22)
 *                 404 not_a_member · 403 member_disabled
 *                 409 concurrent_change (another device of the member wrote at the same moment: ask again)
 *                 503 {"result":"unavailable"} (incl. a missing EVC_CLUB_QR_SECRET and an unknown COMMIT
 *                     outcome: asking again is safe)
 *   GET  /me/coffees  owner decision D23 (2026-11-19): the member card's own free-coffee figures, read only
 *                 200 {"result":"ok","coffees":{"state":"member","today":"YYYY-MM-DD","taken_today":true|false,
 *                      "this_month":<int>}}   today = the Europe/Athens business day the figures belong to
 *                 200 {"result":"ok","coffees":{"state":"not_member"}} / {"state":"member_disabled"}
 *                 503 {"result":"unavailable"} (the card then shows «—»: fail soft). Needs no QR secret.
 * All: customer accounts only (EVC_Customer_Auth: cookie session + own wp_rest nonce), the user is
 * ALWAYS get_current_user_id(); nothing identifying is read from the request. Responses are no-store
 * (EVC_Customer_Rest_Security). The token is never logged.
 */
final class EVC_Rest_Me_Qr_Controller {
    const ROUTE = '/me/qr';
    /** D23: the member's own coffee figures (same gates and the same backend as the QR). */
    const COFFEES_ROUTE = '/me/coffees';

    /** @var EVC_Customer_Qr_Backend */
    private $backend;

    public function __construct(EVC_Customer_Qr_Backend $backend) {
        $this->backend = $backend;
    }

    public function register_routes(bool $override = false): void {
        register_rest_route(EVC_Customer_Rest_Security::NAMESPACE_V1, self::ROUTE, array(
            array(
                'methods' => 'GET',
                'callback' => array($this, 'status'),
                'permission_callback' => array('EVC_Customer_Auth', 'authorize'),
                'args' => array(),
            ),
            array(
                'methods' => 'POST',
                'callback' => array($this, 'issue'),
                'permission_callback' => array('EVC_Customer_Auth', 'authorize'),
                'args' => array(),
            ),
        ), $override);
        register_rest_route(EVC_Customer_Rest_Security::NAMESPACE_V1, self::COFFEES_ROUTE, array(
            array(
                'methods' => 'GET',
                'callback' => array($this, 'coffees'),
                'permission_callback' => array('EVC_Customer_Auth', 'authorize'),
                'args' => array(),
            ),
        ), $override);
    }

    /** @return WP_REST_Response|WP_Error */
    public function coffees(WP_REST_Request $request) {
        $auth = EVC_Customer_Auth::authorize($request);
        if ($auth instanceof WP_Error) {
            return $auth;
        }
        try {
            // Always the caller's own member row: nothing identifying is read from the request.
            $coffees = $this->backend->coffees(get_current_user_id());
        } catch (Throwable $e) {
            return self::unavailable();
        }
        switch ($coffees['state']) {
            case 'member':
                return new WP_REST_Response(array('result' => 'ok', 'coffees' => array(
                    'state' => 'member',
                    'today' => $coffees['today'],
                    'taken_today' => $coffees['taken_today'],
                    'this_month' => $coffees['this_month'],
                )), 200);
            case 'not_member':
            case 'member_disabled':
                return new WP_REST_Response(array('result' => 'ok', 'coffees' => array('state' => $coffees['state'])), 200);
            default:
                return self::unavailable();
        }
    }

    /** @return WP_REST_Response|WP_Error */
    public function status(WP_REST_Request $request) {
        $auth = EVC_Customer_Auth::authorize($request);
        if ($auth instanceof WP_Error) {
            return $auth;
        }
        try {
            $status = $this->backend->status(get_current_user_id());
        } catch (Throwable $e) {
            return self::unavailable();
        }
        if ($status['state'] === 'unavailable') {
            return self::unavailable();
        }
        $qr = array('state' => $status['state']);
        if ($status['state'] === 'active') {
            $qr['issued_at'] = EVC_Rest_Redeem_Controller::rfc3339($status['issued_at_utc']);
        }
        return new WP_REST_Response(array('result' => 'ok', 'qr' => $qr), 200);
    }

    /** @return WP_REST_Response|WP_Error */
    public function issue(WP_REST_Request $request) {
        $auth = EVC_Customer_Auth::authorize($request);
        if ($auth instanceof WP_Error) {
            return $auth;
        }
        $body = $request->get_json_params();
        $raw = $request->get_body();
        if ($body === null && is_string($raw) && trim($raw) !== '') {
            return EVC_Rest_Security::error_response('invalid_request', 400);
        }
        $body = is_array($body) ? $body : array();
        if (array_diff(array_keys($body), array('replace')) !== array() || (array_key_exists('replace', $body) && !is_bool($body['replace']))) {
            return EVC_Rest_Security::error_response('invalid_request', 400);
        }
        try {
            // D22: a customer never replaces the QR; `replace` was validated above and is ignored.
            $result = $this->backend->issue(get_current_user_id());
        } catch (Throwable $e) {
            return self::unavailable();
        }
        switch ($result['result']) {
            case 'issued':
                return new WP_REST_Response(array('result' => 'ok', 'qr' => array(
                    'state' => 'active',
                    'token' => $result['token'],
                    'issued_at' => EVC_Rest_Redeem_Controller::rfc3339($result['issued_at_utc']),
                )), 200);
            case 'not_member':
                return EVC_Rest_Security::error_response('not_a_member', 404);
            case 'member_disabled':
                return EVC_Rest_Security::error_response('member_disabled', 403);
            case 'concurrent_change':
                return EVC_Rest_Security::error_response('concurrent_change', 409);
            default:
                return self::unavailable();
        }
    }

    private static function unavailable(): WP_REST_Response {
        return new WP_REST_Response(array('result' => 'unavailable'), 503);
    }
}
