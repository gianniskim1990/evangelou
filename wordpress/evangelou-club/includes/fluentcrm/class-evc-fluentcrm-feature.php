<?php
defined('ABSPATH') || exit;
/**
 * STAGE-06 opt-in constant: FluentCRM membership tags (docs/fluentcrm-tags.md).
 *
 * Enabled ONLY when wp-config.php contains
 *     define('EVC_CLUB_FLUENTCRM_SYNC_ENABLED', true);
 * with the boolean literal true. Default: OFF — then nothing of it is hooked, scheduled or
 * constructed, and FluentCRM is never called. Independent of every other Club flag.
 *
 * Even ON, nothing is written unless ALL of these hold (otherwise: no writes + an admin notice):
 *  - the six tag constants below are defined (there are NO defaults): each *_ID an int >= 1, each
 *    *_NAME the exact title or slug that tag must carry (case-insensitive, trimmed);
 *  - FluentCRM is active with the documented API surface (EVC_Fluentcrm_Api_Gateway) at >= 3.2.5;
 *  - each configured ID exists in FluentCRM and its title or slug equals the configured name;
 *  - the Club membership source is configured (commerce source + recorded payments + Club DB).
 */
final class EVC_Fluentcrm_Feature {
    const CONSTANT = 'EVC_CLUB_FLUENTCRM_SYNC_ENABLED';

    /** role => [id constant, name constant] */
    const TAG_CONSTANTS = array(
        'active' => array('EVC_CLUB_FLUENTCRM_TAG_ACTIVE_ID', 'EVC_CLUB_FLUENTCRM_TAG_ACTIVE_NAME'),
        'inactive' => array('EVC_CLUB_FLUENTCRM_TAG_INACTIVE_ID', 'EVC_CLUB_FLUENTCRM_TAG_INACTIVE_NAME'),
        'pending' => array('EVC_CLUB_FLUENTCRM_TAG_PENDING_ID', 'EVC_CLUB_FLUENTCRM_TAG_PENDING_NAME'),
    );

    public static function enabled(): bool {
        return defined(self::CONSTANT) && self::is_enabled_value(constant(self::CONSTANT));
    }

    /** Pure parser: only the boolean true enables it. */
    public static function is_enabled_value($value): bool {
        return $value === true;
    }

    /** @return array{0:?EVC_Fluentcrm_Tag_Config,1:?string} the configured tags, or the problem code */
    public static function tag_config(): array {
        $raw = array();
        foreach (self::TAG_CONSTANTS as $role => list($id, $name)) {
            $raw[$role] = array(
                'id' => defined($id) ? constant($id) : null,
                'name' => defined($name) ? constant($name) : null,
            );
        }
        return EVC_Fluentcrm_Tag_Config::parse($raw);
    }

    /**
     * Second layer behind the packager's construction guard: the classes of this path refuse to be
     * constructed while the constant is off. No test override: the tests that construct them run with it ON
     * (the DB-free unit tests construct only the pure core).
     */
    public static function assert_constructible(string $class): void {
        if (!self::enabled()) {
            throw new LogicException($class . ' exists only behind ' . self::CONSTANT . '.');
        }
    }
}
