<?php
defined('ABSPATH') || exit;
/**
 * STAGE-08 round 12 (owner decision D26 part 2): the signed-in member's own push notifications.
 * evangelou-club-customer/v1 (registered by EVC_Push_Plugin behind EVC_CLUB_PUSH_ENABLED; otherwise 404):
 *
 *   GET    /me/push  200 {"result":"ok","push":{"state":"ready"}}           the control may be shown
 *                    200 {"result":"ok","push":{"state":"not_member"}} / {"state":"member_disabled"}
 *                    503 {"result":"unavailable"}   keys unusable, Club DB missing, schema below 5, ...: no control
 *   POST   /me/push  body {"endpoint": "https://<push service>/...", "keys": {"p256dh": "<b64url>", "auth": "<b64url>"},
 *                          "consent": true}   (exactly these keys; consent must be the boolean true: the member's tap)
 *                    200 {"result":"ok","push":{"state":"subscribed"}}
 *                    400 invalid_request (any other shape; an endpoint outside the push-service allowlist; a key that
 *                        is not a P-256 point / a 16-byte secret)
 *                    404 not_a_member · 403 member_disabled
 *                    409 endpoint_in_use (this browser's subscription is another member's active one: the app makes a
 *                        new subscription and asks again)
 *                    503 {"result":"unavailable"}
 *   DELETE /me/push  body {"endpoint": "..."}   200 {"result":"ok","push":{"state":"stopped"}} whether or not a row of
 *                    this member matched (nothing tells whether another member has that endpoint); 400 / 404 / 503 as above.
 *
 * Same customer authorization as every /me route (EVC_Customer_Auth: cookie session + own wp_rest nonce, customer
 * accounts only); the member is ALWAYS get_current_user_id(); responses are no-store (EVC_Customer_Rest_Security). The
 * User-Agent header gives only a coarse browser family (never stored as text). The endpoint is never logged or echoed.
 */
final class EVC_Rest_Me_Push_Controller {
    const ROUTE = '/me/push';

    /** @var EVC_Push_Backend */
    private $backend;

    public function __construct(EVC_Push_Backend $backend) {
        EVC_Push_Feature::assert_constructible(__CLASS__);
        $this->backend = $backend;
    }

    public function register_routes(bool $override = false): void {
        register_rest_route(EVC_Customer_Rest_Security::NAMESPACE_V1, self::ROUTE, array(
            array('methods' => 'GET', 'callback' => array($this, 'status'), 'permission_callback' => array('EVC_Customer_Auth', 'authorize'), 'args' => array()),
            array('methods' => 'POST', 'callback' => array($this, 'subscribe'), 'permission_callback' => array('EVC_Customer_Auth', 'authorize'), 'args' => array()),
            array('methods' => 'DELETE', 'callback' => array($this, 'unsubscribe'), 'permission_callback' => array('EVC_Customer_Auth', 'authorize'), 'args' => array()),
        ), $override);
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
        if (!in_array($status['state'], array('ready', 'not_member', 'member_disabled'), true)) {
            return self::unavailable();
        }
        return new WP_REST_Response(array('result' => 'ok', 'push' => array('state' => $status['state'])), 200);
    }

    /** @return WP_REST_Response|WP_Error */
    public function subscribe(WP_REST_Request $request) {
        $auth = EVC_Customer_Auth::authorize($request);
        if ($auth instanceof WP_Error) {
            return $auth;
        }
        $body = self::body($request);
        if ($body === null || self::keys_of($body) !== array('consent', 'endpoint', 'keys') || $body['consent'] !== true
            || !is_array($body['keys']) || self::keys_of($body['keys']) !== array('auth', 'p256dh')
            || EVC_Push_Endpoint::problem($body['endpoint']) !== null) {
            return EVC_Rest_Security::error_response('invalid_request', 400);
        }
        $p256dh = EVC_Push_Endpoint::p256dh($body['keys']['p256dh']);
        $secret = EVC_Push_Endpoint::auth($body['keys']['auth']);
        if ($p256dh === null || $secret === null) {
            return EVC_Rest_Security::error_response('invalid_request', 400);
        }
        try {
            $result = $this->backend->subscribe(get_current_user_id(), (string) $body['endpoint'], EVC_Push_Crypto::b64u_encode($p256dh), EVC_Push_Crypto::b64u_encode($secret), EVC_Push_Endpoint::agent_family($request->get_header('User-Agent')));
        } catch (Throwable $e) {
            return self::unavailable();
        }
        switch ($result['result']) {
            case EVC_Sql_Push_Subscription_Store::CREATED:
            case EVC_Sql_Push_Subscription_Store::REFRESHED:
            case EVC_Sql_Push_Subscription_Store::RENEWED:
                return new WP_REST_Response(array('result' => 'ok', 'push' => array('state' => 'subscribed')), 200);
            case EVC_Sql_Push_Subscription_Store::TAKEN:
                return EVC_Rest_Security::error_response('endpoint_in_use', 409);
            case 'not_member':
                return EVC_Rest_Security::error_response('not_a_member', 404);
            case 'member_disabled':
                return EVC_Rest_Security::error_response('member_disabled', 403);
            default:
                return self::unavailable();
        }
    }

    /** @return WP_REST_Response|WP_Error */
    public function unsubscribe(WP_REST_Request $request) {
        $auth = EVC_Customer_Auth::authorize($request);
        if ($auth instanceof WP_Error) {
            return $auth;
        }
        $body = self::body($request);
        if ($body === null || self::keys_of($body) !== array('endpoint') || EVC_Push_Endpoint::problem($body['endpoint']) !== null) {
            return EVC_Rest_Security::error_response('invalid_request', 400);
        }
        try {
            $result = $this->backend->unsubscribe(get_current_user_id(), (string) $body['endpoint']);
        } catch (Throwable $e) {
            return self::unavailable();
        }
        if ($result['result'] === 'stopped') {
            return new WP_REST_Response(array('result' => 'ok', 'push' => array('state' => 'stopped')), 200);
        }
        if ($result['result'] === 'not_member') {
            return EVC_Rest_Security::error_response('not_a_member', 404);
        }
        return self::unavailable();
    }

    /** The JSON object of the body, or null (no body, not JSON, not an object). */
    private static function body(WP_REST_Request $request): ?array {
        $raw = $request->get_body();
        if (!is_string($raw) || trim($raw) === '' || strlen($raw) > 4096) {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) && $data !== array() && array_keys($data) !== range(0, count($data) - 1) ? $data : null;
    }

    /** @return string[] the sorted keys */
    private static function keys_of(array $data): array {
        $keys = array_map('strval', array_keys($data));
        sort($keys, SORT_STRING);
        return $keys;
    }

    private static function unavailable(): WP_REST_Response {
        return new WP_REST_Response(array('result' => 'unavailable'), 503);
    }
}
