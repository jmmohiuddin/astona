<?php
defined( 'ABSPATH' ) || exit;

/**
 * GET /cc/v1/health for the external uptime monitor (SDD 14). 200 when everything is fine, 503 otherwise.
 * Public, so it reports booleans only: no counts, ids, versions or error text.
 */
final class CC_Rest_Health {

	/** Cron/Action Scheduler is considered stopped when the reconciler has not run for this long. */
	const HEARTBEAT_MAX_AGE = 300;
	/** A payment still initiated/executing after this long needs a human (SDD 14). */
	const STUCK_PAYMENT_SECONDS = 600;

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		register_rest_route(
			'cc/v1',
			'/health',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'handle' ),
				// Public by design: monitors cannot authenticate, and the answer carries no data beyond four booleans.
				'permission_callback' => '__return_true',
			)
		);
	}

	public static function handle(): WP_REST_Response {
		$result   = self::evaluate( self::facts() );
		$response = new WP_REST_Response( $result, $result['ok'] ? 200 : 503 );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * @param array{db:bool,heartbeat_age:?int,stuck_payments:int,reconcile_needed:int} $facts
	 * @return array{ok:bool,checks:array<string,bool>}
	 */
	public static function evaluate( array $facts ): array {
		$checks = array(
			'database'   => (bool) $facts['db'],
			'scheduler'  => null !== $facts['heartbeat_age'] && $facts['heartbeat_age'] <= self::HEARTBEAT_MAX_AGE,
			'payments'   => 0 === (int) $facts['stuck_payments'],
			'reconciled' => 0 === (int) $facts['reconcile_needed'],
		);
		return array( 'ok' => ! in_array( false, $checks, true ), 'checks' => $checks );
	}

	private static function facts(): array {
		global $wpdb;
		$p  = $wpdb->prefix;
		$db = 1 === (int) $wpdb->get_var( 'SELECT 1' );
		if ( ! $db ) {
			return array( 'db' => false, 'heartbeat_age' => null, 'stuck_payments' => 0, 'reconcile_needed' => 0 );
		}
		$beat = (int) get_option( CC_Reconciler::HEARTBEAT_OPTION, 0 );
		return array(
			'db'               => true,
			'heartbeat_age'    => $beat > 0 ? max( 0, time() - $beat ) : null,
			'stuck_payments'   => (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$p}cc_payments WHERE status IN ('initiated','executing') AND created_at < %s",
					gmdate( 'Y-m-d H:i:s', time() - self::STUCK_PAYMENT_SECONDS )
				)
			),
			'reconcile_needed' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}cc_payments WHERE status = 'reconcile_needed'" ),
		);
	}
}
