<?php
get_header();
the_post();
$article_terms = get_the_terms( get_the_ID(), CC_Blog::TAXONOMY );
$article_lang  = CC_Blog::lang_for( get_the_title() );
$related       = CC_Blog::related( get_the_ID() );
?>
<article<?php echo $article_lang ? ' lang="' . esc_attr( $article_lang ) . '"' : ''; ?>>
	<section class="page-head">
		<div class="container">
			<p class="muted"><a href="<?php echo esc_url( get_post_type_archive_link( CC_Blog::POST_TYPE ) ); ?>">← All articles</a></p>
			<h1><?php the_title(); ?></h1>
			<p class="muted">
				By <?php bloginfo( 'name' ); ?> ·
				<time datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>"><?php echo esc_html( get_the_date( 'j F Y' ) ); ?></time>
				<?php if ( is_array( $article_terms ) && $article_terms ) : ?>
					· <?php echo esc_html( implode( ', ', wp_list_pluck( $article_terms, 'name' ) ) ); ?>
				<?php endif; ?>
			</p>
		</div>
	</section>
	<section class="section">
		<div class="container">
			<?php if ( has_post_thumbnail() ) : ?>
				<figure class="article-hero"><?php the_post_thumbnail( 'large', array( 'loading' => 'eager', 'fetchpriority' => 'high' ) ); ?></figure>
			<?php endif; ?>
			<div class="prose"><?php the_content(); ?></div>
		</div>
	</section>
</article>
<?php if ( $related->have_posts() ) : ?>
	<section class="section section--tint" aria-labelledby="related-heading">
		<div class="container">
			<h2 id="related-heading">Related articles</h2>
			<div class="grid grid--cards">
				<?php while ( $related->have_posts() ) : $related->the_post(); ?>
					<?php get_template_part( 'template-parts/article-card' ); ?>
				<?php endwhile; ?>
			</div>
		</div>
	</section>
	<?php wp_reset_postdata(); ?>
<?php endif; ?>
<?php get_footer(); ?>
