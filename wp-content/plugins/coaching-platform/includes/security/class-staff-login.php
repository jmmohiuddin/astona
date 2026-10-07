<?php
defined( 'ABSPATH' ) || exit;

/**
 * Staff sign-in lives at /admin/login/ (TRD FR-011, SDD 6 and 9). The default wp-login.php is closed to direct requests
 * (404) so scanners and credential-stuffing bots that hammer it find nothing; every login, logout, lost-password and
 * reset link WordPress generates is rewritten to the new path. Also enforces the 60 minute idle timeout for staff.
 * Students never use either path: they sign in at /student/login/.
 */
final class CC_Staff_Login {

	const SLUG          = 'admin/login';
	const IDLE_SECONDS  = 3600;
	const META_ACTIVITY = 'cc_last_activity';
	const IDLE_FLAG     = 'cc_idle';
	/** Activity is recorded at most this often to keep writes off every request. */
	const TOUCH_EVERY   = 60;

	public static function init(): void {
		add_filter( 'login_url', array( __CLASS__, 'filter_login_url' ), 10, 3 );
		add_filter( 'site_url', array( __CLASS__, 'filter_site_url' ), 10, 3 );
		add_filter( 'network_site_url', array( __CLASS__, 'filter_site_url' ), 10, 3 );
		add_action( 'init', array( __CLASS__, 'block_direct' ), 0 );
		add_action( 'wp_loaded', array( __CLASS__, 'serve' ), 5 );
		add_action( 'wp_login', array( __CLASS__, 'on_login' ), 10, 2 );
		add_action( 'admin_init', array( __CLASS__, 'enforce_idle' ), 1 );
		add_filter( 'rest_authentication_errors', array( __CLASS__, 'enforce_idle_rest' ), 110 );
		add_filter( 'login_message', array( __CLASS__, 'idle_message' ) );
	}

	public static function login_path_url(): string {
		return home_url( '/' . self::SLUG . '/' );
	}

	/** @param string $login_url */
	public static function filter_login_url( $login_url, $redirect = '', $force_reauth = false ): string {
		$url = self::login_path_url();
		if ( '' !== (string) $redirect ) {
			$url = add_query_arg( 'redirect_to', rawurlencode( (string) $redirect ), $url );
		}
		if ( $force_reauth ) {
			$url = add_query_arg( 'reauth', '1', $url );
		}
		return $url;
	}

	/** Rewrites wp-login.php links (login, logout, lost password, reset) to the staff path. */
	public static function filter_site_url( $url, $path = '', $scheme = null ) {
		if ( ! is_string( $path ) || 0 !== strpos( $path, 'wp-login.php' ) || ! in_array( $scheme, array( 'login', 'login_post' ), true ) ) {
			return $url;
		}
		return self::login_path_url() . substr( $path, strlen( 'wp-login.php' ) );
	}

	/** Direct hits on wp-login.php get a plain 404. */
	public static function block_direct(): void {
		global $pagenow;
		if ( 'wp-login.php' !== $pagenow || defined( 'CC_STAFF_LOGIN' ) ) {
			return;
		}
		status_header( 404 );
		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo 'Not found';
		exit;
	}

	/** Serves the core login screen at the staff path. */
	public static function serve(): void {
		if ( ! self::is_login_request( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), (string) wp_parse_url( self::login_path_url(), PHP_URL_PATH ) ) ) {
			return;
		}
		define( 'CC_STAFF_LOGIN', true );
		global $pagenow, $error, $interim_login, $action, $user_login;
		$pagenow = 'wp-login.php';
		require_once ABSPATH . 'wp-login.php';
		exit;
	}

	/** Pure: does this request URI address the staff login path (query string and trailing slash ignored)? */
	public static function is_login_request( string $request_uri, string $login_path ): bool {
		$path = (string) parse_url( $request_uri, PHP_URL_PATH );
		return '' !== $login_path && rtrim( $path, '/' ) === rtrim( $login_path, '/' );
	}

	public static function on_login( $user_login, $user ): void {
		if ( $user instanceof WP_User && self::is_staff( $user ) ) {
			update_user_meta( $user->ID, self::META_ACTIVITY, time() );
		}
	}

	public static function is_staff( WP_User $user ): bool {
		$staff = array( CC_Admin_Roles::ROLE_OWNER, CC_Admin_Roles::ROLE_STAFF, CC_Admin_Roles::ROLE_INSTRUCTOR, 'administrator' );
		return (bool) array_intersect( $staff, (array) $user->roles );
	}

	/** Pure: has a session been idle longer than the limit? A missing timestamp counts as fresh (first request after login). */
	public static function is_idle( ?int $last_activity, int $now, int $limit = self::IDLE_SECONDS ): bool {
		return null !== $last_activity && $last_activity > 0 && ( $now - $last_activity ) > $limit;
	}

	public static function idle_limit(): int {
		return defined( 'CC_ADMIN_IDLE_SECONDS' ) ? max( 60, (int) CC_ADMIN_IDLE_SECONDS ) : self::IDLE_SECONDS;
	}

	public static function enforce_idle(): void {
		$user = wp_get_current_user();
		if ( ! $user->exists() || ! self::is_staff( $user ) ) {
			return;
		}
		if ( self::expired_or_touch( $user ) ) {
			wp_logout();
			if ( wp_doing_ajax() ) {
				wp_die( esc_html__( 'Your session timed out. Please sign in again.', 'coaching-platform' ), '', array( 'response' => 401 ) );
			}
			wp_safe_redirect( add_query_arg( self::IDLE_FLAG, '1', self::login_path_url() ) );
			exit;
		}
	}

	/** @param WP_Error|null|bool $result */
	public static function enforce_idle_rest( $result ) {
		$user = wp_get_current_user();
		if ( ! $user->exists() || ! self::is_staff( $user ) ) {
			return $result;
		}
		if ( self::expired_or_touch( $user ) ) {
			wp_logout();
			return new WP_Error( 'cc_session_timeout', 'Your session timed out. Please sign in again.', array( 'status' => 401 ) );
		}
		return $result;
	}

	/** Records activity unless the session already idled out. Heartbeat polling never counts as activity. */
	private static function expired_or_touch( WP_User $user ): bool {
		$now  = time();
		$last = (int) get_user_meta( $user->ID, self::META_ACTIVITY, true );
		if ( self::is_idle( $last ?: null, $now, self::idle_limit() ) ) {
			return true;
		}
		$heartbeat = wp_doing_ajax() && 'heartbeat' === ( $_POST['action'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification -- only inspects the action name.
		if ( ! $heartbeat && ( 0 === $last || $now - $last >= self::TOUCH_EVERY ) ) {
			update_user_meta( $user->ID, self::META_ACTIVITY, $now );
		}
		return false;
	}

	public static function idle_message( $message ) {
		if ( ! empty( $_GET[ self::IDLE_FLAG ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- display only.
			return '<div class="message"><p>' . esc_html__( 'You were signed out after a period of inactivity. Please sign in again.', 'coaching-platform' ) . '</p></div>' . $message;
		}
		return $message;
	}
}
