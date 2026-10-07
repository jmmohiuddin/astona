<?php
defined( 'ABSPATH' ) || exit;

/** Local/test gateway. State lives in a non-autoloaded option keyed by gateway_payment_id. */
final class CC_Fake_Gateway implements CC_Payment_Gateway {

	const OPTION_PREFIX = 'cc_fake_pay_';
	const STATUSES      = array( 'pending', 'completed', 'failed', 'cancelled' );

	const ALLOWED_ENVIRONMENTS = array( 'local', 'development' );

	/** Test seam: forces the environment type seen by is_allowed(); null means use wp_get_environment_type(). */
	private static ?string $environment_override = null;

	public static function override_environment_type( ?string $environment_type ): void {
		self::$environment_override = $environment_type;
	}

	/** The fake gateway is only usable on local/development AND when CC_PAYMENT_GATEWAY is explicitly "fake". */
	public static function is_allowed(): bool {
		$environment = self::$environment_override ?? wp_get_environment_type();
		return in_array( $environment, self::ALLOWED_ENVIRONMENTS, true ) && 'fake' === CC_Gateway_Factory::configured_name();
	}

	public function __construct() {
		if ( ! self::is_allowed() ) {
			throw new RuntimeException( 'The fake payment gateway requires a local/development environment and CC_PAYMENT_GATEWAY=fake.' );
		}
	}

	public function id(): string {
		return 'fake';
	}

	public function create_payment( array $invoice, string $callback_url ): array {
		$pid = 'FAKE' . bin2hex( random_bytes( 12 ) );
		update_option(
			self::OPTION_PREFIX . $pid,
			array(
				'status'       => 'pending',
				'amount'       => (float) $invoice['amount'],
				'trx_id'       => '',
				'callback_url' => $callback_url,
			),
			false
		);
		$redirect = rest_url( 'cc/v1/payments/fake/checkout' ) . '?pid=' . rawurlencode( $pid );
		return array( $pid, $redirect );
	}

	public function execute( string $gateway_payment_id ): array {
		return $this->query( $gateway_payment_id );
	}

	public function query( string $gateway_payment_id ): array {
		$state = self::get_state( $gateway_payment_id );
		if ( null === $state ) {
			return array(
				'status' => 'failed',
				'trx_id' => '',
				'amount' => 0.0,
				'raw'    => array( 'error' => 'unknown_payment' ),
			);
		}
		return array(
			'status' => $state['status'],
			'trx_id' => (string) $state['trx_id'],
			'amount' => (float) $state['amount'],
			'raw'    => $state,
		);
	}

	public static function get_state( string $gateway_payment_id ): ?array {
		$state = get_option( self::OPTION_PREFIX . $gateway_payment_id, null );
		return is_array( $state ) ? $state : null;
	}

	/** Sets the simulated outcome; $amount overrides the charged amount (used to simulate mismatches). */
	public static function set_state( string $gateway_payment_id, string $status, ?float $amount = null ): bool {
		$state = self::get_state( $gateway_payment_id );
		if ( null === $state || ! in_array( $status, self::STATUSES, true ) ) {
			return false;
		}
		$state['status'] = $status;
		if ( null !== $amount ) {
			$state['amount'] = $amount;
		}
		if ( 'completed' === $status && '' === $state['trx_id'] ) {
			$state['trx_id'] = 'TRX' . strtoupper( substr( hash( 'sha256', $gateway_payment_id ), 0, 10 ) );
		}
		update_option( self::OPTION_PREFIX . $gateway_payment_id, $state, false );
		return true;
	}

	public static function delete_state( string $gateway_payment_id ): void {
		delete_option( self::OPTION_PREFIX . $gateway_payment_id );
	}
}
