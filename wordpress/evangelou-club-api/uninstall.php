<?php
/**
 * Runs only when the plugin is deleted from Plugins → Installed Plugins
 * (never on ordinary deactivation). Deliberately conservative: by default
 * this removes NOTHING — the redemptions table, member ids, and QR token
 * hashes all survive a plugin deletion, so reinstalling later doesn't
 * lose Club history.
 *
 * To actually delete that data on uninstall, an administrator must add
 * this to wp-config.php BEFORE deleting the plugin — it is off by
 * default and not something this file will ever enable on its own:
 *
 *   define( 'EVC_UNINSTALL_DELETE_DATA', true );
 *
 * @package EvangelouClub
 */

// This file is only ever loaded by WordPress's own uninstall process.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! defined( 'EVC_UNINSTALL_DELETE_DATA' ) || true !== EVC_UNINSTALL_DELETE_DATA ) {
	return;
}

global $wpdb;

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- explicit opt-in destructive cleanup, table name only.
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'evc_redemptions' );

delete_option( 'evc_settings' );
delete_option( 'evc_db_version' );

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- explicit opt-in destructive cleanup, no user input involved.
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE meta_key IN (%s, %s)", 'evc_member_id', 'evc_qr_token_hash' ) );

$admin_role = get_role( 'administrator' );
if ( $admin_role ) {
	$admin_role->remove_cap( 'use_evangelou_club' );
}
remove_role( 'evangelou_club_staff' );
