<?php
defined('ABSPATH') || exit;

/**
 * Smallest staff-session surface the /club-admin/ app needs (flag-gated):
 *
 *  GET  /evangelou-club/v1/session      status: authenticated, absolute expiry,
 *                                       policy timeouts, feature availability.
 *  POST /evangelou-club/v1/session/end  destroys THIS session server-side and
 *                                       clears the auth cookie (logout / lock).
 *
 * Both require the cookie session + X-WP-Nonce + the restricted Club account
 * + session policy, but never refresh the inactivity window, so a tablet
 * cannot keep itself alive by checking its status. Neither returns any
 * customer or personal data. There is NO unauthenticated nonce endpoint:
 * the nonce reaches the app only via the protected shell, and core refreshes
 * it through the X-WP-Nonce response header on valid cookie requests.
 */
final class EVC_Rest_Session_Controller {
    public function register_routes(bool $override = false): void {
        register_rest_route(EVC_Rest_Security::NAMESPACE_V1, '/session', array(
            'methods' => 'GET',
            'callback' => array($this, 'status'),
            'permission_callback' => array($this, 'permission'),
        ), $override);
        register_rest_route(EVC_Rest_Security::NAMESPACE_V1, '/session/end', array(
            'methods' => 'POST',
            'callback' => array($this, 'end'),
            'permission_callback' => array($this, 'permission'),
        ), $override);
    }

    public function permission(WP_REST_Request $request) {
        return EVC_Staff_Auth::authorize_session_request($request);
    }

    public function status(WP_REST_Request $request) {
        $auth = EVC_Staff_Auth::authorize_session_request($request);
        if (is_wp_error($auth)) {
            return $auth;
        }
        $expiry = EVC_Staff_Session::current_expiry(get_current_user_id());
        if ($expiry === null) {
            return EVC_Rest_Security::error_response('unauthorized', 401);
        }
        return new WP_REST_Response(array(
            'authenticated' => true,
            'session' => array(
                'expires_at' => EVC_Rest_Redeem_Controller::rfc3339(gmdate('Y-m-d H:i:s', $expiry)),
                'absolute_lifetime_seconds' => EVC_Staff_Session::ABSOLUTE_SECONDS,
                'idle_timeout_seconds' => EVC_Staff_Session::IDLE_SECONDS,
                'idle_lock_seconds' => EVC_Staff_Shell::IDLE_LOCK_SECONDS,
            ),
            'features' => array(
                'qr_lookup' => false,
                'phone_lookup' => false,
                'coffee_redemption' => false,
                'history' => false,
            ),
        ), 200);
    }

    public function end(WP_REST_Request $request) {
        $auth = EVC_Staff_Auth::authorize_session_request($request);
        if (is_wp_error($auth)) {
            return $auth;
        }
        EVC_Staff_Session::revoke_current();
        wp_clear_auth_cookie();
        return new WP_REST_Response(array('ended' => true), 200);
    }
}
