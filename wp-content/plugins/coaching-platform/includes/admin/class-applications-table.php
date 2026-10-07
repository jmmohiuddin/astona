<?php
defined( 'ABSPATH' ) || exit;

/**
 * WP_List_Table for applications. Loaded lazily by CC_Admin_Applications::render_list(), after the core base class.
 */
final class CC_Applications_Table extends WP_List_Table {

	public function __construct() {
		parent::__construct( array( 'singular' => 'application', 'plural' => 'applications', 'ajax' => false ) );
	}

	public function get_columns(): array {
		return array(
			'cb'         => '<input type="checkbox">',
			'public_ref' => 'Reference',
			'full_name'  => 'Name',
			'phone'      => 'Phone',
			'batch'      => 'Batch',
			'status'     => 'Status',
			'payment'    => 'Payment',
			'created_at' => 'Submitted',
		);
	}

	protected function get_sortable_columns(): array {
		return array(
			'public_ref' => array( 'public_ref', false ),
			'full_name'  => array( 'full_name', false ),
			'status'     => array( 'status', false ),
			'created_at' => array( 'created_at', true ),
		);
	}

	protected function get_bulk_actions(): array {
		return current_user_can( CC_Admin_Applications::CAP_REVIEW ) ? array( 'reject' => 'Reject' ) : array();
	}

	protected function get_views(): array {
		$counts  = CC_Admin_Applications::status_counts();
		$current = sanitize_key( wp_unslash( $_GET['status'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$views   = array();
		foreach ( array_merge( array( 'all' ), CC_Admin_Applications::STATUSES ) as $status ) {
			$url             = add_query_arg( array( 'page' => CC_Admin_Applications::PAGE, 'status' => 'all' === $status ? false : $status ), admin_url( 'admin.php' ) );
			$is_current      = ( 'all' === $status && '' === $current ) || $status === $current;
			$views[ $status ] = sprintf(
				'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
				esc_url( $url ),
				$is_current ? ' class="current" aria-current="page"' : '',
				esc_html( ucfirst( $status ) ),
				$counts[ $status ]
			);
		}
		return $views;
	}

	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which ) {
			return;
		}
		$selected = absint( $_GET['batch_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<div class="alignleft actions"><label class="screen-reader-text" for="cc-batch">Batch</label><select name="batch_id" id="cc-batch"><option value="0">All batches</option>';
		foreach ( CC_Admin_Applications::batch_options() as $id => $name ) {
			printf( '<option value="%d"%s>%s</option>', (int) $id, selected( $selected, (int) $id, false ), esc_html( $name ) );
		}
		echo '</select>';
		foreach ( array( 'date_from' => 'From', 'date_to' => 'To' ) as $key => $label ) {
			$value = sanitize_text_field( wp_unslash( $_GET[ $key ] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			printf( ' <label for="cc-%1$s">%2$s</label> <input type="date" id="cc-%1$s" name="%1$s" value="%3$s">', esc_attr( $key ), esc_html( $label ), esc_attr( $value ) );
		}
		submit_button( 'Filter', '', 'filter_action', false );
		echo '</div>';
	}

	public function prepare_items(): void {
		$args   = CC_Admin_Applications::filters_from_request( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$args  += array(
			'per_page' => CC_Admin_Applications::PER_PAGE,
			'paged'    => $this->get_pagenum(),
		);
		$result = CC_Admin_Applications::query( $args );

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );
		$this->items           = $result['items'];
		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => CC_Admin_Applications::PER_PAGE,
			)
		);
	}

	protected function column_cb( $item ): string {
		return sprintf( '<input type="checkbox" name="application[]" value="%d">', (int) $item['id'] );
	}

	protected function column_public_ref( $item ): string {
		$id      = (int) $item['id'];
		$view    = add_query_arg( array( 'page' => CC_Admin_Applications::PAGE, 'view' => $id ), admin_url( 'admin.php' ) );
		$actions = array( 'view' => sprintf( '<a href="%s">View</a>', esc_url( $view ) ) );
		if ( 'pending' === $item['status'] && current_user_can( CC_Admin_Applications::CAP_REVIEW ) ) {
			$actions['reject'] = sprintf( '<a href="%s#reject">Reject</a>', esc_url( $view ) );
		}
		return sprintf( '<strong><a href="%s">%s</a></strong>%s', esc_url( $view ), esc_html( $item['public_ref'] ), $this->row_actions( $actions ) );
	}

	protected function column_default( $item, $column_name ): string {
		switch ( $column_name ) {
			case 'full_name':
				return esc_html( $item['full_name'] );
			case 'phone':
				return esc_html( CC_Admin_Applications::display_phone( $item['student_phone'] ) );
			case 'batch':
				return esc_html( (string) $item['batch_name'] );
			case 'status':
				return esc_html( ucfirst( $item['status'] ) );
			case 'payment':
				return esc_html( (string) ( $item['payment_status'] ?? '' ) );
			case 'created_at':
				return esc_html( $item['created_at'] );
		}
		return '';
	}

	public function no_items(): void {
		echo 'No applications found.';
	}
}
