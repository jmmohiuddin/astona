<?php
defined( 'ABSPATH' ) || exit;

interface CC_Payment_Gateway {

	public function id(): string;

	/**
	 * @param array  $invoice      Invoice row (id, number, amount, currency).
	 * @param string $callback_url Absolute URL the gateway sends the payer back to; the gateway appends pid=<gateway_payment_id>.
	 * @return array{0:string,1:string} [gateway_payment_id, redirect_url]
	 */
	public function create_payment( array $invoice, string $callback_url ): array;

	/** @return array{status:string,trx_id:string,amount:float,raw:array} status: completed|failed|cancelled|pending */
	public function execute( string $gateway_payment_id ): array;

	/** @return array{status:string,trx_id:string,amount:float,raw:array} same shape as execute() */
	public function query( string $gateway_payment_id ): array;
}
