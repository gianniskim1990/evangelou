<?php
defined('ABSPATH') || exit;

/**
 * Server-side authorization for every protected Club REST route. The React
 * UI is never the authority. A request is authorized only if ALL hold:
 *
 *  1. the staff feature flag is enabled (trusted server config);
 *  2. a WordPress user is authenticated via the COOKIE session (core sets
 *     the user to 0 when the wp_rest nonce is missing; Application Passwords
 *     are refused);
 *  3. the X-WP-Nonce header carries a valid wp_rest nonce (checked again
 *     here, independently of core);
 *  4. the user has the restricted Club role and the required capability;
 *  5. the shared account is not disabled;
 *  6. the session passes the 12 h absolute / 30 min inactivity policy.
 *
 * The staff identity used downstream is ALWAYS get_current_user_id().
 */
final class EVC_Staff_Auth {
    const DISABLED_META = 'evc_staff_disabled';

    /**
     * Protected Club REST route requiring $capability. Counts as staff
     * activity (refreshes the 30 min inactivity window).
     * @return true|WP_Error
     */
    public static function authorize(WP_REST_Request $request, string $capability) {
        if (!in_array($capability, EVC_Staff_Role::staff_capabilities(), true)) {
            return EVC_Staff_Feature::enabled() ? self::forbidden() : self::feature_disabled();
        }
        return self::authorize_rest($request, $capability, true);
    }

    /**
     * Staff session endpoints (status / end): same account, nonce and session
     * checks but NO capability beyond being the restricted account, and NO
     * activity refresh, so status checks can never keep an idle tablet alive.
     * @return true|WP_Error
     */
    public static function authorize_session_request(WP_REST_Request $request) {
        return self::authorize_rest($request, null, false);
    }

    /** @return true|WP_Error */
    private static function authorize_rest(WP_REST_Request $request, ?string $capability, bool $touch) {
        if (!EVC_Staff_Feature::enabled()) {
            return self::feature_disabled();
        }
        $user = wp_get_current_user();
        if (!$user || !$user->exists()) {
            return self::unauthorized();
        }
        if (did_action('application_password_did_authenticate') > 0) {
            return self::unauthorized();
        }
        $nonce = $request->get_header('X-WP-Nonce');
        if (!is_string($nonce) || $nonce === '' || !wp_verify_nonce($nonce, 'wp_rest')) {
            return self::unauthorized();
        }
        $error = self::check_account($user, $capability, $touch);
        return $error === null ? true : $error;
    }

    /**
     * Account + session policy shared by the REST routes and the /club-admin/
     * HTML shell (a top-level navigation carries no X-WP-Nonce, so the shell
     * relies on the cookie session plus exactly these checks).
     *
     * @return WP_Error|null null when the user is the restricted, enabled
     *   Club account with a valid 12 h / 30 min session.
     */
    public static function check_account(WP_User $user, ?string $capability, bool $touch) {
        if (!EVC_Staff_Role::is_restricted_staff_account($user) || ($capability !== null && !user_can($user, $capability))) {
            return self::forbidden();
        }
        if (self::is_disabled((int) $user->ID)) {
            return self::forbidden();
        }
        if (EVC_Staff_Session::verify_current((int) $user->ID, null, $touch) !== EVC_Staff_Session::OK) {
            return self::unauthorized();
        }
        return null;
    }

    private static function feature_disabled(): WP_Error {
        return new WP_Error('evc_feature_disabled', 'Not available.', array('status' => 404));
    }

    public static function is_disabled(int $user_id): bool {
        return get_user_meta($user_id, self::DISABLED_META, true) === '1';
    }

    /**
     * Club-manager-only: disable/enable the shared Club staff account.
     * Disabling also destroys all of its sessions.
     *
     * The target must be an existing user that carries the Club staff role
     * (the same population emergency revocation acts on, so a misconfigured
     * staff-tagged account can still be locked down). Administrators,
     * editors, subscribers, WooCommerce customers and any other user WITHOUT
     * the staff role are refused with a WP_Error, and on every refusal no
     * user meta and no session is touched.
     *
     * @return true|WP_Error
     */
    public static function set_disabled(int $user_id, bool $disabled) {
        if (!current_user_can(EVC_Staff_Role::CAP_MANAGE)) {
            return self::forbidden();
        }
        $target = $user_id > 0 ? get_userdata($user_id) : false;
        if (!($target instanceof WP_User) || !EVC_Staff_Role::has_staff_role($target)) {
            return new WP_Error('evc_invalid_target', 'Not a Club staff account.', array('status' => 400));
        }
        if ($disabled) {
            update_user_meta($user_id, self::DISABLED_META, '1');
            WP_Session_Tokens::get_instance($user_id)->destroy_all();
            EVC_Staff_Session::delete_all_activity($user_id);
        } else {
            delete_user_meta($user_id, self::DISABLED_META);
        }
        return true;
    }

    private static function unauthorized(): WP_Error {
        return new WP_Error('unauthorized', 'Unauthorized.', array('status' => 401));
    }

    private static function forbidden(): WP_Error {
        return new WP_Error('forbidden', 'Forbidden.', array('status' => 403));
    }
}
