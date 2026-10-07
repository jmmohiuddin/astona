<?php
defined( 'ABSPATH' ) || exit;

final class CC_Gateway_Factory {

	/** Configured driver name (constant, then env), lower-cased; '' when unset. */
	public static function configured_name(): string {
		$name = defined( 'CC_PAYMENT_GATEWAY' ) ? (string) CC_PAYMENT_GATEWAY : (string) getenv( 'CC_PAYMENT_GATEWAY' );
		return strtolower( trim( $name ) );
	}

	/** @throws RuntimeException When CC_PAYMENT_GATEWAY is unset, unknown, or the fake gateway is not allowed here. */
	public static function make(): CC_Payment_Gateway {
		$name = self::configured_name();
		if ( '' === $name ) {
			throw new RuntimeException( 'CC_PAYMENT_GATEWAY is not configured; set it explicitly (e.g. "fake" on local/development only).' );
		}

		if ( 'fake' === $name ) {
			return new CC_Fake_Gateway();
		}
		if ( 'bkash' === $name ) {
			return new CC_Bkash_Gateway();
		}
		throw new RuntimeException( sprintf( 'Unknown payment gateway "%s".', $name ) );
	}
}
