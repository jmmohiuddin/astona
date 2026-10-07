<?php
/**
 * Student payments (/student/payments/): the student's own payments.
 */
$user = CC_Student_Guard::require_student();
CC_Student_Guard::send_no_store();
$payments = CC_Portal_Data::payments( $user->ID );
$balances = CC_Installments::outstanding( $user->ID );
$paid_flag = isset( $_GET['paid'] ) ? sanitize_key( wp_unslash( $_GET['paid'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- display only.
get_header();
?>
<section class="page-head"><div class="container"><h1>Payments</h1></div></section>
<section class="section portal">
	<div class="container">
		<?php get_template_part( 'template-parts/student-nav', null, array( 'current' => 'payments' ) ); ?>
		<p class="portal-status" id="portal-status" role="status" aria-live="polite"></p>
		<?php if ( '1' === $paid_flag ) : ?>
			<p class="notice-box" role="status">Thank you. Your payment was received.</p>
		<?php elseif ( '0' === $paid_flag ) : ?>
			<p class="notice-box" role="alert">We could not confirm that payment yet. If money left your account it will show here shortly; otherwise try again.</p>
		<?php endif; ?>
		<?php foreach ( $balances as $due ) : ?>
			<section class="card card--pad balance-card<?php echo $due['overdue'] ? ' balance-card--overdue' : ''; ?>" aria-label="Balance due">
				<h2>Balance due: <?php echo esc_html( astona_money( $due['balance'] ) ); ?></h2>
				<p><?php echo esc_html( (string) $due['batch_name'] ); ?> · invoice <?php echo esc_html( (string) $due['number'] ); ?><?php if ( ! empty( $due['due_at'] ) ) : ?> · due <?php echo esc_html( mysql2date( 'j M Y', (string) $due['due_at'] ) ); ?><?php endif; ?></p>
				<?php if ( $due['suspended'] ) : ?>
					<p class="notice-box" role="alert">Your access is paused until this balance is paid.</p>
				<?php elseif ( $due['overdue'] ) : ?>
					<p class="notice-box" role="alert">This balance is overdue. Please pay now.</p>
				<?php endif; ?>
				<button type="button" class="btn btn--primary" data-pay-balance="<?php echo esc_attr( (string) (int) $due['invoice_id'] ); ?>">Pay balance</button>
			</section>
		<?php endforeach; ?>
		<?php if ( empty( $payments ) ) : ?>
			<p class="notice-box">No payments found yet.</p>
		<?php else : ?>
			<div class="portal-table-wrap">
				<table class="portal-table">
					<caption class="sr-only">Your payments</caption>
					<thead><tr><th scope="col">Date</th><th scope="col">Invoice</th><th scope="col">Amount</th><th scope="col">Status</th><th scope="col">Transaction ID</th><th scope="col"><span class="sr-only">Receipt</span></th></tr></thead>
					<tbody>
					<?php foreach ( $payments as $pay ) : ?>
						<tr>
							<td data-label="Date"><?php echo esc_html( mysql2date( 'j M Y', (string) ( $pay['settled_at'] ?: $pay['created_at'] ) ) ); ?></td>
							<td data-label="Invoice"><?php echo esc_html( (string) $pay['invoice_number'] ); ?></td>
							<td data-label="Amount"><?php echo esc_html( astona_money( $pay['amount'] ) ); ?></td>
							<td data-label="Status"><?php echo esc_html( ucfirst( str_replace( '_', ' ', (string) $pay['status'] ) ) ); ?></td>
							<td data-label="Transaction ID"><?php echo esc_html( (string) ( $pay['trx_id'] ?: '—' ) ); ?></td>
							<td data-label="Receipt">
								<?php if ( 'completed' === $pay['status'] ) : ?>
									<a href="<?php echo esc_url( CC_Portal_Router::url( 'receipt', array( 'id' => (int) $pay['payment_id'] ) ) ); ?>">View receipt</a>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>
	</div>
</section>
<?php get_footer(); ?>
