<?php
defined('ABSPATH') || (defined('EVC_STANDALONE_TEST') && EVC_STANDALONE_TEST) || exit;

/**
 * Persistence boundary of the payment-provenance recorder (Task 1D-E).
 * Only an IN-MEMORY test implementation exists (tests/support). No SQL
 * store, table or migration exists yet.
 *
 * Invariants every implementation MUST enforce INSIDE commit(), not only
 * trust the recorder to have checked them (in-memory PHP proves none of the
 * database concurrency; see the SQL notes below):
 *  I1  a movement id is recorded at most once;
 *  I2  an alias key maps to at most one movement;
 *  I3  a movement funds at most one period, and a period belongs to exactly
 *      that movement's member and level;
 *  I4  a member's periods form ONE chain: a new period's predecessor must be
 *      the member's current latest period (compare-and-set), and no period
 *      has two successors;
 *  I5  a correction id is recorded at most once; the correction history of
 *      a movement id is append-only (nothing is updated, reordered or
 *      deleted). History may exist for a movement id that is NOT recorded:
 *      those corrections are UNRESOLVED (Task 1D-E.R1, refund-first);
 *  I6  commit() is atomic: all of the write or none of it;
 *  I7  (R1) a movement id is recorded only if the write's
 *      expected_unresolved equals EXACTLY (same ids, same order) the
 *      corrections already held for it. An ordinary recording expects none,
 *      so a payment can never be recorded past a refund that arrived first;
 *      a trusted reconciliation acknowledges them and they become the
 *      movement's history in the same commit (never dropped);
 *  I8  (R1) a correction is appended only if, at commit time: the
 *      movement's latest correction id equals expected_prior_correction
 *      (compare-and-set; null = none), allowed_after(latest kind, new kind)
 *      holds (forward-only), and whether the movement is recorded equals
 *      expected_movement_recorded. Otherwise nothing is written.
 * A violated precondition throws EVC_Provenance_Conflict_Exception; any
 * other failure throws EVC_Provenance_Store_Exception. Both leave the store
 * unchanged.
 *
 * SQL store requirements (NOT implemented; Task 1D-E.R1 contract):
 *  - one InnoDB transaction per commit(); any exception rolls back;
 *  - a per-movement-id correction HEAD row (movement_id PK, latest
 *    correction id, latest kind, version), created with INSERT IGNORE and
 *    then read with SELECT ... FOR UPDATE before any correction or movement
 *    write for that id; the I8 compare-and-set and allowed_after() check run
 *    against that locked row, then the correction row is inserted and the
 *    head updated in the same transaction (no read-check-write outside it);
 *  - UNIQUE(correction_id) and UNIQUE(movement_id, sequence) on corrections;
 *    UNIQUE(movement_id) on movements and periods; UNIQUE(alias_key);
 *    UNIQUE(predecessor_movement_id) on periods;
 *  - recording a movement locks the member chain row FOR UPDATE, then the
 *    movement's correction head row (fixed lock order: member, then
 *    movement head; a correction locks only the movement head), and checks
 *    I4 and I7 against the locked rows;
 *  - unique-key violations and lock-wait/deadlock errors map to
 *    EVC_Provenance_Conflict_Exception, never to success.
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

    /** @return EVC_Movement_Correction[] oldest first (recorded or not) */
    public function corrections_for(string $movement_id): array;

    /**
     * Reconciliation obligations: corrections held for movement ids that are
     * not recorded, oldest first per movement.
     *
     * @return array<string,EVC_Movement_Correction[]> keyed by movement id
     */
    public function unresolved_corrections(): array;

    /** @throws EVC_Provenance_Conflict_Exception|EVC_Provenance_Store_Exception */
    public function commit(EVC_Provenance_Write $write): void;
}

/** The store is unavailable or failed; nothing was written. */
class EVC_Provenance_Store_Exception extends RuntimeException {
}

/** A store invariant / precondition was violated (e.g. a concurrent writer); nothing was written. */
final class EVC_Provenance_Conflict_Exception extends EVC_Provenance_Store_Exception {
}

/**
 * Task 1D-G: COMMIT failed and read-back recovery could not prove either
 * outcome (e.g. the original transaction may still hold its locks, or the
 * database is unreachable). The write MAY have committed. Never treated as
 * a rollback; the caller reports unavailable and retries later with the
 * same canonical ids, which the store resolves idempotently.
 */
final class EVC_Provenance_Commit_Unknown_Exception extends EVC_Provenance_Store_Exception {
}
