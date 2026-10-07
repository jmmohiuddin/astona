<?php
defined( 'ABSPATH' ) || exit;

/**
 * Admin screen "Notices" (WF-024): list with status tabs, editor, mandatory audience preview before publishing, archive.
 * Every handler checks the capability and the nonce itself; menu visibility is never the gate.
 */
final class CC_Admin_Notices {

	const PAGE          = 'cc-notices';
	const CAP           = 'cc_manage_notices';
	const PER_PAGE      = 20;
	const MAX_TITLE     = 190;
	const PREVIEW_TTL   = 900;
	const PREVIEW_KEY   = 'cc_notice_preview_';
	const UPDATE_KEY    = 'cc_notice_update_';
	const TABS          = array(
		'draft'     => array( 'Draft', 'draft' ),
		'scheduled' => array( 'Scheduled', 'future' ),
		'published' => array( 'Published', 'publish' ),
		'archived'  => array( 'Archived', 'any' ),
	);
	const NOTICES       = array(
		'saved'            => array( 'success', 'Notice saved.' ),
		'published'        => array( 'success', 'Notice published.' ),
		'scheduled'        => array( 'success', 'Notice scheduled.' ),
		'archived'         => array( 'success', 'Notice archived.' ),
		'unarchived'       => array( 'success', 'Notice restored.' ),
		'title_needed'     => array( 'error', 'A title is required.' ),
		'audience_needed'  => array( 'error', 'Choose at least one valid batch, or make the notice public.' ),
		'not_found'        => array( 'error', 'Notice not found.' ),
		'preview_required' => array( 'error', 'The audience preview expired or the notice changed. Review the preview again before publishing.' ),
		'already_live'     => array( 'error', 'This notice is already published.' ),
		'review_needed'    => array( 'error', 'Changing the audience or the critical flag of a live notice needs a review first.' ),
		'updated'          => array( 'success', 'Notice updated. SMS is not resent for a notice that is already published.' ),
		'failed'           => array( 'error', 'The notice could not be saved.' ),
	);

	public static function init(): void {
		add_action( 'admin_post_cc_notice_save', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_cc_notice_publish', array( __CLASS__, 'handle_publish' ) );
		add_action( 'admin_post_cc_notice_update', array( __CLASS__, 'handle_update' ) );
		add_action( 'admin_post_cc_notice_archive', array( __CLASS__, 'handle_archive' ) );
	}

	/* ------------------------------------------------------------ operations */

	/** Converts a "Y-m-d\TH:i" value typed in the site timezone to UTC "Y-m-d H:i:s"; null when empty or invalid. */
	public static function schedule_to_gmt( string $local ): ?string {
		$local = trim( $local );
		if ( '' === $local ) {
			return null;
		}
		$date = date_create_immutable( $local, wp_timezone() );
		return false === $date ? null : $date->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	private static function find( int $id ): ?WP_Post {
		$post = $id > 0 ? get_post( $id ) : null;
		return $post instanceof WP_Post && CC_Notice_Service::POST_TYPE === $post->post_type ? $post : null;
	}

	/** @return int[] The given ids that are real batches. */
	private static function existing_batches( array $ids ): array {
		return array_values( array_intersect( array_map( 'absint', $ids ), array_keys( CC_Admin_Applications::batch_options() ) ) );
	}

	/**
	 * Batch ids a new notice form may be pre-ticked with (query arg cc_prefill_batches[], e.g. from the Students roster).
	 * Only digit strings that are real batches pass. This fills the form only: publishing still needs the audience preview.
	 *
	 * @param mixed $raw @return int[]
	 */
	public static function prefill_batches( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$digits = array_filter( array_slice( $raw, 0, 200 ), static fn( $v ): bool => is_string( $v ) && 1 === preg_match( '/^\d{1,10}$/', $v ) );
		return self::existing_batches( array_values( $digits ) );
	}

	private static function is_live( WP_Post $post ): bool {
		return in_array( $post->post_status, array( 'publish', 'future' ), true );
	}

	/**
	 * Validates and normalises editor input. Unslashed input.
	 *
	 * @return array{title:string,content:string,batch_ids:int[],critical:bool}|WP_Error
	 */
	private static function normalise( array $in ) {
		$title = mb_substr( trim( sanitize_text_field( (string) ( $in['title'] ?? '' ) ) ), 0, self::MAX_TITLE );
		if ( '' === $title ) {
			return new WP_Error( 'title_needed', 'A title is required.' );
		}
		$batch_ids = 'batches' === ( $in['audience'] ?? 'public' ) ? self::existing_batches( (array) ( $in['batch_ids'] ?? array() ) ) : array();
		if ( 'batches' === ( $in['audience'] ?? 'public' ) && ! $batch_ids ) {
			return new WP_Error( 'audience_needed', 'Choose at least one valid batch, or make the notice public.' );
		}
		sort( $batch_ids );
		return array(
			'title'     => $title,
			'content'   => wp_kses_post( (string) ( $in['content'] ?? '' ) ),
			'batch_ids' => array_values( array_unique( $batch_ids ) ),
			'critical'  => ! empty( $in['critical'] ),
		);
	}

	/**
	 * Creates a draft or updates an existing notice without changing its status. Unslashed input. A published or scheduled
	 * notice refuses any audience or critical-flag change here; that goes through preview_update() and apply_update().
	 *
	 * @param array{id?:int,title?:string,content?:string,audience?:string,batch_ids?:array,critical?:bool} $in
	 * @return int|WP_Error Notice id.
	 */
	public static function save( array $in, int $actor ) {
		$norm = self::normalise( $in );
		if ( is_wp_error( $norm ) ) {
			return $norm;
		}
		$id = absint( $in['id'] ?? 0 );
		if ( $id > 0 ) {
			$post = self::find( $id );
			if ( ! $post ) {
				return new WP_Error( 'not_found', 'Notice not found.' );
			}
			if ( self::is_live( $post ) && self::changes_reach( $id, $norm ) ) {
				return new WP_Error( 'review_needed', 'Changing the audience or the critical flag of a live notice needs a review first.' );
			}
		}
		return self::persist( $id, $norm, $actor );
	}

	/** @param array{batch_ids:int[],critical:bool} $norm */
	private static function changes_reach( int $id, array $norm ): bool {
		return CC_Notice_Service::audience( $id )['batch_ids'] !== $norm['batch_ids'] || CC_Notice_Service::is_critical( $id ) !== $norm['critical'];
	}

	/**
	 * Writes normalised input. Never changes the post status, so it can never trigger the publish SMS.
	 *
	 * @param array{title:string,content:string,batch_ids:int[],critical:bool} $norm
	 * @return int|WP_Error
	 */
	private static function persist( int $id, array $norm, int $actor ) {
		$postarr = array(
			'post_type'    => CC_Notice_Service::POST_TYPE,
			'post_title'   => $norm['title'],
			'post_content' => $norm['content'],
		);
		if ( $id > 0 ) {
			$postarr['ID'] = $id;
			$saved         = wp_update_post( wp_slash( $postarr ), true );
		} else {
			$postarr['post_status'] = 'draft';
			$postarr['post_author'] = $actor;
			$saved                  = wp_insert_post( wp_slash( $postarr ), true );
		}
		if ( is_wp_error( $saved ) || 0 === (int) $saved ) {
			return new WP_Error( 'failed', 'The notice could not be saved.' );
		}
		$saved = (int) $saved;
		CC_Notice_Service::set_audience( $saved, $norm['batch_ids'] );
		if ( $norm['critical'] ) {
			update_post_meta( $saved, CC_Notice_Service::META_CRITICAL, '1' );
		} else {
			delete_post_meta( $saved, CC_Notice_Service::META_CRITICAL );
		}
		CC_Audit::log( $id > 0 ? 'notice.update' : 'notice.create', 'notice', $saved, array( 'audience' => $norm['batch_ids'] ), 'saved' );
		return $saved;
	}

	/**
	 * Review step for an audience or critical-flag change on a published/scheduled notice. Nothing is saved; the proposal is
	 * kept server-side under a single-use token that apply_update() demands.
	 *
	 * @param array<string,mixed> $in Same shape as save() input.
	 * @return array{token:string,students:int,new_students:int,sms:int,message:string}|WP_Error
	 */
	public static function preview_update( array $in, int $actor ) {
		$norm = self::normalise( $in );
		$id   = absint( $in['id'] ?? 0 );
		$post = self::find( $id );
		if ( is_wp_error( $norm ) ) {
			return $norm;
		}
		if ( ! $post || ! self::is_live( $post ) ) {
			return new WP_Error( 'not_found', 'Notice not found.' );
		}
		$old_ids  = array_column( CC_Notice_Service::recipients( $id ), 'user_id' );
		$new_rows = CC_Notice_Service::recipients_for_batches( $norm['batch_ids'] );
		$students = count( $new_rows );
		$added    = count( array_diff( array_column( $new_rows, 'user_id' ), $old_ids ) );
		$options  = CC_Admin_Applications::batch_options();
		$names    = array_map( static fn( $batch_id ) => (string) ( $options[ $batch_id ] ?? '#' . $batch_id ), $norm['batch_ids'] );
		$future   = 'future' === $post->post_status;
		$sms      = $future && $norm['critical'] && $norm['batch_ids'] ? $students : 0;
		$message  = $norm['batch_ids']
			? sprintf( 'After this change the notice will be visible to %d students in %s (%d newly added).', $students, implode( ', ', $names ), $added )
			: 'After this change the notice will be visible to everyone (public notice).';
		$message .= $future
			? sprintf( ' It is scheduled, so SMS goes to %d phones when it publishes.', $sms )
			: ' It is already published: SMS is not resent, so newly added students are not texted.';
		$token = bin2hex( random_bytes( 16 ) );
		set_transient( self::UPDATE_KEY . $actor . '_' . $id, array( $token, self::update_fingerprint( $post, $norm ), $norm, $message ), self::PREVIEW_TTL );
		return array( 'token' => $token, 'students' => $students, 'new_students' => $added, 'sms' => $sms, 'message' => $message );
	}

	/** The pending review for the preview page: its token and message, or null when it expired. @return array{token:string,message:string}|null */
	public static function pending_update( int $id, int $actor ): ?array {
		$stored = get_transient( self::UPDATE_KEY . $actor . '_' . $id );
		return is_array( $stored ) ? array( 'token' => (string) $stored[0], 'message' => (string) $stored[3] ) : null;
	}

	/**
	 * Saves a reviewed change to a published/scheduled notice. Needs the token from preview_update() for this very proposal
	 * and the same stored notice; never changes the status and never sends SMS.
	 *
	 * @return int|WP_Error Notice id.
	 */
	public static function apply_update( int $id, string $token, int $actor ) {
		$post = self::find( $id );
		if ( ! $post || ! self::is_live( $post ) ) {
			return new WP_Error( 'not_found', 'Notice not found.' );
		}
		$key    = self::UPDATE_KEY . $actor . '_' . $id;
		$stored = get_transient( $key );
		if ( ! is_array( $stored ) || '' === $token || ! hash_equals( (string) $stored[0], $token ) || ! hash_equals( (string) $stored[1], self::update_fingerprint( $post, $stored[2] ) ) ) {
			return new WP_Error( 'preview_required', 'The audience preview expired or the notice changed.' );
		}
		delete_transient( $key );
		return self::persist( $id, $stored[2], $actor );
	}

	/** Covers the proposal (title, content, audience, critical) and the stored notice it would replace, schedule included. */
	private static function update_fingerprint( WP_Post $post, array $norm ): string {
		return hash( 'sha256', (string) wp_json_encode( array( $norm, self::fingerprint( $post, $post->post_date_gmt ) ) ) );
	}

	private static function fingerprint( WP_Post $post, ?string $schedule_gmt ): string {
		return hash(
			'sha256',
			(string) wp_json_encode(
				array(
					$post->ID,
					$post->post_title,
					$post->post_content,
					CC_Notice_Service::audience( (int) $post->ID )['batch_ids'],
					CC_Notice_Service::is_critical( (int) $post->ID ),
					$schedule_gmt,
				)
			)
		);
	}

	/**
	 * Computes who will see the notice and issues the single-use token publish() demands.
	 *
	 * @return array{token:string,public:bool,students:int,sms:int,batches:string[],message:string}|WP_Error
	 */
	public static function preview( int $id, ?string $schedule_gmt, int $actor ) {
		$post = self::find( $id );
		if ( ! $post ) {
			return new WP_Error( 'not_found', 'Notice not found.' );
		}
		$audience   = CC_Notice_Service::audience( $id );
		$students   = count( CC_Notice_Service::recipients( $id ) );
		$sms        = CC_Notice_Service::is_critical( $id ) ? $students : 0;
		$options    = CC_Admin_Applications::batch_options();
		$batches    = array_values( array_map( static fn( $batch_id ) => (string) ( $options[ $batch_id ] ?? '#' . $batch_id ), $audience['batch_ids'] ) );
		$token      = bin2hex( random_bytes( 16 ) );
		set_transient( self::PREVIEW_KEY . $actor . '_' . $id, array( $token, self::fingerprint( $post, $schedule_gmt ) ), self::PREVIEW_TTL );
		$message = $audience['public']
			? 'Will be visible to everyone (public notice); no SMS.'
			: sprintf( 'Will be visible to %d students in %s; SMS to %d phones.', $students, implode( ', ', $batches ), $sms );
		return array( 'token' => $token, 'public' => $audience['public'], 'students' => $students, 'sms' => $sms, 'batches' => $batches, 'message' => $message );
	}

	/**
	 * Publishes now, or schedules when $schedule_gmt is in the future. Requires a fresh preview token for the same content.
	 *
	 * @return array{status:string,students:int,sms:int}|WP_Error
	 */
	public static function publish( int $id, string $token, ?string $schedule_gmt, int $actor ) {
		$post = self::find( $id );
		if ( ! $post ) {
			return new WP_Error( 'not_found', 'Notice not found.' );
		}
		if ( 'publish' === $post->post_status ) {
			return new WP_Error( 'already_live', 'This notice is already published.' );
		}
		$key    = self::PREVIEW_KEY . $actor . '_' . $id;
		$stored = get_transient( $key );
		if ( ! is_array( $stored ) || '' === $token || ! hash_equals( (string) $stored[0], $token ) || ! hash_equals( (string) $stored[1], self::fingerprint( $post, $schedule_gmt ) ) ) {
			return new WP_Error( 'preview_required', 'The audience preview expired or the notice changed.' );
		}
		delete_transient( $key );

		$future = null !== $schedule_gmt && strtotime( $schedule_gmt . ' UTC' ) > time();
		$gmt    = $future ? $schedule_gmt : gmdate( 'Y-m-d H:i:s' );
		$saved  = wp_update_post(
			array(
				'ID'            => $id,
				'post_status'   => $future ? 'future' : 'publish',
				'post_date'     => get_date_from_gmt( $gmt ),
				'post_date_gmt' => $gmt,
				'edit_date'     => true,
			),
			true
		);
		if ( is_wp_error( $saved ) || 0 === (int) $saved ) {
			return new WP_Error( 'failed', 'The notice could not be published.' );
		}
		$status = $future ? 'future' : 'publish';
		CC_Audit::log( 'notice.publish', 'notice', $id, array( 'status' => array( $post->post_status, $status ) ), $status );
		return array(
			'status'   => $status,
			'students' => count( CC_Notice_Service::recipients( $id ) ),
			'sms'      => (int) get_post_meta( $id, CC_Notice_Service::META_SMS_SENT, true ),
		);
	}

	/** @return true|WP_Error */
	public static function archive( int $id, bool $archived, int $actor ) {
		if ( ! self::find( $id ) ) {
			return new WP_Error( 'not_found', 'Notice not found.' );
		}
		CC_Notice_Service::set_archived( $id, $archived );
		CC_Audit::log( $archived ? 'notice.archive' : 'notice.unarchive', 'notice', $id, array(), $archived ? 'archived' : 'unarchived' );
		return true;
	}

	/** @return array{items:array<int,array<string,mixed>>,total:int} */
	public static function list_notices( string $tab, int $paged = 1 ): array {
		$tab   = isset( self::TABS[ $tab ] ) ? $tab : 'draft';
		$args  = array(
			'post_type'      => CC_Notice_Service::POST_TYPE,
			'post_status'    => self::TABS[ $tab ][1],
			'posts_per_page' => self::PER_PAGE,
			'paged'          => max( 1, $paged ),
			'orderby'        => 'date',
			'order'          => 'DESC',
			'meta_query'     => 'archived' === $tab
				? array( array( 'key' => CC_Notice_Service::META_ARCHIVED, 'value' => '1' ) )
				: array( array( 'key' => CC_Notice_Service::META_ARCHIVED, 'compare' => 'NOT EXISTS' ) ),
		);
		$query = new WP_Query( $args );
		$names = CC_Admin_Applications::batch_options();
		$items = array();
		foreach ( $query->posts as $post ) {
			$audience = CC_Notice_Service::audience( (int) $post->ID );
			$items[]  = array(
				'id'       => (int) $post->ID,
				'title'    => $post->post_title,
				'audience' => $audience['public'] ? 'Public' : implode( ', ', array_map( static fn( $b ) => (string) ( $names[ $b ] ?? '#' . $b ), $audience['batch_ids'] ) ),
				'status'   => $post->post_status,
				'critical' => CC_Notice_Service::is_critical( (int) $post->ID ),
				'date'     => $post->post_date_gmt,
			);
		}
		return array( 'items' => $items, 'total' => (int) $query->found_posts );
	}

	/* -------------------------------------------------------------- handlers */

	private static function authorize( string $nonce_action ): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'coaching-platform' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $nonce_action );
	}

	private static function redirect( array $args ): void {
		wp_safe_redirect( add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'admin.php' ) ) );
		exit;
	}

	private static function posted_id(): int {
		return absint( wp_unslash( $_POST['id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in authorize().
	}

	/** @return array{title:string,content:string,audience:string,batch_ids:array,critical:bool} */
	private static function input_from( array $post ): array {
		return array(
			'title'     => (string) ( $post['title'] ?? '' ),
			'content'   => (string) ( $post['content'] ?? '' ),
			'audience'  => 'batches' === ( $post['audience'] ?? '' ) ? 'batches' : 'public',
			'batch_ids' => (array) ( $post['batch_ids'] ?? array() ),
			'critical'  => ! empty( $post['critical'] ),
		);
	}

	public static function handle_save(): void {
		$id = self::posted_id();
		self::authorize( 'cc_notice_save_' . $id );
		$post     = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised in save().
		$saved    = self::save( array( 'id' => $id ) + self::input_from( $post ), get_current_user_id() );
		if ( is_wp_error( $saved ) && 'review_needed' === $saved->get_error_code() ) {
			$reviewed = self::preview_update( array( 'id' => $id ) + self::input_from( $post ), get_current_user_id() );
			self::redirect( array( 'view' => 'update_preview', 'id' => $id, 'cc_notice' => is_wp_error( $reviewed ) ? $reviewed->get_error_code() : '' ) );
		}
		if ( is_wp_error( $saved ) ) {
			self::redirect( array( 'view' => 'edit', 'id' => $id, 'cc_notice' => $saved->get_error_code() ) );
		}
		if ( ! empty( $post['to_preview'] ) ) {
			self::redirect( array( 'view' => 'preview', 'id' => $saved, 'schedule' => sanitize_text_field( (string) ( $post['schedule'] ?? '' ) ) ) );
		}
		self::redirect( array( 'cc_notice' => 'saved' ) );
	}

	public static function handle_publish(): void {
		$id = self::posted_id();
		self::authorize( 'cc_notice_publish_' . $id );
		$schedule = sanitize_text_field( wp_unslash( $_POST['schedule'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$token    = sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$result   = self::publish( $id, $token, self::schedule_to_gmt( $schedule ), get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			self::redirect( array( 'view' => 'preview', 'id' => $id, 'schedule' => $schedule, 'cc_notice' => $result->get_error_code() ) );
		}
		self::redirect( array( 'cc_notice' => 'future' === $result['status'] ? 'scheduled' : 'published', 'cc_n' => $result['students'] ) );
	}

	public static function handle_update(): void {
		$id = self::posted_id();
		self::authorize( 'cc_notice_update_' . $id );
		$token  = sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$result = self::apply_update( $id, $token, get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			self::redirect( array( 'view' => 'edit', 'id' => $id, 'cc_notice' => $result->get_error_code() ) );
		}
		self::redirect( array( 'cc_notice' => 'updated' ) );
	}

	public static function handle_archive(): void {
		$id = self::posted_id();
		self::authorize( 'cc_notice_archive_' . $id );
		$archived = ! empty( $_POST['archived'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$result   = self::archive( $id, $archived, get_current_user_id() );
		$notice   = is_wp_error( $result ) ? $result->get_error_code() : ( $archived ? 'archived' : 'unarchived' );
		self::redirect( array( 'cc_notice' => $notice ) );
	}

	/* ------------------------------------------------------------- rendering */

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to view this page.', 'coaching-platform' ), '', array( 'response' => 403 ) );
		}
		echo '<div class="wrap">';
		self::render_notice();
		$view = sanitize_key( wp_unslash( $_GET['view'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation.
		$id   = absint( $_GET['id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'edit' === $view ) {
			self::render_form( $id );
		} elseif ( 'preview' === $view && self::find( $id ) ) {
			self::render_preview( $id, sanitize_text_field( wp_unslash( $_GET['schedule'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		} elseif ( 'update_preview' === $view && self::find( $id ) ) {
			self::render_update_preview( $id );
		} else {
			self::render_list();
		}
		echo '</div>';
	}

	private static function render_notice(): void {
		$code = sanitize_key( wp_unslash( $_GET['cc_notice'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( self::NOTICES[ $code ] ) ) {
			return;
		}
		list( $type, $message ) = self::NOTICES[ $code ];
		if ( 'published' === $code && isset( $_GET['cc_n'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$message = sprintf( 'Sent to %d students.', absint( $_GET['cc_n'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		if ( 'scheduled' === $code && isset( $_GET['cc_n'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$message = sprintf( 'Scheduled. It will reach %d students when it publishes.', absint( $_GET['cc_n'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $type ), esc_html( $message ) );
	}

	private static function page_url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'admin.php' ) );
	}

	private static function render_list(): void {
		$tab  = sanitize_key( wp_unslash( $_GET['tab'] ?? 'draft' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab  = isset( self::TABS[ $tab ] ) ? $tab : 'draft';
		$list = self::list_notices( $tab, absint( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<h1 class="wp-heading-inline">Notices</h1> <a class="page-title-action" href="' . esc_url( self::page_url( array( 'view' => 'edit' ) ) ) . '">Add notice</a><hr class="wp-header-end">';
		echo '<ul class="subsubsub">';
		$links = array();
		foreach ( self::TABS as $key => $def ) {
			$links[] = sprintf( '<li><a href="%s"%s>%s</a></li>', esc_url( self::page_url( array( 'tab' => $key ) ) ), $key === $tab ? ' class="current"' : '', esc_html( $def[0] ) );
		}
		echo implode( ' | ', $links ) . '</ul><table class="widefat striped"><thead><tr><th>Title</th><th>Audience</th><th>Status</th><th>Critical</th><th>Date (UTC)</th><th></th></tr></thead><tbody>';
		if ( ! $list['items'] ) {
			echo '<tr><td colspan="6">No notices here.</td></tr>';
		}
		foreach ( $list['items'] as $item ) {
			printf(
				'<tr><td><a href="%s">%s</a></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>',
				esc_url( self::page_url( array( 'view' => 'edit', 'id' => $item['id'] ) ) ),
				esc_html( $item['title'] ),
				esc_html( $item['audience'] ),
				esc_html( $item['status'] ),
				$item['critical'] ? 'SMS' : '',
				esc_html( $item['date'] )
			);
			self::render_archive_form( $item['id'], 'archived' !== $tab );
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function render_archive_form( int $id, bool $archive ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">';
		echo '<input type="hidden" name="action" value="cc_notice_archive"><input type="hidden" name="id" value="' . esc_attr( (string) $id ) . '"><input type="hidden" name="archived" value="' . ( $archive ? '1' : '0' ) . '">';
		wp_nonce_field( 'cc_notice_archive_' . $id );
		submit_button( $archive ? 'Archive' : 'Unarchive', 'small', 'submit', false );
		echo '</form>';
	}

	private static function render_form( int $id ): void {
		$post     = self::find( $id );
		$prefill  = $post ? array() : self::prefill_batches( wp_unslash( $_GET['cc_prefill_batches'] ?? array() ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated by prefill_batches().
		$audience = $post ? CC_Notice_Service::audience( $id ) : array( 'public' => ! $prefill, 'batch_ids' => $prefill );
		echo '<h1>' . esc_html( $post ? 'Edit notice' : 'Add notice' ) . '</h1>';
		if ( $prefill ) {
			echo '<div class="notice notice-info inline"><p>The audience was pre-filled from the Students roster. Check it, write the notice, then review the audience preview before publishing.</p></div>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="cc_notice_save"><input type="hidden" name="id" value="' . esc_attr( (string) $id ) . '">';
		wp_nonce_field( 'cc_notice_save_' . $id );
		echo '<p><label for="cc-title"><strong>Title</strong></label><br><input id="cc-title" name="title" type="text" class="large-text" maxlength="' . esc_attr( (string) self::MAX_TITLE ) . '" value="' . esc_attr( $post ? $post->post_title : '' ) . '" required></p>';
		wp_editor( $post ? $post->post_content : '', 'cc_notice_content', array( 'textarea_name' => 'content', 'textarea_rows' => 10, 'media_buttons' => false ) );
		echo '<fieldset><legend><strong>Audience</strong></legend>';
		printf( '<label><input type="radio" name="audience" value="public"%s> Public</label><br>', checked( $audience['public'], true, false ) );
		printf( '<label><input type="radio" name="audience" value="batches"%s> Selected batches only</label>', checked( $audience['public'], false, false ) );
		echo '<div style="margin:6px 0 0 24px">';
		foreach ( CC_Admin_Applications::batch_options() as $batch_id => $name ) {
			printf( '<label><input type="checkbox" name="batch_ids[]" value="%d"%s> %s</label><br>', (int) $batch_id, checked( in_array( (int) $batch_id, $audience['batch_ids'], true ), true, false ), esc_html( $name ) );
		}
		echo '</div></fieldset>';
		printf( '<p><label><input type="checkbox" name="critical" value="1"%s> Critical: also send an SMS to targeted students</label></p>', checked( $post && CC_Notice_Service::is_critical( $id ), true, false ) );
		echo '<p><label for="cc-schedule"><strong>Schedule (site time, optional)</strong></label><br><input id="cc-schedule" name="schedule" type="datetime-local"></p>';
		if ( $post && self::is_live( $post ) ) {
			echo '<p class="description">This notice is live. Changing its audience or the critical flag shows a review first; SMS is never resent after publishing.</p>';
		}
		submit_button( $post && self::is_live( $post ) ? 'Save changes' : 'Save draft', 'secondary', 'submit', false );
		if ( ! $post || 'publish' !== $post->post_status ) {
			echo ' <button type="submit" class="button button-primary" name="to_preview" value="1">Save and preview audience</button>';
		}
		echo ' <a class="button" href="' . esc_url( self::page_url() ) . '">Cancel</a></form>';
	}

	private static function render_preview( int $id, string $schedule ): void {
		$preview = self::preview( $id, self::schedule_to_gmt( $schedule ), get_current_user_id() );
		if ( is_wp_error( $preview ) ) {
			echo '<p>' . esc_html( $preview->get_error_message() ) . '</p>';
			return;
		}
		echo '<h1>Review audience</h1><p><strong>' . esc_html( $preview['message'] ) . '</strong></p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="cc_notice_publish"><input type="hidden" name="id" value="' . esc_attr( (string) $id ) . '">';
		echo '<input type="hidden" name="token" value="' . esc_attr( $preview['token'] ) . '"><input type="hidden" name="schedule" value="' . esc_attr( $schedule ) . '">';
		wp_nonce_field( 'cc_notice_publish_' . $id );
		submit_button( '' === $schedule ? 'Confirm and publish' : 'Confirm and schedule', 'primary', 'submit', false );
		echo ' <a class="button" href="' . esc_url( self::page_url( array( 'view' => 'edit', 'id' => $id ) ) ) . '">Back to editor</a></form>';
	}

	private static function render_update_preview( int $id ): void {
		$pending = self::pending_update( $id, get_current_user_id() );
		if ( ! $pending ) {
			echo '<p>The review expired. <a href="' . esc_url( self::page_url( array( 'view' => 'edit', 'id' => $id ) ) ) . '">Back to the editor</a></p>';
			return;
		}
		echo '<h1>Review changes</h1><p><strong>' . esc_html( $pending['message'] ) . '</strong></p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="cc_notice_update"><input type="hidden" name="id" value="' . esc_attr( (string) $id ) . '">';
		echo '<input type="hidden" name="token" value="' . esc_attr( $pending['token'] ) . '">';
		wp_nonce_field( 'cc_notice_update_' . $id );
		submit_button( 'Confirm and save changes', 'primary', 'submit', false );
		echo ' <a class="button" href="' . esc_url( self::page_url( array( 'view' => 'edit', 'id' => $id ) ) ) . '">Back to editor</a></form>';
	}
}
