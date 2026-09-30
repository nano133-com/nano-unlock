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
		$this->assertNull( $this->signer()->verify( 'receipt', $token ) );
		$this->assertNull( $this->signer()->check_receipt( $token, 'post:1:0' ) );
	}

	public function test_another_site_key_fails() {
		$token = $this->signer( 'a' )->receipt( 'post:1:0', 'nano_x', 'AB', 60 );
		$this->assertNull( $this->signer( 'b' )->check_receipt( $token, 'post:1:0' ) );
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
		$token  = $signer->receipt( 'post:7:0', 'nano_buyer', 'ABCD', 3600, $now );
		$c      = $signer->check_receipt( $token, 'post:7:0', $now + 10 );
		$this->assertSame( 'nano_buyer', $c['b'] );
		$this->assertSame( 'ABCD', $c['h'] );
		$this->assertNull( $signer->check_receipt( $token, 'post:7:1', $now + 10 ), 'another item' );
		$this->assertNull( $signer->check_receipt( $token, 'post:8:0', $now + 10 ), 'another post' );
		$this->assertNull( $signer->check_receipt( $token, 'post:7:0', $now + 3600 ), 'expired' );
	}

	public function test_short_key_refused() {
		$this->expectException( InvalidArgumentException::class );
		new Nano_Unlock_Token( 'short' );
	}
}
