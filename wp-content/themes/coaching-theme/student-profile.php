<?php
/**
 * Student profile (/student/profile/): read-only identity, editable guardian/email, password change.
 */
$user = CC_Student_Guard::require_student();
CC_Student_Guard::send_no_store();
$profile      = CC_Portal_Data::profile( $user->ID );
$force_change = isset( $_GET['change'] ) && '1' === $_GET['change']; // phpcs:ignore WordPress.Security.NonceVerification -- display only.
get_header();

/** @param array<string,string> $attrs */
function portal_field( string $name, string $label, string $type, string $value, array $attrs = array() ): void {
	$extra = '';
	foreach ( $attrs as $key => $val ) {
		$extra .= sprintf( ' %s="%s"', esc_attr( $key ), esc_attr( $val ) );
	}
	printf(
		'<div class="adm-field" data-field="%1$s"><label for="pf-%1$s">%2$s</label><input type="%3$s" id="pf-%1$s" name="%1$s" value="%4$s"%5$s aria-describedby="pf-%1$s-err"><p class="adm-error" id="pf-%1$s-err" hidden></p></div>',
		esc_attr( $name ),
		esc_html( $label ),
		esc_attr( $type ),
		esc_attr( $value ),
		$extra // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
	);
}
?>
<section class="page-head"><div class="container"><h1>My profile</h1></div></section>
<section class="section portal">
	<div class="container">
		<?php get_template_part( 'template-parts/student-nav', null, array( 'current' => 'profile' ) ); ?>
		<?php if ( $force_change ) : ?>
			<p class="notice-box" role="alert" id="portal-force-change">For your security, please set a new password before continuing.</p>
		<?php endif; ?>
		<p class="portal-status" id="portal-status" role="status" aria-live="polite"></p>

		<div class="portal-grid portal-grid--info">
			<section class="card card--pad" aria-labelledby="pf-id-h">
				<h2 id="pf-id-h">Your details</h2>
				<dl class="receipt__list">
					<dt>Name</dt><dd><?php echo esc_html( $profile['full_name'] ); ?></dd>
					<dt>Mobile number</dt><dd><?php echo esc_html( $profile['phone'] ); ?></dd>
				</dl>
				<p class="muted small">To change your name, please contact us.</p>
			</section>

			<section class="card card--pad" aria-labelledby="pf-phone-h">
				<h2 id="pf-phone-h">Change mobile number</h2>
				<p class="muted small">This number is how you sign in. We text a code to the new number to confirm it.</p>
				<form id="portal-phone-form" novalidate>
					<div data-step="request">
						<?php
						portal_field( 'phone', 'New mobile number', 'tel', '', array( 'inputmode' => 'tel', 'autocomplete' => 'tel', 'required' => 'required' ) );
						if ( ! CC_Rest_Auth::otp_recent( $user->ID ) ) {
							// Own name and id: the password form further down has a field called current_password.
							portal_field( 'phone_password', 'Current password', 'password', '', array( 'autocomplete' => 'current-password', 'required' => 'required' ) );
						}
						?>
						<button type="submit" class="btn btn--primary">Send code</button>
					</div>
					<div data-step="confirm" hidden>
						<?php portal_field( 'code', '6-digit code we sent', 'text', '', array( 'inputmode' => 'numeric', 'autocomplete' => 'one-time-code', 'maxlength' => '6' ) ); ?>
						<button type="submit" class="btn btn--primary">Confirm new number</button>
						<button type="button" class="btn btn--ghost" data-phone-back>Use a different number</button>
					</div>
				</form>
			</section>

			<section class="card card--pad" aria-labelledby="pf-contact-h">
				<h2 id="pf-contact-h">Guardian and email</h2>
				<form id="portal-profile-form" novalidate>
					<?php
					portal_field( 'guardian_name', 'Guardian name', 'text', $profile['guardian_name'], array( 'maxlength' => '190', 'required' => 'required' ) );
					portal_field( 'guardian_phone', 'Guardian mobile number', 'tel', $profile['guardian_phone'], array( 'inputmode' => 'tel', 'required' => 'required' ) );
					portal_field( 'email', 'Email (optional)', 'email', $profile['email'], array( 'autocomplete' => 'email', 'maxlength' => '190' ) );
					?>
					<button type="submit" class="btn btn--primary">Save changes</button>
				</form>
			</section>

			<section class="card card--pad" aria-labelledby="pf-pw-h">
				<h2 id="pf-pw-h">Change password</h2>
				<form id="portal-password-form" novalidate>
					<?php
					if ( ! CC_Rest_Auth::otp_recent( $user->ID ) ) {
						portal_field( 'current_password', 'Current password', 'password', '', array( 'autocomplete' => 'current-password', 'required' => 'required' ) );
					}
					portal_field( 'new_password', 'New password (at least 10 characters)', 'password', '', array( 'autocomplete' => 'new-password', 'minlength' => '10', 'required' => 'required' ) );
					?>
					<button type="submit" class="btn btn--primary">Update password</button>
				</form>
			</section>
		</div>
	</div>
</section>
<?php get_footer(); ?>
