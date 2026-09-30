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
 * - a receipt, kept in an httpOnly cookie after a payment: this browser may
 *   see this item until this time.
 */
final class Nano_Unlock_Token {

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
	 * @param string $purpose "offer" or "receipt".
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
	 * @param string $purpose "offer" or "receipt".
	 * @param mixed  $token   The token.
	 * @return array|null
	 */
	public function verify( $purpose, $token ) {
		if ( ! is_string( $token ) || strlen( $token ) > 2048 || 1 !== substr_count( $token, '.' ) ) {
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
	 * A receipt for one item, valid for $seconds.
	 *
	 * @param string $item    The item key.
	 * @param string $buyer   The paying address.
	 * @param string $hash    The payment's block hash.
	 * @param int    $seconds Lifetime.
	 * @param int    $now     The time (for tests).
	 * @return string
	 */
	public function receipt( $item, $buyer, $hash, $seconds, $now = null ) {
		$now = null === $now ? time() : $now;
		return $this->sign(
			'receipt',
			array(
				'i' => $item,
				'b' => $buyer,
				'h' => $hash,
				'e' => $now + $seconds,
			)
		);
	}

	/**
	 * The claims of a receipt for $item that hasn't expired, or null.
	 *
	 * @param mixed  $token The token.
	 * @param string $item  The item key it must name.
	 * @param int    $now   The time (for tests).
	 * @return array|null
	 */
	public function check_receipt( $token, $item, $now = null ) {
		$now = null === $now ? time() : $now;
		$c   = $this->verify( 'receipt', $token );
		if ( ! $c || ! isset( $c['i'], $c['e'] ) || $c['i'] !== $item || ! is_int( $c['e'] ) || $c['e'] <= $now ) {
			return null;
		}
		return $c;
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
