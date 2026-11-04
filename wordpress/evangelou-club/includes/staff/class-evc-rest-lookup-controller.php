<?php
defined('ABSPATH') || exit;
/**
 * STAGE-05C, evangelou-club/v1 (registered only inside `if (EVC_Club_Redemption_Feature::enabled())`
 * in EVC_Staff_Plugin::register_routes(), after the staff gate):
 *
 * POST /members/lookup   the shared Club staff account (EVC_Staff_Auth, capability evc_lookup_member)
 *   body {"method":"qr","qr_token":"evc_<48 hex>"}   (phone lookup is not offered: 400 invalid_request)
 *   200 {"member":{"member_id":"mem_…","first_name":"…"|null},"status":"active"|"used_today"|"inactive"}
 *   400 invalid_qr          not a Club QR at all
 *   404 member_not_found    unknown, replaced, revoked or disabled: ONE answer, never "existed once"
 *   429 rate_limited        ~10 failed scans / 5 minutes on THIS tablet (D9); Retry-After 300
 *   503 server_error        backend unavailable (any missing piece)
 *   The answer grants nothing: the redeem route re-checks the membership when the coffee is recorded.
 *
 * POST /admin/members/qr/revoke   a WordPress user with evc_manage_club (administrators), NEVER a
 *   Club staff-role account; cookie session + valid wp_rest nonce; body {"wp_user_id": <int>}
 *   200 {"revoked":true} | {"revoked":false} (nothing active) · 404 member_not_found · 409 concurrent_change
 *
 * No token, digest, name or id is ever logged. The staff id is ALWAYS get_current_user_id().
 */
final class EVC_Rest_Lookup_Controller {
    const LOOKUP_ROUTE = '/members/lookup';
    const REVOKE_ROUTE = '/admin/members/qr/revoke';
    const RETRY_AFTER_SECONDS = 300;

    /** @var EVC_Club_Lookup_Backend */
    private $backend;

    public function __construct(EVC_Club_Lookup_Backend $backend) {
        $this->backend = $backend;
    }

    public function register_routes(bool $override = false): void {
        register_rest_route(EVC_Rest_Security::NAMESPACE_V1, self::LOOKUP_ROUTE, array(
            'methods' => 'POST',
            'callback' => array($this, 'lookup'),
            'permission_callback' => array($this, 'lookup_permission'),
            'args' => array(),
        ), $override);
        register_rest_route(EVC_Rest_Security::NAMESPACE_V1, self::REVOKE_ROUTE, array(
            'methods' => 'POST',
            'callback' => array($this, 'revoke'),
            'permission_callback' => array(__CLASS__, 'admin_permission'),
            'args' => array(),
        ), $override);
    }

    public function lookup_permission(WP_REST_Request $request) {
        return EVC_Staff_Auth::authorize($request, EVC_Staff_Role::CAP_LOOKUP);
    }

    public function lookup(WP_REST_Request $request) {
        $auth = EVC_Staff_Auth::authorize($request, EVC_Staff_Role::CAP_LOOKUP);
        if (is_wp_error($auth)) {
            return $auth;
        }
        try {
            $body = $request->get_json_params();
            if (!is_array($body) || array_keys($body) !== array('method', 'qr_token') || $body['method'] !== 'qr' || !is_string($body['qr_token'])
                || strlen($body['qr_token']) > 256) {
                return EVC_Rest_Security::error_response('invalid_request', 400);
            }
            $token = wp_get_session_token();
            $actor = new EVC_Staff_Actor(get_current_user_id(), (is_string($token) && $token !== '') ? EVC_Staff_Session::session_ref($token) : null);
            $r = $this->backend->lookup($actor, $body['qr_token']);
            switch ($r['result']) {
                case 'found':
                    return new WP_REST_Response(array(
                        'member' => array('member_id' => $r['member_id'], 'first_name' => $r['first_name']),
                        'status' => $r['status'],
                    ), 200);
                case 'invalid_qr':
                    return EVC_Rest_Security::error_response('invalid_qr', 400);
                case 'not_found':
                    return EVC_Rest_Security::error_response('member_not_found', 404);
                case 'rate_limited':
                    $response = EVC_Rest_Security::error_response('rate_limited', 429, array('retry_after_seconds' => self::RETRY_AFTER_SECONDS));
                    $response->header('Retry-After', (string) self::RETRY_AFTER_SECONDS);
                    return $response;
                case 'forbidden':
                    return EVC_Rest_Security::error_response('forbidden', 403);
                default:
                    return EVC_Rest_Security::error_response('server_error', 503);
            }
        } catch (Throwable $e) {
            return EVC_Rest_Security::error_response('server_error', 500);
        }
    }

    /**
     * Administrators only: evc_manage_club, a cookie session (no Application Password), a valid
     * wp_rest nonce, and never an account carrying the Club staff role.
     *
     * @return true|WP_Error
     */
    public static function admin_permission(WP_REST_Request $request) {
        if (!EVC_Staff_Feature::enabled() || !EVC_Club_Redemption_Feature::enabled()) {
            return new WP_Error('evc_feature_disabled', 'Not available.', array('status' => 404));
        }
        $user = wp_get_current_user();
        if (!$user || !$user->exists() || did_action('application_password_did_authenticate') > 0) {
            return new WP_Error('unauthorized', 'Unauthorized.', array('status' => 401));
        }
        $nonce = $request->get_header('X-WP-Nonce');
        if (!is_string($nonce) || $nonce === '' || !wp_verify_nonce($nonce, 'wp_rest')) {
            return new WP_Error('unauthorized', 'Unauthorized.', array('status' => 401));
        }
        if (EVC_Staff_Role::has_staff_role($user) || !user_can($user, EVC_Staff_Role::CAP_MANAGE)) {
            return new WP_Error('forbidden', 'Forbidden.', array('status' => 403));
        }
        return true;
    }

    public function revoke(WP_REST_Request $request) {
        $auth = self::admin_permission($request);
        if (is_wp_error($auth)) {
            return $auth;
        }
        try {
            $body = $request->get_json_params();
            if (!is_array($body) || array_keys($body) !== array('wp_user_id') || !is_int($body['wp_user_id']) || $body['wp_user_id'] < 1) {
                return EVC_Rest_Security::error_response('invalid_request', 400);
            }
            $r = $this->backend->revoke_for_wp_user(new EVC_Staff_Actor(get_current_user_id()), $body['wp_user_id']);
            switch ($r['result']) {
                case 'revoked':
                    return new WP_REST_Response(array('revoked' => true), 200);
                case 'nothing_to_revoke':
                    return new WP_REST_Response(array('revoked' => false), 200);
                case 'not_a_member':
                    return EVC_Rest_Security::error_response('member_not_found', 404);
                case 'forbidden':
                    return EVC_Rest_Security::error_response('forbidden', 403);
                case 'concurrent_change':
                    return EVC_Rest_Security::error_response('concurrent_change', 409);
                default:
                    return EVC_Rest_Security::error_response('server_error', 503);
            }
        } catch (Throwable $e) {
            return EVC_Rest_Security::error_response('server_error', 500);
        }
    }
}
