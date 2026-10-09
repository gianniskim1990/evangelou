<?php
defined('ABSPATH') || (defined('EVC_STANDALONE_TEST') && EVC_STANDALONE_TEST) || exit;

/**
 * Binds an idempotency key (request_id) to the exact payload it was first
 * used with: stable member identity, benefit, coffee selection and staff
 * actor. Deliberately EXCLUDES the business date and any timestamp, so a
 * legitimate retry that arrives after Athens midnight still replays the
 * original result instead of conflicting.
 */
final class EVC_Request_Fingerprint {
    const VERSION = 'evc-redeem-v1';

    public static function compute(string $member_public_id, string $benefit_type, string $coffee_code, int $staff_wp_user_id): string {
        return hash('sha256', implode("\n", array(
            self::VERSION,
            $member_public_id,
            $benefit_type,
            $coffee_code,
            (string) $staff_wp_user_id,
        )));
    }

    public static function matches(string $stored, string $computed): bool {
        return hash_equals($stored, $computed);
    }
}
