<?php
/**
 * Admissions: application form for an open batch, or the post-payment confirmation view (?ref=).
 */
get_header();

/**
 * Renders one labelled input with an inline error slot (filled by admission.js).
 *
 * @param array<string,mixed> $attrs
 */
function adm_field( string $name, string $label, string $type, array $attrs = array() ): void {
	$required = ! empty( $attrs['required'] );
	$hint     = isset( $attrs['hint'] ) ? (string) $attrs['hint'] : '';
	unset( $attrs['required'], $attrs['hint'] );
	$extra = '';
	foreach ( $attrs as $key => $value ) {
		$extra .= sprintf( ' %s="%s"', esc_attr( $key ), esc_attr( (string) $value ) );
	}
	$describe = 'adm-' . $name . '-err' . ( $hint ? ' adm-' . $name . '-hint' : '' );
	printf(
		'<div class="adm-field" data-field="%1$s"><label for="adm-%1$s">%2$s%3$s</label><input type="%4$s" id="adm-%1$s" name="%1$s"%5$s%6$s aria-describedby="%7$s">%8$s<p class="adm-error" id="adm-%1$s-err" hidden></p></div>',
		esc_attr( $name ),
		esc_html( $label ),
		$required ? ' *' : '',
		esc_attr( $type ),
		$extra, // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
		$required ? ' required' : '',
		esc_attr( $describe ),
		$hint ? '<p class="adm-hint small muted" id="adm-' . esc_attr( $name ) . '-hint">' . esc_html( $hint ) . '</p>' : '' // phpcs:ignore WordPress.Security.EscapeOutput
	);
}

/** @param array<string,string> $options value => label. */
function adm_select( string $name, string $label, array $options ): void {
	$html = '<option value="">Select…</option>';
	foreach ( $options as $value => $text ) {
		$html .= sprintf( '<option value="%s">%s</option>', esc_attr( $value ), esc_html( $text ) );
	}
	printf(
		'<div class="adm-field" data-field="%1$s"><label for="adm-%1$s">%2$s *</label><select id="adm-%1$s" name="%1$s" required aria-describedby="adm-%1$s-err">%3$s</select><p class="adm-error" id="adm-%1$s-err" hidden></p></div>',
		esc_attr( $name ),
		esc_html( $label ),
		$html // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
	);
}

// Strict digits-only: "-1" or "1'<x>" must not be coerced into a valid batch id.
$batch_raw = isset( $_GET['batch'] ) ? sanitize_text_field( wp_unslash( $_GET['batch'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- read-only context lookup.
$batch_id  = ctype_digit( $batch_raw ) ? (int) $batch_raw : 0;
$batch     = ( $batch_id && astona_plugin_ready() ) ? CC_Batch_Repository::find( $batch_id ) : null;
$course    = $batch ? get_post( (int) $batch['course_id'] ) : null;
$contact   = astona_contact();

$ref_raw = isset( $_GET['ref'] ) ? sanitize_text_field( wp_unslash( $_GET['ref'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
$ref     = preg_match( '/^[0-9A-HJKMNP-TV-Z]{26}$/', $ref_raw ) ? $ref_raw : '';

$batch_valid = $batch && $course && 'publish' === $course->post_status && 'draft' !== $batch['status'];
$batch_open  = $batch_valid && CC_Status_Chip::CLOSED !== CC_Status_Chip::for_batch( (string) $batch['status'], (bool) $batch['application_open'], (int) $batch['capacity'], (int) $batch['seats_taken'] );

$phone_tel = preg_replace( '/[^+\d]/', '', $contact['phone'] );
?>
<section class="page-head"><div class="container"><h1>Admissions <span class="muted">· ভর্তি</span></h1></div></section>
<section class="section">
	<div class="container prose">
		<?php if ( '' !== $ref ) : ?>
			<div class="card card--pad adm-status" id="adm-status" data-ref="<?php echo esc_attr( $ref ); ?>">
				<h2 id="adm-status-title" tabindex="-1">Checking your payment…</h2>
				<p id="adm-status-text" role="status" aria-live="polite">Please wait while we confirm your payment.</p>
				<div class="adm-actions" id="adm-status-actions"></div>
			</div>
		<?php elseif ( '' !== $batch_raw && ! $batch_valid ) : ?>
			<p class="notice-box">We could not find that batch. Please choose a course and batch from the course page.</p>
		<?php elseif ( $batch_valid && ! $batch_open ) : ?>
			<p class="notice-box"><strong><?php echo esc_html( get_the_title( $course ) ); ?> — <?php echo esc_html( $batch['name'] ); ?></strong> is not accepting applications. Call us to hear about the next batch.</p>
		<?php elseif ( $batch_open ) : ?>
			<div class="card card--pad">
				<p class="muted">You are applying for</p>
				<h2><?php echo esc_html( get_the_title( $course ) ); ?></h2>
				<p><strong><?php echo esc_html( $batch['name'] ); ?></strong> · <?php echo esc_html( ucfirst( $batch['delivery_mode'] ) ); ?> · starts <?php echo esc_html( mysql2date( 'j M Y', $batch['start_date'] ) ); ?> · <?php echo esc_html( astona_money( $batch['price'] ) ); ?></p>
			</div>
			<form class="adm-form" id="adm-form" method="post" enctype="multipart/form-data" novalidate data-batch-id="<?php echo esc_attr( (string) $batch_id ); ?>">
				<p class="muted small">Fields marked * are required.</p>
				<div class="adm-errors" id="adm-errors" role="alert" aria-live="assertive" tabindex="-1"></div>

				<fieldset class="adm-group">
					<legend>Student identification</legend>
					<?php
					adm_field( 'full_name', 'Full name', 'text', array( 'autocomplete' => 'name', 'maxlength' => 190, 'required' => true ) );
					adm_select( 'gender', 'Gender', array( 'm' => 'Male', 'f' => 'Female', 'o' => 'Other' ) );
					adm_field( 'dob', 'Date of birth', 'date', array( 'autocomplete' => 'bday', 'required' => true ) );
					adm_select( 'id_doc_type', 'ID document type', array( 'nid' => 'National ID', 'birth_cert' => 'Birth certificate', 'passport' => 'Passport' ) );
					adm_field( 'id_doc_number', 'ID document number', 'text', array( 'maxlength' => 30, 'autocomplete' => 'off', 'required' => true ) );
					?>
					<div class="adm-field" data-field="photo">
						<label for="adm-photo">Student photo (JPEG or PNG, up to 2 MB) *</label>
						<input type="file" id="adm-photo" name="photo" accept="image/jpeg,image/png" required aria-describedby="adm-photo-err">
						<img class="adm-preview" id="adm-photo-preview" alt="Selected photo preview" hidden width="96" height="96">
						<p class="adm-error" id="adm-photo-err" hidden></p>
					</div>
				</fieldset>

				<fieldset class="adm-group">
					<legend>Contact</legend>
					<?php
					adm_field( 'student_phone', 'Student mobile number', 'tel', array( 'autocomplete' => 'tel', 'inputmode' => 'tel', 'hint' => 'For example 01712-345678', 'required' => true ) );
					?>
					<?php if ( '' !== astona_turnstile_site_key() ) : ?>
						<div class="adm-captcha" id="adm-captcha-wrap">
							<p class="adm-captcha__label small muted" id="adm-captcha-label">Security check: let it finish before you verify your number or submit.</p>
							<div id="adm-turnstile" data-sitekey="<?php echo esc_attr( astona_turnstile_site_key() ); ?>" data-response-field-name="cf-turnstile-response" aria-labelledby="adm-captcha-label"></div>
						</div>
					<?php endif; ?>
					<div class="adm-verify" id="adm-verify">
						<button type="button" class="btn btn--ghost" id="adm-verify-send">Verify number</button>
						<div class="adm-verify__code" id="adm-verify-code-wrap" hidden>
							<label for="adm-verify-code">Verification code</label>
							<div class="adm-verify__row">
								<input type="text" id="adm-verify-code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="[0-9]{6}" aria-describedby="adm-verify-msg">
								<button type="button" class="btn btn--primary" id="adm-verify-confirm">Confirm code</button>
							</div>
							<button type="button" class="adm-linkbtn" id="adm-verify-resend" disabled>Resend code</button>
						</div>
						<p class="adm-verify__msg" id="adm-verify-msg" role="status" aria-live="polite">We will text a 6-digit code to this number to confirm it is yours.</p>
						<button type="button" class="adm-linkbtn" id="adm-verify-change" hidden>Change number</button>
						<input type="hidden" name="phone_proof" id="adm-phone_proof" value="">
					</div>
					<?php
					adm_field( 'guardian_name', 'Guardian name', 'text', array( 'maxlength' => 190, 'required' => true ) );
					adm_field( 'guardian_phone', 'Guardian mobile number', 'tel', array( 'inputmode' => 'tel', 'required' => true ) );
					adm_field( 'email', 'Email (optional)', 'email', array( 'autocomplete' => 'email', 'maxlength' => 190 ) );
					?>
				</fieldset>

				<fieldset class="adm-group">
					<legend>Academic background</legend>
					<?php
					adm_field( 'institution', 'School or college', 'text', array( 'maxlength' => 190, 'required' => true ) );
					adm_field( 'class_level', 'Class or level', 'text', array( 'maxlength' => 60, 'required' => true ) );
					adm_field( 'passing_year', 'Passing year (optional)', 'text', array( 'inputmode' => 'numeric', 'maxlength' => 4 ) );
					adm_field( 'roll_no', 'Roll number (optional)', 'text', array( 'maxlength' => 40 ) );
					?>
				</fieldset>

				<fieldset class="adm-group">
					<legend>Enrollment</legend>
					<p>Fee: <strong><?php echo esc_html( astona_money( $batch['price'] ) ); ?></strong>. You will be sent to the payment page after submitting.</p>
					<div class="adm-field adm-field--check" data-field="consent">
						<input type="checkbox" id="adm-consent" name="consent" value="1" required aria-describedby="adm-consent-err">
						<label for="adm-consent">I confirm the details are correct and agree to the admission terms. *</label>
						<p class="adm-error" id="adm-consent-err" hidden></p>
					</div>
				</fieldset>

				<div class="adm-hp" aria-hidden="true">
					<label for="adm-hp">Leave this field empty</label>
					<input type="text" id="adm-hp" name="company_site" tabindex="-1" autocomplete="off">
				</div>

				<button type="submit" class="btn btn--primary btn--lg btn--block" id="adm-submit" aria-describedby="adm-submit-hint">Submit and pay</button>
				<p class="adm-submit-hint muted small" id="adm-submit-hint">Verify your student mobile number to enable this button.</p>
				<p class="adm-submitting muted" id="adm-submitting" role="status" aria-live="polite" hidden></p>
			</form>
		<?php else : ?>
			<p class="notice-box">Choose a course and batch to start your application.</p>
		<?php endif; ?>
		<p class="muted small">Need help? Call <a href="tel:<?php echo esc_attr( $phone_tel ); ?>"><?php echo esc_html( $contact['phone'] ); ?></a> or visit our office.</p>
		<p><a class="btn btn--ghost" href="<?php echo esc_url( get_post_type_archive_link( 'cc_course' ) ); ?>">← Back to courses</a></p>
	</div>
</section>
<?php get_footer(); ?>
