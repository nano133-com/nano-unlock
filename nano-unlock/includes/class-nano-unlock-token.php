<?php
/**
 * Signed tokens: receipts and offers.
 *
 * @package NanoUnlock
 */

defined( 'ABSPATH' ) || exit;

/**
 * A small signed token: base64url(JSON claims) "." base64url(HMAC-SHA256).
 *
 * The key is the site's own secret, made at activation and kept in the
 * options table; it never leaves the server. Each kind of token signs with
 * its own purpose in the MAC, so an offer can never pass as a receipt.
 *
 * Two kinds:
 *
 * - an offer, printed in the page next to a locked item: which post, which
 *   item and what price. The browser sends it back to start a checkout, so a
 *   reader can't change the price or ask for an item that isn't there.
 * - the receipts, kept in one httpOnly cookie after payments: for each item
 *   this browser bought, the checkout that paid for it and until when it may
 *   be seen. The payer and the payment's hash stay on the server, in the
 *   checkout's row, so each entry is small; the cookie keeps the newest
 *   entries that fit in RECEIPTS_MAX_BYTES, so many purchases never grow the
 *   request's Cookie header past what servers accept.
 */
final class Nano_Unlock_Token {

	/**
	 * The most a receipts token may weigh, in bytes. A browser keeps a cookie of up to
	 * 4096 bytes, and servers refuse a Cookie header past about 8 KB in all.
	 */
	const RECEIPTS_MAX_BYTES = 2800;

	/**
	 * The signing key (raw bytes).
	 *
	 * @var string
	 */
	private $key;

	/**
	 * Constructor.
	 *
	 * @param string $key The signing key, at least 32 bytes.
	 * @throws InvalidArgumentException When the key is too short.
	 */
	public function __construct( $key ) {
		if ( ! is_string( $key ) || strlen( $key ) < 32 ) {
			throw new InvalidArgumentException( 'The signing key must be at least 32 bytes.' );
		}
		$this->key = $key;
	}

	/**
	 * Signs claims for a purpose.
	 *
	 * @param string $purpose "offer" or "receipts".
	 * @param array  $claims  The claims.
	 * @return string
	 */
	public function sign( $purpose, array $claims ) {
		$body = self::b64( (string) wp_json_encode( $claims ) );
		return $body . '.' . self::b64( hash_hmac( 'sha256', $purpose . '|' . $body, $this->key, true ) );
	}

	/**
	 * The claims of a token signed for $purpose, or null.
	 *
	 * @param string $purpose "offer" or "receipts".
	 * @param mixed  $token   The token.
	 * @return array|null
	 */
	public function verify( $purpose, $token ) {
		if ( ! is_string( $token ) || strlen( $token ) > 4096 || 1 !== substr_count( $token, '.' ) ) {
			return null;
		}
		list( $body, $mac ) = explode( '.', $token );
		$expected           = self::b64( hash_hmac( 'sha256', $purpose . '|' . $body, $this->key, true ) );
		if ( ! hash_equals( $expected, $mac ) ) {
			return null;
		}
		$claims = json_decode( (string) self::unb64( $body ), true );
		return is_array( $claims ) ? $claims : null;
	}

	/**
	 * The receipts token with one more receipt: $item, paid by checkout $checkout, valid for $seconds.
	 *
	 * Expired receipts are dropped, a receipt for the same item is replaced,
	 * and the oldest receipts are dropped until the token fits RECEIPTS_MAX_BYTES.
	 *
	 * @param mixed  $token    The current receipts token (anything invalid counts as none).
	 * @param string $item     The item key.
	 * @param string $checkout The checkout's id.
	 * @param int    $seconds  Lifetime.
	 * @param int    $now      The time (for tests).
	 * @return string
	 */
	public function add_receipt( $token, $item, $checkout, $seconds, $now = null ) {
		$now     = null === $now ? time() : $now;
		$entries = array();
		foreach ( $this->receipts( $token, $now ) as $key => $entry ) {
			if ( $key !== $item ) {
				$entries[] = array( $key, $entry['e'], $entry['c'] );
			}
		}
		$entries[] = array( (string) $item, $now + (int) $seconds, (string) $checkout );
		usort(
			$entries,
			function ( $a, $b ) {
				return $a[1] - $b[1];
			}
		);
		for ( $left = count( $entries ); $left > 0; $left-- ) {
			$signed = $this->sign( 'receipts', array( 'r' => $entries ) );
			$fits   = strlen( $signed ) <= self::RECEIPTS_MAX_BYTES;
			if ( $fits || 1 === $left ) {
				return $signed;
			}
			array_shift( $entries );
		}
		return $this->sign( 'receipts', array( 'r' => $entries ) );
	}

	/**
	 * The receipts in a token that haven't expired: item => array( 'c' => checkout id, 'e' => expiry ).
	 *
	 * @param mixed $token The receipts token.
	 * @param int   $now   The time (for tests).
	 * @return array<string, array{c:string, e:int}>
	 */
	public function receipts( $token, $now = null ) {
		$now    = null === $now ? time() : $now;
		$claims = $this->verify( 'receipts', $token );
		$out    = array();
		if ( ! $claims || ! isset( $claims['r'] ) || ! is_array( $claims['r'] ) ) {
			return $out;
		}
		foreach ( $claims['r'] as $entry ) {
			if ( is_array( $entry ) && 3 === count( $entry ) && is_string( $entry[0] ) && is_int( $entry[1] ) && is_string( $entry[2] ) && $entry[1] > $now ) {
				$out[ $entry[0] ] = array(
					'c' => $entry[2],
					'e' => $entry[1],
				);
			}
		}
		return $out;
	}

	/**
	 * The receipt for $item in a receipts token, if it hasn't expired: array( 'i' => item, 'c' => checkout id, 'e' => expiry ), or null.
	 *
	 * @param mixed  $token The receipts token.
	 * @param string $item  The item key.
	 * @param int    $now   The time (for tests).
	 * @return array|null
	 */
	public function check_receipt( $token, $item, $now = null ) {
		$receipts = $this->receipts( $token, $now );
		if ( ! isset( $receipts[ $item ] ) ) {
			return null;
		}
		return array( 'i' => $item ) + $receipts[ $item ];
	}

	/**
	 * Base64url without padding.
	 *
	 * @param string $bytes Bytes.
	 * @return string
	 */
	public static function b64( $bytes ) {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- a token format, not obfuscation.
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
	}

	/**
	 * Decodes base64url.
	 *
	 * @param string $text Text.
	 * @return string|false
	 */
	public static function unb64( $text ) {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- a token format, not obfuscation.
		return base64_decode( strtr( $text, '-_', '+/' ), true );
	}
}
