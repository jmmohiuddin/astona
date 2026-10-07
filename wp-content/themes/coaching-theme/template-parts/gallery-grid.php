<?php
/**
 * One gallery grid. Args: items (CC_Gallery::public_items() rows), group (slug used to scope lightbox navigation).
 */
$items = isset( $args['items'] ) ? (array) $args['items'] : array();
$group = isset( $args['group'] ) ? (string) $args['group'] : '';
if ( ! $items ) {
	return;
}
?>
<ul class="gallery-grid" role="list">
	<?php foreach ( $items as $item ) : ?>
		<li class="gallery-grid__item">
			<a class="gallery-grid__link" href="<?php echo esc_url( $item['full']['url'] ); ?>" data-gallery-link data-gallery-group="<?php echo esc_attr( $group ); ?>" data-caption="<?php echo esc_attr( $item['caption'] ); ?>" data-width="<?php echo esc_attr( (string) $item['full']['width'] ); ?>" data-height="<?php echo esc_attr( (string) $item['full']['height'] ); ?>">
				<?php
				echo wp_get_attachment_image( // phpcs:ignore WordPress.Security.EscapeOutput -- core builds and escapes the tag.
					$item['image'],
					'medium_large',
					false,
					array(
						'alt'      => $item['alt'],
						'loading'  => 'lazy',
						'decoding' => 'async',
						'sizes'    => '(min-width: 900px) 33vw, (min-width: 560px) 50vw, 100vw',
						'class'    => 'gallery-grid__img',
					)
				);
				?>
			</a>
			<?php if ( '' !== $item['caption'] ) : ?>
				<p class="gallery-grid__caption small"><?php echo esc_html( $item['caption'] ); ?></p>
			<?php endif; ?>
		</li>
	<?php endforeach; ?>
</ul>
