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
 * Each checkout asks for a clean amount: the price, rounded up to a step of
 * 0.0001 XNO. While another open checkout holds that exact amount, the next one
 * asks for one step more, and so on (Nano_Unlock_Store::create). So an amount
 * has at most four decimals, and a wallet that rounds to four decimals still
 * sends it exactly.
 */
final class Nano_Unlock_Amount {

	const RAW_DIGITS = 30;

	/**
	 * A step is 0.0001 XNO: 10^26 raw.
	 */
	const STEP_DIGITS = 26;

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
		return self::from_steps( max( 1, $steps ) );
	}

	/**
	 * A number of 0.0001 XNO steps, in raw.
	 *
	 * @param int $steps Steps (at most 2 × 10^9 for the largest price at the lowest rate).
	 * @return string
	 */
	public static function from_steps( $steps ) {
		return (string) (int) $steps . str_repeat( '0', self::STEP_DIGITS );
	}

	/**
	 * A clean amount (whole steps of 0.0001 XNO) plus $more steps.
	 *
	 * @param string $raw  A clean amount, from from_usd().
	 * @param int    $more Steps to add.
	 * @return string
	 */
	public static function plus_steps( $raw, $more ) {
		return self::from_steps( (int) substr( $raw, 0, -self::STEP_DIGITS ) + (int) $more );
	}

	/**
	 * Whether $raw is a clean amount: whole steps of 0.0001 XNO, so at most four decimals.
	 *
	 * @param mixed $raw A value.
	 * @return bool
	 */
	public static function is_clean( $raw ) {
		return self::is_raw( $raw ) && strlen( $raw ) > self::STEP_DIGITS && str_repeat( '0', self::STEP_DIGITS ) === substr( $raw, -self::STEP_DIGITS );
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
	 * A raw amount as XNO, all significant digits: "0.1512" (and every digit of an older amount with a tail).
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
