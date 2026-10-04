<?php
/**
 * Screenshot helper (e2e/wporg-shots.mjs), active only while the option nano_unlock_shots is set:
 * the site keeps the real default node in its settings, so the pages look as they do on a real
 * site, but this helper sends those node calls to the mock node (no real network, no real money),
 * and fixes the XNO/USD rate at $1.00 so no price feed is called. The script copies this file in
 * and removes it when it ends.
 *
 * @package NanoUnlock
 */

if ( get_option( 'nano_unlock_shots' ) ) {
	add_filter(
		'nano_unlock_rates',
		function () {
			return array( 1.0 );
		}
	);
	add_filter(
		'pre_http_request',
		function ( $pre, $args, $url ) {
			if ( 'https://node.nano133.com/rpc' !== $url ) {
				return $pre;
			}
			$args['redirection'] = 0;
			return wp_remote_post( 'http://host.docker.internal:8787', $args );
		},
		10,
		3
	);
}
