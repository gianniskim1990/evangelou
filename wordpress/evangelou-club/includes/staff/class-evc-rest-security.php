<?php
defined('ABSPATH') || exit;

/**
 * Namespace-scoped REST hardening for evangelou-club/v1. Installed ONLY when
 * the staff feature flag is enabled, and every callback returns early for
 * routes outside the namespace, so core/WooCommerce/PMPro/FluentCRM REST
 * routes are unaffected.
 *
 * - Errors (ours and core's {code,message,data.status}) become the v1
 *   envelope {error:{code,message[,details]}} with client-safe Greek text;
 *   core messages, SQL text and exception messages are never passed on.
 * - Security headers: no-store caching, nosniff, no-referrer, DENY framing,
 *   a JSON-only CSP.
 * - CORS: WordPress core reflects the request Origin with credentials on
 *   REST responses; for this same-origin-only API those headers are
 *   removed (rest_pre_serve_request, after core added them).
 */
final class EVC_Rest_Security {
    const NAMESPACE_V1 = 'evangelou-club/v1';

    /** CSP for the FUTURE plugin-served /club-admin/ HTML shell (not used yet). */
    const SHELL_CSP = "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self'; connect-src 'self'; media-src 'self'; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'";

    const API_CSP = "default-src 'none'; frame-ancestors 'none'; base-uri 'none'";

    const CORS_HEADERS = array(
        'Access-Control-Allow-Origin',
        'Access-Control-Allow-Credentials',
        'Access-Control-Allow-Methods',
        'Access-Control-Allow-Headers',
        'Access-Control-Expose-Headers',
    );

    const MESSAGES = array(
        'invalid_request' => 'Μη έγκυρο αίτημα.',
        'invalid_coffee' => 'Ο επιλεγμένος καφές δεν είναι διαθέσιμος.',
        'member_not_found' => 'Δεν βρέθηκε μέλος.',
        'membership_inactive' => 'Η συνδρομή δεν είναι ενεργή.',
        'benefit_already_redeemed' => 'Η σημερινή παροχή έχει ήδη χρησιμοποιηθεί.',
        'idempotency_key_reused' => 'Το αίτημα δεν ήταν δυνατό να επαληθευτεί.',
        'unauthorized' => 'Απαιτείται σύνδεση.',
        'forbidden' => 'Δεν επιτρέπεται.',
        'rate_limited' => 'Πολλά αιτήματα. Δοκιμάστε αργότερα.',
        'server_error' => 'Προσωρινό σφάλμα. Δοκιμάστε ξανά.',
    );

    public static function register_hooks(): void {
        add_filter('rest_post_dispatch', array(__CLASS__, 'filter_post_dispatch'), 99, 3);
        add_filter('rest_pre_serve_request', array(__CLASS__, 'strip_cors'), 11, 4);
    }

    public static function is_club_route($route): bool {
        $route = (string) $route;
        $base = '/' . self::NAMESPACE_V1;
        return $route === $base || strpos($route, $base . '/') === 0;
    }

    /** v1 error envelope response. */
    public static function error_response(string $code, int $status, ?array $details = null): WP_REST_Response {
        $error = array('code' => $code, 'message' => isset(self::MESSAGES[$code]) ? self::MESSAGES[$code] : self::MESSAGES['server_error']);
        if ($details) {
            $error['details'] = $details;
        }
        return new WP_REST_Response(array('error' => $error), $status);
    }

    /**
     * Maps any non-envelope error inside the namespace (core auth/nonce/param
     * errors, permission_callback WP_Errors) to the v1 envelope. 404/405
     * routing errors keep core's shape (the client treats them as
     * invalid_response, never as a business answer).
     */
    public static function filter_post_dispatch($result, $server, $request) {
        if (!($request instanceof WP_REST_Request) || !self::is_club_route($request->get_route())) {
            return $result;
        }
        $response = rest_ensure_response($result);
        $status = (int) $response->get_status();
        $data = $response->get_data();
        if ($status >= 400 && !(is_array($data) && isset($data['error']))) {
            $code = (is_array($data) && isset($data['code']) && is_string($data['code'])) ? $data['code'] : '';
            $mapped = self::map_core_error($code, $status);
            if ($mapped !== null) {
                $headers = $response->get_headers();
                $response = self::error_response($mapped[0], $mapped[1]);
                $response->set_headers($headers);
            }
        }
        foreach (self::security_headers() as $name => $value) {
            $response->header($name, $value);
        }
        return $response;
    }

    /** @return array{0:string,1:int}|null [code, status] or null to keep core's shape. */
    public static function map_core_error(string $code, int $status): ?array {
        if (isset(self::MESSAGES[$code])) {
            return array($code, $status);
        }
        if ($code === 'rest_cookie_invalid_nonce') {
            return array('unauthorized', 401);
        }
        if ($status === 401) {
            return array('unauthorized', 401);
        }
        if ($status === 403) {
            return array('forbidden', 403);
        }
        if ($status === 400) {
            return array('invalid_request', 400);
        }
        if ($status === 429) {
            return array('rate_limited', 429);
        }
        if ($status >= 500) {
            return array('server_error', 500);
        }
        return null;
    }

    /** @return array<string,string> */
    public static function security_headers(): array {
        return array(
            'Cache-Control' => 'no-store, private, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
            'X-Frame-Options' => 'DENY',
            'Content-Security-Policy' => self::API_CSP,
        );
    }

    /** CORS response headers to drop for this request (empty outside the namespace). */
    public static function cors_headers_to_strip($request): array {
        return ($request instanceof WP_REST_Request && self::is_club_route($request->get_route())) ? self::CORS_HEADERS : array();
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
