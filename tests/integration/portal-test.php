<?php
/**
 * Integration tests for CC_Portal_Data. Run inside the wpcli container:
 *   docker compose run --rm -T wpcli eval-file /tests/integration/portal-test.php
 * Inserts fixture rows directly and removes them afterwards.
 */
global $wpdb, $failures, $checks, $cleanup;
$p        = $wpdb->prefix;
$failures = 0;
$checks   = 0;
$cleanup  = array( 'batches' => array(), 'apps' => array(), 'users' => array() );

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

function t_user(): int {
	global $cleanup;
	$tag = substr( bin2hex( random_bytes( 5 ) ), 0, 10 );
	$id  = wp_insert_user( array( 'user_login' => "zzportal$tag", 'user_pass' => wp_generate_password(), 'user_email' => "zzportal$tag@students.invalid", 'role' => 'subscriber' ) );
	if ( is_wp_error( $id ) ) {
		throw new RuntimeException( $id->get_error_message() );
	}
	$cleanup['users'][] = $id;
	return (int) $id;
}

function t_batch( string $name ): int {
	global $wpdb, $cleanup;
	$now = gmdate( 'Y-m-d H:i:s' );
	$wpdb->insert( $wpdb->prefix . 'cc_batches', array(
		'course_id' => 0, 'name' => $name, 'capacity' => 10, 'price' => 5000, 'start_date' => '2030-01-01',
		'schedule_text' => 'Sat Mon 5pm', 'status' => 'open', 'application_open' => 1, 'created_at' => $now, 'updated_at' => $now,
	) );
	$cleanup['batches'][] = (int) $wpdb->insert_id;
	return (int) $wpdb->insert_id;
}

/** Application owned by $user with an invoice and one payment; returns [app, payment]. */
function t_paid_app( int $user, int $batch, string $pay_status = 'completed' ): array {
	global $wpdb, $cleanup;
	$p   = $wpdb->prefix;
	$now = gmdate( 'Y-m-d H:i:s' );
	$tag = substr( bin2hex( random_bytes( 6 ) ), 0, 12 );
	$wpdb->insert( "{$p}cc_applications", array(
		'public_ref' => substr( strtoupper( 'ZZPORT' . $tag . '00000000' ), 0, 26 ), 'idempotency_key' => wp_generate_uuid4(), 'batch_id' => $batch,
		'user_id' => $user, 'student_phone' => '+8801700000000', 'full_name' => 'ZZ Portal', 'gender' => 'o', 'dob' => '2008-01-01',
		'id_doc_type' => 'nid', 'id_doc_enc' => 'x', 'guardian_name' => 'G', 'guardian_phone' => '+8801700000001',
		'institution' => 'I', 'class_level' => '10', 'consent_at' => $now, 'status' => 'approved', 'created_at' => $now, 'updated_at' => $now,
	) );
	$app = (int) $wpdb->insert_id;
	$cleanup['apps'][] = $app;
	$wpdb->insert( "{$p}cc_invoices", array( 'application_id' => $app, 'amount' => 5000, 'status' => 'paid', 'number' => "ZZP-$tag", 'created_at' => $now ) );
	$invoice = (int) $wpdb->insert_id;
	$wpdb->insert( "{$p}cc_payments", array(
		'invoice_id' => $invoice, 'gateway' => 'fake', 'method' => 'fake', 'gateway_payment_id' => "zz$tag", 'trx_id' => "TRX$tag",
		'amount' => 5000, 'status' => $pay_status, 'created_at' => $now, 'updated_at' => $now, 'settled_at' => $now,
	) );
	return array( $app, (int) $wpdb->insert_id );
}

try {
	$alice = t_user();
	$bob   = t_user();
	$b1    = t_batch( 'ZZ-PORTAL A' );
	$b2    = t_batch( 'ZZ-PORTAL B' );
	list( $app_a1, $pay_a1 ) = t_paid_app( $alice, $b1 );
	list( , $pay_a2 )        = t_paid_app( $alice, $b2, 'failed' );
	list( , $pay_b )         = t_paid_app( $bob, $b1 );

	echo "payments(): owner only\n";
	$alice_pay = CC_Portal_Data::payments( $alice );
	t_assert( 2 === count( $alice_pay ), 'alice sees exactly her two payments' );
	t_assert( ! in_array( $pay_b, array_map( 'intval', array_column( $alice_pay, 'payment_id' ) ), true ), "alice does not see bob's payment" );
	t_assert( 1 === count( CC_Portal_Data::payments( $bob ) ), 'bob sees one payment' );
	t_assert( array() === CC_Portal_Data::payments( t_user() ), 'user with no applications sees none' );

	echo "receipt(): owner only\n";
	$r = CC_Portal_Data::receipt( $alice, $pay_a1 );
	t_assert( null !== $r && 'ZZ Portal' === $r['student_name'] && 0 === strpos( (string) $r['invoice_number'], 'ZZP-' ), 'owner gets receipt with invoice number and student' );
	t_assert( null !== $r && '' !== (string) $r['trx_id'] && 'ZZ-PORTAL A' === $r['batch_name'], 'receipt has trx id and batch' );
	t_assert( null === CC_Portal_Data::receipt( $alice, $pay_b ), "alice cannot read bob's receipt" );
	t_assert( null === CC_Portal_Data::receipt( $bob, $pay_a1 ), "bob cannot read alice's receipt" );
	t_assert( null === CC_Portal_Data::receipt( $alice, $pay_a2 ), 'non-completed payment has no receipt' );
	t_assert( null === CC_Portal_Data::receipt( $alice, 0 ) && null === CC_Portal_Data::receipt( 0, $pay_a1 ), 'zero ids return null' );
	t_assert( null === CC_Portal_Data::receipt( $alice, 999999999 ), 'unknown payment id returns null' );

	echo "dashboard(): 0 / 1 / 2 enrollments\n";
	$carol = t_user();
	$d     = CC_Portal_Data::dashboard( $carol );
	t_assert( array() === $d['enrollments'], '0 enrollments' );
	t_assert( '' !== $d['schedule']['message'] && array() === $d['schedule']['today'] && is_array( $d['notices']['items'] ), 'unenrolled student gets an empty schedule with a clear message' );
	CC_Enrollment_Repository::create( $alice, $b1, $app_a1 );
	$d = CC_Portal_Data::dashboard( $alice );
	t_assert( 1 === count( $d['enrollments'] ) && 'ZZ-PORTAL A' === $d['enrollments'][0]['batch_name'] && 'Sat Mon 5pm' === $d['enrollments'][0]['schedule_text'], '1 enrollment with batch and schedule' );
	list( $app_a3 ) = t_paid_app( $alice, $b2 );
	CC_Enrollment_Repository::create( $alice, $b2, $app_a3 );
	t_assert( 2 === count( CC_Portal_Data::dashboard( $alice )['enrollments'] ), '2 enrollments' );
	t_assert( 0 === count( CC_Portal_Data::dashboard( $bob )['enrollments'] ), "bob does not see alice's enrollments" );

	echo "dashboard(): deactivated enrollments hide batch details\n";
	$ended_enrollment = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$p}cc_enrollments WHERE application_id = %d", $app_a3 ) );
	$wpdb->update( "{$p}cc_enrollments", array( 'status' => 'deactivated' ), array( 'id' => $ended_enrollment ) );
	$d     = CC_Portal_Data::dashboard( $alice );
	$by_id = array();
	foreach ( $d['enrollments'] as $row ) {
		$by_id[ (int) $row['id'] ] = $row;
	}
	$ended  = $by_id[ $ended_enrollment ];
	$active = array_values( array_filter( $d['enrollments'], static fn( $r ) => (int) $r['id'] !== $ended_enrollment ) )[0];
	t_assert( true === $ended['access_ended'] && CC_Portal_Data::MSG_ACCESS_ENDED === $ended['message'], 'deactivated enrollment flagged with the ended message' );
	t_assert( ! array_key_exists( 'schedule_text', $ended ) && ! array_key_exists( 'delivery_mode', $ended ) && ! array_key_exists( 'batch_name', $ended ) && ! array_key_exists( 'start_date', $ended ), 'deactivated enrollment carries no batch details' );
	t_assert( false === $active['access_ended'] && 'ZZ-PORTAL A' === $active['batch_name'] && 'Sat Mon 5pm' === $active['schedule_text'], 'active enrollment unchanged' );
	$wpdb->update( "{$p}cc_enrollments", array( 'status' => 'expired' ), array( 'id' => $ended_enrollment ) );
	$expired = array_values( array_filter( CC_Portal_Data::dashboard( $alice )['enrollments'], static fn( $r ) => (int) $r['id'] === $ended_enrollment ) );
	t_assert( true === $expired[0]['access_ended'], 'expired enrollment flagged too' );
	$wpdb->update( "{$p}cc_enrollments", array( 'status' => 'deactivated' ), array( 'id' => $ended_enrollment ) );

	echo "update_profile(): validation\n";
	$wpdb->insert( "{$p}cc_students", array( 'user_id' => $alice, 'full_name' => 'Alice', 'must_change_pw' => 0, 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
	$bad = CC_Portal_Data::update_profile( $alice, array( 'guardian_name' => '', 'guardian_phone' => '12345', 'email' => 'nope' ) );
	t_assert( is_wp_error( $bad ) && 3 === count( $bad->get_error_data()['fields'] ), 'bad name, phone, email rejected with per-field details' );
	$ok = CC_Portal_Data::update_profile( $alice, array( 'guardian_name' => '<b>Rahim</b>', 'guardian_phone' => '01712-345678', 'email' => 'zzalice@example.com' ) );
	t_assert( ! is_wp_error( $ok ) && 'Rahim' === $ok['guardian_name'] && '+8801712345678' === $ok['guardian_phone'] && 'zzalice@example.com' === $ok['email'], 'valid update sanitized and normalized' );
	t_assert( 'zzalice@example.com' === get_user_meta( $alice, 'cc_application_email', true ) && str_ends_with( get_userdata( $alice )->user_email, '@students.invalid' ), 'email saved to cc_application_email, core user_email untouched' );
	$admin_email = get_userdata( 1 )->user_email;
	$dup         = CC_Portal_Data::update_profile( $bob, array( 'email' => $admin_email ) );
	t_assert( ! is_wp_error( $dup ) && '' === $dup['email'] && '' === (string) get_user_meta( $bob, 'cc_application_email', true ), 'email registered to another user is silently not saved and not revealed' );
	$own = CC_Portal_Data::update_profile( $alice, array( 'email' => 'zzalice2@example.com' ) );
	t_assert( ! is_wp_error( $own ) && 'zzalice2@example.com' === $own['email'], 'student can change their own application email' );
	$cleared = CC_Portal_Data::update_profile( $alice, array( 'email' => '' ) );
	t_assert( ! is_wp_error( $cleared ) && '' === $cleared['email'] && '' === CC_Portal_Data::profile( $alice )['email'] && '' === (string) get_user_meta( $alice, 'cc_application_email', true ) && str_ends_with( get_userdata( $alice )->user_email, '@students.invalid' ), 'empty email clears cc_application_email and leaves user_email alone' );
} finally {
	foreach ( $cleanup['apps'] as $app ) {
		$inv = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$p}cc_invoices WHERE application_id = %d", $app ) );
		$wpdb->delete( "{$p}cc_payments", array( 'invoice_id' => $inv ) );
		$wpdb->delete( "{$p}cc_invoices", array( 'application_id' => $app ) );
		$wpdb->delete( "{$p}cc_enrollments", array( 'application_id' => $app ) );
		$wpdb->delete( "{$p}cc_applications", array( 'id' => $app ) );
	}
	foreach ( $cleanup['batches'] as $b ) {
		$wpdb->delete( "{$p}cc_batches", array( 'id' => $b ) );
	}
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( $cleanup['users'] as $u ) {
		$wpdb->delete( "{$p}cc_students", array( 'user_id' => $u ) );
		wp_delete_user( $u );
	}
}

echo "\n$checks checks, $failures failures\n";
exit( $failures ? 1 : 0 );
