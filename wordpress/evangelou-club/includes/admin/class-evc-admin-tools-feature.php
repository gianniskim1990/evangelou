<?php
defined('ABSPATH') || exit;
/**
 * STAGE-08 opt-in constant: the Club administration screens (the shared till account, the read-only
 * «Ετοιμότητα» readiness page).
 *
 * Enabled ONLY when wp-config.php contains
 *     define('EVC_CLUB_ADMIN_TOOLS_ENABLED', true);
 * with the boolean literal true. Default: OFF — then nothing of it is hooked, registered or constructed
 * (no menu, no admin action, no login hook). Independent of every other Club flag.
 */
final class EVC_Admin_Tools_Feature {
    const CONSTANT = 'EVC_CLUB_ADMIN_TOOLS_ENABLED';

    public static function enabled(): bool {
        return defined(self::CONSTANT) && self::is_enabled_value(constant(self::CONSTANT));
    }

    /** Pure parser: only the boolean true enables it. */
    public static function is_enabled_value($value): bool {
        return $value === true;
    }

    /**
     * Second layer behind the packager's construction guard: the classes of this path refuse to be constructed while
     * the constant is off. No test override: the tests that construct them run with it ON.
     */
    public static function assert_constructible(string $class): void {
        if (!self::enabled()) {
            throw new LogicException($class . ' exists only behind ' . self::CONSTANT . '.');
        }
    }
}
