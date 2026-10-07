<?php
defined( 'ABSPATH' ) || exit;

/**
 * Two-part fee payment (PRD open question A-05; default policy, all numbers per batch or in Settings):
 *  - A batch may allow "pay in two parts". The applicant pays the first part (10-90% of the fee, rounded up) at
 *    admission; that payment approves the application, takes the seat and creates the account exactly like a full payment.
 *  - The invoice becomes `partial` with a due date (batch "balance due after N days"). The student pays the balance from
 *    the portal. Another gateway payment of kind `balance` is created on the same invoice.
 *  - Reminders go out 3 days before the due date, on it, and every 7 days overdue. Optionally (Settings) access is paused
 *    after N days overdue and resumes the moment the balance is paid; 0 days turns pausing off (the default).
 */
final class CC_Installments {

	const REMINDER_LEAD_DAYS   = 3;
	const OVERDUE_REPEAT_DAYS  = 7;
	const PAYMENT_OPEN_SECONDS = 1800;
	const OPTION_SUSPEND_DAYS  = 'cc_installment_suspend_days';

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'cc_daily', array( __CLASS__, 'run_reminders' ) );
	}

	public static function register_routes(): void {
		register_rest_route(
			'cc/v1',
			'/me/payments/(?P<invoice>\d+)/balance',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'rest_pay_balance' ),
				'permission_callback' => array( 'CC_Rest_Auth', 'is_student_request' ),
				'args'                => array( 'invoice' => array( 'type' => 'integer', 'required' => true ) ),
			)
		);
	}

	public static function suspend_after_days(): int {
		return max( 0, (int) get_option( self::OPTION_SUSPEND_DAYS, 0 ) );
	}

	/** @return array<int,array<string,mixed>> The user's unpaid balances, soonest due first. */
	public static function outstanding( int $user_id ): array {
		global $wpdb;
		$p    = $wpdb->prefix;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT i.id AS invoice_id, i.number, i.amount, i.amount_paid, i.currency, i.due_at, i.suspended_at, a.batch_id
				FROM {$p}cc_invoices i JOIN {$p}cc_applications a ON a.id = i.application_id
				WHERE a.user_id = %d AND i.status = 'partial' AND a.status = 'approved' ORDER BY i.due_at ASC",
				$user_id
			),
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $row ) {
			$batch               = CC_Batch_Repository::find( (int) $row['batch_id'] );
			$row['balance']      = round( (float) $row['amount'] - (float) $row['amount_paid'], 2 );
			$row['batch_name']   = $batch ? (string) $batch['name'] : '';
			$row['overdue']      = ! empty( $row['due_at'] ) && strtotime( $row['due_at'] . ' UTC' ) < time();
			$row['suspended']    = ! empty( $row['suspended_at'] );
			$out[]               = $row;
		}
		return $out;
	}

	public static function rest_pay_balance( WP_REST_Request $request ): WP_REST_Response {
		$result = self::start_balance_payment( get_current_user_id(), (int) $request['invoice'] );
		$resp   = is_wp_error( $result )
			? new WP_REST_Response( array( 'code' => $result->get_error_code(), 'message' => $result->get_error_message() ), (int) ( $result->get_error_data()['status'] ?? 400 ) )
			: new WP_REST_Response( array( 'redirect_url' => $result ), 200 );
		$resp->header( 'Cache-Control', 'private, no-store' );
		return $resp;
	}

	/** @return string|WP_Error Gateway checkout URL. */
	public static function start_balance_payment( int $user_id, int $invoice_id ) {
		global $wpdb;
		$p = $wpdb->prefix;
		if ( ! CC_Rate_Limiter::allow( 'balance_pay|' . $user_id, 10, HOUR_IN_SECONDS ) ) {
			return new WP_Error( 'rate_limited', 'Too many attempts. Please try again later.', array( 'status' => 429 ) );
		}
		$invoice = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT i.*, a.public_ref FROM {$p}cc_invoices i JOIN {$p}cc_applications a ON a.id = i.application_id WHERE i.id = %d AND a.user_id = %d AND a.status = 'approved'",
				$invoice_id,
				$user_id
			),
			ARRAY_A
		);
		if ( ! is_array( $invoice ) ) {
			return new WP_Error( 'not_found', 'We could not find that invoice.', array( 'status' => 404 ) );
		}
		if ( 'partial' !== $invoice['status'] ) {
			return new WP_Error( 'nothing_due', 'Nothing is due on this invoice.', array( 'status' => 409 ) );
		}
		$lock = 'cc_balance_' . $invoice_id;
		if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock ) ) ) {
			return new WP_Error( 'payment_in_progress', 'A payment is already being processed. Please wait a moment.', array( 'status' => 409 ) );
		}
		try {
			foreach ( CC_Application_Repository::payments_for_invoice( $invoice_id ) as $payment ) {
				if ( 'balance' !== $payment['kind'] || ! in_array( $payment['status'], array( 'initiated', 'executing', 'reconcile_needed' ), true ) ) {
					continue;
				}
				if ( 'reconcile_needed' === $payment['status'] ) {
					return new WP_Error( 'payment_needs_review', 'A payment needs review. Please contact us.', array( 'status' => 409 ) );
				}
				// Settle first: the student may already have paid and closed the browser.
				try {
					$settled = CC_Settlement::settle( (int) $payment['id'], 'callback' );
				} catch ( Throwable $e ) {
					return new WP_Error( 'gateway_unavailable', 'Payment is temporarily unavailable. Please try again shortly.', array( 'status' => 503 ) );
				}
				if ( 'balance_settled' === $settled['result'] ) {
					return new WP_Error( 'already_paid', 'Your payment has been received.', array( 'status' => 409 ) );
				}
				$fresh = CC_Application_Repository::find_payment( (int) $payment['id'] );
				$age   = time() - (int) strtotime( (string) $payment['created_at'] . ' UTC' );
				if ( $fresh && in_array( $fresh['status'], array( 'initiated', 'executing' ), true ) && $age < self::PAYMENT_OPEN_SECONDS ) {
					return new WP_Error( 'payment_in_progress', 'A payment is still in progress. Please wait a few minutes and check again.', array( 'status' => 409 ) );
				}
			}
			$balance = round( (float) $invoice['amount'] - (float) $invoice['amount_paid'], 2 );
			if ( $balance <= 0 ) {
				return new WP_Error( 'nothing_due', 'Nothing is due on this invoice.', array( 'status' => 409 ) );
			}
			try {
				$gateway = CC_Gateway_Factory::make();
			} catch ( Throwable $e ) {
				return new WP_Error( 'gateway_unavailable', 'Payment is temporarily unavailable. Please try again shortly.', array( 'status' => 503 ) );
			}
			$amount     = number_format( $balance, 2, '.', '' );
			$payment_id = CC_Application_Repository::insert_payment( $invoice, $gateway->id(), $amount, 'balance' );
			try {
				$created = $gateway->create_payment(
					array( 'id' => (int) $invoice['id'], 'number' => $invoice['number'], 'amount' => (float) $amount, 'currency' => $invoice['currency'], 'application_ref' => $invoice['public_ref'], 'payment_id' => $payment_id ),
					rest_url( 'cc/v1/payments/callback' )
				);
			} catch ( Throwable $e ) {
				CC_Application_Repository::mark_payment_failed( $payment_id );
				return new WP_Error( 'gateway_unavailable', 'Payment is temporarily unavailable. Please try again shortly.', array( 'status' => 503 ) );
			}
			list( $gateway_payment_id, $redirect ) = array_values( $created );
			CC_Application_Repository::attach_gateway_payment( $payment_id, (string) $gateway_payment_id, (string) $redirect );
			return (string) $redirect;
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	/**
	 * Which reminder (if any) an invoice is due for now. Pure so the schedule is unit-tested.
	 *
	 * @param string $sent Last reminder sent: '', 'due3', 'due0', or 'over<N>' (N = days overdue when it was sent).
	 * @return string '' for none, else due3 | due0 | over<N>
	 */
	public static function reminder_for( int $due_at, int $now, string $sent ): string {
		$days_left = (int) floor( ( $due_at - $now ) / DAY_IN_SECONDS );
		if ( $now < $due_at ) {
			return $days_left < self::REMINDER_LEAD_DAYS && '' === $sent ? 'due3' : '';
		}
		$over = (int) floor( ( $now - $due_at ) / DAY_IN_SECONDS );
		if ( 0 === $over ) {
			return in_array( $sent, array( '', 'due3' ), true ) ? 'due0' : '';
		}
		$last_over = 1 === preg_match( '/^over(\d+)$/', $sent, $m ) ? (int) $m[1] : -1;
		return ( $over - max( 0, $last_over ) >= self::OVERDUE_REPEAT_DAYS || -1 === $last_over && $over >= 1 ) ? 'over' . $over : '';
	}

	/** Daily: reminders and optional pausing. @return array{reminded:int,suspended:int} */
	public static function run_reminders( ?int $now = null ): array {
		global $wpdb;
		$p       = $wpdb->prefix;
		$now     = $now ?? time();
		$suspend = self::suspend_after_days();
		$rows    = $wpdb->get_results(
			"SELECT i.id, i.application_id, i.amount, i.amount_paid, i.due_at, i.suspended_at, i.last_reminder, a.student_phone, a.user_id, a.batch_id
			FROM {$p}cc_invoices i JOIN {$p}cc_applications a ON a.id = i.application_id
			WHERE i.status = 'partial' AND i.due_at IS NOT NULL AND a.status = 'approved'",
			ARRAY_A
		);
		$out = array( 'reminded' => 0, 'suspended' => 0 );
		foreach ( (array) $rows as $row ) {
			$due     = (int) strtotime( $row['due_at'] . ' UTC' );
			$batch   = CC_Batch_Repository::find( (int) $row['batch_id'] );
			$vars    = array(
				'amount' => number_format( (float) $row['amount'] - (float) $row['amount_paid'], 0 ),
				'batch'  => $batch ? (string) $batch['name'] : '',
				'date'   => gmdate( 'j M Y', $due ),
				'url'    => home_url( '/student/payments/' ),
			);
			$phone   = self::phone_for( (int) $row['user_id'], (string) $row['student_phone'] );
			$reminder = self::reminder_for( $due, $now, (string) $row['last_reminder'] );
			if ( '' !== $reminder ) {
				try {
					CC_Sms::queue( $phone, 'due3' === $reminder || 'due0' === $reminder ? 'balance_due' : 'balance_overdue', $vars, 'invoice', (int) $row['id'] );
					$wpdb->update( "{$p}cc_invoices", array( 'last_reminder' => $reminder ), array( 'id' => (int) $row['id'] ) );
					++$out['reminded'];
				} catch ( Throwable $e ) {
					error_log( 'CC_Installments: reminder SMS failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				}
			}
			if ( $suspend > 0 && empty( $row['suspended_at'] ) && $now >= $due + $suspend * DAY_IN_SECONDS ) {
				$wpdb->query( $wpdb->prepare( "UPDATE {$p}cc_enrollments SET status = 'deactivated' WHERE application_id = %d AND status = 'active'", $row['application_id'] ) );
				$wpdb->update( "{$p}cc_invoices", array( 'suspended_at' => gmdate( 'Y-m-d H:i:s', $now ) ), array( 'id' => (int) $row['id'] ) );
				try {
					CC_Sms::queue( $phone, 'balance_suspended', $vars, 'invoice', (int) $row['id'] );
				} catch ( Throwable $e ) {
					error_log( 'CC_Installments: suspension SMS failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				}
				++$out['suspended'];
			}
		}
		return $out;
	}

	/** The login number if the account exists, else the application phone. */
	private static function phone_for( int $user_id, string $fallback ): string {
		$user = $user_id > 0 ? get_userdata( $user_id ) : false;
		return $user ? '+' . $user->user_login : $fallback;
	}
}
