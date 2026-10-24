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
        return new EVC_Pmpro_Membership_Adapter(new EVC_Wp_Commerce_Pmpro_Reader($config, self::payment_provenance($config)), $config->mapper());
    }

    /**
     * STAGE-03: the Club's recorded payments are read from the Club database ONLY when
     * wp-config.php also defines EVC_CLUB_PAYMENT_PROVENANCE === 'club_db_v1' AND the Club
     * database (EVC_CLUB_DB_*) — whose migration 002 needs separate owner approval. Otherwise
     * no payment is recorded and nobody is active (the STAGE-02 default).
     */
    public static function payment_provenance(EVC_Commerce_Source_Config $config): EVC_Club_Payment_Provenance {
        $db = EVC_Db_Config::from_constants();
        if (!defined('EVC_CLUB_PAYMENT_PROVENANCE') || constant('EVC_CLUB_PAYMENT_PROVENANCE') !== 'club_db_v1' || $db === null) {
            return new EVC_Unrecorded_Club_Payment_Provenance();
        }
        return new EVC_Store_Club_Payment_Provenance(function () use ($db) {
            return new EVC_Sql_Provenance_Store(EVC_Club_Db::connect($db), function () use ($db) {
                return EVC_Club_Db::connect($db);
            }, EVC_Bacs_Payment_Confirmation::ENVIRONMENT);
        }, $config);
    }

    public static function deactivate(): void {
        EVC_Customer_Shell::deactivate();
    }
}
