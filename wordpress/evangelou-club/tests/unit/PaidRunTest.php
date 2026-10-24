<?php
use PHPUnit\Framework\TestCase;

/** STAGE-03B.2: start of the contiguous paid run for a planned period (read-only). */
final class PaidRunTest extends TestCase {
    private static function rec(EVC_In_Memory_Provenance_Store $s): EVC_Provenance_Recorder {
        return EVC_Provenance_Test_Fixture::recorder($s);
    }

    private static function prospective(string $label, string $at): EVC_Prospective_Payment {
        $m = EVC_Provenance_Test_Fixture::movement($label, $at);
        return new EVC_Prospective_Payment($m->movement_id(), 'live', $m->wp_user_id(), $m->level_id(), $m->verified_at_utc(), 2000, 'EUR', $m->aliases(), $m->authority(), $m->evidence_kind());
    }

    private static function local(DateTimeImmutable $i): string {
        return $i->setTimezone(new DateTimeZone('Europe/Athens'))->format('Y-m-d H:i:s');
    }

    public function test_initial_and_late_periods_start_their_own_run(): void {
        $s = new EVC_In_Memory_Provenance_Store();
        $plan = self::rec($s)->plan(self::prospective('oct', '2026-10-15 12:00:00'));
        $this->assertSame('2026-10-15 12:00:00', self::local(EVC_Paid_Run::start_for($s, $plan)));
        self::rec($s)->record(EVC_Provenance_Test_Fixture::movement('oct', '2026-10-15 12:00:00'));
        $late = self::rec($s)->plan(self::prospective('dec', '2026-12-01 10:00:00'));
        $this->assertSame('late', $late->kind());
        $this->assertSame('2026-12-01 10:00:00', self::local(EVC_Paid_Run::start_for($s, $late)));
    }

    public function test_early_renewals_continue_the_run_back_to_its_first_period(): void {
        $s = new EVC_In_Memory_Provenance_Store();
        self::rec($s)->record(EVC_Provenance_Test_Fixture::movement('oct', '2026-10-15 12:00:00'));
        self::rec($s)->record(EVC_Provenance_Test_Fixture::movement('nov', '2026-11-10 09:00:00'));
        $plan = self::rec($s)->plan(self::prospective('dec', '2026-12-10 09:00:00'));
        $this->assertSame('early', $plan->kind());
        $this->assertSame('2026-10-15 12:00:00', self::local(EVC_Paid_Run::start_for($s, $plan)));
    }

    public function test_a_late_renewal_breaks_the_run(): void {
        $s = new EVC_In_Memory_Provenance_Store();
        self::rec($s)->record(EVC_Provenance_Test_Fixture::movement('oct', '2026-10-15 12:00:00'));
        self::rec($s)->record(EVC_Provenance_Test_Fixture::movement('dec', '2026-12-01 10:00:00'));
        $plan = self::rec($s)->plan(self::prospective('dec2', '2026-12-20 10:00:00'));
        $this->assertSame('early', $plan->kind());
        $this->assertSame('2026-12-01 10:00:00', self::local(EVC_Paid_Run::start_for($s, $plan)));
    }

    public function test_a_broken_chain_is_never_guessed(): void {
        $start = EVC_Pmpro_Fixture::athens('2026-11-15 12:00:00');
        $plan = EVC_Period_Plan::planned(EVC_Pmpro_Fixture::ref('mv:x'), $start, EVC_Membership_Calendar::add_calendar_month($start), 'early', EVC_Pmpro_Fixture::ref('mv:missing'));
        $this->expectException(UnexpectedValueException::class);
        EVC_Paid_Run::start_for(new EVC_In_Memory_Provenance_Store(), $plan);
    }

    public function test_a_gap_in_the_chain_is_never_bridged(): void {
        $s = new EVC_In_Memory_Provenance_Store();
        self::rec($s)->record(EVC_Provenance_Test_Fixture::movement('oct', '2026-10-15 12:00:00'));
        $start = EVC_Pmpro_Fixture::athens('2026-11-15 13:00:00'); // one hour after the paid end
        $plan = EVC_Period_Plan::planned(EVC_Pmpro_Fixture::ref('mv:x'), $start, EVC_Membership_Calendar::add_calendar_month($start), 'early', EVC_Pmpro_Fixture::ref('mv:oct'));
        $this->expectException(UnexpectedValueException::class);
        EVC_Paid_Run::start_for($s, $plan);
    }

    public function test_only_a_planned_period_has_a_run(): void {
        $this->expectException(InvalidArgumentException::class);
        EVC_Paid_Run::start_for(new EVC_In_Memory_Provenance_Store(), EVC_Period_Plan::unavailable('x'));
    }
}
