<?php
defined( 'ABSPATH' ) || exit;

/** Local/test driver. Messages (including credentials/OTP bodies) land in a non-autoloaded option; never usable outside local/development. */
final class CC_Sms_Fake_Driver implements CC_Sms_Driver {

	const OPTION_OUTBOX = 'cc_fake_sms_outbox';
	const OUTBOX_LIMIT  = 50;

	const ALLOWED_ENVIRONMENTS = array( 'local', 'development' );

	/** Test seam: forces the environment type seen by is_allowed(); null means use wp_get_environment_type(). */
	private static ?string $environment_override = null;

	public static function override_environment_type( ?string $environment_type ): void {
		self::$environment_override = $environment_type;
	}

	/** Usable only on local/development AND when CC_SMS_PRIMARY or CC_SMS_FALLBACK is explicitly "fake". */
	public static function is_allowed(): bool {
		$environment = self::$environment_override ?? wp_get_environment_type();
		return in_array( $environment, self::ALLOWED_ENVIRONMENTS, true )
			&& ( 'fake' === CC_Sms_Factory::configured_name( 'primary' ) || 'fake' === CC_Sms_Factory::configured_name( 'fallback' ) );
	}

	public function __construct() {
		if ( ! self::is_allowed() ) {
			throw new RuntimeException( 'The fake SMS driver requires a local/development environment and CC_SMS_PRIMARY or CC_SMS_FALLBACK set to "fake".' );
		}
	}

	public function id(): string {
		return 'fake';
	}

	public function send( string $to_e164, string $body ): array {
		$outbox   = self::outbox();
		$outbox[] = array(
			'to'   => $to_e164,
			'body' => $body,
			'time' => gmdate( 'Y-m-d H:i:s' ),
		);
		update_option( self::OPTION_OUTBOX, array_slice( $outbox, -self::OUTBOX_LIMIT ), false );
		return array( true, 'fake-' . bin2hex( random_bytes( 6 ) ), '' );
	}

	/** @return array<int,array{to:string,body:string,time:string}> Oldest first, last 50 messages. */
	public static function outbox(): array {
		$outbox = get_option( self::OPTION_OUTBOX, array() );
		return is_array( $outbox ) ? array_values( $outbox ) : array();
	}

	public static function clear(): void {
		delete_option( self::OPTION_OUTBOX );
	}
}
