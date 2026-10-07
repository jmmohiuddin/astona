<?php
defined( 'ABSPATH' ) || exit;

/**
 * Versioned schema migrations. Forward-compatible (expand-only) per SDD §4.2.
 */
final class CC_Migrations {

	const VERSION = '9';
	const OPTION  = 'cc_db_version';
	const LOCK    = 'cc_migrations';

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'cc_batches';
	}

	public static function maybe_upgrade(): void {
		global $wpdb;
		if ( get_option( self::OPTION ) === self::VERSION ) {
			return;
		}
		if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', self::LOCK ) ) ) {
			return; // another request is migrating; this one carries on with the old schema.
		}
		try {
			// Read the stored version from the database: the options cache may predate the other request's finish.
			$stored = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::OPTION ) );
			if ( $stored !== self::VERSION ) {
				self::run();
			}
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::LOCK ) );
		}
	}

	public static function run(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			course_id BIGINT UNSIGNED NOT NULL,
			name VARCHAR(190) NOT NULL,
			delivery_mode ENUM('physical','online','hybrid') NOT NULL DEFAULT 'physical',
			branch_id BIGINT UNSIGNED NULL,
			capacity SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			seats_taken SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			price DECIMAL(10,2) NOT NULL DEFAULT 0,
			currency CHAR(3) NOT NULL DEFAULT 'BDT',
			start_date DATE NOT NULL,
			end_date DATE NULL,
			schedule_text VARCHAR(255) NULL,
			status ENUM('draft','open','closed','completed') NOT NULL DEFAULT 'draft',
			application_open TINYINT(1) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY course_status (course_id,status),
			KEY status_start (status,start_date)
			) {$charset};"
		);

		self::run_v2( $wpdb->prefix, $charset );
		self::run_v3( $wpdb->prefix, $charset );
		self::run_v4( $wpdb->prefix, $charset );
		self::run_v5( $wpdb->prefix, $charset );
		self::run_v6( $wpdb->prefix, $charset );
		self::run_v7( $wpdb->prefix );
		self::run_v8();
		self::run_v9( $wpdb->prefix );

		update_option( self::OPTION, self::VERSION );
	}

	/**
	 * v9: cc_applications.phone_verified_at — set when the student phone was proven (OTP) at submit time. NULL means the
	 * application predates phone proof (or was created by a path that did not prove it): the provisioner must not attach
	 * such an application to an EXISTING student account. The v2 CREATE TABLE lists the column too (dbDelta adds it); the
	 * explicit guarded ALTER makes the upgrade deterministic.
	 */
	private static function run_v9( string $p ): void {
		global $wpdb;
		$exists = $wpdb->get_row( "SHOW COLUMNS FROM {$p}cc_applications LIKE 'phone_verified_at'", ARRAY_A );
		if ( ! is_array( $exists ) ) {
			$wpdb->query( "ALTER TABLE {$p}cc_applications ADD COLUMN phone_verified_at DATETIME NULL AFTER consent_at" );
		}
	}

	/**
	 * v7: cc_otp.purpose gains 'apply' (phone ownership proof at application). Expand-only: the ENUM only grows.
	 * dbDelta is not relied on for the ENUM change, so the ALTER is explicit and guarded (idempotent). The v3 CREATE TABLE
	 * above lists the final ENUM too, so a later dbDelta run never tries to shrink it back.
	 */
	private static function run_v7( string $p ): void {
		global $wpdb;
		$column = $wpdb->get_row( "SHOW COLUMNS FROM {$p}cc_otp LIKE 'purpose'", ARRAY_A );
		if ( is_array( $column ) && false === strpos( (string) $column['Type'], "'apply'" ) ) {
			$wpdb->query( "ALTER TABLE {$p}cc_otp MODIFY purpose ENUM('login','phone_change','reset','apply') NOT NULL DEFAULT 'login'" );
		}
	}

	/**
	 * v8: result photos move from public attachments (`cc_result_photo`) into the private store. Idempotent. The db version
	 * is bumped regardless of the outcome (so the upgrade does not rerun on every request); what is left is tracked by the
	 * `cc_result_photo_migration_pending` option and retried by an hourly cron event until it is empty. More than a few
	 * photos are left to that cron instead of delaying the request that triggered the upgrade. Manual run:
	 * `wp eval 'CC_Result_Photo_Migration::run();'`.
	 */
	private static function run_v8(): void {
		if ( ! class_exists( 'CC_Result_Photo_Migration' ) ) {
			return;
		}
		if ( CC_Result_Photo_Migration::remaining_count() > CC_Result_Photo_Migration::INLINE_LIMIT ) {
			CC_Result_Photo_Migration::defer();
			return;
		}
		CC_Result_Photo_Migration::run();
	}

	/** v6: contact inquiries (SDD §4.2 V1). Expand-only. */
	private static function run_v6( string $p, string $charset ): void {
		dbDelta(
			"CREATE TABLE {$p}cc_inquiries (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(190) NOT NULL,
			phone VARCHAR(16) NOT NULL,
			email VARCHAR(190) NULL,
			topic VARCHAR(40) NOT NULL,
			course_id BIGINT UNSIGNED NULL,
			message TEXT NOT NULL,
			status ENUM('new','handled','spam') NOT NULL DEFAULT 'new',
			handled_by BIGINT UNSIGNED NULL,
			handled_at DATETIME NULL,
			staff_note VARCHAR(500) NULL,
			ip_hash CHAR(64) NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY status_created (status,created_at),
			KEY phone (phone)
			) {$charset};"
		);
	}

	/** v5: course content, live classes, join log, notice targets (SDD §4.2). Expand-only. */
	private static function run_v5( string $p, string $charset ): void {
		dbDelta(
			"CREATE TABLE {$p}cc_modules (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			batch_id BIGINT UNSIGNED NOT NULL,
			title VARCHAR(190) NOT NULL,
			sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY batch_sort (batch_id,sort_order)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$p}cc_lessons (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			module_id BIGINT UNSIGNED NOT NULL,
			title VARCHAR(190) NOT NULL,
			scheduled_at DATETIME NULL,
			sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			attachment_path VARCHAR(255) NULL,
			attachment_name VARCHAR(190) NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY module_sort (module_id,sort_order),
			KEY scheduled_at (scheduled_at)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$p}cc_live_classes (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			batch_id BIGINT UNSIGNED NOT NULL,
			lesson_id BIGINT UNSIGNED NULL,
			title VARCHAR(190) NOT NULL,
			starts_at DATETIME NOT NULL,
			ends_at DATETIME NOT NULL,
			provider ENUM('meet','zoom','other') NOT NULL DEFAULT 'meet',
			meeting_url_enc VARBINARY(1024) NOT NULL,
			created_by BIGINT UNSIGNED NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY batch_starts (batch_id,starts_at)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$p}cc_join_log (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			live_class_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			joined_at DATETIME NOT NULL,
			ip_hash CHAR(64) NULL,
			PRIMARY KEY  (id),
			KEY live_class_id (live_class_id),
			KEY user_joined (user_id,joined_at)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$p}cc_notice_targets (
			notice_id BIGINT UNSIGNED NOT NULL,
			batch_id BIGINT UNSIGNED NOT NULL,
			PRIMARY KEY  (notice_id,batch_id),
			KEY batch_id (batch_id)
			) {$charset};"
		);
	}

	/** v4: audit log + instructor batch scope (SDD §4.2). Expand-only. */
	private static function run_v4( string $p, string $charset ): void {
		dbDelta(
			"CREATE TABLE {$p}cc_audit_log (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			actor_id BIGINT UNSIGNED NOT NULL,
			action VARCHAR(60) NOT NULL,
			entity_type VARCHAR(40) NOT NULL,
			entity_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			diff_hash CHAR(64) NULL,
			note VARCHAR(255) NULL,
			ip_hash CHAR(64) NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY entity (entity_type,entity_id),
			KEY actor_created (actor_id,created_at),
			KEY created_at (created_at)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$p}cc_batch_staff (
			user_id BIGINT UNSIGNED NOT NULL,
			batch_id BIGINT UNSIGNED NOT NULL,
			PRIMARY KEY  (user_id,batch_id),
			KEY batch_id (batch_id)
			) {$charset};"
		);
	}

	/** v3: student accounts, enrollments, OTP, SMS log (SDD §4.2). Expand-only. */
	private static function run_v3( string $p, string $charset ): void {
		dbDelta(
			"CREATE TABLE {$p}cc_students (
			user_id BIGINT UNSIGNED NOT NULL,
			full_name VARCHAR(190) NOT NULL,
			guardian_name VARCHAR(190) NULL,
			guardian_phone VARCHAR(16) NULL,
			institution VARCHAR(190) NULL,
			photo_path VARCHAR(255) NULL,
			must_change_pw TINYINT(1) NOT NULL DEFAULT 0,
			temp_pw_expires DATETIME NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (user_id)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$p}cc_enrollments (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			batch_id BIGINT UNSIGNED NOT NULL,
			application_id BIGINT UNSIGNED NOT NULL,
			status ENUM('active','expired','deactivated','completed') NOT NULL DEFAULT 'active',
			enrolled_at DATETIME NOT NULL,
			expires_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY user_batch (user_id,batch_id),
			UNIQUE KEY application_id (application_id),
			KEY batch_status (batch_id,status)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$p}cc_otp (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			phone VARCHAR(16) NOT NULL,
			code_hash CHAR(64) NOT NULL,
			purpose ENUM('login','phone_change','reset','apply') NOT NULL DEFAULT 'login',
			attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
			expires_at DATETIME NOT NULL,
			used_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY phone_expires (phone,expires_at)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$p}cc_sms_log (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			to_phone VARCHAR(16) NOT NULL,
			template VARCHAR(40) NOT NULL,
			body_hash CHAR(64) NOT NULL,
			body_enc VARBINARY(1024) NULL,
			segments TINYINT UNSIGNED NOT NULL DEFAULT 1,
			provider VARCHAR(20) NULL,
			status ENUM('queued','sent','delivered','failed') NOT NULL DEFAULT 'queued',
			attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
			error VARCHAR(255) NULL,
			related_type VARCHAR(30) NULL,
			related_id BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			sent_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY status_created (status,created_at),
			KEY phone_created (to_phone,created_at)
			) {$charset};"
		);
	}

	/** v2: admission + payment tables (SDD §4.2). Expand-only. */
	private static function run_v2( string $p, string $charset ): void {
		dbDelta(
			"CREATE TABLE {$p}cc_applications (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			public_ref CHAR(26) NOT NULL,
			idempotency_key CHAR(36) NOT NULL,
			batch_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED NULL,
			student_phone VARCHAR(16) NOT NULL,
			full_name VARCHAR(190) NOT NULL,
			gender ENUM('m','f','o') NOT NULL,
			dob DATE NOT NULL,
			id_doc_type ENUM('nid','birth_cert','passport') NOT NULL,
			id_doc_enc VARBINARY(255) NOT NULL,
			photo_path VARCHAR(255) NULL,
			guardian_name VARCHAR(190) NOT NULL,
			guardian_phone VARCHAR(16) NOT NULL,
			email VARCHAR(190) NULL,
			institution VARCHAR(190) NOT NULL,
			class_level VARCHAR(60) NOT NULL,
			passing_year SMALLINT NULL,
			roll_no VARCHAR(40) NULL,
			branch_pref BIGINT UNSIGNED NULL,
			consent_at DATETIME NOT NULL,
			phone_verified_at DATETIME NULL,
			status ENUM('pending','approved','rejected','waitlisted','cancelled') NOT NULL DEFAULT 'pending',
			payment_mode ENUM('online','offline') NOT NULL DEFAULT 'online',
			rejection_reason VARCHAR(500) NULL,
			reviewed_by BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY public_ref (public_ref),
			UNIQUE KEY idempotency_key (idempotency_key),
			KEY status_created (status,created_at),
			KEY student_phone (student_phone),
			KEY batch_status (batch_id,status)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$p}cc_invoices (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			application_id BIGINT UNSIGNED NOT NULL,
			amount DECIMAL(10,2) NOT NULL,
			currency CHAR(3) NOT NULL DEFAULT 'BDT',
			status ENUM('unpaid','paid','void') NOT NULL DEFAULT 'unpaid',
			number VARCHAR(30) NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY application_id (application_id),
			UNIQUE KEY number (number)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$p}cc_payments (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			invoice_id BIGINT UNSIGNED NOT NULL,
			gateway VARCHAR(20) NOT NULL,
			method ENUM('bkash','offline','fake') NOT NULL DEFAULT 'fake',
			gateway_payment_id VARCHAR(64) NULL,
			trx_id VARCHAR(64) NULL,
			amount DECIMAL(10,2) NOT NULL,
			status ENUM('initiated','executing','completed','failed','cancelled','reconcile_needed','refunded') NOT NULL DEFAULT 'initiated',
			verified_by BIGINT UNSIGNED NULL,
			response_json LONGTEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			settled_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY gateway_payment (gateway,gateway_payment_id),
			KEY status_updated (status,updated_at),
			KEY trx_id (trx_id),
			KEY invoice_id (invoice_id)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$p}cc_payment_events (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			payment_id BIGINT UNSIGNED NULL,
			source ENUM('callback','ipn','poll','admin') NOT NULL,
			event_key CHAR(64) NOT NULL,
			payload_json LONGTEXT NULL,
			received_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY event_key (event_key),
			KEY payment_id (payment_id)
			) {$charset};"
		);
		// cc_idempotency (SDD §4.2) is intentionally not created: application replays are keyed by
		// cc_applications.idempotency_key (UNIQUE) and webhooks by cc_payment_events.event_key.
	}
}
