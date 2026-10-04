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
 *   come (the checkout's 15 minutes plus an hour for late payments), so no two
 *   open checkouts ever ask for the same amount. After that it is cleared
 *   (NULL values don't collide).
 * - `hash` is the payment's block hash, so one payment unlocks one checkout,
 *   once. A paid row is the record of the sale and is kept.
 */
final class Nano_Unlock_Store {

	const DB_VERSION = '1';

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
	 * Creates or updates the table.
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
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
  status varchar(10) NOT NULL DEFAULT 'waiting',
  created_at bigint(20) NOT NULL,
  expires_at bigint(20) NOT NULL,
  hash char(64) NULL,
  payer varchar(65) NULL,
  paid_at bigint(20) NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY amount_lock (amount_lock),
  UNIQUE KEY hash (hash),
  KEY status (status, expires_at)
) {$charset};"
		);
		update_option( 'nano_unlock_db_version', self::DB_VERSION, false );
	}

	/**
	 * Creates a checkout with a unique amount, or returns null when no free amount was found.
	 *
	 * @param array  $row   The fields (item, post_id, usd, address, starter, created_at, expires_at).
	 * @param string $price The price in raw (ending in 26 zeros).
	 * @param int    $late  Seconds after expiry that a payment still counts.
	 * @return array|null The row.
	 */
	public static function create( array $row, $price, $late ) {
		global $wpdb;
		$table = self::table();
		// Amounts whose checkouts can no longer be paid are free again.
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET amount_lock = NULL WHERE amount_lock IS NOT NULL AND expires_at < %d', $table, time() - $late ) );
		$suppress = $wpdb->suppress_errors( true );
		for ( $attempt = 0; $attempt < 5; $attempt++ ) {
			$amount = Nano_Unlock_Amount::unique( $price );
			$data   = array_merge(
				$row,
				array(
					'id'          => bin2hex( random_bytes( 12 ) ),
					'amount'      => $amount,
					'amount_lock' => $amount,
					'status'      => 'waiting',
				)
			);
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- the plugin's own table.
			if ( $wpdb->insert( $table, $data ) ) {
				$wpdb->suppress_errors( $suppress );
				return $data;
			}
		}
		$wpdb->suppress_errors( $suppress );
		return null;
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
	 * Marks a waiting checkout paid by a payment.
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
		$n        = $wpdb->query( $wpdb->prepare( "UPDATE %i SET status = 'paid', hash = %s, payer = %s, paid_at = %d WHERE id = %s AND status = 'waiting'", $table, $hash, $payer, time(), $id ) );
		$wpdb->suppress_errors( $suppress );
		if ( 1 === $n ) {
			return 'paid';
		}
		$other = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE hash = %s', $table, $hash ) );
		return ( $other && $other !== $id ) ? 'used' : 'taken';
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
		$table = self::table();
		$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE status = 'waiting' AND expires_at < %d", $table, time() - $late - DAY_IN_SECONDS ) );
	}
}
