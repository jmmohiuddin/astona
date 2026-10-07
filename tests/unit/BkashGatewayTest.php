<?php
/**
 * Dependency-free tests for CC_Bkash_Gateway with a scripted HTTP transport.
 * Run: php tests/unit/BkashGatewayTest.php
 */

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['t_transients'] = array();
function get_transient( string $k ) {
	return $GLOBALS['t_transients'][ $k ] ?? false;
}
function set_transient( string $k, $v, int $ttl = 0 ): bool {
	$GLOBALS['t_transients'][ $k ] = $v;
	return true;
}
function delete_transient( string $k ): bool {
	unset( $GLOBALS['t_transients'][ $k ] );
	return true;
}

$dir = __DIR__ . '/../../wp-content/plugins/coaching-platform/includes/payments/';
require $dir . 'interface-payment-gateway.php';
require $dir . 'interface-refundable-gateway.php';
require $dir . 'class-bkash-gateway.php';

$GLOBALS['t_pass'] = 0;
$GLOBALS['t_fail'] = array();
function check( string $name, $expected, $actual ): void {
	if ( $expected === $actual ) {
		$GLOBALS['t_pass']++;
		echo "  ok   $name\n";
		return;
	}
	$GLOBALS['t_fail'][] = $name;
	echo "  FAIL $name\n       expected " . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . "\n";
}
function throws( callable $fn, string $class = RuntimeException::class ): bool {
	try {
		$fn();
	} catch ( Throwable $e ) {
		return $e instanceof $class;
	}
	return false;
}

const CFG = array( 'mode' => 'sandbox', 'app_key' => 'KEY', 'app_secret' => 'SECRET', 'username' => 'u', 'password' => 'p' );

/** Scripted transport: a queue of [status, body] or Exception per call; records every request. */
function transport( array $script, array &$log ): callable {
	return static function ( string $url, array $headers, array $body ) use ( &$script, &$log ): array {
		$log[] = array( 'url' => $url, 'headers' => $headers, 'body' => $body );
		$next = array_shift( $script );
		if ( null === $next ) {
			throw new LogicException( 'unexpected call to ' . $url );
		}
		if ( $next instanceof Throwable ) {
			throw $next;
		}
		return $next;
	};
}
const GRANT = array( 200, array( 'statusCode' => '0000', 'id_token' => 'TOK1', 'expires_in' => 3600 ) );

echo "construction\n";
check( 'unknown mode refused', true, throws( fn() => new CC_Bkash_Gateway( array_merge( CFG, array( 'mode' => '' ) ) ) ) );
check( 'missing secret refused', true, throws( fn() => new CC_Bkash_Gateway( array_merge( CFG, array( 'app_secret' => ' ' ) ) ) ) );
check( 'id is bkash', 'bkash', ( new CC_Bkash_Gateway( CFG ) )->id() );

echo "create_payment\n";
$log = array();
$g   = new CC_Bkash_Gateway( CFG, transport( array( GRANT, array( 200, array( 'statusCode' => '0000', 'paymentID' => 'TR001', 'bkashURL' => 'https://pay.bka.sh/x' ) ) ), $log ), fn() => 1000 );
$res = $g->create_payment( array( 'number' => 'INV-20261007-5', 'amount' => 6000, 'currency' => 'BDT' ), 'https://s.test/wp-json/cc/v1/payments/callback' );
check( 'returns payment id and url', array( 'TR001', 'https://pay.bka.sh/x' ), $res );
check( 'grant sends credentials in headers', array( 'u', 'p' ), array( $log[0]['headers']['username'], $log[0]['headers']['password'] ) );
check( 'grant targets sandbox', true, str_contains( $log[0]['url'], 'tokenized.sandbox.bka.sh' ) && str_ends_with( $log[0]['url'], '/token/grant' ) );
check( 'create carries token and app key', array( 'TOK1', 'KEY' ), array( $log[1]['headers']['Authorization'], $log[1]['headers']['X-APP-Key'] ) );
check( 'amount is two-decimal string', '6000.00', $log[1]['body']['amount'] );
check( 'invoice number only, no PII', 'INV-20261007-5', $log[1]['body']['merchantInvoiceNumber'] );
check( 'callback url is passed through', 'https://s.test/wp-json/cc/v1/payments/callback', $log[1]['body']['callbackURL'] );
check( 'token cached for later calls', 'TOK1', $GLOBALS['t_transients']['cc_bkash_id_token']['token'] );

echo "token reuse and refresh\n";
$log = array();
$g   = new CC_Bkash_Gateway( CFG, transport( array( array( 200, array( 'statusCode' => '0000', 'transactionStatus' => 'Initiated' ) ) ), $log ), fn() => 1000 );
$g->query( 'TR001' );
check( 'cached token means no grant call', 1, count( $log ) );
$log = array();
$g   = new CC_Bkash_Gateway( CFG, transport( array( array( 200, array( 'statusCode' => '2079', 'statusMessage' => 'Invalid token' ) ), array( 200, array( 'statusCode' => '0000', 'id_token' => 'TOK2', 'expires_in' => 3600 ) ), array( 200, array( 'statusCode' => '0000', 'transactionStatus' => 'Initiated' ) ) ), $log ), fn() => 1000 );
$r = $g->query( 'TR001' );
check( 'bad token triggers one re-grant and a retry', array( 3, 'TOK2', 'pending' ), array( count( $log ), $log[2]['headers']['Authorization'], $r['status'] ) );
$GLOBALS['t_transients'] = array();
$log = array();
$g   = new CC_Bkash_Gateway( CFG, transport( array( array( 200, array( 'statusCode' => '2001' ) ) ), $log ), fn() => 5000 );
check( 'token grant failure throws', true, throws( fn() => $g->query( 'TR001' ) ) );
$GLOBALS['t_transients']['cc_bkash_id_token'] = array( 'token' => 'OLD', 'expires' => 900 );
$log = array();
$g   = new CC_Bkash_Gateway( CFG, transport( array( GRANT, array( 200, array( 'statusCode' => '0000', 'transactionStatus' => 'Initiated' ) ) ), $log ), fn() => 1000 );
$g->query( 'TR001' );
check( 'expired cached token is replaced', 2, count( $log ) );

echo "create failures\n";
$GLOBALS['t_transients'] = array();
$log = array();
$g   = new CC_Bkash_Gateway( CFG, transport( array( GRANT, array( 200, array( 'statusCode' => '2023', 'statusMessage' => 'Insufficient' ) ) ), $log ), fn() => 1000 );
check( 'create error throws', true, throws( fn() => $g->create_payment( array( 'number' => 'I', 'amount' => 1 ), 'https://s.test/cb' ) ) );
$log = array();
$g   = new CC_Bkash_Gateway( CFG, transport( array( array( 200, array( 'statusCode' => '0000', 'paymentID' => 'P', 'bkashURL' => 'http://evil.test/' ) ) ), $log ), fn() => 1000 );
check( 'non-https checkout url refused', true, throws( fn() => $g->create_payment( array( 'number' => 'I', 'amount' => 1 ), 'https://s.test/cb' ) ) );
$log = array();
$g   = new CC_Bkash_Gateway( CFG, transport( array( new RuntimeException( 'net' ), array( 200, array( 'statusCode' => '0000', 'paymentID' => 'P2', 'bkashURL' => 'https://pay.bka.sh/y' ) ) ), $log ), fn() => 1000 );
check( 'create retries once on a network error', 'P2', $g->create_payment( array( 'number' => 'I', 'amount' => 1 ), 'https://s.test/cb' )[0] );

echo "normalise\n";
$done = array( 'statusCode' => '0000', 'transactionStatus' => 'Completed', 'trxID' => 'TX9', 'amount' => '6000.00', 'merchantInvoiceNumber' => 'INV-1', 'currency' => 'BDT', 'id_token' => 'SECRET' );
$n    = CC_Bkash_Gateway::normalise( $done );
check( 'completed with trx id', array( 'completed', 'TX9', 6000.0, 'INV-1', 'BDT' ), array( $n['status'], $n['trx_id'], $n['amount'], $n['invoice'], $n['currency'] ) );
check( 'raw never keeps tokens', false, isset( $n['raw']['id_token'] ) );
check( 'completed without trx id is not completed', 'pending', CC_Bkash_Gateway::normalise( array( 'statusCode' => '0000', 'transactionStatus' => 'Completed' ) )['status'] );
check( 'completed with an error code is not completed', 'pending', CC_Bkash_Gateway::normalise( array( 'statusCode' => '2062', 'transactionStatus' => 'Completed', 'trxID' => 'X' ) )['status'] );
check( 'initiated is pending', 'pending', CC_Bkash_Gateway::normalise( array( 'statusCode' => '0000', 'transactionStatus' => 'Initiated' ) )['status'] );
check( 'failed maps to failed', 'failed', CC_Bkash_Gateway::normalise( array( 'transactionStatus' => 'Failed' ) )['status'] );
check( 'cancelled maps to cancelled', 'cancelled', CC_Bkash_Gateway::normalise( array( 'transactionStatus' => 'Cancelled' ) )['status'] );
check( 'garbage is pending, never completed', 'pending', CC_Bkash_Gateway::normalise( array() )['status'] );

echo "execute\n";
$GLOBALS['t_transients'] = array();
$log = array();
$g   = new CC_Bkash_Gateway( CFG, transport( array( GRANT, array( 200, $done ) ), $log ), fn() => 1000 );
check( 'execute returns the completed report', 'completed', $g->execute( 'TR001' )['status'] );
check( 'execute called once', true, str_ends_with( $log[1]['url'], '/execute' ) && 2 === count( $log ) );
$log = array();
$g   = new CC_Bkash_Gateway( CFG, transport( array( array( 200, array( 'statusCode' => '2062', 'statusMessage' => 'already' ) ), array( 200, $done ) ), $log ), fn() => 1000 );
check( 'already-executed falls back to query', array( 'completed', true ), array( $g->execute( 'TR001' )['status'], str_ends_with( $log[1]['url'], '/payment/status' ) ) );
$log = array();
$g   = new CC_Bkash_Gateway( CFG, transport( array( new RuntimeException( 'timeout' ), array( 200, array( 'statusCode' => '0000', 'transactionStatus' => 'Initiated' ) ) ), $log ), fn() => 1000 );
check( 'execute network error is not retried, query decides', array( 'pending', 2 ), array( $g->execute( 'TR001' )['status'], count( $log ) ) );
$log = array();
$g   = new CC_Bkash_Gateway( CFG, transport( array( array( 503, array() ), array( 503, array() ) ), $log ), fn() => 1000 );
check( 'query HTTP 5xx throws after one retry attempt', true, throws( function () use ( $g ) {
	$g->query( 'TR001' );
} ) );

echo "refund\n";
$GLOBALS['t_transients'] = array();
$log = array();
$g   = new CC_Bkash_Gateway( CFG, transport( array( GRANT, array( 200, array( 'statusCode' => '0000', 'transactionStatus' => 'Completed', 'refundTrxId' => 'RF123' ) ) ), $log ), fn() => 1000 );
check( 'is a refundable gateway', true, $g instanceof CC_Refundable_Gateway );
$r = $g->refund( 'TR001', 'TX9', 6000.0, 'Student withdrew' );
check( 'refund succeeds with a reference', array( true, 'RF123', '' ), array( $r['ok'], $r['refund_trx_id'], $r['error'] ) );
check( 'refund hits the refund endpoint with payment, trx and amount', array( true, 'TR001', 'TX9', '6000.00' ), array( str_ends_with( $log[1]['url'], '/payment/refund' ), $log[1]['body']['paymentId'], $log[1]['body']['trxId'], $log[1]['body']['amount'] ) );
$log = array();
$g   = new CC_Bkash_Gateway( CFG, transport( array( array( 200, array( 'statusCode' => '2060', 'statusMessage' => 'Refund not allowed' ) ) ), $log ), fn() => 1000 );
$r   = $g->refund( 'TR001', 'TX9', 1.0, 'x' );
check( 'a refusal is reported, not thrown', array( false, '' ), array( $r['ok'], $r['refund_trx_id'] ) );
check( 'the refusal carries the bKash code', true, str_contains( $r['error'], '2060' ) );
check( 'completed without a refund reference is not success', false, ( new CC_Bkash_Gateway( CFG, transport( array( array( 200, array( 'statusCode' => '0000', 'transactionStatus' => 'Completed' ) ) ), $log ), fn() => 1000 ) )->refund( 'a', 'b', 1.0, 'x' )['ok'] );
$log = array();
$g   = new CC_Bkash_Gateway( CFG, transport( array( new RuntimeException( 'timeout' ) ), $log ), fn() => 1000 );
$r   = $g->refund( 'TR001', 'TX9', 1.0, 'x' );
check( 'a transport failure is not retried and is not success', array( false, 1 ), array( $r['ok'], count( $log ) ) );

echo "\n" . $GLOBALS['t_pass'] . ' passed, ' . count( $GLOBALS['t_fail'] ) . " failed\n";
exit( $GLOBALS['t_fail'] ? 1 : 0 );
