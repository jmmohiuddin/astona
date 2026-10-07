<?php
defined( 'ABSPATH' ) || exit;

/**
 * RFC 6238 time-based one-time passwords (HMAC-SHA1, 6 digits, 30 s step), the format every authenticator app reads.
 * Pure functions with no WordPress dependency so the algorithm is unit-tested against the RFC vectors.
 */
final class CC_Totp {

	const STEP    = 30;
	const DIGITS  = 6;
	const WINDOW  = 1; // Accept the previous and next step to absorb clock drift.
	const ALPHA   = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

	/** @return string 160-bit random secret, base32 (32 characters). */
	public static function generate_secret(): string {
		return self::base32_encode( random_bytes( 20 ) );
	}

	public static function code( string $secret_b32, int $step, int $digits = self::DIGITS ): string {
		$key = self::base32_decode( $secret_b32 );
		$mac = hash_hmac( 'sha1', pack( 'J', $step ), $key, true );
		$off = ord( $mac[19] ) & 0x0f;
		$bin = ( ( ord( $mac[ $off ] ) & 0x7f ) << 24 ) | ( ord( $mac[ $off + 1 ] ) << 16 ) | ( ord( $mac[ $off + 2 ] ) << 8 ) | ord( $mac[ $off + 3 ] );
		return str_pad( (string) ( $bin % ( 10 ** $digits ) ), $digits, '0', STR_PAD_LEFT );
	}

	public static function step_at( int $time ): int {
		return intdiv( $time, self::STEP );
	}

	/**
	 * @param int $after_step Steps at or below this were already used; refusing them stops replay of an observed code.
	 * @return int|null The matched time step, or null when the code is wrong or replayed.
	 */
	public static function verify( string $secret_b32, string $code, int $time, int $after_step = 0 ): ?int {
		$code = preg_replace( '/\s+/', '', $code );
		if ( 1 !== preg_match( '/^\d{' . self::DIGITS . '}$/', (string) $code ) ) {
			return null;
		}
		$now   = self::step_at( $time );
		$match = null;
		for ( $s = $now - self::WINDOW; $s <= $now + self::WINDOW; $s++ ) {
			// Every candidate is compared (no early exit) so timing does not reveal which step matched.
			if ( hash_equals( self::code( $secret_b32, $s ), $code ) && $s > $after_step ) {
				$match = $s;
			}
		}
		return $match;
	}

	/** otpauth:// URI that authenticator apps import (by link or QR). */
	public static function uri( string $secret_b32, string $account, string $issuer ): string {
		return 'otpauth://totp/' . rawurlencode( $issuer . ':' . $account ) . '?secret=' . $secret_b32 . '&issuer=' . rawurlencode( $issuer ) . '&algorithm=SHA1&digits=' . self::DIGITS . '&period=' . self::STEP;
	}

	public static function base32_encode( string $bytes ): string {
		$bits = '';
		foreach ( str_split( $bytes ) as $c ) {
			$bits .= str_pad( decbin( ord( $c ) ), 8, '0', STR_PAD_LEFT );
		}
		$out = '';
		foreach ( str_split( $bits, 5 ) as $chunk ) {
			$out .= self::ALPHA[ bindec( str_pad( $chunk, 5, '0' ) ) ];
		}
		return $out;
	}

	public static function base32_decode( string $b32 ): string {
		$bits = '';
		foreach ( str_split( strtoupper( preg_replace( '/[\s=-]/', '', $b32 ) ) ) as $c ) {
			$pos = strpos( self::ALPHA, $c );
			if ( false === $pos ) {
				continue;
			}
			$bits .= str_pad( decbin( $pos ), 5, '0', STR_PAD_LEFT );
		}
		$out = '';
		foreach ( str_split( $bits, 8 ) as $byte ) {
			if ( 8 === strlen( $byte ) ) {
				$out .= chr( bindec( $byte ) );
			}
		}
		return $out;
	}
}
