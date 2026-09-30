<?php
/**
 * Reading the node's answers.
 *
 * @package NanoUnlock
 */

use PHPUnit\Framework\TestCase;

final class VerifierTest extends TestCase {

	const SITE   = 'nano_1cp9z9nwkzonezr97a8xfw79ahx6emdqhj8eu5j1gkmxhwy817dowa67xzoi';
	const BUYER  = 'nano_3getnanons1aaqo5itbm8wdbzhtsp7tctd6p6qa7axwff7ocemzs3w381kfy';
	const AMOUNT = '20000000000000000000000123456';

	private static function hash( $n ) {
		return strtoupper( str_repeat( dechex( $n ), 64 ) );
	}

	public function test_candidates_from_receivable_with_and_without_source() {
		$with    = array(
			'blocks' => array(
				self::hash( 1 ) => array(
					'amount' => self::AMOUNT,
					'source' => self::BUYER,
				),
				self::hash( 2 ) => array(
					'amount' => '20000000000000000000000123457',
					'source' => self::BUYER,
				),
			),
		);
		$without = array( 'blocks' => array( strtolower( self::hash( 3 ) ) => self::AMOUNT ) );
		$this->assertSame( array( self::hash( 1 ) ), Nano_Unlock_Verifier::candidates( $with, array(), self::AMOUNT ) );
		$this->assertSame( array( self::hash( 3 ) ), Nano_Unlock_Verifier::candidates( $without, array(), self::AMOUNT ) );
		$this->assertSame( array(), Nano_Unlock_Verifier::candidates( array( 'blocks' => '' ), array(), self::AMOUNT ) );
	}

	public function test_candidates_from_history_use_the_received_send() {
		$history = array(
			'history' => array(
				array( 'subtype' => 'receive', 'amount' => self::AMOUNT, 'link' => self::hash( 4 ) ),
				array( 'subtype' => 'open', 'amount' => self::AMOUNT, 'link' => self::hash( 5 ) ),
				// A send FROM the site of the same amount is not a payment to it.
				array( 'subtype' => 'send', 'amount' => self::AMOUNT, 'link' => self::hash( 6 ) ),
				array( 'subtype' => 'receive', 'amount' => '1', 'link' => self::hash( 7 ) ),
				array( 'subtype' => 'receive', 'amount' => self::AMOUNT, 'link' => 'not a hash' ),
			),
		);
		$this->assertSame( array( self::hash( 4 ), self::hash( 5 ) ), Nano_Unlock_Verifier::candidates( array(), $history, self::AMOUNT ) );
	}

	public function test_candidates_are_unique() {
		$r = array( 'blocks' => array( self::hash( 1 ) => self::AMOUNT ) );
		$h = array( 'history' => array( array( 'subtype' => 'receive', 'amount' => self::AMOUNT, 'link' => self::hash( 1 ) ) ) );
		$this->assertSame( array( self::hash( 1 ) ), Nano_Unlock_Verifier::candidates( $r, $h, self::AMOUNT ) );
	}

	private static function send( array $change = array() ) {
		return array_replace_recursive(
			array(
				'block_account'   => self::BUYER,
				'amount'          => self::AMOUNT,
				'local_timestamp' => '1790000100',
				'confirmed'       => 'true',
				'subtype'         => 'send',
				'contents'        => array(
					'link_as_account' => self::SITE,
					'subtype'         => 'send',
				),
			),
			$change
		);
	}

	public function test_check_send() {
		$start = 1790000000;
		$this->assertSame( 'paid', Nano_Unlock_Verifier::check_send( self::send(), self::SITE, self::AMOUNT, $start ) );
		$this->assertSame( 'pending', Nano_Unlock_Verifier::check_send( self::send( array( 'confirmed' => 'false' ) ), self::SITE, self::AMOUNT, $start ) );
		$this->assertSame( 'wrong', Nano_Unlock_Verifier::check_send( self::send( array( 'amount' => '1' ) ), self::SITE, self::AMOUNT, $start ) );
		$this->assertSame( 'wrong', Nano_Unlock_Verifier::check_send( self::send( array( 'contents' => array( 'link_as_account' => self::BUYER ) ) ), self::SITE, self::AMOUNT, $start ) );
		$this->assertSame( 'wrong', Nano_Unlock_Verifier::check_send( self::send( array( 'subtype' => 'receive' ) ), self::SITE, self::AMOUNT, $start ) );
		$this->assertSame( 'wrong', Nano_Unlock_Verifier::check_send( array(), self::SITE, self::AMOUNT, $start ) );
	}

	public function test_a_payment_from_before_the_checkout_does_not_count() {
		$start = 1790000000;
		$old   = self::send( array( 'local_timestamp' => (string) ( $start - 3600 ) ) );
		$this->assertSame( 'early', Nano_Unlock_Verifier::check_send( $old, self::SITE, self::AMOUNT, $start ) );
		// Within the clock allowance it still counts.
		$close = self::send( array( 'local_timestamp' => (string) ( $start - 60 ) ) );
		$this->assertSame( 'paid', Nano_Unlock_Verifier::check_send( $close, self::SITE, self::AMOUNT, $start ) );
	}

	public function test_old_prefix_is_the_same_account() {
		$info = self::send( array( 'contents' => array( 'link_as_account' => 'xrb_' . substr( self::SITE, 5 ) ) ) );
		$this->assertSame( 'paid', Nano_Unlock_Verifier::check_send( $info, self::SITE, self::AMOUNT, 0 ) );
		$this->assertSame( self::BUYER, Nano_Unlock_Verifier::payer( self::send( array( 'block_account' => 'xrb_' . substr( self::BUYER, 5 ) ) ) ) );
	}
}
