<?php
defined( 'ABSPATH' ) || exit;

/**
 * PDF payment receipts with correct Bangla conjunct shaping (TRD FR-019: mPDF with OpenType layout).
 *
 * mPDF is an optional Composer dependency (`composer install --no-dev` in this plugin). When it is missing the portal
 * keeps offering the print-optimised HTML receipt, so nothing breaks without it.
 *
 * Bangla text is set in Noto Sans Bengali (bundled, SIL OFL) at normal weight only: the bold master is not embedded, so
 * labels are English (bold) and values, which may hold Bangla names, are normal weight.
 */
final class CC_Receipt_Pdf {

	const FONT_FILE = 'NotoSansBengali-Regular.ttf';

	public static function font_dir(): string {
		$configured = defined( 'CC_PDF_FONT_DIR' ) ? (string) CC_PDF_FONT_DIR : (string) getenv( 'CC_PDF_FONT_DIR' );
		return '' !== $configured ? rtrim( $configured, '/' ) : CC_PATH . 'assets/fonts';
	}

	public static function available(): bool {
		return class_exists( '\Mpdf\Mpdf' ) && is_readable( self::font_dir() . '/' . self::FONT_FILE );
	}

	/**
	 * @param array<string,mixed> $receipt Shape of CC_Portal_Data::receipt().
	 * @return string PDF bytes.
	 * @throws RuntimeException When mPDF is not installed or fails.
	 */
	public static function render( array $receipt, bool $compress = true ): string {
		if ( ! self::available() ) {
			throw new RuntimeException( 'PDF receipts need mPDF (composer install in the plugin) and the bundled font.' );
		}
		$temp = trailingslashit( get_temp_dir() ) . 'cc-mpdf';
		if ( ! wp_mkdir_p( $temp ) ) {
			throw new RuntimeException( 'No writable temp directory for PDF generation.' );
		}
		try {
			$defaults_dirs = ( new \Mpdf\Config\ConfigVariables() )->getDefaults();
			$defaults_font = ( new \Mpdf\Config\FontVariables() )->getDefaults();
			$mpdf          = new \Mpdf\Mpdf(
				array(
					'mode'          => 'utf-8',
					'format'        => 'A5',
					'tempDir'       => $temp,
					'fontDir'       => array_merge( $defaults_dirs['fontDir'], array( self::font_dir() ) ),
					'fontdata'      => $defaults_font['fontdata'] + array(
						'notobengali' => array( 'R' => self::FONT_FILE, 'B' => self::FONT_FILE, 'useOTL' => 0xFF, 'useKashida' => 75 ),
					),
					'default_font'  => 'dejavusans',
					'margin_left'   => 12,
					'margin_right'  => 12,
					'margin_top'    => 12,
					'margin_bottom' => 12,
				)
			);
			$mpdf->SetCompression( $compress );
			$mpdf->SetTitle( 'Payment receipt ' . (string) $receipt['invoice_number'] );
			$mpdf->SetCreator( (string) get_bloginfo( 'name' ) );
			$mpdf->WriteHTML( self::html( $receipt ) );
			return (string) $mpdf->Output( '', \Mpdf\Output\Destination::STRING_RETURN );
		} catch ( \Throwable $e ) {
			error_log( 'CC_Receipt_Pdf: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			throw new RuntimeException( 'The PDF could not be generated.' );
		}
	}

	/** Sets runs of Bangla text in the bundled font so the look does not depend on mPDF's fallback fonts. Input is already escaped HTML. */
	public static function wrap_bangla( string $escaped_html ): string {
		return (string) preg_replace( '/[\x{0980}-\x{09FF}](?:[\x{0980}-\x{09FF}\x{200C}\x{200D} .,\-()]*[\x{0980}-\x{09FF}])?/u', '<span style="font-family:notobengali">$0</span>', $escaped_html );
	}

	/** @param array<string,mixed> $r */
	public static function html( array $r ): string {
		$h     = static fn( $v ): string => esc_html( (string) $v );
		$money = static fn( $v ): string => 'BDT ' . number_format( (float) $v, 2 );
		$rows  = array(
			'Invoice number' => $h( $r['invoice_number'] ),
			'Student'        => $h( $r['student_name'] ),
			'Course'         => $h( $r['course_title'] ),
			'Batch'          => $h( $r['batch_name'] ),
			'Amount paid'    => $h( $money( $r['amount'] ) ),
		);
		if ( isset( $r['invoice_total'] ) && (float) $r['invoice_total'] > (float) $r['amount'] + 0.004 ) {
			$kind                  = 'balance' === ( $r['kind'] ?? '' ) ? 'Balance payment' : 'First payment';
			$rows['Payment type']  = $h( $kind . ' (fee paid in two parts)' );
			$rows['Course fee']    = $h( $money( $r['invoice_total'] ) );
			$rows['Paid so far']   = $h( $money( $r['paid_to_date'] ?? $r['amount'] ) );
			$rows['Still to pay']  = $h( $money( max( 0, (float) $r['invoice_total'] - (float) ( $r['paid_to_date'] ?? $r['amount'] ) ) ) );
		}
		$rows['Transaction ID'] = $h( '' !== (string) $r['trx_id'] ? $r['trx_id'] : '-' );
		$rows['Paid at']        = $h( ! empty( $r['settled_at'] ) ? get_date_from_gmt( (string) $r['settled_at'], 'j M Y, g:i a' ) : '-' );
		$rows['Payment method'] = $h( ucfirst( (string) ( $r['method'] ?? '' ) ) );

		$body = '';
		foreach ( $rows as $label => $value ) {
			$body .= '<tr><th>' . esc_html( $label ) . '</th><td>' . self::wrap_bangla( $value ) . '</td></tr>';
		}
		return '<style>
			body { font-family: dejavusans; font-size: 10pt; color: #1b1f24; }
			h1 { font-size: 16pt; margin: 0 0 2mm; }
			.org { color: #555; margin: 0 0 6mm; }
			table { width: 100%; border-collapse: collapse; }
			th { text-align: left; width: 38%; padding: 2mm 2mm 2mm 0; border-bottom: 0.2mm solid #ddd; font-weight: bold; vertical-align: top; }
			td { padding: 2mm 0; border-bottom: 0.2mm solid #ddd; font-weight: normal; }
			.foot { margin-top: 8mm; color: #666; font-size: 8pt; }
		</style>
		<h1>Payment receipt</h1>
		<p class="org">' . esc_html( (string) get_bloginfo( 'name' ) ) . '</p>
		<table>' . $body . '</table>
		<p class="foot">This receipt was generated automatically and is valid without a signature.</p>';
	}
}
