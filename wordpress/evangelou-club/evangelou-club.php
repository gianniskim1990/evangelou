<?php
/**
 * Plugin Name: Evangelou Club
 * Description: QR, staff redemption and membership integration for Evangelou Club.
 * Version: 0.1.0-dev
 * Requires PHP: 7.4
 * Text Domain: evangelou-club
 */
defined('ABSPATH') || exit;

define('EVC_CLUB_VERSION', '0.1.0-dev');
define('EVC_CLUB_FILE', __FILE__);
define('EVC_CLUB_PATH', plugin_dir_path(__FILE__));
require_once EVC_CLUB_PATH . 'includes/bootstrap.php';
require_once EVC_CLUB_PATH . 'includes/staff/bootstrap.php';
require_once EVC_CLUB_PATH . 'includes/customer/bootstrap.php';

// Staff authentication foundation (Task 1C-C). Role-scoped session policy,
// admin guard and login throttling are active for users holding the
// evc_club_staff role only. The Club REST routes exist ONLY when wp-config.php
// defines EVC_CLUB_STAFF_ENABLED as boolean true (default: disabled), and even
// then redemption fails closed: no production membership adapter exists yet.
// No database connection or migration is ever opened automatically.
register_activation_hook(__FILE__, array('EVC_Staff_Plugin', 'activate'));
register_deactivation_hook(__FILE__, array('EVC_Staff_Plugin', 'deactivate'));
add_action('plugins_loaded', array('EVC_Staff_Plugin', 'boot'));

// Customer surface (STAGE-01): the /club-member/ page and GET /me exist ONLY
// when wp-config.php defines EVC_CLUB_CUSTOMER_ENABLED as boolean true
// (default: disabled). Native WordPress login; read-only; the membership
// source stays unavailable until a verified PMPro/WooCommerce reader exists.
register_deactivation_hook(__FILE__, array('EVC_Customer_Plugin', 'deactivate'));
add_action('plugins_loaded', array('EVC_Customer_Plugin', 'boot'));

// STAGE-03D: WooCommerce «Completed» by shop staff = Club payment confirmation, cancellation /
// refund freezes the card, recovery on WP-Cron. ONLY when wp-config.php defines
// EVC_CLUB_WC_COMPLETION_ENABLED as boolean true (default: OFF = nothing hooked or scheduled).
register_deactivation_hook(__FILE__, array('EVC_Wc_Completion_Plugin', 'deactivate'));
add_action('plugins_loaded', array('EVC_Wc_Completion_Plugin', 'boot'));
