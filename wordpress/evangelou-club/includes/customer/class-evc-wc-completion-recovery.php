<?php
defined('ABSPATH') || exit;
/**
 * STAGE-03D: the recovery step for completions the Club could not confirm in
 * the same request (and for corrections that could not be recorded then).
 * A callable service: run() takes the orders whose follow-up index is open
 * (oldest first, bounded) and hands each to EVC_Wc_Completion_Confirmer::recover(),
 * which re-reads the signed marker, the order, PMPro and the Club record under
 * the member lock and never trusts the index itself. Retries are bounded
 * (EVC_Wc_Completion_Confirmer::MAX_ATTEMPTS), then a manager must look.
 *
 * Scheduled on WP-Cron ONLY by EVC_Wc_Completion_Plugin behind
 * EVC_CLUB_WC_COMPLETION_ENABLED; never constructed anywhere else.
 */
final class EVC_Wc_Completion_Recovery {
    const BATCH = 20;

    /** @var EVC_Wc_Completion_Confirmer */
    private $confirmer;
    /** @var EVC_Wc_Completion_Markers */
    private $markers;
    /** @var array<int,string> */
    private $members = array();

    public function __construct(EVC_Wc_Completion_Confirmer $confirmer, EVC_Wc_Completion_Markers $markers) {
        EVC_Wc_Completion_Feature::assert_constructible(__CLASS__);
        $this->confirmer = $confirmer;
        $this->markers = $markers;
    }

    /**
     * The open completions first, then (STAGE-05B) the Club member rows still missing for customers
     * with a running recorded paid period (EVC_Wc_Completion_Confirmer::reconcile_members()), which
     * also runs when the open-order scan fails.
     *
     * @return array<int,EVC_Wc_Completion_Result> order id => result of this run
     */
    public function run(int $limit = self::BATCH): array {
        $results = array();
        try {
            $orders = $this->markers->open_orders($limit);
        } catch (Throwable $e) {
            $orders = array();
        }
        foreach ($orders as $order_id) {
            $results[$order_id] = $this->confirmer->recover($order_id);
        }
        $this->members = $this->confirmer->reconcile_members();
        return $results;
    }

    /** @return array<int,string> STAGE-05B: WordPress user id => member-row result of the last run() */
    public function last_members(): array {
        return $this->members;
    }
}
