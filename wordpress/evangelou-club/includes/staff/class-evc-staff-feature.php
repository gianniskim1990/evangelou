<?php
defined('ABSPATH') || exit;

/**
 * Server-side feature flag for the Club staff REST surface.
 *
 * Enabled ONLY when wp-config.php (trusted server configuration) contains
 *     define('EVC_CLUB_STAFF_ENABLED', true);
 * with the boolean literal true. Undefined, null, 0, 1, "1", "true", "yes" or
 * any other value means DISABLED. There is deliberately no filter, option or
 * request parameter that can enable it.
 */
final class EVC_Staff_Feature {
    const CONSTANT = 'EVC_CLUB_STAFF_ENABLED';

    public static function enabled(): bool {
        return defined(self::CONSTANT) && self::is_enabled_value(constant(self::CONSTANT));
    }

    /** Pure parser: only the boolean true enables the feature. */
    public static function is_enabled_value($value): bool {
        return $value === true;
    }
}
