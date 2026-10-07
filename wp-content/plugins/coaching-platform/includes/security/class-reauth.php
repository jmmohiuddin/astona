<?php
defined( 'ABSPATH' ) || exit;

/**
 * Password re-confirmation before bulk data exports (SDD 6: "exports require re-auth"). A confirmation is good for
 * 10 minutes. CC_Csv::stream() calls require_fresh() so every export path, present and future, is covered.
 */
final class CC_Reauth {

	const PAGE        = 'cc-reauth';
	const ACTION      = 'cc_reauth';
	const META        = 'cc_reauth_at';
	const FRESH_FOR   = 600;
	const FAIL_LIMIT  = 5;
	const FAIL_WINDOW = 900;

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
	}

	public static function is_fresh( int $user_id, ?int $now = null ): bool {
		if ( CC_Staff_2fa::relaxed() ) {
			return true;
		}
		return ( ( $now ?? time() ) - (int) get_user_meta( $user_id, self::META, true ) ) <= self::FRESH_FOR;
	}

	/** Stops the request with a redirect (GET) or an explanatory page (POST) unless the password was confirmed recently. */
	public static function require_fresh(): void {
		if ( self::is_fresh( get_current_user_id() ) ) {
			return;
		}
		$here = admin_url( 'admin-post.php' ) . ( '' !== (string) ( $_SERVER['QUERY_STRING'] ?? '' ) ? '?' . $_SERVER['QUERY_STRING'] : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- validated by safe_return() before use.
		if ( 'GET' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			wp_safe_redirect( add_query_arg( 'return', rawurlencode( $here ), admin_url( 'admin.php?page=' . self::PAGE ) ) );
			exit;
		}
		wp_die(
			wp_kses_post( sprintf( '<p>%s</p><p><a href="%s">%s</a></p>', esc_html__( 'Please confirm your password before exporting data, then repeat the export.', 'coaching-platform' ), esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ), esc_html__( 'Confirm password', 'coaching-platform' ) ) ),
			'',
			array( 'response' => 403 )
		);
	}

	/** Pure: only same-site admin-post export links may be returned to. */
	public static function safe_return( string $url, string $admin_post_url ): string {
		$base = wp_parse_url( $admin_post_url );
		$want = wp_parse_url( $url );
		if ( ! is_array( $want ) || ( $want['host'] ?? '' ) !== ( $base['host'] ?? '' ) || ( $want['path'] ?? '' ) !== ( $base['path'] ?? '' ) ) {
			return '';
		}
		return $url;
	}

	public static function register_page(): void {
		add_submenu_page( '', __( 'Confirm password', 'coaching-platform' ), '', 'read', self::PAGE, array( __CLASS__, 'render' ) );
	}

	public static function render(): void {
		$return = isset( $_GET['return'] ) ? esc_url_raw( rawurldecode( wp_unslash( $_GET['return'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- validated again on submit.
		echo '<div class="wrap"><h1>' . esc_html__( 'Confirm your password', 'coaching-platform' ) . '</h1>';
		if ( isset( $_GET['failed'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-error"><p>' . esc_html__( 'That password is not correct, or there were too many attempts.', 'coaching-platform' ) . '</p></div>';
		}
		echo '<p>' . esc_html__( 'Exports contain personal data. Confirm your password to continue for the next 10 minutes.', 'coaching-platform' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '"><input type="hidden" name="return" value="' . esc_attr( $return ) . '">';
		wp_nonce_field( self::ACTION );
		echo '<p><label for="cc-reauth-pw">' . esc_html__( 'Password', 'coaching-platform' ) . '</label><br><input type="password" id="cc-reauth-pw" name="password" class="regular-text" autocomplete="current-password" required></p>';
		submit_button( __( 'Confirm', 'coaching-platform' ) );
		echo '</form></div>';
	}

	public static function handle(): void {
		check_admin_referer( self::ACTION );
		$user   = wp_get_current_user();
		$return = self::safe_return( esc_url_raw( wp_unslash( $_POST['return'] ?? '' ) ), admin_url( 'admin-post.php' ) );
		$back   = add_query_arg( array( 'page' => self::PAGE, 'failed' => 1, 'return' => rawurlencode( $return ) ), admin_url( 'admin.php' ) );
		if ( ! CC_Rate_Limiter::allow( 'reauth|' . $user->ID, self::FAIL_LIMIT, self::FAIL_WINDOW ) || ! wp_check_password( (string) wp_unslash( $_POST['password'] ?? '' ), $user->user_pass, $user->ID ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared, never output.
			wp_safe_redirect( $back );
			exit;
		}
		update_user_meta( $user->ID, self::META, time() );
		CC_Audit::log( 'staff.reauth', 'user', $user->ID );
		wp_safe_redirect( '' !== $return ? $return : admin_url( 'admin.php?page=cc-dashboard' ) );
		exit;
	}
}
