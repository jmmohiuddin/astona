<?php
defined( 'ABSPATH' ) || exit;

/** Audit log viewer (capability cc_view_audit, held by the owner). Reads only through CC_Audit::query. */
final class CC_Admin_Audit {

	const PAGE         = 'cc-audit';
	const PER_PAGE     = 50;
	const EXPORT_CHUNK = 100; // CC_Audit::MAX_PER_PAGE
	const CSV_HEADER   = array( 'Time (UTC)', 'Actor', 'Action', 'Entity type', 'Entity ID', 'Note', 'Diff hash' );

	public static function init(): void {
		add_action( 'admin_post_cc_audit_export', array( __CLASS__, 'handle_export' ) );
	}

	public static function filters_from( array $src ): array {
		$date = static function ( $value ): string {
			$value = is_string( $value ) ? trim( $value ) : '';
			$d     = DateTimeImmutable::createFromFormat( '!Y-m-d', $value );
			return ( $d && $d->format( 'Y-m-d' ) === $value ) ? $value : '';
		};
		return array(
			'actor'       => isset( $src['actor'] ) ? absint( $src['actor'] ) : 0,
			'action'      => isset( $src['audit_action'] ) ? sanitize_text_field( (string) $src['audit_action'] ) : '',
			'entity_type' => isset( $src['entity_type'] ) ? sanitize_key( (string) $src['entity_type'] ) : '',
			'date_from'   => $date( $src['date_from'] ?? '' ),
			'date_to'     => $date( $src['date_to'] ?? '' ),
			'page'        => max( 1, (int) ( $src['paged'] ?? 1 ) ),
			'per_page'    => self::PER_PAGE,
		);
	}

	/** @return array{rows:array,total:int} */
	public static function query( array $filters ): array {
		if ( ! class_exists( 'CC_Audit' ) ) {
			return array( 'rows' => array(), 'total' => 0 );
		}
		$active = array_filter( $filters, static fn( $v ) => '' !== $v && 0 !== $v );
		if ( isset( $active['date_from'] ) ) {
			$active['from'] = $active['date_from'];
		}
		if ( isset( $active['date_to'] ) ) {
			$active['to'] = $active['date_to'];
		}
		$result = CC_Audit::query( $active );
		return array( 'rows' => $result['items'], 'total' => $result['total'] );
	}

	public static function csv_rows( array $filters ): Generator {
		$filters['per_page'] = self::EXPORT_CHUNK;
		$page                = 1;
		do {
			$filters['page'] = $page;
			$chunk           = self::query( $filters )['rows'];
			foreach ( $chunk as $row ) {
				yield array(
					(string) $row['created_at'],
					self::actor_label( (int) $row['actor_id'] ),
					(string) $row['action'],
					(string) $row['entity_type'],
					(string) $row['entity_id'],
					(string) $row['note'],
					(string) $row['diff_hash'],
				);
			}
			++$page;
		} while ( count( $chunk ) === self::EXPORT_CHUNK );
	}

	private static function actor_label( int $actor_id ): string {
		$user = $actor_id > 0 ? get_userdata( $actor_id ) : false;
		return $user ? $user->user_login . ' (#' . $actor_id . ')' : '#' . $actor_id;
	}

	public static function handle_export(): void {
		check_admin_referer( 'cc_export_audit' );
		if ( ! current_user_can( 'cc_view_audit' ) || ! current_user_can( 'cc_export_data' ) ) {
			wp_die( esc_html__( 'You are not allowed to export the audit log.', 'coaching-platform' ), '', array( 'response' => 403 ) );
		}
		if ( ! class_exists( 'CC_Csv' ) || ! class_exists( 'CC_Audit' ) ) {
			wp_die( esc_html__( 'CSV export is unavailable.', 'coaching-platform' ), '', array( 'response' => 500 ) );
		}
		$filters = self::filters_from( wp_unslash( $_GET ) );
		CC_Audit::log( 'audit.export', 'audit', 0, array_filter( $filters, static fn( $v ) => '' !== $v && 0 !== $v ), 'CSV export' );
		CC_Csv::stream( 'audit-' . gmdate( 'Ymd-His' ) . '.csv', self::CSV_HEADER, self::csv_rows( $filters ) );
	}

	public static function render(): void {
		if ( ! current_user_can( 'cc_view_audit' ) ) {
			wp_die( esc_html__( 'You are not allowed to view the audit log.', 'coaching-platform' ), '', array( 'response' => 403 ) );
		}
		$filters = self::filters_from( wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification -- read-only filters.
		$result  = self::query( $filters );

		echo '<div class="wrap"><h1>' . esc_html__( 'Audit log', 'coaching-platform' ) . '</h1>';
		self::render_filters( $filters );
		echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
		foreach ( array( 'Time', 'Actor', 'Action', 'Entity', 'Note', 'Diff hash' ) as $label ) {
			echo '<th>' . esc_html( $label ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		if ( ! $result['rows'] ) {
			echo '<tr><td colspan="6">' . esc_html__( 'No audit entries match these filters.', 'coaching-platform' ) . '</td></tr>';
		}
		foreach ( $result['rows'] as $row ) {
			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td><code>%s</code></td></tr>',
				esc_html( CC_Admin_Payments::format_time( (string) $row['created_at'] ) ),
				esc_html( self::actor_label( (int) $row['actor_id'] ) ),
				esc_html( (string) $row['action'] ),
				esc_html( $row['entity_type'] . ' #' . $row['entity_id'] ),
				esc_html( (string) $row['note'] ),
				esc_html( substr( (string) $row['diff_hash'], 0, 12 ) )
			);
		}
		echo '</tbody></table>';
		self::render_pager( $filters, (int) $result['total'] );
		echo '</div>';
	}

	private static function filter_url_args( array $filters ): array {
		return array_filter(
			array(
				'page'         => self::PAGE,
				'actor'        => $filters['actor'],
				'audit_action' => $filters['action'],
				'entity_type'  => $filters['entity_type'],
				'date_from'    => $filters['date_from'],
				'date_to'      => $filters['date_to'],
			),
			static fn( $v ) => '' !== $v && 0 !== $v
		);
	}

	private static function render_filters( array $f ): void {
		echo '<form method="get" style="margin:8px 0"><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '">';
		printf( '<input type="number" min="1" name="actor" value="%s" placeholder="%s"> ', esc_attr( $f['actor'] ? (string) $f['actor'] : '' ), esc_attr__( 'Actor user ID', 'coaching-platform' ) );
		printf( '<input type="text" name="audit_action" value="%s" placeholder="%s"> ', esc_attr( $f['action'] ), esc_attr__( 'Action', 'coaching-platform' ) );
		printf( '<input type="text" name="entity_type" value="%s" placeholder="%s"> ', esc_attr( $f['entity_type'] ), esc_attr__( 'Entity type', 'coaching-platform' ) );
		printf( '<label>%s <input type="date" name="date_from" value="%s"></label> ', esc_html__( 'From', 'coaching-platform' ), esc_attr( $f['date_from'] ) );
		printf( '<label>%s <input type="date" name="date_to" value="%s"></label> ', esc_html__( 'To', 'coaching-platform' ), esc_attr( $f['date_to'] ) );
		submit_button( __( 'Filter', 'coaching-platform' ), 'secondary', '', false );
		if ( current_user_can( 'cc_export_data' ) ) {
			$url = wp_nonce_url( add_query_arg( array_merge( self::filter_url_args( $f ), array( 'action' => 'cc_audit_export' ) ), admin_url( 'admin-post.php' ) ), 'cc_export_audit' );
			printf( ' <a class="button" href="%s">%s</a>', esc_url( $url ), esc_html__( 'Export CSV', 'coaching-platform' ) );
		}
		echo '</form>';
	}

	private static function render_pager( array $filters, int $total ): void {
		$pages = (int) ceil( $total / self::PER_PAGE );
		if ( $pages < 2 ) {
			return;
		}
		$links = paginate_links(
			array(
				'base'    => add_query_arg( array_merge( self::filter_url_args( $filters ), array( 'paged' => '%#%' ) ), admin_url( 'admin.php' ) ),
				'format'  => '',
				'current' => $filters['page'],
				'total'   => $pages,
			)
		);
		echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( (string) $links ) . '</div></div>';
	}
}
