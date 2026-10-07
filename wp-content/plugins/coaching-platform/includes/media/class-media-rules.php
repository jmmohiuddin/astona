<?php
defined( 'ABSPATH' ) || exit;

/**
 * Server-side upload rules for the Astona media library: a strict allowlist (jpeg, png, webp, gif, pdf), finfo sniffing
 * against the extension, size limits, re-encoding of images through WP_Image_Editor (drops EXIF and any appended
 * payload), a pixel cap checked before decoding, WebP siblings and an execution-denying .htaccess in the uploads dir. The pure helpers need no WordPress so they can be unit tested. Client-supplied MIME types
 * are never used.
 */
final class CC_Media_Rules {

	const MAX_IMAGE_BYTES = 5242880;
	const MAX_PDF_BYTES   = 10485760;
	const MAX_ALT_LENGTH  = 250;
	const WEBP_META       = '_cc_webp';
	const EXIF_SCAN_BYTES = 262144;
	const MAX_PIXELS      = 40000000;
	const MAX_SIDE        = 8000;
	const HTACCESS        = "<FilesMatch \"\\.(php|phtml|phar|php[0-9])$\">\n Require all denied\n</FilesMatch>\nOptions -Indexes\n";

	const TYPES = array(
		'jpg'  => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'png'  => 'image/png',
		'webp' => 'image/webp',
		'gif'  => 'image/gif',
		'pdf'  => 'application/pdf',
	);

	/** Rejected anywhere in a filename, so "shell.php.jpg" fails even though the last extension is allowed. */
	const BLOCKED_EXTENSIONS = array(
		'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'pht', 'phps', 'phar', 'html', 'htm', 'xhtml', 'shtml',
		'svg', 'svgz', 'xml', 'js', 'mjs', 'exe', 'dll', 'msi', 'bat', 'cmd', 'com', 'sh', 'jar', 'asp', 'aspx', 'jsp',
		'cgi', 'pl', 'py', 'swf', 'htaccess', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
	);

	public static function init(): void {
		add_filter( 'upload_mimes', array( __CLASS__, 'filter_upload_mimes' ), 99 );
		add_filter( 'wp_handle_upload_prefilter', array( __CLASS__, 'filter_prefilter' ), 1 );
		add_filter( 'wp_handle_upload', array( __CLASS__, 'filter_handle_upload' ), 1 );
		add_action( 'admin_init', array( __CLASS__, 'ensure_uploads_htaccess' ) );
		add_filter( 'wp_generate_attachment_metadata', array( __CLASS__, 'generate_webp_siblings' ), 20, 2 );
		add_action( 'delete_attachment', array( __CLASS__, 'delete_webp_siblings' ) );
	}

	/* ---------------------------------------------------------------- pure helpers */

	/** @return array{ok:bool,code:string,message:string,ext:?string,mime:?string} ext is canonical (jpeg becomes jpg). */
	public static function decide( string $filename, string $sniffed_mime, int $size ): array {
		if ( '' !== $filename && preg_match( '/[\x00-\x1f]/', $filename ) ) {
			return self::refuse( 'type', 'This file type is not allowed.' );
		}
		$segments = explode( '.', strtolower( basename( str_replace( '\\', '/', $filename ) ) ) );
		$ext      = count( $segments ) > 1 ? (string) end( $segments ) : '';
		if ( ! isset( self::TYPES[ $ext ] ) ) {
			return self::refuse( 'type', 'Only JPEG, PNG, WebP, GIF and PDF files are allowed.' );
		}
		if ( array() !== array_intersect( array_slice( $segments, 1, -1 ), self::BLOCKED_EXTENSIONS ) ) {
			return self::refuse( 'double_ext', 'Rename the file: it has a blocked extension in its name.' );
		}
		if ( ! in_array( $sniffed_mime, self::TYPES, true ) ) {
			return self::refuse( 'type', 'Only JPEG, PNG, WebP, GIF and PDF files are allowed.' );
		}
		if ( self::TYPES[ $ext ] !== $sniffed_mime ) {
			return self::refuse( 'mismatch', 'The file content does not match its extension.' );
		}
		if ( $size <= 0 ) {
			return self::refuse( 'empty', 'The file is empty.' );
		}
		if ( $size > self::max_bytes( $sniffed_mime ) ) {
			return self::refuse( 'size', sprintf( 'File too large (max %d MB).', self::max_bytes( $sniffed_mime ) / 1048576 ) );
		}
		return array( 'ok' => true, 'code' => 'ok', 'message' => '', 'ext' => 'jpeg' === $ext ? 'jpg' : $ext, 'mime' => $sniffed_mime );
	}

	public static function is_image_mime( string $mime ): bool {
		return 0 === strpos( $mime, 'image/' ) && in_array( $mime, self::TYPES, true );
	}

	public static function max_bytes( string $mime ): int {
		return 'application/pdf' === $mime ? self::MAX_PDF_BYTES : self::MAX_IMAGE_BYTES;
	}

	/** An opening PHP tag anywhere in an image or PDF: refuse rather than trust the re-encode alone. */
	public static function contains_php( string $bytes ): bool {
		return false !== stripos( $bytes, '<?php' );
	}

	public static function exceeds_pixel_cap( int $width, int $height ): bool {
		return $width > self::MAX_SIDE || $height > self::MAX_SIDE || $width * $height > self::MAX_PIXELS;
	}

	public static function has_exif( string $bytes ): bool {
		return false !== strpos( substr( $bytes, 0, self::EXIF_SCAN_BYTES ), "Exif\0\0" );
	}

	public static function random_filename( string $ext ): string {
		return 'astona-' . bin2hex( random_bytes( 8 ) ) . '.' . $ext;
	}

	/** @return array{ok:bool,code:string,message:string,ext:?string,mime:?string} */
	private static function refuse( string $code, string $message ): array {
		return array( 'ok' => false, 'code' => $code, 'message' => $message, 'ext' => null, 'mime' => null );
	}

	/* ---------------------------------------------------------------- WordPress integration */

	public static function sniff( string $bytes ): string {
		return (string) ( new finfo( FILEINFO_MIME_TYPE ) )->buffer( $bytes );
	}

	/** @return string[] extension => mime accepted by core for users who manage media. */
	public static function filter_upload_mimes( $mimes ) {
		if ( ! current_user_can( CC_Admin_Roles::CAP_MANAGE_MEDIA ) ) {
			return $mimes;
		}
		$allowed = array();
		foreach ( self::TYPES as $ext => $mime ) {
			$allowed[ 'jpg' === $ext ? 'jpg|jpeg' : $ext ] = $mime;
		}
		return $allowed;
	}

	/** Defence in depth for any core upload path used by a media manager. */
	public static function filter_prefilter( $file ) {
		if ( ! is_array( $file ) || ! current_user_can( CC_Admin_Roles::CAP_MANAGE_MEDIA ) ) {
			return $file;
		}
		$tmp   = (string) ( $file['tmp_name'] ?? '' );
		$bytes = '' !== $tmp && is_readable( $tmp ) ? (string) file_get_contents( $tmp ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$check = self::decide( (string) ( $file['name'] ?? '' ), self::sniff( $bytes ), strlen( $bytes ) );
		if ( ! $check['ok'] || self::contains_php( $bytes ) ) {
			$file['error'] = $check['ok'] ? 'The file was rejected.' : $check['message'];
		}
		return $file;
	}

	/**
	 * Re-encodes an image through WP_Image_Editor (GD is preferred because it always drops metadata) and verifies that
	 * no EXIF survived.
	 *
	 * @return string|WP_Error The clean image bytes.
	 */
	public static function reencode( string $bytes, string $ext, string $mime ) {
		$size = getimagesizefromstring( $bytes );
		if ( ! is_array( $size ) ) {
			return new WP_Error( 'cc_media_unreadable', 'The image could not be read.' );
		}
		if ( self::exceeds_pixel_cap( (int) $size[0], (int) $size[1] ) ) {
			return new WP_Error( 'cc_media_dimensions', 'The image is too large: at most 8000 pixels on a side and 40 megapixels in total.' );
		}
		$source = get_temp_dir() . 'cc-media-' . bin2hex( random_bytes( 8 ) ) . '.' . $ext;
		$target = $source . '.out.' . $ext;
		if ( false === file_put_contents( $source, $bytes ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			return new WP_Error( 'cc_media_failed', 'The image could not be processed.' );
		}
		$prefer_gd = static fn( $editors ) => array_values( array_unique( array_merge( array( 'WP_Image_Editor_GD' ), (array) $editors ) ) );
		add_filter( 'wp_image_editors', $prefer_gd, 99 );
		try {
			$editor = wp_get_image_editor( $source );
			if ( is_wp_error( $editor ) ) {
				return new WP_Error( 'cc_media_unreadable', 'The image could not be read.' );
			}
			$editor->maybe_exif_rotate();
			$saved = $editor->save( $target, $mime );
			if ( is_wp_error( $saved ) || ! is_readable( $target ) ) {
				return new WP_Error( 'cc_media_unreadable', 'The image could not be read.' );
			}
			$clean = (string) file_get_contents( $target ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		} finally {
			remove_filter( 'wp_image_editors', $prefer_gd, 99 );
			wp_delete_file( $source );
			wp_delete_file( $target );
		}
		if ( '' === $clean || self::has_exif( $clean ) || self::contains_php( $clean ) ) {
			return new WP_Error( 'cc_media_unreadable', 'The image could not be sanitised.' );
		}
		return $clean;
	}

	/**
	 * Core upload path (wp_handle_upload, any user including administrators): re-encodes the stored image in place so
	 * EXIF/GPS never survives. A file that cannot be re-encoded is deleted and the upload fails.
	 *
	 * @param mixed $upload Array with file, url, type, or error.
	 * @return mixed
	 */
	public static function filter_handle_upload( $upload ) {
		if ( ! is_array( $upload ) || ! empty( $upload['error'] ) || empty( $upload['file'] ) ) {
			return $upload;
		}
		self::ensure_uploads_htaccess();
		$file  = (string) $upload['file'];
		$bytes = is_readable( $file ) ? (string) file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$mime  = self::sniff( $bytes );
		if ( '' === $bytes || ! self::is_image_mime( $mime ) ) {
			return $upload;
		}
		$ext   = (string) array_search( $mime, self::TYPES, true );
		$clean = self::reencode( $bytes, 'jpeg' === $ext ? 'jpg' : $ext, $mime );
		if ( is_wp_error( $clean ) || false === file_put_contents( $file, $clean ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			wp_delete_file( $file );
			return array( 'error' => is_wp_error( $clean ) ? $clean->get_error_message() : 'The image could not be processed.' );
		}
		return $upload;
	}

	/** Creates the uploads .htaccess when missing; errors (read-only volume, non-Apache host) are ignored on purpose. */
	public static function ensure_uploads_htaccess(): void {
		$dir = (string) ( wp_upload_dir( null, false )['basedir'] ?? '' );
		if ( '' === $dir || ! is_dir( $dir ) ) {
			return;
		}
		$path = $dir . '/.htaccess';
		if ( ! file_exists( $path ) ) {
			@file_put_contents( $path, self::HTACCESS ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
	}

	public static function webp_supported(): bool {
		return wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) );
	}

	/**
	 * Writes <file>.webp (the full original name plus ".webp", so foo.jpg and foo.png never share a sibling) next to the
	 * original and each generated size. Existing files that this attachment did not create are never overwritten.
	 */
	public static function generate_webp_siblings( $metadata, $attachment_id ) {
		$path = (string) get_attached_file( (int) $attachment_id );
		$mime = '' !== $path ? (string) get_post_mime_type( (int) $attachment_id ) : '';
		if ( ! in_array( $mime, array( 'image/jpeg', 'image/png' ), true ) || ! is_array( $metadata ) || ! self::webp_supported() ) {
			return $metadata;
		}
		$owned   = array_map( 'strval', (array) get_post_meta( (int) $attachment_id, self::WEBP_META, true ) );
		$created = array();
		foreach ( self::sibling_names( $path, $metadata ) as $source_name ) {
			$source  = path_join( dirname( $path ), $source_name );
			$sibling = $source . '.webp';
			if ( file_exists( $sibling ) && ! in_array( basename( $sibling ), $owned, true ) ) {
				continue;
			}
			$editor = is_readable( $source ) ? wp_get_image_editor( $source ) : null;
			if ( $editor && ! is_wp_error( $editor ) && ! is_wp_error( $editor->save( $sibling, 'image/webp' ) ) ) {
				$created[] = basename( $sibling );
			}
		}
		update_post_meta( (int) $attachment_id, self::WEBP_META, $created );
		return $metadata;
	}

	/** Removes only the siblings recorded for this attachment that are exactly "<own file>.webp". */
	public static function delete_webp_siblings( $attachment_id ): void {
		$path     = (string) get_attached_file( (int) $attachment_id );
		$siblings = get_post_meta( (int) $attachment_id, self::WEBP_META, true );
		if ( '' === $path || ! is_array( $siblings ) ) {
			return;
		}
		$metadata = wp_get_attachment_metadata( (int) $attachment_id );
		$own      = array_map( static fn( $name ) => $name . '.webp', self::sibling_names( $path, is_array( $metadata ) ? $metadata : array() ) );
		foreach ( $siblings as $name ) {
			if ( in_array( basename( (string) $name ), $own, true ) ) {
				wp_delete_file( path_join( dirname( $path ), basename( (string) $name ) ) );
			}
		}
	}

	/** @return string[] Basenames of the original and every generated size. */
	private static function sibling_names( string $path, array $metadata ): array {
		$names = array( basename( $path ) );
		foreach ( (array) ( $metadata['sizes'] ?? array() ) as $size ) {
			if ( ! empty( $size['file'] ) ) {
				$names[] = basename( (string) $size['file'] );
			}
		}
		return array_values( array_unique( $names ) );
	}
}
