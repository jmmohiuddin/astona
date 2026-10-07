<?php
defined( 'ABSPATH' ) || exit;

/**
 * Students change their login mobile number themselves (TRD FR-020): password (or a fresh OTP login) proves who is
 * asking, an OTP sent to the NEW number proves they hold it. The phone is the login name, so the change rewrites
 * user_login, ends every session (the auth cookie embeds the login) and signs this browser back in. The old number
 * is told by SMS. Whether a number is already registered is never revealed: a taken number behaves like any other
 * until the code step, which then fails exactly like a wrong code.
 */
final class CC_Phone_Change {

	const META_PENDING   = 'cc_pending_phone';
	const PENDING_TTL    = 600;
	const REQUEST_LIMIT  = 3;
	const REQUEST_WINDOW = 3600;
	const CONFIRM_LIMIT  = 5;
	const CONFIRM_WINDOW = 900;
	const MSG_SENT       = 'If this number can be used, we sent a 6-digit code to it. It expires in 5 minutes.';
	const MSG_BAD_CODE   = 'That code is not valid or has expired. Request a new one.';

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		$phone = array( 'type' => 'string', 'required' => true, 'maxLength' => 32 );
		register_rest_route( 'cc/v1', '/me/phone/request', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'rest_request' ),
			'permission_callback' => array( 'CC_Rest_Auth', 'is_student_request' ),
			'args'                => array(
				'phone'            => $phone,
				'current_password' => array( 'type' => 'string', 'required' => false, 'default' => '', 'maxLength' => 200 ),
			),
		) );
		register_rest_route( 'cc/v1', '/me/phone/confirm', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( __CLASS__, 'rest_confirm' ),
			'permission_callback' => array( 'CC_Rest_Auth', 'is_student_request' ),
			'args'                => array(
				'phone' => $phone,
				'code'  => array( 'type' => 'string', 'required' => true, 'maxLength' => 16 ),
			),
		) );
	}

	public static function rest_request( WP_REST_Request $request ): WP_REST_Response {
		$result = self::request( get_current_user_id(), (string) $request['phone'], (string) $request['current_password'] );
		return is_wp_error( $result ) ? self::error( $result ) : self::respond( array( 'ok' => true, 'message' => self::MSG_SENT ) );
	}

	public static function rest_confirm( WP_REST_Request $request ): WP_REST_Response {
		$result = self::confirm( get_current_user_id(), (string) $request['phone'], (string) $request['code'] );
		if ( is_wp_error( $result ) ) {
			return self::error( $result );
		}
		return self::respond( array( 'ok' => true, 'message' => 'Your mobile number was changed.', 'phone' => $result['phone'], 'nonce' => $result['nonce'] ) );
	}

	/** @return true|WP_Error */
	public static function request( int $user_id, string $new_raw, string $password ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return new WP_Error( 'forbidden', 'Not signed in.', array( 'status' => 403 ) );
		}
		if ( ! CC_Rate_Limiter::allow( 'phone_chg_req|' . $user_id, self::REQUEST_LIMIT, self::REQUEST_WINDOW )
			|| ! CC_Rate_Limiter::allow( 'phone_chg_ip|' . CC_Rate_Limiter::ip_bucket_key(), self::REQUEST_LIMIT * 4, self::REQUEST_WINDOW ) ) {
			return new WP_Error( 'rate_limited', 'Too many attempts. Please try again later.', array( 'status' => 429 ) );
		}
		$reauthed = '' === $password && CC_Rest_Auth::otp_recent( $user_id );
		if ( ! $reauthed && ! wp_check_password( $password, $user->user_pass, $user_id ) ) {
			return new WP_Error( 'wrong_password', 'Your current password is incorrect.', array( 'status' => 403 ) );
		}
		$new = CC_Phone::normalize( $new_raw );
		if ( null === $new ) {
			return new WP_Error( 'invalid_phone', 'Enter a valid Bangladesh mobile number.', array( 'status' => 422 ) );
		}
		if ( ltrim( $new, '+' ) === (string) $user->user_login ) {
			return new WP_Error( 'same_phone', 'That is already your number.', array( 'status' => 422 ) );
		}
		// A number that belongs to someone else gets the same answer and no SMS, so it cannot be probed.
		if ( ! self::taken( $new, $user_id ) && CC_Otp::request( $new, 'phone_change' ) ) {
			update_user_meta( $user_id, self::META_PENDING, array( 'phone' => $new, 'expires' => time() + self::PENDING_TTL ) );
		}
		return true;
	}

	/** @return array{phone:string,nonce:string}|WP_Error */
	public static function confirm( int $user_id, string $new_raw, string $code ) {
		global $wpdb;
		$user = get_userdata( $user_id );
		$bad  = new WP_Error( 'invalid_code', self::MSG_BAD_CODE, array( 'status' => 422 ) );
		if ( ! $user ) {
			return new WP_Error( 'forbidden', 'Not signed in.', array( 'status' => 403 ) );
		}
		if ( ! CC_Rate_Limiter::allow( 'phone_chg_confirm|' . $user_id, self::CONFIRM_LIMIT, self::CONFIRM_WINDOW ) ) {
			return new WP_Error( 'rate_limited', 'Too many attempts. Please try again later.', array( 'status' => 429 ) );
		}
		$new     = CC_Phone::normalize( $new_raw );
		$pending = get_user_meta( $user_id, self::META_PENDING, true );
		if ( null === $new || 1 !== preg_match( '/^\d{6}$/', trim( $code ) ) || ! is_array( $pending ) || ( $pending['phone'] ?? '' ) !== $new || (int) ( $pending['expires'] ?? 0 ) < time() ) {
			return $bad;
		}
		if ( ! CC_Otp::verify( $new, trim( $code ), 'phone_change' ) ) {
			return $bad;
		}
		delete_user_meta( $user_id, self::META_PENDING );

		$old_login = (string) $user->user_login;
		$digits    = ltrim( $new, '+' );
		if ( self::taken( $new, $user_id ) ) {
			return $bad; // Taken between request and confirm; same answer as a wrong code.
		}
		$updated = $wpdb->update( $wpdb->users, array( 'user_login' => $digits, 'user_nicename' => sanitize_title( $digits ) ), array( 'ID' => $user_id ) );
		if ( 1 !== $updated ) {
			return new WP_Error( 'phone_change_failed', 'We could not change your number. Please contact us.', array( 'status' => 500 ) );
		}
		clean_user_cache( $user_id );
		update_user_meta( $user_id, 'cc_phone_changed_at', gmdate( 'Y-m-d H:i:s' ) );
		CC_Audit::log( 'student.phone_changed', 'user', $user_id, array( 'old_hash' => hash( 'sha256', $old_login ), 'new_hash' => hash( 'sha256', $digits ) ) );
		CC_Sms::queue( '+' . $old_login, 'phone_changed', array( 'new_phone' => CC_Phone::mask( $new ) ), 'student', $user_id );

		$nonce = CC_Rest_Auth::resign( $user_id );
		return array( 'phone' => $new, 'nonce' => $nonce );
	}

	/** True when another account already uses this number as its login. */
	public static function taken( string $e164, int $except_user_id ): bool {
		$other = get_user_by( 'login', ltrim( $e164, '+' ) );
		return $other instanceof WP_User && (int) $other->ID !== $except_user_id;
	}

	private static function respond( array $body, int $status = 200 ): WP_REST_Response {
		$response = new WP_REST_Response( $body, $status );
		$response->header( 'Cache-Control', 'private, no-store' );
		return $response;
	}

	private static function error( WP_Error $e ): WP_REST_Response {
		$data = $e->get_error_data();
		return self::respond( array( 'code' => $e->get_error_code(), 'message' => $e->get_error_message() ), (int) ( $data['status'] ?? 400 ) );
	}
}
