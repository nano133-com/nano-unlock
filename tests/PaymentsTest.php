<?php
/**
 * Asking the node: an older payment of a repeated amount is read once, not on every poll.
 *
 * @package NanoUnlock
 */

use PHPUnit\Framework\TestCase;

final class PaymentsTest extends TestCase {

	const SITE  = 'nano_1cp9z9nwkzonezr97a8xfw79ahx6emdqhj8eu5j1gkmxhwy817dowa67xzoi';
	const BUYER = 'nano_3getnanons1aaqo5itbm8wdbzhtsp7tctd6p6qa7axwff7ocemzs3w381kfy';

	/**
	 * The node's blocks: hash => local_timestamp.
	 *
	 * @var int[]
	 */
	private $blocks = array();

	/**
	 * RPC calls, per action.
	 *
	 * @var int[]
	 */
	private $calls = array();

	protected function setUp(): void {
		nano_unlock_wp_reset();
		$GLOBALS['nano_unlock_wp']['options']['nano_unlock_settings'] = array( 'address' => self::SITE );
		$amount                             = '2' . str_repeat( '0', 28 );
		$GLOBALS['nano_unlock_wp']['node'] = function ( $body ) use ( $amount ) {
			$this->calls[ $body['action'] ] = ( isset( $this->calls[ $body['action'] ] ) ? $this->calls[ $body['action'] ] : 0 ) + 1;
			switch ( $body['action'] ) {
				case 'receivable':
					$out = array();
					foreach ( array_keys( $this->blocks ) as $hash ) {
						$out[ $hash ] = array(
							'amount' => $amount,
							'source' => self::BUYER,
						);
					}
					return array( 'blocks' => $out );
				case 'account_history':
					return array( 'history' => array() );
				case 'block_info':
					return array(
						'block_account'   => self::BUYER,
						'amount'          => $amount,
						'local_timestamp' => (string) $this->blocks[ $body['hash'] ],
						'confirmed'       => 'true',
						'subtype'         => 'send',
						'contents'        => array(
							'link_as_account' => self::SITE,
							'subtype'         => 'send',
						),
					);
			}
			return array( 'error' => 'unknown' );
		};
	}

	public function test_stray_payments_of_the_amount_cost_one_call_each_not_one_per_poll() {
		// Ten older sends of 0.02 XNO that paid nothing (second sends, sends after Cancel), an hour old.
		for ( $i = 1; $i <= 10; $i++ ) {
			$this->blocks[ strtoupper( str_repeat( dechex( $i ), 64 ) ) ] = time() - 3600;
		}
		$checkout = array(
			'address'    => self::SITE,
			'amount'     => '2' . str_repeat( '0', 28 ),
			'created_at' => time(),
		);
		for ( $poll = 0; $poll < 5; $poll++ ) {
			$this->assertSame( array( 'status' => 'waiting' ), Nano_Unlock_Payments::check( $checkout, true ) );
		}
		$this->assertSame( 5, $this->calls['receivable'] );
		$this->assertSame( 10, $this->calls['block_info'], 'each stray is read once (it was 50: one per stray per poll)' );

		// The reader's own payment of that amount still unlocks.
		$this->blocks[ str_repeat( 'F', 64 ) ] = time();
		$found                                 = Nano_Unlock_Payments::check( $checkout, true );
		$this->assertSame( 'paid', $found['status'] );
		$this->assertSame( str_repeat( 'F', 64 ), $found['hash'] );
		$this->assertSame( 11, $this->calls['block_info'] );
	}

	public function test_an_unconfirmed_block_is_asked_again() {
		$this->blocks[ str_repeat( 'A', 64 ) ] = time();
		$confirmed                             = false;
		$node                                  = $GLOBALS['nano_unlock_wp']['node'];
		$GLOBALS['nano_unlock_wp']['node']     = function ( $body ) use ( $node, &$confirmed ) {
			$answer = $node( $body );
			if ( 'block_info' === $body['action'] ) {
				$answer['confirmed'] = $confirmed ? 'true' : 'false';
			}
			return $answer;
		};
		$checkout = array(
			'address'    => self::SITE,
			'amount'     => '2' . str_repeat( '0', 28 ),
			'created_at' => time(),
		);
		$this->assertSame( array( 'status' => 'pending' ), Nano_Unlock_Payments::check( $checkout, true ) );
		$confirmed = true;
		$this->assertSame( 'paid', Nano_Unlock_Payments::check( $checkout, true )['status'], 'a block seen unconfirmed is not kept' );
	}
}
