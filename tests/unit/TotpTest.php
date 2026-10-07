<?php
/**
 * Dependency-free tests for CC_Totp against the RFC 6238 SHA-1 vectors.
 * Run: php tests/unit/TotpTest.php
 */

define( 'ABSPATH', __DIR__ . '/' );
require __DIR__ . '/../../wp-content/plugins/coaching-platform/includes/security/class-totp.php';

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

$secret = CC_Totp::base32_encode( '12345678901234567890' ); // RFC 6238 appendix B key.

echo "RFC 6238 vectors (8 digits)\n";
foreach ( array( 59 => '94287082', 1111111109 => '07081804', 1111111111 => '14050471', 1234567890 => '89005924', 2000000000 => '69279037', 20000000000 => '65353130' ) as $t => $expected ) {
	check( "T=$t", $expected, CC_Totp::code( $secret, CC_Totp::step_at( $t ), 8 ) );
}

echo "6 digits and verify\n";
check( 'six digit code at T=59', '287082', CC_Totp::code( $secret, CC_Totp::step_at( 59 ) ) );
check( 'accepts the current step', 1, CC_Totp::verify( $secret, '287082', 59 ) );
check( 'accepts a code with spaces', 1, CC_Totp::verify( $secret, '287 082', 59 ) );
check( 'accepts one step of drift (late)', 1, CC_Totp::verify( $secret, '287082', 59 + 30 ) );
check( 'accepts one step of drift (early)', 1, CC_Totp::verify( $secret, '287082', 59 - 30 ) );
check( 'rejects two steps of drift', null, CC_Totp::verify( $secret, '287082', 59 + 90 ) );
check( 'rejects a wrong code', null, CC_Totp::verify( $secret, '000000', 59 ) );
check( 'rejects non-numeric input', null, CC_Totp::verify( $secret, 'abcdef', 59 ) );
check( 'rejects short input', null, CC_Totp::verify( $secret, '2870', 59 ) );
check( 'replay of a used step is refused', null, CC_Totp::verify( $secret, '287082', 59, 1 ) );
check( 'a later step still works after an earlier one was used', 1, CC_Totp::verify( $secret, '287082', 59, 0 ) );

echo "base32\n";
check( 'round trip', 'Hello!', CC_Totp::base32_decode( CC_Totp::base32_encode( 'Hello!' ) ) );
check( 'RFC base32 of the test key', 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $secret );
check( 'lower case and spaces tolerated', '12345678901234567890', CC_Totp::base32_decode( 'gezd gnbv gy3t qojq gezd gnbv gy3t qojq' ) );
$s = CC_Totp::generate_secret();
check( 'generated secret is 32 base32 characters', true, 1 === preg_match( '/^[A-Z2-7]{32}$/', $s ) );
check( 'two secrets differ', true, $s !== CC_Totp::generate_secret() );
check( 'uri carries issuer, secret and parameters', true, str_contains( CC_Totp::uri( $s, 'a@b.test', 'Astona' ), 'secret=' . $s . '&issuer=Astona&algorithm=SHA1&digits=6&period=30' ) );

echo "\n" . $GLOBALS['t_pass'] . ' passed, ' . count( $GLOBALS['t_fail'] ) . " failed\n";
exit( $GLOBALS['t_fail'] ? 1 : 0 );
