<?php
defined('ABSPATH') || exit;
/**
 * Namespace-scoped REST hardening for evangelou-club-customer/v1 (STAGE-01),
 * the same policy as the staff namespace (EVC_Rest_Security), installed ONLY
 * when the customer flag is enabled. Every callback returns early outside
 * the namespace, so core/WooCommerce/PMPro/FluentCRM routes are unaffected.
 *
 * - The two domain bodies of the contract pass unchanged:
 *     200 {"result":"ok",...}   and   503 {"result":"unavailable"}
 *   Any other error (core auth/nonce errors, permission WP_Errors) becomes
 *   the v1 envelope {error:{code,message}} with client-safe Greek text.
 * - Security headers: no-store, nosniff, no-referrer, DENY, JSON-only CSP.
 * - CORS headers that core reflects are removed (same-origin only).
 */
final class EVC_Customer_Rest_Security {
    const NAMESPACE_V1 = 'evangelou-club-customer/v1';

    public static function register_hooks(): void {
        add_filter('rest_post_dispatch', array(__CLASS__, 'filter_post_dispatch'), 99, 3);
        add_filter('rest_pre_serve_request', array(__CLASS__, 'strip_cors'), 11, 4);
    }

    public static function is_customer_route($route): bool {
        $route = (string) $route;
        $base = '/' . self::NAMESPACE_V1;
        return $route === $base || strpos($route, $base . '/') === 0;
    }

    /** The only non-envelope bodies the contract allows. */
    public static function is_domain_body($data, int $status): bool {
        if (!is_array($data) || !isset($data['result'])) {
            return false;
        }
        return ($status === 200 && $data['result'] === 'ok') || ($status === 503 && $data === array('result' => 'unavailable'));
    }

    public static function filter_post_dispatch($result, $server, $request) {
        if (!($request instanceof WP_REST_Request) || !self::is_customer_route($request->get_route())) {
            return $result;
        }
        $response = rest_ensure_response($result);
        $status = (int) $response->get_status();
        $data = $response->get_data();
        if ($status >= 400 && !self::is_domain_body($data, $status) && !(is_array($data) && isset($data['error']))) {
            $code = (is_array($data) && isset($data['code']) && is_string($data['code'])) ? $data['code'] : '';
            $mapped = EVC_Rest_Security::map_core_error($code, $status);
            if ($mapped !== null) {
                $headers = $response->get_headers();
                $response = EVC_Rest_Security::error_response($mapped[0], $mapped[1]);
                $response->set_headers($headers);
            }
        }
        foreach (EVC_Rest_Security::security_headers() as $name => $value) {
            $response->header($name, $value);
        }
        return $response;
    }

    /** CORS response headers to drop for this request (empty outside the namespace). */
    public static function cors_headers_to_strip($request): array {
        return ($request instanceof WP_REST_Request && self::is_customer_route($request->get_route())) ? EVC_Rest_Security::CORS_HEADERS : array();
    }

    public static function strip_cors($served, $result, $request, $server) {
        foreach (self::cors_headers_to_strip($request) as $name) {
            if (!headers_sent()) {
                header_remove($name);
            }
        }
        return $served;
    }
}
