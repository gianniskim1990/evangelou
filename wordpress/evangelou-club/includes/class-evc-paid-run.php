<?php
defined('ABSPATH') || (defined('EVC_STANDALONE_TEST') && EVC_STANDALONE_TEST) || exit;

/**
 * STAGE-03B.2: where the member's contiguous PAID RUN starts, for a planned
 * period. PMPro WooCommerce keeps the existing row start on a renewal of the
 * same level, so the start PMPro must show is the start of the run, not of
 * the new month. Read-only over the Club record; never guesses: a broken
 * chain throws (the caller reports unavailable / manager intervention).
 *
 * initial / late period : the run starts with the planned period (D2)
 * early renewal         : walk back over early predecessors, each of which
 *                         must start exactly where its predecessor ended
 */
final class EVC_Paid_Run {
    /** Safety bound on the chain walk (>> any real membership history). */
    const MAX_PERIODS = 1200;

    public static function start_for(EVC_Provenance_Store $store, EVC_Period_Plan $plan): DateTimeImmutable {
        if ($plan->outcome() !== EVC_Period_Plan::PLANNED) {
            throw new InvalidArgumentException('Only a planned period has a paid run to find.');
        }
        if ($plan->kind() !== EVC_Funded_Period::KIND_EARLY) {
            return $plan->start_utc();
        }
        $expected_end = $plan->start_utc();
        $id = $plan->predecessor_movement_id();
        for ($i = 0; $i < self::MAX_PERIODS; $i++) {
            $p = $id === null ? null : $store->period_for_movement($id);
            if ($p === null || $p->end_utc() != $expected_end) {
                throw new UnexpectedValueException('The recorded paid chain is not contiguous.');
            }
            if ($p->kind() !== EVC_Funded_Period::KIND_EARLY) {
                return $p->start_utc();
            }
            $expected_end = $p->start_utc();
            $id = $p->predecessor_movement_id();
        }
        throw new UnexpectedValueException('The recorded paid chain is implausibly long.');
    }
}
