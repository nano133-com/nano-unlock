<?php
/**
 * A mock Nano node for the end-to-end test: an in-memory ledger in a JSON file.
 * It never talks to the real network, and nothing here is real money.
 *
 * RPC (POST /): receivable, account_history, block_info.
 * Test controls:
 *   POST /__pay {to, amount, from?, confirmed?, at?}  a send to `to`; returns {hash}
 *   POST /__confirm {hash}                            the send confirms
 *   POST /__receive {hash}                            the owner's wallet receives it (it moves to history)
 *   POST /__reset                                     a new, empty ledger
 *   GET  /__calls                                     how many RPC calls, per action
 *   POST /__down {down: true|false}                   answer every RPC with HTTP 502
 *
 * Run: php -S 0.0.0.0:8787 e2e/mock-node.php
 */

$state_file = __DIR__ . '/.state/node.json';
if ( ! is_dir( dirname( $state_file ) ) ) {
	mkdir( dirname( $state_file ), 0777, true );
}
$fp = fopen( $state_file, 'c+' );
flock( $fp, LOCK_EX );
$raw   = stream_get_contents( $fp );
$state = $raw ? json_decode( $raw, true ) : null;
if ( ! is_array( $state ) ) {
	$state = array( 'blocks' => array(), 'calls' => array(), 'down' => false );
}

function reply( $data, $code = 200 ) {
	global $fp, $state;
	ftruncate( $fp, 0 );
	rewind( $fp );
	fwrite( $fp, json_encode( $state ) );
	fflush( $fp );
	flock( $fp, LOCK_UN );
	http_response_code( $code );
	header( 'Content-Type: application/json' );
	echo json_encode( $data );
	exit;
}

$path = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
$body = json_decode( file_get_contents( 'php://input' ), true ) ?: array();

switch ( $path ) {
	case '/__reset':
		$state = array( 'blocks' => array(), 'calls' => array(), 'down' => false );
		reply( array( 'ok' => true ) );
	case '/__calls':
		reply( $state['calls'] );
	case '/__down':
		$state['down'] = ! empty( $body['down'] );
		reply( array( 'ok' => true ) );
	case '/__pay':
		$hash                     = strtoupper( bin2hex( random_bytes( 32 ) ) );
		$state['blocks'][ $hash ] = array(
			'from'      => isset( $body['from'] ) ? $body['from'] : 'nano_3pay1ng1111111111111111111111111111111111111111111111111',
			'to'        => $body['to'],
			'amount'    => $body['amount'],
			'confirmed' => isset( $body['confirmed'] ) ? (bool) $body['confirmed'] : true,
			'received'  => false,
			'at'        => isset( $body['at'] ) ? (int) $body['at'] : time(),
		);
		reply( array( 'hash' => $hash ) );
	case '/__confirm':
		$state['blocks'][ $body['hash'] ]['confirmed'] = true;
		reply( array( 'ok' => true ) );
	case '/__receive':
		$state['blocks'][ $body['hash'] ]['received'] = true;
		reply( array( 'ok' => true ) );
}

// The RPC.
$action                    = isset( $body['action'] ) ? $body['action'] : '';
$state['calls'][ $action ] = ( isset( $state['calls'][ $action ] ) ? $state['calls'][ $action ] : 0 ) + 1;
if ( $state['down'] ) {
	reply( array( 'error' => 'bad gateway' ), 502 );
}

// The node.nano133.com gateway refuses lists longer than 50.
if ( isset( $body['count'] ) && ( ! ctype_digit( (string) $body['count'] ) || (int) $body['count'] > 50 ) ) {
	reply( array( 'error' => 'missing or invalid field: count' ), 400 );
}

switch ( $action ) {
	case 'receivable':
		$blocks = array();
		foreach ( $state['blocks'] as $hash => $b ) {
			if ( $b['to'] === $body['account'] && ! $b['received'] && strlen( $b['amount'] ) >= strlen( $body['threshold'] ) && ( strlen( $b['amount'] ) > strlen( $body['threshold'] ) || strcmp( $b['amount'], $body['threshold'] ) >= 0 ) ) {
				$blocks[ $hash ] = array( 'amount' => $b['amount'], 'source' => $b['from'] );
			}
		}
		reply( array( 'blocks' => $blocks ? $blocks : '' ) );
	case 'account_history':
		$history = array();
		foreach ( array_reverse( $state['blocks'], true ) as $hash => $b ) {
			if ( $b['to'] === $body['account'] && $b['received'] ) {
				$history[] = array( 'type' => 'state', 'subtype' => 'receive', 'account' => $b['from'], 'amount' => $b['amount'], 'link' => $hash, 'hash' => strtoupper( md5( $hash ) . md5( $hash ) ), 'confirmed' => 'true' );
			}
		}
		if ( ! $history ) {
			reply( array( 'error' => 'Account not found' ) );
		}
		reply( array( 'account' => $body['account'], 'history' => $history ) );
	case 'block_info':
		$hash = strtoupper( $body['hash'] );
		if ( ! isset( $state['blocks'][ $hash ] ) ) {
			reply( array( 'error' => 'Block not found' ) );
		}
		$b = $state['blocks'][ $hash ];
		reply(
			array(
				'block_account'   => $b['from'],
				'amount'          => $b['amount'],
				'local_timestamp' => (string) $b['at'],
				'confirmed'       => $b['confirmed'] ? 'true' : 'false',
				'subtype'         => 'send',
				'contents'        => array( 'type' => 'state', 'account' => $b['from'], 'link_as_account' => $b['to'], 'subtype' => 'send' ),
			)
		);
}
reply( array( 'error' => 'Action not allowed' ) );
