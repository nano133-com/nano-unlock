<?php
/**
 * Where a paid part may be shown.
 *
 * @package NanoUnlock
 */

use PHPUnit\Framework\TestCase;

final class RenderTest extends TestCase {

	const POST   = 42;
	const SECRET = 'the paid text';
	const SITE   = 'nano_1cp9z9nwkzonezr97a8xfw79ahx6emdqhj8eu5j1gkmxhwy817dowa67xzoi';
	const BUYER  = 'nano_3getnanons1aaqo5itbm8wdbzhtsp7tctd6p6qa7axwff7ocemzs3w381kfy';

	protected function setUp(): void {
		nano_unlock_wp_reset();
		nano_unlock_wp_post( self::POST );
		$GLOBALS['nano_unlock_wp']['options']['nano_unlock_settings'] = array( 'address' => self::SITE );
		$GLOBALS['nano_unlock_wp']['the_id']                         = self::POST;
		$GLOBALS['nano_unlock_wp']['queried']                        = self::POST;
		Nano_Unlock_Render::reset( '' );
	}

	/**
	 * Gives this request a valid receipt for the post's first paid part.
	 */
	private function pay() {
		$item = Nano_Unlock_Render::item( self::POST, '0' );
		$_COOKIE[ Nano_Unlock_Render::cookie_name( $item ) ] = Nano_Unlock::tokens()->receipt( $item, self::BUYER, str_repeat( 'A', 64 ), 3600 );
	}

	private function render() {
		Nano_Unlock_Render::reset( '' );
		return Nano_Unlock_Render::shortcode( array( 'price' => '0.05' ), self::SECRET );
	}

	public function test_the_posts_own_page_shows_the_paid_part_to_a_buyer() {
		$this->pay();
		$html = $this->render();
		$this->assertStringContainsString( self::SECRET, $html );
		$this->assertStringContainsString( 'nano-unlock--paid', $html );
		$this->assertTrue( defined( 'DONOTCACHEPAGE' ), 'the page is marked as not cacheable' );
	}

	public function test_the_posts_own_page_is_locked_without_a_receipt() {
		$html = $this->render();
		$this->assertStringNotContainsString( self::SECRET, $html );
		$this->assertStringContainsString( 'data-nano-unlock-offer=', $html );
	}

	public function test_an_archive_never_shows_the_paid_part_even_to_a_buyer() {
		$this->pay();
		$GLOBALS['nano_unlock_wp']['singular'] = false;
		$GLOBALS['nano_unlock_wp']['queried']  = 0;
		$html                                  = $this->render();
		$this->assertStringNotContainsString( self::SECRET, $html );
		$this->assertStringNotContainsString( 'data-nano-unlock-offer=', $html, 'no checkout on an archive' );
		$this->assertStringContainsString( 'href="https://example.test/?p=42#nano-unlock-0"', $html, 'a link to the post instead' );
		$this->assertStringContainsString( 'nano-unlock--elsewhere', $html );
	}

	public function test_another_posts_page_never_shows_the_paid_part() {
		$this->pay();
		// A singular page of post 7 that lists post 42 (a query loop, a related-posts block).
		$GLOBALS['nano_unlock_wp']['queried'] = 7;
		$html                                 = $this->render();
		$this->assertStringNotContainsString( self::SECRET, $html );
		$this->assertStringContainsString( 'nano-unlock--elsewhere', $html );
	}

	public function test_an_editor_sees_the_preview_only_on_the_posts_page() {
		$GLOBALS['nano_unlock_wp']['can_edit'] = true;
		$this->assertStringContainsString( self::SECRET, $this->render() );
		$GLOBALS['nano_unlock_wp']['singular'] = false;
		$this->assertStringNotContainsString( self::SECRET, $this->render() );
	}

	public function test_a_feed_or_rest_request_never_reads_the_receipt() {
		$this->pay();
		$GLOBALS['nano_unlock_wp']['singular'] = false;
		$GLOBALS['nano_unlock_wp']['queried']  = 0;
		$this->assertStringNotContainsString( 'Unlocked with Nano', $this->render() );
	}
}
