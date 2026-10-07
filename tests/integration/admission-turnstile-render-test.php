<?php
/**
 * Admission page Turnstile rendering: the widget container and the explicit-render api.js tag appear only when a site key exists.
 *   docker compose run --rm -T wpcli eval-file /tests/integration/admission-turnstile-render-test.php key
 *   docker compose run --rm -T wpcli eval-file /tests/integration/admission-turnstile-render-test.php nokey
 * The template declares helper functions, so it can be included once per process: one mode per run.
 * The key is injected through the astona_turnstile_site_key filter, so no environment change is needed.
 */
$checks   = 0;
$failures = 0;
$assert   = static function ( bool $cond, string $label ) use ( &$checks, &$failures ): void {
	++$checks;
	echo ( $cond ? '  ok   ' : '  FAIL ' ) . $label . "\n";
	if ( ! $cond ) {
		++$failures;
	}
};

$page = get_page_by_path( 'admissions' );
if ( ! $page || ! function_exists( 'astona_turnstile_site_key' ) ) {
	echo "SKIP: admissions page or theme helper missing.\n";
	exit( 1 );
}

$batch_id = (int) $GLOBALS['wpdb']->get_var( "SELECT id FROM {$GLOBALS['wpdb']->prefix}cc_batches WHERE application_open = 1 AND status <> 'draft' ORDER BY id DESC LIMIT 1" );
if ( ! $batch_id ) {
	echo "SKIP: no open batch to render the form.\n";
	exit( 1 );
}

$render_with   = static function ( string $key ) use ( $page, $batch_id ): array {
	global $wp_scripts, $wp_query;
	$filter = static fn() => $key;
	add_filter( 'astona_turnstile_site_key', $filter );
	$wp_scripts              = null;
	$wp_query                = new WP_Query( array( 'page_id' => $page->ID ) );
	$GLOBALS['wp_the_query'] = $wp_query;
	$GLOBALS['post']         = $page;
	$_GET['batch']           = (string) $batch_id;
	do_action( 'wp_enqueue_scripts' );
	ob_start();
	wp_print_scripts();
	$scripts = (string) ob_get_clean();
	ob_start();
	include get_theme_file_path( 'page-admissions.php' );
	$html = (string) ob_get_clean();
	remove_filter( 'astona_turnstile_site_key', $filter );
	return array( $html, $scripts );
};

$mode = $args[0] ?? 'key';
if ( 'key' === $mode ) {
	list( $html, $scripts ) = $render_with( 'SITEKEY123' );
	$assert( false !== strpos( $html, 'id="adm-turnstile"' ) && false !== strpos( $html, 'data-sitekey="SITEKEY123"' ), 'with a site key the page has the widget container carrying the key' );
	$assert( false !== strpos( $html, 'data-response-field-name="cf-turnstile-response"' ), 'the widget uses the cf-turnstile-response field name' );
	$assert( 1 === preg_match( '#<script[^>]+defer[^>]+src=[\'"]https://challenges\.cloudflare\.com/turnstile/v0/api\.js\?render=explicit&(?:\#038;|amp;)?onload=astonaTurnstileReady#', $scripts ), 'with a site key the api.js script tag is present (explicit render, defer)' );
} else {
	list( $html, $scripts ) = $render_with( '' );
	$assert( false !== strpos( $html, 'id="adm-form"' ), 'the form renders' );
	$assert( false === strpos( $html, 'adm-turnstile' ), 'without a site key the page has no widget container' );
	$assert( false === strpos( $scripts, 'challenges.cloudflare.com' ), 'without a site key the Turnstile script is not loaded' );
}

echo "\n$checks checks, $failures failures\n";
exit( $failures ? 1 : 0 );
