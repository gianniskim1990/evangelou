<?php
/**
 * TEST-ONLY synthetic BACS intents (STAGE-03B.2). Never shipped (the packager
 * rejects this class). Opaque references and numeric ids only.
 */
final class EVC_Bacs_Intent_Fixture {
    const KEY = 'test-only-evidence-key-0123456789abcdef-0123456789';
    const ORDER = 100;
    const CUSTOMER = 42;
    const ACTOR = 7;

    public static function codec(string $key = self::KEY): EVC_Bacs_Intent_Codec {
        return new EVC_Bacs_Intent_Codec($key);
    }

    public static function ref(string $label): string {
        return EVC_Pmpro_Fixture::ref($label);
    }

    /** Initial-month intent confirmed at $at (Athens wall clock). */
    public static function initial(string $at = '2026-10-15 12:00:00', array $o = array()): EVC_Bacs_Intent {
        $verified = EVC_Pmpro_Fixture::athens($at);
        $plan = EVC_Period_Plan::planned($o['movement'] ?? self::ref('mv:transfer'), $verified, EVC_Membership_Calendar::add_calendar_month($verified), EVC_Funded_Period::KIND_INITIAL, null);
        return EVC_Bacs_Intent::planned(
            $o['env'] ?? 'live',
            $o['order'] ?? self::ORDER,
            $o['customer'] ?? self::CUSTOMER,
            $o['level'] ?? EVC_Pmpro_Fixture::CLUB_LEVEL,
            $o['movement'] ?? self::ref('mv:transfer'),
            $o['anchor'] ?? self::ref('anchor:order100'),
            $o['actor'] ?? self::ACTOR,
            $verified,
            $plan,
            $verified,
            null
        );
    }

    /** Early renewal: confirmed at $at while the paid run [run_start, paid_end) is running. */
    public static function early(string $at, string $run_start, string $paid_end, array $o = array()): EVC_Bacs_Intent {
        $verified = EVC_Pmpro_Fixture::athens($at);
        $start = EVC_Pmpro_Fixture::athens($paid_end);
        $plan = EVC_Period_Plan::planned($o['movement'] ?? self::ref('mv:renewal'), $start, EVC_Membership_Calendar::add_calendar_month($start), EVC_Funded_Period::KIND_EARLY, $o['predecessor'] ?? self::ref('mv:first'));
        return EVC_Bacs_Intent::planned(
            'live',
            $o['order'] ?? 101,
            self::CUSTOMER,
            EVC_Pmpro_Fixture::CLUB_LEVEL,
            $o['movement'] ?? self::ref('mv:renewal'),
            $o['anchor'] ?? self::ref('anchor:order101'),
            self::ACTOR,
            $verified,
            $plan,
            EVC_Pmpro_Fixture::athens($run_start),
            $o['previous_row'] ?? self::ref('row:old')
        );
    }
}
