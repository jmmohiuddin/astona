<?php
/**
 * Integration tests for the refund/void record (CC_Admin_Payments::mark_refunded + CC_Refund). Run inside the wpcli container:
 *   docker compose run --rm -T wpcli eval-file /tests/integration/refund-test.php
 * Builds real settled payments through the fake gateway, provisions the student, then refunds. Removes everything afterwards.
 * The concurrency case starts two `wp eval` child processes against a payment row held locked by a second DB connection.
 */
global $wpdb, $failures, $checks, $cleanup;
$p        = $wpdb->prefix;
$failures = 0;
$checks   = 0;
$cleanup  = array( 'batches' => array(), 'apps' => array(), 'gpids' => array(), 'users' => array(), 'logins' => array() );

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

function t_pending_ids( string $hook ): array {
	if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
		return array();
	}
	return array_map( 'intval', as_get_scheduled_actions( array( 'hook' => $hook, 'status' => ActionScheduler_Store::STATUS_PENDING, 'per_page' => -1 ), 'ids' ) );
}
$as_before = array( 'cc_provision_student' => t_pending_ids( 'cc_provision_student' ), 'cc_send_sms' => t_pending_ids( 'cc_send_sms' ) );
// Provisioning is run by hand below; stop settlement from queueing it asynchronously.
remove_action( 'cc_application_settled', array( 'CC_Provisioner', 'schedule' ), 10 );

function t_row( string $table, string $col, int $id ) {
	global $wpdb;
	return $wpdb->get_var( $wpdb->prepare( "SELECT {$col} FROM {$wpdb->prefix}{$table} WHERE id = %d", $id ) );
}

function t_user( string $cap = '' ): int {
	global $cleanup;
	$id = wp_insert_user( array( 'user_login' => 'zzrefund_' . wp_generate_password( 8, false ), 'user_pass' => wp_generate_password( 20 ), 'user_email' => 'zzrefund' . wp_generate_password( 6, false ) . '@example.invalid', 'role' => 'subscriber' ) );
	foreach ( array_filter( explode( ',', $cap ) ) as $c ) {
		get_userdata( $id )->add_cap( $c );
	}
	$cleanup['users'][] = $id;
	return $id;
}

/** Unsettled fixture: batch (capacity 10, seats 0), pending application, unpaid invoice, initiated payment. */
function t_fixture( float $price ): array {
	global $wpdb, $cleanup;
	$p     = $wpdb->prefix;
	$now   = gmdate( 'Y-m-d H:i:s' );
	$tag   = substr( bin2hex( random_bytes( 6 ) ), 0, 12 );
	$phone = '+88019' . str_pad( (string) random_int( 0, 99999999 ), 8, '0', STR_PAD_LEFT );

	$wpdb->insert( "{$p}cc_batches", array(
		'course_id' => 0, 'name' => "ZZ-REFUND $tag", 'capacity' => 10, 'seats_taken' => 0, 'price' => $price,
		'start_date' => '2030-01-01', 'status' => 'open', 'application_open' => 1, 'created_at' => $now, 'updated_at' => $now,
	) );
	$batch = (int) $wpdb->insert_id;
	$wpdb->insert( "{$p}cc_applications", array(
		'public_ref' => substr( strtoupper( 'ZZRF' . $tag . '0000000000' ), 0, 26 ), 'idempotency_key' => wp_generate_uuid4(), 'batch_id' => $batch,
		'student_phone' => $phone, 'full_name' => 'ZZ Refund', 'gender' => 'o', 'dob' => '2008-01-01', 'id_doc_type' => 'nid', 'id_doc_enc' => 'x',
		'guardian_name' => 'G', 'guardian_phone' => '+8801700000001', 'institution' => 'I', 'class_level' => '10', 'consent_at' => $now,
		'phone_verified_at' => $now, 'status' => 'pending', 'created_at' => $now, 'updated_at' => $now,
	) );
	$app = (int) $wpdb->insert_id;
	$wpdb->insert( "{$p}cc_invoices", array( 'application_id' => $app, 'amount' => $price, 'number' => "ZZRF-$tag", 'created_at' => $now ) );
	$invoice = (int) $wpdb->insert_id;
	$pay     = t_payment( $invoice, $price, 'initiated' );

	$cleanup['batches'][] = $batch;
	$cleanup['apps'][]    = $app;
	$cleanup['logins'][]  = ltrim( $phone, '+' );
	return array( 'batch' => $batch, 'app' => $app, 'invoice' => $invoice, 'payment' => $pay['id'], 'gpid' => $pay['gpid'], 'phone' => $phone );
}

function t_payment( int $invoice, float $amount, string $status ): array {
	global $wpdb, $cleanup;
	$now = gmdate( 'Y-m-d H:i:s' );
	list( $gpid ) = ( new CC_Fake_Gateway() )->create_payment( array( 'amount' => $amount ), 'http://x' );
	$wpdb->insert( "{$wpdb->prefix}cc_payments", array(
		'invoice_id' => $invoice, 'gateway' => 'fake', 'method' => 'fake', 'gateway_payment_id' => $gpid, 'amount' => $amount, 'status' => $status,
		'settled_at' => 'completed' === $status ? $now : null, 'created_at' => $now, 'updated_at' => $now,
	) );
	$cleanup['gpids'][] = $gpid;
	return array( 'id' => (int) $wpdb->insert_id, 'gpid' => $gpid );
}

/** Settles through the fake gateway (approved application, seat taken) and provisions the student. */
function t_paid( float $price ): array {
	$f = t_fixture( $price );
	CC_Fake_Gateway::set_state( $f['gpid'], 'completed' );
	$outcome = CC_Settlement::settle( $f['payment'], 'poll' );
	if ( 'settled' !== $outcome['result'] ) {
		throw new RuntimeException( 'fixture did not settle: ' . $outcome['result'] );
	}
	$f['user'] = CC_Provisioner::provision( $f['app'] )['user_id'];
	return $f;
}

function t_state( array $f ): array {
	return array(
		'payment'    => t_row( 'cc_payments', 'status', $f['payment'] ),
		'invoice'    => t_row( 'cc_invoices', 'status', $f['invoice'] ),
		'app'        => t_row( 'cc_applications', 'status', $f['app'] ),
		'seats'      => (int) t_row( 'cc_batches', 'seats_taken', $f['batch'] ),
		'enrollment' => (string) $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( "SELECT status FROM {$GLOBALS['wpdb']->prefix}cc_enrollments WHERE application_id = %d", $f['app'] ) ),
	);
}

function t_count( string $table, string $where, ...$args ): int {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}{$table} WHERE {$where}", ...$args ) );
}

add_filter( 'wp_die_handler', static fn() => static function ( $m ) {
	throw new RuntimeException( 'wp_die' );
} );
add_filter( 'wp_redirect', static function ( $location ) {
	throw new RuntimeException( 'redirect:' . $location );
} );

$owner  = t_user( 'cc_view_payments,cc_reconcile_payments' );
$viewer = t_user( 'cc_view_payments' );
$nobody = t_user();
wp_set_current_user( $owner );

echo "mark_refunded(): happy path with every side effect\n";
$f = t_paid( 6000.00 );
$before = t_state( $f );
t_assert( 'completed' === $before['payment'] && 'paid' === $before['invoice'] && 'approved' === $before['app'] && 1 === $before['seats'] && 'active' === $before['enrollment'], 'precondition: completed/paid/approved, one seat, active enrollment' );
t_assert( CC_Enrollment_Repository::user_has_active( $f['user'], $f['batch'] ) && null !== CC_Portal_Data::receipt( $f['user'], $f['payment'] ), 'precondition: student has access and a receipt' );
$settled_at  = t_row( 'cc_payments', 'settled_at', $f['payment'] );
$revenue_was = CC_Admin_Dashboard::stats()['revenue_total'];
$ledger_was  = CC_Admin_Payments::query( array( 's' => "ZZRF-" ) )['completed_sum'];
$r = CC_Admin_Payments::mark_refunded( $f['payment'], 'student_withdrew', 'REF-1/a.b 2', $owner );
t_assert( ! is_wp_error( $r ) && true === $r['ok'] && 'refunded' === $r['result'] && true === $r['seat_released'] && '' !== $r['message'], 'returns ok, refunded, seat released, message' );
$after = t_state( $f );
t_assert( 'refunded' === $after['payment'] && $settled_at === t_row( 'cc_payments', 'settled_at', $f['payment'] ), 'payment refunded, settled_at kept' );
t_assert( 'void' === $after['invoice'] && 'cancelled' === $after['app'] && 0 === $after['seats'] && 'deactivated' === $after['enrollment'], 'invoice void, application cancelled, seat released, enrollment deactivated' );
t_assert( 1 === t_count( 'cc_payment_events', "payment_id = %d AND source = 'admin' AND event_key = %s", $f['payment'], CC_Idempotency::event_key( 'refund', (string) $f['payment'] ) ), 'one admin event with the refund event_key' );
$payload = json_decode( (string) t_row_event( $f['payment'] ), true );
t_assert( 'student_withdrew' === $payload['category'] && 'REF-1/a.b 2' === $payload['reference'] && '6000.00' === $payload['amount'] && $owner === $payload['actor_id'], 'event payload holds category, reference, amount, actor_id' );
$events = CC_Admin_Payments::events( $f['payment'] );
$last   = end( $events );
t_assert( 'refund recorded' === $last['result'] && 'Student withdrew, ref REF-1/a.b 2' === $last['detail'] && '6000.00' === $last['amount'], 'timeline whitelist shows "refund recorded" with category label and reference' );
$audit = $wpdb->get_row( $wpdb->prepare( "SELECT note, diff_hash FROM {$p}cc_audit_log WHERE action = 'payment.refund' AND entity_type = 'payment' AND entity_id = %d", $f['payment'] ), ARRAY_A );
t_assert( is_array( $audit ) && 1 === t_count( 'cc_audit_log', "action = 'payment.refund' AND entity_id = %d", $f['payment'] ), 'exactly one payment.refund audit row' );
t_assert( 'student_withdrew' === $audit['note'] && 1 === preg_match( '/^[a-f0-9]{64}$/', (string) $audit['diff_hash'] ) && ! str_contains( (string) wp_json_encode( $audit ), 'REF-1' ), 'audit note is the category code only; no reference or free text' );

echo "refund ends access and is invisible to revenue\n";
t_assert( ! CC_Enrollment_Repository::user_has_active( $f['user'], $f['batch'] ), 'student no longer has an active enrollment' );
$dash = CC_Portal_Data::dashboard( $f['user'] )['enrollments'];
t_assert( 1 === count( $dash ) && true === $dash[0]['access_ended'], 'portal dashboard shows the access-ended state' );
t_assert( null === CC_Portal_Data::receipt( $f['user'], $f['payment'] ), 'no receipt for a refunded payment' );
$pp = CC_Portal_Data::payments( $f['user'] );
t_assert( 1 === count( $pp ) && 'refunded' === $pp[0]['status'], 'portal payments list reports status refunded' );
t_assert( abs( ( $revenue_was - CC_Admin_Dashboard::stats()['revenue_total'] ) - 6000.00 ) < 0.005, 'dashboard revenue_total dropped by exactly the refunded amount' );
t_assert( '0.00' === CC_Admin_Payments::query( array( 's' => 'ZZRF-', 'status' => 'refunded' ) )['completed_sum'] && 1 <= CC_Admin_Payments::query( array( 'status' => 'refunded' ) )['total'], 'ledger: Refunded filter lists it with 0.00 completed sum' );
t_assert( (float) $ledger_was - (float) CC_Admin_Payments::query( array( 's' => 'ZZRF-' ) )['completed_sum'] === 6000.00, 'ledger totals bar excludes the refunded amount' );
$csv = iterator_to_array( CC_Admin_Payments::csv_rows( CC_Admin_Payments::filters_from( array( 's' => 'ZZRF-', 'status' => 'refunded' ) ), false ), false );
t_assert( 1 <= count( $csv ) && 'Status' === CC_Admin_Payments::CSV_HEADER[8] && 'refunded' === $csv[0][8], 'CSV export carries the refunded status' );
t_assert( str_contains( CC_Admin_Payments::status_html( array( 'status' => 'refunded' ) ), 'cc-chip--refunded' ) && '' !== CC_Admin_Payments::refund_link( $f['payment'] ), 'refunded status renders as a chip; owner sees the row action helper' );

echo "mark_refunded(): idempotent, seat released once\n";
$again = CC_Admin_Payments::mark_refunded( $f['payment'], 'other', '', $owner );
t_assert( ! is_wp_error( $again ) && 'already_refunded' === $again['result'] && false === $again['seat_released'], 'second call returns already_refunded' );
t_assert( t_state( $f ) === $after && 1 === t_count( 'cc_payment_events', "payment_id = %d AND source = 'admin'", $f['payment'] ) && 1 === t_count( 'cc_audit_log', "action = 'payment.refund' AND entity_id = %d", $f['payment'] ), 'no second decrement, event or audit row' );
$other_seats = t_paid( 100.00 );
$wpdb->update( "{$p}cc_batches", array( 'seats_taken' => 4 ), array( 'id' => $other_seats['batch'] ) );
CC_Admin_Payments::mark_refunded( $other_seats['payment'], 'error', '', $owner );
CC_Admin_Payments::mark_refunded( $other_seats['payment'], 'error', '', $owner );
t_assert( 3 === t_state( $other_seats )['seats'], 'seats 4 -> 3 after two calls (exactly one decrement)' );

echo "late gateway completion cannot resurrect a refunded payment\n";
CC_Fake_Gateway::set_state( $f['gpid'], 'completed' );
foreach ( array( 'poll', 'callback', 'admin' ) as $source ) {
	t_assert( 'already_settled' === CC_Settlement::settle( $f['payment'], $source )['result'], "settle() from $source returns already_settled" );
}
t_assert( 'already_settled' === CC_Admin_Payments::reconcile( $f['payment'], $owner )['result'], 'admin Reconcile reports already settled' );
t_assert( t_state( $f ) === $after && ! CC_Enrollment_Repository::user_has_active( $f['user'], $f['batch'] ), 'nothing re-approved: refunded, void, cancelled, 0 seats, deactivated' );

echo "never a negative seat count\n";
$z = t_fixture( 200.00 );
$wpdb->update( "{$p}cc_payments", array( 'status' => 'completed', 'settled_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => $z['payment'] ) );
$wpdb->update( "{$p}cc_invoices", array( 'status' => 'paid' ), array( 'id' => $z['invoice'] ) );
$wpdb->update( "{$p}cc_applications", array( 'status' => 'approved' ), array( 'id' => $z['app'] ) );
$rz = CC_Admin_Payments::mark_refunded( $z['payment'], 'error', '', $owner );
t_assert( ! is_wp_error( $rz ) && 'refunded' === $rz['result'] && false === $rz['seat_released'] && 0 === t_state( $z )['seats'] && 'cancelled' === t_state( $z )['app'], 'seats_taken 0 stays 0 (no seat released)' );

echo "application that is not approved is left alone\n";
$n = t_fixture( 300.00 );
$wpdb->update( "{$p}cc_payments", array( 'status' => 'completed', 'settled_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => $n['payment'] ) );
$wpdb->update( "{$p}cc_batches", array( 'seats_taken' => 2 ), array( 'id' => $n['batch'] ) );
CC_Admin_Payments::mark_refunded( $n['payment'], 'other', '', $owner );
t_assert( 'pending' === t_state( $n )['app'] && 2 === t_state( $n )['seats'] && 'refunded' === t_state( $n )['payment'], 'pending application and its batch seats untouched; payment refunded' );

echo "double-paid invoice: refunding the duplicate keeps the application\n";
$d = t_paid( 7000.00 );
$dup = t_payment( $d['invoice'], 7000.00, 'completed' );
$rd  = CC_Admin_Payments::mark_refunded( $dup['id'], 'duplicate_payment', '', $owner );
t_assert( ! is_wp_error( $rd ) && 'refunded_duplicate' === $rd['result'] && false === $rd['seat_released'] && str_contains( $rd['message'], 'this payment only' ), 'result says only this payment was refunded' );
$sd = t_state( $d );
t_assert( 'completed' === $sd['payment'] && 'refunded' === t_row( 'cc_payments', 'status', $dup['id'] ), 'duplicate refunded, original payment still completed' );
t_assert( 'paid' === $sd['invoice'] && 'approved' === $sd['app'] && 1 === $sd['seats'] && 'active' === $sd['enrollment'], 'invoice, application, seat and enrollment untouched' );
$rd2 = CC_Admin_Payments::mark_refunded( $d['payment'], 'student_withdrew', '', $owner );
t_assert( 'refunded' === $rd2['result'] && true === $rd2['seat_released'], 'refunding the last completed payment then does the full cancel' );
t_assert( array( 'refunded', 'void', 'cancelled', 0, 'deactivated' ) === array_values( t_state( $d ) ), 'full effects applied once the duplicate is gone' );

echo "refusals: non-completed, bad input, unknown, capability\n";
foreach ( array( 'initiated', 'executing', 'failed', 'cancelled', 'reconcile_needed' ) as $status ) {
	$x = t_fixture( 400.00 );
	$wpdb->update( "{$p}cc_payments", array( 'status' => $status ), array( 'id' => $x['payment'] ) );
	$rx = CC_Admin_Payments::mark_refunded( $x['payment'], 'other', '', $owner );
	t_assert( is_wp_error( $rx ) && 'refund_not_completed' === $rx->get_error_code() && $status === t_state( $x )['payment'] && 'pending' === t_state( $x )['app'], "$status payment refused, nothing changed" );
}
$c = t_paid( 500.00 );
foreach ( array( 'bogus', '', 'OTHER' ) as $bad ) {
	$rb = CC_Admin_Payments::mark_refunded( $c['payment'], $bad, '', $owner );
	t_assert( is_wp_error( $rb ) && 'refund_invalid' === $rb->get_error_code(), 'category "' . $bad . '" refused' );
}
foreach ( array( str_repeat( 'a', 65 ), '<script>', "x'; DROP", 'a%b', "line\nbreak" ) as $bad ) {
	$rb = CC_Admin_Payments::mark_refunded( $c['payment'], 'other', $bad, $owner );
	t_assert( is_wp_error( $rb ) && 'refund_invalid' === $rb->get_error_code(), 'reference ' . json_encode( substr( $bad, 0, 12 ) ) . ' refused' );
}
t_assert( 'completed' === t_state( $c )['payment'] && 'approved' === t_state( $c )['app'], 'invalid input changed nothing' );
t_assert( 'not_found' === CC_Admin_Payments::mark_refunded( 999999999, 'other', '', $owner )->get_error_code(), 'unknown payment -> not_found' );
foreach ( array( 'view-only user' => $viewer, 'no-cap user' => $nobody ) as $label => $uid ) {
	$rf = CC_Admin_Payments::mark_refunded( $c['payment'], 'other', '', $uid );
	t_assert( is_wp_error( $rf ) && 'refund_forbidden' === $rf->get_error_code(), "$label refused" );
}
t_assert( 'completed' === t_state( $c )['payment'] && 'approved' === t_state( $c )['app'] && 1 === t_state( $c )['seats'], 'refused calls left everything untouched' );
$ok_ref = CC_Admin_Payments::mark_refunded( $c['payment'], 'batch_cancelled', str_repeat( 'a', 64 ), $owner );
t_assert( ! is_wp_error( $ok_ref ), 'a 64-character reference is accepted' );

echo "handler and confirm page: nonce, capability, POST\n";
$h = t_paid( 800.00 );
$_POST = array( 'payment_id' => (string) $h['payment'], 'category' => 'error', 'reference' => 'R9' );
$call  = static function () {
	try {
		CC_Admin_Payments::handle_refund();
	} catch ( RuntimeException $e ) {
		return $e->getMessage();
	}
	return 'returned';
};
$_SERVER['REQUEST_METHOD'] = 'POST';
unset( $_REQUEST['_wpnonce'] );
t_assert( 'wp_die' === $call() && 'completed' === t_state( $h )['payment'], 'no nonce: dies, payment untouched' );
$_REQUEST['_wpnonce'] = wp_create_nonce( 'cc_refund_payment_' . ( $h['payment'] + 1 ) );
t_assert( 'wp_die' === $call() && 'completed' === t_state( $h )['payment'], 'nonce for another payment: dies' );
wp_set_current_user( $viewer );
$_REQUEST['_wpnonce'] = wp_create_nonce( 'cc_refund_payment_' . $h['payment'] );
t_assert( 'wp_die' === $call() && 'completed' === t_state( $h )['payment'], 'valid nonce without the capability: dies' );
wp_set_current_user( $owner );
$_REQUEST['_wpnonce'] = wp_create_nonce( 'cc_refund_payment_' . $h['payment'] );
$_SERVER['REQUEST_METHOD'] = 'GET';
t_assert( 'wp_die' === $call() && 'completed' === t_state( $h )['payment'], 'GET request: dies' );
$_SERVER['REQUEST_METHOD'] = 'POST';
$res = $call();
t_assert( str_starts_with( $res, 'redirect:' ) && str_contains( $res, 'cc_result=refunded' ) && 'refunded' === t_state( $h )['payment'] && 'cancelled' === t_state( $h )['app'], 'valid nonce + owner + POST: refunds and redirects with cc_result=refunded' );
$_POST['category'] = 'nonsense';
$_REQUEST['_wpnonce'] = wp_create_nonce( 'cc_refund_payment_' . $c['payment'] );
$_POST['payment_id']  = (string) $c['payment'];
$wpdb->update( "{$p}cc_payments", array( 'status' => 'completed' ), array( 'id' => $c['payment'] ) );
t_assert( str_contains( $call(), 'cc_result=refund_invalid' ), 'invalid category: redirects with cc_result=refund_invalid' );
$_POST = array();
unset( $_REQUEST['_wpnonce'] );

$page = static function ( int $payment_id ): string {
	$_GET = array( 'page' => CC_Admin_Payments::PAGE, 'refund' => (string) $payment_id );
	ob_start();
	CC_Admin_Payments::render();
	$_GET = array();
	return (string) ob_get_clean();
};
$g = t_paid( 900.00 );
$html = $page( $g['payment'] );
t_assert( str_contains( $html, 'Mark this payment refunded?' ) && str_contains( $html, 'name="category"' ) && str_contains( $html, 'name="_wpnonce"' ) && str_contains( $html, 'value="cc_payment_refund"' ) && str_contains( $html, 'ZZRF-' ), 'confirm page shows invoice, form, nonce' );
t_assert( 5 === substr_count( $html, '<option value="' ) - 1 && str_contains( $html, 'maxlength="64"' ) && str_contains( $html, 'outside this system' ), 'confirm page: five categories, 64-char reference, "recorded outside" explanation' );
t_assert( str_contains( $page( $x['payment'] ), 'Only completed payments' ) && ! str_contains( $page( $x['payment'] ), 'name="category"' ), 'confirm page refuses a non-completed payment (no form)' );
wp_set_current_user( $viewer );
t_assert( ! str_contains( $page( $g['payment'] ), 'name="category"' ) && '' === CC_Admin_Payments::refund_link( $g['payment'] ), 'view-only user gets no form and no row action' );
wp_set_current_user( $owner );
t_assert( str_contains( CC_Admin_Payments::refund_link( $g['payment'] ), 'refund=' . $g['payment'] ), 'owner gets the Mark refunded row action' );

echo "timeline never renders raw payload\n";
$wpdb->insert( "{$p}cc_payment_events", array( 'payment_id' => $g['payment'], 'source' => 'admin', 'event_key' => hash( 'sha256', 'zz-tamper' . $g['payment'] ), 'payload_json' => wp_json_encode( array( 'category' => 'other', 'reference' => '<script>alert(1)</script>', 'actor_id' => 77, 'secret' => 'S3CRET' ) ), 'received_at' => gmdate( 'Y-m-d H:i:s' ) ) );
$tl = CC_Admin_Payments::events( $g['payment'] );
$flat = (string) wp_json_encode( $tl );
t_assert( ! str_contains( $flat, 'script' ) && ! str_contains( $flat, 'S3CRET' ) && ! str_contains( $flat, 'actor_id' ), 'a tampered reference/extra fields never reach the timeline' );
t_assert( 'Other' === end( $tl )['detail'], 'tampered reference is dropped, category label kept' );

echo "concurrency: two simultaneous refunds of one payment (row held by a second connection)\n";
$k = t_paid( 1500.00 );
$wpdb->update( "{$p}cc_batches", array( 'seats_taken' => 5 ), array( 'id' => $k['batch'] ) );
$spawn = static function ( int $payment_id ) use ( $owner ) {
	$code = sprintf( 'wp_set_current_user(%d); $r = CC_Admin_Payments::mark_refunded(%d, "other", "", %d); echo is_wp_error($r) ? "ERR:" . $r->get_error_code() : $r["result"];', $owner, $payment_id, $owner );
	$proc = proc_open( array( 'wp', 'eval', $code ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, ABSPATH );
	return array( $proc, $pipes );
};
$second = new mysqli( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME );
$second->query( 'START TRANSACTION' );
$second->query( "SELECT id FROM {$p}cc_payments WHERE id = {$k['payment']} FOR UPDATE" );
$kids = array( $spawn( $k['payment'] ), $spawn( $k['payment'] ) );
sleep( 2 );
t_assert( 'completed' === t_state( $k )['payment'] && 5 === t_state( $k )['seats'], 'while the row is locked elsewhere nothing has changed' );
$second->query( 'ROLLBACK' );
$second->close();
$results = array();
foreach ( $kids as list( $proc, $pipes ) ) {
	$results[] = trim( stream_get_contents( $pipes[1] ) );
	stream_get_contents( $pipes[2] );
	proc_close( $proc );
}
sort( $results );
t_assert( array( 'already_refunded', 'refunded' ) === $results, 'one child refunded, the other saw already_refunded (got ' . implode( ',', $results ) . ')' );
t_assert( 4 === t_state( $k )['seats'] && 'refunded' === t_state( $k )['payment'] && 1 === t_count( 'cc_audit_log', "action = 'payment.refund' AND entity_id = %d", $k['payment'] ), 'seats 5 -> 4 exactly once, one audit row' );

// Cleanup.
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ( $cleanup['logins'] as $login ) {
	$u = get_user_by( 'login', $login );
	if ( $u ) {
		$wpdb->delete( "{$p}cc_students", array( 'user_id' => $u->ID ) );
		$wpdb->delete( "{$p}cc_enrollments", array( 'user_id' => $u->ID ) );
		wp_delete_user( $u->ID );
	}
}
foreach ( $cleanup['apps'] as $app ) {
	foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$p}cc_sms_log WHERE related_type = 'application' AND related_id = %d", $app ) ) as $sms_id ) {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'cc_send_sms', array( (int) $sms_id ), 'cc' );
		}
	}
	$wpdb->delete( "{$p}cc_sms_log", array( 'related_type' => 'application', 'related_id' => $app ) );
	$wpdb->delete( "{$p}cc_enrollments", array( 'application_id' => $app ) );
	foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$p}cc_invoices WHERE application_id = %d", $app ) ) as $inv ) {
		foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$p}cc_payments WHERE invoice_id = %d", $inv ) ) as $pay ) {
			$wpdb->delete( "{$p}cc_payment_events", array( 'payment_id' => $pay ) );
			$wpdb->delete( "{$p}cc_audit_log", array( 'entity_type' => 'payment', 'entity_id' => $pay ) );
			$wpdb->delete( "{$p}cc_payments", array( 'id' => $pay ) );
		}
		$wpdb->delete( "{$p}cc_invoices", array( 'id' => $inv ) );
	}
	$wpdb->delete( "{$p}cc_applications", array( 'id' => $app ) );
	for ( $attempt = 1; $attempt <= CC_Provisioner::MAX_ATTEMPTS; $attempt++ ) {
		wp_clear_scheduled_hook( CC_Provisioner::HOOK, array( $app, $attempt ) );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( CC_Provisioner::HOOK, array( $app, $attempt ), CC_Provisioner::GROUP );
		}
	}
}
if ( function_exists( 'as_get_scheduled_actions' ) ) {
	foreach ( $as_before as $hook => $ids ) {
		foreach ( array_diff( t_pending_ids( $hook ), $ids ) as $new_id ) {
			ActionScheduler::store()->cancel_action( $new_id );
		}
	}
	t_assert( $as_before['cc_provision_student'] === t_pending_ids( 'cc_provision_student' ) && $as_before['cc_send_sms'] === t_pending_ids( 'cc_send_sms' ), 'no pending cc_provision_student/cc_send_sms actions left by this run' );
}
foreach ( $cleanup['batches'] as $b ) {
	$wpdb->delete( "{$p}cc_batches", array( 'id' => $b ) );
}
foreach ( $cleanup['gpids'] as $gp ) {
	CC_Fake_Gateway::delete_state( $gp );
}
foreach ( $cleanup['users'] as $u ) {
	wp_delete_user( $u );
}

echo "\n$checks checks, $failures failures\n";
if ( $failures > 0 ) {
	WP_CLI::halt( 1 );
}

function t_row_event( int $payment_id ) {
	global $wpdb;
	return $wpdb->get_var( $wpdb->prepare( "SELECT payload_json FROM {$wpdb->prefix}cc_payment_events WHERE payment_id = %d AND source = 'admin' ORDER BY id DESC LIMIT 1", $payment_id ) );
}
