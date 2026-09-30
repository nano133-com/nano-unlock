<?php
/**
 * Nano addresses.
 *
 * @package NanoUnlock
 */

use PHPUnit\Framework\TestCase;

final class AddressTest extends TestCase {

	const REAL = array(
		'nano_1cp9z9nwkzonezr97a8xfw79ahx6emdqhj8eu5j1gkmxhwy817dowa67xzoi',
		'nano_3getnanons1aaqo5itbm8wdbzhtsp7tctd6p6qa7axwff7ocemzs3w381kfy',
		'nano_1natrium1o3z5519ifou7xii8crpxpk8y65qmkih8e8bpsjri651oza8imdd',
		'nano_1jtx5p8141zjtukz4msp1x93st7nh475f74odj8673qqm96xczmtcnanos1o',
	);

	public function test_real_addresses_are_valid() {
		foreach ( self::REAL as $a ) {
			$this->assertTrue( Nano_Unlock_Address::is_valid( $a ), $a );
		}
	}

	public function test_a_typo_breaks_the_checksum() {
		foreach ( self::REAL as $a ) {
			// Change one character of the key part, and one of the checksum.
			foreach ( array( 20, 62 ) as $i ) {
				$typo    = $a;
				$typo[ $i ] = '1' === $a[ $i ] ? '3' : '1';
				$this->assertFalse( Nano_Unlock_Address::is_valid( $typo ), $typo );
			}
		}
	}

	public function test_malformed() {
		$good = self::REAL[0];
		$bad  = array(
			'',
			'nano_',
			substr( $good, 0, -1 ),
			$good . '1',
			'xrb_' . substr( $good, 5 ),
			'NANO_' . substr( $good, 5 ),
			strtoupper( $good ),
			str_replace( 'z', 'l', $good ),
			'nano_2' . substr( $good, 6 ),
			null,
			123,
		);
		foreach ( $bad as $a ) {
			$this->assertFalse( Nano_Unlock_Address::is_valid( $a ), var_export( $a, true ) );
		}
	}

	public function test_round_trip() {
		for ( $i = 0; $i < 20; $i++ ) {
			$key     = random_bytes( 32 );
			$address = Nano_Unlock_Address::from_public_key( $key );
			$this->assertSame( 65, strlen( $address ) );
			$this->assertSame( $key, Nano_Unlock_Address::public_key( $address ) );
		}
	}

	public function test_short() {
		$this->assertSame( 'nano_1c…xzoi', Nano_Unlock_Address::short( self::REAL[0] ) );
	}
}
