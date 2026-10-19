<?php
/**
 * Which amount a checkout gets, on MySQL inside WordPress, and the update from the table of 0.1.1
 * (whose checkouts asked for the price plus a random tail). Run on the TEST site only:
 *   npx wp-env run tests-cli -- wp eval-file wp-content/nano-unlock-tests/amounts-test.php
 *
 * @package NanoUnlock
 */

// phpcs:ignoreFile -- a test script.

if ( false === strpos( home_url(), ':8889' ) ) {
	echo "refusing: this test drops the checkouts table, so it runs on the test site (port 8889) only\n";
	exit( 1 );
}
$fail  = 0;
$check = function ( $ok, $msg ) use ( &$fail ) {
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $msg . "\n";
	$fail += $ok ? 0 : 1;
};
global $wpdb;
$table = $wpdb->prefix . 'nano_unlock_checkouts';
$late  = Nano_Unlock::LATE_SECONDS;
$site  = 'nano_3khpd7q3dzrbcae18bmpidbcrj5mn1n1jnkidup7acocia1b966y88czr5if';
$price = Nano_Unlock_Amount::from_usd( 0.05, 0.3307 ); // Ӿ0.1512.
$step  = function ( $k ) use ( $price ) {
	return Nano_Unlock_Amount::plus_steps( $price, $k );
};
$fields = function ( $visitor = 'aaaaaaaaaaaaaaaa' ) use ( $site ) {
	return array(
		'item'       => 'post:1:0',
		'post_id'    => 1,
		'usd'        => '0.05',
		'address'    => $site,
		'starter'    => hash( 'sha256', 'amounts test' ),
		'visitor'    => $visitor,
		'created_at' => time(),
		'expires_at' => time() + Nano_Unlock::CHECKOUT_SECONDS,
	);
};
$create = function ( $visitor = 'aaaaaaaaaaaaaaaa' ) use ( $fields, $price, $late ) {
	return Nano_Unlock_Store::create( $fields( $visitor ), $price, $late );
};
$row = function ( $id ) {
	return Nano_Unlock_Store::get( $id );
};

echo "— the update from 0.1.1\n";
// The table as 0.1.1 made it, with a checkout that was open when the site updated, and a sale.
$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
$wpdb->query(
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
) " . $wpdb->get_charset_collate()
);
update_option( 'nano_unlock_db_version', '1', false );
$old_amount = '151200000000000000000000364625'; // Ӿ0.1512 with a tail, as 0.1.1 asked.
$wpdb->insert( $table, array( 'id' => str_repeat( 'a', 24 ), 'item' => 'post:1:0', 'post_id' => 1, 'usd' => '0.05', 'amount' => $old_amount, 'amount_lock' => $old_amount, 'address' => $site, 'starter' => str_repeat( '0', 64 ), 'status' => 'waiting', 'created_at' => time() - 120, 'expires_at' => time() + 780 ) );
$sold = '151200000000000000000000111111';
$wpdb->insert( $table, array( 'id' => str_repeat( 'b', 24 ), 'item' => 'post:1:0', 'post_id' => 1, 'usd' => '0.05', 'amount' => $sold, 'amount_lock' => $sold, 'address' => $site, 'starter' => str_repeat( '0', 64 ), 'status' => 'paid', 'created_at' => time() - 600, 'expires_at' => time() + 300, 'hash' => str_repeat( 'B', 64 ), 'payer' => $site, 'paid_at' => time() - 500 ) );

Nano_Unlock::upgrade();
$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table}" );
$indexes = array_unique( $wpdb->get_col( "SHOW INDEX FROM {$table}", 2 ) );
$check( '2' === get_option( 'nano_unlock_db_version' ) && in_array( 'visitor', $columns, true ) && in_array( 'visitor', $indexes, true ), 'the update adds the visitor column and its index (db version 2)' );
$check( in_array( 'amount_lock', $indexes, true ) && in_array( 'hash', $indexes, true ), 'the unique keys on amount_lock and hash are kept' );
$open = $row( str_repeat( 'a', 24 ) );
$check( $open && 'waiting' === $open['status'] && $old_amount === $open['amount'] && $old_amount === $open['amount_lock'], 'the checkout that was open keeps its exact amount, still held' );
$check( $old_amount === Nano_Unlock_Store::smallest_open_amount( $late ), 'it is still looked for (the smallest open amount)' );
$first = $create();
$check( $price === $first['amount'], 'a new checkout at the same price gets the clean Ӿ0.1512: no clash with a tail' );
$wpdb->update( $table, array( 'expires_at' => time() - $late - Nano_Unlock_Store::HOLD_AFTER - 1 ), array( 'id' => str_repeat( 'b', 24 ) ) );
Nano_Unlock_Store::release_old( $late );
$check( null === $row( str_repeat( 'b', 24 ) )['amount_lock'] && 'paid' === $row( str_repeat( 'b', 24 ) )['status'], 'an old sale still holding its amount frees it when its time is over, and stays a sale' );

echo "— steps on MySQL\n";
$wpdb->query( "DELETE FROM {$table}" );
$got = array();
for ( $i = 0; $i < 5; $i++ ) {
	$got[] = Nano_Unlock_Amount::to_xno( $create()['amount'] );
}
$check( array( '0.1512', '0.1513', '0.1514', '0.1515', '0.1516' ) === $got, 'five open checkouts: Ӿ0.1512 to Ӿ0.1516 (' . implode( ' ', $got ) . ')' );
$suppress = $wpdb->suppress_errors( true );
$dup      = $wpdb->insert( $table, array_merge( $fields(), array( 'id' => str_repeat( 'c', 24 ), 'amount' => $price, 'amount_lock' => $price ) ) );
$wpdb->suppress_errors( $suppress );
$check( false === $dup && false !== stripos( $wpdb->last_error, 'Duplicate' ), 'MySQL itself refuses a second hold of the same amount (' . $wpdb->last_error . ')' );
$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM %i ORDER BY CAST(amount AS DECIMAL(40,0))', $table ) );
Nano_Unlock_Store::cancel( $ids[1] );
$check( $step( 1 ) === $create()['amount'], 'a cancel frees its amount at once: the next checkout gets Ӿ0.1513' );
Nano_Unlock_Store::mark_paid( $ids[0], str_repeat( 'D', 64 ), $site );
$check( null === $row( $ids[0] )['amount_lock'] && $price === $create()['amount'], 'a payment frees its amount at once: the next checkout gets Ӿ0.1512' );
$wpdb->update( $table, array( 'expires_at' => time() - $late + 30 ), array( 'id' => $ids[2] ) );
$check( $step( 5 ) === $create()['amount'], 'in its late window an unpaid amount stays held' );
$wpdb->update( $table, array( 'expires_at' => time() - $late - Nano_Unlock_Store::HOLD_AFTER - 1 ), array( 'id' => $ids[2] ) );
$check( $step( 2 ) === $create()['amount'], 'after the late window and HOLD_AFTER it is free again' );

echo "— the limits\n";
$wpdb->query( "DELETE FROM {$table}" );
for ( $i = 0; $i < Nano_Unlock_Store::MAX_STEPS; $i++ ) {
	$create( sprintf( '%016x', $i ) );
}
$busy = false;
try {
	$create();
} catch ( Nano_Unlock_Busy $e ) {
	$busy = true;
}
$max = $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(CAST(amount_lock AS DECIMAL(40,0))) FROM %i', $table ) );
$check( $busy && $step( Nano_Unlock_Store::MAX_STEPS - 1 ) === (string) $max && Nano_Unlock_Store::MAX_STEPS === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) ), 'all 100 steps held (up to Ӿ0.1611): the next checkout is "busy" and holds nothing' );
$wpdb->query( "DELETE FROM {$table}" );
for ( $i = 0; $i < 3; $i++ ) {
	$create( 'eeeeeeeeeeeeeeee' );
}
$check( 3 === Nano_Unlock_Store::held_by( 'eeeeeeeeeeeeeeee', $late ) && 0 === Nano_Unlock_Store::held_by( 'ffffffffffffffff', $late ), 'a visitor\'s open amounts are counted' );

$wpdb->query( "DELETE FROM {$table}" );
Nano_Unlock::activate(); // Put the test site back as it was.
echo $fail ? "\n$fail FAILED\n" : "\nall passed\n";
exit( $fail ? 1 : 0 );
