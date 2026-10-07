<?php
defined( 'ABSPATH' ) || exit;

/**
 * Admin screen "Inquiries": status tabs, search, date filter, detail view, status changes and CSV export.
 * Phones are masked unless the viewer may manage students. Messages are untrusted text: always escaped on output.
 * Audit entries carry only ids, statuses and a hash; never a message, phone or note.
 */
final class CC_Admin_Inquiries {

	const PAGE_SLUG     = 'cc-inquiries';
	const ACTION_STATUS = 'cc_inquiry_status';
	const ACTION_EXPORT = 'cc_inquiry_export';
	const TABS          = array( 'new' => 'New', 'handled' => 'Handled', 'spam' => 'Spam', 'all' => 'All' );
	const AUDIT_ACTIONS = array( 'new' => 'inquiry.reopen', 'handled' => 'inquiry.handled', 'spam' => 'inquiry.spam' );
	const ROW_LABELS    = array( 'handled' => 'Mark handled', 'spam' => 'Mark spam', 'new' => 'Reopen' );

	const NOTICES = array(
		'updated' => array( 'success', 'Inquiry updated.' ),
		'failed'  => array( 'error', 'The inquiry could not be updated.' ),
	);

	public static function init(): void {
		add_action( 'admin_post_' . self::ACTION_STATUS, array( __CLASS__, 'handle_status' ) );
		add_action( 'admin_post_' . self::ACTION_EXPORT, array( __CLASS__, 'handle_export' ) );
	}

	/* ---------------------------------------------------------------- testable operations */

	public static function new_count(): int {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . CC_Inquiry_Repository::table() . " WHERE status = 'new'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name only.
	}

	public static function display_phone( string $e164 ): string {
		return current_user_can( CC_Admin_Roles::CAP_MANAGE_STUDENTS ) ? $e164 : CC_Phone::mask( $e164 );
	}

	/** @return bool|WP_Error */
	public static function change_status( int $id, string $status, int $actor, string $note = '' ) {
		if ( ! user_can( $actor, CC_Admin_Roles::CAP_MANAGE_INQUIRIES ) ) {
			return new WP_Error( 'cc_forbidden', 'You are not allowed to manage inquiries.' );
		}
		$before = CC_Inquiry_Repository::find( $id );
		$result = CC_Inquiry_Repository::set_status( $id, $status, $actor, $note );
		if ( true !== $result ) {
			return is_wp_error( $result ) ? $result : new WP_Error( 'cc_inquiry_failed', 'Could not update the inquiry.' );
		}
		CC_Audit::log( self::AUDIT_ACTIONS[ $status ], 'inquiry', $id, array( 'from' => (string) $before['status'], 'to' => $status, 'has_note' => '' !== $note ) );
		return true;
	}

	/**
	 * @param array<string,mixed> $src Query args (unslashed).
	 * @return array{status:string,search:string,from:string,to:string}
	 */
	public static function filters_from( array $src ): array {
		$status = isset( $src['status'] ) ? sanitize_key( (string) $src['status'] ) : 'new';
		$date   = static fn( string $key ): string => isset( $src[ $key ] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $src[ $key ] ) ? (string) $src[ $key ] : '';
		return array(
			'status' => isset( self::TABS[ $status ] ) ? $status : 'new',
			'search' => isset( $src['s'] ) ? sanitize_text_field( (string) $src['s'] ) : '',
			'from'   => $date( 'from' ),
			'to'     => $date( 'to' ),
		);
	}

	/** Repository query args for a filter set ("all" means no status filter). */
	public static function query_args( array $filters ): array {
		return array_merge( $filters, array( 'status' => 'all' === $filters['status'] ? '' : $filters['status'] ) );
	}

	/* ---------------------------------------------------------------- handlers */

	/**
	 * Reads id, status, to_detail and cc_nonce from the query string only. The list table's surrounding form posts its
	 * own _wpnonce, _wp_http_referer and status fields in the body, so none of those may take part.
	 */
	public static function handle_status(): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			wp_die( esc_html__( 'Invalid request.', 'coaching-platform' ), '', array( 'response' => 405 ) );
		}
		if ( ! current_user_can( CC_Admin_Roles::CAP_MANAGE_INQUIRIES ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'coaching-platform' ), '', array( 'response' => 403 ) );
		}
		$id    = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification -- verified below.
		$nonce = isset( $_GET['cc_nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['cc_nonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! wp_verify_nonce( $nonce, self::ACTION_STATUS . '_' . $id ) ) {
			wp_die( esc_html__( 'The link you followed has expired.', 'coaching-platform' ), '', array( 'response' => 403 ) );
		}
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$note   = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';
		$result = isset( self::AUDIT_ACTIONS[ $status ] ) ? self::change_status( $id, $status, get_current_user_id(), $note ) : new WP_Error( 'cc_inquiry_status', 'Unknown status.' );

		$args = array( 'page' => self::PAGE_SLUG, 'cc_notice' => is_wp_error( $result ) ? 'failed' : 'updated' );
		if ( ! empty( $_GET['to_detail'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$args['inquiry'] = $id;
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function handle_export(): void {
		self::authorize( CC_Admin_Roles::CAP_EXPORT_DATA, self::ACTION_EXPORT );
		$filters = self::filters_from( wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification -- verified in authorize().
		CC_Audit::log( 'inquiry.export', 'inquiry', 0, $filters );
		CC_Csv::stream( 'inquiries-' . gmdate( 'Ymd-His' ) . '.csv', CC_Inquiry_Repository::export_header(), CC_Inquiry_Repository::export_rows( self::query_args( $filters ) ) );
	}

	/* ---------------------------------------------------------------- rendering */

	public static function render(): void {
		if ( ! current_user_can( CC_Admin_Roles::CAP_VIEW_INQUIRIES ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'coaching-platform' ), 403 );
		}
		echo '<div class="wrap"><h1 class="wp-heading-inline">' . esc_html__( 'Inquiries', 'coaching-platform' ) . '</h1>';
		self::render_notice();
		$id = isset( $_GET['inquiry'] ) ? absint( $_GET['inquiry'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification -- display only.
		if ( $id > 0 ) {
			self::render_detail( $id );
		} else {
			self::render_list();
		}
		echo '</div>';
	}

	private static function render_list(): void {
		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		require_once __DIR__ . '/class-inquiries-table.php';
		$table = new CC_Inquiries_Table();
		$table->prepare_items();

		if ( current_user_can( CC_Admin_Roles::CAP_EXPORT_DATA ) ) {
			$args = array_filter( self::filters_from( wp_unslash( $_GET ) ) ); // phpcs:ignore WordPress.Security.NonceVerification -- filters only.
			$url  = wp_nonce_url( add_query_arg( array_merge( $args, array( 'action' => self::ACTION_EXPORT ) ), admin_url( 'admin-post.php' ) ), self::ACTION_EXPORT );
			printf( ' <a class="page-title-action" href="%s">%s</a>', esc_url( $url ), esc_html__( 'Export CSV', 'coaching-platform' ) );
		}
		echo '<hr class="wp-header-end">';
		$table->views();
		echo '<form method="get"><input type="hidden" name="page" value="' . esc_attr( self::PAGE_SLUG ) . '" />';
		echo '<input type="hidden" name="status" value="' . esc_attr( self::filters_from( wp_unslash( $_GET ) )['status'] ) . '" />'; // phpcs:ignore WordPress.Security.NonceVerification
		$table->search_box( __( 'Search name, phone or message', 'coaching-platform' ), 'cc-inquiry' );
		$table->display();
		echo '</form>';
	}

	private static function render_detail( int $id ): void {
		$back = '<p><a href="' . esc_url( add_query_arg( 'page', self::PAGE_SLUG, admin_url( 'admin.php' ) ) ) . '">&larr; ' . esc_html__( 'All inquiries', 'coaching-platform' ) . '</a></p>';
		$row  = CC_Inquiry_Repository::find( $id );
		echo $back; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
		if ( null === $row ) {
			echo '<p>' . esc_html__( 'Inquiry not found.', 'coaching-platform' ) . '</p>';
			return;
		}
		$course = ! empty( $row['course_id'] ) ? get_post( (int) $row['course_id'] ) : null;
		$by     = ! empty( $row['handled_by'] ) ? get_userdata( (int) $row['handled_by'] ) : false;

		echo '<div class="cc-panel"><h2>' . esc_html( (string) $row['name'] ) . '</h2><table class="form-table" role="presentation">';
		$cells = array(
			'Phone'    => esc_html( self::display_phone( (string) $row['phone'] ) ),
			'Email'    => '' === (string) $row['email'] ? '&mdash;' : esc_html( (string) $row['email'] ),
			'Topic'    => esc_html( (string) $row['topic'] ),
			'Course'   => $course instanceof WP_Post ? '<a href="' . esc_url( (string) get_edit_post_link( $course->ID, 'raw' ) ) . '">' . esc_html( get_the_title( $course ) ) . '</a>' : '&mdash;',
			'Received' => esc_html( (string) $row['created_at'] . ' UTC' ),
			'Status'   => esc_html( (string) $row['status'] ),
			'Handled'  => empty( $row['handled_at'] ) ? '&mdash;' : esc_html( (string) $row['handled_at'] . ' UTC' . ( $by ? ' by ' . $by->display_name : '' ) ),
			'Note'     => '' === (string) $row['staff_note'] ? '&mdash;' : nl2br( esc_html( (string) $row['staff_note'] ) ),
		);
		foreach ( $cells as $label => $html ) {
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . $html . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput -- every value is escaped above.
		}
		echo '</table><h3>' . esc_html__( 'Message', 'coaching-platform' ) . '</h3><div class="cc-inquiry-message">' . nl2br( esc_html( (string) $row['message'] ) ) . '</div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped.

		if ( current_user_can( CC_Admin_Roles::CAP_MANAGE_INQUIRIES ) ) {
			self::render_status_forms( $row );
		}
	}

	/** @param array<string,mixed> $row */
	private static function render_status_forms( array $row ): void {
		$id = (int) $row['id'];
		echo '<div class="cc-panel">';
		if ( 'handled' !== $row['status'] ) {
			echo '<form method="post" action="' . esc_url( self::action_url( $id, 'handled', true ) ) . '">';
			echo '<p><label for="cc-inquiry-note"><strong>' . esc_html__( 'Note (optional)', 'coaching-platform' ) . '</strong></label><br><textarea id="cc-inquiry-note" name="note" class="large-text" rows="3" maxlength="' . (int) CC_Inquiry_Repository::NOTE_MAX . '"></textarea></p>';
			submit_button( self::ROW_LABELS['handled'], 'primary', 'submit', false );
			echo '</form>';
		}
		foreach ( array( 'spam', 'new' ) as $status ) {
			if ( $status === $row['status'] ) {
				continue;
			}
			echo '<form method="post" action="' . esc_url( self::action_url( $id, $status, true ) ) . '" style="display:inline-block;margin-right:8px">';
			submit_button( self::ROW_LABELS[ $status ], 'secondary', 'submit', false );
			echo '</form>';
		}
		echo '</div>';
	}

	/** id, status and nonce travel in the query string under names the list table form does not use. */
	public static function action_url( int $id, string $status, bool $to_detail ): string {
		$args = array( 'action' => self::ACTION_STATUS, 'id' => $id, 'status' => $status, 'cc_nonce' => wp_create_nonce( self::ACTION_STATUS . '_' . $id ) );
		if ( $to_detail ) {
			$args['to_detail'] = 1;
		}
		return add_query_arg( $args, admin_url( 'admin-post.php' ) );
	}

	private static function render_notice(): void {
		$code = isset( $_GET['cc_notice'] ) ? sanitize_key( wp_unslash( $_GET['cc_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- display only, whitelisted.
		if ( isset( self::NOTICES[ $code ] ) ) {
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( self::NOTICES[ $code ][0] ), esc_html( self::NOTICES[ $code ][1] ) );
		}
	}

	private static function authorize( string $cap, string $nonce_action ): void {
		if ( ! current_user_can( $cap ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'coaching-platform' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $nonce_action );
	}
}
