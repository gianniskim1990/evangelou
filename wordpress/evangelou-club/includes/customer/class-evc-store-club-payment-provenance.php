<?php
defined('ABSPATH') || exit;
/**
 * STAGE-03: the READ side of the Club's own payment record — existing
 * EVC_Provenance_Store contents (written only by EVC_Provenance_Recorder,
 * e.g. through EVC_Bacs_Payment_Confirmation) as confirmed payment facts for
 * EVC_Wp_Commerce_Pmpro_Reader.
 *
 * One fact per recorded movement of the user on the configured Club level:
 *   payment_ref         = the movement's WooCommerce ORDER anchor reference
 *                         (EVC_Commerce_Source_Config::ref('wc_order', id)),
 *                         so the reader drops that order's raw commerce fact;
 *   dedupe_key          = movement id (one money movement = one fact);
 *   membership_row_ref, period start/end = exactly as RECORDED;
 *   confirmed_at_utc    = the recorded verification instant;
 *   status              = confirmed, or refunded / partially_refunded /
 *                         reversed (a voided payment counts as reversed)
 *                         after a recorded correction (latest one wins).
 * Nothing is computed or guessed here; the mapper re-checks every rule.
 * A movement without a single wc_order anchor or without a period is a
 * contradiction and THROWS (the adapter answers "unavailable").
 *
 * The store is opened lazily, once per read, so a request that does not read
 * a membership never connects to the Club database.
 */
final class EVC_Store_Club_Payment_Provenance implements EVC_Club_Payment_Provenance {
    /** @var callable(): EVC_Provenance_Store */
    private $open_store;
    /** @var EVC_Commerce_Source_Config */
    private $config;

    public function __construct(callable $open_store, EVC_Commerce_Source_Config $config) {
        $this->open_store = $open_store;
        $this->config = $config;
    }

    public function confirmed_payments(int $wp_user_id): array {
        $store = ($this->open_store)();
        if (!$store instanceof EVC_Provenance_Store) {
            throw new UnexpectedValueException('No provenance store.');
        }
        $facts = array();
        foreach ($store->member_movements($wp_user_id) as $m) {
            if ($m->level_id() !== $this->config->level_id) {
                continue;
            }
            $anchors = $m->order_anchors();
            if (count($anchors) !== 1 || $anchors[0]->namespace_name() !== EVC_Movement_Alias::NS_WC_ORDER) {
                throw new UnexpectedValueException('Recorded movement without exactly one WooCommerce order anchor.');
            }
            $period = $store->period_for_movement($m->movement_id());
            if ($period === null || $period->wp_user_id() !== $wp_user_id || $period->pmpro_row_ref() !== $m->pmpro_row_ref()) {
                throw new UnexpectedValueException('Recorded movement without its own period.');
            }
            $facts[] = new EVC_Pmpro_Payment_Fact(
                $anchors[0]->ref(),
                $m->movement_id(),
                $m->evidence_kind(),
                $m->level_id(),
                $m->pmpro_row_ref(),
                self::status_after($store->corrections_for($m->movement_id())),
                $m->environment(),
                $m->verified_at_utc(),
                $period->start_utc(),
                $period->end_utc()
            );
        }
        return $facts;
    }

    /** @param EVC_Movement_Correction[] $history in recorded order */
    private static function status_after(array $history): string {
        if ($history === array()) {
            return EVC_Pmpro_Payment_Fact::STATUS_CONFIRMED;
        }
        switch ($history[count($history) - 1]->kind()) {
            case EVC_Movement_Correction::KIND_REFUNDED:
                return EVC_Pmpro_Payment_Fact::STATUS_REFUNDED;
            case EVC_Movement_Correction::KIND_PARTIALLY_REFUNDED:
                return EVC_Pmpro_Payment_Fact::STATUS_PARTIALLY_REFUNDED;
            default:
                return EVC_Pmpro_Payment_Fact::STATUS_REVERSED; // reversed, voided
        }
    }
}
