<?php
use PHPUnit\Framework\TestCase;

final class ClockTest extends TestCase {
    /** @var string */
    private $original_tz;

    protected function setUp(): void {
        $this->original_tz = date_default_timezone_get();
    }

    protected function tearDown(): void {
        date_default_timezone_set($this->original_tz);
    }

    /** @return array<string,array{string,string}> UTC instant => Athens business date */
    public static function business_dates(): array {
        return array(
            'winter: last microsecond before midnight' => array('2026-01-01T21:59:59.999999Z', '2026-01-01'),
            'winter: midnight (+02:00)' => array('2026-01-01T22:00:00Z', '2026-01-02'),
            'summer: last second before midnight' => array('2026-07-01T20:59:59.999999Z', '2026-07-01'),
            'summer: midnight (+03:00)' => array('2026-07-01T21:00:00Z', '2026-07-02'),
            'spring DST: midnight starting 29 Mar is still +02:00' => array('2026-03-28T22:00:00Z', '2026-03-29'),
            'spring DST: just before 28 Mar ends' => array('2026-03-28T21:59:59Z', '2026-03-28'),
            'spring DST: 02:59:59 +02:00 before the jump' => array('2026-03-29T00:59:59Z', '2026-03-29'),
            'spring DST: 04:00 +03:00 after the jump' => array('2026-03-29T01:00:00Z', '2026-03-29'),
            'spring DST: end of 29 Mar (+03:00)' => array('2026-03-29T20:59:59Z', '2026-03-29'),
            'spring DST: 30 Mar begins at 21:00Z' => array('2026-03-29T21:00:00Z', '2026-03-30'),
            'autumn DST: 25 Oct begins at 21:00Z (+03:00)' => array('2026-10-24T21:00:00Z', '2026-10-25'),
            'autumn DST: just before 25 Oct' => array('2026-10-24T20:59:59Z', '2026-10-24'),
            'autumn DST: 03:30 +03:00 (first pass)' => array('2026-10-25T00:30:00Z', '2026-10-25'),
            'autumn DST: 03:30 +02:00 (repeated hour)' => array('2026-10-25T01:30:00Z', '2026-10-25'),
            'autumn DST: end of 25 Oct is 22:00Z (+02:00)' => array('2026-10-25T21:59:59Z', '2026-10-25'),
            'autumn DST: 26 Oct begins at 22:00Z' => array('2026-10-25T22:00:00Z', '2026-10-26'),
        );
    }

    /** @dataProvider business_dates */
    public function test_business_date_is_europe_athens_day(string $utc_instant, string $expected): void {
        $this->assertSame($expected, EVC_Clock::business_date(new DateTimeImmutable($utc_instant)));
    }

    /** @dataProvider business_dates */
    public function test_business_date_ignores_php_default_timezone(string $utc_instant, string $expected): void {
        foreach (array('UTC', 'America/New_York', 'Pacific/Kiritimati', 'Etc/GMT-3', 'Asia/Tokyo', 'Europe/Athens') as $tz) {
            date_default_timezone_set($tz);
            $this->assertSame($expected, EVC_Clock::business_date(new DateTimeImmutable($utc_instant)), "default tz $tz");
        }
    }

    public function test_business_date_uses_the_instant_not_the_input_offset(): void {
        // 2026-01-02 00:30 in Tokyo is 2026-01-01 15:30Z = 17:30 in Athens on 1 Jan.
        $tokyo = new DateTimeImmutable('2026-01-02 00:30:00', new DateTimeZone('Asia/Tokyo'));
        $this->assertSame('2026-01-01', EVC_Clock::business_date($tokyo));
    }

    public function test_business_timestamp_carries_the_correct_dst_offset(): void {
        $this->assertSame('2026-01-02T00:00:00+02:00', EVC_Clock::business_timestamp(new DateTimeImmutable('2026-01-01T22:00:00Z')));
        $this->assertSame('2026-07-02T00:00:00+03:00', EVC_Clock::business_timestamp(new DateTimeImmutable('2026-07-01T21:00:00Z')));
        $this->assertSame('2026-03-29T04:00:00+03:00', EVC_Clock::business_timestamp(new DateTimeImmutable('2026-03-29T01:00:00Z')));
        $this->assertSame('2026-10-25T03:30:00+03:00', EVC_Clock::business_timestamp(new DateTimeImmutable('2026-10-25T00:30:00Z')));
        $this->assertSame('2026-10-25T03:30:00+02:00', EVC_Clock::business_timestamp(new DateTimeImmutable('2026-10-25T01:30:00Z')));
    }

    public function test_to_utc_preserves_instant_and_microseconds(): void {
        $athens = new DateTimeImmutable('2026-10-09 13:00:00.123456', new DateTimeZone('Europe/Athens'));
        $utc = EVC_Clock::to_utc($athens);
        $this->assertSame('2026-10-09 10:00:00.123456', $utc->format('Y-m-d H:i:s.u'));
        $this->assertSame('+00:00', $utc->format('P'));
    }

    public function test_system_clock_returns_utc_now(): void {
        $before = microtime(true);
        $now = (new EVC_System_Clock())->now();
        $after = microtime(true);
        $this->assertSame('+00:00', $now->format('P'));
        $this->assertGreaterThanOrEqual(floor($before), (float) $now->format('U'));
        $this->assertLessThanOrEqual(ceil($after), (float) $now->format('U'));
    }
}
