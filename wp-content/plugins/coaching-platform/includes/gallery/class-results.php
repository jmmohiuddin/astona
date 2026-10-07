<?php
defined( 'ABSPATH' ) || exit;

/**
 * Student results: CPT `cc_result` (never individually public). A record is shown publicly only when it is published (and not
 * password-protected), verified AND consent-confirmed; every other record is excluded entirely (name and photo). Verified/consent changes
 * and photo changes are audited.
 *
 * Result photos are NOT media-library attachments. They live only under CC_PRIVATE_DIR/result-photos/
 * (CC_Result_Photo_Store), are referenced by the relative path in post meta `cc_result_photo_path` (+ required alt text in
 * `cc_result_photo_alt`), and are readable only through signed, expiring, state-checked URLs (CC_Result_Photo_Access), so
 * no web server URL exists for them. CC_Result_Photo_Migration moves legacy attachment-based photos (`cc_result_photo`); until
 * a result is migrated its legacy attachment is treated as hidden (see hidden_photo_ids()).
 *
 * The attachment guards below (media REST, oEmbed, redirects, URL helpers, listings) protect gallery item images: an image
 * of a gallery item that is not published with alt text answers like a missing attachment for anyone without the gallery
 * capability. Published gallery images are public by design.
 */
final class CC_Results {

	const POST_TYPE       = 'cc_result';
	const META_EXAM       = 'cc_result_exam';
	const META_YEAR       = 'cc_result_year';
	const META_SCORE      = 'cc_result_score';
	const META_INSTITUTION = 'cc_result_institution';
	const META_VERIFIED   = 'cc_result_verified';
	const META_CONSENT    = 'cc_result_consent';
	const META_PHOTO_LEGACY = 'cc_result_photo';
	const META_PHOTO_PATH = 'cc_result_photo_path';
	const META_PHOTO_ALT  = 'cc_result_photo_alt';
	const META_SEED_PHOTO = '_cc_seed_photo';
	const PHOTO_FILE_FIELD = 'cc_result_photo_file';
	const PHOTO_ALT_FIELD = 'cc_result_photo_alt';
	const PHOTO_REMOVE_FIELD = 'cc_result_photo_remove';
	const PHOTO_ERROR_TTL = 60;
	const NONCE_ACTION    = 'cc_result_save';
	const NONCE_FIELD     = 'cc_result_nonce';
	const FLAGS           = array(
		'verified' => self::META_VERIFIED,
		'consent'  => self::META_CONSENT,
	);
	const MIN_YEAR        = 1990;
	const MAX_YEAR        = 2100;
	const MAX_TEXT        = 190;
	const MAX_ITEMS       = 500;

	/** @var int[]|null Per-request cache of hidden_photo_ids(); flushed whenever a result, gallery item or flag changes. */
	private static ?array $hidden_cache = null;

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'add_meta_boxes_' . self::POST_TYPE, array( __CLASS__, 'add_metabox' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'save_metabox' ), 10, 1 );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );
		add_action( 'admin_post_cc_result_toggle', array( __CLASS__, 'handle_toggle' ) );
		add_action( 'post_edit_form_tag', array( __CLASS__, 'multipart_form' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_photo_notice' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'delete_photo_with_post' ), 10, 2 );
		add_action( 'pre_get_posts', array( __CLASS__, 'exclude_hidden_photos' ), 5 );
		add_filter( 'rest_request_before_callbacks', array( __CLASS__, 'guard_rest_media' ), 10, 3 );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_404' ), 1 );
		add_filter( 'redirect_canonical', array( __CLASS__, 'guard_canonical_redirect' ), 10, 1 );
		add_filter( 'oembed_request_post_id', array( __CLASS__, 'guard_oembed_post_id' ), 10, 1 );
		add_filter( 'oembed_response_data', array( __CLASS__, 'guard_oembed_data' ), 10, 2 );
		add_filter( 'wp_get_attachment_url', array( __CLASS__, 'guard_attachment_url' ), 10, 2 );
		add_filter( 'wp_get_attachment_image_src', array( __CLASS__, 'guard_attachment_image_src' ), 10, 2 );
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta', 'transition_post_status', 'deleted_post' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'flush_hidden_cache' ) );
		}
		add_action( 'cc_seed_extra', array( __CLASS__, 'seed' ) );
		CC_Result_Photo_Migration::init();
	}

	public static function register(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'          => 'Results',
					'singular_name' => 'Result',
					'add_new_item'  => 'Add Result',
					'edit_item'     => 'Edit Result',
					'all_items'     => 'All Results',
					'search_items'  => 'Search Results',
				),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => true,
				'show_in_rest'        => false,
				'rewrite'             => false,
				'query_var'           => false,
				'has_archive'         => false,
				'menu_icon'           => 'dashicons-awards',
				'supports'            => array( 'title' ),
				'capability_type'     => array( 'cc_result', 'cc_results' ),
				'capabilities'        => CC_Gallery::cpt_caps(),
				'map_meta_cap'        => true,
			)
		);
	}

	/* ------------------------------------------------------------ pure helpers */

	/** A 4-digit year inside the accepted range, otherwise 0 (unknown). */
	public static function normalise_year( $value ): int {
		$value = trim( (string) $value );
		if ( ! preg_match( '/^\d{4}$/', $value ) ) {
			return 0;
		}
		$year = (int) $value;
		return ( $year >= self::MIN_YEAR && $year <= self::MAX_YEAR ) ? $year : 0;
	}

	/**
	 * Records grouped by year, newest year first; unknown years (0) last.
	 *
	 * @param array<int,array{year:int}> $records
	 * @return array<int,array<int,array>> year => records, original order kept inside a year.
	 */
	public static function group_by_year( array $records ): array {
		$groups = array();
		foreach ( $records as $record ) {
			$groups[ (int) $record['year'] ][] = $record;
		}
		uksort( $groups, static fn( int $a, int $b ): int => ( 0 === $a ) <=> ( 0 === $b ) ?: $b <=> $a );
		return $groups;
	}

	/* ------------------------------------------------------------ visibility */

	/** @return array<int,array<string,mixed>> Published records that are verified AND consent-confirmed. */
	public static function public_records(): array {
		$posts = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => self::MAX_ITEMS,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
				'has_password'   => false,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					'relation' => 'AND',
					array( 'key' => self::META_VERIFIED, 'value' => '1' ),
					array( 'key' => self::META_CONSENT, 'value' => '1' ),
				),
			)
		);
		$records = array();
		foreach ( $posts as $post ) {
			$records[] = array(
				'id'          => (int) $post->ID,
				'name'        => $post->post_title,
				'exam'        => (string) get_post_meta( $post->ID, self::META_EXAM, true ),
				'year'        => self::normalise_year( get_post_meta( $post->ID, self::META_YEAR, true ) ),
				'score'       => (string) get_post_meta( $post->ID, self::META_SCORE, true ),
				'institution' => (string) get_post_meta( $post->ID, self::META_INSTITUTION, true ),
				'photo'       => '' !== self::photo_path( (int) $post->ID ),
				'photo_alt'   => (string) get_post_meta( $post->ID, self::META_PHOTO_ALT, true ),
			);
		}
		return $records;
	}

	/** True when the result is currently published, not password-protected, AND verified AND consent-confirmed. */
	public static function is_public( int $post_id ): bool {
		return self::POST_TYPE === get_post_type( $post_id ) && 'publish' === get_post_status( $post_id )
			&& '' === (string) get_post_field( 'post_password', $post_id )
			&& self::flag( $post_id, 'verified' ) && self::flag( $post_id, 'consent' );
	}

	/** Relative private path of the result's photo, or '' when it has none. */
	public static function photo_path( int $post_id ): string {
		$path = (string) get_post_meta( $post_id, self::META_PHOTO_PATH, true );
		return 1 === preg_match( CC_Result_Photo_Store::PATH_PATTERN, $path ) ? $path : '';
	}

	public static function public_groups(): array {
		return self::group_by_year( self::public_records() );
	}

	public static function flush_hidden_cache(): void {
		self::$hidden_cache = null;
	}

	/**
	 * Attachment ids that must not be public: images of gallery items that are not published with alt text, and legacy
	 * result photos (results that still carry `cc_result_photo`, i.e. not yet moved to the private store; a failed or
	 * pending migration must not leave them public). An image that is also used by a public gallery item stays visible.
	 * Two queries, cached per request.
	 *
	 * @return int[]
	 */
	public static function hidden_photo_ids(): array {
		if ( null !== self::$hidden_cache ) {
			return self::$hidden_cache;
		}
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT CAST(pm.meta_value AS UNSIGNED) AS photo,
					MAX( p.post_status = 'publish' AND TRIM(COALESCE(a.meta_value, '')) <> '' ) AS is_public
				FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = %s
				LEFT JOIN {$wpdb->postmeta} a ON a.post_id = p.ID AND a.meta_key = %s
				WHERE pm.meta_key = %s AND CAST(pm.meta_value AS UNSIGNED) > 0
				GROUP BY photo",
				CC_Gallery::POST_TYPE,
				CC_Gallery::META_ALT,
				CC_Gallery::META_IMAGE
			),
			ARRAY_A
		);
		$hidden = array();
		$public = array();
		foreach ( (array) $rows as $row ) {
			if ( (int) $row['is_public'] ) {
				$public[] = (int) $row['photo'];
			} else {
				$hidden[] = (int) $row['photo'];
			}
		}
		$legacy = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT CAST(pm.meta_value AS UNSIGNED) FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = %s
				WHERE pm.meta_key = %s AND CAST(pm.meta_value AS UNSIGNED) > 0",
				self::POST_TYPE,
				self::META_PHOTO_LEGACY
			)
		);
		$hidden = array_values( array_unique( array_merge( $hidden, array_diff( array_map( 'intval', (array) $legacy ), $public ) ) ) );
		self::$hidden_cache = $hidden;
		return self::$hidden_cache;
	}

	/** True when the current viewer is blocked from this attachment id. */
	private static function is_hidden_from_viewer( int $attachment_id ): bool {
		return $attachment_id > 0 && ! user_can( get_current_user_id(), CC_Gallery::CAP ) && in_array( $attachment_id, self::hidden_photo_ids(), true );
	}

	private static function send_404( WP_Query $query ): void {
		global $post;
		$query->set_404();
		$query->posts      = array();
		$query->post_count = 0;
		$query->post       = null;
		$post              = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		if ( ! headers_sent() ) {
			status_header( 404 );
			nocache_headers();
		}
	}

	/** Attachment page (any URL form) of a hidden photo is a plain 404 for the public. */
	public static function maybe_404(): bool {
		global $wp_query;
		if ( ! is_attachment() || ! self::is_hidden_from_viewer( (int) get_queried_object_id() ) ) {
			return false;
		}
		self::send_404( $wp_query );
		return true;
	}

	/**
	 * /?p=ID, ?page_id= and ?attachment_id= would 301 to the attachment permalink (sequential ids are enumerable) and from
	 * there to the file. pre_get_posts has already zeroed the query vars, so the raw request ids are checked as well.
	 *
	 * @param mixed $redirect_url
	 * @return mixed
	 */
	public static function guard_canonical_redirect( $redirect_url ) {
		if ( ! is_string( $redirect_url ) || '' === $redirect_url ) {
			return $redirect_url;
		}
		foreach ( array( 'p', 'page_id', 'attachment_id' ) as $var ) {
			$from_request = isset( $_GET[ $var ] ) ? absint( wp_unslash( $_GET[ $var ] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
			if ( self::is_hidden_from_viewer( $from_request ) || self::is_hidden_from_viewer( absint( get_query_var( $var ) ) ) ) {
				return false;
			}
		}
		$target = strtok( untrailingslashit( $redirect_url ), '?' );
		foreach ( self::hidden_photo_ids() as $hidden_id ) {
			if ( self::is_hidden_from_viewer( $hidden_id ) && untrailingslashit( (string) get_permalink( $hidden_id ) ) === $target ) {
				return false;
			}
		}
		return $redirect_url;
	}

	/** oEmbed resolves attachment ids and slugs through url_to_postid(); a hidden photo must look like an unknown URL. */
	public static function guard_oembed_post_id( $post_id ) {
		return self::is_hidden_from_viewer( (int) $post_id ) ? 0 : $post_id;
	}

	/** Backstop for oembed_request_post_id: no title or thumbnail for a hidden photo, whatever resolved it. */
	public static function guard_oembed_data( $data, $post ) {
		return $post instanceof WP_Post && self::is_hidden_from_viewer( (int) $post->ID ) ? false : $data;
	}

	/** Pages never print the file URL (or its .webp sibling, which shares the basename) of a hidden photo. */
	public static function guard_attachment_url( $url, $attachment_id ) {
		return self::is_hidden_from_viewer( (int) $attachment_id ) ? false : $url;
	}

	public static function guard_attachment_image_src( $image, $attachment_id ) {
		return self::is_hidden_from_viewer( (int) $attachment_id ) ? false : $image;
	}

	/** Images of unpublished gallery items stay out of public media queries (attachment pages, search, REST collection). */
	public static function exclude_hidden_photos( $query ): void {
		if ( ! $query instanceof WP_Query ) {
			return;
		}
		$types    = (array) $query->get( 'post_type' );
		$includes = in_array( 'attachment', $types, true ) || in_array( 'any', $types, true ) || $query->is_attachment() || ( empty( $types[0] ) && $query->is_search() );
		if ( ! $includes ) {
			return;
		}
		if ( user_can( get_current_user_id(), CC_Gallery::CAP ) ) {
			return;
		}
		$hidden = self::hidden_photo_ids();
		if ( ! $hidden ) {
			return;
		}
		$query->set( 'post__not_in', array_values( array_unique( array_merge( array_map( 'intval', (array) $query->get( 'post__not_in' ) ), $hidden ) ) ) );
		foreach ( array( 'p', 'page_id', 'attachment_id' ) as $var ) {
			if ( in_array( (int) $query->get( $var ), $hidden, true ) ) {
				$query->set( $var, 0 );
				$query->set( 'post__in', array( 0 ) );
			}
		}
		$requested = array_map( 'intval', (array) $query->get( 'post__in' ) );
		if ( $requested ) {
			$query->set( 'post__in', array_values( array_diff( $requested, $hidden ) ) ?: array( 0 ) );
		}
	}

	/** GET/HEAD /wp/v2/media/{id}: route match is case-insensitive like WordPress routing; the handler check is spelling-independent. */
	public static function guard_rest_media( $response, $handler, $request ) {
		if ( ! $request instanceof WP_REST_Request || ! in_array( $request->get_method(), array( 'GET', 'HEAD' ), true ) ) {
			return $response;
		}
		$attachment_id = 0;
		if ( preg_match( '#^/wp/v2/media/(\d+)/?$#i', $request->get_route(), $m ) ) {
			$attachment_id = (int) $m[1];
		}
		$callback = is_array( $handler ) ? ( $handler['callback'] ?? null ) : null;
		if ( is_array( $callback ) && $callback[0] instanceof WP_REST_Attachments_Controller && 'get_item' === $callback[1] ) {
			$attachment_id = max( $attachment_id, absint( $request['id'] ) );
		}
		if ( self::is_hidden_from_viewer( $attachment_id ) ) {
			return new WP_Error( 'rest_post_invalid_id', 'Invalid post ID.', array( 'status' => 404 ) );
		}
		return $response;
	}

	/* ------------------------------------------------------------ flags */

	public static function flag( int $post_id, string $flag ): bool {
		return isset( self::FLAGS[ $flag ] ) && '1' === (string) get_post_meta( $post_id, self::FLAGS[ $flag ], true );
	}

	/** Sets verified/consent and audits real changes. Capability is checked here so no caller can skip it. */
	public static function set_flag( int $post_id, string $flag, bool $value ): bool {
		if ( ! isset( self::FLAGS[ $flag ] ) || self::POST_TYPE !== get_post_type( $post_id ) || ! current_user_can( CC_Gallery::CAP ) ) {
			return false;
		}
		$before = self::flag( $post_id, $flag );
		if ( $before === $value ) {
			return true;
		}
		update_post_meta( $post_id, self::FLAGS[ $flag ], $value ? '1' : '0' );
		CC_Audit::log( 'result.' . $flag, 'result', $post_id, array( 'from' => $before, 'to' => $value ), $value ? 'on' : 'off' );
		return true;
	}

	public static function handle_toggle(): void {
		$post_id = isset( $_GET['result_id'] ) ? absint( wp_unslash( $_GET['result_id'] ) ) : 0;
		$flag    = isset( $_GET['flag'] ) ? sanitize_key( wp_unslash( $_GET['flag'] ) ) : '';
		if ( ! current_user_can( CC_Gallery::CAP ) || ! isset( self::FLAGS[ $flag ] ) ) {
			wp_die( 'You are not allowed to do that.', 403 );
		}
		check_admin_referer( 'cc_result_toggle_' . $post_id . '_' . $flag );
		self::set_flag( $post_id, $flag, ! self::flag( $post_id, $flag ) );
		wp_safe_redirect( admin_url( 'edit.php?post_type=' . self::POST_TYPE ) );
		exit;
	}

	/* ------------------------------------------------------------ metabox */

	public static function add_metabox(): void {
		add_meta_box( 'cc-result-details', 'Result details', array( __CLASS__, 'render_metabox' ), self::POST_TYPE, 'normal', 'high' );
	}

	private static function text_field( int $post_id, string $id, string $label, string $meta, string $hint = '' ): void {
		printf(
			'<p><label for="%1$s"><strong>%2$s</strong></label><br><input type="text" class="large-text" maxlength="%3$d" id="%1$s" name="%1$s" value="%4$s">%5$s</p>',
			esc_attr( $id ),
			esc_html( $label ),
			(int) self::MAX_TEXT,
			esc_attr( (string) get_post_meta( $post_id, $meta, true ) ),
			'' === $hint ? '' : ' <span class="description">' . esc_html( $hint ) . '</span>' // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
		);
	}

	/** The post form needs multipart encoding for the photo upload control. */
	public static function multipart_form(): void {
		global $post;
		if ( $post instanceof WP_Post && self::POST_TYPE === $post->post_type ) {
			echo ' enctype="multipart/form-data"';
		}
	}

	private static function render_photo_control( int $post_id ): void {
		$url = self::photo_path( $post_id ) === '' ? '' : CC_Result_Photo_Access::signed_url( $post_id );
		?>
		<p><label for="cc-result-photo-file"><strong>Photo (optional)</strong></label><br>
			<?php if ( '' !== $url ) : ?>
				<img src="<?php echo esc_url( $url ); ?>" alt="<?php echo esc_attr( (string) get_post_meta( $post_id, self::META_PHOTO_ALT, true ) ); ?>" style="max-width:150px;height:auto"><br>
			<?php endif; ?>
			<input type="file" id="cc-result-photo-file" name="<?php echo esc_attr( self::PHOTO_FILE_FIELD ); ?>" accept="image/jpeg,image/png,image/webp">
			<span class="description">JPEG, PNG or WebP, up to 5 MB. Stored privately and shown on the Results page only while the result is published, verified and consent-confirmed. Choosing a new file replaces the current one.</span>
		</p>
		<p><label for="cc-result-photo-alt"><strong>Photo description (alt text, required when there is a photo)</strong></label><br>
			<input type="text" class="large-text" maxlength="<?php echo (int) self::MAX_TEXT; ?>" id="cc-result-photo-alt" name="<?php echo esc_attr( self::PHOTO_ALT_FIELD ); ?>" value="<?php echo esc_attr( (string) get_post_meta( $post_id, self::META_PHOTO_ALT, true ) ); ?>"></p>
		<?php if ( '' !== $url ) : ?>
			<p><label><input type="checkbox" name="<?php echo esc_attr( self::PHOTO_REMOVE_FIELD ); ?>" value="1"> Remove the current photo</label></p>
		<?php endif;
	}

	public static function render_metabox( WP_Post $post ): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		echo '<p class="description">The title is the student display name, shown exactly as entered.</p>';
		self::text_field( $post->ID, 'cc_result_exam', 'Exam or achievement', self::META_EXAM );
		self::text_field( $post->ID, 'cc_result_year', 'Year', self::META_YEAR, 'Four digits, for example 2026.' );
		self::text_field( $post->ID, 'cc_result_score', 'Score or grade', self::META_SCORE );
		self::text_field( $post->ID, 'cc_result_institution', 'Institution admitted (optional)', self::META_INSTITUTION );
		self::render_photo_control( $post->ID );
		?>
		<p>
			<label><input type="checkbox" name="cc_result_verified" value="1" <?php checked( self::flag( $post->ID, 'verified' ) ); ?>> <strong>Verified</strong> against the official result</label><br>
			<label><input type="checkbox" name="cc_result_consent" value="1" <?php checked( self::flag( $post->ID, 'consent' ) ); ?>> <strong>Consent confirmed</strong> by the student or guardian to show the name and photo</label>
		</p>
		<p class="description">A result appears on the public Results page only when it is published, verified and consent-confirmed.</p>
		<?php
	}

	public static function save_metabox( int $post_id ): void {
		$nonce = isset( $_POST[ self::NONCE_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ) : '';
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
			return;
		}
		if ( ! current_user_can( CC_Gallery::CAP ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$text = static fn( string $key ): string => mb_substr( trim( sanitize_text_field( wp_unslash( $_POST[ $key ] ?? '' ) ) ), 0, self::MAX_TEXT );
		update_post_meta( $post_id, self::META_EXAM, $text( 'cc_result_exam' ) );
		update_post_meta( $post_id, self::META_YEAR, (string) ( self::normalise_year( $text( 'cc_result_year' ) ) ?: '' ) );
		update_post_meta( $post_id, self::META_SCORE, $text( 'cc_result_score' ) );
		update_post_meta( $post_id, self::META_INSTITUTION, $text( 'cc_result_institution' ) );
		$change = self::apply_photo_change( $post_id, self::uploaded_photo_bytes(), $text( self::PHOTO_ALT_FIELD ), ! empty( $_POST[ self::PHOTO_REMOVE_FIELD ] ) );
		if ( is_wp_error( $change ) ) {
			set_transient( self::photo_error_key(), $change->get_error_message(), self::PHOTO_ERROR_TTL );
		}
		self::set_flag( $post_id, 'verified', ! empty( $_POST['cc_result_verified'] ) );
		self::set_flag( $post_id, 'consent', ! empty( $_POST['cc_result_consent'] ) );
	}

	/** @return string|WP_Error|null Bytes of the uploaded file, null when no file was chosen. */
	private static function uploaded_photo_bytes() {
		$file  = isset( $_FILES[ self::PHOTO_FILE_FIELD ] ) && is_array( $_FILES[ self::PHOTO_FILE_FIELD ] ) ? $_FILES[ self::PHOTO_FILE_FIELD ] : array(); // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput -- nonce verified in save_metabox(); content validated by CC_Result_Photo_Store.
		$error = (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE );
		if ( UPLOAD_ERR_NO_FILE === $error ) {
			return null;
		}
		$tmp = (string) ( $file['tmp_name'] ?? '' );
		if ( UPLOAD_ERR_OK !== $error ) {
			return new WP_Error( 'cc_result_photo_upload', CC_Resource_Store::upload_error_message( $error, min( CC_Result_Photo_Store::MAX_BYTES, (int) wp_max_upload_size() ) ) );
		}
		if ( '' === $tmp || ! is_uploaded_file( $tmp ) ) {
			return new WP_Error( 'cc_result_photo_upload', 'Choose a photo to upload.' );
		}
		return (string) file_get_contents( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	/**
	 * Sets, replaces, describes or removes the result's private photo. A new file needs alt text and replaces (and
	 * deletes) the old one; `$remove` without a file deletes the photo. Audited by size only, never by file contents.
	 *
	 * @param string|WP_Error|null $bytes New photo bytes, or null for no new file.
	 * @return true|WP_Error
	 */
	public static function apply_photo_change( int $post_id, $bytes, string $alt, bool $remove ) {
		if ( ! current_user_can( CC_Gallery::CAP ) || self::POST_TYPE !== get_post_type( $post_id ) ) {
			return new WP_Error( 'cc_forbidden', 'You are not allowed to change this photo.' );
		}
		if ( is_wp_error( $bytes ) ) {
			return $bytes;
		}
		$alt = mb_substr( trim( sanitize_text_field( $alt ) ), 0, self::MAX_TEXT );
		$old = self::photo_path( $post_id );
		if ( null !== $bytes ) {
			return self::replace_photo( $post_id, $old, $bytes, $alt );
		}
		if ( '' === $old ) {
			return true;
		}
		if ( $remove ) {
			self::clear_photo( $post_id, $old );
			CC_Audit::log( 'result.photo_remove', 'result', $post_id );
			return true;
		}
		if ( '' === $alt ) {
			return new WP_Error( 'cc_result_photo_alt_required', 'Photo description is required while the result has a photo; the previous text was kept.' );
		}
		update_post_meta( $post_id, self::META_PHOTO_ALT, wp_slash( $alt ) );
		return true;
	}

	/** @return true|WP_Error */
	private static function replace_photo( int $post_id, string $old, string $bytes, string $alt ) {
		if ( '' === $alt ) {
			return new WP_Error( 'cc_result_photo_alt_required', 'Photo description is required: the photo was not saved.' );
		}
		$stored = CC_Result_Photo_Store::store_bytes( $bytes );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}
		update_post_meta( $post_id, self::META_PHOTO_PATH, $stored );
		update_post_meta( $post_id, self::META_PHOTO_ALT, wp_slash( $alt ) );
		if ( '' !== $old ) {
			CC_Result_Photo_Store::delete( $old );
		}
		CC_Audit::log( '' === $old ? 'result.photo_set' : 'result.photo_replace', 'result', $post_id, array( 'bytes' => strlen( $bytes ) ) );
		return true;
	}

	private static function clear_photo( int $post_id, string $path ): void {
		CC_Result_Photo_Store::delete( $path );
		delete_post_meta( $post_id, self::META_PHOTO_PATH );
		delete_post_meta( $post_id, self::META_PHOTO_ALT );
	}

	/** Permanent deletion removes the private files too; trashing keeps them so the result can be restored. */
	public static function delete_photo_with_post( $post_id, $post = null ): void {
		$post_id = (int) $post_id;
		if ( self::POST_TYPE === get_post_type( $post_id ) ) {
			CC_Result_Photo_Store::delete( self::photo_path( $post_id ) );
		}
	}

	private static function photo_error_key(): string {
		return 'cc_result_photo_err_' . get_current_user_id();
	}

	public static function render_photo_notice(): void {
		if ( ! current_user_can( CC_Gallery::CAP ) ) {
			return;
		}
		$message = get_transient( self::photo_error_key() );
		if ( ! is_string( $message ) || '' === $message ) {
			return;
		}
		delete_transient( self::photo_error_key() );
		printf( '<div class="notice notice-error is-dismissible"><p>%s</p></div>', esc_html( $message ) );
	}

	/* ------------------------------------------------------------ list columns */

	public static function columns( array $columns ): array {
		unset( $columns['date'] );
		$columns['cc_year']     = 'Year';
		$columns['cc_score']    = 'Score / grade';
		$columns['cc_verified'] = 'Verified';
		$columns['cc_consent']  = 'Consent';
		return $columns;
	}

	public static function render_column( string $column, int $post_id ): void {
		if ( 'cc_year' === $column ) {
			echo esc_html( (string) ( self::normalise_year( get_post_meta( $post_id, self::META_YEAR, true ) ) ?: '' ) );
		} elseif ( 'cc_score' === $column ) {
			echo esc_html( (string) get_post_meta( $post_id, self::META_SCORE, true ) );
		} elseif ( 'cc_verified' === $column || 'cc_consent' === $column ) {
			$flag = 'cc_verified' === $column ? 'verified' : 'consent';
			$on   = self::flag( $post_id, $flag );
			$url  = wp_nonce_url( admin_url( 'admin-post.php?action=cc_result_toggle&result_id=' . $post_id . '&flag=' . $flag ), 'cc_result_toggle_' . $post_id . '_' . $flag );
			printf( '%s &middot; <a href="%s">%s</a>', $on ? 'Yes' : 'No', esc_url( $url ), $on ? 'Turn off' : 'Turn on' ); // phpcs:ignore WordPress.Security.EscapeOutput -- static strings.
		}
	}

	/* ------------------------------------------------------------ seeding */

	public static function seed(): void {
		self::register();
		$records = array(
			'result-demo-ayesha' => array( 'Ayesha Rahman (sample)', 'HSC Science', 2026, 'GPA 5.00', 'Dhaka University', true, true, array( 67, 56, 202 ) ),
			'result-demo-farhan' => array( 'Farhan Ahmed (sample)', 'University admission test', 2026, 'Merit position 142', 'BUET', true, true, array( 14, 116, 144 ) ),
			'result-demo-nabila' => array( 'Nabila Islam (sample)', 'IELTS Academic', 2025, 'Band 7.5', '', true, true, array( 180, 83, 9 ) ),
			'result-demo-noconsent' => array( 'Hidden Sample Not Consented', 'SSC', 2026, 'GPA 5.00', '', true, false, array( 21, 128, 61 ) ),
			'result-demo-unverified' => array( 'Hidden Sample Unverified', 'HSC Commerce', 2026, 'GPA 4.50', '', false, true, array( 190, 24, 93 ) ),
		);
		foreach ( $records as $slug => $r ) {
			$found = get_posts( array( 'post_type' => self::POST_TYPE, 'name' => $slug, 'post_status' => 'any', 'posts_per_page' => 1, 'fields' => 'ids' ) );
			$id    = $found ? (int) $found[0] : (int) wp_insert_post(
				array(
					'post_type'   => self::POST_TYPE,
					'post_name'   => $slug,
					'post_title'  => $r[0],
					'post_status' => 'publish',
					'meta_input'  => array(
						self::META_EXAM        => $r[1],
						self::META_YEAR        => (string) $r[2],
						self::META_SCORE       => $r[3],
						self::META_INSTITUTION => $r[4],
						self::META_VERIFIED    => $r[5] ? '1' : '0',
						self::META_CONSENT     => $r[6] ? '1' : '0',
					),
				)
			);
			if ( $id > 0 ) {
				self::seed_photo( $id, $r[0], $r[7] );
			}
		}
		CC_Gallery::ensure_page( 'results', 'Results', 'page-results.php' );
		CC_Gallery::ensure_menu_item( 'Results', 'results' );
	}

	/**
	 * Gives a sample result a generated placeholder portrait in the private store. Done once per record (marker meta), so a
	 * photo that staff later remove is not re-added, and records that still carry a legacy attachment photo are left to the
	 * migration.
	 */
	private static function seed_photo( int $post_id, string $name, array $rgb ): void {
		if ( '' !== (string) get_post_meta( $post_id, self::META_SEED_PHOTO, true ) || '' !== self::photo_path( $post_id ) || '' !== (string) get_post_meta( $post_id, self::META_PHOTO_LEGACY, true ) || ! function_exists( 'imagecreatetruecolor' ) ) {
			return;
		}
		$canvas = imagecreatetruecolor( 400, 400 );
		imagefill( $canvas, 0, 0, imagecolorallocate( $canvas, $rgb[0], $rgb[1], $rgb[2] ) );
		ob_start();
		imagejpeg( $canvas, null, 80 );
		$bytes = (string) ob_get_clean();
		imagedestroy( $canvas );
		$stored = CC_Result_Photo_Store::store_bytes( $bytes );
		if ( is_wp_error( $stored ) ) {
			return;
		}
		update_post_meta( $post_id, self::META_PHOTO_PATH, $stored );
		update_post_meta( $post_id, self::META_PHOTO_ALT, wp_slash( 'Sample placeholder portrait of ' . $name ) );
		update_post_meta( $post_id, self::META_SEED_PHOTO, '1' );
	}
}
