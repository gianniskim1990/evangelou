<?php
defined('ABSPATH') || (defined('EVC_STANDALONE_TEST') && EVC_STANDALONE_TEST) || exit;

/**
 * Source of authoritative membership eligibility for a WordPress user.
 *
 * Implementations MUST report verified payment state from the billing /
 * membership system of record (Paid Memberships Pro + WooCommerce later),
 * never from FluentCRM tags, and MUST throw on lookup failure rather than
 * guess: the redemption service treats any exception as "not eligible".
 * Only a test mock exists in Task 1B (tests/support).
 */
interface EVC_Membership_Adapter {
    public function entitlement_for(int $wp_user_id, DateTimeImmutable $at_utc): EVC_Entitlement;
}
