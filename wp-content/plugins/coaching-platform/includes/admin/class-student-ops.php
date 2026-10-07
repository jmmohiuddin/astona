<?php
defined( 'ABSPATH' ) || exit;

/**
 * State-changing student operations behind the Students screen: profile edit, batch move and the bulk helpers. Each
 * public method re-checks the capability for the given actor, so it is safe to call from anywhere. The login phone
 * (user_login) and the application email (user meta cc_application_email) are never written here.
 */
final class CC_Student_Ops {

	const MAX_BULK        = 500;
	const BULK_TTL        = 15 * MINUTE_IN_SECONDS;
	const BULK_KEY        = 'cc_stu_bulk_';
	const PROFILE_ERR_KEY = 'cc_stu_prof_err_';
	const TEXT_MIN        = 2;
	const TEXT_MAX        = 190;
	const MOVABLE_STATUS  = array( 'open', 'closed' );
	const PROFILE_FIELDS  = array( 'full_name', 'guardian_name', 'guardian_phone', 'institution' );

	/* ---------------------------------------------------------------- profile */

	/**
	 * @param array<string,mixed> $input Unslashed form input (full_name, guardian_name, guardian_phone, institution).
	 * @return string[]|WP_Error Names of the fields that changed (empty: nothing to save). Error code cc_invalid carries the field messages as data.
	 */
	public static function update_profile( int $user_id, array $input, int $actor ) {
		global $wpdb;
		if ( ! user_can( $actor, 'cc_manage_students' ) ) {
			return new WP_Error( 'cc_forbidden', 'You are not allowed to manage students.' );
		}
		$student = CC_Enrollment_Repository::student_row( $user_id );
		$user    = get_userdata( $user_id );
		if ( null === $student || ! $user instanceof WP_User || ! CC_Roles::is_student( $user ) ) {
			return new WP_Error( 'cc_not_found', 'Student not found.' );
		}

		$errors = array();
		$clean  = array();
		$labels = array( 'full_name' => 'full name', 'guardian_name' => 'guardian name', 'institution' => 'institution' );
		foreach ( $labels as $field => $label ) {
			$value = trim( sanitize_text_field( (string) ( $input[ $field ] ?? '' ) ) );
			$len   = mb_strlen( $value );
			if ( $len < self::TEXT_MIN || $len > self::TEXT_MAX ) {
				$errors[ $field ] = sprintf( 'Enter the %s (%d to %d characters).', $label, self::TEXT_MIN, self::TEXT_MAX );
				continue;
			}
			$clean[ $field ] = $value;
		}
		$phone = CC_Phone::normalize( (string) ( $input['guardian_phone'] ?? '' ) );
		if ( null === $phone ) {
			$errors['guardian_phone'] = 'Enter a valid Bangladeshi mobile number.';
		} else {
			$clean['guardian_phone'] = $phone;
		}
		if ( $errors ) {
			return new WP_Error( 'cc_invalid', 'Some fields need attention.', $errors );
		}

		$changed = array();
		foreach ( self::PROFILE_FIELDS as $field ) {
			if ( (string) ( $student[ $field ] ?? '' ) !== $clean[ $field ] ) {
				$changed[ $field ] = $clean[ $field ];
			}
		}
		if ( ! $changed ) {
			return array();
		}
		if ( false === $wpdb->update( $wpdb->prefix . 'cc_students', $changed, array( 'user_id' => $user_id ) ) ) {
			return new WP_Error( 'cc_db_error', 'The profile could not be saved.' );
		}
		if ( isset( $changed['full_name'] ) ) {
			wp_update_user( array( 'ID' => $user_id, 'display_name' => $changed['full_name'] ) );
		}
		$names = array_keys( $changed );
		CC_Audit::log( 'student.profile_edit', 'student', $user_id, array( 'fields' => $names ) );
		return $names;
	}

	/* ---------------------------------------------------------------- batch move */

	/**
	 * Batches an enrollment may move to: same course or any open batch, never the current one, never draft/completed.
	 * Each row carries seats_left and flags the picker uses to disable it.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function move_targets( int $enrollment_id ): array {
		global $wpdb;
		$p = $wpdb->prefix;
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names only.
		$current = $wpdb->get_row(
			$wpdb->prepare( "SELECT e.user_id, e.batch_id, b.course_id, b.price FROM {$p}cc_enrollments e JOIN {$p}cc_batches b ON b.id = e.batch_id WHERE e.id = %d", $enrollment_id ),
			ARRAY_A
		);
		if ( ! is_array( $current ) ) {
			return array();
		}
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT b.id, b.name, b.delivery_mode, b.price, b.capacity, b.seats_taken, b.status, p.post_title AS course_title,
					EXISTS (SELECT 1 FROM {$p}cc_enrollments x WHERE x.user_id = %d AND x.batch_id = b.id) AS already_enrolled
				FROM {$p}cc_batches b LEFT JOIN {$wpdb->posts} p ON p.ID = b.course_id
				WHERE b.id <> %d AND ( (b.course_id = %d AND b.status IN ('open','closed')) OR b.status = 'open' )
				ORDER BY (b.course_id = %d) DESC, b.start_date DESC, b.id DESC LIMIT 100",
				(int) $current['user_id'],
				(int) $current['batch_id'],
				(int) $current['course_id'],
				(int) $current['course_id']
			),
			ARRAY_A
		);
		// phpcs:enable
		$targets = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$row['seats_left']       = max( 0, (int) $row['capacity'] - (int) $row['seats_taken'] );
			$row['price_differs']    = (float) $row['price'] !== (float) $current['price'];
			$row['already_enrolled'] = (bool) $row['already_enrolled'];
			$targets[]               = $row;
		}
		return $targets;
	}

	/**
	 * Moves one enrollment to another batch in a single transaction. Both batch rows are locked FOR UPDATE in ascending id
	 * order (the same order for every caller, so two opposite moves cannot deadlock). cc_applications.batch_id is left
	 * alone: the application stays the historical admission record. No money moves.
	 *
	 * @return true|WP_Error Error codes: cc_forbidden, cc_not_found, cc_no_change, cc_stale, cc_invalid_target, cc_batch_full, cc_duplicate, cc_db_error.
	 */
	public static function move_batch( int $enrollment_id, int $target_batch_id, int $actor ) {
		global $wpdb;
		if ( ! user_can( $actor, 'cc_manage_students' ) ) {
			return new WP_Error( 'cc_forbidden', 'You are not allowed to manage students.' );
		}
		$e = $wpdb->prefix . 'cc_enrollments';
		$b = $wpdb->prefix . 'cc_batches';
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names only.
		$current = $wpdb->get_row( $wpdb->prepare( "SELECT user_id, batch_id FROM {$e} WHERE id = %d", $enrollment_id ), ARRAY_A );
		if ( ! is_array( $current ) ) {
			return new WP_Error( 'cc_not_found', 'Enrollment not found.' );
		}
		$source = (int) $current['batch_id'];
		if ( $source === $target_batch_id ) {
			return new WP_Error( 'cc_no_change', 'The enrollment is already in that batch.' );
		}

		$lock_order = array( $source, $target_batch_id );
		sort( $lock_order );
		$wpdb->query( 'START TRANSACTION' );
		try {
			$locked  = $wpdb->get_results(
				$wpdb->prepare( "SELECT id, capacity, seats_taken, status FROM {$b} WHERE id IN (%d,%d) ORDER BY id ASC FOR UPDATE", $lock_order[0], $lock_order[1] ),
				ARRAY_A
			);
			$batches = array_column( is_array( $locked ) ? $locked : array(), null, 'id' );
			$fresh   = $wpdb->get_row( $wpdb->prepare( "SELECT batch_id FROM {$e} WHERE id = %d FOR UPDATE", $enrollment_id ), ARRAY_A );
			if ( ! is_array( $fresh ) || (int) $fresh['batch_id'] !== $source ) {
				return self::rollback( new WP_Error( 'cc_stale', 'The enrollment changed while you were editing. Reload and try again.' ) );
			}
			$target = $batches[ $target_batch_id ] ?? null;
			if ( null === $target || ! in_array( $target['status'], self::MOVABLE_STATUS, true ) ) {
				return self::rollback( new WP_Error( 'cc_invalid_target', 'That batch cannot take students (missing, draft or completed).' ) );
			}
			if ( (int) $target['seats_taken'] >= (int) $target['capacity'] ) {
				return self::rollback( new WP_Error( 'cc_batch_full', 'The target batch has no seats left.' ) );
			}
			$duplicate = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$e} WHERE user_id = %d AND batch_id = %d", (int) $current['user_id'], $target_batch_id ) );
			if ( null !== $duplicate ) {
				return self::rollback( new WP_Error( 'cc_duplicate', 'The student is already enrolled in the target batch.' ) );
			}
			$done = $wpdb->update( $e, array( 'batch_id' => $target_batch_id ), array( 'id' => $enrollment_id ) );
			$done = false !== $done && false !== $wpdb->query( $wpdb->prepare( "UPDATE {$b} SET seats_taken = seats_taken + 1 WHERE id = %d", $target_batch_id ) );
			if ( $done && isset( $batches[ $source ] ) ) {
				$done = false !== $wpdb->query( $wpdb->prepare( "UPDATE {$b} SET seats_taken = GREATEST(seats_taken, 1) - 1 WHERE id = %d", $source ) );
			}
			if ( ! $done ) {
				return self::rollback( new WP_Error( 'cc_db_error', 'The enrollment could not be moved.' ) );
			}
			$wpdb->query( 'COMMIT' );
		} catch ( Throwable $t ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'cc_db_error', 'The enrollment could not be moved.' );
		}
		// phpcs:enable
		CC_Audit::log( 'student.batch_move', 'enrollment', $enrollment_id, array( 'from_batch' => $source, 'to_batch' => $target_batch_id ) );
		return true;
	}

	private static function rollback( WP_Error $error ): WP_Error {
		global $wpdb;
		$wpdb->query( 'ROLLBACK' );
		return $error;
	}

	/* ---------------------------------------------------------------- bulk */

	/** @param mixed $raw @return int[] Positive, unique ids of existing students, at most MAX_BULK. */
	public static function clean_user_ids( $raw ): array {
		global $wpdb;
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $raw ) ) ) );
		$ids = array_slice( $ids, 0, self::MAX_BULK );
		if ( ! $ids ) {
			return array();
		}
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- table name and %d placeholders only.
		$found = $wpdb->get_col( $wpdb->prepare( 'SELECT user_id FROM ' . $wpdb->prefix . 'cc_students WHERE user_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', $ids ) );
		// phpcs:enable
		return array_map( 'intval', is_array( $found ) ? $found : array() );
	}

	/**
	 * Active enrollments a bulk deactivation would touch: those of the given students, limited to one batch when
	 * $batch_scope > 0, otherwise every batch.
	 *
	 * @param int[] $user_ids
	 * @return array<int,array{id:int,user_id:int}>
	 */
	public static function active_enrollments( array $user_ids, int $batch_scope ): array {
		global $wpdb;
		if ( ! $user_ids ) {
			return array();
		}
		$sql    = 'SELECT id, user_id FROM ' . $wpdb->prefix . "cc_enrollments WHERE status = 'active' AND user_id IN (" . implode( ',', array_fill( 0, count( $user_ids ), '%d' ) ) . ')';
		$params = $user_ids;
		if ( $batch_scope > 0 ) {
			$sql     .= ' AND batch_id = %d';
			$params[] = $batch_scope;
		}
		$rows = $wpdb->get_results( $wpdb->prepare( $sql . ' ORDER BY id', $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name and placeholders only.
		return array_map( static fn( array $r ): array => array( 'id' => (int) $r['id'], 'user_id' => (int) $r['user_id'] ), is_array( $rows ) ? $rows : array() );
	}

	/** @param int[] $user_ids @return int Number of enrollments deactivated (each one audited by set_enrollment_status). */
	public static function deactivate_selected( array $user_ids, int $batch_scope, int $actor ): int {
		if ( ! user_can( $actor, 'cc_manage_students' ) ) {
			return 0;
		}
		$done = 0;
		foreach ( self::active_enrollments( $user_ids, $batch_scope ) as $enrollment ) {
			if ( true === CC_Admin_Students::set_enrollment_status( $enrollment['id'], 'deactivated', $actor ) ) {
				++$done;
			}
		}
		return $done;
	}

	/** @param int[] $user_ids @return int[] Distinct batch ids of the students' ACTIVE enrollments. */
	public static function audience_batches( array $user_ids ): array {
		global $wpdb;
		if ( ! $user_ids ) {
			return array();
		}
		$sql = 'SELECT DISTINCT batch_id FROM ' . $wpdb->prefix . "cc_enrollments WHERE status = 'active' AND user_id IN (" . implode( ',', array_fill( 0, count( $user_ids ), '%d' ) ) . ') ORDER BY batch_id';
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( $sql, $user_ids ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name and placeholders only.
	}

	/**
	 * Parks a selection server-side so the confirm page does not carry ids in the URL. Bound to the actor, single use.
	 *
	 * @param int[] $user_ids
	 */
	public static function stage_bulk( array $user_ids, int $batch_scope, int $actor ): string {
		$token = wp_generate_password( 24, false );
		set_transient( self::BULK_KEY . $token, array( 'actor' => $actor, 'user_ids' => $user_ids, 'batch' => $batch_scope ), self::BULK_TTL );
		return $token;
	}

	/** @return array{actor:int,user_ids:int[],batch:int}|null */
	public static function staged_bulk( string $token, int $actor ): ?array {
		$data = '' === $token ? false : get_transient( self::BULK_KEY . $token );
		return is_array( $data ) && (int) $data['actor'] === $actor ? $data : null;
	}

	public static function discard_bulk( string $token ): void {
		delete_transient( self::BULK_KEY . $token );
	}
}
