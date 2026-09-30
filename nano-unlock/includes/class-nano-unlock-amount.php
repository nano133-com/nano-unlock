<?php
/**
 * Amounts in raw.
 *
 * @package NanoUnlock
 */

defined( 'ABSPATH' ) || exit;

/**
 * Nano amounts are counted in raw: 1 XNO is 10^30 raw, which no PHP integer
 * holds. Amounts stay decimal strings here, and the few operations the plugin
 * needs work on the digits, so no bcmath or gmp is required.
 *
 * Each checkout asks for a unique amount: the price, rounded up to 0.0001 XNO,
 * with a random tail in its last six digits (at most 0.000000000000000000000001 XNO).
 * The tail is how a payment is matched to its checkout without a memo field.
 */
final class Nano_Unlock_Amount {

	const RAW_DIGITS = 30;

	/**
	 * Sanity bounds for the XNO/USD rate: outside them, a price feed is wrong, not the market.
	 */
	const MIN_RATE = 0.05;
	const MAX_RATE = 100.0;

	/**
	 * The price of $usd dollars at $rate dollars per XNO, in raw, rounded up to 0.0001 XNO.
	 *
	 * @param float $usd  The price in dollars.
	 * @param float $rate Dollars per XNO.
	 * @return string|null Null when the price or the rate can't be used.
	 */
	public static function from_usd( $usd, $rate ) {
		if ( ! is_numeric( $usd ) || ! is_numeric( $rate ) || $usd <= 0 || $usd > 10000 || $rate < self::MIN_RATE || $rate > self::MAX_RATE ) {
			return null;
		}
		// A tiny allowance so float noise (0.01 / 0.5 * 10000 = 200.00000000000003) doesn't round up a whole step.
		$steps = (int) ceil( ( $usd / $rate ) * 10000 - 1e-6 );
		return (string) max( 1, $steps ) . str_repeat( '0', 26 );
	}

	/**
	 * $raw with its last six digits replaced by $tail (1 to 999999).
	 *
	 * @param string $raw  A price from from_usd() (it ends in 26 zeros).
	 * @param int    $tail The unique tail.
	 * @return string
	 */
	public static function with_tail( $raw, $tail ) {
		$tail = max( 1, min( 999999, (int) $tail ) );
		return substr( $raw, 0, -6 ) . str_pad( (string) $tail, 6, '0', STR_PAD_LEFT );
	}

	/**
	 * A random unique amount for a price.
	 *
	 * @param string $raw A price from from_usd().
	 * @return string
	 */
	public static function unique( $raw ) {
		return self::with_tail( $raw, random_int( 1, 999999 ) );
	}

	/**
	 * Whether $s is a raw amount (digits only, no leading zeros, not zero, at most 39 digits).
	 *
	 * @param mixed $s A value.
	 * @return bool
	 */
	public static function is_raw( $s ) {
		return is_string( $s ) && (bool) preg_match( '/^[1-9][0-9]{0,38}$/', $s );
	}

	/**
	 * Compares two raw amounts: -1, 0 or 1.
	 *
	 * @param string $a An amount.
	 * @param string $b An amount.
	 * @return int
	 */
	public static function compare( $a, $b ) {
		$a = ltrim( $a, '0' );
		$b = ltrim( $b, '0' );
		if ( strlen( $a ) !== strlen( $b ) ) {
			return strlen( $a ) < strlen( $b ) ? -1 : 1;
		}
		return max( -1, min( 1, strcmp( $a, $b ) ) );
	}

	/**
	 * A raw amount as XNO, all significant digits: "0.0123000000000000000000001234".
	 *
	 * @param string $raw An amount.
	 * @return string
	 */
	public static function to_xno( $raw ) {
		$raw   = str_pad( ltrim( $raw, '0' ), self::RAW_DIGITS + 1, '0', STR_PAD_LEFT );
		$whole = substr( $raw, 0, -self::RAW_DIGITS );
		$frac  = rtrim( substr( $raw, -self::RAW_DIGITS ), '0' );
		return '' === $frac ? $whole : $whole . '.' . $frac;
	}

	/**
	 * A raw amount as XNO for reading, to $decimals places, rounded up: "0.0124".
	 *
	 * @param string $raw      An amount.
	 * @param int    $decimals Places.
	 * @return string
	 */
	public static function to_xno_short( $raw, $decimals = 4 ) {
		$raw   = str_pad( ltrim( $raw, '0' ), self::RAW_DIGITS + 1, '0', STR_PAD_LEFT );
		$whole = substr( $raw, 0, -self::RAW_DIGITS );
		$frac  = substr( $raw, -self::RAW_DIGITS );
		$keep  = substr( $frac, 0, $decimals );
		if ( '' !== trim( substr( $frac, $decimals ), '0' ) ) {
			// Round up: the shown amount is never less than the asked one.
			$n     = (int) ( $whole . $keep ) + 1;
			$s     = str_pad( (string) $n, $decimals + 1, '0', STR_PAD_LEFT );
			$whole = substr( $s, 0, -$decimals );
			$keep  = substr( $s, -$decimals );
		}
		$keep = rtrim( $keep, '0' );
		return '' === $keep ? $whole : $whole . '.' . $keep;
	}
}
