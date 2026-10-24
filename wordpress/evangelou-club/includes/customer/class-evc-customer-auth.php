<?php
defined('ABSPATH') || exit;
/**
 * Server-side authorization for the customer REST surface (STAGE-01).
 * Native WordPress authentication only: the customer signs in with e-mail
 * (or username) and password on wp-login.php; no Club password store, no
 * alternative login. A request is authorized only if ALL hold:
 *
 *  1. the customer feature flag is enabled (trusted server config);
 *  2. a WordPress user is authenticated via the COOKIE session (core sets
 *     the user to 0 when the wp_rest nonce is missing; Application Passwords
 *     are refused);
 *  3. the X-WP-Nonce header carries a valid wp_rest nonce (checked again
 *     here, independently of core);
 *  4. the account is a CUSTOMER account: not the Club staff account (or any
 *     staff-tagged user), not a super admin, and without editorial,
 *     administrative, shop-management or Club capabilities.
 *
 * The customer identity used downstream is ALWAYS get_current_user_id();
 * no user id is ever read from the request.
 */
final class EVC_Customer_Auth {
    /** Any of these makes an account privileged, never a customer of this surface. */
    const PRIVILEGED_CAPABILITIES = array('manage_options', 'edit_posts', 'list_users', 'promote_users', 'manage_woocommerce', 'view_woocommerce_reports', 'pmpro_memberships_menu');

    /** @return true|WP_Error */
    public static function authorize(WP_REST_Request $request) {
        if (!EVC_Customer_Feature::enabled()) {
            return new WP_Error('evc_feature_disabled', 'Not available.', array('status' => 404));
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
        $error = self::check_customer($user);
        return $error === null ? true : $error;
    }

    /**
     * Account check shared by the REST route and the /club-member/ page (a
     * top-level navigation carries no X-WP-Nonce; the page relies on the
     * cookie session plus exactly this check).
     *
     * @return WP_Error|null null for a customer account
     */
    public static function check_customer(WP_User $user) {
        if (!$user->exists() || EVC_Staff_Role::has_staff_role($user) || is_super_admin((int) $user->ID)) {
            return self::forbidden();
        }
        $club_caps = array_merge(EVC_Staff_Role::staff_capabilities(), array(EVC_Staff_Role::CAP_MANAGE));
        foreach (array_merge(self::PRIVILEGED_CAPABILITIES, $club_caps) as $capability) {
            if (user_can($user, $capability)) {
                return self::forbidden();
            }
        }
        return null;
    }

    private static function unauthorized(): WP_Error {
        return new WP_Error('unauthorized', 'Unauthorized.', array('status' => 401));
    }

    private static function forbidden(): WP_Error {
        return new WP_Error('forbidden', 'Forbidden.', array('status' => 403));
    }
}
