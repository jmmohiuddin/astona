<?php
/**
 * Course card. Expects $args['course'] from CC_Batch_Repository::course_summaries().
 */
$course = $args['course'] ?? null;
if ( ! $course ) {
	return;
}
?>
<article class="card course-card">
	<a class="card__link" href="<?php echo esc_url( $course['url'] ); ?>">
		<div class="card__media">
			<?php if ( $course['thumbnail'] ) : ?>
				<img src="<?php echo esc_url( $course['thumbnail'] ); ?>" alt="" width="640" height="400" loading="lazy">
			<?php else : ?>
				<span class="card__placeholder" aria-hidden="true"><?php echo esc_html( mb_substr( wp_strip_all_tags( $course['title'] ), 0, 1 ) ); ?></span>
			<?php endif; ?>
		</div>
		<div class="card__body">
			<div class="card__meta">
				<?php echo astona_chip( $course['status'], $course['status_label'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in helper. ?>
				<?php foreach ( $course['modes'] as $mode ) : ?>
					<span class="tag"><?php echo esc_html( ucfirst( $mode ) ); ?></span>
				<?php endforeach; ?>
			</div>
			<h3 class="card__title"><?php echo esc_html( $course['title'] ); ?></h3>
			<p class="card__text"><?php echo esc_html( $course['excerpt'] ); ?></p>
			<?php if ( $course['price_display'] ) : ?>
				<p class="card__price"><span class="muted">From</span> <strong><?php echo esc_html( $course['price_display'] ); ?></strong></p>
			<?php endif; ?>
		</div>
	</a>
</article>
