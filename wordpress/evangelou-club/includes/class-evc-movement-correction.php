<?php
defined('ABSPATH') || (defined('EVC_STANDALONE_TEST') && EVC_STANDALONE_TEST) || exit;

/**
 * A verified, append-only change to a recorded money movement (Task 1D-E):
 * a refund, partial refund, reversal/charge-back, or an administrative void
 * of an incorrectly confirmed manual payment. It never deletes the movement
 * or its period; it only adds history. What a refund MEANS for eligibility
 * stays with EVC_Pmpro_Entitlement_Mapper (refunded -> ineligible; partial /
 * reversed / voided -> indeterminate). Owner decisions D3/D4/D10 stay open.
 */
final class EVC_Movement_Correction {
    const KIND_REFUNDED = 'refunded';
    const KIND_PARTIALLY_REFUNDED = 'partially_refunded';
    const KIND_REVERSED = 'reversed';
    const KIND_VOIDED = 'voided';

    /** @var string */
    private $correction_id;
    /** @var string */
    private $movement_id;
    /** @var string */
    private $kind;
    /** @var string */
    private $authority;
    /** @var DateTimeImmutable */
    private $effective_at_utc;

    public function __construct(string $correction_id, string $movement_id, string $kind, string $authority, DateTimeImmutable $effective_at_utc) {
        if (!preg_match(EVC_Verified_Movement::ID_PATTERN, $correction_id) || !preg_match(EVC_Verified_Movement::ID_PATTERN, $movement_id)) {
            throw new InvalidArgumentException('Correction and movement ids must be opaque 64-hex values.');
        }
        if (!in_array($kind, self::kinds(), true)) {
            throw new InvalidArgumentException('Unknown correction kind.');
        }
        if (!in_array($authority, array(EVC_Verified_Movement::AUTHORITY_GATEWAY_API, EVC_Verified_Movement::AUTHORITY_ORDER_STATE, EVC_Verified_Movement::AUTHORITY_MANUAL_ADMIN), true)) {
            throw new InvalidArgumentException('Unknown correction authority.');
        }
        if ($effective_at_utc->getTimezone()->getName() !== 'UTC') {
            throw new InvalidArgumentException('Correction instant must be expressed in UTC.');
        }
        $this->correction_id = $correction_id;
        $this->movement_id = $movement_id;
        $this->kind = $kind;
        $this->authority = $authority;
        $this->effective_at_utc = $effective_at_utc;
    }

    /** @return string[] */
    public static function kinds(): array {
        return array(self::KIND_REFUNDED, self::KIND_PARTIALLY_REFUNDED, self::KIND_REVERSED, self::KIND_VOIDED);
    }

    /**
     * Forward-only state machine: a partial refund may still become a full
     * refund, reversal or void; every other correction is final.
     */
    public static function allowed_after(?string $current_kind, string $next_kind): bool {
        if ($current_kind === null) {
            return true;
        }
        return $current_kind === self::KIND_PARTIALLY_REFUNDED && $next_kind !== self::KIND_PARTIALLY_REFUNDED;
    }

    public function correction_id(): string {
        return $this->correction_id;
    }

    public function movement_id(): string {
        return $this->movement_id;
    }

    public function kind(): string {
        return $this->kind;
    }

    public function authority(): string {
        return $this->authority;
    }

    public function effective_at_utc(): DateTimeImmutable {
        return $this->effective_at_utc;
    }

    public function same_as(EVC_Movement_Correction $other): bool {
        return $this->correction_id === $other->correction_id
            && $this->movement_id === $other->movement_id
            && $this->kind === $other->kind
            && $this->authority === $other->authority
            && $this->effective_at_utc == $other->effective_at_utc;
    }
}
