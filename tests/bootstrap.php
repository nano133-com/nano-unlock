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

if ( ! function_exists( 'wp_parse_url' ) ) {
	/**
	 * WordPress's parse_url().
	 *
	 * @param string $url       URL.
	 * @param int    $component Component.
	 * @return mixed
	 */
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	/**
	 * No filters in unit tests.
	 *
	 * @param string $hook  Hook.
	 * @param mixed  $value Value.
	 * @return mixed
	 */
	function apply_filters( $hook, $value ) {
		return $value;
	}
}

$nano_unlock_dir = dirname( __DIR__ ) . '/nano-unlock/includes/';
foreach ( array( 'blake2b', 'address', 'amount', 'token', 'verifier', 'settings' ) as $nano_unlock_part ) {
	require_once $nano_unlock_dir . 'class-nano-unlock-' . $nano_unlock_part . '.php';
}
