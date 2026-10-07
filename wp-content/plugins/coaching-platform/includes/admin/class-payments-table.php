<?php
defined( 'ABSPATH' ) || exit;

/** Ledger list table. Loaded lazily by CC_Admin_Payments because WP_List_Table only exists in wp-admin. */
final class CC_Payments_Table extends WP_List_Table {

	/** @var array{total:int,completed_sum:string,completed_count:int} */
	public array $totals = array( 'total' => 0, 'completed_sum' => '0.00', 'completed_count' => 0 );

	private array $args;

	public function __construct( array $args ) {
		parent::__construct( array( 'singular' => 'payment', 'plural' => 'payments', 'ajax' => false ) );
		$this->args = $args;
	}

	public function get_columns(): array {
		return array(
			'invoice' => __( 'Invoice', 'coaching-platform' ),
			'student' => __( 'Student / application', 'coaching-platform' ),
			'amount'  => __( 'Amount', 'coaching-platform' ),
			'gateway' => __( 'Gateway / method', 'coaching-platform' ),
			'status'  => __( 'Status', 'coaching-platform' ),
			'trx_id'  => __( 'Transaction ID', 'coaching-platform' ),
			'created' => __( 'Created', 'coaching-platform' ),
			'settled' => __( 'Settled', 'coaching-platform' ),
		);
	}

	protected function get_sortable_columns(): array {
		return array(
			'invoice' => array( 'invoice', false ),
			'amount'  => array( 'amount', false ),
			'status'  => array( 'status', false ),
			'created' => array( 'created', true ),
			'settled' => array( 'settled', false ),
		);
	}

	protected function get_default_primary_column_name(): string {
		return 'invoice';
	}

	public function prepare_items(): void {
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'invoice' );
		$result                = CC_Admin_Payments::query( $this->args );
		$this->items           = $result['rows'];
		$this->totals          = array( 'total' => $result['total'], 'completed_sum' => $result['completed_sum'], 'completed_count' => $result['completed_count'] );
		$this->set_pagination_args( array( 'total_items' => $result['total'], 'per_page' => (int) $this->args['per_page'] ) );
	}

	protected function column_invoice( $item ): string {
		$detail  = add_query_arg( array( 'page' => CC_Admin_Payments::PAGE, 'payment' => (int) $item['id'] ), admin_url( 'admin.php' ) );
		$actions = array( 'view' => '<a href="' . esc_url( $detail ) . '">' . esc_html__( 'View', 'coaching-platform' ) . '</a>' );
		if ( ! in_array( $item['status'], array( 'completed', 'refunded' ), true ) ) {
			$form = CC_Admin_Payments::reconcile_form( (int) $item['id'] );
			if ( '' !== $form ) {
				$actions['reconcile'] = $form;
			}
		}
		if ( 'completed' === $item['status'] && '' !== CC_Admin_Payments::refund_link( (int) $item['id'] ) ) {
			$actions['refund'] = CC_Admin_Payments::refund_link( (int) $item['id'] );
		}
		return '<strong><a href="' . esc_url( $detail ) . '">' . esc_html( (string) $item['invoice_number'] ) . '</a></strong>' . $this->row_actions( $actions );
	}

	protected function column_student( $item ): string {
		$link  = add_query_arg( array( 'page' => 'cc-applications', 'view' => (int) $item['application_id'] ), admin_url( 'admin.php' ) );
		$phone = current_user_can( 'cc_manage_students' ) ? (string) $item['student_phone'] : CC_Phone::mask( (string) $item['student_phone'] );
		return esc_html( (string) $item['full_name'] ) . '<br><a href="' . esc_url( $link ) . '">' . esc_html( (string) $item['public_ref'] ) . '</a> &middot; ' . esc_html( $phone );
	}

	protected function column_amount( $item ): string {
		return esc_html( number_format_i18n( (float) $item['amount'], 2 ) . ' ' . $item['currency'] );
	}

	protected function column_gateway( $item ): string {
		return esc_html( $item['gateway'] . ' / ' . $item['method'] . ( in_array( $item['kind'] ?? 'full', array( 'first', 'balance' ), true ) ? ' · ' . $item['kind'] . ' part' : '' ) );
	}

	protected function column_status( $item ): string {
		return CC_Admin_Payments::status_html( $item );
	}

	protected function column_trx_id( $item ): string {
		return esc_html( (string) $item['trx_id'] );
	}

	protected function column_created( $item ): string {
		return esc_html( CC_Admin_Payments::format_time( $item['created_at'] ) );
	}

	protected function column_settled( $item ): string {
		return esc_html( CC_Admin_Payments::format_time( $item['settled_at'] ) );
	}

	public function no_items(): void {
		esc_html_e( 'No payments match these filters.', 'coaching-platform' );
	}
}
