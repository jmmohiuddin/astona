<?php
defined( 'ABSPATH' ) || exit;

/**
 * Data access for cc_applications, cc_invoices and the payment rows created at application time.
 */
final class CC_Application_Repository {

	const CROCKFORD = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

	/** Statuses that hold a student's phone for a batch. */
	const ACTIVE_STATUSES = array( 'pending', 'approved', 'waitlisted' );

	public static function ulid(): string {
		$time = (int) floor( microtime( true ) * 1000 );
		$out  = '';
		for ( $i = 0; $i < 10; $i++ ) {
			$out  = self::CROCKFORD[ $time % 32 ] . $out;
			$time = intdiv( $time, 32 );
		}
		for ( $i = 0; $i < 16; $i++ ) {
			$out .= self::CROCKFORD[ random_int( 0, 31 ) ];
		}
		return $out;
	}

	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'cc_' . $name;
	}

	public static function find_by_ref( string $ref ): ?array {
		global $wpdb;
		$table = self::table( 'applications' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE public_ref = %s", $ref ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	public static function find_by_idempotency_key( string $key ): ?array {
		global $wpdb;
		$table = self::table( 'applications' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE idempotency_key = %s", $key ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	public static function find_invoice( int $application_id ): ?array {
		global $wpdb;
		$table = self::table( 'invoices' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE application_id = %d", $application_id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	public static function latest_payment( int $invoice_id ): ?array {
		global $wpdb;
		$table = self::table( 'payments' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE invoice_id = %d ORDER BY id DESC LIMIT 1", $invoice_id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	public static function find_payment( int $payment_id ): ?array {
		global $wpdb;
		$table = self::table( 'payments' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $payment_id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/** @return array<int,array> All payments of an invoice, oldest first. */
	public static function payments_for_invoice( int $invoice_id ): array {
		global $wpdb;
		$table = self::table( 'payments' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE invoice_id = %d ORDER BY id ASC", $invoice_id ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Minimal public fields. payment_status is generic: none|initiated|executing|failed|cancelled|completed|needs_review.
	 *
	 * @return array{ref:string,status:string,payment_status:string}|array{}
	 */
	public static function status_view( string $ref ): array {
		$application = self::find_by_ref( $ref );
		if ( ! $application ) {
			return array();
		}
		$invoice  = self::find_invoice( (int) $application['id'] );
		$payments = $invoice ? self::payments_for_invoice( (int) $invoice['id'] ) : array();
		$statuses = array_column( $payments, 'status' );
		if ( 'waitlisted' === $application['status'] ) {
			return array( 'ref' => $application['public_ref'], 'status' => 'waitlisted', 'payment_status' => 'none', 'waitlist_position' => CC_Waitlist::position( (int) $application['id'] ) );
		}
		if ( in_array( 'completed', $statuses, true ) ) {
			$public = 'completed';
		} elseif ( array_intersect( $statuses, array( 'reconcile_needed', 'refunded' ) ) ) {
			$public = 'needs_review';
		} else {
			$public = $statuses ? (string) end( $statuses ) : 'none';
		}
		return array(
			'ref'            => $application['public_ref'],
			'status'         => $application['status'],
			'payment_status' => $public,
		);
	}

	public static function phone_taken( int $batch_id, string $phone ): bool {
		global $wpdb;
		$table = self::table( 'applications' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		$id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE batch_id = %d AND student_phone = %s AND status IN ('pending','approved','waitlisted') LIMIT 1", $batch_id, $phone ) );
		return null !== $id;
	}

	/**
	 * Insert application + invoice in one transaction. A repeated idempotency key returns the original.
	 *
	 * $data holds validated column values (id_doc_enc already encrypted, student_phone normalised).
	 *
	 * @return array|WP_Error Application row plus 'invoice' and 'replayed'.
	 */
	public static function create( array $data, string $idempotency_key ) {
		global $wpdb;

		$existing = self::find_by_idempotency_key( $idempotency_key );
		if ( $existing ) {
			return self::with_invoice( $existing, true );
		}

		$batch_id = (int) $data['batch_id'];
		$batches  = CC_Migrations::table();
		$now      = gmdate( 'Y-m-d H:i:s' );

		/*
		 * Lock order: a batch row is never locked before an application row in the same transaction.
		 * CC_Settlement locks payment -> invoice -> application -> batch; here only the batch row is
		 * locked before the inserts, and the phone check below is a plain read serialised by that lock.
		 */
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return self::db_failure( false );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$batch = $wpdb->get_row( $wpdb->prepare( "SELECT price, status, application_open, capacity, seats_taken, waitlist_enabled, installments_enabled, first_payment_percent FROM {$batches} WHERE id = %d FOR UPDATE", $batch_id ), ARRAY_A );
		if ( '' !== $wpdb->last_error ) {
			return self::db_failure();
		}
		if ( ! $batch || 'draft' === $batch['status'] ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'batch_not_found', 'Batch not found.', array( 'status' => 404 ) );
		}
		// Seats promised to waitlisted applicants who have not paid yet are not up for grabs.
		$counted               = $batch;
		$counted['seats_taken'] = (int) $batch['seats_taken'] + ( ! empty( $batch['waitlist_enabled'] ) ? CC_Waitlist::outstanding_offers( $batch_id ) : 0 );
		$chip                  = CC_Status_Chip::for_row( $counted );
		if ( CC_Status_Chip::CLOSED === $chip ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'batch_closed', 'This batch is not accepting applications.', array( 'status' => 409 ) );
		}
		$price      = $batch['price'];
		$waitlisted = CC_Status_Chip::WAITLIST === $chip;
		// Two-part payment only where the batch allows it; anything else is silently a full payment.
		$plan = 'installment' === ( $data['payment_plan'] ?? 'full' ) && ! empty( $batch['installments_enabled'] ) && (float) $price > 0 ? 'installment' : 'full';
		$first_amount = 'installment' === $plan ? CC_Batch_Repository::split_amount( (float) $price, (int) $batch['first_payment_percent'] )['first'] : null;
		unset( $data['payment_plan'] );

		$phone_taken = self::phone_taken( $batch_id, (string) $data['student_phone'] );
		if ( '' !== $wpdb->last_error ) {
			return self::db_failure();
		}
		if ( $phone_taken ) {
			$wpdb->query( 'ROLLBACK' );
			return new WP_Error( 'duplicate_phone', 'An application with this student phone already exists for this batch.', array( 'status' => 409 ) );
		}

		$row = array_merge(
			$data,
			array(
				'public_ref'      => $data['public_ref'] ?? self::ulid(),
				'idempotency_key' => $idempotency_key,
				'status'          => $waitlisted ? 'waitlisted' : 'pending',
				'waitlisted_at'   => $waitlisted ? $now : null,
				'payment_mode'    => 'online',
				'created_at'      => $now,
				'updated_at'      => $now,
			)
		);

		$suppress = $wpdb->suppress_errors( true );
		$inserted = $wpdb->insert( self::table( 'applications' ), $row );
		$wpdb->suppress_errors( $suppress );
		if ( ! $inserted ) {
			$wpdb->query( 'ROLLBACK' );
			// Lost a race on the unique idempotency key: serve the winner.
			$winner = self::find_by_idempotency_key( $idempotency_key );
			if ( $winner ) {
				return self::with_invoice( $winner, true );
			}
			return new WP_Error( 'server_error', 'Could not save the application.', array( 'status' => 500 ) );
		}

		$application_id = (int) $wpdb->insert_id;
		$invoice_ok     = $wpdb->insert(
			self::table( 'invoices' ),
			array(
				'application_id' => $application_id,
				'amount'         => $price,
				'currency'       => 'BDT',
				'status'         => 'unpaid',
				'plan'           => $plan,
				'first_amount'   => $first_amount,
				'number'         => sprintf( 'INV-%s-%d', gmdate( 'Ymd' ), $application_id ),
				'created_at'     => $now,
			)
		);
		if ( ! $invoice_ok || '' !== $wpdb->last_error ) {
			return self::db_failure();
		}

		if ( false === $wpdb->query( 'COMMIT' ) ) {
			return self::db_failure();
		}
		$created = self::find_by_ref( (string) $row['public_ref'] );
		return self::with_invoice( $created, false );
	}

	/** Rolls back and returns the generic 500; never continue a transaction after a DB error. */
	private static function db_failure( bool $rollback = true ): WP_Error {
		global $wpdb;
		if ( $rollback ) {
			$wpdb->query( 'ROLLBACK' );
		}
		return new WP_Error( 'server_error', 'Could not save the application.', array( 'status' => 500 ) );
	}

	/** The amount a first or full payment must carry: the first part for an installment plan, else the whole fee. */
	public static function initial_amount( array $invoice ): string {
		return 'installment' === ( $invoice['plan'] ?? 'full' ) && null !== ( $invoice['first_amount'] ?? null ) ? (string) $invoice['first_amount'] : (string) $invoice['amount'];
	}

	/** @param string|null $amount Defaults to initial_amount(); $kind is full, first or balance. */
	public static function insert_payment( array $invoice, string $gateway_id, ?string $amount = null, string $kind = '' ): int {
		global $wpdb;
		$now    = gmdate( 'Y-m-d H:i:s' );
		$amount = $amount ?? self::initial_amount( $invoice );
		if ( '' === $kind ) {
			$kind = 'installment' === ( $invoice['plan'] ?? 'full' ) ? 'first' : 'full';
		}
		$wpdb->insert(
			self::table( 'payments' ),
			array(
				'invoice_id' => (int) $invoice['id'],
				'gateway'    => $gateway_id,
				'method'     => in_array( $gateway_id, array( 'bkash', 'offline', 'fake' ), true ) ? $gateway_id : 'fake',
				'amount'     => $amount,
				'kind'       => $kind,
				'status'     => 'initiated',
				'created_at' => $now,
				'updated_at' => $now,
			)
		);
		return (int) $wpdb->insert_id;
	}

	public static function attach_gateway_payment( int $payment_id, string $gateway_payment_id, string $redirect_url ): void {
		global $wpdb;
		$wpdb->update(
			self::table( 'payments' ),
			array(
				'gateway_payment_id' => $gateway_payment_id,
				'response_json'      => wp_json_encode( array( 'redirect_url' => $redirect_url ) ),
				'updated_at'         => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'id' => $payment_id )
		);
	}

	public static function mark_payment_failed( int $payment_id ): void {
		global $wpdb;
		$wpdb->update( self::table( 'payments' ), array( 'status' => 'failed', 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'id' => $payment_id ) );
	}

	private static function with_invoice( array $application, bool $replayed ): array {
		$application['invoice']  = self::find_invoice( (int) $application['id'] );
		$application['replayed'] = $replayed;
		return $application;
	}
}
