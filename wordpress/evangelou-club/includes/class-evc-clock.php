<?php
defined('ABSPATH') || (defined('EVC_STANDALONE_TEST') && EVC_STANDALONE_TEST) || exit;

/** Europe/Athens business-day clock, never browser or fixed WP UTC+3. */
final class EVC_Clock {
    public static function business_date(DateTimeInterface $instant = null) {
        $now = $instant
            ? new DateTimeImmutable('@' . $instant->getTimestamp())
            : new DateTimeImmutable('now', new DateTimeZone('UTC'));
        return $now->setTimezone(new DateTimeZone('Europe/Athens'))->format('Y-m-d');
    }

    public static function business_timestamp(DateTimeInterface $instant = null) {
        $now = $instant
            ? new DateTimeImmutable('@' . $instant->getTimestamp())
            : new DateTimeImmutable('now', new DateTimeZone('UTC'));
        return $now->setTimezone(new DateTimeZone('Europe/Athens'))->format('Y-m-d\TH:i:sP');
    }
}
