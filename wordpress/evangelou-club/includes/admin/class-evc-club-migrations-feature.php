<?php
defined('ABSPATH') || exit;
/**
 * STAGE-04A opt-in constant: the administrator page «Evangelou Club — Βάση δεδομένων»
 * (WordPress Tools menu) and the WP-CLI command `wp evc-club migrations`, which show the
 * migration status of the SEPARATE Club database and apply the pending shipped migrations.
 *
 * Enabled ONLY when wp-config.php contains
 *     define('EVC_CLUB_MIGRATIONS_ENABLED', true);
 * with the boolean literal true. Default: OFF — then nothing of it is hooked, registered or
 * constructed (no menu, no page, no command, no database connection). Independent of every
 * other Club flag; the plugin activation hook never migrates.
 */
final class EVC_Club_Migrations_Feature {
    const CONSTANT = 'EVC_CLUB_MIGRATIONS_ENABLED';
    /**
     * STAGE-05C review (MINOR 5): the HIGHEST migration the page / command may apply. Undefined = 2, so
     * migration 003 (code approved, applying it NOT approved) is never applied by the one-click apply.
     * The technician sets define('EVC_CLUB_MIGRATIONS_TARGET', 3); only after the owner's go-ahead.
     */
    const TARGET_CONSTANT = 'EVC_CLUB_MIGRATIONS_TARGET';
    const DEFAULT_TARGET = 2;

    /** The configured target version; null = defined but not a positive integer (the page refuses). */
    public static function target_version(): ?int {
        return self::parse_target(defined(self::TARGET_CONSTANT) ? constant(self::TARGET_CONSTANT) : null, defined(self::TARGET_CONSTANT));
    }

    /** Pure parser: undefined -> DEFAULT_TARGET; a positive int (exact type) -> itself; anything else -> null. */
    public static function parse_target($value, bool $defined): ?int {
        if (!$defined) {
            return self::DEFAULT_TARGET;
        }
        return is_int($value) && $value >= 1 ? $value : null;
    }

    public static function enabled(): bool {
        return defined(self::CONSTANT) && self::is_enabled_value(constant(self::CONSTANT));
    }

    /** Pure parser: only the boolean true enables it. */
    public static function is_enabled_value($value): bool {
        return $value === true;
    }

    /**
     * Second layer behind the packager's construction guard: the classes of this path refuse to
     * be constructed while the constant is off (whatever code path reaches their constructor).
     * There is deliberately no test override: the tests that construct them run with it ON.
     */
    public static function assert_constructible(string $class): void {
        if (!self::enabled()) {
            throw new LogicException($class . ' exists only behind ' . self::CONSTANT . '.');
        }
    }
}
