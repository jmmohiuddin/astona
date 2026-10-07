<?php
defined( 'ABSPATH' ) || exit;

/** Gateway-agnostic payer return route plus the Fake gateway checkout page. */
final class CC_Rest_Payments {

	const PID_PATTERN = '^[A-Za-z0-9_-]{1,64}$';

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_filter( 'rest_pre_serve_request', array( __CLASS__, 'serve_html' ), 10, 2 );
	}

	public static function register_routes(): void {
		$pid_arg = array(
			'type'              => 'string',
			'required'          => true,
			'pattern'           => self::PID_PATTERN,
			'validate_callback' => 'rest_validate_request_arg',
		);

		register_rest_route(
			'cc/v1',
			'/payments/callback',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'callback' ),
				// Public: the payer's browser returns here; nothing in the request is trusted, settlement re-queries the gateway.
				'permission_callback' => '__return_true',
				// bKash appends paymentID (and a status hint) to the registered callback URL; the fake gateway sends pid.
				'args'                => array(
					'pid'       => array_merge( $pid_arg, array( 'required' => false ) ),
					'paymentID' => array_merge( $pid_arg, array( 'required' => false ) ),
					'status'    => array(
						'type'     => 'string',
						'required' => false,
						'enum'     => array( 'success', 'failure', 'cancel' ),
					),
				),
			)
		);

		if ( ! CC_Fake_Gateway::is_allowed() ) {
			return;
		}
		register_rest_route(
			'cc/v1',
			'/payments/fake/checkout',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'fake_checkout_page' ),
					// Public: dev-only page, route is only registered when the fake gateway is allowed.
					'permission_callback' => '__return_true',
					'args'                => array( 'pid' => $pid_arg ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'fake_checkout_action' ),
					// Public: dev-only, route is only registered when the fake gateway is allowed.
					'permission_callback' => '__return_true',
					'args'                => array(
						'pid'    => $pid_arg,
						'action' => array(
							'type'     => 'string',
							'required' => true,
							'enum'     => array( 'pay', 'fail', 'cancel' ),
						),
					),
				),
			)
		);
	}

	public static function callback( WP_REST_Request $request ) {
		global $wpdb;
		try {
			$gateway = CC_Gateway_Factory::make();
		} catch ( Throwable $e ) {
			error_log( 'CC_Rest_Payments callback: gateway unavailable: ' . $e->getMessage() );
			return self::redirect( home_url( '/admissions/?paid=0' ) );
		}
		$gateway_id = (string) ( $request->get_param( 'pid' ) ?: $request->get_param( 'paymentID' ) );
		if ( '' === $gateway_id ) {
			return new WP_Error( 'cc_payment_not_found', 'Payment not found.', array( 'status' => 400 ) );
		}

		$payment = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT p.id, a.public_ref FROM {$wpdb->prefix}cc_payments p
				JOIN {$wpdb->prefix}cc_invoices i ON i.id = p.invoice_id
				JOIN {$wpdb->prefix}cc_applications a ON a.id = i.application_id
				WHERE p.gateway = %s AND p.gateway_payment_id = %s",
				$gateway->id(),
				$gateway_id
			),
			ARRAY_A
		);
		if ( null === $payment ) {
			return new WP_Error( 'cc_payment_not_found', 'Payment not found.', array( 'status' => 404 ) );
		}

		$paid = 0;
		try {
			// The status param is only a hint to skip a pointless execute; settle() re-queries the gateway regardless.
			if ( ! in_array( (string) $request->get_param( 'status' ), array( 'failure', 'cancel' ), true ) ) {
				$gateway->execute( $gateway_id );
			}
			$result = CC_Settlement::settle( (int) $payment['id'], 'callback' );
			$paid   = in_array( $result['result'], array( 'settled', 'already_settled' ), true ) ? 1 : 0;
		} catch ( Throwable $e ) {
			// The reconciler retries; the confirmation page polls status meanwhile.
			error_log( sprintf( 'CC_Rest_Payments callback: payment %d failed: %s', (int) $payment['id'], $e->getMessage() ) );
		}

		return self::redirect( home_url( '/admissions/?ref=' . rawurlencode( $payment['public_ref'] ) . '&paid=' . $paid ) );
	}

	public static function fake_checkout_page( WP_REST_Request $request ) {
		$pid = (string) $request->get_param( 'pid' );
		if ( null === CC_Fake_Gateway::get_state( $pid ) ) {
			return new WP_Error( 'cc_payment_not_found', 'Payment not found.', array( 'status' => 404 ) );
		}
		$url  = esc_url( rest_url( 'cc/v1/payments/fake/checkout' ) );
		$pid_e = esc_attr( $pid );
		$btn  = static function ( string $action, string $label ) use ( $url, $pid_e ): string {
			return sprintf(
				'<form method="post" action="%s"><input type="hidden" name="pid" value="%s"><input type="hidden" name="action" value="%s"><button type="submit">%s</button></form>',
				$url,
				$pid_e,
				esc_attr( $action ),
				esc_html( $label )
			);
		};
		$html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="robots" content="noindex"><title>Fake payment</title></head>'
			. '<body style="font-family:sans-serif;max-width:24rem;margin:3rem auto"><h1>Fake payment gateway</h1><p>Development only.</p>'
			. $btn( 'pay', 'Pay' ) . $btn( 'fail', 'Fail' ) . $btn( 'cancel', 'Cancel' ) . '</body></html>';

		$response = new WP_REST_Response( $html );
		$response->header( 'Content-Type', 'text/html; charset=utf-8' );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	public static function fake_checkout_action( WP_REST_Request $request ) {
		$pid      = (string) $request->get_param( 'pid' );
		$statuses = array(
			'pay'    => 'completed',
			'fail'   => 'failed',
			'cancel' => 'cancelled',
		);
		$state    = CC_Fake_Gateway::get_state( $pid );
		if ( null === $state ) {
			return new WP_Error( 'cc_payment_not_found', 'Payment not found.', array( 'status' => 404 ) );
		}
		CC_Fake_Gateway::set_state( $pid, $statuses[ $request->get_param( 'action' ) ] );
		return self::redirect( add_query_arg( 'pid', rawurlencode( $pid ), $state['callback_url'] ) );
	}

	/** Echoes text/html REST responses verbatim instead of JSON-encoding them. */
	public static function serve_html( $served, $result ) {
		$headers = $result instanceof WP_HTTP_Response ? $result->get_headers() : array();
		if ( isset( $headers['Content-Type'] ) && 0 === strpos( $headers['Content-Type'], 'text/html' ) && is_string( $result->get_data() ) ) {
			echo $result->get_data(); // phpcs:ignore WordPress.Security.EscapeOutput -- built and escaped in fake_checkout_page().
			return true;
		}
		return $served;
	}

	private static function redirect( string $url ): WP_REST_Response {
		$response = new WP_REST_Response( null, 302 );
		$response->header( 'Location', $url );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}
}
