<?php get_header(); ?>
<section class="page-head"><div class="container"><h1><?php echo esc_html( is_home() ? get_bloginfo( 'name' ) : get_the_archive_title() ); ?></h1></div></section>
<section class="section">
	<div class="container prose">
		<?php if ( have_posts() ) : while ( have_posts() ) : the_post(); ?>
			<article>
				<h2><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
				<?php the_excerpt(); ?>
			</article>
		<?php endwhile; else : ?>
			<p>Nothing to show here yet.</p>
		<?php endif; ?>
	</div>
</section>
<?php get_footer(); ?>
