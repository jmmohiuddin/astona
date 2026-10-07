<?php
defined( 'ABSPATH' ) || exit;

/**
 * Students list. Required lazily by CC_Admin_Students once WP_List_Table is loaded. The live-search endpoint renders its
 * rows through rows_html(), so the markup is the same as the server-rendered page.
 */
final class CC_Students_Table extends WP_List_Table {

	public function __construct() {
		parent::__construct( array( 'singular' => 'student', 'plural' => 'students', 'ajax' => false ) );
	}

	public static function can_bulk(): bool {
		foreach ( CC_Admin_Students::BULK_ACTIONS as $def ) {
			if ( current_user_can( $def[1] ) ) {
				return true;
			}
		}
		return false;
	}

	public function get_columns(): array {
		$columns = array(
			'name'    => __( 'Name', 'coaching-platform' ),
			'phone'   => __( 'Phone', 'coaching-platform' ),
			'batches' => __( 'Batches', 'coaching-platform' ),
			'status'  => __( 'Enrollment', 'coaching-platform' ),
			'payment' => __( 'Payment', 'coaching-platform' ),
			'joined'  => __( 'Joined', 'coaching-platform' ),
			'sms'     => __( 'Credentials SMS', 'coaching-platform' ),
		);
		return self::can_bulk() ? array_merge( array( 'cb' => '<input type="checkbox" />' ), $columns ) : $columns;
	}

	protected function get_sortable_columns(): array {
		return array(
			'name'   => array( 'name', false ),
			'joined' => array( 'joined', true ),
		);
	}

	public function prepare_items(): void {
		$filters = CC_Admin_Students::filters_from( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification -- read-only filters.
		$paged   = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification
		$result  = CC_Admin_Students::query( array_merge( $filters, array( 'paged' => $paged ) ) );

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );
		$this->items           = $result['items'];
		$this->set_pagination_args( array( 'total_items' => $result['total'], 'per_page' => CC_Admin_Students::PER_PAGE ) );
	}

	public function total_items(): int {
		return (int) $this->get_pagination_arg( 'total_items' );
	}

	/** @param array<int,array<string,mixed>> $items The rows (`<tr>` elements) exactly as display() prints them. */
	public function rows_html( array $items ): string {
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );
		$this->items           = $items;
		ob_start();
		$this->display_rows_or_placeholder();
		return (string) ob_get_clean();
	}

	/** @param array<string,mixed> $item */
	protected function column_cb( $item ): string {
		return sprintf(
			'<input type="checkbox" name="user_ids[]" value="%d" aria-label="%s" />',
			(int) $item['user_id'],
			esc_attr( sprintf( __( 'Select %s', 'coaching-platform' ), (string) $item['full_name'] ) )
		);
	}

	/** @param array<string,mixed> $item */
	protected function column_name( $item ): string {
		$view    = add_query_arg( array( 'page' => CC_Admin_Students::PAGE_SLUG, 'user' => (int) $item['user_id'] ), admin_url( 'admin.php' ) );
		$actions = array( 'view' => '<a href="' . esc_url( $view ) . '">' . esc_html__( 'View', 'coaching-platform' ) . '</a>' );
		return '<strong><a href="' . esc_url( $view ) . '">' . esc_html( (string) $item['full_name'] ) . '</a></strong>' . $this->row_actions( $actions );
	}

	/** @param array<string,mixed> $item */
	protected function column_default( $item, $column_name ): string {
		switch ( $column_name ) {
			case 'phone':
				return esc_html( CC_Admin_Students::display_phone( (string) $item['user_login'] ) );
			case 'batches':
				return esc_html( (string) $item['batches'] );
			case 'status':
				return esc_html( (string) $item['enrollment_status'] );
			case 'payment':
				return esc_html( (string) $item['payment_state'] );
			case 'joined':
				return esc_html( (string) $item['joined_at'] );
			case 'sms':
				return esc_html( (string) ( $item['sms_status'] ?? '' ) );
		}
		return '';
	}

	public function no_items(): void {
		esc_html_e( 'No students found.', 'coaching-platform' );
	}

	/**
	 * Search and filters: a plain GET form (works without JavaScript; admin-students.js upgrades it to live search).
	 *
	 * @param array<string,mixed> $filters From CC_Admin_Students::filters_from().
	 */
	public function render_filters( array $filters ): void {
		global $wpdb;
		$batches = $wpdb->get_results( 'SELECT id, name FROM ' . $wpdb->prefix . 'cc_batches ORDER BY id DESC LIMIT 200', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL -- no input.
		echo '<form method="get" id="cc-students-filter" class="cc-students-filter"><input type="hidden" name="page" value="' . esc_attr( CC_Admin_Students::PAGE_SLUG ) . '" />';
		echo '<label class="screen-reader-text" for="cc-student-search">' . esc_html__( 'Search name or phone', 'coaching-platform' ) . '</label>';
		echo '<input type="search" id="cc-student-search" name="s" value="' . esc_attr( (string) $filters['search'] ) . '" placeholder="' . esc_attr__( 'Search name or phone', 'coaching-platform' ) . '" autocomplete="off" /> ';
		echo '<select name="batch_id" aria-label="' . esc_attr__( 'Batch', 'coaching-platform' ) . '"><option value="0">' . esc_html__( 'All batches', 'coaching-platform' ) . '</option>';
		foreach ( is_array( $batches ) ? $batches : array() as $b ) {
			printf( '<option value="%d"%s>%s</option>', (int) $b['id'], selected( (int) $filters['batch_id'], (int) $b['id'], false ), esc_html( (string) $b['name'] ) );
		}
		echo '</select> <select name="status" aria-label="' . esc_attr__( 'Enrollment status', 'coaching-platform' ) . '"><option value="">' . esc_html__( 'All statuses', 'coaching-platform' ) . '</option>';
		foreach ( array( 'active', 'deactivated', 'expired', 'completed' ) as $s ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $s ), selected( $filters['status'], $s, false ), esc_html( ucfirst( $s ) ) );
		}
		echo '</select> <select name="payment_state" aria-label="' . esc_attr__( 'Payment state', 'coaching-platform' ) . '"><option value="">' . esc_html__( 'All payment states', 'coaching-platform' ) . '</option>';
		foreach ( CC_Admin_Students::PAYMENT_STATES as $key => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $key ), selected( $filters['payment_state'], $key, false ), esc_html( $label ) );
		}
		echo '</select> ';
		submit_button( __( 'Filter', 'coaching-platform' ), '', 'filter_action', false );
		echo '</form>';
	}

	/** Bulk actions the current user may run; rendered inside the POST form that wraps the table. */
	public function render_bulk_bar(): void {
		if ( ! self::can_bulk() ) {
			return;
		}
		echo '<div class="cc-bulk-bar"><label class="screen-reader-text" for="cc-bulk-action">' . esc_html__( 'Bulk action for the selected students on this page', 'coaching-platform' ) . '</label><select name="bulk" id="cc-bulk-action"><option value="">' . esc_html__( 'Bulk actions', 'coaching-platform' ) . '</option>';
		foreach ( CC_Admin_Students::BULK_ACTIONS as $key => $def ) {
			if ( current_user_can( $def[1] ) ) {
				printf( '<option value="%s">%s</option>', esc_attr( $key ), esc_html( $def[0] ) );
			}
		}
		echo '</select> <button type="submit" class="button action">' . esc_html__( 'Apply', 'coaching-platform' ) . '</button></div>';
	}
}
