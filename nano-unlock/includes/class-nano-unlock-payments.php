<?php
/**
 * Finding a checkout's payment.
 *
 * @package NanoUnlock
 */

defined( 'ABSPATH' ) || exit;

/**
 * Asks the node(s) whether a checkout was paid.
 *
 * Node load stays flat however many readers wait: the address scan
 * (`receivable` + `account_history`) is shared by every open checkout and
 * cached for SCAN_SECONDS, so a site makes about two scan calls every few
 * seconds while anyone is paying, and none when nobody is. Only a matching
 * amount leads to a `block_info` call.
 */
final class Nano_Unlock_Payments {

	const SCAN_SECONDS = 3;

	/**
	 * Whether the checkout is paid.
	 *
	 * @param array $checkout The checkout row.
	 * @return array{status:string, hash?:string, payer?:string} status "paid", "pending" or "waiting".
	 * @throws Nano_Unlock_Busy When a node can't answer.
	 */
	public static function check( array $checkout ) {
		$settings = Nano_Unlock_Settings::get();
		$node     = new Nano_Unlock_Node( $settings['node'] );
		$second   = '' !== $settings['node2'] ? new Nano_Unlock_Node( $settings['node2'] ) : null;
		$address  = $checkout['address'];
		$amount   = $checkout['amount'];

		$scan       = self::scan( $node, $address );
		$candidates = Nano_Unlock_Verifier::candidates( $scan['receivable'], $scan['history'], $amount );
		$status     = array( 'status' => 'waiting' );
		foreach ( $candidates as $hash ) {
			$info = $node->call(
				array(
					'action'     => 'block_info',
					'hash'       => $hash,
					'json_block' => 'true',
				)
			);
			if ( ! $info ) {
				continue;
			}
			$verdict = Nano_Unlock_Verifier::check_send( $info, $address, $amount, (int) $checkout['created_at'] );
			if ( 'pending' === $verdict ) {
				$status = array( 'status' => 'pending' );
			}
			if ( 'paid' !== $verdict ) {
				continue;
			}
			if ( $second ) {
				// With a second node, both must confirm the same send; either one alone proves nothing.
				$info2 = $second->call(
					array(
						'action'     => 'block_info',
						'hash'       => $hash,
						'json_block' => 'true',
					)
				);
				if ( ! $info2 || 'paid' !== Nano_Unlock_Verifier::check_send( $info2, $address, $amount, (int) $checkout['created_at'] ) || Nano_Unlock_Verifier::payer( $info2 ) !== Nano_Unlock_Verifier::payer( $info ) ) {
					$status = array( 'status' => 'pending' );
					continue;
				}
			}
			return array(
				'status' => 'paid',
				'hash'   => $hash,
				'payer'  => Nano_Unlock_Verifier::payer( $info ),
			);
		}
		return $status;
	}

	/**
	 * The address's receivable sends and recent receives, shared for SCAN_SECONDS.
	 *
	 * @param Nano_Unlock_Node $node    The node.
	 * @param string           $address The site's address.
	 * @return array{receivable:array, history:array}
	 * @throws Nano_Unlock_Busy When the node can't answer.
	 */
	private static function scan( Nano_Unlock_Node $node, $address ) {
		// Only amounts an open checkout can ask for: dust sent to the address can't push a payment out of the list.
		$threshold = Nano_Unlock_Store::smallest_open_amount( Nano_Unlock::LATE_SECONDS );
		$threshold = null === $threshold ? '1' : $threshold;
		$key       = 'nano_unlock_scan_' . substr( hash( 'sha256', $node->url() . '|' . $address . '|' . $threshold ), 0, 24 );
		$cached    = get_transient( $key );
		if ( is_array( $cached ) && isset( $cached['at'] ) && microtime( true ) - $cached['at'] < self::SCAN_SECONDS ) {
			return $cached;
		}
		$receivable = $node->call(
			array(
				'action'    => 'receivable',
				'account'   => $address,
				'count'     => '100',
				'threshold' => $threshold,
				'source'    => 'true',
			)
		);
		// A new address has no history yet: the node answers "Account not found" (null here).
		$history = $node->call(
			array(
				'action'  => 'account_history',
				'account' => $address,
				'count'   => '50',
				'raw'     => 'true',
			)
		);
		$scan    = array(
			'at'         => microtime( true ),
			'receivable' => is_array( $receivable ) ? $receivable : array(),
			'history'    => is_array( $history ) ? self::slim_history( $history ) : array(),
		);
		set_transient( $key, $scan, 60 );
		return $scan;
	}

	/**
	 * Keeps only what matching needs from a history answer.
	 *
	 * @param array $history The account_history answer.
	 * @return array
	 */
	private static function slim_history( array $history ) {
		$out = array();
		if ( isset( $history['history'] ) && is_array( $history['history'] ) ) {
			foreach ( $history['history'] as $h ) {
				$out[] = array_intersect_key( (array) $h, array_flip( array( 'type', 'subtype', 'amount', 'link' ) ) );
			}
		}
		return array( 'history' => $out );
	}
}
