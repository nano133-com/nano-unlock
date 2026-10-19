<?php
/**
 * Which amount a checkout gets: clean amounts, made unique by reservation.
 *
 * The table here is SQLite (tests/wp-stubs.php) with the same unique keys as the plugin's MySQL
 * table, so the store's own SQL runs. The same rules run on MySQL in `npm run test:wp` and, with
 * parallel requests, in `npm run e2e`.
 *
 * @package NanoUnlock
 */

use PHPUnit\Framework\TestCase;

final class StoreTest extends TestCase {

	const SITE = 'nano_1cp9z9nwkzonezr97a8xfw79ahx6emdqhj8eu5j1gkmxhwy817dowa67xzoi';
	const LATE = 3600;

	/**
	 * $0.05 at $0.3307 per XNO: 0.1512 XNO.
	 *
	 * @var string
	 */
	private $price;

	protected function setUp(): void {
		nano_unlock_wp_reset();
		$this->price = Nano_Unlock_Amount::from_usd( 0.05, 0.3307 );
	}

	/**
	 * A checkout's fields, as Nano_Unlock_Rest::checkout() gives them.
	 *
	 * @param string $visitor The visitor's key.
	 * @return array
	 */
	private function fields( $visitor = 'aaaaaaaaaaaaaaaa' ) {
		$now = time();
		return array(
			'item'       => 'post:42:0',
			'post_id'    => 42,
			'usd'        => '0.05',
			'address'    => self::SITE,
			'starter'    => hash( 'sha256', 'browser' ),
			'visitor'    => $visitor,
			'created_at' => $now,
			'expires_at' => $now + Nano_Unlock::CHECKOUT_SECONDS,
		);
	}

	private function create( $visitor = 'aaaaaaaaaaaaaaaa' ) {
		return Nano_Unlock_Store::create( $this->fields( $visitor ), $this->price, self::LATE );
	}

	private function step( $k ) {
		return Nano_Unlock_Amount::plus_steps( $this->price, $k );
	}

	private function row( $id ) {
		return Nano_Unlock_Store::get( $id );
	}

	public function test_the_first_checkout_asks_for_the_price_itself() {
		$c = $this->create();
		$this->assertSame( $this->price, $c['amount'] );
		$this->assertSame( '0.1512', Nano_Unlock_Amount::to_xno( $c['amount'] ) );
		$this->assertSame( $c['amount'], $this->row( $c['id'] )['amount_lock'], 'the amount is held' );
	}

	public function test_each_checkout_that_holds_the_amount_adds_one_step() {
		$got = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$got[] = Nano_Unlock_Amount::to_xno( $this->create()['amount'] );
		}
		$this->assertSame( array( '0.1512', '0.1513', '0.1514', '0.1515', '0.1516' ), $got );
	}

	public function test_a_cancelled_amount_is_held_three_minutes_then_given_again_lowest_first() {
		$this->create();
		$b = $this->create();
		$this->create();
		$this->assertTrue( Nano_Unlock_Store::cancel( $b['id'] ) );
		$cancelled = $this->row( $b['id'] );
		$this->assertSame( 'cancelled', $cancelled['status'] );
		$this->assertSame( $b['amount'], $cancelled['amount_lock'], 'a cancelled amount stays held' );
		$this->assertNull( $cancelled['visitor'], 'but no longer counts for the visitor' );
		$this->assertSame( $this->step( 3 ), $this->create()['amount'], 'during the hold the next reader gets the next free step' );
		$GLOBALS['wpdb']->set( $b['id'], array( 'cancelled_at' => time() - Nano_Unlock_Store::CANCEL_HOLD + 5 ) );
		$this->assertSame( $this->step( 4 ), $this->create()['amount'], 'still held just before the 3 minutes are over' );
		$GLOBALS['wpdb']->set( $b['id'], array( 'cancelled_at' => time() - Nano_Unlock_Store::CANCEL_HOLD - 1 ) );
		$this->assertSame( $this->step( 1 ), $this->create()['amount'], 'after 3 minutes the next reader gets the freed step' );
		$this->assertNull( $this->row( $b['id'] )['amount_lock'] );
	}

	public function test_the_cancel_hold_is_longer_than_the_clock_allowance() {
		// A payment the node first saw before Cancel (or within a minute after it) must count as "before"
		// the next checkout that gets the amount.
		$this->assertSame( 180, Nano_Unlock_Store::CANCEL_HOLD );
		$this->assertGreaterThan( Nano_Unlock_Verifier::CLOCK_SLACK, Nano_Unlock_Store::CANCEL_HOLD );
	}

	public function test_a_payment_frees_the_amount_at_once() {
		$a = $this->create();
		$this->assertSame( 'paid', Nano_Unlock_Store::mark_paid( $a['id'], str_repeat( 'A', 64 ), self::SITE ) );
		$paid = $this->row( $a['id'] );
		$this->assertNull( $paid['amount_lock'] );
		$this->assertSame( $this->price, $paid['amount'], 'the sale keeps its amount' );
		$this->assertSame( $this->price, $this->create()['amount'], 'the next reader may pay the same amount' );
	}

	public function test_an_unpaid_amount_stays_held_through_the_late_window() {
		$a = $this->create();
		// Shown for 15 minutes, then looked for an hour more (LATE), then HOLD_AFTER more.
		$GLOBALS['wpdb']->set( $a['id'], array( 'expires_at' => time() - self::LATE + 60 ) );
		$this->assertSame( $this->step( 1 ), $this->create()['amount'], 'in the late window: still held' );
		$GLOBALS['wpdb']->set( $a['id'], array( 'expires_at' => time() - self::LATE - Nano_Unlock_Store::HOLD_AFTER + 30 ) );
		$this->assertSame( $this->step( 2 ), $this->create()['amount'], 'just after the late window: still held' );
		$GLOBALS['wpdb']->set( $a['id'], array( 'expires_at' => time() - self::LATE - Nano_Unlock_Store::HOLD_AFTER - 1 ) );
		$this->assertSame( $this->price, $this->create()['amount'], 'after HOLD_AFTER: free again' );
		$this->assertNull( $this->row( $a['id'] )['amount_lock'] );
		$this->assertSame( 'waiting', $this->row( $a['id'] )['status'], 'the row stays until the daily prune' );
	}

	public function test_hold_after_is_longer_than_the_clock_allowance() {
		// A payment sent at the last second of one checkout's window must count as "before" the next
		// checkout that gets the same amount, even with the verifier's clock allowance.
		$this->assertGreaterThan( Nano_Unlock_Verifier::CLOCK_SLACK + 60, Nano_Unlock_Store::HOLD_AFTER );
	}

	public function test_the_upper_limit_answers_busy() {
		// 0.1512 XNO: a tenth is 151 steps, so the limit of 100 amounts applies.
		$this->assertSame( Nano_Unlock_Store::MAX_STEPS, Nano_Unlock_Store::steps( $this->price ) );
		for ( $i = 0; $i < Nano_Unlock_Store::MAX_STEPS; $i++ ) {
			$last = $this->create( sprintf( '%016x', $i ) );
		}
		$this->assertSame( $this->step( Nano_Unlock_Store::MAX_STEPS - 1 ), $last['amount'] );
		$this->assertSame( '0.1611', Nano_Unlock_Amount::to_xno( $last['amount'] ), 'at most 0.0099 XNO above the price' );
		try {
			$this->create();
			$this->fail( 'a checkout beyond MAX_STEPS must be refused' );
		} catch ( Nano_Unlock_Busy $e ) {
			$this->assertSame( 'no free amount', $e->getMessage() );
		}
		$this->assertCount( Nano_Unlock_Store::MAX_STEPS, $GLOBALS['wpdb']->rows, 'nothing was held for the refused reader' );
		// One step comes free (cancelled, and its 3 minutes are over): the next reader gets it.
		$third = array_values( $GLOBALS['wpdb']->rows )[2];
		Nano_Unlock_Store::cancel( $third['id'] );
		$GLOBALS['wpdb']->set( $third['id'], array( 'cancelled_at' => time() - Nano_Unlock_Store::CANCEL_HOLD - 1 ) );
		$this->assertSame( $third['amount'], $this->create()['amount'] );
	}

	public function test_the_steps_never_add_more_than_a_tenth_of_the_price() {
		// 1¢ at $0.33 per XNO is 0.0304 XNO (304 steps): at most 30 steps more, 0.0334 XNO (+9.9%).
		$this->price = Nano_Unlock_Amount::from_usd( 0.01, 0.33 );
		$this->assertSame( '0.0304', Nano_Unlock_Amount::to_xno( $this->price ) );
		$this->assertSame( 31, Nano_Unlock_Store::steps( $this->price ) );
		for ( $i = 0; $i < 31; $i++ ) {
			$last = $this->create( sprintf( '%016x', $i ) );
		}
		$this->assertSame( '0.0334', Nano_Unlock_Amount::to_xno( $last['amount'] ) );
		try {
			$this->create( 'ffffffffffffffff' );
			$this->fail( 'a 32nd checkout at 1¢ must be refused' );
		} catch ( Nano_Unlock_Busy $e ) {
			$this->assertCount( 31, $GLOBALS['wpdb']->rows );
		}
		// A price under 0.001 XNO has no room above it: one open checkout at a time.
		$this->assertSame( 1, Nano_Unlock_Store::steps( Nano_Unlock_Amount::from_steps( 9 ) ) );
		$this->assertSame( 2, Nano_Unlock_Store::steps( Nano_Unlock_Amount::from_steps( 10 ) ) );
		$this->assertSame( 100, Nano_Unlock_Store::steps( Nano_Unlock_Amount::from_steps( 2000000000 ) ) );
	}

	public function test_a_rival_that_takes_the_amount_after_the_read_sends_this_reader_one_step_on() {
		$wpdb = $GLOBALS['wpdb'];
		$fields = $this->fields( 'bbbbbbbbbbbbbbbb' );
		// The rival reads the same free amounts at the same instant, and inserts first.
		$wpdb->before_insert = function ( $data ) use ( $wpdb, $fields ) {
			$wpdb->before_insert = null;
			$rival = array_merge(
				$fields,
				array(
					'id'          => str_repeat( 'b', 24 ),
					'amount'      => $data['amount'],
					'amount_lock' => $data['amount_lock'],
					'status'      => 'waiting',
				)
			);
			$wpdb->insert( 'wp_nano_unlock_checkouts', $rival );
		};
		$mine = $this->create();
		$this->assertSame( $this->price, $this->row( str_repeat( 'b', 24 ) )['amount'], 'the rival holds the price' );
		$this->assertSame( $this->step( 1 ), $mine['amount'], 'this reader moved on one step' );
		$this->assertSame( 1, $wpdb->refused, 'the unique key refused the second insert of the same amount' );
	}

	public function test_a_database_failure_is_busy_and_holds_nothing() {
		$wpdb                = $GLOBALS['wpdb'];
		$wpdb->before_insert = function () use ( $wpdb ) {
			$wpdb->pdo->exec( 'ALTER TABLE wp_nano_unlock_checkouts RENAME TO gone' );
		};
		$this->expectException( Nano_Unlock_Busy::class );
		try {
			$this->create();
		} finally {
			$this->assertSame( 1, $wpdb->refused, 'one failed insert, then a stop: no walk through every step' );
		}
	}

	public function test_an_older_checkout_with_a_tail_keeps_its_exact_amount() {
		// Opened before the update: the price plus a random tail, held under the old rule.
		$old    = '151200000000000000000000364625';
		$fields = array_merge(
			$this->fields(),
			array(
				'id'          => str_repeat( 'd', 24 ),
				'amount'      => $old,
				'amount_lock' => $old,
				'status'      => 'waiting',
			)
		);
		unset( $fields['visitor'] );
		$GLOBALS['wpdb']->insert( 'wp_nano_unlock_checkouts', $fields );
		$this->assertSame( $this->price, $this->create()['amount'], 'a clean amount never collides with a tail' );
		$kept = $this->row( str_repeat( 'd', 24 ) );
		$this->assertSame( $old, $kept['amount'] );
		$this->assertSame( $old, $kept['amount_lock'], 'still held until its time is over' );
	}

	public function test_a_visitor_counts_only_the_amounts_it_holds_now() {
		$v = 'cccccccccccccccc';
		$a = $this->create( $v );
		$b = $this->create( $v );
		$c = $this->create( $v );
		$this->create( 'dddddddddddddddd' );
		$this->assertSame( 3, Nano_Unlock_Store::held_by( $v, self::LATE ) );
		Nano_Unlock_Store::cancel( $a['id'] ); // Still held 3 minutes, but no longer the visitor's.
		Nano_Unlock_Store::mark_paid( $b['id'], str_repeat( 'B', 64 ), self::SITE );
		$this->assertSame( 1, Nano_Unlock_Store::held_by( $v, self::LATE ) );
		$GLOBALS['wpdb']->set( $c['id'], array( 'expires_at' => time() - self::LATE - Nano_Unlock_Store::HOLD_AFTER - 1 ) );
		$this->assertSame( 0, Nano_Unlock_Store::held_by( $v, self::LATE ) );
		Nano_Unlock_Store::release_old( self::LATE );
		$this->assertNull( $this->row( $c['id'] )['visitor'], 'the visitor key goes with the amount' );
	}

	public function test_the_visitor_key() {
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{16}$/', Nano_Unlock_Limit::visitor( '203.0.113.7' ) );
		$this->assertNotSame( Nano_Unlock_Limit::visitor( '203.0.113.7' ), Nano_Unlock_Limit::visitor( '203.0.113.8' ) );
		// One IPv6 /64 is one visitor (a home or a phone has the whole /64).
		$this->assertSame( Nano_Unlock_Limit::visitor( '2001:db8:1:2::1' ), Nano_Unlock_Limit::visitor( '2001:db8:1:2:aaaa::9' ) );
		$this->assertNotSame( Nano_Unlock_Limit::visitor( '2001:db8:1:2::1' ), Nano_Unlock_Limit::visitor( '2001:db8:1:3::1' ) );
		// An IPv4 address written as IPv6 stays one address.
		$this->assertNotSame( Nano_Unlock_Limit::visitor( '::ffff:203.0.113.7' ), Nano_Unlock_Limit::visitor( '::ffff:203.0.113.8' ) );
		$this->assertStringNotContainsString( '203', Nano_Unlock_Limit::visitor( '203.0.113.7' ), 'the address itself is not kept' );
	}

	public function test_used_hashes() {
		$a = $this->create();
		Nano_Unlock_Store::mark_paid( $a['id'], str_repeat( 'A', 64 ), self::SITE );
		$this->assertSame( array( str_repeat( 'A', 64 ) ), Nano_Unlock_Store::used_hashes( array( str_repeat( 'A', 64 ), str_repeat( 'C', 64 ) ) ) );
		$this->assertSame( array(), Nano_Unlock_Store::used_hashes( array() ) );
	}

	public function test_the_daily_prune_keeps_the_sales() {
		$paid      = $this->create();
		$cancelled = $this->create();
		$old       = $this->create();
		$open      = $this->create();
		Nano_Unlock_Store::mark_paid( $paid['id'], str_repeat( 'E', 64 ), self::SITE );
		Nano_Unlock_Store::cancel( $cancelled['id'] );
		$long_ago = time() - self::LATE - DAY_IN_SECONDS - 10;
		foreach ( array( $paid, $cancelled, $old ) as $c ) {
			$GLOBALS['wpdb']->set( $c['id'], array( 'expires_at' => $long_ago ) );
		}
		Nano_Unlock_Store::prune( self::LATE );
		$this->assertSame( array( $paid['id'], $open['id'] ), array_keys( $GLOBALS['wpdb']->rows ) );
	}

	/**
	 * Many readers at the same instant, in separate PHP processes that share one database file:
	 * each asks for a checkout at the same price. None may get the same amount, and together they
	 * hold the first N steps. The readers really overlap: at least one insert is refused by the
	 * unique key and its reader moves on (the readers' own count).
	 */
	public function test_parallel_readers_never_get_the_same_amount() {
		if ( ! function_exists( 'proc_open' ) ) {
			$this->markTestSkipped( 'proc_open is not available' );
		}
		$readers = 24;
		$refused = 0;
		for ( $round = 1; $round <= 3; $round++ ) {
			$file = tempnam( sys_get_temp_dir(), 'nano-unlock-parallel-' );
			new Nano_Unlock_Test_Wpdb( $file );
			$start = microtime( true ) + 1.5;
			$procs = array();
			for ( $i = 0; $i < $readers; $i++ ) {
				$pipes   = array();
				$procs[] = array(
					proc_open(
						array( PHP_BINARY, __DIR__ . '/parallel-checkout.php', $file, sprintf( '%.4F', $start ), $this->price, sprintf( '%016x', $i ) ),
						array(
							1 => array( 'pipe', 'w' ),
							2 => array( 'pipe', 'w' ),
						),
						$pipes
					),
					$pipes,
				);
			}
			$amounts = array();
			foreach ( $procs as $p ) {
				$out = trim( stream_get_contents( $p[1][1] ) );
				$err = trim( stream_get_contents( $p[1][2] ) );
				proc_close( $p[0] );
				$this->assertSame( '', $err, 'a reader failed' );
				list( $amount, $tries ) = explode( ' ', $out );
				$amounts[] = $amount;
				$refused  += (int) $tries;
			}
			$db = new Nano_Unlock_Test_Wpdb( $file );
			$this->assertCount( $readers, $db->rows, 'one checkout per reader' );
			unset( $db );
			unlink( $file );
			foreach ( array( '-wal', '-shm' ) as $suffix ) {
				if ( file_exists( $file . $suffix ) ) {
					unlink( $file . $suffix );
				}
			}

			$this->assertNotContains( 'busy', $amounts, 'every reader got an amount' );
			$this->assertSame( $readers, count( array_unique( $amounts ) ), 'no two readers got the same amount' );
			$want = array();
			for ( $k = 0; $k < $readers; $k++ ) {
				$want[] = $this->step( $k );
			}
			sort( $amounts );
			sort( $want );
			$this->assertSame( $want, $amounts, 'together they hold the first steps, with no gap' );
			if ( $refused > 0 ) {
				break;
			}
		}
		$this->assertGreaterThan( 0, $refused, 'the readers overlapped: the unique key refused an amount that another reader had just taken' );
	}
}
