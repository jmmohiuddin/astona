<?php
defined( 'ABSPATH' ) || exit;

/** Inquiries list. Required lazily by CC_Admin_Inquiries::render_list() once WP_List_Table is loaded. */
final class CC_Inquiries_Table extends WP_List_Table {

	const EXCERPT_WORDS = 14;

	public function __construct() {
		parent::__construct( array( 'singular' => 'inquiry', 'plural' => 'inquiries', 'ajax' => false ) );
	}

	public function get_columns(): array {
		return array(
			'name'     => __( 'Name', 'coaching-platform' ),
			'phone'    => __( 'Phone', 'coaching-platform' ),
			'topic'    => __( 'Topic', 'coaching-platform' ),
			'message'  => __( 'Message', 'coaching-platform' ),
			'received' => __( 'Received (UTC)', 'coaching-platform' ),
			'status'   => __( 'Status', 'coaching-platform' ),
		);
	}

	public function prepare_items(): void {
		$filters = CC_Admin_Inquiries::filters_from( wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification -- read-only filters.
		$paged   = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification
		$result  = CC_Inquiry_Repository::query( array_merge( CC_Admin_Inquiries::query_args( $filters ), array( 'page' => $paged ) ) );

		$this->_column_headers = array( $this->get_columns(), array(), array() );
		$this->items           = $result['items'];
		$this->set_pagination_args( array( 'total_items' => $result['total'], 'per_page' => CC_Inquiry_Repository::PER_PAGE ) );
	}

	/** @return array<string,string> */
	protected function get_views(): array {
		$counts  = CC_Inquiry_Repository::counts();
		$counts['all'] = array_sum( $counts );
		$current = CC_Admin_Inquiries::filters_from( wp_unslash( $_GET ) )['status']; // phpcs:ignore WordPress.Security.NonceVerification
		$views   = array();
		foreach ( CC_Admin_Inquiries::TABS as $key => $label ) {
			$url          = add_query_arg( array( 'page' => CC_Admin_Inquiries::PAGE_SLUG, 'status' => $key ), admin_url( 'admin.php' ) );
			$views[ $key ] = sprintf( '<a href="%s"%s>%s <span class="count">(%d)</span></a>', esc_url( $url ), $current === $key ? ' class="current" aria-current="page"' : '', esc_html( $label ), (int) $counts[ $key ] );
		}
		return $views;
	}

	/** @param array<string,mixed> $item */
	protected function column_name( $item ): string {
		$id      = (int) $item['id'];
		$view    = add_query_arg( array( 'page' => CC_Admin_Inquiries::PAGE_SLUG, 'inquiry' => $id ), admin_url( 'admin.php' ) );
		$actions = array( 'view' => '<a href="' . esc_url( $view ) . '">' . esc_html__( 'View', 'coaching-platform' ) . '</a>' );
		if ( current_user_can( CC_Admin_Roles::CAP_MANAGE_INQUIRIES ) ) {
			foreach ( CC_Admin_Inquiries::ROW_LABELS as $status => $label ) {
				if ( $status !== $item['status'] ) {
					$actions[ $status ] = $this->status_form( $id, $status, $label );
				}
			}
		}
		return '<strong><a href="' . esc_url( $view ) . '">' . esc_html( (string) $item['name'] ) . '</a></strong>' . $this->row_actions( $actions );
	}

	/** @param array<string,mixed> $item */
	protected function column_default( $item, $column_name ): string {
		switch ( $column_name ) {
			case 'phone':
				return esc_html( CC_Admin_Inquiries::display_phone( (string) $item['phone'] ) );
			case 'topic':
				return esc_html( (string) $item['topic'] );
			case 'message':
				return esc_html( wp_trim_words( (string) $item['message'], self::EXCERPT_WORDS ) );
			case 'received':
				return esc_html( (string) $item['created_at'] );
			case 'status':
				return esc_html( (string) $item['status'] );
		}
		return '';
	}

	public function no_items(): void {
		esc_html_e( 'No inquiries found.', 'coaching-platform' );
	}

	/** @param string $which */
	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which ) {
			return;
		}
		$filters = CC_Admin_Inquiries::filters_from( wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification
		echo '<div class="alignleft actions"><label>' . esc_html__( 'From', 'coaching-platform' ) . ' <input type="date" name="from" value="' . esc_attr( $filters['from'] ) . '"></label> ';
		echo '<label>' . esc_html__( 'To', 'coaching-platform' ) . ' <input type="date" name="to" value="' . esc_attr( $filters['to'] ) . '"></label> ';
		submit_button( __( 'Filter', 'coaching-platform' ), '', 'filter_action', false );
		echo '</div>';
	}

	/**
	 * The list sits inside a GET form and forms cannot nest, so each row action is a button that submits that form
	 * as a POST to admin-post.php.
	 */
	private function status_form( int $id, string $status, string $label ): string {
		$url = CC_Admin_Inquiries::action_url( $id, $status, false );
		return '<button type="submit" class="button-link" formmethod="post" formaction="' . esc_url( $url ) . '">' . esc_html( $label ) . '</button>';
	}
}
