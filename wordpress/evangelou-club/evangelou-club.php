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
require_once EVC_CLUB_PATH . 'includes/class-evc-clock.php';
require_once EVC_CLUB_PATH . 'includes/class-evc-qr-token.php';

// Intentionally inert until the staging DB, authenticated staff API, PMPro
// entitlement adapter and end-to-end tests are ready. No data writes.
