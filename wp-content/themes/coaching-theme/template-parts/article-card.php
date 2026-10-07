<?php
/**
 * Blog article card. Call inside The Loop.
 */
$article_id    = get_the_ID();
$article_terms = get_the_terms( $article_id, CC_Blog::TAXONOMY );
$article_term  = is_array( $article_terms ) && $article_terms ? $article_terms[0] : null;
$article_title = get_the_title();
$article_lang  = CC_Blog::lang_for( $article_title );
?>
<article class="card article-card">
	<a class="card__link" href="<?php the_permalink(); ?>">
		<?php if ( has_post_thumbnail() ) : ?>
			<div class="card__media"><?php the_post_thumbnail( 'astona-card', array( 'alt' => '', 'loading' => 'lazy', 'decoding' => 'async' ) ); ?></div>
		<?php endif; ?>
		<div class="card__body"<?php echo $article_lang ? ' lang="' . esc_attr( $article_lang ) . '"' : ''; ?>>
			<div class="card__meta">
				<?php if ( $article_term ) : ?>
					<span class="tag"><?php echo esc_html( $article_term->name ); ?></span>
				<?php endif; ?>
				<time class="muted" datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>"><?php echo esc_html( get_the_date( 'j M Y' ) ); ?></time>
			</div>
			<h3 class="card__title"><?php echo esc_html( $article_title ); ?></h3>
			<p class="card__text"><?php echo esc_html( CC_Blog::excerpt_for( get_post() ) ); ?></p>
		</div>
	</a>
</article>
