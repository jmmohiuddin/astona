<?php
/**
 * Integration tests for admission retry-payment guards, application create() and photo limits. Run inside wpcli:
 *   docker compose run --rm -T wpcli eval-file /tests/integration/admission-test.php
 * Inserts fixture rows directly and removes them afterwards.
 */
global $wpdb, $failures, $checks, $cleanup;
$p        = $wpdb->prefix;
$failures = 0;
$checks   = 0;
$cleanup  = array( 'batches' => array(), 'apps' => array(), 'gpids' => array() );

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

/** @return array{batch:int,app:int,invoice:int,payment:int,gpid:string,ref:string} */
function t_fixture( int $capacity, int $seats, float $price, array $payment_overrides = array() ): array {
	global $wpdb, $cleanup;
	$p   = $wpdb->prefix;
	$now = gmdate( 'Y-m-d H:i:s' );
	$tag = substr( bin2hex( random_bytes( 6 ) ), 0, 12 );

	$wpdb->insert( "{$p}cc_batches", array(
		'course_id' => 0, 'name' => "ZZ-TEST $tag", 'capacity' => $capacity, 'seats_taken' => $seats,
		'price' => $price, 'start_date' => '2030-01-01', 'status' => 'open', 'application_open' => 1,
		'created_at' => $now, 'updated_at' => $now,
	) );
	$batch = (int) $wpdb->insert_id;

	$ref = strtoupper( 'ZZTEST' . $tag . '00000000' );
	$wpdb->insert( "{$p}cc_applications", array(
		'public_ref' => substr( $ref, 0, 26 ), 'idempotency_key' => wp_generate_uuid4(), 'batch_id' => $batch,
		'student_phone' => '+8801700000000', 'full_name' => 'ZZ Test', 'gender' => 'o', 'dob' => '2008-01-01',
		'id_doc_type' => 'nid', 'id_doc_enc' => 'x', 'guardian_name' => 'G', 'guardian_phone' => '+8801700000001',
		'institution' => 'I', 'class_level' => '10', 'consent_at' => $now, 'status' => 'pending',
		'created_at' => $now, 'updated_at' => $now,
	) );
	$app = (int) $wpdb->insert_id;

	$wpdb->insert( "{$p}cc_invoices", array(
		'application_id' => $app, 'amount' => $price, 'number' => "ZZ-$tag", 'created_at' => $now,
	) );
	$invoice = (int) $wpdb->insert_id;

	$invoice_row = array( 'id' => $invoice, 'number' => "ZZ-$tag", 'amount' => $price, 'currency' => 'BDT' );
	list( $gpid ) = ( new CC_Fake_Gateway() )->create_payment( $invoice_row, rest_url( 'cc/v1/payments/callback' ) );
	$wpdb->insert( "{$p}cc_payments", array_merge( array(
		'invoice_id' => $invoice, 'gateway' => 'fake', 'method' => 'fake', 'gateway_payment_id' => $gpid,
		'amount' => $price, 'status' => 'initiated', 'created_at' => $now, 'updated_at' => $now,
	), $payment_overrides ) );
	$payment = (int) $wpdb->insert_id;

	$cleanup['batches'][] = $batch;
	$cleanup['apps'][]    = $app;
	$cleanup['gpids'][]   = $gpid;
	return array( 'batch' => $batch, 'app' => $app, 'invoice' => $invoice, 'payment' => $payment, 'gpid' => $gpid, 'ref' => substr( $ref, 0, 26 ) );
}

function t_row( string $table, string $col, int $id ) {
	global $wpdb;
	return $wpdb->get_var( $wpdb->prepare( "SELECT {$col} FROM {$wpdb->prefix}{$table} WHERE id = %d", $id ) );
}

function t_retry( string $ref ): WP_REST_Response {
	$request = new WP_REST_Request( 'POST', '/cc/v1/applications/' . $ref . '/payment' );
	$request->set_param( 'ref', $ref );
	return CC_Rest_Admissions::retry_payment( $request );
}

function t_payment_count( int $invoice ): int {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}cc_payments WHERE invoice_id = %d", $invoice ) );
}

function t_code( WP_REST_Response $r ): string {
	return (string) ( $r->get_data()['code'] ?? '' );
}

function t_data( int $batch, string $phone ): array {
	$now = gmdate( 'Y-m-d H:i:s' );
	return array(
		'batch_id' => $batch, 'student_phone' => $phone, 'full_name' => 'ZZ Create', 'gender' => 'o', 'dob' => '2008-01-01',
		'id_doc_type' => 'nid', 'id_doc_enc' => 'x', 'guardian_name' => 'G', 'guardian_phone' => '+8801700000001',
		'institution' => 'I', 'class_level' => '10', 'consent_at' => $now,
	);
}

echo "retry after batch_full settlement\n";
$f = t_fixture( 1, 1, 5000.00 );
CC_Fake_Gateway::set_state( $f['gpid'], 'completed' );
t_assert( 'batch_full' === CC_Settlement::settle( $f['payment'], 'callback' )['result'], 'settlement ends batch_full' );
$r = t_retry( $f['ref'] );
t_assert( 409 === $r->get_status() && 'payment_needs_review' === t_code( $r ), 'retry refused with payment_needs_review' );
t_assert( 1 === t_payment_count( $f['invoice'] ), 'no new payment created' );
t_assert( 'needs_review' === CC_Application_Repository::status_view( $f['ref'] )['payment_status'], 'status exposes generic needs_review' );

echo "retry while payment initiated\n";
$f = t_fixture( 10, 0, 5000.00 );
$r = t_retry( $f['ref'] );
t_assert( 409 === $r->get_status() && 'payment_in_progress' === t_code( $r ), 'young pending payment blocks retry' );
t_assert( 1 === t_payment_count( $f['invoice'] ), 'no new payment while pending' );

$f = t_fixture( 10, 0, 5000.00 );
CC_Fake_Gateway::set_state( $f['gpid'], 'completed' );
$r = t_retry( $f['ref'] );
t_assert( 409 === $r->get_status() && 'already_paid' === t_code( $r ), 'paid-at-gateway payment is settled, retry refused' );
t_assert( 'paid' === t_row( 'cc_invoices', 'status', $f['invoice'] ) && 1 === t_payment_count( $f['invoice'] ), 'invoice paid, still one payment' );

$f = t_fixture( 10, 0, 5000.00 );
CC_Fake_Gateway::set_state( $f['gpid'], 'failed' );
$r = t_retry( $f['ref'] );
t_assert( 200 === $r->get_status() && ! empty( $r->get_data()['redirect_url'] ), 'failed payment allows a new one' );
t_assert( 2 === t_payment_count( $f['invoice'] ), 'second payment created' );

$f = t_fixture( 1, 1, 5000.00, array( 'status' => 'failed' ) );
$r = t_retry( $f['ref'] );
t_assert( 409 === $r->get_status() && 'batch_full' === t_code( $r ), 'retry refused when batch has no seats' );

$r = t_retry( 'ZZZZZZZZZZZZZZZZZZZZZZZZZZ' );
t_assert( 404 === $r->get_status(), 'unknown ref is 404' );

echo "create()\n";
$f = t_fixture( 10, 0, 5000.00 );
$e = CC_Application_Repository::create( t_data( $f['batch'], '+8801700000000' ), wp_generate_uuid4() );
t_assert( is_wp_error( $e ) && 'duplicate_phone' === $e->get_error_code(), 'duplicate phone rejected' );
$ok = CC_Application_Repository::create( t_data( $f['batch'], '+8801700000009' ), wp_generate_uuid4() );
t_assert( is_array( $ok ) && 'unpaid' === $ok['invoice']['status'], 'new phone creates application + invoice' );
if ( is_array( $ok ) ) {
	$cleanup['apps'][] = (int) $ok['id'];
}
t_assert( '' === $wpdb->last_error, 'no DB error left behind' );

$closed = t_fixture( 10, 0, 5000.00 );
$wpdb->update( "{$p}cc_batches", array( 'application_open' => 0 ), array( 'id' => $closed['batch'] ) );
$e = CC_Application_Repository::create( t_data( $closed['batch'], '+8801700000008' ), wp_generate_uuid4() );
t_assert( is_wp_error( $e ) && 'batch_closed' === $e->get_error_code(), 'closed batch rejected under lock' );
$full = t_fixture( 1, 1, 5000.00 );
$e    = CC_Application_Repository::create( t_data( $full['batch'], '+8801700000007' ), wp_generate_uuid4() );
t_assert( is_wp_error( $e ) && 'batch_closed' === $e->get_error_code(), 'full batch rejected under lock' );
$e = CC_Application_Repository::create( t_data( 999999999, '+8801700000006' ), wp_generate_uuid4() );
t_assert( is_wp_error( $e ) && 'batch_not_found' === $e->get_error_code(), 'missing batch rejected' );

echo "photo\n";
$png = static function ( int $w, int $h ): string {
	$img = imagecreatetruecolor( $w, $h );
	ob_start();
	imagepng( $img );
	imagedestroy( $img );
	return (string) ob_get_clean();
};
$e = CC_Photo_Store::store_bytes( $png( 4001, 1 ) );
t_assert( is_wp_error( $e ) && 422 === ( $e->get_error_data()['status'] ?? 0 ), 'image wider than 4000px rejected' );
$e = CC_Photo_Store::store_bytes( $png( 1, 4001 ) );
t_assert( is_wp_error( $e ), 'image taller than 4000px rejected' );
$stored = CC_Photo_Store::store_bytes( $png( 20, 20 ) );
t_assert( is_string( $stored ), 'small image stored' );
if ( is_string( $stored ) ) {
	CC_Photo_Store::delete( $stored );
}

// Cleanup.
foreach ( $cleanup['apps'] as $app ) {
	$inv_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$p}cc_invoices WHERE application_id = %d", $app ) );
	foreach ( $inv_ids as $inv ) {
		$pay_rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, gateway_payment_id FROM {$p}cc_payments WHERE invoice_id = %d", $inv ), ARRAY_A );
		foreach ( $pay_rows as $pay ) {
			CC_Fake_Gateway::delete_state( (string) $pay['gateway_payment_id'] );
			$wpdb->delete( "{$p}cc_payment_events", array( 'payment_id' => $pay['id'] ) );
			$wpdb->delete( "{$p}cc_payments", array( 'id' => $pay['id'] ) );
		}
		$wpdb->delete( "{$p}cc_invoices", array( 'id' => $inv ) );
	}
	$wpdb->delete( "{$p}cc_applications", array( 'id' => $app ) );
}
foreach ( $cleanup['batches'] as $b ) {
	$wpdb->delete( "{$p}cc_batches", array( 'id' => $b ) );
}
foreach ( $cleanup['gpids'] as $g ) {
	CC_Fake_Gateway::delete_state( $g );
}

echo "\n$checks checks, $failures failures\n";
if ( $failures > 0 ) {
	WP_CLI::halt( 1 );
}
