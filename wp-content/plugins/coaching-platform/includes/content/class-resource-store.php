<?php
defined( 'ABSPATH' ) || exit;

/**
 * Stores lesson PDFs outside the web root under CC_PRIVATE_DIR/resources/. Files are never served from here: the
 * gated student download re-validates through path() and streams the result.
 */
final class CC_Resource_Store {

	const MAX_BYTES    = 10485760;
	const MAX_NAME_LEN = 190;
	const SUBDIR       = 'resources';
	const PATH_PATTERN = '#^resources/[a-f0-9]{32}\.pdf$#';
	const PDF_MAGIC    = '%PDF-';

	/**
	 * @param array $file One entry of $_FILES.
	 * @return array{path:string,name:string}|WP_Error path is relative to the private dir; name is the sanitised original filename.
	 */
	public static function store( array $file ) {
		$tmp = (string) ( $file['tmp_name'] ?? '' );
		$error = (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE );
		if ( UPLOAD_ERR_OK !== $error ) {
			return self::invalid( self::upload_error_message( $error, self::max_upload_bytes() ) );
		}
		if ( '' === $tmp || ! is_uploaded_file( $tmp ) ) {
			return self::invalid( 'Upload a PDF file.' );
		}
		$size = (int) ( $file['size'] ?? 0 );
		if ( $size <= 0 || $size > self::MAX_BYTES ) {
			return self::invalid( 'The PDF must be 10 MB or smaller.' );
		}
		return self::store_bytes( (string) file_get_contents( $tmp ), (string) ( $file['name'] ?? '' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	/** The real upload limit: the 10 MB product rule, or less if the server allows less. */
	public static function max_upload_bytes(): int {
		return min( self::MAX_BYTES, (int) wp_max_upload_size() );
	}

	public static function format_limit( int $bytes ): string {
		return rtrim( rtrim( number_format( $bytes / 1048576, 1 ), '0' ), '.' ) . ' MB';
	}

	public static function upload_error_message( int $code, int $limit_bytes ): string {
		$limit = self::format_limit( $limit_bytes );
		switch ( $code ) {
			case UPLOAD_ERR_INI_SIZE:
			case UPLOAD_ERR_FORM_SIZE:
				return sprintf( 'File too large (max %s).', $limit );
			case UPLOAD_ERR_PARTIAL:
				return 'The upload was interrupted. Try again.';
			case UPLOAD_ERR_NO_TMP_DIR:
			case UPLOAD_ERR_CANT_WRITE:
			case UPLOAD_ERR_EXTENSION:
				return 'The server could not receive the file. Try again later.';
			default:
				return 'Upload a PDF file.';
		}
	}

	/** @return array{path:string,name:string}|WP_Error */
	public static function store_bytes( string $bytes, string $original_name ) {
		$base = CC_Photo_Store::base_dir();
		if ( null === $base ) {
			return self::unavailable();
		}
		$length = strlen( $bytes );
		if ( 0 === $length || $length > self::MAX_BYTES ) {
			return self::invalid( 'The PDF must be 10 MB or smaller.' );
		}
		if ( ! self::looks_like_pdf( $bytes, ( new finfo( FILEINFO_MIME_TYPE ) )->buffer( $bytes ) ) ) {
			return self::invalid( 'Only PDF files are allowed.' );
		}

		$dir = $base . '/' . self::SUBDIR;
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return self::unavailable();
		}
		$relative = self::SUBDIR . '/' . bin2hex( random_bytes( 16 ) ) . '.pdf';
		$target   = $base . '/' . $relative;
		if ( false === file_put_contents( $target, $bytes, LOCK_EX ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			return self::unavailable();
		}
		chmod( $target, 0640 );
		return array( 'path' => $relative, 'name' => self::display_name( $original_name ) );
	}

	/** Absolute path of a stored PDF, or null when the reference is malformed, escapes the directory or no longer holds a PDF. */
	public static function path( string $relative ): ?string {
		$base = CC_Photo_Store::base_dir();
		if ( null === $base || 1 !== preg_match( self::PATH_PATTERN, $relative ) ) {
			return null;
		}
		$root = realpath( $base . '/' . self::SUBDIR );
		$file = realpath( $base . '/' . $relative );
		if ( false === $root || false === $file || 0 !== strpos( $file, $root . DIRECTORY_SEPARATOR ) || ! is_file( $file ) ) {
			return null;
		}
		$head = file_get_contents( $file, false, null, 0, strlen( self::PDF_MAGIC ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( self::PDF_MAGIC !== $head || 'application/pdf' !== ( new finfo( FILEINFO_MIME_TYPE ) )->file( $file ) ) {
			return null;
		}
		return $file;
	}

	public static function delete( string $relative ): void {
		if ( 1 !== preg_match( self::PATH_PATTERN, $relative ) ) {
			return;
		}
		$base = CC_Photo_Store::base_dir();
		if ( null !== $base ) {
			wp_delete_file( $base . '/' . $relative );
		}
	}

	/** Header check plus finfo verdict: both must agree, so a renamed text or HTML file cannot slip through. */
	public static function looks_like_pdf( string $bytes, string $detected_mime ): bool {
		return 'application/pdf' === $detected_mime && 0 === strncmp( $bytes, self::PDF_MAGIC, strlen( self::PDF_MAGIC ) );
	}

	/** Safe download name: no path, no control characters, always ending in .pdf. */
	public static function display_name( string $original ): string {
		$stem = pathinfo( str_replace( '\\', '/', $original ), PATHINFO_FILENAME );
		$stem = trim( (string) preg_replace( '/[^A-Za-z0-9 ._()-]+/', '', $stem ), " .\t" );
		$stem = '' === $stem ? 'resource' : $stem;
		return mb_substr( $stem, 0, self::MAX_NAME_LEN - 4 ) . '.pdf';
	}

	private static function invalid( string $message ): WP_Error {
		return new WP_Error( 'validation_failed', $message, array( 'status' => 422, 'field' => 'attachment' ) );
	}

	private static function unavailable(): WP_Error {
		return new WP_Error( 'server_error', 'Resource storage is unavailable.', array( 'status' => 500 ) );
	}
}
