<?php
/**
 * A small stand-in for the parts of WordPress that the render and REST tests reach.
 *
 * Everything lives in $GLOBALS['nano_unlock_wp'] so each test can set the
 * request it wants (is it the post's own page, which cookies, which options)
 * and reset it with nano_unlock_wp_reset().
 *
 * @package NanoUnlock
 */

// phpcs:disable

define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'COOKIEPATH', '/' );
define( 'COOKIE_DOMAIN', '' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'NANO_UNLOCK_VERSION', '0.2.0' );
define( 'NANO_UNLOCK_DIR', dirname( __DIR__ ) . '/nano-unlock/' );
define( 'NANO_UNLOCK_URL', 'https://example.test/wp-content/plugins/nano-unlock/' );
define( 'NANO_UNLOCK_FILE', NANO_UNLOCK_DIR . 'nano-unlock.php' );

/**
 * Resets the fake WordPress to a reader on a post's own page, with no cookies.
 */
function nano_unlock_wp_reset() {
	$GLOBALS['nano_unlock_wp'] = array(
		'options'    => array(),
		'transients' => array(),
		'filters'    => array(),
		'singular'   => true,
		'queried'    => 0,
		'the_id'     => 0,
		'can_edit'   => false,
		'posts'      => array(),
		'node'       => null,
		'feeds'      => array(),
		'gets'       => array(),
		'nocache'    => 0,
		'settings_errors' => array(),
	);
	$GLOBALS['wpdb'] = new Nano_Unlock_Test_Wpdb();
	$_COOKIE         = array();
	$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
}

/**
 * A post the fake WordPress knows.
 *
 * @param int $id The post.
 */
function nano_unlock_wp_post( $id ) {
	$GLOBALS['nano_unlock_wp']['posts'][ $id ] = (object) array(
		'ID'                => $id,
		'post_status'       => 'publish',
		'post_modified_gmt' => '2026-10-01 10:00:00',
		'post_content'      => '[nano_unlock]x[/nano_unlock]',
	);
}

class WP_Error {
	private $code;
	private $message;
	private $data;
	public function __construct( $code = '', $message = '', $data = '' ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}
	public function get_error_code() {
		return $this->code;
	}
	public function get_error_message() {
		return $this->message;
	}
	public function get_error_data() {
		return $this->data;
	}
}

class WP_REST_Response {
	private $data;
	private $status;
	private $headers = array();
	public function __construct( $data = null, $status = 200 ) {
		$this->data   = $data;
		$this->status = $status;
	}
	public function header( $key, $value ) {
		$this->headers[ $key ] = $value;
	}
	public function get_data() {
		return $this->data;
	}
	public function get_status() {
		return $this->status;
	}
}

class WP_REST_Request {
	private $params;
	private $headers;
	public function __construct( array $params = array(), array $headers = array() ) {
		$this->params  = $params;
		$this->headers = $headers;
	}
	public function get_param( $key ) {
		return isset( $this->params[ $key ] ) ? $this->params[ $key ] : null;
	}
	public function get_header( $key ) {
		return isset( $this->headers[ $key ] ) ? $this->headers[ $key ] : null;
	}
}

/**
 * The checkouts table in SQLite, standing in for MySQL behind $wpdb: the same columns and the
 * same unique keys, so the store's own SQL runs for real (in memory, or in a file that several
 * processes share for the parallel test). Values come back as strings, as from $wpdb.
 */
class Nano_Unlock_Test_Wpdb {
	public $prefix     = 'wp_';
	public $last_error = '';
	/** When true, marking a checkout paid fails like a database error. */
	public $fail_mark_paid = false;
	/** Inserts the database refused (a unique key, usually). */
	public $refused = 0;
	/** Called before each insert with the row (a test can play a rival reader there). */
	public $before_insert = null;
	/** @var PDO */
	public $pdo;

	public function __construct( $file = ':memory:' ) {
		$this->pdo = new PDO(
			'sqlite:' . $file,
			null,
			null,
			array(
				PDO::ATTR_ERRMODE           => PDO::ERRMODE_SILENT,
				PDO::ATTR_STRINGIFY_FETCHES => true,
				PDO::ATTR_TIMEOUT           => 60,
			)
		);
		$this->pdo->exec( 'PRAGMA busy_timeout = 60000' );
		if ( ':memory:' !== $file ) {
			$this->pdo->exec( 'PRAGMA journal_mode = WAL' );
		}
		$this->pdo->exec(
			'CREATE TABLE IF NOT EXISTS wp_nano_unlock_checkouts (
				id TEXT NOT NULL PRIMARY KEY,
				item TEXT NOT NULL,
				post_id INTEGER NOT NULL DEFAULT 0,
				usd TEXT NOT NULL DEFAULT \'0\',
				amount TEXT NOT NULL,
				amount_lock TEXT NULL UNIQUE,
				address TEXT NOT NULL,
				starter TEXT NOT NULL,
				visitor TEXT NULL,
				status TEXT NOT NULL DEFAULT \'waiting\',
				cancelled_at INTEGER NULL,
				created_at INTEGER NOT NULL,
				expires_at INTEGER NOT NULL,
				hash TEXT NULL UNIQUE,
				payer TEXT NULL,
				paid_at INTEGER NULL
			)'
		);
	}

	/** Every row, by id (read only: tests change rows with set()). */
	public function __get( $name ) {
		if ( 'rows' !== $name ) {
			return null;
		}
		$rows = array();
		foreach ( $this->pdo->query( 'SELECT * FROM wp_nano_unlock_checkouts' )->fetchAll( PDO::FETCH_ASSOC ) as $row ) {
			$rows[ $row['id'] ] = $row;
		}
		return $rows;
	}

	/** Changes a row's fields, as a test needs (an older payment, an older checkout). */
	public function set( $id, array $fields ) {
		$sets = array();
		foreach ( $fields as $k => $v ) {
			$sets[] = $k . ' = ' . ( null === $v ? 'NULL' : $this->pdo->quote( (string) $v ) );
		}
		$this->pdo->exec( 'UPDATE wp_nano_unlock_checkouts SET ' . implode( ', ', $sets ) . ' WHERE id = ' . $this->pdo->quote( $id ) );
	}

	public function suppress_errors( $suppress = true ) {
		return false;
	}
	public function get_charset_collate() {
		return '';
	}
	/** Like WordPress: %i an identifier, %s a quoted string, %d an integer, %f a float, %% a percent sign. */
	public function prepare( $sql, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$pdo = $this->pdo;
		return preg_replace_callback(
			'/%(%|i|s|d|f)/',
			function ( $m ) use ( &$args, $pdo ) {
				if ( '%' === $m[1] ) {
					return '%';
				}
				$v = array_shift( $args );
				switch ( $m[1] ) {
					case 'i':
						return '`' . str_replace( '`', '``', (string) $v ) . '`';
					case 'd':
						return (string) (int) $v;
					case 'f':
						return (string) (float) $v;
				}
				return $pdo->quote( (string) $v );
			},
			$sql
		);
	}
	public function insert( $table, $data ) {
		if ( $this->before_insert ) {
			call_user_func( $this->before_insert, $data );
		}
		$cols = array_keys( $data );
		$stmt = $this->pdo->prepare( 'INSERT INTO ' . $table . ' (' . implode( ', ', $cols ) . ') VALUES (' . implode( ', ', array_fill( 0, count( $cols ), '?' ) ) . ')' );
		if ( ! $stmt || ! $stmt->execute( array_values( $data ) ) ) {
			$this->last_error = $stmt ? (string) $stmt->errorInfo()[2] : 'prepare failed';
			$this->refused++;
			return false;
		}
		$this->last_error = '';
		return 1;
	}
	public function query( $sql ) {
		if ( $this->fail_mark_paid && false !== strpos( $sql, "SET status = 'paid'" ) ) {
			return false;
		}
		$n = $this->pdo->exec( $sql );
		if ( false === $n ) {
			$this->last_error = (string) $this->pdo->errorInfo()[2];
			return false;
		}
		return $n;
	}
	private function rows_of( $sql ) {
		$stmt = $this->pdo->query( $sql );
		if ( ! $stmt ) {
			$this->last_error = (string) $this->pdo->errorInfo()[2];
			return array();
		}
		return $stmt->fetchAll( PDO::FETCH_ASSOC );
	}
	public function get_row( $sql, $output = null ) {
		$rows = $this->rows_of( $sql );
		return $rows ? $rows[0] : null;
	}
	public function get_var( $sql ) {
		$rows = $this->rows_of( $sql );
		return $rows ? reset( $rows[0] ) : null;
	}
	public function get_col( $sql ) {
		return array_map(
			function ( $row ) {
				return reset( $row );
			},
			$this->rows_of( $sql )
		);
	}
	public function get_results( $sql, $output = null ) {
		return $this->rows_of( $sql );
	}
}

function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {
	$GLOBALS['nano_unlock_wp']['filters'][ $hook ][] = $callback;
	return true;
}
function apply_filters( $hook, $value, ...$args ) {
	if ( ! empty( $GLOBALS['nano_unlock_wp']['filters'][ $hook ] ) ) {
		foreach ( $GLOBALS['nano_unlock_wp']['filters'][ $hook ] as $callback ) {
			$value = $callback( $value, ...$args );
		}
	}
	return $value;
}
function add_action() {
	return true;
}
function add_shortcode() {
	return true;
}
function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['nano_unlock_wp']['options'] ) ? $GLOBALS['nano_unlock_wp']['options'][ $name ] : $default;
}
function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['nano_unlock_wp']['options'][ $name ] = $value;
	return true;
}
function delete_option( $name ) {
	unset( $GLOBALS['nano_unlock_wp']['options'][ $name ] );
	return true;
}
function wp_next_scheduled( $hook ) {
	return isset( $GLOBALS['nano_unlock_wp']['cron'][ $hook ] ) ? $GLOBALS['nano_unlock_wp']['cron'][ $hook ] : false;
}
function wp_schedule_event( $timestamp, $recurrence, $hook ) {
	$GLOBALS['nano_unlock_wp']['cron'][ $hook ] = $timestamp;
	return true;
}
/**
 * WordPress's table updater: here it changes nothing, so a test can show what install() does when the
 * update did not take (and make the table itself when it should).
 */
function dbDelta( $sql ) {
	$GLOBALS['nano_unlock_wp']['dbdelta'][] = $sql;
	return array();
}
function get_transient( $name ) {
	return isset( $GLOBALS['nano_unlock_wp']['transients'][ $name ] ) ? $GLOBALS['nano_unlock_wp']['transients'][ $name ] : false;
}
function set_transient( $name, $value, $seconds = 0 ) {
	$GLOBALS['nano_unlock_wp']['transients'][ $name ] = $value;
	return true;
}
function is_singular() {
	return $GLOBALS['nano_unlock_wp']['singular'];
}
function get_queried_object_id() {
	return $GLOBALS['nano_unlock_wp']['queried'];
}
function get_the_ID() {
	return $GLOBALS['nano_unlock_wp']['the_id'];
}
function get_post( $id = null ) {
	$id = null === $id ? $GLOBALS['nano_unlock_wp']['the_id'] : (int) $id;
	return isset( $GLOBALS['nano_unlock_wp']['posts'][ $id ] ) ? $GLOBALS['nano_unlock_wp']['posts'][ $id ] : null;
}
function get_permalink( $id ) {
	return 'https://example.test/?p=' . (int) $id;
}
function post_password_required( $post = null ) {
	return false;
}
function current_user_can( $capability, ...$args ) {
	return $GLOBALS['nano_unlock_wp']['can_edit'];
}
function has_shortcode( $content, $tag ) {
	return false !== strpos( $content, '[' . $tag );
}
function has_block( $name, $post = null ) {
	return false;
}
function nocache_headers() {
	$GLOBALS['nano_unlock_wp']['nocache']++;
}
function shortcode_atts( $pairs, $atts, $shortcode = '' ) {
	$atts = (array) $atts;
	$out  = array();
	foreach ( $pairs as $name => $default ) {
		$out[ $name ] = array_key_exists( $name, $atts ) ? $atts[ $name ] : $default;
	}
	return $out;
}
function do_shortcode( $content ) {
	return $content;
}
function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}
function sanitize_text_field( $text ) {
	// Like WordPress: an array or an object becomes ''.
	return is_array( $text ) || is_object( $text ) ? '' : trim( strip_tags( (string) $text ) );
}
function add_settings_error( $setting, $code, $message ) {
	$GLOBALS['nano_unlock_wp']['settings_errors'][ $code ] = $message;
}
function wp_unslash( $value ) {
	return is_string( $value ) ? stripslashes( $value ) : $value;
}
function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}
function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}
function esc_url( $url ) {
	return htmlspecialchars( (string) $url, ENT_QUOTES, 'UTF-8' );
}
function esc_url_raw( $url ) {
	return (string) $url;
}
function __( $text, $domain = 'default' ) {
	return $text;
}
function esc_html__( $text, $domain = 'default' ) {
	return esc_html( $text );
}
function wp_enqueue_style( $handle ) {}
function wp_enqueue_script( $handle ) {}
function wp_localize_script( $handle, $name, $data ) {
	return true;
}
function rest_url( $path = '' ) {
	return 'https://example.test/wp-json/' . $path;
}
function home_url( $path = '' ) {
	return 'https://example.test' . $path;
}
function wp_hash( $data, $scheme = 'auth' ) {
	return hash_hmac( 'md5', (string) $data, 'test salt ' . $scheme );
}
function wp_create_nonce( $action ) {
	return 'nonce';
}
function wp_verify_nonce( $nonce, $action ) {
	return 'nonce' === $nonce ? 1 : false;
}
function is_ssl() {
	return true;
}
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}
function metadata_exists( $type, $id, $key ) {
	return false;
}
function get_post_meta( $id, $key, $single = false ) {
	return '';
}

/**
 * The node: the test's callable answers each RPC body.
 */
function wp_remote_post( $url, $args ) {
	$node = $GLOBALS['nano_unlock_wp']['node'];
	if ( ! $node ) {
		return new WP_Error( 'down', 'no node' );
	}
	return array(
		'code' => 200,
		'body' => json_encode( $node( json_decode( $args['body'], true ) ) ),
	);
}
/**
 * The price feeds: the test's map of URL => JSON answer (a missing URL fails). Each call is kept.
 */
function wp_remote_get( $url, $args = array() ) {
	$GLOBALS['nano_unlock_wp']['gets'][] = array( 'url' => $url, 'args' => $args );
	if ( ! isset( $GLOBALS['nano_unlock_wp']['feeds'][ $url ] ) ) {
		return new WP_Error( 'down', 'no feed' );
	}
	return array(
		'code' => 200,
		'body' => json_encode( $GLOBALS['nano_unlock_wp']['feeds'][ $url ] ),
	);
}
function wp_remote_retrieve_response_code( $response ) {
	return $response['code'];
}
function wp_remote_retrieve_body( $response ) {
	return $response['body'];
}
