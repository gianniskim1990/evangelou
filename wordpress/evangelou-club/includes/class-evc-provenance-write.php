<?php
defined('ABSPATH') || (defined('EVC_STANDALONE_TEST') && EVC_STANDALONE_TEST) || exit;

/**
 * One atomic unit of work for EVC_Provenance_Store::commit() (Task 1D-E):
 *  - a new movement + its aliases + the period it funds, guarded by the
 *    expected current latest period of the member (compare-and-set), or
 *  - new verified aliases for an already recorded movement, or
 *  - one correction.
 */
final class EVC_Provenance_Write {
    /** @var EVC_Verified_Movement|null */
    public $new_movement;
    /** @var EVC_Funded_Period|null */
    public $new_period;
    /** @var string|null movement id of the latest period the period builds on (null = first) */
    public $expected_predecessor;
    /** @var string|null */
    public $alias_owner;
    /** @var EVC_Movement_Alias[] */
    public $new_aliases = array();
    /** @var EVC_Movement_Correction|null */
    public $correction;

    public static function new_movement(EVC_Verified_Movement $movement, EVC_Funded_Period $period): self {
        $w = new self();
        $w->new_movement = $movement;
        $w->new_period = $period;
        $w->expected_predecessor = $period->predecessor_movement_id();
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

    public static function correction(EVC_Movement_Correction $correction): self {
        $w = new self();
        $w->correction = $correction;
        return $w;
    }
}
