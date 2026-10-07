<?php
defined( 'ABSPATH' ) || exit;

/**
 * Read models for the student portal. Every query is scoped to the given user's own applications.
 */
final class CC_Portal_Data {

	const MSG_ACCESS_ENDED = 'Your access to this batch has ended. Please contact us.';
	const ENDED_STATUSES   = array( 'deactivated', 'expired' );

	/** @return array{enrollments:array<int,array<string,mixed>>,schedule:array<string,mixed>,notices:array<string,mixed>} */
	public static function dashboard( int $user_id ): array {
		return array(
			'enrollments' => array_map( array( __CLASS__, 'mark_access_ended' ), CC_Enrollment_Repository::for_user( $user_id ) ),
			'schedule'    => CC_Portal_Courses::schedule( $user_id ),
			'notices'     => array(
				'title' => 'Notices',
				'items' => CC_Portal_Courses::dashboard_notices( $user_id ),
			),
		);
	}

	/**
	 * Deactivated/expired enrollments keep only their identity and course title so no batch details reach the template.
	 *
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	private static function mark_access_ended( array $row ): array {
		if ( ! in_array( (string) $row['status'], self::ENDED_STATUSES, true ) ) {
			return array_merge( $row, array( 'access_ended' => false ) );
		}
		return array(
			'id'           => $row['id'],
			'status'       => $row['status'],
			'course_title' => $row['course_title'],
			'access_ended' => true,
			'message'      => self::MSG_ACCESS_ENDED,
		);
	}

	/** @return array<int,array<string,mixed>> */
	public static function payments( int $user_id ): array {
		global $wpdb;
		$p = $wpdb->prefix;
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names only.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pay.id AS payment_id, pay.amount, pay.status, pay.trx_id, pay.method, pay.created_at, pay.settled_at,
					inv.number AS invoice_number, inv.currency
				FROM {$p}cc_applications app
				JOIN {$p}cc_invoices inv ON inv.application_id = app.id
				JOIN {$p}cc_payments pay ON pay.invoice_id = inv.id
				WHERE app.user_id = %d
				ORDER BY pay.created_at DESC, pay.id DESC
				LIMIT 200",
				$user_id
			),
			ARRAY_A
		);
		// phpcs:enable
		return is_array( $rows ) ? $rows : array();
	}

	/** @return array<string,mixed>|null Null unless the completed payment belongs to the user's own application. */
	public static function receipt( int $user_id, int $payment_id ): ?array {
		global $wpdb;
		if ( $user_id <= 0 || $payment_id <= 0 ) {
			return null;
		}
		$p = $wpdb->prefix;
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names only.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT pay.id AS payment_id, pay.amount, pay.trx_id, pay.method, pay.settled_at,
					inv.number AS invoice_number, inv.currency,
					app.full_name AS student_name, app.student_phone, app.batch_id
				FROM {$p}cc_applications app
				JOIN {$p}cc_invoices inv ON inv.application_id = app.id
				JOIN {$p}cc_payments pay ON pay.invoice_id = inv.id
				WHERE pay.id = %d AND app.user_id = %d AND pay.status = 'completed'
				LIMIT 1",
				$payment_id,
				$user_id
			),
			ARRAY_A
		);
		// phpcs:enable
		if ( ! is_array( $row ) ) {
			return null;
		}
		$batch               = CC_Batch_Repository::find( (int) $row['batch_id'] );
		$course              = $batch ? get_post( (int) $batch['course_id'] ) : null;
		$row['batch_name']   = $batch ? (string) $batch['name'] : '';
		$row['course_title'] = $course ? get_the_title( $course ) : '';
		return $row;
	}

	/** @return array<string,mixed> */
	public static function profile( int $user_id ): array {
		$user    = get_userdata( $user_id );
		$student = CC_Enrollment_Repository::student_row( $user_id ) ?? array();
		$email   = $user ? (string) $user->user_email : '';
		if ( $user && str_ends_with( $email, '@students.invalid' ) ) {
			$email = (string) get_user_meta( $user_id, 'cc_application_email', true );
		}
		return array(
			'full_name'      => (string) ( $student['full_name'] ?? ( $user ? $user->display_name : '' ) ),
			'phone'          => $user ? '+' . preg_replace( '/\D+/', '', (string) $user->user_login ) : '',
			'guardian_name'  => (string) ( $student['guardian_name'] ?? '' ),
			'guardian_phone' => (string) ( $student['guardian_phone'] ?? '' ),
			'email'          => $email,
		);
	}

	/**
	 * Validates and stores guardian name/phone and email.
	 *
	 * @param array<string,mixed> $input
	 * @return array<string,mixed>|WP_Error The saved profile, or WP_Error code validation_failed with details per field.
	 */
	public static function update_profile( int $user_id, array $input ) {
		global $wpdb;
		$errors = array();
		$update = array();

		if ( array_key_exists( 'guardian_name', $input ) ) {
			$name = trim( sanitize_text_field( (string) $input['guardian_name'] ) );
			if ( '' === $name || mb_strlen( $name ) > 190 ) {
				$errors['guardian_name'] = 'Enter the guardian name (up to 190 characters).';
			} else {
				$update['guardian_name'] = $name;
			}
		}
		if ( array_key_exists( 'guardian_phone', $input ) ) {
			$phone = CC_Phone::normalize( (string) $input['guardian_phone'] );
			if ( null === $phone ) {
				$errors['guardian_phone'] = 'Enter a valid Bangladesh mobile number.';
			} else {
				$update['guardian_phone'] = $phone;
			}
		}
		$email = null;
		if ( array_key_exists( 'email', $input ) ) {
			$raw = trim( (string) $input['email'] );
			if ( '' !== $raw && ( ! is_email( $raw ) || strlen( $raw ) > 190 ) ) {
				$errors['email'] = 'Enter a valid email address or leave it empty.';
			} elseif ( '' !== $raw ) {
				$email = sanitize_email( $raw );
			} else {
				$email = '';
			}
		}
		if ( $errors ) {
			return new WP_Error( 'validation_failed', 'Please correct the highlighted fields.', array( 'status' => 422, 'fields' => $errors ) );
		}

		if ( $update ) {
			$wpdb->update( $wpdb->prefix . 'cc_students', $update, array( 'user_id' => $user_id ) );
		}
		if ( '' === $email ) {
			delete_user_meta( $user_id, 'cc_application_email' );
		} elseif ( null !== $email ) {
			// Unverified until confirmed. A collision with another account is skipped silently so the response never reveals registered emails.
			$other = email_exists( $email );
			if ( ! $other || (int) $other === $user_id ) {
				update_user_meta( $user_id, 'cc_application_email', $email );
			}
		}
		return self::profile( $user_id );
	}

	/** Status chip class key for an enrollment status. */
	public static function enrollment_chip( string $status ): string {
		return 'active' === $status ? 'open' : ( 'completed' === $status ? 'filling' : 'closed' );
	}
}
