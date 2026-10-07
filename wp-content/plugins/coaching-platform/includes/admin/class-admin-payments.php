<?php
defined( 'ABSPATH' ) || exit;

/**
 * Admin payments ledger: list, detail + event timeline, reconcile, CSV export.
 * response_json and payment_events.payload_json are never rendered or exported; only whitelisted fields are read.
 */
final class CC_Admin_Payments {

	const PAGE             = 'cc-payments';
	const STUCK_MINUTES    = 10;
	const PER_PAGE         = 20;
	const EXPORT_CHUNK     = 500;
	const STATUSES         = array( 'initiated', 'executing', 'completed', 'failed', 'cancelled', 'reconcile_needed', 'refunded' );
	const SORTABLE         = array(
		'invoice' => 'i.number',
		'amount'  => 'pay.amount',
		'status'  => 'pay.status',
		'created' => 'pay.created_at',
		'settled' => 'pay.settled_at',
	);
	const CSV_HEADER       = array( 'Invoice', 'Application ref', 'Student', 'Phone', 'Amount', 'Currency', 'Gateway', 'Method', 'Status', 'Transaction ID', 'Created (UTC)', 'Settled (UTC)' );
	const RESULT_MESSAGES  = array(
		'settled'         => 'Payment verified with the gateway and settled. The application is approved.',
		'already_settled' => 'This payment was already settled. Nothing was changed.',
		'not_completed'   => 'The gateway does not report this payment as completed yet. Nothing was settled.',
		'mismatch'        => 'The gateway report does not match the invoice (amount or state). The payment stays flagged for manual review.',
		'batch_full'      => 'The gateway confirms payment but the batch is full. The payment stays flagged for manual review.',
		'not_found'       => 'Payment not found.',
		'forbidden'       => 'You are not allowed to reconcile payments.',
		'refunded'             => 'Refund recorded. The payment is marked refunded, the invoice is void, the application is cancelled, the student lost access and the seat was released.',
		'refunded_duplicate'   => 'Refund recorded for this payment only. Another completed payment covers the same invoice, so the application, enrollment and seat were left untouched.',
		'already_refunded'     => 'This payment was already marked refunded. Nothing was changed.',
		'refund_forbidden'     => 'Only the owner can mark a payment refunded.',
		'refund_not_completed' => 'Only completed payments can be marked refunded. Nothing was changed.',
		'refund_invalid'       => 'Choose a reason and, if you enter a refund reference, use only letters, digits and . _ - / (up to 64 characters). Nothing was changed.',
		'refund_busy'          => 'Another change to this application is running. Try again in a moment.',
		'refund_failed'        => 'The refund could not be recorded because of a server error. Nothing was changed.',
		'error'           => 'Reconcile failed because of a server error. Try again or check the gateway configuration.',
	);
	const REASON_LABELS    = array(
		'mismatch'   => 'Gateway report did not match the invoice',
		'batch_full' => 'Batch was full when payment arrived',
	);
	const REFUND_CATEGORIES = array(
		'duplicate_payment' => 'Duplicate payment',
		'student_withdrew'  => 'Student withdrew',
		'batch_cancelled'   => 'Batch cancelled',
		'error'             => 'Payment taken in error',
		'other'             => 'Other',
	);
	const REFUND_REFERENCE_MAX     = 64;
	const REFUND_REFERENCE_PATTERN = '/^[A-Za-z0-9._\/ -]*$/';

	public static function init(): void {
		add_action( 'admin_post_cc_payment_reconcile', array( __CLASS__, 'handle_reconcile' ) );
		add_action( 'admin_post_cc_payment_refund', array( __CLASS__, 'handle_refund' ) );
		add_action( 'admin_post_cc_payments_export', array( __CLASS__, 'handle_export' ) );
	}

	// ---- Data -------------------------------------------------------------------------------

	/** Normalises raw request input into trusted filter args. */
	public static function filters_from( array $src ): array {
		$status  = isset( $src['status'] ) ? sanitize_key( (string) $src['status'] ) : '';
		$orderby = isset( $src['orderby'] ) ? sanitize_key( (string) $src['orderby'] ) : 'created';
		$order   = isset( $src['order'] ) && 'asc' === strtolower( (string) $src['order'] ) ? 'ASC' : 'DESC';
		return array(
			'status'   => ( 'stuck' === $status || in_array( $status, self::STATUSES, true ) ) ? $status : '',
			'gateway'  => isset( $src['gateway'] ) ? sanitize_key( (string) $src['gateway'] ) : '',
			'date_from' => self::valid_date( $src['date_from'] ?? '' ),
			'date_to'  => self::valid_date( $src['date_to'] ?? '' ),
			's'        => isset( $src['s'] ) ? trim( sanitize_text_field( wp_unslash( (string) $src['s'] ) ) ) : '',
			'orderby'  => isset( self::SORTABLE[ $orderby ] ) ? $orderby : 'created',
			'order'    => $order,
			'page'     => max( 1, (int) ( $src['paged'] ?? $src['page_num'] ?? 1 ) ),
			'per_page' => self::PER_PAGE,
		);
	}

	private static function valid_date( $value ): string {
		$value = is_string( $value ) ? trim( $value ) : '';
		$date  = DateTimeImmutable::createFromFormat( '!Y-m-d', $value );
		return ( $date && $date->format( 'Y-m-d' ) === $value ) ? $value : '';
	}

	/**
	 * @param array $args status|gateway|date_from|date_to|s|orderby|order|page|per_page (already trusted or run through filters_from)
	 * @return array{rows:array,total:int,completed_sum:string,completed_count:int}
	 */
	public static function query( array $args ): array {
		global $wpdb;
		$args = array_merge( self::filters_from( array() ), $args );
		list( $where, $params ) = self::where( $args );
		$from = self::from_sql();

		$totals = $wpdb->get_row(
			self::prepare( "SELECT COUNT(*) AS n, COALESCE(SUM(CASE WHEN pay.status = 'completed' THEN pay.amount ELSE 0 END), 0) AS sum_completed, COALESCE(SUM(pay.status = 'completed'), 0) AS n_completed {$from} {$where}", $params ),
			ARRAY_A
		);

		$order_col = self::SORTABLE[ $args['orderby'] ] ?? 'pay.created_at';
		$order     = 'ASC' === $args['order'] ? 'ASC' : 'DESC';
		$per_page  = max( 1, min( 200, (int) $args['per_page'] ) );
		$offset    = ( max( 1, (int) $args['page'] ) - 1 ) * $per_page;
		$rows      = $wpdb->get_results(
			self::prepare(
				"SELECT pay.id, pay.invoice_id, pay.gateway, pay.method, pay.trx_id, pay.amount, pay.status, pay.created_at, pay.updated_at, pay.settled_at,
					i.number AS invoice_number, i.currency, i.application_id, a.public_ref, a.full_name, a.student_phone
				{$from} {$where} ORDER BY {$order_col} {$order}, pay.id DESC LIMIT %d OFFSET %d",
				array_merge( $params, array( $per_page, $offset ) )
			),
			ARRAY_A
		);

		return array(
			'rows'            => is_array( $rows ) ? $rows : array(),
			'total'           => (int) ( $totals['n'] ?? 0 ),
			'completed_sum'   => number_format( (float) ( $totals['sum_completed'] ?? 0 ), 2, '.', '' ),
			'completed_count' => (int) ( $totals['n_completed'] ?? 0 ),
		);
	}

	private static function from_sql(): string {
		global $wpdb;
		$p = $wpdb->prefix;
		return "FROM {$p}cc_payments pay LEFT JOIN {$p}cc_invoices i ON i.id = pay.invoice_id LEFT JOIN {$p}cc_applications a ON a.id = i.application_id";
	}

	/** @return array{0:string,1:array} WHERE clause and its parameters. */
	private static function where( array $args ): array {
		global $wpdb;
		$cond   = array();
		$params = array();

		$status = (string) ( $args['status'] ?? '' );
		if ( 'stuck' === $status ) {
			$cond[]   = self::stuck_sql();
			$params[] = self::stuck_cutoff();
		} elseif ( in_array( $status, self::STATUSES, true ) ) {
			$cond[]   = 'pay.status = %s';
			$params[] = $status;
		}
		if ( '' !== (string) ( $args['gateway'] ?? '' ) ) {
			$cond[]   = 'pay.gateway = %s';
			$params[] = (string) $args['gateway'];
		}
		if ( '' !== self::valid_date( $args['date_from'] ?? '' ) ) {
			$cond[]   = 'pay.created_at >= %s';
			$params[] = $args['date_from'] . ' 00:00:00';
		}
		if ( '' !== self::valid_date( $args['date_to'] ?? '' ) ) {
			$cond[]   = 'pay.created_at < %s';
			$params[] = gmdate( 'Y-m-d', strtotime( $args['date_to'] . ' UTC' ) + DAY_IN_SECONDS ) . ' 00:00:00';
		}
		$search = trim( (string) ( $args['s'] ?? '' ) );
		if ( '' !== $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$sub      = array( 'i.number LIKE %s', 'pay.trx_id LIKE %s' );
			$sub_args = array( $like, $like );
			if ( preg_match( '/^\d{4}$/', $search ) ) {
				$sub[]      = 'a.student_phone LIKE %s';
				$sub_args[] = '%' . $wpdb->esc_like( $search );
			}
			$cond[]  = '(' . implode( ' OR ', $sub ) . ')';
			$params  = array_merge( $params, $sub_args );
		}
		return array( $cond ? 'WHERE ' . implode( ' AND ', $cond ) : '', $params );
	}

	private static function prepare( string $sql, array $params ): string {
		global $wpdb;
		return $params ? (string) $wpdb->prepare( $sql, ...$params ) : $sql;
	}

	/**
	 * The one SQL definition of "stuck" (dashboard counter and ledger filter): reconcile_needed, or initiated/executing
	 * created more than STUCK_MINUTES ago. Needs the payments table aliased `pay` and one %s bound to stuck_cutoff().
	 */
	public static function stuck_sql(): string {
		return "(pay.status = 'reconcile_needed' OR (pay.status IN ('initiated','executing') AND pay.created_at < %s))";
	}

	public static function stuck_cutoff( ?int $now = null ): string {
		return gmdate( 'Y-m-d H:i:s', ( $now ?? time() ) - self::STUCK_MINUTES * MINUTE_IN_SECONDS );
	}

	/** PHP twin of stuck_sql() for a loaded row. */
	public static function is_stuck( array $row, ?int $now = null ): bool {
		$status = (string) ( $row['status'] ?? '' );
		if ( 'reconcile_needed' === $status ) {
			return true;
		}
		if ( 'initiated' !== $status && 'executing' !== $status ) {
			return false;
		}
		$created = strtotime( (string) ( $row['created_at'] ?? '' ) . ' UTC' );
		return false !== $created && $created < ( $now ?? time() ) - self::STUCK_MINUTES * MINUTE_IN_SECONDS;
	}

	public static function distinct_gateways(): array {
		global $wpdb;
		return array_map( 'strval', (array) $wpdb->get_col( "SELECT DISTINCT gateway FROM {$wpdb->prefix}cc_payments ORDER BY gateway" ) );
	}

	/** Whitelisted event timeline; payload_json is decoded only to pick known scalar fields. */
	public static function events( int $payment_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT source, payload_json, received_at FROM {$wpdb->prefix}cc_payment_events WHERE payment_id = %d ORDER BY received_at ASC, id ASC", $payment_id ),
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $row ) {
			$payload = json_decode( (string) $row['payload_json'], true );
			$payload = is_array( $payload ) ? $payload : array();
			$gateway = is_array( $payload['gateway'] ?? null ) ? $payload['gateway'] : array();
			$result  = (string) ( $payload['result'] ?? '' );
			$status  = (string) ( $gateway['status'] ?? '' );
			$refund  = self::refund_event_detail( $payload );
			$amount  = $gateway['amount'] ?? ( null !== $refund ? ( $payload['amount'] ?? null ) : null );
			$out[]   = array(
				'source'      => (string) $row['source'],
				'received_at' => (string) $row['received_at'],
				'result'      => null !== $refund ? 'refund recorded' : ( isset( self::RESULT_MESSAGES[ $result ] ) ? $result : '' ),
				'detail'      => (string) $refund,
				'gw_status'   => in_array( $status, CC_Fake_Gateway::STATUSES, true ) ? $status : '',
				'trx_id'      => preg_match( '/^[A-Za-z0-9_-]{1,64}$/', (string) ( $gateway['trx_id'] ?? '' ) ) ? (string) $gateway['trx_id'] : '',
				'amount'      => is_numeric( $amount ) ? number_format( (float) $amount, 2, '.', '' ) : '',
			);
		}
		return $out;
	}

	/** Category label (plus the reference when it passes the pattern) of a refund event payload, or null when it is not one. */
	private static function refund_event_detail( array $payload ): ?string {
		$category = (string) ( $payload['category'] ?? '' );
		if ( ! isset( self::REFUND_CATEGORIES[ $category ] ) ) {
			return null;
		}
		$reference = (string) ( $payload['reference'] ?? '' );
		return '' !== $reference && self::valid_reference( $reference ) ? self::REFUND_CATEGORIES[ $category ] . ', ref ' . $reference : self::REFUND_CATEGORIES[ $category ];
	}

	private static function valid_reference( string $reference ): bool {
		return strlen( $reference ) <= self::REFUND_REFERENCE_MAX && 1 === preg_match( self::REFUND_REFERENCE_PATTERN, $reference );
	}

	// ---- Reconcile ---------------------------------------------------------------------------

	/** @return array{ok:bool,result:string,message:string} */
	public static function reconcile( int $payment_id, int $actor ): array {
		global $wpdb;
		if ( ! user_can( $actor, 'cc_reconcile_payments' ) ) {
			return self::reconcile_result( 'forbidden', false );
		}
		$status = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}cc_payments WHERE id = %d", $payment_id ) );
		if ( null === $status ) {
			return self::reconcile_result( 'not_found', false );
		}
		if ( 'completed' === $status || 'refunded' === $status ) {
			return self::reconcile_result( 'already_settled', true );
		}

		try {
			$outcome = CC_Settlement::settle( $payment_id, 'admin' );
		} catch ( Throwable $e ) {
			error_log( 'CC admin reconcile failed for payment ' . $payment_id . ': ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			return self::reconcile_result( 'error', false );
		}

		$after = (string) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}cc_payments WHERE id = %d", $payment_id ) );
		if ( class_exists( 'CC_Audit' ) ) {
			CC_Audit::log( 'payment.reconcile', 'payment', $payment_id, array( 'result' => $outcome['result'], 'from' => $status, 'to' => $after ), 'Reconcile now' );
		}
		return self::reconcile_result( $outcome['result'], 'settled' === $outcome['result'] );
	}

	private static function reconcile_result( string $code, bool $ok ): array {
		return array(
			'ok'      => $ok,
			'result'  => $code,
			'message' => self::RESULT_MESSAGES[ $code ] ?? self::RESULT_MESSAGES['error'],
		);
	}

	// ---- Refund (record only) ----------------------------------------------------------------

	/**
	 * Records a refund staff made OUTSIDE the system (no gateway refund API is called). A future gateway refund driver
	 * can call this same method once it has refunded at the gateway. Owner only; see CC_Refund for the effects.
	 *
	 * @param string $category  A key of REFUND_CATEGORIES.
	 * @param string $reference Optional external refund reference, up to 64 characters of A-Za-z0-9._-/ and space.
	 * @return array{ok:bool,result:string,seat_released:bool,message:string}|WP_Error
	 */
	public static function mark_refunded( int $payment_id, string $category, string $reference, int $actor ) {
		if ( ! user_can( $actor, 'cc_reconcile_payments' ) ) {
			return new WP_Error( 'refund_forbidden', self::RESULT_MESSAGES['refund_forbidden'] );
		}
		$reference = trim( $reference );
		if ( ! isset( self::REFUND_CATEGORIES[ $category ] ) || ! self::valid_reference( $reference ) ) {
			return new WP_Error( 'refund_invalid', self::RESULT_MESSAGES['refund_invalid'] );
		}
		$outcome = CC_Refund::record( $payment_id, $category, $reference, $actor );
		if ( is_wp_error( $outcome ) ) {
			return new WP_Error( $outcome->get_error_code(), self::RESULT_MESSAGES[ $outcome->get_error_code() ] ?? self::RESULT_MESSAGES['refund_failed'] );
		}
		return array(
			'ok'            => true,
			'result'        => $outcome['result'],
			'seat_released' => $outcome['seat_released'],
			'message'       => self::RESULT_MESSAGES[ $outcome['result'] ],
		);
	}

	public static function handle_refund(): void {
		$payment_id = isset( $_POST['payment_id'] ) ? absint( $_POST['payment_id'] ) : 0;
		check_admin_referer( 'cc_refund_payment_' . $payment_id );
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! current_user_can( 'cc_reconcile_payments' ) ) {
			wp_die( esc_html__( 'You are not allowed to mark payments refunded.', 'coaching-platform' ), '', array( 'response' => 403 ) );
		}
		$outcome = self::mark_refunded(
			$payment_id,
			sanitize_key( wp_unslash( (string) ( $_POST['category'] ?? '' ) ) ),
			sanitize_text_field( wp_unslash( (string) ( $_POST['reference'] ?? '' ) ) ),
			get_current_user_id()
		);
		$code = is_wp_error( $outcome ) ? (string) $outcome->get_error_code() : $outcome['result'];
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE, 'payment' => $payment_id, 'cc_result' => $code ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/** Link to the confirm page; shown on completed payments to the owner only. The confirm page is the only way to POST. */
	public static function refund_link( int $payment_id ): string {
		if ( ! current_user_can( 'cc_reconcile_payments' ) ) {
			return '';
		}
		$url = add_query_arg( array( 'page' => self::PAGE, 'refund' => $payment_id ), admin_url( 'admin.php' ) );
		return '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Mark refunded', 'coaching-platform' ) . '</a>';
	}

	private static function render_refund_confirm( int $payment_id ): void {
		global $wpdb;
		$p   = $wpdb->prefix;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT pay.id, pay.amount, pay.status, i.number AS invoice_number, i.currency, a.public_ref, a.full_name
				FROM {$p}cc_payments pay LEFT JOIN {$p}cc_invoices i ON i.id = pay.invoice_id LEFT JOIN {$p}cc_applications a ON a.id = i.application_id
				WHERE pay.id = %d",
				$payment_id
			),
			ARRAY_A
		);
		$back = add_query_arg( array( 'page' => self::PAGE, 'payment' => $payment_id ), admin_url( 'admin.php' ) );
		if ( null === $row || 'completed' !== $row['status'] || ! current_user_can( 'cc_reconcile_payments' ) ) {
			echo '<p>' . esc_html( null === $row ? self::RESULT_MESSAGES['not_found'] : ( 'completed' !== $row['status'] ? self::RESULT_MESSAGES['refund_not_completed'] : self::RESULT_MESSAGES['refund_forbidden'] ) ) . '</p>';
			echo '<p><a href="' . esc_url( add_query_arg( array( 'page' => self::PAGE ), admin_url( 'admin.php' ) ) ) . '">&larr; ' . esc_html__( 'All payments', 'coaching-platform' ) . '</a></p>';
			return;
		}

		echo '<h2>' . esc_html__( 'Mark this payment refunded?', 'coaching-platform' ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:720px"><tbody>';
		printf( '<tr><th style="width:160px">%s</th><td>%s</td></tr>', esc_html__( 'Invoice', 'coaching-platform' ), esc_html( (string) $row['invoice_number'] ) );
		printf( '<tr><th>%s</th><td>%s %s</td></tr>', esc_html__( 'Student', 'coaching-platform' ), esc_html( (string) $row['full_name'] ), esc_html( (string) $row['public_ref'] ) );
		printf( '<tr><th>%s</th><td>%s</td></tr>', esc_html__( 'Amount', 'coaching-platform' ), esc_html( number_format_i18n( (float) $row['amount'], 2 ) . ' ' . $row['currency'] ) );
		echo '</tbody></table>';
		echo '<p style="max-width:720px"><strong>' . esc_html__( 'This only records a refund you already paid back outside this system (for example in the bKash app). No money is sent from here.', 'coaching-platform' ) . '</strong></p>';
		echo '<p>' . esc_html__( 'What will happen:', 'coaching-platform' ) . '</p><ul style="list-style:disc;margin-left:20px">';
		foreach ( array( 'The payment is marked Refunded.', 'The invoice is voided.', 'The application is cancelled.', 'The student loses access to the batch immediately.', 'The seat in the batch is released.' ) as $line ) {
			echo '<li>' . esc_html( $line ) . '</li>';
		}
		echo '</ul><p>' . esc_html__( 'If another completed payment covers the same invoice (a duplicate), only this payment is marked refunded and the student keeps the seat.', 'coaching-platform' ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="cc_payment_refund"><input type="hidden" name="payment_id" value="' . esc_attr( (string) $payment_id ) . '">';
		wp_nonce_field( 'cc_refund_payment_' . $payment_id );
		echo '<p><label for="cc-refund-category"><strong>' . esc_html__( 'Reason', 'coaching-platform' ) . '</strong></label><br><select id="cc-refund-category" name="category" required>';
		echo '<option value="">' . esc_html__( 'Choose a reason', 'coaching-platform' ) . '</option>';
		foreach ( self::REFUND_CATEGORIES as $code => $label ) {
			printf( '<option value="%s">%s</option>', esc_attr( $code ), esc_html( $label ) );
		}
		echo '</select></p>';
		printf(
			'<p><label for="cc-refund-reference"><strong>%s</strong></label><br><input type="text" id="cc-refund-reference" name="reference" maxlength="%d" pattern="[A-Za-z0-9._/ \-]*" class="regular-text"> <span class="description">%s</span></p>',
			esc_html__( 'Refund reference (optional)', 'coaching-platform' ),
			(int) self::REFUND_REFERENCE_MAX,
			esc_html__( 'Transaction or note number from where you refunded. Letters, digits and . _ - / only.', 'coaching-platform' )
		);
		submit_button( __( 'Confirm: mark refunded', 'coaching-platform' ), 'delete', 'submit', false );
		echo ' <a class="button" href="' . esc_url( $back ) . '">' . esc_html__( 'Cancel', 'coaching-platform' ) . '</a></form>';
	}

	public static function handle_reconcile(): void {
		$payment_id = isset( $_POST['payment_id'] ) ? absint( $_POST['payment_id'] ) : 0;
		check_admin_referer( 'cc_reconcile_payment_' . $payment_id );
		if ( ! current_user_can( 'cc_reconcile_payments' ) ) {
			wp_die( esc_html__( 'You are not allowed to reconcile payments.', 'coaching-platform' ), '', array( 'response' => 403 ) );
		}
		$outcome = self::reconcile( $payment_id, get_current_user_id() );
		$args    = array( 'page' => self::PAGE, 'cc_result' => $outcome['result'] );
		if ( isset( $_POST['back'] ) && 'detail' === $_POST['back'] ) {
			$args['payment'] = $payment_id;
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	// ---- CSV ---------------------------------------------------------------------------------

	/** Chunked generator of export rows; only whitelisted columns are selected. */
	public static function csv_rows( array $args, bool $show_phone ): Generator {
		$args['per_page'] = self::EXPORT_CHUNK;
		$page             = 1;
		do {
			$args['page'] = $page;
			$chunk        = self::query( $args )['rows'];
			foreach ( $chunk as $row ) {
				yield array(
					(string) $row['invoice_number'],
					(string) $row['public_ref'],
					(string) $row['full_name'],
					$show_phone ? (string) $row['student_phone'] : CC_Phone::mask( (string) $row['student_phone'] ),
					(string) $row['amount'],
					(string) $row['currency'],
					(string) $row['gateway'],
					(string) $row['method'],
					(string) $row['status'],
					(string) $row['trx_id'],
					(string) $row['created_at'],
					(string) $row['settled_at'],
				);
			}
			++$page;
		} while ( count( $chunk ) === self::EXPORT_CHUNK );
	}

	public static function handle_export(): void {
		check_admin_referer( 'cc_export_payments' );
		if ( ! current_user_can( 'cc_export_data' ) || ! current_user_can( 'cc_view_payments' ) ) {
			wp_die( esc_html__( 'You are not allowed to export payments.', 'coaching-platform' ), '', array( 'response' => 403 ) );
		}
		if ( ! class_exists( 'CC_Csv' ) ) {
			wp_die( esc_html__( 'CSV export is unavailable.', 'coaching-platform' ), '', array( 'response' => 500 ) );
		}
		$args = self::filters_from( wp_unslash( $_GET ) );
		if ( class_exists( 'CC_Audit' ) ) {
			CC_Audit::log( 'payment.export', 'payment', 0, array_filter( array( 'status' => $args['status'], 'gateway' => $args['gateway'], 'date_from' => $args['date_from'], 'date_to' => $args['date_to'] ) ), 'CSV export' );
		}
		CC_Csv::stream( 'payments-' . gmdate( 'Ymd-His' ) . '.csv', self::CSV_HEADER, self::csv_rows( $args, current_user_can( 'cc_manage_students' ) ) );
	}

	// ---- Rendering ---------------------------------------------------------------------------

	public static function render(): void {
		if ( ! current_user_can( 'cc_view_payments' ) ) {
			wp_die( esc_html__( 'You are not allowed to view payments.', 'coaching-platform' ), '', array( 'response' => 403 ) );
		}
		echo '<div class="wrap"><h1 class="wp-heading-inline">' . esc_html__( 'Payments', 'coaching-platform' ) . '</h1><hr class="wp-header-end">';
		self::render_notice();
		$payment_id = isset( $_GET['payment'] ) ? absint( $_GET['payment'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification -- read-only view.
		$refund_id  = isset( $_GET['refund'] ) ? absint( $_GET['refund'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification -- read-only confirm page; the POST carries the nonce.
		if ( $refund_id > 0 ) {
			self::render_refund_confirm( $refund_id );
		} elseif ( $payment_id > 0 ) {
			self::render_detail( $payment_id );
		} else {
			self::render_list();
		}
		echo '</div>';
	}

	private static function render_notice(): void {
		$code = isset( $_GET['cc_result'] ) ? sanitize_key( wp_unslash( $_GET['cc_result'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! isset( self::RESULT_MESSAGES[ $code ] ) ) {
			return;
		}
		$class = in_array( $code, array( 'settled', 'already_settled', 'refunded', 'refunded_duplicate', 'already_refunded' ), true ) ? 'notice-success' : 'notice-warning';
		printf( '<div class="notice %s is-dismissible"><p>%s <code>%s</code></p></div>', esc_attr( $class ), esc_html( self::RESULT_MESSAGES[ $code ] ), esc_html( $code ) );
	}

	private static function render_list(): void {
		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		require_once __DIR__ . '/class-payments-table.php';
		$args  = self::filters_from( wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$table = new CC_Payments_Table( $args );
		$table->prepare_items();

		self::render_filters( $args );
		printf(
			'<div class="cc-totals" style="margin:12px 0;padding:8px 12px;background:#fff;border:1px solid #c3c4c7"><strong>%s</strong> %s BDT &middot; %s &middot; %s</div>',
			esc_html__( 'Completed in this filter:', 'coaching-platform' ),
			esc_html( number_format_i18n( (float) $table->totals['completed_sum'], 2 ) ),
			/* translators: %d: number of completed payments */
			esc_html( sprintf( _n( '%d completed payment', '%d completed payments', $table->totals['completed_count'], 'coaching-platform' ), $table->totals['completed_count'] ) ),
			/* translators: %d: number of matching rows */
			esc_html( sprintf( _n( '%d row', '%d rows', $table->totals['total'], 'coaching-platform' ), $table->totals['total'] ) )
		);
		$table->display();
	}

	private static function render_filters( array $args ): void {
		$statuses = array_merge( array( 'stuck' ), self::STATUSES );
		echo '<form method="get" class="cc-filters" style="margin:8px 0">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '">';
		echo '<select name="status"><option value="">' . esc_html__( 'All statuses', 'coaching-platform' ) . '</option>';
		foreach ( $statuses as $status ) {
			$label = 'stuck' === $status ? __( 'Needs attention', 'coaching-platform' ) : ucwords( str_replace( '_', ' ', $status ) );
			printf( '<option value="%s"%s>%s</option>', esc_attr( $status ), selected( $args['status'], $status, false ), esc_html( $label ) );
		}
		echo '</select> <select name="gateway"><option value="">' . esc_html__( 'All gateways', 'coaching-platform' ) . '</option>';
		foreach ( self::distinct_gateways() as $gateway ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $gateway ), selected( $args['gateway'], $gateway, false ), esc_html( $gateway ) );
		}
		echo '</select> ';
		printf( '<label>%s <input type="date" name="date_from" value="%s"></label> ', esc_html__( 'From', 'coaching-platform' ), esc_attr( $args['date_from'] ) );
		printf( '<label>%s <input type="date" name="date_to" value="%s"></label> ', esc_html__( 'To', 'coaching-platform' ), esc_attr( $args['date_to'] ) );
		printf( '<input type="search" name="s" value="%s" placeholder="%s"> ', esc_attr( $args['s'] ), esc_attr__( 'Invoice, trx id, phone last 4', 'coaching-platform' ) );
		submit_button( __( 'Filter', 'coaching-platform' ), 'secondary', '', false );
		if ( current_user_can( 'cc_export_data' ) ) {
			$export = wp_nonce_url(
				add_query_arg(
					array_filter( array( 'action' => 'cc_payments_export', 'status' => $args['status'], 'gateway' => $args['gateway'], 'date_from' => $args['date_from'], 'date_to' => $args['date_to'], 's' => $args['s'] ) ),
					admin_url( 'admin-post.php' )
				),
				'cc_export_payments'
			);
			printf( ' <a class="button" href="%s">%s</a>', esc_url( $export ), esc_html__( 'Export CSV', 'coaching-platform' ) );
		}
		echo '</form>';
	}

	public static function reconcile_form( int $payment_id, string $back = 'list' ): string {
		if ( ! current_user_can( 'cc_reconcile_payments' ) ) {
			return '';
		}
		ob_start();
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">';
		echo '<input type="hidden" name="action" value="cc_payment_reconcile">';
		echo '<input type="hidden" name="payment_id" value="' . esc_attr( (string) $payment_id ) . '">';
		echo '<input type="hidden" name="back" value="' . esc_attr( $back ) . '">';
		wp_nonce_field( 'cc_reconcile_payment_' . $payment_id );
		echo '<button type="submit" class="button-link">' . esc_html__( 'Reconcile now', 'coaching-platform' ) . '</button></form>';
		return (string) ob_get_clean();
	}

	/** Status text plus the 'Needs attention' chip, or a muted 'Refunded' chip. */
	public static function status_html( array $row ): string {
		$label = ucwords( str_replace( '_', ' ', (string) ( $row['status'] ?? '' ) ) );
		if ( 'refunded' === ( $row['status'] ?? '' ) ) {
			return '<span class="cc-chip cc-chip--refunded" style="font-size:11px;padding:1px 6px;border-radius:9px;background:#e2e4e7;color:#3c434a">' . esc_html( $label ) . '</span>';
		}
		return esc_html( $label ) . ( self::is_stuck( $row ) ? self::attention_chip() : '' );
	}

	public static function attention_chip(): string {
		return ' <span class="cc-chip cc-chip--attention" style="font-size:11px;padding:1px 6px;border-radius:9px;background:#fcf0cd;color:#6e4e00">' . esc_html__( 'Needs attention', 'coaching-platform' ) . '</span>';
	}

	public static function format_time( ?string $utc ): string {
		if ( null === $utc || '' === $utc ) {
			return '—';
		}
		return get_date_from_gmt( $utc, 'Y-m-d H:i' );
	}

	private static function render_detail( int $payment_id ): void {
		global $wpdb;
		$list = add_query_arg( array( 'page' => self::PAGE ), admin_url( 'admin.php' ) );
		echo '<p><a href="' . esc_url( $list ) . '">&larr; ' . esc_html__( 'All payments', 'coaching-platform' ) . '</a></p>';

		$p   = $wpdb->prefix;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT pay.id, pay.gateway, pay.method, pay.trx_id, pay.amount, pay.status, pay.response_json, pay.created_at, pay.updated_at, pay.settled_at,
					i.number AS invoice_number, i.currency, i.application_id, a.public_ref, a.full_name
				FROM {$p}cc_payments pay LEFT JOIN {$p}cc_invoices i ON i.id = pay.invoice_id LEFT JOIN {$p}cc_applications a ON a.id = i.application_id
				WHERE pay.id = %d",
				$payment_id
			),
			ARRAY_A
		);
		if ( null === $row ) {
			echo '<p>' . esc_html( self::RESULT_MESSAGES['not_found'] ) . '</p>';
			return;
		}

		$stored = json_decode( (string) $row['response_json'], true );
		$reason = is_array( $stored ) ? (string) ( $stored['settle_result'] ?? '' ) : '';
		unset( $row['response_json'] );

		$app_link = add_query_arg( array( 'page' => 'cc-applications', 'view' => (int) $row['application_id'] ), admin_url( 'admin.php' ) );
		echo '<table class="widefat striped" style="max-width:720px"><tbody>';
		$fields = array(
			__( 'Invoice', 'coaching-platform' )     => esc_html( (string) $row['invoice_number'] ),
			__( 'Application', 'coaching-platform' ) => '<a href="' . esc_url( $app_link ) . '">' . esc_html( (string) $row['public_ref'] ) . '</a> ' . esc_html( (string) $row['full_name'] ),
			__( 'Amount', 'coaching-platform' )      => esc_html( number_format_i18n( (float) $row['amount'], 2 ) . ' ' . $row['currency'] ),
			__( 'Gateway', 'coaching-platform' )     => esc_html( $row['gateway'] . ' / ' . $row['method'] ),
			__( 'Status', 'coaching-platform' )      => self::status_html( $row ),
			__( 'Transaction ID', 'coaching-platform' ) => esc_html( (string) $row['trx_id'] ),
			__( 'Created', 'coaching-platform' )     => esc_html( self::format_time( $row['created_at'] ) ),
			__( 'Settled', 'coaching-platform' )     => esc_html( self::format_time( $row['settled_at'] ) ),
		);
		if ( 'reconcile_needed' === $row['status'] && isset( self::REASON_LABELS[ $reason ] ) ) {
			$fields[ __( 'Reason', 'coaching-platform' ) ] = esc_html( self::REASON_LABELS[ $reason ] );
		}
		foreach ( $fields as $label => $html ) {
			echo '<tr><th style="width:160px">' . esc_html( $label ) . '</th><td>' . $html . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput -- values escaped above.
		}
		echo '</tbody></table>';

		if ( ! in_array( $row['status'], array( 'completed', 'refunded' ), true ) ) {
			echo '<p>' . self::reconcile_form( $payment_id, 'detail' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- built with escaping helpers.
		}
		if ( 'completed' === $row['status'] && '' !== self::refund_link( $payment_id ) ) {
			echo '<p>' . self::refund_link( $payment_id ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- built with escaping helpers.
		}

		echo '<h2>' . esc_html__( 'Event timeline', 'coaching-platform' ) . '</h2>';
		$events = self::events( $payment_id );
		if ( ! $events ) {
			echo '<p>' . esc_html__( 'No events recorded.', 'coaching-platform' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped" style="max-width:720px"><thead><tr><th>' . esc_html__( 'Time', 'coaching-platform' ) . '</th><th>' . esc_html__( 'Source', 'coaching-platform' ) . '</th><th>' . esc_html__( 'Outcome', 'coaching-platform' ) . '</th><th>' . esc_html__( 'Gateway status', 'coaching-platform' ) . '</th><th>' . esc_html__( 'Amount', 'coaching-platform' ) . '</th><th>' . esc_html__( 'Trx ID', 'coaching-platform' ) . '</th></tr></thead><tbody>';
		foreach ( $events as $e ) {
			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( self::format_time( $e['received_at'] ) ),
				esc_html( $e['source'] ),
				esc_html( '' !== $e['detail'] ? $e['result'] . ': ' . $e['detail'] : $e['result'] ),
				esc_html( $e['gw_status'] ),
				esc_html( $e['amount'] ),
				esc_html( $e['trx_id'] )
			);
		}
		echo '</tbody></table>';
	}
}
