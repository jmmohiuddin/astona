<?php
defined( 'ABSPATH' ) || exit;

final class CC_Idempotency {

	const UUID_V4_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i';

	public static function valid_key( string $k ): bool {
		return 1 === preg_match( self::UUID_V4_PATTERN, $k );
	}

	/** Deterministic dedupe key; parts are length-prefixed so ("ab","c") differs from ("a","bc"). */
	public static function event_key( string ...$parts ): string {
		$material = '';
		foreach ( $parts as $part ) {
			$material .= strlen( $part ) . ':' . $part . '|';
		}
		return hash( 'sha256', $material );
	}
}
