<?php
defined('ABSPATH') || (defined('EVC_STANDALONE_TEST') && EVC_STANDALONE_TEST) || exit;

/**
 * Owner-approved membership time arithmetic (Task 1D-B, decisions D1/D2/D5).
 * Pure functions; never reads a clock, never depends on PHP's default zone.
 *
 *  D1  A paid period ends at the EXACT Europe/Athens wall-clock time one
 *      calendar month after it starts. The end instant is EXCLUSIVE.
 *  D5  "One month" = one CALENDAR month in the Athens calendar, clamped to the
 *      last valid day (31 Jan -> 28/29 Feb), never PHP's "+1 month" overflow
 *      (31 Jan -> 3 Mar) and never a fixed 30 days. Each period is computed
 *      from the previous paid END, so month-end anniversaries drift
 *      (31 Jan -> 28 Feb -> 28 Mar); that is the approved rule, not a bug.
 *  D2  Early renewal (payment confirmed while the current paid period is still
 *      running) starts at the current paid end; late renewal (confirmed at or
 *      after that end) starts at the payment-confirmation instant.
 *
 * DST: a computed wall-clock time may not exist (spring gap) or occur twice
 * (autumn repeat). It resolves to the FIRST instant at which the Athens clock
 * shows that time or later: the first occurrence of a repeated time, and the
 * transition instant for a skipped time. That never yields a later instant
 * than any reading of the wall-clock time (PHP's own normalisation would move
 * 03:30 in the gap to 04:30, granting extra time), and loses at most the
 * skipped part of the hour.
 */
final class EVC_Membership_Calendar {
    const ZONE = 'Europe/Athens';

    const RESOLVED_EXACT = 'exact';
    const RESOLVED_REPEATED_FIRST = 'repeated_first';
    const RESOLVED_SKIPPED_TRANSITION = 'skipped_transition';

    /** Exclusive end of the period that starts at $start (D1 + D5). */
    public static function add_calendar_month(DateTimeImmutable $start): DateTimeImmutable {
        return self::add_calendar_month_resolved($start)['instant'];
    }

    /**
     * @return array{instant:DateTimeImmutable,resolution:string}
     */
    public static function add_calendar_month_resolved(DateTimeImmutable $start): array {
        $local = EVC_Clock::to_utc($start)->setTimezone(self::zone());
        $year = (int) $local->format('Y');
        $month = (int) $local->format('n') + 1;
        if ($month === 13) {
            $month = 1;
            $year++;
        }
        $day = min((int) $local->format('j'), self::days_in_month($year, $month));
        return self::resolve_local_time(
            $year,
            $month,
            $day,
            (int) $local->format('G'),
            (int) $local->format('i'),
            (int) $local->format('s'),
            (int) $local->format('u')
        );
    }

    /**
     * D2: the paid period funded by a payment confirmed at $confirmed_at.
     *
     * @return array{start:DateTimeImmutable,end:DateTimeImmutable,renewal:string} renewal is "early" or "late"
     */
    public static function next_period(?DateTimeImmutable $current_paid_end, DateTimeImmutable $confirmed_at): array {
        $confirmed_at = EVC_Clock::to_utc($confirmed_at);
        if ($current_paid_end !== null && $confirmed_at < EVC_Clock::to_utc($current_paid_end)) {
            $start = EVC_Clock::to_utc($current_paid_end);
            $renewal = 'early';
        } else {
            // Expired exactly at its end (exclusive), so equality is late.
            $start = $confirmed_at;
            $renewal = 'late';
        }
        return array('start' => $start, 'end' => self::add_calendar_month($start), 'renewal' => $renewal);
    }

    /**
     * Converts an Athens wall-clock time to a UTC instant (see the DST rule
     * above). Throws on an impossible date/time such as 30 February.
     *
     * @return array{instant:DateTimeImmutable,resolution:string}
     */
    public static function resolve_local_time(int $year, int $month, int $day, int $hour, int $minute, int $second, int $microsecond = 0): array {
        if ($year < 1970 || $year > 2200 || $month < 1 || $month > 12 || $day < 1 || $day > self::days_in_month($year, $month)
            || $hour < 0 || $hour > 23 || $minute < 0 || $minute > 59 || $second < 0 || $second > 59
            || $microsecond < 0 || $microsecond > 999999) {
            throw new InvalidArgumentException('Invalid local date/time.');
        }
        $wall = sprintf('%04d-%02d-%02d %02d:%02d:%02d.%06d', $year, $month, $day, $hour, $minute, $second, $microsecond);
        $naive = gmmktime($hour, $minute, $second, $month, $day, $year);

        $zone = self::zone();
        $offsets = array();
        foreach ($zone->getTransitions($naive - 2 * 86400, $naive + 2 * 86400) as $t) {
            $offsets[(int) $t['offset']] = true;
        }

        $valid = array();
        $candidates = array();
        foreach (array_keys($offsets) as $offset) {
            $seconds = $naive - $offset;
            $candidates[] = $seconds;
            $instant = self::instant($seconds, $microsecond);
            if ($instant->setTimezone($zone)->format('Y-m-d H:i:s.u') === $wall) {
                $valid[] = $seconds;
            }
        }

        if (count($valid) === 1) {
            return array('instant' => self::instant($valid[0], $microsecond), 'resolution' => self::RESOLVED_EXACT);
        }
        if (count($valid) > 1) {
            return array('instant' => self::instant(min($valid), $microsecond), 'resolution' => self::RESOLVED_REPEATED_FIRST);
        }

        // Skipped wall-clock time: the clock jumps over it at a transition
        // lying between the candidate readings.
        if (count($candidates) >= 2) {
            $from = min($candidates);
            $to = max($candidates);
            foreach ($zone->getTransitions($from, $to + 1) as $t) {
                if ((int) $t['ts'] > $from && (int) $t['ts'] <= $to + 1) {
                    return array('instant' => self::instant((int) $t['ts'], 0), 'resolution' => self::RESOLVED_SKIPPED_TRANSITION);
                }
            }
        }
        throw new RuntimeException('Unresolvable local time in ' . self::ZONE . '.');
    }

    public static function days_in_month(int $year, int $month): int {
        return (int) gmdate('t', gmmktime(0, 0, 0, $month, 1, $year));
    }

    private static function zone(): DateTimeZone {
        return new DateTimeZone(self::ZONE);
    }

    private static function instant(int $seconds, int $microsecond): DateTimeImmutable {
        return DateTimeImmutable::createFromFormat('U.u', sprintf('%d.%06d', $seconds, $microsecond), new DateTimeZone('UTC'))
            ->setTimezone(new DateTimeZone('UTC'));
    }
}
