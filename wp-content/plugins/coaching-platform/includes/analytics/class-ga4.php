<?php
defined( 'ABSPATH' ) || exit;

/**
 * Server-side GA4 `payment_success` via the Measurement Protocol (TRD FR-023, PRD 27).
 * Sent only after settle() verified the payment, so it cannot be inflated from the browser. Queued so a slow or failing
 * Google endpoint never delays settlement. No personal data: the client id is a hash of the application reference.
 * Settings: GA4_MEASUREMENT_ID (public) and GA4_API_SECRET (secret), constants or environment. Both unset = disabled.
 */
final class CC_Ga4 {

	const HOOK     = 'cc_ga4_payment_success';
	const GROUP    = 'cc-ga4';
	const ENDPOINT = 'https://www.google-analytics.com/mp/collect';

	public static function init(): void {
		add_action( 'cc_application_settled', array( __CLASS__, 'schedule' ), 20, 2 );
		add_action( self::HOOK, array( __CLASS__, 'run' ), 10, 2 );
	}

	public static function enabled(): bool {
		return '' !== self::setting( 'GA4_MEASUREMENT_ID' ) && '' !== self::setting( 'GA4_API_SECRET' );
	}

	public static function schedule( $application_id, $payment_id = 0 ): void {
		if ( ! self::enabled() || (int) $payment_id < 1 ) {
			return;
		}
		$args = array( (int) $application_id, (int) $payment_id );
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::HOOK, $args, self::GROUP, true );
			return;
		}
		wp_schedule_single_event( time() + 5, self::HOOK, $args );
	}

	/** Action Scheduler callback. A failed send is logged and dropped: analytics must never block or retry-storm. */
	public static function run( $application_id, $payment_id ): void {
		global $wpdb;
		$p   = $wpdb->prefix;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT pay.trx_id, pay.amount, i.currency, a.public_ref, a.batch_id
				FROM {$p}cc_payments pay JOIN {$p}cc_invoices i ON i.id = pay.invoice_id JOIN {$p}cc_applications a ON a.id = i.application_id
				WHERE pay.id = %d AND a.id = %d AND pay.status = 'completed'",
				(int) $payment_id,
				(int) $application_id
			),
			ARRAY_A
		);
		if ( null === $row ) {
			return;
		}
		$request = self::request( $row );
		$res     = wp_remote_post( $request['url'], array( 'timeout' => 5, 'redirection' => 0, 'headers' => array( 'Content-Type' => 'application/json' ), 'body' => wp_json_encode( $request['body'] ) ) );
		if ( is_wp_error( $res ) ) {
			error_log( 'CC_Ga4: measurement protocol send failed: ' . $res->get_error_code() );
		}
	}

	/**
	 * @param array{trx_id:string,amount:string,currency:string,public_ref:string,batch_id:string|int} $row
	 * @return array{url:string,body:array}
	 */
	public static function request( array $row ): array {
		return array(
			'url'  => self::ENDPOINT . '?' . http_build_query(
				array(
					'measurement_id' => self::setting( 'GA4_MEASUREMENT_ID' ),
					'api_secret'     => self::setting( 'GA4_API_SECRET' ),
				)
			),
			'body' => array(
				'client_id' => substr( hash( 'sha256', 'cc-ga4|' . $row['public_ref'] ), 0, 20 ) . '.1',
				'events'    => array(
					array(
						'name'   => 'payment_success',
						'params' => array(
							'transaction_id' => (string) $row['trx_id'],
							'value'          => round( (float) $row['amount'], 2 ),
							'currency'       => (string) $row['currency'],
							'batch_id'       => (int) $row['batch_id'],
						),
					),
				),
			),
		);
	}

	private static function setting( string $name ): string {
		return trim( defined( $name ) ? (string) constant( $name ) : (string) getenv( $name ) );
	}
}
