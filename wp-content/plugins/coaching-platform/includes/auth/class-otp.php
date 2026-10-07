<?php
defined( 'ABSPATH' ) || exit;

/**
 * One-time codes for passwordless login. Codes are never stored: only hmac-sha256(key, phone . code) where key is
 * hmac-sha256(wp_salt('auth'), 'cc_otp') (domain-separated; codes issued before this derivation simply expire, TTL 5 min).
 * Lock state lives in cc_otp itself: the fifth wrong attempt pushes the row's expires_at to now + 15 min, and
 * request() refuses while such a row exists, so a new code cannot be used to reset the attempt counter.
 * On top of that, wrong guesses are counted per phone across all codes: DAILY_FAIL_LIMIT failures within
 * DAILY_FAIL_WINDOW lock OTP request and verify for that phone for DAILY_LOCK_SECONDS. Failures are counted, not requests.
 * The 'apply' purpose (phone proof on the public admission form) has its own failure counters, so a stranger guessing at
 * the admission form cannot lock a student out of OTP login. Its wrong guesses are counted per IP bucket (per hour, across
 * phones: IP_FAIL_LIMIT failures block that IP only), per (phone, IP) and per phone. The 24 hour phone lock needs the
 * per-phone failures to come from at least DAILY_LOCK_MIN_IPS distinct IP buckets, so one client cannot lock a victim out
 * for a day; the per-code MAX_ATTEMPTS lock stays the phone-wide guess control.
 */
final class CC_Otp {

	const TTL_SECONDS        = 300;
	const MAX_ATTEMPTS       = 5;
	const LOCK_SECONDS       = 900;
	const DAILY_FAIL_LIMIT   = 10;
	const DAILY_FAIL_WINDOW  = 86400;
	const DAILY_LOCK_SECONDS = 86400;
	const DAILY_LOCK_MIN_IPS = 3;
	const IP_FAIL_LIMIT      = 10;
	const IP_FAIL_WINDOW     = 3600;
	const CLEANUP_HOOK       = 'cc_otp_cleanup';
	const CLEANUP_GRACE      = 3600;
	const PURPOSES           = array( 'login', 'phone_change', 'reset', 'apply' );
	const SMS_TEMPLATES      = array( 'apply' => 'apply_otp' ); // Every other purpose uses the 'otp' (login code) text.

	public static function hash( string $phone_e164, string $code ): string {
		return hash_hmac( 'sha256', $phone_e164 . $code, hash_hmac( 'sha256', 'cc_otp', wp_salt( 'auth' ) ) );
	}

	public static function schedule_cleanup(): void {
		add_action( self::CLEANUP_HOOK, array( __CLASS__, 'cleanup' ) );
		if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CLEANUP_HOOK );
		}
	}

	public static function cleanup(): int {
		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::CLEANUP_GRACE );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}cc_otp WHERE expires_at < %s", $cutoff ) );
		$deleted = (int) $wpdb->rows_affected;
		CC_Rate_Limiter::cleanup();
		CC_Phone_Proof::cleanup();
		return $deleted;
	}

	/** Issues and queues a new code. Returns false when the phone is locked or the purpose is unknown. */
	public static function request( string $phone_e164, string $purpose = 'login' ): bool {
		global $wpdb;
		if ( ! in_array( $purpose, self::PURPOSES, true ) || self::is_locked( $phone_e164, $purpose ) || self::is_daily_locked( $phone_e164, $purpose ) ) {
			return false;
		}
		$table = $wpdb->prefix . 'cc_otp';
		$now   = gmdate( 'Y-m-d H:i:s' );

		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET used_at = %s WHERE phone = %s AND purpose = %s AND used_at IS NULL", $now, $phone_e164, $purpose ) );

		$code     = str_pad( (string) random_int( 0, 999999 ), 6, '0', STR_PAD_LEFT );
		$inserted = $wpdb->insert(
			$table,
			array(
				'phone'      => $phone_e164,
				'code_hash'  => self::hash( $phone_e164, $code ),
				'purpose'    => $purpose,
				'attempts'   => 0,
				'expires_at' => gmdate( 'Y-m-d H:i:s', time() + self::TTL_SECONDS ),
				'created_at' => $now,
			)
		);
		if ( ! $inserted ) {
			error_log( 'CC_Otp::request: insert failed' );
			return false;
		}
		CC_Sms::queue( $phone_e164, self::SMS_TEMPLATES[ $purpose ] ?? 'otp', array( 'code' => $code ), 'otp', (int) $wpdb->insert_id );
		return true;
	}

	/** $ip_bucket is CC_Rate_Limiter::ip_bucket_key() of the caller; only the 'apply' purpose uses it. */
	public static function verify( string $phone_e164, string $code, string $purpose = 'login', string $ip_bucket = '' ): bool {
		global $wpdb;
		if ( self::is_daily_locked( $phone_e164, $purpose ) ) {
			return false;
		}
		$table = $wpdb->prefix . 'cc_otp';
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, code_hash FROM {$table} WHERE phone = %s AND purpose = %s AND used_at IS NULL AND expires_at > %s ORDER BY id DESC LIMIT 1",
				$phone_e164,
				$purpose,
				gmdate( 'Y-m-d H:i:s' )
			),
			ARRAY_A
		);
		if ( ! $row ) {
			return false;
		}

		// Claim an attempt before comparing so parallel guesses cannot exceed MAX_ATTEMPTS.
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET attempts = attempts + 1 WHERE id = %d AND used_at IS NULL AND attempts < %d", (int) $row['id'], self::MAX_ATTEMPTS ) );
		if ( 1 !== (int) $wpdb->rows_affected ) {
			return false;
		}

		if ( ! hash_equals( (string) $row['code_hash'], self::hash( $phone_e164, $code ) ) ) {
			self::record_failure( (int) $row['id'], $phone_e164, $purpose, $ip_bucket );
			return false;
		}

		// The WHERE clause makes the code single-use even when two requests race.
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET used_at = %s WHERE id = %d AND used_at IS NULL", gmdate( 'Y-m-d H:i:s' ), (int) $row['id'] ) );
		return 1 === (int) $wpdb->rows_affected;
	}

	public static function is_locked( string $phone_e164, string $purpose = 'login' ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT 1 FROM {$wpdb->prefix}cc_otp WHERE phone = %s AND purpose = %s AND used_at IS NULL AND attempts >= %d AND expires_at > %s LIMIT 1",
				$phone_e164,
				$purpose,
				self::MAX_ATTEMPTS,
				gmdate( 'Y-m-d H:i:s' )
			)
		);
	}

	public static function is_daily_locked( string $phone_e164, string $purpose = 'login' ): bool {
		return false !== get_transient( self::daily_lock_key( $phone_e164, $purpose ) );
	}

	/** True once this IP bucket has made IP_FAIL_LIMIT wrong 'apply' guesses within IP_FAIL_WINDOW (any phone). */
	public static function is_ip_blocked( string $ip_bucket ): bool {
		return CC_Rate_Limiter::count( 'otp_fail_apply_ip|' . $ip_bucket ) >= self::IP_FAIL_LIMIT;
	}

	private static function daily_lock_key( string $phone_e164, string $purpose ): string {
		return ( 'apply' === $purpose ? 'cc_otp_lock_apply_' : 'cc_otp_lock_' ) . md5( $phone_e164 );
	}

	private static function record_failure( int $id, string $phone_e164, string $purpose, string $ip_bucket ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'cc_otp';
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET expires_at = %s WHERE id = %d AND used_at IS NULL AND attempts >= %d",
				gmdate( 'Y-m-d H:i:s', time() + self::LOCK_SECONDS ),
				$id,
				self::MAX_ATTEMPTS
			)
		);
		if ( 'apply' === $purpose ) {
			self::record_apply_failure( $phone_e164, $ip_bucket );
			return;
		}
		if ( CC_Rate_Limiter::hit( 'otp_fail|' . $phone_e164, self::DAILY_FAIL_WINDOW ) >= self::DAILY_FAIL_LIMIT ) {
			set_transient( self::daily_lock_key( $phone_e164, $purpose ), 1, self::DAILY_LOCK_SECONDS );
		}
	}

	private static function record_apply_failure( string $phone_e164, string $ip_bucket ): void {
		CC_Rate_Limiter::hit( 'otp_fail_apply_ip|' . $ip_bucket, self::IP_FAIL_WINDOW );
		$phone_failures = CC_Rate_Limiter::hit( 'otp_fail_apply|' . $phone_e164, self::DAILY_FAIL_WINDOW );
		// The first failure of a (phone, IP) pair is a newly seen IP bucket for that phone.
		if ( 1 === CC_Rate_Limiter::hit( 'otp_fail_apply_pair|' . $phone_e164 . '|' . $ip_bucket, self::DAILY_FAIL_WINDOW ) ) {
			CC_Rate_Limiter::hit( 'otp_fail_apply_ips|' . $phone_e164, self::DAILY_FAIL_WINDOW );
		}
		if ( $phone_failures >= self::DAILY_FAIL_LIMIT && CC_Rate_Limiter::count( 'otp_fail_apply_ips|' . $phone_e164 ) >= self::DAILY_LOCK_MIN_IPS ) {
			set_transient( self::daily_lock_key( $phone_e164, 'apply' ), 1, self::DAILY_LOCK_SECONDS );
		}
	}
}
