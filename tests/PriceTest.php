<?php
/**
 * The XNO/USD rate: the median of the feeds that answer, and what the requests carry.
 *
 * @package NanoUnlock
 */

use PHPUnit\Framework\TestCase;

final class PriceTest extends TestCase {

	const COINGECKO = 'https://api.coingecko.com/api/v3/simple/price?ids=nano&vs_currencies=usd';
	const KRAKEN    = 'https://api.kraken.com/0/public/Ticker?pair=NANOUSD';
	const KUCOIN    = 'https://api.kucoin.com/api/v1/market/orderbook/level1?symbol=XNO-USDT';

	protected function setUp(): void {
		nano_unlock_wp_reset();
	}

	public function test_the_median_of_the_feeds() {
		$GLOBALS['nano_unlock_wp']['feeds'] = array(
			self::COINGECKO => array( 'nano' => array( 'usd' => 0.9 ) ),
			self::KRAKEN    => array( 'result' => array( 'NANOUSD' => array( 'c' => array( '1.0', '1' ) ) ) ),
			self::KUCOIN    => array( 'data' => array( 'price' => '5.0' ) ),
		);
		$this->assertSame( 1.0, Nano_Unlock_Price::rate() );
		$this->assertCount( 3, $GLOBALS['nano_unlock_wp']['gets'] );
	}

	public function test_the_requests_name_the_plugin_not_the_site() {
		$GLOBALS['nano_unlock_wp']['feeds'] = array( self::COINGECKO => array( 'nano' => array( 'usd' => 1.2 ) ) );
		Nano_Unlock_Price::rate();
		foreach ( $GLOBALS['nano_unlock_wp']['gets'] as $get ) {
			$this->assertSame( 'NanoUnlock/' . NANO_UNLOCK_VERSION, $get['args']['user-agent'], $get['url'] );
			$this->assertSame( 0, $get['args']['redirection'], $get['url'] );
		}
	}

	public function test_a_cached_rate_calls_no_feed() {
		$GLOBALS['nano_unlock_wp']['transients'][ Nano_Unlock_Price::CACHE ] = 1.1;
		$this->assertSame( 1.1, Nano_Unlock_Price::rate() );
		$this->assertCount( 0, $GLOBALS['nano_unlock_wp']['gets'] );
	}
}
