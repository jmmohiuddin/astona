<?php
/**
 * Printable receipt (/student/payments/receipt/?id=). Owner-only; anything else gets the same neutral notice.
 */
$user = CC_Student_Guard::require_student();
CC_Student_Guard::send_no_store();
$id_raw  = isset( $_GET['id'] ) ? sanitize_text_field( wp_unslash( $_GET['id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- read-only, ownership enforced in the query.
$receipt = ctype_digit( $id_raw ) ? CC_Portal_Data::receipt( $user->ID, (int) $id_raw ) : null;
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
					<dt>Transaction ID</dt><dd><?php echo esc_html( (string) ( $receipt['trx_id'] ?: '—' ) ); ?></dd>
					<dt>Paid at</dt><dd><?php echo esc_html( get_date_from_gmt( (string) $receipt['settled_at'], 'j M Y, g:i a' ) ); ?></dd>
				</dl>
			</article>
			<p class="portal-noprint"><button type="button" class="btn btn--primary" id="portal-print">Print receipt</button></p>
		<?php endif; ?>
	</div>
</section>
<?php get_footer(); ?>
