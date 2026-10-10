<?php
defined('ABSPATH') || (defined('EVC_STANDALONE_TEST') && EVC_STANDALONE_TEST) || exit;

/**
 * Persistence boundary of the payment-provenance recorder (Task 1D-E).
 * Only an IN-MEMORY test implementation exists (tests/support). No SQL
 * store, table or migration exists yet.
 *
 * Invariants every implementation MUST enforce INSIDE commit(), not only
 * trust the recorder to have checked them (a future SQL store needs unique
 * constraints, a transaction and a per-member row lock; in-memory PHP proves
 * none of that):
 *  I1  a movement id is recorded at most once;
 *  I2  an alias key maps to at most one movement;
 *  I3  a movement funds at most one period, and a period belongs to exactly
 *      that movement's member and level;
 *  I4  a member's periods form ONE chain: a new period's predecessor must be
 *      the member's current latest period (compare-and-set), and no period
 *      has two successors;
 *  I5  a correction id is recorded at most once and only for an existing
 *      movement; history is append-only (nothing is updated or deleted);
 *  I6  commit() is atomic: all of the write or none of it.
 * A violated precondition throws EVC_Provenance_Conflict_Exception; any
 * other failure throws EVC_Provenance_Store_Exception. Both leave the store
 * unchanged.
 */
interface EVC_Provenance_Store {
    public function movement(string $movement_id): ?EVC_Verified_Movement;

    /** Movement id the alias key belongs to, or null. */
    public function movement_for_alias(string $alias_key): ?string;

    /** @return EVC_Verified_Movement[] all recorded movements of the member */
    public function member_movements(int $wp_user_id): array;

    public function latest_period(int $wp_user_id): ?EVC_Funded_Period;

    public function period_for_movement(string $movement_id): ?EVC_Funded_Period;

    public function correction(string $correction_id): ?EVC_Movement_Correction;

    /** @return EVC_Movement_Correction[] oldest first */
    public function corrections_for(string $movement_id): array;

    /** @throws EVC_Provenance_Conflict_Exception|EVC_Provenance_Store_Exception */
    public function commit(EVC_Provenance_Write $write): void;
}

/** The store is unavailable or failed; nothing was written. */
class EVC_Provenance_Store_Exception extends RuntimeException {
}

/** A store invariant / precondition was violated (e.g. a concurrent writer); nothing was written. */
final class EVC_Provenance_Conflict_Exception extends EVC_Provenance_Store_Exception {
}
