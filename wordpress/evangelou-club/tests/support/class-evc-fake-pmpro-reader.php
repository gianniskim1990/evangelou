<?php
/**
 * TEST-ONLY PMPro reader and fixture builder (Task 1D-B). Lives under tests/
 * so it can never ship in the plugin package. All data is synthetic: no real
 * users, orders, gateways or personal data.
 */
final class EVC_Fake_Pmpro_Reader implements EVC_Pmpro_Reader {
    /** @var EVC_Pmpro_Snapshot|Throwable */
    private $result;
    /** @var int[] */
    public $calls = array();

    /** @param EVC_Pmpro_Snapshot|Throwable $result */
    public function __construct($result) {
        $this->result = $result;
    }

    public function snapshot(int $wp_user_id): EVC_Pmpro_Snapshot {
        $this->calls[] = $wp_user_id;
        if ($this->result instanceof Throwable) {
            throw $this->result;
        }
        return $this->result;
    }
}

/**
 * Builds normalised fixtures. Times are written as Europe/Athens wall-clock
 * strings and periods are spelled out by hand in each test: fixtures never use
 * EVC_Membership_Calendar, so the mapper is checked against independent
 * expectations rather than against itself.
 */
final class EVC_Pmpro_Fixture {
    const CLUB_LEVEL = 7;
    const OTHER_LEVEL = 9;
    const PMPRO_VERSION = '3.4.1';

    /** Opaque 64-hex test reference (plain SHA-256 of a label: test-only). */
    public static function ref(string $label): string {
        return hash('sha256', 'evc-fixture:' . $label);
    }

    /** Europe/Athens wall-clock "Y-m-d H:i[:s]" (must be unambiguous) -> UTC instant. */
    public static function athens(string $local): DateTimeImmutable {
        return (new DateTimeImmutable($local, new DateTimeZone('Europe/Athens')))->setTimezone(new DateTimeZone('UTC'));
    }

    public static function utc(string $instant): DateTimeImmutable {
        return (new DateTimeImmutable($instant))->setTimezone(new DateTimeZone('UTC'));
    }

    const SECOND_CLUB_LEVEL = 8;

    public static function config(): EVC_Pmpro_Mapper_Config {
        return new EVC_Pmpro_Mapper_Config(array(self::CLUB_LEVEL), '3.0', '4.0');
    }

    public static function mapper(): EVC_Pmpro_Entitlement_Mapper {
        return new EVC_Pmpro_Entitlement_Mapper(self::config());
    }

    /** Explicitly configured with TWO approved Club levels (7 and 8). */
    public static function two_level_mapper(): EVC_Pmpro_Entitlement_Mapper {
        return new EVC_Pmpro_Entitlement_Mapper(new EVC_Pmpro_Mapper_Config(array(self::CLUB_LEVEL, self::SECOND_CLUB_LEVEL), '3.0', '4.0'));
    }

    /** Raw PMPro row; dates are site-local wall-clock strings exactly as PMPro stores them. */
    public static function row(string $label = 'row1', string $status = 'active', ?string $end_local = '2026-11-15 12:00:00', int $level = self::CLUB_LEVEL, bool $cancellation_pending = false, ?string $start_local = '2026-10-15 12:00:00'): EVC_Pmpro_Membership_Row {
        return new EVC_Pmpro_Membership_Row(self::ref($label), $level, $status, $start_local, $end_local, $cancellation_pending);
    }

    /**
     * A payment fact. $start/$end are the RECORDED paid period (provenance),
     * as instants; null = provenance not proven.
     *
     * @param array<string,mixed> $override property => value
     */
    public static function payment(
        string $label,
        string $status,
        ?DateTimeImmutable $confirmed,
        ?DateTimeImmutable $start,
        ?DateTimeImmutable $end,
        string $row_label = 'row1',
        array $override = array()
    ): EVC_Pmpro_Payment_Fact {
        $fact = new EVC_Pmpro_Payment_Fact(
            self::ref('payment-record:' . $label),
            self::ref('money:' . $label),
            EVC_Payment_Evidence::KIND_PMPRO_ORDER,
            self::CLUB_LEVEL,
            self::ref($row_label),
            $status,
            'live',
            $confirmed,
            $start,
            $end
        );
        foreach ($override as $property => $value) {
            $fact->$property = $value;
        }
        return $fact;
    }

    /** Confirmed payment whose recorded period is given as Athens wall-clock strings. */
    public static function paid(string $label, string $confirmed_local, string $start_local, string $end_local, string $row_label = 'row1', array $override = array()): EVC_Pmpro_Payment_Fact {
        return self::payment($label, 'confirmed', self::athens($confirmed_local), self::athens($start_local), self::athens($end_local), $row_label, $override);
    }

    /**
     * @param EVC_Pmpro_Membership_Row[] $rows
     * @param EVC_Pmpro_Payment_Fact[] $payments
     * @param array<string,mixed> $override snapshot property => value
     */
    public static function snapshot(array $rows, array $payments, array $override = array()): EVC_Pmpro_Snapshot {
        $s = new EVC_Pmpro_Snapshot(EVC_Pmpro_Snapshot::CONTRACT_VERSION, true, self::PMPRO_VERSION, 'Europe/Athens', true, $rows, $payments);
        foreach ($override as $property => $value) {
            $s->$property = $value;
        }
        return $s;
    }

    /** The reference scenario: one confirmed payment 15 Oct 12:00 -> 15 Nov 12:00 Athens. */
    public static function single_paid_month(): EVC_Pmpro_Snapshot {
        return self::snapshot(
            array(self::row()),
            array(self::paid('oct', '2026-10-15 12:00', '2026-10-15 12:00', '2026-11-15 12:00'))
        );
    }
}
