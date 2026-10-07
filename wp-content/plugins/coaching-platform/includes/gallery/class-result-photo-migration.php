<?php
defined( 'ABSPATH' ) || exit;

/**
 * Moves legacy result photos (attachment id in post meta `cc_result_photo`, public uploads dir) into the private store.
 * Per result, under a per-result MySQL lock (a concurrent request skips that result, and the legacy meta and private path
 * are re-read after the lock is taken, so no duplicate private file is made): copy the attachment file through
 * CC_Result_Photo_Store (same rules, EXIF stripped, WebP sibling), set `cc_result_photo_path` + `cc_result_photo_alt`,
 * remove the old meta, then delete the attachment with its generated sizes and .webp siblings from public uploads.
 * Idempotent: re-running finds nothing to do, and a run interrupted midway is finished by the next one.
 *
 * An attachment that is still used elsewhere (gallery item, featured image, embedded in content) is left in place, because
 * deleting it would break that use; the result itself already points at its private copy, and the run counts it in
 * `kept_shared` and logs that the original stays public. A photo that cannot be copied (unreadable, rejected by the store
 * rules, private dir unavailable) keeps its legacy meta and attachment, is counted in `failed` and logged with attachment
 * and post id (never file contents). Until it is migrated its attachment is hidden from the public by CC_Results
 * (hidden_photo_ids), `cc_result_photo_migration_pending` stays set, an admin notice with a retry button is shown to
 * cc_manage_gallery users and an hourly cron event (`cc_result_photo_migration_retry`) repeats the run until nothing is left.
 *
 * Runs from CC_Migrations (db version 8; deferred to the cron when more than INLINE_LIMIT results need moving) and can be
 * run by hand: wp eval 'print_r( CC_Result_Photo_Migration::run() );'
 */
final class CC_Result_Photo_Migration {

	const PENDING_OPTION = 'cc_result_photo_migration_pending';
	const RETRY_HOOK     = 'cc_result_photo_migration_retry';
	const RETRY_ACTION   = 'cc_result_photo_migration_retry_now';
	const INLINE_LIMIT   = 5;

	public static function init(): void {
		add_action( self::RETRY_HOOK, array( __CLASS__, 'run' ) );
		add_action( 'init', array( __CLASS__, 'ensure_scheduled' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_notice' ) );
		add_action( 'admin_post_' . self::RETRY_ACTION, array( __CLASS__, 'handle_retry' ) );
	}

	/** @return array{migrated:int,cleared:int,kept_shared:int,failed:int} */
	public static function run(): array {
		global $wpdb;
		$summary = array( 'migrated' => 0, 'cleared' => 0, 'kept_shared' => 0, 'failed' => 0 );
		$rows    = $wpdb->get_results(
			$wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", CC_Results::META_PHOTO_LEGACY ),
			ARRAY_A
		);
		foreach ( (array) $rows as $row ) {
			$outcome = self::migrate_one( (int) $row['post_id'] );
			if ( isset( $summary[ $outcome ] ) ) {
				++$summary[ $outcome ];
			}
		}
		self::sync_pending();
		return $summary;
	}

	/** Number of results that still carry a legacy photo reference. */
	public static function remaining_count(): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = %s WHERE pm.meta_key = %s",
				CC_Results::POST_TYPE,
				CC_Results::META_PHOTO_LEGACY
			)
		);
	}

	/** Leaves the work to the retry cron (first run right away). */
	public static function defer(): void {
		update_option( self::PENDING_OPTION, '1', false );
		self::schedule( time() );
	}

	/** Pending flag and cron event follow what is actually left: set while any legacy photo remains, cleared when none does. */
	public static function sync_pending(): void {
		if ( self::remaining_count() > 0 ) {
			update_option( self::PENDING_OPTION, '1', false );
			self::schedule( time() + HOUR_IN_SECONDS );
			return;
		}
		delete_option( self::PENDING_OPTION );
		wp_clear_scheduled_hook( self::RETRY_HOOK );
	}

	public static function ensure_scheduled(): void {
		if ( '1' === (string) get_option( self::PENDING_OPTION ) ) {
			self::schedule( time() + HOUR_IN_SECONDS );
		}
	}

	private static function schedule( int $first_run ): void {
		if ( false === wp_next_scheduled( self::RETRY_HOOK ) ) {
			wp_schedule_event( $first_run, 'hourly', self::RETRY_HOOK );
		}
	}

	public static function render_notice(): void {
		if ( '1' !== (string) get_option( self::PENDING_OPTION ) || ! current_user_can( CC_Gallery::CAP ) ) {
			return;
		}
		$count = self::remaining_count();
		if ( $count < 1 ) {
			return;
		}
		$url = wp_nonce_url( admin_url( 'admin-post.php?action=' . self::RETRY_ACTION ), self::RETRY_ACTION );
		printf(
			'<div class="notice notice-error"><p>%s <a href="%s">Retry now</a>. It is also retried every hour. See the "Private result photos" section of the Astona README, or run <code>wp eval \'CC_Result_Photo_Migration::run();\'</code>.</p></div>',
			esc_html( sprintf( _n( '%d result photo could not be moved to private storage and its image is withheld from the public meanwhile.', '%d result photos could not be moved to private storage and their images are withheld from the public meanwhile.', $count ), $count ) ),
			esc_url( $url )
		);
	}

	public static function handle_retry(): void {
		if ( ! current_user_can( CC_Gallery::CAP ) ) {
			wp_die( 'You are not allowed to do that.', 403 );
		}
		check_admin_referer( self::RETRY_ACTION );
		self::run();
		wp_safe_redirect( wp_get_referer() ?: admin_url() );
		exit;
	}

	/** @return string One of the summary keys, or 'skipped'. */
	private static function migrate_one( int $post_id ): string {
		global $wpdb;
		$lock = 'cc_rpm_' . $post_id;
		if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) {
			return 'skipped';
		}
		try {
			wp_cache_delete( $post_id, 'post_meta' );
			return self::migrate_locked( $post_id );
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	private static function migrate_locked( int $post_id ): string {
		if ( CC_Results::POST_TYPE !== get_post_type( $post_id ) || ! metadata_exists( 'post', $post_id, CC_Results::META_PHOTO_LEGACY ) ) {
			return 'skipped';
		}
		$attachment_id = absint( get_post_meta( $post_id, CC_Results::META_PHOTO_LEGACY, true ) );
		if ( $attachment_id <= 0 || 'attachment' !== get_post_type( $attachment_id ) ) {
			delete_post_meta( $post_id, CC_Results::META_PHOTO_LEGACY );
			return 'cleared';
		}
		if ( '' === CC_Results::photo_path( $post_id ) && ! self::copy_into_private_store( $post_id, $attachment_id ) ) {
			return 'failed';
		}
		delete_post_meta( $post_id, CC_Results::META_PHOTO_LEGACY );
		if ( self::retire_attachment( $attachment_id ) ) {
			return 'migrated';
		}
		error_log( sprintf( 'CC result photo migration: result %d moved to private storage; original attachment %d remains public because it is used elsewhere.', $post_id, $attachment_id ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		return 'kept_shared';
	}

	private static function copy_into_private_store( int $post_id, int $attachment_id ): bool {
		$file  = (string) get_attached_file( $attachment_id );
		$bytes = '' !== $file && is_readable( $file ) ? (string) file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$store = '' === $bytes ? new WP_Error( 'cc_unreadable', 'The attachment file is missing or unreadable.' ) : CC_Result_Photo_Store::store_bytes( $bytes );
		if ( is_wp_error( $store ) ) {
			error_log( sprintf( 'CC result photo migration: result %d, attachment %d not migrated: %s', $post_id, $attachment_id, $store->get_error_message() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return false;
		}
		$alt = trim( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );
		update_post_meta( $post_id, CC_Results::META_PHOTO_PATH, $store );
		update_post_meta( $post_id, CC_Results::META_PHOTO_ALT, wp_slash( '' !== $alt ? $alt : 'Photo of ' . get_the_title( $post_id ) ) );
		CC_Audit::log( 'result.photo_migrate', 'result', $post_id );
		return true;
	}

	/** @return bool True when the attachment was deleted, false when it was kept because something else still uses it. */
	private static function retire_attachment( int $attachment_id ): bool {
		global $wpdb;
		$still_legacy = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s", CC_Results::META_PHOTO_LEGACY, (string) $attachment_id ) );
		if ( $still_legacy > 0 ) {
			return true; // another result still points here; the last one to migrate deletes it.
		}
		if ( array() !== CC_Admin_Media::usages( $attachment_id ) ) {
			return false;
		}
		$siblings = self::webp_sibling_paths( $attachment_id );
		wp_delete_attachment( $attachment_id, true );
		foreach ( $siblings as $sibling ) {
			wp_delete_file( $sibling );
		}
		return true;
	}

	/** @return string[] "<file>.webp" next to the original and each generated size. */
	private static function webp_sibling_paths( int $attachment_id ): array {
		$path = (string) get_attached_file( $attachment_id );
		if ( '' === $path ) {
			return array();
		}
		$metadata = wp_get_attachment_metadata( $attachment_id );
		$names    = array( basename( $path ) );
		foreach ( (array) ( is_array( $metadata ) ? ( $metadata['sizes'] ?? array() ) : array() ) as $size ) {
			if ( ! empty( $size['file'] ) ) {
				$names[] = basename( (string) $size['file'] );
			}
		}
		return array_map( static fn( string $name ): string => path_join( dirname( $path ), $name . '.webp' ), array_values( array_unique( $names ) ) );
	}
}
