<?php
/**
 * Uninstall keeps the data unless "delete data" is ticked. Run on the TEST site only:
 *   npx wp-env run tests-cli -- wp eval-file wp-content/nano-unlock-tests/uninstall-test.php
 *
 * @package NanoUnlock
 */

// phpcs:ignoreFile -- a test script.

if ( false === strpos( home_url(), ':8889' ) ) {
	echo "refusing: this test deletes data, so it runs on the test site (port 8889) only\n";
	exit( 1 );
}
$fail = 0;
$check = function ( $ok, $msg ) use ( &$fail ) {
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $msg . "\n";
	$fail += $ok ? 0 : 1;
};
global $wpdb;
wp_set_current_user( 1 );
Nano_Unlock::activate(); // The table and the key exist, as on a site that sells.
$id    = wp_insert_post( wp_slash( array( 'post_title' => 'uninstall test', 'post_status' => 'publish', 'post_content' => '<!-- wp:nano-unlock/paywall --><!-- wp:paragraph --><p>SECRET-U</p><!-- /wp:paragraph --><!-- /wp:nano-unlock/paywall -->' ) ) );
$parts = function () use ( $wpdb, $id ) {
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key LIKE %s", $id, '_nano_unlock_part_%' ) );
};
$table = function () use ( $wpdb ) {
	return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'nano_unlock_checkouts' ) );
};
$check( 1 === $parts() && $table(), 'before: one stored part, and the table' );

define( 'WP_UNINSTALL_PLUGIN', 'nano-unlock/nano-unlock.php' );
$settings                = Nano_Unlock_Settings::get();
$settings['delete_data'] = false;
update_option( 'nano_unlock_settings', $settings );
include WP_PLUGIN_DIR . '/nano-unlock/uninstall.php';
$check( 1 === $parts() && $table() && get_option( 'nano_unlock_secret' ), '"delete data" off: the part, the table and the key are kept' );

$settings['delete_data'] = true;
update_option( 'nano_unlock_settings', $settings );
include WP_PLUGIN_DIR . '/nano-unlock/uninstall.php';
$check( 0 === $parts() && ! $table() && false === get_option( 'nano_unlock_settings' ) && false === get_option( 'nano_unlock_secret' ), '"delete data" on: the parts, the table, the settings and the key are gone' );

wp_delete_post( $id, true );
Nano_Unlock::activate(); // Put the test site back as it was.
echo $fail ? "\n$fail FAILED\n" : "\nall passed\n";
exit( $fail ? 1 : 0 );
