<?php
defined( 'ABSPATH' ) || exit;

/**
 * Student login, OTP login, logout and password change under /cc/v1.
 *
 * Password login throttling (all counted by CC_Rate_Limiter, none of it consumed by successful logins):
 *  - login_ip: every attempt from one IP, LOGIN_IP_LIMIT per LOGIN_WINDOW.
 *  - login_fail_pair: FAILED attempts for one phone from one IP, LOGIN_PAIR_LIMIT per LOGIN_WINDOW. A stranger
 *    cannot burn the owner's allowance because the owner is on a different IP.
 *  - Per-phone exponential back-off: after LOGIN_BACKOFF_FREE failures in LOGIN_FAIL_WINDOW (any IP), further
 *    attempts from IPs that have not logged in successfully for that phone within TRUST_SECONDS are refused for
 *    BACKOFF_BASE * 2^n seconds (n = failures beyond the free ones), capped at BACKOFF_MAX. Refused attempts are
 *    not evaluated, so they cannot be used to guess. A phone+IP that signed in before is exempt, so an attacker
 *    cannot lock the real owner out of their usual network; the owner on a new network waits at most BACKOFF_MAX.
 */
final class CC_Rest_Auth {

	const NAMESPACE_         = 'cc/v1';
	const LOGIN_WINDOW       = 900;
	const LOGIN_IP_LIMIT     = 10;
	const LOGIN_PAIR_LIMIT   = 5;
	const LOGIN_FAIL_WINDOW  = 86400;
	const LOGIN_BACKOFF_FREE = 5;
	const BACKOFF_BASE       = 30;
	const BACKOFF_MAX        = 900;
	const TRUST_SECONDS      = 2592000;
	const OTP_WINDOW         = 3600;
	const OTP_PHONE_IP_LIMIT = 3;
	const OTP_PHONE_LIMIT    = 10;
	const OTP_IP_LIMIT       = 10;
	const OTP_MIN_SECONDS    = 0.3;
	const VERIFY_IP_LIMIT    = 30;
	const PW_USER_LIMIT      = 10;
	const MIN_PASSWORD       = 10;
	const MAX_PASSWORD       = 128;
	const REAUTH_KEY         = 'cc_reauth_until';
	const REAUTH_SECONDS     = 900;
	const REMEMBER_MIN_SPAN  = 259200;

	const MSG_INVALID_LOGIN = 'Invalid phone or password';
	const MSG_INVALID_CODE  = 'Invalid or expired code';
	const MSG_TOO_MANY      = 'Too many attempts. Please wait a while and try again.';

	// Precomputed wp_hash_password() of a discarded random string: unknown phones cost one verify, like known ones.
	const DUMMY_HASH = '$wp$2y$10$vEXyntQ4byqtsNSaD5NTw.WrG0DGrL8pY.HHbejfAot24nBz8Y5fu';

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'no_store_on_errors' ), 10, 3 );
		CC_Otp::schedule_cleanup();
	}

	/** Core-generated errors (argument validation, bad nonce, 401/403) bypass respond(); cover every auth and /me route here. */
	public static function no_store_on_errors( $response, $server, $request ) {
		$route = $request->get_route();
		$base  = '/' . self::NAMESPACE_;
		$ours  = 0 === strpos( $route, $base . '/auth/' ) || 0 === strpos( $route, $base . '/me/' );
		if ( $ours && $response instanceof WP_HTTP_Response ) {
			$response->header( 'Cache-Control', 'private, no-store' );
		}
		return $response;
	}

	public static function register_routes(): void {
		$phone    = array( 'type' => 'string', 'required' => true, 'maxLength' => 32 );
		$password = array( 'type' => 'string', 'required' => true, 'maxLength' => self::MAX_PASSWORD );
		$remember = array( 'type' => 'boolean', 'required' => false, 'default' => false );

		// Public routes: they authenticate the caller themselves and are rate limited.
		register_rest_route( self::NAMESPACE_, '/auth/login', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'login' ),
			'permission_callback' => '__return_true',
			'args'                => array( 'phone' => $phone, 'password' => $password, 'remember' => $remember ),
		) );
		register_rest_route( self::NAMESPACE_, '/auth/otp/request', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'otp_request' ),
			'permission_callback' => '__return_true',
			'args'                => array( 'phone' => $phone ),
		) );
		register_rest_route( self::NAMESPACE_, '/auth/otp/verify', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'otp_verify' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'phone'    => $phone,
				'code'     => array( 'type' => 'string', 'required' => true, 'maxLength' => 16 ),
				'remember' => $remember,
			),
		) );

		// Cookie-authenticated routes: core rejects the cookie unless a valid X-WP-Nonce (wp_rest) accompanies it.
		register_rest_route( self::NAMESPACE_, '/auth/logout', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'logout' ),
			'permission_callback' => 'is_user_logged_in',
		) );
		// No minLength here: the handler returns the friendly weak_password message instead of core's generic one.
		register_rest_route( self::NAMESPACE_, '/me/password', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'change_password' ),
			'permission_callback' => array( __CLASS__, 'is_student_request' ),
			'args'                => array(
				'current_password' => array( 'type' => 'string', 'required' => false, 'default' => '', 'maxLength' => self::MAX_PASSWORD ),
				'new_password'     => array( 'type' => 'string', 'required' => true, 'maxLength' => self::MAX_PASSWORD ),
			),
		) );
	}

	public static function is_student_request(): bool {
		$user = wp_get_current_user();
		return $user->exists() && CC_Student_Guard::is_student( $user );
	}

	public static function login( WP_REST_Request $request ): WP_REST_Response {
		$ip = CC_Rate_Limiter::client_ip();
		if ( ! CC_Rate_Limiter::allow( 'login_ip|' . $ip, self::LOGIN_IP_LIMIT, self::LOGIN_WINDOW ) ) {
			return self::error( 429, 'rate_limited', self::MSG_TOO_MANY );
		}
		$phone = CC_Phone::normalize( (string) $request['phone'] );
		if ( null === $phone ) {
			return self::invalid_login();
		}
		$pair = 'login_fail_pair|' . $phone . '|' . $ip;
		if ( CC_Rate_Limiter::count( $pair ) >= self::LOGIN_PAIR_LIMIT
			|| ( self::backoff_until( $phone ) > time() && ! self::is_trusted( $phone, $ip ) ) ) {
			return self::error( 429, 'rate_limited', self::MSG_TOO_MANY );
		}

		$user = self::student_by_phone( $phone );
		$ok   = wp_check_password( (string) $request['password'], $user ? $user->user_pass : self::DUMMY_HASH, $user ? $user->ID : 0 );
		if ( ! $user || ! $ok ) {
			self::record_login_failure( $phone, $pair );
			return self::invalid_login();
		}
		if ( self::temp_password_expired( $user ) ) {
			return self::invalid_login();
		}
		self::clear_login_failures( $phone, $pair );
		self::trust( $phone, $ip );
		return self::sign_in( $user, (bool) $request['remember'], false );
	}

	public static function otp_request( WP_REST_Request $request ): WP_REST_Response {
		$phone = CC_Phone::normalize( (string) $request['phone'] );
		if ( null === $phone ) {
			return self::error( 422, 'invalid_phone', 'Enter a valid Bangladesh mobile number.' );
		}
		// Every outcome after this point takes at least OTP_MIN_SECONDS so timing does not reveal whether the phone is registered.
		$started  = microtime( true );
		$response = self::otp_request_work( $phone );
		$padding  = self::OTP_MIN_SECONDS - ( microtime( true ) - $started );
		if ( $padding > 0 ) {
			usleep( (int) ( $padding * 1000000 ) );
		}
		return $response;
	}

	private static function otp_request_work( string $phone ): WP_REST_Response {
		$ip = CC_Rate_Limiter::client_ip();
		// Buckets are consumed before the registered-phone lookup so the limits behave identically for every number.
		// The phone+IP bucket is the tight one; the per-phone bucket is only a cost cap, set high enough that a stranger cannot exhaust it alone.
		if ( ! CC_Rate_Limiter::allow( 'otp_req_ip|' . $ip, self::OTP_IP_LIMIT, self::OTP_WINDOW )
			|| ! CC_Rate_Limiter::allow( 'otp_req_pair|' . $phone . '|' . $ip, self::OTP_PHONE_IP_LIMIT, self::OTP_WINDOW )
			|| ! CC_Rate_Limiter::allow( 'otp_req_phone|' . $phone, self::OTP_PHONE_LIMIT, self::OTP_WINDOW ) ) {
			return self::error( 429, 'rate_limited', self::MSG_TOO_MANY );
		}
		if ( self::student_by_phone( $phone ) ) {
			CC_Otp::request( $phone, 'login' );
		}
		return self::respond( array( 'ok' => true, 'message' => 'If this number is registered, a code has been sent by SMS.' ) );
	}

	public static function otp_verify( WP_REST_Request $request ): WP_REST_Response {
		$ip = CC_Rate_Limiter::client_ip();
		if ( ! CC_Rate_Limiter::allow( 'otp_verify_ip|' . $ip, self::VERIFY_IP_LIMIT, self::LOGIN_WINDOW ) ) {
			return self::error( 429, 'rate_limited', self::MSG_TOO_MANY );
		}
		$phone = CC_Phone::normalize( (string) $request['phone'] );
		$code  = (string) $request['code'];
		if ( null === $phone || 1 !== preg_match( '/^\d{6}$/', $code ) ) {
			return self::error( 401, 'invalid_code', self::MSG_INVALID_CODE );
		}
		$user = self::student_by_phone( $phone );
		if ( ! CC_Otp::verify( $phone, $code, 'login' ) || ! $user ) {
			return self::error( 401, 'invalid_code', self::MSG_INVALID_CODE );
		}
		self::trust( $phone, $ip );
		return self::sign_in( $user, (bool) $request['remember'], true );
	}

	/** A recent OTP login stands in for the current password, for 15 minutes, in that login session only. */
	public static function otp_recent( int $user_id ): bool {
		$session = self::current_session( $user_id );
		return null !== $session && (int) ( $session[ self::REAUTH_KEY ] ?? 0 ) > time();
	}

	public static function logout(): WP_REST_Response {
		wp_destroy_current_session();
		wp_clear_auth_cookie();
		wp_set_current_user( 0 );
		return self::respond( array( 'ok' => true ) );
	}

	public static function change_password( WP_REST_Request $request ): WP_REST_Response {
		$user = wp_get_current_user();
		if ( ! CC_Rate_Limiter::allow( 'pw_user|' . $user->ID, self::PW_USER_LIMIT, self::LOGIN_WINDOW ) ) {
			return self::error( 429, 'rate_limited', self::MSG_TOO_MANY );
		}
		$current = (string) $request['current_password'];
		$new     = (string) $request['new_password'];

		$reauthed = '' === $current && self::otp_recent( $user->ID );
		if ( ! $reauthed && ! wp_check_password( $current, $user->user_pass, $user->ID ) ) {
			return self::error( 403, 'wrong_password', 'Your current password is incorrect.' );
		}
		if ( strlen( $new ) < self::MIN_PASSWORD ) {
			return self::error( 422, 'weak_password', 'Your new password must be at least ' . self::MIN_PASSWORD . ' characters.' );
		}
		if ( ! $reauthed && hash_equals( $current, $new ) ) {
			return self::error( 422, 'same_password', 'Choose a password different from the current one.' );
		}

		$session  = self::current_session( $user->ID );
		$remember = null !== $session && ( (int) ( $session['expiration'] ?? 0 ) - (int) ( $session['login'] ?? 0 ) ) > self::REMEMBER_MIN_SPAN;

		wp_set_password( $new, $user->ID );
		CC_Enrollment_Repository::mark_pw_changed( $user->ID );

		// Changing the hash invalidates every auth cookie; drop all sessions, then sign this browser back in.
		WP_Session_Tokens::get_instance( $user->ID )->destroy_all();
		self::start_session( $user->ID, $remember );
		// The nonce is bound to the session token, so the page's old one is now stale.
		return self::respond( array( 'ok' => true, 'message' => 'Password updated.', 'nonce' => wp_create_nonce( 'wp_rest' ) ) );
	}

	/**
	 * Ends every session of the user and signs this browser back in, keeping the remember-me choice. Needed whenever the
	 * password or the login name (the phone) changes, because the auth cookie embeds the login and the password hash.
	 *
	 * @return string Fresh REST nonce for the new session.
	 */
	public static function resign( int $user_id ): string {
		$session  = self::current_session( $user_id );
		$remember = null !== $session && ( (int) ( $session['expiration'] ?? 0 ) - (int) ( $session['login'] ?? 0 ) ) > self::REMEMBER_MIN_SPAN;
		WP_Session_Tokens::get_instance( $user_id )->destroy_all();
		self::start_session( $user_id, $remember );
		return wp_create_nonce( 'wp_rest' );
	}

	private static function sign_in( WP_User $user, bool $remember, bool $grant_reauth ): WP_REST_Response {
		$token = self::start_session( $user->ID, $remember );
		if ( $grant_reauth && '' !== $token ) {
			$manager = WP_Session_Tokens::get_instance( $user->ID );
			$session = $manager->get( $token );
			if ( is_array( $session ) ) {
				$manager->update( $token, array_merge( $session, array( self::REAUTH_KEY => time() + self::REAUTH_SECONDS ) ) );
			}
		}

		$row         = CC_Enrollment_Repository::student_row( $user->ID );
		$must_change = $row && (int) $row['must_change_pw'];
		$path        = $must_change ? '/student/profile/?change=1' : '/student/';
		return self::respond( array( 'redirect' => $path ) );
	}

	/** Sets the auth cookies and makes the new session the current one for this request; returns its token ('' if unknown). */
	private static function start_session( int $user_id, bool $remember ): string {
		$token   = '';
		$capture = static function ( $auth_cookie, $expire, $expiration, $uid, $scheme, $new_token ) use ( &$token ) {
			$token                       = (string) $new_token;
			$_COOKIE[ LOGGED_IN_COOKIE ] = wp_generate_auth_cookie( $uid, $expiration, 'logged_in', $new_token );
		};
		add_action( 'set_auth_cookie', $capture, 10, 6 );
		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, $remember );
		remove_action( 'set_auth_cookie', $capture, 10 );
		return $token;
	}

	private static function current_session( int $user_id ): ?array {
		if ( $user_id <= 0 || get_current_user_id() !== $user_id ) {
			return null;
		}
		$token = wp_get_session_token();
		if ( '' === $token ) {
			return null;
		}
		$session = WP_Session_Tokens::get_instance( $user_id )->get( $token );
		return is_array( $session ) ? $session : null;
	}

	private static function student_by_phone( string $phone_e164 ): ?WP_User {
		$user = get_user_by( 'login', ltrim( $phone_e164, '+' ) );
		return ( $user && CC_Student_Guard::is_student( $user ) ) ? $user : null;
	}

	private static function temp_password_expired( WP_User $user ): bool {
		$row = CC_Enrollment_Repository::student_row( $user->ID );
		if ( ! $row || ! (int) $row['must_change_pw'] || empty( $row['temp_pw_expires'] ) ) {
			return false;
		}
		return strtotime( $row['temp_pw_expires'] . ' UTC' ) < time();
	}

	private static function record_login_failure( string $phone, string $pair ): void {
		CC_Rate_Limiter::hit( $pair, self::LOGIN_WINDOW );
		$failures = CC_Rate_Limiter::hit( 'login_fail_phone|' . $phone, self::LOGIN_FAIL_WINDOW );
		if ( $failures > self::LOGIN_BACKOFF_FREE ) {
			$steps = min( $failures - self::LOGIN_BACKOFF_FREE - 1, 16 );
			$delay = min( self::BACKOFF_MAX, self::BACKOFF_BASE * ( 2 ** $steps ) );
			set_transient( self::backoff_key( $phone ), time() + $delay, $delay );
		}
	}

	private static function clear_login_failures( string $phone, string $pair ): void {
		CC_Rate_Limiter::reset( $pair );
		CC_Rate_Limiter::reset( 'login_fail_phone|' . $phone );
		delete_transient( self::backoff_key( $phone ) );
	}

	private static function backoff_until( string $phone ): int {
		return (int) get_transient( self::backoff_key( $phone ) );
	}

	private static function backoff_key( string $phone ): string {
		return 'cc_login_until_' . md5( $phone );
	}

	private static function trust( string $phone, string $ip ): void {
		set_transient( self::trust_key( $phone, $ip ), 1, self::TRUST_SECONDS );
	}

	private static function is_trusted( string $phone, string $ip ): bool {
		return false !== get_transient( self::trust_key( $phone, $ip ) );
	}

	private static function trust_key( string $phone, string $ip ): string {
		return 'cc_login_trust_' . md5( $phone . '|' . $ip );
	}

	private static function invalid_login(): WP_REST_Response {
		return self::error( 401, 'invalid_credentials', self::MSG_INVALID_LOGIN );
	}

	private static function respond( array $body, int $status = 200 ): WP_REST_Response {
		$response = new WP_REST_Response( $body, $status );
		$response->header( 'Cache-Control', 'private, no-store' );
		return $response;
	}

	private static function error( int $status, string $code, string $message ): WP_REST_Response {
		return self::respond( array( 'code' => $code, 'message' => $message ), $status );
	}
}
