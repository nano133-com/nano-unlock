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

	public function test_array_values_are_refused_without_a_warning() {
		nano_unlock_wp_reset();
		$GLOBALS['nano_unlock_wp']['options']['nano_unlock_settings'] = array(
			'address' => 'nano_3khpd7q3dzrbcae18bmpidbcrj5mn1n1jnkidup7acocia1b966y88czr5if',
			'usd'     => '0.05',
			'node'    => 'https://node.nano133.com/rpc',
		);
		// A crafted form posts arrays; PHPUnit turns any "Array to string conversion" warning into a failure.
		$out = Nano_Unlock_Settings::sanitize(
			array(
				'address' => array( 'x' ),
				'usd'     => array( '1' ),
				'node'    => array( 'https://evil.test' ),
				'node2'   => array( 'https://evil.test' ),
			)
		);
		$this->assertSame( 'nano_3khpd7q3dzrbcae18bmpidbcrj5mn1n1jnkidup7acocia1b966y88czr5if', $out['address'], 'the old address is kept' );
		$this->assertSame( '0.05', $out['usd'], 'the old price is kept' );
		$this->assertSame( 'https://node.nano133.com/rpc', $out['node'], 'the old node is kept' );
		$this->assertSame( '', $out['node2'] );
		$this->assertArrayHasKey( 'usd', $GLOBALS['nano_unlock_wp']['settings_errors'] );
		$this->assertNull( Nano_Unlock_Settings::price( array( '1' ) ) );
	}
}
