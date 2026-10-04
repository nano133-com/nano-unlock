<?php
/**
 * The paid part of a post: the shortcode and the block.
 *
 * @package NanoUnlock
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders a paid part.
 *
 * The server decides: the paid content is only in the HTML when the request
 * carries a valid receipt for it (or comes from someone who can edit the
 * post). Otherwise the HTML holds the price and a signed offer, never the
 * content, hidden or not.
 *
 * Only the post's own page may show the paid content, because only that page
 * is marked as not cacheable. Everywhere else (the home page, archives,
 * search, feeds, another post's page that lists this one, the REST API) a
 * paid part is a link to the post's page, and the receipt is not even read.
 *
 * An item is "post:{ID}:{slot}". The slot is the part's `id` attribute when
 * it has one, or its position among the post's paid parts.
 */
final class Nano_Unlock_Render {

	/**
	 * The httpOnly cookie that holds this browser's receipts (see Nano_Unlock_Token).
	 */
	const RECEIPTS_COOKIE = 'nano_unlock_receipts';

	/**
	 * Paid parts rendered so far, per post, in this rendering of its content.
	 *
	 * @var int[]
	 */
	private static $counter = array();

	/**
	 * Hooks.
	 */
	public static function init() {
		add_shortcode( 'nano_unlock', array( __CLASS__, 'shortcode' ) );
		// Each rendering of a post's content counts its paid parts from zero (the_content can run more than once).
		add_filter( 'the_content', array( __CLASS__, 'reset' ), 1 );
		add_action( 'template_redirect', array( __CLASS__, 'no_cache' ) );
		add_action( 'init', array( __CLASS__, 'block' ) );
		// Registered early: block themes render the content before wp_enqueue_scripts runs.
		add_action( 'init', array( __CLASS__, 'register_assets' ) );
	}

	/**
	 * Starts the count of paid parts for the post being rendered.
	 *
	 * @param string $content The content.
	 * @return string
	 */
	public static function reset( $content ) {
		self::$counter[ (int) get_the_ID() ] = 0;
		return $content;
	}

	/**
	 * A page with a paid part must not be cached: its HTML depends on the reader's receipt.
	 */
	public static function no_cache() {
		if ( ! is_singular() ) {
			return;
		}
		$post = get_post();
		if ( $post && ( has_shortcode( $post->post_content, 'nano_unlock' ) || has_block( 'nano-unlock/paywall', $post ) ) ) {
			self::uncacheable();
		}
	}

	/**
	 * Tells the browser, proxies and page-cache plugins not to keep this page.
	 *
	 * Called before the page starts (no_cache) and again when a paid part is
	 * rendered on its post's page, for a part the first check could not see
	 * (in a pattern or a template, for example). By then the headers may be
	 * gone, but page-cache plugins read DONOTCACHEPAGE when the page ends.
	 */
	private static function uncacheable() {
		if ( ! headers_sent() ) {
			nocache_headers();
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- the constant page-cache plugins read.
		}
	}

	/**
	 * Whether this request is the post's own page: the only place its paid part may be shown.
	 *
	 * @param int $post_id The post being rendered.
	 * @return bool
	 */
	public static function own_page( $post_id ) {
		return is_singular() && (int) get_queried_object_id() === (int) $post_id;
	}

	/**
	 * Registers the block (its editor script is plain JS, no build step).
	 */
	public static function block() {
		register_block_type(
			NANO_UNLOCK_DIR . 'blocks/paywall',
			array( 'render_callback' => array( __CLASS__, 'render_block' ) )
		);
	}

	/**
	 * Registers the reader's script and style (enqueued only where a locked part is shown).
	 */
	public static function register_assets() {
		wp_register_script( 'nano-unlock-qr', NANO_UNLOCK_URL . 'assets/vendor/qrcode.js', array(), '2.0.4', true );
		wp_register_script( 'nano-unlock', NANO_UNLOCK_URL . 'assets/checkout.js', array( 'nano-unlock-qr' ), NANO_UNLOCK_VERSION, true );
		wp_register_style( 'nano-unlock', NANO_UNLOCK_URL . 'assets/checkout.css', array(), NANO_UNLOCK_VERSION );
	}

	/**
	 * The [nano_unlock price="0.05" id="…"]…[/nano_unlock] shortcode.
	 *
	 * @param array|string $atts    Attributes.
	 * @param string|null  $content The paid content.
	 * @return string
	 */
	public static function shortcode( $atts, $content = null ) {
		$atts = shortcode_atts(
			array(
				'price' => '',
				'id'    => '',
				'part'  => '',
			),
			$atts,
			'nano_unlock'
		);
		$part = (string) $atts['part'];
		return self::render(
			(string) $atts['price'],
			(string) $atts['id'],
			function () use ( $content, $part ) {
				// The paid text is stored in post meta (see Nano_Unlock_Parts); inline text is from before that.
				$stored = '' !== $part ? Nano_Unlock_Parts::get( (int) get_the_ID(), $part ) : null;
				return do_shortcode( null !== $stored ? $stored : (string) $content );
			}
		);
	}

	/**
	 * The block's server-side render.
	 *
	 * @param array  $attributes The block's attributes.
	 * @param string $content    The rendered inner blocks.
	 * @return string
	 */
	public static function render_block( $attributes, $content ) {
		return self::render(
			isset( $attributes['price'] ) ? (string) $attributes['price'] : '',
			isset( $attributes['itemId'] ) ? (string) $attributes['itemId'] : '',
			function () use ( $attributes, $content ) {
				$stored = isset( $attributes['partId'] ) ? Nano_Unlock_Parts::get( (int) get_the_ID(), (string) $attributes['partId'] ) : null;
				return null !== $stored ? do_blocks( $stored ) : (string) $content;
			}
		);
	}

	/**
	 * The item key for a post and slot.
	 *
	 * @param int    $post_id The post.
	 * @param string $slot    The slot.
	 * @return string
	 */
	public static function item( $post_id, $slot ) {
		return 'post:' . (int) $post_id . ':' . $slot;
	}

	/**
	 * The receipt for $item in this request, or null.
	 *
	 * @param string $item The item key.
	 * @return array|null array( 'i' => item, 'c' => checkout id, 'e' => expiry ).
	 */
	public static function receipt( $item ) {
		if ( empty( $_COOKIE[ self::RECEIPTS_COOKIE ] ) || ! is_string( $_COOKIE[ self::RECEIPTS_COOKIE ] ) ) {
			return null;
		}
		$token = sanitize_text_field( wp_unslash( $_COOKIE[ self::RECEIPTS_COOKIE ] ) );
		return Nano_Unlock::tokens()->check_receipt( $token, $item );
	}

	/**
	 * The note under an unlocked part: who paid, from the checkout's row.
	 *
	 * @param array $receipt The receipt.
	 * @return string
	 */
	private static function paid_note( array $receipt ) {
		$row = Nano_Unlock_Store::get( (string) $receipt['c'] );
		if ( ! $row || 'paid' !== $row['status'] || '' === (string) $row['payer'] ) {
			return __( 'Unlocked with Nano', 'nano-unlock' );
		}
		/* translators: %s: the paying Nano address, shortened. */
		return sprintf( __( 'Unlocked with Nano by %s', 'nano-unlock' ), Nano_Unlock_Address::short( (string) $row['payer'] ) );
	}

	/**
	 * Renders one paid part.
	 *
	 * @param string   $price   The price attribute (dollars), or "" for the default.
	 * @param string   $id      The id attribute, or "".
	 * @param callable $content Returns the paid content's HTML (called only when it may be shown).
	 * @return string
	 */
	private static function render( $price, $id, $content ) {
		$post_id = (int) get_the_ID();
		if ( ! $post_id ) {
			return '';
		}
		if ( ! isset( self::$counter[ $post_id ] ) ) {
			self::$counter[ $post_id ] = 0;
		}
		$position = self::$counter[ $post_id ]++;
		$slot     = '' !== $id ? substr( sanitize_key( $id ), 0, 40 ) : (string) $position;
		$item     = self::item( $post_id, $slot );
		$usd      = Nano_Unlock_Settings::price( '' !== $price ? $price : Nano_Unlock_Settings::get()['usd'] );
		$usd      = null === $usd ? Nano_Unlock_Settings::get()['usd'] : $usd;

		wp_enqueue_style( 'nano-unlock' );
		if ( ! self::own_page( $post_id ) ) {
			// A cached archive or feed must never hold one reader's paid part: send everyone to the post's page.
			return self::elsewhere( $post_id, $slot, $usd );
		}
		self::uncacheable();
		$receipt = self::receipt( $item );
		if ( $receipt ) {
			return '<div class="nano-unlock nano-unlock--paid" id="' . esc_attr( 'nano-unlock-' . $slot ) . '">' . $content() . '<p class="nano-unlock__note">'
				. esc_html( self::paid_note( $receipt ) )
				. '</p></div>';
		}

		if ( current_user_can( 'edit_post', $post_id ) ) {
			return '<div class="nano-unlock nano-unlock--preview"><p class="nano-unlock__note">'
				/* translators: %s: the price in dollars. */
				. esc_html( sprintf( __( 'Readers see a paywall here: they pay $%s in Nano to read this part. You see it because you can edit this post.', 'nano-unlock' ), $usd ) )
				. '</p>' . $content() . '</div>';
		}

		/* translators: %s: the price in dollars. */
		$label = sprintf( __( 'Unlock for $%s', 'nano-unlock' ), $usd );
		$html  = '<div class="nano-unlock nano-unlock--locked" id="' . esc_attr( 'nano-unlock-' . $slot ) . '"';
		if ( Nano_Unlock_Settings::ready() ) {
			$post  = get_post( $post_id );
			$offer = Nano_Unlock::tokens()->sign(
				'offer',
				array(
					'p' => $post_id,
					's' => $slot,
					'u' => $usd,
					'm' => $post ? $post->post_modified_gmt : '',
				)
			);
			$html .= ' data-nano-unlock-offer="' . esc_attr( $offer ) . '" data-nano-unlock-item="' . esc_attr( $item ) . '"';
			self::enqueue();
		}
		$html .= '><div class="nano-unlock__head"><span class="nano-unlock__mark" aria-hidden="true">Ӿ</span><strong>' . esc_html__( 'The rest of this is paid', 'nano-unlock' ) . '</strong></div>';
		if ( Nano_Unlock_Settings::ready() && Nano_Unlock_Settings::test_mode() ) {
			$html .= '<p class="nano-unlock__test">' . esc_html__( 'TEST MODE: this site uses a test node. Do not send real money.', 'nano-unlock' ) . '</p>';
		}
		if ( Nano_Unlock_Settings::ready() ) {
			$html .= '<p class="nano-unlock__text">'
				/* translators: %s: the price in dollars. */
				. esc_html( sprintf( __( 'Pay $%s in Nano (XNO) to read it. No account and no card: it arrives in about a second and unlocks on this browser.', 'nano-unlock' ), $usd ) )
				. '</p><button type="button" class="nano-unlock__button">' . esc_html( $label ) . '</button><div class="nano-unlock__checkout" hidden></div>';
		} else {
			$html .= '<p class="nano-unlock__text">' . esc_html__( 'This part is not for sale yet.', 'nano-unlock' ) . '</p>';
		}
		return $html . '</div>';
	}

	/**
	 * A paid part outside its post's page: the price and a link to the page.
	 *
	 * @param int    $post_id The post.
	 * @param string $slot    The part's slot.
	 * @param string $usd     The price in dollars.
	 * @return string
	 */
	private static function elsewhere( $post_id, $slot, $usd ) {
		return '<div class="nano-unlock nano-unlock--elsewhere"><div class="nano-unlock__head"><span class="nano-unlock__mark" aria-hidden="true">Ӿ</span><strong>' . esc_html__( 'The rest of this is paid', 'nano-unlock' ) . '</strong></div>'
			. '<p class="nano-unlock__text"><a href="' . esc_url( get_permalink( $post_id ) . '#nano-unlock-' . $slot ) . '">'
			/* translators: %s: the price in dollars. */
			. esc_html( sprintf( __( 'Read it on the post\'s page ($%s in Nano)', 'nano-unlock' ), $usd ) )
			. '</a></p></div>';
	}

	/**
	 * The reader's script, with what it needs to talk to this site.
	 */
	private static function enqueue() {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;
		wp_enqueue_script( 'nano-unlock' );
		wp_localize_script(
			'nano-unlock',
			'nanoUnlock',
			array(
				'rest'  => esc_url_raw( rest_url( 'nano-unlock/v1/' ) ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
				'late'  => Nano_Unlock::LATE_SECONDS,
				'text'  => array(
					'starting' => __( 'Starting…', 'nano-unlock' ),
					'pay'      => __( 'Pay exactly', 'nano-unlock' ),
					'to'       => __( 'to', 'nano-unlock' ),
					'open'     => __( 'Open in wallet', 'nano-unlock' ),
					'copy'     => __( 'Copy', 'nano-unlock' ),
					'copied'   => __( 'Copied', 'nano-unlock' ),
					'amount'   => __( 'Amount', 'nano-unlock' ),
					'address'  => __( 'Address', 'nano-unlock' ),
					'waiting'  => __( 'Waiting for your payment…', 'nano-unlock' ),
					'pending'  => __( 'Payment seen, confirming…', 'nano-unlock' ),
					'paid'     => __( 'Paid. Unlocking…', 'nano-unlock' ),
					'expired'  => __( 'This checkout has expired. If you already paid, keep this page open: the payment is still being looked for.', 'nano-unlock' ),
					'gone'     => __( 'This checkout can no longer be paid. Start again.', 'nano-unlock' ),
					'busy'     => __( 'The network check is busy; still trying.', 'nano-unlock' ),
					'reload'   => __( 'This page is out of date. Reload it and try again.', 'nano-unlock' ),
					'exact'    => __( 'Send the exact amount, from a wallet you control. The last digits identify your payment.', 'nano-unlock' ),
					'left'     => __( 'left', 'nano-unlock' ),
					'cancel'   => __( 'Cancel', 'nano-unlock' ),
					'retry'    => __( 'Try again', 'nano-unlock' ),
					'kept'     => __( 'Your payment is recorded, but this browser did not keep the unlock cookie. Allow cookies for this site, then press Try again.', 'nano-unlock' ),
					'test'     => __( 'TEST MODE: do not send real money. This site uses a test node.', 'nano-unlock' ),
				),
			)
		);
	}
}
