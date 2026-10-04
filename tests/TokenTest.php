<?php
/**
 * Offers and receipts.
 *
 * @package NanoUnlock
 */

use PHPUnit\Framework\TestCase;

final class TokenTest extends TestCase {

	private function signer( $seed = 'a' ) {
		return new Nano_Unlock_Token( str_repeat( $seed, 32 ) );
	}

	public function test_round_trip() {
		$claims = array(
			'p' => 12,
			's' => '0',
			'u' => '0.01',
			'm' => '2026-09-30 10:00:00',
		);
		$token  = $this->signer()->sign( 'offer', $claims );
		$this->assertSame( $claims, $this->signer()->verify( 'offer', $token ) );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]{43}$/', $token );
	}

	public function test_an_offer_is_not_a_receipt() {
		$token = $this->signer()->sign( 'offer', array( 'i' => 'post:1:0', 'e' => time() + 60 ) );
		$this->assertNull( $this->signer()->verify( 'receipts', $token ) );
		$this->assertNull( $this->signer()->check_receipt( $token, 'post:1:0' ) );
	}

	public function test_another_site_key_fails() {
		$token = $this->signer( 'a' )->add_receipt( '', 'post:1:0', str_repeat( 'a', 24 ), 60 );
		$this->assertNull( $this->signer( 'b' )->check_receipt( $token, 'post:1:0' ) );
		$this->assertSame( array(), $this->signer( 'b' )->receipts( $token ) );
	}

	public function test_changed_claims_or_mac_fail() {
		$signer            = $this->signer();
		$token             = $signer->sign( 'offer', array( 'u' => '0.01' ) );
		list( $body, $mac ) = explode( '.', $token );
		$cheaper           = Nano_Unlock_Token::b64( '{"u":"0.00"}' );
		$this->assertNull( $signer->verify( 'offer', $cheaper . '.' . $mac ) );
		$this->assertNull( $signer->verify( 'offer', $body . '.' . strrev( $mac ) ) );
		$this->assertNull( $signer->verify( 'offer', $body ) );
		$this->assertNull( $signer->verify( 'offer', $token . '.x' ) );
		$this->assertNull( $signer->verify( 'offer', str_repeat( 'a', 3000 ) . '.' . $mac ) );
		$this->assertNull( $signer->verify( 'offer', null ) );
		$this->assertNull( $signer->verify( 'offer', array( $token ) ) );
	}

	public function test_receipt_names_its_item_and_expires() {
		$signer = $this->signer();
		$now    = 1790000000;
		$token  = $signer->add_receipt( '', 'post:7:0', str_repeat( 'c', 24 ), 3600, $now );
		$c      = $signer->check_receipt( $token, 'post:7:0', $now + 10 );
		$this->assertSame( str_repeat( 'c', 24 ), $c['c'] );
		$this->assertSame( $now + 3600, $c['e'] );
		$this->assertNull( $signer->check_receipt( $token, 'post:7:1', $now + 10 ), 'another item' );
		$this->assertNull( $signer->check_receipt( $token, 'post:8:0', $now + 10 ), 'another post' );
		$this->assertNull( $signer->check_receipt( $token, 'post:7:0', $now + 3600 ), 'expired' );
	}

	public function test_receipts_share_one_token() {
		$signer = $this->signer();
		$now    = 1790000000;
		$token  = $signer->add_receipt( '', 'post:7:0', str_repeat( 'a', 24 ), 3600, $now );
		$token  = $signer->add_receipt( $token, 'post:8:recipe', str_repeat( 'b', 24 ), 3600, $now + 1 );
		$this->assertNotNull( $signer->check_receipt( $token, 'post:7:0', $now + 5 ) );
		$this->assertNotNull( $signer->check_receipt( $token, 'post:8:recipe', $now + 5 ) );
		// Buying the same item again replaces its receipt.
		$token = $signer->add_receipt( $token, 'post:7:0', str_repeat( 'd', 24 ), 7200, $now + 2 );
		$this->assertCount( 2, $signer->receipts( $token, $now + 5 ) );
		$this->assertSame( str_repeat( 'd', 24 ), $signer->check_receipt( $token, 'post:7:0', $now + 5 )['c'] );
		// An expired receipt is dropped when the next one is added.
		$token = $signer->add_receipt( $token, 'post:9:0', str_repeat( 'e', 24 ), 3600, $now + 4000 );
		$this->assertSame( array( 'post:7:0', 'post:9:0' ), array_keys( $signer->receipts( $token, $now + 4000 ) ) );
		// A bad current token counts as none.
		$this->assertCount( 1, $signer->receipts( $signer->add_receipt( 'garbage', 'post:1:0', 'x', 60, $now ), $now ) );
	}

	public function test_the_receipts_cookie_stays_small_however_many_purchases() {
		$signer = $this->signer();
		$now    = 1790000000;
		$token  = '';
		// 500 purchases with the longest slots an item can have (40 characters).
		for ( $i = 1; $i <= 500; $i++ ) {
			$token = $signer->add_receipt( $token, 'post:' . ( 100000 + $i ) . ':' . str_repeat( 's', 40 ), bin2hex( random_bytes( 12 ) ), 30 * 86400, $now + $i );
			$this->assertLessThanOrEqual( Nano_Unlock_Token::RECEIPTS_MAX_BYTES, strlen( $token ) );
		}
		$receipts = $signer->receipts( $token, $now + 600 );
		$this->assertGreaterThanOrEqual( 20, count( $receipts ), 'it still holds many receipts' );
		// The newest purchase is kept; the oldest ones were dropped.
		$this->assertArrayHasKey( 'post:100500:' . str_repeat( 's', 40 ), $receipts );
		$this->assertArrayNotHasKey( 'post:100001:' . str_repeat( 's', 40 ), $receipts );
		// The whole Set-Cookie value (name, value, attributes) fits a browser's 4096-byte cookie, and leaves
		// most of an 8 KB request header for everything else.
		$this->assertLessThan( 4096 - 200, strlen( Nano_Unlock_Render::RECEIPTS_COOKIE . '=' . $token ) );
		// The old format: about 330 bytes per purchase, in a cookie each.
		$this->assertLessThan( 330 * 24, strlen( $token ) );
	}

	public function test_an_offer_is_not_a_receipts_token() {
		$signer = $this->signer();
		$offer  = $signer->sign( 'offer', array( 'r' => array( array( 'post:1:0', time() + 60, 'x' ) ) ) );
		$this->assertNull( $signer->check_receipt( $offer, 'post:1:0' ) );
	}

	public function test_short_key_refused() {
		$this->expectException( InvalidArgumentException::class );
		new Nano_Unlock_Token( 'short' );
	}
}
