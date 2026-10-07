<?php
defined( 'ABSPATH' ) || exit;

/**
 * Admin screen for admission applications: list, detail, reject, authenticated photo stream, ID reveal, CSV export.
 * Every handler checks the capability and the nonce itself; menu visibility is never the gate.
 */
final class CC_Admin_Applications {

	const PAGE         = 'cc-applications';
	const REVEAL_FIELD = 'cc_app_reveal';
	const CAP_REVIEW   = 'cc_review_applications';
	const CAP_EXPORT   = 'cc_export_data';
	const CAP_PHONES   = 'cc_manage_students';
	const CAP_REVEAL   = 'cc_manage_staff'; // Owner-only marker: staff and instructors never hold it.
	const PER_PAGE     = 20;
	const EXPORT_CHUNK = 500;
	const MAX_REASON   = 500;
	const STATUSES     = array( 'pending', 'waitlisted', 'approved', 'rejected', 'cancelled' );
	const PHOTO_REGEX  = '#^photos/[a-f0-9]{32}\.(jpg|png)$#';
	const SORTABLE     = array(
		'full_name'  => 'a.full_name',
		'public_ref' => 'a.public_ref',
		'status'     => 'a.status',
		'created_at' => 'a.created_at',
	);
	const NOTICES      = array(
		'rejected'      => array( 'success', 'Application rejected.' ),
		'offered'       => array( 'success', 'Seat offered. The applicant was sent an SMS with a link to pay.' ),
		'no_free_seat'  => array( 'warning', 'There is no free seat in this batch. Tick "offer anyway" to go over capacity.' ),
		'not_waitlisted' => array( 'error', 'This application is not on the waitlist.' ),
		'bulk_rejected' => array( 'success', 'Selected applications processed.' ),
		'reason_needed' => array( 'error', 'A rejection reason is required.' ),
		'not_pending'   => array( 'error', 'Only pending applications can be rejected.' ),
		'paid'          => array( 'error', 'This application has a completed payment and cannot be rejected.' ),
		'failed'        => array( 'error', 'The application could not be rejected.' ),
		'attached'      => array( 'success', 'Phone confirmed. The application is attached to the existing student account and the student was told by SMS.' ),
		'attach_pending' => array( 'warning', 'Phone confirmed, but the enrollment is not finished yet. It will be retried automatically; check the student if it does not appear.' ),
		'attach_invalid' => array( 'error', 'Tick the box and choose how you verified the phone. Nothing was changed.' ),
		'not_blocked'   => array( 'error', 'This application is not waiting for a manual phone review. Nothing was changed.' ),
		'attach_forbidden' => array( 'error', 'You are not allowed to do this.' ),
	);
	const VERIFY_METHODS = array(
		'phone_call' => 'I phoned the number on file',
		'in_person'  => 'The student confirmed in person at the center',
	);

	/** @var array<int,string> Application id => plaintext ID number, set only while serving a reveal POST. */
	private static array $revealed = array();

	public static function init(): void {
		add_action( 'admin_post_cc_app_reject', array( __CLASS__, 'handle_reject' ) );
		add_action( 'admin_post_cc_app_offer', array( __CLASS__, 'handle_offer' ) );
		add_action( 'admin_post_cc_app_bulk_reject', array( __CLASS__, 'handle_bulk_reject' ) );
		add_action( 'admin_post_cc_app_attach_override', array( __CLASS__, 'handle_attach_override' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_handle_reveal' ) );
		add_action( 'admin_post_cc_app_photo', array( __CLASS__, 'handle_photo' ) );
		add_action( 'admin_post_cc_app_export', array( __CLASS__, 'handle_export' ) );
	}

	/* ------------------------------------------------------------------ data */

	private static function table( string $name ): string {
		return CC_Application_Repository::table( $name );
	}

	/**
	 * @param array $args status, search, batch_id, date_from, date_to (Y-m-d), orderby, order, per_page (0 = all), paged.
	 * @return array{items:array<int,array>,total:int}
	 */
	public static function query( array $args = array() ): array {
		global $wpdb;
		$apps     = self::table( 'applications' );
		$invoices = self::table( 'invoices' );
		$payments = self::table( 'payments' );
		$batches  = CC_Migrations::table();

		$where  = array( '1=1' );
		$params = array();

		$status = (string) ( $args['status'] ?? '' );
		if ( in_array( $status, self::STATUSES, true ) ) {
			$where[]  = 'a.status = %s';
			$params[] = $status;
		}
		$batch_id = absint( $args['batch_id'] ?? 0 );
		if ( $batch_id > 0 ) {
			$where[]  = 'a.batch_id = %d';
			$params[] = $batch_id;
		}
		$from = (string) ( $args['date_from'] ?? '' );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ) {
			$where[]  = 'a.created_at >= %s';
			$params[] = $from . ' 00:00:00';
		}
		$to = (string) ( $args['date_to'] ?? '' );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) ) {
			$where[]  = 'a.created_at <= %s';
			$params[] = $to . ' 23:59:59';
		}
		$search = trim( (string) ( $args['search'] ?? '' ) );
		if ( '' !== $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$clauses  = array( 'a.full_name LIKE %s', 'a.public_ref LIKE %s', 'i.number LIKE %s', 'a.student_phone LIKE %s' );
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$digits   = preg_replace( '/\D/', '', $search );
			if ( strlen( $digits ) >= 3 ) {
				$local = 0 === strpos( $digits, '880' ) ? substr( $digits, 3 ) : ltrim( $digits, '0' );
				if ( '' !== $local ) {
					$clauses[] = 'a.student_phone LIKE %s';
					$params[]  = '%' . $wpdb->esc_like( $local ) . '%';
				}
			}
			$where[] = '(' . implode( ' OR ', $clauses ) . ')';
		}
		$where_sql = implode( ' AND ', $where );
		$from_sql  = "FROM {$apps} a LEFT JOIN {$invoices} i ON i.application_id = a.id LEFT JOIN {$batches} b ON b.id = a.batch_id";

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- tables are internal; every value is a placeholder.
		$count_sql = "SELECT COUNT(*) {$from_sql} WHERE {$where_sql}";
		$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) );

		$orderby = self::SORTABLE[ (string) ( $args['orderby'] ?? '' ) ] ?? 'a.created_at';
		$order   = 'ASC' === strtoupper( (string) ( $args['order'] ?? '' ) ) ? 'ASC' : 'DESC';
		$limit   = '';
		$per     = max( 0, (int) ( $args['per_page'] ?? self::PER_PAGE ) );
		if ( $per > 0 ) {
			$limit    = ' LIMIT %d OFFSET %d';
			$params[] = $per;
			$params[] = ( max( 1, (int) ( $args['paged'] ?? 1 ) ) - 1 ) * $per;
		}

		$sql = "SELECT a.id, a.public_ref, a.batch_id, a.user_id, a.student_phone, a.full_name, a.gender, a.dob, a.id_doc_type,
			a.guardian_name, a.guardian_phone, a.email, a.institution, a.class_level, a.passing_year, a.roll_no, a.status,
			a.rejection_reason, a.created_at, b.name AS batch_name, i.number AS invoice_number, i.amount AS invoice_amount,
			(SELECT p.status FROM {$payments} p WHERE p.invoice_id = i.id ORDER BY p.id DESC LIMIT 1) AS payment_status
			{$from_sql} WHERE {$where_sql} ORDER BY {$orderby} {$order}, a.id DESC{$limit}";
		$items = $params ? $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ) : $wpdb->get_results( $sql, ARRAY_A );
		// phpcs:enable

		return array(
			'items' => is_array( $items ) ? $items : array(),
			'total' => $total,
		);
	}

	/** @return array<string,int> Count per status plus 'all'. */
	public static function status_counts(): array {
		global $wpdb;
		$apps = self::table( 'applications' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		$rows   = $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$apps} GROUP BY status", ARRAY_A );
		$counts = array_fill_keys( self::STATUSES, 0 );
		$all    = 0;
		foreach ( (array) $rows as $row ) {
			$all += (int) $row['n'];
			if ( isset( $counts[ $row['status'] ] ) ) {
				$counts[ $row['status'] ] = (int) $row['n'];
			}
		}
		$counts['all'] = $all;
		return $counts;
	}

	private static function find( int $id ): ?array {
		global $wpdb;
		$apps = self::table( 'applications' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$apps} WHERE id = %d", $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/* ------------------------------------------------------------ operations */

	/** @return true|WP_Error */
	public static function reject( int $id, string $reason, int $actor ) {
		global $wpdb;
		$reason = trim( $reason );
		if ( '' === $reason || mb_strlen( $reason ) > self::MAX_REASON ) {
			return new WP_Error( 'reason_needed', 'A rejection reason of up to ' . self::MAX_REASON . ' characters is required.' );
		}
		$application = self::find( $id );
		if ( ! $application ) {
			return new WP_Error( 'not_found', 'Application not found.' );
		}

		$apps     = self::table( 'applications' );
		$invoices = self::table( 'invoices' );
		$payments = self::table( 'payments' );
		// Conditional update: only a pending application with no completed payment can flip, even under a race with settlement.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names only.
		$changed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$apps} SET status = 'rejected', rejection_reason = %s, reviewed_by = %d, updated_at = %s
				WHERE id = %d AND status IN ('pending','waitlisted')
				AND NOT EXISTS (
					SELECT 1 FROM {$invoices} i JOIN {$payments} p ON p.invoice_id = i.id
					WHERE i.application_id = %d AND p.status = 'completed'
				)",
				$reason,
				$actor,
				gmdate( 'Y-m-d H:i:s' ),
				$id,
				$id
			)
		);
		// phpcs:enable
		if ( 1 !== $changed ) {
			if ( ! in_array( $application['status'], array( 'pending', 'waitlisted' ), true ) ) {
				return new WP_Error( 'not_pending', 'Only pending applications can be rejected.' );
			}
			$fresh = self::find( $id );
			return $fresh && in_array( $fresh['status'], array( 'pending', 'waitlisted' ), true )
				? new WP_Error( 'paid', 'This application has a completed payment and cannot be rejected.' )
				: new WP_Error( 'not_pending', 'Only pending applications can be rejected.' );
		}

		CC_Audit::log( 'application.reject', 'application', $id, array( 'status' => array( $application['status'], 'rejected' ) ), 'rejected' ); // The reason can hold personal data; it lives in rejection_reason only.
		self::notify_rejected( $application );
		return true;
	}

	/**
	 * Manual review resolution for an application the existing-student guard blocked: staff confirmed by phone call (or in
	 * person) that the person behind the number applied. Sets phone_verified_at, which from now on means "proven by OTP OR
	 * verified by staff" (the staff case is the 'application.attach_override' audit row: method code and actor, no PII), then
	 * re-runs provisioning, which is idempotent and queues the 'enrolled' SMS once.
	 *
	 * @return array{result:string}|WP_Error result: attached | attach_pending (verified, enrollment will be retried).
	 */
	public static function attach_override( int $id, string $method, int $actor ) {
		global $wpdb;
		if ( ! user_can( $actor, self::CAP_REVIEW ) ) {
			return new WP_Error( 'attach_forbidden', 'You are not allowed to do this.' );
		}
		if ( ! isset( self::VERIFY_METHODS[ $method ] ) ) {
			return new WP_Error( 'attach_invalid', 'Choose how the phone was verified.' );
		}
		$now = gmdate( 'Y-m-d H:i:s' );
		// The guard state (approved, unverified, unlinked, flagged) is re-checked inside the UPDATE, so a double submit verifies once.
		$changed = in_array( $id, CC_Provisioner::blocked_application_ids(), true )
			? $wpdb->update( self::table( 'applications' ), array( 'phone_verified_at' => $now, 'updated_at' => $now ), array( 'id' => $id, 'status' => 'approved', 'phone_verified_at' => null, 'user_id' => null ) )
			: 0;
		if ( 1 !== $changed ) {
			return new WP_Error( 'not_blocked', 'This application is not waiting for a manual phone review.' );
		}
		CC_Audit::log( 'application.attach_override', 'application', $id, array( 'method' => $method, 'actor_id' => $actor ), $method );

		try {
			CC_Provisioner::provision( $id );
		} catch ( Throwable $e ) {
			error_log( sprintf( '[cc-applications] attach after manual review failed for application #%d: %s', $id, get_class( $e ) ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			CC_Provisioner::ensure_enqueued( $id );
			return array( 'result' => 'attach_pending' );
		}
		return array( 'result' => 'attached' );
	}

	/** Best effort (PRD-FUNC-009): the reason is never texted, and a queue failure must not undo the rejection. */
	private static function notify_rejected( array $application ): void {
		$phone = CC_Phone::normalize( (string) $application['student_phone'] );
		if ( null === $phone ) {
			return;
		}
		try {
			CC_Sms::queue( $phone, 'application_rejected', array(), 'application', (int) $application['id'] );
		} catch ( Throwable $e ) {
			error_log( sprintf( '[cc-applications] rejection SMS for application #%d not queued: %s', (int) $application['id'], get_class( $e ) ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	/** @return string|WP_Error Plain ID number; audited. Capability is the caller's job. */
	public static function reveal( int $id ) {
		$application = self::find( $id );
		if ( ! $application ) {
			return new WP_Error( 'not_found', 'Application not found.' );
		}
		try {
			$plain = CC_Crypto::decrypt( (string) $application['id_doc_enc'] );
		} catch ( RuntimeException $e ) {
			return new WP_Error( 'decrypt_failed', 'The ID number could not be decrypted.' );
		}
		CC_Audit::log( 'application.reveal_id', 'application', $id );
		return $plain;
	}

	/** Last four characters only; the decrypted value never leaves this method. */
	public static function masked_id_number( array $application ): string {
		try {
			$plain = CC_Crypto::decrypt( (string) $application['id_doc_enc'] );
		} catch ( RuntimeException $e ) {
			return '****';
		}
		return '****' . mb_substr( $plain, -4 );
	}

	/** Absolute file path of the application photo, or null when absent, malformed or outside the private dir. */
	public static function photo_file( array $application ): ?string {
		$relative = (string) ( $application['photo_path'] ?? '' );
		$base     = CC_Photo_Store::base_dir();
		if ( null === $base || ! preg_match( self::PHOTO_REGEX, $relative ) ) {
			return null;
		}
		$real_base = realpath( $base );
		$real_file = realpath( $base . '/' . $relative );
		if ( false === $real_base || false === $real_file || 0 !== strpos( $real_file, $real_base . DIRECTORY_SEPARATOR ) || ! is_file( $real_file ) ) {
			return null;
		}
		return $real_file;
	}

	public static function display_phone( string $phone ): string {
		return current_user_can( self::CAP_PHONES ) ? $phone : CC_Phone::mask( $phone );
	}

	/* -------------------------------------------------------------- handlers */

	private static function authorize( string $cap, string $nonce_action ): void {
		if ( ! current_user_can( $cap ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'coaching-platform' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $nonce_action );
	}

	private static function redirect( array $args ): void {
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	private static function posted_id(): int {
		return absint( wp_unslash( $_POST['id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in authorize().
	}

	public static function handle_reject(): void {
		$id = self::posted_id();
		self::authorize( self::CAP_REVIEW, 'cc_app_reject_' . $id );
		$reason = sanitize_textarea_field( wp_unslash( $_POST['reason'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$result = self::reject( $id, $reason, get_current_user_id() );
		$notice = is_wp_error( $result ) ? self::notice_code( $result ) : 'rejected';
		self::redirect( array( 'page' => self::PAGE, 'view' => $id, 'cc_notice' => $notice ) );
	}

	public static function handle_offer(): void {
		$id = self::posted_id();
		self::authorize( self::CAP_REVIEW, 'cc_app_offer_' . $id );
		$result = CC_Waitlist::offer( $id, get_current_user_id(), ! empty( $_POST['force'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in authorize().
		self::redirect( array( 'page' => self::PAGE, 'view' => $id, 'cc_notice' => is_wp_error( $result ) ? $result->get_error_code() : 'offered' ) );
	}

	public static function handle_attach_override(): void {
		$id = self::posted_id();
		self::authorize( self::CAP_REVIEW, 'cc_app_attach_' . $id );
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'coaching-platform' ), '', array( 'response' => 405 ) );
		}
		$method = sanitize_key( wp_unslash( $_POST['method'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in authorize().
		$result = empty( $_POST['confirm'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			? new WP_Error( 'attach_invalid', 'The confirmation box was not ticked.' )
			: self::attach_override( $id, $method, get_current_user_id() );
		$notice = is_wp_error( $result ) ? self::notice_code( $result ) : $result['result'];
		self::redirect( array( 'page' => self::PAGE, 'view' => $id, 'cc_notice' => $notice ) );
	}

	public static function handle_bulk_reject(): void {
		self::authorize( self::CAP_REVIEW, 'cc_app_bulk_reject' );
		$ids    = array_filter( array_map( 'absint', (array) wp_unslash( $_POST['application'] ?? array() ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$reason = sanitize_textarea_field( wp_unslash( $_POST['reason'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( empty( $_POST['confirm'] ) || ! $ids ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			self::redirect( array( 'page' => self::PAGE, 'cc_notice' => 'failed' ) );
		}
		$done = 0;
		foreach ( $ids as $id ) {
			if ( true === self::reject( $id, $reason, get_current_user_id() ) ) {
				++$done;
			}
		}
		$notice = '' === trim( $reason ) ? 'reason_needed' : 'bulk_rejected';
		self::redirect( array( 'page' => self::PAGE, 'cc_notice' => $notice, 'cc_n' => $done ) );
	}

	/** Runs on admin_init, before any output, so the response headers can still be set. */
	public static function maybe_handle_reveal(): void {
		global $pagenow;
		if ( 'admin.php' !== $pagenow || 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || empty( $_POST[ self::REVEAL_FIELD ] ) || self::PAGE !== ( $_GET['page'] ?? '' ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- routing only; verified in handle_reveal().
			return;
		}
		self::handle_reveal();
	}

	/**
	 * Verifies capability and nonce, decrypts and audits, then keeps the number for render_detail() to print on this
	 * same response. It is never redirected or put in a URL; the response is marked no-store.
	 */
	public static function handle_reveal(): void {
		$id = self::posted_id();
		self::authorize( self::CAP_REVEAL, 'cc_app_reveal_' . $id );
		$plain = self::reveal( $id );
		if ( is_wp_error( $plain ) ) {
			wp_die( esc_html( $plain->get_error_message() ), '', array( 'response' => 404 ) );
		}
		nocache_headers();
		header( 'Cache-Control: no-store, private' );
		self::$revealed = array( $id => $plain );
	}

	public static function handle_photo(): void {
		$id = absint( wp_unslash( $_GET['id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified in authorize().
		self::authorize( self::CAP_REVIEW, 'cc_app_photo_' . $id );
		$application = self::find( $id );
		$file        = $application ? self::photo_file( $application ) : null;
		$mime        = $file ? ( new finfo( FILEINFO_MIME_TYPE ) )->file( $file ) : false;
		if ( ! $file || ! isset( CC_Photo_Store::MIME_EXTENSION[ $mime ] ) ) {
			wp_die( esc_html__( 'Photo not found.', 'coaching-platform' ), '', array( 'response' => 404 ) );
		}
		nocache_headers();
		header( 'Cache-Control: no-store, private' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Type: ' . $mime );
		header( 'Content-Length: ' . filesize( $file ) );
		readfile( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	public static function handle_export(): void {
		self::authorize( self::CAP_EXPORT, 'cc_app_export' );
		$args = self::filters_from_request( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified in authorize().
		CC_Audit::log( 'application.export', 'application', 0, $args );
		CC_Csv::stream( 'applications-' . gmdate( 'Ymd-His' ) . '.csv', self::export_header(), self::export_rows( $args ) );
	}

	private static function notice_code( WP_Error $error ): string {
		$code = $error->get_error_code();
		return isset( self::NOTICES[ $code ] ) ? (string) $code : 'failed';
	}

	/* ---------------------------------------------------------------- export */

	public static function export_header(): array {
		return array( 'Reference', 'Status', 'Batch', 'Name', 'Phone', 'Gender', 'Date of birth', 'ID type', 'Guardian', 'Guardian phone', 'Email', 'Institution', 'Class', 'Passing year', 'Roll', 'Invoice', 'Amount', 'Payment status', 'Created (UTC)' );
	}

	private static function export_rows( array $args ): Generator {
		$args['per_page'] = self::EXPORT_CHUNK;
		for ( $page = 1;; $page++ ) {
			$args['paged'] = $page;
			$items         = self::query( $args )['items'];
			foreach ( $items as $r ) {
				yield array(
					$r['public_ref'], $r['status'], (string) $r['batch_name'], $r['full_name'], CC_Csv::phone( (string) $r['student_phone'] ), $r['gender'], $r['dob'],
					$r['id_doc_type'], $r['guardian_name'], CC_Csv::phone( (string) $r['guardian_phone'] ), (string) $r['email'], $r['institution'], $r['class_level'],
					(string) $r['passing_year'], (string) $r['roll_no'], (string) $r['invoice_number'], (string) $r['invoice_amount'],
					(string) $r['payment_status'], $r['created_at'],
				);
			}
			if ( count( $items ) < self::EXPORT_CHUNK ) {
				return;
			}
		}
	}

	/** Whitelisted filter args from a request array. The search term is read from `s` (list form) or `search`. */
	public static function filters_from_request( array $request ): array {
		$pick = static fn( string $key ): string => sanitize_text_field( wp_unslash( (string) ( $request[ $key ] ?? '' ) ) );
		return array(
			'status'    => $pick( 'status' ),
			'search'    => '' !== $pick( 's' ) ? $pick( 's' ) : $pick( 'search' ),
			'batch_id'  => absint( $request['batch_id'] ?? 0 ),
			'date_from' => $pick( 'date_from' ),
			'date_to'   => $pick( 'date_to' ),
			'orderby'   => $pick( 'orderby' ),
			'order'     => $pick( 'order' ),
		);
	}

	/** Non-empty filters keyed the way the handler reads them (`s` for the search term). */
	public static function export_link_args( array $request ): array {
		$filters = self::filters_from_request( $request );
		$filters['s'] = $filters['search'];
		unset( $filters['search'] );
		return array_filter( $filters );
	}

	/* ------------------------------------------------------------- rendering */

	public static function render(): void {
		if ( ! current_user_can( self::CAP_REVIEW ) ) {
			wp_die( esc_html__( 'You are not allowed to view this page.', 'coaching-platform' ), '', array( 'response' => 403 ) );
		}
		echo '<div class="wrap">';
		self::render_notice();
		$view = absint( $_GET['view'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation.
		self::render_review_notice( $view );
		if ( $view > 0 ) {
			if ( ! empty( $_GET['confirm_attach'] ) && in_array( $view, CC_Provisioner::blocked_application_ids(), true ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only; the POST carries the nonce.
				self::render_attach_confirm( $view );
			} else {
				self::render_detail( $view, self::$revealed[ $view ] ?? null );
			}
		} else {
			self::render_list();
		}
		echo '</div>';
	}

	private static function render_notice(): void {
		$code = sanitize_key( wp_unslash( $_GET['cc_notice'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( self::NOTICES[ $code ] ) ) {
			return;
		}
		list( $type, $message ) = self::NOTICES[ $code ];
		if ( 'bulk_rejected' === $code && isset( $_GET['cc_n'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$message = sprintf( 'Rejected %d application(s). Others were skipped because they are not pending or are paid.', absint( $_GET['cc_n'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $type ), esc_html( $message ) );
	}

	/** Approved applications the provisioner refused to attach to an existing student (phone never verified). */
	private static function render_review_notice( int $view ): void {
		$blocked = CC_Provisioner::blocked_application_ids();
		if ( $view > 0 ) {
			$blocked = array_intersect( $blocked, array( $view ) );
		}
		if ( ! $blocked ) {
			return;
		}
		$text = $view > 0 ? 'Needs manual review: phone not verified.' : sprintf( 'Needs manual review: phone not verified (%d approved application(s), id %s).', count( $blocked ), implode( ', ', $blocked ) );
		printf( '<div class="notice notice-warning"><p><strong>%s</strong> The student phone was never confirmed by code and already belongs to a student account, so the enrollment was not created and no SMS was sent.</p></div>', esc_html( $text ) );
	}

	private static function render_list(): void {
		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		require_once __DIR__ . '/class-applications-table.php';
		$table = new CC_Applications_Table();

		if ( 'reject' === $table->current_action() ) {
			check_admin_referer( 'bulk-applications' );
			$ids = array_filter( array_map( 'absint', (array) wp_unslash( $_REQUEST['application'] ?? array() ) ) );
			if ( $ids ) {
				self::render_bulk_confirm( array_values( $ids ) );
				return;
			}
		}

		$table->prepare_items();
		echo '<h1 class="wp-heading-inline">Applications</h1>';
		if ( current_user_can( self::CAP_EXPORT ) ) {
			$export = add_query_arg( array_merge( array( 'action' => 'cc_app_export' ), self::export_link_args( $_GET ) ), admin_url( 'admin-post.php' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			printf( ' <a class="page-title-action" href="%s">Export CSV</a>', esc_url( wp_nonce_url( $export, 'cc_app_export' ) ) );
		}
		echo '<hr class="wp-header-end">';
		$table->views();
		echo '<form method="get"><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '">';
		if ( ! empty( $_GET['status'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<input type="hidden" name="status" value="' . esc_attr( sanitize_key( wp_unslash( $_GET['status'] ) ) ) . '">'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		$table->search_box( 'Search applications', 'cc-app' );
		$table->display();
		echo '</form>';
	}

	private static function render_bulk_confirm( array $ids ): void {
		echo '<h1>Reject ' . esc_html( (string) count( $ids ) ) . ' application(s)?</h1>';
		echo '<p>Only pending, unpaid applications will be rejected; others are skipped. This cannot be undone.</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="cc_app_bulk_reject"><input type="hidden" name="confirm" value="1">';
		wp_nonce_field( 'cc_app_bulk_reject' );
		foreach ( $ids as $id ) {
			echo '<input type="hidden" name="application[]" value="' . esc_attr( (string) $id ) . '">';
		}
		echo '<p><label for="cc-reason"><strong>Reason</strong></label><br><textarea id="cc-reason" name="reason" rows="3" cols="60" maxlength="' . esc_attr( (string) self::MAX_REASON ) . '" required></textarea></p>';
		submit_button( 'Confirm reject', 'delete', 'submit', false );
		echo ' <a class="button" href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ) . '">Cancel</a></form>';
	}

	private static function render_detail( int $id, ?string $revealed = null ): void {
		$application = self::find( $id );
		if ( ! $application ) {
			echo '<p>Application not found.</p>';
			return;
		}
		$batch    = self::batch_name( (int) $application['batch_id'] );
		$invoice  = CC_Application_Repository::find_invoice( $id );
		$payments = $invoice ? CC_Application_Repository::payments_for_invoice( (int) $invoice['id'] ) : array();

		echo '<h1>Application ' . esc_html( $application['public_ref'] ) . '</h1>';
		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ) . '">&larr; All applications</a></p>';
		echo '<table class="widefat striped" style="max-width:720px"><tbody>';
		$rows = array(
			'Status'          => $application['status'],
			'Batch'           => $batch,
			'Name'            => $application['full_name'],
			'Phone'           => self::display_phone( $application['student_phone'] ),
			'Gender'          => $application['gender'],
			'Date of birth'   => $application['dob'],
			'Guardian'        => $application['guardian_name'],
			'Guardian phone'  => self::display_phone( $application['guardian_phone'] ),
			'Email'           => (string) $application['email'],
			'Institution'     => $application['institution'],
			'Class'           => $application['class_level'],
			'Passing year'    => (string) $application['passing_year'],
			'Roll'            => (string) $application['roll_no'],
			'Submitted (UTC)' => $application['created_at'],
			'Reject reason'   => (string) $application['rejection_reason'],
		);
		foreach ( $rows as $label => $value ) {
			printf( '<tr><th scope="row">%s</th><td>%s</td></tr>', esc_html( $label ), esc_html( (string) $value ) );
		}
		echo '<tr><th scope="row">' . esc_html( strtoupper( str_replace( '_', ' ', $application['id_doc_type'] ) ) ) . ' number</th><td>';
		echo esc_html( $revealed ?? self::masked_id_number( $application ) );
		if ( null === $revealed && current_user_can( self::CAP_REVEAL ) ) {
			self::render_reveal_form( $id );
		}
		echo '</td></tr>';
		if ( self::photo_file( $application ) ) {
			$src = wp_nonce_url( add_query_arg( array( 'action' => 'cc_app_photo', 'id' => $id ), admin_url( 'admin-post.php' ) ), 'cc_app_photo_' . $id );
			echo '<tr><th scope="row">Photo</th><td><img src="' . esc_url( $src ) . '" alt="Applicant photo" style="max-width:160px;height:auto"></td></tr>';
		}
		echo '</tbody></table>';

		self::render_payments( $invoice, $payments );
		self::render_enrollment( $id );
		if ( 'waitlisted' === $application['status'] ) {
			self::render_offer_form( $id, $application );
		}
		if ( in_array( $application['status'], array( 'pending', 'waitlisted' ), true ) ) {
			self::render_reject_form( $id );
		}
		if ( in_array( $id, CC_Provisioner::blocked_application_ids(), true ) ) {
			self::render_manual_review( $id );
		}
	}

	private static function render_offer_form( int $id, array $application ): void {
		$free = CC_Waitlist::free_seats( (int) $application['batch_id'] );
		echo '<h2>Waitlist</h2>';
		printf( '<p>Place %d in line. Free seats now: <strong>%d</strong>. Seats are offered automatically in order when one frees up; offers lapse after %d hours without payment.</p>', CC_Waitlist::position( $id ), $free, CC_Waitlist::offer_hours() ); // phpcs:ignore WordPress.Security.EscapeOutput -- integers.
		if ( ! current_user_can( self::CAP_REVIEW ) ) {
			return;
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="cc_app_offer"><input type="hidden" name="id" value="' . esc_attr( (string) $id ) . '">';
		wp_nonce_field( 'cc_app_offer_' . $id );
		echo '<p><label><input type="checkbox" name="force" value="1"> Offer anyway (go over capacity)</label></p>';
		submit_button( 'Offer a seat now', 'primary', 'submit', false );
		echo '</form>';
	}

	private static function render_manual_review( int $id ): void {
		$confirm = add_query_arg( array( 'page' => self::PAGE, 'view' => $id, 'confirm_attach' => 1 ), admin_url( 'admin.php' ) );
		echo '<h2 id="manual-review">Manual review: phone not verified</h2>';
		echo '<p>Call the number on file for the existing student. If the person confirms they applied, attach this application to their account.</p>';
		printf( '<p><a class="button button-primary" href="%s">Confirm phone by call and attach to existing account</a></p>', esc_url( $confirm ) );
		echo '<p><strong>Reject (cannot verify):</strong> this application is approved and paid, so it cannot be rejected. Refund the payment first (owner: Payments &rarr; Mark refunded), then the application is cancelled.</p>';
	}

	private static function render_attach_confirm( int $id ): void {
		$application = self::find( $id );
		if ( ! $application ) {
			echo '<p>Application not found.</p>';
			return;
		}
		$user     = get_user_by( 'login', ltrim( (string) $application['student_phone'], '+' ) );
		$back     = add_query_arg( array( 'page' => self::PAGE, 'view' => $id ), admin_url( 'admin.php' ) );
		echo '<h1>Attach ' . esc_html( $application['public_ref'] ) . ' to an existing account?</h1>';
		echo '<table class="widefat striped" style="max-width:720px"><tbody>';
		printf( '<tr><th scope="row">Applicant</th><td>%s</td></tr>', esc_html( $application['full_name'] ) );
		printf( '<tr><th scope="row">Phone</th><td>%s</td></tr>', esc_html( self::display_phone( $application['student_phone'] ) ) );
		printf( '<tr><th scope="row">Existing student account</th><td>%s</td></tr>', esc_html( $user ? $user->display_name : 'Not found' ) );
		echo '</tbody></table>';
		echo '<p style="max-width:720px">The student will be enrolled in the batch with the account they already have, and receive an SMS. Your name and how you verified are recorded in the Audit log.</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="cc_app_attach_override"><input type="hidden" name="id" value="' . esc_attr( (string) $id ) . '">';
		wp_nonce_field( 'cc_app_attach_' . $id );
		echo '<p><label><input type="checkbox" name="confirm" value="1" required> I called the number on file for the existing student and the person confirmed they applied</label></p>';
		echo '<p><label for="cc-verify-method"><strong>How was it verified?</strong></label><br><select id="cc-verify-method" name="method" required><option value="">Choose</option>';
		foreach ( self::VERIFY_METHODS as $code => $label ) {
			printf( '<option value="%s">%s</option>', esc_attr( $code ), esc_html( $label ) );
		}
		echo '</select></p>';
		submit_button( 'Confirm and attach', 'primary', 'submit', false );
		echo ' <a class="button" href="' . esc_url( $back ) . '">Cancel</a></form>';
	}

	private static function render_reveal_form( int $id ): void {
		$action = add_query_arg( array( 'page' => self::PAGE, 'view' => $id ), admin_url( 'admin.php' ) );
		echo '<form method="post" action="' . esc_url( $action ) . '" style="display:inline">';
		echo '<input type="hidden" name="' . esc_attr( self::REVEAL_FIELD ) . '" value="1"><input type="hidden" name="id" value="' . esc_attr( (string) $id ) . '">';
		wp_nonce_field( 'cc_app_reveal_' . $id );
		echo '<button type="submit" class="button button-small">Reveal ID number</button></form>';
	}

	private static function render_payments( ?array $invoice, array $payments ): void {
		echo '<h2>Invoice and payments</h2>';
		if ( ! $invoice ) {
			echo '<p>No invoice.</p>';
			return;
		}
		printf( '<p>Invoice %s: %s %s (%s)</p>', esc_html( $invoice['number'] ), esc_html( $invoice['amount'] ), esc_html( $invoice['currency'] ), esc_html( $invoice['status'] ) );
		if ( ! $payments ) {
			echo '<p>No payment attempts.</p>';
			return;
		}
		echo '<table class="widefat striped" style="max-width:720px"><thead><tr><th>Created (UTC)</th><th>Gateway</th><th>Amount</th><th>Status</th><th>Trx</th><th>Settled</th></tr></thead><tbody>';
		foreach ( $payments as $p ) {
			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( $p['created_at'] ),
				esc_html( $p['gateway'] ),
				esc_html( $p['amount'] ),
				esc_html( $p['status'] ),
				esc_html( (string) $p['trx_id'] ),
				esc_html( (string) $p['settled_at'] )
			);
		}
		echo '</tbody></table>';
	}

	private static function render_enrollment( int $application_id ): void {
		global $wpdb;
		$enrollments = self::table( 'enrollments' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT user_id, status FROM {$enrollments} WHERE application_id = %d", $application_id ), ARRAY_A );
		if ( ! $row ) {
			return;
		}
		echo '<h2>Enrollment</h2><p>Status: ' . esc_html( $row['status'] );
		$link = current_user_can( 'edit_user', (int) $row['user_id'] ) ? get_edit_user_link( (int) $row['user_id'] ) : '';
		if ( $link ) {
			echo ' &middot; <a href="' . esc_url( $link ) . '">Student account</a>';
		}
		echo '</p>';
	}

	private static function render_reject_form( int $id ): void {
		echo '<h2 id="reject">Reject application</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="cc_app_reject"><input type="hidden" name="id" value="' . esc_attr( (string) $id ) . '">';
		wp_nonce_field( 'cc_app_reject_' . $id );
		echo '<p><label for="cc-reason"><strong>Reason</strong></label><br><textarea id="cc-reason" name="reason" rows="3" cols="60" maxlength="' . esc_attr( (string) self::MAX_REASON ) . '" required></textarea></p>';
		submit_button( 'Reject application', 'delete', 'submit', false );
		echo '</form>';
	}

	private static function batch_name( int $batch_id ): string {
		global $wpdb;
		$batches = CC_Migrations::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$batches} WHERE id = %d", $batch_id ) );
	}

	/** @return array<int,string> Batch id => name, for the filter dropdown. */
	public static function batch_options(): array {
		global $wpdb;
		$batches = CC_Migrations::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		$rows = $wpdb->get_results( "SELECT id, name FROM {$batches} ORDER BY start_date DESC, id DESC LIMIT 200", ARRAY_A );
		return array_column( (array) $rows, 'name', 'id' );
	}
}
