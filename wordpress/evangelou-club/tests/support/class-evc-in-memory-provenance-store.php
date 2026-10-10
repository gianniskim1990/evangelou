<?php
/**
 * TEST-ONLY in-memory EVC_Provenance_Store (Task 1D-E). Never shipped (the
 * packager rejects this class). It enforces invariants I1-I6 of the store
 * contract inside commit() by applying the write to a COPY of the state and
 * swapping it in only at the very end, so a failure at any point leaves the
 * store unchanged. Single-process PHP: this says nothing about database
 * concurrency; a future SQL store needs a transaction, a per-member row lock
 * and unique constraints for the same invariants.
 */
final class EVC_In_Memory_Provenance_Store implements EVC_Provenance_Store {
    /** @var array{movements:array<string,EVC_Verified_Movement>,aliases:array<string,string>,periods:array<string,EVC_Funded_Period>,latest:array<int,string>,successor:array<string,string>,corrections:array<string,EVC_Movement_Correction>,history:array<string,string[]>,members:array<int,string[]>} */
    private $state = array(
        'movements' => array(),
        'aliases' => array(),
        'periods' => array(),
        'latest' => array(),
        'successor' => array(),
        'corrections' => array(),
        'history' => array(),
        'members' => array(),
    );

    /** Test hooks */
    public $unavailable = false;
    public $fail_next_commit_after_apply = false;
    /** @var callable|null runs at the start of the next commit (simulates a concurrent writer) */
    public $before_next_commit;
    public $commits = 0;

    public function movement(string $movement_id): ?EVC_Verified_Movement {
        $this->guard();
        return $this->state['movements'][$movement_id] ?? null;
    }

    public function movement_for_alias(string $alias_key): ?string {
        $this->guard();
        return $this->state['aliases'][$alias_key] ?? null;
    }

    public function member_movements(int $wp_user_id): array {
        $this->guard();
        $out = array();
        foreach ($this->state['members'][$wp_user_id] ?? array() as $id) {
            $out[] = $this->state['movements'][$id];
        }
        return $out;
    }

    public function latest_period(int $wp_user_id): ?EVC_Funded_Period {
        $this->guard();
        $id = $this->state['latest'][$wp_user_id] ?? null;
        return $id === null ? null : $this->state['periods'][$id];
    }

    public function period_for_movement(string $movement_id): ?EVC_Funded_Period {
        $this->guard();
        return $this->state['periods'][$movement_id] ?? null;
    }

    public function correction(string $correction_id): ?EVC_Movement_Correction {
        $this->guard();
        return $this->state['corrections'][$correction_id] ?? null;
    }

    public function corrections_for(string $movement_id): array {
        $this->guard();
        $out = array();
        foreach ($this->state['history'][$movement_id] ?? array() as $id) {
            $out[] = $this->state['corrections'][$id];
        }
        return $out;
    }

    public function commit(EVC_Provenance_Write $w): void {
        if ($this->before_next_commit !== null) {
            $hook = $this->before_next_commit;
            $this->before_next_commit = null;
            $hook($this);
        }
        $this->guard();
        $next = $this->state; // arrays copy by value: the live state is untouched until the swap

        if ($w->new_movement !== null) {
            $m = $w->new_movement;
            $p = $w->new_period;
            $id = $m->movement_id();
            if (isset($next['movements'][$id])) {
                throw new EVC_Provenance_Conflict_Exception('I1 movement already recorded');
            }
            if ($p === null || $p->movement_id() !== $id || $p->wp_user_id() !== $m->wp_user_id() || $p->level_id() !== $m->level_id()) {
                throw new EVC_Provenance_Conflict_Exception('I3 period does not belong to the movement');
            }
            $current_latest = $next['latest'][$m->wp_user_id()] ?? null;
            if ($current_latest !== $w->expected_predecessor || $p->predecessor_movement_id() !== $current_latest) {
                throw new EVC_Provenance_Conflict_Exception('I4 member chain changed (compare-and-set failed)');
            }
            if ($current_latest !== null && isset($next['successor'][$current_latest])) {
                throw new EVC_Provenance_Conflict_Exception('I4 predecessor already has a successor');
            }
            $next['movements'][$id] = $m;
            $next['periods'][$id] = $p;
            $next['members'][$m->wp_user_id()][] = $id;
            if ($current_latest !== null) {
                $next['successor'][$current_latest] = $id;
            }
            $next['latest'][$m->wp_user_id()] = $id;
        }

        if ($w->new_aliases !== array()) {
            if ($w->alias_owner === null || !isset($next['movements'][$w->alias_owner])) {
                throw new EVC_Provenance_Conflict_Exception('I2 aliases for an unknown movement');
            }
            foreach ($w->new_aliases as $alias) {
                $owner = $next['aliases'][$alias->key()] ?? null;
                if ($owner !== null && $owner !== $w->alias_owner) {
                    throw new EVC_Provenance_Conflict_Exception('I2 alias belongs to another movement');
                }
                $next['aliases'][$alias->key()] = $w->alias_owner;
            }
        }

        if ($w->correction !== null) {
            $c = $w->correction;
            if (isset($next['corrections'][$c->correction_id()])) {
                throw new EVC_Provenance_Conflict_Exception('I5 correction already recorded');
            }
            if (!isset($next['movements'][$c->movement_id()])) {
                throw new EVC_Provenance_Conflict_Exception('I5 correction for an unknown movement');
            }
            $next['corrections'][$c->correction_id()] = $c;
            $next['history'][$c->movement_id()][] = $c->correction_id();
        }

        if ($this->fail_next_commit_after_apply) {
            // Simulates a crash after the write was staged but before commit (I6).
            $this->fail_next_commit_after_apply = false;
            throw new EVC_Provenance_Store_Exception('simulated failure before commit');
        }
        $this->state = $next;
        $this->commits++;
    }

    /** Counts for "nothing was written" assertions. */
    public function counts(): array {
        return array(
            'movements' => count($this->state['movements']),
            'aliases' => count($this->state['aliases']),
            'periods' => count($this->state['periods']),
            'corrections' => count($this->state['corrections']),
        );
    }

    private function guard(): void {
        if ($this->unavailable) {
            throw new EVC_Provenance_Store_Exception('store unavailable');
        }
    }
}

/**
 * TEST-ONLY bridge from recorded provenance to the existing mapper's
 * normalised facts (Task 1D-E, scenario W). Manufactures nothing: a movement
 * without a period yields a fact without provenance, which the mapper rejects.
 */
final class EVC_Provenance_Fact_Builder {
    /** Correction kind -> mapper payment status. A void is presented as a reversal (never as paid). */
    const STATUS_FOR_CORRECTION = array(
        EVC_Movement_Correction::KIND_REFUNDED => EVC_Pmpro_Payment_Fact::STATUS_REFUNDED,
        EVC_Movement_Correction::KIND_PARTIALLY_REFUNDED => EVC_Pmpro_Payment_Fact::STATUS_PARTIALLY_REFUNDED,
        EVC_Movement_Correction::KIND_REVERSED => EVC_Pmpro_Payment_Fact::STATUS_REVERSED,
        EVC_Movement_Correction::KIND_VOIDED => EVC_Pmpro_Payment_Fact::STATUS_REVERSED,
    );

    /** @return EVC_Pmpro_Payment_Fact[] */
    public static function facts(EVC_Provenance_Store $store, int $wp_user_id): array {
        $facts = array();
        foreach ($store->member_movements($wp_user_id) as $m) {
            $period = $store->period_for_movement($m->movement_id());
            $history = $store->corrections_for($m->movement_id());
            $status = $history === array()
                ? EVC_Pmpro_Payment_Fact::STATUS_CONFIRMED
                : self::STATUS_FOR_CORRECTION[$history[count($history) - 1]->kind()];
            $facts[] = new EVC_Pmpro_Payment_Fact(
                $m->movement_id(),
                $m->movement_id(),
                $m->evidence_kind(),
                $m->level_id(),
                $period === null ? null : $period->pmpro_row_ref(),
                $status,
                $m->environment(),
                $m->verified_at_utc(),
                $period === null ? null : $period->start_utc(),
                $period === null ? null : $period->end_utc()
            );
        }
        return $facts;
    }

    /** @param EVC_Pmpro_Membership_Row[] $rows synthetic PMPro rows */
    public static function snapshot(EVC_Provenance_Store $store, int $wp_user_id, array $rows): EVC_Pmpro_Snapshot {
        return EVC_Pmpro_Fixture::snapshot($rows, self::facts($store, $wp_user_id));
    }
}
