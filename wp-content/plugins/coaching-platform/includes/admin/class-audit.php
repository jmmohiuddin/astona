<?php
defined( 'ABSPATH' ) || exit;

/**
 * Append-only audit trail. Stores who did what to which entity, plus a sha256 of the canonical-JSON diff.
 * The diff itself is never persisted, so the table holds no personal data.
 */
final class CC_Audit {

	const MAX_PER_PAGE = 100;

	/** Key-sorted (recursively) JSON so equal diffs always hash equally; list order is preserved. */
	public static function canonical_json( array $diff ): string {
		return (string) json_encode( self::sort_keys( $diff ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- pure helper, must run without WordPress.
	}

	public static function diff_hash( array $diff ): ?string {
		return array() === $diff ? null : hash( 'sha256', self::canonical_json( $diff ) );
	}

	public static function log( string $action, string $entity_type, int $entity_id = 0, array $diff = array(), string $note = '' ): void {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'cc_audit_log',
			array(
				'actor_id'    => get_current_user_id(),
				'action'      => substr( $action, 0, 60 ),
				'entity_type' => substr( $entity_type, 0, 40 ),
				'entity_id'   => max( 0, $entity_id ),
				'diff_hash'   => self::diff_hash( $diff ),
				'note'        => '' === $note ? null : mb_substr( $note, 0, 255 ),
				'ip_hash'     => self::ip_hash(),
				'created_at'  => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * @param array{actor?:int,action?:string,entity_type?:string,from?:string,to?:string,page?:int,per_page?:int} $args Dates are Y-m-d (UTC).
	 * @return array{items:array<int,array<string,mixed>>,total:int,page:int,per_page:int}
	 */
	public static function query( array $args = array() ): array {
		global $wpdb;
		$table = $wpdb->prefix . 'cc_audit_log';
		$where = array( '1=1' );
		$vals  = array();

		if ( ! empty( $args['actor'] ) ) {
			$where[] = 'actor_id = %d';
			$vals[]  = (int) $args['actor'];
		}
		foreach ( array( 'action', 'entity_type' ) as $col ) {
			if ( ! empty( $args[ $col ] ) ) {
				$where[] = "$col = %s";
				$vals[]  = (string) $args[ $col ];
			}
		}
		if ( ! empty( $args['from'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $args['from'] ) ) {
			$where[] = 'created_at >= %s';
			$vals[]  = $args['from'] . ' 00:00:00';
		}
		if ( ! empty( $args['to'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $args['to'] ) ) {
			$where[] = 'created_at <= %s';
			$vals[]  = $args['to'] . ' 23:59:59';
		}

		$per_page = min( self::MAX_PER_PAGE, max( 1, (int) ( $args['per_page'] ?? 20 ) ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$clause   = implode( ' AND ', $where );

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$clause}";
		$total     = (int) ( $vals ? $wpdb->get_var( $wpdb->prepare( $count_sql, $vals ) ) : $wpdb->get_var( $count_sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$rows_sql = "SELECT * FROM {$table} WHERE {$clause} ORDER BY id DESC LIMIT %d OFFSET %d";
		$items    = $wpdb->get_results( $wpdb->prepare( $rows_sql, array_merge( $vals, array( $per_page, ( $page - 1 ) * $per_page ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array(
			'items'    => is_array( $items ) ? $items : array(),
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
		);
	}

	private static function sort_keys( array $value ): array {
		foreach ( $value as $k => $v ) {
			if ( is_array( $v ) ) {
				$value[ $k ] = self::sort_keys( $v );
			}
		}
		if ( array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
			ksort( $value );
		}
		return $value;
	}

	private static function ip_hash(): ?string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return '' === $ip ? null : hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) );
	}
}
