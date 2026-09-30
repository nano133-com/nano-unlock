<?php
/**
 * Reading the node's answers.
 *
 * @package NanoUnlock
 */

defined( 'ABSPATH' ) || exit;

/**
 * The rules that decide whether a payment arrived, kept apart from the
 * network so they can be tested alone.
 *
 * A payment to the site's address can be in two places: still receivable
 * (no wallet has received it yet), or already received (the site owner's
 * wallet took it, and it shows in the address's history). Both are searched
 * for the checkout's exact amount. A match is only a candidate: the send block
 * itself is then read, and it counts only when it is a confirmed send of that
 * exact amount to that address, first seen after the checkout started.
 */
final class Nano_Unlock_Verifier {

	/**
	 * Allowed clock difference between this server and the node, in seconds.
	 */
	const CLOCK_SLACK = 120;

	/**
	 * The send hashes in a scan that paid exactly $amount.
	 *
	 * @param array  $receivable The node's answer to `receivable` (with source), or an empty array.
	 * @param array  $history    The node's answer to `account_history` (raw), or an empty array.
	 * @param string $amount     The checkout's amount in raw.
	 * @return string[] Upper-case hashes.
	 */
	public static function candidates( array $receivable, array $history, $amount ) {
		$found = array();
		if ( isset( $receivable['blocks'] ) && is_array( $receivable['blocks'] ) ) {
			foreach ( $receivable['blocks'] as $hash => $entry ) {
				$paid = is_array( $entry ) ? ( isset( $entry['amount'] ) ? $entry['amount'] : '' ) : $entry;
				if ( self::is_hash( $hash ) && is_string( $paid ) && $paid === $amount ) {
					$found[] = strtoupper( $hash );
				}
			}
		}
		if ( isset( $history['history'] ) && is_array( $history['history'] ) ) {
			foreach ( $history['history'] as $h ) {
				$kind = isset( $h['subtype'] ) ? $h['subtype'] : ( isset( $h['type'] ) ? $h['type'] : '' );
				if ( ! in_array( $kind, array( 'receive', 'open' ), true ) ) {
					continue;
				}
				// In raw history, a receive's link is the hash of the send it received.
				if ( isset( $h['amount'], $h['link'] ) && $h['amount'] === $amount && self::is_hash( $h['link'] ) ) {
					$found[] = strtoupper( $h['link'] );
				}
			}
		}
		return array_values( array_unique( $found ) );
	}

	/**
	 * Whether a send block (the node's `block_info` answer) is this checkout's payment.
	 *
	 * @param array  $info       The block_info answer (json_block).
	 * @param string $address    The site's address.
	 * @param string $amount     The checkout's amount in raw.
	 * @param int    $not_before The checkout's start (Unix time).
	 * @return string "paid", "pending" (right block, not confirmed yet), "early" (sent before the checkout) or "wrong".
	 */
	public static function check_send( array $info, $address, $amount, $not_before ) {
		$contents = isset( $info['contents'] ) && is_array( $info['contents'] ) ? $info['contents'] : array();
		$subtype  = isset( $info['subtype'] ) ? $info['subtype'] : ( isset( $contents['subtype'] ) ? $contents['subtype'] : '' );
		$to       = isset( $contents['link_as_account'] ) ? self::nano_prefix( $contents['link_as_account'] ) : '';
		if ( 'send' !== $subtype || $to !== $address || ! isset( $info['amount'] ) || $info['amount'] !== $amount ) {
			return 'wrong';
		}
		if ( isset( $info['local_timestamp'] ) && is_numeric( $info['local_timestamp'] ) && (int) $info['local_timestamp'] > 0 && (int) $info['local_timestamp'] < $not_before - self::CLOCK_SLACK ) {
			return 'early';
		}
		if ( ! isset( $info['confirmed'] ) || 'true' !== $info['confirmed'] ) {
			return 'pending';
		}
		return 'paid';
	}

	/**
	 * The paying account of a block_info answer.
	 *
	 * @param array $info The block_info answer.
	 * @return string
	 */
	public static function payer( array $info ) {
		return isset( $info['block_account'] ) ? self::nano_prefix( (string) $info['block_account'] ) : '';
	}

	/**
	 * Whether $s is a 64-hex block hash.
	 *
	 * @param mixed $s A value.
	 * @return bool
	 */
	public static function is_hash( $s ) {
		return is_string( $s ) && (bool) preg_match( '/^[0-9A-Fa-f]{64}$/', $s );
	}

	/**
	 * Old nodes and wallets write xrb_; the account is the same.
	 *
	 * @param string $a An account.
	 * @return string
	 */
	private static function nano_prefix( $a ) {
		return 0 === strpos( $a, 'xrb_' ) ? 'nano_' . substr( $a, 4 ) : $a;
	}
}
