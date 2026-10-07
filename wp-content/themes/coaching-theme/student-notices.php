<?php
/**
 * Notices feed (/student/notices/): public notices plus those targeted at the student's active batches.
 */
$user = CC_Student_Guard::require_student();
CC_Student_Guard::send_no_store();
$labels   = CC_Portal_Courses::batch_labels( $user->ID );
$page_in  = isset( $_GET['page'] ) ? absint( wp_unslash( $_GET['page'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification -- read-only filter.
$batch_in = isset( $_GET['batch'] ) ? absint( wp_unslash( $_GET['batch'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
$feed     = CC_Portal_Courses::notices_page( $user->ID, $page_in, $batch_in );
get_header();
?>
<section class="page-head"><div class="container"><h1>Notices</h1></div></section>
<section class="section portal">
	<div class="container">
		<?php get_template_part( 'template-parts/student-nav', null, array( 'current' => 'notices' ) ); ?>
		<p class="portal-status" id="portal-status" role="status" aria-live="polite"></p>
		<?php if ( count( $labels ) > 1 ) : ?>
			<form method="get" class="notice-filter" action="<?php echo esc_url( CC_Portal_Router::url( 'notices' ) ); ?>">
				<label for="notice-batch">Show notices for</label>
				<select id="notice-batch" name="batch">
					<option value="0">All my courses</option>
					<?php foreach ( $labels as $id => $label ) : ?>
						<option value="<?php echo esc_attr( (string) $id ); ?>"<?php selected( $feed['batch_id'], $id ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<button type="submit" class="btn btn--ghost">Filter</button>
			</form>
		<?php endif; ?>
		<?php if ( empty( $feed['items'] ) ) : ?>
			<p class="notice-box">No notices yet. New announcements for your courses will show up here.</p>
		<?php else : ?>
			<ul class="notice-feed">
				<?php foreach ( $feed['items'] as $notice ) : ?>
					<li class="card card--pad">
						<h2 class="h3"><a href="<?php echo esc_url( $notice['url'] ); ?>"><?php echo esc_html( $notice['title'] ); ?></a></h2>
						<p class="muted small"><?php echo esc_html( $notice['date'] ); ?><?php echo $notice['public'] ? ' · Public' : ''; ?></p>
						<p><?php echo esc_html( $notice['excerpt'] ); ?></p>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
		<?php if ( $feed['page'] > 1 || $feed['has_more'] ) : ?>
			<nav class="notice-paging" aria-label="Notices pages">
				<?php if ( $feed['page'] > 1 ) : ?>
					<a href="<?php echo esc_url( CC_Portal_Router::url( 'notices', array( 'batch' => $feed['batch_id'], 'page' => $feed['page'] - 1 ) ) ); ?>">Newer</a>
				<?php endif; ?>
				<?php if ( $feed['has_more'] ) : ?>
					<a href="<?php echo esc_url( CC_Portal_Router::url( 'notices', array( 'batch' => $feed['batch_id'], 'page' => $feed['page'] + 1 ) ) ); ?>">Older</a>
				<?php endif; ?>
			</nav>
		<?php endif; ?>
	</div>
</section>
<?php get_footer(); ?>
