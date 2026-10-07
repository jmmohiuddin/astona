<?php get_header(); ?>
<section class="section">
	<div class="container empty">
		<h1>Page not found</h1>
		<p>The page you are looking for does not exist or has moved.</p>
		<a class="btn btn--primary" href="<?php echo esc_url( get_post_type_archive_link( 'cc_course' ) ); ?>">Browse courses</a>
	</div>
</section>
<?php get_footer(); ?>
