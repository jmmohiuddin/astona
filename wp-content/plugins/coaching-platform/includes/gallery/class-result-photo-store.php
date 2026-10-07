<?php
defined( 'ABSPATH' ) || exit;

/**
 * Student result photos live ONLY under CC_PRIVATE_DIR/result-photos/, never in the public uploads directory, so no web
 * server URL exists for them. Reuses CC_Photo_Store::base_dir() (fails closed when CC_PRIVATE_DIR is unset outside the
 * local environment) and CC_Media_Rules (type sniffing, pixel cap, re-encode that strips EXIF and appended payloads).
 * Files get random 32-hex names; for JPEG and PNG a "<name>.webp" sibling is written next to the original. They are
 * only ever read through CC_Result_Photo_Access, which re-checks the result's public state on every request.
 */
final class CC_Result_Photo_Store {

	const SUBDIR       = 'result-photos';
	const MAX_BYTES    = 5242880;
	const PATH_PATTERN = '#^result-photos/[a-f0-9]{32}\.(jpg|png|webp)$#';
	const MIME_EXT     = array(
		'image/jpeg' => 'jpg',
		'image/png'  => 'png',
		'image/webp' => 'webp',
	);

	/**
	 * @param array $file One entry of $_FILES.
	 * @return string|WP_Error Path relative to the private dir.
	 */
	public static function store( array $file ) {
		$tmp   = (string) ( $file['tmp_name'] ?? '' );
		$error = (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE );
		if ( UPLOAD_ERR_OK !== $error ) {
			return self::invalid( CC_Resource_Store::upload_error_message( $error, min( self::MAX_BYTES, (int) wp_max_upload_size() ) ) );
		}
		if ( '' === $tmp || ! is_uploaded_file( $tmp ) ) {
			return self::invalid( 'Choose a photo to upload.' );
		}
		return self::store_bytes( (string) file_get_contents( $tmp ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	/** @return string|WP_Error Path relative to the private dir. */
	public static function store_bytes( string $bytes ) {
		$base = CC_Photo_Store::base_dir();
		if ( null === $base ) {
			return self::unavailable();
		}
		$length = strlen( $bytes );
		if ( 0 === $length || $length > self::MAX_BYTES ) {
			return self::invalid( 'The photo must be 5 MB or smaller.' );
		}
		$mime = CC_Media_Rules::sniff( $bytes );
		if ( ! isset( self::MIME_EXT[ $mime ] ) || CC_Media_Rules::contains_php( $bytes ) ) {
			return self::invalid( 'The photo must be a JPEG, PNG or WebP image.' );
		}
		$ext   = self::MIME_EXT[ $mime ];
		$clean = CC_Media_Rules::reencode( $bytes, $ext, $mime );
		if ( is_wp_error( $clean ) ) {
			return self::invalid( $clean->get_error_message() );
		}
		$dir = $base . '/' . self::SUBDIR;
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return self::unavailable();
		}
		$relative = self::SUBDIR . '/' . bin2hex( random_bytes( 16 ) ) . '.' . $ext;
		$target   = $base . '/' . $relative;
		if ( false === file_put_contents( $target, $clean, LOCK_EX ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			return self::unavailable();
		}
		chmod( $target, 0640 );
		self::write_webp_sibling( $target, $ext );
		return $relative;
	}

	/**
	 * Absolute path of a stored photo (or of its WebP sibling), or null when the reference is malformed, escapes the
	 * directory (realpath containment) or no longer holds an image.
	 */
	public static function path( string $relative, bool $webp_variant = false ): ?string {
		$base = CC_Photo_Store::base_dir();
		if ( null === $base || 1 !== preg_match( self::PATH_PATTERN, $relative ) ) {
			return null;
		}
		$root = realpath( $base . '/' . self::SUBDIR );
		$file = realpath( $base . '/' . $relative . ( $webp_variant ? '.webp' : '' ) );
		if ( false === $root || false === $file || 0 !== strpos( $file, $root . DIRECTORY_SEPARATOR ) || ! is_file( $file ) ) {
			return null;
		}
		return $file;
	}

	public static function has_webp_variant( string $relative ): bool {
		return ! str_ends_with( $relative, '.webp' ) && null !== self::path( $relative, true );
	}

	/** Removes the photo and its WebP sibling. Malformed references are ignored. */
	public static function delete( string $relative ): void {
		$base = CC_Photo_Store::base_dir();
		if ( null === $base || 1 !== preg_match( self::PATH_PATTERN, $relative ) ) {
			return;
		}
		wp_delete_file( $base . '/' . $relative );
		wp_delete_file( $base . '/' . $relative . '.webp' );
	}

	private static function write_webp_sibling( string $target, string $ext ): void {
		if ( 'webp' === $ext || ! CC_Media_Rules::webp_supported() ) {
			return;
		}
		$editor = wp_get_image_editor( $target );
		if ( ! is_wp_error( $editor ) && ! is_wp_error( $editor->save( $target . '.webp', 'image/webp' ) ) ) {
			chmod( $target . '.webp', 0640 );
		}
	}

	private static function invalid( string $message ): WP_Error {
		return new WP_Error( 'validation_failed', $message, array( 'status' => 422, 'field' => 'photo' ) );
	}

	private static function unavailable(): WP_Error {
		return new WP_Error( 'server_error', 'Photo storage is unavailable.', array( 'status' => 500 ) );
	}
}
