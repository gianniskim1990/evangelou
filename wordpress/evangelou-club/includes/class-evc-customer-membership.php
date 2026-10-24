<?php
defined('ABSPATH') || (defined('EVC_STANDALONE_TEST') && EVC_STANDALONE_TEST) || exit;

/**
 * What a signed-in CUSTOMER may be told about their own membership
 * (STAGE-01, contract "customer-me-v1"). Pure: an authoritative
 * EVC_Entitlement (from the membership adapter, i.e. the PMPro reader +
 * EVC_Pmpro_Entitlement_Mapper) and an instant -> a public state.
 *
 *   active           eligible now: active status, VERIFIED payment, known
 *                    expiry, instant < expiry (EVC_Entitlement::denial_reason)
 *   expired          the paid period ended (paid_until_utc when known)
 *   cancelled        cancelled membership
 *   pending_payment  a purchase whose payment is not confirmed
 *   none             no Club membership at all
 *   unverified       anything that cannot be confirmed: indeterminate facts,
 *                    unverified payment, unknown expiry, failed or refunded
 *                    payment. Never presented as paid or as active.
 *
 * paid_until_utc is given ONLY for active and expired, and only when the
 * entitlement carries an expiry. Diagnostic flags, level references and
 * payment evidence are internal and never part of this output.
 */
final class EVC_Customer_Membership {
    const CONTRACT = 'customer-me-v1';

    const ACTIVE = 'active';
    const EXPIRED = 'expired';
    const CANCELLED = 'cancelled';
    const PENDING_PAYMENT = 'pending_payment';
    const NONE = 'none';
    const UNVERIFIED = 'unverified';

    /** @return string[] */
    public static function states(): array {
        return array(self::ACTIVE, self::EXPIRED, self::CANCELLED, self::PENDING_PAYMENT, self::NONE, self::UNVERIFIED);
    }

    /** @return array{state:string,paid_until_utc:?string} */
    public static function describe(EVC_Entitlement $entitlement, DateTimeImmutable $at): array {
        $at = EVC_Clock::to_utc($at);
        $reason = $entitlement->denial_reason($at);
        $until = $entitlement->expires_at_utc();
        if ($reason === null) {
            return array('state' => self::ACTIVE, 'paid_until_utc' => self::instant($until));
        }
        if ($reason === EVC_Entitlement::DENIAL_EXPIRED || $entitlement->status() === EVC_Entitlement::STATUS_EXPIRED) {
            return array('state' => self::EXPIRED, 'paid_until_utc' => self::instant($until));
        }
        switch ($entitlement->status()) {
            case EVC_Entitlement::STATUS_CANCELLED:
                return array('state' => self::CANCELLED, 'paid_until_utc' => null);
            case EVC_Entitlement::STATUS_PENDING_PAYMENT:
                return array('state' => self::PENDING_PAYMENT, 'paid_until_utc' => null);
            case EVC_Entitlement::STATUS_NONE:
                return array('state' => self::NONE, 'paid_until_utc' => null);
            default:
                // indeterminate, payment_failed, refunded, or "active" without a
                // verified payment / known expiry: never shown as paid.
                return array('state' => self::UNVERIFIED, 'paid_until_utc' => null);
        }
    }

    /** RFC 3339 UTC instant with an explicit offset, or null. */
    private static function instant(?DateTimeImmutable $at): ?string {
        return $at === null ? null : EVC_Clock::to_utc($at)->format('Y-m-d\TH:i:sP');
    }
}
