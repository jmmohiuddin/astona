<?php
/**
 * Dependency-free tests for CC_Ga4 (request building, enablement) and CC_Rest_Health::evaluate().
 * Run: php tests/unit/Ga4HealthTest.php
 */

define( 'ABSPATH', __DIR__ . '/' );
class WP_REST_Server { const READABLE = 'GET'; }
class WP_REST_Response {}
function add_action() {}

$dir = __DIR__ . '/../../wp-content/plugins/coaching-platform/includes/';
require $dir . 'analytics/class-ga4.php';
require $dir . 'health/class-rest-health.php';

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
function wp_json_encode( $v ) {
	return json_encode( $v );
}

echo "ga4 enablement\n";
putenv( 'GA4_MEASUREMENT_ID' );
putenv( 'GA4_API_SECRET' );
check( 'disabled when unset', false, CC_Ga4::enabled() );
putenv( 'GA4_MEASUREMENT_ID=G-ABCD1234' );
check( 'disabled without the secret', false, CC_Ga4::enabled() );
putenv( 'GA4_API_SECRET=s3cret' );
check( 'enabled with both', true, CC_Ga4::enabled() );

echo "ga4 request\n";
$row = array( 'trx_id' => 'TX9', 'amount' => '6000.00', 'currency' => 'BDT', 'public_ref' => '01HZXAPPREF0000000000000AB', 'batch_id' => '4' );
$req = CC_Ga4::request( $row );
check( 'measurement protocol endpoint with id and secret', 'https://www.google-analytics.com/mp/collect?measurement_id=G-ABCD1234&api_secret=s3cret', $req['url'] );
check( 'event is payment_success', 'payment_success', $req['body']['events'][0]['name'] );
check( 'params carry transaction, value, currency, batch', array( 'TX9', 6000.0, 'BDT', 4 ), array_values( $req['body']['events'][0]['params'] ) );
check( 'client id is a hash, not the application reference', false, str_contains( $req['body']['client_id'], 'APPREF' ) );
check( 'client id is stable per application', $req['body']['client_id'], CC_Ga4::request( $row )['body']['client_id'] );
check( 'no phone, name or email in the payload', false, (bool) preg_match( '/phone|email|full_name|\+880/i', json_encode( $req['body'] ) ) );

echo "health\n";
$ok = array( 'db' => true, 'heartbeat_age' => 30, 'stuck_payments' => 0, 'reconcile_needed' => 0 );
check( 'all good is ok', true, CC_Rest_Health::evaluate( $ok )['ok'] );
check( 'db down fails', false, CC_Rest_Health::evaluate( array_merge( $ok, array( 'db' => false ) ) )['checks']['database'] );
check( 'no heartbeat yet fails the scheduler check', false, CC_Rest_Health::evaluate( array_merge( $ok, array( 'heartbeat_age' => null ) ) )['checks']['scheduler'] );
check( 'heartbeat older than 5 minutes fails', false, CC_Rest_Health::evaluate( array_merge( $ok, array( 'heartbeat_age' => 301 ) ) )['ok'] );
check( 'heartbeat at 5 minutes passes', true, CC_Rest_Health::evaluate( array_merge( $ok, array( 'heartbeat_age' => 300 ) ) )['ok'] );
check( 'a stuck payment fails', false, CC_Rest_Health::evaluate( array_merge( $ok, array( 'stuck_payments' => 1 ) ) )['ok'] );
check( 'any reconcile_needed fails', false, CC_Rest_Health::evaluate( array_merge( $ok, array( 'reconcile_needed' => 2 ) ) )['ok'] );
check( 'output is booleans only', true, array() === array_filter( CC_Rest_Health::evaluate( $ok )['checks'], 'is_int' ) );

echo "\n" . $GLOBALS['t_pass'] . ' passed, ' . count( $GLOBALS['t_fail'] ) . " failed\n";
exit( $GLOBALS['t_fail'] ? 1 : 0 );
