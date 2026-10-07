<?php get_header(); ?>

<section class="hero">
	<div class="container hero__inner">
		<p class="eyebrow">Coaching center · কোচিং সেন্টার</p>
		<h1 class="hero__title">Learn with clarity.<br>Grow with confidence.</h1>
		<p class="hero__lead">Board exams, university admission and job-ready skills — in small batches, taught by instructors who care.</p>
		<div class="hero__actions">
			<a class="btn btn--primary btn--lg" href="<?php echo esc_url( get_post_type_archive_link( 'cc_course' ) ); ?>">Browse Courses</a>
			<a class="btn btn--ghost btn--lg" href="<?php echo esc_url( astona_apply_url() ); ?>">Apply Now</a>
		</div>
	</div>
</section>

<?php if ( ! astona_plugin_ready() ) : ?>
	<div class="container section"><p class="notice-box">The Coaching Platform plugin is not active.</p></div>
<?php else : ?>

	<?php $categories = get_terms( array( 'taxonomy' => 'cc_category', 'hide_empty' => true ) ); ?>
	<?php if ( ! is_wp_error( $categories ) && $categories ) : ?>
	<section class="section">
		<div class="container">
			<h2 class="section__title">Find your course</h2>
			<ul class="category-grid">
				<?php foreach ( $categories as $term ) : ?>
					<li><a class="category-card" href="<?php echo esc_url( add_query_arg( 'category', $term->slug, get_post_type_archive_link( 'cc_course' ) ) ); ?>">
						<span class="category-card__name"><?php echo esc_html( $term->name ); ?></span>
						<span class="muted"><?php echo esc_html( sprintf( _n( '%d course', '%d courses', $term->count ), $term->count ) ); ?></span>
					</a></li>
				<?php endforeach; ?>
			</ul>
		</div>
	</section>
	<?php endif; ?>

	<section class="section section--tint">
		<div class="container">
			<div class="section__head">
				<h2 class="section__title">Popular courses</h2>
				<a class="link-arrow" href="<?php echo esc_url( get_post_type_archive_link( 'cc_course' ) ); ?>">All courses →</a>
			</div>
			<div class="grid grid--cards">
				<?php foreach ( array_slice( CC_Batch_Repository::course_summaries(), 0, 6 ) as $course ) : ?>
					<?php get_template_part( 'template-parts/course-card', null, array( 'course' => $course ) ); ?>
				<?php endforeach; ?>
			</div>
		</div>
	</section>

	<section class="section">
		<div class="container">
			<h2 class="section__title">Why students choose Astona</h2>
			<ul class="trust-grid">
				<li class="trust"><strong>Small batches</strong><span>Direct access to instructors and quick doubt-clearing.</span></li>
				<li class="trust"><strong>Learn your way</strong><span>Physical, online and hybrid batches for most courses.</span></li>
				<li class="trust"><strong>Weekly tests</strong><span>Regular exams and feedback so progress is visible.</span></li>
				<li class="trust"><strong>One-step admission</strong><span>Apply and pay in one visit — no separate sign-up.</span></li>
			</ul>
		</div>
	</section>

	<?php $notices = get_posts( array( 'post_type' => 'cc_notice', 'posts_per_page' => 3, 'post__not_in' => CC_Notice_Access::hidden_ids() ) ); ?>
	<?php if ( $notices ) : ?>
	<section class="section section--tint">
		<div class="container">
			<div class="section__head">
				<h2 class="section__title">Latest notices</h2>
				<a class="link-arrow" href="<?php echo esc_url( get_post_type_archive_link( 'cc_notice' ) ); ?>">All notices →</a>
			</div>
			<ul class="notice-list">
				<?php foreach ( $notices as $notice ) : ?>
					<li><a href="<?php echo esc_url( get_permalink( $notice ) ); ?>">
						<time datetime="<?php echo esc_attr( get_the_date( 'c', $notice ) ); ?>"><?php echo esc_html( get_the_date( 'j M Y', $notice ) ); ?></time>
						<span><?php echo esc_html( get_the_title( $notice ) ); ?></span>
					</a></li>
				<?php endforeach; ?>
			</ul>
		</div>
	</section>
	<?php endif; ?>

<?php endif; ?>

<?php get_footer(); ?>
