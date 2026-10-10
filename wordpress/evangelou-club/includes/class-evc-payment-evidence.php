<?php
defined('ABSPATH') || (defined('EVC_STANDALONE_TEST') && EVC_STANDALONE_TEST) || exit;

/**
 * Minimal, privacy-preserving proof that a CONFIRMED live payment funds the
 * paid period an entitlement relies on (Task 1D-B).
 *
 * Holds no names, addresses, phones, e-mails, card data or raw gateway /
 * order identifiers: `reference` must be an opaque 64-hex value produced by
 * the trusted reader, e.g. EVC_Evidence_Ref::hmac() keyed with a secret the
 * WordPress reader injects. This class cannot tell an HMAC from any other
 * 64-hex string; the reader contract is what guarantees it.
 */
final class EVC_Payment_Evidence {
    const KIND_PMPRO_ORDER = 'pmpro_order';
    const KIND_WC_ORDER = 'wc_order';
    const KIND_MANUAL_CONFIRMATION = 'manual_confirmation';

    const REFERENCE_PATTERN = '/^[0-9a-f]{64}$/D';

    /** @var string */
    private $kind;
    /** @var string */
    private $reference;
    /** @var DateTimeImmutable */
    private $confirmed_at_utc;
    /** @var DateTimeImmutable */
    private $period_start_utc;
    /** @var DateTimeImmutable */
    private $period_end_utc;

    public function __construct(string $kind, string $reference, DateTimeImmutable $confirmed_at, DateTimeImmutable $period_start, DateTimeImmutable $period_end) {
        if (!in_array($kind, self::kinds(), true)) {
            throw new InvalidArgumentException('Unknown payment evidence kind.');
        }
        if (!preg_match(self::REFERENCE_PATTERN, $reference)) {
            throw new InvalidArgumentException('Payment evidence reference must be an opaque 64-hex value.');
        }
        $period_start = EVC_Clock::to_utc($period_start);
        $period_end = EVC_Clock::to_utc($period_end);
        if ($period_end <= $period_start) {
            throw new InvalidArgumentException('Paid period must end after it starts.');
        }
        $this->kind = $kind;
        $this->reference = $reference;
        $this->confirmed_at_utc = EVC_Clock::to_utc($confirmed_at);
        $this->period_start_utc = $period_start;
        $this->period_end_utc = $period_end;
    }

    /** @return string[] */
    public static function kinds(): array {
        return array(self::KIND_PMPRO_ORDER, self::KIND_WC_ORDER, self::KIND_MANUAL_CONFIRMATION);
    }

    public function kind(): string {
        return $this->kind;
    }

    public function reference(): string {
        return $this->reference;
    }

    public function confirmed_at_utc(): DateTimeImmutable {
        return $this->confirmed_at_utc;
    }

    public function period_start_utc(): DateTimeImmutable {
        return $this->period_start_utc;
    }

    public function period_end_utc(): DateTimeImmutable {
        return $this->period_end_utc;
    }
}
