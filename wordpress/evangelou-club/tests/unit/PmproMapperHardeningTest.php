<?php
use PHPUnit\Framework\TestCase;

/**
 * Task 1D-B.R1: membership start validation, payment identity uniqueness and
 * payment-to-row level binding. Synthetic data; periods written out by hand.
 */
final class PmproMapperHardeningTest extends TestCase {
    private static function when(string $athens_local): DateTimeImmutable {
        return EVC_Pmpro_Fixture::athens($athens_local);
    }

    private static function local(?DateTimeImmutable $instant): ?string {
        return $instant === null ? null : $instant->setTimezone(new DateTimeZone('Europe/Athens'))->format('Y-m-d H:i:s');
    }

    private function assertIndeterminate(EVC_Entitlement $e, string $flag, DateTimeImmutable $at): void {
        $this->assertSame('indeterminate', $e->status(), 'flags: ' . implode(',', $e->diagnostic_flags()));
        $this->assertContains($flag, $e->diagnostic_flags());
        $this->assertFalse($e->is_eligible_at($at));
        $this->assertNull($e->payment_evidence());
        $this->assertNull($e->expires_at_utc());
    }

    private static function with_start(?string $start_local): EVC_Pmpro_Snapshot {
        $s = EVC_Pmpro_Fixture::single_paid_month();
        $s->memberships = array(EVC_Pmpro_Fixture::row('row1', 'active', '2026-11-15 12:00:00', EVC_Pmpro_Fixture::CLUB_LEVEL, false, $start_local));
        return $s;
    }

    // ==== Issue 1: membership start ==========================================

    public function test_future_membership_start_is_not_eligible_despite_valid_payment(): void {
        $s = self::with_start('2026-10-25 12:00:00');
        $at = self::when('2026-10-20 09:00');
        $this->assertIndeterminate(EVC_Pmpro_Fixture::mapper()->map($s, $at), 'membership_not_started', $at);
    }

    public function test_exact_membership_start_boundary_is_inclusive(): void {
        $s = self::with_start('2026-10-25 12:00:00');
        $start = self::when('2026-10-25 12:00');
        $this->assertIndeterminate(EVC_Pmpro_Fixture::mapper()->map($s, $start->modify('-1 second')), 'membership_not_started', $start->modify('-1 second'));
        $this->assertIndeterminate(EVC_Pmpro_Fixture::mapper()->map($s, $start->modify('-1 microsecond')), 'membership_not_started', $start->modify('-1 microsecond'));
        $e = EVC_Pmpro_Fixture::mapper()->map($s, $start);
        $this->assertSame('active', $e->status());
        $this->assertTrue($e->is_eligible_at($start));
        // Exclusive expiry is unchanged.
        $end = self::when('2026-11-15 12:00');
        $this->assertSame('active', EVC_Pmpro_Fixture::mapper()->map($s, $end->modify('-1 second'))->status());
        $this->assertSame('expired', EVC_Pmpro_Fixture::mapper()->map($s, $end)->status());
    }

    public function test_start_at_or_after_end_is_rejected(): void {
        $at = self::when('2026-10-20 09:00');
        foreach (array('2026-11-15 12:00:00', '2026-11-15 12:00:01', '2026-12-01 00:00:00') as $start) {
            $this->assertIndeterminate(EVC_Pmpro_Fixture::mapper()->map(self::with_start($start), $at), 'membership_start_after_end', $at);
        }
    }

    /** @return array<string,array{?string,string}> */
    public static function bad_starts(): array {
        return array(
            'missing' => array(null, 'start_missing'),
            'empty' => array('', 'start_missing'),
            'zero date' => array('0000-00-00 00:00:00', 'start_missing'),
            'PMPro magic minimum' => array('1000-01-01 00:00:00', 'start_missing'),
            'impossible day' => array('2026-02-30 10:00:00', 'invalid_membership_dates'),
            'hour 24' => array('2026-10-15 24:00:00', 'invalid_membership_dates'),
            'not a date' => array('yesterday', 'invalid_membership_dates'),
            'ISO with offset' => array('2026-10-15T12:00:00+03:00', 'invalid_membership_dates'),
        );
    }

    /** @dataProvider bad_starts */
    public function test_missing_malformed_or_magic_start(?string $start, string $flag): void {
        $at = self::when('2026-10-20 09:00');
        $this->assertIndeterminate(EVC_Pmpro_Fixture::mapper()->map(self::with_start($start), $at), $flag, $at);
    }

    public function test_original_start_kept_across_multiple_verified_renewals_is_valid(): void {
        // PMPro / the Woo add-on may keep the ORIGINAL start on the active row.
        $s = EVC_Pmpro_Fixture::snapshot(
            array(
                EVC_Pmpro_Fixture::row('r1', 'changed', '2026-11-15 12:00:00', EVC_Pmpro_Fixture::CLUB_LEVEL, false, '2026-10-15 12:00:00'),
                EVC_Pmpro_Fixture::row('r2', 'changed', '2026-12-15 12:00:00', EVC_Pmpro_Fixture::CLUB_LEVEL, false, '2026-10-15 12:00:00'),
                EVC_Pmpro_Fixture::row('r3', 'active', '2027-01-15 12:00:00', EVC_Pmpro_Fixture::CLUB_LEVEL, false, '2026-10-15 12:00:00'),
            ),
            array(
                EVC_Pmpro_Fixture::paid('m1', '2026-10-15 12:00', '2026-10-15 12:00', '2026-11-15 12:00', 'r1'),
                EVC_Pmpro_Fixture::paid('m2', '2026-11-10 09:00', '2026-11-15 12:00', '2026-12-15 12:00', 'r2'),
                EVC_Pmpro_Fixture::paid('m3', '2026-12-10 09:00', '2026-12-15 12:00', '2027-01-15 12:00', 'r3'),
            )
        );
        $e = EVC_Pmpro_Fixture::mapper()->map($s, self::when('2026-12-20 09:00'));
        $this->assertSame('active', $e->status(), implode(',', $e->diagnostic_flags()));
        $this->assertSame('2027-01-15 12:00:00', self::local($e->expires_at_utc()));
        $this->assertSame('2026-12-15 12:00:00', self::local($e->payment_evidence()->period_start_utc()), 'funded by the latest renewal');
    }

    public function test_row_started_at_the_latest_renewal_checkout_is_also_valid(): void {
        // A source that starts a NEW row at renewal time (inside the paid run).
        $s = EVC_Pmpro_Fixture::snapshot(
            array(
                EVC_Pmpro_Fixture::row('row1', 'changed', '2026-11-15 12:00:00'),
                EVC_Pmpro_Fixture::row('row2', 'active', '2026-12-15 12:00:00', EVC_Pmpro_Fixture::CLUB_LEVEL, false, '2026-11-01 09:00:00'),
            ),
            array(
                EVC_Pmpro_Fixture::paid('oct', '2026-10-15 12:00', '2026-10-15 12:00', '2026-11-15 12:00', 'row1'),
                EVC_Pmpro_Fixture::paid('nov', '2026-11-01 09:00', '2026-11-15 12:00', '2026-12-15 12:00', 'row2'),
            )
        );
        $this->assertSame('active', EVC_Pmpro_Fixture::mapper()->map($s, self::when('2026-11-10 09:00'))->status());
    }

    public function test_pmpro_start_before_the_current_paid_run_is_contradictory(): void {
        $at = self::when('2026-10-20 09:00');
        // Single paid month from 15 Oct 12:00, but PMPro claims membership since August.
        $this->assertIndeterminate(EVC_Pmpro_Fixture::mapper()->map(self::with_start('2026-08-01 10:00:00'), $at), 'membership_start_before_paid_run', $at);
        // One second before the paid run began is already a contradiction (no tolerance).
        $this->assertIndeterminate(EVC_Pmpro_Fixture::mapper()->map(self::with_start('2026-10-15 11:59:59'), $at), 'membership_start_before_paid_run', $at);

        // Late renewal after a lapse: the active row may not span the unpaid gap.
        $late = EVC_Pmpro_Fixture::snapshot(
            array(
                EVC_Pmpro_Fixture::row('row1', 'expired', '2026-11-15 12:00:00'),
                EVC_Pmpro_Fixture::row('row2', 'active', '2026-12-20 10:00:00', EVC_Pmpro_Fixture::CLUB_LEVEL, false, '2026-11-15 12:00:00'),
            ),
            array(
                EVC_Pmpro_Fixture::paid('oct', '2026-10-15 12:00', '2026-10-15 12:00', '2026-11-15 12:00', 'row1'),
                EVC_Pmpro_Fixture::paid('nov', '2026-11-20 10:00', '2026-11-20 10:00', '2026-12-20 10:00', 'row2'),
            )
        );
        $at = self::when('2026-11-25 09:00');
        $this->assertIndeterminate(EVC_Pmpro_Fixture::mapper()->map($late, $at), 'membership_start_before_paid_run', $at);
    }

    public function test_ambiguous_autumn_start_resolves_to_the_later_occurrence(): void {
        // 2026-10-25 03:30 Athens happens at 00:30Z and again at 01:30Z.
        // Paid from 00:45Z; a row start of "03:30" must mean 01:30Z (later).
        $confirmed = EVC_Pmpro_Fixture::utc('2026-10-25T00:45:00Z'); // 03:45 EEST
        $end = EVC_Pmpro_Fixture::athens('2026-11-25 03:45');        // 01:45Z, unambiguous
        $s = EVC_Pmpro_Fixture::snapshot(
            array(EVC_Pmpro_Fixture::row('row1', 'active', '2026-11-25 03:45:00', EVC_Pmpro_Fixture::CLUB_LEVEL, false, '2026-10-25 03:30:00')),
            array(EVC_Pmpro_Fixture::payment('oct', 'confirmed', $confirmed, $confirmed, $end))
        );
        $first = EVC_Pmpro_Fixture::utc('2026-10-25T01:00:00Z');
        $this->assertIndeterminate(EVC_Pmpro_Fixture::mapper()->map($s, $first), 'membership_not_started', $first);
        $this->assertIndeterminate(EVC_Pmpro_Fixture::mapper()->map($s, EVC_Pmpro_Fixture::utc('2026-10-25T01:29:59Z')), 'membership_not_started', EVC_Pmpro_Fixture::utc('2026-10-25T01:29:59Z'));
        $this->assertSame('active', EVC_Pmpro_Fixture::mapper()->map($s, EVC_Pmpro_Fixture::utc('2026-10-25T01:30:00Z'))->status());
    }

    public function test_nonexistent_spring_start_resolves_to_the_transition(): void {
        // 2027-03-28 03:30 Athens does not exist; clocks jump at 01:00Z.
        $confirmed = EVC_Pmpro_Fixture::utc('2027-03-28T00:30:00Z'); // 02:30 EET
        $s = EVC_Pmpro_Fixture::snapshot(
            array(EVC_Pmpro_Fixture::row('row1', 'active', '2027-04-28 02:30:00', EVC_Pmpro_Fixture::CLUB_LEVEL, false, '2027-03-28 03:30:00')),
            array(EVC_Pmpro_Fixture::payment('mar', 'confirmed', $confirmed, $confirmed, EVC_Pmpro_Fixture::athens('2027-04-28 02:30')))
        );
        $before = EVC_Pmpro_Fixture::utc('2027-03-28T00:59:59Z');
        $this->assertIndeterminate(EVC_Pmpro_Fixture::mapper()->map($s, $before), 'membership_not_started', $before);
        $this->assertSame('active', EVC_Pmpro_Fixture::mapper()->map($s, EVC_Pmpro_Fixture::utc('2027-03-28T01:00:00Z'))->status());
    }

    public function test_start_resolution_modes(): void {
        $first = EVC_Membership_Calendar::resolve_local_time(2026, 10, 25, 3, 30, 0, 0, EVC_Membership_Calendar::EARLIEST);
        $last = EVC_Membership_Calendar::resolve_local_time(2026, 10, 25, 3, 30, 0, 0, EVC_Membership_Calendar::LATEST);
        $this->assertSame('2026-10-25T00:30:00Z', $first['instant']->format('Y-m-d\TH:i:s\Z'));
        $this->assertSame(EVC_Membership_Calendar::RESOLVED_REPEATED_LAST, $last['resolution']);
        $this->assertSame('2026-10-25T01:30:00Z', $last['instant']->format('Y-m-d\TH:i:s\Z'));
        $gap = EVC_Membership_Calendar::resolve_local_time(2027, 3, 28, 3, 30, 0, 0, EVC_Membership_Calendar::LATEST);
        $this->assertSame('2027-03-28T01:00:00Z', $gap['instant']->format('Y-m-d\TH:i:s\Z'));
        $this->expectException(InvalidArgumentException::class);
        EVC_Membership_Calendar::resolve_local_time(2026, 10, 25, 3, 30, 0, 0, 'middle');
    }

    // ==== Issue 2: underlying payment identity ===============================

    public function test_one_payment_reference_cannot_fund_two_periods_under_two_dedupe_keys(): void {
        $s = EVC_Pmpro_Fixture::single_paid_month();
        $s->memberships = array(
            EVC_Pmpro_Fixture::row('row1', 'changed', '2026-11-15 12:00:00'),
            EVC_Pmpro_Fixture::row('row2', 'active', '2026-12-15 12:00:00'),
        );
        // Same underlying payment record as "oct", re-reported under a new money identity.
        $s->payments[] = EVC_Pmpro_Fixture::paid('oct', '2026-11-01 09:00', '2026-11-15 12:00', '2026-12-15 12:00', 'row2', array('dedupe_key' => EVC_Pmpro_Fixture::ref('money:other')));
        $at = self::when('2026-11-10 09:00');
        $this->assertIndeterminate(EVC_Pmpro_Fixture::mapper()->map($s, $at), 'payment_identity_conflict', $at);
    }

    public function test_identical_repeat_of_one_payment_reference_counts_once(): void {
        $s = EVC_Pmpro_Fixture::single_paid_month();
        $s->payments[] = EVC_Pmpro_Fixture::paid('oct', '2026-10-15 12:00', '2026-10-15 12:00', '2026-11-15 12:00');
        $this->assertSame($s->payments[0]->payment_ref, $s->payments[1]->payment_ref);
        $e = EVC_Pmpro_Fixture::mapper()->map($s, self::when('2026-10-20 09:00'));
        $this->assertSame('active', $e->status());
        $this->assertSame('2026-11-15 12:00:00', self::local($e->expires_at_utc()), 'not extended');
    }

    public function test_same_reference_and_dedupe_key_with_incompatible_facts(): void {
        $at = self::when('2026-10-20 09:00');
        foreach (array(
            'other period' => array('period_end_utc' => self::when('2026-12-15 12:00')),
            'other confirmation' => array('confirmed_at_utc' => self::when('2026-10-15 12:01')),
            'other status' => array('status' => 'pending', 'confirmed_at_utc' => null),
            'other level' => array('level_id' => EVC_Pmpro_Fixture::OTHER_LEVEL),
            'other environment' => array('environment' => 'sandbox'),
        ) as $name => $override) {
            $s = EVC_Pmpro_Fixture::single_paid_month();
            $s->payments[] = EVC_Pmpro_Fixture::paid('oct', '2026-10-15 12:00', '2026-10-15 12:00', '2026-11-15 12:00', 'row1', $override);
            $this->assertIndeterminate(EVC_Pmpro_Fixture::mapper()->map($s, $at), 'payment_identity_conflict', $at);
        }
    }

    public function test_distinct_payments_with_separate_verified_periods_stay_eligible(): void {
        $s = EVC_Pmpro_Fixture::snapshot(
            array(EVC_Pmpro_Fixture::row('row1', 'changed', '2026-11-15 12:00:00'), EVC_Pmpro_Fixture::row('row2', 'active', '2026-12-15 12:00:00')),
            array(
                EVC_Pmpro_Fixture::paid('oct', '2026-10-15 12:00', '2026-10-15 12:00', '2026-11-15 12:00', 'row1'),
                EVC_Pmpro_Fixture::paid('nov', '2026-11-01 09:00', '2026-11-15 12:00', '2026-12-15 12:00', 'row2'),
            )
        );
        $this->assertSame('active', EVC_Pmpro_Fixture::mapper()->map($s, self::when('2026-11-20 09:00'))->status());
    }

    public function test_renewal_payment_reference_reused_cannot_extend_twice(): void {
        $s = EVC_Pmpro_Fixture::snapshot(
            array(
                EVC_Pmpro_Fixture::row('row1', 'changed', '2026-11-15 12:00:00'),
                EVC_Pmpro_Fixture::row('row2', 'changed', '2026-12-15 12:00:00'),
                EVC_Pmpro_Fixture::row('row3', 'active', '2027-01-15 12:00:00'),
            ),
            array(
                EVC_Pmpro_Fixture::paid('oct', '2026-10-15 12:00', '2026-10-15 12:00', '2026-11-15 12:00', 'row1'),
                EVC_Pmpro_Fixture::paid('nov', '2026-11-01 09:00', '2026-11-15 12:00', '2026-12-15 12:00', 'row2'),
                // The November payment record again, as if it were a third month.
                EVC_Pmpro_Fixture::paid('nov', '2026-11-02 09:00', '2026-12-15 12:00', '2027-01-15 12:00', 'row3', array('dedupe_key' => EVC_Pmpro_Fixture::ref('money:nov-again'))),
            )
        );
        $at = self::when('2026-12-20 09:00');
        $this->assertIndeterminate(EVC_Pmpro_Fixture::mapper()->map($s, $at), 'payment_identity_conflict', $at);
    }

    // ==== Issue 3: payment-to-row level binding ===============================

    private static function two_level_rows(): array {
        return array(
            EVC_Pmpro_Fixture::row('row7', 'active', '2026-11-15 12:00:00', EVC_Pmpro_Fixture::CLUB_LEVEL),
            EVC_Pmpro_Fixture::row('row8', 'changed', '2026-11-15 12:00:00', EVC_Pmpro_Fixture::SECOND_CLUB_LEVEL),
        );
    }

    public function test_matching_row_and_level_is_eligible_with_two_approved_levels(): void {
        $s = EVC_Pmpro_Fixture::snapshot(self::two_level_rows(), array(
            EVC_Pmpro_Fixture::paid('oct', '2026-10-15 12:00', '2026-10-15 12:00', '2026-11-15 12:00', 'row7'),
        ));
        $e = EVC_Pmpro_Fixture::two_level_mapper()->map($s, self::when('2026-10-20 09:00'));
        $this->assertSame('active', $e->status());
        $this->assertSame('pmpro:7', $e->level_ref());
    }

    public function test_payment_linked_to_a_row_of_another_approved_level_is_rejected(): void {
        $at = self::when('2026-10-20 09:00');
        // Level-7 money linked to the level-8 row.
        $s = EVC_Pmpro_Fixture::snapshot(self::two_level_rows(), array(
            EVC_Pmpro_Fixture::paid('oct', '2026-10-15 12:00', '2026-10-15 12:00', '2026-11-15 12:00', 'row8'),
        ));
        $this->assertIndeterminate(EVC_Pmpro_Fixture::two_level_mapper()->map($s, $at), 'payment_level_mismatch', $at);
        // Level-8 money linked to the active level-7 row.
        $s = EVC_Pmpro_Fixture::snapshot(self::two_level_rows(), array(
            EVC_Pmpro_Fixture::paid('oct', '2026-10-15 12:00', '2026-10-15 12:00', '2026-11-15 12:00', 'row7', array('level_id' => EVC_Pmpro_Fixture::SECOND_CLUB_LEVEL)),
        ));
        $this->assertIndeterminate(EVC_Pmpro_Fixture::two_level_mapper()->map($s, $at), 'payment_level_mismatch', $at);
    }

    public function test_unknown_row_reference_is_rejected(): void {
        $at = self::when('2026-10-20 09:00');
        $s = EVC_Pmpro_Fixture::snapshot(self::two_level_rows(), array(
            EVC_Pmpro_Fixture::paid('oct', '2026-10-15 12:00', '2026-10-15 12:00', '2026-11-15 12:00', 'row-nowhere'),
        ));
        $this->assertIndeterminate(EVC_Pmpro_Fixture::two_level_mapper()->map($s, $at), 'payment_period_unlinked', $at);
    }

    public function test_one_paid_run_cannot_mix_levels(): void {
        // October paid on level 8; early renewal on level 7 would let level-8
        // money fund level-7 time.
        $s = EVC_Pmpro_Fixture::snapshot(
            array(
                EVC_Pmpro_Fixture::row('row8', 'changed', '2026-11-15 12:00:00', EVC_Pmpro_Fixture::SECOND_CLUB_LEVEL),
                EVC_Pmpro_Fixture::row('row7', 'active', '2026-12-15 12:00:00', EVC_Pmpro_Fixture::CLUB_LEVEL),
            ),
            array(
                EVC_Pmpro_Fixture::paid('oct', '2026-10-15 12:00', '2026-10-15 12:00', '2026-11-15 12:00', 'row8', array('level_id' => EVC_Pmpro_Fixture::SECOND_CLUB_LEVEL)),
                EVC_Pmpro_Fixture::paid('nov', '2026-11-01 09:00', '2026-11-15 12:00', '2026-12-15 12:00', 'row7'),
            )
        );
        $at = self::when('2026-11-10 09:00');
        $this->assertIndeterminate(EVC_Pmpro_Fixture::two_level_mapper()->map($s, $at), 'level_change_within_paid_run', $at);
    }

    public function test_historical_rows_of_another_approved_level_in_a_lapsed_run_are_supported(): void {
        $s = EVC_Pmpro_Fixture::snapshot(
            array(
                EVC_Pmpro_Fixture::row('row8', 'expired', '2026-09-01 10:00:00', EVC_Pmpro_Fixture::SECOND_CLUB_LEVEL, false, '2026-08-01 10:00:00'),
                EVC_Pmpro_Fixture::row('row7', 'active', '2026-11-15 12:00:00', EVC_Pmpro_Fixture::CLUB_LEVEL),
            ),
            array(
                EVC_Pmpro_Fixture::paid('aug', '2026-08-01 10:00', '2026-08-01 10:00', '2026-09-01 10:00', 'row8', array('level_id' => EVC_Pmpro_Fixture::SECOND_CLUB_LEVEL)),
                EVC_Pmpro_Fixture::paid('oct', '2026-10-15 12:00', '2026-10-15 12:00', '2026-11-15 12:00', 'row7'),
            )
        );
        $e = EVC_Pmpro_Fixture::two_level_mapper()->map($s, self::when('2026-10-20 09:00'));
        $this->assertSame('active', $e->status(), implode(',', $e->diagnostic_flags()));
        $this->assertSame('pmpro:7', $e->level_ref());
    }

    public function test_inconsistent_row_identity_is_rejected(): void {
        $s = EVC_Pmpro_Fixture::single_paid_month();
        // The same row reference reported twice with different contents.
        $s->memberships[] = EVC_Pmpro_Fixture::row('row1', 'changed', '2026-12-15 12:00:00');
        $at = self::when('2026-10-20 09:00');
        $this->assertIndeterminate(EVC_Pmpro_Fixture::mapper()->map($s, $at), 'membership_row_identity_conflict', $at);
    }
}
