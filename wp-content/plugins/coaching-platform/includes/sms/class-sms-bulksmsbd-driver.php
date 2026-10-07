<?php
defined( 'ABSPATH' ) || exit;

/**
 * BulkSMSBD (https://bulksmsbd.net) driver. Settings: BULKSMSBD_API_KEY, BULKSMSBD_SENDER_ID.
 * Success is JSON {"response_code":202,...}; any other code is a provider error. Confirm the sender ID approval and
 * Unicode (Bangla) handling on the real account before launch (OQ-02).
 */
final class CC_Sms_Bulksmsbd_Driver extends CC_Sms_Http_Driver {

	const ENDPOINT = 'https://bulksmsbd.net/api/smsapi';

	/** Provider response codes worth naming in the log (error text only; no secrets). */
	const ERRORS = array(
		1002 => 'sender id not correct or disabled',
		1003 => 'required fields missing',
		1005 => 'internal error',
		1006 => 'balance validity not available',
		1007 => 'insufficient balance',
		1011 => 'user id not found',
		1012 => 'masking sms must be sent in bengali',
		1013 => 'sender id has not found api key',
		1014 => 'sender type name not found',
		1015 => 'sender id has not found any valid gateway',
		1016 => 'sender type name active price info not found',
		1017 => 'sender type name price info not found',
		1018 => 'account is disabled',
		1019 => 'sender type name price is disabled',
		1020 => 'parent account not found',
		1021 => 'parent active sender type price not found',
		1031 => 'account not verified',
		1032 => 'ip not whitelisted',
	);

	public function id(): string {
		return 'bulksmsbd';
	}

	protected function endpoint(): string {
		return self::ENDPOINT;
	}

	protected function form( string $to_e164, string $body ): array {
		return array(
			'api_key'  => self::required( 'BULKSMSBD_API_KEY' ),
			'senderid' => self::required( 'BULKSMSBD_SENDER_ID' ),
			'number'   => self::msisdn( $to_e164 ),
			'message'  => $body,
		);
	}

	protected function interpret( int $http_status, string $raw ): array {
		$json = json_decode( $raw, true );
		$code = is_array( $json ) ? (int) ( $json['response_code'] ?? 0 ) : 0;
		if ( 202 === $code ) {
			return array( true, 'bulksmsbd-' . substr( hash( 'sha256', $raw . microtime() ), 0, 12 ), '' );
		}
		return array( false, '', 'bulksmsbd rejected: ' . ( self::ERRORS[ $code ] ?? ( $code ? 'code ' . $code : 'HTTP ' . $http_status ) ) );
	}
}
