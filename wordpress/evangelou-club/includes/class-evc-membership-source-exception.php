<?php
defined('ABSPATH') || (defined('EVC_STANDALONE_TEST') && EVC_STANDALONE_TEST) || exit;

/**
 * The membership source (PMPro / WooCommerce) could not be read. Thrown by
 * EVC_Pmpro_Membership_Adapter so the redemption engine fails closed with
 * server_error (retryable) instead of granting or wrongly denying. Carries no
 * message from the underlying failure (no SQL, no personal data).
 */
final class EVC_Membership_Source_Exception extends RuntimeException {
}
