<?php
defined('ABSPATH') || (defined('EVC_STANDALONE_TEST') && EVC_STANDALONE_TEST) || exit;

/**
 * One payment fact, normalised by the trusted reader from a PMPro order, a
 * WooCommerce order or a recorded manual (cash/bank) confirmation.
 *
 * status (normalised by the reader; anything else is rejected):
 *   confirmed           money verified received (PMPro "success", Woo "completed",
 *                       manual payment explicitly marked paid)
 *   pending             not (yet) verified: pending, on-hold, processing, review,
 *                       unconfirmed cash / bank transfer
 *   failed              never paid
 *   refunded            was confirmed, then fully refunded
 *   partially_refunded  was confirmed, then partly refunded (owner policy D3 open)
 *   reversed            was confirmed, then cancelled / charged back
 *
 * Provenance: membership_row_ref + period_start_utc/period_end_utc state which
 * PMPro row and which paid period this payment funds, as RECORDED by the
 * source. Null means "not proven" and is never guessed.
 */
final class EVC_Pmpro_Payment_Fact {
    const STATUS_CONFIRMED = 'confirmed';
    const STATUS_PENDING = 'pending';
    const STATUS_FAILED = 'failed';
    const STATUS_REFUNDED = 'refunded';
    const STATUS_PARTIALLY_REFUNDED = 'partially_refunded';
    const STATUS_REVERSED = 'reversed';

    const ENV_LIVE = 'live';

    /** @var string opaque 64-hex reference of this payment record */
    public $payment_ref;
    /** @var string|null opaque 64-hex identity of the underlying money movement (same for repeated notifications) */
    public $dedupe_key;
    /** @var string EVC_Payment_Evidence::KIND_* */
    public $kind;
    /** @var int */
    public $level_id;
    /** @var string|null */
    public $membership_row_ref;
    /** @var string */
    public $status;
    /** @var string "live", "sandbox" or anything else the source reports */
    public $environment;
    /** @var DateTimeImmutable|null instant the payment was VERIFIED (not order creation) */
    public $confirmed_at_utc;
    /** @var DateTimeImmutable|null */
    public $period_start_utc;
    /** @var DateTimeImmutable|null */
    public $period_end_utc;

    public function __construct(
        string $payment_ref,
        ?string $dedupe_key,
        string $kind,
        int $level_id,
        ?string $membership_row_ref,
        string $status,
        string $environment,
        ?DateTimeImmutable $confirmed_at_utc,
        ?DateTimeImmutable $period_start_utc,
        ?DateTimeImmutable $period_end_utc
    ) {
        $this->payment_ref = $payment_ref;
        $this->dedupe_key = $dedupe_key;
        $this->kind = $kind;
        $this->level_id = $level_id;
        $this->membership_row_ref = $membership_row_ref;
        $this->status = $status;
        $this->environment = $environment;
        $this->confirmed_at_utc = $confirmed_at_utc;
        $this->period_start_utc = $period_start_utc;
        $this->period_end_utc = $period_end_utc;
    }

    /** @return string[] */
    public static function statuses(): array {
        return array(
            self::STATUS_CONFIRMED,
            self::STATUS_PENDING,
            self::STATUS_FAILED,
            self::STATUS_REFUNDED,
            self::STATUS_PARTIALLY_REFUNDED,
            self::STATUS_REVERSED,
        );
    }

    /** Statuses that mean the money WAS verified at confirmed_at_utc (even if later undone). */
    public function was_confirmed(): bool {
        return in_array($this->status, array(self::STATUS_CONFIRMED, self::STATUS_REFUNDED, self::STATUS_PARTIALLY_REFUNDED, self::STATUS_REVERSED), true);
    }
}
