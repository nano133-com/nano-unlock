<?php
/**
 * Deleting the plugin removes its table, its settings and its key.
 *
 * @package NanoUnlock
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;
$nano_unlock_table = $wpdb->prefix . 'nano_unlock_checkouts';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the plugin's own table, on uninstall.
$wpdb->query( "DROP TABLE IF EXISTS {$nano_unlock_table}" );
foreach ( array( 'nano_unlock_settings', 'nano_unlock_secret', 'nano_unlock_db_version', 'nano_unlock_rate_last' ) as $nano_unlock_option ) {
	delete_option( $nano_unlock_option );
}
delete_transient( 'nano_unlock_rate' );
wp_clear_scheduled_hook( 'nano_unlock_prune' );
