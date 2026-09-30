<?php
/**
 * The reader's REST routes.
 *
 * @package NanoUnlock
 */

defined( 'ABSPATH' ) || exit;

/**
 * POST /wp-json/nano-unlock/v1/checkout {offer}  a unique amount to pay for one item
 * POST /wp-json/nano-unlock/v1/claim {id}        has the payment arrived? On success, this browser gets its receipt
 *
 * Both need the page's REST nonce, both are rate limited per visitor address,
 * and only the browser that started a checkout gets its receipt.
 */
final class Nano_Unlock_Rest {

	const STARTER_COOKIE = 'nano_unlock_starter';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/**
	 * Registers the routes.
	 */
	public static function routes() {
		register_rest_route(
			'nano-unlock/v1',
			'/checkout',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'checkout' ),
				'permission_callback' => array( __CLASS__, 'nonce' ),
				'args'                => array(
					'offer' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
		register_rest_route(
			'nano-unlock/v1',
			'/claim',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'claim' ),
				'permission_callback' => array( __CLASS__, 'nonce' ),
				'args'                => array(
					'id' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * The page's REST nonce must come with every call.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return true|WP_Error
	 */
	public static function nonce( WP_REST_Request $request ) {
		$nonce = (string) $request->get_header( 'x_wp_nonce' );
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error( 'nano_unlock_nonce', __( 'This page is out of date. Reload it and try again.', 'nano-unlock' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Starts a checkout.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function checkout( WP_REST_Request $request ) {
		$ip = Nano_Unlock_Limit::client_ip();
		// Each checkout holds an amount for over an hour, so starting them is limited.
		if ( ! Nano_Unlock_Limit::allow( 'checkout', 20, 10 * MINUTE_IN_SECONDS, $ip ) || ! Nano_Unlock_Limit::allow( 'checkout', 600, 10 * MINUTE_IN_SECONDS, 'all' ) ) {
			return self::error( 429, __( 'That is a lot of checkouts at once. Please wait a few minutes.', 'nano-unlock' ) );
		}
		$settings = Nano_Unlock_Settings::get();
		if ( ! Nano_Unlock_Settings::ready() ) {
			return self::error(
				503,
				Nano_Unlock_Settings::test_mode()
					? __( 'This site uses a test node, so it cannot see real payments. Nothing was charged: do not pay.', 'nano-unlock' )
					: __( 'This site is not set up to sell yet.', 'nano-unlock' )
			);
		}
		$offer = Nano_Unlock::tokens()->verify( 'offer', $request->get_param( 'offer' ) );
		if ( ! $offer || ! isset( $offer['p'], $offer['s'], $offer['u'], $offer['m'] ) ) {
			return self::error( 400, __( 'That offer is not valid.', 'nano-unlock' ) );
		}
		$post = get_post( (int) $offer['p'] );
		if ( ! $post || 'publish' !== $post->post_status || post_password_required( $post ) ) {
			return self::error( 404, __( 'That post is not available.', 'nano-unlock' ) );
		}
		// The offer was printed for this version of the post: after an edit (maybe a new price), the page must be reloaded.
		if ( $post->post_modified_gmt !== $offer['m'] ) {
			return self::error( 409, __( 'This page is out of date. Reload it and try again.', 'nano-unlock' ) );
		}
		$usd  = (string) $offer['u'];
		$rate = Nano_Unlock_Price::rate();
		$raw  = $rate ? Nano_Unlock_Amount::from_usd( (float) $usd, $rate ) : null;
		if ( ! $raw ) {
			return self::error( 503, __( 'The XNO price is not available right now. Try again soon.', 'nano-unlock' ) );
		}

		$starter = self::starter();
		$now     = time();
		$row     = Nano_Unlock_Store::create(
			array(
				'item'       => Nano_Unlock_Render::item( $post->ID, (string) $offer['s'] ),
				'post_id'    => $post->ID,
				'usd'        => $usd,
				'address'    => $settings['address'],
				'starter'    => hash( 'sha256', $starter ),
				'created_at' => $now,
				'expires_at' => $now + Nano_Unlock::CHECKOUT_SECONDS,
			),
			$raw,
			Nano_Unlock::LATE_SECONDS
		);
		if ( ! $row ) {
			return self::error( 503, __( 'Busy, try again.', 'nano-unlock' ) );
		}
		return self::ok(
			array(
				'id'        => $row['id'],
				'amount'    => $row['amount'],
				'xno'       => Nano_Unlock_Amount::to_xno( $row['amount'] ),
				'xnoShort'  => Nano_Unlock_Amount::to_xno_short( $raw ),
				'usd'       => $usd,
				'rate'      => $rate,
				'address'   => $row['address'],
				'uri'       => 'nano:' . $row['address'] . '?amount=' . $row['amount'],
				'expiresAt' => (int) $row['expires_at'],
				'test'      => Nano_Unlock_Settings::test_mode(),
				'now'       => $now,
			)
		);
	}

	/**
	 * Checks a checkout's payment.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function claim( WP_REST_Request $request ) {
		// About one poll every 2 s per open checkout; this leaves room for a few readers behind one address.
		if ( ! Nano_Unlock_Limit::allow( 'claim', 180, MINUTE_IN_SECONDS, Nano_Unlock_Limit::client_ip() ) ) {
			return self::error( 429, __( 'That is a lot of payment checks at once. Please wait a moment.', 'nano-unlock' ) );
		}
		$id  = (string) $request->get_param( 'id' );
		$row = Nano_Unlock_Store::get( $id );
		if ( ! $row ) {
			return self::error( 404, __( 'No such checkout.', 'nano-unlock' ) );
		}
		$mine = self::is_starter( $row );

		if ( 'paid' === $row['status'] ) {
			// A browser that lost the first answer still gets its receipt, for an hour.
			if ( $mine && time() - (int) $row['paid_at'] < HOUR_IN_SECONDS ) {
				self::grant( $row );
			}
			return self::ok( array( 'paid' => true ) );
		}
		if ( time() > (int) $row['expires_at'] + Nano_Unlock::LATE_SECONDS ) {
			return self::ok(
				array(
					'paid' => false,
					'gone' => true,
				)
			);
		}
		// The last "not yet" for this checkout is given again for a moment, without asking the node.
		$quiet = 'nano_unlock_q_' . $id;
		if ( get_transient( $quiet ) ) {
			return self::ok( array( 'paid' => false ) );
		}

		try {
			$found = Nano_Unlock_Payments::check( $row );
		} catch ( Nano_Unlock_Busy $e ) {
			return self::error( 503, __( 'The network check is busy, try again in a moment.', 'nano-unlock' ) );
		}
		if ( 'paid' !== $found['status'] ) {
			// Nothing seen yet: rest a moment. A payment already seen but not yet confirmed is asked about on every poll.
			if ( 'waiting' === $found['status'] ) {
				set_transient( $quiet, 1, 2 );
			}
			return self::ok(
				array(
					'paid'    => false,
					'pending' => 'pending' === $found['status'],
				)
			);
		}

		$result = Nano_Unlock_Store::mark_paid( $id, $found['hash'], $found['payer'] );
		if ( 'used' === $result ) {
			return self::error( 409, __( 'That payment was already used.', 'nano-unlock' ) );
		}
		$row = Nano_Unlock_Store::get( $id );
		if ( $mine && $row && 'paid' === $row['status'] ) {
			self::grant( $row );
		}
		return self::ok( array( 'paid' => true ) );
	}

	/**
	 * Sets the receipt cookie for a paid checkout.
	 *
	 * @param array $row The checkout.
	 */
	private static function grant( array $row ) {
		/**
		 * Filters how long a receipt keeps an item unlocked on the buyer's browser, in seconds.
		 *
		 * @param int    $seconds 30 days.
		 * @param string $item    The item key.
		 */
		$seconds = (int) apply_filters( 'nano_unlock_receipt_seconds', 30 * DAY_IN_SECONDS, $row['item'] );
		$token   = Nano_Unlock::tokens()->receipt( $row['item'], (string) $row['payer'], (string) $row['hash'], $seconds );
		self::cookie( Nano_Unlock_Render::cookie_name( $row['item'] ), $token, $seconds );
	}

	/**
	 * This browser's random starter token (made on its first checkout).
	 *
	 * @return string
	 */
	private static function starter() {
		$current = isset( $_COOKIE[ self::STARTER_COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::STARTER_COOKIE ] ) ) : '';
		if ( preg_match( '/^[0-9a-f]{64}$/', $current ) ) {
			return $current;
		}
		$token = bin2hex( random_bytes( 32 ) );
		self::cookie( self::STARTER_COOKIE, $token, 30 * DAY_IN_SECONDS );
		return $token;
	}

	/**
	 * Whether this browser started the checkout.
	 *
	 * @param array $row The checkout.
	 * @return bool
	 */
	private static function is_starter( array $row ) {
		$current = isset( $_COOKIE[ self::STARTER_COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::STARTER_COOKIE ] ) ) : '';
		return '' !== $current && hash_equals( (string) $row['starter'], hash( 'sha256', $current ) );
	}

	/**
	 * Sets an httpOnly cookie for the whole site.
	 *
	 * @param string $name    Name.
	 * @param string $value   Value.
	 * @param int    $seconds Lifetime.
	 */
	private static function cookie( $name, $value, $seconds ) {
		if ( headers_sent() ) {
			return;
		}
		setcookie(
			$name,
			$value,
			array(
				'expires'  => time() + $seconds,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
		$_COOKIE[ $name ] = $value;
	}

	/**
	 * A private, uncached answer.
	 *
	 * @param array $data The data.
	 * @return WP_REST_Response
	 */
	private static function ok( array $data ) {
		$response = new WP_REST_Response( $data, 200 );
		$response->header( 'Cache-Control', 'private, no-store' );
		return $response;
	}

	/**
	 * An error the reader's script shows.
	 *
	 * @param int    $status  The HTTP status.
	 * @param string $message The message.
	 * @return WP_Error
	 */
	private static function error( $status, $message ) {
		return new WP_Error( 'nano_unlock', $message, array( 'status' => $status ) );
	}
}
