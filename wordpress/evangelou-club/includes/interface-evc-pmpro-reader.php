<?php
defined('ABSPATH') || (defined('EVC_STANDALONE_TEST') && EVC_STANDALONE_TEST) || exit;

/**
 * Narrow, read-only boundary to Paid Memberships Pro / WooCommerce facts
 * (Task 1D-B). Pure contract: no WordPress, SQL or PMPro calls live behind it
 * in this code base yet — only test fakes implement it.
 *
 * A future concrete reader (separate task, against an installed and verified
 * PMPro/WooCommerce version) must:
 *  - report FACTS only, never decisions: raw PMPro membership rows (site-local
 *    wall-clock dates exactly as stored) and normalised payment facts;
 *  - bind each payment to the paid period it funds from VERIFIED provenance
 *    (e.g. a period recorded at confirmation time). PMPro orders do not carry
 *    a period themselves; when provenance is unknown the reader leaves it null
 *    and the mapper fails closed. It must never infer the binding from user
 *    id, amount or a timestamp alone;
 *  - replace raw order/transaction/row identifiers with EVC_Evidence_Ref
 *    HMACs keyed by an injected secret, and never return personal data;
 *  - THROW (any Throwable) when the source cannot be read. The adapter turns
 *    that into a failure; nothing is ever granted on a read error.
 */
interface EVC_Pmpro_Reader {
    public function snapshot(int $wp_user_id): EVC_Pmpro_Snapshot;
}
