<?php
defined( 'ABSPATH' ) || exit;

/**
 * Read-only SMS delivery log (SDD 9 "Logs"). Failed messages first, so staff can see who did not get credentials or a
 * receipt and resend from the student's roster entry. Never shows message bodies (they can hold passwords or OTPs);
 * phone numbers are masked.
 */
final class CC_Admin_Sms_Log {

	const PAGE_SLUG = 'cc-sms-log';
	const PER_PAGE  = 30;
	const STATUSES  = array( 'failed' => 'Failed', 'queued' => 'Queued', 'sent' => 'Sent', 'delivered' => 'Delivered', 'all' => 'All' );

	/** @return string One of the keys of STATUSES; unknown input falls back to "failed". */
	public static function status_from( $raw ): string {
		$status = sanitize_key( (string) $raw );
		return isset( self::STATUSES[ $status ] ) ? $status : 'failed';
	}

	/** @return array{items:array<int,array<string,mixed>>,total:int,counts:array<string,int>} */
	public static function query( string $status, int $page ): array {
		global $wpdb;
		$table  = $wpdb->prefix . 'cc_sms_log';
		$page   = max( 1, $page );
		$where  = 'all' === $status ? '1=1' : $wpdb->prepare( 'status = %s', $status ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared on this line.
		$total  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name and prepared clause.
		$items  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, to_phone, template, segments, provider, status, attempts, error, created_at, sent_at
				FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name and prepared clause.
				self::PER_PAGE,
				( $page - 1 ) * self::PER_PAGE
			),
			ARRAY_A
		);
		$counts = array_fill_keys( array_keys( self::STATUSES ), 0 );
		foreach ( (array) $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$table} GROUP BY status", ARRAY_A ) as $row ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
			$counts[ $row['status'] ] = (int) $row['n'];
		}
		$counts['all'] = array_sum( $counts );
		return array( 'items' => is_array( $items ) ? $items : array(), 'total' => $total, 'counts' => $counts );
	}

	public static function render(): void {
		if ( ! current_user_can( CC_Admin_Roles::CAP_VIEW_AUDIT ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'coaching-platform' ), 403 );
		}
		$status = self::status_from( wp_unslash( $_GET['status'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification -- read-only filter.
		$page   = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification
		$data   = self::query( $status, $page );

		echo '<div class="wrap"><h1>' . esc_html__( 'SMS log', 'coaching-platform' ) . '</h1>';
		echo '<p class="description">' . esc_html__( 'Delivery status only. Message text is never shown or stored here. Resend credentials from the student\'s page in Students.', 'coaching-platform' ) . '</p>';
		echo '<ul class="subsubsub">';
		$links = array();
		foreach ( self::STATUSES as $key => $label ) {
			$url     = add_query_arg( array( 'page' => self::PAGE_SLUG, 'status' => $key ), admin_url( 'admin.php' ) );
			$links[] = sprintf( '<li><a href="%s"%s>%s <span class="count">(%d)</span></a></li>', esc_url( $url ), $key === $status ? ' class="current" aria-current="page"' : '', esc_html( $label ), (int) $data['counts'][ $key ] );
		}
		echo implode( ' | ', $links ) . '</ul><br class="clear">'; // phpcs:ignore WordPress.Security.EscapeOutput -- each link is built with escaping helpers.

		echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
		foreach ( array( 'Created (UTC)', 'To', 'Template', 'Segments', 'Provider', 'Status', 'Attempts', 'Last error' ) as $heading ) {
			echo '<th scope="col">' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		if ( ! $data['items'] ) {
			echo '<tr><td colspan="8">' . esc_html__( 'No messages here.', 'coaching-platform' ) . '</td></tr>';
		}
		foreach ( $data['items'] as $row ) {
			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td>%d</td><td>%s</td><td><strong>%s</strong></td><td>%d</td><td>%s</td></tr>',
				esc_html( (string) $row['created_at'] ),
				esc_html( CC_Phone::mask( (string) $row['to_phone'] ) ),
				esc_html( (string) $row['template'] ),
				(int) $row['segments'],
				esc_html( (string) $row['provider'] ),
				esc_html( (string) $row['status'] ),
				(int) $row['attempts'],
				esc_html( (string) $row['error'] )
			);
		}
		echo '</tbody></table>';

		$pages = (int) ceil( $data['total'] / self::PER_PAGE );
		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">' . paginate_links( // phpcs:ignore WordPress.Security.EscapeOutput -- core builds escaped links.
				array(
					'base'      => add_query_arg( array( 'page' => self::PAGE_SLUG, 'status' => $status, 'paged' => '%#%' ), admin_url( 'admin.php' ) ),
					'format'    => '',
					'current'   => max( 1, $page ),
					'total'     => $pages,
					'prev_text' => '&laquo;',
					'next_text' => '&raquo;',
				)
			) . '</div></div>';
		}
		echo '</div>';
	}
}
