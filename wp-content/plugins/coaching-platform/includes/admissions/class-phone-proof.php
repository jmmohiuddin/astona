<?php
defined( 'ABSPATH' ) || exit;

/**
 * Short-lived proof that the applicant controls a phone number. The token is stateless and signed:
 * base64url(json{p: phone, e: expiry, n: nonce}) . '.' . hmac-sha256(key, payload) with key = hmac-sha256(wp_salt('auth'), 'cc_phone_proof') (domain-separated; proofs
 * issued before this derivation simply expire, TTL 15 min).
 * Single use is enforced by claiming the nonce with one INSERT IGNORE into wp_options (the same pattern notices use),
 * so two parallel requests holding the same token cannot both claim it.
 */
final class CC_Phone_Proof {

	const TTL_SECONDS  = 900;
	const CLAIM_PREFIX = 'cc_pp_used_';
	const CLAIM_GRACE  = 3600;

	public static function issue( string $phone_e164 ): string {
		$payload = self::b64( (string) wp_json_encode( array( 'p' => $phone_e164, 'e' => time() + self::TTL_SECONDS, 'n' => bin2hex( random_bytes( 16 ) ) ) ) );
		return $payload . '.' . self::sign( $payload );
	}

	/** @return array{p:string,e:int,n:string}|null The payload when the token is genuine, unexpired, unused and bound to $phone_e164. */
	public static function verify( string $token, string $phone_e164 ): ?array {
		$parts = explode( '.', $token );
		if ( 2 !== count( $parts ) || ! hash_equals( self::sign( $parts[0] ), $parts[1] ) ) {
			return null;
		}
		$decoded = base64_decode( strtr( $parts[0], '-_', '+/' ), true );
		$data    = false === $decoded ? null : json_decode( $decoded, true );
		if ( ! is_array( $data ) || ! is_string( $data['p'] ?? null ) || ! is_int( $data['e'] ?? null ) || ! is_string( $data['n'] ?? null ) || 1 !== preg_match( '/^[0-9a-f]{32}$/', $data['n'] ) ) {
			return null;
		}
		if ( $data['e'] <= time() || ! hash_equals( $data['p'], $phone_e164 ) || self::is_claimed( $data['n'] ) ) {
			return null;
		}
		return array( 'p' => $data['p'], 'e' => $data['e'], 'n' => $data['n'] );
	}

	/** Marks the proof used. Exactly one caller gets true, however many race. */
	public static function claim( array $proof ): bool {
		global $wpdb;
		$claimed = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", self::CLAIM_PREFIX . $proof['n'], (string) $proof['e'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		return 1 === (int) $claimed;
	}

	/** Gives a claimed proof back when the application it was claimed for was not created. */
	public static function release( array $proof ): void {
		global $wpdb;
		$wpdb->delete( $wpdb->options, array( 'option_name' => self::CLAIM_PREFIX . $proof['n'] ) );
	}

	public static function cleanup(): int {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d",
				$wpdb->esc_like( self::CLAIM_PREFIX ) . '%',
				time() - self::CLAIM_GRACE
			)
		);
		return (int) $wpdb->rows_affected;
	}

	private static function is_claimed( string $nonce ): bool {
		global $wpdb;
		return null !== $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", self::CLAIM_PREFIX . $nonce ) );
	}

	private static function sign( string $payload ): string {
		return hash_hmac( 'sha256', $payload, hash_hmac( 'sha256', 'cc_phone_proof', wp_salt( 'auth' ) ) );
	}

	private static function b64( string $raw ): string {
		return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' );
	}
}
