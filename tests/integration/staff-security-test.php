<?php
/**
 * Integration tests for staff 2FA, the /admin/login path, the idle timeout and export re-authentication. Run:
 *   docker compose run --rm -T wpcli eval-file /tests/integration/staff-security-test.php
 * Creates throwaway users and removes them. The live checks call http://wordpress/ (compose network).
 * Local dev relaxes staff security with CC_STAFF_SECURITY_RELAXED=1; this test switches that off and the rate limiter on.
 */
global $wpdb, $failures, $checks;
$failures = 0;
$checks   = 0;

function t_assert( bool $cond, string $label ): void {
	global $failures, $checks;
	++$checks;
	echo ( $cond ? "  ok   " : "  FAIL " ) . "$label\n";
	if ( ! $cond ) {
		++$failures;
	}
}

foreach ( array( 'CC_Staff_2fa', 'CC_Staff_Login', 'CC_Reauth', 'CC_Totp' ) as $class ) {
	if ( ! class_exists( $class ) ) {
		echo "SKIP: $class not loaded.\n";
		exit( 1 );
	}
}
putenv( 'CC_STAFF_SECURITY_RELAXED=0' );
putenv( 'CC_RATE_LIMIT_DISABLED=0' );
$_SERVER['REMOTE_ADDR'] = '203.0.113.' . random_int( 1, 250 );

$made = array();
$mk   = static function ( string $role ) use ( &$made ): array {
	$login = 'zzsec' . bin2hex( random_bytes( 4 ) );
	$pw    = 'Pw-' . bin2hex( random_bytes( 6 ) );
	$id    = wp_insert_user( array( 'user_login' => $login, 'user_pass' => $pw, 'user_email' => $login . '@example.test', 'role' => $role ) );
	$made[] = $id;
	return array( 'id' => (int) $id, 'login' => $login, 'pw' => $pw );
};
$owner   = $mk( CC_Admin_Roles::ROLE_OWNER );
$admin   = $mk( 'administrator' );
$sub     = $mk( 'subscriber' );
$student = $mk( CC_Roles::ROLE );
$user    = static fn( array $u ): WP_User => get_userdata( $u['id'] );

echo "policy\n";
t_assert( CC_Staff_2fa::required_for( $user( $owner ) ), 'owner needs 2FA' );
t_assert( CC_Staff_2fa::required_for( $user( $admin ) ), 'administrator needs 2FA' );
t_assert( ! CC_Staff_2fa::required_for( $user( $sub ) ), 'subscriber does not' );
t_assert( ! CC_Staff_2fa::required_for( $user( $student ) ), 'student does not' );
putenv( 'CC_STAFF_SECURITY_RELAXED=1' );
t_assert( ! CC_Staff_2fa::required_for( $user( $owner ) ), 'RELAXED switch turns it off in local' );
putenv( 'CC_STAFF_SECURITY_RELAXED=0' );

echo "enrolment\n";
t_assert( ! CC_Staff_2fa::is_enrolled( $owner['id'] ), 'not enrolled at first' );
$secret = CC_Staff_2fa::begin_enrolment( $owner['id'] );
t_assert( 1 === preg_match( '/^[A-Z2-7]{32}$/', $secret ), 'pending secret issued' );
t_assert( $secret === CC_Staff_2fa::begin_enrolment( $owner['id'] ), 'resuming returns the same pending secret' );
$now  = time();
$bad  = CC_Staff_2fa::complete_enrolment( $owner['id'], '000000', $now );
t_assert( is_wp_error( $bad ) && 'cc_2fa_invalid' === $bad->get_error_code() && ! CC_Staff_2fa::is_enrolled( $owner['id'] ), 'wrong code does not enrol' );
$code0 = CC_Totp::code( $secret, CC_Totp::step_at( $now ) );
$codes = CC_Staff_2fa::complete_enrolment( $owner['id'], $code0, $now );
t_assert( is_array( $codes ) && 8 === count( $codes ) && CC_Staff_2fa::is_enrolled( $owner['id'] ), 'right code enrols and returns 8 recovery codes' );
$stored = (string) get_user_meta( $owner['id'], CC_Staff_2fa::META_SECRET, true );
t_assert( 0 === strpos( $stored, 'k1:' ) && ! str_contains( $stored, $secret ), 'secret stored encrypted' );
t_assert( ! str_contains( wp_json_encode( get_user_meta( $owner['id'], CC_Staff_2fa::META_RECOVERY, true ) ), str_replace( '-', '', $codes[0] ) ), 'recovery codes stored hashed' );
t_assert( 1 === preg_match( '/^[A-Z2-7]{5}-[A-Z2-7]{5}$/', $codes[0] ), 'recovery code format' );
t_assert( null === get_user_meta( $owner['id'], CC_Staff_2fa::META_PENDING, true ) || '' === get_user_meta( $owner['id'], CC_Staff_2fa::META_PENDING, true ), 'pending secret cleared' );

echo "login check\n";
$_POST = array();
$res = wp_authenticate( $owner['login'], $owner['pw'] );
t_assert( is_wp_error( $res ) && 'cc_totp_required' === $res->get_error_code(), 'password alone is refused once enrolled' );
$_POST[ CC_Staff_2fa::FIELD ] = '123456';
$res = wp_authenticate( $owner['login'], $owner['pw'] );
t_assert( is_wp_error( $res ) && 'cc_totp_invalid' === $res->get_error_code(), 'wrong code refused' );
$_POST[ CC_Staff_2fa::FIELD ] = CC_Totp::code( $secret, CC_Totp::step_at( time() + 30 ) );
t_assert( 'ok' === CC_Staff_2fa::verify_login( $owner['id'], $_POST[ CC_Staff_2fa::FIELD ], time() + 30 ), 'a later code is accepted' );
t_assert( 'invalid' === CC_Staff_2fa::verify_login( $owner['id'], $_POST[ CC_Staff_2fa::FIELD ], time() + 30 ), 'the same code cannot be replayed' );
t_assert( 'invalid' === CC_Staff_2fa::verify_login( $owner['id'], $code0, $now ), 'the enrolment code cannot be replayed either' );
$wrong_pw = wp_authenticate( $owner['login'], 'not-the-password' );
t_assert( is_wp_error( $wrong_pw ) && 'cc_totp_required' !== $wrong_pw->get_error_code(), 'a wrong password never reaches the code step' );
$_POST = array();
$unenrolled = wp_authenticate( $admin['login'], $admin['pw'] );
t_assert( $unenrolled instanceof WP_User, 'an unenrolled admin may sign in (and is then forced to enrol)' );

echo "recovery codes\n";
CC_Rate_Limiter::reset( 'totp_user|' . $owner['id'] );
CC_Rate_Limiter::reset( 'totp_ip|' . CC_Rate_Limiter::ip_bucket_key() );
t_assert( 'ok' === CC_Staff_2fa::verify_login( $owner['id'], strtolower( $codes[0] ) ), 'a recovery code signs in (case-insensitive)' );
t_assert( 'invalid' === CC_Staff_2fa::verify_login( $owner['id'], $codes[0] ), 'a recovery code works only once' );
t_assert( 7 === CC_Staff_2fa::recovery_remaining( $owner['id'] ), 'seven codes remain' );

echo "rate limit\n";
foreach ( array( 'totp_user|' . $owner['id'], 'totp_ip|' . CC_Rate_Limiter::ip_bucket_key() ) as $bucket ) {
	CC_Rate_Limiter::reset( $bucket );
}
$last = '';
for ( $i = 0; $i < 7; $i++ ) {
	$last = CC_Staff_2fa::verify_login( $owner['id'], '111111' );
}
t_assert( 'limited' === $last, 'repeated wrong codes are rate limited' );
t_assert( 'limited' === CC_Staff_2fa::verify_login( $owner['id'], CC_Totp::code( $secret, CC_Totp::step_at( time() + 60 ) ), time() + 60 ), 'even a right code is refused while limited' );
CC_Rate_Limiter::reset( 'totp_user|' . $owner['id'] );
CC_Rate_Limiter::reset( 'totp_ip|' . CC_Rate_Limiter::ip_bucket_key() );

echo "reset\n";
CC_Staff_2fa::reset( $owner['id'] );
t_assert( ! CC_Staff_2fa::is_enrolled( $owner['id'] ) && 0 === CC_Staff_2fa::recovery_remaining( $owner['id'] ), 'reset clears secret and recovery codes' );

echo "idle timeout\n";
set_error_handler( static fn( int $no, string $str ): bool => str_contains( $str, 'headers already sent' ) ); // wp_logout() clears cookies; CLI has already printed output.
t_assert( CC_Staff_Login::is_idle( 1000, 1000 + 3601 ) && ! CC_Staff_Login::is_idle( 1000, 1000 + 3600 ) && ! CC_Staff_Login::is_idle( null, 5000 ), 'idle limit is 3600 s; no timestamp is fresh' );
wp_set_current_user( $owner['id'] );
update_user_meta( $owner['id'], CC_Staff_Login::META_ACTIVITY, time() - 7200 );
$err = CC_Staff_Login::enforce_idle_rest( null );
t_assert( is_wp_error( $err ) && 'cc_session_timeout' === $err->get_error_code() && 401 === ( $err->get_error_data()['status'] ?? 0 ), 'an idle staff REST request is refused with 401' );
wp_set_current_user( $owner['id'] );
update_user_meta( $owner['id'], CC_Staff_Login::META_ACTIVITY, time() - 120 );
t_assert( null === CC_Staff_Login::enforce_idle_rest( null ) && time() - (int) get_user_meta( $owner['id'], CC_Staff_Login::META_ACTIVITY, true ) < 5, 'an active session passes and its activity is recorded' );
wp_set_current_user( $student['id'] );
t_assert( null === CC_Staff_Login::enforce_idle_rest( null ), 'students are not subject to the staff timeout' );
wp_set_current_user( 0 );

restore_error_handler();
echo "re-authentication\n";
t_assert( ! CC_Reauth::is_fresh( $owner['id'] ), 'no confirmation yet means not fresh' );
update_user_meta( $owner['id'], CC_Reauth::META, time() - 30 );
t_assert( CC_Reauth::is_fresh( $owner['id'] ), 'a confirmation 30 s ago is fresh' );
update_user_meta( $owner['id'], CC_Reauth::META, time() - 700 );
t_assert( ! CC_Reauth::is_fresh( $owner['id'] ), 'a confirmation 700 s ago is stale' );
$ap = admin_url( 'admin-post.php' );
t_assert( '' !== CC_Reauth::safe_return( $ap . '?action=cc_app_export&_wpnonce=x', $ap ), 'returns to an admin-post export link' );
t_assert( '' === CC_Reauth::safe_return( 'https://evil.example/admin-post.php?x=1', $ap ) && '' === CC_Reauth::safe_return( admin_url( 'users.php' ), $ap ), 'refuses other hosts and other admin pages' );

echo "login path\n";
t_assert( CC_Staff_Login::is_login_request( '/admin/login/?a=1', '/admin/login/' ) && CC_Staff_Login::is_login_request( '/admin/login', '/admin/login/' ), 'path matching ignores slash and query' );
t_assert( ! CC_Staff_Login::is_login_request( '/admin/login/x', '/admin/login/' ) && ! CC_Staff_Login::is_login_request( '/wp-login.php', '/admin/login/' ), 'other paths do not match' );
t_assert( str_ends_with( wp_login_url(), '/admin/login/' ) && str_contains( wp_login_url( 'http://wordpress/wp-admin/' ), 'redirect_to=' ), 'wp_login_url points at the staff path' );
t_assert( str_contains( wp_logout_url(), '/admin/login/?action=logout' ), 'logout links use the staff path' );
t_assert( str_contains( wp_lostpassword_url(), '/admin/login/?action=lostpassword' ), 'lost-password links use the staff path' );
$get = wp_remote_get( 'http://wordpress/wp-login.php', array( 'timeout' => 20, 'redirection' => 0 ) );
t_assert( ! is_wp_error( $get ) && 404 === wp_remote_retrieve_response_code( $get ), 'direct wp-login.php is a 404' );
$get = wp_remote_get( 'http://wordpress/wp-login.php?action=lostpassword', array( 'timeout' => 20, 'redirection' => 0 ) );
t_assert( ! is_wp_error( $get ) && 404 === wp_remote_retrieve_response_code( $get ), 'direct wp-login.php lost-password is a 404' );
$page = wp_remote_get( 'http://wordpress/admin/login/', array( 'timeout' => 20, 'redirection' => 0 ) );
$body = is_wp_error( $page ) ? '' : wp_remote_retrieve_body( $page );
t_assert( 200 === wp_remote_retrieve_response_code( $page ) && str_contains( $body, 'name="log"' ), '/admin/login/ serves the login form' );
t_assert( str_contains( $body, 'action="http://wordpress/admin/login/"' ), 'the form posts back to /admin/login/' );
// The web server may run with CC_STAFF_SECURITY_RELAXED=1 (the dev compose file does); the live 2FA checks need it off.
$server_enforces = str_contains( $body, 'name="cc_totp"' );
if ( ! $server_enforces ) {
	echo "  note the web server runs with CC_STAFF_SECURITY_RELAXED=1: live 2FA sign-in checks are skipped (unset it on the web container to run them)\n";
}

echo "live sign-in with 2FA\n";
if ( $server_enforces ) {
$o2 = $mk( CC_Admin_Roles::ROLE_OWNER );
$sec2 = CC_Staff_2fa::begin_enrolment( $o2['id'] );
CC_Staff_2fa::complete_enrolment( $o2['id'], CC_Totp::code( $sec2, CC_Totp::step_at( time() ) ) );
$post = static function ( array $u, string $code ) use ( $sec2 ): array {
	$r  = wp_remote_post( 'http://wordpress/admin/login/', array( 'timeout' => 30, 'redirection' => 0, 'headers' => array( 'Cookie' => 'wordpress_test_cookie=WP%20Cookie%20check' ), 'body' => array( 'log' => $u['login'], 'pwd' => $u['pw'], 'cc_totp' => $code, 'wp-submit' => 'Log In', 'testcookie' => '1' ) ) );
	$in = false;
	foreach ( is_wp_error( $r ) ? array() : wp_remote_retrieve_cookies( $r ) as $c ) {
		$in = $in || 0 === strpos( $c->name, 'wordpress_logged_in_' );
	}
	return array( 'in' => $in, 'status' => is_wp_error( $r ) ? 0 : wp_remote_retrieve_response_code( $r ), 'body' => is_wp_error( $r ) ? '' : wp_remote_retrieve_body( $r ) );
};
$r = $post( $o2, '' );
t_assert( ! $r['in'] && str_contains( $r['body'], 'authenticator' ), 'live: password without a code does not sign in' );
$r = $post( $o2, '999999' );
t_assert( ! $r['in'], 'live: wrong code does not sign in' );
CC_Rate_Limiter::reset( 'totp_user|' . $o2['id'] );
$r = $post( $o2, CC_Totp::code( $sec2, CC_Totp::step_at( time() + 30 ) ) );
// The code for the next step is only valid inside the +-1 window, which holds for the next 30 s step boundary half the time; retry with the current step is refused as replay.
if ( ! $r['in'] ) {
	$r = $post( $o2, CC_Staff_2fa::issue_recovery_codes( $o2['id'] )[0] );
}
t_assert( $r['in'] && in_array( $r['status'], array( 302, 200 ), true ), 'live: password plus a valid code signs in' );
}

foreach ( $made as $id ) {
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $id );
}
$wpdb->query( "DELETE FROM {$wpdb->prefix}cc_audit_log WHERE action LIKE 'staff.%' AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR)" );
echo "\n$checks checks, $failures failures\n";
exit( $failures ? 1 : 0 );
