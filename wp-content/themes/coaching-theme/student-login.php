<?php
/**
 * Student login: phone + password, or a one-time code sent by SMS. Behaviour lives in assets/js/student-login.js.
 */
CC_Student_Guard::send_no_store();
if ( is_user_logged_in() && CC_Student_Guard::is_student( wp_get_current_user() ) ) {
	wp_safe_redirect( home_url( '/student/' ) );
	exit;
}
get_header();
?>
<main id="main" class="section" tabindex="-1">
	<div class="container sl-wrap">
		<h1 class="sl-title">Student login</h1>
		<p class="muted">Use the mobile number you enrolled with. Your login details were sent to you by SMS.</p>

		<form id="sl-form" class="sl-card" novalidate data-mode="password">
			<div class="sl-field">
				<label for="sl-phone">Mobile number</label>
				<input type="tel" id="sl-phone" name="phone" inputmode="tel" autocomplete="username" placeholder="01XXXXXXXXX" required aria-describedby="sl-error">
			</div>

			<div class="sl-field" id="sl-password-field">
				<label for="sl-password">Password</label>
				<input type="password" id="sl-password" name="password" autocomplete="current-password" required aria-describedby="sl-error">
			</div>

			<div class="sl-field" id="sl-code-field" hidden>
				<label for="sl-code">6-digit code</label>
				<input type="text" id="sl-code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="[0-9]{6}" aria-describedby="sl-error sl-code-hint">
				<p class="small muted" id="sl-code-hint">The code is valid for 5 minutes.</p>
			</div>

			<div class="sl-check">
				<input type="checkbox" id="sl-remember" name="remember" value="1">
				<label for="sl-remember">Keep me signed in for 14 days</label>
			</div>

			<p class="sl-error" id="sl-error" role="alert" aria-live="assertive"></p>
			<p class="sl-status" id="sl-status" aria-live="polite"></p>

			<button type="submit" class="btn btn--primary btn--block" id="sl-submit">Log in</button>
			<div class="sl-links">
				<button type="button" class="sl-link" id="sl-toggle" aria-controls="sl-form">Login with OTP instead</button>
				<button type="button" class="sl-link" id="sl-resend" hidden>Send a new code</button>
			</div>
		</form>

		<p class="small muted">Trouble logging in? Contact us and we will help you.</p>
	</div>
</main>
<?php
get_footer();
