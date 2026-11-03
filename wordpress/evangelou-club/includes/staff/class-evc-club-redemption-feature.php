<?php
defined('ABSPATH') || exit;
/**
 * STAGE-05A opt-in constant: the staff redeem route (v1) uses the REAL Club redemption backend
 * (EVC_Club_Redemption_Backend: Club DB ledger + WooCommerce/PMPro membership + the Club's own
 * recorded payment provenance) instead of the always-unavailable one.
 *
 * Enabled ONLY when wp-config.php contains
 *     define('EVC_CLUB_REDEMPTION_SOURCE', 'club_db_v1');
 * with exactly that string (no spaces, lower case). Undefined, true, 1, 'CLUB_DB_V1',
 * ' club_db_v1' or any other value means OFF: the redeem route keeps answering 503, byte for byte
 * as before STAGE-05A. There is deliberately no filter, option or request parameter that turns it
 * on. Even when ON, the backend is unavailable (503) unless every other piece is configured
 * (staff flag, commerce source, EVC_CLUB_PAYMENT_PROVENANCE = 'club_db_v1', Club DB, schema 002).
 */
final class EVC_Club_Redemption_Feature {
    const CONSTANT = 'EVC_CLUB_REDEMPTION_SOURCE';
    const VALUE = 'club_db_v1';

    public static function enabled(): bool {
        return defined(self::CONSTANT) && self::is_enabled_value(constant(self::CONSTANT));
    }

    /** Pure parser: only the exact string 'club_db_v1' enables it. */
    public static function is_enabled_value($value): bool {
        return $value === self::VALUE;
    }
}
