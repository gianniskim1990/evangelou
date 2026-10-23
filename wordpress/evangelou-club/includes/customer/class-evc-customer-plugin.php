<?php
defined('ABSPATH') || exit;
/**
 * Wiring for the customer surface (STAGE-01).
 *
 * The /club-member/ page hooks are registered always so the rewrite rule is
 * removed again when the flag is switched off; the query var, the rule and
 * the page itself exist only while the flag is on.
 *
 * Only when EVC_CLUB_CUSTOMER_ENABLED === true: the customer REST hardening
 * and GET /me, wired to the ALWAYS-unavailable production membership source
 * (fail closed: 503 "unavailable", never a guessed membership).
 */
final class EVC_Customer_Plugin {
    public static function boot(): void {
        EVC_Customer_Shell::register_hooks();
        if (!EVC_Customer_Feature::enabled()) {
            return;
        }
        EVC_Customer_Rest_Security::register_hooks();
        add_action('rest_api_init', array(__CLASS__, 'register_routes'));
    }

    public static function register_routes(): void {
        if (!EVC_Customer_Feature::enabled()) {
            return;
        }
        (new EVC_Rest_Me_Controller(new EVC_Unavailable_Membership_Adapter(), new EVC_System_Clock()))->register_routes();
    }

    public static function deactivate(): void {
        EVC_Customer_Shell::deactivate();
    }
}
