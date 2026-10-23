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
 * and GET /me. Its membership source is, by default, the ALWAYS-unavailable
 * adapter (fail closed: 503 "unavailable", never a guessed membership).
 * STAGE-02: only when wp-config.php ALSO configures the commerce source
 * (EVC_Commerce_Source_Config, off by default), /me reads the real
 * WooCommerce + PMPro facts through the read-only reader and the existing
 * mapper. Confirmed payments still come only from the Club's own provenance
 * record, which does not exist yet, so nobody is reported as active.
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
        (new EVC_Rest_Me_Controller(self::membership_adapter(), new EVC_System_Clock()))->register_routes();
    }

    /** The configured real source, or the always-unavailable one (the default). */
    public static function membership_adapter(): EVC_Membership_Adapter {
        $config = EVC_Commerce_Source_Config::from_constants();
        if ($config === null) {
            return new EVC_Unavailable_Membership_Adapter();
        }
        return new EVC_Pmpro_Membership_Adapter(new EVC_Wp_Commerce_Pmpro_Reader($config, new EVC_Unrecorded_Club_Payment_Provenance()), $config->mapper());
    }

    public static function deactivate(): void {
        EVC_Customer_Shell::deactivate();
    }
}
