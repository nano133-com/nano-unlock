<?php
/**
 * BLAKE2b in plain PHP.
 *
 * A Nano address ends in a 5-byte BLAKE2b checksum of its public key. The
 * sodium extension only makes digests of 16 bytes or more, and a BLAKE2b
 * digest of 5 bytes is not the first 5 bytes of a longer one (the length is
 * part of the parameters), so this small implementation makes it. It is only
 * used on short inputs, so it favours clarity over speed.
 *
 * Each 64-bit word is held as two 32-bit halves, so the code runs the same on
 * every PHP build and never overflows into floats.
 *
 * @package NanoUnlock
 */

defined( 'ABSPATH' ) || exit;

/**
 * BLAKE2b (RFC 7693), unkeyed, any digest length from 1 to 64 bytes.
 */
final class Nano_Unlock_Blake2b {

	const MASK = 0xffffffff;

	/**
	 * The initialization vector, as [high, low] halves.
	 *
	 * @var int[][]
	 */
	const IV = array(
		array( 0x6a09e667, 0xf3bcc908 ),
		array( 0xbb67ae85, 0x84caa73b ),
		array( 0x3c6ef372, 0xfe94f82b ),
		array( 0xa54ff53a, 0x5f1d36f1 ),
		array( 0x510e527f, 0xade682d1 ),
		array( 0x9b05688c, 0x2b3e6c1f ),
		array( 0x1f83d9ab, 0xfb41bd6b ),
		array( 0x5be0cd19, 0x137e2179 ),
	);

	/**
	 * The message schedule for the 12 rounds.
	 *
	 * @var int[][]
	 */
	const SIGMA = array(
		array( 0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15 ),
		array( 14, 10, 4, 8, 9, 15, 13, 6, 1, 12, 0, 2, 11, 7, 5, 3 ),
		array( 11, 8, 12, 0, 5, 2, 15, 13, 10, 14, 3, 6, 7, 1, 9, 4 ),
		array( 7, 9, 3, 1, 13, 12, 11, 14, 2, 6, 5, 10, 4, 0, 15, 8 ),
		array( 9, 0, 5, 7, 2, 4, 10, 15, 14, 1, 11, 12, 6, 8, 3, 13 ),
		array( 2, 12, 6, 10, 0, 11, 8, 3, 4, 13, 7, 5, 15, 14, 1, 9 ),
		array( 12, 5, 1, 15, 14, 13, 4, 10, 0, 7, 6, 3, 9, 2, 8, 11 ),
		array( 13, 11, 7, 14, 12, 1, 3, 9, 5, 0, 15, 4, 8, 6, 2, 10 ),
		array( 6, 15, 14, 9, 11, 3, 0, 8, 12, 2, 13, 7, 1, 4, 10, 5 ),
		array( 10, 2, 8, 4, 7, 6, 1, 5, 15, 11, 9, 14, 3, 12, 13, 0 ),
		array( 0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15 ),
		array( 14, 10, 4, 8, 9, 15, 13, 6, 1, 12, 0, 2, 11, 7, 5, 3 ),
	);

	/**
	 * The digest of $data, $length bytes long (raw bytes).
	 *
	 * @param string $data   The message.
	 * @param int    $length The digest length in bytes, 1 to 64.
	 * @return string
	 * @throws InvalidArgumentException When the length is out of range.
	 */
	public static function hash( $data, $length = 64 ) {
		if ( $length < 1 || $length > 64 ) {
			throw new InvalidArgumentException( 'BLAKE2b digests are 1 to 64 bytes long.' );
		}
		$h       = self::IV;
		$h[0][1] = $h[0][1] ^ ( 0x01010000 | $length );

		$total  = strlen( $data );
		$blocks = max( 1, (int) ceil( $total / 128 ) );
		for ( $i = 0; $i < $blocks; $i++ ) {
			$last  = ( $i === $blocks - 1 );
			$chunk = str_pad( (string) substr( $data, $i * 128, 128 ), 128, "\0" );
			$count = $last ? $total : ( $i + 1 ) * 128;
			$h     = self::compress( $h, $chunk, $count, $last );
		}

		$out = '';
		foreach ( $h as $word ) {
			$out .= pack( 'V2', $word[1], $word[0] );
		}
		return substr( $out, 0, $length );
	}

	/**
	 * One compression of a 128-byte block.
	 *
	 * @param int[][] $h     The state.
	 * @param string  $chunk The block.
	 * @param int     $count Bytes hashed so far, including this block.
	 * @param bool    $last  Whether this is the final block.
	 * @return int[][]
	 */
	private static function compress( array $h, $chunk, $count, $last ) {
		$words = array_values( unpack( 'V32', $chunk ) );
		$m     = array();
		for ( $i = 0; $i < 16; $i++ ) {
			$m[] = array( $words[ 2 * $i + 1 ], $words[ 2 * $i ] );
		}

		$v     = array_merge( $h, self::IV );
		$v[12] = self::xor64( $v[12], array( (int) floor( $count / 4294967296 ) & self::MASK, $count & self::MASK ) );
		if ( $last ) {
			$v[14] = self::xor64( $v[14], array( self::MASK, self::MASK ) );
		}

		foreach ( self::SIGMA as $s ) {
			self::mix( $v, 0, 4, 8, 12, $m[ $s[0] ], $m[ $s[1] ] );
			self::mix( $v, 1, 5, 9, 13, $m[ $s[2] ], $m[ $s[3] ] );
			self::mix( $v, 2, 6, 10, 14, $m[ $s[4] ], $m[ $s[5] ] );
			self::mix( $v, 3, 7, 11, 15, $m[ $s[6] ], $m[ $s[7] ] );
			self::mix( $v, 0, 5, 10, 15, $m[ $s[8] ], $m[ $s[9] ] );
			self::mix( $v, 1, 6, 11, 12, $m[ $s[10] ], $m[ $s[11] ] );
			self::mix( $v, 2, 7, 8, 13, $m[ $s[12] ], $m[ $s[13] ] );
			self::mix( $v, 3, 4, 9, 14, $m[ $s[14] ], $m[ $s[15] ] );
		}

		for ( $i = 0; $i < 8; $i++ ) {
			$h[ $i ] = self::xor64( self::xor64( $h[ $i ], $v[ $i ] ), $v[ $i + 8 ] );
		}
		return $h;
	}

	/**
	 * The G function.
	 *
	 * @param int[][] $v The working vector (changed in place).
	 * @param int     $a Index.
	 * @param int     $b Index.
	 * @param int     $c Index.
	 * @param int     $d Index.
	 * @param int[]   $x A message word.
	 * @param int[]   $y A message word.
	 */
	private static function mix( array &$v, $a, $b, $c, $d, array $x, array $y ) {
		$v[ $a ] = self::add64( self::add64( $v[ $a ], $v[ $b ] ), $x );
		$v[ $d ] = self::rotr64( self::xor64( $v[ $d ], $v[ $a ] ), 32 );
		$v[ $c ] = self::add64( $v[ $c ], $v[ $d ] );
		$v[ $b ] = self::rotr64( self::xor64( $v[ $b ], $v[ $c ] ), 24 );
		$v[ $a ] = self::add64( self::add64( $v[ $a ], $v[ $b ] ), $y );
		$v[ $d ] = self::rotr64( self::xor64( $v[ $d ], $v[ $a ] ), 16 );
		$v[ $c ] = self::add64( $v[ $c ], $v[ $d ] );
		$v[ $b ] = self::rotr64( self::xor64( $v[ $b ], $v[ $c ] ), 63 );
	}

	/**
	 * Addition modulo 2^64.
	 *
	 * @param int[] $a A word.
	 * @param int[] $b A word.
	 * @return int[]
	 */
	private static function add64( array $a, array $b ) {
		$lo = $a[1] + $b[1];
		$hi = ( $a[0] + $b[0] + ( $lo >> 32 ) ) & self::MASK;
		return array( $hi, $lo & self::MASK );
	}

	/**
	 * Exclusive or.
	 *
	 * @param int[] $a A word.
	 * @param int[] $b A word.
	 * @return int[]
	 */
	private static function xor64( array $a, array $b ) {
		return array( $a[0] ^ $b[0], $a[1] ^ $b[1] );
	}

	/**
	 * Rotation to the right by $n bits (1 to 63).
	 *
	 * @param int[] $w A word.
	 * @param int   $n Bits.
	 * @return int[]
	 */
	private static function rotr64( array $w, $n ) {
		list( $hi, $lo ) = $w;
		if ( $n >= 32 ) {
			list( $hi, $lo ) = array( $lo, $hi );
			$n              -= 32;
		}
		if ( 0 === $n ) {
			return array( $hi, $lo );
		}
		return array(
			( ( $hi >> $n ) | ( $lo << ( 32 - $n ) ) ) & self::MASK,
			( ( $lo >> $n ) | ( $hi << ( 32 - $n ) ) ) & self::MASK,
		);
	}
}
