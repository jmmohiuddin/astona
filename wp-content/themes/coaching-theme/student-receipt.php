<?php
/**
 * Printable receipt (/student/payments/receipt/?id=). Owner-only; anything else gets the same neutral notice.
 */
$user = CC_Student_Guard::require_student();
CC_Student_Guard::send_no_store();
$id_raw  = isset( $_GET['id'] ) ? sanitize_text_field( wp_unslash( $_GET['id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- read-only, ownership enforced in the query.
$receipt = ctype_digit( $id_raw ) ? CC_Portal_Data::receipt( $user->ID, (int) $id_raw ) : null;
// ?format=pdf streams a PDF when mPDF is installed; otherwise it falls through to the HTML receipt below.
$want_pdf = isset( $_GET['format'] ) && 'pdf' === $_GET['format']; // phpcs:ignore WordPress.Security.NonceVerification -- read-only.
if ( $want_pdf && null !== $receipt && class_exists( 'CC_Receipt_Pdf' ) && CC_Receipt_Pdf::available() ) {
	try {
		$pdf = CC_Receipt_Pdf::render( $receipt );
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="receipt-' . preg_replace( '/[^A-Za-z0-9._-]/', '-', (string) $receipt['invoice_number'] ) . '.pdf"' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Length: ' . strlen( $pdf ) );
		echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput -- binary PDF.
		exit;
	} catch ( RuntimeException $e ) {
		// Fall back to the HTML receipt.
	}
}
$pdf_url = ( null !== $receipt && class_exists( 'CC_Receipt_Pdf' ) && CC_Receipt_Pdf::available() ) ? add_query_arg( array( 'id' => (int) $receipt['payment_id'], 'format' => 'pdf' ), CC_Portal_Router::url( 'receipt' ) ) : '';
get_header();
?>
<section class="page-head portal-noprint"><div class="container"><h1>Receipt</h1></div></section>
<section class="section portal">
	<div class="container">
		<div class="portal-noprint"><?php get_template_part( 'template-parts/student-nav', null, array( 'current' => 'payments' ) ); ?></div>
		<?php if ( null === $receipt ) : ?>
			<p class="notice-box" role="alert">This receipt is not available. <a href="<?php echo esc_url( CC_Portal_Router::url( 'payments' ) ); ?>">Back to payments</a></p>
		<?php else : ?>
			<article class="receipt card card--pad" aria-labelledby="receipt-title">
				<h2 id="receipt-title">Payment receipt</h2>
				<p class="muted"><?php bloginfo( 'name' ); ?></p>
				<dl class="receipt__list">
					<dt>Invoice number</dt><dd><?php echo esc_html( (string) $receipt['invoice_number'] ); ?></dd>
					<dt>Student</dt><dd><?php echo esc_html( (string) $receipt['student_name'] ); ?></dd>
					<dt>Course</dt><dd><?php echo esc_html( (string) $receipt['course_title'] ); ?></dd>
					<dt>Batch</dt><dd><?php echo esc_html( (string) $receipt['batch_name'] ); ?></dd>
					<dt>Amount paid</dt><dd><?php echo esc_html( astona_money( $receipt['amount'] ) ); ?></dd>
					<?php if ( (float) $receipt['invoice_total'] > (float) $receipt['amount'] + 0.004 ) : ?>
						<dt>Payment type</dt><dd><?php echo esc_html( 'balance' === $receipt['kind'] ? 'Balance payment' : 'First payment' ); ?> (fee paid in two parts)</dd>
						<dt>Course fee</dt><dd><?php echo esc_html( astona_money( $receipt['invoice_total'] ) ); ?></dd>
						<dt>Paid so far</dt><dd><?php echo esc_html( astona_money( $receipt['paid_to_date'] ) ); ?></dd>
						<dt>Still to pay</dt><dd><?php echo esc_html( astona_money( max( 0, (float) $receipt['invoice_total'] - (float) $receipt['paid_to_date'] ) ) ); ?></dd>
					<?php endif; ?>
					<dt>Transaction ID</dt><dd><?php echo esc_html( (string) ( $receipt['trx_id'] ?: '—' ) ); ?></dd>
					<dt>Paid at</dt><dd><?php echo esc_html( get_date_from_gmt( (string) $receipt['settled_at'], 'j M Y, g:i a' ) ); ?></dd>
				</dl>
			</article>
			<p class="portal-noprint"><button type="button" class="btn btn--primary" id="portal-print">Print receipt</button>
				<?php if ( '' !== $pdf_url ) : ?><a class="btn btn--ghost" href="<?php echo esc_url( $pdf_url ); ?>">Download PDF</a><?php endif; ?></p>
		<?php endif; ?>
	</div>
</section>
<?php get_footer(); ?>
