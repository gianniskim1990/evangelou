<?php
defined('ABSPATH') || exit;

/**
 * Wiring for the staff foundation (Tasks 1C-C / 1C-D).
 *
 * Always active while the plugin is active, but scoped to users holding
 * the evc_club_staff role (no effect on anyone else):
 *   12 h / 30 min session policy, admin-area guard, login throttling,
 *   administrator-only emergency revocation (admin-post).
 * /club-admin/ shell hooks are registered always so the rewrite rule is
 * removed again when the flag is switched off; the query var, the rule and
 * the page itself exist only while the flag is on.
 * Only when EVC_CLUB_STAFF_ENABLED === true:
 *   Club REST hardening, the session status/end routes and the redeem route.
 *   The redeem route's backend is the always-unavailable one (fail-closed 503)
 *   unless wp-config.php ALSO defines EVC_CLUB_REDEMPTION_SOURCE === 'club_db_v1'
 *   (STAGE-05A, default OFF): then EVC_Club_Redemption_Backend, which still
 *   answers 503 until every other piece is configured and builds nothing
 *   before a redeem request arrives. register_routes() is the ONLY place that
 *   constructs it (packager-enforced).
 */
final class EVC_Staff_Plugin {
    public static function boot(): void {
        EVC_Staff_Session::register_hooks();
        EVC_Staff_Admin_Guard::register_hooks();
        EVC_Login_Throttle::register_hooks();
        EVC_Staff_Shell::register_hooks();
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
        $backend = EVC_Club_Redemption_Feature::enabled() ? new EVC_Club_Redemption_Backend(new EVC_Approved_Coffee_Catalogue_Source()) : new EVC_Unavailable_Redemption_Backend();
        (new EVC_Rest_Redeem_Controller($backend))->register_routes();
        (new EVC_Rest_Session_Controller())->register_routes();
        // STAGE-05C: QR scan + administrator QR revocation exist ONLY with the same opt-in (packager-enforced).
        if (EVC_Club_Redemption_Feature::enabled()) {
            (new EVC_Rest_Lookup_Controller(new EVC_Club_Lookup_Backend()))->register_routes();
        }
    }

    public static function activate(): void {
        EVC_Staff_Role::register();
    }

    public static function deactivate(): void {
        EVC_Staff_Role::unregister();
        EVC_Staff_Shell::deactivate();
    }
}
