<?php
defined('ABSPATH') || exit;
/**
 * STAGE-08 round 12 (owner decision D26 part 2): the opt-in constants of member web push notifications.
 *
 *   define('EVC_CLUB_PUSH_ENABLED', true);          the «Θέλω ειδοποιήσεις» control, POST / DELETE /me/push and the
 *                                                   administrator page «Ειδοποιήσεις». Needs the member page too
 *                                                   (EVC_CLUB_CUSTOMER_ENABLED) and valid VAPID keys (EVC_Push_Vapid).
 *   define('EVC_CLUB_PUSH_AUTO_ACTIVATED', true);   «Η συνδρομή σας ενεργοποιήθηκε» when the shop completes the order
 *   define('EVC_CLUB_PUSH_AUTO_EXPIRY', true);      «Η συνδρομή σας λήγει σε 3 ημέρες», once a day at 10:00 Athens
 *
 * Each ONLY with the boolean literal true; anything else (undefined, 1, 'true', 'yes') is OFF. Default: everything OFF,
 * and then nothing of it is hooked, registered, scheduled or constructed. The two automatic ones also need
 * EVC_CLUB_PUSH_ENABLED (and fail closed with it). The VAPID keys are checked on every use (ready()), never cached.
 */
final class EVC_Push_Feature {
    const CONSTANT = 'EVC_CLUB_PUSH_ENABLED';
    const AUTO_ACTIVATED_CONSTANT = 'EVC_CLUB_PUSH_AUTO_ACTIVATED';
    const AUTO_EXPIRY_CONSTANT = 'EVC_CLUB_PUSH_AUTO_EXPIRY';
    /** Migration 005 (evc_push_subscriptions). */
    const SCHEMA_VERSION = 5;

    /** The flag itself (with the member page): the plugin's gate. */
    public static function enabled(): bool {
        return defined(self::CONSTANT) && self::is_enabled_value(constant(self::CONSTANT)) && EVC_Customer_Feature::enabled();
    }

    /** Pure parser: only the boolean true enables it. */
    public static function is_enabled_value($value): bool {
        return $value === true;
    }

    /** The flag AND usable VAPID keys: push may be offered and sent (fail closed on any key problem). */
    public static function ready(): bool {
        if (!self::enabled()) {
            return false;
        }
        $keys = EVC_Push_Vapid::constants();
        return EVC_Push_Vapid::keys_problem($keys['public'], $keys['private'], $keys['subject']) === null;
    }

    public static function auto_activated(): bool {
        return self::enabled() && defined(self::AUTO_ACTIVATED_CONSTANT) && self::is_enabled_value(constant(self::AUTO_ACTIVATED_CONSTANT));
    }

    public static function auto_expiry(): bool {
        return self::enabled() && defined(self::AUTO_EXPIRY_CONSTANT) && self::is_enabled_value(constant(self::AUTO_EXPIRY_CONSTANT));
    }

    /**
     * The member page's bootstrap keys: the public key only while push is ready (the page then shows the control,
     * or explains why it cannot work, e.g. without the service worker of pretty permalinks). Nothing otherwise.
     * @return array<string,string>
     */
    public static function page_config(): array {
        if (!self::ready()) {
            return array();
        }
        return array('pushPublicKey' => (string) constant(EVC_Push_Vapid::PUBLIC_CONSTANT));
    }

    /** Second layer behind the packager's construction guard. */
    public static function assert_constructible(string $class): void {
        if (!self::enabled()) {
            throw new LogicException($class . ' exists only behind ' . self::CONSTANT . '.');
        }
    }
}
