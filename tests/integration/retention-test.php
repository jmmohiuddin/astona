<?php
/**
 * Integration tests for CC_Retention (dry run vs apply, what is kept, what is scrubbed).
 *   docker compose run --rm -T wpcli eval-file /tests/integration/retention-test.php
 */
global $wpdb, $failures, $checks;
$p        = $wpdb->prefix;
$failures = 0;
$checks   = 0;
function t_assert( bool $cond, string $label ): void {
	global $failures, $checks;
	++$checks;
	echo ( $cond ? '  ok   ' : '  FAIL ' ) . "$label\n";
	if ( ! $cond ) {
		++$failures;
	}
}
$tag  = bin2hex( random_bytes( 3 ) );
$now  = gmdate( 'Y-m-d H:i:s' );
$ago  = static fn( int $days ): string => gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
$made = array( 'apps' => array(), 'batches' => array(), 'users' => array() );

$batch = static function ( string $end ) use ( $wpdb, $p, $now, &$made, $tag ): int {
	$wpdb->insert( "{$p}cc_batches", array( 'course_id' => 0, 'name' => "ZZ-RET $tag", 'capacity' => 10, 'seats_taken' => 0, 'price' => 100, 'start_date' => '2020-01-01', 'end_date' => $end, 'status' => 'open', 'application_open' => 0, 'created_at' => $now, 'updated_at' => $now ) );
	return $made['batches'][] = (int) $wpdb->insert_id;
};
$app = static function ( int $batch_id, string $status, string $updated, ?int $user = null ) use ( $wpdb, $p, &$made ): int {
	$wpdb->insert( "{$p}cc_applications", array(
		'public_ref' => substr( strtoupper( 'ZZRET' . bin2hex( random_bytes( 11 ) ) ), 0, 26 ), 'idempotency_key' => wp_generate_uuid4(), 'batch_id' => $batch_id, 'user_id' => $user,
		'student_phone' => '+88017' . random_int( 10000000, 99999999 ), 'full_name' => 'ZZ Person', 'gender' => 'o', 'dob' => '2008-01-01', 'id_doc_type' => 'nid', 'id_doc_enc' => 'secret',
		'guardian_name' => 'Guardian', 'guardian_phone' => '+8801700000001', 'email' => 'a@b.test', 'institution' => 'School', 'class_level' => '10', 'consent_at' => $updated,
		'status' => $status, 'created_at' => $updated, 'updated_at' => $updated,
	) );
	return $made['apps'][] = (int) $wpdb->insert_id;
};
$invoice = static function ( int $app_id, string $settled ) use ( $wpdb, $p ): int {
	$wpdb->insert( "{$p}cc_invoices", array( 'application_id' => $app_id, 'amount' => 100, 'number' => 'ZZR-' . bin2hex( random_bytes( 5 ) ), 'status' => 'paid', 'created_at' => $settled ) );
	$inv = (int) $wpdb->insert_id;
	$wpdb->insert( "{$p}cc_payments", array( 'invoice_id' => $inv, 'gateway' => 'fake', 'method' => 'fake', 'gateway_payment_id' => 'ZZR' . bin2hex( random_bytes( 6 ) ), 'amount' => 100, 'status' => 'completed', 'created_at' => $settled, 'updated_at' => $settled, 'settled_at' => $settled ) );
	return $inv;
};
$student = static function () use ( &$made ): int {
	$id = wp_insert_user( array( 'user_login' => '88016' . random_int( 10000000, 99999999 ), 'user_pass' => wp_generate_password(), 'user_email' => bin2hex( random_bytes( 4 ) ) . '@students.invalid', 'role' => 'cc_student' ) );
	return $made['users'][] = (int) $id;
};
$enroll = static function ( int $user, int $batch_id, int $app_id, string $status ) use ( $wpdb, $p ): void {
	$wpdb->insert( "{$p}cc_enrollments", array( 'user_id' => $user, 'batch_id' => $batch_id, 'application_id' => $app_id, 'status' => $status, 'enrolled_at' => '2020-01-05 00:00:00' ) );
	$wpdb->replace( "{$p}cc_students", array( 'user_id' => $user, 'full_name' => 'ZZ Person', 'created_at' => '2020-01-05 00:00:00' ) );
};

$old_batch    = $batch( gmdate( 'Y-m-d', time() - 5 * 365 * DAY_IN_SECONDS ) );
$recent_batch = $batch( gmdate( 'Y-m-d', time() - 30 * DAY_IN_SECONDS ) );

$rej_old    = $app( $recent_batch, 'rejected', $ago( 400 ) );
$rej_new    = $app( $recent_batch, 'rejected', $ago( 100 ) );
$canc_old   = $app( $recent_batch, 'cancelled', $ago( 500 ) );
$pend_old   = $app( $recent_batch, 'pending', $ago( 900 ) );

$stu_old    = $student();
$app_old    = $app( $old_batch, 'approved', $ago( 2000 ), $stu_old );
$enroll( $stu_old, $old_batch, $app_old, 'expired' );
$inv_old    = $invoice( $app_old, $ago( 2000 ) );

$stu_mixed  = $student();
$app_m1     = $app( $old_batch, 'approved', $ago( 2000 ), $stu_mixed );
$app_m2     = $app( $recent_batch, 'approved', $ago( 20 ), $stu_mixed );
$enroll( $stu_mixed, $old_batch, $app_m1, 'expired' );
$enroll( $stu_mixed, $recent_batch, $app_m2, 'active' );

$stu_active = $student();
$app_a      = $app( $old_batch, 'approved', $ago( 2000 ), $stu_active );
$enroll( $stu_active, $old_batch, $app_a, 'active' );

$not_student = wp_insert_user( array( 'user_login' => 'zzret' . $tag, 'user_pass' => wp_generate_password(), 'user_email' => 'zzret' . $tag . '@example.test', 'role' => 'subscriber' ) );
$made['users'][] = (int) $not_student;
$app_ns = $app( $old_batch, 'approved', $ago( 2000 ), (int) $not_student );
$enroll( (int) $not_student, $old_batch, $app_ns, 'expired' );

$rej_fin = $app( $recent_batch, 'cancelled', $ago( 3000 ) );
$inv_fin = $invoice( $rej_fin, $ago( 3000 ) );
$inv_recent_pay = $invoice( $rej_new, $ago( 10 ) );

$wpdb->insert( "{$p}cc_sms_log", array( 'to_phone' => '+8801700000009', 'template' => 'notice', 'body_hash' => str_repeat( 'a', 64 ), 'segments' => 1, 'status' => 'sent', 'created_at' => $ago( 400 ) ) );
$old_sms = (int) $wpdb->insert_id;
$wpdb->insert( "{$p}cc_sms_log", array( 'to_phone' => '+8801700000009', 'template' => 'notice', 'body_hash' => str_repeat( 'b', 64 ), 'segments' => 1, 'status' => 'sent', 'created_at' => $ago( 5 ) ) );
$new_sms = (int) $wpdb->insert_id;

$scrubbed = static fn( int $id ): bool => 'Removed' === $wpdb->get_var( "SELECT full_name FROM {$p}cc_applications WHERE id = $id" ) && '' === (string) $wpdb->get_var( "SELECT id_doc_enc FROM {$p}cc_applications WHERE id = $id" );
$exists   = static fn( string $t, string $col, int $id ): bool => null !== $wpdb->get_var( "SELECT 1 FROM {$p}{$t} WHERE {$col} = $id" );

echo "dry run changes nothing\n";
$dry = CC_Retention::run( false );
t_assert( $dry['applications'] >= 3 && $dry['students'] >= 1 && $dry['financial'] >= 1 && $dry['logs'] >= 1, 'it counts what would go' );
t_assert( ! $scrubbed( $rej_old ) && $exists( 'cc_students', 'user_id', $stu_old ) && $exists( 'cc_sms_log', 'id', $old_sms ), 'but nothing was touched' );
t_assert( ! CC_Retention::enabled(), 'automatic deletion is off by default' );
delete_option( CC_Retention::OPTION_LAST );
CC_Retention::daily();
t_assert( empty( get_option( CC_Retention::OPTION_LAST )['applied'] ) && ! $scrubbed( $rej_old ), 'the daily job with the switch off is a dry run and records the counts' );

echo "apply\n";
CC_Retention::run( true );
t_assert( $scrubbed( $rej_old ) && $scrubbed( $canc_old ), 'rejected and cancelled applications past 12 months are scrubbed' );
t_assert( 'removed' === strtolower( (string) $wpdb->get_var( "SELECT guardian_name FROM {$p}cc_applications WHERE id = $rej_old" ) ) && null === $wpdb->get_var( "SELECT email FROM {$p}cc_applications WHERE id = $rej_old" ), 'guardian and email gone too' );
t_assert( ! $scrubbed( $rej_new ), 'a rejection from 100 days ago is kept' );
t_assert( ! $scrubbed( $pend_old ), 'a pending application is never scrubbed by age' );
t_assert( false === get_userdata( $stu_old ) && ! $exists( 'cc_students', 'user_id', $stu_old ) && ! $exists( 'cc_enrollments', 'user_id', $stu_old ), 'a student whose last batch ended over 3 years ago is erased' );
t_assert( $scrubbed( $app_old ) && null === $wpdb->get_var( "SELECT user_id FROM {$p}cc_applications WHERE id = $app_old" ), 'their application is scrubbed and detached' );
t_assert( $exists( 'cc_invoices', 'id', $inv_old ), 'their payment record stays (it holds no personal details and is under 7 years old)' );
t_assert( false !== get_userdata( $stu_mixed ), 'a student with a recent active batch is kept' );
t_assert( false !== get_userdata( $stu_active ), 'a student with an active enrollment is kept' );
t_assert( false !== get_userdata( (int) $not_student ), 'an account that is not a student is never deleted' );
t_assert( ! $exists( 'cc_sms_log', 'id', $old_sms ) && $exists( 'cc_sms_log', 'id', $new_sms ), 'old SMS log rows go, recent ones stay' );
t_assert( ! $exists( 'cc_invoices', 'id', $inv_fin ) && ! $exists( 'cc_applications', 'id', $rej_fin ), 'payment records settled over 7 years ago are deleted with their scrubbed application' );
t_assert( $exists( 'cc_invoices', 'id', $inv_recent_pay ), 'recent payment records stay' );
t_assert( 1 <= (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}cc_audit_log WHERE action = 'retention.run'" ), 'the run is audit-logged' );
$again = CC_Retention::run( true );
t_assert( 0 === $again['applications'] && 0 === $again['students'], 'running again finds nothing new' );

// Cleanup.
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ( $made['users'] as $u ) {
	wp_delete_user( $u );
}
$ids = implode( ',', array_map( 'intval', $made['apps'] ) ) ?: '0';
$wpdb->query( "DELETE FROM {$p}cc_payments WHERE invoice_id IN ( SELECT id FROM {$p}cc_invoices WHERE application_id IN ($ids) )" );
$wpdb->query( "DELETE FROM {$p}cc_invoices WHERE application_id IN ($ids)" );
$wpdb->query( "DELETE FROM {$p}cc_enrollments WHERE application_id IN ($ids)" );
$wpdb->query( "DELETE FROM {$p}cc_applications WHERE id IN ($ids)" );
$wpdb->query( "DELETE FROM {$p}cc_students WHERE full_name = 'ZZ Person'" );
foreach ( $made['batches'] as $b ) {
	$wpdb->delete( "{$p}cc_batches", array( 'id' => $b ) );
}
$wpdb->query( "DELETE FROM {$p}cc_sms_log WHERE to_phone = '+8801700000009'" );
$wpdb->query( "DELETE FROM {$p}cc_audit_log WHERE action = 'retention.run'" );
delete_option( CC_Retention::OPTION_LAST );
echo "\n$checks checks, $failures failures\n";
exit( $failures ? 1 : 0 );
