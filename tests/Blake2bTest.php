<?php
/**
 * BLAKE2b.
 *
 * @package NanoUnlock
 */

use PHPUnit\Framework\TestCase;

final class Blake2bTest extends TestCase {

	public function test_rfc_7693_vector() {
		$this->assertSame(
			'ba80a53f981c4d0d6a2797b69f12f6e94c212f14685ac4b74b12bb6fdbffa2d17d87c5392aab792dc252d5de4533cc9518d38aa8dbf1925ab92386edd4009923',
			bin2hex( Nano_Unlock_Blake2b::hash( 'abc', 64 ) )
		);
	}

	public function test_empty_message() {
		$this->assertSame(
			'786a02f742015903c6c6fd852552d272912f4740e15847618a86e217f71f5419d25e1031afee585313896444934eb04b903a685b1448b755d56f701afe9be2ce',
			bin2hex( Nano_Unlock_Blake2b::hash( '', 64 ) )
		);
	}

	/**
	 * Every length libsodium can make, across block boundaries.
	 */
	public function test_matches_libsodium() {
		foreach ( array( 0, 1, 32, 127, 128, 129, 255, 256, 1000 ) as $size ) {
			$data = str_repeat( chr( $size % 251 ), $size );
			foreach ( array( 16, 20, 32, 48, 64 ) as $length ) {
				$this->assertSame( bin2hex( sodium_crypto_generichash( $data, '', $length ) ), bin2hex( Nano_Unlock_Blake2b::hash( $data, $length ) ), "size $size, length $length" );
			}
		}
	}

	public function test_a_short_digest_is_not_a_prefix_of_a_long_one() {
		$this->assertSame( 5, strlen( Nano_Unlock_Blake2b::hash( 'x', 5 ) ) );
		$this->assertNotSame( substr( Nano_Unlock_Blake2b::hash( 'x', 64 ), 0, 5 ), Nano_Unlock_Blake2b::hash( 'x', 5 ) );
	}

	public function test_bad_length() {
		$this->expectException( InvalidArgumentException::class );
		Nano_Unlock_Blake2b::hash( 'x', 65 );
	}
}
