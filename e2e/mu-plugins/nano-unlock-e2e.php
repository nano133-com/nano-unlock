<?php
/**
 * End-to-end test helper, active only while the option nano_unlock_e2e is set
 * (e2e/run.mjs sets it, and removes it when it ends): a fixed XNO/USD rate, so
 * the test never depends on a live price feed, and permission to start
 * checkouts against the mock node.
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
}
