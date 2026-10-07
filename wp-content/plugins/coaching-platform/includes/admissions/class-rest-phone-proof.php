<?php
defined( 'ABSPATH' ) || exit;

/**
 * Proof of phone ownership for the public admission form (closes accepted risk L4).
 * POST /cc/v1/applications/verify-phone/request {phone}      sends a code by SMS to any valid number (same answer for registered and unregistered phones).
 * POST /cc/v1/applications/verify-phone/confirm {phone,code} returns a signed, single-use phone_proof for POST /cc/v1/applications.
 * Errors use the envelope {code, message}.
 */
final class CC_Rest_Phone_Proof {

	const NAMESPACE_      = 'cc/v1';
	const BASE            = '/applications/verify-phone/';
	const WINDOW          = 3600;
	const PAIR_LIMIT      = 3;
	const PHONE_LIMIT     = 10;
	const IP_LIMIT        = 10;
	const GLOBAL_LIMIT    = 300;
	const VERIFY_IP_LIMIT = 30;
	const VERIFY_WINDOW   = 900;
	const RESEND_SECONDS  = 60;
	const PHONE_DAILY_LIMIT  = 15;
	const GLOBAL_BUCKET      = 'apply_otp_global';
	const CAPTCHA_AT_PERCENT = 70;
	const WARN_AT_PERCENT    = 90;

	const MSG_TOO_MANY    = 'Too many attempts. Please wait a while and try again.';
	const MSG_LOCKED      = 'Too many wrong codes. Please wait 15 minutes and request a new code.';
	const MSG_LOCKED_DAILY = 'Too many attempts. Try again tomorrow or contact us.';
	const MSG_CAPTCHA     = 'Please complete the verification and try again.';
	const MSG_INVALID     = 'That code is not correct or has expired.';

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'no_store_on_errors' ), 10, 3 );
	}

	/** Core-generated errors (argument validation) bypass respond(); cover both routes here. */
	public static function no_store_on_errors( $response, $server, $request ) {
		if ( 0 === strpos( $request->get_route(), '/' . self::NAMESPACE_ . self::BASE ) && $response instanceof WP_HTTP_Response ) {
			$response->header( 'Cache-Control', 'private, no-store' );
		}
		return $response;
	}

	public static function register_routes(): void {
		$phone = array( 'type' => 'string', 'required' => true, 'maxLength' => 32 );

		register_rest_route( self::NAMESPACE_, self::BASE . 'request', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'request_code' ),
			'permission_callback' => '__return_true', // Public: anonymous applicants; rate limited per IP, phone and globally; captcha above 70% of the global cap.
			'args'                => array( 'phone' => $phone ),
		) );
		register_rest_route( self::NAMESPACE_, self::BASE . 'confirm', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'confirm_code' ),
			'permission_callback' => '__return_true', // Public: anonymous applicants; attempts are capped per code, per phone per day and per IP.
			'args'                => array( 'phone' => $phone, 'code' => array( 'type' => 'string', 'required' => true, 'maxLength' => 16 ) ),
		) );
	}

	public static function request_code( WP_REST_Request $request ): WP_REST_Response {
		$phone = CC_Phone::normalize( (string) $request['phone'] );
		if ( null === $phone ) {
			return self::error( 422, 'invalid_phone', 'Enter a valid Bangladeshi mobile number.' );
		}
		// The registered-phone state is never read here: every valid number gets a code, so the answer cannot leak it.
		$ip = CC_Rate_Limiter::ip_bucket_key();
		if ( CC_Otp::is_ip_blocked( $ip ) ) {
			return self::error( 429, 'rate_limited', self::MSG_TOO_MANY );
		}
		$locked = self::lock_error( $phone );
		if ( null !== $locked ) {
			return $locked;
		}
		if ( self::captcha_required() && ! apply_filters( 'cc_verify_captcha', true, $request ) ) {
			return self::error( 400, 'bot_check_failed', self::MSG_CAPTCHA );
		}
		// Counted in this order so that a refused request never burns a bucket that comes after the one that refused it.
		if ( ! CC_Rate_Limiter::allow( 'apply_otp_ip|' . $ip, self::IP_LIMIT, self::WINDOW ) ) {
			return self::error( 429, 'rate_limited', self::MSG_TOO_MANY );
		}
		if ( ! CC_Rate_Limiter::allow( 'apply_otp_gap|' . $phone, 1, self::RESEND_SECONDS ) ) {
			return self::resend_too_soon( CC_Rate_Limiter::seconds_left( 'apply_otp_gap|' . $phone, self::RESEND_SECONDS ) );
		}
		if ( ! CC_Rate_Limiter::allow( 'apply_otp_pair|' . $phone . '|' . $ip, self::PAIR_LIMIT, self::WINDOW )
			|| ! CC_Rate_Limiter::allow( 'apply_otp_phone|' . $phone, self::PHONE_LIMIT, self::WINDOW )
			|| ! CC_Rate_Limiter::allow( 'apply_otp_phone_day|' . $phone, self::PHONE_DAILY_LIMIT, DAY_IN_SECONDS )
			|| ! CC_Rate_Limiter::allow( self::GLOBAL_BUCKET, self::GLOBAL_LIMIT, self::WINDOW ) ) {
			return self::error( 429, 'rate_limited', self::MSG_TOO_MANY );
		}
		self::warn_when_global_nearly_full();
		try {
			$sent = CC_Otp::request( $phone, 'apply' );
		} catch ( Throwable $e ) {
			error_log( 'CC_Rest_Phone_Proof::request_code: ' . get_class( $e ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			$sent = false;
		}
		if ( ! $sent ) {
			return self::error( 503, 'sms_unavailable', 'We could not send the code. Please try again shortly.' );
		}
		return self::respond( array(
			'ok'           => true,
			'phone'        => CC_Phone::mask( $phone ),
			'expires_in'   => CC_Otp::TTL_SECONDS,
			'resend_after' => self::RESEND_SECONDS,
		) );
	}

	public static function confirm_code( WP_REST_Request $request ): WP_REST_Response {
		$ip = CC_Rate_Limiter::ip_bucket_key();
		if ( ! CC_Rate_Limiter::allow( 'apply_verify_ip|' . $ip, self::VERIFY_IP_LIMIT, self::VERIFY_WINDOW ) || CC_Otp::is_ip_blocked( $ip ) ) {
			return self::error( 429, 'rate_limited', self::MSG_TOO_MANY );
		}
		$phone = CC_Phone::normalize( (string) $request['phone'] );
		$code  = (string) $request['code'];
		if ( null === $phone || 1 !== preg_match( '/^\d{6}$/', $code ) ) {
			return self::error( 422, 'invalid_code', self::MSG_INVALID );
		}
		$locked = self::lock_error( $phone );
		if ( null !== $locked ) {
			return $locked;
		}
		if ( ! CC_Otp::verify( $phone, $code, 'apply', $ip ) ) {
			return self::lock_error( $phone ) ?? self::error( 422, 'invalid_code', self::MSG_INVALID );
		}
		return self::respond( array( 'phone_proof' => CC_Phone_Proof::issue( $phone ), 'expires_in' => CC_Phone_Proof::TTL_SECONDS ) );
	}

	/** The 24 hour lock outranks the 15 minute one: its message tells the applicant not to wait for a quarter of an hour. */
	private static function lock_error( string $phone ): ?WP_REST_Response {
		if ( CC_Otp::is_daily_locked( $phone, 'apply' ) ) {
			return self::error( 429, 'locked_daily', self::MSG_LOCKED_DAILY );
		}
		return CC_Otp::is_locked( $phone, 'apply' ) ? self::error( 429, 'locked', self::MSG_LOCKED ) : null;
	}

	private static function resend_too_soon( int $seconds ): WP_REST_Response {
		$response = self::error( 429, 'resend_too_soon', sprintf( 'Please wait %d seconds before requesting another code.', $seconds ) );
		$response->set_data( array_merge( $response->get_data(), array( 'retry_after' => $seconds ) ) );
		$response->header( 'Retry-After', (string) $seconds );
		return $response;
	}

	/** Turnstile when a secret is configured, and for everyone once the global bucket is past CAPTCHA_AT_PERCENT. */
	private static function captcha_required(): bool {
		$secret = defined( 'TURNSTILE_SECRET' ) ? (string) TURNSTILE_SECRET : (string) getenv( 'TURNSTILE_SECRET' );
		return '' !== $secret || CC_Rate_Limiter::count( self::GLOBAL_BUCKET ) * 100 > self::GLOBAL_LIMIT * self::CAPTCHA_AT_PERCENT;
	}

	/** One error_log line (no personal data) the first time the global bucket passes WARN_AT_PERCENT in a window. */
	private static function warn_when_global_nearly_full(): void {
		if ( CC_Rate_Limiter::count( self::GLOBAL_BUCKET ) * 100 >= self::GLOBAL_LIMIT * self::WARN_AT_PERCENT
			&& 1 === CC_Rate_Limiter::hit( self::GLOBAL_BUCKET . '_warned', self::WINDOW ) ) {
			error_log( sprintf( 'CC_Rest_Phone_Proof: admission SMS global cap is over %d%% used (limit %d per hour).', self::WARN_AT_PERCENT, self::GLOBAL_LIMIT ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
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
