<?php
/**
 * The test-node rule: a node that can't see real payments must never take real money.
 *
 * @package NanoUnlock
 */

use PHPUnit\Framework\TestCase;

final class SettingsTest extends TestCase {

	public function test_public_https_nodes() {
		foreach ( array( 'https://node.nano133.com/rpc', 'https://rpc.nano.to', 'https://8.8.8.8:7076' ) as $url ) {
			$this->assertFalse( Nano_Unlock_Settings::is_test_node( $url ), $url );
		}
	}

	public function test_test_nodes() {
		$urls = array(
			'http://node.nano133.com/rpc',
			'http://host.docker.internal:8787',
			'https://host.docker.internal/rpc',
			'https://localhost:7076',
			'https://node.localhost',
			'https://127.0.0.1:7076',
			'https://192.168.1.5:7076',
			'https://10.0.0.2',
			'https://[::1]:7076',
			'https://mynode:7076',
			'https://nano.local',
			'',
			'not a url',
		);
		foreach ( $urls as $url ) {
			$this->assertTrue( Nano_Unlock_Settings::is_test_node( $url ), $url );
		}
	}

	public function test_prices() {
		$this->assertSame( '0.05', Nano_Unlock_Settings::price( '$0.05' ) );
		$this->assertSame( '1', Nano_Unlock_Settings::price( '1.0000' ) );
		$this->assertNull( Nano_Unlock_Settings::price( '0.001' ) );
		$this->assertNull( Nano_Unlock_Settings::price( '5000' ) );
		$this->assertNull( Nano_Unlock_Settings::price( 'free' ) );
	}
}
