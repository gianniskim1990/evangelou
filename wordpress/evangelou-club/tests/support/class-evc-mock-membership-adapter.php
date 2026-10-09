<?php
/**
 * TEST-ONLY membership adapter. Lives under tests/ so it can never be loaded
 * by the production plugin. Unknown users have no membership (fail closed).
 */
final class EVC_Mock_Membership_Adapter implements EVC_Membership_Adapter {
    const SOURCE = 'mock';

    /** @var array<int,EVC_Entitlement|Throwable> */
    private $by_user = array();
    /** @var array<int,array{wp_user_id:int,at:string}> */
    public $calls = array();

    public function set(int $wp_user_id, $entitlement_or_error): self {
        $this->by_user[$wp_user_id] = $entitlement_or_error;
        return $this;
    }

    public function entitlement_for(int $wp_user_id, DateTimeImmutable $at_utc): EVC_Entitlement {
        $this->calls[] = array('wp_user_id' => $wp_user_id, 'at' => $at_utc->format('Y-m-d H:i:s.u'));
        if (!isset($this->by_user[$wp_user_id])) {
            return EVC_Entitlement::none(self::SOURCE);
        }
        $value = $this->by_user[$wp_user_id];
        if ($value instanceof Throwable) {
            throw $value;
        }
        return $value;
    }

    public static function active_until(string $expires_at, string $level_ref = 'club_monthly'): EVC_Entitlement {
        return new EVC_Entitlement(EVC_Entitlement::STATUS_ACTIVE, true, new DateTimeImmutable($expires_at), $level_ref, self::SOURCE);
    }

    public static function with(string $status, bool $payment_verified, ?string $expires_at): EVC_Entitlement {
        return new EVC_Entitlement(
            $status,
            $payment_verified,
            $expires_at === null ? null : new DateTimeImmutable($expires_at),
            'club_monthly',
            self::SOURCE
        );
    }
}
