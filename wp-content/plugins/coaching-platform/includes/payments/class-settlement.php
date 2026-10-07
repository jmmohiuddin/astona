<?php
defined( 'ABSPATH' ) || exit;

/**
 * Turns a verified gateway payment into an approved application + taken seat.
 * Everything is decided server-side from gateway->query(); caller input is never trusted.
 * Lock order is always payment -> invoice -> application -> batch.
 * Note: gateway->query() runs while those row locks are held, so real gateway drivers
 * must use a short HTTP timeout (<= 5 seconds) to avoid stalling concurrent settlements.
 */
final class CC_Settlement {

	const SOURCES = array( 'callback', 'ipn', 'poll', 'admin' );

	/**
	 * @return array{result:string,application_ref:string}
	 * @throws InvalidArgumentException On an unknown source.
	 * @throws RuntimeException         On any DB failure (transaction is rolled back).
	 */
	public static function settle( int $payment_id, string $source ): array {
		global $wpdb;
		if ( ! in_array( $source, self::SOURCES, true ) ) {
			throw new InvalidArgumentException( 'Unknown settlement source.' );
		}

		self::exec( 'START TRANSACTION' );
		try {
			$outcome = self::settle_locked( $payment_id, $source );
			self::exec( 'COMMIT' );
		} catch ( Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			throw $e;
		}

		if ( 'settled' === $outcome['result'] ) {
			do_action( 'cc_application_settled', $outcome['application_id'], $payment_id );
		}
		return array(
			'result'          => $outcome['result'],
			'application_ref' => $outcome['application_ref'],
		);
	}

	private static function settle_locked( int $payment_id, string $source ): array {
		global $wpdb;
		$p = $wpdb->prefix;

		$payment = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}cc_payments WHERE id = %d FOR UPDATE", $payment_id ), ARRAY_A );
		if ( null === $payment ) {
			return self::outcome( 'not_completed', null );
		}
		$invoice     = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}cc_invoices WHERE id = %d FOR UPDATE", $payment['invoice_id'] ), ARRAY_A );
		$application = $invoice ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}cc_applications WHERE id = %d FOR UPDATE", $invoice['application_id'] ), ARRAY_A ) : null;
		if ( null === $application ) {
			throw new RuntimeException( 'Payment has no invoice/application.' );
		}

		$already = self::previous_result( $payment );
		if ( null !== $already ) {
			return self::outcome( $already, $application );
		}

		$gateway = CC_Gateway_Factory::make();
		$report  = $gateway->query( (string) $payment['gateway_payment_id'] );

		if ( 'completed' !== $report['status'] ) {
			self::record_non_completed( $payment, $report );
			return self::outcome( 'not_completed', $application );
		}

		$problem = self::validate( $payment, $invoice, $application, $report );
		if ( null === $problem ) {
			$batch = $wpdb->get_row( $wpdb->prepare( "SELECT id, capacity, seats_taken FROM {$p}cc_batches WHERE id = %d FOR UPDATE", $application['batch_id'] ), ARRAY_A );
			if ( null === $batch || (int) $batch['seats_taken'] >= (int) $batch['capacity'] ) {
				$problem = 'batch_full';
			}
		}
		if ( null !== $problem ) {
			self::mark_reconcile( $payment, $report, $problem, $source );
			return self::outcome( $problem, $application );
		}

		self::apply_success( $payment, $invoice, $application, $report, $source );
		return self::outcome( 'settled', $application );
	}

	/** Result to return without touching anything, or null when the payment still needs processing. */
	private static function previous_result( array $payment ): ?string {
		switch ( $payment['status'] ) {
			case 'completed':
			case 'refunded':
				return 'already_settled';
			case 'reconcile_needed':
				$stored = json_decode( (string) $payment['response_json'], true );
				$result = is_array( $stored ) ? ( $stored['settle_result'] ?? '' ) : '';
				return in_array( $result, array( 'mismatch', 'batch_full' ), true ) ? $result : 'mismatch';
			default:
				return null;
		}
	}

	/** @return string|null 'mismatch' when the gateway report cannot be applied to this invoice. */
	private static function validate( array $payment, array $invoice, array $application, array $report ): ?string {
		$expected_cents = (int) round( (float) $invoice['amount'] * 100 );
		$paid_cents     = (int) round( (float) $report['amount'] * 100 );
		if ( $paid_cents !== $expected_cents || 'unpaid' !== $invoice['status'] || 'pending' !== $application['status'] ) {
			return 'mismatch';
		}
		return null;
	}

	/** A non-completed report only ever moves the payment forward from initiated/executing/cancelled-by-timeout. */
	private static function record_non_completed( array $payment, array $report ): void {
		$map    = array(
			'pending'   => 'executing',
			'failed'    => 'failed',
			'cancelled' => 'cancelled',
		);
		$target = $map[ $report['status'] ] ?? null;
		if ( null === $target || $target === $payment['status'] || 'initiated' !== $payment['status'] && 'executing' !== $payment['status'] ) {
			return;
		}
		self::update_payment( (int) $payment['id'], array( 'status' => $target ) );
	}

	private static function mark_reconcile( array $payment, array $report, string $reason, string $source ): void {
		self::update_payment(
			(int) $payment['id'],
			array(
				'status'        => 'reconcile_needed',
				'trx_id'        => (string) $report['trx_id'],
				'response_json' => wp_json_encode(
					array(
						'settle_result' => $reason,
						'gateway'       => $report,
					)
				),
			)
		);
		self::log_event( (int) $payment['id'], $source, $reason, $report );
	}

	private static function apply_success( array $payment, array $invoice, array $application, array $report, string $source ): void {
		global $wpdb;
		$p   = $wpdb->prefix;
		$now = gmdate( 'Y-m-d H:i:s' );

		$seat = $wpdb->query( $wpdb->prepare( "UPDATE {$p}cc_batches SET seats_taken = seats_taken + 1 WHERE id = %d AND seats_taken < capacity", $application['batch_id'] ) );
		if ( 1 !== $seat ) {
			throw new RuntimeException( 'Seat increment failed under lock.' );
		}
		self::update_payment(
			(int) $payment['id'],
			array(
				'status'        => 'completed',
				'trx_id'        => (string) $report['trx_id'],
				'settled_at'    => $now,
				'response_json' => wp_json_encode( array( 'gateway' => $report ) ),
			)
		);
		self::exec_prepared( $wpdb->prepare( "UPDATE {$p}cc_invoices SET status = 'paid' WHERE id = %d", $invoice['id'] ) );
		self::exec_prepared( $wpdb->prepare( "UPDATE {$p}cc_applications SET status = 'approved', updated_at = %s WHERE id = %d", $now, $application['id'] ) );
		self::log_event( (int) $payment['id'], $source, 'settled', $report );
	}

	private static function update_payment( int $payment_id, array $fields ): void {
		global $wpdb;
		$fields['updated_at'] = gmdate( 'Y-m-d H:i:s' );
		if ( false === $wpdb->update( $wpdb->prefix . 'cc_payments', $fields, array( 'id' => $payment_id ) ) ) {
			throw new RuntimeException( 'Payment update failed: ' . $wpdb->last_error );
		}
	}

	/** Dedupes on (payment, source, outcome, trx) via the unique event_key. */
	private static function log_event( int $payment_id, string $source, string $result, array $report ): void {
		global $wpdb;
		$key = CC_Idempotency::event_key( (string) $payment_id, $source, $result, (string) $report['trx_id'] );
		self::exec_prepared(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->prefix}cc_payment_events (payment_id, source, event_key, payload_json, received_at) VALUES (%d, %s, %s, %s, %s)",
				$payment_id,
				$source,
				$key,
				wp_json_encode( array( 'result' => $result, 'gateway' => $report ) ),
				gmdate( 'Y-m-d H:i:s' )
			)
		);
	}

	private static function outcome( string $result, ?array $application ): array {
		return array(
			'result'          => $result,
			'application_ref' => $application ? (string) $application['public_ref'] : '',
			'application_id'  => $application ? (int) $application['id'] : 0,
		);
	}

	private static function exec( string $sql ): void {
		global $wpdb;
		if ( false === $wpdb->query( $sql ) ) {
			throw new RuntimeException( 'DB statement failed: ' . $wpdb->last_error );
		}
	}

	private static function exec_prepared( ?string $sql ): void {
		self::exec( (string) $sql );
	}
}
