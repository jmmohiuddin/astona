<?php get_header(); ?>
<section class="page-head"><div class="container"><h1>Our faculty <span class="muted">· শিক্ষকবৃন্দ</span></h1><p class="lead">Experienced instructors who know the syllabus and the students.</p></div></section>
<section class="section">
	<div class="container">
		<?php if ( have_posts() ) : ?>
			<div class="grid grid--people">
				<?php while ( have_posts() ) : the_post(); ?>
					<div class="person">
						<span class="person__avatar" aria-hidden="true"><?php echo esc_html( mb_substr( get_the_title(), 0, 1 ) ); ?></span>
						<strong><?php the_title(); ?></strong>
						<span class="muted"><?php echo esc_html( (string) get_post_meta( get_the_ID(), 'cc_subject', true ) ); ?></span>
						<span class="small"><?php echo esc_html( (string) get_post_meta( get_the_ID(), 'cc_credentials', true ) ); ?></span>
					</div>
				<?php endwhile; ?>
			</div>
		<?php else : ?>
			<div class="empty"><p><strong>Faculty profiles are coming soon.</strong></p></div>
		<?php endif; ?>
	</div>
</section>
<?php get_footer(); ?>
