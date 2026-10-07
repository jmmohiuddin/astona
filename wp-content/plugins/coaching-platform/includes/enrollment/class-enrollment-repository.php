<?php
defined( 'ABSPATH' ) || exit;

/**
 * Data access for cc_students and cc_enrollments.
 */
final class CC_Enrollment_Repository {

	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'cc_' . $name;
	}

	/** Idempotent by application_id; returns the enrollment id. */
	public static function create( int $user_id, int $batch_id, int $application_id ): int {
		global $wpdb;
		$table = self::table( 'enrollments' );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table} (user_id, batch_id, application_id, status, enrolled_at, expires_at) VALUES (%d, %d, %d, 'active', %s, NULL)",
				$user_id,
				$batch_id,
				$application_id,
				gmdate( 'Y-m-d H:i:s' )
			)
		);
		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE application_id = %d", $application_id ) );
		// phpcs:enable
		if ( 0 === $id ) {
			throw new RuntimeException( 'Enrollment could not be created (user/batch already enrolled by another application?).' );
		}
		return $id;
	}

	public static function find_by_application( int $application_id ): ?array {
		global $wpdb;
		$table = self::table( 'enrollments' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE application_id = %d", $application_id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/** @return array<int,array> Enrollments with batch and course details, newest first. */
	public static function for_user( int $user_id ): array {
		global $wpdb;
		$e = self::table( 'enrollments' );
		$b = self::table( 'batches' );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names only.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT e.id, e.batch_id, e.application_id, e.status, e.enrolled_at, e.expires_at,
					b.name AS batch_name, b.course_id, p.post_title AS course_title,
					b.schedule_text, b.delivery_mode, b.start_date, b.end_date
				FROM {$e} e
				JOIN {$b} b ON b.id = e.batch_id
				LEFT JOIN {$wpdb->posts} p ON p.ID = b.course_id
				WHERE e.user_id = %d
				ORDER BY e.enrolled_at DESC, e.id DESC",
				$user_id
			),
			ARRAY_A
		);
		// phpcs:enable
		return is_array( $rows ) ? $rows : array();
	}

	public static function user_has_active( int $user_id, int $batch_id ): bool {
		global $wpdb;
		$table = self::table( 'enrollments' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE user_id = %d AND batch_id = %d AND status = 'active' AND (expires_at IS NULL OR expires_at > %s) LIMIT 1",
				$user_id,
				$batch_id,
				gmdate( 'Y-m-d H:i:s' )
			)
		);
		return null !== $found;
	}

	public static function student_row( int $user_id ): ?array {
		global $wpdb;
		$table = self::table( 'students' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d", $user_id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/** Idempotent: an existing row is left untouched. */
	public static function create_student( int $user_id, string $full_name, string $guardian_name, string $guardian_phone, string $institution, ?string $photo_path, string $temp_pw_expires ): void {
		global $wpdb;
		$table = self::table( 'students' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ok = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table} (user_id, full_name, guardian_name, guardian_phone, institution, photo_path, must_change_pw, temp_pw_expires, created_at) VALUES (%d, %s, %s, %s, %s, %s, 1, %s, %s)",
				$user_id,
				$full_name,
				$guardian_name,
				$guardian_phone,
				$institution,
				$photo_path,
				$temp_pw_expires,
				gmdate( 'Y-m-d H:i:s' )
			)
		);
		if ( false === $ok ) {
			throw new RuntimeException( 'Student row insert failed.' );
		}
	}

	public static function require_password_change( int $user_id, string $temp_pw_expires ): void {
		global $wpdb;
		$ok = $wpdb->update(
			self::table( 'students' ),
			array(
				'must_change_pw'  => 1,
				'temp_pw_expires' => $temp_pw_expires,
			),
			array( 'user_id' => $user_id )
		);
		if ( false === $ok ) {
			throw new RuntimeException( 'Student update failed.' );
		}
	}

	public static function mark_pw_changed( int $user_id ): void {
		global $wpdb;
		$table = self::table( 'students' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only; NULL cannot go through update().
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET must_change_pw = 0, temp_pw_expires = NULL WHERE user_id = %d", $user_id ) );
	}
}
