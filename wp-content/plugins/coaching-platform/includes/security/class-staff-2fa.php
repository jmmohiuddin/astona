<?php
defined( 'ABSPATH' ) || exit;

/**
 * Mandatory TOTP two-factor sign-in for cc_owner, cc_staff and administrator (TRD FR-011, SEC-001).
 *
 * Login: a correct password is not enough once a user has enrolled; the authenticator code (or a one-time recovery
 * code) must accompany it, attempts are rate limited per user and per IP, and an accepted code cannot be replayed.
 * Enrolment: staff who have not enrolled can sign in but every admin screen redirects to Astona > Security until they do.
 * Secrets are encrypted with CC_Crypto; recovery codes are stored as salted hashes and shown once.
 * Local development can relax it with CC_STAFF_SECURITY_RELAXED=1 (ignored outside WP_ENVIRONMENT_TYPE=local).
 */
final class CC_Staff_2fa {

	const PAGE             = 'cc-security';
	const FIELD            = 'cc_totp';
	const META_SECRET      = 'cc_totp_secret';
	const META_PENDING     = 'cc_totp_pending';
	const META_LAST_STEP   = 'cc_totp_last_step';
	const META_RECOVERY    = 'cc_totp_recovery';
	const ACTION_ENABLE    = 'cc_2fa_enable';
	const ACTION_RECOVERY  = 'cc_2fa_recovery';
	const RECOVERY_COUNT   = 8;
	const PENDING_SECONDS  = 900;
	const ATTEMPT_LIMIT    = 5;
	const ATTEMPT_WINDOW   = 900;
	const IP_LIMIT         = 20;

	/** True when this request's login error is one of ours, so the uniform-error filter in cc-hardening lets it through. */
	public static bool $show_message = false;

	public static function init(): void {
		add_action( 'login_form', array( __CLASS__, 'render_login_field' ) );
		add_filter( 'authenticate', array( __CLASS__, 'filter_authenticate' ), 50, 3 );
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'gate' ), 2 );
		add_filter( 'rest_authentication_errors', array( __CLASS__, 'gate_rest' ), 120 );
		add_action( 'admin_post_' . self::ACTION_ENABLE, array( __CLASS__, 'handle_enable' ) );
		add_action( 'admin_post_' . self::ACTION_RECOVERY, array( __CLASS__, 'handle_recovery' ) );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'cc 2fa-reset', array( __CLASS__, 'cli_reset' ) );
		}
	}

	/* ---------------------------------------------------------------- policy */

	public static function relaxed(): bool {
		return '1' === getenv( 'CC_STAFF_SECURITY_RELAXED' ) && 'local' === wp_get_environment_type();
	}

	/** @return string[] */
	public static function required_roles(): array {
		return (array) apply_filters( 'cc_2fa_roles', array( CC_Admin_Roles::ROLE_OWNER, CC_Admin_Roles::ROLE_STAFF, 'administrator' ) );
	}

	public static function required_for( WP_User $user ): bool {
		return ! self::relaxed() && (bool) array_intersect( self::required_roles(), (array) $user->roles );
	}

	public static function is_enrolled( int $user_id ): bool {
		return '' !== (string) get_user_meta( $user_id, self::META_SECRET, true );
	}

	/* ---------------------------------------------------------------- login */

	public static function render_login_field(): void {
		if ( self::relaxed() ) {
			return;
		}
		echo '<p><label for="' . esc_attr( self::FIELD ) . '">' . esc_html__( 'Authenticator code (staff)', 'coaching-platform' ) . '</label>';
		echo '<input type="text" name="' . esc_attr( self::FIELD ) . '" id="' . esc_attr( self::FIELD ) . '" class="input" value="" size="20" autocomplete="one-time-code" inputmode="numeric" spellcheck="false"></p>';
	}

	/**
	 * @param WP_User|WP_Error|null $user
	 * @return WP_User|WP_Error|null
	 */
	public static function filter_authenticate( $user, $username = '', $password = '' ) {
		if ( ! $user instanceof WP_User || ! self::required_for( $user ) || ! self::is_enrolled( $user->ID ) ) {
			return $user;
		}
		$code = isset( $_POST[ self::FIELD ] ) ? trim( sanitize_text_field( wp_unslash( $_POST[ self::FIELD ] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- login form; the password was verified just before this filter.
		self::$show_message = true;
		if ( '' === $code ) {
			return new WP_Error( 'cc_totp_required', __( '<strong>Error:</strong> Enter the code from your authenticator app.', 'coaching-platform' ) );
		}
		$result = self::verify_login( $user->ID, $code );
		if ( 'ok' === $result ) {
			self::$show_message = false;
			return $user;
		}
		if ( 'limited' === $result ) {
			return new WP_Error( 'cc_totp_limited', __( '<strong>Error:</strong> Too many attempts. Try again in a few minutes.', 'coaching-platform' ) );
		}
		return new WP_Error( 'cc_totp_invalid', __( '<strong>Error:</strong> That code is not valid.', 'coaching-platform' ) );
	}

	/**
	 * Checks a TOTP code or recovery code for a user whose password was already accepted.
	 *
	 * @return string 'ok', 'invalid' or 'limited'
	 */
	public static function verify_login( int $user_id, string $code, ?int $now = null ): string {
		$now = $now ?? time();
		if ( ! CC_Rate_Limiter::allow( 'totp_user|' . $user_id, self::ATTEMPT_LIMIT, self::ATTEMPT_WINDOW )
			|| ! CC_Rate_Limiter::allow( 'totp_ip|' . CC_Rate_Limiter::ip_bucket_key(), self::IP_LIMIT, self::ATTEMPT_WINDOW ) ) {
			return 'limited';
		}
		try {
			$secret = CC_Crypto::decrypt( (string) get_user_meta( $user_id, self::META_SECRET, true ) );
		} catch ( RuntimeException $e ) {
			error_log( 'CC_Staff_2fa: cannot decrypt the secret for user ' . $user_id );
			return 'invalid';
		}
		$step = CC_Totp::verify( $secret, $code, $now, (int) get_user_meta( $user_id, self::META_LAST_STEP, true ) );
		if ( null !== $step ) {
			update_user_meta( $user_id, self::META_LAST_STEP, $step );
			return 'ok';
		}
		return self::consume_recovery_code( $user_id, $code ) ? 'ok' : 'invalid';
	}

	/* ---------------------------------------------------------------- recovery codes */

	/** @return string[] The plain codes, shown once. Only salted hashes are stored. */
	public static function issue_recovery_codes( int $user_id ): array {
		$plain  = array();
		$hashes = array();
		for ( $i = 0; $i < self::RECOVERY_COUNT; $i++ ) {
			$raw      = substr( CC_Totp::base32_encode( random_bytes( 8 ) ), 0, 10 );
			$plain[]  = substr( $raw, 0, 5 ) . '-' . substr( $raw, 5 );
			$hashes[] = self::hash_recovery( $raw );
		}
		update_user_meta( $user_id, self::META_RECOVERY, $hashes );
		return $plain;
	}

	private static function consume_recovery_code( int $user_id, string $input ): bool {
		$raw = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', $input ) );
		if ( 10 !== strlen( $raw ) ) {
			return false;
		}
		$hash   = self::hash_recovery( $raw );
		$stored = get_user_meta( $user_id, self::META_RECOVERY, true );
		foreach ( is_array( $stored ) ? $stored : array() as $i => $candidate ) {
			if ( hash_equals( (string) $candidate, $hash ) ) {
				unset( $stored[ $i ] );
				update_user_meta( $user_id, self::META_RECOVERY, array_values( $stored ) );
				CC_Audit::log( 'staff.2fa_recovery_used', 'user', $user_id );
				return true;
			}
		}
		return false;
	}

	private static function hash_recovery( string $raw ): string {
		return hash_hmac( 'sha256', $raw, wp_salt( 'auth' ) );
	}

	public static function recovery_remaining( int $user_id ): int {
		$stored = get_user_meta( $user_id, self::META_RECOVERY, true );
		return is_array( $stored ) ? count( $stored ) : 0;
	}

	/* ---------------------------------------------------------------- enrolment */

	/** Starts (or resumes) enrolment and returns the pending base32 secret. */
	public static function begin_enrolment( int $user_id ): string {
		$pending = get_user_meta( $user_id, self::META_PENDING, true );
		if ( is_array( $pending ) && (int) ( $pending['expires'] ?? 0 ) > time() ) {
			try {
				return CC_Crypto::decrypt( (string) $pending['secret'] );
			} catch ( RuntimeException $e ) {
				// Fall through and issue a new one.
			}
		}
		$secret = CC_Totp::generate_secret();
		update_user_meta( $user_id, self::META_PENDING, array( 'secret' => CC_Crypto::encrypt( $secret ), 'expires' => time() + self::PENDING_SECONDS ) );
		return $secret;
	}

	/**
	 * Confirms enrolment with a code from the app.
	 *
	 * @return string[]|WP_Error Recovery codes (shown once) or an error.
	 */
	public static function complete_enrolment( int $user_id, string $code, ?int $now = null ) {
		$pending = get_user_meta( $user_id, self::META_PENDING, true );
		if ( ! is_array( $pending ) || (int) ( $pending['expires'] ?? 0 ) <= ( $now ?? time() ) ) {
			return new WP_Error( 'cc_2fa_expired', 'Setup expired. Start again.' );
		}
		if ( ! CC_Rate_Limiter::allow( 'totp_enrol|' . $user_id, self::ATTEMPT_LIMIT, self::ATTEMPT_WINDOW ) ) {
			return new WP_Error( 'cc_2fa_limited', 'Too many attempts. Try again in a few minutes.' );
		}
		$secret = CC_Crypto::decrypt( (string) $pending['secret'] );
		$step   = CC_Totp::verify( $secret, $code, $now ?? time() );
		if ( null === $step ) {
			return new WP_Error( 'cc_2fa_invalid', 'That code is not valid. Check the time on your phone and try again.' );
		}
		update_user_meta( $user_id, self::META_SECRET, CC_Crypto::encrypt( $secret ) );
		update_user_meta( $user_id, self::META_LAST_STEP, $step );
		delete_user_meta( $user_id, self::META_PENDING );
		$codes = self::issue_recovery_codes( $user_id );
		CC_Audit::log( 'staff.2fa_enabled', 'user', $user_id );
		return $codes;
	}

	/** Owner/CLI reset for a locked-out colleague; the user must enrol again at next sign-in. */
	public static function reset( int $user_id ): void {
		foreach ( array( self::META_SECRET, self::META_PENDING, self::META_LAST_STEP, self::META_RECOVERY ) as $meta ) {
			delete_user_meta( $user_id, $meta );
		}
		CC_Audit::log( 'staff.2fa_reset', 'user', $user_id );
	}

	/** wp cc 2fa-reset <user-login> */
	public static function cli_reset( array $args ): void {
		$user = get_user_by( 'login', (string) ( $args[0] ?? '' ) );
		if ( ! $user ) {
			WP_CLI::error( 'No such user.' );
		}
		self::reset( $user->ID );
		WP_CLI::success( 'Two-factor reset. The user must enrol again at next sign-in.' );
	}

	/* ---------------------------------------------------------------- gating */

	public static function gate(): void {
		global $pagenow;
		$user = wp_get_current_user();
		if ( ! $user->exists() || ! self::required_for( $user ) || self::is_enrolled( $user->ID ) || wp_doing_ajax() ) {
			return;
		}
		$on_setup = 'admin.php' === $pagenow && self::PAGE === ( $_GET['page'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification -- routing only.
		$posting  = 'admin-post.php' === $pagenow && in_array( $_REQUEST['action'] ?? '', array( self::ACTION_ENABLE ), true ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( $on_setup || $posting || 'admin-post.php' === $pagenow && 'logout' === ( $_REQUEST['action'] ?? '' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE ) );
		exit;
	}

	/** @param WP_Error|null|bool $result */
	public static function gate_rest( $result ) {
		$user = wp_get_current_user();
		if ( $user->exists() && self::required_for( $user ) && ! self::is_enrolled( $user->ID ) ) {
			return new WP_Error( 'cc_2fa_required', 'Set up two-factor authentication first.', array( 'status' => 403 ) );
		}
		return $result;
	}

	/* ---------------------------------------------------------------- screens */

	public static function register_page(): void {
		// Hidden from the menu (no parent); reachable by URL and by the redirect above. Needs only "read".
		add_submenu_page( '', __( 'Security', 'coaching-platform' ), '', 'read', self::PAGE, array( __CLASS__, 'render' ) );
	}

	public static function render(): void {
		$user = wp_get_current_user();
		echo '<div class="wrap"><h1>' . esc_html__( 'Two-factor authentication', 'coaching-platform' ) . '</h1>';
		self::render_notice();
		$codes = get_transient( 'cc_2fa_codes_' . $user->ID );
		if ( is_array( $codes ) ) {
			delete_transient( 'cc_2fa_codes_' . $user->ID );
			echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Save these recovery codes now. Each works once if you lose your phone. They will not be shown again.', 'coaching-platform' ) . '</strong></p><p><code>' . esc_html( implode( '   ', $codes ) ) . '</code></p></div>';
		}
		if ( self::is_enrolled( $user->ID ) ) {
			printf( '<p>%s</p>', esc_html( sprintf( /* translators: %d: codes left */ __( 'Two-factor authentication is on. Recovery codes left: %d.', 'coaching-platform' ), self::recovery_remaining( $user->ID ) ) ) );
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="' . esc_attr( self::ACTION_RECOVERY ) . '">';
			wp_nonce_field( self::ACTION_RECOVERY );
			echo '<p><label for="cc-2fa-code">' . esc_html__( 'Current code (to make new recovery codes)', 'coaching-platform' ) . '</label><br><input id="cc-2fa-code" name="code" class="regular-text" inputmode="numeric" autocomplete="one-time-code"></p>';
			submit_button( __( 'Make new recovery codes', 'coaching-platform' ), 'secondary' );
			echo '</form></div>';
			return;
		}
		$secret = self::begin_enrolment( $user->ID );
		$uri    = CC_Totp::uri( $secret, $user->user_login, (string) get_bloginfo( 'name' ) );
		echo '<p>' . esc_html__( 'Staff accounts must use an authenticator app (Google Authenticator, Microsoft Authenticator, Authy). Add this account, then enter the 6-digit code to finish.', 'coaching-platform' ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Key:', 'coaching-platform' ) . '</strong> <code>' . esc_html( trim( chunk_split( $secret, 4, ' ' ) ) ) . '</code><br>';
		echo '<a href="' . esc_url( $uri, array( 'otpauth' ) ) . '">' . esc_html__( 'Open in authenticator app (on the same phone)', 'coaching-platform' ) . '</a></p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="' . esc_attr( self::ACTION_ENABLE ) . '">';
		wp_nonce_field( self::ACTION_ENABLE );
		echo '<p><label for="cc-2fa-code">' . esc_html__( 'Code from the app', 'coaching-platform' ) . '</label><br><input id="cc-2fa-code" name="code" class="regular-text" inputmode="numeric" autocomplete="one-time-code" required></p>';
		submit_button( __( 'Turn on two-factor', 'coaching-platform' ) );
		echo '</form></div>';
	}

	public static function handle_enable(): void {
		check_admin_referer( self::ACTION_ENABLE );
		$user   = wp_get_current_user();
		$result = self::complete_enrolment( $user->ID, sanitize_text_field( wp_unslash( $_POST['code'] ?? '' ) ) );
		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE, 'cc_notice' => $result->get_error_code() ), admin_url( 'admin.php' ) ) );
			exit;
		}
		set_transient( 'cc_2fa_codes_' . $user->ID, $result, 300 );
		wp_safe_redirect( add_query_arg( 'page', self::PAGE, admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function handle_recovery(): void {
		check_admin_referer( self::ACTION_RECOVERY );
		$user = wp_get_current_user();
		if ( ! self::is_enrolled( $user->ID ) || 'ok' !== self::verify_login( $user->ID, sanitize_text_field( wp_unslash( $_POST['code'] ?? '' ) ) ) ) {
			wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE, 'cc_notice' => 'cc_2fa_invalid' ), admin_url( 'admin.php' ) ) );
			exit;
		}
		set_transient( 'cc_2fa_codes_' . $user->ID, self::issue_recovery_codes( $user->ID ), 300 );
		CC_Audit::log( 'staff.2fa_recovery_reissued', 'user', $user->ID );
		wp_safe_redirect( add_query_arg( 'page', self::PAGE, admin_url( 'admin.php' ) ) );
		exit;
	}

	private static function render_notice(): void {
		$messages = array(
			'cc_2fa_invalid' => 'That code is not valid. Check the time on your phone and try again.',
			'cc_2fa_expired' => 'Setup expired. Start again with the new key below.',
			'cc_2fa_limited' => 'Too many attempts. Try again in a few minutes.',
		);
		$code = isset( $_GET['cc_notice'] ) ? sanitize_key( wp_unslash( $_GET['cc_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- display only, whitelisted.
		if ( isset( $messages[ $code ] ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $messages[ $code ] ) . '</p></div>';
		}
	}
}
