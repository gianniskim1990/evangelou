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
define('EVC_CLUB_PATH', plugin_dir_path(__FILE__));
require_once EVC_CLUB_PATH . 'includes/bootstrap.php';
require_once EVC_CLUB_PATH . 'includes/staff/bootstrap.php';

// Staff authentication foundation (Task 1C-C). Role-scoped session policy,
// admin guard and login throttling are active for users holding the
// evc_club_staff role only. The Club REST routes exist ONLY when wp-config.php
// defines EVC_CLUB_STAFF_ENABLED as boolean true (default: disabled), and even
// then redemption fails closed: no production membership adapter exists yet.
// No database connection or migration is ever opened automatically.
register_activation_hook(__FILE__, array('EVC_Staff_Plugin', 'activate'));
register_deactivation_hook(__FILE__, array('EVC_Staff_Plugin', 'deactivate'));
add_action('plugins_loaded', array('EVC_Staff_Plugin', 'boot'));
