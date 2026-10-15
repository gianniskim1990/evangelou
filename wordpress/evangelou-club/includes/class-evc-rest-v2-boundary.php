<?php
defined('ABSPATH') || (defined('EVC_STANDALONE_TEST') && EVC_STANDALONE_TEST) || exit;

/**
 * UI-04H — the FUTURE v2 staff REST boundary as pure definitions
 * (no WordPress calls, no route registration, no hook).
 *
 * EVC_Rest_V2_Routes is the designated route table; nothing registers it in
 * shipped code. EVC_Rest_V2_Envelope_Policy decides, fail closed, whether a
 * response may leave a v2 route as a v2 DOMAIN body or must be replaced by
 * the v1 `{"error":{code,message}}` transport envelope. A JSON key set alone
 * is never sufficient: the body must come from the sealed response source
 * (EVC_Rest_V2_Domain_Response, which only the future controllers create),
 * on the exact designated route + method it was sealed for, unchanged, with
 * exactly the status EVC_Contract_Http recommends, for an authenticated
 * request. Design: docs/rest-v2-boundary.md.
 */
final class EVC_Rest_V2_Routes {
    const NAMESPACE_V2 = 'evangelou-club/v2';
    const MEMBER = '(?P<member_id>mem_[0-9a-f]{32})';
    const REQUEST = '(?P<request_id>[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})';

    /**
     * route id => method, route (relative to the namespace), surface, capability,
     * lookup proof, idempotency key, rate-limit bucket.
     * Capabilities marked PENDING are NOT granted by EVC_Staff_Role (UI-04H
     * capability audit; owner decision required) so these routes would be 403.
     */
    const ROUTES = array(
        'lookup_qr' => array('method' => 'POST', 'route' => '/lookup/qr', 'surface' => 'lookup', 'capability' => 'evc_lookup_member', 'lookup_proof' => false, 'idempotency' => null, 'rate_limit' => 'lookup'),
        'lookup_phone' => array('method' => 'POST', 'route' => '/lookup/phone', 'surface' => 'lookup', 'capability' => 'evc_lookup_member', 'lookup_proof' => false, 'idempotency' => null, 'rate_limit' => 'lookup'),
        'redeem' => array('method' => 'POST', 'route' => '/members/' . self::MEMBER . '/redemptions', 'surface' => 'redemption', 'capability' => 'evc_redeem_benefit', 'lookup_proof' => true, 'idempotency' => 'request_id', 'rate_limit' => 'write'),
        'status' => array('method' => 'GET', 'route' => '/members/' . self::MEMBER . '/redemptions/' . self::REQUEST, 'surface' => 'status', 'capability' => 'evc_view_redemption_status', 'lookup_proof' => false, 'idempotency' => null, 'rate_limit' => 'read'),
        'handoff' => array('method' => 'POST', 'route' => '/members/' . self::MEMBER . '/redemptions/' . self::REQUEST . '/handoff', 'surface' => 'handoff', 'capability' => 'evc_confirm_handoff', 'lookup_proof' => false, 'idempotency' => 'handoff_request_id', 'rate_limit' => 'write'),
        'usual' => array('method' => 'POST', 'route' => '/members/' . self::MEMBER . '/usual-coffee', 'surface' => 'usual', 'capability' => 'evc_view_usual_coffee', 'lookup_proof' => true, 'idempotency' => null, 'rate_limit' => 'read'),
    );

    /** Exactly the v2 namespace (never a longer namespace that merely starts with it, e.g. "evangelou-club/v2x"). */
    public static function is_v2_route($route): bool {
        $route = (string) $route;
        $base = '/' . self::NAMESPACE_V2;
        return $route === $base || strpos($route, $base . '/') === 0;
    }

    /** @return array|null the route definition (with its id) */
    public static function route(string $id): ?array {
        return isset(self::ROUTES[$id]) ? array('id' => $id) + self::ROUTES[$id] : null;
    }

    /** Full matched-route pattern WordPress reports for a designated route. */
    public static function pattern(string $id): ?string {
        return isset(self::ROUTES[$id]) ? '/' . self::NAMESPACE_V2 . self::ROUTES[$id]['route'] : null;
    }

    /**
     * The designated route for a WordPress matched-route pattern and method.
     * @return array|null
     */
    public static function designated($matched_route, $method): ?array {
        foreach (self::ROUTES as $id => $r) {
            if ($matched_route === '/' . self::NAMESPACE_V2 . $r['route'] && $method === $r['method']) {
                return self::route($id);
            }
        }
        return null;
    }
}

final class EVC_Rest_V2_Envelope_Policy {
    const PASS = 'pass';
    const ENVELOPE = 'envelope';
    const IGNORE = 'ignore';

    /** WordPress output-shaping query parameters a v2 client never sends. */
    const TRANSPORT_PARAMS = array('_envelope', '_jsonp', '_embed', '_fields');

    /**
     * @param array $in {
     *   route: string            request route (namespace included)
     *   method: string           request method
     *   matched_route: ?string   pattern WordPress matched (null = none)
     *   status: int              current response status
     *   data: mixed              current response data
     *   sealed: ?array           {route_id, surface, body, status} from the sealed source, or null
     *   authenticated: bool      a WordPress user is authenticated for this request
     *   transport_params: bool   an output-shaping query parameter is present
     * }
     * @return array{action:string,code?:string,status?:int}
     */
    public static function decide(array $in): array {
        $sealed = isset($in['sealed']) && is_array($in['sealed']) ? $in['sealed'] : null;
        if (!EVC_Rest_V2_Routes::is_v2_route(isset($in['route']) ? $in['route'] : '')) {
            // Wrong namespace NEVER gains an exemption: a sealed v2 body outside v2 is a wiring fault.
            return $sealed !== null ? self::envelope('server_error', 500) : array('action' => self::IGNORE);
        }
        $status = isset($in['status']) && is_int($in['status']) ? $in['status'] : 500;
        if ($status === 401) {
            // HTTP 401 is always a transport failure, never an authenticated domain body.
            return self::envelope('unauthorized', 401);
        }
        if ($sealed === null) {
            return self::unsealed($status, isset($in['data']) ? $in['data'] : null);
        }
        if (!empty($in['transport_params'])) {
            return self::envelope('invalid_request', 400);
        }
        if (empty($in['authenticated'])) {
            return self::envelope('unauthorized', 401);
        }
        $route = EVC_Rest_V2_Routes::designated(isset($in['matched_route']) ? $in['matched_route'] : null, isset($in['method']) ? $in['method'] : null);
        if ($route === null || !isset($sealed['route_id'], $sealed['surface'], $sealed['body'], $sealed['status'])
            || $sealed['route_id'] !== $route['id'] || $sealed['surface'] !== $route['surface']) {
            return self::envelope('server_error', 500);
        }
        $body = $sealed['body'];
        // Unchanged since sealing, and exactly the recommended status for this body.
        if (!array_key_exists('data', $in) || $in['data'] !== $body || $status !== $sealed['status']
            || !EVC_Contract_Envelope_Rule::is_domain_body_for($route['surface'], $body)
            || $status !== EVC_Contract_Http::status($route['surface'], $body)) {
            return self::envelope('server_error', 500);
        }
        return array('action' => self::PASS);
    }

    /**
     * Anything on a v2 route that is not a sealed domain reply becomes a v1
     * transport envelope; codes and messages never pass through.
     *
     * @param mixed $data
     * @return array{action:string,code:string,status:int}
     */
    public static function unsealed(int $status, $data): array {
        $code = '';
        if (is_array($data) && isset($data['code']) && is_string($data['code'])) {
            $code = $data['code'];
        } elseif (is_array($data) && isset($data['error']['code']) && is_string($data['error']['code'])) {
            $code = $data['error']['code'];
        }
        if ($code === 'rest_cookie_invalid_nonce' || $status === 401) {
            return self::envelope('unauthorized', 401);
        }
        if ($status === 403) {
            return self::envelope('forbidden', 403);
        }
        if ($status === 429) {
            return self::envelope('rate_limited', 429);
        }
        if ($status === 503) {
            return self::envelope('server_error', 503);
        }
        if ($status >= 400 && $status < 500) {
            return self::envelope('invalid_request', ($status === 404 || $status === 405) ? $status : 400);
        }
        // 2xx/3xx bodies of unknown origin and every other server failure.
        return self::envelope('server_error', 500);
    }

    /** @return array{action:string,code:string,status:int} */
    private static function envelope(string $code, int $status): array {
        return array('action' => self::ENVELOPE, 'code' => $code, 'status' => $status);
    }
}
