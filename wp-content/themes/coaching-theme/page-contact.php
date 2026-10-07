<?php
/**
 * Contact: inquiry form (assets/js/contact.js posts to /cc/v1/contact) plus branch details and maps.
 */
get_header();

$contact   = astona_contact();
$phone_tel = preg_replace( '/[^+\d]/', '', $contact['phone'] );
$branches  = class_exists( 'CC_Branches' ) ? CC_Branches::all() : array();
$courses   = astona_plugin_ready() ? get_posts( array( 'post_type' => 'cc_course', 'post_status' => 'publish', 'posts_per_page' => 100, 'orderby' => 'title', 'order' => 'ASC' ) ) : array();
$topics    = array( 'course' => 'A course', 'admission' => 'Admission', 'fees' => 'Fees and payment', 'other' => 'Something else' );

/** One labelled control with an inline error slot (filled by contact.js). */
function ct_field( string $name, string $label, string $control, string $hint = '' ): void {
	printf(
		'<div class="ct-field" data-field="%1$s"><label for="ct-%1$s">%2$s</label>%3$s%4$s<p class="ct-error" id="ct-%1$s-err" hidden></p></div>',
		esc_attr( $name ),
		esc_html( $label ),
		$control, // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts by the caller.
		$hint ? '<p class="ct-hint small muted" id="ct-' . esc_attr( $name ) . '-hint">' . esc_html( $hint ) . '</p>' : '' // phpcs:ignore WordPress.Security.EscapeOutput
	);
}
?>
<section class="page-head"><div class="container"><h1>Contact us <span class="muted">· যোগাযোগ</span></h1></div></section>
<section class="section">
	<div class="container ct-layout">
		<div class="ct-form-wrap">
			<div class="card card--pad ct-success" id="ct-success" role="status" aria-live="polite" tabindex="-1" hidden>
				<h2>Thank you</h2>
				<p>We received your message and will call or write back soon.</p>
			</div>
			<form class="ct-form card card--pad" id="ct-form" novalidate>
				<h2>Send us a message</h2>
				<p class="muted small">Fields marked * are required.</p>
				<div class="ct-errors" id="ct-errors" role="alert" aria-live="assertive" tabindex="-1"></div>
				<?php
				ct_field( 'name', 'Your name *', '<input type="text" id="ct-name" name="name" required minlength="2" maxlength="190" autocomplete="name" aria-describedby="ct-name-err">' );
				ct_field( 'phone', 'Mobile number *', '<input type="tel" id="ct-phone" name="phone" required inputmode="tel" autocomplete="tel" aria-describedby="ct-phone-err ct-phone-hint">', 'For example 01712-345678' );
				ct_field( 'email', 'Email (optional)', '<input type="email" id="ct-email" name="email" maxlength="190" autocomplete="email" aria-describedby="ct-email-err">' );

				$options = '<option value="">Select…</option>';
				foreach ( $topics as $value => $text ) {
					$options .= sprintf( '<option value="%s">%s</option>', esc_attr( $value ), esc_html( $text ) );
				}
				ct_field( 'topic', 'What is it about? *', '<select id="ct-topic" name="topic" required aria-describedby="ct-topic-err">' . $options . '</select>' );

				if ( $courses ) {
					$options = '<option value="">Not about a specific course</option>';
					foreach ( $courses as $course ) {
						$options .= sprintf( '<option value="%d">%s</option>', (int) $course->ID, esc_html( get_the_title( $course ) ) );
					}
					ct_field( 'course_id', 'Course (optional)', '<select id="ct-course_id" name="course_id" aria-describedby="ct-course_id-err">' . $options . '</select>' );
				}

				ct_field( 'message', 'Your message *', '<textarea id="ct-message" name="message" rows="6" required minlength="10" maxlength="2000" aria-describedby="ct-message-err ct-message-hint"></textarea>', '10 to 2000 characters.' );
				?>
				<div class="ct-hp" aria-hidden="true">
					<label for="ct-hp">Leave this field empty</label>
					<input type="text" id="ct-hp" name="company_site" tabindex="-1" autocomplete="off">
				</div>
				<?php if ( '' !== astona_turnstile_site_key() ) : ?>
					<div class="cf-turnstile" data-sitekey="<?php echo esc_attr( astona_turnstile_site_key() ); ?>" data-response-field-name="cf-turnstile-response"></div>
				<?php endif; ?>
				<button type="submit" class="btn btn--primary btn--lg btn--block" id="ct-submit">Send message</button>
				<p class="ct-sending muted" id="ct-sending" role="status" aria-live="polite" hidden></p>
			</form>
		</div>

		<aside class="ct-details">
			<div class="card card--pad">
				<h2>Reach us</h2>
				<p><strong>Phone:</strong> <a href="tel:<?php echo esc_attr( $phone_tel ); ?>"><?php echo esc_html( $contact['phone'] ); ?></a></p>
				<p><strong>Email:</strong> <a href="mailto:<?php echo esc_attr( $contact['email'] ); ?>"><?php echo esc_html( $contact['email'] ); ?></a></p>
				<?php if ( ! $branches ) : ?>
					<p><strong>Address:</strong> <?php echo esc_html( $contact['address'] ); ?></p>
				<?php endif; ?>
			</div>
			<?php foreach ( $branches as $branch ) : ?>
				<div class="card card--pad ct-branch">
					<h3><?php echo esc_html( $branch['name'] ); ?></h3>
					<?php if ( '' !== $branch['address'] ) : ?><p><?php echo esc_html( $branch['address'] ); ?></p><?php endif; ?>
					<?php if ( '' !== $branch['phone'] ) : ?><p><a href="tel:<?php echo esc_attr( preg_replace( '/[^+\d]/', '', $branch['phone'] ) ); ?>"><?php echo esc_html( $branch['phone'] ); ?></a></p><?php endif; ?>
					<?php if ( '' !== $branch['hours'] ) : ?><p class="muted small"><?php echo nl2br( esc_html( $branch['hours'] ) ); ?></p><?php endif; ?>
					<?php echo CC_Branches::map_iframe( $branch['map_url'], $branch['name'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside map_iframe(). ?>
				</div>
			<?php endforeach; ?>
		</aside>
	</div>
</section>
<?php get_footer(); ?>
