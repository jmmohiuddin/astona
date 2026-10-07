<?php
/**
 * Dependency-free tests for the BulkSMSBD and GreenWeb drivers with a scripted transport.
 * Run: php tests/unit/SmsDriversTest.php
 */

define( 'ABSPATH', __DIR__ . '/' );

$dir = __DIR__ . '/../../wp-content/plugins/coaching-platform/includes/sms/';
require $dir . 'interface-sms-driver.php';
require $dir . 'class-sms-http-driver.php';
require $dir . 'class-sms-bulksmsbd-driver.php';
require $dir . 'class-sms-greenweb-driver.php';

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

function http( $answer, array &$log ): callable {
	return static function ( string $url, array $form ) use ( $answer, &$log ): array {
		$log[] = array( $url, $form );
		if ( $answer instanceof Throwable ) {
			throw $answer;
		}
		return $answer;
	};
}

putenv( 'BULKSMSBD_API_KEY=KEY123' );
putenv( 'BULKSMSBD_SENDER_ID=ASTONA' );
putenv( 'GREENWEB_TOKEN=TOK456' );

echo "bulksmsbd\n";
$log = array();
$d   = new CC_Sms_Bulksmsbd_Driver( http( array( 200, '{"response_code":202,"success_message":"SMS Submitted Successfully"}' ), $log ) );
$r   = $d->send( '+8801712345678', 'Your code is 123456' );
check( 'accepted on 202', true, $r[0] );
check( 'provider id is set', true, '' !== $r[1] && '' === $r[2] );
check( 'posts to https endpoint', 'https://bulksmsbd.net/api/smsapi', $log[0][0] );
check( 'number without plus, key and sender id sent', array( '8801712345678', 'KEY123', 'ASTONA', 'Your code is 123456' ), array( $log[0][1]['number'], $log[0][1]['api_key'], $log[0][1]['senderid'], $log[0][1]['message'] ) );
$r = ( new CC_Sms_Bulksmsbd_Driver( http( array( 200, '{"response_code":1007}' ), $log ) ) )->send( '+8801712345678', 'x' );
check( 'known error code is named', array( false, '', 'bulksmsbd rejected: insufficient balance' ), $r );
$r = ( new CC_Sms_Bulksmsbd_Driver( http( array( 200, 'not json' ), $log ) ) )->send( '+8801712345678', 'x' );
check( 'non-JSON answer is a failure', false, $r[0] );
$r = ( new CC_Sms_Bulksmsbd_Driver( http( array( 502, '' ), $log ) ) )->send( '+8801712345678', 'x' );
check( 'HTTP 5xx is a failure', array( false, '', 'bulksmsbd HTTP 502' ), $r );
$r = ( new CC_Sms_Bulksmsbd_Driver( http( new RuntimeException( 'bulksmsbd transport error: http_request_failed' ), $log ) ) )->send( '+8801712345678', 'x' );
check( 'transport exception becomes a failure, not a throw', array( false, '', 'bulksmsbd transport error: http_request_failed' ), $r );
putenv( 'BULKSMSBD_API_KEY' );
$log = array();
$r   = ( new CC_Sms_Bulksmsbd_Driver( http( array( 200, '{"response_code":202}' ), $log ) ) )->send( '+8801712345678', 'x' );
check( 'missing key fails without calling the provider', array( false, 0 ), array( $r[0], count( $log ) ) );
check( 'missing key error names the setting, not a value', 'BULKSMSBD_API_KEY is not configured', $r[2] );
putenv( 'BULKSMSBD_API_KEY=KEY123' );

echo "greenweb\n";
$log = array();
$d   = new CC_Sms_Greenweb_Driver( http( array( 200, 'Ok: Message Sent to 8801712345678' ), $log ) );
$r   = $d->send( '+8801712345678', 'hello' );
check( 'accepted on Ok', true, $r[0] );
check( 'token and number sent', array( 'TOK456', '8801712345678' ), array( $log[0][1]['token'], $log[0][1]['to'] ) );
$r = ( new CC_Sms_Greenweb_Driver( http( array( 200, 'Error: Invalid Token' ), $log ) ) )->send( '+8801712345678', 'hello' );
check( 'error text is reported', array( false, '', 'greenweb rejected: Error: Invalid Token' ), $r );
check( 'error text never contains the token', false, str_contains( $r[2], 'TOK456' ) );
$r = ( new CC_Sms_Greenweb_Driver( http( array( 0, '' ), $log ) ) )->send( '+8801712345678', 'hello' );
check( 'status 0 is a failure', false, $r[0] );

echo "bangla body passes through unchanged\n";
$log = array();
( new CC_Sms_Greenweb_Driver( http( array( 200, 'Ok' ), $log ) ) )->send( '+8801712345678', 'আপনার কোড ১২৩৪৫৬' );
check( 'unicode message untouched', 'আপনার কোড ১২৩৪৫৬', $log[0][1]['message'] );

echo "\n" . $GLOBALS['t_pass'] . ' passed, ' . count( $GLOBALS['t_fail'] ) . " failed\n";
exit( $GLOBALS['t_fail'] ? 1 : 0 );
