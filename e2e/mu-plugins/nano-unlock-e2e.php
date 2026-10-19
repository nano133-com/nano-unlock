<?php
/**
 * End-to-end test helper, active only while the option nano_unlock_e2e is set
 * (e2e/run.mjs sets it, and removes it when it ends): a fixed XNO/USD rate, so
 * the test never depends on a live price feed, permission to start checkouts
 * against the mock node, and a visitor address taken from the request's
 * X-Nano-Unlock-Test-Ip header, so the parallel test can be many visitors.
 *
 * @package NanoUnlock
 */

if ( get_option( 'nano_unlock_e2e' ) ) {
	add_filter(
		'nano_unlock_rates',
		function () {
			return array( 0.5 );
		}
	);
	add_filter( 'nano_unlock_allow_test_node', '__return_true' );
	add_filter(
		'nano_unlock_client_ip',
		function ( $ip ) {
			return isset( $_SERVER['HTTP_X_NANO_UNLOCK_TEST_IP'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_NANO_UNLOCK_TEST_IP'] ) ) : $ip;
		}
	);
}
