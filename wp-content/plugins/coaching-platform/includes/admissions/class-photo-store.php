<?php
defined( 'ABSPATH' ) || exit;

/**
 * Stores applicant photos outside the web root after re-encoding (which strips EXIF and any payload).
 */
final class CC_Photo_Store {

	const MAX_BYTES      = 2097152;
	const MAX_DIMENSION  = 4000;
	const JPEG_QUALITY   = 85;
	const MIME_EXTENSION = array(
		'image/jpeg' => 'jpg',
		'image/png'  => 'png',
	);

	/** Null when CC_PRIVATE_DIR is unset outside the local environment: never fall back into the web root. */
	public static function base_dir(): ?string {
		$configured = defined( 'CC_PRIVATE_DIR' ) ? (string) CC_PRIVATE_DIR : (string) getenv( 'CC_PRIVATE_DIR' );
		if ( '' !== $configured ) {
			return rtrim( $configured, '/' );
		}
		return 'local' === wp_get_environment_type() ? WP_CONTENT_DIR . '/../private' : null;
	}

	/**
	 * @param array $file One entry of $_FILES.
	 * @return string|WP_Error Path relative to the private dir.
	 */
	public static function store( array $file, string $ref ) {
		$tmp = (string) ( $file['tmp_name'] ?? '' );
		if ( UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) || '' === $tmp || ! is_uploaded_file( $tmp ) ) {
			return self::invalid( 'Upload a photo.' );
		}
		$size = (int) ( $file['size'] ?? 0 );
		if ( $size <= 0 || $size > self::MAX_BYTES ) {
			return self::invalid( 'Photo must be 2 MB or smaller.' );
		}

		return self::store_bytes( (string) file_get_contents( $tmp ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	/** @return string|WP_Error Path relative to the private dir. */
	public static function store_bytes( string $bytes ) {
		$base = self::base_dir();
		if ( null === $base ) {
			return new WP_Error( 'server_error', 'Photo storage is unavailable.', array( 'status' => 500 ) );
		}
		$mime = ( new finfo( FILEINFO_MIME_TYPE ) )->buffer( $bytes );
		if ( ! isset( self::MIME_EXTENSION[ $mime ] ) ) {
			return self::invalid( 'Photo must be a JPEG or PNG image.' );
		}
		$dims = getimagesizefromstring( $bytes );
		if ( ! $dims || $dims[0] > self::MAX_DIMENSION || $dims[1] > self::MAX_DIMENSION ) {
			return self::invalid( 'Photo dimensions are not supported.' );
		}
		$image = function_exists( 'imagecreatefromstring' ) ? @imagecreatefromstring( $bytes ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- GD warns on corrupt input.
		if ( ! $image ) {
			return self::invalid( 'Photo could not be read.' );
		}

		$dir = $base . '/photos';
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'server_error', 'Photo storage is unavailable.', array( 'status' => 500 ) );
		}

		$name = bin2hex( random_bytes( 16 ) ) . '.' . self::MIME_EXTENSION[ $mime ];
		$path = $dir . '/' . $name;
		$ok   = 'image/png' === $mime ? imagepng( $image, $path ) : imagejpeg( $image, $path, self::JPEG_QUALITY );
		imagedestroy( $image );
		if ( ! $ok ) {
			return new WP_Error( 'server_error', 'Photo storage is unavailable.', array( 'status' => 500 ) );
		}
		chmod( $path, 0640 );
		return 'photos/' . $name;
	}

	public static function delete( string $relative_path ): void {
		if ( preg_match( '#^photos/[a-f0-9]{32}\.(jpg|png)$#', $relative_path ) ) {
			$base = self::base_dir();
			if ( null !== $base ) {
				wp_delete_file( $base . '/' . $relative_path );
			}
		}
	}

	private static function invalid( string $message ): WP_Error {
		return new WP_Error( 'validation_failed', $message, array( 'status' => 422, 'field' => 'photo' ) );
	}
}
