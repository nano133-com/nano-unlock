<?php
/**
 * Unit tests. The pure parts need no WordPress; the render and REST tests use the small
 * stand-in in wp-stubs.php.
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
require_once __DIR__ . '/wp-stubs.php';

$nano_unlock_dir = dirname( __DIR__ ) . '/nano-unlock/includes/';
foreach ( array( 'blake2b', 'address', 'amount', 'token', 'verifier', 'busy', 'node', 'price', 'limit', 'store', 'payments', 'settings', 'parts', 'render', 'rest' ) as $nano_unlock_part ) {
	require_once $nano_unlock_dir . 'class-nano-unlock-' . $nano_unlock_part . '.php';
}
require_once $nano_unlock_dir . 'class-nano-unlock.php';
nano_unlock_wp_reset();
