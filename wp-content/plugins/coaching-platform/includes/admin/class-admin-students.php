<?php
defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-student-ops.php';

/**
 * Admin Students screen: list (live search, bulk actions), detail (profile edit, batch move), enrollment (de)activation,
 * credential resend and CSV export.
 * Every handler checks the nonce and the capability; the public static methods re-check the capability for the
 * given actor so they are safe to call from anywhere. No password is generated or seen here: while the student has
 * never changed the temporary password (cc_students.must_change_pw = 1) credentials are re-issued by
 * CC_Provisioner::reissue_credentials(), which queues the SMS itself; afterwards the password and sessions are left
 * alone and only a login-with-OTP hint SMS is queued.
 */
final class CC_Admin_Students {

	const PAGE_SLUG      = 'cc-students';
	const PER_PAGE       = 20;
	const EXPORT_CHUNK   = 200;
	const SMS_LOG_LIMIT  = 20;
	const PAYMENT_LIMIT  = 100;
	const PHONE_SEARCH_MIN_DIGITS = 3;
	const RESEND_LIMIT   = 3;
	const RESEND_WINDOW  = HOUR_IN_SECONDS;
	const SENT_PASSWORD  = 'credentials';
	const SENT_HINT      = 'login_hint';
	const STATUSES       = array( 'active', 'deactivated' );
	const PAYMENT_STATES = array( 'paid' => 'Paid', 'pending' => 'Pending', 'refunded' => 'Refunded' );
	const AJAX_ACTION    = 'cc_students_search';
	const SCRIPT_HANDLE  = 'cc-admin-students';
	const BULK_ACTIONS   = array(
		'export'     => array( 'Export selected (CSV)', 'cc_export_data' ),
		'deactivate' => array( 'Deactivate selected enrollments', 'cc_manage_students' ),
		'notice'     => array( 'Compose notice for selected', 'cc_manage_notices' ),
	);
	const CSV_HEADER     = array( 'Name', 'Phone', 'Batches', 'Enrollment status', 'Joined (UTC)', 'Last credentials SMS' );

	const NOTICES = array(
		'status_ok'     => array( 'success', 'Enrollment updated.' ),
		'status_failed' => array( 'error', 'The enrollment could not be updated.' ),
		'resend_ok'     => array( 'success', 'Credentials SMS queued.' ),
		'resend_hint'   => array( 'success', 'Student already has a password; a login-with-OTP hint was sent.' ),
		'resend_failed' => array( 'error', 'Credentials could not be re-sent. The hourly limit may be reached, or you lack permission.' ),
		'profile_ok'      => array( 'success', 'Profile updated.' ),
		'profile_same'    => array( 'info', 'Nothing was changed.' ),
		'profile_invalid' => array( 'error', 'The profile was not saved. Fix the fields marked below.' ),
		'profile_failed'  => array( 'error', 'The profile could not be saved.' ),
		'move_ok'         => array( 'success', 'The enrollment was moved. No money was moved: adjust fees manually if the price differs.' ),
		'move_same'       => array( 'info', 'The enrollment is already in that batch.' ),
		'move_full'       => array( 'error', 'The target batch has no seats left.' ),
		'move_duplicate'  => array( 'error', 'The student is already enrolled in the target batch.' ),
		'move_target'     => array( 'error', 'That batch cannot take students (missing, draft or completed).' ),
		'move_failed'     => array( 'error', 'The enrollment could not be moved.' ),
		'bulk_none'       => array( 'error', 'Select at least one student, and choose an action you are allowed to use.' ),
		'bulk_expired'    => array( 'error', 'The confirmation expired. Select the students again.' ),
		'bulk_done'       => array( 'success', 'Enrollments deactivated.' ),
	);

	const MOVE_NOTICE_FOR_CODE = array(
		'cc_no_change'      => 'move_same',
		'cc_batch_full'     => 'move_full',
		'cc_duplicate'      => 'move_duplicate',
		'cc_invalid_target' => 'move_target',
	);

	public static function init(): void {
		add_action( 'admin_post_cc_student_status', array( __CLASS__, 'handle_status' ) );
		add_action( 'admin_post_cc_student_resend', array( __CLASS__, 'handle_resend' ) );
		add_action( 'admin_post_cc_students_export', array( __CLASS__, 'handle_export' ) );
		add_action( 'admin_post_cc_student_profile', array( __CLASS__, 'handle_profile' ) );
		add_action( 'admin_post_cc_student_move', array( __CLASS__, 'handle_move' ) );
		add_action( 'admin_post_cc_students_bulk', array( __CLASS__, 'handle_bulk' ) );
		add_action( 'admin_post_cc_students_bulk_deactivate', array( __CLASS__, 'handle_bulk_deactivate' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( __CLASS__, 'handle_ajax_search' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/** Live search script: Students list screen only. @param mixed $hook */
	public static function enqueue_assets( $hook ): void {
		if ( ! is_string( $hook ) || false === strpos( $hook, self::PAGE_SLUG ) || ! current_user_can( 'cc_view_students' ) || isset( $_GET['user'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- screen check only.
			return;
		}
		$main = dirname( __DIR__, 2 ) . '/coaching-platform.php';
		$file = dirname( $main ) . '/assets/admin-students.js';
		wp_enqueue_script( self::SCRIPT_HANDLE, plugins_url( 'assets/admin-students.js', $main ), array(), CC_VERSION . '.' . (int) @filemtime( $file ), true );
		wp_localize_script(
			self::SCRIPT_HANDLE,
			'ccStudents',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'action'  => self::AJAX_ACTION,
				'nonce'   => wp_create_nonce( self::AJAX_ACTION ),
			)
		);
	}

	/* ---------------------------------------------------------------- testable operations */

	/** @return bool|WP_Error */
	public static function set_enrollment_status( int $enrollment_id, string $status, int $actor ) {
		global $wpdb;
		if ( ! user_can( $actor, 'cc_manage_students' ) ) {
			return new WP_Error( 'cc_forbidden', 'You are not allowed to manage students.' );
		}
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return new WP_Error( 'cc_invalid_status', 'Status must be active or deactivated.' );
		}
		$table = $wpdb->prefix . 'cc_enrollments';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, status FROM {$table} WHERE id = %d", $enrollment_id ), ARRAY_A );
		if ( null === $row ) {
			return new WP_Error( 'cc_not_found', 'Enrollment not found.' );
		}
		$from = (string) $row['status'];
		if ( $from === $status || ( 'active' === $status && 'deactivated' !== $from ) ) {
			return new WP_Error( 'cc_no_change', 'Enrollment is already in that state.' );
		}
		if ( false === $wpdb->update( $table, array( 'status' => $status ), array( 'id' => $enrollment_id ) ) ) {
			return new WP_Error( 'cc_db_error', 'Enrollment could not be saved.' );
		}
		CC_Audit::log( 'student.enrollment_' . $status, 'enrollment', $enrollment_id, array( 'from' => $from, 'to' => $status ) );
		return true;
	}

	/** @return string|WP_Error SENT_PASSWORD when a new temporary password was issued, SENT_HINT when only the OTP hint was sent. */
	public static function resend_credentials( int $user_id, int $actor ) {
		if ( ! user_can( $actor, 'cc_manage_students' ) ) {
			return new WP_Error( 'cc_forbidden', 'You are not allowed to manage students.' );
		}
		$student = CC_Enrollment_Repository::student_row( $user_id );
		if ( null === $student ) {
			return new WP_Error( 'cc_not_found', 'Student not found.' );
		}
		if ( ! CC_Rate_Limiter::allow( 'cc_resend_credentials_' . $user_id, self::RESEND_LIMIT, self::RESEND_WINDOW ) ) {
			return new WP_Error( 'cc_rate_limited', 'Credentials were already re-sent 3 times in the last hour.' );
		}
		if ( 1 === (int) $student['must_change_pw'] ) {
			$result = CC_Provisioner::reissue_credentials( $user_id );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			CC_Audit::log( 'student.credentials_resent', 'student', $user_id );
			return self::SENT_PASSWORD;
		}
		$user = get_userdata( $user_id );
		if ( ! $user instanceof WP_User || ! CC_Roles::is_student( $user ) ) {
			return new WP_Error( 'cc_not_student', 'Only student accounts can be messaged.' );
		}
		CC_Sms::queue( '+' . $user->user_login, 'login_hint', array( 'url' => home_url( '/student/login/' ) ), 'student', $user_id );
		CC_Audit::log( 'student.login_hint_sent', 'student', $user_id );
		return self::SENT_HINT;
	}

	/**
	 * @param array{search?:string,batch_id?:int,status?:string,orderby?:string,order?:string,paged?:int,per_page?:int} $args
	 * @return array{items:array<int,array<string,mixed>>,total:int}
	 */
	public static function query( array $args = array() ): array {
		global $wpdb;
		$p        = $wpdb->prefix;
		$per_page = max( 1, min( 200, (int) ( $args['per_page'] ?? self::PER_PAGE ) ) );
		$offset   = ( max( 1, (int) ( $args['paged'] ?? 1 ) ) - 1 ) * $per_page;
		$order    = 'asc' === strtolower( (string) ( $args['order'] ?? '' ) ) ? 'ASC' : 'DESC';
		$orderby  = 'name' === ( $args['orderby'] ?? '' ) ? 's.full_name' : 's.created_at';

		$where  = array( '1=1' );
		$params = array();
		$search = trim( (string) ( $args['search'] ?? '' ) );
		if ( '' !== $search ) {
			$digits = preg_replace( '/\D+/', '', $search );
			$clause = 's.full_name LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $search ) . '%';
			if ( preg_match( '/^[\d\s+()-]+$/', $search ) && strlen( $digits ) >= self::PHONE_SEARCH_MIN_DIGITS ) {
				$clause  .= ' OR u.user_login LIKE %s';
				$params[] = '%' . $wpdb->esc_like( $digits ) . '%';
			}
			$where[] = '(' . $clause . ')';
		}
		$batch_id = (int) ( $args['batch_id'] ?? 0 );
		$status   = (string) ( $args['status'] ?? '' );
		$pay      = (string) ( $args['payment_state'] ?? '' );
		if ( $batch_id > 0 ) {
			$where[]  = "EXISTS (SELECT 1 FROM {$p}cc_enrollments e WHERE e.user_id = s.user_id AND e.batch_id = %d)";
			$params[] = $batch_id;
		}
		if ( in_array( $status, array( 'active', 'deactivated', 'expired', 'completed' ), true ) ) {
			$where[]  = "EXISTS (SELECT 1 FROM {$p}cc_enrollments e2 WHERE e2.user_id = s.user_id AND e2.status = %s)";
			$params[] = $status;
		}
		if ( isset( self::PAYMENT_STATES[ $pay ] ) ) {
			$where[] = "EXISTS (SELECT 1 FROM {$p}cc_enrollments e3 WHERE e3.user_id = s.user_id" . ( $batch_id > 0 ? ' AND e3.batch_id = %d' : '' ) . ' AND ' . self::payment_state_condition( $pay, 'e3' ) . ')';
			if ( $batch_id > 0 ) {
				$params[] = $batch_id;
			}
		}
		if ( array_key_exists( 'user_ids', $args ) ) {
			$user_ids = array_values( array_filter( array_map( 'absint', (array) $args['user_ids'] ) ) );
			$where[]  = $user_ids ? 's.user_id IN (' . implode( ',', array_fill( 0, count( $user_ids ), '%d' ) ) . ')' : '1=0';
			$params   = array_merge( $params, $user_ids );
		}
		$where_sql = implode( ' AND ', $where );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- table names, whitelisted ORDER BY and placeholders only.
		$total = (int) $wpdb->get_var( self::prepare_maybe( "SELECT COUNT(*) FROM {$p}cc_students s JOIN {$wpdb->users} u ON u.ID = s.user_id WHERE {$where_sql}", $params ) );
		$rows  = $wpdb->get_results(
			self::prepare_maybe(
				"SELECT s.user_id, s.full_name, s.must_change_pw, s.created_at AS joined_at, u.user_login,
					(SELECT l.status FROM {$p}cc_sms_log l
						WHERE l.template = 'credentials'
						AND ( (l.related_type = 'student' AND l.related_id = s.user_id)
							OR (l.related_type = 'application' AND l.related_id IN (SELECT a.id FROM {$p}cc_applications a WHERE a.user_id = s.user_id)) )
						ORDER BY l.id DESC LIMIT 1) AS sms_status
				FROM {$p}cc_students s JOIN {$wpdb->users} u ON u.ID = s.user_id
				WHERE {$where_sql}
				ORDER BY {$orderby} {$order}, s.user_id DESC
				LIMIT %d OFFSET %d",
				array_merge( $params, array( $per_page, $offset ) )
			),
			ARRAY_A
		);
		// phpcs:enable
		$rows = is_array( $rows ) ? $rows : array();
		return array(
			'items' => self::attach_enrollments( $rows, $batch_id ),
			'total' => $total,
		);
	}

	/**
	 * SQL condition (constants only, no input) that an enrollment alias is in the given payment state. Refunded wins over
	 * paid; pending means the enrollment (so an approved application) has neither a completed nor a refunded payment.
	 * Uses the unique cc_invoices.application_id and the cc_payments.invoice_id index.
	 */
	private static function payment_state_condition( string $state, string $alias ): string {
		global $wpdb;
		$p        = $wpdb->prefix;
		$exists   = static fn( string $status ): string => "EXISTS (SELECT 1 FROM {$p}cc_invoices i JOIN {$p}cc_payments pay ON pay.invoice_id = i.id WHERE i.application_id = {$alias}.application_id AND pay.status = '{$status}')";
		$refunded = $exists( 'refunded' );
		$paid     = $exists( 'completed' );
		if ( 'refunded' === $state ) {
			return $refunded;
		}
		return 'paid' === $state ? "NOT {$refunded} AND {$paid}" : "NOT {$refunded} AND NOT {$paid}";
	}

	/** @param array<int,mixed> $params */
	private static function prepare_maybe( string $sql, array $params ): string {
		global $wpdb;
		return $params ? $wpdb->prepare( $sql, $params ) : $sql; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/** @param array<int,array<string,mixed>> $rows @return array<int,array<string,mixed>> */
	private static function attach_enrollments( array $rows, int $batch_scope = 0 ): array {
		global $wpdb;
		if ( ! $rows ) {
			return array();
		}
		$ids = array_map( static fn( $r ) => (int) $r['user_id'], $rows );
		$p   = $wpdb->prefix;
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- table names, constant conditions and %d placeholders only.
		$found = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT e.user_id, e.batch_id, e.status, b.name AS batch_name,
					CASE WHEN ' . self::payment_state_condition( 'refunded', 'e' ) . "  THEN 'refunded'
						WHEN " . self::payment_state_condition( 'paid', 'e' ) . " THEN 'paid' ELSE 'pending' END AS payment_state
				FROM " . $p . 'cc_enrollments e JOIN ' . $p . 'cc_batches b ON b.id = e.batch_id WHERE e.user_id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ') ORDER BY e.enrolled_at DESC, e.id DESC',
				$ids
			),
			ARRAY_A
		);
		// phpcs:enable
		$by_user = array();
		foreach ( is_array( $found ) ? $found : array() as $e ) {
			$by_user[ (int) $e['user_id'] ][] = $e;
		}
		return array_map(
			static function ( array $row ) use ( $by_user, $batch_scope ): array {
				$list            = $by_user[ (int) $row['user_id'] ] ?? array();
				$statuses        = array_column( $list, 'status' );
				$scoped          = $batch_scope > 0 ? array_filter( $list, static fn( array $e ): bool => (int) $e['batch_id'] === $batch_scope ) : $list;
				$row['batches']  = implode( ', ', array_column( $list, 'batch_name' ) );
				$row['enrollment_status'] = in_array( 'active', $statuses, true ) ? 'active' : (string) ( $statuses[0] ?? '' );
				$row['payment_state']     = implode( ', ', array_map( static fn( string $s ): string => self::PAYMENT_STATES[ $s ], array_values( array_unique( array_column( $scoped, 'payment_state' ) ) ) ) );
				return $row;
			},
			$rows
		);
	}

	public static function display_phone( string $user_login ): string {
		$phone = '+' . $user_login;
		return current_user_can( 'cc_manage_students' ) ? $phone : CC_Phone::mask( $phone );
	}

	/* ---------------------------------------------------------------- handlers */

	public static function handle_status(): void {
		$enrollment_id = isset( $_POST['enrollment_id'] ) ? absint( wp_unslash( $_POST['enrollment_id'] ) ) : 0;
		check_admin_referer( 'cc_student_status_' . $enrollment_id );
		self::require_cap( 'cc_manage_students' );
		global $wpdb;
		$status  = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';
		$user_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT user_id FROM ' . $wpdb->prefix . 'cc_enrollments WHERE id = %d', $enrollment_id ) );
		$result  = self::set_enrollment_status( $enrollment_id, $status, get_current_user_id() );
		self::redirect( $user_id, true === $result ? 'status_ok' : 'status_failed' );
	}

	public static function handle_resend(): void {
		$user_id = isset( $_POST['user_id'] ) ? absint( wp_unslash( $_POST['user_id'] ) ) : 0;
		check_admin_referer( 'cc_student_resend_' . $user_id );
		self::require_cap( 'cc_manage_students' );
		$result = self::resend_credentials( $user_id, get_current_user_id() );
		$notices = array( self::SENT_PASSWORD => 'resend_ok', self::SENT_HINT => 'resend_hint' );
		self::redirect( $user_id, is_string( $result ) ? $notices[ $result ] : 'resend_failed' );
	}

	public static function handle_export(): void {
		check_admin_referer( 'cc_students_export' );
		self::require_cap( 'cc_export_data' );
		$filters = self::filters_from( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification -- verified above.
		CC_Audit::log( 'student.export', 'student', 0, $filters );
		CC_Csv::stream( 'students-' . gmdate( 'Ymd-His' ) . '.csv', self::CSV_HEADER, self::export_rows( $filters ) );
	}

	public static function handle_profile(): void {
		$user_id = isset( $_POST['user_id'] ) ? absint( wp_unslash( $_POST['user_id'] ) ) : 0;
		check_admin_referer( 'cc_student_profile_' . $user_id );
		self::require_cap( 'cc_manage_students' );
		$input = array();
		foreach ( CC_Student_Ops::PROFILE_FIELDS as $field ) {
			$input[ $field ] = isset( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : '';
		}
		$result = CC_Student_Ops::update_profile( $user_id, $input, get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			if ( 'cc_invalid' === $result->get_error_code() ) {
				set_transient( CC_Student_Ops::PROFILE_ERR_KEY . get_current_user_id() . '_' . $user_id, array( 'errors' => $result->get_error_data(), 'input' => $input ), 5 * MINUTE_IN_SECONDS );
			}
			self::redirect( $user_id, 'cc_invalid' === $result->get_error_code() ? 'profile_invalid' : 'profile_failed' );
		}
		self::redirect( $user_id, $result ? 'profile_ok' : 'profile_same' );
	}

	public static function handle_move(): void {
		$enrollment_id = isset( $_POST['enrollment_id'] ) ? absint( wp_unslash( $_POST['enrollment_id'] ) ) : 0;
		check_admin_referer( 'cc_student_move_' . $enrollment_id );
		self::require_cap( 'cc_manage_students' );
		global $wpdb;
		$target  = isset( $_POST['target_batch_id'] ) ? absint( wp_unslash( $_POST['target_batch_id'] ) ) : 0;
		$user_id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT user_id FROM ' . $wpdb->prefix . 'cc_enrollments WHERE id = %d', $enrollment_id ) );
		$result  = CC_Student_Ops::move_batch( $enrollment_id, $target, get_current_user_id() );
		$notice  = true === $result ? 'move_ok' : ( self::MOVE_NOTICE_FOR_CODE[ $result->get_error_code() ] ?? 'move_failed' );
		self::redirect( $user_id, $notice );
	}

	/** Entry point of the list's bulk form: ids arrive in POST only. */
	public static function handle_bulk(): void {
		check_admin_referer( 'cc_students_bulk', 'cc_bulk_nonce' );
		self::require_cap( 'cc_view_students' );
		$choice = isset( $_POST['bulk'] ) ? sanitize_key( wp_unslash( $_POST['bulk'] ) ) : '';
		if ( ! isset( self::BULK_ACTIONS[ $choice ] ) ) {
			self::redirect_list( 'bulk_none' );
		}
		self::require_cap( self::BULK_ACTIONS[ $choice ][1] );
		$ids   = CC_Student_Ops::clean_user_ids( isset( $_POST['user_ids'] ) ? wp_unslash( $_POST['user_ids'] ) : array() );
		$scope = isset( $_POST['scope_batch_id'] ) ? absint( wp_unslash( $_POST['scope_batch_id'] ) ) : 0;
		if ( ! $ids ) {
			self::redirect_list( 'bulk_none' );
		}
		if ( 'export' === $choice ) {
			CC_Audit::log( 'student.export', 'student', 0, array( 'selected' => count( $ids ) ), 'selected' );
			CC_Csv::stream( 'students-selected-' . gmdate( 'Ymd-His' ) . '.csv', self::CSV_HEADER, self::export_rows( array( 'user_ids' => $ids ) ) );
		}
		if ( 'notice' === $choice ) {
			$batches = CC_Student_Ops::audience_batches( $ids );
			wp_safe_redirect( add_query_arg( array( 'page' => CC_Admin_Notices::PAGE, 'view' => 'edit', 'cc_prefill_batches' => $batches ), admin_url( 'admin.php' ) ) );
			exit;
		}
		$token = CC_Student_Ops::stage_bulk( $ids, $scope, get_current_user_id() );
		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_SLUG, 'cc_bulk' => $token ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function handle_bulk_deactivate(): void {
		check_admin_referer( 'cc_students_bulk_deactivate' );
		self::require_cap( 'cc_manage_students' );
		$token  = isset( $_POST['cc_bulk'] ) ? sanitize_key( wp_unslash( $_POST['cc_bulk'] ) ) : '';
		$staged = CC_Student_Ops::staged_bulk( $token, get_current_user_id() );
		if ( null === $staged ) {
			self::redirect_list( 'bulk_expired' );
		}
		CC_Student_Ops::discard_bulk( $token );
		$count = CC_Student_Ops::deactivate_selected( $staged['user_ids'], $staged['batch'], get_current_user_id() );
		self::redirect_list( 'bulk_done', array( 'cc_n' => $count ) );
	}

	/** JSON {rows_html,total,pages,page} for the live search. Read-only, so the nonce travels in the query string. */
	public static function handle_ajax_search(): void {
		check_ajax_referer( self::AJAX_ACTION, 'nonce' );
		if ( ! current_user_can( 'cc_view_students' ) ) {
			wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
		}
		wp_send_json_success( self::ajax_payload( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification -- verified above.
	}

	/**
	 * @param array<string,mixed> $source Request parameters: s, batch, status, payment_state, page.
	 * @return array{rows_html:string,total:int,pages:int,page:int}
	 */
	public static function ajax_payload( array $source ): array {
		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		require_once __DIR__ . '/class-students-table.php';
		$filters = self::filters_from(
			array(
				's'             => $source['s'] ?? '',
				'batch_id'      => $source['batch'] ?? 0,
				'status'        => $source['status'] ?? '',
				'payment_state' => $source['payment_state'] ?? '',
				'orderby'       => $source['orderby'] ?? '',
				'order'         => $source['order'] ?? '',
			)
		);
		$page   = max( 1, absint( $source['page'] ?? 1 ) );
		$result = self::query( array_merge( $filters, array( 'paged' => $page ) ) );
		$pages  = max( 1, (int) ceil( $result['total'] / self::PER_PAGE ) );
		if ( $page > $pages ) {
			$page   = $pages;
			$result = self::query( array_merge( $filters, array( 'paged' => $page ) ) );
		}
		$table = new CC_Students_Table();
		return array(
			'rows_html' => $table->rows_html( $result['items'] ),
			'total'     => $result['total'],
			'pages'     => $pages,
			'page'      => $page,
		);
	}

	/** @param array<string,mixed> $filters Roster filters, optionally with user_ids. */
	public static function export_rows( array $filters ): Generator {
		$page = 1;
		do {
			$result = self::query( array_merge( $filters, array( 'paged' => $page, 'per_page' => self::EXPORT_CHUNK ) ) );
			foreach ( $result['items'] as $row ) {
				yield array(
					(string) $row['full_name'],
					CC_Csv::phone( self::display_phone( (string) $row['user_login'] ) ),
					(string) $row['batches'],
					(string) $row['enrollment_status'],
					(string) $row['joined_at'],
					(string) ( $row['sms_status'] ?? '' ),
				);
			}
			++$page;
		} while ( count( $result['items'] ) === self::EXPORT_CHUNK );
	}

	private static function require_cap( string $cap ): void {
		if ( ! current_user_can( $cap ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'coaching-platform' ), '', array( 'response' => 403 ) );
		}
	}

	private static function redirect( int $user_id, string $notice ): void {
		$args = array( 'page' => self::PAGE_SLUG, 'cc_notice' => $notice );
		if ( $user_id > 0 ) {
			$args['user'] = $user_id;
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/** @param array<string,int|string> $extra */
	private static function redirect_list( string $notice, array $extra = array() ): void {
		wp_safe_redirect( add_query_arg( array_merge( array( 'page' => self::PAGE_SLUG, 'cc_notice' => $notice ), $extra ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/** Non-empty filters keyed the way the handler reads them (`s` for the search term). @param array<string,mixed> $source @return array<string,mixed> */
	public static function export_link_args( array $source ): array {
		$filters      = self::filters_from( $source );
		$filters['s'] = $filters['search'];
		unset( $filters['search'] );
		return array_filter( $filters );
	}

	/** The search term is read from `s` (list form) or `search`. @param array<string,mixed> $source @return array<string,mixed> */
	public static function filters_from( array $source ): array {
		return array(
			'search'   => sanitize_text_field( wp_unslash( (string) ( $source['s'] ?? $source['search'] ?? '' ) ) ),
			'batch_id' => isset( $source['batch_id'] ) ? absint( $source['batch_id'] ) : 0,
			'status'   => isset( $source['status'] ) ? sanitize_key( wp_unslash( (string) $source['status'] ) ) : '',
			'payment_state' => isset( $source['payment_state'] ) ? sanitize_key( wp_unslash( (string) $source['payment_state'] ) ) : '',
			'orderby'  => isset( $source['orderby'] ) ? sanitize_key( wp_unslash( (string) $source['orderby'] ) ) : '',
			'order'    => isset( $source['order'] ) ? sanitize_key( wp_unslash( (string) $source['order'] ) ) : '',
		);
	}

	/* ---------------------------------------------------------------- rendering */

	public static function render(): void {
		if ( ! current_user_can( 'cc_view_students' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'coaching-platform' ), '', array( 'response' => 403 ) );
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'Students', 'coaching-platform' ) . '</h1>';
		self::render_notice();
		$user_id = isset( $_GET['user'] ) ? absint( $_GET['user'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification -- read-only view.
		$bulk = isset( $_GET['cc_bulk'] ) ? sanitize_key( wp_unslash( $_GET['cc_bulk'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- token is single use, bound to the actor and only previews.
		if ( $user_id > 0 ) {
			self::render_detail( $user_id );
		} elseif ( '' !== $bulk ) {
			self::render_bulk_confirm( $bulk );
		} else {
			self::render_list();
		}
		echo '</div>';
	}

	private static function render_notice(): void {
		$code = isset( $_GET['cc_notice'] ) ? sanitize_key( wp_unslash( $_GET['cc_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- display only, whitelisted.
		if ( ! isset( self::NOTICES[ $code ] ) ) {
			return;
		}
		$message = self::NOTICES[ $code ][1];
		if ( 'bulk_done' === $code && isset( $_GET['cc_n'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- display only.
			$message = sprintf( '%d enrollment(s) deactivated.', absint( $_GET['cc_n'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		}
		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( self::NOTICES[ $code ][0] ), esc_html( $message ) );
	}

	private static function render_list(): void {
		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		require_once __DIR__ . '/class-students-table.php';
		$table = new CC_Students_Table();
		$table->prepare_items();
		$filters = self::filters_from( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification -- read-only filters.
		if ( current_user_can( 'cc_export_data' ) ) {
			$url = wp_nonce_url( add_query_arg( array_merge( array( 'action' => 'cc_students_export' ), self::export_link_args( $_GET ) ), admin_url( 'admin-post.php' ) ), 'cc_students_export' ); // phpcs:ignore WordPress.Security.NonceVerification -- filters only.
			printf( '<a class="page-title-action" id="cc-students-export" href="%s">%s</a>', esc_url( $url ), esc_html__( 'Export CSV', 'coaching-platform' ) );
		}
		$table->render_filters( $filters );
		printf( '<p id="cc-students-status" class="cc-students-status" role="status" aria-live="polite" data-pages="%d">%s</p>', max( 1, (int) ceil( $table->total_items() / self::PER_PAGE ) ), esc_html( sprintf( '%d students found', $table->total_items() ) ) );
		echo '<nav id="cc-students-pager" class="cc-students-pager" aria-label="' . esc_attr__( 'Students pages', 'coaching-platform' ) . '" hidden></nav>';
		echo '<form method="post" id="cc-students-bulk" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="cc_students_bulk" />';
		wp_nonce_field( 'cc_students_bulk', 'cc_bulk_nonce' );
		echo '<input type="hidden" name="scope_batch_id" value="' . esc_attr( (string) $filters['batch_id'] ) . '" />';
		$table->render_bulk_bar();
		$table->display();
		echo '</form>';
	}

	/** Confirm step of "Deactivate selected enrollments": the selection comes from the staged token, never from the URL. */
	private static function render_bulk_confirm( string $token ): void {
		$back = '<p><a href="' . esc_url( add_query_arg( 'page', self::PAGE_SLUG, admin_url( 'admin.php' ) ) ) . '">&larr; ' . esc_html__( 'All students', 'coaching-platform' ) . '</a></p>';
		$staged = current_user_can( 'cc_manage_students' ) ? CC_Student_Ops::staged_bulk( $token, get_current_user_id() ) : null;
		if ( null === $staged ) {
			echo $back . '<p>' . esc_html__( 'This confirmation expired or is not yours. Select the students again.', 'coaching-platform' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- $back is escaped above.
			return;
		}
		$enrollments = CC_Student_Ops::active_enrollments( $staged['user_ids'], $staged['batch'] );
		$students    = count( array_unique( array_column( $enrollments, 'user_id' ) ) );
		$batch_name  = '';
		if ( $staged['batch'] > 0 ) {
			$batch_name = (string) ( CC_Admin_Applications::batch_options()[ $staged['batch'] ] ?? '#' . $staged['batch'] );
		}
		echo $back; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
		echo '<h2>' . esc_html__( 'Deactivate selected enrollments', 'coaching-platform' ) . '</h2>';
		$scope = '' !== $batch_name
			? sprintf( 'Only ACTIVE enrollments in the batch "%s" (the batch filter currently set) will be deactivated. Their enrollments in other batches stay active.', $batch_name )
			: 'No batch filter is set, so ALL active enrollments of the selected students, in EVERY batch, will be deactivated.';
		printf( '<p><strong>%s</strong></p><p>%s</p>', esc_html( sprintf( '%d active enrollment(s) of %d student(s) of the %d selected.', count( $enrollments ), $students, count( $staged['user_ids'] ) ) ), esc_html( $scope ) );
		echo '<p class="description">' . esc_html__( 'Deactivated students lose access to courses, live classes and batch notices. Seats are not freed and payments are not changed. Each enrollment can be reactivated later.', 'coaching-platform' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="cc_students_bulk_deactivate" /><input type="hidden" name="cc_bulk" value="' . esc_attr( $token ) . '" />';
		wp_nonce_field( 'cc_students_bulk_deactivate' );
		if ( $enrollments ) {
			echo '<button type="submit" class="button button-primary">' . esc_html__( 'Confirm and deactivate', 'coaching-platform' ) . '</button> ';
		}
		echo '<a class="button" href="' . esc_url( add_query_arg( 'page', self::PAGE_SLUG, admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Cancel', 'coaching-platform' ) . '</a></form>';
	}

	private static function render_detail( int $user_id ): void {
		global $wpdb;
		$student = CC_Enrollment_Repository::student_row( $user_id );
		$user    = get_userdata( $user_id );
		$back    = '<p><a href="' . esc_url( add_query_arg( 'page', self::PAGE_SLUG, admin_url( 'admin.php' ) ) ) . '">&larr; ' . esc_html__( 'All students', 'coaching-platform' ) . '</a></p>';
		if ( null === $student || ! $user instanceof WP_User ) {
			echo $back . '<p>' . esc_html__( 'Student not found.', 'coaching-platform' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- $back is escaped above.
			return;
		}
		echo $back; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
		printf( '<h2>%s</h2><p>%s &middot; %s</p>', esc_html( (string) $student['full_name'] ), esc_html( self::display_phone( $user->user_login ) ), esc_html( sprintf( 'Joined %s UTC', $student['created_at'] ) ) );

		$can_manage = current_user_can( 'cc_manage_students' );
		if ( $can_manage ) {
			$needs_password = 1 === (int) $student['must_change_pw'];
			self::post_form( 'cc_student_resend', 'cc_student_resend_' . $user_id, array( 'user_id' => $user_id ), $needs_password ? __( 'Resend credentials', 'coaching-platform' ) : __( 'Send login hint', 'coaching-platform' ) );
		}

		if ( $can_manage ) {
			self::render_profile_form( $user_id, $student, $user );
		}

		echo '<h3>' . esc_html__( 'Enrollments', 'coaching-platform' ) . '</h3><table class="widefat striped"><thead><tr><th>Course</th><th>Batch</th><th>Status</th><th>Enrolled</th><th></th></tr></thead><tbody>';
		foreach ( CC_Enrollment_Repository::for_user( $user_id ) as $e ) {
			$is_off = 'deactivated' === $e['status'];
			printf( '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>', esc_html( (string) $e['course_title'] ), esc_html( (string) $e['batch_name'] ), esc_html( (string) $e['status'] ), esc_html( (string) $e['enrolled_at'] ) );
			if ( $can_manage ) {
				self::post_form( 'cc_student_status', 'cc_student_status_' . (int) $e['id'], array( 'enrollment_id' => (int) $e['id'], 'status' => $is_off ? 'active' : 'deactivated' ), $is_off ? __( 'Reactivate', 'coaching-platform' ) : __( 'Deactivate', 'coaching-platform' ) );
				self::render_move_form( (int) $e['id'] );
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';

		self::render_payments( $user_id );
		self::render_sms_log( $user_id );
	}

	/** @param array<string,mixed> $student */
	private static function render_profile_form( int $user_id, array $student, WP_User $user ): void {
		$key    = CC_Student_Ops::PROFILE_ERR_KEY . get_current_user_id() . '_' . $user_id;
		$failed = get_transient( $key );
		$errors = is_array( $failed ) ? (array) $failed['errors'] : array();
		$values = is_array( $failed ) ? (array) $failed['input'] : $student;
		if ( is_array( $failed ) ) {
			delete_transient( $key );
		}
		$labels = array( 'full_name' => 'Full name', 'guardian_name' => 'Guardian name', 'guardian_phone' => 'Guardian phone', 'institution' => 'Institution' );
		echo '<h3>' . esc_html__( 'Profile', 'coaching-platform' ) . '</h3><form method="post" class="cc-form" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="cc_student_profile" /><input type="hidden" name="user_id" value="' . esc_attr( (string) $user_id ) . '" />';
		wp_nonce_field( 'cc_student_profile_' . $user_id );
		echo '<table class="form-table" role="presentation">';
		foreach ( $labels as $field => $label ) {
			printf(
				'<tr><th scope="row"><label for="cc-prof-%1$s">%2$s</label></th><td><input type="text" class="regular-text" id="cc-prof-%1$s" name="%1$s" value="%3$s" maxlength="190" required%4$s />%5$s</td></tr>',
				esc_attr( $field ),
				esc_html( $label ),
				esc_attr( (string) ( $values[ $field ] ?? '' ) ),
				isset( $errors[ $field ] ) ? ' aria-invalid="true" aria-describedby="cc-prof-' . esc_attr( $field ) . '-err"' : '',
				isset( $errors[ $field ] ) ? '<p class="description cc-field-error" id="cc-prof-' . esc_attr( $field ) . '-err" role="alert">' . esc_html( (string) $errors[ $field ] ) . '</p>' : ''
			);
		}
		printf(
			'<tr><th scope="row">%s</th><td><code>%s</code><p class="description">%s</p></td></tr>',
			esc_html__( 'Login number', 'coaching-platform' ),
			esc_html( self::display_phone( $user->user_login ) ),
			esc_html__( 'The login number cannot be changed here. To change a login number the student must apply again or contact the owner. The applicant email is not editable either.', 'coaching-platform' )
		);
		echo '</table><button type="submit" class="button button-primary">' . esc_html__( 'Save profile', 'coaching-platform' ) . '</button></form>';
	}

	private static function render_move_form( int $enrollment_id ): void {
		$targets = CC_Student_Ops::move_targets( $enrollment_id );
		echo '<details class="cc-move"><summary>' . esc_html__( 'Move to another batch', 'coaching-platform' ) . '</summary>';
		if ( ! $targets ) {
			echo '<p>' . esc_html__( 'No other batch can take this student.', 'coaching-platform' ) . '</p></details>';
			return;
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="cc_student_move" /><input type="hidden" name="enrollment_id" value="' . esc_attr( (string) $enrollment_id ) . '" />';
		wp_nonce_field( 'cc_student_move_' . $enrollment_id );
		echo '<select name="target_batch_id" aria-label="' . esc_attr__( 'Target batch', 'coaching-platform' ) . '" required><option value="">' . esc_html__( 'Choose a batch', 'coaching-platform' ) . '</option>';
		$any_price_differs = false;
		foreach ( $targets as $t ) {
			$blocked           = $t['seats_left'] < 1 || $t['already_enrolled'];
			$any_price_differs = $any_price_differs || $t['price_differs'];
			$label             = sprintf(
				'%s / %s / %s / %s BDT / %s%s%s',
				'' !== (string) $t['course_title'] ? $t['course_title'] : 'No course',
				$t['name'],
				$t['delivery_mode'],
				$t['price'],
				sprintf( '%d seats left', $t['seats_left'] ),
				$t['price_differs'] ? ' / price differs' : '',
				$t['already_enrolled'] ? ' / already enrolled' : ''
			);
			printf( '<option value="%d"%s>%s</option>', (int) $t['id'], disabled( $blocked, true, false ), esc_html( $label ) );
		}
		echo '</select> <button type="submit" class="button">' . esc_html__( 'Move', 'coaching-platform' ) . '</button>';
		if ( $any_price_differs ) {
			echo '<p class="description">' . esc_html__( 'Batches marked "price differs" cost a different amount. No money is moved: adjust fees manually.', 'coaching-platform' ) . '</p>';
		}
		echo '<p class="description">' . esc_html__( 'The application keeps its original batch as the admission record.', 'coaching-platform' ) . '</p></form></details>';
	}

	/** @param array<string,int|string> $fields */
	private static function post_form( string $action, string $nonce_action, array $fields, string $label ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">';
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '" />';
		foreach ( $fields as $name => $value ) {
			echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '" />';
		}
		wp_nonce_field( $nonce_action );
		echo '<button type="submit" class="button">' . esc_html( $label ) . '</button></form>';
	}

	private static function render_payments( int $user_id ): void {
		global $wpdb;
		$p = $wpdb->prefix;
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names only.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT inv.number, pay.amount, pay.method, pay.status, pay.trx_id, pay.created_at
				FROM {$p}cc_applications app
				JOIN {$p}cc_invoices inv ON inv.application_id = app.id
				JOIN {$p}cc_payments pay ON pay.invoice_id = inv.id
				WHERE app.user_id = %d ORDER BY pay.created_at DESC, pay.id DESC LIMIT %d",
				$user_id,
				self::PAYMENT_LIMIT
			),
			ARRAY_A
		);
		// phpcs:enable
		echo '<h3>' . esc_html__( 'Payments', 'coaching-platform' ) . '</h3><table class="widefat striped"><thead><tr><th>Invoice</th><th>Amount</th><th>Method</th><th>Status</th><th>Trx</th><th>Created</th></tr></thead><tbody>';
		foreach ( is_array( $rows ) ? $rows : array() as $r ) {
			printf( '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>', esc_html( (string) $r['number'] ), esc_html( (string) $r['amount'] ), esc_html( (string) $r['method'] ), esc_html( (string) $r['status'] ), esc_html( (string) $r['trx_id'] ), esc_html( (string) $r['created_at'] ) );
		}
		echo '</tbody></table>';
	}

	private static function render_sms_log( int $user_id ): void {
		global $wpdb;
		$p = $wpdb->prefix;
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names only.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l.to_phone, l.template, l.status, l.created_at FROM {$p}cc_sms_log l
				WHERE (l.related_type = 'student' AND l.related_id = %d)
				OR (l.related_type = 'application' AND l.related_id IN (SELECT a.id FROM {$p}cc_applications a WHERE a.user_id = %d))
				ORDER BY l.id DESC LIMIT %d",
				$user_id,
				$user_id,
				self::SMS_LOG_LIMIT
			),
			ARRAY_A
		);
		// phpcs:enable
		echo '<h3>' . esc_html__( 'SMS log', 'coaching-platform' ) . '</h3><table class="widefat striped"><thead><tr><th>To</th><th>Template</th><th>Status</th><th>Sent</th></tr></thead><tbody>';
		foreach ( is_array( $rows ) ? $rows : array() as $r ) {
			printf( '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>', esc_html( CC_Phone::mask( (string) $r['to_phone'] ) ), esc_html( (string) $r['template'] ), esc_html( (string) $r['status'] ), esc_html( (string) $r['created_at'] ) );
		}
		echo '</tbody></table>';
	}
}
