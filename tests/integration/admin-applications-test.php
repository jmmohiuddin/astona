<?php
/**
 * Integration tests for CC_Admin_Applications. Run inside the wpcli container:
 *   docker compose run --rm -T wpcli eval-file /tests/integration/admin-applications-test.php
 * Inserts fixture rows directly and removes them afterwards.
 */
global $wpdb, $failures, $checks, $cleanup;
$p        = $wpdb->prefix;
$failures = 0;
$checks   = 0;
$cleanup  = array( 'batches' => array(), 'apps' => array(), 'users' => array(), 'files' => array() );

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

foreach ( array( 'CC_Admin_Applications', 'CC_Application_Repository', 'CC_Crypto', 'CC_Photo_Store' ) as $required ) {
	if ( ! class_exists( $required ) ) {
		echo "FAIL missing class $required\n";
		exit( 1 );
	}
}
$have_audit = class_exists( 'CC_Audit' );
$have_csv   = class_exists( 'CC_Csv' );
echo $have_audit ? '' : "  note CC_Audit not present yet: audit assertions skipped\n";
echo $have_csv ? '' : "  note CC_Csv not present yet\n";

function t_batch( string $tag ): int {
	global $wpdb, $cleanup;
	$now = gmdate( 'Y-m-d H:i:s' );
	$wpdb->insert( $wpdb->prefix . 'cc_batches', array(
		'course_id' => 0, 'name' => "ZZ-APPS $tag", 'capacity' => 10, 'seats_taken' => 0, 'price' => 1000,
		'start_date' => '2030-01-01', 'status' => 'open', 'application_open' => 1, 'created_at' => $now, 'updated_at' => $now,
	) );
	$cleanup['batches'][] = (int) $wpdb->insert_id;
	return (int) $wpdb->insert_id;
}

/** @return array{app:int,invoice:int,payment:int,ref:string} */
function t_app( int $batch, string $name, string $status, string $created, ?string $payment_status = null, string $phone = '+8801700000000' ): array {
	global $wpdb, $cleanup;
	$p   = $wpdb->prefix;
	$tag = strtoupper( substr( bin2hex( random_bytes( 8 ) ), 0, 16 ) );
	$ref = substr( 'ZZAP' . $tag . '000000000000', 0, 26 );
	$wpdb->insert( "{$p}cc_applications", array(
		'public_ref' => $ref, 'idempotency_key' => wp_generate_uuid4(), 'batch_id' => $batch, 'student_phone' => $phone,
		'full_name' => $name, 'gender' => 'o', 'dob' => '2008-01-01', 'id_doc_type' => 'nid',
		'id_doc_enc' => CC_Crypto::encrypt( '1990123456789' ), 'guardian_name' => 'G', 'guardian_phone' => '+8801700000001',
		'institution' => 'I', 'class_level' => '10', 'consent_at' => $created, 'status' => $status,
		'created_at' => $created, 'updated_at' => $created,
	) );
	$app = (int) $wpdb->insert_id;
	$cleanup['apps'][] = $app;
	$wpdb->insert( "{$p}cc_invoices", array( 'application_id' => $app, 'amount' => 1000, 'number' => 'ZZ-' . $tag, 'created_at' => $created ) );
	$invoice = (int) $wpdb->insert_id;
	$payment = 0;
	if ( $payment_status ) {
		$wpdb->insert( "{$p}cc_payments", array(
			'invoice_id' => $invoice, 'gateway' => 'fake', 'method' => 'fake', 'gateway_payment_id' => 'zz' . $tag, 'amount' => 1000,
			'status' => $payment_status, 'created_at' => $created, 'updated_at' => $created,
		) );
		$payment = (int) $wpdb->insert_id;
	}
	return array( 'app' => $app, 'invoice' => $invoice, 'payment' => $payment, 'ref' => $ref, 'tag' => $tag );
}

function t_row( int $app ): array {
	global $wpdb;
	return (array) $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}cc_applications WHERE id = %d", $app ), ARRAY_A );
}

function t_user( string $login, array $caps ): int {
	global $cleanup;
	$id = wp_insert_user( array( 'user_login' => $login, 'user_pass' => wp_generate_password(), 'user_email' => $login . '@example.test', 'role' => 'subscriber' ) );
	$cleanup['users'][] = (int) $id;
	foreach ( $caps as $cap ) {
		( new WP_User( $id ) )->add_cap( $cap );
	}
	return (int) $id;
}

/** Runs a handler and reports whether it was stopped by wp_die. */
function t_dies( callable $fn ): bool {
	$filter = static fn() => static function () {
		throw new RuntimeException( 'wp_die' );
	};
	add_filter( 'wp_die_handler', $filter, 999 );
	try {
		$fn();
		return false;
	} catch ( RuntimeException $e ) {
		return 'wp_die' === $e->getMessage();
	} finally {
		remove_filter( 'wp_die_handler', $filter, 999 );
	}
}

$tag   = substr( bin2hex( random_bytes( 4 ) ), 0, 8 );
$batch = t_batch( $tag );
$other = t_batch( $tag . 'b' );

$a1 = t_app( $batch, "Zed Alpha $tag", 'pending', '2030-03-01 10:00:00', 'initiated', '+8801711111111' );
$a2 = t_app( $batch, "Zed Beta $tag", 'approved', '2030-03-05 10:00:00', 'completed', '+8801722222222' );
$a3 = t_app( $other, "Zed Gamma $tag", 'rejected', '2030-03-10 10:00:00' );
$a4 = t_app( $batch, "Zed Delta $tag", 'pending', '2030-03-12 10:00:00', 'completed', '+8801733333333' );

echo "query()\n";
$q = CC_Admin_Applications::query( array( 'search' => "Zed" . ' ' . $tag ) );
t_assert( 0 === $q['total'], 'multi-word search with no literal match returns nothing' );
$q = CC_Admin_Applications::query( array( 'search' => $tag, 'batch_id' => $batch ) );
t_assert( 3 === $q['total'] && 3 === count( $q['items'] ), 'search by name fragment plus batch filter' );
$q = CC_Admin_Applications::query( array( 'search' => $tag ) );
t_assert( 4 === $q['total'], 'search without batch filter finds all four' );
$q = CC_Admin_Applications::query( array( 'search' => $tag, 'status' => 'pending' ) );
t_assert( 2 === $q['total'], 'status filter pending' );
$q = CC_Admin_Applications::query( array( 'search' => $a2['ref'] ) );
t_assert( 1 === $q['total'] && (int) $q['items'][0]['id'] === $a2['app'], 'search by public ref' );
$q = CC_Admin_Applications::query( array( 'search' => 'ZZ-' . $a3['tag'] ) );
t_assert( 1 === $q['total'] && (int) $q['items'][0]['id'] === $a3['app'], 'search by invoice number' );
$q = CC_Admin_Applications::query( array( 'search' => '01722222222', 'batch_id' => $batch ) );
t_assert( 1 === $q['total'] && (int) $q['items'][0]['id'] === $a2['app'], 'search by local-format phone' );
$q = CC_Admin_Applications::query( array( 'search' => $tag, 'date_from' => '2030-03-05', 'date_to' => '2030-03-10' ) );
t_assert( 2 === $q['total'], 'date range is inclusive of the end day' );
$q = CC_Admin_Applications::query( array( 'search' => $tag, 'date_from' => 'not-a-date' ) );
t_assert( 4 === $q['total'], 'malformed date is ignored' );
$q = CC_Admin_Applications::query( array( 'search' => $tag, 'orderby' => 'created_at', 'order' => 'asc' ) );
t_assert( (int) $q['items'][0]['id'] === $a1['app'], 'sort created_at ascending' );
$q = CC_Admin_Applications::query( array( 'search' => $tag, 'orderby' => 'full_name; DROP TABLE x', 'order' => 'sideways' ) );
t_assert( 4 === $q['total'] && (int) $q['items'][0]['id'] === $a4['app'], 'unknown orderby/order fall back to created_at DESC' );
$q = CC_Admin_Applications::query( array( 'search' => $tag, 'per_page' => 2, 'paged' => 2, 'orderby' => 'created_at', 'order' => 'desc' ) );
t_assert( 4 === $q['total'] && 2 === count( $q['items'] ) && (int) $q['items'][0]['id'] === $a2['app'], 'paging returns page 2 with total intact' );
t_assert( array_key_exists( 'payment_status', $q['items'][0] ) && ! array_key_exists( 'id_doc_enc', $q['items'][0] ), 'rows carry payment status and never the encrypted ID' );
$q = CC_Admin_Applications::query( array( 'search' => "%' OR 1=1 -- ", 'batch_id' => $batch ) );
t_assert( 0 === $q['total'], 'SQL metacharacters in search are inert' );
$counts = CC_Admin_Applications::status_counts();
t_assert( $counts['pending'] >= 2 && $counts['all'] >= 4, 'status counts include fixtures' );

echo "reject()\n";
$actor = t_user( 'zzapps-actor-' . $tag, array() );
$r     = CC_Admin_Applications::reject( $a1['app'], '  Incomplete documents  ', $actor );
$row   = t_row( $a1['app'] );
t_assert( true === $r, 'pending application rejects' );
t_assert( 'rejected' === $row['status'] && 'Incomplete documents' === $row['rejection_reason'] && (string) $actor === (string) $row['reviewed_by'], 'status, trimmed reason and reviewer recorded' );
t_assert( 'initiated' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$p}cc_payments WHERE id = %d", $a1['payment'] ) ), 'initiated payment left untouched' );
t_assert( 'unpaid' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$p}cc_invoices WHERE id = %d", $a1['invoice'] ) ), 'invoice left untouched' );
if ( $have_audit ) {
	$n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}cc_audit_log WHERE action = 'application.reject' AND entity_type = 'application' AND entity_id = %d", $a1['app'] ) );
	t_assert( 1 === $n, 'rejection audited once' );
	$note = $wpdb->get_var( $wpdb->prepare( "SELECT note FROM {$p}cc_audit_log WHERE action = 'application.reject' AND entity_id = %d", $a1['app'] ) );
	t_assert( 'rejected' === $note, 'audit note is the fixed word, not the staff-typed reason' );
}
$r = CC_Admin_Applications::reject( $a1['app'], 'again', $actor );
t_assert( is_wp_error( $r ) && 'not_pending' === $r->get_error_code(), 'already rejected application refuses' );
$r = CC_Admin_Applications::reject( $a2['app'], 'nope', $actor );
t_assert( is_wp_error( $r ) && 'not_pending' === $r->get_error_code() && 'approved' === t_row( $a2['app'] )['status'], 'approved application refuses and stays approved' );
$r = CC_Admin_Applications::reject( $a4['app'], 'paid already', $actor );
t_assert( is_wp_error( $r ) && 'paid' === $r->get_error_code() && 'pending' === t_row( $a4['app'] )['status'], 'pending application with completed payment refuses' );
$a5 = t_app( $batch, "Zed Epsilon $tag", 'pending', '2030-03-13 10:00:00' );
$r  = CC_Admin_Applications::reject( $a5['app'], '   ', $actor );
t_assert( is_wp_error( $r ) && 'reason_needed' === $r->get_error_code() && 'pending' === t_row( $a5['app'] )['status'], 'blank reason refuses' );
$r = CC_Admin_Applications::reject( $a5['app'], str_repeat( 'x', 501 ), $actor );
t_assert( is_wp_error( $r ) && 'reason_needed' === $r->get_error_code(), 'over-long reason refuses' );
$r = CC_Admin_Applications::reject( 999999999, 'x', $actor );
t_assert( is_wp_error( $r ) && 'not_found' === $r->get_error_code(), 'unknown id refuses' );

echo "rejected SMS\n";
$sms_for = static fn( int $app ): array => (array) $wpdb->get_results( $wpdb->prepare( "SELECT to_phone, template FROM {$p}cc_sms_log WHERE related_type = 'application' AND related_id = %d AND template = 'application_rejected'", $app ), ARRAY_A );
$sms     = $sms_for( $a1['app'] );
t_assert( 1 === count( $sms ) && '+8801711111111' === $sms[0]['to_phone'], 'rejection queues one SMS to the applicant phone' );
t_assert( 0 === count( $sms_for( $a2['app'] ) ) && 0 === count( $sms_for( $a4['app'] ) ), 'refused rejections queue no SMS' );
t_assert( 0 === count( $sms_for( $a5['app'] ) ), 'blank-reason refusal queues no SMS' );
$body = CC_Sms::render( 'application_rejected', array() );
t_assert( false !== strpos( $body, 'Your application was not approved. Please contact us.' ) && false === strpos( $body, 'Incomplete' ), 'text has no reason' );
$a6 = t_app( $batch, "Zed Zeta $tag", 'pending', '2030-03-14 10:00:00', null, '12345' );
t_assert( true === CC_Admin_Applications::reject( $a6['app'], 'bad phone', $actor ) && 'rejected' === t_row( $a6['app'] )['status'], 'unusable phone does not fail the rejection' );
t_assert( 0 === count( $sms_for( $a6['app'] ) ), 'no SMS queued for an unusable phone' );
$a7 = t_app( $batch, "Zed Eta $tag", 'pending', '2030-03-15 10:00:00', null, '01744444444' );
CC_Admin_Applications::reject( $a7['app'], 'local format', $actor );
$sms = $sms_for( $a7['app'] );
t_assert( 1 === count( $sms ) && '+8801744444444' === $sms[0]['to_phone'], 'local-format phone is normalised to E.164' );

echo "export search key\n";
$f_s      = CC_Admin_Applications::filters_from_request( array( 's' => $a2['ref'] ) );
$f_search = CC_Admin_Applications::filters_from_request( array( 'search' => $a2['ref'] ) );
t_assert( $a2['ref'] === $f_s['search'] && $a2['ref'] === $f_search['search'], 'filters_from_request accepts both s and search' );
$link = CC_Admin_Applications::export_link_args( array( 's' => $a2['ref'], 'status' => 'approved' ) );
t_assert( $a2['ref'] === ( $link['s'] ?? null ) && ! isset( $link['search'] ), 'export link carries the term under the key the handler reads' );
$q = CC_Admin_Applications::query( CC_Admin_Applications::filters_from_request( $link ) );
t_assert( 1 === $q['total'] && (int) $q['items'][0]['id'] === $a2['app'], 'a link-derived export filter returns only the matching row' );
$q = CC_Admin_Applications::query( CC_Admin_Applications::filters_from_request( array( 's' => 'zz-no-such-' . $tag ) ) );
t_assert( 0 === $q['total'], 'a non-matching term exports nothing' );

echo "reveal / mask / photo\n";
t_assert( '****6789' === CC_Admin_Applications::masked_id_number( t_row( $a5['app'] ) ), 'ID number masked to last four' );
t_assert( '****' === CC_Admin_Applications::masked_id_number( array( 'id_doc_enc' => 'garbage' ) ), 'undecryptable ID number shows bare mask' );
$plain = CC_Admin_Applications::reveal( $a5['app'] );
t_assert( '1990123456789' === $plain, 'reveal returns the plaintext' );
if ( $have_audit ) {
	$n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}cc_audit_log WHERE action = 'application.reveal_id' AND entity_id = %d", $a5['app'] ) );
	t_assert( 1 === $n, 'reveal audited' );
}
t_assert( null === CC_Admin_Applications::photo_file( array( 'photo_path' => 'photos/../../wp-config.php' ) ), 'traversal photo path rejected' );
t_assert( null === CC_Admin_Applications::photo_file( array( 'photo_path' => null ) ), 'missing photo path yields null' );
if ( function_exists( 'imagecreatetruecolor' ) && null !== CC_Photo_Store::base_dir() ) {
	$img = imagecreatetruecolor( 8, 8 );
	ob_start();
	imagejpeg( $img );
	$stored = CC_Photo_Store::store_bytes( (string) ob_get_clean() );
	if ( is_string( $stored ) ) {
		$cleanup['files'][] = $stored;
		$file = CC_Admin_Applications::photo_file( array( 'photo_path' => $stored ) );
		t_assert( null !== $file && is_file( $file ), 'stored photo resolves inside the private dir' );
		t_assert( null === CC_Admin_Applications::photo_file( array( 'photo_path' => 'photos/' . str_repeat( 'a', 32 ) . '.jpg' ) ), 'well-formed but nonexistent photo yields null' );
	} else {
		t_assert( false, 'photo fixture could not be stored' );
	}
}

echo "handler capability and nonce refusals\n";
$nobody = t_user( 'zzapps-nobody-' . $tag, array() );
$staff  = t_user( 'zzapps-staff-' . $tag, array( 'cc_review_applications', 'cc_export_data' ) );
$_POST  = array( 'id' => $a5['app'], 'reason' => 'x', 'application' => array( $a5['app'] ), 'confirm' => '1' );
$_GET   = array( 'id' => $a5['app'] );
$_REQUEST = array_merge( $_GET, $_POST );

wp_set_current_user( $nobody );
foreach ( array( 'handle_reject', 'handle_bulk_reject', 'handle_reveal', 'handle_photo', 'handle_export' ) as $handler ) {
	t_assert( t_dies( array( 'CC_Admin_Applications', $handler ) ), "$handler refuses a user without capability" );
}
t_assert( 'pending' === t_row( $a5['app'] )['status'], 'refused handlers changed nothing' );
t_assert( t_dies( array( 'CC_Admin_Applications', 'render' ) ), 'render refuses a user without capability' );

wp_set_current_user( $staff );
foreach ( array( 'handle_reject', 'handle_bulk_reject', 'handle_photo', 'handle_export' ) as $handler ) {
	t_assert( t_dies( array( 'CC_Admin_Applications', $handler ) ), "$handler refuses a missing nonce" );
}
t_assert( 'pending' === t_row( $a5['app'] )['status'], 'nonce-less reject changed nothing' );
$_REQUEST['_wpnonce'] = wp_create_nonce( 'cc_app_reveal_' . $a5['app'] );
$_POST['_wpnonce']    = $_REQUEST['_wpnonce'];
t_assert( t_dies( array( 'CC_Admin_Applications', 'handle_reveal' ) ), 'reveal refuses staff even with a valid nonce (owner-only)' );
$_REQUEST['_wpnonce'] = wp_create_nonce( 'cc_app_reject_' . $a5['app'] . 'x' );
t_assert( t_dies( array( 'CC_Admin_Applications', 'handle_reject' ) ), 'reject refuses a nonce for a different action' );

wp_set_current_user( 0 );
$_POST = $_GET = $_REQUEST = array();

// Cleanup.
foreach ( $cleanup['apps'] as $id ) {
	$wpdb->query( $wpdb->prepare( "DELETE p FROM {$p}cc_payments p JOIN {$p}cc_invoices i ON i.id = p.invoice_id WHERE i.application_id = %d", $id ) );
	$wpdb->delete( "{$p}cc_invoices", array( 'application_id' => $id ) );
	$wpdb->delete( "{$p}cc_applications", array( 'id' => $id ) );
	$wpdb->delete( "{$p}cc_sms_log", array( 'related_type' => 'application', 'related_id' => $id ) );
	if ( $have_audit ) {
		$wpdb->delete( "{$p}cc_audit_log", array( 'entity_type' => 'application', 'entity_id' => $id ) );
	}
}
foreach ( $cleanup['batches'] as $id ) {
	$wpdb->delete( "{$p}cc_batches", array( 'id' => $id ) );
}
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ( $cleanup['users'] as $id ) {
	wp_delete_user( $id );
}
foreach ( $cleanup['files'] as $relative ) {
	CC_Photo_Store::delete( $relative );
}

echo "\n$checks checks, $failures failures\n";
exit( $failures ? 1 : 0 );
