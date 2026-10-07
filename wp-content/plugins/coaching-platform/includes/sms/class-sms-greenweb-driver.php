<?php
defined( 'ABSPATH' ) || exit;

/**
 * GreenWeb BD (https://api.greenweb.com.bd) driver. Setting: GREENWEB_TOKEN.
 * Success is a plain-text answer starting with "Ok:"; anything else is an error. Confirm the sender ID and Unicode
 * (Bangla) rules on the real account before launch (OQ-02).
 */
final class CC_Sms_Greenweb_Driver extends CC_Sms_Http_Driver {

	const ENDPOINT = 'https://api.greenweb.com.bd/api.php';

	public function id(): string {
		return 'greenweb';
	}

	protected function endpoint(): string {
		return self::ENDPOINT;
	}

	protected function form( string $to_e164, string $body ): array {
		return array(
			'token'   => self::required( 'GREENWEB_TOKEN' ),
			'to'      => self::msisdn( $to_e164 ),
			'message' => $body,
		);
	}

	protected function interpret( int $http_status, string $raw ): array {
		$text = trim( $raw );
		if ( 0 === stripos( $text, 'ok' ) ) {
			return array( true, 'greenweb-' . substr( hash( 'sha256', $text . microtime() ), 0, 12 ), '' );
		}
		// Only the provider's own status text, trimmed; the request body is never echoed back by this API.
		return array( false, '', 'greenweb rejected: ' . substr( preg_replace( '/[^\x20-\x7E]/', '', $text ), 0, 80 ) );
	}
}
