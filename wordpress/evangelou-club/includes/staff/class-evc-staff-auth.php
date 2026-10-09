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

    /** @return true|WP_Error */
    public static function authorize(WP_REST_Request $request, string $capability) {
        if (!EVC_Staff_Feature::enabled()) {
            return new WP_Error('evc_feature_disabled', 'Not available.', array('status' => 404));
        }
        if (!in_array($capability, EVC_Staff_Role::staff_capabilities(), true)) {
            return self::forbidden();
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
        if (!EVC_Staff_Role::is_staff_user($user) || !user_can($user, $capability)) {
            return self::forbidden();
        }
        if (self::is_disabled((int) $user->ID)) {
            return self::forbidden();
        }
        if (EVC_Staff_Session::verify_current((int) $user->ID) !== EVC_Staff_Session::OK) {
            return self::unauthorized();
        }
        return true;
    }

    public static function is_disabled(int $user_id): bool {
        return get_user_meta($user_id, self::DISABLED_META, true) === '1';
    }

    /**
     * Administrator-only: disable/enable the shared account. Disabling also
     * destroys all of its sessions.
     * @return true|WP_Error
     */
    public static function set_disabled(int $user_id, bool $disabled) {
        if (!current_user_can(EVC_Staff_Role::CAP_MANAGE)) {
            return self::forbidden();
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
