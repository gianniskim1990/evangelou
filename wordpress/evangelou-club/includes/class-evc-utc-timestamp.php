<?php
defined('ABSPATH') || (defined('EVC_STANDALONE_TEST') && EVC_STANDALONE_TEST) || exit;

/**
 * UI-04B — strict validation of the stored UTC timestamp format used by the
 * Club ledger, snapshots' records and handoff confirmations: the canonical
 * DATETIME(6) text "YYYY-MM-DD HH:MM:SS.ffffff", always UTC, no offset, no
 * "T", no missing or extra fraction digits. Anything else is corrupt.
 *
 * Pure PHP 7.4+, independent of PHP's default timezone.
 */
final class EVC_Utc_Timestamp {
    const PATTERN = '/^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})\.(\d{6})$/D';

    /** @param mixed $value */
    public static function is_canonical($value): bool {
        if (!is_string($value) || !preg_match(self::PATTERN, $value, $m)) {
            return false;
        }
        $year = (int) $m[1];
        return $year >= 1970 && $year <= 9999
            && checkdate((int) $m[2], (int) $m[3], $year)
            && (int) $m[4] <= 23 && (int) $m[5] <= 59 && (int) $m[6] <= 59;
    }

    /**
     * Europe/Athens business date (Y-m-d) of a canonical UTC timestamp.
     *
     * @throws InvalidArgumentException for a non-canonical value
     */
    public static function athens_date(string $value): string {
        if (!self::is_canonical($value)) {
            throw new InvalidArgumentException('Not a canonical UTC timestamp.');
        }
        return EVC_Clock::business_date(new DateTimeImmutable($value, new DateTimeZone('UTC')));
    }

    /** Chronological comparison of two canonical timestamps (byte order equals time order). */
    public static function compare(string $a, string $b): int {
        return strcmp($a, $b) <=> 0;
    }
}
