<?php
defined('ABSPATH') || (defined('EVC_STANDALONE_TEST') && EVC_STANDALONE_TEST) || exit;

/** Europe/Athens business-day clock, never browser or fixed WP UTC+3. */
final class EVC_Clock {
    const BUSINESS_TIMEZONE = 'Europe/Athens';

    /** Europe/Athens calendar day (Y-m-d) of the given instant (default: now). */
    public static function business_date(?DateTimeInterface $instant = null): string {
        return self::in_business_zone($instant)->format('Y-m-d');
    }

    public static function business_timestamp(?DateTimeInterface $instant = null): string {
        return self::in_business_zone($instant)->format('Y-m-d\TH:i:sP');
    }

    /** The same instant expressed in UTC, keeping microseconds. */
    public static function to_utc(DateTimeInterface $instant): DateTimeImmutable {
        return DateTimeImmutable::createFromFormat(
            'U.u',
            $instant->format('U.u'),
            new DateTimeZone('UTC')
        )->setTimezone(new DateTimeZone('UTC'));
    }

    private static function in_business_zone(?DateTimeInterface $instant): DateTimeImmutable {
        $utc = $instant ? self::to_utc($instant) : new DateTimeImmutable('now', new DateTimeZone('UTC'));
        return $utc->setTimezone(new DateTimeZone(self::BUSINESS_TIMEZONE));
    }
}
