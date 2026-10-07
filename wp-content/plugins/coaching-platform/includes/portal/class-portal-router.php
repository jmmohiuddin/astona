<?php
defined( 'ABSPATH' ) || exit;

/**
 * Student portal routing: /student/, /student/login/, /student/payments/, /student/payments/receipt/, /student/profile/,
 * /student/courses/, /student/courses/{batch_id}/, /student/notices/, /student/resources/{lesson_id}/.
 */
final class CC_Portal_Router {

	const QUERY_VAR      = 'cc_portal';
	const ID_QUERY_VAR   = 'cc_portal_id';
	const FLUSH_OPTION   = 'cc_portal_rewrite_version';
	const REWRITE_SCHEMA = '2';

	/** @var array<string,string> view => theme template file */
	const TEMPLATES = array(
		'dashboard' => 'student-dashboard.php',
		'login'     => 'student-login.php',
		'payments'  => 'student-payments.php',
		'receipt'   => 'student-receipt.php',
		'profile'   => 'student-profile.php',
		'courses'   => 'student-courses.php',
		'course'    => 'student-course.php',
		'notices'   => 'student-notices.php',
		'resource'  => '',
	);

	/** @var array<string,string> rewrite regex => view (plus extra query args) */
	const RULES = array(
		'^student/?$'                    => 'dashboard',
		'^student/login/?$'              => 'login',
		'^student/payments/?$'           => 'payments',
		'^student/payments/receipt/?$'   => 'receipt',
		'^student/profile/?$'            => 'profile',
		'^student/courses/?$'            => 'courses',
		'^student/courses/([0-9]+)/?$'   => 'course&cc_portal_id=$matches[1]',
		'^student/notices/?$'            => 'notices',
		'^student/resources/([0-9]+)/?$' => 'resource&cc_portal_id=$matches[1]',
	);

	public static function init(): void {
		$rest = CC_PATH . 'includes/portal/class-rest-portal.php';
		if ( is_readable( $rest ) ) {
			require_once $rest;
			CC_Rest_Portal::init();
		}
		add_action( 'init', array( __CLASS__, 'add_rewrite_rules' ) );
		add_action( 'init', array( __CLASS__, 'maybe_flush' ), 99 );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_filter( 'template_include', array( __CLASS__, 'template_include' ), 99 );
		add_action( 'template_redirect', array( __CLASS__, 'send_headers' ), 0 );
		add_filter( 'wp_robots', array( __CLASS__, 'robots' ) );
		add_filter( 'redirect_canonical', array( __CLASS__, 'skip_canonical' ) );
	}

	public static function add_rewrite_rules(): void {
		foreach ( self::RULES as $regex => $view ) {
			add_rewrite_rule( $regex, 'index.php?' . self::QUERY_VAR . '=' . $view, 'top' );
		}
	}

	public static function maybe_flush(): void {
		$current = CC_VERSION . '-' . self::REWRITE_SCHEMA . '-db' . get_option( CC_Migrations::OPTION, '' );
		if ( get_option( self::FLUSH_OPTION ) === $current ) {
			return;
		}
		flush_rewrite_rules( false );
		update_option( self::FLUSH_OPTION, $current, false );
	}

	/** @param array<int,string> $vars */
	public static function query_vars( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		$vars[] = self::ID_QUERY_VAR;
		return $vars;
	}

	/** Current portal view key, or '' when not a portal request. */
	public static function current_view(): string {
		$view = (string) get_query_var( self::QUERY_VAR );
		return isset( self::TEMPLATES[ $view ] ) ? $view : '';
	}

	/** Numeric id captured from the URL (batch id or lesson id); 0 when absent. */
	public static function current_id(): int {
		return absint( get_query_var( self::ID_QUERY_VAR ) );
	}

	public static function template_include( string $template ): string {
		$view = self::current_view();
		if ( '' === $view ) {
			return $template;
		}
		$located = locate_template( self::TEMPLATES[ $view ] );
		return '' !== $located ? $located : $template;
	}

	public static function send_headers(): void {
		global $wp_query;
		if ( '' === self::current_view() ) {
			return;
		}
		// No post matches the rewrite, so WP would otherwise answer 404.
		$wp_query->is_404 = false;
		status_header( 200 );
		header( 'Cache-Control: private, no-store, max-age=0' );
		header( 'Pragma: no-cache' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
	}

	/** @param array<string,mixed> $robots */
	public static function robots( array $robots ): array {
		if ( '' === self::current_view() ) {
			return $robots;
		}
		return array( 'noindex' => true, 'nofollow' => true );
	}

	/** @param string|false $redirect */
	public static function skip_canonical( $redirect ) {
		return '' !== self::current_view() ? false : $redirect;
	}

	public static function url( string $view = 'dashboard', array $args = array() ): string {
		$paths = array(
			'dashboard' => '/student/',
			'login'     => '/student/login/',
			'payments'  => '/student/payments/',
			'receipt'   => '/student/payments/receipt/',
			'profile'   => '/student/profile/',
			'courses'   => '/student/courses/',
			'notices'   => '/student/notices/',
		);
		$id = isset( $args['id'] ) && in_array( $view, array( 'course', 'resource' ), true ) ? absint( $args['id'] ) : 0;
		if ( $id ) {
			unset( $args['id'] );
			return add_query_arg( $args, home_url( ( 'course' === $view ? '/student/courses/' : '/student/resources/' ) . $id . '/' ) );
		}
		return add_query_arg( $args, home_url( $paths[ $view ] ?? '/student/' ) );
	}
}
