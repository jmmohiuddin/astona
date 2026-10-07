<?php
defined( 'ABSPATH' ) || exit;

/**
 * Data retention (TRD OQ-10 proposal, to be confirmed by counsel against Bangladeshi data-protection rules):
 *  - Rejected and cancelled applications: personal details scrubbed (and the photo deleted) 12 months after the last change.
 *  - Students: 3 years after the last batch they were in ended, the account, profile and photo are deleted and their
 *    applications scrubbed. Payment, invoice and receipt rows carry no personal details and stay.
 *  - Payment and invoice records: deleted 7 years after the payment was settled (tax record keeping).
 *  - SMS delivery log and live-class join log: 12 months.
 * Destructive steps run only when "Delete old personal data automatically" is switched on in Settings. Until then the daily
 * job only counts what WOULD go (shown in Settings), so the owner can review before enabling it.
 * Manual run: wp cc retention [--apply]
 */
final class CC_Retention {

	const OPTION_ENABLED = 'cc_retention_enabled';
	const OPTION_LAST    = 'cc_retention_last';

	const MONTHS_APPLICATIONS = 12;
	const MONTHS_STUDENTS     = 36;
	const MONTHS_FINANCIAL    = 84;
	const MONTHS_LOGS         = 12;
	const BATCH_LIMIT         = 200;

	public static function init(): void {
		add_action( 'cc_daily', array( __CLASS__, 'daily' ), 30 );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'cc retention', array( __CLASS__, 'cli' ) );
		}
	}

	public static function enabled(): bool {
		return '1' === (string) get_option( self::OPTION_ENABLED, '0' );
	}

	public static function daily(): void {
		$result = self::run( self::enabled() );
		update_option( self::OPTION_LAST, array( 'at' => gmdate( 'Y-m-d H:i:s' ), 'applied' => self::enabled() ) + $result, false );
	}

	/** wp cc retention [--apply] */
	public static function cli( array $args, array $assoc ): void {
		$result = self::run( ! empty( $assoc['apply'] ) );
		WP_CLI::log( wp_json_encode( $result ) );
		WP_CLI::success( ! empty( $assoc['apply'] ) ? 'Retention applied.' : 'Dry run only. Add --apply to delete.' );
	}

	/** Unix cut-off for "older than N months". */
	public static function cutoff( int $months, ?int $now = null ): string {
		return gmdate( 'Y-m-d H:i:s', ( $now ?? time() ) - (int) round( $months * 30.4375 * DAY_IN_SECONDS ) );
	}

	/**
	 * @param bool $apply False counts only.
	 * @return array{applications:int,students:int,financial:int,logs:int}
	 */
	public static function run( bool $apply, ?int $now = null ): array {
		$out = array(
			'applications' => self::applications( $apply, $now ),
			'students'     => self::students( $apply, $now ),
			'financial'    => self::financial( $apply, $now ),
			'logs'         => self::logs( $apply, $now ),
		);
		if ( $apply && array_sum( $out ) > 0 && class_exists( 'CC_Audit' ) ) {
			CC_Audit::log( 'retention.run', 'system', 0, $out );
		}
		return $out;
	}

	/** Rejected/cancelled applications past retention that still hold personal details. */
	private static function applications( bool $apply, ?int $now ): int {
		global $wpdb;
		$p   = $wpdb->prefix;
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$p}cc_applications WHERE status IN ('rejected','cancelled') AND updated_at < %s AND full_name <> 'Removed' ORDER BY id LIMIT %d",
				self::cutoff( self::MONTHS_APPLICATIONS, $now ),
				self::BATCH_LIMIT
			)
		);
		if ( $apply ) {
			foreach ( $ids as $id ) {
				self::scrub_application( (int) $id );
			}
		}
		return count( $ids );
	}

	/** Students whose every enrollment ended more than 36 months ago. */
	private static function students( bool $apply, ?int $now ): int {
		global $wpdb;
		$p       = $wpdb->prefix;
		$cutoff  = self::cutoff( self::MONTHS_STUDENTS, $now );
		$user_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT e.user_id FROM {$p}cc_enrollments e JOIN {$p}cc_batches b ON b.id = e.batch_id
				JOIN {$wpdb->usermeta} um ON um.user_id = e.user_id AND um.meta_key = %s AND um.meta_value LIKE %s
				GROUP BY e.user_id
				HAVING SUM( e.status = 'active' ) = 0 AND MAX( COALESCE( e.expires_at, b.end_date, b.start_date ) ) < %s
				ORDER BY e.user_id LIMIT %d",
				$wpdb->prefix . 'capabilities',
				'%' . $wpdb->esc_like( '"' . CC_Roles::ROLE . '"' ) . '%',
				$cutoff,
				self::BATCH_LIMIT
			)
		);
		if ( $apply ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			foreach ( $user_ids as $user_id ) {
				self::erase_student( (int) $user_id );
			}
		}
		return count( $user_ids );
	}

	/** Payments settled over 7 years ago, with their events, invoices and the (already scrubbed) application row. */
	private static function financial( bool $apply, ?int $now ): int {
		global $wpdb;
		$p       = $wpdb->prefix;
		$cutoff  = self::cutoff( self::MONTHS_FINANCIAL, $now );
		$invoices = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT i.id FROM {$p}cc_invoices i
				WHERE NOT EXISTS ( SELECT 1 FROM {$p}cc_payments pay WHERE pay.invoice_id = i.id AND COALESCE( pay.settled_at, pay.updated_at ) >= %s )
				AND EXISTS ( SELECT 1 FROM {$p}cc_payments pay WHERE pay.invoice_id = i.id )
				ORDER BY i.id LIMIT %d",
				$cutoff,
				self::BATCH_LIMIT
			)
		);
		if ( $apply ) {
			foreach ( $invoices as $invoice_id ) {
				$application_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT application_id FROM {$p}cc_invoices WHERE id = %d", $invoice_id ) );
				$wpdb->query( $wpdb->prepare( "DELETE ev FROM {$p}cc_payment_events ev JOIN {$p}cc_payments pay ON pay.id = ev.payment_id WHERE pay.invoice_id = %d", $invoice_id ) );
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}cc_payments WHERE invoice_id = %d", $invoice_id ) );
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}cc_invoices WHERE id = %d", $invoice_id ) );
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}cc_applications WHERE id = %d AND full_name = 'Removed'", $application_id ) );
			}
		}
		return count( $invoices );
	}

	private static function logs( bool $apply, ?int $now ): int {
		global $wpdb;
		$p      = $wpdb->prefix;
		$cutoff = self::cutoff( self::MONTHS_LOGS, $now );
		$count  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}cc_sms_log WHERE created_at < %s", $cutoff ) )
			+ (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}cc_join_log WHERE joined_at < %s", $cutoff ) );
		if ( $apply && $count > 0 ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}cc_sms_log WHERE created_at < %s", $cutoff ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}cc_join_log WHERE joined_at < %s", $cutoff ) );
		}
		return $count;
	}

	/** Replaces every personal field with a placeholder and deletes the photo; keeps the row for the invoice that points at it. */
	public static function scrub_application( int $application_id ): void {
		global $wpdb;
		$p     = $wpdb->prefix;
		$photo = (string) $wpdb->get_var( $wpdb->prepare( "SELECT photo_path FROM {$p}cc_applications WHERE id = %d", $application_id ) );
		if ( '' !== $photo ) {
			CC_Photo_Store::delete( $photo );
		}
		$wpdb->update(
			"{$p}cc_applications",
			array(
				'full_name'        => 'Removed',
				'student_phone'    => '+000' . $application_id,
				'dob'              => '1900-01-01',
				'id_doc_enc'       => '',
				'photo_path'       => null,
				'guardian_name'    => 'Removed',
				'guardian_phone'   => '+000',
				'email'            => null,
				'institution'      => 'Removed',
				'roll_no'          => null,
				'rejection_reason' => null,
				'user_id'          => null,
			),
			array( 'id' => $application_id )
		);
	}

	/** Deletes the account and profile, then scrubs every application that belonged to it. */
	public static function erase_student( int $user_id ): void {
		global $wpdb;
		$p     = $wpdb->prefix;
		$user  = get_userdata( $user_id );
		if ( ! $user || ! in_array( CC_Roles::ROLE, (array) $user->roles, true ) ) {
			return; // Never delete anything that is not a student account.
		}
		$apps  = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$p}cc_applications WHERE user_id = %d", $user_id ) );
		$photo = (string) $wpdb->get_var( $wpdb->prepare( "SELECT photo_path FROM {$p}cc_students WHERE user_id = %d", $user_id ) );
		if ( '' !== $photo ) {
			CC_Photo_Store::delete( $photo );
		}
		foreach ( $apps as $app_id ) {
			self::scrub_application( (int) $app_id );
		}
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}cc_enrollments WHERE user_id = %d", $user_id ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}cc_students WHERE user_id = %d", $user_id ) );
		wp_delete_user( $user_id );
	}
}
