<?php
/**
 * Plugin Name: CC Hardening
 * Description: Baseline WordPress hardening for the Astona platform (SEC-010).
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'xmlrpc_enabled', '__return_false' );
add_filter( 'xmlrpc_methods', '__return_empty_array' );
add_filter( 'pings_open', '__return_false' );

// Uniform login error: do not reveal whether a username exists.
// The two-factor step (reached only after a correct password) keeps its own messages so staff can tell what to do.
add_filter(
	'login_errors',
	static fn( $message ): string => class_exists( 'CC_Staff_2fa' ) && CC_Staff_2fa::$show_message ? (string) $message : 'Invalid username or password.'
);

// Do not leak author names through oEmbed.
add_filter(
	'oembed_response_data',
	static fn( array $data ): array => array_diff_key( $data, array( 'author_name' => 1, 'author_url' => 1 ) )
);
add_filter( 'wp_is_application_passwords_available', '__return_false' );

// Remove generator/version leaks.
remove_action( 'wp_head', 'wp_generator' );
add_filter( 'the_generator', '__return_empty_string' );

// Block user enumeration: ?author=N and /wp/v2/users and /wp/v2/media for anonymous visitors.
add_action(
	'template_redirect',
	static function () {
		if ( is_author() || ( isset( $_GET['author'] ) && ! is_admin() ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			wp_safe_redirect( home_url( '/' ), 301 );
			exit;
		}
	},
	1 // Run before core's redirect_canonical (priority 10), which would expose /author/<login>/.
);

add_filter(
	'wp_sitemaps_add_provider',
	static fn( $provider, string $name ) => 'users' === $name ? false : $provider,
	10,
	2
);

add_filter(
	'rest_endpoints',
	static function ( array $endpoints ): array {
		if ( ! is_user_logged_in() ) {
			unset( $endpoints['/wp/v2/users'], $endpoints['/wp/v2/users/(?P<id>[\d]+)'] );
			// The media library is not a public listing: attachment ids are sequential and include private uploads.
			foreach ( array_keys( $endpoints ) as $route ) {
				if ( 0 === stripos( $route, '/wp/v2/media' ) ) {
					unset( $endpoints[ $route ] );
				}
			}
		}
		return $endpoints;
	}
);

/**
 * Staff roles (cc_owner, cc_staff, cc_instructor) cannot update WordPress, so core's "WordPress X is available!
 * Please notify the site administrator." and maintenance nags are noise to them and leak the core version.
 * Administrators (update_core) keep both nags. Runs on admin_init: core registers these callbacks while loading
 * wp-admin/includes/admin.php (admin-filters.php / ms-admin-filters.php), which happens before admin_init.
 */
function cc_hide_core_update_nags_for_non_updaters(): void {
	if ( current_user_can( 'update_core' ) ) {
		return;
	}
	foreach ( array( 'admin_notices', 'network_admin_notices' ) as $hook ) {
		remove_action( $hook, 'update_nag', 3 );
		remove_action( $hook, 'maintenance_nag', 10 );
	}
	// The footer's "Version X.Y.Z" is core_update_footer() on update_footer (priority 10): staff do not need the core version.
	remove_filter( 'update_footer', 'core_update_footer' );
	add_filter( 'update_footer', '__return_empty_string', 11 );
}
add_action( 'admin_init', 'cc_hide_core_update_nags_for_non_updaters' );

// Security headers for public pages.
add_action(
	'send_headers',
	static function () {
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Frame-Options: SAMEORIGIN' );
		header( 'Referrer-Policy: strict-origin-when-cross-origin' );
		header( 'Permissions-Policy: camera=(), microphone=(), geolocation=()' );
	}
);
