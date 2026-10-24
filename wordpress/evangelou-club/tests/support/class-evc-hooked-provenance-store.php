<?php
/**
 * TEST-ONLY EVC_Provenance_Store decorator (STAGE-03B.3a) for fault and race
 * injection around a REAL store: run a callback before the N-th call of a
 * read method or before commit, or make commit fail before / report an
 * unknown outcome after (or without) really committing. Never shipped.
 */
final class EVC_Hooked_Provenance_Store implements EVC_Provenance_Store {
    const COMMIT_NORMAL = 'normal';
    /** throw before the inner commit: definitely not stored */
    const COMMIT_FAIL_BEFORE = 'fail_before';
    /** commit for real, then report an unknown outcome (e.g. the COMMIT reply was lost) */
    const COMMIT_UNKNOWN_AFTER = 'unknown_after';
    /** do not commit, but report an unknown outcome */
    const COMMIT_UNKNOWN_WITHOUT = 'unknown_without';

    /** @var EVC_Provenance_Store */
    private $inner;
    /** @var string */
    public $commit_mode = self::COMMIT_NORMAL;
    /** @var callable|null runs once, right before the next commit (inner store passed) */
    public $before_commit;
    /** @var array<string,array{at:int,hook:callable}> method => run hook before its at-th call */
    private $read_hooks = array();
    /** @var array<string,int> */
    public $calls = array();
    /** @var bool reads throw (database gone) */
    public $reads_fail = false;

    public function __construct(EVC_Provenance_Store $inner) {
        $this->inner = $inner;
    }

    public function before_call(string $method, int $at, callable $hook): void {
        $this->read_hooks[$method] = array('at' => $at, 'hook' => $hook);
    }

    private function enter(string $method): void {
        $this->calls[$method] = ($this->calls[$method] ?? 0) + 1;
        if (isset($this->read_hooks[$method]) && $this->read_hooks[$method]['at'] === $this->calls[$method]) {
            $hook = $this->read_hooks[$method]['hook'];
            unset($this->read_hooks[$method]);
            $hook($this->inner);
        }
        if ($this->reads_fail) {
            throw new EVC_Provenance_Store_Exception('simulated: Club database unreachable');
        }
    }

    public function movement(string $movement_id): ?EVC_Verified_Movement {
        $this->enter(__FUNCTION__);
        return $this->inner->movement($movement_id);
    }

    public function movement_for_alias(string $alias_key): ?string {
        $this->enter(__FUNCTION__);
        return $this->inner->movement_for_alias($alias_key);
    }

    public function member_movements(int $wp_user_id): array {
        $this->enter(__FUNCTION__);
        return $this->inner->member_movements($wp_user_id);
    }

    public function latest_period(int $wp_user_id): ?EVC_Funded_Period {
        $this->enter(__FUNCTION__);
        return $this->inner->latest_period($wp_user_id);
    }

    public function period_for_movement(string $movement_id): ?EVC_Funded_Period {
        $this->enter(__FUNCTION__);
        return $this->inner->period_for_movement($movement_id);
    }

    public function correction(string $correction_id): ?EVC_Movement_Correction {
        $this->enter(__FUNCTION__);
        return $this->inner->correction($correction_id);
    }

    public function corrections_for(string $movement_id): array {
        $this->enter(__FUNCTION__);
        return $this->inner->corrections_for($movement_id);
    }

    public function unresolved_corrections(): array {
        $this->enter(__FUNCTION__);
        return $this->inner->unresolved_corrections();
    }

    public function commit(EVC_Provenance_Write $write): void {
        $this->calls['commit'] = ($this->calls['commit'] ?? 0) + 1;
        if ($this->before_commit !== null) {
            $hook = $this->before_commit;
            $this->before_commit = null;
            $hook($this->inner);
        }
        switch ($this->commit_mode) {
            case self::COMMIT_FAIL_BEFORE:
                throw new EVC_Provenance_Store_Exception('simulated: commit failed, nothing written');
            case self::COMMIT_UNKNOWN_WITHOUT:
                throw new EVC_Provenance_Commit_Unknown_Exception('simulated: commit outcome unknown (not written)');
            case self::COMMIT_UNKNOWN_AFTER:
                $this->inner->commit($write);
                throw new EVC_Provenance_Commit_Unknown_Exception('simulated: commit outcome unknown (written)');
            default:
                $this->inner->commit($write);
        }
    }
}
