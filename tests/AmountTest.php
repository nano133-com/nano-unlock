<?php
/**
 * Amounts in raw.
 *
 * @package NanoUnlock
 */

use PHPUnit\Framework\TestCase;

final class AmountTest extends TestCase {

	public function test_price_in_raw() {
		// 1¢ at $0.50 per XNO is 0.02 XNO = 2 × 10^28 raw.
		$this->assertSame( '2' . str_repeat( '0', 28 ), Nano_Unlock_Amount::from_usd( 0.01, 0.5 ) );
		// 5¢ at $0.50 is 0.1 XNO.
		$this->assertSame( '1' . str_repeat( '0', 29 ), Nano_Unlock_Amount::from_usd( 0.05, 0.5 ) );
		// $2 at $0.80 is 2.5 XNO.
		$this->assertSame( '25' . str_repeat( '0', 29 ), Nano_Unlock_Amount::from_usd( 2, 0.8 ) );
	}

	public function test_rounds_up_to_a_ten_thousandth() {
		// 1¢ at $0.30 is 0.0333… XNO: 0.0334.
		$this->assertSame( '334' . str_repeat( '0', 26 ), Nano_Unlock_Amount::from_usd( 0.01, 0.3 ) );
		// Float noise must not add a step: 0.07 / 0.7 = 0.1 exactly.
		$this->assertSame( '1' . str_repeat( '0', 29 ), Nano_Unlock_Amount::from_usd( 0.07, 0.7 ) );
		// A tiny price is at least 0.0001 XNO.
		$this->assertSame( '1' . str_repeat( '0', 26 ), Nano_Unlock_Amount::from_usd( 0.00001, 50 ) );
	}

	public function test_refuses_bad_rates_and_prices() {
		$this->assertNull( Nano_Unlock_Amount::from_usd( 0.01, 0.01 ) );
		$this->assertNull( Nano_Unlock_Amount::from_usd( 0.01, 1000 ) );
		$this->assertNull( Nano_Unlock_Amount::from_usd( 0, 1 ) );
		$this->assertNull( Nano_Unlock_Amount::from_usd( -1, 1 ) );
		$this->assertNull( Nano_Unlock_Amount::from_usd( 20000, 1 ) );
		$this->assertNull( Nano_Unlock_Amount::from_usd( 'abc', 1 ) );
	}

	public function test_steps_of_a_ten_thousandth() {
		$price = Nano_Unlock_Amount::from_usd( 0.05, 0.3307 );
		// $0.05 at $0.3307 is 0.15119… XNO: rounded up to 0.1512.
		$this->assertSame( '1512' . str_repeat( '0', 26 ), $price );
		$this->assertSame( '1513' . str_repeat( '0', 26 ), Nano_Unlock_Amount::plus_steps( $price, 1 ) );
		$this->assertSame( '0.1611', Nano_Unlock_Amount::to_xno( Nano_Unlock_Amount::plus_steps( $price, 99 ) ) );
		// Across a power of ten: 0.9999 + 0.0001 is 1.
		$this->assertSame( '1', Nano_Unlock_Amount::to_xno( Nano_Unlock_Amount::plus_steps( Nano_Unlock_Amount::from_steps( 9999 ), 1 ) ) );
		$this->assertSame( '0.0001', Nano_Unlock_Amount::to_xno( Nano_Unlock_Amount::from_steps( 1 ) ) );
		// The largest price at the lowest rate still fits: $10000 at $0.05 is 200000 XNO.
		$this->assertSame( '200000', Nano_Unlock_Amount::to_xno( Nano_Unlock_Amount::from_usd( 10000, 0.05 ) ) );
	}

	public function test_every_amount_is_short_and_clean() {
		$price = Nano_Unlock_Amount::from_usd( 0.01, 0.5 );
		for ( $k = 0; $k < 100; $k++ ) {
			$a = Nano_Unlock_Amount::plus_steps( $price, $k );
			$this->assertTrue( Nano_Unlock_Amount::is_clean( $a ) );
			$this->assertTrue( Nano_Unlock_Amount::is_raw( $a ) );
			// At most four decimals, so a wallet that rounds to four decimals sends it exactly.
			$this->assertMatchesRegularExpression( '/^\\d+(\\.\\d{1,4})?$/', Nano_Unlock_Amount::to_xno( $a ) );
			$this->assertSame( Nano_Unlock_Amount::to_xno( $a ), Nano_Unlock_Amount::to_xno_short( $a ) );
		}
		// An older amount, with a random tail in its last digits, is not clean.
		$this->assertFalse( Nano_Unlock_Amount::is_clean( '151200000000000000000000364625' ) );
		$this->assertFalse( Nano_Unlock_Amount::is_clean( '100' ) );
		$this->assertFalse( Nano_Unlock_Amount::is_clean( 'abc' ) );
	}

	public function test_compare() {
		$this->assertSame( 0, Nano_Unlock_Amount::compare( '100', '100' ) );
		$this->assertSame( -1, Nano_Unlock_Amount::compare( '99', '100' ) );
		$this->assertSame( 1, Nano_Unlock_Amount::compare( '1000000000000000000000000000001', '1000000000000000000000000000000' ) );
		$this->assertSame( 0, Nano_Unlock_Amount::compare( '0012', '12' ) );
	}

	public function test_to_xno() {
		$this->assertSame( '0.02', Nano_Unlock_Amount::to_xno( '2' . str_repeat( '0', 28 ) ) );
		$this->assertSame( '0.020000000000000000000000123456', Nano_Unlock_Amount::to_xno( '20000000000000000000000123456' ) );
		$this->assertSame( '1', Nano_Unlock_Amount::to_xno( '1' . str_repeat( '0', 30 ) ) );
		$this->assertSame( '133.5', Nano_Unlock_Amount::to_xno( '1335' . str_repeat( '0', 29 ) ) );
		$this->assertSame( '0.000000000000000000000000000001', Nano_Unlock_Amount::to_xno( '1' ) );
	}

	public function test_to_xno_short_rounds_up() {
		$this->assertSame( '0.02', Nano_Unlock_Amount::to_xno_short( '2' . str_repeat( '0', 28 ) ) );
		$this->assertSame( '0.0201', Nano_Unlock_Amount::to_xno_short( '20000000000000000000000123456' ) );
		$this->assertSame( '1', Nano_Unlock_Amount::to_xno_short( '999900000000000000000000000001' ) );
		$this->assertSame( '0.123457', Nano_Unlock_Amount::to_xno_short( '123456700000000000000000000000', 6 ) );
	}

	public function test_is_raw() {
		$this->assertTrue( Nano_Unlock_Amount::is_raw( '1' ) );
		$this->assertTrue( Nano_Unlock_Amount::is_raw( str_repeat( '9', 39 ) ) );
		$this->assertFalse( Nano_Unlock_Amount::is_raw( str_repeat( '9', 40 ) ) );
		$this->assertFalse( Nano_Unlock_Amount::is_raw( '0' ) );
		$this->assertFalse( Nano_Unlock_Amount::is_raw( '012' ) );
		$this->assertFalse( Nano_Unlock_Amount::is_raw( '1.5' ) );
		$this->assertFalse( Nano_Unlock_Amount::is_raw( 12 ) );
	}
}
