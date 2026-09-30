<?php
/**
 * Rate limits.
 *
 * @package NanoUnlock
 */

defined( 'ABSPATH' ) || exit;

/**
 * Fixed-window counters in transients, one per visitor address and window.
 *
 * The visitor's address is REMOTE_ADDR. Forwarded headers are not trusted by
 * default, because anyone can send them; a site behind a proxy or a CDN can
 * name the real header with the `nano_unlock_client_ip` filter.
 */
final class Nano_Unlock_Limit {

	/**
	 * Counts one call; false when the window is already full.
	 *
	 * @param string $scope  What is limited ("checkout", "claim").
	 * @param int    $max    Calls allowed per window.
	 * @param int    $window The window in seconds.
	 * @param string $who    Whom to count ("all" for everyone).
	 * @return bool
	 */
	public static function allow( $scope, $max, $window, $who ) {
		$slot  = (int) floor( time() / $window );
		$key   = 'nano_unlock_rl_' . substr( hash( 'sha256', $scope . '|' . $who . '|' . $window . '|' . $slot ), 0, 32 );
		$count = (int) get_transient( $key );
		if ( $count >= $max ) {
			return false;
		}
		set_transient( $key, $count + 1, 2 * $window );
		return true;
	}

	/**
	 * The visitor's address.
	 *
	 * @return string
	 */
	public static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		/**
		 * Filters the visitor's address used for rate limits.
		 *
		 * @param string $ip REMOTE_ADDR.
		 */
		$ip = (string) apply_filters( 'nano_unlock_client_ip', $ip );
		return '' === $ip ? 'unknown' : $ip;
	}
}
