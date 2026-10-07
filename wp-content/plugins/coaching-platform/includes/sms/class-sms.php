<?php
defined( 'ABSPATH' ) || exit;

/**
 * SMS queue and sender. Bodies for secret templates (credentials, otp) are never stored: their variables live in a
 * short-lived transient, stored encrypted, that is deleted once the message is sent or has permanently failed.
 */
final class CC_Sms {

	const HOOK                 = 'cc_send_sms';
	const GROUP                = 'cc';
	const MAX_PRIMARY_ATTEMPTS = 3;
	const RETRY_BACKOFF        = array( 30, 120 ); // Seconds before primary attempt 2 and 3.
	const PAYLOAD_TTL          = 3600;
	const PAYLOAD_PREFIX       = 'cc_sms_vars_';
	const ERROR_MAX_LENGTH     = 255;

	const SECRET_TEMPLATES = array( 'credentials', 'otp', 'apply_otp', 'phone_otp' );

	const TEMPLATES = array(
		'credentials' => array(
			'en' => 'Astona: Login phone {phone}, temporary password {password}. Login at {url} (valid 72 hours, change it on first login).',
			'bn' => 'আস্তোনা: লগইন ফোন {phone}, অস্থায়ী পাসওয়ার্ড {password}। {url} এ লগইন করুন (৭২ ঘণ্টা বৈধ, প্রথম লগইনে পাসওয়ার্ড বদলান)।',
		),
		'otp'         => array(
			'en' => 'Astona: Your login code is {code}. It expires in 5 minutes. Do not share it.',
			'bn' => 'আস্তোনা: আপনার লগইন কোড {code}। ৫ মিনিট পর্যন্ত বৈধ। কাউকে জানাবেন না।',
		),
		'apply_otp'   => array(
			'en' => 'Astona: Your phone verification code for your admission application is {code}. It expires in 5 minutes. Do not share it.',
			'bn' => 'আস্তোনা: আপনার ভর্তি আবেদনের ফোন যাচাই কোড {code}। ৫ মিনিট পর্যন্ত বৈধ। কাউকে জানাবেন না।',
		),
		'phone_otp'   => array(
			'en' => 'Astona: Your code to change your login mobile number is {code}. It expires in 5 minutes. Do not share it.',
			'bn' => 'আস্তোনা: আপনার লগইন মোবাইল নম্বর বদলানোর কোড {code}। ৫ মিনিট পর্যন্ত বৈধ। কাউকে জানাবেন না।',
		),
		'receipt'     => array(
			'en' => 'Astona: Payment received. Receipt {number}, BDT {amount}, Trx {trx_id}. Thank you.',
			'bn' => 'আস্তোনা: পেমেন্ট গৃহীত হয়েছে। রসিদ {number}, ৳{amount}, ট্রানজেকশন {trx_id}। ধন্যবাদ।',
		),
		'login_hint'  => array(
			'en' => 'To sign in to Astona, open {url} and choose Login with OTP. We will text you a code.',
			'bn' => 'আস্তোনায় সাইন ইন করতে {url} খুলে "OTP দিয়ে লগইন" বেছে নিন। আমরা আপনাকে একটি কোড পাঠাব।',
		),
		'enrolled'    => array(
			'en' => 'Astona: A new course has been added to your account: {course}. Login at {url}',
			'bn' => 'আস্তোনা: আপনার অ্যাকাউন্টে নতুন কোর্স যুক্ত হয়েছে: {course}। লগইন করুন {url}',
		),
		'notice'      => array(
			'en' => 'Astona notice: {title}. Read: {url}',
			'bn' => 'আস্তোনা নোটিশ: {title}। দেখুন: {url}',
		),
		'phone_changed' => array(
			'en' => 'Astona: The login number on your account was changed to {new_phone}. If this was not you, contact us now.',
			'bn' => 'আস্তোনা: আপনার অ্যাকাউন্টের লগইন নম্বর বদলে {new_phone} করা হয়েছে। আপনি না করলে এখনই আমাদের জানান।',
		),
		'application_rejected' => array(
			'en' => 'Astona: Your application was not approved. Please contact us.',
			'bn' => 'আস্তোনা: আপনার আবেদনটি অনুমোদিত হয়নি। অনুগ্রহ করে আমাদের সাথে যোগাযোগ করুন।',
		),
	);

	const GSM7_BASIC     = "@£\$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !\"#¤%&'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà";
	const GSM7_EXTENDED  = "\f^{}\\[~]|€";
	const GSM7_SINGLE    = 160;
	const GSM7_MULTI     = 153;
	const UCS2_SINGLE    = 70;
	const UCS2_MULTI     = 67;

	public static function init(): void {
		add_action( self::HOOK, array( __CLASS__, 'handle' ), 10, 1 );
	}

	/** Renders the template, writes a cc_sms_log row and schedules the async send. @return int Log id. */
	public static function queue( string $to_e164, string $template, array $vars = array(), string $related_type = '', int $related_id = 0 ): int {
		global $wpdb;
		$body      = self::render( $template, $vars ); // Throws on unknown template; kept in memory only for secret templates.
		$is_secret = in_array( $template, self::SECRET_TEMPLATES, true );

		$row = array(
			'to_phone'     => $to_e164,
			'template'     => $template,
			'body_hash'    => $is_secret ? hash( 'sha256', $template . '|' . $to_e164 . '|' . bin2hex( random_bytes( 16 ) ) ) : hash( 'sha256', $body ),
			'body_enc'     => $is_secret ? null : CC_Crypto::encrypt( $body ),
			'segments'     => max( 1, self::segments( $body ) ),
			'status'       => 'queued',
			'attempts'     => 0,
			'related_type' => '' === $related_type ? null : $related_type,
			'related_id'   => 0 === $related_id ? null : $related_id,
			'created_at'   => gmdate( 'Y-m-d H:i:s' ),
		);
		if ( false === $wpdb->insert( $wpdb->prefix . 'cc_sms_log', $row ) ) {
			throw new RuntimeException( 'Could not write the SMS log row.' );
		}
		$log_id = (int) $wpdb->insert_id;

		if ( $is_secret ) {
			set_transient( self::PAYLOAD_PREFIX . $log_id, CC_Crypto::encrypt( wp_json_encode( $vars ) ), self::PAYLOAD_TTL );
		}
		self::schedule( $log_id, 0 );
		return $log_id;
	}

	/** Cron/Action Scheduler entry point: one send step, rescheduling with backoff when a primary attempt fails. */
	public static function handle( $log_id ): void {
		$log_id = (int) $log_id;
		try {
			$result = self::send( $log_id );
		} catch ( Throwable $e ) {
			self::mark_failed( $log_id, 'Unexpected error: ' . get_class( $e ) );
			return;
		}
		if ( 'retry' === $result ) {
			$attempts = (int) self::row( $log_id )['attempts'];
			self::schedule( $log_id, self::RETRY_BACKOFF[ $attempts - 1 ] ?? end( self::RETRY_BACKOFF ) );
		}
	}

	/**
	 * Performs one send step. Primary is tried up to 3 times (one per call, the caller backs off between calls);
	 * after the third failure the fallback driver is tried in the same call.
	 *
	 * @return string sent|retry|failed|skipped (skipped: row missing or no longer queued)
	 */
	public static function send( int $log_id ): string {
		$row = self::row( $log_id );
		if ( null === $row || 'queued' !== $row['status'] ) {
			return 'skipped';
		}
		$body = self::resolve_body( $row );
		if ( null === $body ) {
			self::mark_failed( $log_id, 'Message payload is no longer available.' );
			return 'failed';
		}

		$attempts   = (int) $row['attempts'];
		$last_error = (string) $row['error'];
		if ( $attempts < self::MAX_PRIMARY_ATTEMPTS ) {
			try {
				$primary = CC_Sms_Factory::primary();
			} catch ( RuntimeException $e ) {
				self::mark_failed( $log_id, $e->getMessage() );
				return 'failed';
			}
			++$attempts;
			list( $ok, , $error ) = self::attempt( $primary, $row, $body );
			if ( $ok ) {
				self::mark_sent( $log_id, $primary->id(), $attempts );
				return 'sent';
			}
			$last_error = self::redact( $error, $row );
			self::record_attempt( $log_id, $attempts, $last_error );
			if ( $attempts < self::MAX_PRIMARY_ATTEMPTS ) {
				return 'retry';
			}
		}

		try {
			$fallback = CC_Sms_Factory::fallback();
		} catch ( RuntimeException $e ) {
			self::mark_failed( $log_id, $e->getMessage() );
			return 'failed';
		}
		if ( null === $fallback ) {
			self::mark_failed( $log_id, 'Primary failed after ' . self::MAX_PRIMARY_ATTEMPTS . ' attempts and no fallback is configured: ' . $last_error );
			return 'failed';
		}
		list( $ok, , $error ) = self::attempt( $fallback, $row, $body );
		if ( $ok ) {
			self::mark_sent( $log_id, $fallback->id(), $attempts );
			return 'sent';
		}
		self::mark_failed( $log_id, 'Primary and fallback failed: ' . self::redact( $error, $row ) );
		return 'failed';
	}

	/** @param array<string,mixed> $vars */
	public static function render( string $template, array $vars ): string {
		if ( ! isset( self::TEMPLATES[ $template ] ) ) {
			throw new InvalidArgumentException( sprintf( 'Unknown SMS template "%s".', $template ) );
		}
		$variants = self::TEMPLATES[ $template ];
		$text     = 'bn' === ( $vars['lang'] ?? 'en' ) ? $variants['bn'] : $variants['en'];

		preg_match_all( '/\{[a-z_]+\}/', $text, $found );
		$replacements = array_fill_keys( $found[0], '' );
		foreach ( $vars as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$replacements[ '{' . $key . '}' ] = preg_replace( '/[\r\n]+/', ' ', (string) $value );
			}
		}
		return strtr( $text, $replacements );
	}

	/** Number of SMS parts: GSM-7 160/153 (extension chars count 2), otherwise UCS-2 70/67 (UTF-16 code units). */
	public static function segments( string $body ): int {
		if ( '' === $body ) {
			return 0;
		}
		$chars = preg_split( '//u', $body, -1, PREG_SPLIT_NO_EMPTY );
		if ( false === $chars ) {
			return (int) ceil( strlen( $body ) / self::UCS2_SINGLE );
		}

		$gsm_units = 0;
		foreach ( $chars as $char ) {
			if ( false !== strpos( self::GSM7_BASIC, $char ) ) {
				++$gsm_units;
			} elseif ( false !== strpos( self::GSM7_EXTENDED, $char ) ) {
				$gsm_units += 2;
			} else {
				$gsm_units = -1;
				break;
			}
		}
		if ( $gsm_units >= 0 ) {
			return $gsm_units <= self::GSM7_SINGLE ? 1 : (int) ceil( $gsm_units / self::GSM7_MULTI );
		}

		$ucs_units = 0;
		foreach ( $chars as $char ) {
			$ucs_units += strlen( $char ) >= 4 ? 2 : 1;
		}
		return $ucs_units <= self::UCS2_SINGLE ? 1 : (int) ceil( $ucs_units / self::UCS2_MULTI );
	}

	private static function schedule( int $log_id, int $delay ): void {
		$args = array( $log_id );
		if ( function_exists( 'as_enqueue_async_action' ) && function_exists( 'as_schedule_single_action' ) ) {
			if ( $delay <= 0 ) {
				as_enqueue_async_action( self::HOOK, $args, self::GROUP );
			} else {
				as_schedule_single_action( time() + $delay, self::HOOK, $args, self::GROUP );
			}
			return;
		}
		wp_schedule_single_event( time() + $delay, self::HOOK, $args );
	}

	/** @return array<string,mixed>|null */
	private static function row( int $log_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}cc_sms_log WHERE id = %d", $log_id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	private static function resolve_body( array $row ): ?string {
		if ( in_array( $row['template'], self::SECRET_TEMPLATES, true ) ) {
			$vars = self::secret_vars( $row );
			return null === $vars ? null : self::render( $row['template'], $vars );
		}
		try {
			return CC_Crypto::decrypt( (string) $row['body_enc'] );
		} catch ( RuntimeException $e ) {
			return null;
		}
	}

	/** @return array<string,mixed>|null Null when the payload is missing, expired, or cannot be decrypted. */
	private static function secret_vars( array $row ): ?array {
		$blob = get_transient( self::PAYLOAD_PREFIX . $row['id'] );
		if ( ! is_string( $blob ) ) {
			return null;
		}
		try {
			$vars = json_decode( CC_Crypto::decrypt( $blob ), true );
		} catch ( RuntimeException $e ) {
			return null;
		}
		return is_array( $vars ) ? $vars : null;
	}

	/** @return array{0:bool,1:string,2:string} */
	private static function attempt( CC_Sms_Driver $driver, array $row, string $body ): array {
		try {
			return $driver->send( $row['to_phone'], $body );
		} catch ( Throwable $e ) {
			return array( false, '', 'Driver exception: ' . get_class( $e ) );
		}
	}

	/** Strips the secret variable values from driver error text before it is stored or logged. */
	private static function redact( string $error, array $row ): string {
		if ( in_array( $row['template'], self::SECRET_TEMPLATES, true ) ) {
			foreach ( self::secret_vars( $row ) ?? array() as $value ) {
				if ( is_scalar( $value ) && '' !== (string) $value ) {
					$error = str_replace( (string) $value, '***', $error );
				}
			}
		}
		return substr( $error, 0, self::ERROR_MAX_LENGTH );
	}

	private static function record_attempt( int $log_id, int $attempts, string $error ): void {
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'cc_sms_log', array( 'attempts' => $attempts, 'error' => $error ), array( 'id' => $log_id ) );
	}

	private static function mark_sent( int $log_id, string $provider, int $attempts ): void {
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'cc_sms_log',
			array(
				'status'   => 'sent',
				'provider' => $provider,
				'attempts' => $attempts,
				'error'    => null,
				'sent_at'  => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'id' => $log_id )
		);
		delete_transient( self::PAYLOAD_PREFIX . $log_id );
	}

	private static function mark_failed( int $log_id, string $error ): void {
		global $wpdb;
		$error = substr( $error, 0, self::ERROR_MAX_LENGTH );
		$wpdb->update( $wpdb->prefix . 'cc_sms_log', array( 'status' => 'failed', 'error' => $error ), array( 'id' => $log_id ) );
		delete_transient( self::PAYLOAD_PREFIX . $log_id );
		$row = self::row( $log_id );
		error_log( sprintf( '[cc-sms] #%d to %s (%s) failed: %s', $log_id, CC_Phone::mask( (string) ( $row['to_phone'] ?? '' ) ), $row['template'] ?? '?', $error ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}
