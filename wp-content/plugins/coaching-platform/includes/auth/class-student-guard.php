<?php
defined( 'ABSPATH' ) || exit;

/** Access gate for portal pages. */
final class CC_Student_Guard {

	const ROLE = 'cc_student';

	public static function init(): void {
		add_filter( 'logout_redirect', array( __CLASS__, 'logout_redirect' ), 10, 3 );
	}

	/** @param string|WP_User|WP_Error $user */
	public static function logout_redirect( $redirect_to, $requested, $user ) {
		return ( $user instanceof WP_User && self::is_student( $user ) ) ? home_url( '/student/login/' ) : $redirect_to;
	}

	public static function is_student( WP_User $user ): bool {
		return in_array( self::ROLE, (array) $user->roles, true );
	}

	/** Returns the signed-in student or redirects (and exits). */
	public static function require_student(): WP_User {
		self::send_no_store();
		$user = wp_get_current_user();

		if ( ! $user->exists() || ! self::is_student( $user ) ) {
			$here = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( (string) $_SERVER['REQUEST_URI'] ) : '/student/';
			wp_safe_redirect( add_query_arg( 'redirect', rawurlencode( $here ), home_url( '/student/login/' ) ) );
			exit;
		}

		$row = CC_Enrollment_Repository::student_row( $user->ID );
		if ( $row && (int) $row['must_change_pw'] && ! self::on_profile_page() ) {
			wp_safe_redirect( home_url( '/student/profile/?change=1' ) );
			exit;
		}
		return $user;
	}

	public static function send_no_store(): void {
		if ( headers_sent() ) {
			return;
		}
		nocache_headers();
		header( 'Cache-Control: private, no-store' );
	}

	private static function on_profile_page(): bool {
		$path = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ) : '';
		return 0 === strpos( trailingslashit( $path ), '/student/profile/' );
	}
}
