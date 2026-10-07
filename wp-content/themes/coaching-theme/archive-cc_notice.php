<?php get_header(); ?>
<section class="page-head"><div class="container"><h1>Notices <span class="muted">· নোটিশ</span></h1><p class="lead">Announcements from Astona.</p></div></section>
<section class="section">
	<div class="container">
		<?php if ( have_posts() ) : ?>
			<ul class="notice-list notice-list--full">
				<?php while ( have_posts() ) : the_post(); ?>
					<li><a href="<?php the_permalink(); ?>">
						<time datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>"><?php echo esc_html( get_the_date( 'j M Y' ) ); ?></time>
						<span><strong><?php the_title(); ?></strong><span class="muted"><?php echo esc_html( wp_strip_all_tags( get_the_excerpt() ) ); ?></span></span>
					</a></li>
				<?php endwhile; ?>
			</ul>
			<?php the_posts_pagination(); ?>
		<?php else : ?>
			<div class="empty"><p><strong>No public notices at this time.</strong></p></div>
		<?php endif; ?>
	</div>
</section>
<?php get_footer(); ?>
