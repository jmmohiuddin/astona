<?php
defined( 'ABSPATH' ) || exit;

/**
 * Waitlist for full batches (PRD open question; default policy, owner may change the constants/settings):
 *  - A batch with "Waitlist when full" accepts applications beyond capacity as status `waitlisted` (no payment yet).
 *  - Staff offer a seat, or it is offered automatically in first-come order when a seat frees up (refund, capacity
 *    increase). An offer turns the application `pending`; the applicant pays through the normal flow.
 *  - Seats are NOT held before payment. An offer counts against free seats while it is outstanding, so one seat is
 *    never offered twice, and it lapses after OFFER_HOURS without payment (the application is cancelled and the next
 *    person is offered the seat).
 */
final class CC_Waitlist {

	const OFFER_HOURS_DEFAULT = 72;

	/** Waitlisted applications ahead of or equal to this one in the same batch, oldest first. */
	public static function position( int $application_id ): int {
		global $wpdb;
		$p   = $wpdb->prefix;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT batch_id, waitlisted_at, id FROM {$p}cc_applications WHERE id = %d AND status = 'waitlisted'", $application_id ), ARRAY_A );
		if ( ! is_array( $row ) ) {
			return 0;
		}
		return 1 + (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$p}cc_applications WHERE batch_id = %d AND status = 'waitlisted' AND ( waitlisted_at < %s OR ( waitlisted_at = %s AND id < %d ) )",
				$row['batch_id'],
				$row['waitlisted_at'],
				$row['waitlisted_at'],
				$row['id']
			)
		);
	}

	public static function offer_hours(): int {
		return max( 1, (int) get_option( 'cc_waitlist_offer_hours', self::OFFER_HOURS_DEFAULT ) );
	}

	/** SMS once per application, however many times the response is replayed. */
	public static function notify_joined( array $application ): void {
		global $wpdb;
		$p = $wpdb->prefix;
		if ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}cc_sms_log WHERE template = 'waitlist_joined' AND related_type = 'application' AND related_id = %d", $application['id'] ) ) > 0 ) {
			return;
		}
		$batch = CC_Batch_Repository::find( (int) $application['batch_id'] );
		try {
			CC_Sms::queue( (string) $application['student_phone'], 'waitlist_joined', array( 'batch' => $batch ? (string) $batch['name'] : '', 'position' => (string) self::position( (int) $application['id'] ), 'ref' => (string) $application['public_ref'] ), 'application', (int) $application['id'] );
		} catch ( Throwable $e ) {
			error_log( 'CC_Waitlist: could not queue the joined SMS: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	/** Pending applications that hold an unpaid seat offer for the batch. */
	public static function outstanding_offers( int $batch_id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}cc_applications WHERE batch_id = %d AND status = 'pending' AND offered_at IS NOT NULL", $batch_id ) );
	}

	public static function free_seats( int $batch_id ): int {
		$batch = CC_Batch_Repository::find( $batch_id );
		if ( ! $batch || 'open' !== $batch['status'] ) {
			return 0;
		}
		return max( 0, (int) $batch['capacity'] - (int) $batch['seats_taken'] - self::outstanding_offers( $batch_id ) );
	}

	/**
	 * Offers a seat to one waitlisted application.
	 *
	 * @param bool $force Staff may offer past the free-seat count (an exception they own).
	 * @return true|WP_Error
	 */
	public static function offer( int $application_id, int $actor = 0, bool $force = false ) {
		global $wpdb;
		$p   = $wpdb->prefix;
		$app = $wpdb->get_row( $wpdb->prepare( "SELECT id, batch_id, status, student_phone, public_ref FROM {$p}cc_applications WHERE id = %d", $application_id ), ARRAY_A );
		if ( ! is_array( $app ) || 'waitlisted' !== $app['status'] ) {
			return new WP_Error( 'not_waitlisted', 'This application is not on the waitlist.' );
		}
		// The batch row lock serialises concurrent offers and new applications (CC_Application_Repository::create() locks the
		// same row), so one free seat is never promised twice.
		// Lock order application -> batch, the same as CC_Settlement, so the two cannot deadlock.
		$wpdb->query( 'START TRANSACTION' );
		$locked = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$p}cc_applications WHERE id = %d FOR UPDATE", $application_id ) );
		if ( 'waitlisted' !== $locked ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'not_waitlisted', 'This application is not on the waitlist.' );
		}
		$wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$p}cc_batches WHERE id = %d FOR UPDATE", $app['batch_id'] ) );
		if ( ! $force && self::free_seats( (int) $app['batch_id'] ) < 1 ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'no_free_seat', 'There is no free seat in this batch.' );
		}
		// The status guard in WHERE makes a double click or a race offer exactly once.
		$changed = $wpdb->query( $wpdb->prepare( "UPDATE {$p}cc_applications SET status = 'pending', offered_at = %s, updated_at = %s WHERE id = %d AND status = 'waitlisted'", gmdate( 'Y-m-d H:i:s' ), gmdate( 'Y-m-d H:i:s' ), $application_id ) );
		if ( 1 !== $changed ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'not_waitlisted', 'This application is not on the waitlist.' );
		}
		if ( false === $wpdb->query( 'COMMIT' ) ) {
			return new WP_Error( 'offer_failed', 'The offer could not be saved.' );
		}
		$batch = CC_Batch_Repository::find( (int) $app['batch_id'] );
		try {
			CC_Sms::queue( (string) $app['student_phone'], 'waitlist_offer', array( 'batch' => $batch ? (string) $batch['name'] : '', 'hours' => (string) self::offer_hours(), 'url' => add_query_arg( 'ref', $app['public_ref'], home_url( '/admissions/' ) ) ), 'application', $application_id );
		} catch ( Throwable $e ) {
			error_log( 'CC_Waitlist: could not queue the offer SMS: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
		if ( $actor > 0 && class_exists( 'CC_Audit' ) ) {
			CC_Audit::log( 'application.waitlist_offer', 'application', $application_id, array( 'forced' => $force ) );
		}
		return true;
	}

	/** Offers seats to the oldest waitlisted applications while free seats remain. @return int Number offered. */
	public static function fill( int $batch_id ): int {
		global $wpdb;
		$offered = 0;
		while ( self::free_seats( $batch_id ) > 0 ) {
			$next = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}cc_applications WHERE batch_id = %d AND status = 'waitlisted' ORDER BY waitlisted_at ASC, id ASC LIMIT 1", $batch_id ) );
			if ( $next < 1 || true !== self::offer( $next ) ) {
				break;
			}
			++$offered;
		}
		return $offered;
	}

	/** Fills every waitlist-enabled batch; used after batch edits and by the daily job. */
	public static function fill_all(): int {
		global $wpdb;
		$total = 0;
		foreach ( $wpdb->get_col( "SELECT id FROM {$wpdb->prefix}cc_batches WHERE waitlist_enabled = 1 AND status = 'open'" ) as $id ) {
			$total += self::fill( (int) $id );
		}
		return $total;
	}

	/**
	 * Cancels offers that were not paid in time and passes the seat on. An offer with a payment in flight or completed is left alone.
	 *
	 * @return int Offers expired.
	 */
	public static function expire_offers( ?int $now = null ): int {
		global $wpdb;
		$p       = $wpdb->prefix;
		$cutoff  = gmdate( 'Y-m-d H:i:s', ( $now ?? time() ) - self::offer_hours() * HOUR_IN_SECONDS );
		$rows    = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT a.id, a.batch_id, a.student_phone FROM {$p}cc_applications a
				WHERE a.status = 'pending' AND a.offered_at IS NOT NULL AND a.offered_at < %s
				AND NOT EXISTS ( SELECT 1 FROM {$p}cc_invoices i JOIN {$p}cc_payments pay ON pay.invoice_id = i.id WHERE i.application_id = a.id AND pay.status IN ('initiated','executing','completed','reconcile_needed') )",
				$cutoff
			),
			ARRAY_A
		);
		$expired = 0;
		$batches = array();
		foreach ( (array) $rows as $row ) {
			if ( 1 !== $wpdb->query( $wpdb->prepare( "UPDATE {$p}cc_applications SET status = 'cancelled', updated_at = %s WHERE id = %d AND status = 'pending'", gmdate( 'Y-m-d H:i:s' ), $row['id'] ) ) ) {
				continue;
			}
			$wpdb->query( $wpdb->prepare( "UPDATE {$p}cc_invoices SET status = 'void' WHERE application_id = %d AND status = 'unpaid'", $row['id'] ) );
			$batch = CC_Batch_Repository::find( (int) $row['batch_id'] );
			try {
				CC_Sms::queue( (string) $row['student_phone'], 'waitlist_expired', array( 'batch' => $batch ? (string) $batch['name'] : '' ), 'application', (int) $row['id'] );
			} catch ( Throwable $e ) {
				error_log( 'CC_Waitlist: could not queue the expiry SMS: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
			$batches[ (int) $row['batch_id'] ] = true;
			++$expired;
		}
		foreach ( array_keys( $batches ) as $batch_id ) {
			self::fill( $batch_id );
		}
		return $expired;
	}
}
