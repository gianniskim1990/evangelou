<?php
defined('ABSPATH') || exit;
/**
 * Production membership source for the customer surface (STAGE-01):
 * ALWAYS unavailable.
 *
 * The real source is EVC_Pmpro_Membership_Adapter (the existing reader
 * contract + EVC_Pmpro_Entitlement_Mapper). It needs a concrete
 * EVC_Pmpro_Reader for the EXACT PMPro / WooCommerce / add-on versions
 * installed on staging, the approved Club level id(s) for
 * EVC_Pmpro_Mapper_Config, an evidence-reference key and VERIFIED payment
 * provenance (each confirmed payment bound to the paid period it funds).
 * None of these is confirmed yet, so no reader ships: nothing is faked and
 * nothing is ever reported as paid. GET /me answers 503 "unavailable".
 * Replacing this requires a reviewed reader task against the technician's
 * confirmed setup.
 */
final class EVC_Unavailable_Membership_Adapter implements EVC_Membership_Adapter {
    public function entitlement_for(int $wp_user_id, DateTimeImmutable $at_utc): EVC_Entitlement {
        throw new EVC_Membership_Source_Exception('Membership source not configured.');
    }
}
