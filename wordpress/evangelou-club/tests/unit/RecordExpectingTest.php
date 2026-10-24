<?php
use PHPUnit\Framework\TestCase;

/**
 * STAGE-03B.3a: EVC_Provenance_Recorder::record_expecting() commits ONLY the
 * signed, expected period. A changed chain is refused BEFORE commit, and a
 * chain that changes between the recorder's read and the commit is refused by
 * the store's compare-and-set — a different period is never committed.
 */
final class RecordExpectingTest extends TestCase {
    private static function m(string $label, string $at, array $o = array()): EVC_Verified_Movement {
        return EVC_Provenance_Test_Fixture::movement($label, $at, $o);
    }

    private static function rec(EVC_Provenance_Store $s): EVC_Provenance_Recorder {
        return EVC_Provenance_Test_Fixture::recorder($s);
    }

    /** The plan a prospective twin of $m would get from $s right now. */
    private static function plan_for(EVC_Provenance_Store $s, EVC_Verified_Movement $m): EVC_Period_Plan {
        return self::rec($s)->plan(new EVC_Prospective_Payment($m->movement_id(), $m->environment(), $m->wp_user_id(), $m->level_id(), $m->verified_at_utc(), $m->amount_minor(), $m->currency(), $m->aliases(), $m->authority(), $m->evidence_kind()));
    }

    private static function local(DateTimeImmutable $i): string {
        return $i->setTimezone(new DateTimeZone('Europe/Athens'))->format('Y-m-d H:i:s');
    }

    public function test_the_expected_period_is_recorded(): void {
        $s = new EVC_In_Memory_Provenance_Store();
        $m = self::m('oct', '2026-10-15 12:00:00');
        $plan = self::plan_for($s, $m);
        $o = self::rec($s)->record_expecting($m, $plan);
        $this->assertSame(EVC_Record_Outcome::NEWLY_RECORDED, $o->outcome(), (string) $o->reason());
        $this->assertTrue($plan->matches_recorded($o->period()));
    }

    public function test_a_chain_changed_since_the_plan_is_refused_before_commit(): void {
        $s = new EVC_In_Memory_Provenance_Store();
        $ours = self::m('ours', '2026-10-15 12:00:00');
        $plan = self::plan_for($s, $ours); // initial month, as signed
        // Another verified payment, EARLIER than ours, is recorded after the plan was fixed.
        self::rec($s)->record(self::m('other', '2026-10-15 11:00:00', array('row' => EVC_Provenance_Test_Fixture::ref('row9'))));
        $counts = $s->counts();
        $commits = $s->commits;

        $o = self::rec($s)->record_expecting($ours, $plan);
        $this->assertSame(EVC_Record_Outcome::CONFLICT, $o->outcome());
        $this->assertSame('expected_period_changed', $o->reason());
        $this->assertSame($counts, $s->counts(), 'nothing committed');
        $this->assertSame($commits, $s->commits);
        $this->assertNull($s->movement($ours->movement_id()));

        // Plain record() WOULD have funded a different (early-renewal) period: that is what is prevented.
        $plain = self::rec($s)->record($ours);
        $this->assertSame(EVC_Record_Outcome::NEWLY_RECORDED, $plain->outcome());
        $this->assertFalse($plan->matches_recorded($plain->period()));
        $this->assertSame('early', $plain->period()->kind());
        $this->assertSame('2026-11-15 11:00:00', self::local($plain->period()->start_utc()));
    }

    public function test_a_chain_change_between_read_and_commit_is_refused_by_the_store(): void {
        $s = new EVC_In_Memory_Provenance_Store();
        $ours = self::m('ours', '2026-10-15 12:00:00');
        $plan = self::plan_for($s, $ours);
        $s->before_next_commit = function (EVC_In_Memory_Provenance_Store $store) {
            // A concurrent writer wins the race after record_expecting() compared the period.
            self::rec($store)->record(self::m('racer', '2026-10-15 11:30:00', array('row' => EVC_Provenance_Test_Fixture::ref('row9'))));
        };
        $o = self::rec($s)->record_expecting($ours, $plan);
        $this->assertSame(EVC_Record_Outcome::CONFLICT, $o->outcome());
        $this->assertSame('concurrent_update', $o->reason());
        $this->assertNull($s->movement($ours->movement_id()), 'the store compare-and-set kept our period out');
        $this->assertSame(1, $s->counts()['periods']);
    }

    public function test_a_repeat_must_carry_the_expected_period(): void {
        $s = new EVC_In_Memory_Provenance_Store();
        $m = self::m('oct', '2026-10-15 12:00:00');
        $plan = self::plan_for($s, $m);
        self::rec($s)->record_expecting($m, $plan);
        $again = self::rec($s)->record_expecting($m, $plan);
        $this->assertSame(EVC_Record_Outcome::ALREADY_RECORDED, $again->outcome());
        $this->assertSame(1, $s->counts()['periods']);

        $at = $m->verified_at_utc()->modify('+1 day');
        $other = EVC_Period_Plan::planned($m->movement_id(), $at, EVC_Membership_Calendar::add_calendar_month($at), 'initial', null);
        $o = self::rec($s)->record_expecting($m, $other);
        $this->assertSame(EVC_Record_Outcome::CONFLICT, $o->outcome());
        $this->assertSame('recorded_period_differs', $o->reason());
    }

    public function test_only_a_planned_period_for_this_movement_is_accepted(): void {
        $s = new EVC_In_Memory_Provenance_Store();
        $m = self::m('oct', '2026-10-15 12:00:00');
        $foreign = self::plan_for($s, self::m('other', '2026-10-15 12:00:00'));
        foreach (array($foreign, EVC_Period_Plan::refused(EVC_Record_Outcome::conflict('x')), EVC_Period_Plan::unavailable('x')) as $plan) {
            $o = self::rec($s)->record_expecting($m, $plan);
            $this->assertSame(EVC_Record_Outcome::CONFLICT, $o->outcome());
            $this->assertSame('expected_period_invalid', $o->reason());
        }
        $this->assertSame(0, $s->commits);
    }

    public function test_store_failures_are_reported_never_as_success(): void {
        $s = new EVC_In_Memory_Provenance_Store();
        $m = self::m('oct', '2026-10-15 12:00:00');
        $plan = self::plan_for($s, $m);
        $s->unavailable = true;
        $this->assertSame('store_unavailable', self::rec($s)->record_expecting($m, $plan)->reason());
        $s->unavailable = false;
        $hooked = new EVC_Hooked_Provenance_Store($s);
        $hooked->commit_mode = EVC_Hooked_Provenance_Store::COMMIT_UNKNOWN_AFTER;
        $o = self::rec($hooked)->record_expecting($m, $plan);
        $this->assertSame(EVC_Record_Outcome::UNAVAILABLE, $o->outcome());
        $this->assertSame('commit_outcome_unknown', $o->reason());
        $this->assertNotNull($s->movement($m->movement_id()), 'unknown, yet in fact written: read-back decides');
    }

    public function test_plain_record_is_unchanged(): void {
        $s = new EVC_In_Memory_Provenance_Store();
        $o = self::rec($s)->record(self::m('oct', '2026-10-15 12:00:00'));
        $this->assertSame(EVC_Record_Outcome::NEWLY_RECORDED, $o->outcome());
        $this->assertSame('2026-11-15 12:00:00', self::local($o->period()->end_utc()));
    }
}
