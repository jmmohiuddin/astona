<?php
/**
 * Integration tests for CC_Admin_Payments / CC_Admin_Audit. Run inside the wpcli container:
 *   docker compose run --rm -T wpcli eval-file /tests/integration/admin-payments-test.php
 * Inserts fixture rows directly and removes them afterwards (incl. pending Action Scheduler actions).
 */
global $wpdb, $failures, $checks, $cleanup;
$p        = $wpdb->prefix;
$failures = 0;
$checks   = 0;
$cleanup  = array( 'batches' => array(), 'apps' => array(), 'gpids' => array(), 'users' => array() );
$run      = strtoupper( substr( bin2hex( random_bytes( 4 ) ), 0, 8 ) );

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

/** @return array{batch:int,app:int,invoice:int,payment:int,gpid:string} */
function t_fixture( float $price, array $payment_overrides = array(), string $invoice_number = '' ): array {
	global $wpdb, $cleanup;
	$p   = $wpdb->prefix;
	$now = gmdate( 'Y-m-d H:i:s' );
	$tag = substr( bin2hex( random_bytes( 6 ) ), 0, 12 );

	$wpdb->insert( "{$p}cc_batches", array(
		'course_id' => 0, 'name' => "ZZ-TEST $tag", 'capacity' => 10, 'seats_taken' => 0,
		'price' => $price, 'start_date' => '2030-01-01', 'status' => 'open', 'application_open' => 1,
		'created_at' => $now, 'updated_at' => $now,
	) );
	$batch = (int) $wpdb->insert_id;
	$wpdb->insert( "{$p}cc_applications", array(
		'public_ref' => substr( strtoupper( 'ZZTEST' . $tag . '00000000' ), 0, 26 ), 'idempotency_key' => wp_generate_uuid4(), 'batch_id' => $batch,
		'student_phone' => '+8801700000000', 'full_name' => 'ZZ Test', 'gender' => 'o', 'dob' => '2008-01-01',
		'id_doc_type' => 'nid', 'id_doc_enc' => 'x', 'guardian_name' => 'G', 'guardian_phone' => '+8801700000001',
		'institution' => 'I', 'class_level' => '10', 'consent_at' => $now, 'status' => 'pending',
		'created_at' => $now, 'updated_at' => $now,
	) );
	$app = (int) $wpdb->insert_id;
	$wpdb->insert( "{$p}cc_invoices", array( 'application_id' => $app, 'amount' => $price, 'number' => '' !== $invoice_number ? $invoice_number : "ZZ-$tag", 'created_at' => $now ) );
	$invoice = (int) $wpdb->insert_id;
	list( $gpid ) = ( new CC_Fake_Gateway() )->create_payment( array( 'amount' => $price ), 'http://x' );
	$wpdb->insert( "{$p}cc_payments", array_merge( array(
		'invoice_id' => $invoice, 'gateway' => 'fake', 'method' => 'fake', 'gateway_payment_id' => $gpid,
		'amount' => $price, 'status' => 'initiated', 'created_at' => $now, 'updated_at' => $now,
	), $payment_overrides ) );

	$cleanup['batches'][] = $batch;
	$cleanup['apps'][]    = $app;
	$cleanup['gpids'][]   = $gpid;
	return array( 'batch' => $batch, 'app' => $app, 'invoice' => $invoice, 'payment' => (int) $wpdb->insert_id, 'gpid' => $gpid );
}

function t_row( string $table, string $col, int $id ) {
	global $wpdb;
	return $wpdb->get_var( $wpdb->prepare( "SELECT {$col} FROM {$wpdb->prefix}{$table} WHERE id = %d", $id ) );
}

function t_user( string $cap = '' ): int {
	global $cleanup;
	$id = wp_insert_user( array( 'user_login' => 'zzpay_' . wp_generate_password( 8, false ), 'user_pass' => wp_generate_password( 20 ), 'user_email' => 'zzpay' . wp_generate_password( 6, false ) . '@example.invalid', 'role' => 'subscriber' ) );
	if ( '' !== $cap ) {
		foreach ( explode( ',', $cap ) as $c ) {
			get_userdata( $id )->add_cap( $c );
		}
	}
	$cleanup['users'][] = $id;
	return $id;
}

$ago = static fn( int $minutes ): string => gmdate( 'Y-m-d H:i:s', time() - $minutes * 60 );

$reconciler = t_user( 'cc_view_payments,cc_reconcile_payments' );
$viewer     = t_user( 'cc_view_payments' );
$nobody     = t_user();

echo "reconcile(): completed-at-gateway initiated payment\n";
$f = t_fixture( 5000.00, array( 'created_at' => $ago( 15 ) ) );
CC_Fake_Gateway::set_state( $f['gpid'], 'completed' );
wp_set_current_user( $reconciler );
$r = CC_Admin_Payments::reconcile( $f['payment'], $reconciler );
t_assert( 'settled' === $r['result'] && true === $r['ok'] && '' !== $r['message'], 'result settled with message' );
t_assert( 'completed' === t_row( 'cc_payments', 'status', $f['payment'] ) && 'approved' === t_row( 'cc_applications', 'status', $f['app'] ), 'payment completed, application approved' );
$events = CC_Admin_Payments::events( $f['payment'] );
t_assert( 1 === count( $events ) && 'admin' === $events[0]['source'] && 'settled' === $events[0]['result'], 'timeline has one admin/settled event' );

echo "reconcile(): already completed is only reported\n";
$r2 = CC_Admin_Payments::reconcile( $f['payment'], $reconciler );
t_assert( 'already_settled' === $r2['result'] && '1' === t_row( 'cc_batches', 'seats_taken', $f['batch'] ), 'already_settled, seat taken once' );

echo "reconcile(): not completed / unknown / caps\n";
$f2 = t_fixture( 3000.00 );
t_assert( 'not_completed' === CC_Admin_Payments::reconcile( $f2['payment'], $reconciler )['result'], 'pending at gateway -> not_completed' );
t_assert( 'not_found' === CC_Admin_Payments::reconcile( 999999999, $reconciler )['result'], 'unknown id -> not_found' );
CC_Fake_Gateway::set_state( $f2['gpid'], 'completed' );
foreach ( array( 'view-only user' => $viewer, 'no-cap user' => $nobody ) as $label => $uid ) {
	$denied = CC_Admin_Payments::reconcile( $f2['payment'], $uid );
	t_assert( 'forbidden' === $denied['result'] && false === $denied['ok'], "$label refused" );
}
t_assert( 'completed' !== t_row( 'cc_payments', 'status', $f2['payment'] ), 'refused reconcile left payment untouched' );

echo "reconcile(): mismatch is surfaced, not applied\n";
$f3 = t_fixture( 4000.00 );
CC_Fake_Gateway::set_state( $f3['gpid'], 'completed', 1.00 );
t_assert( 'mismatch' === CC_Admin_Payments::reconcile( $f3['payment'], $reconciler )['result'] && 'reconcile_needed' === t_row( 'cc_payments', 'status', $f3['payment'] ), 'mismatch -> reconcile_needed' );

echo "handlers: nonce and capability enforced\n";
add_filter( 'wp_die_handler', static fn() => static function ( $m ) {
	throw new RuntimeException( 'wp_die' );
} );
$_POST['payment_id'] = (string) $f2['payment'];
unset( $_REQUEST['_wpnonce'] );
$died = false;
try {
	CC_Admin_Payments::handle_reconcile();
} catch ( RuntimeException $e ) {
	$died = 'wp_die' === $e->getMessage();
}
t_assert( $died, 'reconcile handler dies without nonce' );
wp_set_current_user( $viewer );
$_REQUEST['_wpnonce'] = wp_create_nonce( 'cc_reconcile_payment_' . $f2['payment'] );
$died = false;
try {
	CC_Admin_Payments::handle_reconcile();
} catch ( RuntimeException $e ) {
	$died = 'wp_die' === $e->getMessage();
}
t_assert( $died && 'completed' !== t_row( 'cc_payments', 'status', $f2['payment'] ), 'reconcile handler dies for valid nonce without cap, payment untouched' );
$_GET = array();
$_REQUEST['_wpnonce'] = wp_create_nonce( 'cc_export_payments' );
$died = false;
try {
	CC_Admin_Payments::handle_export();
} catch ( RuntimeException $e ) {
	$died = 'wp_die' === $e->getMessage();
}
t_assert( $died, 'export handler dies without cc_export_data' );
wp_set_current_user( $reconciler );
unset( $_POST['payment_id'], $_REQUEST['_wpnonce'] );

echo "filters, totals, sort, paging\n";
$mk = static function ( float $amount, string $status, string $created, ?string $phone = null ) use ( $run, $wpdb, $p ) {
	static $n = 0;
	++$n;
	$f = t_fixture( $amount, array( 'status' => $status, 'created_at' => $created, 'updated_at' => $created, 'trx_id' => "TRXZZ{$run}{$n}" ), "ZZP{$run}-{$n}" );
	if ( null !== $phone ) {
		$wpdb->update( "{$p}cc_applications", array( 'student_phone' => $phone ), array( 'id' => $f['app'] ) );
	}
	return $f;
};
$a = $mk( 1000.00, 'completed', $ago( 60 ), '+8801700004827' );
$b = $mk( 2500.00, 'completed', $ago( 30 ) );
$c = $mk( 700.00, 'failed', $ago( 20 ) );
$base = array( 's' => "ZZP{$run}" );
$all  = CC_Admin_Payments::query( $base );
t_assert( 3 === $all['total'] && '3500.00' === $all['completed_sum'] && 2 === $all['completed_count'], 'search by invoice prefix: total 3, completed sum 3500.00' );
$done = CC_Admin_Payments::query( $base + array( 'status' => 'completed' ) );
t_assert( 2 === $done['total'] && '3500.00' === $done['completed_sum'], 'status=completed filter' );
$failed = CC_Admin_Payments::query( $base + array( 'status' => 'failed' ) );
t_assert( 1 === $failed['total'] && '0.00' === $failed['completed_sum'], 'status=failed: total 1, completed sum 0.00' );
t_assert( 0 === CC_Admin_Payments::query( $base + array( 'gateway' => 'nogateway' ) )['total'], 'gateway filter excludes' );
t_assert( 3 === CC_Admin_Payments::query( $base + array( 'gateway' => 'fake' ) )['total'], 'gateway=fake includes' );
$today = gmdate( 'Y-m-d' );
t_assert( 3 === CC_Admin_Payments::query( $base + array( 'date_from' => $today, 'date_to' => $today ) )['total'] || gmdate( 'H' ) < 2, 'date range today (tolerates midnight rollover)' );
t_assert( 0 === CC_Admin_Payments::query( $base + array( 'date_from' => gmdate( 'Y-m-d', time() + 2 * DAY_IN_SECONDS ) ) )['total'], 'future date_from excludes all' );
t_assert( 0 === CC_Admin_Payments::query( $base + array( 'date_to' => '2000-01-01' ) )['total'], 'past date_to excludes all' );
t_assert( 1 === CC_Admin_Payments::query( array( 's' => "TRXZZ{$run}2" ) )['total'], 'search by trx id' );
$by_phone = CC_Admin_Payments::query( array( 's' => '4827' ) );
t_assert( in_array( (string) $a['payment'], array_column( $by_phone['rows'], 'id' ), true ), 'search by phone last 4' );
$asc = CC_Admin_Payments::query( $base + array( 'orderby' => 'amount', 'order' => 'ASC' ) );
t_assert( array( '700.00', '1000.00', '2500.00' ) === array_column( $asc['rows'], 'amount' ), 'sort by amount ASC' );
$page2 = CC_Admin_Payments::query( $base + array( 'per_page' => 2, 'page' => 2, 'orderby' => 'amount', 'order' => 'ASC' ) );
t_assert( 1 === count( $page2['rows'] ) && 3 === $page2['total'] && '2500.00' === $page2['rows'][0]['amount'], 'paging: page 2 of per_page 2' );
$inj = CC_Admin_Payments::query( $base + array( 'orderby' => 'amount; DROP TABLE x', 'order' => 'sideways' ) );
$norm = CC_Admin_Payments::filters_from( array( 'orderby' => 'amount; DROP TABLE x', 'order' => 'sideways', 'status' => "x' OR 1=1" ) );
t_assert( 'created' === $norm['orderby'] && 'DESC' === $norm['order'] && '' === $norm['status'] && 3 === $inj['total'], 'filters_from whitelists orderby/order/status' );

echo "stuck flag\n";
$s_old   = $mk( 100.00, 'initiated', $ago( 11 ) );
$s_new   = $mk( 100.00, 'initiated', $ago( 2 ) );
$s_exec  = $mk( 100.00, 'executing', $ago( 12 ) );
$s_recon = $mk( 100.00, 'reconcile_needed', $ago( 1 ) );
$s_done  = $mk( 100.00, 'completed', $ago( 600 ) );
$stuck_rows = CC_Admin_Payments::query( $base + array( 'status' => 'stuck', 'per_page' => 100 ) )['rows'];
$stuck_ids  = array_map( 'intval', array_column( $stuck_rows, 'id' ) );
sort( $stuck_ids );
$expected = array( $s_old['payment'], $s_exec['payment'], $s_recon['payment'] );
sort( $expected );
t_assert( $expected === $stuck_ids, 'status=stuck filter returns old initiated, old executing, reconcile_needed only' );
$flags = array();
foreach ( CC_Admin_Payments::query( $base + array( 'per_page' => 100 ) )['rows'] as $row ) {
	$flags[ (int) $row['id'] ] = CC_Admin_Payments::is_stuck( $row );
}
t_assert( $flags[ $s_old['payment'] ] && $flags[ $s_exec['payment'] ] && $flags[ $s_recon['payment'] ], 'is_stuck true for >10min initiated/executing and reconcile_needed' );
t_assert( ! $flags[ $s_new['payment'] ] && ! $flags[ $s_done['payment'] ] && ! $flags[ $a['payment'] ], 'is_stuck false for fresh initiated and completed' );

echo "CSV rows\n";
$wpdb->update( "{$p}cc_payments", array( 'response_json' => '{"token":"SECRETTOKEN","id_token":"SECRETTOKEN"}' ), array( 'id' => $a['payment'] ) );
$wpdb->update( "{$p}cc_applications", array( 'full_name' => '=HYPERLINK("http://x")' ), array( 'id' => $a['app'] ) );
$masked = iterator_to_array( CC_Admin_Payments::csv_rows( $base, false ), false );
$full   = iterator_to_array( CC_Admin_Payments::csv_rows( $base, true ), false );
t_assert( 8 === count( $masked ) && count( CC_Admin_Payments::CSV_HEADER ) === count( $masked[0] ), 'one row per payment, column count matches header' );
t_assert( false === strpos( wp_json_encode( array( $masked, $full ) ), 'SECRETTOKEN' ), 'export never contains response_json content' );
t_assert( false === strpos( wp_json_encode( $masked ), '+8801700004827' ) && false !== strpos( wp_json_encode( $full ), '4827' ), 'phone masked unless cc_manage_students' );
if ( class_exists( 'CC_Csv' ) ) {
	$names = array_map( array( 'CC_Csv', 'neutralise' ), array_column( $full, 2 ) );
	t_assert( in_array( "'=HYPERLINK(\"http://x\")", $names, true ) && ! in_array( '=HYPERLINK("http://x")', $names, true ), 'formula-leading student name is neutralised by CC_Csv' );
} else {
	echo "  skip CC_Csv not present\n";
}

echo "events whitelist and detail render\n";
$wpdb->insert( "{$p}cc_payment_events", array( 'payment_id' => $a['payment'], 'source' => 'ipn', 'event_key' => hash( 'sha256', "zz$run" ), 'payload_json' => wp_json_encode( array( 'result' => 'settled', 'secret' => 'SECRETTOKEN', 'gateway' => array( 'status' => 'completed', 'trx_id' => 'TRXOK1', 'amount' => 1000, 'raw' => array( 'token' => 'SECRETTOKEN' ) ) ) ), 'received_at' => gmdate( 'Y-m-d H:i:s' ) ) );
$ev = CC_Admin_Payments::events( $a['payment'] );
t_assert( 1 === count( $ev ) && 'ipn' === $ev[0]['source'] && 'TRXOK1' === $ev[0]['trx_id'] && false === strpos( wp_json_encode( $ev ), 'SECRETTOKEN' ), 'events() returns only whitelisted fields' );
$_GET['payment'] = (string) $a['payment'];
ob_start();
CC_Admin_Payments::render();
$html = ob_get_clean();
unset( $_GET['payment'] );
t_assert( false !== strpos( $html, "ZZP{$run}-1" ) && false === strpos( $html, 'SECRETTOKEN' ) && false === strpos( $html, 'response_json' ), 'detail view renders invoice, never raw payload' );
t_assert( false !== strpos( $html, '&lt;' ) || false === strpos( $html, '=HYPERLINK("http' ), 'student name is escaped in detail view' );
$_GET['payment'] = (string) $s_old['payment'];
ob_start();
CC_Admin_Payments::render();
$html = ob_get_clean();
unset( $_GET['payment'] );
t_assert( false !== strpos( $html, 'Reconcile now' ) && false !== strpos( $html, 'Needs attention' ), 'detail of stuck payment shows chip and reconcile button for reconciler' );
wp_set_current_user( $viewer );
$_GET['payment'] = (string) $s_old['payment'];
ob_start();
CC_Admin_Payments::render();
$html = ob_get_clean();
unset( $_GET['payment'] );
t_assert( false === strpos( $html, 'Reconcile now' ), 'no reconcile button without cc_reconcile_payments' );
wp_set_current_user( $nobody );
$died = false;
try {
	CC_Admin_Payments::render();
} catch ( RuntimeException $e ) {
	$died = true;
}
t_assert( $died, 'render refuses users without cc_view_payments' );

echo "list table render\n";
if ( ! function_exists( 'convert_to_screen' ) ) {
	require_once ABSPATH . 'wp-admin/includes/admin.php';
}
wp_set_current_user( $reconciler );
$_GET = array( 'page' => 'cc-payments', 's' => "ZZP{$run}" );
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/wp-admin/admin.php?page=cc-payments';
set_current_screen( 'toplevel_page_cc-payments' );
ob_start();
CC_Admin_Payments::render();
$html = ob_get_clean();
$_GET = array();
t_assert( false !== strpos( $html, "ZZP{$run}-1" ) && false !== strpos( $html, 'Completed in this filter' ) && false !== strpos( $html, '3,600.00' ), 'list renders rows and totals bar' );
t_assert( false !== strpos( $html, 'Needs attention' ) && false !== strpos( $html, 'name="_wpnonce"' ), 'list shows attention chip and nonce-protected reconcile form' );
t_assert( false === strpos( $html, 'SECRETTOKEN' ) && false === strpos( $html, '=HYPERLINK("http' ), 'list never leaks raw payload; names escaped' );

echo "audit viewer\n";
if ( class_exists( 'CC_Audit' ) && class_exists( 'CC_Admin_Audit' ) ) {
	$owner = t_user( 'cc_view_audit,cc_export_data' );
	wp_set_current_user( $owner );
	$action = 'zztest.' . strtolower( $run );
	CC_Audit::log( $action, 'payment', 42, array( 'k' => 'v' ), 'note one' );
	CC_Audit::log( $action, 'application', 43, array(), 'note two' );
	$f = CC_Admin_Audit::filters_from( array( 'audit_action' => $action, 'actor' => (string) $owner, 'paged' => '0', 'date_from' => 'bogus' ) );
	t_assert( $action === $f['action'] && $owner === $f['actor'] && 1 === $f['page'] && '' === $f['date_from'], 'filters_from sanitises input' );
	$res = CC_Admin_Audit::query( $f );
	t_assert( 2 === $res['total'] && 2 === count( $res['rows'] ), 'query by action+actor returns both entries' );
	$res = CC_Admin_Audit::query( CC_Admin_Audit::filters_from( array( 'audit_action' => $action, 'entity_type' => 'payment' ) ) );
	t_assert( 1 === $res['total'], 'entity_type filter narrows' );
	$res = CC_Admin_Audit::query( CC_Admin_Audit::filters_from( array( 'audit_action' => $action, 'date_from' => gmdate( 'Y-m-d', time() + 2 * DAY_IN_SECONDS ) ) ) );
	t_assert( 0 === $res['total'], 'future date_from returns nothing' );
	$csv = iterator_to_array( CC_Admin_Audit::csv_rows( CC_Admin_Audit::filters_from( array( 'audit_action' => $action ) ) ), false );
	t_assert( 2 === count( $csv ) && count( CC_Admin_Audit::CSV_HEADER ) === count( $csv[0] ), 'audit csv rows match header width' );
	ob_start();
	CC_Admin_Audit::render();
	$html = ob_get_clean();
	t_assert( false !== strpos( $html, $action ), 'audit page renders the entries' );
	wp_set_current_user( $nobody );
	$died = false;
	try {
		CC_Admin_Audit::render();
	} catch ( RuntimeException $e ) {
		$died = true;
	}
	t_assert( $died, 'audit page refuses users without cc_view_audit' );
	$wpdb->delete( "{$p}cc_audit_log", array( 'action' => $action ) );
} else {
	echo "  skip CC_Audit / CC_Admin_Audit not present yet\n";
}

// Cleanup.
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ( $cleanup['apps'] as $app ) {
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
	t_assert( array() === array_diff( t_pending_ids( 'cc_provision_student' ), $as_before['cc_provision_student'] ) && array() === array_diff( t_pending_ids( 'cc_send_sms' ), $as_before['cc_send_sms'] ), 'no pending cc_provision_student/cc_send_sms actions left by this run' );
}
foreach ( $cleanup['batches'] as $b ) {
	$wpdb->delete( "{$p}cc_batches", array( 'id' => $b ) );
}
foreach ( $cleanup['gpids'] as $g ) {
	CC_Fake_Gateway::delete_state( $g );
}
foreach ( $cleanup['users'] as $u ) {
	wp_delete_user( $u );
}

echo "\n$checks checks, $failures failures\n";
if ( $failures > 0 ) {
	WP_CLI::halt( 1 );
}
