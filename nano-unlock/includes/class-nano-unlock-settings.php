<?php
/**
 * The settings page.
 *
 * @package NanoUnlock
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings → Nano Unlock: where payments go, the default price, and which
 * node checks them. Only users who can manage options see or change it.
 */
final class Nano_Unlock_Settings {

	const OPTION       = 'nano_unlock_settings';
	const DEFAULT_NODE = 'https://node.nano133.com/rpc';

	/**
	 * The settings, with defaults.
	 *
	 * @return array{address:string, usd:string, node:string, node2:string}
	 */
	public static function get() {
		$saved = get_option( self::OPTION, array() );
		return array_merge(
			array(
				'address'     => '',
				'usd'         => '0.05',
				'node'        => self::DEFAULT_NODE,
				'node2'       => '',
				'delete_data' => false,
			),
			is_array( $saved ) ? $saved : array()
		);
	}

	/**
	 * Whether the plugin can sell: a valid address is set.
	 *
	 * @return bool
	 */
	public static function ready() {
		return Nano_Unlock_Address::is_valid( self::get()['address'] ) && ( ! self::test_mode() || self::test_allowed() );
	}

	/**
	 * Whether a node set on this page is a test node: not https, or a local or private host.
	 * A test node can't see real payments, so a reader's real money would be sent and never unlock anything.
	 *
	 * @return bool
	 */
	public static function test_mode() {
		$s = self::get();
		return self::is_test_node( $s['node'] ) || ( '' !== $s['node2'] && self::is_test_node( $s['node2'] ) );
	}

	/**
	 * Whether a test helper allows checkouts against a test node (the plugin's own end-to-end test does).
	 *
	 * @return bool
	 */
	public static function test_allowed() {
		/**
		 * Filters whether checkouts may start while a test node is set. Never enable it on a site that sells.
		 *
		 * @param bool $allowed False.
		 */
		return (bool) apply_filters( 'nano_unlock_allow_test_node', false );
	}

	/**
	 * Whether $url is a test node rather than a public https node.
	 *
	 * @param string $url The node URL.
	 * @return bool
	 */
	public static function is_test_node( $url ) {
		if ( 'https' !== wp_parse_url( $url, PHP_URL_SCHEME ) ) {
			return true;
		}
		$host = strtolower( trim( (string) wp_parse_url( $url, PHP_URL_HOST ), '[]' ) );
		if ( '' === $host || ( false === strpos( $host, '.' ) && false === strpos( $host, ':' ) ) ) {
			return true;
		}
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return ! filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
		}
		foreach ( array( '.localhost', '.local', '.internal', '.test', '.invalid', '.example', '.lan', '.home.arpa' ) as $suffix ) {
			if ( substr( '.' . $host, -strlen( $suffix ) ) === $suffix ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( NANO_UNLOCK_FILE ), array( __CLASS__, 'links' ) );
	}

	/**
	 * The "Settings" link on the plugins screen.
	 *
	 * @param string[] $links Links.
	 * @return string[]
	 */
	public static function links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=nano-unlock' ) ) . '">' . esc_html__( 'Settings', 'nano-unlock' ) . '</a>' );
		return $links;
	}

	/**
	 * Adds the page.
	 */
	public static function menu() {
		add_options_page( __( 'Nano Unlock', 'nano-unlock' ), __( 'Nano Unlock', 'nano-unlock' ), 'manage_options', 'nano-unlock', array( __CLASS__, 'page' ) );
	}

	/**
	 * Registers the setting and its fields.
	 */
	public static function register() {
		register_setting(
			'nano_unlock',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'show_in_rest'      => false,
			)
		);
		add_settings_section( 'nano_unlock_main', '', '__return_false', 'nano-unlock' );
		$fields = array(
			'address'     => __( 'Your Nano address', 'nano-unlock' ),
			'usd'         => __( 'Default price (USD)', 'nano-unlock' ),
			'node'        => __( 'Node RPC URL', 'nano-unlock' ),
			'node2'       => __( 'Second node (optional)', 'nano-unlock' ),
			'delete_data' => __( 'When the plugin is deleted', 'nano-unlock' ),
		);
		foreach ( $fields as $key => $label ) {
			add_settings_field(
				'nano_unlock_' . $key,
				$label,
				array( __CLASS__, 'field' ),
				'nano-unlock',
				'nano_unlock_main',
				array(
					'key'       => $key,
					'label_for' => 'nano_unlock_' . $key,
				)
			);
		}
	}

	/**
	 * Checks and cleans the submitted settings. A bad value keeps the old one and shows an error.
	 *
	 * @param mixed $input The submitted values.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$old   = self::get();
		$input = is_array( $input ) ? $input : array();
		$out   = $old;

		$address = isset( $input['address'] ) ? strtolower( trim( sanitize_text_field( $input['address'] ) ) ) : '';
		if ( 0 === strpos( $address, 'xrb_' ) ) {
			$address = 'nano_' . substr( $address, 4 );
		}
		if ( '' === $address || Nano_Unlock_Address::is_valid( $address ) ) {
			$out['address'] = $address;
		} else {
			add_settings_error( self::OPTION, 'address', __( 'That Nano address is not valid (check for a typo: the last 8 characters are a checksum). The old address is kept.', 'nano-unlock' ) );
		}

		$usd = isset( $input['usd'] ) ? self::price( $input['usd'] ) : null;
		if ( null !== $usd ) {
			$out['usd'] = $usd;
		} else {
			add_settings_error( self::OPTION, 'usd', __( 'The default price must be between $0.01 and $1000. The old price is kept.', 'nano-unlock' ) );
		}

		foreach ( array( 'node', 'node2' ) as $key ) {
			$url = isset( $input[ $key ] ) ? trim( (string) $input[ $key ] ) : '';
			if ( 'node' === $key && '' === $url ) {
				$url = self::DEFAULT_NODE;
			}
			if ( '' === $url || self::is_node_url( $url ) ) {
				$out[ $key ] = esc_url_raw( $url, array( 'http', 'https' ) );
			} else {
				add_settings_error( self::OPTION, $key, __( 'A node URL must start with https:// (or http://). The old URL is kept.', 'nano-unlock' ) );
			}
		}
		$out['delete_data'] = ! empty( $input['delete_data'] );
		if ( $out['node2'] === $out['node'] ) {
			$out['node2'] = '';
		}
		return $out;
	}

	/**
	 * A price in dollars as a clean string, or null.
	 *
	 * @param mixed $value The value.
	 * @return string|null
	 */
	public static function price( $value ) {
		$value = trim( str_replace( '$', '', (string) $value ) );
		if ( ! is_numeric( $value ) ) {
			return null;
		}
		$usd = round( (float) $value, 4 );
		if ( $usd < 0.01 || $usd > 1000 ) {
			return null;
		}
		return rtrim( rtrim( number_format( $usd, 4, '.', '' ), '0' ), '.' );
	}

	/**
	 * Whether $url is an http(s) URL.
	 *
	 * @param string $url The URL.
	 * @return bool
	 */
	private static function is_node_url( $url ) {
		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		return false !== filter_var( $url, FILTER_VALIDATE_URL ) && in_array( $scheme, array( 'http', 'https' ), true );
	}

	/**
	 * One field.
	 *
	 * @param array $args The field's key.
	 */
	public static function field( $args ) {
		$s    = self::get();
		$key  = $args['key'];
		$name = self::OPTION . '[' . $key . ']';
		if ( 'delete_data' === $key ) {
			printf(
				'<label><input type="checkbox" id="nano_unlock_delete_data" name="%1$s" value="1" %2$s /> %3$s</label><p class="description">%4$s</p>',
				esc_attr( $name ),
				checked( ! empty( $s['delete_data'] ), true, false ),
				esc_html__( 'Also delete the paid parts of every post, the sales and these settings', 'nano-unlock' ),
				esc_html__( 'Off: deleting the plugin keeps everything, and the paid parts stay hidden (they are stored apart from the posts). Turning the plugin off never deletes anything.', 'nano-unlock' )
			);
			return;
		}
		$help        = array(
			'address' => __( 'Readers pay this address directly. The plugin never holds a key or any money.', 'nano-unlock' ),
			'usd'     => __( 'Used when a paid part names no price. Readers pay the same value in XNO at the current rate.', 'nano-unlock' ),
			'node'    => __( 'The node that proves payments. The default is the public node of nano133.com; any Nano node RPC works.', 'nano-unlock' ),
			'node2'   => __( 'If set, a payment counts only when both nodes confirm it.', 'nano-unlock' ),
		);
		$placeholder = array(
			'address' => 'nano_…',
			'usd'     => '0.05',
			'node'    => self::DEFAULT_NODE,
			'node2'   => 'https://…',
		);
		printf(
			'<input type="%1$s" id="nano_unlock_%2$s" name="%3$s" value="%4$s" placeholder="%5$s" class="%6$s" %7$s /><p class="description">%8$s</p>',
			'usd' === $key ? 'text' : ( 'address' === $key ? 'text' : 'url' ),
			esc_attr( $key ),
			esc_attr( $name ),
			esc_attr( $s[ $key ] ),
			esc_attr( $placeholder[ $key ] ),
			'usd' === $key ? 'small-text' : 'large-text code',
			'usd' === $key ? 'inputmode="decimal"' : 'spellcheck="false" autocomplete="off"',
			esc_html( $help[ $key ] )
		);
	}

	/**
	 * The page.
	 */
	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$sales = Nano_Unlock_Store::recent_sales( 20 );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Nano Unlock', 'nano-unlock' ); ?></h1>
			<p><?php esc_html_e( 'Sell part of a post for a few cents in Nano (XNO). Wrap the paid part in the "Nano Unlock" block, or in [nano_unlock price="0.05"] … [/nano_unlock].', 'nano-unlock' ); ?></p>
			<?php if ( self::test_mode() ) : ?>
				<div class="notice notice-error inline"><p><strong><?php esc_html_e( 'Test node: real payments will not be seen.', 'nano-unlock' ); ?></strong>
				<?php
				echo esc_html(
					self::test_allowed()
						? __( 'A test helper allows checkouts anyway. Do not send real money to this site.', 'nano-unlock' )
						: __( 'A node above is not a public https node, so checkouts are refused. Set a public node (the default is fine) to sell.', 'nano-unlock' )
				);
				?>
				</p></div>
			<?php endif; ?>
			<?php if ( ! Nano_Unlock_Address::is_valid( self::get()['address'] ) ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'Add your Nano address to start selling. Until then, readers see the paid parts as "not for sale yet".', 'nano-unlock' ); ?></p></div>
			<?php endif; ?>
			<form action="options.php" method="post">
				<?php
				settings_fields( 'nano_unlock' );
				do_settings_sections( 'nano-unlock' );
				submit_button();
				?>
			</form>
			<h2><?php esc_html_e( 'Recent sales', 'nano-unlock' ); ?></h2>
			<?php if ( ! $sales ) : ?>
				<p><?php esc_html_e( 'No sales yet.', 'nano-unlock' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead><tr>
						<th><?php esc_html_e( 'When', 'nano-unlock' ); ?></th>
						<th><?php esc_html_e( 'Post', 'nano-unlock' ); ?></th>
						<th><?php esc_html_e( 'Price', 'nano-unlock' ); ?></th>
						<th><?php esc_html_e( 'Paid', 'nano-unlock' ); ?></th>
						<th><?php esc_html_e( 'From', 'nano-unlock' ); ?></th>
						<th><?php esc_html_e( 'Block', 'nano-unlock' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $sales as $sale ) : ?>
						<tr>
							<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $sale['paid_at'] ) ); ?></td>
							<td><a href="<?php echo esc_url( get_permalink( (int) $sale['post_id'] ) ); ?>"><?php echo esc_html( get_the_title( (int) $sale['post_id'] ) ); ?></a></td>
							<td>$<?php echo esc_html( rtrim( rtrim( (string) $sale['usd'], '0' ), '.' ) ); ?></td>
							<td>Ӿ<?php echo esc_html( Nano_Unlock_Amount::to_xno_short( substr( (string) $sale['amount'], 0, -6 ) . '000000', 6 ) ); ?></td>
							<td><code><?php echo esc_html( Nano_Unlock_Address::short( (string) $sale['payer'] ) ); ?></code></td>
							<td><a href="<?php echo esc_url( 'https://nanexplorer.com/nano/block/' . $sale['hash'] ); ?>" target="_blank" rel="noopener noreferrer"><code><?php echo esc_html( substr( (string) $sale['hash'], 0, 10 ) ); ?>…</code></a></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
			<h2><?php esc_html_e( 'How payments are checked', 'nano-unlock' ); ?></h2>
			<p><?php esc_html_e( 'Each checkout asks for a unique amount (the price plus a tiny tail in its last digits). This site asks the node above for a confirmed payment of exactly that amount to your address, sent after the checkout started. One payment unlocks one item, once. The reader keeps access on that browser through a signed cookie.', 'nano-unlock' ); ?></p>
		</div>
		<?php
	}
}
