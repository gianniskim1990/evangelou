<?php
defined('ABSPATH') || exit;
/**
 * STAGE-02: READ side of the Club's own payment provenance — the ONLY place
 * a CONFIRMED Club payment, bound to the exact paid period it funds, can come
 * from.
 *
 * Why WooCommerce / PMPro alone are not enough (verified against the
 * installed sources, see docs/commerce-membership-source.md):
 *  - a bank transfer (BACS) order sits "on-hold" until someone changes its
 *    status by hand; "processing" / "completed" are workflow states anyone
 *    with shop rights (or a plugin) can set and prove no settlement;
 *  - PMPro WooCommerce 1.11 grants the level on "completed" and stores only
 *    site-local start/end strings computed with PHP "+1 month" and the
 *    seconds zeroed; no order is linked to the period it funds;
 *  - so "this money funded exactly this Athens period" is a fact that must
 *    be RECORDED at confirmation time by a trusted write path (an authorised
 *    manager confirming receipt, never the shared staff account, through
 *    EVC_Provenance_Recorder and a persistent provenance store).
 *
 * That write path does NOT exist yet (it needs the provenance store
 * migration, which is not approved, and an owner-approved confirmation
 * procedure). Until it does, production uses
 * EVC_Unrecorded_Club_Payment_Provenance: no confirmed payment exists, so
 * nobody is ever "active"; pending, expired, cancelled and none still work.
 *
 * Contract for a future implementation: return confirmed / refunded /
 * reversed facts only, each with membership_row_ref + period_start_utc +
 * period_end_utc as recorded, confirmed_at_utc = verification instant, and
 * payment_ref = the configured ref of the WooCommerce order it settles
 * (namespace "wc_order"), so the reader can drop that order from the raw
 * commerce facts. Throw when the store cannot be read.
 */
interface EVC_Club_Payment_Provenance {
    /** @return EVC_Pmpro_Payment_Fact[] */
    public function confirmed_payments(int $wp_user_id): array;
}

/** Production default: no recorded Club payment provenance exists (see above). */
final class EVC_Unrecorded_Club_Payment_Provenance implements EVC_Club_Payment_Provenance {
    public function confirmed_payments(int $wp_user_id): array {
        return array();
    }
}
