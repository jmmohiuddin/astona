<?php
/**
 * Dependency-free tests for the pure CC_Live_Repository helpers: state(), url_error(), field_error(), mask_url().
 * Run: php tests/unit/LiveStateTest.php
 */

define( 'ABSPATH', __DIR__ . '/' );

require __DIR__ . '/../../wp-content/plugins/coaching-platform/includes/live/class-live-repository.php';

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

$start = '2026-10-07 10:00:00';
$end   = '2026-10-07 11:00:00';
$row   = array( 'starts_at' => $start, 'ends_at' => $end );
$t0    = strtotime( $start . ' UTC' );
$t1    = strtotime( $end . ' UTC' );

echo "state boundaries\n";
check( 'well before T-15 is inactive', 'inactive', CC_Live_Repository::state( $row, $t0 - 3600 ) );
check( '1 second before T-15 is inactive', 'inactive', CC_Live_Repository::state( $row, $t0 - 901 ) );
check( 'exactly T-15 is active', 'active', CC_Live_Repository::state( $row, $t0 - 900 ) );
check( 'exactly start is active', 'active', CC_Live_Repository::state( $row, $t0 ) );
check( '1 second before end is active', 'active', CC_Live_Repository::state( $row, $t1 - 1 ) );
check( 'exactly end is ended', 'ended', CC_Live_Repository::state( $row, $t1 ) );
check( 'long after end is ended', 'ended', CC_Live_Repository::state( $row, $t1 + 86400 ) );
check( 'row times are read as UTC regardless of the PHP timezone', 'inactive', ( static function () use ( $row, $t0 ) {
	date_default_timezone_set( 'Asia/Dhaka' );
	$state = CC_Live_Repository::state( $row, $t0 - 901 );
	date_default_timezone_set( 'UTC' );
	return $state;
} )() );
check( 'default now is the current time', 'ended', CC_Live_Repository::state( array( 'starts_at' => '2000-01-01 00:00:00', 'ends_at' => '2000-01-01 01:00:00' ) ) );

echo "url validation\n";
check( 'meet https accepted', null, CC_Live_Repository::url_error( 'https://meet.google.com/abc-defg-hij', 'meet' ) );
check( 'zoom subdomain accepted', null, CC_Live_Repository::url_error( 'https://us02web.zoom.us/j/123456789?pwd=abc', 'zoom' ) );
check( 'zoom apex accepted', null, CC_Live_Repository::url_error( 'https://zoom.us/j/123', 'zoom' ) );
check( 'other accepts any https host', null, CC_Live_Repository::url_error( 'https://example.org/room', 'other' ) );
check( 'punycode IDN host accepted', null, CC_Live_Repository::url_error( 'https://xn--mt-fka.example.org/x', 'other' ) );
check( 'uppercase scheme and host accepted', null, CC_Live_Repository::url_error( 'HTTPS://Meet.Google.com/abc', 'meet' ) );
foreach ( array(
	'http scheme' => array( 'http://meet.google.com/abc', 'meet' ),
	'javascript scheme' => array( 'javascript:alert(1)', 'other' ),
	'data scheme' => array( 'data:text/html,hi', 'other' ),
	'no scheme' => array( 'meet.google.com/abc', 'meet' ),
	'empty' => array( '', 'other' ),
	'meet link with zoom host' => array( 'https://us02web.zoom.us/j/1', 'meet' ),
	'zoom link with meet host' => array( 'https://meet.google.com/abc', 'zoom' ),
	'lookalike suffix host' => array( 'https://evilzoom.us/j/1', 'zoom' ),
	'lookalike meet host' => array( 'https://meet.google.com.evil.test/x', 'meet' ),
	'userinfo trick' => array( 'https://meet.google.com@evil.test/x', 'meet' ),
	'userinfo on other' => array( 'https://user:pw@example.org/x', 'other' ),
	'custom port' => array( 'https://example.org:8443/x', 'other' ),
	'whitespace' => array( 'https://example.org/a b', 'other' ),
	'newline' => array( "https://example.org/a\nb", 'other' ),
	'too long' => array( 'https://example.org/' . str_repeat( 'a', CC_Live_Repository::MAX_URL ), 'other' ),
	'trailing-dot host' => array( 'https://meet.google.com./abc', 'meet' ),
	'trailing-dot zoom host' => array( 'https://us02web.zoom.us./j/1', 'zoom' ),
	'backslash before zoom suffix' => array( 'https://evil.com\\.zoom.us/j/1', 'zoom' ),
	'backslash before meet suffix' => array( 'https://meet.google.com\\.evil.com/x', 'meet' ),
	'backslash on other provider' => array( 'https://evil.com\\.example.org/x', 'other' ),
	'backslash in path' => array( 'https://zoom.us/j\\1', 'zoom' ),
	'percent-encoded dot in host' => array( 'https://evil.com%2ezoom.us/j/1', 'zoom' ),
	'percent-encoded dot in meet host' => array( 'https://meet.google.com%2eevil.com/x', 'meet' ),
	'percent sign in query' => array( 'https://zoom.us/j/1?pwd=a%20b', 'zoom' ),
	'underscore host' => array( 'https://ev_il.example.org/x', 'other' ),
	'non-punycode IDN host' => array( "https://m\u{00e9}et.example.org/x", 'other' ),
	'leading-dot host' => array( 'https://.zoom.us/j/1', 'zoom' ),
	'doubled-dot host' => array( 'https://evil..zoom.us/j/1', 'zoom' ),
) as $label => $args ) {
	check( "$label rejected", true, is_string( CC_Live_Repository::url_error( $args[0], $args[1] ) ) );
}

echo "field validation\n";
$ok = array( 'batch_id' => 1, 'title' => 'Algebra', 'starts_at' => $start, 'ends_at' => $end, 'provider' => 'meet' );
$url = 'https://meet.google.com/abc-defg-hij';
check( 'valid fields accepted', null, CC_Live_Repository::field_error( $ok, $url ) );
check( 'ends equal to starts rejected', true, is_string( CC_Live_Repository::field_error( array_merge( $ok, array( 'ends_at' => $start ) ), $url ) ) );
check( 'ends before starts rejected', true, is_string( CC_Live_Repository::field_error( array_merge( $ok, array( 'ends_at' => '2026-10-07 09:00:00' ) ), $url ) ) );
check( 'malformed time rejected', true, is_string( CC_Live_Repository::field_error( array_merge( $ok, array( 'starts_at' => '2026-13-40 10:00:00' ) ), $url ) ) );
check( 'empty time rejected', true, is_string( CC_Live_Repository::field_error( array_merge( $ok, array( 'starts_at' => '' ) ), $url ) ) );
check( 'blank title rejected', true, is_string( CC_Live_Repository::field_error( array_merge( $ok, array( 'title' => '  ' ) ), $url ) ) );
check( 'over-long title rejected', true, is_string( CC_Live_Repository::field_error( array_merge( $ok, array( 'title' => str_repeat( 'a', 191 ) ) ), $url ) ) );
check( 'missing batch rejected', true, is_string( CC_Live_Repository::field_error( array_merge( $ok, array( 'batch_id' => 0 ) ), $url ) ) );
check( 'unknown provider rejected', true, is_string( CC_Live_Repository::field_error( array_merge( $ok, array( 'provider' => 'skype' ) ), $url ) ) );
check( 'provider and host mismatch rejected', true, is_string( CC_Live_Repository::field_error( array_merge( $ok, array( 'provider' => 'zoom' ) ), $url ) ) );
check( 'http url rejected through field_error', true, is_string( CC_Live_Repository::field_error( $ok, 'http://meet.google.com/abc' ) ) );

echo "masking\n";
check( 'meet link masked to host and last 3 chars', 'https://meet.google.com/…hij', CC_Live_Repository::mask_url( 'https://meet.google.com/abc-defg-hij' ) );
check( 'query string never shown', 'https://us02web.zoom.us/…789', CC_Live_Repository::mask_url( 'https://us02web.zoom.us/j/123456789?pwd=SECRET' ) );
check( 'short path shows nothing of it', 'https://example.org/…', CC_Live_Repository::mask_url( 'https://example.org/ab' ) );
check( 'garbage masks to empty', '', CC_Live_Repository::mask_url( 'not a url' ) );

$fails = count( $GLOBALS['t_fail'] );
echo "\n{$GLOBALS['t_pass']} passed, $fails failed\n";
exit( $fails > 0 ? 1 : 0 );
