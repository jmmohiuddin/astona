<?php
defined( 'ABSPATH' ) || exit;

/**
 * Shared plumbing for commercial bulk-SMS gateways that take a single HTTPS POST (SDD 10, TRD FR-010).
 * Credentials come from constants or the environment only. send() never throws for provider-side failures; it returns
 * [false, '', error] so CC_Sms can retry and then switch to the fallback driver. Error text never contains the body
 * or the credentials.
 */
abstract class CC_Sms_Http_Driver implements CC_Sms_Driver {

	const HTTP_TIMEOUT = 8;

	/** @var callable(string $url, array $form): array{0:int,1:string} [http status, raw body]; test seam */
	protected $http;

	public function __construct( ?callable $http = null ) {
		$this->http = $http ?? array( $this, 'wp_http' );
	}

	/** @return string URL the form is POSTed to. */
	abstract protected function endpoint(): string;

	/** @return array<string,string> Form fields for one message. */
	abstract protected function form( string $to_e164, string $body ): array;

	/** @return array{0:bool,1:string,2:string} [ok, provider_id, error] from the raw provider answer. */
	abstract protected function interpret( int $http_status, string $raw ): array;

	final public function send( string $to_e164, string $body ): array {
		try {
			$form = $this->form( $to_e164, $body );
		} catch ( RuntimeException $e ) {
			return array( false, '', $e->getMessage() );
		}
		try {
			list( $status, $raw ) = ( $this->http )( $this->endpoint(), $form );
		} catch ( RuntimeException $e ) {
			return array( false, '', $e->getMessage() );
		}
		if ( $status >= 500 || 0 === $status ) {
			return array( false, '', sprintf( '%s HTTP %d', $this->id(), $status ) );
		}
		return $this->interpret( $status, $raw );
	}

	/** Bangladesh mobile in E.164 (+8801XXXXXXXXX) to the provider's 8801XXXXXXXXX form. */
	protected static function msisdn( string $to_e164 ): string {
		return ltrim( $to_e164, '+' );
	}

	/** @throws RuntimeException When the setting is missing; the message names the setting, never a value. */
	protected static function required( string $name ): string {
		$value = defined( $name ) ? (string) constant( $name ) : (string) getenv( $name );
		if ( '' === trim( $value ) ) {
			throw new RuntimeException( $name . ' is not configured' );
		}
		return $value;
	}

	/** @return array{0:int,1:string} */
	private function wp_http( string $url, array $form ): array {
		$response = wp_remote_post(
			$url,
			array(
				'timeout'     => self::HTTP_TIMEOUT,
				'redirection' => 0,
				'body'        => $form,
			)
		);
		if ( is_wp_error( $response ) ) {
			throw new RuntimeException( $this->id() . ' transport error: ' . $response->get_error_code() );
		}
		return array( (int) wp_remote_retrieve_response_code( $response ), (string) wp_remote_retrieve_body( $response ) );
	}
}
