<?php
/**
 * Student dashboard (/student/): enrolled batches, schedule and top notices.
 */
$user = CC_Student_Guard::require_student();
CC_Student_Guard::send_no_store();
$data     = CC_Portal_Data::dashboard( $user->ID );
$profile  = CC_Portal_Data::profile( $user->ID );
$schedule = $data['schedule'];

/** Live items get the Join row; lessons a plain line. @param array<string,mixed> $item */
function student_schedule_row( array $item ): void {
	if ( 'live' === $item['type'] ) {
		get_template_part( 'template-parts/student-live-row', null, array( 'item' => $item ) );
		return;
	}
	printf(
		'<li class="live-row"><div class="live-row__info"><strong>%s</strong> <span class="muted small">%s</span></div></li>',
		esc_html( $item['title'] ),
		esc_html( $item['when_text'] . ( '' !== $item['batch_label'] ? ' · ' . $item['batch_label'] : '' ) )
	);
}
get_header();
?>
<section class="page-head"><div class="container"><h1>Welcome<?php echo '' !== $profile['full_name'] ? ', ' . esc_html( $profile['full_name'] ) : ''; ?></h1></div></section>
<section class="section portal">
	<div class="container">
		<?php get_template_part( 'template-parts/student-nav', null, array( 'current' => 'dashboard' ) ); ?>
		<p class="portal-status" id="portal-status" role="status" aria-live="polite"></p>
		<?php foreach ( CC_Installments::outstanding( $user->ID ) as $due ) : ?>
			<p class="notice-box" role="<?php echo $due['overdue'] || $due['suspended'] ? 'alert' : 'status'; ?>">
				Balance due: <strong><?php echo esc_html( astona_money( $due['balance'] ) ); ?></strong> for <?php echo esc_html( (string) $due['batch_name'] ); ?><?php echo $due['suspended'] ? ' (access paused until it is paid)' : ( $due['overdue'] ? ' (overdue)' : '' ); ?>.
				<a href="<?php echo esc_url( home_url( '/student/payments/' ) ); ?>">Pay now</a>
			</p>
		<?php endforeach; ?>

		<h2>My courses</h2>
		<?php if ( empty( $data['enrollments'] ) ) : ?>
			<p class="notice-box">You are not enrolled in any course yet. <a href="<?php echo esc_url( get_post_type_archive_link( 'cc_course' ) ); ?>">Browse courses</a>.</p>
		<?php else : ?>
			<div class="portal-grid">
				<?php foreach ( $data['enrollments'] as $row ) : ?>
					<?php if ( $row['access_ended'] ) : ?>
					<article class="card card--pad portal-card">
						<h3><?php echo esc_html( (string) ( $row['course_title'] ?? '' ) ); ?></h3>
						<p class="muted"><?php echo esc_html( $row['message'] ); ?></p>
					</article>
						<?php continue; ?>
					<?php endif; ?>
					<article class="card card--pad portal-card">
						<h3><?php echo esc_html( (string) ( $row['course_title'] ?? '' ) ); ?></h3>
						<p><strong><?php echo esc_html( (string) ( $row['batch_name'] ?? '' ) ); ?></strong></p>
						<p class="muted small"><?php echo esc_html( ucfirst( (string) ( $row['delivery_mode'] ?? '' ) ) ); ?></p>
						<?php if ( ! empty( $row['schedule_text'] ) ) : ?>
							<p class="small"><?php echo esc_html( (string) $row['schedule_text'] ); ?></p>
						<?php endif; ?>
						<?php echo astona_chip( CC_Portal_Data::enrollment_chip( (string) ( $row['status'] ?? '' ) ), ucfirst( (string) ( $row['status'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in astona_chip. ?>
					</article>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<div class="portal-grid portal-grid--info">
			<section class="card card--pad" aria-labelledby="portal-schedule-h">
				<h2 id="portal-schedule-h"><?php echo esc_html( $schedule['title'] ); ?></h2>
				<?php if ( $schedule['today'] || $schedule['later'] ) : ?>
					<?php foreach ( array( 'today' => 'Today', 'later' => 'Coming up this week' ) as $key => $heading ) : ?>
						<?php if ( $schedule[ $key ] ) : ?>
							<h3 class="h4"><?php echo esc_html( $heading ); ?></h3>
							<ul class="live-list">
								<?php foreach ( $schedule[ $key ] as $item ) : ?>
									<?php student_schedule_row( $item ); ?>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
					<?php endforeach; ?>
				<?php else : ?>
					<p class="muted"><?php echo esc_html( $schedule['message'] ); ?></p>
					<?php if ( $schedule['next'] ) : ?>
						<ul class="live-list"><?php student_schedule_row( $schedule['next'] ); ?></ul>
					<?php endif; ?>
				<?php endif; ?>
			</section>
			<section class="card card--pad" aria-labelledby="portal-notices-h">
				<h2 id="portal-notices-h"><?php echo esc_html( $data['notices']['title'] ); ?></h2>
				<?php if ( empty( $data['notices']['items'] ) ) : ?>
					<p class="muted">No notices yet. New announcements for your courses will show up here.</p>
				<?php else : ?>
					<ul class="notice-feed notice-feed--compact">
						<?php foreach ( $data['notices']['items'] as $notice ) : ?>
							<li><a href="<?php echo esc_url( $notice['url'] ); ?>"><?php echo esc_html( $notice['title'] ); ?></a> <span class="muted small"><?php echo esc_html( $notice['date'] ); ?></span></li>
						<?php endforeach; ?>
					</ul>
					<p><a href="<?php echo esc_url( CC_Portal_Router::url( 'notices' ) ); ?>">All notices</a></p>
				<?php endif; ?>
			</section>
		</div>

		<h2>Quick links</h2>
		<ul class="portal-links">
			<li><a href="<?php echo esc_url( CC_Portal_Router::url( 'courses' ) ); ?>">My courses</a></li>
			<li><a href="<?php echo esc_url( CC_Portal_Router::url( 'payments' ) ); ?>">Payments and receipts</a></li>
			<li><a href="<?php echo esc_url( CC_Portal_Router::url( 'profile' ) ); ?>">My profile and password</a></li>
			<li><a href="<?php echo esc_url( get_post_type_archive_link( 'cc_notice' ) ); ?>">Public notices</a></li>
		</ul>
	</div>
</section>
<?php get_footer(); ?>
