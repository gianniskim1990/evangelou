<?php
defined('ABSPATH') || (defined('EVC_STANDALONE_TEST') && EVC_STANDALONE_TEST) || exit;

/**
 * Outcome of one redemption attempt. Success outcomes carry the redemption
 * record; failures carry only safe, code-specific details (never another
 * member's data, SQL text or exception messages).
 */
final class EVC_Redemption_Result {
    const REDEEMED = 'redeemed';
    const REPLAYED = 'replayed';
    const INVALID_REQUEST = 'invalid_request';
    const INVALID_COFFEE = 'invalid_coffee';
    const MEMBER_NOT_FOUND = 'member_not_found';
    const MEMBERSHIP_INACTIVE = 'membership_inactive';
    const ALREADY_REDEEMED = 'benefit_already_redeemed';
    const IDEMPOTENCY_CONFLICT = 'idempotency_key_reused';
    const SERVER_ERROR = 'server_error';

    /** @var string */
    private $outcome;
    /** @var array<string,string>|null */
    private $redemption;
    /** @var array<string,string> */
    private $details;

    private function __construct(string $outcome, ?array $redemption, array $details) {
        $this->outcome = $outcome;
        $this->redemption = $redemption;
        $this->details = $details;
    }

    /** @param array<string,string> $redemption */
    public static function success(string $outcome, array $redemption): self {
        if ($outcome !== self::REDEEMED && $outcome !== self::REPLAYED) {
            throw new InvalidArgumentException('Not a success outcome.');
        }
        return new self($outcome, $redemption, array());
    }

    /** @param array<string,string> $details */
    public static function failure(string $outcome, array $details = array()): self {
        if ($outcome === self::REDEEMED || $outcome === self::REPLAYED) {
            throw new InvalidArgumentException('Not a failure outcome.');
        }
        return new self($outcome, null, $details);
    }

    public function outcome(): string {
        return $this->outcome;
    }

    public function is_success(): bool {
        return $this->redemption !== null;
    }

    public function is_replay(): bool {
        return $this->outcome === self::REPLAYED;
    }

    /**
     * Keys: member_public_id, benefit_type, coffee_code, business_date
     * (Y-m-d, Europe/Athens), redeemed_at_utc (Y-m-d H:i:s.u), request_id.
     * @return array<string,string>|null
     */
    public function redemption(): ?array {
        return $this->redemption;
    }

    /** @return array<string,string> */
    public function details(): array {
        return $this->details;
    }
}
