<?php
/**
 * The plugin.
 *
 * @package NanoUnlock
 */

defined( 'ABSPATH' ) || exit;

/**
 * The plugin: its constants, its key, activation and hooks.
 */
final class Nano_Unlock {

	/**
	 * How long a checkout waits for its payment.
	 */
	const CHECKOUT_SECONDS = 900;

	/**
	 * A payment that arrives this long after the checkout expired still counts (its amount stays reserved).
	 */
	const LATE_SECONDS = 3600;

	const SECRET_OPTION = 'nano_unlock_secret';

	/**
	 * The signer for offers and receipts.
	 *
	 * @var Nano_Unlock_Token|null
	 */
	private static $tokens = null;

	/**
	 * Hooks.
	 */
	public static function init() {
		Nano_Unlock_Settings::init();
		Nano_Unlock_Parts::init();
		Nano_Unlock_Render::init();
		Nano_Unlock_Rest::init();
		add_action( 'nano_unlock_prune', array( __CLASS__, 'prune' ) );
		add_action( 'plugins_loaded', array( __CLASS__, 'upgrade' ) );
		add_action( 'admin_notices', array( __CLASS__, 'db_notice' ) );
	}

	/**
	 * Activation: the table, the key and the daily clean-up.
	 */
	public static function activate() {
		Nano_Unlock_Store::install();
		self::secret();
		Nano_Unlock_Parts::migrate();
		self::schedule();
	}

	/**
	 * The daily clean-up, on this site (on a network, each site gets it on its first load: see upgrade()).
	 */
	private static function schedule() {
		if ( ! wp_next_scheduled( 'nano_unlock_prune' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'nano_unlock_prune' );
		}
	}

	/**
	 * Deactivation: stop the clean-up (the data stays until the plugin is deleted).
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'nano_unlock_prune' );
	}

	/**
	 * Creates the table when an update brings a new version of it (and on a network's sub-site, on its
	 * first load). An update that failed is tried again on a later load, at most once a minute.
	 */
	public static function upgrade() {
		if ( self::db_ready() || get_transient( 'nano_unlock_db_retry' ) ) {
			return;
		}
		if ( ! Nano_Unlock_Store::install() ) {
			set_transient( 'nano_unlock_db_retry', 1, MINUTE_IN_SECONDS );
		}
		self::schedule();
	}

	/**
	 * Whether the table is at this version (checkouts can start).
	 *
	 * @return bool
	 */
	public static function db_ready() {
		return get_option( 'nano_unlock_db_version' ) === Nano_Unlock_Store::DB_VERSION;
	}

	/**
	 * On the dashboard, for admins: the table update failed, so readers can't start a checkout.
	 */
	public static function db_notice() {
		if ( ! current_user_can( 'manage_options' ) || self::db_ready() || ! get_option( 'nano_unlock_db_error' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Nano Unlock could not update its database table.', 'nano-unlock' ) . '</strong> '
			/* translators: %s: the table's name. */
			. esc_html( sprintf( __( 'Readers can\'t start a checkout until the table %s has its new columns. The database user needs the ALTER right. The plugin tries again on a later page load, at most once a minute.', 'nano-unlock' ), Nano_Unlock_Store::table() ) )
			. '</p></div>';
	}

	/**
	 * Deletes old unpaid checkouts.
	 */
	public static function prune() {
		Nano_Unlock_Store::prune( self::LATE_SECONDS );
	}

	/**
	 * The site's signing key: 32 random bytes made once and kept in the options table (not autoloaded).
	 *
	 * @return string
	 */
	private static function secret() {
		$hex = get_option( self::SECRET_OPTION );
		if ( ! is_string( $hex ) || ! preg_match( '/^[0-9a-f]{64}$/', $hex ) ) {
			$hex = bin2hex( random_bytes( 32 ) );
			update_option( self::SECRET_OPTION, $hex, false );
		}
		return (string) hex2bin( $hex );
	}

	/**
	 * The signer for offers and receipts.
	 *
	 * @return Nano_Unlock_Token
	 */
	public static function tokens() {
		if ( null === self::$tokens ) {
			self::$tokens = new Nano_Unlock_Token( self::secret() );
		}
		return self::$tokens;
	}
}
