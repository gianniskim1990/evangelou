<?php
defined('ABSPATH') || (defined('EVC_STANDALONE_TEST') && EVC_STANDALONE_TEST) || exit;

/**
 * Authoritative membership entitlement as reported by a membership adapter
 * (mock in tests; Paid Memberships Pro later). Fail-closed rules:
 *
 *   eligible  <=>  status == active
 *              AND payment_verified (completed payment, not pending/unpaid)
 *              AND expires_at_utc is known
 *              AND evaluation instant < expires_at_utc   (expiry is exclusive)
 *
 * An unknown/missing expiry is NOT eligible: Phase 1 memberships are manual
 * one-month purchases that must always carry an end date. FluentCRM tags are
 * never an input here.
 */
final class EVC_Entitlement {
    const STATUS_ACTIVE = 'active';
    const STATUS_EXPIRED = 'expired';
    const STATUS_CANCELLED = 'cancelled';
    const STATUS_PENDING_PAYMENT = 'pending_payment';
    const STATUS_PAYMENT_FAILED = 'payment_failed';
    const STATUS_REFUNDED = 'refunded';
    const STATUS_NONE = 'none';

    const DENIAL_PAYMENT_UNVERIFIED = 'payment_unverified';
    const DENIAL_EXPIRY_UNKNOWN = 'expiry_unknown';
    const DENIAL_EXPIRED = 'expired';

    /** @var string */
    private $status;
    /** @var bool */
    private $payment_verified;
    /** @var DateTimeImmutable|null */
    private $expires_at_utc;
    /** @var string|null */
    private $level_ref;
    /** @var string */
    private $source;

    public function __construct(string $status, bool $payment_verified, ?DateTimeImmutable $expires_at_utc, ?string $level_ref, string $source) {
        if (!in_array($status, self::statuses(), true)) {
            throw new InvalidArgumentException('Unknown membership status.');
        }
        if (!preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $source)) {
            throw new InvalidArgumentException('Invalid membership source.');
        }
        if ($level_ref !== null && !preg_match('/^[A-Za-z0-9_.:-]{1,64}$/D', $level_ref)) {
            throw new InvalidArgumentException('Invalid membership level reference.');
        }
        $this->status = $status;
        $this->payment_verified = $payment_verified;
        $this->expires_at_utc = $expires_at_utc === null ? null : EVC_Clock::to_utc($expires_at_utc);
        $this->level_ref = $level_ref;
        $this->source = $source;
    }

    public static function none(string $source): self {
        return new self(self::STATUS_NONE, false, null, null, $source);
    }

    /** @return string[] */
    public static function statuses(): array {
        return array(
            self::STATUS_ACTIVE,
            self::STATUS_EXPIRED,
            self::STATUS_CANCELLED,
            self::STATUS_PENDING_PAYMENT,
            self::STATUS_PAYMENT_FAILED,
            self::STATUS_REFUNDED,
            self::STATUS_NONE,
        );
    }

    /** Null when eligible at $at; otherwise a machine-readable denial reason. */
    public function denial_reason(DateTimeImmutable $at): ?string {
        if ($this->status !== self::STATUS_ACTIVE) {
            return $this->status;
        }
        if (!$this->payment_verified) {
            return self::DENIAL_PAYMENT_UNVERIFIED;
        }
        if ($this->expires_at_utc === null) {
            return self::DENIAL_EXPIRY_UNKNOWN;
        }
        if ($at >= $this->expires_at_utc) {
            return self::DENIAL_EXPIRED;
        }
        return null;
    }

    public function is_eligible_at(DateTimeImmutable $at): bool {
        return $this->denial_reason($at) === null;
    }

    /** Collapses to the v1 API buckets: active | expired | cancelled | inactive. */
    public function public_status(DateTimeImmutable $at): string {
        $reason = $this->denial_reason($at);
        if ($reason === null) {
            return 'active';
        }
        if ($reason === self::DENIAL_EXPIRED || $this->status === self::STATUS_EXPIRED) {
            return 'expired';
        }
        if ($this->status === self::STATUS_CANCELLED) {
            return 'cancelled';
        }
        return 'inactive';
    }

    public function status(): string {
        return $this->status;
    }

    public function payment_verified(): bool {
        return $this->payment_verified;
    }

    public function expires_at_utc(): ?DateTimeImmutable {
        return $this->expires_at_utc;
    }

    public function level_ref(): ?string {
        return $this->level_ref;
    }

    public function source(): string {
        return $this->source;
    }
}
