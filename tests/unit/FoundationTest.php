<?php
/**
 * Dependency-free tests for CC_Crypto, CC_Phone, CC_Rate_Limiter, CC_Idempotency.
 * Run: php tests/unit/FoundationTest.php
 */

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['t_env']        = 'local';
$GLOBALS['t_transients'] = array();

function wp_salt( string $scheme = 'auth' ): string {
	return 'test-salt-' . $scheme;
}
function wp_get_environment_type(): string {
	return $GLOBALS['t_env'];
}
function get_transient( string $key ) {
	return $GLOBALS['t_transients'][ $key ] ?? false;
}
function set_transient( string $key, $value, int $ttl ): bool {
	$GLOBALS['t_transients'][ $key ] = $value;
	return true;
}

// In-memory stand-in for an external object cache (the limiter's atomic path); entries expire at 'exp'.
$GLOBALS['t_cache'] = array();
function wp_using_ext_object_cache(): bool {
	return true;
}
function t_cache_live( string $key, string $group ): bool {
	return isset( $GLOBALS['t_cache'][ $group . $key ] ) && $GLOBALS['t_cache'][ $group . $key ]['exp'] > time();
}
function wp_cache_add( string $key, $value, string $group = '', int $ttl = 0 ): bool {
	if ( t_cache_live( $key, $group ) ) {
		return false;
	}
	$GLOBALS['t_cache'][ $group . $key ] = array( 'value' => $value, 'exp' => time() + $ttl );
	return true;
}
function wp_cache_incr( string $key, int $by = 1, string $group = '' ) {
	if ( ! t_cache_live( $key, $group ) ) {
		return false;
	}
	$GLOBALS['t_cache'][ $group . $key ]['value'] += $by;
	return $GLOBALS['t_cache'][ $group . $key ]['value'];
}
function wp_cache_get( string $key, string $group = '' ) {
	return t_cache_live( $key, $group ) ? $GLOBALS['t_cache'][ $group . $key ]['value'] : false;
}
function wp_cache_delete( string $key, string $group = '' ): bool {
	unset( $GLOBALS['t_cache'][ $group . $key ] );
	return true;
}

$support = __DIR__ . '/../../wp-content/plugins/coaching-platform/includes/support/';
foreach ( array( 'crypto', 'phone', 'rate-limiter', 'idempotency' ) as $f ) {
	require $support . "class-$f.php";
}

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

function throws( callable $fn ): bool {
	try {
		$fn();
	} catch ( RuntimeException $e ) {
		return true;
	}
	return false;
}

echo "phone::normalize valid\n";
$valid = array(
	'01712345678'        => '+8801712345678',
	'8801712345678'      => '+8801712345678',
	'+8801712345678'     => '+8801712345678',
	'+880 1712 345678'   => '+8801712345678',
	'017-1234-5678'      => '+8801712345678',
	' 01712 345 678 '    => '+8801712345678',
	'(+88) 01712-345678' => '+8801712345678',
	'01312345678'        => '+8801312345678',
	'01999999999'        => '+8801999999999',
);
foreach ( $valid as $in => $out ) {
	check( "normalize '$in'", $out, CC_Phone::normalize( $in ) );
}

echo "phone::normalize invalid\n";
$invalid = array( '', '   ', '1712345678', '0171234567', '017123456789', '01212345678', '01012345678', '01112345678', '02712345678', '+8811712345678', '+8801712 34567a', 'abcdefghijk', '+88017123456789', '00880171234567' );
foreach ( $invalid as $in ) {
	check( "reject '$in'", null, CC_Phone::normalize( $in ) );
}

echo "phone::mask\n";
check( 'mask e164', '+88017*****678', CC_Phone::mask( '+8801712345678' ) );
check( 'mask keeps length', 14, strlen( CC_Phone::mask( '+8801712345678' ) ) );
check( 'mask short input', '***', CC_Phone::mask( '123' ) );

echo "crypto\n";
$plain = 'NID-1234567890';
$blob  = CC_Crypto::encrypt( $plain );
check( 'blob has k1 prefix', true, 0 === strpos( $blob, 'k1:' ) );
check( 'round trip (local dev key)', $plain, CC_Crypto::decrypt( $blob ) );
check( 'round trip empty string', '', CC_Crypto::decrypt( CC_Crypto::encrypt( '' ) ) );
check( 'round trip unicode', 'জন্ম', CC_Crypto::decrypt( CC_Crypto::encrypt( 'জন্ম' ) ) );
check( 'nonce randomises output', true, CC_Crypto::encrypt( $plain ) !== $blob );
check( 'plaintext not in blob', false, strpos( $blob, $plain ) );

$raw      = base64_decode( substr( $blob, 3 ) );
$tampered = 'k1:' . base64_encode( substr( $raw, 0, -1 ) . chr( ord( substr( $raw, -1 ) ) ^ 1 ) );
check( 'tampered ciphertext throws', true, throws( fn() => CC_Crypto::decrypt( $tampered ) ) );
check( 'bad prefix throws', true, throws( fn() => CC_Crypto::decrypt( 'k2:' . substr( $blob, 3 ) ) ) );
check( 'non-base64 throws', true, throws( fn() => CC_Crypto::decrypt( 'k1:!!!' ) ) );
check( 'truncated throws', true, throws( fn() => CC_Crypto::decrypt( 'k1:' . base64_encode( 'short' ) ) ) );

$GLOBALS['t_env'] = 'production';
check( 'missing key in production: encrypt throws', true, throws( fn() => CC_Crypto::encrypt( 'x' ) ) );
check( 'missing key in production: decrypt throws', true, throws( fn() => CC_Crypto::decrypt( $blob ) ) );
putenv( 'CC_ENC_KEY=' . base64_encode( str_repeat( 'k', 32 ) ) );
$prod_blob = CC_Crypto::encrypt( $plain );
check( 'env key round trip in production', $plain, CC_Crypto::decrypt( $prod_blob ) );
check( 'dev-key blob not decryptable with env key', true, throws( fn() => CC_Crypto::decrypt( $blob ) ) );
putenv( 'CC_ENC_KEY=' . base64_encode( 'too-short' ) );
check( 'wrong-length key throws', true, throws( fn() => CC_Crypto::encrypt( 'x' ) ) );
putenv( 'CC_ENC_KEY' );
$GLOBALS['t_env'] = 'local';

echo "idempotency\n";
check( 'valid uuid v4', true, CC_Idempotency::valid_key( '3f2b8c1e-5d4a-4b7e-9a10-1c2d3e4f5a6b' ) );
check( 'valid uuid v4 uppercase', true, CC_Idempotency::valid_key( '3F2B8C1E-5D4A-4B7E-9A10-1C2D3E4F5A6B' ) );
check( 'reject uuid v1', false, CC_Idempotency::valid_key( '3f2b8c1e-5d4a-1b7e-9a10-1c2d3e4f5a6b' ) );
check( 'reject bad variant', false, CC_Idempotency::valid_key( '3f2b8c1e-5d4a-4b7e-1a10-1c2d3e4f5a6b' ) );
check( 'reject empty', false, CC_Idempotency::valid_key( '' ) );
check( 'reject trailing newline', false, CC_Idempotency::valid_key( "3f2b8c1e-5d4a-4b7e-9a10-1c2d3e4f5a6b\n" ) );
check( 'reject garbage', false, CC_Idempotency::valid_key( 'not-a-uuid' ) );
$ek = CC_Idempotency::event_key( 'payment', '42', 'completed' );
check( 'event_key is 64 hex chars', 1, preg_match( '/^[0-9a-f]{64}$/', $ek ) );
check( 'event_key deterministic', $ek, CC_Idempotency::event_key( 'payment', '42', 'completed' ) );
check( 'event_key order matters', true, $ek !== CC_Idempotency::event_key( 'payment', 'completed', '42' ) );
check( 'event_key part boundaries matter', true, CC_Idempotency::event_key( 'ab', 'c' ) !== CC_Idempotency::event_key( 'a', 'bc' ) );
check( 'event_key with no parts still 64 chars', 64, strlen( CC_Idempotency::event_key() ) );

echo "rate limiter\n";
check( 'first call allowed', true, CC_Rate_Limiter::allow( 'b1', 2, 60 ) );
check( 'second call allowed', true, CC_Rate_Limiter::allow( 'b1', 2, 60 ) );
check( 'third call blocked', false, CC_Rate_Limiter::allow( 'b1', 2, 60 ) );
check( 'other bucket independent', true, CC_Rate_Limiter::allow( 'b2', 2, 60 ) );
check( 'count reads without consuming', 3, CC_Rate_Limiter::count( 'b1' ) );
$GLOBALS['t_cache'][ CC_Rate_Limiter::CACHE_GROUP . md5( 'b1' ) ]['exp'] = time() - 1;
check( 'allowed again after window expires', true, CC_Rate_Limiter::allow( 'b1', 2, 60 ) );
CC_Rate_Limiter::reset( 'b1' );
check( 'reset clears the bucket', 0, CC_Rate_Limiter::count( 'b1' ) );
unset( $_SERVER['REMOTE_ADDR'] );
check( 'client_ip without REMOTE_ADDR', '0.0.0.0', CC_Rate_Limiter::client_ip() );
$_SERVER['REMOTE_ADDR']          = '203.0.113.9';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4';
check( 'client_ip uses REMOTE_ADDR only', '203.0.113.9', CC_Rate_Limiter::client_ip() );
$_SERVER['REMOTE_ADDR'] = 'garbage';
check( 'client_ip rejects invalid', '0.0.0.0', CC_Rate_Limiter::client_ip() );

$fails = count( $GLOBALS['t_fail'] );
echo "\n{$GLOBALS['t_pass']} passed, $fails failed\n";
exit( $fails > 0 ? 1 : 0 );
