<?php
/**
 * Integration tests for CC_Settlement / CC_Reconciler. Run inside the wpcli container:
 *   docker compose run --rm -T wpcli eval-file /tests/integration/settlement-test.php
 * Inserts fixture rows directly and removes them afterwards.
 */
global $wpdb, $failures, $checks, $cleanup;
$p        = $wpdb->prefix;
$failures = 0;
$checks   = 0;
$cleanup  = array( 'batches' => array(), 'apps' => array(), 'gpids' => array() );
$settled_calls = array();
add_action(
	'cc_application_settled',
	static function ( $app_id, $payment_id ) use ( &$settled_calls ) {
		$settled_calls[] = array( $app_id, $payment_id );
	},
	10,
	2
);

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

echo "settle(): happy path\n";
$f = t_fixture( 10, 3, 5000.00 );
CC_Fake_Gateway::set_state( $f['gpid'], 'completed' );
$r = CC_Settlement::settle( $f['payment'], 'callback' );
t_assert( 'settled' === $r['result'] && $r['application_ref'] === $f['ref'], 'result settled with application_ref' );
t_assert( 'completed' === t_row( 'cc_payments', 'status', $f['payment'] ), 'payment completed' );
t_assert( '' !== (string) t_row( 'cc_payments', 'trx_id', $f['payment'] ) && null !== t_row( 'cc_payments', 'settled_at', $f['payment'] ), 'trx_id and settled_at set' );
t_assert( 'paid' === t_row( 'cc_invoices', 'status', $f['invoice'] ), 'invoice paid' );
t_assert( 'approved' === t_row( 'cc_applications', 'status', $f['app'] ), 'application approved' );
t_assert( '4' === t_row( 'cc_batches', 'seats_taken', $f['batch'] ), 'seats_taken 3 -> 4' );
t_assert( 1 === count( $settled_calls ), 'hook fired once' );

echo "settle(): double settle is idempotent\n";
$r2 = CC_Settlement::settle( $f['payment'], 'callback' );
$r3 = CC_Settlement::settle( $f['payment'], 'poll' );
t_assert( 'already_settled' === $r2['result'] && 'already_settled' === $r3['result'], 'second and third calls already_settled' );
t_assert( '4' === t_row( 'cc_batches', 'seats_taken', $f['batch'] ), 'seats_taken still 4' );
t_assert( 1 === count( $settled_calls ), 'hook still fired exactly once' );

echo "settle(): amount mismatch\n";
$settled_calls = array();
$f = t_fixture( 10, 0, 5000.00 );
CC_Fake_Gateway::set_state( $f['gpid'], 'completed', 1.00 );
$r = CC_Settlement::settle( $f['payment'], 'callback' );
t_assert( 'mismatch' === $r['result'], 'result mismatch' );
t_assert( 'reconcile_needed' === t_row( 'cc_payments', 'status', $f['payment'] ), 'payment reconcile_needed' );
t_assert( 'unpaid' === t_row( 'cc_invoices', 'status', $f['invoice'] ) && 'pending' === t_row( 'cc_applications', 'status', $f['app'] ), 'invoice unpaid, application pending' );
t_assert( '0' === t_row( 'cc_batches', 'seats_taken', $f['batch'] ) && 0 === count( $settled_calls ), 'no seat taken, no hook' );
t_assert( 'mismatch' === CC_Settlement::settle( $f['payment'], 'poll' )['result'], 'repeat call still reports mismatch' );

echo "settle(): batch full\n";
$f = t_fixture( 2, 2, 5000.00 );
CC_Fake_Gateway::set_state( $f['gpid'], 'completed' );
$r = CC_Settlement::settle( $f['payment'], 'callback' );
t_assert( 'batch_full' === $r['result'], 'result batch_full' );
t_assert( 'reconcile_needed' === t_row( 'cc_payments', 'status', $f['payment'] ), 'payment reconcile_needed' );
t_assert( 'pending' === t_row( 'cc_applications', 'status', $f['app'] ) && '2' === t_row( 'cc_batches', 'seats_taken', $f['batch'] ), 'application pending, seats unchanged' );
t_assert( 0 === count( $settled_calls ), 'no hook' );

echo "settle(): not completed at gateway\n";
$f = t_fixture( 5, 0, 5000.00 );
$r = CC_Settlement::settle( $f['payment'], 'callback' );
t_assert( 'not_completed' === $r['result'] && 'executing' === t_row( 'cc_payments', 'status', $f['payment'] ), 'pending -> not_completed, payment executing' );
CC_Fake_Gateway::set_state( $f['gpid'], 'failed' );
CC_Settlement::settle( $f['payment'], 'callback' );
t_assert( 'failed' === t_row( 'cc_payments', 'status', $f['payment'] ), 'gateway failed -> payment failed' );

echo "settle(): second payment on an already-paid invoice\n";
$f  = t_fixture( 10, 0, 5000.00 );
CC_Fake_Gateway::set_state( $f['gpid'], 'completed' );
CC_Settlement::settle( $f['payment'], 'callback' );
$now = gmdate( 'Y-m-d H:i:s' );
list( $gpid2 ) = ( new CC_Fake_Gateway() )->create_payment( array( 'amount' => 5000.00 ), 'http://x' );
$wpdb->insert( "{$p}cc_payments", array( 'invoice_id' => $f['invoice'], 'gateway' => 'fake', 'method' => 'fake', 'gateway_payment_id' => $gpid2, 'amount' => 5000.00, 'status' => 'initiated', 'created_at' => $now, 'updated_at' => $now ) );
$pay2 = (int) $wpdb->insert_id;
$cleanup['gpids'][] = $gpid2;
CC_Fake_Gateway::set_state( $gpid2, 'completed' );
$r = CC_Settlement::settle( $pay2, 'callback' );
t_assert( 'mismatch' === $r['result'] && '1' === t_row( 'cc_batches', 'seats_taken', $f['batch'] ), 'duplicate payment flagged, no extra seat' );

echo "reconciler\n";
$old = gmdate( 'Y-m-d H:i:s', time() - 600 );
$f   = t_fixture( 10, 0, 5000.00, array( 'status' => 'executing', 'created_at' => $old, 'updated_at' => $old ) );
CC_Fake_Gateway::set_state( $f['gpid'], 'completed' );
$settled_calls = array();
$n = CC_Reconciler::run();
t_assert( $n >= 1, 'run() processed at least one payment' );
t_assert( 'completed' === t_row( 'cc_payments', 'status', $f['payment'] ) && 'approved' === t_row( 'cc_applications', 'status', $f['app'] ), 'abandoned paid payment settled by poll' );
t_assert( 1 === count( $settled_calls ) && '1' === t_row( 'cc_batches', 'seats_taken', $f['batch'] ), 'hook once, seat taken once' );

$very_old = gmdate( 'Y-m-d H:i:s', time() - 3600 );
$f        = t_fixture( 10, 0, 5000.00, array( 'status' => 'initiated', 'created_at' => $very_old, 'updated_at' => $very_old ) );
CC_Reconciler::run();
t_assert( 'cancelled' === t_row( 'cc_payments', 'status', $f['payment'] ), 'unpaid payment older than 30 min cancelled' );

$f = t_fixture( 10, 0, 5000.00 );
CC_Reconciler::run();
t_assert( 'initiated' === t_row( 'cc_payments', 'status', $f['payment'] ), 'fresh payment left alone' );

$f = t_fixture( 10, 0, 5000.00, array( 'status' => 'initiated', 'created_at' => $very_old, 'updated_at' => $very_old ) );
CC_Fake_Gateway::set_state( $f['gpid'], 'completed' );
CC_Reconciler::run();
t_assert( 'completed' === t_row( 'cc_payments', 'status', $f['payment'] ), 'late-paid payment older than 30 min is settled, not cancelled' );

echo "reconciler: late payment on cancelled payments\n";
$hours = static fn( float $h ): string => gmdate( 'Y-m-d H:i:s', time() - (int) ( $h * 3600 ) );
$f_in  = t_fixture( 10, 0, 5000.00, array( 'status' => 'cancelled', 'created_at' => $hours( 2 ), 'updated_at' => $hours( 1 ) ) );
$f_out = t_fixture( 10, 0, 5000.00, array( 'status' => 'cancelled', 'created_at' => $hours( 25 ), 'updated_at' => $hours( 1 ) ) );
$f_new = t_fixture( 10, 0, 5000.00, array( 'status' => 'cancelled', 'created_at' => $hours( 2 ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ) );
$f_unpd = t_fixture( 10, 0, 5000.00, array( 'status' => 'cancelled', 'created_at' => $hours( 2 ), 'updated_at' => $hours( 1 ) ) );
foreach ( array( $f_in, $f_out, $f_new ) as $late ) {
	CC_Fake_Gateway::set_state( $late['gpid'], 'completed' );
}
CC_Reconciler::run();
t_assert( 'completed' === t_row( 'cc_payments', 'status', $f_in['payment'] ) && 'approved' === t_row( 'cc_applications', 'status', $f_in['app'] ), 'late completion inside window settles' );
t_assert( '1' === t_row( 'cc_batches', 'seats_taken', $f_in['batch'] ), 'late settle takes exactly one seat' );
t_assert( 'cancelled' === t_row( 'cc_payments', 'status', $f_out['payment'] ) && 'pending' === t_row( 'cc_applications', 'status', $f_out['app'] ), 'late completion outside window is ignored' );
t_assert( 'cancelled' === t_row( 'cc_payments', 'status', $f_new['payment'] ), 'recently checked cancelled payment is not re-polled yet' );
t_assert( 'cancelled' === t_row( 'cc_payments', 'status', $f_unpd['payment'] ) && strtotime( t_row( 'cc_payments', 'updated_at', $f_unpd['payment'] ) . ' UTC' ) > time() - 60, 'unpaid cancelled payment stays cancelled and updated_at is touched' );
$settled_calls = array();
CC_Reconciler::run();
t_assert( 'completed' === t_row( 'cc_payments', 'status', $f_in['payment'] ) && 0 === count( $settled_calls ), 'settled late payment is not processed again' );

echo "gateway configuration\n";
$env_before = getenv( 'CC_PAYMENT_GATEWAY' );
if ( defined( 'CC_PAYMENT_GATEWAY' ) ) {
	echo "  skip factory-unset test (CC_PAYMENT_GATEWAY constant is defined)\n";
} else {
	putenv( 'CC_PAYMENT_GATEWAY' );
	$threw = false;
	try {
		CC_Gateway_Factory::make();
	} catch ( RuntimeException $e ) {
		$threw = false !== strpos( $e->getMessage(), 'CC_PAYMENT_GATEWAY is not configured' );
	}
	t_assert( $threw, 'factory throws when CC_PAYMENT_GATEWAY is unset' );
	$threw = false;
	try {
		new CC_Fake_Gateway();
	} catch ( RuntimeException $e ) {
		$threw = true;
	}
	t_assert( $threw && ! CC_Fake_Gateway::is_allowed(), 'fake gateway refuses without explicit CC_PAYMENT_GATEWAY=fake' );
	if ( false !== $env_before ) {
		putenv( 'CC_PAYMENT_GATEWAY=' . $env_before );
	}
}
if ( 'fake' === CC_Gateway_Factory::configured_name() ) {
	foreach ( array( 'staging', 'production' ) as $env_type ) {
		CC_Fake_Gateway::override_environment_type( $env_type );
		$threw = false;
		try {
			CC_Gateway_Factory::make();
		} catch ( RuntimeException $e ) {
			$threw = true;
		}
		t_assert( $threw && ! CC_Fake_Gateway::is_allowed(), "fake gateway refuses on '$env_type'" );
	}
	CC_Fake_Gateway::override_environment_type( 'development' );
	t_assert( CC_Fake_Gateway::is_allowed() && 'fake' === CC_Gateway_Factory::make()->id(), 'fake gateway allowed on development' );
	CC_Fake_Gateway::override_environment_type( null );
	t_assert( CC_Fake_Gateway::is_allowed(), 'fake gateway allowed on this local environment' );
} else {
	t_assert( false, 'CC_PAYMENT_GATEWAY must be "fake" for these tests (docker-compose x-wp-env)' );
}

// Cleanup.
foreach ( $cleanup['apps'] as $app ) {
	$inv_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$p}cc_invoices WHERE application_id = %d", $app ) );
	foreach ( $inv_ids as $inv ) {
		$pay_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$p}cc_payments WHERE invoice_id = %d", $inv ) );
		foreach ( $pay_ids as $pay ) {
			$wpdb->delete( "{$p}cc_payment_events", array( 'payment_id' => $pay ) );
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
	$leftover = 0;
	foreach ( $cleanup['apps'] as $app ) {
		for ( $attempt = 1; $attempt <= CC_Provisioner::MAX_ATTEMPTS; $attempt++ ) {
			$leftover += count( as_get_scheduled_actions( array( 'hook' => CC_Provisioner::HOOK, 'args' => array( $app, $attempt ), 'status' => ActionScheduler_Store::STATUS_PENDING, 'per_page' => 1 ), 'ids' ) );
		}
	}
	t_assert( 0 === $leftover, 'no pending cc_provision_student actions left for this run' );
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
