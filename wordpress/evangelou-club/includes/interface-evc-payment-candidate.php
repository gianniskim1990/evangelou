<?php
defined('ABSPATH') || (defined('EVC_STANDALONE_TEST') && EVC_STANDALONE_TEST) || exit;

/**
 * The payment facts EVC_Provenance_Recorder's business rules read
 * (STAGE-03B.1). Implemented by EVC_Verified_Movement (a recordable,
 * row-bound movement) and EVC_Prospective_Payment (the same facts BEFORE
 * PMPro has issued its membership row), so record() and plan() apply ONE
 * implementation of the refusal, alias, ordering and D1/D2/D5 rules.
 *
 * Deliberately absent: a PMPro row reference and a confirmation state. Only
 * EVC_Verified_Movement carries those, and only it can be recorded.
 */
interface EVC_Payment_Candidate {
    public function movement_id(): string;

    public function environment(): string;

    public function wp_user_id(): int;

    public function level_id(): int;

    public function verified_at_utc(): DateTimeImmutable;

    public function amount_minor(): int;

    public function currency(): string;

    /** @return EVC_Movement_Alias[] keyed and sorted by alias key */
    public function aliases(): array;

    /** @return EVC_Movement_Alias[] */
    public function order_anchors(): array;

    public function authority(): string;

    public function evidence_kind(): string;
}
