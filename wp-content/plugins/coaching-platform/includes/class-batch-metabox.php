<?php
defined( 'ABSPATH' ) || exit;

/**
 * wp-admin editing for course details, batches and faculty fields (native screens, ADR-011).
 */
final class CC_Batch_Metabox {

	const NONCE_ACTION = 'cc_course_save';
	const NONCE_FIELD  = 'cc_course_nonce';
	const BLANK_ROWS   = 2;

	public static function init(): void {
		add_action( 'add_meta_boxes', array( __CLASS__, 'register' ) );
		add_action( 'save_post_cc_course', array( __CLASS__, 'save_course' ) );
		add_action( 'save_post_cc_faculty', array( __CLASS__, 'save_faculty' ) );
	}

	public static function register(): void {
		add_meta_box( 'cc_course_details', 'Course details', array( __CLASS__, 'render_details' ), 'cc_course', 'normal', 'high' );
		add_meta_box( 'cc_course_batches', 'Batches (schedule, price, capacity)', array( __CLASS__, 'render_batches' ), 'cc_course', 'normal', 'high' );
		add_meta_box( 'cc_faculty_details', 'Faculty details', array( __CLASS__, 'render_faculty' ), 'cc_faculty', 'normal', 'high' );
	}

	public static function render_details( WP_Post $post ): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		$duration    = (string) get_post_meta( $post->ID, 'cc_duration', true );
		$syllabus    = (string) get_post_meta( $post->ID, 'cc_syllabus', true );
		$instructors = array_map( 'intval', (array) get_post_meta( $post->ID, 'cc_instructors', true ) );
		$faculty     = get_posts( array( 'post_type' => 'cc_faculty', 'posts_per_page' => 100, 'orderby' => 'title', 'order' => 'ASC' ) );
		?>
		<p><label><strong>Duration</strong> (e.g. "6 months")<br>
			<input type="text" class="widefat" name="cc_duration" value="<?php echo esc_attr( $duration ); ?>"></label></p>
		<p><label><strong>Syllabus</strong> (one topic per line)<br>
			<textarea class="widefat" rows="6" name="cc_syllabus"><?php echo esc_textarea( $syllabus ); ?></textarea></label></p>
		<p><label><strong>Instructors</strong> (hold Ctrl/Cmd to select several)<br>
			<select name="cc_instructors[]" multiple class="widefat" size="5">
				<?php foreach ( $faculty as $member ) : ?>
					<option value="<?php echo esc_attr( (string) $member->ID ); ?>" <?php selected( in_array( $member->ID, $instructors, true ) ); ?>><?php echo esc_html( $member->post_title ); ?></option>
				<?php endforeach; ?>
			</select></label></p>
		<?php
	}

	public static function render_batches( WP_Post $post ): void {
		$rows = CC_Batch_Repository::for_course( $post->ID );
		for ( $i = 0; $i < self::BLANK_ROWS; $i++ ) {
			$rows[] = array();
		}
		?>
		<p class="description">Leave the name empty to skip a row. Remove a batch by clearing its name. "Seats taken" is managed by admissions and cannot be edited here.</p>
		<?php foreach ( $rows as $i => $row ) : ?>
			<fieldset style="border:1px solid #c3c4c7;padding:10px;margin:0 0 10px;">
				<input type="hidden" name="cc_batches[<?php echo (int) $i; ?>][id]" value="<?php echo esc_attr( (string) ( $row['id'] ?? '' ) ); ?>">
				<p style="display:flex;gap:8px;flex-wrap:wrap;">
					<label>Name<br><input type="text" name="cc_batches[<?php echo (int) $i; ?>][name]" value="<?php echo esc_attr( (string) ( $row['name'] ?? '' ) ); ?>"></label>
					<label>Mode<br>
						<select name="cc_batches[<?php echo (int) $i; ?>][delivery_mode]">
							<?php foreach ( CC_Batch_Repository::MODES as $mode ) : ?>
								<option value="<?php echo esc_attr( $mode ); ?>" <?php selected( ( $row['delivery_mode'] ?? 'physical' ) === $mode ); ?>><?php echo esc_html( ucfirst( $mode ) ); ?></option>
							<?php endforeach; ?>
						</select></label>
					<label>Capacity<br><input type="number" min="0" style="width:80px" name="cc_batches[<?php echo (int) $i; ?>][capacity]" value="<?php echo esc_attr( (string) ( $row['capacity'] ?? '30' ) ); ?>"></label>
					<label>Price (BDT)<br><input type="number" min="0" step="0.01" style="width:110px" name="cc_batches[<?php echo (int) $i; ?>][price]" value="<?php echo esc_attr( (string) ( $row['price'] ?? '' ) ); ?>"></label>
					<label>Start<br><input type="date" name="cc_batches[<?php echo (int) $i; ?>][start_date]" value="<?php echo esc_attr( (string) ( $row['start_date'] ?? '' ) ); ?>"></label>
					<label>End<br><input type="date" name="cc_batches[<?php echo (int) $i; ?>][end_date]" value="<?php echo esc_attr( (string) ( $row['end_date'] ?? '' ) ); ?>"></label>
				</p>
				<p style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;">
					<label>Schedule (e.g. "Sat, Mon, Wed · 6:00–7:30 PM")<br><input type="text" style="width:320px" name="cc_batches[<?php echo (int) $i; ?>][schedule_text]" value="<?php echo esc_attr( (string) ( $row['schedule_text'] ?? '' ) ); ?>"></label>
					<label>Status<br>
						<select name="cc_batches[<?php echo (int) $i; ?>][status]">
							<?php foreach ( CC_Batch_Repository::STATUSES as $status ) : ?>
								<option value="<?php echo esc_attr( $status ); ?>" <?php selected( ( $row['status'] ?? 'draft' ) === $status ); ?>><?php echo esc_html( ucfirst( $status ) ); ?></option>
							<?php endforeach; ?>
						</select></label>
					<label><input type="checkbox" name="cc_batches[<?php echo (int) $i; ?>][application_open]" value="1" <?php checked( ! empty( $row['application_open'] ) ); ?>> Applications open</label>
					<label title="When the batch is full, applicants join a waitlist and staff offer them a seat."><input type="checkbox" name="cc_batches[<?php echo (int) $i; ?>][waitlist_enabled]" value="1" <?php checked( ! empty( $row['waitlist_enabled'] ) ); ?>> Waitlist when full</label>
					<?php if ( ! empty( $row['id'] ) ) : ?>
						<span>Seats taken: <strong><?php echo esc_html( (string) $row['seats_taken'] ); ?></strong></span>
					<?php endif; ?>
				</p>
				<p style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;">
					<label><input type="checkbox" name="cc_batches[<?php echo (int) $i; ?>][installments_enabled]" value="1" <?php checked( ! empty( $row['installments_enabled'] ) ); ?>> Allow two-part payment</label>
					<label>First payment %<br><input type="number" min="10" max="90" style="width:70px" name="cc_batches[<?php echo (int) $i; ?>][first_payment_percent]" value="<?php echo esc_attr( (string) ( $row['first_payment_percent'] ?? '50' ) ); ?>"></label>
					<label>Balance due after (days)<br><input type="number" min="1" max="365" style="width:70px" name="cc_batches[<?php echo (int) $i; ?>][installment_days]" value="<?php echo esc_attr( (string) ( $row['installment_days'] ?? '30' ) ); ?>"></label>
				</p>
			</fieldset>
		<?php endforeach; ?>
		<?php
	}

	public static function render_faculty( WP_Post $post ): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		?>
		<p><label><strong>Subject</strong><br><input type="text" class="widefat" name="cc_subject" value="<?php echo esc_attr( (string) get_post_meta( $post->ID, 'cc_subject', true ) ); ?>"></label></p>
		<p><label><strong>Credentials</strong><br><input type="text" class="widefat" name="cc_credentials" value="<?php echo esc_attr( (string) get_post_meta( $post->ID, 'cc_credentials', true ) ); ?>"></label></p>
		<?php
	}

	private static function can_save( int $post_id ): bool {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return false;
		}
		$nonce = isset( $_POST[ self::NONCE_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ) : '';
		return $nonce && wp_verify_nonce( $nonce, self::NONCE_ACTION ) && current_user_can( 'edit_post', $post_id );
	}

	public static function save_course( int $post_id ): void {
		if ( ! self::can_save( $post_id ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification -- verified in can_save().
		update_post_meta( $post_id, 'cc_duration', sanitize_text_field( wp_unslash( $_POST['cc_duration'] ?? '' ) ) );
		update_post_meta( $post_id, 'cc_syllabus', sanitize_textarea_field( wp_unslash( $_POST['cc_syllabus'] ?? '' ) ) );
		$instructors = array_values(
			array_filter(
				array_map( 'absint', (array) ( $_POST['cc_instructors'] ?? array() ) ),
				static fn( int $id ): bool => $id > 0 && 'cc_faculty' === get_post_type( $id )
			)
		);
		update_post_meta( $post_id, 'cc_instructors', $instructors );

		// Only touch batches when the Batches box was actually submitted (it can be hidden via Screen Options).
		if ( is_array( $_POST['cc_batches'] ?? null ) ) {
			CC_Batch_Repository::sync( $post_id, self::sanitize_batches( wp_unslash( $_POST['cc_batches'] ) ) );
		}
		// phpcs:enable
		do_action( 'cc_catalogue_changed', $post_id );
	}

	public static function save_faculty( int $post_id ): void {
		if ( ! self::can_save( $post_id ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification
		update_post_meta( $post_id, 'cc_subject', sanitize_text_field( wp_unslash( $_POST['cc_subject'] ?? '' ) ) );
		update_post_meta( $post_id, 'cc_credentials', sanitize_text_field( wp_unslash( $_POST['cc_credentials'] ?? '' ) ) );
		// phpcs:enable
	}

	/**
	 * @param array<int,mixed> $submitted
	 * @return array<int,array<string,mixed>>
	 */
	private static function sanitize_batches( array $submitted ): array {
		$rows = array();
		foreach ( $submitted as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}
			$name = sanitize_text_field( (string) ( $raw['name'] ?? '' ) );
			if ( '' === $name ) {
				continue;
			}
			$mode   = (string) ( $raw['delivery_mode'] ?? 'physical' );
			$status = (string) ( $raw['status'] ?? 'draft' );
			$rows[] = array(
				'id'               => absint( $raw['id'] ?? 0 ),
				'name'             => $name,
				'delivery_mode'    => in_array( $mode, CC_Batch_Repository::MODES, true ) ? $mode : 'physical',
				'capacity'         => min( absint( $raw['capacity'] ?? 0 ), 65535 ),
				'price'            => min( 99999999.99, max( 0, round( (float) ( $raw['price'] ?? 0 ), 2 ) ) ),
				'start_date'       => self::valid_date( (string) ( $raw['start_date'] ?? '' ) ) ?: gmdate( 'Y-m-d' ),
				'end_date'         => self::valid_date( (string) ( $raw['end_date'] ?? '' ) ),
				'schedule_text'    => sanitize_text_field( (string) ( $raw['schedule_text'] ?? '' ) ),
				'status'           => in_array( $status, CC_Batch_Repository::STATUSES, true ) ? $status : 'draft',
				'application_open' => ! empty( $raw['application_open'] ),
				'waitlist_enabled' => ! empty( $raw['waitlist_enabled'] ),
				'installments_enabled' => ! empty( $raw['installments_enabled'] ),
				'first_payment_percent' => CC_Batch_Repository::clamp_percent( absint( $raw['first_payment_percent'] ?? 50 ) ),
				'installment_days' => max( 1, min( 365, absint( $raw['installment_days'] ?? 30 ) ) ),
			);
		}
		return $rows;
	}

	private static function valid_date( string $value ): string {
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) ) {
			return '';
		}
		return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ? $value : '';
	}
}
