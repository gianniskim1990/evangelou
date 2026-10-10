<?php
defined('ABSPATH') || (defined('EVC_STANDALONE_TEST') && EVC_STANDALONE_TEST) || exit;

/**
 * Result of EVC_Provenance_Recorder (Task 1D-E). A recording outcome is NOT
 * eligibility: even "newly_recorded" grants nothing by itself; only
 * EVC_Pmpro_Entitlement_Mapper over the whole authoritative state decides.
 *
 *  newly_recorded    the write was committed (movement + period, or correction)
 *  already_recorded  identical fact already present; nothing (or only new
 *                    verified aliases) written
 *  conflict          contradicts recorded facts; nothing written; needs review
 *  indeterminate     not recordable as given (pending, unanchored, out of
 *                    order, same instant, wrong amount/env/level); nothing written
 *  unavailable       the store failed; nothing written; safe to retry
 */
final class EVC_Record_Outcome {
    const NEWLY_RECORDED = 'newly_recorded';
    const ALREADY_RECORDED = 'already_recorded';
    const CONFLICT = 'conflict';
    const INDETERMINATE = 'indeterminate';
    const UNAVAILABLE = 'unavailable';

    /** @var string */
    private $outcome;
    /** @var string|null */
    private $reason;
    /** @var EVC_Funded_Period|null */
    private $period;

    private function __construct(string $outcome, ?string $reason, ?EVC_Funded_Period $period) {
        $this->outcome = $outcome;
        $this->reason = $reason;
        $this->period = $period;
    }

    public static function recorded(?EVC_Funded_Period $period): self {
        return new self(self::NEWLY_RECORDED, null, $period);
    }

    public static function already(?EVC_Funded_Period $period): self {
        return new self(self::ALREADY_RECORDED, null, $period);
    }

    public static function conflict(string $reason): self {
        return new self(self::CONFLICT, $reason, null);
    }

    public static function indeterminate(string $reason): self {
        return new self(self::INDETERMINATE, $reason, null);
    }

    public static function unavailable(): self {
        return new self(self::UNAVAILABLE, 'store_unavailable', null);
    }

    public function outcome(): string {
        return $this->outcome;
    }

    /** Internal diagnostic code; never shown to staff or members. */
    public function reason(): ?string {
        return $this->reason;
    }

    public function period(): ?EVC_Funded_Period {
        return $this->period;
    }
}
