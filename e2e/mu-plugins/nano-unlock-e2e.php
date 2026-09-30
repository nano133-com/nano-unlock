<?php
/**
 * End-to-end test helper (mapped into the wp-env site only): a fixed XNO/USD
 * rate when the option nano_unlock_e2e_rate is set, so the test never depends
 * on a live price feed.
 *
 * @package NanoUnlock
 */

add_filter(
	'nano_unlock_rates',
	function ( $rates ) {
		$rate = get_option( 'nano_unlock_e2e_rate' );
		return $rate ? array( (float) $rate ) : $rates;
	}
);
