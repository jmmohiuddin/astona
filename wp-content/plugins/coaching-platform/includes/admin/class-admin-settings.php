<?php
defined( 'ABSPATH' ) || exit;

/** Institution contact details (fed to the theme via astona_contact) and a read-only system status panel. */
final class CC_Admin_Settings {

	const OPTION   = 'cc_settings';
	const ACTION   = 'cc_save_settings';
	const MAX_ADDR = 300;
	const MAX_NAME = 120;

	public static function init(): void {
		add_filter( 'astona_contact', array( __CLASS__, 'filter_contact' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_save' ) );
	}

	/** @return array{name:string,phone:string,email:string,address:string} */
	public static function get(): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		return array(
			'name'    => (string) ( $stored['name'] ?? '' ),
			'phone'   => (string) ( $stored['phone'] ?? '' ),
			'email'   => (string) ( $stored['email'] ?? '' ),
			'address' => (string) ( $stored['address'] ?? '' ),
		);
	}

	/** Non-empty settings override the theme defaults. */
	public static function filter_contact( $contact ): array {
		$contact = is_array( $contact ) ? $contact : array();
		foreach ( self::get() as $key => $value ) {
			if ( '' !== $value ) {
				$contact[ $key ] = $value;
			}
		}
		return $contact;
	}

	/** @param array<string,mixed> $raw Unslashed input. */
	public static function sanitize( array $raw ): array {
		$email = sanitize_email( (string) ( $raw['email'] ?? '' ) );
		return array(
			'name'    => mb_substr( sanitize_text_field( (string) ( $raw['name'] ?? '' ) ), 0, self::MAX_NAME ),
			'phone'   => trim( (string) preg_replace( '/[^0-9+\-\s()]/', '', (string) ( $raw['phone'] ?? '' ) ) ),
			'email'   => is_email( $email ) ? $email : '',
			'address' => mb_substr( sanitize_textarea_field( (string) ( $raw['address'] ?? '' ) ), 0, self::MAX_ADDR ),
		);
	}

	public static function handle_save(): void {
		if ( ! current_user_can( CC_Admin_Roles::CAP_MANAGE_SETTINGS ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'coaching-platform' ), 403 );
		}
		check_admin_referer( self::ACTION );

		$before = self::get();
		$after  = self::sanitize( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised field by field.
		update_option( self::OPTION, $after, false );

		$changed = array_keys( array_diff_assoc( $after, $before ) );
		CC_Audit::log( 'settings.update', 'settings', 0, array( 'changed' => $changed ), implode( ',', $changed ) );

		wp_safe_redirect( add_query_arg( array( 'page' => 'cc-settings', 'cc_notice' => 'saved' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/** Labels and states only; never keys, secrets or filesystem paths. @return array<string,string> */
	public static function status(): array {
		$gateway  = class_exists( 'CC_Gateway_Factory' ) ? CC_Gateway_Factory::configured_name() : '';
		$primary  = class_exists( 'CC_Sms_Factory' ) ? CC_Sms_Factory::configured_name( 'primary' ) : '';
		$fallback = class_exists( 'CC_Sms_Factory' ) ? CC_Sms_Factory::configured_name( 'fallback' ) : '';
		$next_run = function_exists( 'as_next_scheduled_action' ) ? as_next_scheduled_action( CC_Reconciler::HOOK ) : wp_next_scheduled( CC_Reconciler::HOOK );
		$dir      = class_exists( 'CC_Photo_Store' ) ? CC_Photo_Store::base_dir() : null;
		$has_key  = defined( 'CC_ENC_KEY' ) ? '' !== (string) CC_ENC_KEY : '' !== (string) getenv( 'CC_ENC_KEY' );

		if ( $has_key ) {
			$key_state = 'Configured';
		} else {
			$key_state = 'local' === wp_get_environment_type() ? 'Not set (local dev key in use)' : 'NOT CONFIGURED';
		}

		$have     = static fn( string $name ): bool => '' !== trim( defined( $name ) ? (string) constant( $name ) : (string) getenv( $name ) );
		$bkash_ok = $have( 'BKASH_APP_KEY' ) && $have( 'BKASH_APP_SECRET' ) && $have( 'BKASH_USERNAME' ) && $have( 'BKASH_PASSWORD' ) && in_array( strtolower( trim( defined( 'BKASH_MODE' ) ? (string) BKASH_MODE : (string) getenv( 'BKASH_MODE' ) ) ), array( 'sandbox', 'live' ), true );

		$rows = array(
			'Payment gateway'       => '' === $gateway ? 'Not configured' : $gateway,
			'SMS primary driver'    => '' === $primary ? 'Not configured' : $primary,
			'SMS fallback driver'   => '' === $fallback ? 'None' : $fallback,
			'Action Scheduler'      => function_exists( 'as_schedule_recurring_action' ) ? 'Present' : 'Not installed (WP-Cron fallback)',
			'Reconciler next run'   => $next_run ? wp_date( 'Y-m-d H:i:s', (int) $next_run ) : 'Not scheduled',
			'Private directory'     => null !== $dir && is_dir( $dir ) && is_writable( $dir ) ? 'Writable' : 'Missing or not writable',
			'Encryption key (ENC)'  => $key_state,
			'Analytics (GA4)'       => $have( 'GA4_MEASUREMENT_ID' ) ? ( $have( 'GA4_API_SECRET' ) ? 'Browser and server events' : 'Browser events only' ) : 'Off',
		);
		if ( 'bkash' === $gateway ) {
			$rows['bKash credentials'] = $bkash_ok ? 'Configured' : 'NOT CONFIGURED';
		}
		return $rows;
	}

	public static function render(): void {
		if ( ! current_user_can( CC_Admin_Roles::CAP_MANAGE_SETTINGS ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'coaching-platform' ), 403 );
		}
		$values = self::get();
		$saved  = isset( $_GET['cc_notice'] ) && 'saved' === sanitize_key( wp_unslash( $_GET['cc_notice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification -- display only.
		?>
		<div class="wrap">
			<h1>Astona settings</h1>
			<?php if ( $saved ) : ?>
				<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>
			<?php endif; ?>
			<form class="cc-form cc-panel" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<?php wp_nonce_field( self::ACTION ); ?>
				<h2>Institution</h2>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><label for="cc-name">Name</label></th><td><input id="cc-name" name="name" type="text" class="regular-text" maxlength="<?php echo (int) self::MAX_NAME; ?>" value="<?php echo esc_attr( $values['name'] ); ?>"></td></tr>
					<tr><th scope="row"><label for="cc-phone">Phone</label></th><td><input id="cc-phone" name="phone" type="text" class="regular-text" value="<?php echo esc_attr( $values['phone'] ); ?>"></td></tr>
					<tr><th scope="row"><label for="cc-email">Email</label></th><td><input id="cc-email" name="email" type="email" class="regular-text" value="<?php echo esc_attr( $values['email'] ); ?>"></td></tr>
					<tr><th scope="row"><label for="cc-address">Address</label></th><td><textarea id="cc-address" name="address" rows="3" class="large-text" maxlength="<?php echo (int) self::MAX_ADDR; ?>"><?php echo esc_textarea( $values['address'] ); ?></textarea></td></tr>
				</table>
				<?php submit_button( 'Save settings' ); ?>
			</form>
			<div class="cc-panel">
				<h2>System status</h2>
				<table class="cc-table">
					<?php foreach ( self::status() as $label => $state ) : ?>
						<tr><th scope="row"><?php echo esc_html( $label ); ?></th><td><?php echo esc_html( $state ); ?></td></tr>
					<?php endforeach; ?>
				</table>
			</div>
		</div>
		<?php
	}
}
