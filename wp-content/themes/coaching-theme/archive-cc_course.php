<?php
get_header();

$category   = isset( $_GET['category'] ) ? sanitize_title( wp_unslash( $_GET['category'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- public filter.
$mode_raw   = isset( $_GET['mode'] ) ? sanitize_key( wp_unslash( $_GET['mode'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
$mode       = in_array( $mode_raw, array( 'physical', 'online', 'hybrid' ), true ) ? $mode_raw : '';
$categories = get_terms( array( 'taxonomy' => 'cc_category', 'hide_empty' => false ) );
$courses    = astona_plugin_ready() ? CC_Batch_Repository::course_summaries( array_filter( array( 'category' => $category, 'mode' => $mode ) ) ) : array();
?>
<section class="page-head">
	<div class="container">
		<h1>Courses <span class="muted">· কোর্সসমূহ</span></h1>
		<p class="lead">Compare courses, batches and prices — then apply in one step.</p>
	</div>
</section>

<section class="section">
	<div class="container">
		<form class="filters" method="get" action="<?php echo esc_url( get_post_type_archive_link( 'cc_course' ) ); ?>" data-course-filters>
			<label>Category
				<select name="category">
					<option value="">All categories</option>
					<?php if ( ! is_wp_error( $categories ) ) : foreach ( $categories as $term ) : ?>
						<option value="<?php echo esc_attr( $term->slug ); ?>" <?php selected( $category, $term->slug ); ?>><?php echo esc_html( $term->name ); ?></option>
					<?php endforeach; endif; ?>
				</select>
			</label>
			<label>Delivery mode
				<select name="mode">
					<option value="">Any mode</option>
					<?php foreach ( array( 'physical' => 'Physical', 'online' => 'Online', 'hybrid' => 'Hybrid' ) as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $mode, $value ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<button type="submit" class="btn btn--primary">Filter</button>
			<a class="btn btn--ghost" href="<?php echo esc_url( get_post_type_archive_link( 'cc_course' ) ); ?>">Clear filters</a>
		</form>

		<h2 class="sr-only">Course results</h2>
		<p class="sr-only" role="status" data-course-status></p>
		<div class="grid grid--cards" id="course-grid" data-course-grid>
			<?php foreach ( $courses as $course ) : ?>
				<?php get_template_part( 'template-parts/course-card', null, array( 'course' => $course ) ); ?>
			<?php endforeach; ?>
		</div>
		<div class="empty" data-course-empty <?php echo $courses ? 'hidden' : ''; ?>>
			<p><strong>No courses match these filters.</strong> Use “Clear filters” above to see all courses.</p>
		</div>
	</div>
</section>
<?php get_footer(); ?>
