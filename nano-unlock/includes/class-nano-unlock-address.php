<?php
/**
 * Nano addresses.
 *
 * @package NanoUnlock
 */

defined( 'ABSPATH' ) || exit;

/**
 * Checks a nano_ address: its alphabet, its length, its padding and its
 * checksum (the last 8 characters are a 5-byte BLAKE2b digest of the public
 * key, in reverse byte order).
 */
final class Nano_Unlock_Address {

	const ALPHABET = '13456789abcdefghijkmnopqrstuwxyz';

	/**
	 * Whether $address is a valid nano_ address.
	 *
	 * @param string $address The address.
	 * @return bool
	 */
	public static function is_valid( $address ) {
		return null !== self::public_key( $address );
	}

	/**
	 * The address's 32-byte public key (raw), or null when the address is not valid.
	 *
	 * @param string $address The address.
	 * @return string|null
	 */
	public static function public_key( $address ) {
		if ( ! is_string( $address ) || ! preg_match( '/^nano_[13][13456789abcdefghijkmnopqrstuwxyz]{59}$/', $address ) ) {
			return null;
		}
		$bits = self::bits( substr( $address, 5, 52 ) );
		// 52 characters carry 260 bits: 4 zero bits of padding, then the 256-bit key.
		if ( '0000' !== substr( $bits, 0, 4 ) ) {
			return null;
		}
		$key      = self::bytes( substr( $bits, 4 ) );
		$checksum = self::bytes( self::bits( substr( $address, 57, 8 ) ) );
		$expected = strrev( Nano_Unlock_Blake2b::hash( $key, 5 ) );
		return hash_equals( $expected, $checksum ) ? $key : null;
	}

	/**
	 * The address for a 32-byte public key.
	 *
	 * @param string $key The raw public key.
	 * @return string
	 */
	public static function from_public_key( $key ) {
		$bits = '0000' . self::to_bits( $key );
		$sum  = self::to_bits( strrev( Nano_Unlock_Blake2b::hash( $key, 5 ) ) );
		return 'nano_' . self::encode( $bits ) . self::encode( $sum );
	}

	/**
	 * Base32 characters to a string of bits.
	 *
	 * @param string $chars Characters of the Nano alphabet.
	 * @return string
	 */
	private static function bits( $chars ) {
		$bits = '';
		foreach ( str_split( $chars ) as $c ) {
			$bits .= str_pad( decbin( strpos( self::ALPHABET, $c ) ), 5, '0', STR_PAD_LEFT );
		}
		return $bits;
	}

	/**
	 * A string of bits (a multiple of 5) to base32 characters.
	 *
	 * @param string $bits Bits.
	 * @return string
	 */
	private static function encode( $bits ) {
		$out = '';
		foreach ( str_split( $bits, 5 ) as $group ) {
			$out .= self::ALPHABET[ bindec( $group ) ];
		}
		return $out;
	}

	/**
	 * Bytes to a string of bits.
	 *
	 * @param string $bytes Raw bytes.
	 * @return string
	 */
	private static function to_bits( $bytes ) {
		$bits = '';
		foreach ( str_split( $bytes ) as $b ) {
			$bits .= str_pad( decbin( ord( $b ) ), 8, '0', STR_PAD_LEFT );
		}
		return $bits;
	}

	/**
	 * A string of bits (a multiple of 8) to bytes.
	 *
	 * @param string $bits Bits.
	 * @return string
	 */
	private static function bytes( $bits ) {
		$out = '';
		foreach ( str_split( $bits, 8 ) as $byte ) {
			$out .= chr( bindec( $byte ) );
		}
		return $out;
	}

	/**
	 * A short form for display: nano_3f…k9x.
	 *
	 * @param string $address The address.
	 * @return string
	 */
	public static function short( $address ) {
		return substr( $address, 0, 7 ) . '…' . substr( $address, -4 );
	}
}
