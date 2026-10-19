<?php
/**
 * The checkouts table.
 *
 * @package NanoUnlock
 */

defined( 'ABSPATH' ) || exit;

// This class is the only code that touches the plugin's own table. Its rows change on every poll,
// so there is nothing to cache. Every query goes through $wpdb->prepare(), the table name as an
// identifier (%i); only CREATE TABLE, for dbDelta(), names it in the SQL.
// phpcs:disable WordPress.DB.DirectDatabaseQuery

/**
 * One row per checkout, in the plugin's own table.
 *
 * Two unique keys carry the money rules:
 *
 * - `amount_lock` holds the checkout's amount while a payment for it may still
 *   be matched: from the start, through the checkout's 15 minutes and the hour
 *   for late payments, plus HOLD_AFTER. No two checkouts ever hold the same
 *   amount at once, so a payment can match only one of them. The amount is
 *   freed (NULL: NULL values don't collide) at once when the checkout is paid,
 *   CANCEL_HOLD after it is cancelled, or when that time is over.
 * - `hash` is the payment's block hash, so on this site one payment unlocks one checkout,
 *   once. A paid row is the record of the sale and is kept.
 */
final class Nano_Unlock_Store {

	const DB_VERSION = '2';

	/**
	 * The most amounts one price can have open at once: the price and up to 99 steps of
	 * 0.0001 XNO above it (at most 0.0099 XNO more). See steps().
	 */
	const MAX_STEPS = 100;

	/**
	 * The steps above a price never add more than this share of it, in percent: a 1¢ price
	 * (about 0.0303 XNO) gets at most 30 steps more. When all are held, the reader is asked
	 * to try again in a moment.
	 */
	const MAX_EXTRA_PERCENT = 10;

	/**
	 * Seconds a cancelled checkout's amount stays held. A payment the node first sees within a
	 * minute after Cancel can then never count for the next checkout that gets the amount
	 * (that one only counts payments from CLOCK_SLACK before its own start).
	 */
	const CANCEL_HOLD = 180;

	/**
	 * Seconds an unpaid amount stays held after its late window: more than the clock allowance
	 * (Nano_Unlock_Verifier::CLOCK_SLACK), so a payment sent at the very end of one checkout's
	 * window can never count for the next checkout that gets the same amount.
	 */
	const HOLD_AFTER = 300;

	/**
	 * The most amounts one visitor (one IP address, or one IPv6 /64) may hold at once,
	 * so that one visitor alone can never use up the steps of a price.
	 */
	const MAX_HELD = 10;

	/**
	 * The table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'nano_unlock_checkouts';
	}

	/**
	 * Creates or updates the table. The version is recorded only when the table really has this
	 * version's columns: an update that fails (a database user without ALTER, a lock) is tried
	 * again later, and the settings screens say so (Nano_Unlock::db_notice()).
	 *
	 * @return bool Whether the table is ready.
	 */
	public static function install() {
		global $wpdb;
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
  id char(24) NOT NULL,
  item varchar(100) NOT NULL,
  post_id bigint(20) unsigned NOT NULL DEFAULT 0,
  usd decimal(10,4) NOT NULL DEFAULT 0,
  amount varchar(40) NOT NULL,
  amount_lock varchar(40) NULL,
  address varchar(65) NOT NULL,
  starter char(64) NOT NULL,
  visitor char(16) NULL,
  status varchar(10) NOT NULL DEFAULT 'waiting',
  cancelled_at bigint(20) NULL,
  created_at bigint(20) NOT NULL,
  expires_at bigint(20) NOT NULL,
  hash char(64) NULL,
  payer varchar(65) NULL,
  paid_at bigint(20) NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY amount_lock (amount_lock),
  UNIQUE KEY hash (hash),
  KEY status (status, expires_at),
  KEY visitor (visitor)
) {$charset};"
		);
		if ( ! self::table_ready() ) {
			update_option( 'nano_unlock_db_error', time(), false );
			return false;
		}
		delete_option( 'nano_unlock_db_error' );
		update_option( 'nano_unlock_db_version', self::DB_VERSION, false );
		return true;
	}

	/**
	 * Whether the table has every column this version reads and writes.
	 *
	 * @return bool
	 */
	public static function table_ready() {
		global $wpdb;
		$suppress = $wpdb->suppress_errors( true );
		$ok       = false !== $wpdb->query( $wpdb->prepare( 'SELECT id, visitor, cancelled_at FROM %i LIMIT 1', self::table() ) );
		$wpdb->suppress_errors( $suppress );
		return $ok;
	}

	/**
	 * How many amounts a price may use: the price itself and the steps above it, at most
	 * MAX_EXTRA_PERCENT of the price and at most MAX_STEPS amounts in all.
	 *
	 * @param string $price The price in raw, a clean amount.
	 * @return int At least 1.
	 */
	public static function steps( $price ) {
		$base = (int) substr( $price, 0, -Nano_Unlock_Amount::STEP_DIGITS );
		return max( 1, min( self::MAX_STEPS, intdiv( $base * self::MAX_EXTRA_PERCENT, 100 ) + 1 ) );
	}

	/**
	 * Creates a checkout with the smallest free amount at or above its price.
	 *
	 * The amounts already held in the price's range are read first; then the free
	 * ones are tried in order. Two readers at the same instant may both see an
	 * amount free: the unique key lets only one of them have it, and the other
	 * moves on to the next step.
	 *
	 * @param array  $row   The fields (item, post_id, usd, address, starter, visitor, created_at, expires_at).
	 * @param string $price The price in raw, a clean amount from Nano_Unlock_Amount::from_usd().
	 * @param int    $late  Seconds after expiry that a payment still counts.
	 * @return array The row.
	 * @throws Nano_Unlock_Busy When every amount within steps() is held, or the database refuses the row.
	 */
	public static function create( array $row, $price, $late ) {
		global $wpdb;
		$table = self::table();
		self::release_old( $late );

		$amounts = array();
		for ( $k = 0, $n = self::steps( $price ); $k < $n; $k++ ) {
			$amounts[] = Nano_Unlock_Amount::plus_steps( $price, $k );
		}
		$in   = implode( ', ', array_fill( 0, count( $amounts ), '%s' ) );
		$held = $wpdb->get_col( $wpdb->prepare( "SELECT amount_lock FROM %i WHERE amount_lock IN ($in)", array_merge( array( $table ), $amounts ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in is only %s placeholders.
		$held = array_flip( (array) $held );

		$suppress = $wpdb->suppress_errors( true );
		try {
			foreach ( $amounts as $amount ) {
				if ( isset( $held[ $amount ] ) ) {
					continue;
				}
				$data = array_merge(
					$row,
					array(
						'id'          => bin2hex( random_bytes( 12 ) ),
						'amount'      => $amount,
						'amount_lock' => $amount,
						'status'      => 'waiting',
					)
				);
				if ( $wpdb->insert( $table, $data ) ) {
					return $data;
				}
				// Refused: another checkout took this amount since the read (go on to the next step),
				// or the database failed (stop: the reader is told to try again).
				if ( ! $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE amount_lock = %s', $table, $amount ) ) ) {
					break;
				}
			}
		} finally {
			$wpdb->suppress_errors( $suppress );
		}
		throw new Nano_Unlock_Busy( esc_html( 'no free amount' ) );
	}

	/**
	 * Frees the amounts of checkouts that can no longer be paid (their late window and HOLD_AFTER
	 * are over), and of checkouts cancelled more than CANCEL_HOLD ago.
	 *
	 * @param int $late Seconds after expiry that a payment still counts.
	 */
	public static function release_old( $late ) {
		global $wpdb;
		$now = time();
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET amount_lock = NULL, visitor = NULL WHERE amount_lock IS NOT NULL AND ( expires_at < %d OR ( status = 'cancelled' AND cancelled_at < %d ) )", self::table(), $now - $late - self::HOLD_AFTER, $now - self::CANCEL_HOLD ) );
	}

	/**
	 * Takes the database's named lock for one visitor (MySQL and MariaDB GET_LOCK), waiting up to 3 s.
	 *
	 * @param string $visitor The visitor's key.
	 * @return bool|null True when taken, false when another request held it too long, null when the
	 *                   database has no named locks (SQLite, as in WordPress Playground): go on without.
	 */
	public static function lock( $visitor ) {
		global $wpdb;
		$suppress = $wpdb->suppress_errors( true );
		$got      = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', self::lock_name( $visitor ), 3 ) );
		$wpdb->suppress_errors( $suppress );
		return null === $got ? null : '1' === (string) $got;
	}

	/**
	 * Gives the visitor's lock back.
	 *
	 * @param string $visitor The visitor's key.
	 */
	public static function unlock( $visitor ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::lock_name( $visitor ) ) );
	}

	/**
	 * A lock name for this site's table and one visitor (at most 64 characters).
	 *
	 * @param string $visitor The visitor's key.
	 * @return string
	 */
	private static function lock_name( $visitor ) {
		return 'nano_unlock_' . substr( hash( 'sha256', self::table() . '|' . $visitor ), 0, 32 );
	}

	/**
	 * How many amounts a visitor holds now.
	 *
	 * @param string $visitor The visitor's key (Nano_Unlock_Limit::visitor()).
	 * @param int    $late    Seconds after expiry that a payment still counts.
	 * @return int
	 */
	public static function held_by( $visitor, $late ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE visitor = %s AND amount_lock IS NOT NULL AND expires_at >= %d', self::table(), $visitor, time() - $late - self::HOLD_AFTER ) );
	}

	/**
	 * A checkout by id.
	 *
	 * @param string $id The id.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		if ( ! is_string( $id ) || ! preg_match( '/^[0-9a-f]{24}$/', $id ) ) {
			return null;
		}
		$table = self::table();
		$row   = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %s', $table, $id ), ARRAY_A );
		return $row ? $row : null;
	}

	/**
	 * Marks a waiting checkout paid by a payment, and frees its amount for the next checkout.
	 *
	 * @param string $id    The checkout.
	 * @param string $hash  The payment's block hash.
	 * @param string $payer The paying address.
	 * @return string "paid", "used" (that payment already paid a checkout) or "taken" (this checkout was paid meanwhile).
	 */
	public static function mark_paid( $id, $hash, $payer ) {
		global $wpdb;
		$table    = self::table();
		$suppress = $wpdb->suppress_errors( true );
		$n        = $wpdb->query( $wpdb->prepare( "UPDATE %i SET status = 'paid', hash = %s, payer = %s, paid_at = %d, amount_lock = NULL, visitor = NULL WHERE id = %s AND status = 'waiting'", $table, $hash, $payer, time(), $id ) );
		$wpdb->suppress_errors( $suppress );
		if ( 1 === $n ) {
			return 'paid';
		}
		$other = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE hash = %s', $table, $hash ) );
		return ( $other && $other !== $id ) ? 'used' : 'taken';
	}

	/**
	 * Ends a waiting checkout. A payment that comes after this no longer counts for it. Its
	 * amount stays held for CANCEL_HOLD more (no longer counted for the visitor), then it is free.
	 *
	 * @param string $id The checkout.
	 * @return bool Whether it was cancelled (false: it was not waiting any more).
	 */
	public static function cancel( $id ) {
		global $wpdb;
		$n = $wpdb->query( $wpdb->prepare( "UPDATE %i SET status = 'cancelled', cancelled_at = %d, visitor = NULL WHERE id = %s AND status = 'waiting'", self::table(), time(), $id ) );
		return 1 === $n;
	}

	/**
	 * Which of these block hashes already paid a checkout.
	 *
	 * @param string[] $hashes Upper-case block hashes.
	 * @return string[]
	 */
	public static function used_hashes( array $hashes ) {
		global $wpdb;
		if ( ! $hashes ) {
			return array();
		}
		$in = implode( ', ', array_fill( 0, count( $hashes ), '%s' ) );
		return array_map( 'strtoupper', (array) $wpdb->get_col( $wpdb->prepare( "SELECT hash FROM %i WHERE hash IN ($in)", array_merge( array( self::table() ), array_values( $hashes ) ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in is only %s placeholders.
	}

	/**
	 * The smallest amount among checkouts that may still be paid (for the receivable threshold), or null.
	 *
	 * @param int $late Seconds after expiry that a payment still counts.
	 * @return string|null
	 */
	public static function smallest_open_amount( $late ) {
		global $wpdb;
		$table   = self::table();
		$amounts = $wpdb->get_col( $wpdb->prepare( "SELECT amount FROM %i WHERE status = 'waiting' AND expires_at >= %d", $table, time() - $late ) );
		$min     = null;
		foreach ( $amounts as $a ) {
			if ( null === $min || Nano_Unlock_Amount::compare( $a, $min ) < 0 ) {
				$min = $a;
			}
		}
		return $min;
	}

	/**
	 * The latest sales, for the settings page.
	 *
	 * @param int $limit How many.
	 * @return array[]
	 */
	public static function recent_sales( $limit = 20 ) {
		global $wpdb;
		$table = self::table();
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT item, post_id, usd, amount, payer, hash, paid_at FROM %i WHERE status = 'paid' ORDER BY paid_at DESC LIMIT %d", $table, $limit ), ARRAY_A );
	}

	/**
	 * Deletes unpaid checkouts that can no longer be paid (daily).
	 *
	 * @param int $late Seconds after expiry that a payment still counts.
	 */
	public static function prune( $late ) {
		global $wpdb;
		self::release_old( $late );
		$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE status IN ('waiting', 'cancelled') AND expires_at < %d", self::table(), time() - $late - DAY_IN_SECONDS ) );
	}
}
