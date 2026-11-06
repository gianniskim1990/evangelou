<?php
defined('ABSPATH') || exit;
/**
 * STAGE-07 opt-in constant: the in-store QR onboarding (public join page, tablet poster, join REST routes).
 *
 * Enabled ONLY when wp-config.php contains
 *     define('EVC_CLUB_ONBOARDING_ENABLED', true);
 * with the boolean literal true, AND the customer surface is on (EVC_CLUB_CUSTOMER_ENABLED === true: the join
 * flow ends in the member area). Default: OFF — then nothing of it is hooked, registered or constructed: no
 * rewrite rule, no query variable, no route, no page (the site is unchanged; /club/join is a normal 404).
 */
final class EVC_Onboarding_Feature {
    const CONSTANT = 'EVC_CLUB_ONBOARDING_ENABLED';

    public static function enabled(): bool {
        return defined(self::CONSTANT) && self::is_enabled_value(constant(self::CONSTANT)) && EVC_Customer_Feature::enabled();
    }

    /** Pure parser: only the boolean true enables it. */
    public static function is_enabled_value($value): bool {
        return $value === true;
    }

    /** The classes of this path refuse to be constructed while it is off (second layer behind the packager). */
    public static function assert_constructible(string $class): void {
        if (!self::enabled()) {
            throw new LogicException($class . ' exists only behind ' . self::CONSTANT . '.');
        }
    }
}
