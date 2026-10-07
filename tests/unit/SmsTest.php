<?php
/**
 * Dependency-free tests for CC_Sms (segmenter, render), the fake driver and the factory.
 * Run: php tests/unit/SmsTest.php
 */

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['t_options'] = array();
function get_option( string $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['t_options'] ) ? $GLOBALS['t_options'][ $name ] : $default;
}
function update_option( string $name, $value, $autoload = null ): bool {
	$GLOBALS['t_options'][ $name ] = $value;
	return true;
}
function delete_option( string $name ): bool {
	unset( $GLOBALS['t_options'][ $name ] );
	return true;
}
function wp_get_environment_type(): string {
	return 'local';
}

$dir = __DIR__ . '/../../wp-content/plugins/coaching-platform/includes/sms/';
require $dir . 'interface-sms-driver.php';
require $dir . 'class-sms-fake-driver.php';
require $dir . 'class-sms-factory.php';
require $dir . 'class-sms.php';

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

function throws( callable $fn, string $class ): bool {
	try {
		$fn();
	} catch ( Throwable $e ) {
		return $e instanceof $class;
	}
	return false;
}

echo "segments GSM-7\n";
check( 'empty body is 0', 0, CC_Sms::segments( '' ) );
check( 'short ascii is 1', 1, CC_Sms::segments( 'Hello' ) );
check( '160 ascii chars is 1', 1, CC_Sms::segments( str_repeat( 'a', 160 ) ) );
check( '161 ascii chars is 2', 2, CC_Sms::segments( str_repeat( 'a', 161 ) ) );
check( '306 ascii chars is 2 (153*2)', 2, CC_Sms::segments( str_repeat( 'a', 306 ) ) );
check( '307 ascii chars is 3', 3, CC_Sms::segments( str_repeat( 'a', 307 ) ) );
check( 'extension chars count double: 80 braces is 1', 1, CC_Sms::segments( str_repeat( '{', 80 ) ) );
check( 'extension chars count double: 81 braces is 2', 2, CC_Sms::segments( str_repeat( '{', 81 ) ) );
check( 'GSM accented char stays GSM-7', 1, CC_Sms::segments( str_repeat( 'é', 160 ) ) );

echo "segments UCS-2\n";
check( 'one Bangla char is 1', 1, CC_Sms::segments( 'আ' ) );
check( '70 Bangla chars is 1', 1, CC_Sms::segments( str_repeat( 'আ', 70 ) ) );
check( '71 Bangla chars is 2', 2, CC_Sms::segments( str_repeat( 'আ', 71 ) ) );
check( '134 Bangla chars is 2 (67*2)', 2, CC_Sms::segments( str_repeat( 'আ', 134 ) ) );
check( '135 Bangla chars is 3', 3, CC_Sms::segments( str_repeat( 'আ', 135 ) ) );
check( 'one non-GSM char flips whole message to UCS-2', 2, CC_Sms::segments( str_repeat( 'a', 70 ) . 'আ' ) );
check( 'emoji counts as two UTF-16 units (35 emoji fit, 36 do not)', 2, CC_Sms::segments( str_repeat( "\u{1F600}", 36 ) ) );
check( '35 emoji is 1', 1, CC_Sms::segments( str_repeat( "\u{1F600}", 35 ) ) );
check( 'invalid UTF-8 does not crash', 1, CC_Sms::segments( "\xff\xfe" ) );

echo "render\n";
check(
	'otp substitutes code',
	'Astona: Your login code is 123456. It expires in 5 minutes. Do not share it.',
	CC_Sms::render( 'otp', array( 'code' => '123456' ) )
);
check(
	'credentials substitutes phone, password, url',
	'Astona: Login phone +8801712345678, temporary password Abc23xyz9K. Login at https://x.test/student/login/ (valid 72 hours, change it on first login).',
	CC_Sms::render( 'credentials', array( 'phone' => '+8801712345678', 'password' => 'Abc23xyz9K', 'url' => 'https://x.test/student/login/' ) )
);
check( 'missing variable renders empty', 'Astona: Your login code is . It expires in 5 minutes. Do not share it.', CC_Sms::render( 'otp', array() ) );
check( 'newlines in values are flattened', true, false === strpos( CC_Sms::render( 'otp', array( 'code' => "1\n2" ) ), "\n" ) );
check( 'value containing a placeholder is kept literally', true, false !== strpos( CC_Sms::render( 'credentials', array( 'password' => '{url}', 'url' => 'U' ) ), 'password {url}.' ) || false !== strpos( CC_Sms::render( 'credentials', array( 'password' => '{url}', 'url' => 'U' ) ), 'password {url},' ) );
check( 'bangla variant is UCS-2 and contains the code', true, false !== strpos( CC_Sms::render( 'otp', array( 'code' => '654321', 'lang' => 'bn' ) ), '654321' ) );
check( 'english otp is a single GSM-7 segment', 1, CC_Sms::segments( CC_Sms::render( 'otp', array( 'code' => '123456' ) ) ) );
check( 'english credentials fit one segment', 1, CC_Sms::segments( CC_Sms::render( 'credentials', array( 'phone' => '+8801712345678', 'password' => 'Abc23xyz9K', 'url' => 'https://astona.example/student/login/' ) ) ) );
check( 'unknown template throws', true, throws( fn() => CC_Sms::render( 'nope', array() ), InvalidArgumentException::class ) );

echo "fake driver\n";
CC_Sms_Factory::override_name( 'primary', 'fake' );
$driver = new CC_Sms_Fake_Driver();
check( 'id is fake', 'fake', $driver->id() );
$result = $driver->send( '+8801712345678', 'hi' );
check( 'send reports ok', true, $result[0] );
check( 'outbox holds the message', array( '+8801712345678', 'hi' ), array( CC_Sms_Fake_Driver::outbox()[0]['to'], CC_Sms_Fake_Driver::outbox()[0]['body'] ) );
for ( $i = 0; $i < 60; $i++ ) {
	$driver->send( '+8801712345678', "m$i" );
}
check( 'outbox capped at 50', 50, count( CC_Sms_Fake_Driver::outbox() ) );
check( 'outbox keeps the newest', 'm59', array_slice( CC_Sms_Fake_Driver::outbox(), -1 )[0]['body'] );
CC_Sms_Fake_Driver::clear();
check( 'clear empties the outbox', array(), CC_Sms_Fake_Driver::outbox() );
CC_Sms_Fake_Driver::override_environment_type( 'production' );
check( 'refused outside local/development', true, throws( fn() => new CC_Sms_Fake_Driver(), RuntimeException::class ) );
CC_Sms_Fake_Driver::override_environment_type( null );
CC_Sms_Factory::override_name( 'primary', 'other' );
check( 'refused when neither role is "fake"', true, throws( fn() => new CC_Sms_Fake_Driver(), RuntimeException::class ) );

echo "factory\n";
CC_Sms_Factory::override_name( 'primary', '' );
check( 'primary throws when unset', true, throws( fn() => CC_Sms_Factory::primary(), RuntimeException::class ) );
CC_Sms_Factory::override_name( 'primary', 'bogus' );
check( 'primary throws for unknown driver', true, throws( fn() => CC_Sms_Factory::primary(), RuntimeException::class ) );
CC_Sms_Factory::override_name( 'primary', ' FAKE ' );
check( 'name is trimmed and lower-cased', 'fake', CC_Sms_Factory::primary()->id() );
check( 'fallback is null when unset', null, CC_Sms_Factory::fallback() );
CC_Sms_Factory::register_driver( 'stub', fn() => new CC_Sms_Fake_Driver() );
CC_Sms_Factory::override_name( 'fallback', 'stub' );
check( 'registered drivers resolve', 'fake', CC_Sms_Factory::fallback()->id() );

$fails = count( $GLOBALS['t_fail'] );
echo "\n{$GLOBALS['t_pass']} passed, $fails failed\n";
exit( $fails > 0 ? 1 : 0 );
