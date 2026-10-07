<?php
defined( 'ABSPATH' ) || exit;

/**
 * Bangladesh mobile number handling. Canonical form is E.164: +8801XXXXXXXXX (operator digit 3-9).
 */
final class CC_Phone {

	const PATTERN = '/^(?:\+?88)?(01[3-9]\d{8})$/';

	public static function normalize( string $raw ): ?string {
		$compact = preg_replace( '/[\s\-().]+/', '', $raw );
		if ( ! is_string( $compact ) || ! preg_match( self::PATTERN, $compact, $m ) ) {
			return null;
		}
		return '+88' . $m[1];
	}

	/** +8801712345678 -> +88017*****678 */
	public static function mask( string $e164 ): string {
		if ( strlen( $e164 ) < 9 ) {
			return str_repeat( '*', strlen( $e164 ) );
		}
		return substr( $e164, 0, 6 ) . str_repeat( '*', strlen( $e164 ) - 9 ) . substr( $e164, -3 );
	}
}
