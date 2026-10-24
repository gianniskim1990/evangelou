<?php
defined('ABSPATH') || exit;
/**
 * Server-side feature flag for the CUSTOMER surface (STAGE-01): the
 * /club-member/ page and GET evangelou-club-customer/v1/me.
 *
 * Enabled ONLY when wp-config.php (trusted server configuration) contains
 *     define('EVC_CLUB_CUSTOMER_ENABLED', true);
 * with the boolean literal true. Anything else means DISABLED. There is no
 * filter, option or request parameter that can enable it, and it is
 * independent of EVC_CLUB_STAFF_ENABLED (neither turns the other on).
 */
final class EVC_Customer_Feature {
    const CONSTANT = 'EVC_CLUB_CUSTOMER_ENABLED';

    public static function enabled(): bool {
        return defined(self::CONSTANT) && self::is_enabled_value(constant(self::CONSTANT));
    }

    /** Pure parser: only the boolean true enables the feature. */
    public static function is_enabled_value($value): bool {
        return $value === true;
    }
}
