<?php
defined('ABSPATH') || exit;
/**
 * STAGE-07 join routes (namespace evangelou-club-customer/v1, contract "join-v1"); registered only by
 * EVC_Onboarding_Plugin behind EVC_CLUB_ONBOARDING_ENABLED (+ the customer flag).
 *
 *   POST /join/account   PUBLIC (signed out). CSRF: X-WP-Nonce must be the page's wp_rest nonce for the signed-out
 *                        visitor AND an Origin header, when sent, must be this site. 200 {"result": ok | invalid
 *                        (+fields) | check_account | not_completed | signed_in | try_later}, 429 rate_limited,
 *                        403 forbidden (nonce / origin; neither is counted as a failure), 503 {"result":"unavailable"}.
 *   POST /join/terms     customer (EVC_Customer_Auth): {"accept": true} records the current Club terms.
 *   POST /join/checkout  customer: {"result":"ok","next":"member"|"checkout","url":…} or {"result":"terms_required"};
 *                        the URL is always built by the server (member area or the WooCommerce checkout), never
 *                        taken from the request: no open redirect.
 * No route reads a user id, a redirect target or anything about payments from the request.
 */
final class EVC_Rest_Join_Controller {
    const BASE = '/join';

    /** @var EVC_Onboarding_Registration */
    private $registration;
    /** @var EVC_Onboarding_Handoff */
    private $handoff;

    public function __construct(EVC_Onboarding_Registration $registration, EVC_Onboarding_Handoff $handoff) {
        EVC_Onboarding_Feature::assert_constructible(__CLASS__);
        $this->registration = $registration;
        $this->handoff = $handoff;
    }

    /** $override: TEST seam (a test that wires the parts again replaces the earlier registration). */
    public function register_routes(bool $override = false): void {
        register_rest_route(EVC_Customer_Rest_Security::NAMESPACE_V1, self::BASE . '/account', array(
            'methods' => 'POST',
            'callback' => array($this, 'account'),
            'permission_callback' => '__return_true', // public by design; the callback checks nonce + origin itself
            'args' => array(),
        ), $override);
        register_rest_route(EVC_Customer_Rest_Security::NAMESPACE_V1, self::BASE . '/terms', array(
            'methods' => 'POST',
            'callback' => array($this, 'terms'),
            'permission_callback' => array('EVC_Customer_Auth', 'authorize'),
            'args' => array(),
        ), $override);
        register_rest_route(EVC_Customer_Rest_Security::NAMESPACE_V1, self::BASE . '/checkout', array(
            'methods' => 'POST',
            'callback' => array($this, 'checkout'),
            'permission_callback' => array('EVC_Customer_Auth', 'authorize'),
            'args' => array(),
        ), $override);
    }

    /** @return WP_REST_Response */
    public function account(WP_REST_Request $request) {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        if (!self::anonymous_nonce_ok($request)) {
            // No (or no valid) page nonce: refused, and NOT counted (anyone could otherwise spend a client's budget).
            return EVC_Rest_Security::error_response('forbidden', 403);
        }
        if (!self::same_origin($request)) {
            // Round 3 (R4): NOT counted either; a foreign page could otherwise spend a client's failure budget.
            return EVC_Rest_Security::error_response('forbidden', 403);
        }
        $body = $request->get_json_params();
        $r = $this->registration->register(is_array($body) ? $body : array(), $ip);
        switch ($r['outcome']) {
            case EVC_Onboarding_Registration::RATE_LIMITED:
                return EVC_Rest_Security::error_response('rate_limited', 429);
            case EVC_Onboarding_Registration::UNAVAILABLE:
                return new WP_REST_Response(array('result' => 'unavailable'), 503);
            case EVC_Onboarding_Registration::INVALID:
                return new WP_REST_Response(array('result' => 'invalid', 'fields' => $r['fields'] ?? array()), 200);
            default:
                return new WP_REST_Response(array('result' => $r['outcome']), 200);
        }
    }

    /** @return WP_REST_Response */
    public function terms(WP_REST_Request $request) {
        $body = $request->get_json_params();
        if (!is_array($body) || ($body['accept'] ?? null) !== true) {
            return new WP_REST_Response(array('result' => 'invalid'), 200);
        }
        return EVC_Onboarding_Handoff::accept_terms(get_current_user_id())
            ? new WP_REST_Response(array('result' => 'ok'), 200)
            : new WP_REST_Response(array('result' => 'unavailable'), 503);
    }

    /** @return WP_REST_Response */
    public function checkout(WP_REST_Request $request) {
        $next = $this->handoff->next_step(get_current_user_id());
        if ($next === EVC_Onboarding_Handoff::NEXT_TERMS) {
            return new WP_REST_Response(array('result' => 'terms_required'), 200);
        }
        if ($next === EVC_Onboarding_Handoff::NEXT_MEMBER) {
            return new WP_REST_Response(array('result' => 'ok', 'next' => 'member', 'url' => EVC_Customer_Shell::shell_url()), 200);
        }
        if ($next === EVC_Onboarding_Handoff::NEXT_CHECKOUT) {
            return new WP_REST_Response(array('result' => 'ok', 'next' => 'checkout', 'url' => $this->handoff->checkout_url()), 200);
        }
        return new WP_REST_Response(array('result' => 'unavailable'), 503);
    }

    /** An Origin header, when the browser sends one, must be exactly this site's origin. */
    public static function same_origin(WP_REST_Request $request): bool {
        $origin = $request->get_header('origin');
        if ($origin === null || $origin === '') {
            return true; // same-origin navigations of older browsers; the nonce below still binds the request
        }
        return strtolower(rtrim($origin, '/')) === strtolower(self::site_origin());
    }

    public static function site_origin(): string {
        $parts = wp_parse_url(home_url('/'));
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return '';
        }
        return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    /**
     * The page's wp_rest nonce. WordPress core already refuses a PRESENT but invalid nonce before the route runs (for
     * signed-out visitors too); a MISSING nonce reaches here and is refused by this check.
     */
    private static function anonymous_nonce_ok(WP_REST_Request $request): bool {
        $nonce = $request->get_header('X-WP-Nonce');
        return is_string($nonce) && $nonce !== '' && wp_verify_nonce($nonce, 'wp_rest') !== false;
    }
}
