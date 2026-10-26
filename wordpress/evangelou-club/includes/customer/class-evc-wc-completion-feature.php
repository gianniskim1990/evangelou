<?php
defined('ABSPATH') || exit;
/**
 * STAGE-03D opt-in constant: «Ολοκληρωμένη» (Completed) in WooCommerce by shop
 * staff counts as the Club payment confirmation (EVC_Wc_Completion_Confirmer),
 * cancellation / refund freezes the card, and the recovery step runs on WP-Cron.
 *
 * Enabled ONLY when wp-config.php contains
 *     define('EVC_CLUB_WC_COMPLETION_ENABLED', true);
 * with the boolean literal true. Default: OFF — then nothing of it is hooked,
 * scheduled or constructed at load. Independent of every other Club flag.
 */
final class EVC_Wc_Completion_Feature {
    const CONSTANT = 'EVC_CLUB_WC_COMPLETION_ENABLED';

    public static function enabled(): bool {
        return defined(self::CONSTANT) && self::is_enabled_value(constant(self::CONSTANT));
    }

    /** Pure parser: only the boolean true enables it. */
    public static function is_enabled_value($value): bool {
        return $value === true;
    }
}
