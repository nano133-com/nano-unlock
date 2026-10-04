<?php
/**
 * The XNO/USD rate.
 *
 * @package NanoUnlock
 */

defined( 'ABSPATH' ) || exit;

/**
 * Dollars per XNO: the median of the public price feeds that answer, so one
 * bad feed can't move it. Cached for five minutes; when every feed fails, the
 * last good rate is used for up to a day, and after that checkouts pause.
 */
final class Nano_Unlock_Price {

	const CACHE     = 'nano_unlock_rate';
	const LAST_GOOD = 'nano_unlock_rate_last';

	/**
	 * Dollars per XNO, or null when no believable rate is known.
	 *
	 * @return float|null
	 */
	public static function rate() {
		$cached = get_transient( self::CACHE );
		if ( is_numeric( $cached ) ) {
			return (float) $cached;
		}
		$rates = array();
		foreach ( self::sources() as $url => $read ) {
			$response = wp_remote_get(
				$url,
				array(
					'timeout'     => 5,
					'redirection' => 0,
					// A plain public price request: the plugin's name, not WordPress's default (which names the site).
					'user-agent'  => 'NanoUnlock/' . NANO_UNLOCK_VERSION,
				)
			);
			if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				continue;
			}
			$json = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			$rate = is_array( $json ) ? (float) $read( $json ) : 0.0;
			if ( $rate >= Nano_Unlock_Amount::MIN_RATE && $rate <= Nano_Unlock_Amount::MAX_RATE ) {
				$rates[] = $rate;
			}
		}
		/**
		 * Filters the rates read from the feeds (tests set a fixed rate here).
		 *
		 * @param float[] $rates Dollars per XNO, one per feed that answered.
		 */
		$rates = (array) apply_filters( 'nano_unlock_rates', $rates );
		if ( ! $rates ) {
			$last = get_option( self::LAST_GOOD );
			if ( is_array( $last ) && isset( $last['rate'], $last['at'] ) && time() - (int) $last['at'] < DAY_IN_SECONDS ) {
				return (float) $last['rate'];
			}
			return null;
		}
		sort( $rates );
		$rate = (float) $rates[ (int) floor( count( $rates ) / 2 ) ];
		set_transient( self::CACHE, $rate, 5 * MINUTE_IN_SECONDS );
		update_option(
			self::LAST_GOOD,
			array(
				'rate' => $rate,
				'at'   => time(),
			),
			false
		);
		return $rate;
	}

	/**
	 * The feeds: URL => a reader that returns dollars per XNO from the JSON answer.
	 *
	 * @return array<string, callable>
	 */
	private static function sources() {
		return array(
			'https://api.coingecko.com/api/v3/simple/price?ids=nano&vs_currencies=usd' => function ( $j ) {
				return isset( $j['nano']['usd'] ) ? $j['nano']['usd'] : 0;
			},
			'https://api.kraken.com/0/public/Ticker?pair=NANOUSD' => function ( $j ) {
				if ( empty( $j['result'] ) || ! is_array( $j['result'] ) ) {
					return 0;
				}
				$first = reset( $j['result'] );
				return isset( $first['c'][0] ) ? $first['c'][0] : 0;
			},
			'https://api.kucoin.com/api/v1/market/orderbook/level1?symbol=XNO-USDT' => function ( $j ) {
				return isset( $j['data']['price'] ) ? $j['data']['price'] : 0;
			},
		);
	}
}
