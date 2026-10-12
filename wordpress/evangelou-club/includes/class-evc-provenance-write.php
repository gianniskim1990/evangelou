<?php
defined('ABSPATH') || (defined('EVC_STANDALONE_TEST') && EVC_STANDALONE_TEST) || exit;

/**
 * One atomic unit of work for EVC_Provenance_Store::commit() (Task 1D-E):
 *  - a new movement + its aliases + the period it funds, guarded by the
 *    expected current latest period of the member (compare-and-set) AND by
 *    the exact list of unresolved corrections already held for that movement
 *    id (empty for an ordinary recording; the acknowledged list for a trusted
 *    reconciliation, Task 1D-E.R1), or
 *  - new verified aliases for an already recorded movement, or
 *  - one correction, guarded by the correction it follows (compare-and-set
 *    on the movement's correction history) and by whether the movement was
 *    already recorded when the writer looked (Task 1D-E.R1).
 */
final class EVC_Provenance_Write {
    /** @var EVC_Verified_Movement|null */
    public $new_movement;
    /** @var EVC_Funded_Period|null */
    public $new_period;
    /** @var string|null movement id of the latest period the period builds on (null = first) */
    public $expected_predecessor;
    /** @var string[] correction ids already held for the new movement, oldest first (must match exactly) */
    public $expected_unresolved = array();
    /** @var string|null */
    public $alias_owner;
    /** @var EVC_Movement_Alias[] */
    public $new_aliases = array();
    /** @var EVC_Movement_Correction|null */
    public $correction;
    /** @var string|null id of the movement's latest correction the new one follows (null = first) */
    public $expected_prior_correction;
    /** @var bool whether the corrected movement was recorded when the writer read the store */
    public $expected_movement_recorded = false;

    /** @param string[] $expected_unresolved */
    public static function new_movement(EVC_Verified_Movement $movement, EVC_Funded_Period $period, array $expected_unresolved = array()): self {
        $w = new self();
        $w->new_movement = $movement;
        $w->new_period = $period;
        $w->expected_predecessor = $period->predecessor_movement_id();
        $w->expected_unresolved = array_values($expected_unresolved);
        $w->alias_owner = $movement->movement_id();
        $w->new_aliases = array_values($movement->aliases());
        return $w;
    }

    /** @param EVC_Movement_Alias[] $aliases */
    public static function aliases_for(string $movement_id, array $aliases): self {
        $w = new self();
        $w->alias_owner = $movement_id;
        $w->new_aliases = array_values($aliases);
        return $w;
    }

    public static function correction(EVC_Movement_Correction $correction, ?string $expected_prior_correction, bool $movement_recorded): self {
        $w = new self();
        $w->correction = $correction;
        $w->expected_prior_correction = $expected_prior_correction;
        $w->expected_movement_recorded = $movement_recorded;
        return $w;
    }
}
