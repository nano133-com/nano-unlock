<?php
/**
 * Deleting the plugin keeps its data (the paid parts, the sales, the settings
 * and the key) unless the admin ticked "delete data" on the settings page.
 * Kept paid parts stay hidden: they are protected post meta, which no theme,
 * feed or public API prints.
 *
 * @package NanoUnlock
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

wp_clear_scheduled_hook( 'nano_unlock_prune' );
delete_transient( 'nano_unlock_rate' );

$nano_unlock_settings = get_option( 'nano_unlock_settings' );
if ( ! is_array( $nano_unlock_settings ) || empty( $nano_unlock_settings['delete_data'] ) ) {
	return;
}

global $wpdb;
$nano_unlock_table = $wpdb->prefix . 'nano_unlock_checkouts';
// phpcs:disable WordPress.DB.DirectDatabaseQuery -- the plugin's own data, on uninstall.
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $nano_unlock_table ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $wpdb->esc_like( '_nano_unlock_part_' ) . '%' ) );
// phpcs:enable
foreach ( array( 'nano_unlock_settings', 'nano_unlock_secret', 'nano_unlock_db_version', 'nano_unlock_rate_last', 'nano_unlock_parts_version' ) as $nano_unlock_option ) {
	delete_option( $nano_unlock_option );
}
