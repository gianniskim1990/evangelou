<?php
defined('ABSPATH') || exit;
/**
 * DEFAULT membership source for the customer surface: ALWAYS unavailable
 * (GET /me answers 503 "unavailable"; nothing is faked, nothing is paid).
 *
 * STAGE-02: the real, read-only source (EVC_Wp_Commerce_Pmpro_Reader +
 * EVC_Pmpro_Entitlement_Mapper) replaces it ONLY when wp-config.php
 * configures EVC_Commerce_Source_Config. Even then nobody is "active" until
 * the Club's own payment-provenance record exists
 * (EVC_Club_Payment_Provenance; see docs/commerce-membership-source.md).
 */
final class EVC_Unavailable_Membership_Adapter implements EVC_Membership_Adapter {
    public function entitlement_for(int $wp_user_id, DateTimeImmutable $at_utc): EVC_Entitlement {
        throw new EVC_Membership_Source_Exception('Membership source not configured.');
    }
}
