<?php
defined( 'ABSPATH' ) || exit;

/**
 * Keeps cc_student accounts out of every core WordPress credential path. Students sign in only through
 * CC_Rest_Auth (password or OTP) and recover only by OTP, so core password reset, wp-login.php /
 * XML-RPC / application-password authentication and the core users REST API are closed to them.
 */
final class CC_Student_Lockdown {

	const USERS_ROUTE_PREFIX = '/wp/v2/users';
	const MSG_DENIED         = 'Invalid credentials';

	public static function init(): void {
		add_filter( 'allow_password_reset', array( __CLASS__, 'filter_allow_password_reset' ), 10, 2 );
		add_action( 'lostpassword_post', array( __CLASS__, 'block_lostpassword' ), 10, 2 );
		add_action( 'login_form_resetpass', array( __CLASS__, 'block_reset_form' ) );
		add_action( 'login_form_rp', array( __CLASS__, 'block_reset_form' ) );
		add_filter( 'authenticate', array( __CLASS__, 'filter_authenticate' ), 99 );
		add_filter( 'wp_is_application_passwords_available_for_user', array( __CLASS__, 'filter_app_passwords' ), 10, 2 );
		add_filter( 'rest_endpoints', array( __CLASS__, 'filter_rest_endpoints' ) );
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'guard_users_dispatch' ), 10, 3 );
		add_filter( 'rest_pre_insert_user', array( __CLASS__, 'guard_user_write' ), 10, 2 );
	}

	/** @param bool|WP_Error $allow */
	public static function filter_allow_password_reset( $allow, $user_id ) {
		return CC_Roles::is_student( (int) $user_id ) ? new WP_Error( 'password_reset_not_allowed', __( 'Password reset is not allowed for this user.' ) ) : $allow;
	}

	/** Same reply as an unknown account, so lostpassword does not reveal which logins are students. */
	public static function block_lostpassword( $errors, $user_data = false ): void {
		if ( $errors instanceof WP_Error && $user_data instanceof WP_User && CC_Roles::is_student( $user_data ) ) {
			$errors->add( 'password_reset_not_allowed', __( 'Password reset is not allowed for this user.' ) );
		}
	}

	/** A reset link issued before this lockdown must not work for a student either. */
	public static function block_reset_form(): void {
		$login = isset( $_REQUEST['login'] ) ? sanitize_user( wp_unslash( $_REQUEST['login'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$user  = '' === $login ? false : get_user_by( 'login', $login );
		if ( $user instanceof WP_User && CC_Roles::is_student( $user ) ) {
			wp_safe_redirect( home_url( '/student/login/' ) );
			exit;
		}
	}

	/** @param WP_User|WP_Error|null $user */
	public static function filter_authenticate( $user ) {
		if ( $user instanceof WP_User && CC_Roles::is_student( $user ) ) {
			return new WP_Error( 'invalid_username', self::MSG_DENIED );
		}
		return $user;
	}

	public static function filter_app_passwords( $available, $user ) {
		return $user instanceof WP_User && CC_Roles::is_student( $user ) ? false : $available;
	}

	public static function filter_rest_endpoints( $endpoints ) {
		if ( ! self::current_is_student() || ! is_array( $endpoints ) ) {
			return $endpoints;
		}
		return array_filter(
			$endpoints,
			static fn( $route ) => ! self::is_users_route( (string) $route ),
			ARRAY_FILTER_USE_KEY
		);
	}

	public static function guard_users_dispatch( $result, $server, $request ) {
		if ( self::current_is_student() && $request instanceof WP_REST_Request && self::is_users_route( $request->get_route() ) ) {
			return new WP_Error( 'rest_forbidden', 'Sorry, you are not allowed to do that.', array( 'status' => 403 ) );
		}
		return $result;
	}

	/** @param object $prepared_user */
	public static function guard_user_write( $prepared_user, $request ) {
		if ( self::current_is_student() ) {
			return new WP_Error( 'rest_forbidden', 'Sorry, you are not allowed to do that.', array( 'status' => 403 ) );
		}
		return $prepared_user;
	}

	private static function is_users_route( string $route ): bool {
		return 0 === strpos( $route, self::USERS_ROUTE_PREFIX );
	}

	private static function current_is_student(): bool {
		return is_user_logged_in() && CC_Roles::is_student( wp_get_current_user() );
	}
}
