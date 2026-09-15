<?php
/**
 * Plugin Name:       Evangelou Club API
 * Plugin URI:        https://evaggelou.example/
 * Description:       Server-side implementation of the evangelou-club/v1 REST API — resolves Club members (FluentCRM, WooCommerce fallback), checks Paid Memberships Pro eligibility, and enforces the one-free-coffee-per-day redemption rule. Does not talk to the public internet; the React /club app is not connected by this plugin alone.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Evangelou
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       evangelou-club-api
 *
 * @package EvangelouClub
 */

// Refuse a direct hit — this file must only ever load through WordPress.
defined( 'ABSPATH' ) || exit;

define( 'EVC_VERSION', '0.1.0' );
define( 'EVC_DB_VERSION', '1' );
define( 'EVC_PLUGIN_FILE', __FILE__ );
define( 'EVC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EVC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'EVC_REST_NAMESPACE', 'evangelou-club/v1' );

/**
 * The business timezone every business_date/redeemed_at calculation in
 * this plugin uses — never the server's OS timezone, never UTC, never a
 * client-supplied value. See includes/class-timezone.php.
 */
define( 'EVC_BUSINESS_TIMEZONE', 'Europe/Athens' );

require_once EVC_PLUGIN_DIR . 'includes/class-logger.php';
require_once EVC_PLUGIN_DIR . 'includes/class-timezone.php';
require_once EVC_PLUGIN_DIR . 'includes/class-identifiers.php';
require_once EVC_PLUGIN_DIR . 'includes/class-settings.php';
require_once EVC_PLUGIN_DIR . 'includes/class-redemption-repository.php';
require_once EVC_PLUGIN_DIR . 'includes/class-membership-adapter.php';
require_once EVC_PLUGIN_DIR . 'includes/class-member-resolver.php';
require_once EVC_PLUGIN_DIR . 'includes/class-errors.php';
require_once EVC_PLUGIN_DIR . 'includes/class-rest-controller.php';
require_once EVC_PLUGIN_DIR . 'includes/class-admin.php';
require_once EVC_PLUGIN_DIR . 'includes/class-plugin.php';

register_activation_hook( EVC_PLUGIN_FILE, array( '\\EvangelouClub\\Plugin', 'activate' ) );
register_deactivation_hook( EVC_PLUGIN_FILE, array( '\\EvangelouClub\\Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( '\\EvangelouClub\\Plugin', 'instance' ) );
