<?php
/**
 * The reader's REST routes.
 *
 * @package NanoUnlock
 */

defined( 'ABSPATH' ) || exit;

/**
 * POST /wp-json/nano-unlock/v1/checkout {offer}  an amount of its own to pay for one item
 * POST /wp-json/nano-unlock/v1/claim {id}        has the payment arrived? "paid" only once this browser holds its receipt
 * POST /wp-json/nano-unlock/v1/cancel {id}       ends the checkout and frees its amount (unless its payment is already there)
 *
 * All need the page's REST nonce, all are rate limited per visitor address,
 * and only the browser that started a checkout gets its receipt or may cancel it.
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
		foreach ( array( 'claim', 'cancel' ) as $route ) {
			register_rest_route(
				'nano-unlock/v1',
				'/' . $route,
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, $route ),
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
		if ( '' === $starter ) {
			// Without the starter cookie this browser could pay but never claim its receipt.
			return self::error( 503, __( 'This browser did not accept the checkout cookie. Allow cookies for this site, then try again.', 'nano-unlock' ) );
		}
		// Each open checkout holds its amount for over an hour: one visitor alone must never hold them all.
		$visitor = Nano_Unlock_Limit::visitor( $ip );
		if ( Nano_Unlock_Store::held_by( $visitor, Nano_Unlock::LATE_SECONDS ) >= Nano_Unlock_Store::MAX_HELD ) {
			return self::error( 429, __( 'Too many checkouts are open from your connection. Cancel one, or wait a few minutes.', 'nano-unlock' ) );
		}
		$now = time();
		try {
			$row = Nano_Unlock_Store::create(
				array(
					'item'       => Nano_Unlock_Render::item( $post->ID, (string) $offer['s'] ),
					'post_id'    => $post->ID,
					'usd'        => $usd,
					'address'    => $settings['address'],
					'starter'    => hash( 'sha256', $starter ),
					'visitor'    => $visitor,
					'created_at' => $now,
					'expires_at' => $now + Nano_Unlock::CHECKOUT_SECONDS,
				),
				$raw,
				Nano_Unlock::LATE_SECONDS
			);
		} catch ( Nano_Unlock_Busy $e ) {
			return self::error( 503, __( 'Busy right now: many readers are paying at this price. Try again in a moment.', 'nano-unlock' ) );
		}
		$shown = Nano_Unlock_Settings::price( $usd );
		return self::ok(
			array(
				'id'        => $row['id'],
				'amount'    => $row['amount'],
				// Every digit of the amount: at most four decimals (an older checkout's amount keeps its whole tail).
				'xno'       => Nano_Unlock_Amount::to_xno( $row['amount'] ),
				'usd'       => null === $shown ? $usd : $shown,
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
		if ( 'paid' === $row['status'] ) {
			return self::paid( $row );
		}
		// A cancelled checkout gave its amount back: a payment of that amount may now belong to another reader.
		if ( 'waiting' !== $row['status'] || time() > (int) $row['expires_at'] + Nano_Unlock::LATE_SECONDS ) {
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

		return self::record( $id, $found );
	}

	/**
	 * Cancels a checkout, so that its amount is free at once for the next reader.
	 *
	 * The node is asked first, without the shared scan: when the payment is already
	 * there, the checkout is paid, not cancelled (a reader may press Cancel just after
	 * paying), and a payment seen but not confirmed yet keeps it open. When the node
	 * can't answer, nothing is cancelled: the amount stays held until the checkout's
	 * time is over, as when a reader just leaves the page.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function cancel( WP_REST_Request $request ) {
		if ( ! Nano_Unlock_Limit::allow( 'claim', 180, MINUTE_IN_SECONDS, Nano_Unlock_Limit::client_ip() ) ) {
			return self::error( 429, __( 'That is a lot of payment checks at once. Please wait a moment.', 'nano-unlock' ) );
		}
		$id  = (string) $request->get_param( 'id' );
		$row = Nano_Unlock_Store::get( $id );
		if ( ! $row ) {
			return self::error( 404, __( 'No such checkout.', 'nano-unlock' ) );
		}
		if ( ! self::is_starter( $row ) ) {
			return self::error( 403, __( 'This checkout was started in another browser.', 'nano-unlock' ), 'nano_unlock_not_starter' );
		}
		if ( 'paid' === $row['status'] ) {
			return self::paid( $row );
		}
		if ( 'waiting' !== $row['status'] || time() > (int) $row['expires_at'] + Nano_Unlock::LATE_SECONDS ) {
			// Over (cancelled, or past the time a payment counts): nothing to look for.
			Nano_Unlock_Store::cancel( $id );
			return self::ok( array( 'cancelled' => true ) );
		}
		try {
			$found = Nano_Unlock_Payments::check( $row, true );
		} catch ( Nano_Unlock_Busy $e ) {
			return self::error( 503, __( 'The network check is busy, try again in a moment.', 'nano-unlock' ) );
		}
		if ( 'pending' === $found['status'] ) {
			return self::ok(
				array(
					'cancelled' => false,
					'pending'   => true,
				)
			);
		}
		if ( 'paid' === $found['status'] && 'used' !== Nano_Unlock_Store::mark_paid( $id, $found['hash'], $found['payer'] ) ) {
			$row = Nano_Unlock_Store::get( $id );
			if ( $row && 'paid' === $row['status'] ) {
				return self::paid( $row );
			}
			return self::error( 503, __( 'The network check is busy, try again in a moment.', 'nano-unlock' ) );
		}
		Nano_Unlock_Store::cancel( $id );
		return self::ok( array( 'cancelled' => true ) );
	}

	/**
	 * Records a payment the node confirmed for a checkout, and answers as claim() does.
	 *
	 * @param string $id    The checkout.
	 * @param array  $found What Nano_Unlock_Payments::check() found ("paid", with its hash and payer).
	 * @return WP_REST_Response|WP_Error
	 */
	private static function record( $id, array $found ) {
		$result = Nano_Unlock_Store::mark_paid( $id, $found['hash'], $found['payer'] );
		if ( 'used' === $result ) {
			return self::error( 409, __( 'That payment was already used.', 'nano-unlock' ) );
		}
		$row = Nano_Unlock_Store::get( $id );
		if ( ! $row || 'paid' !== $row['status'] ) {
			// The payment was found but not recorded (a database error): the next check finds it again.
			return self::error( 503, __( 'The network check is busy, try again in a moment.', 'nano-unlock' ) );
		}
		return self::paid( $row );
	}

	/**
	 * The answer for a paid checkout: "paid" only when this browser now holds its receipt.
	 *
	 * The reader's page reloads on "paid", so a "paid" without the receipt
	 * cookie would lose the checkout. Every other case is an error that the
	 * page shows, and the page keeps the checkout to ask again.
	 *
	 * @param array $row The paid checkout.
	 * @return WP_REST_Response|WP_Error
	 */
	private static function paid( array $row ) {
		if ( ! self::is_starter( $row ) ) {
			return self::error( 403, __( 'This checkout is paid, but it was started in another browser (or this browser does not keep cookies for this site), so it cannot be unlocked here.', 'nano-unlock' ), 'nano_unlock_not_starter' );
		}
		// A browser that lost the first answer still gets its receipt, for an hour.
		if ( time() - (int) $row['paid_at'] >= HOUR_IN_SECONDS ) {
			return self::error( 410, __( 'This checkout was paid more than an hour ago, so its access can no longer be given to a browser.', 'nano-unlock' ), 'nano_unlock_too_late' );
		}
		if ( ! self::grant( $row ) ) {
			return self::error( 500, __( 'Your payment is recorded, but this browser did not get the unlock cookie. Allow cookies for this site, then try again.', 'nano-unlock' ), 'nano_unlock_receipt' );
		}
		return self::ok( array( 'paid' => true ) );
	}

	/**
	 * Adds a paid checkout's item to this browser's receipts cookie.
	 *
	 * @param array $row The checkout.
	 * @return bool Whether the cookie was sent.
	 */
	private static function grant( array $row ) {
		/**
		 * Filters how long a receipt keeps an item unlocked on the buyer's browser, in seconds.
		 *
		 * @param int    $seconds 30 days.
		 * @param string $item    The item key.
		 */
		$seconds = (int) apply_filters( 'nano_unlock_receipt_seconds', 30 * DAY_IN_SECONDS, $row['item'] );
		$name    = Nano_Unlock_Render::RECEIPTS_COOKIE;
		$current = isset( $_COOKIE[ $name ] ) && is_string( $_COOKIE[ $name ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ $name ] ) ) : '';
		$tokens  = Nano_Unlock::tokens();
		$token   = $tokens->add_receipt( $current, (string) $row['item'], (string) $row['id'], $seconds );
		// The cookie lives as long as its longest receipt.
		$until = time() + $seconds;
		foreach ( $tokens->receipts( $token ) as $receipt ) {
			$until = max( $until, $receipt['e'] );
		}
		return self::cookie( $name, $token, $until - time() );
	}

	/**
	 * This browser's random starter token (made on its first checkout).
	 *
	 * @return string The token, or '' when a new one's cookie could not be sent.
	 */
	private static function starter() {
		$current = isset( $_COOKIE[ self::STARTER_COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ self::STARTER_COOKIE ] ) ) : '';
		if ( preg_match( '/^[0-9a-f]{64}$/', $current ) ) {
			return $current;
		}
		$token = bin2hex( random_bytes( 32 ) );
		return self::cookie( self::STARTER_COOKIE, $token, 30 * DAY_IN_SECONDS ) ? $token : '';
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
	 * @return bool Whether the cookie was sent.
	 */
	private static function cookie( $name, $value, $seconds ) {
		$options = array(
			'expires'  => time() + $seconds,
			'path'     => COOKIEPATH ? COOKIEPATH : '/',
			'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Lax',
		);
		/**
		 * Short-circuits sending one of the plugin's cookies, for a host that sends
		 * cookies its own way (and for tests). Return true when the cookie was sent,
		 * false when it could not be; null sends it with setcookie().
		 *
		 * @param bool|null $sent    Null.
		 * @param string    $name    The cookie's name.
		 * @param string    $value   The cookie's value.
		 * @param array     $options The setcookie() options.
		 */
		$sent = apply_filters( 'nano_unlock_pre_set_cookie', null, $name, $value, $options );
		if ( null === $sent ) {
			$sent = ! headers_sent() && setcookie( $name, $value, $options );
		}
		if ( $sent ) {
			$_COOKIE[ $name ] = $value;
		}
		return (bool) $sent;
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
	 * @param string $code    The error code the script can tell apart.
	 * @return WP_Error
	 */
	private static function error( $status, $message, $code = 'nano_unlock' ) {
		return new WP_Error( $code, $message, array( 'status' => $status ) );
	}
}
