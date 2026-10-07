<?php
defined( 'ABSPATH' ) || exit;

/**
 * The cc_student role: portal-only accounts that never see wp-admin, wp-login or the admin bar.
 */
final class CC_Roles {

	const ROLE = 'cc_student';

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'ensure_role' ) );
		add_filter( 'show_admin_bar', array( __CLASS__, 'filter_admin_bar' ) );
		add_action( 'init', array( __CLASS__, 'block_admin_early' ), 1 );
		add_action( 'admin_init', array( __CLASS__, 'block_admin' ) );
		add_action( 'login_init', array( __CLASS__, 'block_login_page' ) );
		add_filter( 'login_redirect', array( __CLASS__, 'filter_login_redirect' ), 99, 3 );
	}

	public static function ensure_role(): void {
		if ( null === get_role( self::ROLE ) ) {
			add_role( self::ROLE, 'Student', array( 'read' => true ) );
		}
	}

	/** @param WP_User|int $user */
	public static function is_student( $user ): bool {
		$user = $user instanceof WP_User ? $user : get_userdata( (int) $user );
		return $user instanceof WP_User && in_array( self::ROLE, (array) $user->roles, true );
	}

	public static function portal_url(): string {
		return home_url( '/student/' );
	}

	public static function filter_admin_bar( $show ) {
		return is_user_logged_in() && self::is_student( wp_get_current_user() ) ? false : $show;
	}

	/** admin.php rejects unknown ?page= values with a 403 before admin_init fires, so students are redirected at init. */
	public static function block_admin_early(): void {
		if ( is_admin() ) {
			self::block_admin();
		}
	}

	public static function block_admin(): void {
		if ( wp_doing_ajax() || ! is_user_logged_in() || ! self::is_student( wp_get_current_user() ) ) {
			return;
		}
		wp_safe_redirect( self::portal_url() );
		exit;
	}

	/** Covers a student who is already logged in and opens wp-login.php; logout must keep working. */
	public static function block_login_page(): void {
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : 'login'; // phpcs:ignore WordPress.Security.NonceVerification
		if ( 'logout' === $action || ! is_user_logged_in() || ! self::is_student( wp_get_current_user() ) ) {
			return;
		}
		wp_safe_redirect( self::portal_url() );
		exit;
	}

	/** Students who sign in through wp-login.php land on the portal, not wp-admin. */
	public static function filter_login_redirect( $redirect_to, $requested, $user ) {
		return $user instanceof WP_User && self::is_student( $user ) ? self::portal_url() : $redirect_to;
	}
}
