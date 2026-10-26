<?php
use PHPUnit\Framework\TestCase;

/**
 * STAGE-03B.3b: the PMPro local dates the issuer writes must be read back by the
 * mapper / recovery policy as EXACTLY the planned instants (Europe/Athens, DST-safe).
 */
final class PmproLocalTimeTest extends TestCase {
    private static function utc(string $s): DateTimeImmutable {
        return new DateTimeImmutable($s, new DateTimeZone('UTC'));
    }

    public function test_an_ordinary_instant_is_written_as_athens_wall_clock(): void {
        $i = self::utc('2026-10-15 09:00:00');
        $this->assertSame('2026-10-15 12:00:00', EVC_Pmpro_Local_Time::exact($i, EVC_Membership_Calendar::LATEST));
        $this->assertSame('2026-10-15 12:00:00', EVC_Pmpro_Local_Time::exact($i, EVC_Membership_Calendar::EARLIEST));
        $this->assertEquals($i, EVC_Pmpro_Local_Time::instant('2026-10-15 12:00:00', EVC_Membership_Calendar::LATEST));
    }

    public function test_a_plan_crossing_the_autumn_change_keeps_the_athens_wall_clock(): void {
        $start = self::utc('2026-10-10 10:00:00'); // 13:00 EEST
        $end = EVC_Membership_Calendar::add_calendar_month($start);
        $this->assertSame('2026-10-10 13:00:00', EVC_Pmpro_Local_Time::exact($start, EVC_Membership_Calendar::LATEST));
        $this->assertSame('2026-11-10 13:00:00', EVC_Pmpro_Local_Time::exact($end, EVC_Membership_Calendar::EARLIEST));
        $this->assertSame('2026-11-10T11:00:00+00:00', $end->format(DATE_ATOM));
    }

    public function test_a_month_end_plan_is_written_with_the_clamped_day(): void {
        $start = EVC_Pmpro_Fixture::athens('2026-01-31 10:00:00');
        $end = EVC_Membership_Calendar::add_calendar_month($start);
        $this->assertSame('2026-01-31 10:00:00', EVC_Pmpro_Local_Time::exact($start, EVC_Membership_Calendar::LATEST));
        $this->assertSame('2026-02-28 10:00:00', EVC_Pmpro_Local_Time::exact($end, EVC_Membership_Calendar::EARLIEST));
    }

    public function test_a_start_in_the_first_repeated_autumn_hour_cannot_be_written(): void {
        $first = self::utc('2026-10-25 00:30:00');  // 03:30 EEST (first occurrence)
        $second = self::utc('2026-10-25 01:30:00'); // 03:30 EET (second occurrence)
        // A start is read LATEST: only the second occurrence round-trips.
        $this->assertNull(EVC_Pmpro_Local_Time::exact($first, EVC_Membership_Calendar::LATEST));
        $this->assertSame('2026-10-25 03:30:00', EVC_Pmpro_Local_Time::exact($second, EVC_Membership_Calendar::LATEST));
        // An end is read EARLIEST: only the first occurrence round-trips.
        $this->assertSame('2026-10-25 03:30:00', EVC_Pmpro_Local_Time::exact($first, EVC_Membership_Calendar::EARLIEST));
        $this->assertNull(EVC_Pmpro_Local_Time::exact($second, EVC_Membership_Calendar::EARLIEST));
    }

    public function test_an_end_resolved_to_the_spring_transition_is_written_exactly(): void {
        // 29 Feb 2020 03:30 + one calendar month = 29 Mar 2020 03:30, skipped by the spring change.
        $end = EVC_Membership_Calendar::add_calendar_month(EVC_Pmpro_Fixture::athens('2020-02-29 03:30:00'));
        $this->assertSame('2020-03-29T01:00:00+00:00', $end->format(DATE_ATOM));
        $this->assertSame('2020-03-29 04:00:00', EVC_Pmpro_Local_Time::exact($end, EVC_Membership_Calendar::EARLIEST));
    }

    public function test_a_sub_second_instant_is_never_rounded(): void {
        $this->assertNull(EVC_Pmpro_Local_Time::exact(self::utc('2026-10-15 09:00:00.5'), EVC_Membership_Calendar::LATEST));
    }

    public function test_every_written_value_reads_back_as_the_same_instant_around_both_changes(): void {
        foreach (array('2026-03-28 20:00:00', '2026-10-24 20:00:00') as $from) {
            $t = self::utc($from);
            for ($k = 0; $k < 24 * 6; $k++, $t = $t->modify('+10 minutes')) {
                foreach (array(EVC_Membership_Calendar::LATEST, EVC_Membership_Calendar::EARLIEST) as $mode) {
                    $local = EVC_Pmpro_Local_Time::exact($t, $mode);
                    if ($local !== null) {
                        $this->assertEquals($t, EVC_Pmpro_Local_Time::instant($local, $mode), $t->format(DATE_ATOM) . ' ' . $mode);
                    }
                }
                $this->assertTrue(EVC_Pmpro_Local_Time::exact($t, EVC_Membership_Calendar::LATEST) !== null || EVC_Pmpro_Local_Time::exact($t, EVC_Membership_Calendar::EARLIEST) !== null, 'every whole instant is writable in at least one direction');
            }
        }
    }

    public function test_placeholders_and_malformed_values_are_not_dates(): void {
        foreach (array(null, '', '0000-00-00 00:00:00', '1999-12-31 23:59:59', '2026-02-30 10:00:00', "'2026-10-15 12:00:00'", '2026-10-15T12:00:00') as $v) {
            $this->assertNull(EVC_Pmpro_Local_Time::instant($v, EVC_Membership_Calendar::EARLIEST), var_export($v, true));
        }
    }
}
