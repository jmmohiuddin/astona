<?php
/**
 * Integration tests for CC_Otp, CC_Rate_Limiter and CC_Rest_Auth. Run inside the wpcli container:
 *   docker compose run --rm -T wpcli eval-file /tests/integration/auth-test.php
 * Creates a throwaway student and removes everything it created.
 */
global $wpdb, $failures, $checks;
$p        = $wpdb->prefix;
$failures = 0;
$checks   = 0;

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

foreach ( array( 'CC_Otp', 'CC_Rest_Auth', 'CC_Student_Guard', 'CC_Enrollment_Repository', 'CC_Sms', 'CC_Rate_Limiter' ) as $class ) {
	if ( ! class_exists( $class ) ) {
		echo "SKIP: $class not loaded; cannot run auth integration tests.\n";
		exit( 1 );
	}
}

add_filter( 'send_auth_cookies', '__return_false' ); // CLI has already emitted output; no headers.
do_action( 'rest_api_init' );

$added_role = false;
if ( ! get_role( 'cc_student' ) ) {
	add_role( 'cc_student', 'Student', array( 'read' => true ) );
	$added_role = true;
}

$tag      = (string) random_int( 10000000, 99999999 );
$phone    = '+88013' . $tag;                  // 13 + 8 digits: valid BD mobile
$login    = ltrim( $phone, '+' );
$password = 'Temp-Pass-12345';
$user_id  = wp_insert_user( array( 'user_login' => $login, 'user_pass' => $password, 'user_email' => "$login@students.invalid", 'role' => 'cc_student' ) );
$admin_id = wp_insert_user( array( 'user_login' => "zzadmin$tag", 'user_pass' => $password, 'user_email' => "zzadmin$tag@example.invalid", 'role' => 'administrator' ) );
$expired_phone = '+88014' . $tag;
$expired_id    = wp_insert_user( array( 'user_login' => ltrim( $expired_phone, '+' ), 'user_pass' => $password, 'user_email' => "x$tag@students.invalid", 'role' => 'cc_student' ) );
$now           = gmdate( 'Y-m-d H:i:s' );
foreach ( array( $user_id => array( 1, gmdate( 'Y-m-d H:i:s', time() + 3600 ) ), $expired_id => array( 1, gmdate( 'Y-m-d H:i:s', time() - 3600 ) ) ) as $uid => $v ) {
	$wpdb->insert( "{$p}cc_students", array( 'user_id' => $uid, 'full_name' => 'ZZ Auth Test', 'must_change_pw' => $v[0], 'temp_pw_expires' => $v[1], 'created_at' => $now ) );
}

function t_call( string $path, array $params, string $method = 'POST' ): WP_REST_Response {
	$request = new WP_REST_Request( $method, '/cc/v1' . $path );
	$request->set_header( 'content-type', 'application/json' );
	$request->set_body( wp_json_encode( $params ) );
	return rest_do_request( $request );
}

/** Inserts a live OTP row with a known code, bypassing SMS. */
function t_otp_row( string $phone, string $code, array $over = array() ): int {
	global $wpdb;
	$wpdb->insert( $wpdb->prefix . 'cc_otp', array_merge( array(
		'phone' => $phone, 'code_hash' => CC_Otp::hash( $phone, $code ), 'purpose' => 'login', 'attempts' => 0,
		'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 300 ), 'created_at' => gmdate( 'Y-m-d H:i:s' ),
	), $over ) );
	return (int) $wpdb->insert_id;
}

function t_clear_otp( string $phone ): void {
	global $wpdb;
	$wpdb->delete( $wpdb->prefix . 'cc_otp', array( 'phone' => $phone ) );
}

/** Creates a login session for the user and makes it the current one (cookie + current user), as a browser request would. */
function t_use_new_session( int $uid, int $lifetime = 2 * DAY_IN_SECONDS ): string {
	$token = WP_Session_Tokens::get_instance( $uid )->create( time() + $lifetime );
	t_use_session( $uid, $token );
	return $token;
}

function t_use_session( int $uid, string $token ): void {
	$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $uid, time() + DAY_IN_SECONDS, 'logged_in', $token );
	clean_user_cache( $uid );
	wp_set_current_user( 0 ); // forces a fresh WP_User, so user_pass is current
	wp_set_current_user( $uid );
}

function t_ip(): string {
	return '203.0.113.' . random_int( 1, 254 );
}

function t_forget_login_state( string $phone, array $ips ): void {
	CC_Rate_Limiter::reset( 'login_fail_phone|' . $phone );
	delete_transient( 'cc_login_until_' . md5( $phone ) );
	foreach ( $ips as $ip ) {
		CC_Rate_Limiter::reset( 'login_ip|' . $ip );
		CC_Rate_Limiter::reset( 'login_fail_pair|' . $phone . '|' . $ip );
		delete_transient( 'cc_login_trust_' . md5( $phone . '|' . $ip ) );
	}
}

echo "OTP hashing\n";
$hash = CC_Otp::hash( $phone, '123456' );
t_assert( 64 === strlen( $hash ) && ctype_xdigit( $hash ), 'hash is 64 hex chars' );
t_assert( $hash !== CC_Otp::hash( $phone, '123457' ) && $hash !== CC_Otp::hash( '+8801300000000', '123456' ), 'hash depends on phone and code' );

echo "OTP verify\n";
t_otp_row( $phone, '424242' );
t_assert( false === CC_Otp::verify( $phone, '000000' ), 'wrong code rejected' );
t_assert( true === CC_Otp::verify( $phone, '424242' ), 'right code accepted' );
t_assert( false === CC_Otp::verify( $phone, '424242' ), 'code is single use' );
t_clear_otp( $phone );

t_otp_row( $phone, '111111', array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ) );
t_assert( false === CC_Otp::verify( $phone, '111111' ), 'expired code rejected' );
t_clear_otp( $phone );

echo "OTP lockout\n";
$id = t_otp_row( $phone, '555555' );
for ( $i = 0; $i < 5; $i++ ) {
	CC_Otp::verify( $phone, '999999' );
}
t_assert( true === CC_Otp::is_locked( $phone ), 'locked after 5 wrong attempts' );
t_assert( false === CC_Otp::verify( $phone, '555555' ), 'correct code refused while locked' );
t_assert( false === CC_Otp::request( $phone ), 'new code refused while locked' );
$lock_end = strtotime( $wpdb->get_var( $wpdb->prepare( "SELECT expires_at FROM {$p}cc_otp WHERE id = %d", $id ) ) . ' UTC' );
t_assert( abs( $lock_end - ( time() + CC_Otp::LOCK_SECONDS ) ) < 10, 'lock lasts ~15 minutes' );
$wpdb->update( "{$p}cc_otp", array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ), array( 'id' => $id ) );
t_assert( false === CC_Otp::is_locked( $phone ), 'lock lifts after 15 minutes' );
t_clear_otp( $phone );

echo "OTP attempt claiming\n";
$id = t_otp_row( $phone, '616161', array( 'attempts' => 4 ) );
CC_Otp::verify( $phone, '000000' );
t_assert( 5 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT attempts FROM {$p}cc_otp WHERE id = %d", $id ) ), 'fifth wrong guess claims the last attempt' );
t_assert( false === CC_Otp::verify( $phone, '616161' ), 'no attempt left: even the right code is refused' );
t_assert( 5 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT attempts FROM {$p}cc_otp WHERE id = %d", $id ) ), 'attempts never exceed the cap' );
t_clear_otp( $phone );
t_otp_row( $phone, '717171', array( 'attempts' => 4 ) );
t_assert( true === CC_Otp::verify( $phone, '717171' ), 'right code on the last attempt is accepted' );
t_clear_otp( $phone );

echo "OTP cumulative failure cap (per phone)\n";
putenv( 'CC_RATE_LIMIT_DISABLED=0' );
$cap_phone = '+88013' . ( $tag + 1 );
CC_Rate_Limiter::reset( 'otp_fail|' . $cap_phone );
delete_transient( 'cc_otp_lock_' . md5( $cap_phone ) );
CC_Otp::verify( $cap_phone, '000000' );
t_assert( 0 === CC_Rate_Limiter::count( 'otp_fail|' . $cap_phone ), 'guess with no live code is not counted as a failure' );
for ( $i = 0; $i < CC_Otp::DAILY_FAIL_LIMIT; $i++ ) {
	t_otp_row( $cap_phone, '123123' ); // a fresh code each time: the per-code counter would reset, the daily one must not
	CC_Otp::verify( $cap_phone, '000000' );
}
t_assert( true === CC_Otp::is_daily_locked( $cap_phone ), 'locked after ' . CC_Otp::DAILY_FAIL_LIMIT . ' failures across different codes' );
t_otp_row( $cap_phone, '321321' );
t_assert( false === CC_Otp::verify( $cap_phone, '321321' ), 'verify refused while daily-locked' );
t_assert( false === CC_Otp::request( $cap_phone ), 'request refused while daily-locked' );
$lock_ttl = (int) get_option( '_transient_timeout_cc_otp_lock_' . md5( $cap_phone ) ) - time();
t_assert( abs( $lock_ttl - CC_Otp::DAILY_LOCK_SECONDS ) < 10, 'daily lock lasts ~24 hours' );
CC_Rate_Limiter::reset( 'otp_fail|' . $cap_phone );
delete_transient( 'cc_otp_lock_' . md5( $cap_phone ) );
t_clear_otp( $cap_phone );
putenv( 'CC_RATE_LIMIT_DISABLED=1' );

echo "OTP request\n";
t_assert( true === CC_Otp::request( $phone ), 'request issues a code' );
$active = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}cc_otp WHERE phone = %s AND used_at IS NULL", $phone ) );
t_assert( 1 === $active, 'one active row' );
CC_Otp::request( $phone );
$active = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}cc_otp WHERE phone = %s AND used_at IS NULL", $phone ) );
t_assert( 1 === $active, 'a new request supersedes the previous code' );
$stored = (string) $wpdb->get_var( $wpdb->prepare( "SELECT code_hash FROM {$p}cc_otp WHERE phone = %s AND used_at IS NULL", $phone ) );
t_assert( 1 !== preg_match( '/^\d{6}$/', $stored ), 'plain code is not stored' );
t_clear_otp( $phone );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}cc_sms_log WHERE related_type = 'otp' AND to_phone = %s", $phone ) );

echo "OTP cleanup\n";
t_otp_row( $phone, '222222', array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 7200 ) ) );
CC_Otp::cleanup();
t_assert( 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}cc_otp WHERE phone = %s", $phone ) ), 'old expired rows removed' );

echo "Rate limiter counters\n";
putenv( 'CC_RATE_LIMIT_DISABLED=0' );
$bucket = 'test|' . $tag;
CC_Rate_Limiter::reset( $bucket );
t_assert( 0 === CC_Rate_Limiter::count( $bucket ), 'empty bucket counts 0' );
t_assert( 1 === CC_Rate_Limiter::hit( $bucket, 60 ) && 2 === CC_Rate_Limiter::hit( $bucket, 60 ) && 3 === CC_Rate_Limiter::hit( $bucket, 60 ), 'hit increments one at a time' );
t_assert( 3 === CC_Rate_Limiter::count( $bucket ), 'count reads without consuming' );
t_assert( false === CC_Rate_Limiter::allow( $bucket, 3, 60 ), 'allow refuses once the limit is passed' );
$wpdb->update( $wpdb->options, array( 'option_value' => ( time() - 5 ) . '|9' ), array( 'option_name' => CC_Rate_Limiter::OPTION_PREFIX . md5( $bucket ) ) );
t_assert( 0 === CC_Rate_Limiter::count( $bucket ), 'expired window counts 0' );
t_assert( 1 === CC_Rate_Limiter::hit( $bucket, 60 ), 'expired window restarts at 1' );
$wpdb->update( $wpdb->options, array( 'option_value' => ( time() - 5 ) . '|9' ), array( 'option_name' => CC_Rate_Limiter::OPTION_PREFIX . md5( $bucket ) ) );
CC_Rate_Limiter::cleanup();
t_assert( null === $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", CC_Rate_Limiter::OPTION_PREFIX . md5( $bucket ) ) ), 'cleanup removes expired counters' );
putenv( 'CC_RATE_LIMIT_DISABLED=1' );
t_assert( true === CC_Rate_Limiter::allow( $bucket, 0, 60 ) && 0 === CC_Rate_Limiter::hit( $bucket, 60 ), 'local switch disables limiting' );

echo "Login\n";
$unknown  = t_call( '/auth/login', array( 'phone' => '01399999999', 'password' => 'whatever-123' ) );
$wrong_pw = t_call( '/auth/login', array( 'phone' => $phone, 'password' => 'wrong-password' ) );
$bad_fmt  = t_call( '/auth/login', array( 'phone' => 'abc', 'password' => 'x' ) );
$admin    = t_call( '/auth/login', array( 'phone' => "zzadmin$tag", 'password' => $password ) );
$expired  = t_call( '/auth/login', array( 'phone' => $expired_phone, 'password' => $password ) );
foreach ( array( 'unknown phone' => $unknown, 'wrong password' => $wrong_pw, 'malformed phone' => $bad_fmt, 'expired temp password' => $expired ) as $label => $resp ) {
	t_assert( 401 === $resp->get_status() && $resp->get_data() === $unknown->get_data(), "uniform 401 body: $label" );
}
t_assert( 'Invalid phone or password' === $unknown->get_data()['message'], 'uniform message text' );
t_assert( 401 === $admin->get_status() && $admin->get_data() === $unknown->get_data(), 'non-student account cannot log in' );
t_assert( 'private, no-store' === $wrong_pw->get_headers()['Cache-Control'], 'login error is no-store' );

$ok = t_call( '/auth/login', array( 'phone' => substr( $phone, 3 ), 'password' => $password ) );
t_assert( 200 === $ok->get_status(), 'student logs in with local-format phone' );
t_assert( '/student/profile/?change=1' === ( $ok->get_data()['redirect'] ?? '' ), 'must_change_pw redirects to profile change' );
t_assert( 'private, no-store' === $ok->get_headers()['Cache-Control'], 'login success is no-store' );

echo "Timing side-channel\n";
$dummy = CC_Rest_Auth::DUMMY_HASH;
t_assert( str_starts_with( $dummy, '$wp$2y$' ) === str_starts_with( wp_hash_password( 'x' ), '$wp$2y$' ), 'dummy hash uses the same algorithm as real hashes' );
t_assert( false === wp_check_password( 'anything', $dummy ) && false === wp_check_password( '', $dummy ), 'dummy hash matches nothing' );
$t0 = microtime( true );
t_call( '/auth/login', array( 'phone' => '01399999999', 'password' => 'whatever-123' ) );
$unknown_secs = microtime( true ) - $t0;
$t0 = microtime( true );
t_call( '/auth/login', array( 'phone' => $phone, 'password' => 'wrong-password' ) );
$known_secs = microtime( true ) - $t0;
t_assert( abs( $unknown_secs - $known_secs ) < 0.15, sprintf( 'unknown vs known phone login time is comparable (%.0f ms vs %.0f ms)', $unknown_secs * 1000, $known_secs * 1000 ) );

echo "OTP login routes\n";
$t0          = microtime( true );
$req_known   = t_call( '/auth/otp/request', array( 'phone' => $phone ) );
$known_secs  = microtime( true ) - $t0;
$t0          = microtime( true );
$req_unknown = t_call( '/auth/otp/request', array( 'phone' => '01388888888' ) );
$unknown_secs = microtime( true ) - $t0;
t_assert( 200 === $req_known->get_status() && $req_known->get_data() === $req_unknown->get_data(), 'otp request response identical for registered and unknown phone' );
t_assert( $known_secs >= CC_Rest_Auth::OTP_MIN_SECONDS - 0.02 && $unknown_secs >= CC_Rest_Auth::OTP_MIN_SECONDS - 0.02, 'otp request takes at least the padded minimum for both' );
t_assert( abs( $known_secs - $unknown_secs ) < 0.15, sprintf( 'otp request time is uniform (%.0f ms vs %.0f ms)', $known_secs * 1000, $unknown_secs * 1000 ) );
t_assert( 0 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}cc_otp WHERE phone = '+8801388888888'" ), 'no code row created for unknown phone' );
t_assert( 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}cc_otp WHERE phone = %s", $phone ) ), 'code row created for registered phone' );
t_assert( 422 === t_call( '/auth/otp/request', array( 'phone' => 'nope' ) )->get_status(), 'otp request rejects malformed phone' );
t_clear_otp( $phone );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}cc_sms_log WHERE related_type = 'otp' AND to_phone = %s", $phone ) );

t_otp_row( $phone, '314159' );
$bad  = t_call( '/auth/otp/verify', array( 'phone' => $phone, 'code' => '000000' ) );
$bad2 = t_call( '/auth/otp/verify', array( 'phone' => '01388888888', 'code' => '000000' ) );
t_assert( 401 === $bad->get_status() && $bad->get_data() === $bad2->get_data(), 'verify error identical for wrong code and unknown phone' );
t_assert( 401 === t_call( '/auth/otp/verify', array( 'phone' => $phone, 'code' => '12ab56' ) )->get_status(), 'non-numeric code rejected' );
$good = t_call( '/auth/otp/verify', array( 'phone' => $phone, 'code' => '314159' ) );
t_assert( 200 === $good->get_status() && isset( $good->get_data()['redirect'] ), 'correct code signs in' );
t_assert( 401 === t_call( '/auth/otp/verify', array( 'phone' => $phone, 'code' => '314159' ) )->get_status(), 'code cannot be replayed' );
t_clear_otp( $phone );

echo "Password change\n";
wp_set_current_user( 0 );
unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
t_assert( in_array( t_call( '/me/password', array( 'current_password' => $password, 'new_password' => 'Brand-New-Pass-1' ) )->get_status(), array( 401, 403 ), true ), 'anonymous cannot change password' );
wp_set_current_user( $admin_id );
t_assert( 403 === t_call( '/me/password', array( 'current_password' => $password, 'new_password' => 'Brand-New-Pass-1' ) )->get_status(), 'non-student cannot use /me/password' );
wp_set_current_user( $user_id );
$short = t_call( '/me/password', array( 'current_password' => $password, 'new_password' => 'short' ) );
t_assert( 422 === $short->get_status() && 'weak_password' === $short->get_data()['code'], 'password under 10 chars rejected by the handler' );
t_assert( 'Your new password must be at least 10 characters.' === $short->get_data()['message'], 'short password gets the friendly message' );
t_assert( 403 === t_call( '/me/password', array( 'current_password' => 'not-it-at-all', 'new_password' => 'Brand-New-Pass-1' ) )->get_status(), 'wrong current password rejected' );
t_assert( 422 === t_call( '/me/password', array( 'current_password' => $password, 'new_password' => $password ) )->get_status(), 'reusing the password rejected' );
$other_token = WP_Session_Tokens::get_instance( $user_id )->create( time() + 3600 );
$changed     = t_call( '/me/password', array( 'current_password' => $password, 'new_password' => 'Brand-New-Pass-1' ) );
t_assert( 200 === $changed->get_status(), 'password change succeeds' );
$row = CC_Enrollment_Repository::student_row( $user_id );
t_assert( 0 === (int) $row['must_change_pw'], 'must_change_pw cleared' );
t_assert( null === $row['temp_pw_expires'], 'temp_pw_expires cleared' );
t_assert( null === WP_Session_Tokens::get_instance( $user_id )->get( $other_token ), 'other sessions destroyed' );
t_assert( 200 === t_call( '/auth/login', array( 'phone' => $phone, 'password' => 'Brand-New-Pass-1' ) )->get_status(), 'new password works' );
t_assert( '/student/' === t_call( '/auth/login', array( 'phone' => $phone, 'password' => 'Brand-New-Pass-1' ) )->get_data()['redirect'], 'redirect is /student/ after change' );

echo "Password change: fresh nonce and remember flag\n";
$pw1 = 'Brand-New-Pass-1';
$pw2 = 'Second-New-Pass-2';
$pw3 = 'Third-New-Pass-33';
clean_user_cache( $user_id );
$old_token = t_use_new_session( $user_id );
$old_nonce = wp_create_nonce( 'wp_rest' );
t_assert( 1 === wp_verify_nonce( $old_nonce, 'wp_rest' ) || 2 === wp_verify_nonce( $old_nonce, 'wp_rest' ), 'precondition: old nonce valid in old session' );
$r = t_call( '/me/password', array( 'current_password' => $pw1, 'new_password' => $pw2 ) );
$new_token = wp_get_session_token();
t_assert( 200 === $r->get_status() && is_string( $r->get_data()['nonce'] ?? null ) && '' !== $r->get_data()['nonce'], 'response carries a nonce' );
t_assert( $new_token !== $old_token && '' !== $new_token, 'a new session is current after the change' );
t_assert( false !== wp_verify_nonce( $r->get_data()['nonce'], 'wp_rest' ), 'returned nonce is valid for the new session' );
t_assert( false === wp_verify_nonce( $old_nonce, 'wp_rest' ), 'old nonce is stale after the change' );
$new_session = WP_Session_Tokens::get_instance( $user_id )->get( $new_token );
t_assert( ( $new_session['expiration'] - $new_session['login'] ) <= CC_Rest_Auth::REMEMBER_MIN_SPAN, 'non-remembered session stays short' );

clean_user_cache( $user_id );
t_use_new_session( $user_id, 14 * DAY_IN_SECONDS );
$r = t_call( '/me/password', array( 'current_password' => $pw2, 'new_password' => $pw3 ) );
$new_session = WP_Session_Tokens::get_instance( $user_id )->get( wp_get_session_token() );
t_assert( 200 === $r->get_status() && ( $new_session['expiration'] - $new_session['login'] ) > CC_Rest_Auth::REMEMBER_MIN_SPAN, 'remember flag survives a password change' );
$pw2 = $pw3;

echo "OTP re-authentication (session-bound)\n";
wp_set_current_user( 0 );
unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
t_assert( false === CC_Rest_Auth::otp_recent( $user_id ), 'no marker without a session' );
t_call( '/auth/login', array( 'phone' => $phone, 'password' => $pw2 ) );
t_assert( false === CC_Rest_Auth::otp_recent( $user_id ), 'password login never sets the marker' );
t_otp_row( $phone, '777777' );
t_call( '/auth/otp/verify', array( 'phone' => $phone, 'code' => '777777' ) );
t_assert( true === CC_Rest_Auth::otp_recent( $user_id ), 'OTP login sets the marker on its own session' );
$otp_token = wp_get_session_token();
t_call( '/auth/login', array( 'phone' => $phone, 'password' => $pw2 ) );
t_assert( false === CC_Rest_Auth::otp_recent( $user_id ), 'a later password-login session has no marker' );
t_assert( 403 === t_call( '/me/password', array( 'new_password' => 'Another-Pass-22' ) )->get_status(), 'other session cannot reuse the OTP marker' );
t_use_session( $user_id, $otp_token );
t_assert( true === CC_Rest_Auth::otp_recent( $user_id ), 'the OTP session still has its marker' );
unset( $_COOKIE[ LOGGED_IN_COOKIE ] );
t_assert( false === CC_Rest_Auth::otp_recent( $user_id ), 'no session token: reauth is not granted' );
t_assert( 403 === t_call( '/me/password', array( 'new_password' => 'Another-Pass-22' ) )->get_status(), 'no session token: current_password required' );

$mgr     = WP_Session_Tokens::get_instance( $user_id );
$session = $mgr->get( $otp_token );
$mgr->update( $otp_token, array_merge( $session, array( CC_Rest_Auth::REAUTH_KEY => time() - 1 ) ) );
t_use_session( $user_id, $otp_token );
t_assert( false === CC_Rest_Auth::otp_recent( $user_id ), 'expired marker does not count' );
t_assert( 403 === t_call( '/me/password', array( 'new_password' => 'Another-Pass-22' ) )->get_status(), 'expired marker: current_password required' );

$mgr->update( $otp_token, array_merge( $session, array( CC_Rest_Auth::REAUTH_KEY => time() + 600 ) ) );
t_assert( 422 === t_call( '/me/password', array( 'new_password' => 'short' ) )->get_status(), 'marker still needs a 10+ char password' );
t_assert( true === CC_Rest_Auth::otp_recent( $user_id ), 'rejected change does not consume the marker' );
t_assert( 200 === t_call( '/me/password', array( 'new_password' => 'Another-Pass-22' ) )->get_status(), 'OTP session changes password without current_password' );
t_assert( false === CC_Rest_Auth::otp_recent( $user_id ), 'marker gone after the change (new session)' );
t_assert( 403 === t_call( '/me/password', array( 'new_password' => 'Third-Pass-333' ) )->get_status(), 'second change requires current_password' );
clean_user_cache( $user_id );
wp_set_current_user( 0 );
wp_set_current_user( $user_id );
$r = t_call( '/me/password', array( 'current_password' => 'Another-Pass-22', 'new_password' => $pw1 ) );
t_assert( 200 === $r->get_status(), 'change with current_password still works ' . wp_json_encode( $r->get_data() ) );

echo "Logout\n";
t_use_new_session( $user_id );
t_assert( 200 === t_call( '/auth/logout', array() )->get_status(), 'logout succeeds' );
t_assert( 0 === get_current_user_id(), 'current user cleared' );

echo "Student guard\n";
t_assert( true === CC_Student_Guard::is_student( get_user_by( 'id', $user_id ) ) && false === CC_Student_Guard::is_student( get_user_by( 'id', $admin_id ) ), 'is_student distinguishes roles' );

echo "No-store coverage\n";
$server = rest_get_server();
foreach ( array( '/cc/v1/me/password' => 403, '/cc/v1/me/anything' => 401, '/cc/v1/auth/logout' => 403, '/cc/v1/auth/otp/verify' => 400 ) as $route => $status ) {
	$filtered = CC_Rest_Auth::no_store_on_errors( new WP_REST_Response( array( 'code' => 'x' ), $status ), $server, new WP_REST_Request( 'POST', $route ) );
	t_assert( 'private, no-store' === ( $filtered->get_headers()['Cache-Control'] ?? '' ), "$status on $route is no-store" );
}
$filtered = CC_Rest_Auth::no_store_on_errors( new WP_REST_Response( array(), 200 ), $server, new WP_REST_Request( 'GET', '/cc/v1/admissions/status' ) );
t_assert( ! isset( $filtered->get_headers()['Cache-Control'] ), 'unrelated routes are untouched' );

echo "Rate limit (login per IP)\n";
putenv( 'CC_RATE_LIMIT_DISABLED=0' );
$ip_a = t_ip();
$_SERVER['REMOTE_ADDR'] = $ip_a;
$codes                  = array();
for ( $i = 0; $i < CC_Rest_Auth::LOGIN_IP_LIMIT + 1; $i++ ) {
	$codes[] = t_call( '/auth/login', array( 'phone' => '013777777' . sprintf( '%02d', $i ), 'password' => 'nope-nope-nope' ) )->get_status();
}
t_assert( 429 === end( $codes ) && 10 === count( array_filter( $codes, static fn( $c ) => 401 === $c ) ), '11th login from one IP gets 429' );
CC_Rate_Limiter::reset( 'login_ip|' . $ip_a );
for ( $i = 0; $i < 11; $i++ ) {
	$n = sprintf( '%02d', $i );
	CC_Rate_Limiter::reset( 'login_fail_pair|+88013777777' . $n . '|' . $ip_a );
	CC_Rate_Limiter::reset( 'login_fail_phone|+88013777777' . $n );
	delete_transient( 'cc_login_until_' . md5( '+88013777777' . $n ) );
}

echo "Login throttling: failures only, per phone+IP, back-off\n";
$ip_owner    = t_ip();
$ip_attacker = '198.51.100.' . random_int( 1, 120 );
$ip_second   = '198.51.100.' . random_int( 130, 250 );
$ip_fresh    = '192.0.2.' . random_int( 1, 254 );
$all_ips     = array( $ip_owner, $ip_attacker, $ip_second, $ip_fresh );
t_forget_login_state( $phone, $all_ips );
$try = static function ( string $ip, string $pw ) use ( $phone ): int {
	$_SERVER['REMOTE_ADDR'] = $ip;
	return t_call( '/auth/login', array( 'phone' => $phone, 'password' => $pw ) )->get_status();
};
// A success never consumes failure allowance, however many there are.
$statuses = array();
for ( $i = 0; $i < 4; $i++ ) {
	$statuses[] = $try( $ip_owner, $pw1 );
}
t_assert( array( 200 ) === array_values( array_unique( $statuses ) ), 'repeated successful logins are never throttled' );

$attacker = array();
for ( $i = 0; $i < CC_Rest_Auth::LOGIN_PAIR_LIMIT + 1; $i++ ) {
	$attacker[] = $try( $ip_attacker, 'guess-guess-' . $i );
}
t_assert( 5 === count( array_filter( $attacker, static fn( $c ) => 401 === $c ) ) && 429 === end( $attacker ), 'attacker IP is stopped after 5 failures for that phone' );
t_assert( 200 === $try( $ip_owner, $pw1 ), 'owner on another IP is not affected by attacker failures (below back-off threshold)' );

// Push the phone over the free-failure threshold from several IPs.
t_forget_login_state( $phone, array( $ip_attacker, $ip_second, $ip_fresh ) );
for ( $i = 0; $i < CC_Rest_Auth::LOGIN_BACKOFF_FREE; $i++ ) {
	$try( $ip_attacker, 'guess-guess-' . $i );
}
t_assert( 401 === $try( $ip_second, 'guess-guess-x' ), 'sixth failure from another IP is still evaluated' );
$until = (int) get_transient( 'cc_login_until_' . md5( $phone ) );
t_assert( abs( $until - ( time() + CC_Rest_Auth::BACKOFF_BASE ) ) < 5, 'first back-off step is BACKOFF_BASE seconds' );
t_assert( 429 === $try( $ip_fresh, $pw1 ), 'during back-off an unknown IP is refused, even with the right password' );
t_assert( 429 === $try( $ip_second, 'guess-guess-y' ), 'refused attempts are not evaluated' );
t_assert( 401 === $try( $ip_owner, 'guess-guess-owner' ), 'known IP is still evaluated during back-off' );
$until2 = (int) get_transient( 'cc_login_until_' . md5( $phone ) );
t_assert( abs( $until2 - ( time() + 2 * CC_Rest_Auth::BACKOFF_BASE ) ) < 5, 'back-off doubles with each further failure' );
t_assert( 200 === $try( $ip_owner, $pw1 ), 'known IP with the right password signs in during back-off' );
t_assert( false === get_transient( 'cc_login_until_' . md5( $phone ) ), 'a successful login clears the back-off' );
t_assert( 200 === $try( $ip_fresh, $pw1 ), 'unknown IP can log in once back-off is cleared' );
t_forget_login_state( $phone, $all_ips );

echo "OTP request throttling does not let strangers block the owner\n";
$ip_x = t_ip();
$ip_y = '192.0.2.' . random_int( 1, 254 );
foreach ( array( $ip_x, $ip_y ) as $ip ) {
	CC_Rate_Limiter::reset( 'otp_req_ip|' . $ip );
	CC_Rate_Limiter::reset( 'otp_req_pair|' . $phone . '|' . $ip );
}
CC_Rate_Limiter::reset( 'otp_req_phone|' . $phone );
$_SERVER['REMOTE_ADDR'] = $ip_x;
$codes                  = array();
for ( $i = 0; $i < CC_Rest_Auth::OTP_PHONE_IP_LIMIT + 1; $i++ ) {
	$codes[] = t_call( '/auth/otp/request', array( 'phone' => $phone ) )->get_status();
}
t_assert( 429 === end( $codes ) && 3 === count( array_filter( $codes, static fn( $c ) => 200 === $c ) ), '4th otp request for one phone from one IP gets 429' );
$_SERVER['REMOTE_ADDR'] = $ip_y;
t_assert( 200 === t_call( '/auth/otp/request', array( 'phone' => $phone ) )->get_status(), 'the same phone can still request a code from another IP' );
foreach ( array( $ip_x, $ip_y ) as $ip ) {
	CC_Rate_Limiter::reset( 'otp_req_ip|' . $ip );
	CC_Rate_Limiter::reset( 'otp_req_pair|' . $phone . '|' . $ip );
}
CC_Rate_Limiter::reset( 'otp_req_phone|' . $phone );
t_clear_otp( $phone );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}cc_sms_log WHERE related_type = 'otp' AND to_phone = %s", $phone ) );
putenv( 'CC_RATE_LIMIT_DISABLED=1' );
unset( $_SERVER['REMOTE_ADDR'] );

// Cleanup.
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ( array( $user_id, $admin_id, $expired_id ) as $uid ) {
	$wpdb->delete( "{$p}cc_students", array( 'user_id' => $uid ) );
	wp_delete_user( $uid );
}
t_clear_otp( $phone );
delete_transient( 'cc_login_trust_' . md5( $phone . '|0.0.0.0' ) );
delete_transient( 'cc_login_trust_' . md5( $phone . '|' . ( $_SERVER['REMOTE_ADDR'] ?? '' ) ) );
if ( $added_role ) {
	remove_role( 'cc_student' );
}

echo "\n$checks checks, $failures failures\n";
exit( $failures ? 1 : 0 );
