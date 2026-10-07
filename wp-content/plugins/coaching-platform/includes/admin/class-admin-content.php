<?php
defined( 'ABSPATH' ) || exit;

/**
 * Admin "Course content" screen: modules and lessons per batch, with ordering and an optional PDF per lesson.
 * Every handler checks the capability and the nonce; the public static operations re-check the capability for the
 * given actor so they are safe to call from anywhere. Lesson times are entered in the site timezone and stored UTC.
 * PDFs are only stored here (CC_Resource_Store); they are never served from the admin.
 */
final class CC_Admin_Content {

	const PAGE_SLUG = 'cc-content';
	const CAP       = 'cc_manage_content';
	const FORM_TIME = 'Y-m-d\TH:i';
	const DB_TIME   = 'Y-m-d H:i:s';

	const NOTICES = array(
		'module_saved'   => array( 'success', 'Module saved.' ),
		'module_deleted' => array( 'success', 'Module and its lessons deleted.' ),
		'lesson_saved'   => array( 'success', 'Lesson saved.' ),
		'lesson_deleted' => array( 'success', 'Lesson deleted.' ),
		'moved'          => array( 'success', 'Order updated.' ),
		'failed'         => array( 'error', 'The change could not be saved. Check the fields and try again.' ),
	);

	public static function init(): void {
		foreach ( array( 'module_save', 'module_delete', 'module_move', 'lesson_save', 'lesson_delete', 'lesson_move' ) as $action ) {
			add_action( 'admin_post_cc_content_' . $action, array( __CLASS__, 'handle_' . $action ) );
		}
		add_action( 'admin_init', array( __CLASS__, 'catch_oversized_post' ) );
	}

	/* ---------------------------------------------------------------- testable operations */

	/** @return int|WP_Error Module id. $module_id 0 creates a module in $batch_id. */
	public static function save_module( int $batch_id, int $module_id, string $title, int $actor ) {
		if ( ! user_can( $actor, self::CAP ) ) {
			return self::forbidden();
		}
		$title = trim( $title );
		if ( '' === $title ) {
			return new WP_Error( 'cc_invalid_title', 'A module needs a title.' );
		}
		if ( $module_id > 0 ) {
			if ( null === CC_Content_Repository::find_module( $module_id ) ) {
				return new WP_Error( 'cc_not_found', 'Module not found.' );
			}
			if ( ! CC_Content_Repository::update_module( $module_id, array( 'title' => $title ) ) ) {
				return new WP_Error( 'cc_db_error', 'Module could not be saved.' );
			}
			CC_Audit::log( 'content.module_updated', 'module', $module_id );
			return $module_id;
		}
		if ( null === CC_Batch_Repository::find( $batch_id ) ) {
			return new WP_Error( 'cc_not_found', 'Batch not found.' );
		}
		$created = CC_Content_Repository::create_module( $batch_id, $title );
		if ( 0 === $created ) {
			return new WP_Error( 'cc_db_error', 'Module could not be saved.' );
		}
		CC_Audit::log( 'content.module_created', 'module', $created, array( 'batch_id' => $batch_id ) );
		return $created;
	}

	/** @return true|WP_Error */
	public static function delete_module( int $module_id, int $actor ) {
		if ( ! user_can( $actor, self::CAP ) ) {
			return self::forbidden();
		}
		if ( null === CC_Content_Repository::find_module( $module_id ) ) {
			return new WP_Error( 'cc_not_found', 'Module not found.' );
		}
		if ( ! CC_Content_Repository::delete_module( $module_id ) ) {
			return new WP_Error( 'cc_db_error', 'Module could not be deleted.' );
		}
		CC_Audit::log( 'content.module_deleted', 'module', $module_id );
		return true;
	}

	/** @return true|WP_Error */
	public static function move_module( int $module_id, int $direction, int $actor ) {
		if ( ! user_can( $actor, self::CAP ) ) {
			return self::forbidden();
		}
		if ( ! CC_Content_Repository::move_module( $module_id, $direction ) ) {
			return new WP_Error( 'cc_no_change', 'Module cannot be moved that way.' );
		}
		CC_Audit::log( 'content.module_moved', 'module', $module_id, array( 'direction' => $direction < 0 ? 'up' : 'down' ) );
		return true;
	}

	/**
	 * @param array{title?:string,scheduled_at?:string,remove_attachment?:bool} $input scheduled_at is a site-timezone datetime ('' clears it).
	 * @param array|null $file One entry of $_FILES; null or UPLOAD_ERR_NO_FILE leaves the PDF as it is.
	 * @return int|WP_Error Lesson id. $lesson_id 0 creates a lesson in $module_id.
	 */
	public static function save_lesson( int $module_id, int $lesson_id, array $input, ?array $file, int $actor ) {
		if ( ! user_can( $actor, self::CAP ) ) {
			return self::forbidden();
		}
		$existing = $lesson_id > 0 ? CC_Content_Repository::find_lesson( $lesson_id ) : null;
		if ( $lesson_id > 0 && null === $existing ) {
			return new WP_Error( 'cc_not_found', 'Lesson not found.' );
		}
		if ( null === $existing && null === CC_Content_Repository::find_module( $module_id ) ) {
			return new WP_Error( 'cc_not_found', 'Module not found.' );
		}
		$title = trim( (string) ( $input['title'] ?? '' ) );
		if ( '' === $title ) {
			return new WP_Error( 'cc_invalid_title', 'A lesson needs a title.' );
		}
		$scheduled = self::site_time_to_utc( (string) ( $input['scheduled_at'] ?? '' ) );
		if ( is_wp_error( $scheduled ) ) {
			return $scheduled;
		}

		$fields   = array( 'title' => $title, 'scheduled_at' => $scheduled );
		$uploaded = null;
		if ( null !== $file && UPLOAD_ERR_NO_FILE !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
			$uploaded = CC_Resource_Store::store( $file );
			if ( is_wp_error( $uploaded ) ) {
				return $uploaded;
			}
			$fields['attachment_path'] = $uploaded['path'];
			$fields['attachment_name'] = $uploaded['name'];
		} elseif ( ! empty( $input['remove_attachment'] ) ) {
			$fields['attachment_path'] = null;
			$fields['attachment_name'] = null;
		}

		if ( null === $existing ) {
			$saved = CC_Content_Repository::create_lesson( $module_id, $fields );
			$id    = $saved;
		} else {
			$saved = CC_Content_Repository::update_lesson( $lesson_id, $fields );
			$id    = $lesson_id;
		}
		if ( ! $saved ) {
			if ( null !== $uploaded ) {
				CC_Resource_Store::delete( $uploaded['path'] );
			}
			return new WP_Error( 'cc_db_error', 'Lesson could not be saved.' );
		}
		CC_Audit::log( null === $existing ? 'content.lesson_created' : 'content.lesson_updated', 'lesson', $id, array( 'attachment_changed' => array_key_exists( 'attachment_path', $fields ) ) );
		return $id;
	}

	/** @return true|WP_Error */
	public static function delete_lesson( int $lesson_id, int $actor ) {
		if ( ! user_can( $actor, self::CAP ) ) {
			return self::forbidden();
		}
		if ( ! CC_Content_Repository::delete_lesson( $lesson_id ) ) {
			return new WP_Error( 'cc_not_found', 'Lesson not found.' );
		}
		CC_Audit::log( 'content.lesson_deleted', 'lesson', $lesson_id );
		return true;
	}

	/** @return true|WP_Error */
	public static function move_lesson( int $lesson_id, int $direction, int $actor ) {
		if ( ! user_can( $actor, self::CAP ) ) {
			return self::forbidden();
		}
		if ( ! CC_Content_Repository::move_lesson( $lesson_id, $direction ) ) {
			return new WP_Error( 'cc_no_change', 'Lesson cannot be moved that way.' );
		}
		CC_Audit::log( 'content.lesson_moved', 'lesson', $lesson_id, array( 'direction' => $direction < 0 ? 'up' : 'down' ) );
		return true;
	}

	/** @return string|null|WP_Error UTC `Y-m-d H:i:s`, null when empty. */
	public static function site_time_to_utc( string $local ) {
		$local = trim( $local );
		if ( '' === $local ) {
			return null;
		}
		$parsed = DateTimeImmutable::createFromFormat( self::FORM_TIME, $local, wp_timezone() );
		if ( false === $parsed || $parsed->format( self::FORM_TIME ) !== $local ) {
			return new WP_Error( 'cc_invalid_time', 'Enter the lesson date and time as YYYY-MM-DD HH:MM.' );
		}
		return $parsed->setTimezone( new DateTimeZone( 'UTC' ) )->format( self::DB_TIME );
	}

	public static function utc_to_site_time( ?string $utc, string $format = self::FORM_TIME ): string {
		if ( null === $utc || '' === $utc ) {
			return '';
		}
		return ( new DateTimeImmutable( $utc, new DateTimeZone( 'UTC' ) ) )->setTimezone( wp_timezone() )->format( $format );
	}

	/* ---------------------------------------------------------------- handlers */

	public static function handle_module_save(): void {
		$module_id = self::posted( 'module_id' );
		$batch_id  = self::posted( 'batch_id' );
		self::authorize( 'cc_content_module_save_' . $module_id . '_' . $batch_id );
		$result = self::save_module( $batch_id, $module_id, sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) ), get_current_user_id() ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in authorize().
		self::redirect( $batch_id, is_wp_error( $result ) ? 'failed' : 'module_saved' );
	}

	public static function handle_module_delete(): void {
		$module_id = self::posted( 'module_id' );
		self::authorize( 'cc_content_module_delete_' . $module_id );
		$batch_id = (int) ( CC_Content_Repository::find_module( $module_id )['batch_id'] ?? 0 );
		self::redirect( $batch_id, is_wp_error( self::delete_module( $module_id, get_current_user_id() ) ) ? 'failed' : 'module_deleted' );
	}

	public static function handle_module_move(): void {
		$module_id = self::posted( 'module_id' );
		self::authorize( 'cc_content_module_move_' . $module_id );
		$batch_id = (int) ( CC_Content_Repository::find_module( $module_id )['batch_id'] ?? 0 );
		self::redirect( $batch_id, is_wp_error( self::move_module( $module_id, self::posted_direction(), get_current_user_id() ) ) ? 'failed' : 'moved' );
	}

	/**
	 * A body over post_max_size empties $_POST and $_FILES, so the action and nonce are gone and admin-post.php would
	 * end on a blank page. The nonce cannot be checked here; the only effect is a redirect for a signed-in content manager.
	 */
	public static function catch_oversized_post(): void {
		if ( 'admin-post.php' !== basename( (string) ( $_SERVER['SCRIPT_NAME'] ?? '' ) ) || 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			return;
		}
		if ( array() !== $_POST || (int) ( $_SERVER['CONTENT_LENGTH'] ?? 0 ) <= 0 || ! current_user_can( self::CAP ) ) {
			return;
		}
		$referer = (string) wp_get_referer();
		parse_str( (string) wp_parse_url( $referer, PHP_URL_QUERY ), $query );
		$limit = CC_Resource_Store::format_limit( CC_Resource_Store::max_upload_bytes() );
		self::redirect( absint( $query['batch'] ?? 0 ), 'failed', sprintf( 'The file is larger than the server allows (max %s).', $limit ) );
	}

	public static function handle_lesson_save(): void {
		$module_id = self::posted( 'module_id' );
		$lesson_id = self::posted( 'lesson_id' );
		self::authorize( 'cc_content_lesson_save_' . $lesson_id . '_' . $module_id );
		$input  = array(
			'title'             => sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in authorize().
			'scheduled_at'      => sanitize_text_field( wp_unslash( $_POST['scheduled_at'] ?? '' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'remove_attachment' => ! empty( $_POST['remove_attachment'] ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
		);
		$result = self::save_lesson( $module_id, $lesson_id, $input, isset( $_FILES['attachment'] ) ? (array) $_FILES['attachment'] : null, get_current_user_id() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated by CC_Resource_Store.
		self::redirect( (int) ( CC_Content_Repository::find_module( $module_id )['batch_id'] ?? 0 ), is_wp_error( $result ) ? 'failed' : 'lesson_saved', is_wp_error( $result ) ? $result->get_error_message() : '' );
	}

	public static function handle_lesson_delete(): void {
		$lesson_id = self::posted( 'lesson_id' );
		self::authorize( 'cc_content_lesson_delete_' . $lesson_id );
		$batch_id = (int) ( CC_Content_Repository::find_lesson( $lesson_id )['batch_id'] ?? 0 );
		self::redirect( $batch_id, is_wp_error( self::delete_lesson( $lesson_id, get_current_user_id() ) ) ? 'failed' : 'lesson_deleted' );
	}

	public static function handle_lesson_move(): void {
		$lesson_id = self::posted( 'lesson_id' );
		self::authorize( 'cc_content_lesson_move_' . $lesson_id );
		$batch_id = (int) ( CC_Content_Repository::find_lesson( $lesson_id )['batch_id'] ?? 0 );
		self::redirect( $batch_id, is_wp_error( self::move_lesson( $lesson_id, self::posted_direction(), get_current_user_id() ) ) ? 'failed' : 'moved' );
	}

	private static function authorize( string $nonce_action ): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'coaching-platform' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $nonce_action );
	}

	private static function posted( string $key ): int {
		return absint( wp_unslash( $_POST[ $key ] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in authorize(); read before it only to build the nonce action.
	}

	private static function posted_direction(): int {
		return 'up' === sanitize_key( wp_unslash( $_POST['direction'] ?? '' ) ) ? -1 : 1; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in authorize().
	}

	private static function redirect( int $batch_id, string $notice, string $detail = '' ): void {
		$args = array( 'page' => self::PAGE_SLUG, 'cc_notice' => $notice );
		if ( $batch_id > 0 ) {
			$args['batch'] = $batch_id;
		}
		if ( '' !== $detail ) {
			$args['cc_detail'] = rawurlencode( mb_substr( $detail, 0, 120 ) );
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	private static function forbidden(): WP_Error {
		return new WP_Error( 'cc_forbidden', 'You are not allowed to manage course content.' );
	}

	/* ---------------------------------------------------------------- rendering */

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'coaching-platform' ), '', array( 'response' => 403 ) );
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'Course content', 'coaching-platform' ) . '</h1>';
		self::render_notice();
		$batch_id = isset( $_GET['batch'] ) ? absint( $_GET['batch'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification -- read-only view.
		self::render_picker( $batch_id );
		$batch = $batch_id > 0 ? CC_Batch_Repository::find( $batch_id ) : null;
		if ( null !== $batch ) {
			self::render_batch( $batch );
		}
		echo '</div>';
		self::print_size_check_script();
	}

	/**
	 * Refuses an oversized PDF before upload. The form is not submitted, so the refusal must be shown as an in-page
	 * error notice (same place and wording as the server-side refusal), not a dismissible alert() that leaves no trace.
	 */
	private static function print_size_check_script(): void {
		?>
<script>
document.querySelectorAll('input[type=file][data-max-bytes]').forEach(function (input) {
	input.form.addEventListener('submit', function (event) {
		var file = input.files && input.files[0];
		if (!file || file.size <= Number(input.dataset.maxBytes)) {
			return;
		}
		event.preventDefault();
		var old = document.getElementById('cc-size-notice');
		if (old) {
			old.remove();
		}
		var notice = document.createElement('div');
		notice.id = 'cc-size-notice';
		notice.className = 'notice notice-error';
		notice.setAttribute('role', 'alert');
		var para = document.createElement('p');
		para.textContent = 'File too large (max ' + input.dataset.maxLabel + '). "' + file.name + '" was not uploaded; choose a smaller PDF.';
		notice.appendChild(para);
		var heading = document.querySelector('.wrap > h1');
		heading.parentNode.insertBefore(notice, heading.nextSibling);
		notice.scrollIntoView({ block: 'center' });
	});
});
</script>
		<?php
	}

	private static function render_notice(): void {
		$code = isset( $_GET['cc_notice'] ) ? sanitize_key( wp_unslash( $_GET['cc_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- display only, whitelisted.
		if ( ! isset( self::NOTICES[ $code ] ) ) {
			return;
		}
		$detail = isset( $_GET['cc_detail'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['cc_detail'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- display only, escaped.
		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( self::NOTICES[ $code ][0] ), esc_html( self::NOTICES[ $code ][1] . ( 'failed' === $code && '' !== $detail ? ' ' . $detail : '' ) ) );
	}

	private static function render_picker( int $selected ): void {
		echo '<form method="get"><input type="hidden" name="page" value="' . esc_attr( self::PAGE_SLUG ) . '" />';
		echo '<label for="cc-content-batch">' . esc_html__( 'Course and batch', 'coaching-platform' ) . '</label> <select id="cc-content-batch" name="batch"><option value="">' . esc_html__( 'Choose a batch', 'coaching-platform' ) . '</option>';
		foreach ( get_posts( array( 'post_type' => 'cc_course', 'post_status' => array( 'publish', 'draft', 'private' ), 'numberposts' => 200, 'orderby' => 'title', 'order' => 'ASC' ) ) as $course ) {
			$batches = CC_Batch_Repository::for_course( (int) $course->ID );
			if ( ! $batches ) {
				continue;
			}
			echo '<optgroup label="' . esc_attr( $course->post_title ) . '">';
			foreach ( $batches as $batch ) {
				printf( '<option value="%d"%s>%s</option>', (int) $batch['id'], selected( $selected, (int) $batch['id'], false ), esc_html( (string) $batch['name'] ) );
			}
			echo '</optgroup>';
		}
		echo '</select> <button type="submit" class="button">' . esc_html__( 'Open', 'coaching-platform' ) . '</button></form>';
	}

	/** @param array<string,mixed> $batch */
	private static function render_batch( array $batch ): void {
		$batch_id    = (int) $batch['id'];
		$edit_module = isset( $_GET['edit_module'] ) ? absint( $_GET['edit_module'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification -- read-only view.
		$edit_lesson = isset( $_GET['edit_lesson'] ) ? absint( $_GET['edit_lesson'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$modules     = CC_Content_Repository::modules_for_batch( $batch_id );
		$last_module = count( $modules ) - 1;
		/* translators: %s: timezone name. */
		echo '<h2>' . esc_html( (string) $batch['name'] ) . '</h2><p class="description">' . esc_html( sprintf( 'Lesson times are in %s.', wp_timezone_string() ) ) . '</p>';

		foreach ( $modules as $m_index => $module ) {
			$module_id = $module['id'];
			echo '<div class="postbox" style="padding:12px;margin-top:16px">';
			if ( $edit_module === $module_id ) {
				self::module_form( $batch_id, $module_id, $module['title'] );
			} else {
				echo '<h3 style="margin-top:0">' . esc_html( $module['title'] ) . '</h3>';
				self::move_buttons( 'cc_content_module_move', 'cc_content_module_move_' . $module_id, 'module_id', $module_id, 0 === $m_index, $m_index === $last_module );
				echo ' <a class="button" href="' . esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'batch' => $batch_id, 'edit_module' => $module_id ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Rename', 'coaching-platform' ) . '</a> ';
				self::post_form( 'cc_content_module_delete', 'cc_content_module_delete_' . $module_id, array( 'module_id' => $module_id ), __( 'Delete module', 'coaching-platform' ), __( 'Delete this module and all its lessons?', 'coaching-platform' ) );
			}
			self::render_lessons( $batch_id, $module, $edit_lesson );
			echo '</div>';
		}

		echo '<h3>' . esc_html__( 'Add module', 'coaching-platform' ) . '</h3>';
		self::module_form( $batch_id, 0, '' );
	}

	private static function module_form( int $batch_id, int $module_id, string $title ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		self::hidden( array( 'action' => 'cc_content_module_save', 'module_id' => $module_id, 'batch_id' => $batch_id ) );
		wp_nonce_field( 'cc_content_module_save_' . $module_id . '_' . $batch_id );
		echo '<label class="screen-reader-text" for="cc-module-title-' . (int) $module_id . '">' . esc_html__( 'Module title', 'coaching-platform' ) . '</label>';
		echo '<input type="text" id="cc-module-title-' . (int) $module_id . '" name="title" maxlength="' . (int) CC_Content_Repository::TITLE_MAX . '" required class="regular-text" value="' . esc_attr( $title ) . '" /> ';
		echo '<button type="submit" class="button button-primary">' . esc_html( $module_id > 0 ? __( 'Save module', 'coaching-platform' ) : __( 'Add module', 'coaching-platform' ) ) . '</button></form>';
	}

	/** @param array<string,mixed> $module */
	private static function render_lessons( int $batch_id, array $module, int $edit_lesson ): void {
		$module_id = (int) $module['id'];
		$lessons   = $module['lessons'];
		$last      = count( $lessons ) - 1;
		echo '<div class="cc-table-wrap"><table class="widefat striped cc-lessons" style="margin-top:8px"><thead><tr><th>' . esc_html__( 'Lesson', 'coaching-platform' ) . '</th><th>' . esc_html__( 'Scheduled', 'coaching-platform' ) . '</th><th>' . esc_html__( 'PDF', 'coaching-platform' ) . '</th><th>' . esc_html__( 'Actions', 'coaching-platform' ) . '</th></tr></thead><tbody>';
		foreach ( $lessons as $index => $lesson ) {
			$lesson_id = $lesson['id'];
			if ( $edit_lesson === $lesson_id ) {
				echo '<tr><td colspan="4">';
				self::lesson_form( $module_id, $lesson );
				echo '</td></tr>';
				continue;
			}
			printf( '<tr><td>%s</td><td>%s</td><td>%s</td><td>', esc_html( $lesson['title'] ), esc_html( self::utc_to_site_time( $lesson['scheduled_at'], 'Y-m-d H:i' ) ), esc_html( $lesson['has_attachment'] ? $lesson['attachment_name'] : '' ) );
			self::move_buttons( 'cc_content_lesson_move', 'cc_content_lesson_move_' . $lesson_id, 'lesson_id', $lesson_id, 0 === $index, $index === $last );
			echo ' <a class="button" href="' . esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'batch' => $batch_id, 'edit_lesson' => $lesson_id ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Edit', 'coaching-platform' ) . '</a> ';
			self::post_form( 'cc_content_lesson_delete', 'cc_content_lesson_delete_' . $lesson_id, array( 'lesson_id' => $lesson_id ), __( 'Delete', 'coaching-platform' ), __( 'Delete this lesson and its PDF?', 'coaching-platform' ) );
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';
		if ( 0 === $edit_lesson ) {
			echo '<h4>' . esc_html__( 'Add lesson', 'coaching-platform' ) . '</h4>';
			self::lesson_form( $module_id, null );
		}
	}

	/** @param array<string,mixed>|null $lesson */
	private static function lesson_form( int $module_id, ?array $lesson ): void {
		$lesson_id = null === $lesson ? 0 : (int) $lesson['id'];
		$key       = $module_id . '-' . $lesson_id;
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		self::hidden( array( 'action' => 'cc_content_lesson_save', 'module_id' => $module_id, 'lesson_id' => $lesson_id ) );
		wp_nonce_field( 'cc_content_lesson_save_' . $lesson_id . '_' . $module_id );
		echo '<p><label for="cc-lesson-title-' . esc_attr( $key ) . '">' . esc_html__( 'Title', 'coaching-platform' ) . '</label> ';
		echo '<input type="text" id="cc-lesson-title-' . esc_attr( $key ) . '" name="title" required maxlength="' . (int) CC_Content_Repository::TITLE_MAX . '" class="regular-text" value="' . esc_attr( (string) ( $lesson['title'] ?? '' ) ) . '" /> ';
		echo '<label for="cc-lesson-time-' . esc_attr( $key ) . '">' . esc_html__( 'Date and time', 'coaching-platform' ) . '</label> ';
		echo '<input type="datetime-local" id="cc-lesson-time-' . esc_attr( $key ) . '" name="scheduled_at" value="' . esc_attr( self::utc_to_site_time( $lesson['scheduled_at'] ?? null ) ) . '" /></p>';
		echo '<p><label for="cc-lesson-pdf-' . esc_attr( $key ) . '">' . esc_html( null !== $lesson && $lesson['has_attachment'] ? __( 'Replace PDF', 'coaching-platform' ) : __( 'PDF notes (max 10 MB)', 'coaching-platform' ) ) . '</label> ';
		$limit = CC_Resource_Store::max_upload_bytes();
		echo '<input type="file" id="cc-lesson-pdf-' . esc_attr( $key ) . '" name="attachment" accept="application/pdf,.pdf" data-max-bytes="' . (int) $limit . '" data-max-label="' . esc_attr( CC_Resource_Store::format_limit( $limit ) ) . '" />';
		if ( null !== $lesson && $lesson['has_attachment'] ) {
			echo ' <label><input type="checkbox" name="remove_attachment" value="1" /> ' . esc_html( sprintf( 'Remove %s', $lesson['attachment_name'] ) ) . '</label>';
		}
		echo '</p><button type="submit" class="button button-primary">' . esc_html( null === $lesson ? __( 'Add lesson', 'coaching-platform' ) : __( 'Save lesson', 'coaching-platform' ) ) . '</button></form>';
	}

	private static function move_buttons( string $action, string $nonce_action, string $field, int $id, bool $is_first, bool $is_last ): void {
		foreach ( array( 'up' => array( __( 'Move up', 'coaching-platform' ), $is_first ), 'down' => array( __( 'Move down', 'coaching-platform' ), $is_last ) ) as $direction => $spec ) {
			if ( $spec[1] ) {
				continue;
			}
			self::post_form( $action, $nonce_action, array( $field => $id, 'direction' => $direction ), $spec[0] );
		}
	}

	/** @param array<string,int|string> $fields */
	private static function post_form( string $action, string $nonce_action, array $fields, string $label, string $confirm = '' ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline"' . ( '' !== $confirm ? ' onsubmit="return confirm(' . esc_attr( wp_json_encode( $confirm ) ) . ');"' : '' ) . '>';
		self::hidden( array_merge( array( 'action' => $action ), $fields ) );
		wp_nonce_field( $nonce_action );
		echo '<button type="submit" class="button">' . esc_html( $label ) . '</button></form>';
	}

	/** @param array<string,int|string> $fields */
	private static function hidden( array $fields ): void {
		foreach ( $fields as $name => $value ) {
			echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '" />';
		}
	}
}
