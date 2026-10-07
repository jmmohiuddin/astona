<?php
/**
 * Integration tests for contact inquiries, branches and the Inquiries admin screen. Run inside the wpcli container:
 *   docker compose run --rm -T wpcli eval-file /tests/integration/contact-test.php
 * Creates throwaway users, posts and inquiries (all names start with "ZZ Contact") and removes them, plus their audit rows.
 */
global $wpdb, $failures, $checks, $cleanup;
$p        = $wpdb->prefix;
$failures = 0;
$checks   = 0;
$cleanup  = array( 'users' => array(), 'posts' => array(), 'buckets' => array() );

foreach ( array( 'CC_Inquiry_Repository', 'CC_Rest_Contact', 'CC_Branches', 'CC_Admin_Inquiries', 'CC_Csv', 'CC_Rate_Limiter' ) as $class ) {
	if ( ! class_exists( $class ) ) {
		echo "SKIP: $class not loaded; cannot run contact integration tests.\n";
		exit( 1 );
	}
}
require_once ABSPATH . 'wp-admin/includes/admin.php';

function t_assert( bool $cond, string $label ): void {
	global $failures, $checks;
	++$checks;
	echo ( $cond ? '  ok   ' : '  FAIL ' ) . $label . "\n";
	if ( ! $cond ) {
		++$failures;
	}
}

function t_user( array $caps = array(), string $role = 'subscriber' ): int {
	global $cleanup;
	$id = wp_insert_user( array( 'user_login' => 'zzct' . bin2hex( random_bytes( 4 ) ), 'user_pass' => wp_generate_password( 20 ), 'user_email' => bin2hex( random_bytes( 4 ) ) . '@zz.invalid', 'role' => $role ) );
	$cleanup['users'][] = (int) $id;
	foreach ( $caps as $cap ) {
		( new WP_User( $id ) )->add_cap( $cap );
	}
	return (int) $id;
}

function t_post( string $type, string $status = 'publish' ): int {
	global $cleanup;
	$id = (int) wp_insert_post( array( 'post_type' => $type, 'post_status' => $status, 'post_title' => 'ZZ Contact ' . bin2hex( random_bytes( 3 ) ) ) );
	$cleanup['posts'][] = $id;
	return $id;
}

function t_phone(): string {
	return '+88017' . str_pad( (string) random_int( 0, 99999999 ), 8, '0', STR_PAD_LEFT );
}

function t_call( array $params, array $headers = array() ): WP_REST_Response {
	$request = new WP_REST_Request( 'POST', '/cc/v1/contact' );
	$request->set_header( 'content-type', 'application/json' );
	foreach ( $headers as $name => $value ) {
		$request->set_header( $name, $value );
	}
	$request->set_body( wp_json_encode( $params ) );
	return rest_do_request( $request );
}

function t_valid( array $over = array() ): array {
	return array_merge( array( 'name' => 'ZZ Contact Tester', 'phone' => t_phone(), 'email' => '', 'topic' => 'fees', 'course_id' => '', 'message' => 'How much is the monthly fee for HSC?' ), $over );
}

function t_rows(): int {
	global $wpdb;
	return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . "cc_inquiries WHERE name LIKE 'ZZ Contact%'" );
}

function t_row_by_phone( string $phone ): ?array {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'cc_inquiries WHERE phone = %s ORDER BY id DESC', $phone ), ARRAY_A ) ?: null;
}

function t_audit( string $action, int $id ): array {
	global $wpdb;
	return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'cc_audit_log WHERE action = %s AND entity_id = %d', $action, $id ), ARRAY_A );
}

add_filter( 'wp_die_handler', static fn() => static function ( $message, $title = '', $args = array() ) {
	throw new RuntimeException( 'wp_die:' . ( is_array( $args ) ? (int) ( $args['response'] ?? 0 ) : (int) $args ) );
} );

function t_dies( callable $fn ): bool {
	try {
		$fn();
	} catch ( RuntimeException $e ) {
		return 0 === strpos( $e->getMessage(), 'wp_die:' );
	}
	return false;
}

do_action( 'rest_api_init' );
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
$env_before = getenv( 'CC_RATE_LIMIT_DISABLED' );
$ip_before  = $_SERVER['REMOTE_ADDR'] ?? null;
putenv( 'CC_RATE_LIMIT_DISABLED=1' );
$_SERVER['REMOTE_ADDR'] = '198.51.100.' . random_int( 2, 250 );

$viewer  = t_user( array( 'cc_view_inquiries' ) );
$manager = t_user( array( 'cc_view_inquiries', 'cc_manage_inquiries', 'cc_export_data', 'cc_manage_students' ) );
$nobody  = t_user();

echo "validation matrix\n";
$before = t_rows();
foreach ( array(
	'name too short'   => array( array( 'name' => 'Z' ), 'name' ),
	'name too long'    => array( array( 'name' => str_repeat( 'a', 191 ) ), 'name' ),
	'bad phone'        => array( array( 'phone' => '12345' ), 'phone' ),
	'bad email'        => array( array( 'email' => 'nope' ), 'email' ),
	'bad topic'        => array( array( 'topic' => 'spam' ), 'topic' ),
	'message short'    => array( array( 'message' => 'too short' ), 'message' ),
	'message long'     => array( array( 'message' => str_repeat( 'a', 2001 ) ), 'message' ),
	'course not a number' => array( array( 'course_id' => '1abc' ), 'course_id' ),
) as $label => $case ) {
	$response = t_call( t_valid( $case[0] ) );
	$data     = $response->get_data();
	t_assert( 422 === $response->get_status() && isset( $data['details'][ $case[1] ] ), "$label -> 422 with {$case[1]} error" );
}
$draft_course = t_post( 'cc_course', 'draft' );
$page         = t_post( 'page' );
$course       = t_post( 'cc_course' );
t_assert( 422 === t_call( t_valid( array( 'course_id' => (string) $draft_course ) ) )->get_status(), 'draft course rejected' );
t_assert( 422 === t_call( t_valid( array( 'course_id' => (string) $page ) ) )->get_status(), 'non-course post rejected' );
t_assert( 422 === t_call( t_valid( array( 'course_id' => '999999999' ) ) )->get_status(), 'unknown course rejected' );
t_assert( $before === t_rows(), 'no rows stored by invalid submissions' );

echo "success, storage and headers\n";
$phone    = t_phone();
$response = t_call( t_valid( array( 'phone' => str_replace( '+88', '', $phone ), 'email' => 'zz@example.com', 'course_id' => (string) $course, 'topic' => 'course' ) ) );
t_assert( 200 === $response->get_status() && array( 'ok' => true ) === $response->get_data(), 'valid submission returns {ok:true}' );
t_assert( false !== stripos( (string) ( $response->get_headers()['Cache-Control'] ?? '' ), 'no-store' ), 'response is no-store' );
$row = t_row_by_phone( $phone );
t_assert( null !== $row && 'new' === $row['status'] && (int) $row['course_id'] === $course && 'zz@example.com' === $row['email'], 'row stored as new with phone normalised, email and course' );
t_assert( 1 === preg_match( '/^[0-9a-f]{64}$/', (string) $row['ip_hash'] ) && false === strpos( (string) $row['ip_hash'], '198.51' ), 'ip stored only as a hash' );
$response = t_call( t_valid( array( 'name' => 'ZZ Contact <b>Bold</b>', 'message' => "ZZ <script>alert(1)</script>line one\nline two is here" ) ) );
t_assert( 200 === $response->get_status(), 'markup in text fields still accepted' );
$stored = $wpdb->get_row( "SELECT name, message FROM {$p}cc_inquiries ORDER BY id DESC LIMIT 1", ARRAY_A );
t_assert( false === strpos( $stored['name'] . $stored['message'], '<' ), 'tags stripped by sanitisation on the REST path' );

echo "origin check\n";
$before    = t_rows();
$home_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
$evil      = t_call( t_valid(), array( 'Origin' => 'https://evil.example' ) );
t_assert( 403 === $evil->get_status() && t_rows() === $before, 'foreign Origin -> 403, nothing stored' );
t_assert( 403 === t_call( t_valid(), array( 'Referer' => 'https://evil.example/contact/' ) )->get_status(), 'foreign Referer -> 403' );
t_assert( 403 === t_call( t_valid(), array( 'Origin' => 'null' ) )->get_status(), 'Origin "null" -> 403' );
t_assert( 403 === t_call( t_valid(), array( 'Origin' => 'https://' . $home_host, 'Referer' => 'https://evil.example/' ) )->get_status(), 'good Origin does not excuse a foreign Referer' );
t_assert( $evil->get_data() === t_call( t_valid(), array( 'Origin' => 'https://evil.example', 'Referer' => 'https://other.example/' ) )->get_data(), 'the 403 body is uniform' );
t_assert( 200 === t_call( t_valid(), array( 'Origin' => 'https://' . $home_host, 'Referer' => 'https://' . $home_host . '/contact/' ) )->get_status(), 'own Origin and Referer accepted' );
t_assert( 200 === t_call( t_valid() )->get_status(), 'neither header (curl) accepted' );

echo "honeypot (no oracle)\n";
$before   = t_rows();
$plain    = t_call( t_valid( array( 'phone' => '12345' ) ) );
$trapped  = t_call( t_valid( array( 'phone' => '12345', 'company_site' => 'http://spam.example' ) ) );
t_assert( 422 === $plain->get_status() && $plain->get_status() === $trapped->get_status() && $plain->get_data() === $trapped->get_data(), 'invalid body gets the identical 422 with or without honeypot' );
$response = t_call( t_valid( array( 'company_site' => 'http://spam.example' ) ) );
t_assert( 200 === $response->get_status() && array( 'ok' => true ) === $response->get_data() && t_rows() === $before, 'valid honeypot submission gets success reply and nothing is stored' );

echo "captcha\n";
add_filter( 'cc_verify_captcha', '__return_false' );
$response = t_call( t_valid() );
remove_filter( 'cc_verify_captcha', '__return_false' );
t_assert( 400 === $response->get_status() && t_rows() === $before, 'failed captcha filter -> 400, nothing stored' );
t_assert( 200 === t_call( t_valid() )->get_status(), 'no TURNSTILE_SECRET in a local environment lets the submission through' );
$secret_before = getenv( 'TURNSTILE_SECRET' );
putenv( 'TURNSTILE_SECRET=zz-test-secret' );
$sent     = array();
$mock     = static function ( $reply ) use ( &$sent ) {
	return static function ( $pre, $args, $url ) use ( &$sent, $reply ) {
		if ( false === strpos( $url, 'challenges.cloudflare.com' ) ) {
			return $pre;
		}
		$sent[] = array( 'url' => $url, 'args' => $args );
		return $reply;
	};
};
$cases = array(
	'success true'          => array( array( 'response' => array( 'code' => 200 ), 'body' => '{"success":true}' ), 200 ),
	'success false'         => array( array( 'response' => array( 'code' => 200 ), 'body' => '{"success":false,"error-codes":["invalid-input-response"]}' ), 400 ),
	'http 500'              => array( array( 'response' => array( 'code' => 500 ), 'body' => '{"success":true}' ), 400 ),
	'garbage body'          => array( array( 'response' => array( 'code' => 200 ), 'body' => 'not json' ), 400 ),
	'transport error'       => array( new WP_Error( 'http_request_failed', 'timeout' ), 400 ),
);
foreach ( $cases as $label => $case ) {
	$sent   = array();
	$filter = $mock( $case[0] );
	add_filter( 'pre_http_request', $filter, 10, 3 );
	$response = t_call( t_valid( array( 'cf-turnstile-response' => 'tok-123' ) ) );
	remove_filter( 'pre_http_request', $filter, 10 );
	t_assert( $case[1] === $response->get_status(), "turnstile $label -> {$case[1]}" );
}
$sent   = array();
$filter = $mock( array( 'response' => array( 'code' => 200 ), 'body' => '{"success":true}' ) );
add_filter( 'pre_http_request', $filter, 10, 3 );
t_call( t_valid( array( 'cf-turnstile-response' => 'tok-123' ) ) );
$body = $sent[0]['args']['body'] ?? array();
t_assert( 1 === count( $sent ) && 'https://challenges.cloudflare.com/turnstile/v0/siteverify' === $sent[0]['url'], 'siteverify called once at the Turnstile URL' );
t_assert( 5 === (int) ( $sent[0]['args']['timeout'] ?? 0 ), 'siteverify uses a 5 second timeout' );
t_assert( 'zz-test-secret' === ( $body['secret'] ?? '' ) && 'tok-123' === ( $body['response'] ?? '' ) && CC_Rate_Limiter::client_ip() === ( $body['remoteip'] ?? '' ), 'secret, token and client IP are posted' );
$sent = array();
$bad  = t_call( t_valid() );
t_assert( 400 === $bad->get_status() && 'bot_check_failed' === $bad->get_data()['code'] && array() === $sent, 'missing token fails closed without calling Cloudflare' );
$sent = array();
t_assert( 422 === t_call( t_valid( array( 'phone' => '1', 'cf-turnstile-response' => 'tok' ) ) )->get_status() && array() === $sent, 'invalid body is rejected before the captcha is spent' );
remove_filter( 'pre_http_request', $filter, 10 );
putenv( false === $secret_before ? 'TURNSTILE_SECRET' : 'TURNSTILE_SECRET=' . $secret_before );

echo "rate limits\n";
putenv( 'CC_RATE_LIMIT_DISABLED=0' );
CC_Rate_Limiter::reset( 'contact-global' );
$ip = '203.0.113.' . random_int( 2, 250 );
$_SERVER['REMOTE_ADDR'] = $ip;
$cleanup['buckets'][] = 'contact-ip:' . $ip;
t_call( t_valid( array( 'name' => '' ) ) );
$statuses = array();
for ( $i = 0; $i < 4; ++$i ) {
	$statuses[] = t_call( t_valid() )->get_status();
}
t_assert( array( 200, 200, 200, 429 ) === $statuses, '3 per IP per hour (an invalid request earlier did not use one up)' );
$limited_ip = t_call( t_valid() )->get_data();
$shared     = t_phone();
$cleanup['buckets'][] = 'contact-phone:' . $shared;
$cleanup['buckets'][] = 'contact-global';
$statuses = array();
for ( $i = 0; $i < 21; ++$i ) {
	$_SERVER['REMOTE_ADDR'] = '203.0.114.' . ( 10 + $i );
	$cleanup['buckets'][]   = 'contact-ip:' . $_SERVER['REMOTE_ADDR'];
	$statuses[]             = t_call( t_valid( array( 'phone' => $shared ) ) )->get_status();
}
t_assert( array_fill( 0, 20, 200 ) === array_slice( $statuses, 0, 20 ) && 429 === $statuses[20], '20 per phone per day' );
$limited_phone = t_call( t_valid( array( 'phone' => $shared ) ) )->get_data();
t_assert( $limited_ip === $limited_phone, 'IP and phone limits give the same reply' );

$_SERVER['REMOTE_ADDR'] = '203.0.115.' . random_int( 2, 250 );
$flooder                = $_SERVER['REMOTE_ADDR'];
$victim                 = t_phone();
$cleanup['buckets'][]   = 'contact-ip:' . $flooder;
$cleanup['buckets'][]   = 'contact-phone:' . $victim;
for ( $i = 0; $i < CC_Rest_Contact::IP_LIMIT; ++$i ) {
	CC_Rate_Limiter::hit( 'contact-ip:' . $flooder, HOUR_IN_SECONDS );
}
t_assert( 429 === t_call( t_valid( array( 'phone' => $victim ) ) )->get_status() && 0 === CC_Rate_Limiter::count( 'contact-phone:' . $victim ), 'a request refused on the IP bucket does not count against the phone bucket' );

CC_Rate_Limiter::reset( 'contact-global' );
for ( $i = 0; $i < CC_Rest_Contact::GLOBAL_LIMIT - 1; ++$i ) {
	CC_Rate_Limiter::hit( 'contact-global', DAY_IN_SECONDS );
}
$_SERVER['REMOTE_ADDR'] = '203.0.116.' . random_int( 2, 120 );
$cleanup['buckets'][]   = 'contact-ip:' . $_SERVER['REMOTE_ADDR'];
$last_ok                = t_call( t_valid() );
$_SERVER['REMOTE_ADDR'] = '203.0.116.' . random_int( 130, 250 );
$cleanup['buckets'][]   = 'contact-ip:' . $_SERVER['REMOTE_ADDR'];
$over                   = t_call( t_valid() );
t_assert( 200 === $last_ok->get_status() && 429 === $over->get_status() && $over->get_data() === $limited_ip, 'site-wide ceiling of 200 per day gives the same 429 body' );
CC_Rate_Limiter::reset( 'contact-global' );
putenv( 'CC_RATE_LIMIT_DISABLED=1' );
$_SERVER['REMOTE_ADDR'] = '198.51.100.' . random_int( 2, 250 );

echo "repository: query, counts, status flow\n";
$ids = array();
foreach ( array( array( 'ZZ Contact Alpha', 'Looking for the alpha batch details' ), array( 'ZZ Contact Beta', 'Need fee information please' ), array( 'ZZ Contact Gamma', 'Unique-needle-777 inside the message' ) ) as $n => $c ) {
	$ids[ $n ] = CC_Inquiry_Repository::create( t_valid( array( 'name' => $c[0], 'message' => $c[1], 'phone' => '+880170000' . ( 1000 + $n ) ) ) );
}
t_assert( ! is_wp_error( $ids[0] ) && $ids[0] > 0, 'create returns an id' );
t_assert( is_wp_error( CC_Inquiry_Repository::create( t_valid( array( 'topic' => 'x' ) ) ) ), 'create refuses invalid data' );
$q = CC_Inquiry_Repository::query( array( 'search' => 'Unique-needle-777' ) );
t_assert( 1 === $q['total'] && (int) $q['items'][0]['id'] === $ids[2], 'search finds by message' );
$q = CC_Inquiry_Repository::query( array( 'search' => 'ZZ Contact Beta' ) );
t_assert( 1 === $q['total'], 'search finds by name' );
$q = CC_Inquiry_Repository::query( array( 'search' => '01700001001' ) );
t_assert( 1 === $q['total'] && (int) $q['items'][0]['id'] === $ids[1], 'search finds by phone in local format' );
$q = CC_Inquiry_Repository::query( array( 'search' => "x' OR '1'='1" ) );
t_assert( 0 === $q['total'], 'search is parameterised' );
$today = wp_date( 'Y-m-d' );
t_assert( CC_Inquiry_Repository::query( array( 'search' => 'ZZ Contact Alpha', 'from' => $today, 'to' => $today ) )['total'] >= 1, 'date filter includes today' );
t_assert( 0 === CC_Inquiry_Repository::query( array( 'search' => 'ZZ Contact Alpha', 'to' => '2000-01-01' ) )['total'], 'date filter excludes later rows' );
t_assert( 2 === count( CC_Inquiry_Repository::query( array( 'search' => 'ZZ Contact', 'per_page' => 2 ) )['items'] ), 'per_page limits rows' );

$counts_before = CC_Inquiry_Repository::counts();
t_assert( true === CC_Inquiry_Repository::set_status( $ids[0], 'handled', $manager, 'Called back' ), 'mark handled' );
$row = CC_Inquiry_Repository::find( $ids[0] );
t_assert( 'handled' === $row['status'] && (int) $row['handled_by'] === $manager && ! empty( $row['handled_at'] ) && 'Called back' === $row['staff_note'], 'handled stores actor, time and note' );
t_assert( CC_Inquiry_Repository::counts()['new'] === $counts_before['new'] - 1 && CC_Inquiry_Repository::counts()['handled'] === $counts_before['handled'] + 1, 'counts follow the status' );
t_assert( true === CC_Inquiry_Repository::set_status( $ids[0], 'new', $manager ), 'reopen' );
$row = CC_Inquiry_Repository::find( $ids[0] );
t_assert( 'new' === $row['status'] && null === $row['handled_by'] && null === $row['handled_at'] && 'Called back' === $row['staff_note'], 'reopen clears handler, keeps note' );
t_assert( true === CC_Inquiry_Repository::set_status( $ids[1], 'spam', $manager ) && 'spam' === CC_Inquiry_Repository::find( $ids[1] )['status'], 'mark spam' );
t_assert( is_wp_error( CC_Inquiry_Repository::set_status( $ids[0], 'handled', $manager, str_repeat( 'n', 501 ) ) ), 'note over 500 chars refused' );
t_assert( true === CC_Inquiry_Repository::set_status( $ids[0], 'handled', $manager, str_repeat( 'n', 500 ) ), 'note of 500 chars accepted' );
t_assert( is_wp_error( CC_Inquiry_Repository::set_status( $ids[0], 'bogus', $manager ) ), 'unknown status refused' );
t_assert( is_wp_error( CC_Inquiry_Repository::set_status( 999999999, 'handled', $manager ) ), 'unknown id refused' );
t_assert( CC_Admin_Inquiries::new_count() === CC_Inquiry_Repository::counts()['new'], 'new_count matches the new tab count' );

echo "admin: caps and audit\n";
$r = CC_Admin_Inquiries::change_status( $ids[2], 'handled', $viewer, 'x' );
t_assert( is_wp_error( $r ) && 'cc_forbidden' === $r->get_error_code() && 'new' === CC_Inquiry_Repository::find( $ids[2] )['status'], 'view-only user cannot change status' );
$r = CC_Admin_Inquiries::change_status( $ids[2], 'handled', $nobody );
t_assert( is_wp_error( $r ), 'user without caps cannot change status' );
wp_set_current_user( $manager );
t_assert( true === CC_Admin_Inquiries::change_status( $ids[2], 'handled', $manager, 'Secret note text' ), 'manager marks handled' );
CC_Admin_Inquiries::change_status( $ids[2], 'spam', $manager );
CC_Admin_Inquiries::change_status( $ids[2], 'new', $manager );
$audit_rows = array_merge( t_audit( 'inquiry.handled', $ids[2] ), t_audit( 'inquiry.spam', $ids[2] ), t_audit( 'inquiry.reopen', $ids[2] ) );
t_assert( 3 === count( $audit_rows ), 'handled, spam and reopen are each audited' );
$blob = wp_json_encode( $audit_rows );
t_assert( false === strpos( $blob, 'Unique-needle' ) && false === strpos( $blob, 'Secret note' ) && false === strpos( $blob, '+880170000' ) && false === strpos( $blob, 'ZZ Contact Gamma' ), 'audit rows hold no message, note, phone or name' );

echo "admin: handler refusals\n";
add_filter( 'wp_redirect', static function ( $location ) {
	throw new RuntimeException( 'redirect:' . $location );
}, -100 );
/** Runs handle_status and returns the redirect location, 'die' when refused, '' when it returned silently. */
function t_status_call(): string {
	try {
		CC_Admin_Inquiries::handle_status();
	} catch ( RuntimeException $e ) {
		return 0 === strpos( $e->getMessage(), 'redirect:' ) ? substr( $e->getMessage(), 9 ) : 'die';
	}
	return '';
}
/** The exact request a row-action button in the list table's GET form produces. */
function t_list_request( int $id, string $status ): void {
	$url = CC_Admin_Inquiries::action_url( $id, $status, false );
	parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
	$_GET     = $query;
	$_POST    = array( 'page' => 'cc-inquiries', 'status' => 'new', 's' => '', 'from' => '', 'to' => '', 'filter_action' => '', '_wpnonce' => wp_create_nonce( 'bulk-inquiries' ), '_wp_http_referer' => '/wp-admin/admin.php?page=cc-inquiries' );
	$_REQUEST = array_merge( $_GET, $_POST );
	$_SERVER['REQUEST_METHOD'] = 'POST';
}
$target = CC_Inquiry_Repository::create( t_valid( array( 'name' => 'ZZ Contact Row Action' ) ) );

wp_set_current_user( $manager );
t_list_request( $target, 'handled' );
t_assert( false !== strpos( t_status_call(), 'cc_notice=updated' ), 'list-form POST with a stray _wpnonce and _wp_http_referer succeeds' );
t_assert( 'handled' === CC_Inquiry_Repository::find( $target )['status'], 'list-form POST changed the status' );
t_list_request( $target, 'new' );
t_status_call();
t_assert( 'new' === CC_Inquiry_Repository::find( $target )['status'], 'list-form POST reopens the inquiry' );

t_list_request( $target, 'spam' );
wp_set_current_user( $viewer );
t_assert( 'die' === t_status_call(), 'handle_status refuses without cc_manage_inquiries' );
wp_set_current_user( $manager );
t_list_request( $target, 'spam' );
unset( $_GET['cc_nonce'] );
t_assert( 'die' === t_status_call(), 'handle_status refuses a missing cc_nonce even when the body carries a valid _wpnonce' );
t_list_request( $target, 'spam' );
$_GET['cc_nonce'] = wp_create_nonce( 'cc_inquiry_status_' . ( $target + 1 ) );
t_assert( 'die' === t_status_call(), 'handle_status refuses a cc_nonce minted for another inquiry' );
t_list_request( $target, 'spam' );
$_GET['cc_nonce'] = 'garbage';
t_assert( 'die' === t_status_call(), 'handle_status refuses a wrong cc_nonce' );
t_list_request( $target, 'spam' );
unset( $_GET['cc_nonce'] );
$_GET['_wpnonce'] = wp_create_nonce( 'cc_inquiry_status_' . $target );
t_assert( 'die' === t_status_call(), 'a valid nonce under the old _wpnonce name is no longer accepted' );
t_list_request( $target, 'spam' );
$_SERVER['REQUEST_METHOD'] = 'GET';
t_assert( 'die' === t_status_call(), 'handle_status refuses GET even with a valid cc_nonce' );
t_assert( 'new' === CC_Inquiry_Repository::find( $target )['status'], 'refused requests changed nothing' );

t_list_request( $target, 'handled' );
$_GET['to_detail'] = '1';
t_assert( false !== strpos( t_status_call(), 'inquiry=' . $target ), 'detail-view style request (to_detail) redirects back to the detail' );
$_REQUEST = array();
$_POST    = array();
$_SERVER['REQUEST_METHOD'] = 'POST';
$_GET                      = array();
wp_set_current_user( $viewer );
t_assert( t_dies( array( 'CC_Admin_Inquiries', 'handle_export' ) ), 'export refuses without cc_export_data' );
wp_set_current_user( $manager );
t_assert( t_dies( array( 'CC_Admin_Inquiries', 'handle_export' ) ), 'export refuses a missing nonce' );
wp_set_current_user( $nobody );
t_assert( t_dies( array( 'CC_Admin_Inquiries', 'render' ) ), 'render refuses users without cc_view_inquiries' );
$_GET = array();

echo "admin: rendering and XSS\n";
$xss = CC_Inquiry_Repository::create( t_valid( array( 'name' => 'ZZ Contact <i>x</i>', 'message' => "<script>alert('xss')</script><img src=x onerror=alert(1)> padding text\nsecond line", 'phone' => '+8801700009999', 'email' => 'zz@example.com' ) ) );
wp_set_current_user( $viewer );
$_GET = array( 'page' => 'cc-inquiries', 'inquiry' => (string) $xss );
ob_start();
CC_Admin_Inquiries::render();
$html = ob_get_clean();
t_assert( false !== strpos( $html, '&lt;script&gt;' ) && false === strpos( $html, '<script>alert' ) && false === strpos( $html, '<img src=x' ), 'detail view escapes message markup' );
t_assert( false !== strpos( $html, CC_Phone::mask( '+8801700009999' ) ), 'viewer sees a masked phone' );
t_assert( false === strpos( $html, '+8801700009999' ) && false === strpos( $html, 'cc_inquiry_status' ), 'viewer sees no full phone and no action forms' );
wp_set_current_user( $manager );
ob_start();
CC_Admin_Inquiries::render();
$html = ob_get_clean();
t_assert( false !== strpos( $html, '+8801700009999' ) && false !== strpos( $html, 'cc_inquiry_status' ) && false !== strpos( $html, 'cc_nonce' ), 'manager sees the full phone and nonce-protected actions' );
$_GET = array( 'page' => 'cc-inquiries', 'status' => 'new', 's' => 'padding text' );
ob_start();
CC_Admin_Inquiries::render();
$html = ob_get_clean();
t_assert( false !== strpos( $html, 'New <span class="count">(' ) && false !== strpos( $html, 'Handled' ) && false !== strpos( $html, 'Spam' ), 'list shows status tabs with counts' );
t_assert( false === strpos( $html, '<script>alert' ) && false === strpos( $html, '<img src=x' ) && false === strpos( $html, '<i>x</i>' ), 'list escapes names and messages' );
t_assert( false !== strpos( $html, 'Export CSV' ), 'manager sees the export link' );
wp_set_current_user( $viewer );
ob_start();
CC_Admin_Inquiries::render();
$html = ob_get_clean();
t_assert( false === strpos( $html, 'Export CSV' ) && false === strpos( $html, '+8801700009999' ), 'viewer list has no export link and masked phones' );
$_GET = array();

echo "csv neutralisation\n";
$evil = array( '=HYPERLINK("http://evil")', '+1+1', '-2+3', '@SUM(A1)' );
$eids = array();
foreach ( $evil as $i => $cell ) {
	$eids[] = CC_Inquiry_Repository::create( t_valid( array( 'name' => 'ZZ Contact ' . $cell, 'message' => $cell . ' formula attempt message', 'phone' => '+880170001' . str_pad( (string) $i, 4, '0', STR_PAD_LEFT ) ) ) );
}
$exported = iterator_to_array( CC_Inquiry_Repository::export_rows( array( 'search' => 'formula attempt message' ) ), false );
t_assert( count( $evil ) === count( $exported ), 'export yields the filtered rows' );
$safe = true;
foreach ( $exported as $line ) {
	$safe = $safe && 0 === strpos( CC_Csv::neutralise( $line[8] ), "'" );
}
t_assert( $safe, 'formula-leading message cells are neutralised' );
t_assert( 1 === preg_match( '/^8801700010\d{3}$/', $exported[0][3] ) || 1 === preg_match( '/^88017000\d{5}$/', $exported[0][3] ), 'phone exported as digits with country code' );
t_assert( 10 === count( CC_Inquiry_Repository::export_header() ) && 10 === count( $exported[0] ), 'header and rows have the same width' );

echo "retention purge\n";
$old = gmdate( 'Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS );
$mk  = static function ( string $status, string $created ) use ( $wpdb, $p ): int {
	$wpdb->insert( $p . 'cc_inquiries', array( 'name' => 'ZZ Contact purge', 'phone' => '+8801700000000', 'topic' => 'other', 'message' => 'purge fixture message', 'status' => $status, 'created_at' => $created ) );
	return (int) $wpdb->insert_id;
};
$old_handled = $mk( 'handled', $old );
$old_spam    = $mk( 'spam', $old );
$old_new     = $mk( 'new', $old );
$ancient_new = $mk( 'new', gmdate( 'Y-m-d H:i:s', time() - 800 * DAY_IN_SECONDS ) );
$recent_new  = $mk( 'new', gmdate( 'Y-m-d H:i:s', time() - 700 * DAY_IN_SECONDS ) );
$recent      = $mk( 'handled', gmdate( 'Y-m-d H:i:s', time() - 10 * DAY_IN_SECONDS ) );
$edge        = $mk( 'spam', gmdate( 'Y-m-d H:i:s', time() - 364 * DAY_IN_SECONDS ) );
$deleted     = CC_Inquiry_Repository::purge_older_than();
t_assert( null === CC_Inquiry_Repository::find( $old_handled ) && null === CC_Inquiry_Repository::find( $old_spam ), 'old handled and spam rows are purged' );
t_assert( null !== CC_Inquiry_Repository::find( $old_new ) && null !== CC_Inquiry_Repository::find( $recent_new ), 'new rows younger than 730 days are kept' );
t_assert( null === CC_Inquiry_Repository::find( $ancient_new ), 'new rows older than 730 days are purged' );
t_assert( null !== CC_Inquiry_Repository::find( $recent ) && null !== CC_Inquiry_Repository::find( $edge ), 'rows inside the retention window are kept' );
t_assert( $deleted >= 3, 'purge reports the deleted count' );
t_assert( false !== wp_next_scheduled( CC_Inquiry_Repository::PURGE_HOOK ) && has_action( CC_Inquiry_Repository::PURGE_HOOK ), 'daily purge is scheduled and hooked' );

echo "branches: embed allowlist\n";
$branch = t_post( 'cc_branch' );
$osm    = 'https://www.openstreetmap.org/export/embed.html?bbox=90.38%2C23.74%2C90.42%2C23.78&layer=mapnik';
$google = 'https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3651.9';
foreach ( array( $osm, $google ) as $good ) {
	update_post_meta( $branch, CC_Branches::META_MAP, $good );
	t_assert( $good === get_post_meta( $branch, CC_Branches::META_MAP, true ), 'allowed embed stored: ' . substr( $good, 0, 40 ) );
}
foreach ( array( 'javascript:alert(1)', 'data:text/html,x', 'http://www.google.com/maps/embed?pb=1', 'https://evil.example/maps/embed?pb=1', 'https://www.google.com.evil.com/maps/embed?pb=1', 'https://www.google.com@evil.example/maps/embed?pb=1', 'https://www.openstreetmap.org/?mlat=1' ) as $bad ) {
	update_post_meta( $branch, CC_Branches::META_MAP, $bad );
	t_assert( '' === get_post_meta( $branch, CC_Branches::META_MAP, true ), 'dropped on meta write: ' . substr( $bad, 0, 45 ) );
}
update_post_meta( $branch, CC_Branches::META_ADDRESS, '<b>12 Sample Rd</b>' );
t_assert( '12 Sample Rd' === get_post_meta( $branch, CC_Branches::META_ADDRESS, true ), 'address text is sanitised' );
$html = CC_Branches::map_iframe( $osm, 'Dhaka "Main"' );
t_assert( false !== strpos( $html, 'sandbox="allow-scripts allow-same-origin"' ) && false !== strpos( $html, 'loading="lazy"' ) && false !== strpos( $html, 'referrerpolicy="no-referrer"' ) && false !== strpos( $html, 'title="Map: Dhaka &quot;Main&quot;"' ), 'iframe is sandboxed, lazy, no-referrer and titled (title escaped)' );
t_assert( '' === CC_Branches::map_iframe( 'javascript:alert(1)', 'x' ), 'no iframe for a disallowed URL' );
update_post_meta( $branch, CC_Branches::META_MAP, $osm );
t_assert( in_array( $branch, array_column( CC_Branches::all(), 'id' ), true ), 'published branch is listed by all()' );
wp_update_post( array( 'ID' => $branch, 'post_status' => 'draft' ) );
t_assert( ! in_array( $branch, array_column( CC_Branches::all(), 'id' ), true ), 'draft branch is not listed' );
t_assert( ! get_post_type_object( 'cc_branch' )->public, 'cc_branch stays non-public' );

echo "branches: metabox save\n";
$editor = t_user( array( 'cc_manage_content', 'read' ) );
wp_set_current_user( $editor );
$_POST = array( CC_Branches::NONCE_FIELD => wp_create_nonce( CC_Branches::NONCE_ACTION ), 'cc_branch_address' => 'A', 'cc_branch_phone' => 'P', 'cc_branch_hours' => 'H', 'cc_branch_map_embed_url' => 'javascript:alert(1)' );
CC_Branches::save( $branch );
t_assert( 'A' === get_post_meta( $branch, CC_Branches::META_ADDRESS, true ) && '' === get_post_meta( $branch, CC_Branches::META_MAP, true ), 'save keeps text fields and drops a bad map URL' );
$_POST[ CC_Branches::NONCE_FIELD ] = 'bad';
$_POST['cc_branch_address']        = 'CHANGED';
CC_Branches::save( $branch );
t_assert( 'A' === get_post_meta( $branch, CC_Branches::META_ADDRESS, true ), 'save refuses a bad nonce' );
wp_set_current_user( $nobody );
$_POST[ CC_Branches::NONCE_FIELD ] = wp_create_nonce( CC_Branches::NONCE_ACTION );
CC_Branches::save( $branch );
t_assert( 'A' === get_post_meta( $branch, CC_Branches::META_ADDRESS, true ), 'save refuses a user who cannot edit the branch' );
$_POST = array();

echo "branches: capability regression\n";
$staff = t_user( array(), 'cc_staff' );
t_assert( user_can( $staff, 'cc_manage_content' ), 'cc_staff still has cc_manage_content' );
t_assert( user_can( $staff, 'edit_post', $branch ), 'staff can edit a cc_branch post' );
t_assert( ! user_can( $nobody, 'edit_post', $branch ), 'a user without cc_manage_content cannot edit a cc_branch post' );
$admin = t_user( array(), 'administrator' );
t_assert( user_can( $admin, 'cc_manage_content' ) && user_can( $admin, 'edit_post', $branch ), 'administrator keeps cc_manage_content and can edit a branch' );

echo "seed idempotency\n";
CC_Branches::seed();
$count_a = count( get_posts( array( 'post_type' => 'cc_branch', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids' ) ) );
CC_Branches::seed();
$count_b = count( get_posts( array( 'post_type' => 'cc_branch', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids' ) ) );
t_assert( $count_a === $count_b, 'seeding twice creates no duplicates' );
foreach ( array_keys( CC_Branches::SAMPLES ) as $slug ) {
	$found = get_posts( array( 'post_type' => 'cc_branch', 'name' => $slug, 'post_status' => 'any', 'posts_per_page' => 1 ) );
	t_assert( ! empty( $found ) && '' !== CC_Branches::sanitize_embed_url( get_post_meta( $found[0]->ID, CC_Branches::META_MAP, true ) ), "sample branch $slug exists with a valid map" );
}

echo "seo graph\n";
$graph = CC_Branches::filter_seo_graph( array( array( '@type' => 'Course' ) ) );
t_assert( array( array( '@type' => 'Course' ) ) === $graph, 'graph untouched outside home and contact' );

foreach ( $cleanup['buckets'] as $bucket ) {
	CC_Rate_Limiter::reset( $bucket );
}
$wpdb->query( "DELETE FROM {$p}cc_inquiries WHERE name LIKE 'ZZ Contact%'" );
$wpdb->query( "DELETE FROM {$p}cc_inquiries WHERE phone IN ('+8801700009999') AND name LIKE 'ZZ%'" );
$all_ids = array_merge( $ids, array( $xss, $target ), $eids );
if ( $all_ids ) {
	$wpdb->query( "DELETE FROM {$p}cc_audit_log WHERE entity_type = 'inquiry' AND entity_id IN (" . implode( ',', array_map( 'intval', $all_ids ) ) . ')' );
}
foreach ( $cleanup['posts'] as $post_id ) {
	wp_delete_post( $post_id, true );
}
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ( $cleanup['users'] as $user_id ) {
	wp_delete_user( $user_id );
}
putenv( false === $env_before ? 'CC_RATE_LIMIT_DISABLED' : 'CC_RATE_LIMIT_DISABLED=' . $env_before );
if ( null !== $ip_before ) {
	$_SERVER['REMOTE_ADDR'] = $ip_before;
}

echo "\n$checks checks, $failures failures\n";
exit( $failures > 0 ? 1 : 0 );
