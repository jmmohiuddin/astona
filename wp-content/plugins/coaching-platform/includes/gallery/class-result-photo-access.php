<?php
defined( 'ABSPATH' ) || exit;

/**
 * The only way to read a result photo: /cc-result-photo/{result_id}/{expiry}/{signature}.{ext}.
 *
 * Ids and expiry must be canonical decimals (no leading zero), so each photo has exactly one URL form.
 * The signature is HMAC-SHA256 (wp_salt('auth')) over "result_id|expiry|variant" (variant "orig" or "webp"), compared in
 * constant time. A URL lives at most TTL seconds (10 minutes; a longer expiry is refused even if correctly signed). On
 * every request the result is looked up again and streamed only when it is currently published AND verified AND
 * consent-confirmed, or when the viewer holds cc_manage_gallery (managers may preview any state). Everything else (no such
 * result, not public, bad signature, expired, wrong extension, missing file, other HTTP method) gets one identical 404
 * (same status, headers and body), so nothing can be learned about a result from the answer. Revoking consent or
 * verification therefore ends access at once, including through URLs already handed out; the only lingering copy is a
 * browser's own cache, bounded by BROWSER_MAX_AGE (`Cache-Control: private, max-age=300`, never public or immutable).
 *
 * Cache trade-off (documented choice): the public /results/ page embeds freshly signed URLs at render time, so a copy of
 * that HTML older than TTL would show broken photos. The page therefore sends `Cache-Control: private, no-cache` whenever
 * it shows at least one photo (see page-results.php); a shared page cache or CDN must not store it. The alternative of a
 * long-lived listing signature was rejected: it would extend how long a revoked, already-issued URL is usable by the same
 * margin, which is the property this design exists to bound.
 */
final class CC_Result_Photo_Access {

	const QUERY_VAR        = 'cc_result_photo';
	const PATH_VAR         = 'cc_result_photo_path';
	const URL_PREFIX       = 'cc-result-photo';
	const TTL              = 600;
	const BROWSER_MAX_AGE  = 300;
	const VARIANT_ORIGINAL = 'orig';
	const VARIANT_WEBP     = 'webp';
	const NOT_FOUND_BODY   = "Not found\n";
	const REWRITE_OPTION   = 'cc_result_photo_rewrite';
	const REWRITE_SCHEMA   = '1';
	const REQUEST_PATTERN  = '#^([1-9]\d{0,11})/([1-9]\d{0,11})/([a-f0-9]{64})\.(jpg|png|webp)$#';

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'add_rewrite_rule' ) );
		add_action( 'init', array( __CLASS__, 'maybe_flush' ), 99 );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'serve' ), 0 );
	}

	public static function add_rewrite_rule(): void {
		add_rewrite_rule(
			'^' . self::URL_PREFIX . '(?:/(.*))?$',
			'index.php?' . self::QUERY_VAR . '=1&' . self::PATH_VAR . '=$matches[1]',
			'top'
		);
	}

	public static function maybe_flush(): void {
		if ( get_option( self::REWRITE_OPTION ) === self::REWRITE_SCHEMA ) {
			return;
		}
		flush_rewrite_rules( false );
		update_option( self::REWRITE_OPTION, self::REWRITE_SCHEMA, false );
	}

	/** @param array<int,string> $vars */
	public static function query_vars( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		$vars[] = self::PATH_VAR;
		return $vars;
	}

	/* ------------------------------------------------------------ signing */

	public static function sign( int $result_id, int $expiry, string $variant ): string {
		return hash_hmac( 'sha256', $result_id . '|' . $expiry . '|' . $variant, wp_salt( 'auth' ) );
	}

	/** Signed URL for the result's photo, or '' when it has none (or no WebP sibling for the webp variant). */
	public static function signed_url( int $result_id, string $variant = self::VARIANT_ORIGINAL, int $ttl = self::TTL ): string {
		$path = CC_Results::photo_path( $result_id );
		if ( '' === $path || ( self::VARIANT_WEBP === $variant && ! CC_Result_Photo_Store::has_webp_variant( $path ) ) ) {
			return '';
		}
		$expiry = time() + $ttl;
		$ext    = self::VARIANT_WEBP === $variant ? 'webp' : pathinfo( $path, PATHINFO_EXTENSION );
		return home_url( '/' . self::URL_PREFIX . '/' . $result_id . '/' . $expiry . '/' . self::sign( $result_id, $expiry, $variant ) . '.' . $ext );
	}

	/* ------------------------------------------------------------ authorisation */

	/**
	 * @return array{file:string,mime:string}|null The file to stream, or null for every kind of refusal.
	 */
	public static function resolve( int $result_id, int $expiry, string $signature, string $ext, ?int $now = null ): ?array {
		$now = $now ?? time();
		if ( $result_id <= 0 || $expiry < $now || $expiry > $now + self::TTL ) {
			return null;
		}
		$variant = self::matching_variant( $result_id, $expiry, $signature );
		if ( null === $variant ) {
			return null;
		}
		$path = CC_Results::photo_path( $result_id );
		if ( '' === $path || ! ( current_user_can( CC_Gallery::CAP ) || CC_Results::is_public( $result_id ) ) ) {
			return null;
		}
		$webp = self::VARIANT_WEBP === $variant;
		if ( $ext !== ( $webp ? 'webp' : pathinfo( $path, PATHINFO_EXTENSION ) ) ) {
			return null;
		}
		$file = CC_Result_Photo_Store::path( $path, $webp && ! str_ends_with( $path, '.webp' ) );
		$mime = null === $file ? '' : (string) ( new finfo( FILEINFO_MIME_TYPE ) )->file( $file );
		if ( null === $file || ! isset( CC_Result_Photo_Store::MIME_EXT[ $mime ] ) || $ext !== CC_Result_Photo_Store::MIME_EXT[ $mime ] ) {
			return null;
		}
		return array( 'file' => $file, 'mime' => $mime );
	}

	private static function matching_variant( int $result_id, int $expiry, string $signature ): ?string {
		$matched = null;
		foreach ( array( self::VARIANT_ORIGINAL, self::VARIANT_WEBP ) as $variant ) {
			if ( hash_equals( self::sign( $result_id, $expiry, $variant ), $signature ) ) {
				$matched = $variant;
			}
		}
		return $matched;
	}

	/* ------------------------------------------------------------ route */

	public static function serve(): void {
		if ( '' === (string) get_query_var( self::QUERY_VAR ) ) {
			return;
		}
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'GET';
		$parts  = array();
		if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) || 1 !== preg_match( self::REQUEST_PATTERN, (string) get_query_var( self::PATH_VAR ), $parts ) ) {
			self::not_found();
		}
		$found = self::resolve( (int) $parts[1], (int) $parts[2], $parts[3], $parts[4] );
		if ( null === $found ) {
			self::not_found();
		}
		self::stream( $found['file'], $found['mime'], 'HEAD' === $method );
	}

	private static function stream( string $file, string $mime, bool $head_only ): never {
		$size = (int) filesize( $file );
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		foreach ( array( 'Expires', 'Pragma', 'Last-Modified' ) as $header ) {
			header_remove( $header );
		}
		status_header( 200 );
		header( 'Content-Type: ' . $mime );
		header( 'Content-Length: ' . $size );
		header( 'Content-Disposition: inline; filename="photo.' . CC_Result_Photo_Store::MIME_EXT[ $mime ] . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Cache-Control: private, max-age=' . self::BROWSER_MAX_AGE );
		header( 'X-Robots-Tag: noindex, noarchive' );
		if ( ! $head_only ) {
			readfile( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- path verified by CC_Result_Photo_Store::path().
		}
		exit;
	}

	private static function not_found(): never {
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		foreach ( array( 'Expires', 'Pragma', 'Last-Modified' ) as $header ) {
			header_remove( $header );
		}
		status_header( 404 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Cache-Control: no-store' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: noindex' );
		echo self::NOT_FOUND_BODY; // phpcs:ignore WordPress.Security.EscapeOutput -- constant.
		exit;
	}
}
