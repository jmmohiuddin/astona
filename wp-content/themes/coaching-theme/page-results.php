<?php
/**
 * Template Name: Results
 * Public results: only records that are verified AND consent-confirmed are ever queried.
 */
$groups = class_exists( 'CC_Results' ) ? CC_Results::public_groups() : array();
$has_photos = (bool) array_filter( array_merge( array(), ...array_values( $groups ) ), static fn( array $record ): bool => $record['photo'] );
if ( $has_photos && ! headers_sent() ) {
	// Photo URLs are signed at render time and expire after 10 minutes: no shared cache may keep this page (CC_Result_Photo_Access).
	header( 'Cache-Control: private, no-cache' );
}
get_header();
the_post();
?>
<section class="page-head"><div class="container"><h1><?php the_title(); ?> <span class="muted">· ফলাফল</span></h1><p class="lead">Verified achievements of Astona students, shown with their permission.</p></div></section>
<section class="section">
	<div class="container">
		<?php if ( get_the_content() ) : ?>
			<div class="prose"><?php the_content(); ?></div>
		<?php endif; ?>
		<?php if ( $groups ) : ?>
			<?php foreach ( $groups as $year => $records ) : ?>
				<section class="results-year" aria-labelledby="results-<?php echo esc_attr( $year ? (string) $year : 'other' ); ?>">
					<h2 id="results-<?php echo esc_attr( $year ? (string) $year : 'other' ); ?>"><?php echo esc_html( $year ? (string) $year : 'Other' ); ?></h2>
					<ul class="results-list" role="list">
						<?php foreach ( $records as $record ) : ?>
							<li class="results-card card">
								<?php if ( $record['photo'] ) : ?>
									<?php
									$photo_src  = CC_Result_Photo_Access::signed_url( $record['id'] );
									$photo_webp = CC_Result_Photo_Access::signed_url( $record['id'], CC_Result_Photo_Access::VARIANT_WEBP );
									?>
									<?php if ( '' !== $photo_src ) : ?>
										<picture>
											<?php if ( '' !== $photo_webp ) : ?><source type="image/webp" srcset="<?php echo esc_url( $photo_webp ); ?>"><?php endif; ?>
											<img class="results-card__photo" src="<?php echo esc_url( $photo_src ); ?>" alt="<?php echo esc_attr( $record['photo_alt'] ); ?>" width="72" height="72" loading="lazy" decoding="async">
										</picture>
									<?php endif; ?>
								<?php endif; ?>
								<div class="results-card__body">
									<strong class="results-card__name"><?php echo esc_html( $record['name'] ); ?></strong>
									<?php if ( '' !== $record['exam'] ) : ?><span class="muted"><?php echo esc_html( $record['exam'] ); ?></span><?php endif; ?>
									<?php if ( '' !== $record['score'] ) : ?><span class="results-card__score"><?php echo esc_html( $record['score'] ); ?></span><?php endif; ?>
									<?php if ( '' !== $record['institution'] ) : ?><span class="small">Admitted to <?php echo esc_html( $record['institution'] ); ?></span><?php endif; ?>
								</div>
							</li>
						<?php endforeach; ?>
					</ul>
				</section>
			<?php endforeach; ?>
		<?php else : ?>
			<div class="empty"><p><strong>Results will be published here soon.</strong></p></div>
		<?php endif; ?>
	</div>
</section>
<?php get_footer(); ?>
