<?php
get_header();
the_post();

if ( ! astona_plugin_ready() ) {
	echo '<section class="section"><div class="container"><p class="notice-box">The Coaching Platform plugin is not active.</p></div></section>';
	get_footer();
	return;
}

$course_id   = get_the_ID();
$batches     = CC_Batch_Repository::for_course( $course_id, true );
$chip        = CC_Status_Chip::for_batches( $batches );
$prices      = CC_Batch_Repository::headline_prices( $batches );
$syllabus    = array_filter( array_map( 'trim', explode( "\n", (string) get_post_meta( $course_id, 'cc_syllabus', true ) ) ) );
$duration    = (string) get_post_meta( $course_id, 'cc_duration', true );
$instructors = array_filter(
	array_map( 'get_post', array_map( 'intval', (array) get_post_meta( $course_id, 'cc_instructors', true ) ) ),
	static fn( $p ): bool => $p instanceof WP_Post && 'cc_faculty' === $p->post_type && 'publish' === $p->post_status
);

$batch_state = static function ( array $b ): string {
	return CC_Status_Chip::for_batch( (string) $b['status'], (bool) $b['application_open'], (int) $b['capacity'], (int) $b['seats_taken'] );
};
$open_batches = array_values( array_filter( $batches, static fn( $b ) => CC_Status_Chip::CLOSED !== $batch_state( $b ) ) );
$default_id   = $open_batches ? (int) $open_batches[0]['id'] : 0;
$apply_url    = astona_apply_url( $default_id );

$term_slugs = wp_get_post_terms( $course_id, 'cc_category', array( 'fields' => 'slugs' ) );
$related    = array_slice(
	array_filter(
		CC_Batch_Repository::course_summaries( ( is_array( $term_slugs ) && $term_slugs ) ? array( 'category' => $term_slugs[0] ) : array() ),
		static fn( $c ) => $c['id'] !== $course_id
	),
	0,
	3
);
?>
<section class="course-hero">
	<div class="container course-hero__inner">
		<div>
			<div class="card__meta"><?php echo astona_chip( $chip, CC_Status_Chip::label( $chip ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<?php if ( $duration ) : ?><span class="tag"><?php echo esc_html( $duration ); ?></span><?php endif; ?></div>
			<h1><?php the_title(); ?></h1>
			<div class="lead"><?php the_excerpt(); ?></div>
		</div>
		<aside class="buy-box">
			<?php if ( $prices ) : ?>
				<p class="buy-box__price"><span class="muted">From</span> <strong><?php echo esc_html( astona_money( min( $prices ) ) ); ?></strong></p>
			<?php endif; ?>
			<?php if ( $open_batches ) : ?>
				<a class="btn btn--primary btn--lg btn--block" href="<?php echo esc_url( $apply_url ); ?>" data-apply-cta aria-label="<?php echo esc_attr( 'Apply now for ' . get_the_title() ); ?>">Apply Now</a>
			<?php else : ?>
				<span class="btn btn--disabled btn--lg btn--block" aria-disabled="true">Applications Closed</span>
				<p class="muted small">Call us to hear about the next batch.</p>
			<?php endif; ?>
		</aside>
	</div>
</section>

<div class="container course-body">
	<div>
		<?php if ( $batches ) : ?>
		<section class="block" aria-labelledby="batches-h">
			<h2 id="batches-h">Choose a batch</h2>
			<form data-batch-form data-apply-base="<?php echo esc_url( astona_apply_url() ); ?>">
			<fieldset class="batch-list">
				<legend class="sr-only">Choose a batch</legend>
				<?php foreach ( $batches as $b ) :
					$state    = $batch_state( $b );
					$seats    = max( 0, (int) $b['capacity'] - (int) $b['seats_taken'] );
					$disabled = CC_Status_Chip::CLOSED === $state;
					?>
					<label class="batch <?php echo $disabled ? 'batch--closed' : ''; ?>">
						<input type="radio" name="batch" value="<?php echo esc_attr( (string) $b['id'] ); ?>" <?php checked( (int) $b['id'] === $default_id ); ?> <?php disabled( $disabled ); ?>>
						<span class="batch__main">
							<strong><?php echo esc_html( $b['name'] ); ?></strong>
							<span class="muted"><?php echo esc_html( ucfirst( $b['delivery_mode'] ) ); ?> · starts <?php echo esc_html( mysql2date( 'j M Y', $b['start_date'] ) ); ?></span>
							<?php if ( $b['schedule_text'] ) : ?><span class="muted"><?php echo esc_html( $b['schedule_text'] ); ?></span><?php endif; ?>
						</span>
						<span class="batch__side">
							<strong><?php echo esc_html( astona_money( $b['price'] ) ); ?></strong>
							<?php echo astona_chip( $state, CC_Status_Chip::label( $state ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
							<?php if ( ! $disabled ) : ?><span class="muted small"><?php echo esc_html( $seats ); ?> seats left</span><?php endif; ?>
						</span>
					</label>
				<?php endforeach; ?>
			</fieldset>
			</form>
		</section>
		<?php endif; ?>

		<?php if ( get_the_content() ) : ?>
		<section class="block"><h2>About this course</h2><div class="prose"><?php the_content(); ?></div></section>
		<?php endif; ?>

		<?php if ( $syllabus ) : ?>
		<section class="block" aria-labelledby="syllabus-h">
			<h2 id="syllabus-h">Syllabus</h2>
			<?php foreach ( $syllabus as $i => $topic ) : ?>
				<details class="accordion"><summary><?php echo esc_html( $topic ); ?></summary><p class="muted">Topic <?php echo esc_html( (string) ( $i + 1 ) ); ?> — detailed breakdown is shared at orientation.</p></details>
			<?php endforeach; ?>
		</section>
		<?php endif; ?>

		<?php if ( $instructors ) : ?>
		<section class="block" aria-labelledby="inst-h">
			<h2 id="inst-h">Your instructors</h2>
			<div class="grid grid--people">
				<?php foreach ( $instructors as $person ) : ?>
					<div class="person">
						<span class="person__avatar" aria-hidden="true"><?php echo esc_html( mb_substr( $person->post_title, 0, 1 ) ); ?></span>
						<strong><?php echo esc_html( $person->post_title ); ?></strong>
						<span class="muted"><?php echo esc_html( (string) get_post_meta( $person->ID, 'cc_subject', true ) ); ?></span>
						<span class="small"><?php echo esc_html( (string) get_post_meta( $person->ID, 'cc_credentials', true ) ); ?></span>
					</div>
				<?php endforeach; ?>
			</div>
		</section>
		<?php endif; ?>
	</div>
</div>

<?php if ( $related ) : ?>
<section class="section section--tint">
	<div class="container">
		<h2 class="section__title">Related courses</h2>
		<div class="grid grid--cards">
			<?php foreach ( $related as $course ) : ?>
				<?php get_template_part( 'template-parts/course-card', null, array( 'course' => $course ) ); ?>
			<?php endforeach; ?>
		</div>
	</div>
</section>
<?php endif; ?>

<?php if ( $open_batches ) : ?>
<div class="sticky-cta"><a class="btn btn--primary btn--block" href="<?php echo esc_url( $apply_url ); ?>" data-apply-cta aria-label="<?php echo esc_attr( 'Apply now for ' . get_the_title() ); ?>">Apply Now</a></div>
<?php endif; ?>

<?php get_footer(); ?>
