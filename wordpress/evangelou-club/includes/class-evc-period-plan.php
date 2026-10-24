<?php
defined('ABSPATH') || (defined('EVC_STANDALONE_TEST') && EVC_STANDALONE_TEST) || exit;

/**
 * STAGE-03B.1: ADVISORY result of EVC_Provenance_Recorder::plan() — the paid
 * period a prospective payment WOULD fund if it were recorded now.
 *
 * A plan is not a payment record, not a confirmation and not eligibility.
 * It has no PMPro row, is never stored by this code and may already be STALE
 * when read: another payment, correction or alias may be recorded at any
 * moment. record() never accepts a plan; it re-reads the store and re-applies
 * every rule, and the store re-checks its invariants at commit time
 * (EVC_Provenance_Store I1-I8). A caller that acts on a plan (e.g. sets
 * PMPro dates) must compare the period record() actually returns
 * (matches_recorded()) and treat any difference as a conflict.
 *
 *  planned           start/end/kind/predecessor of the prospective period
 *  already_recorded  this movement is ALREADY recorded with the same facts;
 *                    recorded_period() is that period (bound to its row)
 *  conflict          contradicts recorded facts (another order, other facts,
 *                    alias owned elsewhere)
 *  indeterminate     not plannable as given (wrong env/level/amount, no
 *                    anchor, same instant, out of order, unresolved
 *                    correction)
 *  unavailable       the store failed or returned inconsistent data
 */
final class EVC_Period_Plan {
    const PLANNED = 'planned';
    const ALREADY_RECORDED = 'already_recorded';
    const CONFLICT = 'conflict';
    const INDETERMINATE = 'indeterminate';
    const UNAVAILABLE = 'unavailable';

    /** @var string */
    private $outcome;
    /** @var string|null */
    private $reason;
    /** @var string|null */
    private $movement_id;
    /** @var DateTimeImmutable|null */
    private $start_utc;
    /** @var DateTimeImmutable|null */
    private $end_utc;
    /** @var string|null */
    private $kind;
    /** @var string|null */
    private $predecessor_movement_id;
    /** @var EVC_Funded_Period|null */
    private $recorded_period;

    private function __construct(string $outcome, ?string $reason) {
        $this->outcome = $outcome;
        $this->reason = $reason;
    }

    public static function planned(string $movement_id, DateTimeImmutable $start_utc, DateTimeImmutable $end_utc, string $kind, ?string $predecessor_movement_id): self {
        if ($end_utc <= $start_utc) {
            throw new InvalidArgumentException('A planned period must end after it starts.');
        }
        if (!in_array($kind, array(EVC_Funded_Period::KIND_INITIAL, EVC_Funded_Period::KIND_EARLY, EVC_Funded_Period::KIND_LATE), true)) {
            throw new InvalidArgumentException('Unknown period kind.');
        }
        if (($kind === EVC_Funded_Period::KIND_INITIAL) !== ($predecessor_movement_id === null)) {
            throw new InvalidArgumentException('Only the initial period has no predecessor.');
        }
        $plan = new self(self::PLANNED, null);
        $plan->movement_id = $movement_id;
        $plan->start_utc = $start_utc;
        $plan->end_utc = $end_utc;
        $plan->kind = $kind;
        $plan->predecessor_movement_id = $predecessor_movement_id;
        return $plan;
    }

    public static function already(EVC_Funded_Period $period): self {
        $plan = new self(self::ALREADY_RECORDED, null);
        $plan->movement_id = $period->movement_id();
        $plan->start_utc = $period->start_utc();
        $plan->end_utc = $period->end_utc();
        $plan->kind = $period->kind();
        $plan->predecessor_movement_id = $period->predecessor_movement_id();
        $plan->recorded_period = $period;
        return $plan;
    }

    /** A recorder refusal (conflict / indeterminate / unavailable) as a plan; nothing else. */
    public static function refused(EVC_Record_Outcome $refusal): self {
        $map = array(
            EVC_Record_Outcome::CONFLICT => self::CONFLICT,
            EVC_Record_Outcome::INDETERMINATE => self::INDETERMINATE,
            EVC_Record_Outcome::UNAVAILABLE => self::UNAVAILABLE,
        );
        if (!isset($map[$refusal->outcome()])) {
            throw new LogicException('Only a refusal can become a refused plan.');
        }
        return new self($map[$refusal->outcome()], $refusal->reason());
    }

    public static function unavailable(string $reason): self {
        return new self(self::UNAVAILABLE, $reason);
    }

    public function outcome(): string {
        return $this->outcome;
    }

    /** Internal diagnostic code; never shown to staff or members. */
    public function reason(): ?string {
        return $this->reason;
    }

    public function movement_id(): ?string {
        return $this->movement_id;
    }

    public function start_utc(): ?DateTimeImmutable {
        return $this->start_utc;
    }

    public function end_utc(): ?DateTimeImmutable {
        return $this->end_utc;
    }

    public function kind(): ?string {
        return $this->kind;
    }

    public function predecessor_movement_id(): ?string {
        return $this->predecessor_movement_id;
    }

    /** Only for already_recorded. */
    public function recorded_period(): ?EVC_Funded_Period {
        return $this->recorded_period;
    }

    /**
     * True only when this is a planned / already-recorded result whose
     * period is EXACTLY $recorded (movement, start, end, kind, predecessor).
     * Any difference means the plan went stale: the caller must stop.
     */
    public function matches_recorded(EVC_Funded_Period $recorded): bool {
        return ($this->outcome === self::PLANNED || $this->outcome === self::ALREADY_RECORDED)
            && $this->movement_id === $recorded->movement_id()
            && $this->start_utc == $recorded->start_utc()
            && $this->end_utc == $recorded->end_utc()
            && $this->kind === $recorded->kind()
            && $this->predecessor_movement_id === $recorded->predecessor_movement_id();
    }
}
