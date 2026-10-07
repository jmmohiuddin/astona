<?php
defined( 'ABSPATH' ) || exit;

/**
 * Data access for cc_modules and cc_lessons. Times are UTC. Lesson PDFs live in CC_Resource_Store and are deleted
 * together with their lesson, module or replaced reference.
 */
final class CC_Content_Repository {

	const TITLE_MAX = 190;

	/** @return array<int,array<string,mixed>> Modules in order, each with `lessons`; private paths are reduced to `has_attachment`. */
	public static function modules_for_batch( int $batch_id ): array {
		global $wpdb;
		$p = $wpdb->prefix;
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names only.
		$modules = $wpdb->get_results( $wpdb->prepare( "SELECT id, batch_id, title, sort_order FROM {$p}cc_modules WHERE batch_id = %d ORDER BY sort_order ASC, id ASC", $batch_id ), ARRAY_A );
		$lessons = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l.id, l.module_id, l.title, l.scheduled_at, l.sort_order, l.attachment_path, l.attachment_name
				FROM {$p}cc_lessons l JOIN {$p}cc_modules m ON m.id = l.module_id
				WHERE m.batch_id = %d ORDER BY l.sort_order ASC, l.id ASC",
				$batch_id
			),
			ARRAY_A
		);
		// phpcs:enable
		$by_module = array();
		foreach ( is_array( $lessons ) ? $lessons : array() as $lesson ) {
			$by_module[ (int) $lesson['module_id'] ][] = self::public_lesson( $lesson );
		}
		return array_map(
			static function ( array $module ) use ( $by_module ): array {
				$id = (int) $module['id'];
				return array(
					'id'         => $id,
					'batch_id'   => (int) $module['batch_id'],
					'title'      => (string) $module['title'],
					'sort_order' => (int) $module['sort_order'],
					'lessons'    => $by_module[ $id ] ?? array(),
				);
			},
			is_array( $modules ) ? $modules : array()
		);
	}

	/** @return int Module id, 0 on failure. A sort of 0 appends after the last module. */
	public static function create_module( int $batch_id, string $title, int $sort = 0 ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'cc_modules';
		if ( $sort <= 0 ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
			$sort = 1 + (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(sort_order) FROM {$table} WHERE batch_id = %d", $batch_id ) );
		}
		$ok = $wpdb->insert(
			$table,
			array( 'batch_id' => $batch_id, 'title' => mb_substr( $title, 0, self::TITLE_MAX ), 'sort_order' => $sort, 'created_at' => gmdate( 'Y-m-d H:i:s' ) ),
			array( '%d', '%s', '%d', '%s' )
		);
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/** @param array{title?:string,sort_order?:int} $fields */
	public static function update_module( int $id, array $fields ): bool {
		global $wpdb;
		$data = array();
		if ( isset( $fields['title'] ) ) {
			$data['title'] = mb_substr( (string) $fields['title'], 0, self::TITLE_MAX );
		}
		if ( isset( $fields['sort_order'] ) ) {
			$data['sort_order'] = max( 0, (int) $fields['sort_order'] );
		}
		return self::update_row( $wpdb->prefix . 'cc_modules', $id, $data );
	}

	public static function delete_module( int $id ): bool {
		global $wpdb;
		$p = $wpdb->prefix;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		$paths = $wpdb->get_col( $wpdb->prepare( "SELECT attachment_path FROM {$p}cc_lessons WHERE module_id = %d AND attachment_path IS NOT NULL", $id ) );
		if ( false === $wpdb->delete( $p . 'cc_lessons', array( 'module_id' => $id ), array( '%d' ) ) ) {
			return false;
		}
		foreach ( $paths as $path ) {
			CC_Resource_Store::delete( (string) $path );
		}
		return (bool) $wpdb->delete( $p . 'cc_modules', array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * @param array{title?:string,scheduled_at?:?string,sort_order?:int,attachment_path?:?string,attachment_name?:?string} $fields scheduled_at is UTC `Y-m-d H:i:s` or null.
	 * @return int Lesson id, 0 on failure.
	 */
	public static function create_lesson( int $module_id, array $fields ): int {
		global $wpdb;
		$table = $wpdb->prefix . 'cc_lessons';
		$sort  = (int) ( $fields['sort_order'] ?? 0 );
		if ( $sort <= 0 ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
			$sort = 1 + (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(sort_order) FROM {$table} WHERE module_id = %d", $module_id ) );
		}
		$ok = $wpdb->insert(
			$table,
			array(
				'module_id'       => $module_id,
				'title'           => mb_substr( (string) ( $fields['title'] ?? '' ), 0, self::TITLE_MAX ),
				'scheduled_at'    => $fields['scheduled_at'] ?? null,
				'sort_order'      => $sort,
				'attachment_path' => $fields['attachment_path'] ?? null,
				'attachment_name' => $fields['attachment_name'] ?? null,
				'created_at'      => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/** A changed or cleared attachment reference deletes the file it replaces. @param array<string,mixed> $fields Same keys as create_lesson(). */
	public static function update_lesson( int $id, array $fields ): bool {
		global $wpdb;
		$existing = self::find_lesson( $id );
		if ( null === $existing ) {
			return false;
		}
		$data = array();
		if ( isset( $fields['title'] ) ) {
			$data['title'] = mb_substr( (string) $fields['title'], 0, self::TITLE_MAX );
		}
		if ( isset( $fields['sort_order'] ) ) {
			$data['sort_order'] = max( 0, (int) $fields['sort_order'] );
		}
		foreach ( array( 'scheduled_at', 'attachment_path', 'attachment_name' ) as $nullable ) {
			if ( array_key_exists( $nullable, $fields ) ) {
				$data[ $nullable ] = $fields[ $nullable ];
			}
		}
		if ( ! self::update_row( $wpdb->prefix . 'cc_lessons', $id, $data ) ) {
			return false;
		}
		$old = (string) ( $existing['attachment_path'] ?? '' );
		if ( '' !== $old && array_key_exists( 'attachment_path', $data ) && $data['attachment_path'] !== $old ) {
			CC_Resource_Store::delete( $old );
		}
		return true;
	}

	public static function delete_lesson( int $id ): bool {
		global $wpdb;
		$existing = self::find_lesson( $id );
		if ( null === $existing ) {
			return false;
		}
		if ( ! $wpdb->delete( $wpdb->prefix . 'cc_lessons', array( 'id' => $id ), array( '%d' ) ) ) {
			return false;
		}
		if ( ! empty( $existing['attachment_path'] ) ) {
			CC_Resource_Store::delete( (string) $existing['attachment_path'] );
		}
		return true;
	}

	/** Full row including the private attachment_path, plus batch_id from its module. @return array<string,mixed>|null */
	public static function find_lesson( int $id ): ?array {
		global $wpdb;
		$p = $wpdb->prefix;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names only.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT l.*, m.batch_id FROM {$p}cc_lessons l JOIN {$p}cc_modules m ON m.id = l.module_id WHERE l.id = %d", $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/** @return array<string,mixed>|null */
	public static function find_module( int $id ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}cc_modules WHERE id = %d", $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/** Swaps a module with its neighbour (-1 up, +1 down) after renumbering the batch's modules 1..n. */
	public static function move_module( int $id, int $direction ): bool {
		$module = self::find_module( $id );
		return null !== $module && self::move( 'cc_modules', 'batch_id', (int) $module['batch_id'], $id, $direction );
	}

	public static function move_lesson( int $id, int $direction ): bool {
		$lesson = self::find_lesson( $id );
		return null !== $lesson && self::move( 'cc_lessons', 'module_id', (int) $lesson['module_id'], $id, $direction );
	}

	/**
	 * Scheduled lessons of the given batches with from_utc <= scheduled_at <= to_utc (UTC `Y-m-d H:i:s`), earliest first.
	 *
	 * @param int[] $batch_ids
	 * @return array<int,array<string,mixed>> Same lesson shape as modules_for_batch() plus batch_id and module_title.
	 */
	public static function lessons_scheduled_between( array $batch_ids, string $from_utc, string $to_utc ): array {
		global $wpdb;
		$batch_ids = array_values( array_unique( array_filter( array_map( 'absint', $batch_ids ) ) ) );
		if ( ! $batch_ids ) {
			return array();
		}
		$p = $wpdb->prefix;
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- table names and %d placeholders only.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l.id, l.module_id, l.title, l.scheduled_at, l.sort_order, l.attachment_path, l.attachment_name, m.batch_id, m.title AS module_title
				FROM {$p}cc_lessons l JOIN {$p}cc_modules m ON m.id = l.module_id
				WHERE m.batch_id IN (" . implode( ',', array_fill( 0, count( $batch_ids ), '%d' ) ) . ')
				AND l.scheduled_at >= %s AND l.scheduled_at <= %s
				ORDER BY l.scheduled_at ASC, l.id ASC',
				array_merge( $batch_ids, array( $from_utc, $to_utc ) )
			),
			ARRAY_A
		);
		// phpcs:enable
		return array_map(
			static fn( array $row ): array => array_merge( self::public_lesson( $row ), array( 'batch_id' => (int) $row['batch_id'], 'module_title' => (string) $row['module_title'] ) ),
			is_array( $rows ) ? $rows : array()
		);
	}

	/** @param array<string,mixed> $row @return array<string,mixed> */
	private static function public_lesson( array $row ): array {
		return array(
			'id'              => (int) $row['id'],
			'module_id'       => (int) $row['module_id'],
			'title'           => (string) $row['title'],
			'scheduled_at'    => null === $row['scheduled_at'] ? null : (string) $row['scheduled_at'],
			'sort_order'      => (int) $row['sort_order'],
			'has_attachment'  => ! empty( $row['attachment_path'] ),
			'attachment_name' => ! empty( $row['attachment_path'] ) ? (string) ( $row['attachment_name'] ?? '' ) : '',
		);
	}

	/** @param array<string,mixed> $data */
	private static function update_row( string $table, int $id, array $data ): bool {
		global $wpdb;
		if ( ! $data ) {
			return false;
		}
		return false !== $wpdb->update( $table, $data, array( 'id' => $id ) );
	}

	private static function move( string $table_suffix, string $parent_column, int $parent_id, int $id, int $direction ): bool {
		global $wpdb;
		$table = $wpdb->prefix . $table_suffix;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table and column names are fixed by the callers.
		$ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE {$parent_column} = %d ORDER BY sort_order ASC, id ASC", $parent_id ) ) );
		$at  = array_search( $id, $ids, true );
		$to  = false === $at ? -1 : $at + ( $direction < 0 ? -1 : 1 );
		if ( $to < 0 || $to >= count( $ids ) ) {
			return false;
		}
		$swap         = $ids[ $to ];
		$ids[ $to ]   = $id;
		$ids[ $at ]   = $swap;
		foreach ( $ids as $index => $row_id ) {
			$wpdb->update( $table, array( 'sort_order' => $index + 1 ), array( 'id' => $row_id ), array( '%d' ), array( '%d' ) );
		}
		return true;
	}
}
