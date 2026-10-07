<?php
defined( 'ABSPATH' ) || exit;

/**
 * Fixed-window atomic counters. With an external object cache they use wp_cache_add + wp_cache_incr; otherwise a
 * single INSERT ... ON DUPLICATE KEY UPDATE on wp_options, so concurrent requests cannot lose increments.
 * DB rows hold "reset_at|count" under the cc_rl_ prefix and are removed by cleanup().
 */
final class CC_Rate_Limiter {

	const CACHE_GROUP   = 'cc_rate_limit';
	const OPTION_PREFIX = 'cc_rl_';

	/** Counts the attempt and reports whether it is within $limit for the window. */
	public static function allow( string $bucket, int $limit, int $window_seconds ): bool {
		if ( self::disabled() ) {
			return true;
		}
		return self::hit( $bucket, $window_seconds ) <= $limit;
	}

	/** Atomically increments the bucket (starting a window if none is live) and returns the new count. */
	public static function hit( string $bucket, int $window_seconds ): int {
		if ( self::disabled() ) {
			return 0;
		}
		$key = md5( $bucket );
		return wp_using_ext_object_cache() ? self::hit_cache( $key, $window_seconds ) : self::hit_db( $key, $window_seconds );
	}

	/** Current count without consuming anything. */
	public static function count( string $bucket ): int {
		if ( self::disabled() ) {
			return 0;
		}
		$key = md5( $bucket );
		if ( wp_using_ext_object_cache() ) {
			return (int) wp_cache_get( $key, self::CACHE_GROUP );
		}
		global $wpdb;
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::OPTION_PREFIX . $key ) );
		if ( ! is_string( $value ) ) {
			return 0;
		}
		$parts = array_map( 'intval', explode( '|', $value ) + array( 0, 0 ) );
		return $parts[0] > time() ? $parts[1] : 0;
	}

	public static function reset( string $bucket ): void {
		$key = md5( $bucket );
		if ( wp_using_ext_object_cache() ) {
			wp_cache_delete( $key, self::CACHE_GROUP );
			return;
		}
		global $wpdb;
		$wpdb->delete( $wpdb->options, array( 'option_name' => self::OPTION_PREFIX . $key ) );
	}

	public static function cleanup(): int {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(SUBSTRING_INDEX(option_value, '|', 1) AS UNSIGNED) < %d",
				$wpdb->esc_like( self::OPTION_PREFIX ) . '%',
				time()
			)
		);
		return (int) $wpdb->rows_affected;
	}

	/** REMOTE_ADDR only: forwarded headers are client-controlled and would let callers dodge limits. */
	public static function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
		return false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '0.0.0.0';
	}

	/**
	 * Bucket key for per-IP limits: IPv4 as is, IPv6 reduced to its /64 (one subscriber holds a whole /64, so per-address
	 * keys would let a single client rotate through 2^64 buckets). REMOTE_ADDR only. Behind a reverse proxy REMOTE_ADDR is the
	 * proxy: restore the real client address at the proxy/Apache layer (mod_remoteip) before relying on per-IP limits.
	 */
	public static function ip_bucket_key( ?string $ip = null ): string {
		$ip    = $ip ?? self::client_ip();
		$bytes = false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ? false : inet_pton( $ip );
		if ( false === $bytes ) {
			return $ip;
		}
		if ( 0 === strncmp( $bytes, str_repeat( "\0", 10 ) . "\xff\xff", 12 ) ) {
			return (string) inet_ntop( substr( $bytes, 12 ) ); // IPv4-mapped: a /64 of it would pool every IPv4 client together.
		}
		return bin2hex( substr( $bytes, 0, 8 ) ) . '::/64';
	}

	/** Seconds until the bucket's window ends (the full $window_seconds when that cannot be read, as with an object cache). */
	public static function seconds_left( string $bucket, int $window_seconds ): int {
		if ( wp_using_ext_object_cache() ) {
			return $window_seconds;
		}
		global $wpdb;
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::OPTION_PREFIX . md5( $bucket ) ) );
		return is_string( $value ) ? max( 1, (int) explode( '|', $value )[0] - time() ) : 1;
	}

	// Dev/test convenience only: never honoured outside a local environment.
	private static function disabled(): bool {
		return '1' === getenv( 'CC_RATE_LIMIT_DISABLED' ) && 'local' === wp_get_environment_type();
	}

	private static function hit_cache( string $key, int $window_seconds ): int {
		wp_cache_add( $key, 0, self::CACHE_GROUP, $window_seconds );
		$count = wp_cache_incr( $key, 1, self::CACHE_GROUP );
		return false === $count ? 1 : (int) $count;
	}

	private static function hit_db( string $key, int $window_seconds ): int {
		global $wpdb;
		$name = self::OPTION_PREFIX . $key;
		$now  = time();
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')
				ON DUPLICATE KEY UPDATE option_value = IF(
					CAST(SUBSTRING_INDEX(option_value, '|', 1) AS UNSIGNED) <= %d,
					VALUES(option_value),
					CONCAT(SUBSTRING_INDEX(option_value, '|', 1), '|', CAST(SUBSTRING_INDEX(option_value, '|', -1) AS UNSIGNED) + 1)
				)",
				$name,
				( $now + $window_seconds ) . '|1',
				$now
			)
		);
		$value = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
		return (int) substr( (string) strrchr( '|' . $value, '|' ), 1 );
	}
}
