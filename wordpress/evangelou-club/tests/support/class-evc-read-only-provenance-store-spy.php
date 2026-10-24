<?php
/**
 * TEST-ONLY EVC_Provenance_Store decorator (STAGE-03B.1): forwards every
 * read to the wrapped store, logs it, and FAILS THE TEST on any write. Used
 * to prove EVC_Provenance_Recorder::plan() never commits anything. Never
 * shipped.
 */
final class EVC_Read_Only_Provenance_Store_Spy implements EVC_Provenance_Store {
    /** @var EVC_Provenance_Store */
    private $inner;
    /** @var string[] method names, in call order */
    public $reads = array();
    public $write_attempts = 0;

    public function __construct(EVC_Provenance_Store $inner) {
        $this->inner = $inner;
    }

    public function movement(string $movement_id): ?EVC_Verified_Movement {
        $this->reads[] = __FUNCTION__;
        return $this->inner->movement($movement_id);
    }

    public function movement_for_alias(string $alias_key): ?string {
        $this->reads[] = __FUNCTION__;
        return $this->inner->movement_for_alias($alias_key);
    }

    public function member_movements(int $wp_user_id): array {
        $this->reads[] = __FUNCTION__;
        return $this->inner->member_movements($wp_user_id);
    }

    public function latest_period(int $wp_user_id): ?EVC_Funded_Period {
        $this->reads[] = __FUNCTION__;
        return $this->inner->latest_period($wp_user_id);
    }

    public function period_for_movement(string $movement_id): ?EVC_Funded_Period {
        $this->reads[] = __FUNCTION__;
        return $this->inner->period_for_movement($movement_id);
    }

    public function correction(string $correction_id): ?EVC_Movement_Correction {
        $this->reads[] = __FUNCTION__;
        return $this->inner->correction($correction_id);
    }

    public function corrections_for(string $movement_id): array {
        $this->reads[] = __FUNCTION__;
        return $this->inner->corrections_for($movement_id);
    }

    public function unresolved_corrections(): array {
        $this->reads[] = __FUNCTION__;
        return $this->inner->unresolved_corrections();
    }

    public function commit(EVC_Provenance_Write $write): void {
        $this->write_attempts++;
        throw new LogicException('A read-only path attempted to write to the provenance store.');
    }
}
