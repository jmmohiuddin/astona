<?php
defined( 'ABSPATH' ) || exit;

/** A gateway that can send money back to the payer. Optional: CC_Payment_Gateway drivers without it are refunded outside the system. */
interface CC_Refundable_Gateway extends CC_Payment_Gateway {

	/**
	 * @param string $gateway_payment_id The id returned by create_payment().
	 * @param string $trx_id             The gateway transaction id of the original payment.
	 * @return array{ok:bool,refund_trx_id:string,error:string}
	 */
	public function refund( string $gateway_payment_id, string $trx_id, float $amount, string $reason ): array;
}
