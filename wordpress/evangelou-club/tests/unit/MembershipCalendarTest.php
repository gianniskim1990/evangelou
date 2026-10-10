<?php
use PHPUnit\Framework\TestCase;

/**
 * Owner-approved D1 (exact Athens time, exclusive end), D5 (calendar month,
 * clamped) and D2 (early/late renewal) arithmetic, incl. DST. Expected values
 * are written out by hand; the default PHP timezone is hostile (bootstrap).
 */
final class MembershipCalendarTest extends TestCase {
    private static function athens(string $local): DateTimeImmutable {
        return EVC_Pmpro_Fixture::athens($local);
    }

    private static function local(DateTimeImmutable $instant): string {
        return $instant->setTimezone(new DateTimeZone('Europe/Athens'))->format('Y-m-d H:i:s.u P');
    }

    /** @return array<string,array{string,string}> start (Athens) => expected end (Athens, with offset) */
    public static function calendar_months(): array {
        return array(
            'owner example: 15 Oct 12:00 -> 15 Nov 12:00 (crosses DST end)' => array('2026-10-15 12:00:00', '2026-11-15 12:00:00.000000 +02:00'),
            '31 Jan -> 28 Feb (non-leap clamp)' => array('2027-01-31 12:00:00', '2027-02-28 12:00:00.000000 +02:00'),
            '31 Jan -> 29 Feb (leap clamp)' => array('2028-01-31 12:00:00', '2028-02-29 12:00:00.000000 +02:00'),
            '30 Jan -> 28 Feb (clamp)' => array('2027-01-30 08:15:00', '2027-02-28 08:15:00.000000 +02:00'),
            '28 Feb -> 28 Mar (no stretch to month end)' => array('2027-02-28 12:00:00', '2027-03-28 12:00:00.000000 +03:00'),
            '29 Feb -> 29 Mar (leap)' => array('2028-02-29 10:00:00', '2028-03-29 10:00:00.000000 +03:00'),
            '31 Mar -> 30 Apr (clamp)' => array('2027-03-31 18:00:00', '2027-04-30 18:00:00.000000 +03:00'),
            '30 Apr -> 30 May' => array('2027-04-30 09:00:00', '2027-05-30 09:00:00.000000 +03:00'),
            '31 May -> 30 Jun' => array('2027-05-31 23:59:59', '2027-06-30 23:59:59.000000 +03:00'),
            '31 Aug -> 30 Sep' => array('2027-08-31 00:00:00', '2027-09-30 00:00:00.000000 +03:00'),
            'Dec -> Jan, year rollover' => array('2026-12-15 12:00:00', '2027-01-15 12:00:00.000000 +02:00'),
            '31 Dec -> 31 Jan, year rollover' => array('2026-12-31 23:30:00', '2027-01-31 23:30:00.000000 +02:00'),
            'local midnight stays local midnight (winter)' => array('2027-01-10 00:00:00', '2027-02-10 00:00:00.000000 +02:00'),
            'local midnight stays local midnight (summer)' => array('2027-06-10 00:00:00', '2027-07-10 00:00:00.000000 +03:00'),
            'winter -> summer keeps wall-clock time' => array('2027-03-10 12:00:00', '2027-04-10 12:00:00.000000 +03:00'),
        );
    }

    /** @dataProvider calendar_months */
    public function test_one_calendar_month_with_clamp(string $start, string $expected_end): void {
        $end = EVC_Membership_Calendar::add_calendar_month(self::athens($start));
        $this->assertSame($expected_end, self::local($end));
        $this->assertSame('UTC', $end->getTimezone()->getName(), 'instants are UTC internally');
    }

    public function test_naive_php_plus_one_month_would_overflow(): void {
        // Guard against the bug D5 forbids: PHP "+1 month" turns 31 Jan into 3 Mar.
        $naive = (new DateTimeImmutable('2027-01-31 12:00:00', new DateTimeZone('Europe/Athens')))->modify('+1 month');
        $this->assertSame('2027-03-03', $naive->format('Y-m-d'));
        $this->assertSame('2027-02-28', EVC_Membership_Calendar::add_calendar_month(self::athens('2027-01-31 12:00'))->setTimezone(new DateTimeZone('Europe/Athens'))->format('Y-m-d'));
    }

    public function test_period_across_dst_end_is_not_a_fixed_duration(): void {
        $start = self::athens('2026-10-15 12:00');
        $end = EVC_Membership_Calendar::add_calendar_month($start);
        $this->assertSame('2026-10-15T09:00:00Z', $start->format('Y-m-d\TH:i:s\Z'));
        $this->assertSame('2026-11-15T10:00:00Z', $end->format('Y-m-d\TH:i:s\Z'), '31 days + 1 hour of real time');
    }

    public function test_microseconds_are_preserved(): void {
        $end = EVC_Membership_Calendar::add_calendar_month(EVC_Pmpro_Fixture::utc('2026-10-15T09:00:00.123456Z'));
        $this->assertSame('2026-11-15T10:00:00.123456Z', $end->format('Y-m-d\TH:i:s.u\Z'));
    }

    public function test_consecutive_month_end_renewals_drift_as_documented(): void {
        // Each period is computed from the previous paid END (approved D5 rule),
        // so a 31st anniversary becomes the 28th after February and stays there.
        $end = self::athens('2027-01-31 12:00');
        $chain = array();
        for ($i = 0; $i < 4; $i++) {
            $end = EVC_Membership_Calendar::add_calendar_month($end);
            $chain[] = $end->setTimezone(new DateTimeZone('Europe/Athens'))->format('Y-m-d H:i');
        }
        $this->assertSame(array('2027-02-28 12:00', '2027-03-28 12:00', '2027-04-28 12:00', '2027-05-28 12:00'), $chain);
    }

    public function test_leap_year_chain(): void {
        $end = self::athens('2028-01-31 07:00');
        $end = EVC_Membership_Calendar::add_calendar_month($end);
        $this->assertSame('2028-02-29 07:00', $end->setTimezone(new DateTimeZone('Europe/Athens'))->format('Y-m-d H:i'));
        $end = EVC_Membership_Calendar::add_calendar_month($end);
        $this->assertSame('2028-03-29 07:00', $end->setTimezone(new DateTimeZone('Europe/Athens'))->format('Y-m-d H:i'));
    }

    // ---- DST: nonexistent and repeated wall-clock times ------------------

    public function test_spring_gap_resolves_to_the_transition_instant(): void {
        // 2027-03-28: Athens clocks jump 03:00 -> 04:00 (01:00Z). 03:30 does not exist.
        $r = EVC_Membership_Calendar::resolve_local_time(2027, 3, 28, 3, 30, 0);
        $this->assertSame(EVC_Membership_Calendar::RESOLVED_SKIPPED_TRANSITION, $r['resolution']);
        $this->assertSame('2027-03-28T01:00:00.000000Z', $r['instant']->format('Y-m-d\TH:i:s.u\Z'));
        // PHP's own normalisation would grant 30 extra minutes (04:30 = 01:30Z).
        $php = new DateTimeImmutable('2027-03-28 03:30:00', new DateTimeZone('Europe/Athens'));
        $this->assertGreaterThan($r['instant'], $php->setTimezone(new DateTimeZone('UTC')));
    }

    public function test_month_ending_in_the_spring_gap_is_never_extended(): void {
        $r = EVC_Membership_Calendar::add_calendar_month_resolved(self::athens('2027-02-28 03:30'));
        $this->assertSame(EVC_Membership_Calendar::RESOLVED_SKIPPED_TRANSITION, $r['resolution']);
        $this->assertSame('2027-03-28T01:00:00Z', $r['instant']->format('Y-m-d\TH:i:s\Z'));
        $this->assertSame('2027-03-28 04:00:00.000000 +03:00', self::local($r['instant']));
    }

    public function test_autumn_repeat_resolves_to_the_first_occurrence(): void {
        // 2026-10-25: 04:00 EEST -> 03:00 EET; 03:30 happens at 00:30Z and 01:30Z.
        $r = EVC_Membership_Calendar::resolve_local_time(2026, 10, 25, 3, 30, 0);
        $this->assertSame(EVC_Membership_Calendar::RESOLVED_REPEATED_FIRST, $r['resolution']);
        $this->assertSame('2026-10-25T00:30:00Z', $r['instant']->format('Y-m-d\TH:i:s\Z'));
        $end = EVC_Membership_Calendar::add_calendar_month(self::athens('2026-09-25 03:30'));
        $this->assertSame('2026-10-25T00:30:00Z', $end->format('Y-m-d\TH:i:s\Z'), 'earlier of the two readings');
    }

    public function test_times_next_to_transitions_are_exact(): void {
        $cases = array(
            array(array(2027, 3, 28, 2, 59, 59, 999999), '2027-03-28T00:59:59.999999Z'),
            array(array(2027, 3, 28, 4, 0, 0, 0), '2027-03-28T01:00:00.000000Z'),
            array(array(2026, 10, 25, 2, 59, 59, 0), '2026-10-24T23:59:59.000000Z'),
            array(array(2026, 10, 25, 4, 0, 0, 0), '2026-10-25T02:00:00.000000Z'),
        );
        foreach ($cases as $case) {
            list($args, $expected) = $case;
            $r = EVC_Membership_Calendar::resolve_local_time(...$args);
            $this->assertSame(EVC_Membership_Calendar::RESOLVED_EXACT, $r['resolution'], $expected);
            $this->assertSame($expected, $r['instant']->format('Y-m-d\TH:i:s.u\Z'));
        }
    }

    public function test_impossible_local_dates_are_rejected(): void {
        foreach (array(array(2027, 2, 29, 12, 0, 0), array(2026, 4, 31, 0, 0, 0), array(2026, 13, 1, 0, 0, 0), array(2026, 1, 1, 24, 0, 0), array(1000, 1, 1, 0, 0, 0)) as $args) {
            try {
                EVC_Membership_Calendar::resolve_local_time(...$args);
                $this->fail('accepted ' . implode('-', $args));
            } catch (InvalidArgumentException $e) {
                $this->assertSame('Invalid local date/time.', $e->getMessage());
            }
        }
    }

    // ---- D2 renewal -------------------------------------------------------

    public function test_early_renewal_extends_from_the_current_paid_end(): void {
        $p = EVC_Membership_Calendar::next_period(self::athens('2026-11-15 12:00'), self::athens('2026-11-01 09:00'));
        $this->assertSame('early', $p['renewal']);
        $this->assertSame('2026-11-15 12:00:00.000000 +02:00', self::local($p['start']));
        $this->assertSame('2026-12-15 12:00:00.000000 +02:00', self::local($p['end']));
    }

    public function test_late_renewal_starts_at_payment_confirmation(): void {
        $p = EVC_Membership_Calendar::next_period(self::athens('2026-11-15 12:00'), self::athens('2026-11-20 10:00'));
        $this->assertSame('late', $p['renewal']);
        $this->assertSame('2026-11-20 10:00:00.000000 +02:00', self::local($p['start']));
        $this->assertSame('2026-12-20 10:00:00.000000 +02:00', self::local($p['end']));
    }

    public function test_confirmation_exactly_at_the_end_is_late(): void {
        $end = self::athens('2026-11-15 12:00');
        $p = EVC_Membership_Calendar::next_period($end, $end);
        $this->assertSame('late', $p['renewal']);
        $this->assertEquals($end, $p['start']);
        $one_before = EVC_Membership_Calendar::next_period($end, $end->modify('-1 microsecond'));
        $this->assertSame('early', $one_before['renewal']);
    }

    public function test_first_purchase_starts_at_confirmation(): void {
        $p = EVC_Membership_Calendar::next_period(null, self::athens('2026-10-15 12:00'));
        $this->assertSame('late', $p['renewal']);
        $this->assertSame('2026-11-15 12:00:00.000000 +02:00', self::local($p['end']));
    }

    public function test_results_do_not_depend_on_php_default_timezone(): void {
        $previous = date_default_timezone_get();
        $results = array();
        try {
            foreach (array('UTC', 'America/New_York', 'Asia/Tokyo', 'Pacific/Kiritimati') as $zone) {
                date_default_timezone_set($zone);
                $results[] = EVC_Membership_Calendar::add_calendar_month(self::athens('2027-01-31 12:00'))->format('U.u')
                    . '|' . EVC_Membership_Calendar::resolve_local_time(2027, 3, 28, 3, 30, 0)['instant']->format('U.u');
            }
        } finally {
            date_default_timezone_set($previous);
        }
        $this->assertCount(1, array_unique($results));
    }
}
