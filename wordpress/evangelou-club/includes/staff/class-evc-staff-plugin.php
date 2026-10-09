<?php
defined('ABSPATH') || exit;

/**
 * Wiring for the staff authentication foundation (Task 1C-C).
 *
 * Always active while the plugin is active, but scoped to users holding
 * the evc_club_staff role (no effect on anyone else):
 *   12 h / 30 min session policy, admin-area guard, login throttling,
 *   administrator-only emergency revocation (admin-post).
 * Only when EVC_CLUB_STAFF_ENABLED === true:
 *   Club REST hardening + the redeem route (with the always-unavailable
 *   production backend, i.e. fail-closed 503).
 */
final class EVC_Staff_Plugin {
    public static function boot(): void {
        EVC_Staff_Session::register_hooks();
        EVC_Staff_Admin_Guard::register_hooks();
        EVC_Login_Throttle::register_hooks();
        if (!EVC_Staff_Feature::enabled()) {
            return;
        }
        EVC_Rest_Security::register_hooks();
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
    }

    public static function register_routes(): void {
        if (!EVC_Staff_Feature::enabled()) {
            return;
        }
        (new EVC_Rest_Redeem_Controller(new EVC_Unavailable_Redemption_Backend()))->register_routes();
    }

    public static function activate(): void {
        EVC_Staff_Role::register();
    }

    public static function deactivate(): void {
        EVC_Staff_Role::unregister();
    }
}
