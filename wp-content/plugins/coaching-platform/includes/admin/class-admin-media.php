<?php
defined( 'ABSPATH' ) || exit;

/**
 * Admin screen "Media": upload with mandatory alt text, thumbnail grid, alt editing and guarded delete. The cc_* roles
 * lack the core upload_files capability, so this is the only upload path for them. Every handler checks the capability
 * and the nonce; the public static operations re-check the capability for the given actor. Audit entries record the
 * type and size, never file contents.
 */
final class CC_Admin_Media {

	const CAP           = 'cc_manage_media';
	const PAGE_SLUG     = 'cc-media';
	const ACTION_UPLOAD = 'cc_media_upload';
	const ACTION_ALT    = 'cc_media_alt';
	const ACTION_DELETE = 'cc_media_delete';
	const PER_PAGE      = 24;
	const ERROR_TTL     = 60;
	const WEBP_NOTICE   = 'cc_media_webp_notice_seen';

	/** Post types whose post_content is scanned for embedded images (wp-image-{id} class or the file URL). */
	const CONTENT_POST_TYPES = array( 'post', 'cc_article', 'page' );

	/** Meta keys that hold an attachment id: featured image, gallery item image. Filterable for new modules. Result photos are private files, not attachments. */
	const REFERENCE_META_KEYS = array( '_thumbnail_id', 'cc_gallery_image' );

	const NOTICES = array(
		'uploaded' => array( 'success', 'File uploaded.' ),
		'saved'    => array( 'success', 'Alt text saved.' ),
		'deleted'  => array( 'success', 'File deleted.' ),
		'failed'   => array( 'error', 'The action could not be completed.' ),
	);

	public static function init(): void {
		add_action( 'admin_post_' . self::ACTION_UPLOAD, array( __CLASS__, 'handle_upload' ) );
		add_action( 'admin_post_' . self::ACTION_ALT, array( __CLASS__, 'handle_alt' ) );
		add_action( 'admin_post_' . self::ACTION_DELETE, array( __CLASS__, 'handle_delete' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	public static function pick_url(): string {
		return admin_url( 'admin.php?page=' . self::PAGE_SLUG );
	}

	/* ---------------------------------------------------------------- testable operations */

	/**
	 * @param array $file One entry of $_FILES.
	 * @return int|WP_Error The attachment id.
	 */
	public static function upload( array $file, string $alt, int $actor ) {
		$error = (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE );
		$tmp   = (string) ( $file['tmp_name'] ?? '' );
		if ( UPLOAD_ERR_OK !== $error ) {
			return new WP_Error( 'cc_media_invalid', CC_Resource_Store::upload_error_message( $error, min( CC_Media_Rules::MAX_PDF_BYTES, (int) wp_max_upload_size() ) ) );
		}
		if ( '' === $tmp || ! is_uploaded_file( $tmp ) ) {
			return new WP_Error( 'cc_media_invalid', 'Choose a file to upload.' );
		}
		return self::upload_bytes( (string) file_get_contents( $tmp ), (string) ( $file['name'] ?? '' ), $alt, $actor ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	/** @return int|WP_Error The attachment id. */
	public static function upload_bytes( string $bytes, string $original_name, string $alt, int $actor ) {
		if ( ! user_can( $actor, self::CAP ) ) {
			return new WP_Error( 'cc_forbidden', 'You are not allowed to manage media.' );
		}
		$check = CC_Media_Rules::decide( $original_name, CC_Media_Rules::sniff( $bytes ), strlen( $bytes ) );
		if ( ! $check['ok'] ) {
			return new WP_Error( 'cc_media_' . $check['code'], $check['message'] );
		}
		if ( CC_Media_Rules::contains_php( $bytes ) ) {
			return new WP_Error( 'cc_media_invalid', 'The file was rejected.' );
		}
		$is_image = CC_Media_Rules::is_image_mime( (string) $check['mime'] );
		$alt      = mb_substr( sanitize_text_field( $alt ), 0, CC_Media_Rules::MAX_ALT_LENGTH );
		if ( $is_image && '' === $alt ) {
			return new WP_Error( 'cc_media_alt_required', 'Alt text is required for images.' );
		}
		if ( $is_image ) {
			$bytes = CC_Media_Rules::reencode( $bytes, (string) $check['ext'], (string) $check['mime'] );
			if ( is_wp_error( $bytes ) ) {
				return $bytes;
			}
		}
		return self::store( $bytes, $check, $alt, $actor );
	}

	/** @return true|WP_Error */
	public static function update_alt( int $id, string $alt, int $actor ) {
		if ( ! user_can( $actor, self::CAP ) ) {
			return new WP_Error( 'cc_forbidden', 'You are not allowed to manage media.' );
		}
		$mime = self::managed_mime( $id );
		if ( null === $mime ) {
			return new WP_Error( 'cc_media_not_found', 'File not found.' );
		}
		$alt = mb_substr( sanitize_text_field( $alt ), 0, CC_Media_Rules::MAX_ALT_LENGTH );
		if ( CC_Media_Rules::is_image_mime( $mime ) ) {
			if ( '' === $alt ) {
				return new WP_Error( 'cc_media_alt_required', 'Alt text is required for images.' );
			}
			update_post_meta( $id, '_wp_attachment_image_alt', $alt );
			CC_Audit::log( 'media.alt_update', 'attachment', $id );
		}
		return true;
	}

	/**
	 * Refuses while the file is referenced by a featured image or gallery item, or embedded in the content of a
	 * post, article or page of any status (trash included), unless an owner forces it.
	 *
	 * @return true|WP_Error
	 */
	public static function remove( int $id, int $actor, bool $force = false ) {
		if ( ! user_can( $actor, self::CAP ) ) {
			return new WP_Error( 'cc_forbidden', 'You are not allowed to manage media.' );
		}
		if ( null === self::managed_mime( $id ) ) {
			return new WP_Error( 'cc_media_not_found', 'File not found.' );
		}
		$used_by = self::usages( $id );
		$forced  = array() !== $used_by && $force && user_can( $actor, CC_Admin_Roles::CAP_MANAGE_STAFF );
		if ( array() !== $used_by && ! $forced ) {
			return new WP_Error( 'cc_media_in_use', sprintf( 'This file is used by %d item(s) and cannot be deleted.', count( $used_by ) ) );
		}
		if ( false === wp_delete_attachment( $id, true ) ) {
			return new WP_Error( 'cc_media_failed', 'The file could not be deleted.' );
		}
		CC_Audit::log( 'media.delete', 'attachment', $id, array( 'used_by' => count( $used_by ) ), $forced ? 'forced' : '' );
		return true;
	}

	/** @return array<int,array{post_id:int,title:string,post_type:string}> Posts (any status except auto-draft, trash included) that reference the attachment by meta field or embed it in post_content. */
	public static function usages( int $id ): array {
		$out = array();
		foreach ( array_merge( self::meta_usages( $id ), self::content_usages( $id ) ) as $row ) {
			$out[ $row['post_id'] ] = $row;
		}
		return array_values( $out );
	}

	/** @return array<int,array{post_id:int,title:string,post_type:string}> */
	private static function meta_usages( int $id ): array {
		global $wpdb;
		$keys = array_values( array_unique( array_filter( array_map( 'strval', (array) apply_filters( 'cc_media_reference_meta_keys', self::REFERENCE_META_KEYS ) ) ) ) );
		if ( array() === $keys ) {
			return array();
		}
		$placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		$sql          = "SELECT DISTINCT p.ID, p.post_title, p.post_type FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key IN ($placeholders) AND pm.meta_value = %s AND p.post_status <> 'auto-draft' AND p.ID <> %d";
		$rows         = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $keys, array( (string) $id, $id ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders built from the key count.
		return self::usage_rows( $rows );
	}

	/** @return array<int,array{post_id:int,title:string,post_type:string}> */
	private static function content_usages( int $id ): array {
		global $wpdb;
		$url          = (string) wp_get_attachment_url( $id );
		$url_base     = '' === $url ? '' : (string) preg_replace( '/\.[^.\/]+$/', '', (string) preg_replace( '#^https?:#', '', $url ) );
		$url_ext      = '' === $url ? '' : (string) pathinfo( $url, PATHINFO_EXTENSION );
		$class_match  = '/wp-image-' . $id . '(?!\d)/';
		$url_match    = '' === $url_base ? '' : '#' . preg_quote( $url_base, '#' ) . '(?:-scaled)?(?:-\d+x\d+)?\.' . preg_quote( $url_ext, '#' ) . '#';
		$likes        = array( '%' . $wpdb->esc_like( 'wp-image-' . $id ) . '%' );
		$conditions   = 'p.post_content LIKE %s';
		if ( '' !== $url_base ) {
			$likes[]    = '%' . $wpdb->esc_like( $url_base ) . '%';
			$conditions .= ' OR p.post_content LIKE %s';
		}
		$types        = self::CONTENT_POST_TYPES;
		$placeholders = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$sql          = "SELECT p.ID, p.post_title, p.post_type, p.post_content FROM {$wpdb->posts} p WHERE p.post_type IN ($placeholders) AND p.post_status <> 'auto-draft' AND p.ID <> %d AND ($conditions)";
		$rows         = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $types, array( $id ), $likes ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders built from fixed counts.
		$hits         = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$content = (string) $row['post_content'];
			if ( 1 === preg_match( $class_match, $content ) || ( '' !== $url_match && 1 === preg_match( $url_match, $content ) ) ) {
				$hits[] = $row;
			}
		}
		return self::usage_rows( $hits );
	}

	/** @param mixed $rows */
	private static function usage_rows( $rows ): array {
		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[] = array( 'post_id' => (int) $row['ID'], 'title' => (string) $row['post_title'], 'post_type' => (string) $row['post_type'] );
		}
		return $out;
	}

	/* ---------------------------------------------------------------- handlers */

	public static function handle_upload(): void {
		self::guard( self::ACTION_UPLOAD );
		$alt    = isset( $_POST['alt'] ) ? (string) wp_unslash( $_POST['alt'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified in guard(); sanitised in upload_bytes().
		$result = self::upload( isset( $_FILES['file'] ) && is_array( $_FILES['file'] ) ? $_FILES['file'] : array(), $alt, get_current_user_id() ); // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified in guard(); validated by CC_Media_Rules.
		if ( ! empty( $_POST['ajax'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- verified in guard().
			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ), 422 );
			}
			wp_send_json_success( array( 'id' => $result ) );
		}
		if ( is_wp_error( $result ) ) {
			set_transient( self::error_key(), $result->get_error_message(), self::ERROR_TTL );
		}
		self::redirect( is_wp_error( $result ) ? 'failed' : 'uploaded' );
	}

	public static function handle_alt(): void {
		self::guard( self::ACTION_ALT );
		$alt    = isset( $_POST['alt'] ) ? (string) wp_unslash( $_POST['alt'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified in guard(); sanitised in update_alt().
		$result = self::update_alt( isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0, $alt, get_current_user_id() ); // phpcs:ignore WordPress.Security.NonceVerification -- verified in guard().
		self::finish( $result, 'saved' );
	}

	public static function handle_delete(): void {
		self::guard( self::ACTION_DELETE );
		$result = self::remove( isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0, get_current_user_id(), ! empty( $_POST['force'] ) ); // phpcs:ignore WordPress.Security.NonceVerification -- verified in guard().
		self::finish( $result, 'deleted' );
	}

	/* ---------------------------------------------------------------- rendering */

	/** @param mixed $hook */
	public static function enqueue_assets( $hook ): void {
		if ( ! is_string( $hook ) || '_page_' . self::PAGE_SLUG !== substr( $hook, -strlen( '_page_' . self::PAGE_SLUG ) ) ) {
			return;
		}
		$main = dirname( __DIR__, 2 ) . '/coaching-platform.php';
		// Version by file mtime so browsers never keep serving a stale copy after an update.
		$assets = dirname( $main ) . '/assets/';
		wp_enqueue_style( 'cc-admin-media', plugins_url( 'assets/admin-media.css', $main ), array( 'cc-admin' ), CC_VERSION . '.' . (int) @filemtime( $assets . 'admin-media.css' ) );
		wp_enqueue_script( 'cc-admin-media', plugins_url( 'assets/admin-media.js', $main ), array(), CC_VERSION . '.' . (int) @filemtime( $assets . 'admin-media.js' ), true );
		wp_localize_script(
			'cc-admin-media',
			'ccMedia',
			array(
				'endpoint'   => admin_url( 'admin-post.php' ),
				'action'     => self::ACTION_UPLOAD,
				'nonce'      => wp_create_nonce( self::ACTION_UPLOAD ),
				'types'      => CC_Media_Rules::TYPES,
				'imageMax'   => CC_Media_Rules::MAX_IMAGE_BYTES,
				'pdfMax'     => CC_Media_Rules::MAX_PDF_BYTES,
				'altMax'     => CC_Media_Rules::MAX_ALT_LENGTH,
				'blocked'    => CC_Media_Rules::BLOCKED_EXTENSIONS,
			)
		);
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'coaching-platform' ), 403 );
		}
		$page  = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification -- display only.
		$query = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => array_values( array_unique( CC_Media_Rules::TYPES ) ),
				'posts_per_page' => self::PER_PAGE,
				'paged'          => $page,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => false,
			)
		);
		?>
		<div class="wrap">
			<h1>Media</h1>
			<?php self::render_notice(); ?>
			<?php self::render_webp_notice(); ?>
			<?php self::render_upload_zone(); ?>
			<h2>Library</h2>
			<?php if ( ! $query->have_posts() ) : ?>
				<p>No files yet.</p>
			<?php else : ?>
				<div class="cc-media-grid">
					<?php foreach ( $query->posts as $post ) : ?>
						<?php self::render_item( $post ); ?>
					<?php endforeach; ?>
				</div>
				<?php
				echo wp_kses_post(
					(string) paginate_links(
						array(
							'base'    => add_query_arg( 'paged', '%#%' ),
							'format'  => '',
							'current' => $page,
							'total'   => max( 1, (int) $query->max_num_pages ),
						)
					)
				);
				?>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_upload_zone(): void {
		?>
		<div class="cc-panel">
			<h2>Upload</h2>
			<p class="description">JPEG, PNG, WebP, GIF (max 5 MB) or PDF (max 10 MB). Every image needs alt text describing it.</p>
			<div id="cc-media-drop" class="cc-media-drop" tabindex="0" role="button" aria-label="Choose files to upload">Drop files here or click to choose</div>
			<input type="file" id="cc-media-input" multiple hidden accept=".jpg,.jpeg,.png,.webp,.gif,.pdf">
			<ul id="cc-media-queue" class="cc-media-queue"></ul>
			<p id="cc-media-status" role="status" aria-live="polite"></p>
			<button type="button" class="button button-primary" id="cc-media-start" disabled>Upload</button>
			<noscript>
				<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_UPLOAD ); ?>">
					<?php wp_nonce_field( self::ACTION_UPLOAD ); ?>
					<p><input type="file" name="file" required></p>
					<p><label>Alt text <input type="text" name="alt" class="regular-text" maxlength="<?php echo (int) CC_Media_Rules::MAX_ALT_LENGTH; ?>"></label></p>
					<p><button class="button button-primary">Upload</button></p>
				</form>
			</noscript>
		</div>
		<?php
	}

	private static function render_item( WP_Post $post ): void {
		$id       = (int) $post->ID;
		$is_image = CC_Media_Rules::is_image_mime( (string) $post->post_mime_type );
		$webp     = get_post_meta( $id, CC_Media_Rules::WEBP_META, true );
		$used_by  = self::usages( $id );
		?>
		<div class="cc-media-item cc-panel">
			<div class="cc-media-thumb">
				<?php if ( $is_image ) : ?>
					<?php echo wp_get_attachment_image( $id, 'thumbnail' ); ?>
				<?php else : ?>
					<span class="cc-chip">PDF</span>
				<?php endif; ?>
			</div>
			<p class="cc-media-name"><?php echo esc_html( wp_basename( (string) get_attached_file( $id ) ) ); ?>
				<?php if ( is_array( $webp ) && array() !== $webp ) : ?>
					<span class="cc-chip cc-chip--ok">WebP</span>
				<?php endif; ?>
				<?php if ( array() !== $used_by ) : ?>
					<span class="cc-chip cc-chip--warn">In use (<?php echo (int) count( $used_by ); ?>)</span>
				<?php endif; ?>
			</p>
			<?php if ( $is_image ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_ALT ); ?>">
					<input type="hidden" name="id" value="<?php echo (int) $id; ?>">
					<?php wp_nonce_field( self::ACTION_ALT, '_wpnonce', false ); ?>
					<label class="screen-reader-text" for="cc-alt-<?php echo (int) $id; ?>">Alt text</label>
					<input type="text" id="cc-alt-<?php echo (int) $id; ?>" name="alt" required maxlength="<?php echo (int) CC_Media_Rules::MAX_ALT_LENGTH; ?>" value="<?php echo esc_attr( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) ); ?>">
					<button class="button button-small">Save alt</button>
				</form>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_DELETE ); ?>">
				<input type="hidden" name="id" value="<?php echo (int) $id; ?>">
				<?php wp_nonce_field( self::ACTION_DELETE, '_wpnonce', false ); ?>
				<?php if ( array() !== $used_by && current_user_can( CC_Admin_Roles::CAP_MANAGE_STAFF ) ) : ?>
					<label><input type="checkbox" name="force" value="1"> Delete even though it is in use</label>
				<?php endif; ?>
				<button class="button button-small button-link-delete" onclick="return confirm('Delete this file?');">Delete</button>
			</form>
		</div>
		<?php
	}

	private static function render_notice(): void {
		$code = isset( $_GET['cc_notice'] ) ? sanitize_key( wp_unslash( $_GET['cc_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- display only.
		if ( ! isset( self::NOTICES[ $code ] ) ) {
			return;
		}
		list( $type, $message ) = self::NOTICES[ $code ];
		if ( 'failed' === $code ) {
			$detail = get_transient( self::error_key() );
			if ( is_string( $detail ) && '' !== $detail ) {
				$message = $detail;
				delete_transient( self::error_key() );
			}
		}
		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $type ), esc_html( $message ) );
	}

	/** Shown once per user: images still upload without WebP siblings. */
	private static function render_webp_notice(): void {
		$user = get_current_user_id();
		if ( CC_Media_Rules::webp_supported() || get_user_meta( $user, self::WEBP_NOTICE, true ) ) {
			return;
		}
		update_user_meta( $user, self::WEBP_NOTICE, 1 );
		echo '<div class="notice notice-warning is-dismissible"><p>This server cannot create WebP images, so uploads are saved without a WebP version. Ask your host to enable WebP support in GD or Imagick.</p></div>';
	}

	/* ---------------------------------------------------------------- internals */

	/** Mime type of an attachment this screen manages, or null. */
	private static function managed_mime( int $id ): ?string {
		$post = $id > 0 ? get_post( $id ) : null;
		if ( ! $post instanceof WP_Post || 'attachment' !== $post->post_type || ! in_array( $post->post_mime_type, CC_Media_Rules::TYPES, true ) ) {
			return null;
		}
		return (string) $post->post_mime_type;
	}

	/**
	 * @param array{ok:bool,ext:?string,mime:?string} $check
	 * @return int|WP_Error
	 */
	private static function store( string $bytes, array $check, string $alt, int $actor ) {
		CC_Media_Rules::ensure_uploads_htaccess();
		$stored = wp_upload_bits( CC_Media_Rules::random_filename( (string) $check['ext'] ), null, $bytes );
		if ( ! empty( $stored['error'] ) ) {
			return new WP_Error( 'cc_media_failed', 'The file could not be saved.' );
		}
		$id = wp_insert_attachment(
			array(
				'post_mime_type' => (string) $check['mime'],
				'post_title'     => '' !== $alt ? $alt : 'Document',
				'post_status'    => 'inherit',
				'post_author'    => $actor,
			),
			$stored['file'],
			0,
			true
		);
		if ( is_wp_error( $id ) ) {
			wp_delete_file( $stored['file'] );
			return new WP_Error( 'cc_media_failed', 'The file could not be saved.' );
		}
		require_once ABSPATH . 'wp-admin/includes/image.php';
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $stored['file'] ) );
		if ( CC_Media_Rules::is_image_mime( (string) $check['mime'] ) ) {
			update_post_meta( $id, '_wp_attachment_image_alt', $alt );
		}
		CC_Audit::log( 'media.upload', 'attachment', $id, array( 'mime' => $check['mime'], 'bytes' => strlen( $bytes ) ) );
		return (int) $id;
	}

	/** @param true|WP_Error $result */
	private static function finish( $result, string $success_code ): void {
		if ( is_wp_error( $result ) ) {
			set_transient( self::error_key(), $result->get_error_message(), self::ERROR_TTL );
		}
		self::redirect( is_wp_error( $result ) ? 'failed' : $success_code );
	}

	private static function guard( string $action ): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'coaching-platform' ), 403 );
		}
		check_admin_referer( $action );
	}

	private static function error_key(): string {
		return 'cc_media_err_' . get_current_user_id();
	}

	private static function redirect( string $notice ): void {
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'cc_notice' => $notice ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
