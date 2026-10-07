<?php
/**
 * My courses (/student/courses/): the student's active batches.
 */
$user = CC_Student_Guard::require_student();
CC_Student_Guard::send_no_store();
$enrollments = CC_Portal_Courses::active_enrollments( $user->ID );
get_header();
?>
<section class="page-head"><div class="container"><h1>My courses</h1></div></section>
<section class="section portal">
	<div class="container">
		<?php get_template_part( 'template-parts/student-nav', null, array( 'current' => 'courses' ) ); ?>
		<p class="portal-status" id="portal-status" role="status" aria-live="polite"></p>
		<?php if ( empty( $enrollments ) ) : ?>
			<p class="notice-box">You have no active course right now. <a href="<?php echo esc_url( get_post_type_archive_link( 'cc_course' ) ); ?>">Browse courses</a>.</p>
		<?php else : ?>
			<div class="portal-grid">
				<?php foreach ( $enrollments as $row ) : ?>
					<article class="card card--pad portal-card">
						<h2 class="h3"><?php echo esc_html( (string) ( $row['course_title'] ?? '' ) ); ?></h2>
						<p><strong><?php echo esc_html( (string) $row['batch_name'] ); ?></strong></p>
						<?php if ( ! empty( $row['schedule_text'] ) ) : ?>
							<p class="small"><?php echo esc_html( (string) $row['schedule_text'] ); ?></p>
						<?php endif; ?>
						<a class="btn btn--primary" href="<?php echo esc_url( CC_Portal_Router::url( 'course', array( 'id' => (int) $row['batch_id'] ) ) ); ?>">Open course</a>
					</article>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</div>
</section>
<?php get_footer(); ?>
