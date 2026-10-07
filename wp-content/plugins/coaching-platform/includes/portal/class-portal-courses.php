<?php
defined( 'ABSPATH' ) || exit;

/**
 * Student-facing course content: my courses, course page, schedule, notices feed and the gated PDF stream.
 * Read models never include a meeting URL or a private file path.
 */
final class CC_Portal_Courses {

	const SCHEDULE_DAYS    = 7;
	const NOTICE_PAGE_SIZE = 20;
	const NOTICE_SCAN_MAX  = 100;
	const DASHBOARD_NOTICES = 3;
	const NEXT_CLASS_DAYS  = 365;
	const LIVE_SCAN_LIMIT  = 100;

	public static function init(): void {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_stream_resource' ), 1 );
	}

	/** Active enrollments only (status active and not expired). @return array<int,array<string,mixed>> */
	public static function active_enrollments( int $user_id ): array {
		$rows = array_filter(
			CC_Enrollment_Repository::for_user( $user_id ),
			static fn( array $row ): bool => CC_Enrollment_Repository::user_has_active( $user_id, (int) $row['batch_id'] )
		);
		return array_values( $rows );
	}

	/** @return array<int,string> batch_id => "Course - Batch" label for the user's active enrollments. */
	public static function batch_labels( int $user_id ): array {
		$labels = array();
		foreach ( self::active_enrollments( $user_id ) as $row ) {
			$labels[ (int) $row['batch_id'] ] = trim( (string) ( $row['course_title'] ?? '' ) . ' - ' . (string) $row['batch_name'], ' -' );
		}
		return $labels;
	}

	/**
	 * Course page model, or null when the batch is not one of the user's ACTIVE enrollments.
	 *
	 * @return array{batch_label:string,batch_id:int,modules:array<int,array<string,mixed>>,live:array<int,array<string,mixed>>}|null
	 */
	public static function course_view( int $user_id, int $batch_id ): ?array {
		$labels = self::batch_labels( $user_id );
		if ( ! isset( $labels[ $batch_id ] ) ) {
			return null;
		}
		$modules = array();
		foreach ( CC_Content_Repository::modules_for_batch( $batch_id ) as $module ) {
			$lessons = array();
			foreach ( (array) ( $module['lessons'] ?? array() ) as $lesson ) {
				$lessons[] = array(
					'id'              => (int) $lesson['id'],
					'title'           => (string) $lesson['title'],
					'when_text'       => self::when_text( (string) ( $lesson['scheduled_at'] ?? '' ) ),
					'has_attachment'  => ! empty( $lesson['has_attachment'] ),
					'attachment_name' => (string) ( $lesson['attachment_name'] ?? '' ),
					'download_url'    => ! empty( $lesson['has_attachment'] ) ? CC_Portal_Router::url( 'resource', array( 'id' => (int) $lesson['id'] ) ) : '',
				);
			}
			$modules[] = array( 'id' => (int) $module['id'], 'title' => (string) $module['title'], 'lessons' => $lessons );
		}
		$live = array();
		foreach ( CC_Live_Repository::for_batch( $batch_id ) as $row ) {
			$live[] = self::live_item( $row, $labels[ $batch_id ] );
		}
		return array( 'batch_id' => $batch_id, 'batch_label' => $labels[ $batch_id ], 'modules' => $modules, 'live' => $live );
	}

	/**
	 * Dashboard schedule: live classes and scheduled lessons in the next 7 days (today first), or the next class.
	 *
	 * @return array{title:string,today:array<int,array<string,mixed>>,later:array<int,array<string,mixed>>,next:?array<string,mixed>,message:string}
	 */
	public static function schedule( int $user_id, ?int $now = null ): array {
		$now    = $now ?? time();
		$labels = self::batch_labels( $user_id );
		$out    = array( 'title' => "Today's schedule", 'today' => array(), 'later' => array(), 'next' => null, 'message' => '' );
		if ( ! $labels ) {
			$out['message'] = 'You have no active course, so there is nothing scheduled.';
			return $out;
		}
		$horizon = $now + self::SCHEDULE_DAYS * DAY_IN_SECONDS;
		$items   = self::schedule_items( $user_id, array_keys( $labels ), $labels, $now - DAY_IN_SECONDS, $horizon );
		$today   = wp_date( 'Y-m-d', $now );
		foreach ( $items as $item ) {
			// Live classes stay listed until they end; lessons until the start time passes.
			if ( $item['end_ts'] < $now ) {
				continue;
			}
			$key           = wp_date( 'Y-m-d', $item['start_ts'] ) <= $today ? 'today' : 'later';
			$out[ $key ][] = $item;
		}
		if ( ! $out['today'] && ! $out['later'] ) {
			$out['next']    = self::next_item( $user_id, array_keys( $labels ), $labels, $now );
			$out['message'] = $out['next'] ? 'Nothing scheduled in the next 7 days. Your next class:' : 'No classes are scheduled yet. Check back soon.';
		}
		return $out;
	}

	/** @return array<int,array<string,mixed>> */
	public static function dashboard_notices( int $user_id ): array {
		return self::notice_items( CC_Notice_Service::visible_to_user( $user_id, self::DASHBOARD_NOTICES, 0 ) );
	}

	/**
	 * Notices feed page, optionally narrowed to one of the student's batches (that batch's notices plus public ones).
	 *
	 * @return array{items:array<int,array<string,mixed>>,has_more:bool,page:int,batch_id:int}
	 */
	public static function notices_page( int $user_id, int $page, int $batch_id = 0 ): array {
		$page = max( 1, $page );
		if ( $batch_id > 0 && ! isset( self::batch_labels( $user_id )[ $batch_id ] ) ) {
			$batch_id = 0;
		}
		$offset = ( $page - 1 ) * self::NOTICE_PAGE_SIZE;
		if ( 0 === $batch_id ) {
			$rows = CC_Notice_Service::visible_to_user( $user_id, self::NOTICE_PAGE_SIZE + 1, $offset );
		} else {
			$all  = CC_Notice_Service::visible_to_user( $user_id, self::NOTICE_SCAN_MAX, 0 );
			$rows = array_slice(
				array_values( array_filter( $all, static fn( $n ): bool => self::notice_in_batch( $n, $batch_id ) ) ),
				$offset,
				self::NOTICE_PAGE_SIZE + 1
			);
		}
		return array(
			'items'    => self::notice_items( array_slice( $rows, 0, self::NOTICE_PAGE_SIZE ) ),
			'has_more' => count( $rows ) > self::NOTICE_PAGE_SIZE,
			'page'     => $page,
			'batch_id' => $batch_id,
		);
	}

	/** @param array<string,mixed> $notice */
	private static function notice_in_batch( array $notice, int $batch_id ): bool {
		$audience = CC_Notice_Service::audience( (int) $notice['id'] );
		return $audience['public'] || in_array( $batch_id, $audience['batch_ids'], true );
	}

	/**
	 * @param array<int,array<string,mixed>> $rows Output of CC_Notice_Service::visible_to_user(); full content is dropped.
	 * @return array<int,array<string,mixed>>
	 */
	private static function notice_items( array $rows ): array {
		return array_map(
			static fn( array $row ): array => array(
				'id'      => (int) $row['id'],
				'title'   => (string) $row['title'],
				'excerpt' => (string) $row['excerpt'],
				'date'    => wp_date( 'j M Y', (int) strtotime( $row['date_gmt'] . ' UTC' ) ),
				'url'     => (string) $row['url'],
				'public'  => (bool) $row['public'],
			),
			$rows
		);
	}

	/** @return array<string,mixed> A live class stripped to display fields; never carries the meeting URL. */
	private static function live_item( array $row, string $batch_label ): array {
		$start = self::ts( (string) $row['starts_at'] );
		$end   = self::ts( (string) $row['ends_at'] );
		return array(
			'type'        => 'live',
			'id'          => (int) $row['id'],
			'batch_id'    => (int) ( $row['batch_id'] ?? 0 ),
			'title'       => (string) $row['title'],
			'batch_label' => $batch_label,
			'start_ts'    => $start,
			'end_ts'      => $end,
			'when_text'   => self::when_text( (string) $row['starts_at'] ),
			'state'       => (string) ( $row['state'] ?? CC_Live_Repository::state( $row ) ),
		);
	}

	/**
	 * @param array<int,int>    $batch_ids
	 * @param array<int,string> $labels
	 * @return array<int,array<string,mixed>> Sorted by start.
	 */
	private static function schedule_items( int $user_id, array $batch_ids, array $labels, int $from, int $to ): array {
		$items = array();
		foreach ( CC_Live_Repository::upcoming_for_user( $user_id, self::LIVE_SCAN_LIMIT ) as $row ) {
			$start = self::ts( (string) $row['starts_at'] );
			if ( $start >= $from && $start <= $to ) {
				$items[] = self::live_item( $row, $labels[ (int) ( $row['batch_id'] ?? 0 ) ] ?? '' );
			}
		}
		$lessons = CC_Content_Repository::lessons_scheduled_between( $batch_ids, gmdate( 'Y-m-d H:i:s', $from ), gmdate( 'Y-m-d H:i:s', $to ) );
		foreach ( $lessons as $lesson ) {
			$start   = self::ts( (string) $lesson['scheduled_at'] );
			$items[] = array(
				'type'        => 'lesson',
				'id'          => (int) $lesson['id'],
				'batch_id'    => (int) ( $lesson['batch_id'] ?? 0 ),
				'title'       => (string) $lesson['title'],
				'batch_label' => $labels[ (int) ( $lesson['batch_id'] ?? 0 ) ] ?? '',
				'start_ts'    => $start,
				'end_ts'      => $start,
				'when_text'   => self::when_text( (string) $lesson['scheduled_at'] ),
				'state'       => '',
			);
		}
		usort( $items, static fn( array $a, array $b ): int => $a['start_ts'] <=> $b['start_ts'] );
		return $items;
	}

	/** @return array<string,mixed>|null */
	private static function next_item( int $user_id, array $batch_ids, array $labels, int $now ): ?array {
		$items = self::schedule_items( $user_id, $batch_ids, $labels, $now, $now + self::NEXT_CLASS_DAYS * DAY_IN_SECONDS );
		foreach ( $items as $item ) {
			if ( $item['end_ts'] >= $now ) {
				return $item;
			}
		}
		return null;
	}

	private static function ts( string $utc ): int {
		return '' === $utc ? 0 : (int) strtotime( $utc . ' UTC' );
	}

	/** Site-timezone display of a UTC mysql datetime; '' when unscheduled. */
	public static function when_text( string $utc ): string {
		$ts = self::ts( $utc );
		return $ts ? wp_date( 'D j M, g:i A', $ts ) : '';
	}

	/**
	 * Single place deciding who may download a lesson PDF. Same null for unknown lesson, no file and not enrolled.
	 *
	 * @return array{path:string,name:string}|null
	 */
	public static function authorize_resource( int $user_id, int $lesson_id ): ?array {
		if ( $user_id <= 0 || $lesson_id <= 0 ) {
			return null;
		}
		$lesson = CC_Content_Repository::find_lesson( $lesson_id );
		if ( ! $lesson || empty( $lesson['attachment_path'] ) || ! CC_Enrollment_Repository::user_has_active( $user_id, (int) $lesson['batch_id'] ) ) {
			return null;
		}
		// path() enforces containment, the %PDF header and the finfo MIME type.
		$path = CC_Resource_Store::path( (string) $lesson['attachment_path'] );
		if ( null === $path ) {
			return null;
		}
		return array( 'path' => $path, 'name' => self::download_name( (string) ( $lesson['attachment_name'] ?? '' ), $lesson_id ) );
	}

	public static function download_name( string $stored, int $lesson_id ): string {
		$base = sanitize_file_name( wp_basename( str_replace( '\\', '/', $stored ) ) );
		$base = preg_replace( '/[^A-Za-z0-9._-]+/', '-', $base ) ?? '';
		$base = trim( (string) preg_replace( '/\.pdf$/i', '', $base ), '.-' );
		return ( '' === $base ? 'lesson-' . $lesson_id : $base ) . '.pdf';
	}

	public static function maybe_stream_resource(): void {
		if ( 'resource' !== CC_Portal_Router::current_view() ) {
			return;
		}
		$user     = CC_Student_Guard::require_student();
		$resource = self::authorize_resource( $user->ID, CC_Portal_Router::current_id() );
		if ( null === $resource ) {
			status_header( 404 );
			nocache_headers();
			header( 'Content-Type: text/plain; charset=utf-8' );
			header( 'X-Content-Type-Options: nosniff' );
			echo 'Not found';
			exit;
		}
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		status_header( 200 );
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="' . $resource['name'] . '"' );
		header( 'Content-Length: ' . (string) filesize( $resource['path'] ) );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Cache-Control: private, no-store, max-age=0' );
		header( 'Pragma: no-cache' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		readfile( $resource['path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streaming a private file.
		exit;
	}
}
