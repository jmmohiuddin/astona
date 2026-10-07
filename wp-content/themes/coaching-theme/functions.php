<?php
defined( 'ABSPATH' ) || exit;

const ASTONA_VERSION = '0.1.0';

add_action(
	'after_setup_theme',
	static function () {
		add_theme_support( 'title-tag' );
		add_theme_support( 'post-thumbnails' );
		add_theme_support( 'html5', array( 'search-form', 'gallery', 'caption', 'style', 'script' ) );
		add_image_size( 'astona-card', 640, 400, true );
		register_nav_menus( array( 'primary' => 'Primary menu' ) );
	}
);

/** GA4 measurement ID (constant or env var); empty when unset or malformed, which disables analytics entirely. */
function astona_ga4_id(): string {
	$id = defined( 'GA4_MEASUREMENT_ID' ) ? (string) GA4_MEASUREMENT_ID : (string) getenv( 'GA4_MEASUREMENT_ID' );
	return 1 === preg_match( '/^G-[A-Z0-9]{4,14}$/', $id ) ? $id : '';
}

/** Public Turnstile site key (constant or env var); empty disables the widget. */
function astona_turnstile_site_key(): string {
	$key = defined( 'TURNSTILE_SITE_KEY' ) ? (string) TURNSTILE_SITE_KEY : (string) getenv( 'TURNSTILE_SITE_KEY' );
	return (string) apply_filters( 'astona_turnstile_site_key', $key );
}

// Turnstile asks for async defer; core's strategy argument takes only one of them.
add_filter(
	'script_loader_tag',
	static fn( $tag, $handle ) => 'cf-turnstile' === $handle ? str_replace( ' src=', ' defer src=', (string) $tag ) : $tag,
	10,
	2
);

add_action(
	'wp_enqueue_scripts',
	static function () {
		wp_enqueue_style( 'astona-main', get_theme_file_uri( 'assets/css/main.css' ), array(), ASTONA_VERSION );
		wp_enqueue_script( 'astona-main', get_theme_file_uri( 'assets/js/main.js' ), array(), ASTONA_VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );
		wp_localize_script( 'astona-main', 'ASTONA', array( 'coursesUrl' => esc_url_raw( rest_url( 'cc/v1/courses' ) ), 'ga4' => astona_ga4_id() ) );
		if ( is_page( 'contact' ) ) {
			wp_enqueue_script( 'astona-contact', get_theme_file_uri( 'assets/js/contact.js' ), array(), ASTONA_VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );
			wp_localize_script( 'astona-contact', 'ASTONA_CONTACT', array( 'restBase' => esc_url_raw( rest_url( 'cc/v1/' ) ) ) );
			if ( '' !== astona_turnstile_site_key() ) {
				wp_enqueue_script( 'cf-turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js', array(), null, array( 'in_footer' => true, 'strategy' => 'async' ) ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
			}
		}
		if ( is_page( 'admissions' ) ) {
			wp_enqueue_script( 'astona-admission', get_theme_file_uri( 'assets/js/admission.js' ), array(), ASTONA_VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );
			wp_localize_script( 'astona-admission', 'ASTONA_ADMISSION', array( 'restBase' => esc_url_raw( rest_url( 'cc/v1/' ) ), 'loginUrl' => home_url( '/student/login/' ) ) );
			if ( '' !== astona_turnstile_site_key() ) {
				// Explicit render: admission.js renders the widget and resets it, because every request spends the token.
				wp_enqueue_script( 'cf-turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit&onload=astonaTurnstileReady', array(), null, array( 'in_footer' => true, 'strategy' => 'async' ) ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
			}
		}
		if ( is_page( 'gallery' ) ) {
			wp_enqueue_script( 'astona-gallery', get_theme_file_uri( 'assets/js/gallery.js' ), array(), ASTONA_VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );
		}
		$portal_view = class_exists( 'CC_Portal_Router' ) ? CC_Portal_Router::current_view() : '';
		if ( '' !== $portal_view && 'login' !== $portal_view ) {
			wp_enqueue_script( 'astona-student-portal', get_theme_file_uri( 'assets/js/student-portal.js' ), array(), ASTONA_VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );
			wp_localize_script(
				'astona-student-portal',
				'ASTONA_PORTAL',
				array(
					'restBase'     => esc_url_raw( rest_url( 'cc/v1/' ) ),
					'nonce'        => wp_create_nonce( 'wp_rest' ),
					'loginUrl'     => home_url( '/student/login/' ),
					'dashboardUrl' => home_url( '/student/' ),
				)
			);
		}
		if ( in_array( $portal_view, array( 'dashboard', 'course' ), true ) ) {
			wp_enqueue_script( 'astona-student-live', get_theme_file_uri( 'assets/js/student-live.js' ), array( 'astona-student-portal' ), ASTONA_VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );
			wp_localize_script(
				'astona-student-live',
				'ASTONA_LIVE',
				array(
					'serverNow'   => time(),
					'leadSeconds' => 900,
					'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				)
			);
		}
		if ( 'login' === $portal_view ) {
			wp_enqueue_script( 'astona-student-login', get_theme_file_uri( 'assets/js/student-login.js' ), array(), ASTONA_VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );
			wp_localize_script( 'astona-student-login', 'ASTONA_STUDENT_LOGIN', array( 'restBase' => esc_url_raw( rest_url( 'cc/v1/' ) ) ) );
		}
	}
);

// Fonts are self-hosted (assets/fonts, @font-face in main.css): no third-party connection, no render-blocking CSS request.
// Preload only the Latin face; the Bengali face is fetched on demand via unicode-range.
add_action(
	'wp_head',
	static function () {
		printf(
			"<link rel=\"preload\" href=\"%s\" as=\"font\" type=\"font/woff2\" crossorigin>\n",
			esc_url( get_theme_file_uri( 'assets/fonts/inter-latin-var.woff2' ) )
		);
	},
	1
);

// The emoji detection script and styles are not used by this theme.
remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
remove_action( 'wp_print_styles', 'print_emoji_styles' );

function astona_plugin_ready(): bool {
	return class_exists( 'CC_Batch_Repository' );
}

function astona_money( $amount ): string {
	return '৳ ' . number_format( (float) $amount );
}

function astona_apply_url( int $batch_id = 0 ): string {
	$url = home_url( '/admissions/' );
	return $batch_id ? add_query_arg( 'batch', $batch_id, $url ) : $url;
}

function astona_chip( string $key, string $label ): string {
	return sprintf( '<span class="chip chip--%s">%s</span>', esc_attr( $key ), esc_html( $label ) );
}

function astona_contact(): array {
	return apply_filters(
		'astona_contact',
		array(
			'phone'   => '+880 1700-000000',
			'email'   => 'info@astona.example',
			'address' => 'Sample address, Dhaka, Bangladesh',
		)
	);
}

function astona_menu_fallback(): void {
	echo '<ul class="nav__list">';
	printf( '<li><a href="%s">Courses</a></li>', esc_url( get_post_type_archive_link( 'cc_course' ) ) );
	printf( '<li><a href="%s">Notices</a></li>', esc_url( get_post_type_archive_link( 'cc_notice' ) ) );
	printf( '<li><a href="%s">Faculty</a></li>', esc_url( get_post_type_archive_link( 'cc_faculty' ) ) );
	echo '</ul>';
}
