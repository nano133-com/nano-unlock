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
		$GLOBALS['nano_unlock_wp']['options']['nano_unlock_db_version'] = Nano_Unlock_Store::DB_VERSION;
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
	 * The signed offer for the post's first paid part.
	 *
	 * @param string $usd The price.
	 * @return string
	 */
	private function offer( $usd = '0.01' ) {
		return Nano_Unlock::tokens()->sign(
			'offer',
			array(
				'p' => self::POST,
				's' => '0',
				'u' => $usd,
				'm' => '2026-10-01 10:00:00',
			)
		);
	}

	/**
	 * Starts a checkout for the post's first paid part at $0.01.
	 *
	 * @param string $usd The price.
	 * @return array The checkout's answer.
	 */
	private function checkout( $usd = '0.01' ) {
		$res = Nano_Unlock_Rest::checkout( new WP_REST_Request( array( 'offer' => $this->offer( $usd ) ) ) );
		$this->assertInstanceOf( WP_REST_Response::class, $res );
		return $res->get_data();
	}

	/**
	 * The node now holds a confirmed payment of $amount to the site.
	 *
	 * @param string $amount Raw.
	 */
	private function pay( $amount, $hash = self::HASH, $confirmed = true ) {
		// A new answer from the node: no shared scan, no resting "not yet".
		$GLOBALS['nano_unlock_wp']['transients'] = array( 'nano_unlock_rate' => 0.5 );
		$GLOBALS['nano_unlock_wp']['node']       = function ( $body ) use ( $amount, $hash, $confirmed ) {
			switch ( $body['action'] ) {
				case 'receivable':
					return array(
						'blocks' => array(
							$hash => array(
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
						'confirmed'       => $confirmed ? 'true' : 'false',
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

	public function test_the_checkout_asks_for_a_short_exact_amount() {
		$c = $this->checkout();
		// $0.01 at $0.50 per XNO: exactly 0.02 XNO.
		$this->assertSame( '2' . str_repeat( '0', 28 ), $c['amount'] );
		$this->assertSame( '0.02', $c['xno'] );
		$this->assertSame( '0.01', $c['usd'] );
		$this->assertSame( 'nano:' . self::SITE . '?amount=' . $c['amount'], $c['uri'], 'the QR code and wallet link carry the exact amount' );
		$this->assertFalse( $c['test'] );
		// A second open checkout at the same price: one step more.
		$this->assertSame( '0.0201', $this->checkout()['xno'] );

		// The reader's script shows that amount on the card's one line, and copies it from Details.
		$js = file_get_contents( dirname( __DIR__ ) . '/nano-unlock/assets/checkout.js' );
		$this->assertStringContainsString( "var xno = t.xno.replace( '%s', c.xno );", $js );
		$this->assertStringContainsString( 'fill( t.pay, price, xno )', $js );
		$this->assertStringContainsString( 'copyButton( c.xno, t.amount, test )', $js );
	}

	public function test_the_dollar_amount_shows_its_cents() {
		// An offer printed before this version says "0.1"; the card says "$0.10".
		$this->assertSame( '0.10', $this->checkout( '0.1' )['usd'] );
		$this->assertSame( '1.00', $this->checkout( '1' )['usd'] );
	}

	public function test_the_payment_that_paid_one_checkout_never_pays_the_next_with_the_same_amount() {
		$first = $this->checkout();
		$this->pay( $first['amount'] );
		$this->assertSame( array( 'paid' => true ), $this->claim( $first['id'] )->get_data() );
		// The amount is free again, and the next reader gets it, while the node still lists that payment.
		$next = $this->checkout();
		$this->assertSame( $first['amount'], $next['amount'] );
		$this->pay( $first['amount'] );
		$this->assertSame(
			array(
				'paid'    => false,
				'pending' => false,
			),
			$this->claim( $next['id'] )->get_data(),
			'the used payment is skipped, not "already used"'
		);
		// The next reader's own payment of the same amount unlocks it.
		$this->pay( $first['amount'], str_repeat( 'C', 64 ) );
		$this->assertSame( array( 'paid' => true ), $this->claim( $next['id'] )->get_data() );
		$this->assertSame( str_repeat( 'C', 64 ), $GLOBALS['wpdb']->rows[ $next['id'] ]['hash'] );
	}

	public function test_cancel_frees_the_amount_and_ends_the_checkout() {
		$c = $this->checkout();
		$this->pay( '3' . str_repeat( '0', 28 ) ); // The node holds no payment of this amount.
		$res = Nano_Unlock_Rest::cancel( new WP_REST_Request( array( 'id' => $c['id'] ) ) );
		$this->assertSame( array( 'cancelled' => true ), $res->get_data() );
		$row = $GLOBALS['wpdb']->rows[ $c['id'] ];
		$this->assertSame( 'cancelled', $row['status'] );
		$this->assertSame( $c['amount'], $row['amount_lock'], 'held 3 minutes more' );
		$this->assertSame( '0.0201', $this->checkout()['xno'], 'meanwhile the next reader gets one step more' );
		$GLOBALS['wpdb']->set( $c['id'], array( 'cancelled_at' => time() - Nano_Unlock_Store::CANCEL_HOLD - 1 ) );
		$this->assertSame( $c['amount'], $this->checkout()['amount'], 'after 3 minutes the next reader gets it' );
		// A payment for the cancelled checkout no longer counts for it.
		$this->pay( $c['amount'] );
		$this->assertSame(
			array(
				'paid' => false,
				'gone' => true,
			),
			$this->claim( $c['id'] )->get_data()
		);
	}

	public function test_cancel_after_the_payment_arrived_unlocks_instead() {
		$c = $this->checkout();
		$this->pay( $c['amount'] );
		$res = Nano_Unlock_Rest::cancel( new WP_REST_Request( array( 'id' => $c['id'] ) ) );
		$this->assertSame( array( 'paid' => true ), $res->get_data() );
		$this->assertNotNull( $this->receipt_for( 'post:42:0' ) );
		$this->assertSame( 'paid', $GLOBALS['wpdb']->rows[ $c['id'] ]['status'] );
	}

	public function test_cancel_while_the_payment_confirms_keeps_the_checkout() {
		$c = $this->checkout();
		$this->pay( $c['amount'], self::HASH, false );
		$res = Nano_Unlock_Rest::cancel( new WP_REST_Request( array( 'id' => $c['id'] ) ) );
		$this->assertSame(
			array(
				'cancelled' => false,
				'pending'   => true,
			),
			$res->get_data()
		);
		$this->assertSame( $c['amount'], $GLOBALS['wpdb']->rows[ $c['id'] ]['amount_lock'] );
	}

	public function test_cancel_without_the_node_keeps_the_amount_held() {
		$c                                 = $this->checkout();
		$GLOBALS['nano_unlock_wp']['node'] = null;
		$res                               = Nano_Unlock_Rest::cancel( new WP_REST_Request( array( 'id' => $c['id'] ) ) );
		$this->assertError( $res, 503, 'nano_unlock' );
		$row = $GLOBALS['wpdb']->rows[ $c['id'] ];
		$this->assertSame( 'waiting', $row['status'], 'a payment may be on its way: the amount stays held' );
		$this->assertSame( $c['amount'], $row['amount_lock'] );
	}

	public function test_a_page_on_another_site_cannot_start_a_checkout() {
		$cross = Nano_Unlock_Rest::checkout( new WP_REST_Request( array( 'offer' => $this->offer() ), array( 'sec_fetch_site' => 'cross-site' ) ) );
		$this->assertError( $cross, 403, 'nano_unlock' );
		$this->assertSame( array(), $GLOBALS['wpdb']->rows, 'nothing is held' );
		$same = Nano_Unlock_Rest::checkout( new WP_REST_Request( array( 'offer' => $this->offer() ), array( 'sec_fetch_site' => 'same-origin' ) ) );
		$this->assertInstanceOf( WP_REST_Response::class, $same );
	}

	public function test_no_checkout_while_the_table_update_failed() {
		$GLOBALS['nano_unlock_wp']['options']['nano_unlock_db_version'] = '1';
		$res = Nano_Unlock_Rest::checkout( new WP_REST_Request( array( 'offer' => $this->offer() ) ) );
		$this->assertError( $res, 503, 'nano_unlock' );
		$this->assertStringContainsString( 'not ready to sell', $res->get_error_message() );
	}

	public function test_the_limits_never_name_the_readers_address() {
		$this->checkout();
		$ip = $_SERVER['REMOTE_ADDR'];
		foreach ( array_keys( $GLOBALS['nano_unlock_wp']['transients'] ) as $name ) {
			foreach ( array( 'checkout', 'claim' ) as $scope ) {
				$this->assertStringNotContainsString( substr( hash( 'sha256', $scope . '|' . $ip . '|600|' . (int) floor( time() / 600 ) ), 0, 32 ), $name, 'no unkeyed hash of the IP address' );
			}
		}
		$this->assertNull( $GLOBALS['wpdb']->rows[ array_keys( $GLOBALS['wpdb']->rows )[0] ]['cancelled_at'] );
	}

	public function test_cancel_after_the_late_window_asks_no_node() {
		$c = $this->checkout();
		$GLOBALS['wpdb']->set( $c['id'], array( 'expires_at' => time() - Nano_Unlock::LATE_SECONDS - 1 ) );
		$asked                             = false;
		$GLOBALS['nano_unlock_wp']['node'] = function () use ( &$asked ) {
			$asked = true;
			return array( 'error' => 'unexpected' );
		};
		$res = Nano_Unlock_Rest::cancel( new WP_REST_Request( array( 'id' => $c['id'] ) ) );
		$this->assertSame( array( 'cancelled' => true ), $res->get_data() );
		$this->assertFalse( $asked, 'a payment no longer counts then, so the node is not asked' );
		$this->assertSame( 'cancelled', $GLOBALS['wpdb']->rows[ $c['id'] ]['status'] );
	}

	public function test_only_the_starting_browser_can_cancel() {
		$c = $this->checkout();
		$this->pay( '3' . str_repeat( '0', 28 ) );
		$_COOKIE[ Nano_Unlock_Rest::STARTER_COOKIE ] = str_repeat( 'f', 64 );
		$this->assertError( Nano_Unlock_Rest::cancel( new WP_REST_Request( array( 'id' => $c['id'] ) ) ), 403, 'nano_unlock_not_starter' );
		$this->assertSame( 'waiting', $GLOBALS['wpdb']->rows[ $c['id'] ]['status'] );
	}

	public function test_one_visitor_cannot_hold_every_amount() {
		for ( $i = 0; $i < Nano_Unlock_Store::MAX_HELD; $i++ ) {
			$this->checkout();
		}
		$this->assertError( Nano_Unlock_Rest::checkout( new WP_REST_Request( array( 'offer' => $this->offer() ) ) ), 429, 'nano_unlock' );
		// Another visitor is not affected.
		$_SERVER['REMOTE_ADDR'] = '198.51.100.20';
		$this->assertSame( '0.021', $this->checkout()['xno'], 'one step after the first visitor\'s ten' );
	}

	public function test_all_steps_held_answers_busy_in_plain_words() {
		// $0.01 at $0.50 is 0.02 XNO: a tenth is 20 steps, so 21 amounts.
		$price = Nano_Unlock_Amount::from_usd( 0.01, 0.5 );
		$this->assertSame( 21, Nano_Unlock_Store::steps( $price ) );
		for ( $i = 0; $i < 21; $i++ ) {
			Nano_Unlock_Store::create(
				array(
					'item'       => 'post:42:0',
					'post_id'    => self::POST,
					'usd'        => '0.01',
					'address'    => self::SITE,
					'starter'    => str_repeat( '0', 64 ),
					'visitor'    => sprintf( '%016x', $i ),
					'created_at' => time(),
					'expires_at' => time() + 900,
				),
				$price,
				Nano_Unlock::LATE_SECONDS
			);
		}
		$res = Nano_Unlock_Rest::checkout( new WP_REST_Request( array( 'offer' => $this->offer() ) ) );
		$this->assertError( $res, 503, 'nano_unlock' );
		$this->assertStringContainsString( 'Try again in a few minutes', $res->get_error_message() );
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
		$this->assertError( Nano_Unlock_Rest::checkout( new WP_REST_Request( array( 'offer' => $this->offer() ) ) ), 503, 'nano_unlock' );
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
		$GLOBALS['wpdb']->set( $c['id'], array( 'paid_at' => time() - 2 * HOUR_IN_SECONDS ) );
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
