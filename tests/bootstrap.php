<?php
/**
 * Unit tests for the plugin's pure parts (no WordPress needed).
 *
 * @package NanoUnlock
 */

define( 'ABSPATH', __DIR__ . '/' );

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * The one WordPress function the pure parts use.
	 *
	 * @param mixed $data Data.
	 * @return string|false
	 */
	function wp_json_encode( $data ) {
		return json_encode( $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}
}

$nano_unlock_dir = dirname( __DIR__ ) . '/nano-unlock/includes/';
foreach ( array( 'blake2b', 'address', 'amount', 'token', 'verifier' ) as $nano_unlock_part ) {
	require_once $nano_unlock_dir . 'class-nano-unlock-' . $nano_unlock_part . '.php';
}
