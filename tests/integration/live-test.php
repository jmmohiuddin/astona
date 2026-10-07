<?php
/**
 * Integration tests for CC_Live_Repository, CC_Rest_Live and CC_Admin_Live. Run inside the wpcli container:
 *   docker compose run --rm -T wpcli eval-file /tests/integration/live-test.php
 * Creates throwaway batches, students, staff and live classes and removes everything it created.
 */
global $wpdb, $failures, $checks, $tag, $url, $now;
$p        = $wpdb->prefix;
$failures = 0;
$checks   = 0;

function t_assert( bool $cond, string $label ): void {
	global $failures, $checks;
	++$checks;
	if ( $cond ) {
		echo "  ok   $label\n";
		return;
	}
	++$failures;
	echo "  FAIL $label\n";
}

foreach ( array( 'CC_Live_Repository', 'CC_Rest_Live', 'CC_Admin_Live', 'CC_Enrollment_Repository', 'CC_Rate_Limiter', 'CC_Audit', 'CC_Crypto' ) as $class ) {
	if ( ! class_exists( $class ) ) {
		echo "SKIP: $class not loaded; cannot run live integration tests.\n";
		exit( 1 );
	}
}

do_action( 'rest_api_init' );

$added_role = false;
if ( ! get_role( 'cc_student' ) ) {
	add_role( 'cc_student', 'Student', array( 'read' => true ) );
	$added_role = true;
}

$tag  = (string) random_int( 10000000, 99999999 );
$now  = time();
$utc  = static fn( int $ts ): string => gmdate( 'Y-m-d H:i:s', $ts );
$url  = 'https://meet.google.com/zzt-' . strtolower( $tag ) . '-abc';
$url2 = 'https://meet.google.com/new-' . strtolower( $tag ) . '-xyz';

function t_student( string $suffix ): int {
	global $tag;
	$login = '88015' . $suffix . substr( $tag, 0, 6 );
	return (int) wp_insert_user( array( 'user_login' => $login, 'user_pass' => 'Temp-Pass-12345', 'user_email' => "$login@students.invalid", 'role' => 'cc_student' ) );
}

function t_batch( string $name ): int {
	global $wpdb;
	$wpdb->insert( $wpdb->prefix . 'cc_batches', array( 'course_id' => 0, 'name' => $name, 'start_date' => gmdate( 'Y-m-d' ), 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ) );
	return (int) $wpdb->insert_id;
}

function t_enroll( int $user_id, int $batch_id, string $status = 'active', ?string $expires = null ): void {
	global $wpdb;
	$wpdb->insert( $wpdb->prefix . 'cc_enrollments', array( 'user_id' => $user_id, 'batch_id' => $batch_id, 'application_id' => random_int( 900000000, 2000000000 ), 'status' => $status, 'enrolled_at' => gmdate( 'Y-m-d H:i:s' ), 'expires_at' => $expires ) );
}

function t_join( int $live_id ): WP_REST_Response {
	return rest_do_request( new WP_REST_Request( 'POST', '/cc/v1/live-classes/' . $live_id . '/join' ) );
}

function t_live( array $over = array() ) {
	global $url, $now;
	return CC_Live_Repository::create(
		array_merge(
			array( 'batch_id' => 0, 'lesson_id' => 0, 'title' => 'ZZ live', 'starts_at' => gmdate( 'Y-m-d H:i:s', $now - 300 ), 'ends_at' => gmdate( 'Y-m-d H:i:s', $now + 3300 ), 'provider' => 'meet', 'meeting_url' => $url ),
			$over
		),
		1
	);
}

$batch_a = t_batch( 'ZZ-LIVE A' );
$batch_b = t_batch( 'ZZ-LIVE B' );
$enrolled    = t_student( '1' );
$outsider    = t_student( '2' );
$deactivated = t_student( '3' );
$other_batch = t_student( '4' );
$expired     = t_student( '5' );
t_enroll( $enrolled, $batch_a );
t_enroll( $deactivated, $batch_a, 'deactivated' );
t_enroll( $other_batch, $batch_b );
t_enroll( $expired, $batch_a, 'active', $utc( $now - 60 ) );

$live_active   = t_live( array( 'batch_id' => $batch_a ) );
$live_early    = t_live( array( 'batch_id' => $batch_a, 'title' => 'ZZ early', 'starts_at' => $utc( $now + 1200 ), 'ends_at' => $utc( $now + 4800 ) ) );
$live_future   = t_live( array( 'batch_id' => $batch_a, 'title' => 'ZZ future', 'starts_at' => $utc( $now + 7200 ), 'ends_at' => $utc( $now + 10800 ) ) );
$live_ended    = t_live( array( 'batch_id' => $batch_a, 'title' => 'ZZ ended', 'starts_at' => $utc( $now - 7200 ), 'ends_at' => $utc( $now - 3600 ) ) );
$live_ids      = array( $live_active, $live_early, $live_future, $live_ended );
t_assert( 4 === count( array_filter( $live_ids, 'is_int' ) ), 'fixture live classes created' );

echo "Encrypted at rest\n";
$raw = (string) $wpdb->get_var( $wpdb->prepare( "SELECT meeting_url_enc FROM {$p}cc_live_classes WHERE id = %d", $live_active ) );
t_assert( '' !== $raw && $raw !== $url && false === strpos( $raw, $url ) && false === strpos( $raw, 'meet.google.com' ), 'raw column is not the URL and does not contain it' );
t_assert( 0 === strpos( $raw, CC_Crypto::PREFIX ), 'raw column is a CC_Crypto blob' );
t_assert( $url === CC_Live_Repository::meeting_url( $live_active ), 'meeting_url() decrypts' );
t_assert( '' === CC_Live_Repository::meeting_url( 999999999 ), 'meeting_url() of unknown id is empty' );
$row = CC_Live_Repository::find( $live_active );
t_assert( ! array_key_exists( 'meeting_url_enc', $row ) && false === strpos( wp_json_encode( $row ), 'meet.google.com' ), 'find() never returns the link' );
t_assert( false === strpos( wp_json_encode( CC_Live_Repository::for_batch( $batch_a ) ), 'meet.google.com' ), 'for_batch() never returns the link' );

echo "Join matrix\n";
wp_set_current_user( 0 );
t_assert( in_array( t_join( $live_active )->get_status(), array( 401, 403 ), true ), 'anonymous is refused' );

wp_set_current_user( $enrolled );
$res = t_join( $live_active );
t_assert( 200 === $res->get_status() && array( 'url' => $url ) === $res->get_data(), 'enrolled student inside window gets the url' );
t_assert( false !== stripos( (string) ( $res->get_headers()['Cache-Control'] ?? '' ), 'no-store' ) && false !== stripos( (string) $res->get_headers()['Cache-Control'], 'private' ), 'success response is private, no-store' );

$denials = array(
	'outsider (not enrolled)'    => array( $outsider, $live_active, 403, 'not_enrolled' ),
	'deactivated enrollment'     => array( $deactivated, $live_active, 403, 'not_enrolled' ),
	'expired enrollment'         => array( $expired, $live_active, 403, 'not_enrolled' ),
	'enrolled in another batch'  => array( $other_batch, $live_active, 403, 'not_enrolled' ),
	'enrolled, class too early'  => array( $enrolled, $live_early, 409, 'outside_window' ),
	'enrolled, class far ahead'  => array( $enrolled, $live_future, 409, 'outside_window' ),
	'enrolled, class ended'      => array( $enrolled, $live_ended, 409, 'outside_window' ),
	'unknown id'                 => array( $enrolled, 999999999, 404, 'not_found' ),
);
foreach ( $denials as $label => list( $uid, $lid, $status, $code ) ) {
	wp_set_current_user( $uid );
	CC_Rate_Limiter::reset( 'live_join|' . $uid );
	$res  = t_join( $lid );
	$body = wp_json_encode( $res->get_data() );
	t_assert( $status === $res->get_status() && $code === ( $res->get_data()['code'] ?? '' ), "$label -> $status $code" );
	t_assert( false === strpos( $body, 'meet.google.com' ) && false === strpos( $body, $tag ) && ! isset( $res->get_data()['url'] ), "$label body has no url" );
	t_assert( false !== stripos( (string) ( $res->get_headers()['Cache-Control'] ?? '' ), 'no-store' ), "$label is no-store" );
}

echo "Window opens 15 minutes early\n";
wp_set_current_user( $enrolled );
$wpdb->update( "{$p}cc_live_classes", array( 'starts_at' => $utc( $now + 899 ), 'ends_at' => $utc( $now + 4500 ) ), array( 'id' => $live_early ) );
t_assert( 200 === t_join( $live_early )->get_status(), 'join allowed 899s before start' );
$wpdb->update( "{$p}cc_live_classes", array( 'starts_at' => $utc( $now + 905 ) ), array( 'id' => $live_early ) );
t_assert( 409 === t_join( $live_early )->get_status(), 'join refused 905s before start' );

echo "Join log\n";
$log = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$p}cc_join_log WHERE live_class_id = %d", $live_active ), ARRAY_A );
t_assert( 1 === count( $log ) && (int) $log[0]['user_id'] === $enrolled, 'only the successful join was logged' );
t_assert( 1 === preg_match( '/^[a-f0-9]{64}$/', (string) $log[0]['ip_hash'] ) && $log[0]['ip_hash'] === hash_hmac( 'sha256', CC_Rate_Limiter::client_ip(), wp_salt( 'auth' ) ), 'ip is an hmac like CC_Audit' );
$dump = wp_json_encode( $wpdb->get_results( "SELECT * FROM {$p}cc_join_log", ARRAY_A ) ) . wp_json_encode( $wpdb->get_results( "SELECT * FROM {$p}cc_audit_log ORDER BY id DESC LIMIT 200", ARRAY_A ) );
t_assert( false === strpos( $dump, $url ) && false === strpos( $dump, $url2 ) && false === strpos( $dump, 'meet.google.com/zzt' ), 'url is in neither the join log nor the audit log' );

echo "Rate limit 10/min/user\n";
putenv( 'CC_RATE_LIMIT_DISABLED=0' );
CC_Rate_Limiter::reset( 'live_join|' . $enrolled );
$codes = array();
for ( $i = 0; $i < 11; $i++ ) {
	$codes[] = t_join( $live_active )->get_status();
}
t_assert( 10 === count( array_filter( array_slice( $codes, 0, 10 ), static fn( $c ) => 200 === $c ) ) && 429 === $codes[10], '11th join in a minute gets 429' );
$limited = t_join( $live_active );
t_assert( ! isset( $limited->get_data()['url'] ) && 429 === $limited->get_status(), 'rate limited body has no url' );
wp_set_current_user( $other_batch );
CC_Rate_Limiter::reset( 'live_join|' . $other_batch );
t_assert( 403 === t_join( $live_active )->get_status(), 'another user is not affected by the limit (gets its normal answer)' );
CC_Rate_Limiter::reset( 'live_join|' . $enrolled );
CC_Rate_Limiter::reset( 'live_join|' . $other_batch );
putenv( 'CC_RATE_LIMIT_DISABLED=1' );

echo "URL update takes effect on the next join\n";
wp_set_current_user( $enrolled );
t_assert( true === CC_Live_Repository::update( $live_active, array( 'meeting_url' => $url2 ) ), 'update with a new url succeeds' );
t_assert( array( 'url' => $url2 ) === t_join( $live_active )->get_data(), 'next join returns the latest url' );
t_assert( true === CC_Live_Repository::update( $live_active, array( 'title' => 'ZZ renamed', 'meeting_url' => '' ) ), 'update with blank url keeps the stored link' );
t_assert( array( 'url' => $url2 ) === t_join( $live_active )->get_data(), 'blank url kept the link' );

echo "upcoming_for_user\n";
$up = CC_Live_Repository::upcoming_for_user( $enrolled, 10 );
t_assert( array( $live_active, $live_early, $live_future ) === array_map( static fn( $r ) => (int) $r['id'], $up ), 'enrolled student sees non-ended classes ordered by start' );
t_assert( array( 'active', 'inactive', 'inactive' ) === array_column( $up, 'state' ), 'each row carries its state' );
t_assert( false === strpos( wp_json_encode( $up ), 'meet.google.com' ) && ! array_key_exists( 'meeting_url_enc', $up[0] ), 'rows never include the url' );
t_assert( 1 === count( CC_Live_Repository::upcoming_for_user( $enrolled, 1 ) ), 'limit applies' );
t_assert( array() === CC_Live_Repository::upcoming_for_user( $deactivated ) && array() === CC_Live_Repository::upcoming_for_user( $expired ) && array() === CC_Live_Repository::upcoming_for_user( $outsider ), 'deactivated, expired and unenrolled users see nothing' );
t_assert( array() === CC_Live_Repository::upcoming_for_user( $other_batch ) || ! in_array( $live_active, array_column( CC_Live_Repository::upcoming_for_user( $other_batch ), 'id' ), true ), "other batch's classes are not listed" );

echo "Validation\n";
$base = array( 'batch_id' => $batch_a, 'title' => 'ZZ v', 'starts_at' => $utc( $now + 100 ), 'ends_at' => $utc( $now + 4000 ), 'provider' => 'meet', 'meeting_url' => $url );
$count_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}cc_live_classes" );
foreach ( array(
	'http url'            => array( 'meeting_url' => 'http://meet.google.com/abc-defg-hij' ),
	'javascript url'      => array( 'meeting_url' => 'javascript:alert(1)' ),
	'wrong provider host' => array( 'provider' => 'zoom' ),
	'ends equal starts'   => array( 'ends_at' => $utc( $now + 100 ) ),
	'ends before starts'  => array( 'ends_at' => $utc( $now + 50 ) ),
	'unknown batch'       => array( 'batch_id' => 999999999 ),
	'lesson of no batch'  => array( 'lesson_id' => 999999999 ),
) as $label => $over ) {
	t_assert( is_wp_error( CC_Live_Repository::create( array_merge( $base, $over ), 1 ) ), "create rejects $label" );
}
t_assert( $count_before === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}cc_live_classes" ), 'rejected creates stored nothing' );
t_assert( is_wp_error( CC_Live_Repository::update( $live_active, array( 'meeting_url' => 'http://meet.google.com/x' ) ) ), 'update rejects http' );
t_assert( is_wp_error( CC_Live_Repository::update( $live_active, array( 'provider' => 'zoom' ) ) ), 'changing provider re-validates the stored link' );
t_assert( array( 'url' => $url2 ) === t_join( $live_active )->get_data(), 'rejected updates left the stored link untouched' );
t_assert( is_wp_error( CC_Live_Repository::update( 999999999, array( 'title' => 'x' ) ) ), 'update of unknown id errors' );

echo "Admin handlers\n";
$staff    = (int) wp_insert_user( array( 'user_login' => "zzlivestaff$tag", 'user_pass' => 'Temp-Pass-12345', 'user_email' => "zzlivestaff$tag@example.invalid", 'role' => 'subscriber' ) );
$no_cap   = (int) wp_insert_user( array( 'user_login' => "zzlivenocap$tag", 'user_pass' => 'Temp-Pass-12345', 'user_email' => "zzlivenocap$tag@example.invalid", 'role' => 'subscriber' ) );
get_userdata( $staff )->add_cap( CC_Admin_Live::CAP );
clean_user_cache( $staff );
$local = static fn( int $ts ): string => wp_date( CC_Admin_Live::INPUT_TIME, $ts );
$form  = array( 'batch_id' => $batch_a, 'title' => 'ZZ admin', 'starts_at' => $local( $now + 86400 ), 'ends_at' => $local( $now + 90000 ), 'provider' => 'zoom', 'meeting_url' => 'https://us02web.zoom.us/j/' . $tag . '?pwd=SECRETPWD' );

t_assert( is_wp_error( CC_Admin_Live::save( $form, $no_cap ) ) && 'cc_forbidden' === CC_Admin_Live::save( $form, $no_cap )->get_error_code(), 'save refuses an actor without the capability' );
t_assert( is_wp_error( CC_Admin_Live::remove( $live_ended, $no_cap ) ), 'remove refuses an actor without the capability' );
t_assert( null !== CC_Live_Repository::find( $live_ended ), 'refused remove deleted nothing' );
foreach ( array(
	'http'            => array( 'meeting_url' => 'http://us02web.zoom.us/j/1' ),
	'javascript:'     => array( 'meeting_url' => 'javascript:alert(1)' ),
	'wrong host'      => array( 'meeting_url' => 'https://meet.google.com/abc-defg-hij' ),
	'ends <= starts'  => array( 'ends_at' => $local( $now + 86400 ) ),
) as $label => $over ) {
	t_assert( is_wp_error( CC_Admin_Live::save( array_merge( $form, $over ), $staff ) ), "save rejects $label" );
}

$new_id = CC_Admin_Live::save( $form, $staff );
t_assert( is_int( $new_id ) && $new_id > 0, 'save creates a class for an actor with the capability' );
$live_ids[] = $new_id;
$saved = CC_Live_Repository::find( (int) $new_id );
t_assert( $local( $now + 86400 ) === CC_Admin_Live::utc_to_local( $saved['starts_at'] ) && $utc( strtotime( $saved['starts_at'] . ' UTC' ) ) === $saved['starts_at'], 'times round-trip through the site timezone' );
$audit = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}cc_audit_log WHERE entity_type = 'live_class' AND entity_id = %d AND action = 'live.create'", $new_id ), ARRAY_A );
t_assert( null !== $audit, 'creation is audited' );

$changed = CC_Admin_Live::save( array_merge( $form, array( 'id' => $new_id, 'meeting_url' => 'https://us02web.zoom.us/j/' . $tag . '9?pwd=OTHERSECRET' ) ), $staff );
t_assert( $new_id === $changed, 'save updates an existing class' );
$url_audit = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}cc_audit_log WHERE entity_type = 'live_class' AND entity_id = %d AND action = 'live.meeting_url_changed'", $new_id ) );
t_assert( 1 === $url_audit, 'a changed link is audited as live.meeting_url_changed' );
CC_Admin_Live::save( array_merge( $form, array( 'id' => $new_id, 'meeting_url' => '' ) ), $staff );
t_assert( 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}cc_audit_log WHERE entity_type = 'live_class' AND entity_id = %d AND action = 'live.meeting_url_changed'", $new_id ) ), 'a blank link field is not audited as a change' );
$audit_dump = wp_json_encode( $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$p}cc_audit_log WHERE entity_type = 'live_class' AND entity_id = %d", $new_id ), ARRAY_A ) );
t_assert( false === strpos( $audit_dump, 'SECRET' ) && false === strpos( $audit_dump, 'zoom.us' ), 'audit rows never contain the link' );

// admin-post handlers: refuse without capability, and without a valid nonce.
add_filter( 'wp_die_handler', static fn() => static function ( $message, $title = '', $args = array() ) {
	throw new RuntimeException( 'wp_die:' . ( is_array( $args ) ? ( $args['response'] ?? '' ) : $args ) );
} );
$attempt = static function ( callable $fn ): string {
	try {
		$fn();
		return 'returned';
	} catch ( RuntimeException $e ) {
		return $e->getMessage();
	}
};
$_POST = $form;
wp_set_current_user( $no_cap );
$_REQUEST = array( '_wpnonce' => wp_create_nonce( CC_Admin_Live::ACTION_SAVE ) );
t_assert( 'wp_die:403' === $attempt( array( 'CC_Admin_Live', 'handle_save' ) ), 'handle_save refuses without the capability even with a valid nonce' );
$_REQUEST = array( '_wpnonce' => wp_create_nonce( CC_Admin_Live::ACTION_DEL ) );
$_POST    = array( 'id' => $live_ended );
t_assert( 'wp_die:403' === $attempt( array( 'CC_Admin_Live', 'handle_delete' ) ), 'handle_delete refuses without the capability' );
wp_set_current_user( $staff );
$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}cc_live_classes" );
$_POST    = $form;
$_REQUEST = array();
t_assert( 'wp_die:403' === $attempt( array( 'CC_Admin_Live', 'handle_save' ) ), 'handle_save refuses without a nonce' );
$_REQUEST = array( '_wpnonce' => 'badnonce' );
t_assert( 'wp_die:403' === $attempt( array( 'CC_Admin_Live', 'handle_save' ) ), 'handle_save refuses a bad nonce' );
$_POST    = array( 'id' => $live_ended );
$_REQUEST = array();
t_assert( 'wp_die:403' === $attempt( array( 'CC_Admin_Live', 'handle_delete' ) ), 'handle_delete refuses without a nonce' );
t_assert( $before === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}cc_live_classes" ) && null !== CC_Live_Repository::find( $live_ended ), 'refused handlers changed nothing' );
$_POST = array();
$_REQUEST = array();

t_assert( true === CC_Admin_Live::remove( $new_id, $staff ) && null === CC_Live_Repository::find( (int) $new_id ), 'remove deletes for an actor with the capability' );
t_assert( 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}cc_audit_log WHERE entity_type = 'live_class' AND entity_id = %d AND action = 'live.delete'", $new_id ) ), 'deletion is audited' );

// Cleanup.
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_set_current_user( 0 );
$ids_sql = implode( ',', array_map( 'intval', $live_ids ) );
$wpdb->query( "DELETE FROM {$p}cc_join_log WHERE live_class_id IN ($ids_sql)" );
$wpdb->query( "DELETE FROM {$p}cc_audit_log WHERE entity_type = 'live_class' AND entity_id IN ($ids_sql)" );
$wpdb->query( "DELETE FROM {$p}cc_live_classes WHERE id IN ($ids_sql)" );
foreach ( array( $enrolled, $outsider, $deactivated, $other_batch, $expired, $staff, $no_cap ) as $uid ) {
	$wpdb->delete( "{$p}cc_enrollments", array( 'user_id' => $uid ) );
	wp_delete_user( $uid );
}
$wpdb->delete( "{$p}cc_batches", array( 'id' => $batch_a ) );
$wpdb->delete( "{$p}cc_batches", array( 'id' => $batch_b ) );
if ( $added_role ) {
	remove_role( 'cc_student' );
}

echo "\n$checks checks, $failures failures\n";
exit( $failures ? 1 : 0 );
