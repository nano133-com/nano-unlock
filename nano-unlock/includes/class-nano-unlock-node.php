<?php
/**
 * Talking to a Nano node.
 *
 * @package NanoUnlock
 */

defined( 'ABSPATH' ) || exit;

/**
 * A Nano node's RPC, over HTTP(S), from this server.
 *
 * Every answer that decides whether a payment arrived comes from the node
 * (or both nodes) set on the settings page. When a node doesn't answer, the
 * check fails closed: the reader is told to wait, and nothing is unlocked.
 */
final class Nano_Unlock_Node {

	/**
	 * The RPC URL.
	 *
	 * @var string
	 */
	private $url;

	/**
	 * Constructor.
	 *
	 * @param string $url The RPC URL.
	 */
	public function __construct( $url ) {
		$this->url = $url;
	}

	/**
	 * The RPC URL.
	 *
	 * @return string
	 */
	public function url() {
		return $this->url;
	}

	/**
	 * Calls the node.
	 *
	 * @param array $body The request.
	 * @return array|null The answer, or null when the node says the thing isn't there ("Block not found").
	 * @throws Nano_Unlock_Busy When the node doesn't answer, or answers with another error.
	 */
	public function call( array $body ) {
		$response = wp_remote_post(
			$this->url,
			array(
				'timeout'     => 8,
				'redirection' => 0,
				'headers'     => array( 'Content-Type' => 'application/json' ),
				'body'        => wp_json_encode( $body ),
				'user-agent'  => 'NanoUnlock/' . NANO_UNLOCK_VERSION . '; ' . home_url( '/' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			throw new Nano_Unlock_Busy( esc_html( 'no answer' ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$json = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $json ) ) {
			throw new Nano_Unlock_Busy( esc_html( 'HTTP ' . $code ) );
		}
		if ( isset( $json['error'] ) ) {
			if ( false !== stripos( (string) $json['error'], 'not found' ) ) {
				return null;
			}
			throw new Nano_Unlock_Busy( esc_html( (string) $json['error'] ) );
		}
		if ( $code >= 400 ) {
			throw new Nano_Unlock_Busy( esc_html( 'HTTP ' . $code ) );
		}
		return $json;
	}
}
