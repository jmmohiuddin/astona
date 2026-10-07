<?php
defined( 'ABSPATH' ) || exit;

interface CC_Sms_Driver {

	public function id(): string;

	/**
	 * @param string $to_e164 Recipient in E.164 (+8801XXXXXXXXX).
	 * @param string $body    Final message text.
	 * @return array{0:bool,1:string,2:string} [ok, provider_id, error]
	 */
	public function send( string $to_e164, string $body ): array;
}
