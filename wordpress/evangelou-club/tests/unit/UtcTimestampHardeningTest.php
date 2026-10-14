<?php
use PHPUnit\Framework\TestCase;

/**
 * UI-04B — strict canonical UTC timestamps (DATETIME(6) text) in the ledger,
 * handoff records and their write sets; chronology and Athens-date
 * consistency fail closed everywhere the records are read.
 */
final class UtcTimestampHardeningTest extends TestCase {
    const MEMBER = 'mem_0123456789abcdef0123456789abcdef';
    const R1 = '3fa85f64-5717-4562-b3fc-2c963f66afa6';
    const H1 = '7c9e6679-7425-40de-944b-e07fc1f90ae7';
    const H2 = 'a3bb189e-8bf9-4888-9912-ace4e6543002';

    /** @var EVC_In_Memory_Prepared_Redemption_Store */
    private $store;
    /** @var EVC_Fixed_Clock */
    private $clock;
    /** @var EVC_Fake_Club_Staff_Authorizer */
    private $authorizer;

    protected function setUp(): void {
        $this->store = new EVC_In_Memory_Prepared_Redemption_Store();
        $this->store->add_member(self::MEMBER, 501);
        $this->clock = new EVC_Fixed_Clock('2026-10-14T07:30:00Z');
        $this->authorizer = new EVC_Fake_Club_Staff_Authorizer();
        $this->authorizer->grants[7] = array(EVC_Club_Staff_Authorizer::CONFIRM_HANDOFF, EVC_Club_Staff_Authorizer::VIEW_REDEMPTION_STATUS);
    }

    private function redemptions(): EVC_Prepared_Redemption_Service {
        $membership = new EVC_Mock_Membership_Adapter();
        $membership->set(501, EVC_Mock_Membership_Adapter::active_until('2030-01-01T00:00:00Z'));
        $authority = new EVC_Fake_Prep_Catalogue_Authority(json_decode((string) file_get_contents(dirname(__DIR__) . '/fixtures/preparation/catalogue-illustrative.json'), true, 512, JSON_THROW_ON_ERROR));
        return new EVC_Prepared_Redemption_Service($this->store, $authority, $membership, $this->clock, EVC_Prepared_Redemption_Service::ALLOW_ILLUSTRATIVE_CATALOGUE_FOR_TESTS);
    }

    private function redeem(): EVC_Prepared_Redemption_Outcome {
        return $this->redemptions()->redeem(self::MEMBER, 'free_coffee', new EVC_Staff_Actor(7), array(
            'request_id' => self::R1,
            'catalogue_version' => 1,
            'coffee_code' => 'espresso',
            'preparation' => array('modifiers' => array(array('group' => 'shots', 'option' => 'double'), array('group' => 'sweetness', 'option' => 'none')), 'quick_notes' => array()),
        ));
    }

    private function confirm(string $hid = self::H1, string $claim = 'handed_now'): EVC_Handoff_Outcome {
        $service = new EVC_Handoff_Service($this->store, $this->authorizer, new EVC_No_Late_Handoff_Policy(), $this->clock);
        return $service->confirm(self::MEMBER, new EVC_Staff_Actor(7), array('handoff_request_id' => $hid, 'redemption_request_id' => self::R1, 'claim' => $claim));
    }

    // ---- the format itself ----------------------------------------------------------------------

    /** @return array<string,array{mixed,bool}> */
    public static function stamps(): array {
        return array(
            'canonical' => array('2026-10-14 07:30:00.000000', true),
            'canonical, all digits used' => array('2026-12-31 23:59:59.999999', true),
            'leap day in a leap year' => array('2028-02-29 12:00:00.000001', true),
            'leap day of 2000 (divisible by 400)' => array('2000-02-29 00:00:00.000000', true),
            'leap day in a common year' => array('2026-02-29 12:00:00.000000', false),
            'leap day of 2100 (not a leap year)' => array('2100-02-29 12:00:00.000000', false),
            'month 13' => array('2026-13-01 00:00:00.000000', false),
            'day 31 of a 30-day month' => array('2026-04-31 00:00:00.000000', false),
            'day zero' => array('2026-10-00 00:00:00.000000', false),
            'hour 24' => array('2026-10-14 24:00:00.000000', false),
            'minute 60' => array('2026-10-14 23:60:00.000000', false),
            'leap second' => array('2026-12-31 23:59:60.000000', false),
            'missing microseconds' => array('2026-10-14 07:30:00', false),
            'milliseconds only' => array('2026-10-14 07:30:00.000', false),
            'seven fraction digits' => array('2026-10-14 07:30:00.0000000', false),
            'ISO T separator' => array('2026-10-14T07:30:00.000000', false),
            'Z suffix' => array('2026-10-14 07:30:00.000000Z', false),
            'offset' => array('2026-10-14 07:30:00.000000+00:00', false),
            'Athens offset' => array('2026-10-14 10:30:00.000000+03:00', false),
            'trailing newline' => array("2026-10-14 07:30:00.000000\n", false),
            'leading space' => array(' 2026-10-14 07:30:00.000000', false),
            'single-digit month' => array('2026-1-14 07:30:00.000000', false),
            'before 1970' => array('1969-12-31 23:59:59.999999', false),
            'non-ASCII digits' => array('٢٠٢٦-10-14 07:30:00.000000', false),
            'integer' => array(1792000000, false),
            'null' => array(null, false),
            'empty' => array('', false),
        );
    }

    /** @dataProvider stamps */
    public function test_only_canonical_utc_datetime6_text_is_accepted($value, bool $ok): void {
        $this->assertSame($ok, EVC_Utc_Timestamp::is_canonical($value));
    }

    public function test_athens_business_date_of_a_stamp_follows_summer_winter_and_leap_years(): void {
        $this->assertSame('2026-10-14', EVC_Utc_Timestamp::athens_date('2026-10-14 20:59:59.999999'));
        $this->assertSame('2026-10-15', EVC_Utc_Timestamp::athens_date('2026-10-14 21:00:00.000000'), 'midnight EEST');
        $this->assertSame('2026-10-25', EVC_Utc_Timestamp::athens_date('2026-10-25 21:59:59.999999'));
        $this->assertSame('2026-10-26', EVC_Utc_Timestamp::athens_date('2026-10-25 22:00:00.000000'), 'midnight EET');
        $this->assertSame('2028-02-29', EVC_Utc_Timestamp::athens_date('2028-02-28 22:00:00.000000'), 'leap day starts at 22:00 UTC');
        $this->assertSame('2028-03-01', EVC_Utc_Timestamp::athens_date('2028-02-29 22:00:00.000000'));
        $this->assertSame('2027-01-01', EVC_Utc_Timestamp::athens_date('2026-12-31 22:00:00.000000'), 'new year in Athens');
        $this->expectException(InvalidArgumentException::class);
        EVC_Utc_Timestamp::athens_date('2026-10-14T07:30:00Z');
    }

    public function test_comparison_is_chronological(): void {
        $this->assertSame(-1, EVC_Utc_Timestamp::compare('2026-10-14 07:30:00.000000', '2026-10-14 07:30:00.000001'));
        $this->assertSame(0, EVC_Utc_Timestamp::compare('2026-10-14 07:30:00.000000', '2026-10-14 07:30:00.000000'));
        $this->assertSame(1, EVC_Utc_Timestamp::compare('2027-01-01 00:00:00.000000', '2026-12-31 23:59:59.999999'));
    }

    // ---- ledger times are verified wherever a record is read ----------------------------------

    /** @return array<string,array{string}> */
    public static function corrupt_ledger_times(): array {
        return array(
            'missing microseconds' => array('2026-10-14 07:30:00'),
            'ISO with Z' => array('2026-10-14T07:30:00.000000Z'),
            'offset' => array('2026-10-14 10:30:00.000000+03:00'),
            'impossible date' => array('2026-02-30 07:30:00.000000'),
            'impossible time' => array('2026-10-14 25:30:00.000000'),
            'another Athens day' => array('2026-10-14 21:30:00.000000'),
            'the day before' => array('2026-10-13 07:30:00.000000'),
        );
    }

    /** @dataProvider corrupt_ledger_times */
    public function test_a_corrupt_ledger_time_fails_closed_for_replay_handoff_status_and_history($stamp): void {
        $this->assertSame(EVC_Prepared_Redemption_Outcome::REDEEMED, $this->redeem()->kind());
        $this->store->tamper_ledger(self::R1, array('redeemed_at_utc' => $stamp));

        $replay = $this->redeem();
        $this->assertSame(EVC_Prepared_Redemption_Outcome::RECORD_INTEGRITY_FAILURE, $replay->kind());
        $this->assertSame(array('reason' => 'ledger_time_invalid'), $replay->details());
        $this->assertSame(EVC_Prepared_Redemption_Outcome::DO_NOT_PREPARE, $replay->instruction());

        $handoff = $this->confirm();
        $this->assertSame(EVC_Handoff_Outcome::RECORD_INTEGRITY_FAILURE, $handoff->kind());
        $this->assertSame(array(), $this->store->handoff_rows());

        $reader = new EVC_Fulfillment_Read_Model($this->store, $this->authorizer, $this->clock);
        $this->assertSame(EVC_Fulfillment_Read_Result::RECORD_INTEGRITY_FAILURE, $reader->redemption_status(new EVC_Staff_Actor(7), self::MEMBER, self::R1)->kind());
    }

    public function test_a_redemption_at_athens_midnight_is_consistent_and_replays(): void {
        $this->clock->set('2026-10-14T20:59:59.999999Z');
        $first = $this->redeem();
        $this->assertSame('2026-10-14', $first->redemption()['business_date']);
        $this->assertSame('2026-10-14 20:59:59.999999', $first->redemption()['redeemed_at_utc']);
        $this->clock->set('2026-10-14T21:00:00Z');
        $replay = $this->redeem();
        $this->assertSame(EVC_Prepared_Redemption_Outcome::REPLAYED, $replay->kind());
        $this->assertSame(EVC_Prepared_Redemption_Outcome::DO_NOT_PREPARE_FOR_TODAY, $replay->instruction());
    }

    public function test_a_leap_day_redemption_is_consistent(): void {
        $this->clock->set('2028-02-28T22:30:00Z'); // 00:30 Athens, 29 February 2028
        $outcome = $this->redemptions()->redeem(self::MEMBER, 'free_coffee', new EVC_Staff_Actor(7), array(
            'request_id' => self::R1,
            'catalogue_version' => 1,
            'coffee_code' => 'espresso',
            'preparation' => array('modifiers' => array(array('group' => 'shots', 'option' => 'single'), array('group' => 'sweetness', 'option' => 'none')), 'quick_notes' => array()),
        ));
        $this->assertSame('2028-02-29', $outcome->redemption()['business_date']);
        $this->assertSame(EVC_Prepared_Redemption_Outcome::REPLAYED, $this->redemptions()->redeem(self::MEMBER, 'free_coffee', new EVC_Staff_Actor(7), array(
            'request_id' => self::R1,
            'catalogue_version' => 1,
            'coffee_code' => 'espresso',
            'preparation' => array('modifiers' => array(array('group' => 'shots', 'option' => 'single'), array('group' => 'sweetness', 'option' => 'none')), 'quick_notes' => array()),
        ))->kind());
    }

    public function test_legacy_v1_rows_keep_their_own_refusal_reason(): void {
        $this->store->insert_legacy_v1(1, '2026-10-14', self::R1, 'espresso');
        $this->store->tamper_ledger(self::R1, array('redeemed_at_utc' => 'garbage'));
        $handoff = $this->confirm();
        $this->assertSame(array('reason' => 'fingerprint_version'), $handoff->details());
    }

    // ---- handoff record times -------------------------------------------------------------------

    /** @return array<string,array{callable,string}> */
    public static function corrupt_handoff_times(): array {
        return array(
            'missing microseconds' => array(function (array $h) {
                $h['recorded_at_utc'] = '2026-10-14 07:35:00';
                $h['handoff_at_utc'] = $h['recorded_at_utc'];
                return $h;
            }, 'handoff_corrupt'),
            'ISO instant' => array(function (array $h) {
                $h['recorded_at_utc'] = '2026-10-14T07:35:00.000000Z';
                $h['handoff_at_utc'] = $h['recorded_at_utc'];
                return $h;
            }, 'handoff_corrupt'),
            'impossible date' => array(function (array $h) {
                $h['recorded_at_utc'] = '2026-02-31 07:35:00.000000';
                $h['handoff_at_utc'] = $h['recorded_at_utc'];
                return $h;
            }, 'handoff_corrupt'),
            'handed-now time differs from the record time' => array(function (array $h) {
                $h['handoff_at_utc'] = '2026-10-14 07:34:59.999999';
                return $h;
            }, 'handoff_corrupt'),
            'confirmation recorded before the redemption' => array(function (array $h) {
                $h['recorded_at_utc'] = '2026-10-14 07:29:59.999999';
                $h['handoff_at_utc'] = $h['recorded_at_utc'];
                return $h;
            }, 'handoff_conflict'),
        );
    }

    /** @dataProvider corrupt_handoff_times */
    public function test_a_corrupt_handoff_time_fails_closed($corrupt, string $reason): void {
        $this->redeem();
        $this->clock->set('2026-10-14T07:35:00Z');
        $this->assertSame(EVC_Handoff_Outcome::CONFIRMED, $this->confirm()->kind());
        $row = $this->store->handoff_rows()[0];
        $this->store->tamper_handoffs(self::R1, array($corrupt($row)));

        $this->assertSame(array('reason' => $reason), $this->redeem()->details());
        $retry = $this->confirm();
        $this->assertSame(EVC_Handoff_Outcome::RECORD_INTEGRITY_FAILURE, $retry->kind());
        $this->assertSame(array('reason' => $reason), $retry->details());
        $this->assertSame(EVC_Handoff_Outcome::RECORD_INTEGRITY_FAILURE, $this->confirm(self::H2)->kind());
    }

    public function test_a_confirmation_in_the_same_microsecond_as_the_redemption_is_valid(): void {
        $this->redeem();
        $this->assertSame(EVC_Handoff_Outcome::CONFIRMED, $this->confirm()->kind());
        $row = $this->store->handoff_rows()[0];
        $this->assertSame('2026-10-14 07:30:00.000000', $row['recorded_at_utc']);
        $this->assertSame(EVC_Prepared_Redemption_Outcome::DO_NOT_PREPARE, $this->redeem()->instruction());
    }

    public function test_an_earlier_handoff_never_gets_a_time(): void {
        $this->redeem();
        $this->clock->set('2026-10-14T08:00:00Z');
        $this->confirm(self::H1, 'already_handed_earlier');
        $row = $this->store->handoff_rows()[0];
        $this->assertNull($row['handoff_at_utc']);
        $row['handoff_at_utc'] = '2026-10-14 07:40:00.000000'; // an invented time
        $this->store->tamper_handoffs(self::R1, array($row));
        $this->assertSame(array('reason' => 'handoff_corrupt'), $this->redeem()->details());
    }

    // ---- write sets refuse non-canonical times before anything is stored ----------------------

    public function test_write_sets_refuse_bad_times(): void {
        $this->redeem();
        $ledger = $this->store->ledger_rows()[0];
        $snapshot = $this->store->snapshots()[$ledger['id']][0];
        $audit = $this->store->audit_events()[0];
        unset($ledger['id'], $audit['redemption_id']);
        new EVC_Prepared_Redemption_Write($ledger, $snapshot, $audit);

        $cases = array(
            'non-canonical redemption time' => array('redeemed_at_utc' => '2026-10-14 07:30:00'),
            'business date of another Athens day' => array('business_date' => '2026-10-15'),
            'non-canonical expiry' => array('membership_expires_at_utc' => '2030-01-01T00:00:00Z'),
            'expiry before the redemption' => array('membership_expires_at_utc' => '2026-10-14 07:29:59.999999'),
            'expiry equal to the redemption' => array('membership_expires_at_utc' => '2026-10-14 07:30:00.000000'),
        );
        foreach ($cases as $name => $change) {
            $l = array_merge($ledger, $change);
            $a = $audit;
            if (isset($change['redeemed_at_utc'])) {
                $a['occurred_at_utc'] = $change['redeemed_at_utc'];
            }
            try {
                new EVC_Prepared_Redemption_Write($l, $snapshot, $a);
                $this->fail('accepted ' . $name);
            } catch (InvalidArgumentException $e) {
                $this->assertSame('Inconsistent redemption write: time.', $e->getMessage(), $name);
            }
        }

        $this->confirm();
        $record = $this->store->handoff_rows()[0];
        unset($record['id']);
        $handoff_audit = null;
        foreach ($this->store->audit_events() as $event) {
            if ($event['event_type'] === 'handoff') {
                $handoff_audit = $event;
            }
        }
        new EVC_Handoff_Write($record, $handoff_audit);
        $record['recorded_at_utc'] = '2026-10-14 07:30:00';
        $record['handoff_at_utc'] = $record['recorded_at_utc'];
        $handoff_audit['occurred_at_utc'] = $record['recorded_at_utc'];
        $this->expectException(InvalidArgumentException::class);
        new EVC_Handoff_Write($record, $handoff_audit);
    }
}
