<?php
defined('ABSPATH') || (defined('EVC_STANDALONE_TEST') && EVC_STANDALONE_TEST) || exit;

/**
 * STAGE-03B.3b: the site-local wall-clock strings PMPro stores
 * (`pmpro_memberships_users.startdate` / `enddate`, 'Y-m-d H:i:s' in the site
 * timezone, which the Club requires to be Europe/Athens) for an exact UTC
 * instant — and the way the Club reads them back.
 *
 * Reading uses exactly the interpretation of EVC_Pmpro_Entitlement_Mapper and
 * EVC_Bacs_Recovery_Policy (EVC_Membership_Calendar::resolve_local_time; a
 * START resolves LATEST, an END resolves EARLIEST). Writing is only allowed
 * when that reading returns the SAME instant: an instant inside the first
 * occurrence of the repeated autumn hour cannot be written as a start (the
 * mapper would read the second occurrence), and a sub-second instant cannot be
 * written at all. Then exact() returns null and the caller must stop before
 * anything is written — it never rounds, shifts or guesses.
 *
 * Pure: no clock, no WordPress, no I/O.
 */
final class EVC_Pmpro_Local_Time {
    const FORMAT = 'Y-m-d H:i:s';

    /** The local string PMPro must store so that the Club reads back exactly $instant; null when impossible. */
    public static function exact(DateTimeImmutable $instant, string $mode): ?string {
        $utc = $instant->setTimezone(new DateTimeZone('UTC'));
        if ($utc->format('u') !== '000000') {
            return null;
        }
        $local = $utc->setTimezone(new DateTimeZone(EVC_Membership_Calendar::ZONE))->format(self::FORMAT);
        $back = self::instant($local, $mode);
        return $back !== null && $back == $utc ? $local : null;
    }

    /** The instant the mapper / recovery policy read from a stored PMPro date; null when not a real date. */
    public static function instant(?string $local, string $mode): ?DateTimeImmutable {
        if ($local === null || !preg_match(EVC_Pmpro_Entitlement_Mapper::LOCAL_DATETIME_PATTERN, $local, $m) || (int) $m[1] < EVC_Pmpro_Entitlement_Mapper::MIN_REAL_YEAR) {
            return null;
        }
        try {
            return EVC_Membership_Calendar::resolve_local_time((int) $m[1], (int) $m[2], (int) $m[3], (int) $m[4], (int) $m[5], (int) $m[6], 0, $mode)['instant'];
        } catch (Throwable $e) {
            return null;
        }
    }
}
