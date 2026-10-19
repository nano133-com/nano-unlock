<?php
/**
 * One reader in StoreTest::test_parallel_readers_never_get_the_same_amount, in its own process:
 *
 *   php parallel-checkout.php <database file> <start time> <price in raw> <visitor>
 *
 * It waits for the shared start time, asks for a checkout, and prints the amount it got (or
 * "busy") and how many of its inserts the unique key refused.
 *
 * @package NanoUnlock
 */

// phpcs:ignoreFile -- a test helper.

require __DIR__ . '/bootstrap.php';

list( , $file, $start, $price, $visitor ) = $argv;
$GLOBALS['wpdb'] = new Nano_Unlock_Test_Wpdb( $file );
while ( microtime( true ) < (float) $start ) {
	usleep( 100 );
}
$now = time();
try {
	$row = Nano_Unlock_Store::create(
		array(
			'item'       => 'post:42:0',
			'post_id'    => 42,
			'usd'        => '0.05',
			'address'    => 'nano_1cp9z9nwkzonezr97a8xfw79ahx6emdqhj8eu5j1gkmxhwy817dowa67xzoi',
			'starter'    => hash( 'sha256', $visitor ),
			'visitor'    => $visitor,
			'created_at' => $now,
			'expires_at' => $now + 900,
		),
		$price,
		3600
	);
	echo $row['amount'], ' ', $GLOBALS['wpdb']->refused, "\n";
} catch ( Nano_Unlock_Busy $e ) {
	echo 'busy ', $GLOBALS['wpdb']->refused, "\n";
}
