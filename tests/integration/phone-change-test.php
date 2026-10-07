<?php
/**
 * Integration tests for CC_Phone_Change (student changes the login phone with an OTP to the new number).
 *   docker compose run --rm -T wpcli eval-file /tests/integration/phone-change-test.php
 * Needs the fake SMS driver and http://wordpress/ (compose network). Creates throwaway students and removes them.
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

putenv( 'CC_RATE_LIMIT_DISABLED=0' );
$_SERVER['REMOTE_ADDR'] = '198.51.100.' . random_int( 1, 250 );
$now = gmdate( 'Y-m-d H:i:s' );
$wpdb->insert( "{$p}cc_batches", array( 'course_id' => 0, 'name' => 'ZZ-PC ' . bin2hex( random_bytes( 3 ) ), 'capacity' => 10, 'seats_taken' => 2, 'price' => 100, 'start_date' => '2030-01-01', 'schedule_text' => 'Sat', 'status' => 'open', 'application_open' => 1, 'created_at' => $now, 'updated_at' => $now ) );
$batch = (int) $wpdb->insert_id;

$rand_phone = static fn(): string => '+88018' . str_pad( (string) random_int( 0, 99999999 ), 8, '0', STR_PAD_LEFT );
$mk_student = static function () use ( $wpdb, $p, $batch, $now, $rand_phone ): array {
	$phone = $rand_phone();
	$wpdb->insert( "{$p}cc_applications", array(
		'public_ref' => substr( strtoupper( 'ZZPC' . bin2hex( random_bytes( 11 ) ) ), 0, 26 ), 'idempotency_key' => wp_generate_uuid4(), 'batch_id' => $batch, 'student_phone' => $phone,
		'full_name' => 'ZZ Phone Change', 'gender' => 'o', 'dob' => '2008-01-01', 'id_doc_type' => 'nid', 'id_doc_enc' => 'x', 'guardian_name' => 'G', 'guardian_phone' => '+8801700000001',
		'email' => '', 'institution' => 'I', 'class_level' => '10', 'consent_at' => $now, 'status' => 'approved', 'created_at' => $now, 'updated_at' => $now,
	) );
	$r  = CC_Provisioner::provision( (int) $wpdb->insert_id );
	$pw = 'PcTest-' . bin2hex( random_bytes( 6 ) );
	wp_set_password( $pw, $r['user_id'] );
	return array( 'id' => (int) $r['user_id'], 'phone' => $phone, 'pw' => $pw );
};
/** Sends queued SMS to a number through the fake driver and returns the 6-digit code from the newest message. */
$code_for = static function ( string $phone ) use ( $wpdb, $p ): string {
	foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$p}cc_sms_log WHERE to_phone = %s AND status = 'queued' ORDER BY id", $phone ) ) as $id ) {
		CC_Sms::send( (int) $id );
	}
	wp_cache_flush(); // another process (the web server) may have sent it already.
	$mine = array_values( array_filter( CC_Sms_Fake_Driver::outbox(), static fn( $m ) => $m['to'] === $phone ) );
	$last = $mine ? end( $mine )['body'] : '';
	return 1 === preg_match( '/\b(\d{6})\b/', $last, $m ) ? $m[1] : '';
};
$reset = static function ( int $uid ): void {
	foreach ( array( 'phone_chg_req|' . $uid, 'phone_chg_confirm|' . $uid, 'phone_chg_ip|' . CC_Rate_Limiter::ip_bucket_key() ) as $b ) {
		CC_Rate_Limiter::reset( $b );
	}
};
CC_Sms_Fake_Driver::clear();

$a = $mk_student();
$b = $mk_student();
$new = $rand_phone();
wp_set_current_user( $a['id'] );

echo "request validation\n";
$r = CC_Phone_Change::request( $a['id'], $new, 'wrong-password' );
t_assert( is_wp_error( $r ) && 'wrong_password' === $r->get_error_code(), 'wrong password refused' );
$r = CC_Phone_Change::request( $a['id'], '12345', $a['pw'] );
t_assert( is_wp_error( $r ) && 'invalid_phone' === $r->get_error_code(), 'invalid number refused' );
$r = CC_Phone_Change::request( $a['id'], $a['phone'], $a['pw'] );
t_assert( is_wp_error( $r ) && 'same_phone' === $r->get_error_code(), 'own number refused' );

echo "a number already in use is indistinguishable\n";
$reset( $a['id'] );
$before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}cc_sms_log WHERE to_phone = '{$b['phone']}'" );
$r      = CC_Phone_Change::request( $a['id'], $b['phone'], $a['pw'] );
t_assert( true === $r, 'request answers success for a taken number' );
t_assert( $before === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}cc_sms_log WHERE to_phone = '{$b['phone']}'" ), 'but no SMS is sent to it' );
$r = CC_Phone_Change::confirm( $a['id'], $b['phone'], '123456' );
t_assert( is_wp_error( $r ) && 'invalid_code' === $r->get_error_code(), 'and confirming it fails like a wrong code' );

echo "happy path\n";
$reset( $a['id'] );
t_assert( true === CC_Phone_Change::request( $a['id'], $new, $a['pw'] ), 'request accepted' );
$code = $code_for( $new );
t_assert( 1 === preg_match( '/^\d{6}$/', $code ), 'a 6-digit code reached the new number' );
$r = CC_Phone_Change::confirm( $a['id'], $new, '000000' === $code ? '111111' : '000000' );
t_assert( is_wp_error( $r ) && 'invalid_code' === $r->get_error_code(), 'wrong code refused' );
t_assert( ltrim( $a['phone'], '+' ) === get_userdata( $a['id'] )->user_login, 'login unchanged after a wrong code' );
$other = $rand_phone();
$r     = CC_Phone_Change::confirm( $a['id'], $other, $code );
t_assert( is_wp_error( $r ), 'the code does not work for a different number' );
$r = CC_Phone_Change::confirm( $a['id'], $new, $code );
t_assert( ! is_wp_error( $r ) && $new === $r['phone'] && '' !== $r['nonce'], 'right code changes the number' );
$u = get_userdata( $a['id'] );
t_assert( ltrim( $new, '+' ) === $u->user_login, 'user_login is now the new number' );
t_assert( $u->ID === ( get_user_by( 'login', ltrim( $new, '+' ) )->ID ?? 0 ) && false === get_user_by( 'login', ltrim( $a['phone'], '+' ) ), 'lookups resolve the new login only' );
t_assert( $new === CC_Portal_Data::profile( $a['id'] )['phone'], 'profile shows the new number' );
t_assert( 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}cc_audit_log WHERE action = 'student.phone_changed' AND entity_id = {$a['id']}" ), 'the change is audit-logged' );
$audit = (string) $wpdb->get_var( "SELECT CONCAT_WS('|', diff_hash, note) FROM {$p}cc_audit_log WHERE action = 'student.phone_changed' AND entity_id = {$a['id']}" );
t_assert( ! str_contains( $audit, ltrim( $new, '+' ) ), 'the audit row holds no phone number' );
$notice = $code_for( $a['phone'] );
t_assert( '' === $notice, 'the old number got a notice (no code in it)' );
$old_msgs = array_values( array_filter( CC_Sms_Fake_Driver::outbox(), static fn( $m ) => $m['to'] === $a['phone'] && str_contains( $m['body'], 'changed' ) ) );
t_assert( 1 === count( $old_msgs ) && str_contains( $old_msgs[0]['body'], '*' ) && ! str_contains( $old_msgs[0]['body'], ltrim( $new, '+' ) ), 'the old number is told, with the new one masked' );
$r = CC_Phone_Change::confirm( $a['id'], $new, $code );
t_assert( is_wp_error( $r ), 'the code cannot be used twice' );

echo "limits\n";
$reset( $b['id'] );
wp_set_current_user( $b['id'] );
$err = '';
for ( $i = 0; $i < 4; $i++ ) {
	$res = CC_Phone_Change::request( $b['id'], $rand_phone(), $b['pw'] );
	$err = is_wp_error( $res ) ? $res->get_error_code() : '';
}
t_assert( 'rate_limited' === $err, 'more than 3 requests an hour are refused' );
$reset( $b['id'] );
CC_Phone_Change::request( $b['id'], $rand_phone(), $b['pw'] );
$last = '';
for ( $i = 0; $i < 7; $i++ ) {
	$res  = CC_Phone_Change::confirm( $b['id'], '+8801812345678', '123456' );
	$last = is_wp_error( $res ) ? $res->get_error_code() : '';
}
t_assert( 'rate_limited' === $last, 'repeated confirm attempts are rate limited' );
$reset( $b['id'] );

echo "over HTTP: sessions and sign-in\n";
$c  = $mk_student();
$n2 = $rand_phone();
$j  = static function ( string $path, array $body, array $headers = array(), array $cookies = array() ): array {
	$r = wp_remote_post( 'http://wordpress/wp-json/cc/v1/' . $path, array( 'timeout' => 30, 'cookies' => $cookies, 'headers' => array_merge( array( 'Content-Type' => 'application/json' ), $headers ), 'body' => wp_json_encode( $body ) ) );
	return is_wp_error( $r ) ? array( 'status' => 0, 'body' => array(), 'cookies' => array() ) : array( 'status' => wp_remote_retrieve_response_code( $r ), 'body' => json_decode( wp_remote_retrieve_body( $r ), true ) ?: array(), 'cookies' => wp_remote_retrieve_cookies( $r ) );
};
$login = $j( 'auth/login', array( 'phone' => $c['phone'], 'password' => $c['pw'] ) );
t_assert( 200 === $login['status'], 'student signs in over REST' );
$page  = wp_remote_get( 'http://wordpress/student/profile/', array( 'cookies' => $login['cookies'], 'timeout' => 30 ) );
$nonce = 1 === preg_match( '/"nonce":"([a-z0-9]+)"/', wp_remote_retrieve_body( $page ), $m ) ? $m[1] : '';
t_assert( '' !== $nonce && str_contains( wp_remote_retrieve_body( $page ), 'portal-phone-form' ), 'profile page has the change-number form and a REST nonce' );
$other_session = $j( 'auth/login', array( 'phone' => $c['phone'], 'password' => $c['pw'] ) );
$req = $j( 'me/phone/request', array( 'phone' => $n2, 'current_password' => $c['pw'] ), array( 'X-WP-Nonce' => $nonce ), $login['cookies'] );
t_assert( 200 === $req['status'] && ! empty( $req['body']['ok'] ), 'HTTP request step succeeds' );
$anon = $j( 'me/phone/request', array( 'phone' => $n2, 'current_password' => 'x' ) );
t_assert( in_array( $anon['status'], array( 401, 403 ), true ), 'anonymous callers are refused' );
$code2 = $code_for( $n2 );
$cf    = $j( 'me/phone/confirm', array( 'phone' => $n2, 'code' => $code2 ), array( 'X-WP-Nonce' => $nonce ), $login['cookies'] );
t_assert( 200 === $cf['status'] && $n2 === ( $cf['body']['phone'] ?? '' ) && '' !== ( $cf['body']['nonce'] ?? '' ), 'HTTP confirm step succeeds and returns a new nonce' );
$fresh = array();
foreach ( $cf['cookies'] as $ck ) {
	$fresh[] = $ck;
}
$page2 = wp_remote_get( 'http://wordpress/student/profile/', array( 'cookies' => $fresh, 'timeout' => 30, 'redirection' => 0 ) );
t_assert( 200 === wp_remote_retrieve_response_code( $page2 ) && str_contains( wp_remote_retrieve_body( $page2 ), esc_html( $n2 ) ), 'the same browser stays signed in with the new cookie' );
$page3 = wp_remote_get( 'http://wordpress/student/profile/', array( 'cookies' => $other_session['cookies'], 'timeout' => 30, 'redirection' => 0 ) );
t_assert( 200 !== wp_remote_retrieve_response_code( $page3 ), 'another session of the same student was ended' );
$relogin = $j( 'auth/login', array( 'phone' => $c['phone'], 'password' => $c['pw'] ) );
t_assert( 200 !== $relogin['status'], 'the old number no longer signs in' );
$relogin2 = $j( 'auth/login', array( 'phone' => $n2, 'password' => $c['pw'] ) );
t_assert( 200 === $relogin2['status'], 'the new number signs in with the same password' );

require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ( array( $a, $b, $c ) as $s ) {
	wp_delete_user( $s['id'] );
}
$wpdb->query( "DELETE FROM {$p}cc_applications WHERE full_name = 'ZZ Phone Change'" );
$wpdb->query( "DELETE FROM {$p}cc_audit_log WHERE action = 'student.phone_changed'" );
$wpdb->delete( "{$p}cc_batches", array( 'id' => $batch ) );
echo "\n$checks checks, $failures failures\n";
exit( $failures ? 1 : 0 );
