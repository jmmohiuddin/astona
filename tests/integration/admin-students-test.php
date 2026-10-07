<?php
/**
 * Integration tests for CC_Admin_Students. Run inside the wpcli container:
 *   docker compose run --rm -T wpcli eval-file /tests/integration/admin-students-test.php
 * Creates fixture batch/application/enrollment/users, then removes them, their audit rows, SMS rows and any
 * pending Action Scheduler actions.
 */
global $wpdb, $failures, $checks, $cleanup;
$p        = $wpdb->prefix;
$failures = 0;
$checks   = 0;
$cleanup  = array( 'batches' => array(), 'apps' => array(), 'users' => array(), 'sms' => array() );

require_once ABSPATH . 'wp-admin/includes/user.php';

function t_assert( bool $cond, string $label ): void {
	global $failures, $checks;
	++$checks;
	echo ( $cond ? '  ok   ' : '  FAIL ' ) . $label . "\n";
	if ( ! $cond ) {
		++$failures;
	}
}

function t_user( array $caps = array() ): int {
	global $cleanup;
	$id = wp_insert_user( array( 'user_login' => 'zzadm' . bin2hex( random_bytes( 4 ) ), 'user_pass' => wp_generate_password( 20 ), 'user_email' => bin2hex( random_bytes( 4 ) ) . '@zz.invalid', 'role' => 'subscriber' ) );
	$cleanup['users'][] = (int) $id;
	foreach ( $caps as $cap ) {
		( new WP_User( $id ) )->add_cap( $cap );
	}
	return (int) $id;
}

function t_audit_count( string $action, int $entity_id ): int {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'cc_audit_log WHERE action = %s AND entity_id = %d', $action, $entity_id ) );
}

CC_Roles::ensure_role();
$now   = gmdate( 'Y-m-d H:i:s' );
$wpdb->insert( $p . 'cc_batches', array( 'course_id' => 0, 'name' => 'ZZ-STU ' . bin2hex( random_bytes( 3 ) ), 'capacity' => 10, 'seats_taken' => 1, 'price' => 100, 'start_date' => '2030-01-01', 'schedule_text' => 'Sat', 'status' => 'open', 'application_open' => 1, 'created_at' => $now, 'updated_at' => $now ) );
$batch = (int) $wpdb->insert_id;
$cleanup['batches'][] = $batch;

$phone = '+88019' . str_pad( (string) random_int( 0, 99999999 ), 8, '0', STR_PAD_LEFT );
$login = ltrim( $phone, '+' );
$uid   = (int) wp_insert_user( array( 'user_login' => $login, 'user_pass' => wp_generate_password( 20 ), 'user_email' => $login . '@students.invalid', 'display_name' => 'ZZ Stu', 'role' => CC_Roles::ROLE ) );
$cleanup['users'][] = $uid;
CC_Enrollment_Repository::create_student( $uid, 'ZZ Searchable Student', 'G', '+8801700000001', 'Sch', null, gmdate( 'Y-m-d H:i:s', time() + 3600 ) );

$wpdb->insert( $p . 'cc_applications', array(
	'public_ref' => substr( strtoupper( 'ZZSTU' . bin2hex( random_bytes( 10 ) ) ), 0, 26 ), 'idempotency_key' => wp_generate_uuid4(), 'batch_id' => $batch, 'user_id' => $uid,
	'student_phone' => $phone, 'full_name' => 'ZZ Searchable Student', 'gender' => 'o', 'dob' => '2008-01-01', 'id_doc_type' => 'nid', 'id_doc_enc' => 'x',
	'guardian_name' => 'G', 'guardian_phone' => '+8801700000001', 'institution' => 'S', 'class_level' => '10', 'consent_at' => $now, 'status' => 'approved', 'created_at' => $now, 'updated_at' => $now,
) );
$app = (int) $wpdb->insert_id;
$cleanup['apps'][] = $app;
$enrollment = CC_Enrollment_Repository::create( $uid, $batch, $app );

$wpdb->insert( $p . 'cc_sms_log', array( 'to_phone' => $phone, 'template' => 'credentials', 'body_hash' => hash( 'sha256', 'zz' ), 'status' => 'failed', 'related_type' => 'application', 'related_id' => $app, 'created_at' => $now ) );
$cleanup['sms'][] = (int) $wpdb->insert_id;

$manager = t_user( array( 'cc_manage_students', 'cc_view_students' ) );
$viewer  = t_user( array( 'cc_view_students' ) );
$nobody  = t_user();

echo "set_enrollment_status\n";
$r = CC_Admin_Students::set_enrollment_status( $enrollment, 'deactivated', $nobody );
t_assert( is_wp_error( $r ) && 'cc_forbidden' === $r->get_error_code(), 'user without cap refused' );
$r = CC_Admin_Students::set_enrollment_status( $enrollment, 'deactivated', $viewer );
t_assert( is_wp_error( $r ) && 'cc_forbidden' === $r->get_error_code(), 'view-only user refused' );
t_assert( CC_Enrollment_Repository::user_has_active( $uid, $batch ), 'refusals left enrollment active' );
$r = CC_Admin_Students::set_enrollment_status( $enrollment, 'bogus', $manager );
t_assert( is_wp_error( $r ) && 'cc_invalid_status' === $r->get_error_code(), 'invalid status rejected' );
$r = CC_Admin_Students::set_enrollment_status( 999999999, 'deactivated', $manager );
t_assert( is_wp_error( $r ) && 'cc_not_found' === $r->get_error_code(), 'unknown enrollment rejected' );
wp_set_current_user( $manager );
$r = CC_Admin_Students::set_enrollment_status( $enrollment, 'deactivated', $manager );
t_assert( true === $r, 'manager deactivates' );
t_assert( ! CC_Enrollment_Repository::user_has_active( $uid, $batch ), 'deactivated enrollment no longer active for portal access check' );
t_assert( 1 === t_audit_count( 'student.enrollment_deactivated', $enrollment ), 'deactivate audited' );
$r = CC_Admin_Students::set_enrollment_status( $enrollment, 'deactivated', $manager );
t_assert( is_wp_error( $r ) && 'cc_no_change' === $r->get_error_code(), 'repeat deactivate is a no-op error' );
$r = CC_Admin_Students::set_enrollment_status( $enrollment, 'active', $manager );
t_assert( true === $r && CC_Enrollment_Repository::user_has_active( $uid, $batch ), 'manager reactivates' );
t_assert( 1 === t_audit_count( 'student.enrollment_active', $enrollment ), 'reactivate audited' );
$r = CC_Admin_Students::set_enrollment_status( $enrollment, 'active', $manager );
t_assert( is_wp_error( $r ) && 'cc_no_change' === $r->get_error_code(), 'reactivating an active enrollment is a no-op error' );

echo "query\n";
$q = CC_Admin_Students::query( array( 'search' => 'Searchable Student' ) );
t_assert( 1 === $q['total'] && $uid === (int) $q['items'][0]['user_id'], 'search by name finds the student' );
t_assert( $batch > 0 && false !== strpos( (string) $q['items'][0]['batches'], 'ZZ-STU' ) && 'active' === $q['items'][0]['enrollment_status'], 'batches and status attached' );
t_assert( 'failed' === $q['items'][0]['sms_status'], 'last credentials SMS status resolved through application id' );
$q = CC_Admin_Students::query( array( 'search' => substr( $phone, -8 ) ) );
t_assert( 1 === $q['total'], 'search by phone digits' );
$q = CC_Admin_Students::query( array( 'search' => "Searchable' OR 'a'='a" ) );
t_assert( 0 === $q['total'], 'quote in search is inert' );
$q = CC_Admin_Students::query( array( 'batch_id' => $batch ) );
t_assert( 1 === $q['total'], 'batch filter' );
$q = CC_Admin_Students::query( array( 'batch_id' => $batch, 'status' => 'deactivated' ) );
t_assert( 0 === $q['total'], 'status filter excludes active enrollment' );
wp_set_current_user( $viewer );
t_assert( CC_Phone::mask( $phone ) === CC_Admin_Students::display_phone( $login ), 'view-only actor sees masked phone' );
wp_set_current_user( $manager );
t_assert( $phone === CC_Admin_Students::display_phone( $login ), 'manager sees full phone' );

echo "resend_credentials\n";
$r = CC_Admin_Students::resend_credentials( $uid, $viewer );
t_assert( is_wp_error( $r ) && 'cc_forbidden' === $r->get_error_code(), 'view-only user refused' );
$r = CC_Admin_Students::resend_credentials( 999999999, $manager );
t_assert( is_wp_error( $r ) && 'cc_not_found' === $r->get_error_code(), 'unknown student rejected' );

putenv( 'CC_RATE_LIMIT_DISABLED=0' );
CC_Rate_Limiter::reset( 'cc_resend_credentials_' . $uid );
$old_password = 'zz-old-' . bin2hex( random_bytes( 4 ) );
wp_set_password( $old_password, $uid );
CC_Enrollment_Repository::mark_pw_changed( $uid );
$old_token = WP_Session_Tokens::get_instance( $uid )->create( time() + HOUR_IN_SECONDS );
$hash_before = get_userdata( $uid )->user_pass;
$cred_before = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}cc_sms_log WHERE template = 'credentials' AND to_phone = %s", $phone ) );
$r           = CC_Admin_Students::resend_credentials( $uid, $manager );
t_assert( CC_Admin_Students::SENT_HINT === $r, 'password already changed: only a login hint is sent' );
$hint_rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, status, body_enc FROM {$p}cc_sms_log WHERE template = 'login_hint' AND related_type = 'student' AND related_id = %d", $uid ), ARRAY_A );
foreach ( $hint_rows as $row ) {
	$cleanup['sms'][] = (int) $row['id'];
}
t_assert( 1 === count( $hint_rows ) && 'queued' === $hint_rows[0]['status'], 'exactly one login_hint SMS queued for the student' );
t_assert( $cred_before === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}cc_sms_log WHERE template = 'credentials' AND to_phone = %s", $phone ) ), 'no credentials SMS queued' );
t_assert( $hash_before === get_userdata( $uid )->user_pass && wp_check_password( $old_password, get_userdata( $uid )->user_pass, $uid ), 'password untouched' );
t_assert( null !== WP_Session_Tokens::get_instance( $uid )->get( $old_token ), 'sessions untouched' );
t_assert( 0 === (int) CC_Enrollment_Repository::student_row( $uid )['must_change_pw'], 'must_change_pw stays 0' );
t_assert( 1 === t_audit_count( 'student.login_hint_sent', $uid ) && 0 === t_audit_count( 'student.credentials_resent', $uid ), 'hint audited as login_hint_sent, not credentials_resent' );
putenv( 'CC_SMS_PRIMARY=fake' );
CC_Sms_Fake_Driver::clear();
CC_Sms::handle( (int) $hint_rows[0]['id'] );
putenv( 'CC_SMS_PRIMARY' );
$outbox = CC_Sms_Fake_Driver::outbox();
t_assert( 1 === count( $outbox ) && $phone === $outbox[0]['to'] && false !== strpos( $outbox[0]['body'], 'Login with OTP' ) && false === stripos( $outbox[0]['body'], 'password {' ), 'hint delivered to the student phone, no password in it' );
CC_Enrollment_Repository::require_password_change( $uid, gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ) );
$before    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}cc_sms_log WHERE template = 'credentials' AND to_phone = %s", $phone ) );
CC_Sms_Fake_Driver::clear();
$r = CC_Admin_Students::resend_credentials( $uid, $manager );
t_assert( CC_Admin_Students::SENT_PASSWORD === $r, 'must_change_pw still 1: manager re-issues credentials' );
$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, body_enc, status FROM {$p}cc_sms_log WHERE template = 'credentials' AND to_phone = %s ORDER BY id DESC", $phone ), ARRAY_A );
foreach ( $rows as $row ) {
	if ( ! in_array( (int) $row['id'], $cleanup['sms'], true ) ) {
		$cleanup['sms'][] = (int) $row['id'];
	}
}
t_assert( count( $rows ) === $before + 1 && 'queued' === $rows[0]['status'] && null === $rows[0]['body_enc'], 'exactly one credentials SMS queued, no body stored' );
$st = CC_Enrollment_Repository::student_row( $uid );
t_assert( 1 === (int) $st['must_change_pw'] && strtotime( $st['temp_pw_expires'] . ' UTC' ) > time() + 71 * HOUR_IN_SECONDS, 'must_change_pw=1 and expiry ~72h' );
t_assert( 1 === t_audit_count( 'student.credentials_resent', $uid ), 'resend audited' );
t_assert( null === WP_Session_Tokens::get_instance( $uid )->get( $old_token ), 'existing sessions destroyed' );
t_assert( ! wp_check_password( $old_password, get_userdata( $uid )->user_pass, $uid ), 'old password no longer works' );
putenv( 'CC_SMS_PRIMARY=fake' );
CC_Sms::handle( (int) $rows[0]['id'] );
putenv( 'CC_SMS_PRIMARY' );
$outbox = CC_Sms_Fake_Driver::outbox();
$sent   = $outbox ? end( $outbox ) : array( 'to' => '', 'body' => '' );
t_assert( $phone === $sent['to'] && 1 === preg_match( '/temporary password (\w+)\./', $sent['body'], $m ), 'SMS delivered to the student phone carrying a temp password' );
t_assert( isset( $m[1] ) && wp_check_password( $m[1], get_userdata( $uid )->user_pass, $uid ), 'the SMS temp password is the new account password' );
CC_Sms_Fake_Driver::clear();
CC_Admin_Students::resend_credentials( $uid, $manager );
$r = CC_Admin_Students::resend_credentials( $uid, $manager );
t_assert( is_wp_error( $r ) && 'cc_rate_limited' === $r->get_error_code(), '4th resend (hint or credentials) within the hour is rate limited' );
$non_student = t_user();
$r           = CC_Provisioner::reissue_credentials( $non_student );
t_assert( is_wp_error( $r ) && 'cc_not_student' === $r->get_error_code(), 'reissue refuses a non-student user' );
CC_Rate_Limiter::reset( 'cc_resend_credentials_' . $uid );
putenv( 'CC_RATE_LIMIT_DISABLED=1' );

echo "export search key\n";
$parsed_s      = CC_Admin_Students::filters_from( array( 's' => 'Searchable Student', 'batch_id' => (string) $batch ) );
$parsed_search = CC_Admin_Students::filters_from( array( 'search' => 'Searchable Student' ) );
t_assert( 'Searchable Student' === $parsed_s['search'] && 'Searchable Student' === $parsed_search['search'], 'filters_from accepts both s and search' );
$link_args = CC_Admin_Students::export_link_args( array( 's' => 'Searchable Student' ) );
t_assert( 'Searchable Student' === ( $link_args['s'] ?? null ) && ! isset( $link_args['search'] ), 'export link carries the term under the key the handler reads' );
t_assert( 1 === CC_Admin_Students::query( $parsed_s )['total'], 'matching term returns the student' );
t_assert( 0 === CC_Admin_Students::query( CC_Admin_Students::filters_from( array( 's' => 'NoSuchStudent' . $batch ) ) )['total'], 'non-matching term returns no rows' );

echo "search: phone clause only for phone-shaped terms\n";
$tail = substr( $login, -6 );
t_assert( 0 === CC_Admin_Students::query( array( 'search' => 'nobody ' . substr( $login, -1 ) ) )['total'], 'name term with a digit does not match by phone digits' );
t_assert( 1 === CC_Admin_Students::query( array( 'search' => $tail, 'batch_id' => $batch ) )['total'], 'digits-only term finds the student by phone' );
t_assert( 1 === CC_Admin_Students::query( array( 'search' => '+' . substr( $login, 0, 8 ) . ' ', 'batch_id' => $batch ) )['total'], 'plus-prefixed phone term finds the student by phone' );
t_assert( 0 === CC_Admin_Students::query( array( 'search' => '9', 'batch_id' => $batch ) )['total'], 'single-digit term is below the phone threshold' );
$wpdb->update( $p . 'cc_students', array( 'full_name' => 'ZZ Rahim 2 Searchable' ), array( 'user_id' => $uid ) );
t_assert( 1 === CC_Admin_Students::query( array( 'search' => 'Rahim 2', 'batch_id' => $batch ) )['total'], 'name containing a digit is still found by name' );

echo "detail view button\n";
$render = static function () use ( $uid ): string {
	$_GET['user'] = (string) $uid;
	ob_start();
	CC_Admin_Students::render();
	unset( $_GET['user'] );
	return (string) ob_get_clean();
};
wp_set_current_user( $manager );
CC_Enrollment_Repository::require_password_change( $uid, gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ) );
$html = $render();
t_assert( false !== strpos( $html, 'Resend credentials' ) && false === strpos( $html, 'Send login hint' ), 'must_change_pw=1 shows only Resend credentials' );
CC_Enrollment_Repository::mark_pw_changed( $uid );
$html = $render();
t_assert( false !== strpos( $html, 'Send login hint' ) && false === strpos( $html, 'Resend credentials' ) && false !== strpos( $html, 'value="cc_student_resend"' ), 'must_change_pw=0 shows only Send login hint, posting to the same handler' );
wp_set_current_user( $viewer );
$html = $render();
t_assert( false === strpos( $html, 'Send login hint' ) && false === strpos( $html, 'Resend credentials' ), 'view-only user sees neither button' );

echo "cleanup\n";
wp_set_current_user( 0 );
$ids = $cleanup['sms'];
if ( $ids ) {
	$rows = $wpdb->get_col( 'SELECT id FROM ' . $p . 'cc_sms_log WHERE related_id IN (' . (int) $uid . ',' . (int) $app . ") AND related_type IN ('student','application')" );
	$ids  = array_unique( array_merge( $ids, array_map( 'intval', $rows ) ) );
}
foreach ( $ids as $id ) {
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'cc_send_sms', array( (int) $id ), 'cc' );
	}
	$wpdb->delete( $p . 'cc_sms_log', array( 'id' => (int) $id ) );
	delete_transient( 'cc_sms_vars_' . (int) $id );
}
foreach ( $cleanup['apps'] as $id ) {
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'cc_provision_student', array( (int) $id, 1 ), 'cc' );
	}
	$wpdb->delete( $p . 'cc_enrollments', array( 'application_id' => (int) $id ) );
	$wpdb->delete( $p . 'cc_applications', array( 'id' => (int) $id ) );
}
foreach ( $cleanup['batches'] as $id ) {
	$wpdb->delete( $p . 'cc_batches', array( 'id' => (int) $id ) );
}
$wpdb->delete( $p . 'cc_students', array( 'user_id' => $uid ) );
$wpdb->delete( $p . 'cc_audit_log', array( 'entity_id' => $enrollment, 'entity_type' => 'enrollment' ) );
$wpdb->delete( $p . 'cc_audit_log', array( 'entity_id' => $uid, 'entity_type' => 'student' ) );
foreach ( $cleanup['users'] as $id ) {
	wp_delete_user( $id );
}
CC_Rate_Limiter::reset( 'cc_resend_credentials_' . $uid );

echo "\n$checks checks, $failures failures\n";
exit( $failures ? 1 : 0 );
