<?php
defined( 'ABSPATH' ) || exit;

/**
 * Records a refund that staff made OUTSIDE the system (no gateway refund API exists yet). It changes our books only:
 * a future gateway refund driver can call CC_Admin_Payments::mark_refunded() after it has refunded at the gateway.
 *
 * Everything happens in ONE transaction. Lock order: the invoice's payments (by id) -> invoice -> application -> batch ->
 * enrollment, i.e. the same payment -> invoice -> application -> batch order as CC_Settlement. The provisioning mutex
 * (GET_LOCK) is taken first so a queued provisioning run cannot create an enrollment for a refunded application.
 *
 * Seat release is exact-once because the 'completed' -> 'refunded' flip is decided under the payment row lock.
 * A double-paid invoice (another completed payment on it) only flips THIS payment; the application stays untouched.
 */
final class CC_Refund {

	const LOCK_TIMEOUT = 5;

	/**
	 * @return array{result:string,seat_released:bool}|WP_Error result: refunded | refunded_duplicate | already_refunded.
	 *         Inputs are validated by the caller (CC_Admin_Payments::mark_refunded).
	 */
	public static function record( int $payment_id, string $category, string $reference, int $actor ) {
		global $wpdb;
		$p       = $wpdb->prefix;
		$context = $wpdb->get_row(
			$wpdb->prepare( "SELECT pay.invoice_id, i.application_id FROM {$p}cc_payments pay JOIN {$p}cc_invoices i ON i.id = pay.invoice_id WHERE pay.id = %d", $payment_id ),
			ARRAY_A
		);
		if ( null === $context ) {
			return new WP_Error( 'not_found', 'Payment not found.' );
		}

		$mutex = CC_Provisioner::lock_name( (int) $context['application_id'] );
		if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $mutex, self::LOCK_TIMEOUT ) ) ) {
			return new WP_Error( 'refund_busy', 'Another change to this application is running.' );
		}
		try {
			$wpdb->query( 'START TRANSACTION' );
			try {
				$outcome = self::record_locked( $payment_id, (int) $context['invoice_id'], (int) $context['application_id'], $category, $reference, $actor );
				if ( false === $wpdb->query( 'COMMIT' ) ) {
					throw new RuntimeException( 'Commit failed.' );
				}
			} catch ( Throwable $e ) {
				$wpdb->query( 'ROLLBACK' );
				error_log( sprintf( 'CC refund failed for payment %d: %s', $payment_id, $e->getMessage() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				return new WP_Error( 'refund_failed', 'The refund could not be recorded.' );
			}
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $mutex ) );
		}

		if ( is_wp_error( $outcome ) || 'already_refunded' === $outcome['result'] ) {
			return $outcome;
		}
		CC_Audit::log(
			'payment.refund',
			'payment',
			$payment_id,
			array( 'status' => array( 'completed', 'refunded' ), 'scope' => $outcome['result'], 'seat_released' => $outcome['seat_released'], 'reference_given' => '' !== $reference, 'actor_id' => $actor ),
			$category
		);
		return $outcome;
	}

	/** @return array{result:string,seat_released:bool}|WP_Error */
	private static function record_locked( int $payment_id, int $invoice_id, int $application_id, string $category, string $reference, int $actor ) {
		global $wpdb;
		$p = $wpdb->prefix;

		$siblings = $wpdb->get_results( $wpdb->prepare( "SELECT id, status, amount FROM {$p}cc_payments WHERE invoice_id = %d ORDER BY id FOR UPDATE", $invoice_id ), ARRAY_A );
		$payment  = null;
		$other_completed = false;
		foreach ( (array) $siblings as $row ) {
			if ( (int) $row['id'] === $payment_id ) {
				$payment = $row;
			} elseif ( 'completed' === $row['status'] ) {
				$other_completed = true;
			}
		}
		if ( null === $payment ) {
			return new WP_Error( 'not_found', 'Payment not found.' );
		}
		if ( 'refunded' === $payment['status'] ) {
			return array( 'result' => 'already_refunded', 'seat_released' => false );
		}
		if ( 'completed' !== $payment['status'] ) {
			return new WP_Error( 'refund_not_completed', 'Only completed payments can be marked refunded.' );
		}

		$now = gmdate( 'Y-m-d H:i:s' );
		self::exec( $wpdb->prepare( "UPDATE {$p}cc_payments SET status = 'refunded', updated_at = %s WHERE id = %d", $now, $payment_id ) );
		self::log_event( $payment_id, $category, $reference, (string) $payment['amount'], $actor, $now );
		if ( $other_completed ) {
			return array( 'result' => 'refunded_duplicate', 'seat_released' => false );
		}

		$wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$p}cc_invoices WHERE id = %d FOR UPDATE", $invoice_id ) );
		$application = $wpdb->get_row( $wpdb->prepare( "SELECT id, batch_id, status FROM {$p}cc_applications WHERE id = %d FOR UPDATE", $application_id ), ARRAY_A );
		if ( null === $application ) {
			throw new RuntimeException( 'Payment has no application.' );
		}
		$wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$p}cc_batches WHERE id = %d FOR UPDATE", $application['batch_id'] ) );
		$wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$p}cc_enrollments WHERE application_id = %d FOR UPDATE", $application_id ) );

		self::exec( $wpdb->prepare( "UPDATE {$p}cc_invoices SET status = 'void' WHERE id = %d", $invoice_id ) );
		self::exec( $wpdb->prepare( "UPDATE {$p}cc_enrollments SET status = 'deactivated' WHERE application_id = %d AND status = 'active'", $application_id ) );

		$seat_released = false;
		if ( 'approved' === $application['status'] ) {
			self::exec( $wpdb->prepare( "UPDATE {$p}cc_applications SET status = 'cancelled', updated_at = %s WHERE id = %d", $now, $application_id ) );
			$seat_released = 1 === $wpdb->query( $wpdb->prepare( "UPDATE {$p}cc_batches SET seats_taken = seats_taken - 1 WHERE id = %d AND seats_taken > 0", $application['batch_id'] ) );
		}
		return array( 'result' => 'refunded', 'seat_released' => $seat_released );
	}

	/** Deduped by the unique event_key; the payload is only ever read through CC_Admin_Payments::events() whitelists. */
	private static function log_event( int $payment_id, string $category, string $reference, string $amount, int $actor, string $now ): void {
		global $wpdb;
		self::exec(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->prefix}cc_payment_events (payment_id, source, event_key, payload_json, received_at) VALUES (%d, 'admin', %s, %s, %s)",
				$payment_id,
				CC_Idempotency::event_key( 'refund', (string) $payment_id ),
				wp_json_encode( array( 'category' => $category, 'reference' => $reference, 'amount' => $amount, 'actor_id' => $actor ) ),
				$now
			)
		);
	}

	private static function exec( ?string $sql ): void {
		global $wpdb;
		if ( false === $wpdb->query( (string) $sql ) ) {
			throw new RuntimeException( 'DB statement failed: ' . $wpdb->last_error );
		}
	}
}
