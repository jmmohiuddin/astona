<?php
get_header();
$article_categories = get_terms( array( 'taxonomy' => CC_Blog::TAXONOMY, 'hide_empty' => true ) );
$current_term       = is_tax( CC_Blog::TAXONOMY ) ? get_queried_object() : null;
?>
<section class="page-head">
	<div class="container">
		<h1><?php echo $current_term ? esc_html( $current_term->name ) : 'Blog'; ?> <span class="muted">· ব্লগ</span></h1>
		<p class="lead">Study guides, exam tips and news from Astona.</p>
	</div>
</section>
<section class="section">
	<div class="container">
		<?php if ( ! is_wp_error( $article_categories ) && $article_categories ) : ?>
			<nav class="blog-filter" aria-label="Blog categories">
				<a class="tag<?php echo $current_term ? '' : ' tag--active'; ?>" href="<?php echo esc_url( get_post_type_archive_link( CC_Blog::POST_TYPE ) ); ?>"<?php echo $current_term ? '' : ' aria-current="page"'; ?>>All</a>
				<?php foreach ( $article_categories as $article_category ) : ?>
					<?php $is_current = $current_term && (int) $current_term->term_id === (int) $article_category->term_id; ?>
					<a class="tag<?php echo $is_current ? ' tag--active' : ''; ?>" href="<?php echo esc_url( get_term_link( $article_category ) ); ?>"<?php echo $is_current ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $article_category->name ); ?></a>
				<?php endforeach; ?>
			</nav>
		<?php endif; ?>

		<?php if ( have_posts() ) : ?>
			<div class="grid grid--cards">
				<?php while ( have_posts() ) : the_post(); ?>
					<?php get_template_part( 'template-parts/article-card' ); ?>
				<?php endwhile; ?>
			</div>
			<?php the_posts_pagination( array( 'mid_size' => 1, 'prev_text' => '← Newer', 'next_text' => 'Older →' ) ); ?>
		<?php else : ?>
			<div class="empty"><p><strong>No articles yet.</strong> Please check back soon.</p></div>
		<?php endif; ?>
	</div>
</section>
<?php get_footer(); ?>
