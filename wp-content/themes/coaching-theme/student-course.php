<?php
/**
 * Course page (/student/courses/{batch_id}/): modules, lessons, PDF notes and live classes.
 * Only the student's own ACTIVE enrollment is shown; anything else gets the same friendly view.
 */
$user = CC_Student_Guard::require_student();
CC_Student_Guard::send_no_store();
$course = CC_Portal_Courses::course_view( $user->ID, CC_Portal_Router::current_id() );
get_header();
?>
<section class="page-head"><div class="container"><h1><?php echo $course ? esc_html( $course['batch_label'] ) : 'Course'; ?></h1></div></section>
<section class="section portal">
	<div class="container">
		<?php get_template_part( 'template-parts/student-nav', null, array( 'current' => 'courses' ) ); ?>
		<p class="portal-status" id="portal-status" role="status" aria-live="polite"></p>
		<?php if ( null === $course ) : ?>
			<p class="notice-box">This course is not available to you. <a href="<?php echo esc_url( CC_Portal_Router::url( 'courses' ) ); ?>">Back to my courses</a>.</p>
		<?php else : ?>
			<h2>Live classes</h2>
			<?php
			$upcoming = array_values( array_filter( $course['live'], static fn( array $l ): bool => 'ended' !== $l['state'] ) );
			?>
			<?php if ( empty( $course['live'] ) ) : ?>
				<p class="muted">No live classes are scheduled yet.</p>
			<?php else : ?>
				<ul class="live-list">
					<?php foreach ( $course['live'] as $live ) : ?>
						<?php
						$next = '';
						foreach ( $upcoming as $candidate ) {
							if ( $candidate['start_ts'] > $live['start_ts'] ) {
								$next = 'Next session: ' . $candidate['title'] . ', ' . $candidate['when_text'];
								break;
							}
						}
						get_template_part( 'template-parts/student-live-row', null, array( 'item' => $live, 'next_text' => $next ) );
						?>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<h2>Lessons</h2>
			<?php if ( empty( $course['modules'] ) ) : ?>
				<p class="muted">Lessons for this course will appear here once they are published.</p>
			<?php endif; ?>
			<?php foreach ( $course['modules'] as $module ) : ?>
				<section class="card card--pad course-module" aria-labelledby="module-<?php echo esc_attr( (string) $module['id'] ); ?>">
					<h3 id="module-<?php echo esc_attr( (string) $module['id'] ); ?>"><?php echo esc_html( $module['title'] ); ?></h3>
					<?php if ( empty( $module['lessons'] ) ) : ?>
						<p class="muted small">No lessons in this module yet.</p>
					<?php else : ?>
						<ul class="lesson-list">
							<?php foreach ( $module['lessons'] as $lesson ) : ?>
								<li>
									<span class="lesson-list__title"><?php echo esc_html( $lesson['title'] ); ?></span>
									<?php if ( '' !== $lesson['when_text'] ) : ?>
										<span class="muted small"><?php echo esc_html( $lesson['when_text'] ); ?></span>
									<?php endif; ?>
									<?php if ( '' !== $lesson['download_url'] ) : ?>
										<a href="<?php echo esc_url( $lesson['download_url'] ); ?>" rel="nofollow">Download PDF<?php echo '' !== $lesson['attachment_name'] ? ' (' . esc_html( $lesson['attachment_name'] ) . ')' : ''; ?></a>
									<?php endif; ?>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</section>
			<?php endforeach; ?>
		<?php endif; ?>
	</div>
</section>
<?php get_footer(); ?>
