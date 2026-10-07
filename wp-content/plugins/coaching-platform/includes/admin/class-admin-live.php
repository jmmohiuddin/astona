<?php
defined( 'ABSPATH' ) || exit;

/**
 * Admin screen "Live classes": list per batch plus create/edit/delete. The meeting link is encrypted at rest, shown only
 * masked, and re-entered by typing to change it. Every handler checks the nonce and the capability; the public static
 * operations re-check the capability for the given actor. Audit entries never contain the link.
 */
final class CC_Admin_Live {

	const CAP         = 'cc_manage_live';
	const PAGE_SLUG   = 'cc-live';
	const ACTION_SAVE = 'cc_live_save';
	const ACTION_DEL  = 'cc_live_delete';
	const ERROR_TTL   = 60;
	const INPUT_TIME  = 'Y-m-d\TH:i';

	const NOTICES = array(
		'saved'   => array( 'success', 'Live class saved.' ),
		'deleted' => array( 'success', 'Live class deleted.' ),
		'failed'  => array( 'error', 'The live class could not be saved.' ),
	);

	public static function init(): void {
		add_action( 'admin_post_' . self::ACTION_SAVE, array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_' . self::ACTION_DEL, array( __CLASS__, 'handle_delete' ) );
	}

	/* ---------------------------------------------------------------- testable operations */

	/** datetime-local value in the site timezone to UTC "Y-m-d H:i:s"; null when malformed. */
	public static function local_to_utc( string $value ): ?string {
		$date = DateTimeImmutable::createFromFormat( '!' . self::INPUT_TIME, $value, wp_timezone() );
		if ( false === $date || $date->format( self::INPUT_TIME ) !== $value ) {
			return null;
		}
		return $date->setTimezone( new DateTimeZone( 'UTC' ) )->format( CC_Live_Repository::TIME_FORMAT );
	}

	public static function utc_to_local( string $utc ): string {
		$date = DateTimeImmutable::createFromFormat( '!' . CC_Live_Repository::TIME_FORMAT, $utc, new DateTimeZone( 'UTC' ) );
		return false === $date ? '' : $date->setTimezone( wp_timezone() )->format( self::INPUT_TIME );
	}

	/**
	 * Creates (no id) or updates (id given) a class from form input; times are site-timezone datetime-local values.
	 *
	 * @param array<string,mixed> $raw Unslashed input.
	 * @return int|WP_Error The class id.
	 */
	public static function save( array $raw, int $actor ) {
		if ( ! user_can( $actor, self::CAP ) ) {
			return new WP_Error( 'cc_forbidden', 'You are not allowed to manage live classes.' );
		}
		$id     = (int) ( $raw['id'] ?? 0 );
		$fields = array(
			'batch_id'    => (int) ( $raw['batch_id'] ?? 0 ),
			'lesson_id'   => (int) ( $raw['lesson_id'] ?? 0 ),
			'title'       => sanitize_text_field( (string) ( $raw['title'] ?? '' ) ),
			'starts_at'   => self::local_to_utc( (string) ( $raw['starts_at'] ?? '' ) ) ?? '',
			'ends_at'     => self::local_to_utc( (string) ( $raw['ends_at'] ?? '' ) ) ?? '',
			'provider'    => sanitize_key( (string) ( $raw['provider'] ?? '' ) ),
			'meeting_url' => trim( (string) ( $raw['meeting_url'] ?? '' ) ),
		);
		return $id > 0 ? self::update_existing( $id, $fields ) : self::create_new( $fields, $actor );
	}

	/** @return bool|WP_Error */
	public static function remove( int $id, int $actor ) {
		if ( ! user_can( $actor, self::CAP ) ) {
			return new WP_Error( 'cc_forbidden', 'You are not allowed to manage live classes.' );
		}
		$row = CC_Live_Repository::find( $id );
		if ( null === $row || ! CC_Live_Repository::delete( $id ) ) {
			return new WP_Error( 'cc_live_not_found', 'Live class not found.' );
		}
		CC_Audit::log( 'live.delete', 'live_class', $id, array( 'batch_id' => (int) $row['batch_id'] ) );
		return true;
	}

	/* ---------------------------------------------------------------- handlers */

	public static function handle_save(): void {
		self::guard( self::ACTION_SAVE );
		$result = self::save( wp_unslash( $_POST ), get_current_user_id() ); // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified in guard(); sanitised in save().
		if ( is_wp_error( $result ) ) {
			set_transient( self::error_key(), $result->get_error_message(), self::ERROR_TTL );
			self::redirect( 'failed' );
		}
		self::redirect( 'saved' );
	}

	public static function handle_delete(): void {
		self::guard( self::ACTION_DEL );
		$result = self::remove( isset( $_POST['id'] ) ? (int) $_POST['id'] : 0, get_current_user_id() ); // phpcs:ignore WordPress.Security.NonceVerification -- verified in guard().
		self::redirect( is_wp_error( $result ) ? 'failed' : 'deleted' );
	}

	/* ---------------------------------------------------------------- rendering */

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'coaching-platform' ), 403 );
		}
		$batches = self::batches();
		$edit_id = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification -- display only.
		$current = $edit_id > 0 ? CC_Live_Repository::find( $edit_id ) : null;
		?>
		<div class="wrap">
			<h1>Live classes</h1>
			<?php self::render_notice(); ?>
			<p class="description">The link is never shown publicly. Students only receive it when they press Join, and only for a class they are enrolled in.</p>
			<?php self::render_form( $batches, $current ); ?>
			<?php foreach ( $batches as $batch_id => $name ) : ?>
				<?php self::render_batch_table( $batch_id, $name ); ?>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/** @param array<int,string> $batches */
	private static function render_form( array $batches, ?array $current ): void {
		$v = array_merge(
			array( 'id' => 0, 'batch_id' => 0, 'lesson_id' => 0, 'title' => '', 'starts_at' => '', 'ends_at' => '', 'provider' => 'meet' ),
			$current ?? array()
		);
		$masked = '';
		if ( null !== $current ) {
			try {
				$masked = CC_Live_Repository::mask_url( CC_Live_Repository::meeting_url( (int) $v['id'] ) );
			} catch ( Throwable $e ) {
				$masked = 'unreadable, please re-enter';
			}
		}
		?>
		<form class="cc-form cc-panel" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SAVE ); ?>">
			<input type="hidden" name="id" value="<?php echo (int) $v['id']; ?>">
			<?php wp_nonce_field( self::ACTION_SAVE ); ?>
			<h2><?php echo $current ? 'Edit live class' : 'New live class'; ?></h2>
			<table class="form-table" role="presentation">
				<tr><th scope="row"><label for="cc-live-title">Title</label></th><td><input id="cc-live-title" name="title" type="text" class="regular-text" required maxlength="<?php echo (int) CC_Live_Repository::MAX_TITLE; ?>" value="<?php echo esc_attr( (string) $v['title'] ); ?>"></td></tr>
				<tr><th scope="row"><label for="cc-live-batch">Batch</label></th><td>
					<select id="cc-live-batch" name="batch_id" required>
						<option value="">Choose a batch</option>
						<?php foreach ( $batches as $batch_id => $name ) : ?>
							<option value="<?php echo (int) $batch_id; ?>" <?php selected( (int) $v['batch_id'], $batch_id ); ?>><?php echo esc_html( $name ); ?></option>
						<?php endforeach; ?>
					</select></td></tr>
				<tr><th scope="row"><label for="cc-live-lesson">Lesson ID (optional)</label></th><td><input id="cc-live-lesson" name="lesson_id" type="number" min="0" class="small-text" value="<?php echo (int) $v['lesson_id'] > 0 ? (int) $v['lesson_id'] : ''; ?>"></td></tr>
				<tr><th scope="row"><label for="cc-live-start">Starts (<?php echo esc_html( wp_timezone_string() ); ?>)</label></th><td><input id="cc-live-start" name="starts_at" type="datetime-local" required value="<?php echo esc_attr( self::utc_to_local( (string) $v['starts_at'] ) ); ?>"></td></tr>
				<tr><th scope="row"><label for="cc-live-end">Ends (<?php echo esc_html( wp_timezone_string() ); ?>)</label></th><td><input id="cc-live-end" name="ends_at" type="datetime-local" required value="<?php echo esc_attr( self::utc_to_local( (string) $v['ends_at'] ) ); ?>"></td></tr>
				<tr><th scope="row"><label for="cc-live-provider">Provider</label></th><td>
					<select id="cc-live-provider" name="provider">
						<?php foreach ( array( 'meet' => 'Google Meet', 'zoom' => 'Zoom', 'other' => 'Other' ) as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( (string) $v['provider'], $key ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select></td></tr>
				<tr><th scope="row"><label for="cc-live-url">Meeting link</label></th><td>
					<input id="cc-live-url" name="meeting_url" type="url" class="regular-text" autocomplete="off" maxlength="<?php echo (int) CC_Live_Repository::MAX_URL; ?>" <?php echo $current ? '' : 'required'; ?> placeholder="<?php echo esc_attr( '' !== $masked ? $masked : 'https://' ); ?>">
					<p class="description"><?php echo $current ? 'Current link is hidden. Leave blank to keep it, or type the full link to replace it.' : 'https only. The link is never shown publicly.'; ?></p></td></tr>
			</table>
			<?php submit_button( $current ? 'Save changes' : 'Create live class' ); ?>
		</form>
		<?php
	}

	private static function render_batch_table( int $batch_id, string $name ): void {
		$rows = CC_Live_Repository::for_batch( $batch_id );
		if ( ! $rows ) {
			return;
		}
		?>
		<div class="cc-panel">
			<h2><?php echo esc_html( $name ); ?></h2>
			<table class="cc-table widefat striped">
				<thead><tr><th scope="col">Title</th><th scope="col">Starts</th><th scope="col">Ends</th><th scope="col">Provider</th><th scope="col">Link</th><th scope="col">Actions</th></tr></thead>
				<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td><?php echo esc_html( $row['title'] ); ?></td>
						<td><?php echo esc_html( str_replace( 'T', ' ', self::utc_to_local( $row['starts_at'] ) ) ); ?></td>
						<td><?php echo esc_html( str_replace( 'T', ' ', self::utc_to_local( $row['ends_at'] ) ) ); ?></td>
						<td><?php echo esc_html( $row['provider'] ); ?></td>
						<td><?php echo esc_html( self::masked_link( (int) $row['id'] ) ); ?></td>
						<td>
							<a href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'edit' => (int) $row['id'] ), admin_url( 'admin.php' ) ) ); ?>">Edit</a>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline" onsubmit="return confirm('Delete this live class?');">
								<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_DEL ); ?>">
								<input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>">
								<?php wp_nonce_field( self::ACTION_DEL ); ?>
								<button type="submit" class="button-link-delete">Delete</button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
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

	/* ---------------------------------------------------------------- internals */

	/** @param array<string,mixed> $fields */
	private static function create_new( array $fields, int $actor ) {
		$id = CC_Live_Repository::create( $fields, $actor );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		CC_Audit::log( 'live.create', 'live_class', $id, array( 'batch_id' => (int) $fields['batch_id'] ) );
		return $id;
	}

	/** @param array<string,mixed> $fields */
	private static function update_existing( int $id, array $fields ) {
		$before = CC_Live_Repository::find( $id );
		if ( null === $before ) {
			return new WP_Error( 'cc_live_not_found', 'Live class not found.' );
		}
		$url_changed = false;
		if ( '' !== $fields['meeting_url'] ) {
			try {
				$url_changed = ! hash_equals( CC_Live_Repository::meeting_url( $id ), $fields['meeting_url'] );
			} catch ( Throwable $e ) {
				$url_changed = true;
			}
		}
		$result = CC_Live_Repository::update( $id, $fields );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$changed = array();
		foreach ( array( 'batch_id', 'lesson_id', 'title', 'starts_at', 'ends_at', 'provider' ) as $key ) {
			if ( (string) $before[ $key ] !== (string) $fields[ $key ] ) {
				$changed[] = $key;
			}
		}
		CC_Audit::log( 'live.update', 'live_class', $id, array( 'changed' => $changed ), implode( ',', $changed ) );
		if ( $url_changed ) {
			CC_Audit::log( 'live.meeting_url_changed', 'live_class', $id );
		}
		return $id;
	}

	private static function masked_link( int $id ): string {
		try {
			return CC_Live_Repository::mask_url( CC_Live_Repository::meeting_url( $id ) );
		} catch ( Throwable $e ) {
			return 'unreadable';
		}
	}

	/** @return array<int,string> batch id => name */
	private static function batches(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'cc_batches';
		$rows  = $wpdb->get_results( "SELECT id, name FROM {$table} ORDER BY id DESC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		$out   = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[ (int) $row['id'] ] = (string) $row['name'];
		}
		return $out;
	}

	private static function guard( string $action ): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'coaching-platform' ), 403 );
		}
		check_admin_referer( $action );
	}

	private static function error_key(): string {
		return 'cc_live_err_' . get_current_user_id();
	}

	private static function redirect( string $notice ): void {
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'cc_notice' => $notice ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
