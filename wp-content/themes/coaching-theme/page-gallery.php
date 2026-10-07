<?php
/**
 * Template Name: Gallery
 * Public photo gallery: only categories with at least one published item are listed.
 */
get_header();
the_post();
$groups = class_exists( 'CC_Gallery' ) ? CC_Gallery::public_groups() : array();
?>
<section class="page-head"><div class="container"><h1><?php the_title(); ?> <span class="muted">· গ্যালারি</span></h1><p class="lead">Life at Astona: our campus, events and celebrations.</p></div></section>
<section class="section">
	<div class="container">
		<?php if ( get_the_content() ) : ?>
			<div class="prose"><?php the_content(); ?></div>
		<?php endif; ?>
		<?php if ( $groups ) : ?>
			<?php foreach ( $groups as $group ) : ?>
				<section class="gallery-group" aria-labelledby="gallery-<?php echo esc_attr( $group['slug'] ); ?>">
					<h2 id="gallery-<?php echo esc_attr( $group['slug'] ); ?>"><?php echo esc_html( $group['name'] ); ?></h2>
					<?php get_template_part( 'template-parts/gallery-grid', null, array( 'items' => $group['items'], 'group' => $group['slug'] ) ); ?>
				</section>
			<?php endforeach; ?>
		<?php else : ?>
			<div class="empty"><p><strong>Photos are coming soon.</strong></p></div>
		<?php endif; ?>
	</div>
</section>
<?php get_footer(); ?>
