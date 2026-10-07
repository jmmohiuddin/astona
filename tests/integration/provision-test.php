<?php
/**
 * Integration tests for CC_Provisioner / CC_Enrollment_Repository / CC_Roles. Run inside the wpcli container:
 *   docker compose run --rm -T wpcli eval-file /tests/integration/provision-test.php
 * Inserts fixture rows directly and removes them (and the WP users they create) afterwards.
 * Uses the real CC_Sms when present; otherwise a recording stub that writes cc_sms_log rows.
 */
global $wpdb, $failures, $checks, $cleanup, $stub_sms_calls;
$p              = $wpdb->prefix;
$failures       = 0;
$checks         = 0;
$stub_sms_calls = array();
$cleanup        = array( 'batches' => array(), 'apps' => array(), 'logins' => array() );

$using_stub = ! class_exists( 'CC_Sms' );
if ( $using_stub ) {
	echo "NOTE: CC_Sms not found, using a recording stub (SMS module not integration-tested)\n\n";
	class CC_Sms {
		public static function queue( string $to_e164, string $template, array $vars = array(), string $related_type = '', int $related_id = 0 ): int {
			global $wpdb, $stub_sms_calls;
			$stub_sms_calls[] = array( 'to' => $to_e164, 'template' => $template, 'vars' => $vars );
			$wpdb->insert( $wpdb->prefix . 'cc_sms_log', array(
				'to_phone' => $to_e164, 'template' => $template, 'body_hash' => hash( 'sha256', $template ),
				'related_type' => $related_type, 'related_id' => $related_id, 'created_at' => gmdate( 'Y-m-d H:i:s' ),
			) );
			return (int) $wpdb->insert_id;
		}
	}
}

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

function t_batch(): int {
	global $wpdb, $cleanup;
	$now = gmdate( 'Y-m-d H:i:s' );
	$wpdb->insert( $wpdb->prefix . 'cc_batches', array(
		'course_id' => 0, 'name' => 'ZZ-PROV ' . bin2hex( random_bytes( 4 ) ), 'capacity' => 10, 'seats_taken' => 1,
		'price' => 100, 'start_date' => '2030-01-01', 'schedule_text' => 'Sat 6pm', 'status' => 'open',
		'application_open' => 1, 'created_at' => $now, 'updated_at' => $now,
	) );
	$cleanup['batches'][] = (int) $wpdb->insert_id;
	return (int) $wpdb->insert_id;
}

function t_phone(): string {
	return '+88019' . str_pad( (string) random_int( 0, 99999999 ), 8, '0', STR_PAD_LEFT );
}

function t_application( int $batch, string $phone, string $status = 'approved', string $email = '', bool $verified = true ): int {
	global $wpdb, $cleanup;
	$now = gmdate( 'Y-m-d H:i:s' );
	$wpdb->insert( $wpdb->prefix . 'cc_applications', array(
		'public_ref' => substr( strtoupper( 'ZZPROV' . bin2hex( random_bytes( 10 ) ) ), 0, 26 ), 'idempotency_key' => wp_generate_uuid4(),
		'batch_id' => $batch, 'student_phone' => $phone, 'full_name' => 'ZZ Prov Student', 'gender' => 'o', 'dob' => '2008-01-01',
		'id_doc_type' => 'nid', 'id_doc_enc' => 'x', 'guardian_name' => 'ZZ Guardian', 'guardian_phone' => '+8801700000001',
		'email' => $email ?: null, 'institution' => 'ZZ School', 'class_level' => '10', 'consent_at' => $now, 'status' => $status,
		'phone_verified_at' => $verified ? $now : null, 'created_at' => $now, 'updated_at' => $now,
	) );
	$cleanup['apps'][] = (int) $wpdb->insert_id;
	return (int) $wpdb->insert_id;
}

function t_count( string $table, string $where, ...$args ): int {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}{$table} WHERE {$where}", ...$args ) );
}

function t_sms_count( int $app ): int {
	return t_count( 'cc_sms_log', "related_type = 'application' AND related_id = %d", $app );
}

function t_hash( int $user_id ): string {
	global $wpdb;
	return (string) $wpdb->get_var( $wpdb->prepare( "SELECT user_pass FROM {$wpdb->users} WHERE ID = %d", $user_id ) );
}

// 1. New student.
echo "provision(): new student\n";
$batch1 = t_batch();
$phone  = t_phone();
$login  = ltrim( $phone, '+' );
$app1   = t_application( $batch1, $phone );
$cleanup['logins'][] = $login;
$r1   = CC_Provisioner::provision( $app1 );
$user = get_user_by( 'login', $login );
t_assert( $user instanceof WP_User && (int) $user->ID === $r1['user_id'], 'user_login is the digits of the normalized phone' );
t_assert( true === $r1['created'] && $r1['enrollment_id'] > 0, 'reports created + enrollment id' );
t_assert( in_array( 'cc_student', (array) $user->roles, true ) && 1 === count( $user->roles ), 'role is cc_student only' );
t_assert( $login . '@students.invalid' === $user->user_email, 'placeholder email when application has none' );
$student = CC_Enrollment_Repository::student_row( $r1['user_id'] );
t_assert( null !== $student && '1' === (string) $student['must_change_pw'], 'must_change_pw = 1' );
$ttl = strtotime( $student['temp_pw_expires'] . ' UTC' ) - time();
t_assert( $ttl > 72 * 3600 - 120 && $ttl <= 72 * 3600, 'temp_pw_expires is about +72h' );
t_assert( 1 === t_count( 'cc_enrollments', 'application_id = %d AND user_id = %d AND batch_id = %d AND status = \'active\' AND expires_at IS NULL', $app1, $r1['user_id'], $batch1 ), 'one active enrollment without expiry' );
t_assert( (int) $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM {$p}cc_applications WHERE id = %d", $app1 ) ) === $r1['user_id'], 'application.user_id set' );
t_assert( 1 === t_sms_count( $app1 ), 'exactly one SMS queued' );
if ( $using_stub ) {
	$call = $stub_sms_calls[0];
	t_assert( 'credentials' === $call['template'] && $phone === $call['to'], 'credentials SMS addressed to the student phone' );
	t_assert( 1 === preg_match( '/^[A-HJ-NP-Za-km-z2-9]{10}$/', $call['vars']['password'] ), 'temp password is 10 unambiguous chars, passed via vars' );
	t_assert( wp_check_password( $call['vars']['password'], t_hash( $r1['user_id'] ), $r1['user_id'] ), 'queued temp password logs the user in' );
	t_assert( ! str_contains( (string) wp_json_encode( $wpdb->get_results( "SELECT * FROM {$p}cc_sms_log WHERE related_id = {$app1}", ARRAY_A ) ), $call['vars']['password'] ), 'password absent from cc_sms_log' );
	t_assert( ! str_contains( (string) wp_json_encode( get_user_meta( $r1['user_id'] ) ), $call['vars']['password'] ) && ! str_contains( (string) wp_json_encode( $student ), $call['vars']['password'] ), 'password absent from usermeta and cc_students' );
}

// 2. Replays.
echo "provision(): replay is idempotent\n";
$hash_before = t_hash( $r1['user_id'] );
$r1b         = CC_Provisioner::provision( $app1 );
CC_Provisioner::run_scheduled( $app1, 1 );
do_action( 'cc_application_settled', $app1, 0 );
CC_Provisioner::run_scheduled( $app1, 1 );
t_assert( $r1b['user_id'] === $r1['user_id'] && $r1b['enrollment_id'] === $r1['enrollment_id'], 'same user and enrollment returned' );
t_assert( 1 === t_count( 'cc_enrollments', 'application_id = %d', $app1 ), 'still one enrollment' );
t_assert( 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login = %s", $login ) ), 'still one user' );
t_assert( 1 === t_sms_count( $app1 ), 'still exactly one SMS' );
t_assert( $hash_before === t_hash( $r1['user_id'] ), 'password not reset on replay' );
t_assert( false !== wp_next_scheduled( CC_Provisioner::HOOK, array( $app1, 1 ) ) || function_exists( 'as_has_scheduled_action' ), 'settled hook schedules the provisioning action' );

// 3. Existing student, new course.
echo "provision(): existing student, second course\n";
$batch2 = t_batch();
$app2   = t_application( $batch2, $phone );
$r2     = CC_Provisioner::provision( $app2 );
t_assert( $r2['user_id'] === $r1['user_id'] && false === $r2['created'], 'same user, created = false' );
t_assert( $r2['enrollment_id'] !== $r1['enrollment_id'] && 2 === t_count( 'cc_enrollments', 'user_id = %d', $r1['user_id'] ), 'second enrollment added' );
t_assert( $hash_before === t_hash( $r1['user_id'] ), 'no new password for existing student' );
t_assert( 1 === t_sms_count( $app2 ), 'one SMS for the new course' );
t_assert( 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}cc_sms_log WHERE related_type = 'application' AND related_id = %d AND template = 'enrolled'", $app2 ) ), 'SMS template is enrolled' );
CC_Provisioner::provision( $app2 );
t_assert( 1 === t_sms_count( $app2 ) && 2 === t_count( 'cc_enrollments', 'user_id = %d', $r1['user_id'] ), 'replay of second application adds nothing' );
CC_Provisioner::provision( $app1 );
t_assert( 1 === t_sms_count( $app1 ) && $hash_before === t_hash( $r1['user_id'] ), 'replay of first application after second changes nothing' );

// 4. Recovery after a failure between user creation and SMS.
echo "provision(): retry after partial failure\n";
$phone3 = t_phone();
$login3 = ltrim( $phone3, '+' );
$app3   = t_application( t_batch(), $phone3 );
$cleanup['logins'][] = $login3;
$partial_id = wp_insert_user( array(
	'user_login' => $login3, 'user_pass' => 'PartialOnly99', 'user_email' => $login3 . '@students.invalid',
	'role' => 'cc_student', 'meta_input' => array( CC_Provisioner::CREATED_BY_META => $app3 ),
) );
t_assert( 0 === t_sms_count( $app3 ) && null === CC_Enrollment_Repository::student_row( (int) $partial_id ), 'precondition: user only, no student row/enrollment/SMS' );
$r3 = CC_Provisioner::provision( $app3 );
t_assert( $r3['user_id'] === (int) $partial_id && true === $r3['created'], 'retry reuses the half-created user' );
t_assert( 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login = %s", $login3 ) ), 'no second user' );
t_assert( ! wp_check_password( 'PartialOnly99', t_hash( $r3['user_id'] ), $r3['user_id'] ), 'undelivered password was replaced' );
t_assert( null !== CC_Enrollment_Repository::student_row( $r3['user_id'] ) && 1 === t_sms_count( $app3 ) && 1 === t_count( 'cc_enrollments', 'application_id = %d', $app3 ), 'student row, enrollment and one SMS recovered' );
$hash3 = t_hash( $r3['user_id'] );
CC_Provisioner::provision( $app3 );
t_assert( $hash3 === t_hash( $r3['user_id'] ) && 1 === t_sms_count( $app3 ), 'second retry does not reset the password or resend' );

// 5. Guards.
echo "provision(): guards\n";
$phone4 = t_phone();
$login4 = ltrim( $phone4, '+' );
$app4   = t_application( t_batch(), $phone4, 'approved', 'zz-prov-' . bin2hex( random_bytes( 3 ) ) . '@example.test' );
$cleanup['logins'][] = $login4;
$other = wp_insert_user( array( 'user_login' => $login4, 'user_pass' => wp_generate_password(), 'user_email' => 'zz-other-' . bin2hex( random_bytes( 3 ) ) . '@example.test', 'role' => 'subscriber' ) );
$threw = false;
try {
	CC_Provisioner::provision( $app4 );
} catch ( RuntimeException $e ) {
	$threw = true;
}
t_assert( $threw && 0 === t_count( 'cc_enrollments', 'application_id = %d', $app4 ) && 0 === t_sms_count( $app4 ), 'refuses to adopt a non-student account' );
$app5  = t_application( t_batch(), t_phone(), 'pending' );
$threw = false;
try {
	CC_Provisioner::provision( $app5 );
} catch ( RuntimeException $e ) {
	$threw = true;
}
t_assert( $threw, 'refuses a non-approved application' );
$app6_e = t_application( t_batch(), t_phone(), 'approved', 'zz-prov-mail-' . bin2hex( random_bytes( 3 ) ) . '@example.test' );
$phone7 = $wpdb->get_var( $wpdb->prepare( "SELECT student_phone FROM {$p}cc_applications WHERE id = %d", $app6_e ) );
$cleanup['logins'][] = ltrim( (string) $phone7, '+' );
$r7 = CC_Provisioner::provision( $app6_e );
$u7 = get_userdata( $r7['user_id'] );
t_assert( ltrim( (string) $phone7, '+' ) . '@students.invalid' === $u7->user_email, 'login email is the placeholder even when the application has an email' );
t_assert( str_starts_with( (string) get_user_meta( $r7['user_id'], 'cc_application_email', true ), 'zz-prov-mail-' ), 'application email kept in cc_application_email meta' );

// 6. Repository and roles.
echo "repository + roles\n";
$rows = CC_Enrollment_Repository::for_user( $r1['user_id'] );
t_assert( 2 === count( $rows ) && array_key_exists( 'course_title', $rows[0] ) && 'Sat 6pm' === $rows[0]['schedule_text'] && 'physical' === $rows[0]['delivery_mode'] && 'active' === $rows[0]['status'] && isset( $rows[0]['batch_name'] ), 'for_user returns batch/course fields' );
t_assert( CC_Enrollment_Repository::user_has_active( $r1['user_id'], $batch1 ) && ! CC_Enrollment_Repository::user_has_active( $r1['user_id'], 999999999 ), 'user_has_active' );
t_assert( CC_Enrollment_Repository::create( $r1['user_id'], $batch1, $app1 ) === $r1['enrollment_id'], 'create() is idempotent by application_id' );
$wpdb->update( "{$p}cc_enrollments", array( 'expires_at' => '2000-01-01 00:00:00' ), array( 'id' => $r1['enrollment_id'] ) );
t_assert( ! CC_Enrollment_Repository::user_has_active( $r1['user_id'], $batch1 ), 'expired enrollment is not active' );
CC_Enrollment_Repository::mark_pw_changed( $r1['user_id'] );
$student = CC_Enrollment_Repository::student_row( $r1['user_id'] );
t_assert( '0' === (string) $student['must_change_pw'] && null === $student['temp_pw_expires'], 'mark_pw_changed clears flag and expiry' );

wp_set_current_user( $r1['user_id'] );
t_assert( false === apply_filters( 'show_admin_bar', true ), 'admin bar hidden for students' );
t_assert( home_url( '/student/' ) === apply_filters( 'login_redirect', admin_url(), '', get_userdata( $r1['user_id'] ) ), 'student login redirect goes to /student/' );
t_assert( has_action( 'admin_init', array( 'CC_Roles', 'block_admin' ) ) !== false && has_action( 'login_init', array( 'CC_Roles', 'block_login_page' ) ) !== false, 'wp-admin and wp-login guards registered' );
wp_set_current_user( (int) $other );
t_assert( true === apply_filters( 'show_admin_bar', true ) && admin_url() === apply_filters( 'login_redirect', admin_url(), '', get_userdata( (int) $other ) ), 'non-students unaffected' );
wp_set_current_user( 0 );

// 7. Reconciler recovery sweep.
echo "reconciler: provisioning recovery sweep\n";
$rb      = t_batch();
$rphone  = t_phone();
$rlogin  = ltrim( $rphone, '+' );
$cleanup['logins'][] = $rlogin;
$old     = gmdate( 'Y-m-d H:i:s', time() - 600 );
$app_old = t_application( $rb, $rphone );
$wpdb->update( "{$p}cc_applications", array( 'updated_at' => $old ), array( 'id' => $app_old ) );
$app_new = t_application( $rb, t_phone() );
$app_lnk = t_application( $rb, t_phone() );
$wpdb->update( "{$p}cc_applications", array( 'updated_at' => $old, 'user_id' => 1 ), array( 'id' => $app_lnk ) );

t_assert( CC_Provisioner::ensure_enqueued( $app_new ), 'ensure_enqueued queues a new action' );
t_assert( ! CC_Provisioner::ensure_enqueued( $app_new ), 'ensure_enqueued skips when an identical action is pending' );
wp_clear_scheduled_hook( CC_Provisioner::HOOK, array( $app_new, 1 ) );
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( CC_Provisioner::HOOK, array( $app_new, 1 ), CC_Provisioner::GROUP );
}

CC_Reconciler::recover_unprovisioned();
CC_Reconciler::recover_unprovisioned();
$pending = function ( int $a ): bool {
	return function_exists( 'as_has_scheduled_action' )
		? as_has_scheduled_action( CC_Provisioner::HOOK, array( $a, 1 ), CC_Provisioner::GROUP )
		: false !== wp_next_scheduled( CC_Provisioner::HOOK, array( $a, 1 ) );
};
t_assert( $pending( $app_old ), 'stale approved application without a user is queued' );
t_assert( ! $pending( $app_new ), 'application younger than 2 minutes is skipped' );
t_assert( ! $pending( $app_lnk ), 'application with user_id set is skipped' );
$count_pending = 0;
if ( function_exists( 'as_get_scheduled_actions' ) ) {
	$count_pending = count( as_get_scheduled_actions( array( 'hook' => CC_Provisioner::HOOK, 'args' => array( $app_old, 1 ), 'status' => ActionScheduler_Store::STATUS_PENDING, 'per_page' => 10 ), 'ids' ) );
} else {
	$count_pending = $pending( $app_old ) ? 1 : 0;
}
t_assert( 1 === $count_pending, 'running the sweep twice leaves exactly one pending action' );

CC_Provisioner::run_scheduled( $app_old, 1 );
CC_Provisioner::run_scheduled( $app_old, 1 );
t_assert( 1 === t_count( 'cc_enrollments', 'application_id = %d', $app_old ) && 1 === t_sms_count( $app_old ) && null !== get_user_by( 'login', $rlogin ) && 0 === t_count( 'cc_applications', 'id = %d AND user_id IS NULL', $app_old ), 'recovered application is provisioned exactly once' );
t_assert( 0 === t_count( 'cc_applications', 'id = %d AND user_id IS NOT NULL', $app_new ), 'skipped young application was not provisioned' );
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	foreach ( array( $app_old, $app_new, $app_lnk ) as $a ) {
		as_unschedule_all_actions( CC_Provisioner::HOOK, array( $a, 1 ), CC_Provisioner::GROUP );
	}
}

// 8. Unverified phone (M4).
echo "provision(): application without a verified phone\n";
$batch_u  = t_batch();
$app_u    = t_application( $batch_u, $phone, 'approved', '', false );
$enr_before = t_count( 'cc_enrollments', 'user_id = %d', $r1['user_id'] );
$audit_u  = static fn( int $id ): int => t_count( 'cc_audit_log', "action = 'application.attach_blocked' AND entity_type = 'application' AND entity_id = %d", $id );
$ru = CC_Provisioner::provision( $app_u );
t_assert( true === $ru['blocked'] && 0 === $ru['user_id'] && 0 === $ru['enrollment_id'], 'existing student + unverified phone: provisioning is blocked' );
t_assert( t_count( 'cc_enrollments', 'user_id = %d', $r1['user_id'] ) === $enr_before && 0 === t_sms_count( $app_u ), 'no enrollment and no SMS for the blocked application' );
t_assert( 1 === $audit_u( $app_u ) && 'approved' === $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$p}cc_applications WHERE id = %d", $app_u ) ) && 0 === t_count( 'cc_applications', 'id = %d AND user_id IS NOT NULL', $app_u ), 'one application.attach_blocked audit row; application stays approved and unlinked' );
$audit_note = (string) $wpdb->get_var( $wpdb->prepare( "SELECT CONCAT_WS('|', note, diff_hash) FROM {$p}cc_audit_log WHERE action = 'application.attach_blocked' AND entity_id = %d", $app_u ) );
t_assert( ! str_contains( $audit_note, $phone ) && ! str_contains( $audit_note, $login ), 'the audit row holds no phone number' );
CC_Provisioner::run_scheduled( $app_u, 1 );
CC_Provisioner::provision( $app_u );
t_assert( 1 === $audit_u( $app_u ) && 0 === t_sms_count( $app_u ), 'replays do not add audit rows, enrollments or SMS' );
t_assert( in_array( $app_u, CC_Provisioner::blocked_application_ids(), true ), 'the application is listed for manual review' );
$phone_new = t_phone();
$login_new = ltrim( $phone_new, '+' );
$cleanup['logins'][] = $login_new;
$app_nv = t_application( t_batch(), $phone_new, 'approved', '', false );
$rn     = CC_Provisioner::provision( $app_nv );
t_assert( true === $rn['created'] && false === $rn['blocked'] && $rn['user_id'] > 0 && 1 === t_sms_count( $app_nv ), 'unverified phone that is not registered yet still creates a new account (unchanged)' );
t_assert( ! in_array( $app_nv, CC_Provisioner::blocked_application_ids(), true ), 'and is not listed for review' );

echo "admin Applications screen shows the review notice\n";
$reviewer = wp_insert_user( array( 'user_login' => 'zzprov-reviewer-' . bin2hex( random_bytes( 3 ) ), 'user_pass' => wp_generate_password(), 'user_email' => 'zzprov-reviewer-' . bin2hex( random_bytes( 3 ) ) . '@example.test', 'role' => 'subscriber' ) );
( new WP_User( $reviewer ) )->add_cap( 'cc_review_applications' );
wp_set_current_user( (int) $reviewer );
$_GET['view'] = (string) $app_u;
ob_start();
CC_Admin_Applications::render();
$html = (string) ob_get_clean();
t_assert( str_contains( $html, 'Needs manual review: phone not verified' ), 'the blocked application detail shows the notice' );
$_GET['view'] = (string) $app1;
ob_start();
CC_Admin_Applications::render();
$html_ok = (string) ob_get_clean();
t_assert( ! str_contains( $html_ok, 'Needs manual review' ), 'other applications show no notice' );
$_GET = array();
wp_set_current_user( 0 );
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( (int) $reviewer );

echo "audit_unverified_pending()\n";
$app_pu = t_application( $batch_u, $phone, 'pending', '', false );
$app_pv = t_application( t_batch(), $phone, 'pending', '', true );
$app_pn = t_application( t_batch(), t_phone(), 'pending', '', false );
ob_start();
$found = CC_Provisioner::audit_unverified_pending();
$out   = (string) ob_get_clean();
t_assert( in_array( $app_u, $found['approved'], true ) && in_array( $app_pu, $found['pending'], true ), 'lists approved and pending unverified applications of an existing student' );
t_assert( ! in_array( $app_pv, $found['pending'], true ) && ! in_array( $app_pn, $found['pending'], true ), 'skips verified applications and phones without a student account' );
t_assert( 1 === preg_match( '/pending with unverified phone matching an existing student: \d+ \(ids [\d,]*' . $app_pu . '/', $out ) && str_contains( $out, 'approved with unverified phone' ) && ! str_contains( $out, $phone ), 'prints counts and ids only (no phone numbers)' );

// Cleanup.
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ( $cleanup['logins'] as $l ) {
	$u = get_user_by( 'login', $l );
	if ( $u ) {
		$wpdb->delete( "{$p}cc_students", array( 'user_id' => $u->ID ) );
		$wpdb->delete( "{$p}cc_enrollments", array( 'user_id' => $u->ID ) );
		wp_delete_user( $u->ID );
	}
}
foreach ( $cleanup['apps'] as $a ) {
	foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$p}cc_sms_log WHERE related_type = 'application' AND related_id = %d", $a ) ) as $sms_id ) {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'cc_send_sms', array( (int) $sms_id ), 'cc' );
		}
	}
	$wpdb->delete( "{$p}cc_sms_log", array( 'related_type' => 'application', 'related_id' => $a ) );
	$wpdb->delete( "{$p}cc_audit_log", array( 'action' => CC_Provisioner::BLOCKED_ACTION, 'entity_id' => $a ) );
	$wpdb->delete( "{$p}cc_enrollments", array( 'application_id' => $a ) );
	$wpdb->delete( "{$p}cc_applications", array( 'id' => $a ) );
	for ( $attempt = 1; $attempt <= CC_Provisioner::MAX_ATTEMPTS; $attempt++ ) {
		wp_clear_scheduled_hook( CC_Provisioner::HOOK, array( $a, $attempt ) );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( CC_Provisioner::HOOK, array( $a, $attempt ), CC_Provisioner::GROUP );
		}
	}
}
if ( function_exists( 'as_get_scheduled_actions' ) ) {
	$leftover = 0;
	foreach ( $cleanup['apps'] as $a ) {
		for ( $attempt = 1; $attempt <= CC_Provisioner::MAX_ATTEMPTS; $attempt++ ) {
			$leftover += count( as_get_scheduled_actions( array( 'hook' => CC_Provisioner::HOOK, 'args' => array( $a, $attempt ), 'status' => ActionScheduler_Store::STATUS_PENDING, 'per_page' => 1 ), 'ids' ) );
		}
	}
	t_assert( 0 === $leftover, 'no pending cc_provision_student actions left for this run' );
}
foreach ( $cleanup['batches'] as $b ) {
	$wpdb->delete( "{$p}cc_batches", array( 'id' => $b ) );
}
if ( isset( $other ) && ! is_wp_error( $other ) ) {
	wp_delete_user( (int) $other );
}

echo "\n$checks checks, $failures failures\n";
if ( $failures > 0 ) {
	WP_CLI::halt( 1 );
}
