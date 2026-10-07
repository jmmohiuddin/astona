<?php
defined( 'ABSPATH' ) || exit;

/**
 * Turns a settled (approved) application into a student account, an enrollment and one SMS.
 *
 * Recovery rule: every step is idempotent and the credentials/enrolled SMS is the last step. A user created
 * by this application is tagged with user meta CC_Provisioner::CREATED_BY_META = application id. On a retry,
 * if that user exists but no SMS has been queued for the application (cc_sms_log related_type
 * 'application', related_id = application id), the temp password was never delivered, so it is reset once
 * more and the SMS queued. Once an SMS row exists nothing is touched again. A replayed hook therefore
 * yields exactly one user, one enrollment and one SMS. A row that exists but has status 'failed' is an
 * SMS-delivery problem (the student can use OTP login); provisioning does not resend.
 *
 * Attach rule: an application is attached to an EXISTING student account only when its phone_verified_at is set (the
 * applicant proved the phone by OTP). Otherwise nothing is created or sent, one 'application.attach_blocked' audit row is
 * written and the Applications admin screen flags it for manual review; the application stays approved and paid.
 * Staff can resolve a blocked application by phoning the number on file (CC_Admin_Applications::attach_override()): that
 * sets phone_verified_at too, so from then on the column means "phone proven by OTP OR verified by staff"; the staff
 * case is told apart by its 'application.attach_override' audit row.
 */
final class CC_Provisioner {

	const HOOK            = 'cc_provision_student';
	const GROUP           = 'cc';
	const CREATED_BY_META = 'cc_provisioned_by_application';
	const SMS_TYPE        = 'application';
	const TEMP_PW_LENGTH  = 10;
	const TEMP_PW_CHARS   = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
	const TEMP_PW_TTL     = 72 * HOUR_IN_SECONDS;
	const LOCK_TIMEOUT    = 10;
	const MAX_ATTEMPTS    = 5;
	const RETRY_BASE      = 60;

	const BLOCKED_ACTION  = 'application.attach_blocked';

	public static function init(): void {
		add_action( 'cc_application_settled', array( __CLASS__, 'schedule' ), 10, 2 );
		add_action( self::HOOK, array( __CLASS__, 'run_scheduled' ), 10, 2 );
	}

	public static function schedule( $application_id, $payment_id = 0 ): void {
		self::enqueue( (int) $application_id, 1, 0 );
	}

	/**
	 * Recovery path: queues provisioning for an application unless an attempt is already pending.
	 *
	 * @return bool True when a new action was queued.
	 */
	public static function ensure_enqueued( int $application_id ): bool {
		for ( $attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++ ) {
			$args = array( $application_id, $attempt );
			if ( function_exists( 'as_has_scheduled_action' ) ? as_has_scheduled_action( self::HOOK, $args, self::GROUP ) : false !== wp_next_scheduled( self::HOOK, $args ) ) {
				return false;
			}
		}
		self::enqueue( $application_id, 1, 0 );
		return true;
	}

	private static function enqueue( int $application_id, int $attempt, int $delay ): void {
		$args = array( $application_id, $attempt );
		if ( function_exists( 'as_enqueue_async_action' ) && function_exists( 'as_schedule_single_action' ) ) {
			if ( 0 === $delay ) {
				as_enqueue_async_action( self::HOOK, $args, self::GROUP, true );
			} else {
				as_schedule_single_action( time() + $delay, self::HOOK, $args, self::GROUP, true );
			}
			return;
		}
		wp_schedule_single_event( time() + $delay, self::HOOK, $args );
	}

	/** Scheduler entry point: failures are logged without secrets and retried with exponential backoff. */
	public static function run_scheduled( $application_id, $attempt = 1 ): void {
		$application_id = (int) $application_id;
		$attempt        = max( 1, (int) $attempt );
		try {
			self::provision( $application_id );
		} catch ( Throwable $e ) {
			// Exception text can carry DB errors or applicant data; log only the id, attempt and exception class.
			error_log( sprintf( 'cc provisioning failed for application %d (attempt %d): %s', $application_id, $attempt, get_class( $e ) ) );
			if ( $attempt < self::MAX_ATTEMPTS ) {
				self::enqueue( $application_id, $attempt + 1, self::RETRY_BASE * ( 2 ** $attempt ) );
			}
		}
	}

	/** The per-application MySQL mutex; CC_Refund takes it too so a refund and provisioning never interleave. */
	public static function lock_name( int $application_id ): string {
		return 'cc_provision_' . $application_id;
	}

	/**
	 * @return array{user_id:int,created:bool,enrollment_id:int,blocked:bool} created = this application created the user;
	 *         blocked = refused to attach to an existing student (phone not verified), user_id and enrollment_id are 0.
	 * @throws RuntimeException When the application is not approved, the login belongs to a non-student, or a step fails.
	 */
	public static function provision( int $application_id ): array {
		global $wpdb;
		$lock = self::lock_name( $application_id );
		if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock, self::LOCK_TIMEOUT ) ) ) {
			throw new RuntimeException( 'Provisioning is already running for this application.' );
		}
		try {
			return self::provision_locked( $application_id );
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	private static function provision_locked( int $application_id ): array {
		global $wpdb;
		$application = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . 'cc_applications WHERE id = %d', $application_id ), ARRAY_A );
		if ( null === $application || 'approved' !== $application['status'] ) {
			throw new RuntimeException( 'Application is missing or not approved.' );
		}
		$phone = CC_Phone::normalize( (string) $application['student_phone'] );
		if ( null === $phone ) {
			throw new RuntimeException( 'Application has an invalid student phone.' );
		}

		CC_Roles::ensure_role();
		$login   = ltrim( $phone, '+' );
		$user    = get_user_by( 'login', $login );
		$created  = $user instanceof WP_User && (int) get_user_meta( $user->ID, self::CREATED_BY_META, true ) === $application_id;
		$password = null;

		if ( ! $user instanceof WP_User ) {
			$password = self::temp_password();
			$user_id  = self::create_user( $login, $password, $application );
			$created  = true;
		} elseif ( $created ) {
			$user_id = (int) $user->ID;
		} elseif ( CC_Roles::is_student( $user ) ) {
			// Attaching to a registered student is safe only for an application whose phone was proven by OTP.
			if ( null === $application['phone_verified_at'] ) {
				self::block_attach( $application_id );
				return array( 'user_id' => 0, 'created' => false, 'enrollment_id' => 0, 'blocked' => true );
			}
			$user_id = (int) $user->ID;
		} else {
			throw new RuntimeException( 'A non-student account already uses this login.' );
		}

		if ( $created ) {
			CC_Enrollment_Repository::create_student(
				$user_id,
				(string) $application['full_name'],
				(string) $application['guardian_name'],
				(string) $application['guardian_phone'],
				(string) $application['institution'],
				null === $application['photo_path'] ? null : (string) $application['photo_path'],
				gmdate( 'Y-m-d H:i:s', time() + self::TEMP_PW_TTL )
			);
		}

		$enrollment_id = CC_Enrollment_Repository::create( $user_id, (int) $application['batch_id'], $application_id );
		if ( false === $wpdb->update( $wpdb->prefix . 'cc_applications', array( 'user_id' => $user_id ), array( 'id' => $application_id ) ) ) {
			throw new RuntimeException( 'Could not link application to user.' );
		}

		if ( ! self::sms_queued( $application_id ) ) {
			if ( $created ) {
				self::queue_credentials( $user_id, $phone, $password, $application_id );
			} else {
				self::queue_enrolled( $user_id, $phone, (int) $application['batch_id'], $application_id );
			}
		}

		return array(
			'user_id'       => $user_id,
			'created'       => $created,
			'enrollment_id' => $enrollment_id,
			'blocked'       => false,
		);
	}

	/** Records (once per application, no personal data) that an unproven application was kept away from an existing account. */
	private static function block_attach( int $application_id ): void {
		global $wpdb;
		$logged = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . $wpdb->prefix . 'cc_audit_log WHERE action = %s AND entity_type = %s AND entity_id = %d LIMIT 1', self::BLOCKED_ACTION, 'application', $application_id ) );
		if ( null === $logged ) {
			CC_Audit::log( self::BLOCKED_ACTION, 'application', $application_id, array(), 'phone not verified' );
		}
	}

	/** @return int[] Approved applications still waiting for manual review because attaching them was refused. */
	public static function blocked_application_ids(): array {
		global $wpdb;
		$p = $wpdb->prefix;
		return array_map(
			'intval',
			$wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT a.id FROM {$p}cc_applications a JOIN {$p}cc_audit_log l ON l.entity_id = a.id AND l.entity_type = 'application' AND l.action = %s
					WHERE a.status = 'approved' AND a.phone_verified_at IS NULL AND a.user_id IS NULL ORDER BY a.id",
					self::BLOCKED_ACTION
				)
			)
		);
	}

	/**
	 * One-off backfill report: pending/approved applications without a verified phone whose student phone is already a
	 * student login. Prints counts and application ids only. Run: wp eval 'CC_Provisioner::audit_unverified_pending();'
	 *
	 * @return array{pending:int[],approved:int[]}
	 */
	public static function audit_unverified_pending(): array {
		global $wpdb;
		$found = array( 'pending' => array(), 'approved' => array() );
		$rows  = $wpdb->get_results( 'SELECT id, status, student_phone FROM ' . $wpdb->prefix . "cc_applications WHERE status IN ('pending','approved') AND phone_verified_at IS NULL ORDER BY id", ARRAY_A );
		foreach ( $rows as $row ) {
			$phone = CC_Phone::normalize( (string) $row['student_phone'] );
			$user  = null === $phone ? false : get_user_by( 'login', ltrim( $phone, '+' ) );
			if ( $user instanceof WP_User && CC_Roles::is_student( $user ) ) {
				$found[ $row['status'] ][] = (int) $row['id'];
			}
		}
		foreach ( $found as $status => $ids ) {
			echo sprintf( "%s with unverified phone matching an existing student: %d%s\n", $status, count( $ids ), $ids ? ' (ids ' . implode( ',', $ids ) . ')' : '' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI output.
		}
		return $found;
	}

	/**
	 * Staff-initiated reset: new temp password, 72h expiry, all sessions (and their OTP reauth state) destroyed.
	 *
	 * @return int|WP_Error The cc_sms_log id of the queued credentials SMS.
	 */
	public static function reissue_credentials( int $user_id ) {
		$user = get_userdata( $user_id );
		if ( ! $user instanceof WP_User || ! CC_Roles::is_student( $user ) ) {
			return new WP_Error( 'cc_not_student', 'Credentials can only be re-issued for student accounts.' );
		}
		$phone    = '+' . $user->user_login;
		$password = self::temp_password();
		wp_set_password( $password, $user_id );
		CC_Enrollment_Repository::require_password_change( $user_id, gmdate( 'Y-m-d H:i:s', time() + self::TEMP_PW_TTL ) );
		WP_Session_Tokens::get_instance( $user_id )->destroy_all();
		return CC_Sms::queue(
			$phone,
			'credentials',
			array(
				'phone'    => $phone,
				'password' => $password,
				'url'      => home_url( '/student/login/' ),
			),
			'student',
			$user_id
		);
	}

	private static function create_user( string $login, string $password, array $application ): int {
		// The applicant's email is unverified, so it must never become the login email (account pre-takeover).
		$meta  = array( self::CREATED_BY_META => (int) $application['id'] );
		$email = (string) $application['email'];
		if ( '' !== $email && is_email( $email ) ) {
			$meta['cc_application_email'] = $email;
		}
		$user_id = wp_insert_user(
			array(
				'user_login'           => $login,
				'user_pass'            => $password,
				'user_email'           => $login . '@students.invalid',
				'display_name'         => (string) $application['full_name'],
				'role'                 => CC_Roles::ROLE,
				'show_admin_bar_front' => 'false',
				'meta_input'           => $meta,
			)
		);
		if ( is_wp_error( $user_id ) ) {
			throw new RuntimeException( 'User creation failed: ' . $user_id->get_error_code() );
		}
		return (int) $user_id;
	}

	/** Retry path: credentials were never delivered, so a fresh temp password is safe. */
	private static function queue_credentials( int $user_id, string $phone, ?string $password, int $application_id ): void {
		if ( null === $password ) {
			$password = self::temp_password();
			wp_set_password( $password, $user_id );
		}
		CC_Enrollment_Repository::require_password_change( $user_id, gmdate( 'Y-m-d H:i:s', time() + self::TEMP_PW_TTL ) );
		CC_Sms::queue(
			$phone,
			'credentials',
			array(
				'phone'     => $phone,
				'password'  => $password,
				'url'       => home_url( '/student/login/' ),
			),
			self::SMS_TYPE,
			$application_id
		);
	}

	private static function queue_enrolled( int $user_id, string $phone, int $batch_id, int $application_id ): void {
		$title = '';
		foreach ( CC_Enrollment_Repository::for_user( $user_id ) as $row ) {
			if ( (int) $row['batch_id'] === $batch_id ) {
				$title = (string) $row['course_title'];
			}
		}
		CC_Sms::queue(
			$phone,
			'enrolled',
			array(
				'course'    => $title,
				'url'       => home_url( '/student/login/' ),
			),
			self::SMS_TYPE,
			$application_id
		);
	}

	private static function sms_queued( int $application_id ): bool {
		global $wpdb;
		$found = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT id FROM ' . $wpdb->prefix . "cc_sms_log WHERE related_type = %s AND related_id = %d AND template IN ('credentials','enrolled') LIMIT 1",
				self::SMS_TYPE,
				$application_id
			)
		);
		return null !== $found;
	}

	private static function temp_password(): string {
		$out = '';
		$max = strlen( self::TEMP_PW_CHARS ) - 1;
		for ( $i = 0; $i < self::TEMP_PW_LENGTH; $i++ ) {
			$out .= self::TEMP_PW_CHARS[ random_int( 0, $max ) ];
		}
		return $out;
	}
}
