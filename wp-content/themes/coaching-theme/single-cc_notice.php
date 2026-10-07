<?php get_header(); the_post(); ?>
<section class="page-head">
	<div class="container">
		<p class="muted"><a href="<?php echo esc_url( get_post_type_archive_link( 'cc_notice' ) ); ?>">← All notices</a></p>
		<h1><?php the_title(); ?></h1>
		<p class="muted"><time datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>"><?php echo esc_html( get_the_date( 'j F Y' ) ); ?></time></p>
	</div>
</section>
<section class="section"><div class="container prose"><?php the_content(); ?></div></section>
<?php get_footer(); ?>
