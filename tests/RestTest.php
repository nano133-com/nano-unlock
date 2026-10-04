<?php
/**
 * The reader's REST routes: a checkout, and claiming its payment.
 *
 * @package NanoUnlock
 */

use PHPUnit\Framework\TestCase;

final class RestTest extends TestCase {

	const POST  = 42;
	const SITE  = 'nano_1cp9z9nwkzonezr97a8xfw79ahx6emdqhj8eu5j1gkmxhwy817dowa67xzoi';
	const BUYER = 'nano_3getnanons1aaqo5itbm8wdbzhtsp7tctd6p6qa7axwff7ocemzs3w381kfy';
	const HASH  = 'ABCDEF0123456789ABCDEF0123456789ABCDEF0123456789ABCDEF0123456789';

	/**
	 * Cookies the browser accepts (false: it refuses them, like a server whose headers are already sent).
	 *
	 * @var bool
	 */
	private $cookies_work = true;

	protected function setUp(): void {
		nano_unlock_wp_reset();
		nano_unlock_wp_post( self::POST );
		$GLOBALS['nano_unlock_wp']['options']['nano_unlock_settings'] = array( 'address' => self::SITE );
		$GLOBALS['nano_unlock_wp']['transients']['nano_unlock_rate']  = 0.5;
		$this->cookies_work = true;
		add_filter(
			'nano_unlock_pre_set_cookie',
			function () {
				return $this->cookies_work;
			}
		);
	}

	/**
	 * Starts a checkout for the post's first paid part at $0.01.
	 *
	 * @return array The checkout's answer.
	 */
	private function checkout() {
		$offer = Nano_Unlock::tokens()->sign(
			'offer',
			array(
				'p' => self::POST,
				's' => '0',
				'u' => '0.01',
				'm' => '2026-10-01 10:00:00',
			)
		);
		$res   = Nano_Unlock_Rest::checkout( new WP_REST_Request( array( 'offer' => $offer ) ) );
		$this->assertInstanceOf( WP_REST_Response::class, $res );
		return $res->get_data();
	}

	/**
	 * The node now holds a confirmed payment of $amount to the site.
	 *
	 * @param string $amount Raw.
	 */
	private function pay( $amount ) {
		$GLOBALS['nano_unlock_wp']['node'] = function ( $body ) use ( $amount ) {
			switch ( $body['action'] ) {
				case 'receivable':
					return array(
						'blocks' => array(
							self::HASH => array(
								'amount' => $amount,
								'source' => self::BUYER,
							),
						),
					);
				case 'account_history':
					return array( 'history' => array() );
				case 'block_info':
					return array(
						'block_account'   => self::BUYER,
						'amount'          => $amount,
						'local_timestamp' => (string) time(),
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

	private function claim( $id ) {
		return Nano_Unlock_Rest::claim( new WP_REST_Request( array( 'id' => $id ) ) );
	}

	private function assertError( $res, $status, $code ) {
		$this->assertInstanceOf( WP_Error::class, $res, 'an error, not "paid"' );
		$this->assertSame( $status, $res->get_error_data()['status'] );
		$this->assertSame( $code, $res->get_error_code() );
	}

	private function receipt_for( $item ) {
		$token = isset( $_COOKIE[ Nano_Unlock_Render::RECEIPTS_COOKIE ] ) ? $_COOKIE[ Nano_Unlock_Render::RECEIPTS_COOKIE ] : '';
		return Nano_Unlock::tokens()->check_receipt( $token, $item );
	}

	public function test_a_payment_unlocks_with_a_receipt() {
		$c = $this->checkout();
		$this->pay( $c['amount'] );
		$res = $this->claim( $c['id'] );
		$this->assertInstanceOf( WP_REST_Response::class, $res );
		$this->assertSame( array( 'paid' => true ), $res->get_data() );
		$this->assertNotNull( $this->receipt_for( 'post:42:0' ) );
		$this->assertSame( $c['id'], $this->receipt_for( 'post:42:0' )['c'] );
	}

	public function test_no_paid_answer_when_the_receipt_cookie_cannot_be_set() {
		$c = $this->checkout();
		$this->pay( $c['amount'] );
		$this->cookies_work = false;
		$this->assertError( $this->claim( $c['id'] ), 500, 'nano_unlock_receipt' );
		$this->assertNull( $this->receipt_for( 'post:42:0' ) );
		$this->assertSame( 'paid', $GLOBALS['wpdb']->rows[ $c['id'] ]['status'], 'the payment is recorded' );
		// The page kept the checkout id; once cookies work, claiming it again unlocks.
		$this->cookies_work = true;
		$res                = $this->claim( $c['id'] );
		$this->assertSame( array( 'paid' => true ), $res->get_data() );
		$this->assertNotNull( $this->receipt_for( 'post:42:0' ) );
	}

	public function test_no_paid_answer_when_the_payment_cannot_be_recorded() {
		$c = $this->checkout();
		$this->pay( $c['amount'] );
		$GLOBALS['wpdb']->fail_mark_paid = true;
		$res                             = $this->claim( $c['id'] );
		$this->assertInstanceOf( WP_Error::class, $res );
		$this->assertSame( 503, $res->get_error_data()['status'], 'busy: the page keeps asking' );
		$this->assertNull( $this->receipt_for( 'post:42:0' ) );
		$GLOBALS['wpdb']->fail_mark_paid = false;
		$this->assertSame( array( 'paid' => true ), $this->claim( $c['id'] )->get_data() );
	}

	public function test_no_checkout_when_the_starter_cookie_cannot_be_set() {
		$this->cookies_work = false;
		$offer              = Nano_Unlock::tokens()->sign(
			'offer',
			array(
				'p' => self::POST,
				's' => '0',
				'u' => '0.01',
				'm' => '2026-10-01 10:00:00',
			)
		);
		$this->assertError( Nano_Unlock_Rest::checkout( new WP_REST_Request( array( 'offer' => $offer ) ) ), 503, 'nano_unlock' );
		$this->assertSame( array(), $GLOBALS['wpdb']->rows, 'no amount is held for a browser that could never claim it' );
	}

	public function test_another_browser_gets_an_error_and_no_receipt() {
		$c = $this->checkout();
		$this->pay( $c['amount'] );
		$this->assertSame( array( 'paid' => true ), $this->claim( $c['id'] )->get_data() );
		$_COOKIE = array( Nano_Unlock_Rest::STARTER_COOKIE => str_repeat( 'f', 64 ) );
		$this->assertError( $this->claim( $c['id'] ), 403, 'nano_unlock_not_starter' );
		$this->assertNull( $this->receipt_for( 'post:42:0' ) );
	}

	public function test_a_paid_checkout_gives_its_receipt_again_for_an_hour_only() {
		$c = $this->checkout();
		$this->pay( $c['amount'] );
		$this->claim( $c['id'] );
		unset( $_COOKIE[ Nano_Unlock_Render::RECEIPTS_COOKIE ] );
		$this->assertSame( array( 'paid' => true ), $this->claim( $c['id'] )->get_data(), 'a browser that lost the answer' );
		$GLOBALS['wpdb']->rows[ $c['id'] ]['paid_at'] = (string) ( time() - 2 * HOUR_IN_SECONDS );
		unset( $_COOKIE[ Nano_Unlock_Render::RECEIPTS_COOKIE ] );
		$this->assertError( $this->claim( $c['id'] ), 410, 'nano_unlock_too_late' );
	}

	public function test_a_new_receipt_keeps_the_cookie_small() {
		$tokens = Nano_Unlock::tokens();
		$full   = '';
		for ( $i = 1; $i <= 100; $i++ ) {
			$full = $tokens->add_receipt( $full, 'post:' . ( 1000 + $i ) . ':0', bin2hex( random_bytes( 12 ) ), DAY_IN_SECONDS, time() - 200 + $i );
		}
		$_COOKIE[ Nano_Unlock_Render::RECEIPTS_COOKIE ] = $full;
		$c = $this->checkout();
		$this->pay( $c['amount'] );
		$this->assertSame( array( 'paid' => true ), $this->claim( $c['id'] )->get_data() );
		$this->assertLessThanOrEqual( Nano_Unlock_Token::RECEIPTS_MAX_BYTES, strlen( $_COOKIE[ Nano_Unlock_Render::RECEIPTS_COOKIE ] ) );
		$this->assertNotNull( $this->receipt_for( 'post:42:0' ), 'the newest receipt is kept' );
		$this->assertNull( $this->receipt_for( 'post:1001:0' ), 'the oldest was dropped' );
	}
}
