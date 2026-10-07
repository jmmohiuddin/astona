<?php
/**
 * Dependency-free tests for the pure contact helpers: map embed allowlist and inquiry validation.
 * Run: php tests/unit/ContactTest.php
 */

define( 'ABSPATH', __DIR__ . '/' );
$dir = __DIR__ . '/../../wp-content/plugins/coaching-platform/includes/';
require $dir . 'support/class-phone.php';
require $dir . 'contact/class-inquiry-repository.php';
require $dir . 'contact/class-branches.php';
require $dir . 'contact/class-rest-contact.php';

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

echo "embed allowlist: accepted\n";
$google = 'https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3651.9!2d90.39!3d23.75';
$osm    = 'https://www.openstreetmap.org/export/embed.html?bbox=90.38%2C23.74%2C90.42%2C23.78&layer=mapnik';
check( 'google maps embed', $google, CC_Branches::sanitize_embed_url( $google ) );
check( 'openstreetmap embed', $osm, CC_Branches::sanitize_embed_url( $osm ) );
check( 'surrounding whitespace is trimmed', $osm, CC_Branches::sanitize_embed_url( "  $osm\n" ) );

echo "embed allowlist: dropped\n";
foreach ( array(
	'javascript scheme'       => 'javascript:alert(1)',
	'data scheme'             => 'data:text/html,<script>alert(1)</script>',
	'http google'             => 'http://www.google.com/maps/embed?pb=1',
	'http osm'                => 'http://www.openstreetmap.org/export/embed.html?bbox=1',
	'evil host'               => 'https://evil.example/maps/embed?pb=1',
	'google.com.evil.com'     => 'https://www.google.com.evil.com/maps/embed?pb=1',
	'userinfo trick'          => 'https://www.google.com@evil.example/maps/embed?pb=1',
	'userinfo trick 2'        => 'https://evil.example/@www.google.com/maps/embed?pb=1',
	'google without www'      => 'https://google.com/maps/embed?pb=1',
	'google maps non-embed'   => 'https://www.google.com/maps?q=dhaka',
	'google embed no query'   => 'https://www.google.com/maps/embed',
	'google embed empty query' => 'https://www.google.com/maps/embed?',
	'google path prefix'      => 'https://www.google.com/maps/embedx?pb=1',
	'osm other path'          => 'https://www.openstreetmap.org/?mlat=1',
	'port'                    => 'https://www.google.com:8443/maps/embed?pb=1',
	'protocol-relative'       => '//www.google.com/maps/embed?pb=1',
	'quote injection'         => 'https://www.google.com/maps/embed?pb=1" onload="x',
	'angle bracket'           => 'https://www.google.com/maps/embed?pb=<script>',
	'space'                   => 'https://www.google.com/maps/embed?pb=1 2',
	'newline inside'          => "https://www.google.com/maps/embed?pb=1\nx",
	'backslash'               => 'https://www.google.com/maps/embed?pb=1\\x',
	'uppercase host'          => 'https://WWW.GOOGLE.COM/maps/embed?pb=1',
	'empty'                   => '',
	'overlong'                => 'https://www.google.com/maps/embed?pb=' . str_repeat( 'a', 2100 ),
) as $label => $url ) {
	check( $label, '', CC_Branches::sanitize_embed_url( $url ) );
}
check( 'array input', '', CC_Branches::sanitize_embed_url( array( $osm ) ) );
check( 'null input', '', CC_Branches::sanitize_embed_url( null ) );

echo "inquiry validation\n";
$valid = array( 'name' => 'Rahim Uddin', 'phone' => '01712-345678', 'email' => '', 'topic' => 'fees', 'course_id' => '', 'message' => 'How much is the monthly fee?' );
$r     = CC_Inquiry_Repository::validate( $valid );
check( 'valid input has no errors', array(), $r['errors'] );
check( 'phone is normalised', '+8801712345678', $r['data']['phone'] );
check( 'empty email stored as null', null, $r['data']['email'] );
check( 'empty course stored as null', null, $r['data']['course_id'] );

$errors = static fn( array $over ): array => array_keys( CC_Inquiry_Repository::validate( array_merge( $valid, $over ) )['errors'] );
check( 'name of 1 char fails', array( 'name' ), $errors( array( 'name' => 'A' ) ) );
check( 'name of 2 chars passes', array(), $errors( array( 'name' => 'Al' ) ) );
check( 'name of 190 chars passes', array(), $errors( array( 'name' => str_repeat( 'a', 190 ) ) ) );
check( 'name of 191 chars fails', array( 'name' ), $errors( array( 'name' => str_repeat( 'a', 191 ) ) ) );
check( 'multibyte name counts characters', array(), $errors( array( 'name' => 'রহিম' ) ) );
check( 'phone with letters fails', array( 'phone' ), $errors( array( 'phone' => '0171234abcd' ) ) );
check( 'landline-style phone fails', array( 'phone' ), $errors( array( 'phone' => '0212345678' ) ) );
check( 'phone with +88 prefix passes', array(), $errors( array( 'phone' => '+8801712345678' ) ) );
check( 'valid email passes', array(), $errors( array( 'email' => 'a@example.com' ) ) );
check( 'invalid email fails', array( 'email' ), $errors( array( 'email' => 'not-an-email' ) ) );
check( 'overlong email fails', array( 'email' ), $errors( array( 'email' => str_repeat( 'a', 185 ) . '@e.com' ) ) );
foreach ( CC_Inquiry_Repository::TOPICS as $topic ) {
	check( "topic $topic passes", array(), $errors( array( 'topic' => $topic ) ) );
}
check( 'unknown topic fails', array( 'topic' ), $errors( array( 'topic' => 'spam' ) ) );
check( 'empty topic fails', array( 'topic' ), $errors( array( 'topic' => '' ) ) );
check( 'message of 9 chars fails', array( 'message' ), $errors( array( 'message' => '123456789' ) ) );
check( 'message of 10 chars passes', array(), $errors( array( 'message' => '1234567890' ) ) );
check( 'message of 2000 chars passes', array(), $errors( array( 'message' => str_repeat( 'a', 2000 ) ) ) );
check( 'message of 2001 chars fails', array( 'message' ), $errors( array( 'message' => str_repeat( 'a', 2001 ) ) ) );
check( 'message padding is not counted', array( 'message' ), $errors( array( 'message' => "   short   \n" ) ) );
check( 'CRLF counts as one character', array(), $errors( array( 'message' => str_repeat( "a\r\n", 1000 ) ) ) );
check( 'non-numeric course id fails', array( 'course_id' ), $errors( array( 'course_id' => '12abc' ) ) );
check( 'negative course id fails', array( 'course_id' ), $errors( array( 'course_id' => '-1' ) ) );
check( 'all fields missing reports every required field', array( 'name', 'phone', 'topic', 'message' ), array_keys( CC_Inquiry_Repository::validate( array() )['errors'] ) );

echo "origin host matching\n";
check( 'same host origin passes', true, CC_Rest_Contact::host_matches( 'https://astona.example', 'astona.example' ) );
check( 'same host with port and path passes', true, CC_Rest_Contact::host_matches( 'http://astona.example:8080/contact/', 'astona.example' ) );
check( 'host compare ignores case', true, CC_Rest_Contact::host_matches( 'https://ASTONA.example', 'astona.example' ) );
check( 'other host fails', false, CC_Rest_Contact::host_matches( 'https://evil.example', 'astona.example' ) );
check( 'suffix lookalike fails', false, CC_Rest_Contact::host_matches( 'https://astona.example.evil.com', 'astona.example' ) );
check( 'userinfo trick fails', false, CC_Rest_Contact::host_matches( 'https://astona.example@evil.example/', 'astona.example' ) );
check( 'literal null origin fails', false, CC_Rest_Contact::host_matches( 'null', 'astona.example' ) );
check( 'empty home host never matches', false, CC_Rest_Contact::host_matches( 'https://astona.example', '' ) );

echo "limits and retention constants\n";
check( 'phone daily limit is 20', 20, CC_Rest_Contact::PHONE_LIMIT );
check( 'global daily limit is 200', 200, CC_Rest_Contact::GLOBAL_LIMIT );
check( 'new inquiries are kept twice as long as handled ones', 730, CC_Inquiry_Repository::RETENTION_DAYS * CC_Inquiry_Repository::NEW_RETENTION_MULTIPLIER );

$fails = count( $GLOBALS['t_fail'] );
echo "\n{$GLOBALS['t_pass']} passed, $fails failed\n";
exit( $fails > 0 ? 1 : 0 );
