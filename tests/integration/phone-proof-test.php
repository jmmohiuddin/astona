<?php
/**
 * Integration tests for phone ownership proof at application (CC_Rest_Phone_Proof, CC_Phone_Proof, 'apply' OTP purpose).
 *   docker compose run --rm -T wpcli eval-file /tests/integration/phone-proof-test.php
 * Request/confirm and the limiter run in-process (rest_do_request). Creating applications needs a real multipart upload, so
 * those checks call the running site over the compose network (http://wordpress, Host: localhost:8080); that server runs with
 * CC_RATE_LIMIT_DISABLED=1, so its own limits do not interfere. Everything created here is removed at the end.
 * The fake SMS outbox option legitimately holds sent codes (it is the local fake driver), so the "nothing stored" checks skip it.
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

foreach ( array( 'CC_Otp', 'CC_Rest_Phone_Proof', 'CC_Phone_Proof', 'CC_Rest_Admissions', 'CC_Sms', 'CC_Rate_Limiter' ) as $class ) {
	if ( ! class_exists( $class ) ) {
		echo "SKIP: $class not loaded; cannot run phone-proof tests.\n";
		exit( 1 );
	}
}
do_action( 'rest_api_init' );

const T_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

$tag          = (string) random_int( 10000000, 99999999 );
$err_log_file = tempnam( sys_get_temp_dir(), 'ccpp' );
$old_err_log  = ini_get( 'error_log' );
ini_set( 'error_log', $err_log_file ); // phpcs:ignore WordPress.PHP.IniSet.Risky
$audit_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}cc_audit_log" );
$cleanup      = array( 'phones' => array(), 'batches' => array(), 'users' => array(), 'ips' => array(), 'buckets' => array() );

function t_phone( string $prefix = '015' ): string {
	global $cleanup;
	$phone                = '+880' . substr( $prefix, 1 ) . str_pad( (string) random_int( 0, 99999999 ), 8, '0', STR_PAD_LEFT );
	$cleanup['phones'][] = $phone;
	return $phone;
}

function t_call( string $path, array $params ): WP_REST_Response {
	$request = new WP_REST_Request( 'POST', '/cc/v1/applications/verify-phone/' . $path );
	$request->set_header( 'content-type', 'application/json' );
	$request->set_body( wp_json_encode( $params ) );
	return rest_do_request( $request );
}

function t_ip( string $ip ): void {
	global $cleanup;
	$_SERVER['REMOTE_ADDR'] = $ip;
	$cleanup['ips'][]       = $ip;
}

/** Sends the queued apply_otp SMS through the fake driver (cron may have beaten us to it) and returns the code from the outbox. */
function t_code( string $phone ): string {
	global $wpdb;
	$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}cc_sms_log WHERE to_phone = %s AND template = 'apply_otp' ORDER BY id DESC LIMIT 1", $phone ) );
	putenv( 'CC_SMS_PRIMARY=fake' );
	CC_Sms::send( $id );
	putenv( 'CC_SMS_PRIMARY' );
	$mine = array_values( array_filter( CC_Sms_Fake_Driver::outbox(), static fn( $m ) => $m['to'] === $phone ) );
	$last = $mine ? end( $mine ) : array( 'body' => '' );
	return 1 === preg_match( '/code for your admission application is (\d{6})/', $last['body'], $m ) ? $m[1] : '';
}

function t_proof_for( string $phone ): string {
	t_call( 'request', array( 'phone' => $phone ) );
	$r = t_call( 'confirm', array( 'phone' => $phone, 'code' => t_code( $phone ) ) );
	return (string) ( $r->get_data()['phone_proof'] ?? '' );
}

/** Request a code with the per-phone resend gap cleared (the gap has its own tests). */
function t_req( string $phone ): WP_REST_Response {
	CC_Rate_Limiter::reset( 'apply_otp_gap|' . $phone );
	return t_call( 'request', array( 'phone' => $phone ) );
}

/** One wrong guess against a fresh code from the given IP. */
function t_fail_round( string $phone, string $ip ): WP_REST_Response {
	global $wpdb;
	$p = $wpdb->prefix;
	$wpdb->update( "{$p}cc_otp", array( 'used_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'phone' => $phone, 'purpose' => 'apply' ) );
	$wpdb->insert( "{$p}cc_otp", array( 'phone' => $phone, 'code_hash' => CC_Otp::hash( $phone, '123123' ), 'purpose' => 'apply', 'attempts' => 0, 'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 300 ), 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
	t_ip( $ip );
	return t_call( 'confirm', array( 'phone' => $phone, 'code' => '000000' ) );
}

function t_limits_on( bool $on ): void {
	putenv( 'CC_RATE_LIMIT_DISABLED=' . ( $on ? '0' : '1' ) );
}

/** @return array{0:int,1:array} */
function t_http_apply( array $fields, ?string $key, bool $photo = true ): array {
	$boundary = '----ccpp' . bin2hex( random_bytes( 8 ) );
	$body     = '';
	foreach ( $fields as $name => $value ) {
		$body .= "--$boundary\r\nContent-Disposition: form-data; name=\"$name\"\r\n\r\n$value\r\n";
	}
	if ( $photo ) {
		$body .= "--$boundary\r\nContent-Disposition: form-data; name=\"photo\"; filename=\"p.png\"\r\nContent-Type: image/png\r\n\r\n" . base64_decode( T_PNG ) . "\r\n";
	}
	$body   .= "--$boundary--\r\n";
	$headers = array( 'Host' => 'localhost:8080', 'Content-Type' => 'multipart/form-data; boundary=' . $boundary );
	if ( null !== $key ) {
		$headers['Idempotency-Key'] = $key;
	}
	$response = wp_remote_post( 'http://wordpress/index.php?rest_route=/cc/v1/applications', array( 'headers' => $headers, 'body' => $body, 'timeout' => 30 ) );
	if ( is_wp_error( $response ) ) {
		return array( 0, array( 'error' => $response->get_error_message() ) );
	}
	return array( (int) wp_remote_retrieve_response_code( $response ), (array) json_decode( wp_remote_retrieve_body( $response ), true ) );
}

function t_fields( int $batch, string $student_phone, array $over = array() ): array {
	return array_merge( array(
		'batch_id' => (string) $batch, 'full_name' => 'ZZ Proof Test', 'gender' => 'm', 'dob' => '2008-05-17', 'id_doc_type' => 'birth_cert',
		'id_doc_number' => 'BC12345678', 'student_phone' => $student_phone, 'guardian_name' => 'ZZ Guardian', 'guardian_phone' => '01811223344',
		'institution' => 'Test School', 'class_level' => 'Class 9', 'consent' => '1',
	), $over );
}

function t_forge( array $payload, bool $sign = true ): string {
	$b64 = rtrim( strtr( base64_encode( (string) wp_json_encode( $payload ) ), '+/', '-_' ), '=' );
	$sig = $sign ? hash_hmac( 'sha256', $b64, hash_hmac( 'sha256', 'cc_phone_proof', wp_salt( 'auth' ) ) ) : str_repeat( '0', 64 );
	return $b64 . '.' . $sig;
}

// Fixtures: one open batch, one registered student.
$now = gmdate( 'Y-m-d H:i:s' );
$wpdb->insert( "{$p}cc_batches", array(
	'course_id' => 0, 'name' => "ZZ-PROOF $tag", 'capacity' => 50, 'seats_taken' => 0, 'price' => 1000, 'start_date' => '2030-01-01',
	'status' => 'open', 'application_open' => 1, 'created_at' => $now, 'updated_at' => $now,
) );
$batch                = (int) $wpdb->insert_id;
$cleanup['batches'][] = $batch;

$added_role = false;
if ( ! get_role( 'cc_student' ) ) {
	add_role( 'cc_student', 'Student', array( 'read' => true ) );
	$added_role = true;
}
$registered = t_phone( '013' );
$uid        = wp_insert_user( array( 'user_login' => ltrim( $registered, '+' ), 'user_pass' => 'Temp-Pass-12345', 'user_email' => "zzpp$tag@students.invalid", 'role' => 'cc_student' ) );
$cleanup['users'][] = $uid;
$wpdb->insert( "{$p}cc_students", array( 'user_id' => $uid, 'full_name' => 'ZZ Registered', 'must_change_pw' => 0, 'created_at' => $now ) );

t_limits_on( false );
t_ip( '198.51.100.1' );

echo "schema\n";
$col = $wpdb->get_row( "SHOW COLUMNS FROM {$p}cc_otp LIKE 'purpose'", ARRAY_A );
t_assert( false !== strpos( (string) $col['Type'], "'apply'" ) && CC_Migrations::VERSION === (string) get_option( CC_Migrations::OPTION ), "cc_otp.purpose has 'apply' and DB version is current" );
$run_v7 = new ReflectionMethod( 'CC_Migrations', 'run_v7' );
$run_v7->setAccessible( true );
$run_v7->invoke( null, $p );
$run_v7->invoke( null, $p );
$col = $wpdb->get_row( "SHOW COLUMNS FROM {$p}cc_otp LIKE 'purpose'", ARRAY_A );
t_assert( "enum('login','phone_change','reset','apply')" === $col['Type'] && '' === $wpdb->last_error, 'run_v7 is idempotent' );

echo "request: happy path and SMS storage\n";
$phone = t_phone();
$r     = t_call( 'request', array( 'phone' => $phone ) );
t_assert( 200 === $r->get_status() && true === ( $r->get_data()['ok'] ?? false ), 'request returns 200 ok' );
t_assert( CC_Phone::mask( $phone ) === $r->get_data()['phone'] && 60 === $r->get_data()['resend_after'] && 300 === $r->get_data()['expires_in'], 'response has masked phone, resend_after 60, expires_in 300' );
t_assert( false !== strpos( (string) $r->get_headers()['Cache-Control'], 'no-store' ), 'response is no-store' );
$otp = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}cc_otp WHERE phone = %s", $phone ), ARRAY_A );
t_assert( $otp && 'apply' === $otp['purpose'] && 0 === (int) $otp['attempts'], "one cc_otp row with purpose 'apply'" );
$sms = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}cc_sms_log WHERE to_phone = %s AND template = 'apply_otp'", $phone ), ARRAY_A );
t_assert( $sms && null === $sms['body_enc'] && 'otp' === $sms['related_type'], 'apply_otp SMS logged without a stored body' );
$code = t_code( $phone );
t_assert( 1 === preg_match( '/^\d{6}$/', $code ), 'SMS carries a 6 digit code (apply wording, not the login text)' );
t_assert( CC_Otp::hash( $phone, $code ) === $otp['code_hash'] && $code !== $otp['code_hash'], 'only the hmac of phone+code is stored' );
t_assert( hash( 'sha256', CC_Sms::render( 'apply_otp', array( 'code' => $code ) ) ) !== $sms['body_hash'], 'body_hash is not a hash of the guessable body' );
t_assert( 1 === preg_match( '/\p{Bengali}/u', CC_Sms::render( 'apply_otp', array( 'code' => $code, 'lang' => 'bn' ) ) ), 'Bangla variant exists' );
$bad = t_call( 'request', array( 'phone' => '12345' ) );
t_assert( 422 === $bad->get_status() && 'invalid_phone' === $bad->get_data()['code'], 'invalid phone is 422' );

echo "confirm: wrong code, success, single use\n";
$w = t_call( 'confirm', array( 'phone' => $phone, 'code' => '000000' === $code ? '000001' : '000000' ) );
t_assert( 422 === $w->get_status() && 'invalid_code' === $w->get_data()['code'], 'wrong code -> 422 invalid_code' );
$f = t_call( 'confirm', array( 'phone' => $phone, 'code' => 'abc' ) );
t_assert( 422 === $f->get_status(), 'non-numeric code -> 422' );
$ok = t_call( 'confirm', array( 'phone' => $phone, 'code' => $code ) );
$proof = (string) ( $ok->get_data()['phone_proof'] ?? '' );
t_assert( 200 === $ok->get_status() && '' !== $proof && 900 === $ok->get_data()['expires_in'], 'right code -> 200 with phone_proof (15 min)' );
t_assert( false !== strpos( (string) $ok->get_headers()['Cache-Control'], 'no-store' ), 'confirm response is no-store' );
$again = t_call( 'confirm', array( 'phone' => $phone, 'code' => $code ) );
t_assert( 422 === $again->get_status(), 'the same code cannot be confirmed twice' );
$other_fmt = t_call( 'confirm', array( 'phone' => substr( $phone, 3 ), 'code' => $code ) );
t_assert( 422 === $other_fmt->get_status(), 'used code stays used when the phone is written as 01...' );

echo "confirm: expiry\n";
$ex = t_phone();
t_call( 'request', array( 'phone' => $ex ) );
$ex_code = t_code( $ex );
$wpdb->update( "{$p}cc_otp", array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 5 ) ), array( 'phone' => $ex ) );
t_assert( 422 === t_call( 'confirm', array( 'phone' => $ex, 'code' => $ex_code ) )->get_status(), 'expired code refused' );
$ttl_phone = t_phone();
t_call( 'request', array( 'phone' => $ttl_phone ) );
$ttl = strtotime( (string) $wpdb->get_var( $wpdb->prepare( "SELECT expires_at FROM {$p}cc_otp WHERE phone = %s", $ttl_phone ) ) . ' UTC' ) - time();
t_assert( $ttl > 290 && $ttl <= 300, 'code lifetime is 5 minutes' );

echo "confirm: attempt lockout\n";
$lk = t_phone();
t_call( 'request', array( 'phone' => $lk ) );
$lk_code = t_code( $lk );
$wrong   = '000000' === $lk_code ? '111111' : '000000';
$last    = null;
for ( $i = 1; $i <= CC_Otp::MAX_ATTEMPTS; $i++ ) {
	$last = t_call( 'confirm', array( 'phone' => $lk, 'code' => $wrong ) );
	if ( $i < CC_Otp::MAX_ATTEMPTS ) {
		t_assert( 422 === $last->get_status(), "wrong attempt $i -> 422" );
	}
}
t_assert( 429 === $last->get_status() && 'locked' === $last->get_data()['code'] && CC_Rest_Phone_Proof::MSG_LOCKED === $last->get_data()['message'], 'fifth wrong attempt locks (429 locked, 15 minute message)' );
t_assert( 429 === t_call( 'confirm', array( 'phone' => $lk, 'code' => $lk_code ) )->get_status(), 'the right code is refused while locked' );
$rq = t_call( 'request', array( 'phone' => $lk ) );
t_assert( 429 === $rq->get_status() && 'locked' === $rq->get_data()['code'], 'a new code cannot be requested while locked' );
t_assert( ! CC_Otp::is_locked( $lk, 'login' ), 'apply lock does not lock login OTP for that phone' );

echo "confirm: 24h phone lock needs several IP buckets (M2)\n";
t_limits_on( true );
$vic = t_phone();
foreach ( array( '198.51.100.40', '198.51.100.41', '198.51.100.42', '198.51.100.43' ) as $ip ) {
	$cleanup['buckets'][] = 'otp_fail_apply_ip|' . $ip;
	$cleanup['buckets'][] = 'apply_verify_ip|' . $ip;
	$cleanup['buckets'][] = 'apply_otp_ip|' . $ip;
	CC_Rate_Limiter::reset( 'otp_fail_apply_ip|' . $ip );
	CC_Rate_Limiter::reset( 'apply_verify_ip|' . $ip );
	CC_Rate_Limiter::reset( 'otp_fail_apply_ip|' . $ip );
}
CC_Rate_Limiter::reset( 'otp_fail_apply|' . $vic );
CC_Rate_Limiter::reset( 'otp_fail_apply_ips|' . $vic );
$last = null;
for ( $i = 0; $i < CC_Otp::IP_FAIL_LIMIT; $i++ ) {
	$last = t_fail_round( $vic, '198.51.100.40' );
}
t_assert( 422 === $last->get_status(), 'a single IP gets ' . CC_Otp::IP_FAIL_LIMIT . ' wrong guesses answered as wrong codes' );
t_assert( CC_Otp::is_ip_blocked( '198.51.100.40' ) && ! CC_Otp::is_daily_locked( $vic, 'apply' ), 'one IP past its threshold is blocked but the phone is not 24h locked' );
$blocked = t_fail_round( $vic, '198.51.100.40' );
t_assert( 429 === $blocked->get_status() && 'rate_limited' === $blocked->get_data()['code'], 'the blocked IP gets 429 rate_limited on confirm' );
t_assert( 429 === t_call( 'request', array( 'phone' => t_phone() ) )->get_status(), 'the IP block also applies to other phones (request)' );
t_ip( '198.51.100.41' );
$owner_req = t_call( 'request', array( 'phone' => $vic ) );
t_assert( 200 === $owner_req->get_status(), 'the owner on another IP can still request a code during the attacker IP block' );
$owner_ok = t_call( 'confirm', array( 'phone' => $vic, 'code' => t_code( $vic ) ) );
t_assert( 200 === $owner_ok->get_status() && ! empty( $owner_ok->get_data()['phone_proof'] ), 'and confirm it' );

$multi = t_phone();
CC_Rate_Limiter::reset( 'otp_fail_apply|' . $multi );
CC_Rate_Limiter::reset( 'otp_fail_apply_ips|' . $multi );
for ( $i = 0; $i < 6; $i++ ) {
	t_fail_round( $multi, '198.51.100.41' );
	t_fail_round( $multi, '198.51.100.42' );
}
t_assert( CC_Rate_Limiter::count( 'otp_fail_apply|' . $multi ) >= CC_Otp::DAILY_FAIL_LIMIT && ! CC_Otp::is_daily_locked( $multi, 'apply' ), 'enough failures from only 2 IP buckets do not lock the phone for a day' );
$third = t_fail_round( $multi, '198.51.100.43' );
t_assert( CC_Otp::is_daily_locked( $multi, 'apply' ), 'a failure from a 3rd IP bucket locks the phone for 24 hours' );
$rq_day = t_call( 'request', array( 'phone' => $multi ) );
t_assert( 429 === $rq_day->get_status() && 'locked_daily' === $rq_day->get_data()['code'] && CC_Rest_Phone_Proof::MSG_LOCKED_DAILY === $rq_day->get_data()['message'], 'request while daily locked -> locked_daily with the tomorrow message' );
$cf_day = t_call( 'confirm', array( 'phone' => $multi, 'code' => '123123' ) );
t_assert( 429 === $cf_day->get_status() && 'locked_daily' === $cf_day->get_data()['code'], 'confirm while daily locked -> locked_daily' );
t_assert( CC_Rest_Phone_Proof::MSG_LOCKED_DAILY !== CC_Rest_Phone_Proof::MSG_LOCKED && false !== strpos( CC_Rest_Phone_Proof::MSG_LOCKED_DAILY, 'Try again tomorrow or contact us.' ), 'the two lock messages differ' );
t_assert( ! CC_Otp::is_daily_locked( $multi, 'login' ) && ! CC_Otp::is_daily_locked( $multi ), 'the apply daily lock does not touch login OTP' );
t_limits_on( false );

echo "uniform response for registered and unregistered phones\n";
t_ip( '198.51.100.2' );
$unreg = t_phone( '014' );
$a     = t_call( 'request', array( 'phone' => $registered ) );
$b     = t_call( 'request', array( 'phone' => $unreg ) );
$strip = static fn( array $d, string $phone ): array => array_merge( $d, array( 'phone' => str_replace( CC_Phone::mask( $phone ), 'M', (string) $d['phone'] ) ) );
t_assert( $a->get_status() === $b->get_status() && 200 === $a->get_status() && $strip( $a->get_data(), $registered ) === $strip( $b->get_data(), $unreg ), 'same status and body for registered and unregistered phone' );
$ca = t_call( 'confirm', array( 'phone' => $registered, 'code' => '999999' ) );
$cb = t_call( 'confirm', array( 'phone' => $unreg, 'code' => '999999' ) );
t_assert( $ca->get_status() === $cb->get_status() && $ca->get_data() === $cb->get_data(), 'same wrong-code answer for registered and unregistered phone' );
$sms_reg = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}cc_sms_log WHERE to_phone = %s AND template = 'apply_otp'", $registered ) );
t_assert( 1 === $sms_reg, 'registered phone gets a code SMS like anyone else (no existence-based branching)' );

echo "proof token: binding, forgery, expiry\n";
$phone_a = t_phone();
$phone_b = t_phone();
$proof_a = t_proof_for( $phone_a );
t_assert( '' !== $proof_a && null !== CC_Phone_Proof::verify( $proof_a, $phone_a ), 'proof verifies for its own phone' );
t_assert( null === CC_Phone_Proof::verify( $proof_a, $phone_b ), 'proof is refused for another phone' );
list( $pl, $sig ) = explode( '.', $proof_a );
t_assert( null === CC_Phone_Proof::verify( $pl . '.' . strrev( $sig ), $phone_a ), 'tampered signature refused' );
$data = json_decode( base64_decode( strtr( $pl, '-_', '+/' ) ), true );
t_assert( null === CC_Phone_Proof::verify( t_forge( array_merge( $data, array( 'p' => $phone_b ) ), false ) , $phone_b ) && null === CC_Phone_Proof::verify( rtrim( strtr( base64_encode( wp_json_encode( array_merge( $data, array( 'p' => $phone_b ) ) ) ), '+/', '-_' ), '=' ) . '.' . $sig, $phone_b ), 'payload edited to another phone refused (old or forged signature)' );
t_assert( null === CC_Phone_Proof::verify( t_forge( array_merge( $data, array( 'e' => time() + 99999 ) ), false ), $phone_a ), 'extended expiry with a forged signature refused' );
t_assert( null === CC_Phone_Proof::verify( '', $phone_a ) && null === CC_Phone_Proof::verify( 'a.b', $phone_a ) && null === CC_Phone_Proof::verify( 'garbage', $phone_a ) && null === CC_Phone_Proof::verify( $pl, $phone_a ), 'empty/garbage/unsigned tokens refused' );
$expired = t_forge( array( 'p' => $phone_a, 'e' => time() - 1, 'n' => bin2hex( random_bytes( 16 ) ) ) );
t_assert( null === CC_Phone_Proof::verify( $expired, $phone_a ), 'validly signed but expired proof refused' );
$fresh = t_forge( array( 'p' => $phone_a, 'e' => time() + 60, 'n' => bin2hex( random_bytes( 16 ) ) ) );
t_assert( null !== CC_Phone_Proof::verify( $fresh, $phone_a ), 'control: a validly signed unexpired proof is accepted' );
t_assert( abs( $data['e'] - ( time() + CC_Phone_Proof::TTL_SECONDS ) ) < 10, 'proof lifetime is 15 minutes' );
$claim = CC_Phone_Proof::verify( $proof_a, $phone_a );
t_assert( true === CC_Phone_Proof::claim( $claim ) && false === CC_Phone_Proof::claim( $claim ), 'claim succeeds exactly once' );
t_assert( null === CC_Phone_Proof::verify( $proof_a, $phone_a ), 'a claimed proof no longer verifies' );
CC_Phone_Proof::release( $claim );
t_assert( null !== CC_Phone_Proof::verify( $proof_a, $phone_a ), 'release makes it usable again' );

echo "applications: proof required (site over HTTP)\n";
$key = wp_generate_uuid4();
list( $st, $res ) = t_http_apply( t_fields( $batch, $phone_a ), $key );
t_assert( 422 === $st && 'Verify your phone number first.' === ( $res['details']['phone_proof'] ?? '' ), 'no proof -> 422 details.phone_proof' );
list( $st, $res ) = t_http_apply( t_fields( $batch, $phone_a, array( 'phone_proof' => t_proof_for( $phone_b ) ) ), $key );
t_assert( 422 === $st && isset( $res['details']['phone_proof'] ), "another phone's proof -> 422 details.phone_proof" );
list( $st, $res ) = t_http_apply( t_fields( $batch, $phone_a, array( 'phone_proof' => $pl . '.' . strrev( $sig ) ) ), $key );
t_assert( 422 === $st && isset( $res['details']['phone_proof'] ), 'forged proof -> 422 details.phone_proof' );
list( $st, $res ) = t_http_apply( t_fields( $batch, $phone_a, array( 'phone_proof' => $expired ) ), $key );
t_assert( 422 === $st && isset( $res['details']['phone_proof'] ), 'expired proof -> 422 details.phone_proof' );
$app_count = static fn( string $ph ): int => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}cc_applications WHERE student_phone = %s", $ph ) );
t_assert( 0 === $app_count( $phone_a ), 'no application was created by any refused attempt' );

echo "applications: proof consumed only on creation\n";
$proof_a = t_proof_for( $phone_a );
list( $st, $res ) = t_http_apply( t_fields( $batch, $phone_a, array( 'phone_proof' => $proof_a, 'consent' => '' ) ), $key );
t_assert( 422 === $st && isset( $res['details']['consent'] ) && ! isset( $res['details']['phone_proof'] ), 'other validation error does not blame the proof' );
list( $st, $res ) = t_http_apply( t_fields( $batch, $phone_a, array( 'phone_proof' => $proof_a ) ), $key, false );
t_assert( 422 === $st && isset( $res['details']['photo'] ), 'missing photo -> 422 on photo' );
t_assert( null !== CC_Phone_Proof::verify( $proof_a, $phone_a ), 'proof still unused after failed submissions' );
list( $st, $res ) = t_http_apply( t_fields( $batch, $phone_a, array( 'phone_proof' => $proof_a ) ), $key );
t_assert( 201 === $st && ! empty( $res['ref'] ) && ! empty( $res['redirect_url'] ), 'valid proof + valid form -> 201' );
$ref = (string) ( $res['ref'] ?? '' );
t_assert( 1 === $app_count( $phone_a ), 'exactly one application stored for the verified phone' );
t_assert( null === CC_Phone_Proof::verify( $proof_a, $phone_a ), 'proof is consumed after creation' );

echo "applications: replay and single use\n";
list( $st, $res ) = t_http_apply( t_fields( $batch, $phone_a ), $key );
t_assert( 200 === $st && $ref === ( $res['ref'] ?? '' ), 'replay with the same Idempotency-Key and no proof returns the original response' );
list( $st, $res ) = t_http_apply( t_fields( $batch, $phone_a, array( 'phone_proof' => $proof_a ) ), $key );
t_assert( 200 === $st && $ref === ( $res['ref'] ?? '' ), 'replay with the consumed proof still returns the original response' );
t_assert( 1 === $app_count( $phone_a ), 'replays created nothing' );
list( $st, $res ) = t_http_apply( t_fields( $batch, $phone_a, array( 'phone_proof' => $proof_a ) ), wp_generate_uuid4() );
t_assert( 422 === $st && isset( $res['details']['phone_proof'] ), 'a used proof is refused for a new application' );

echo "applications: proof survives a duplicate-phone refusal\n";
$proof_dup = t_proof_for( $phone_a );
list( $st, $res ) = t_http_apply( t_fields( $batch, $phone_a, array( 'phone_proof' => $proof_dup ) ), wp_generate_uuid4() );
t_assert( 409 === $st, 'duplicate student phone for the batch -> 409 even with a proof' );
t_assert( null !== CC_Phone_Proof::verify( $proof_dup, $phone_a ), 'the proof was handed back (no application was created)' );

echo "applications: registered phone needs a proof too\n";
list( $st, $res ) = t_http_apply( t_fields( $batch, $registered ), wp_generate_uuid4() );
t_assert( 422 === $st && isset( $res['details']['phone_proof'] ), "someone else's registered phone cannot be applied for without a proof" );

echo "rate limits (limiter enabled in-process)\n";
t_limits_on( true );
$rl_phone = t_phone();
t_ip( '198.51.100.10' );
foreach ( array( 'apply_otp_ip|198.51.100.10', 'apply_otp_pair|' . $rl_phone . '|198.51.100.10', 'apply_otp_phone|' . $rl_phone, 'apply_otp_global' ) as $bucket ) {
	CC_Rate_Limiter::reset( $bucket );
	$cleanup['buckets'][] = $bucket;
}
$codes = array();
for ( $i = 0; $i < 3; $i++ ) {
	$codes[] = t_req( $rl_phone )->get_status();
}
t_assert( array( 200, 200, 200 ) === $codes, 'three requests per phone per hour are allowed' );
$fourth = t_req( $rl_phone );
t_assert( 429 === $fourth->get_status() && 'rate_limited' === $fourth->get_data()['code'], 'the fourth request for a phone from one IP is 429' );
$queued = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}cc_sms_log WHERE to_phone = %s AND template = 'apply_otp'", $rl_phone ) );
t_assert( 3 === $queued, 'no SMS is queued for a rate-limited request' );

$per_phone = t_phone();
CC_Rate_Limiter::reset( 'apply_otp_phone|' . $per_phone );
$statuses  = array();
foreach ( array( '198.51.100.21', '198.51.100.22', '198.51.100.23', '198.51.100.24' ) as $ip ) {
	t_ip( $ip );
	$cleanup['buckets'][] = 'apply_otp_ip|' . $ip;
	$cleanup['buckets'][] = 'apply_otp_pair|' . $per_phone . '|' . $ip;
	CC_Rate_Limiter::reset( 'apply_otp_ip|' . $ip );
	CC_Rate_Limiter::reset( 'apply_otp_pair|' . $per_phone . '|' . $ip );
	for ( $i = 0; $i < 3; $i++ ) {
		$statuses[] = t_req( $per_phone )->get_status();
	}
}
$cleanup['buckets'][] = 'apply_otp_phone|' . $per_phone;
t_assert( 10 === count( array_keys( $statuses, 200, true ) ) && 2 === count( array_keys( $statuses, 429, true ) ), 'many IPs cannot push one phone past ' . CC_Rest_Phone_Proof::PHONE_LIMIT . ' codes per hour' );

$ip_statuses = array();
t_ip( '198.51.100.30' );
CC_Rate_Limiter::reset( 'apply_otp_ip|198.51.100.30' );
$cleanup['buckets'][] = 'apply_otp_ip|198.51.100.30';
for ( $i = 0; $i < CC_Rest_Phone_Proof::IP_LIMIT + 1; $i++ ) {
	$ip_statuses[] = t_call( 'request', array( 'phone' => t_phone() ) )->get_status();
}
t_assert( 200 === $ip_statuses[ CC_Rest_Phone_Proof::IP_LIMIT - 1 ] && 429 === end( $ip_statuses ), 'one IP gets ' . CC_Rest_Phone_Proof::IP_LIMIT . ' requests per hour across phones, then 429' );

t_ip( '198.51.100.31' );
$cleanup['buckets'][] = 'apply_otp_ip|198.51.100.31';
$wpdb->replace( $wpdb->options, array( 'option_name' => CC_Rate_Limiter::OPTION_PREFIX . md5( 'apply_otp_global' ), 'option_value' => ( time() + 600 ) . '|' . CC_Rest_Phone_Proof::GLOBAL_LIMIT, 'autoload' => 'no' ) );
$g = t_call( 'request', array( 'phone' => t_phone() ) );
t_assert( 429 === $g->get_status(), 'global hourly cap -> 429' );
CC_Rate_Limiter::reset( 'apply_otp_global' );

t_ip( '198.51.100.32' );
$cleanup['buckets'][] = 'apply_verify_ip|198.51.100.32';
CC_Rate_Limiter::reset( 'apply_verify_ip|198.51.100.32' );
$v = array();
for ( $i = 0; $i < CC_Rest_Phone_Proof::VERIFY_IP_LIMIT + 1; $i++ ) {
	$v[] = t_call( 'confirm', array( 'phone' => t_phone(), 'code' => '123456' ) )->get_status();
}
t_assert( 422 === $v[ CC_Rest_Phone_Proof::VERIFY_IP_LIMIT - 1 ] && 429 === end( $v ), 'confirm attempts are capped per IP' );
t_limits_on( false );

echo "SMS bombing controls (M3)\n";
t_limits_on( true );
t_assert( '203.0.113.7' === CC_Rate_Limiter::ip_bucket_key( '203.0.113.7' ), 'IPv4 bucket key is the address' );
t_assert( CC_Rate_Limiter::ip_bucket_key( '2001:db8:1:2::1' ) === CC_Rate_Limiter::ip_bucket_key( '2001:db8:1:2:ffff:ffff:ffff:ffff' ), 'IPv6 addresses in one /64 share a bucket' );
t_assert( CC_Rate_Limiter::ip_bucket_key( '2001:db8:1:2::1' ) !== CC_Rate_Limiter::ip_bucket_key( '2001:db8:1:3::1' ), 'different /64 prefixes get different buckets' );
t_assert( '203.0.113.9' === CC_Rate_Limiter::ip_bucket_key( '::ffff:203.0.113.9' ), 'IPv4-mapped IPv6 keys on the IPv4 address' );
$_SERVER['REMOTE_ADDR'] = '2001:db8:aaaa:bbbb::5';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '9.9.9.9';
t_assert( CC_Rate_Limiter::ip_bucket_key() === CC_Rate_Limiter::ip_bucket_key( '2001:db8:aaaa:bbbb::99' ), 'default key reads REMOTE_ADDR /64 and ignores forwarded headers' );
unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );

$v6_bucket            = CC_Rate_Limiter::ip_bucket_key( '2001:db8:cafe:1::1' );
$cleanup['buckets'][] = 'apply_otp_ip|' . $v6_bucket;
CC_Rate_Limiter::reset( 'apply_otp_ip|' . $v6_bucket );
$v6_status = array();
for ( $i = 0; $i < CC_Rest_Phone_Proof::IP_LIMIT + 1; $i++ ) {
	$_SERVER['REMOTE_ADDR'] = 0 === $i % 2 ? '2001:db8:cafe:1::1' : '2001:db8:cafe:1::2';
	$v6_status[]            = t_call( 'request', array( 'phone' => t_phone() ) )->get_status();
}
t_assert( 200 === $v6_status[ CC_Rest_Phone_Proof::IP_LIMIT - 1 ] && 429 === end( $v6_status ), 'hopping between addresses of one IPv6 /64 does not escape the per-IP limit' );

$gap = t_phone();
t_ip( '198.51.100.50' );
foreach ( array( 'apply_otp_ip|198.51.100.50', 'apply_otp_pair|' . $gap . '|198.51.100.50', 'apply_otp_phone|' . $gap, 'apply_otp_phone_day|' . $gap ) as $bucket ) {
	CC_Rate_Limiter::reset( $bucket );
	$cleanup['buckets'][] = $bucket;
}
CC_Rate_Limiter::reset( 'apply_otp_gap|' . $gap );
$first     = t_call( 'request', array( 'phone' => $gap ) );
$soon      = t_call( 'request', array( 'phone' => $gap ) );
$soon_data = $soon->get_data();
t_assert( 200 === $first->get_status() && 429 === $soon->get_status() && 'resend_too_soon' === $soon_data['code'], 'a second code request within 60 seconds is 429 resend_too_soon' );
t_assert( is_int( $soon_data['retry_after'] ) && $soon_data['retry_after'] >= 1 && $soon_data['retry_after'] <= CC_Rest_Phone_Proof::RESEND_SECONDS && (string) $soon_data['retry_after'] === (string) $soon->get_headers()['Retry-After'], 'retry_after field and Retry-After header carry the seconds left' );
t_assert( 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}cc_sms_log WHERE to_phone = %s AND template = 'apply_otp'", $gap ) ), 'the refused resend queued no SMS' );
t_ip( '198.51.100.51' );
$cleanup['buckets'][] = 'apply_otp_ip|198.51.100.51';
CC_Rate_Limiter::reset( 'apply_otp_ip|198.51.100.51' );
t_assert( 429 === t_call( 'request', array( 'phone' => $gap ) )->get_status(), 'the gap is per phone: another IP cannot request inside the window either' );
CC_Rate_Limiter::reset( 'apply_otp_gap|' . $gap );
t_assert( 200 === t_call( 'request', array( 'phone' => $gap ) )->get_status(), 'a request after the gap succeeds' );

$day = t_phone();
CC_Rate_Limiter::reset( 'apply_otp_phone_day|' . $day );
$cleanup['buckets'][] = 'apply_otp_phone_day|' . $day;
$day_status           = array();
for ( $i = 0; $i < CC_Rest_Phone_Proof::PHONE_DAILY_LIMIT + 1; $i++ ) {
	t_ip( '198.51.100.' . ( 100 + $i ) );
	CC_Rate_Limiter::reset( 'apply_otp_ip|198.51.100.' . ( 100 + $i ) );
	CC_Rate_Limiter::reset( 'apply_otp_phone|' . $day ); // the hourly cap is not under test here
	$cleanup['buckets'][] = 'apply_otp_ip|198.51.100.' . ( 100 + $i );
	$day_status[]         = t_req( $day )->get_status();
}
t_assert( 200 === $day_status[ CC_Rest_Phone_Proof::PHONE_DAILY_LIMIT - 1 ] && 429 === end( $day_status ), 'a phone gets ' . CC_Rest_Phone_Proof::PHONE_DAILY_LIMIT . ' codes a day, then 429' );

$captcha_calls = 0;
$captcha_deny  = static function () use ( &$captcha_calls ) {
	++$captcha_calls;
	return false;
};
$set_global = static function ( int $count ) use ( $wpdb ): void {
	$wpdb->replace( $wpdb->options, array( 'option_name' => CC_Rate_Limiter::OPTION_PREFIX . md5( CC_Rest_Phone_Proof::GLOBAL_BUCKET ), 'option_value' => ( time() + 600 ) . '|' . $count, 'autoload' => 'no' ) );
};
add_filter( 'cc_verify_captcha', $captcha_deny, 20 );
t_ip( '198.51.100.90' );
$cleanup['buckets'][] = 'apply_otp_ip|198.51.100.90';
CC_Rate_Limiter::reset( 'apply_otp_ip|198.51.100.90' );
$set_global( 100 );
$low = t_req( t_phone() );
t_assert( 200 === $low->get_status() && 0 === $captcha_calls, 'below 70% of the global cap the captcha filter is not consulted' );
$set_global( 215 );
$high = t_req( t_phone() );
t_assert( 400 === $high->get_status() && 'bot_check_failed' === $high->get_data()['code'] && 1 === $captcha_calls, 'above 70% the captcha filter runs and a failure is 400 bot_check_failed' );
remove_filter( 'cc_verify_captcha', $captcha_deny, 20 );
t_assert( 200 === t_req( t_phone() )->get_status(), 'above 70% with a passing captcha (local, no secret) the request goes through' );

// Turnstile for real: a configured secret makes the captcha mandatory on request, with siteverify mocked at the HTTP layer.
if ( defined( 'TURNSTILE_SECRET' ) ) {
	echo "  skip Turnstile secret cases: TURNSTILE_SECRET is a constant here\n";
} else {
	$set_global( 0 );
	putenv( 'TURNSTILE_SECRET=test-secret' );
	$verify_calls = array();
	$mock_verify  = static function ( $short, $args, $url ) use ( &$verify_calls ) {
		if ( false === strpos( $url, 'turnstile/v0/siteverify' ) ) {
			return $short;
		}
		$verify_calls[] = $args['body'];
		$token          = $args['body']['response'] ?? '';
		if ( 'transport-error' === $token ) {
			return new WP_Error( 'http_request_failed', 'mocked transport error' );
		}
		$ok = 'good-token' === $token;
		return array( 'headers' => array(), 'cookies' => array(), 'filename' => null, 'response' => array( 'code' => 200, 'message' => 'OK' ), 'body' => wp_json_encode( array( 'success' => $ok ) ) );
	};
	add_filter( 'pre_http_request', $mock_verify, 10, 3 );
	$ts_ip = '198.51.100.91';
	t_ip( $ts_ip );
	$cleanup['buckets'][] = 'apply_otp_ip|' . $ts_ip;
	$ts_req               = static function ( ?string $token ) use ( $ts_ip ) {
		CC_Rate_Limiter::reset( 'apply_otp_ip|' . $ts_ip );
		$params = array( 'phone' => t_phone() );
		if ( null !== $token ) {
			$params['cf-turnstile-response'] = $token;
		}
		return t_call( 'request', $params );
	};
	$no_token = $ts_req( null );
	t_assert( 400 === $no_token->get_status() && 'bot_check_failed' === $no_token->get_data()['code'] && 0 === count( $verify_calls ), 'secret set: a request without a Turnstile token is 400 bot_check_failed without calling siteverify' );
	$good = $ts_req( 'good-token' );
	t_assert( 200 === $good->get_status() && 1 === count( $verify_calls ) && 'test-secret' === $verify_calls[0]['secret'] && 'good-token' === $verify_calls[0]['response'], 'secret set: a token that siteverify accepts lets the request through (token and secret are posted)' );
	$bad = $ts_req( 'bad-token' );
	t_assert( 400 === $bad->get_status() && 'bot_check_failed' === $bad->get_data()['code'], 'secret set: a token that siteverify rejects is 400 bot_check_failed' );
	$down = $ts_req( 'transport-error' );
	t_assert( 400 === $down->get_status() && 'bot_check_failed' === $down->get_data()['code'], 'secret set: a siteverify transport error fails closed (400 bot_check_failed)' );
	remove_filter( 'pre_http_request', $mock_verify, 10 );
	putenv( 'TURNSTILE_SECRET' );
	t_assert( 200 === $ts_req( null )->get_status(), 'no secret (local): a request without a token is still accepted' );
}

CC_Rate_Limiter::reset( CC_Rest_Phone_Proof::GLOBAL_BUCKET . '_warned' );
$cleanup['buckets'][] = CC_Rest_Phone_Proof::GLOBAL_BUCKET . '_warned';
$warn_text            = 'admission SMS global cap is over 90% used';
$set_global( 100 );
t_req( t_phone() );
t_assert( 0 === substr_count( (string) file_get_contents( $err_log_file ), $warn_text ), 'no warning logged below 90%' );
$set_global( 275 );
t_req( t_phone() );
t_req( t_phone() );
$warn_log = (string) file_get_contents( $err_log_file );
t_assert( 1 === substr_count( $warn_log, $warn_text ), 'one error_log warning when the 90% mark is first crossed in a window' );
t_assert( false !== strpos( $warn_log, 'limit 300 per hour' ) && false === strpos( $warn_log, '+880' ), 'the warning carries no phone number' );
CC_Rate_Limiter::reset( CC_Rest_Phone_Proof::GLOBAL_BUCKET );
CC_Rate_Limiter::reset( CC_Rest_Phone_Proof::GLOBAL_BUCKET . '_warned' );
t_limits_on( false );

echo "domain-separated keys (I1)\n";
t_assert( null === CC_Phone_Proof::verify( $pl . '.' . hash_hmac( 'sha256', 'cc_phone_proof|' . $pl, wp_salt( 'auth' ) ), $phone_a ), 'a proof signed with the old (undifferentiated) derivation is refused' );
t_assert( CC_Otp::hash( $phone_a, '123456' ) !== hash_hmac( 'sha256', $phone_a . '123456', wp_salt( 'auth' ) ), 'OTP hashes no longer use the raw wp_salt key' );
t_assert( CC_Otp::hash( $phone_a, '123456' ) === hash_hmac( 'sha256', $phone_a . '123456', hash_hmac( 'sha256', 'cc_otp', wp_salt( 'auth' ) ) ), 'OTP hashes use the cc_otp purpose key' );

echo "idempotency replay must match the body (L4)\n";
list( $st, $res ) = t_http_apply( t_fields( $batch, $phone_b ), $key );
t_assert( 409 === $st && 'idempotency_conflict' === ( $res['code'] ?? '' ) && ! isset( $res['ref'] ), 'same key with a different student phone -> 409 idempotency_conflict' );
list( $st, $res ) = t_http_apply( t_fields( $batch, $phone_a, array( 'batch_id' => (string) ( $batch + 1000000 ) ) ), $key );
t_assert( 409 === $st && 'idempotency_conflict' === ( $res['code'] ?? '' ), 'same key with a different batch -> 409 idempotency_conflict' );
list( $st, $res ) = t_http_apply( array( 'consent' => '1' ), $key );
t_assert( 409 === $st && ! isset( $res['ref'] ), 'same key with an empty body does not reveal the first applicant ref' );
list( $st, $res ) = t_http_apply( t_fields( $batch, substr( $phone_a, 3 ) ), $key );
t_assert( 200 === $st && $ref === ( $res['ref'] ?? '' ), 'a legitimate retry (same phone written as 01...) still replays' );
t_assert( 1 === $app_count( $phone_a ) && 0 === $app_count( $phone_b ), 'no application was created by the conflicting replays' );

echo "application creation and COMMIT failure (M4a, L3)\n";
$stored = CC_Application_Repository::find_by_idempotency_key( $key );
t_assert( null !== $stored['phone_verified_at'] && abs( time() - (int) strtotime( $stored['phone_verified_at'] . ' UTC' ) ) < 300, 'an application created with a proof has phone_verified_at set (UTC now)' );
$finish = new ReflectionMethod( 'CC_Rest_Admissions', 'finish_create' );
$finish->setAccessible( true );
$new_request  = static fn(): WP_REST_Request => new WP_REST_Request( 'POST', '/cc/v1/applications' );
$server_error = static fn(): WP_Error => new WP_Error( 'server_error', 'Could not save the application.', array( 'status' => 500 ) );
$claimed_proof = static function (): array {
	$for_phone = t_phone();
	$proof     = CC_Phone_Proof::verify( t_proof_for( $for_phone ), $for_phone );
	CC_Phone_Proof::claim( $proof );
	return $proof;
};
$unused = $claimed_proof();
$resp   = $finish->invoke( null, $server_error(), $unused, 'no-such-photo', wp_generate_uuid4(), $new_request() );
t_assert( 500 === $resp->get_status() && true === CC_Phone_Proof::claim( $unused ), 'a 500 with no application for the key releases the proof' );
$held = $claimed_proof();
$resp = $finish->invoke( null, $server_error(), $held, (string) $stored['photo_path'], $key, $new_request() );
t_assert( 500 === $resp->get_status() && false === CC_Phone_Proof::claim( $held ), 'a 500 when an application exists for the key keeps the proof claimed (fail closed)' );
t_assert( null !== CC_Admin_Applications::photo_file( $stored ), 'and keeps that application photo' );

$commit_phone = t_phone();
$commit_key   = wp_generate_uuid4();
$commit_hook  = static function ( $query ) use ( $wpdb, &$commit_hook ) {
	if ( 'COMMIT' !== $query ) {
		return $query;
	}
	remove_filter( 'query', $commit_hook );
	$wpdb->query( 'COMMIT' ); // The commit lands, but the caller is told it failed.
	return 'SELECT * FROM zz_cc_missing_table';
};
add_filter( 'query', $commit_hook );
$quiet   = $wpdb->suppress_errors( true );
$created = CC_Application_Repository::create( array(
	'batch_id' => $batch, 'student_phone' => $commit_phone, 'full_name' => 'ZZ Commit', 'gender' => 'o', 'dob' => '2008-01-01',
	'id_doc_type' => 'nid', 'id_doc_enc' => 'x', 'guardian_name' => 'G', 'guardian_phone' => '+8801700000001',
	'institution' => 'I', 'class_level' => '10', 'consent_at' => $now,
), $commit_key );
$wpdb->suppress_errors( $quiet );
remove_filter( 'query', $commit_hook );
t_assert( is_wp_error( $created ) && 500 === (int) $created->get_error_data()['status'] && null !== CC_Application_Repository::find_by_idempotency_key( $commit_key ), 'simulated COMMIT failure: create() answers 500 although the application landed' );
$held = $claimed_proof();
$resp = $finish->invoke( null, $created, $held, 'no-such-photo', $commit_key, $new_request() );
t_assert( 500 === $resp->get_status() && false === CC_Phone_Proof::claim( $held ), 'the proof of that application is not released' );
$replay = CC_Application_Repository::create( array( 'batch_id' => $batch, 'student_phone' => $commit_phone ), $commit_key );
t_assert( ! is_wp_error( $replay ) && true === $replay['replayed'], 'the client retry with the same key replays the landed application' );

echo "nothing secret is stored or logged\n";
$all_codes  = array( $code, $ex_code, $lk_code );
$audit_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}cc_audit_log" );
t_assert( $audit_rows === $audit_before, 'no audit rows were written by the flow' );
$audit_hit = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}cc_audit_log WHERE note LIKE %s OR note LIKE %s", '%' . $wpdb->esc_like( $proof_a ) . '%', '%' . $wpdb->esc_like( $code ) . '%' ) );
t_assert( 0 === $audit_hit, 'audit log holds no proof or code' );
$token_in_db = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name <> %s AND (option_value LIKE %s OR option_value LIKE %s)", CC_Sms_Fake_Driver::OPTION_OUTBOX, '%' . $wpdb->esc_like( $pl ) . '%', '%' . $wpdb->esc_like( $sig ) . '%' ) );
t_assert( 0 === $token_in_db, 'the proof token is stored nowhere (only its nonce claim)' );
$code_in_sms = 0;
foreach ( $all_codes as $c ) {
	$code_in_sms += (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}cc_sms_log WHERE template = 'apply_otp' AND (error LIKE %s OR body_hash = %s)", '%' . $c . '%', hash( 'sha256', CC_Sms::render( 'apply_otp', array( 'code' => $c ) ) ) ) );
}
t_assert( 0 === $code_in_sms, 'no apply_otp SMS row contains a code or the hash of its body' );
$no_body = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}cc_sms_log WHERE template = 'apply_otp' AND body_enc IS NOT NULL" );
t_assert( 0 === $no_body, 'no apply_otp SMS body is persisted' );
$transients = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s AND (option_value LIKE %s OR option_value LIKE %s)", '%cc_sms_vars_%', '%' . $code . '%', '%' . $lk_code . '%' ) );
t_assert( 0 === $transients, 'no plaintext code in SMS payload transients' );
$log = (string) file_get_contents( $err_log_file );
t_assert( false === strpos( $log, $proof_a ) && false === strpos( $log, $pl ) && false === strpos( $log, $code ) && false === strpos( $log, $lk_code ), 'error_log never saw a code or proof (' . strlen( $log ) . ' bytes logged)' );

echo "cleanup of used-proof claims\n";
$old = t_forge( array( 'p' => $phone_a, 'e' => time() - 2 * HOUR_IN_SECONDS, 'n' => bin2hex( random_bytes( 16 ) ) ) );
$old_payload = json_decode( base64_decode( strtr( explode( '.', $old )[0], '-_', '+/' ) ), true );
CC_Phone_Proof::claim( $old_payload );
CC_Otp::cleanup();
t_assert( null === $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", CC_Phone_Proof::CLAIM_PREFIX . $old_payload['n'] ) ), 'claims of long-expired proofs are removed by cleanup' );
$recent_kept = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( CC_Phone_Proof::CLAIM_PREFIX ) . '%' ) );
t_assert( $recent_kept >= 1, 'cleanup leaves recent claims alone' );

// Cleanup.
ini_set( 'error_log', (string) $old_err_log ); // phpcs:ignore WordPress.PHP.IniSet.Risky
@unlink( $err_log_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
foreach ( $cleanup['phones'] as $ph ) {
	$apps = $wpdb->get_results( $wpdb->prepare( "SELECT id, photo_path FROM {$p}cc_applications WHERE student_phone = %s", $ph ), ARRAY_A );
	foreach ( $apps as $app ) {
		if ( ! empty( $app['photo_path'] ) ) {
			CC_Photo_Store::delete( (string) $app['photo_path'] );
		}
		foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$p}cc_invoices WHERE application_id = %d", $app['id'] ) ) as $inv ) {
			foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT id, gateway_payment_id FROM {$p}cc_payments WHERE invoice_id = %d", $inv ), ARRAY_A ) as $pay ) {
				CC_Fake_Gateway::delete_state( (string) $pay['gateway_payment_id'] );
				$wpdb->delete( "{$p}cc_payment_events", array( 'payment_id' => $pay['id'] ) );
				$wpdb->delete( "{$p}cc_payments", array( 'id' => $pay['id'] ) );
			}
			$wpdb->delete( "{$p}cc_invoices", array( 'id' => $inv ) );
		}
		$wpdb->delete( "{$p}cc_applications", array( 'id' => $app['id'] ) );
	}
	$wpdb->delete( "{$p}cc_otp", array( 'phone' => $ph ) );
	$wpdb->delete( "{$p}cc_sms_log", array( 'to_phone' => $ph ) );
	CC_Rate_Limiter::reset( 'apply_otp_phone|' . $ph );
	CC_Rate_Limiter::reset( 'otp_fail_apply|' . $ph );
	CC_Rate_Limiter::reset( 'otp_fail_apply_ips|' . $ph );
	CC_Rate_Limiter::reset( 'apply_otp_gap|' . $ph );
	CC_Rate_Limiter::reset( 'apply_otp_phone_day|' . $ph );
	delete_transient( 'cc_otp_lock_apply_' . md5( $ph ) );
	foreach ( $cleanup['ips'] as $ip ) {
		CC_Rate_Limiter::reset( 'apply_otp_pair|' . $ph . '|' . $ip );
		CC_Rate_Limiter::reset( 'otp_fail_apply_pair|' . $ph . '|' . $ip );
	}
}
foreach ( array_unique( $cleanup['buckets'] ) as $bucket ) {
	CC_Rate_Limiter::reset( $bucket );
}
foreach ( array_unique( $cleanup['ips'] ) as $ip ) {
	CC_Rate_Limiter::reset( 'apply_otp_ip|' . $ip );
	CC_Rate_Limiter::reset( 'apply_verify_ip|' . $ip );
	CC_Rate_Limiter::reset( 'otp_fail_apply_ip|' . $ip );
}
CC_Rate_Limiter::reset( 'apply_otp_global' );
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( CC_Phone_Proof::CLAIM_PREFIX ) . '%' ) );
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ( $cleanup['users'] as $user_id ) {
	$wpdb->delete( "{$p}cc_students", array( 'user_id' => $user_id ) );
	wp_delete_user( $user_id );
}
foreach ( $cleanup['batches'] as $b ) {
	$wpdb->delete( "{$p}cc_batches", array( 'id' => $b ) );
}
if ( $added_role ) {
	remove_role( 'cc_student' );
}
unset( $_SERVER['REMOTE_ADDR'] );
putenv( 'CC_RATE_LIMIT_DISABLED=1' );

echo "\n$checks checks, $failures failures\n";
exit( $failures ? 1 : 0 );
