<?php
defined( 'ABSPATH' ) || exit;

/** Streams CSV downloads that open correctly in Excel (UTF-8 BOM) and cannot carry spreadsheet formulas. */
final class CC_Csv {

	const FLUSH_EVERY = 500;
	const BOM         = "\xEF\xBB\xBF";

	const FORMULA_START = '/^[\s\x{3000}]*[=+\-@\x{FF1D}\x{FF0B}\x{FF0D}]/u';

	/**
	 * Prefixes cells a spreadsheet would execute as a formula: the first non-whitespace character (ASCII or U+3000
	 * whitespace) is = + - @ or a full-width = + -, or the cell starts with a tab or CR. Numbers pass through untouched.
	 * Text that is not valid UTF-8 is prefixed too, because the check cannot be trusted on it.
	 */
	public static function neutralise( $cell ): string {
		if ( null === $cell ) {
			return '';
		}
		if ( is_int( $cell ) || is_float( $cell ) ) {
			return (string) $cell;
		}
		$cell = (string) $cell;
		if ( '' === $cell ) {
			return '';
		}
		$match = preg_match( self::FORMULA_START, $cell ); // false on invalid UTF-8.
		return false !== strpos( "\t\r", $cell[0] ) || 0 !== $match ? "'" . $cell : $cell;
	}

	/**
	 * Phone numbers are exported as digits with the country code and no '+' (8801XXXXXXXXX), because a leading '+'
	 * would be neutralised into "'+880...". Spreadsheets may show such long numbers in scientific notation; the
	 * stored CSV text is correct.
	 */
	public static function phone( string $e164 ): string {
		return ltrim( $e164, '+' );
	}

	/** @param iterable<array<int,mixed>> $rows */
	public static function stream( string $filename, array $header, iterable $rows ): never {
		$safe_name = preg_replace( '/[^A-Za-z0-9._-]/', '-', $filename );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $safe_name . '"' );
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		header( 'X-Content-Type-Options: nosniff' );

		$out = fopen( 'php://output', 'w' );
		fwrite( $out, self::BOM );
		fputcsv( $out, array_map( array( __CLASS__, 'neutralise' ), $header ) );

		$count = 0;
		foreach ( $rows as $row ) {
			fputcsv( $out, array_map( array( __CLASS__, 'neutralise' ), $row ) );
			if ( 0 === ++$count % self::FLUSH_EVERY ) {
				fflush( $out );
			}
		}
		fclose( $out );
		exit;
	}
}
