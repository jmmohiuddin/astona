<?php
defined( 'ABSPATH' ) || exit;

/**
 * Course > Batch > Module > Lesson editor backend (TRD FR-013: "inline nested editing, capacity indicator,
 * transactional REST save"). One GET returns the whole tree of a course; one PUT replaces it atomically.
 *
 *  - The save is ONE database transaction: either every batch, module and lesson change lands or none does.
 *  - Optimistic locking: the client sends the `rev` it loaded; a different current rev means someone else saved
 *    first and the save is refused with 409 so nobody's work is overwritten silently.
 *  - seats_taken is never accepted from input, capacity cannot drop below the seats already taken, and a batch
 *    that has applications cannot be deleted (close it instead).
 *  - Lesson PDFs are not edited here; a lesson that keeps its id keeps its attachment.
 *  - Lesson times are entered in the site timezone (`Y-m-d\TH:i`) and stored in UTC.
 * Requires the cc_manage_content capability (checked in the REST permission callback and again in save()).
 */
final class CC_Course_Tree {

	const CAP          = 'cc_manage_content';
	const MAX_BATCHES  = 30;
	const MAX_MODULES  = 60;
	const MAX_LESSONS  = 300;
	const TITLE_MAX    = 190;
	const FORM_TIME    = 'Y-m-d\TH:i';
	const DB_TIME      = 'Y-m-d H:i:s';

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes(): void {
		register_rest_route(
			'cc/v1',
			'/admin/courses/(?P<id>\d+)/tree',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'rest_get' ),
					'permission_callback' => array( __CLASS__, 'can_edit' ),
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( __CLASS__, 'rest_put' ),
					'permission_callback' => array( __CLASS__, 'can_edit' ),
				),
			)
		);
	}

	public static function can_edit( WP_REST_Request $request ): bool {
		return current_user_can( self::CAP ) && 'cc_course' === get_post_type( (int) $request['id'] );
	}

	public static function rest_get( WP_REST_Request $request ): WP_REST_Response {
		return self::respond( self::load( (int) $request['id'] ) );
	}

	public static function rest_put( WP_REST_Request $request ): WP_REST_Response {
		$body   = $request->get_json_params();
		$result = self::save( (int) $request['id'], is_array( $body ) ? $body : array(), (string) ( is_array( $body ) ? ( $body['rev'] ?? '' ) : '' ), get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			return self::respond( array( 'code' => $result->get_error_code(), 'message' => $result->get_error_message(), 'errors' => $data['errors'] ?? new stdClass() ), (int) ( $data['status'] ?? 400 ) );
		}
		return self::respond( $result );
	}

	/** @return array{course:array{id:int,title:string},batches:array<int,array<string,mixed>>,rev:string,timezone:string} */
	public static function load( int $course_id ): array {
		$batches = array();
		foreach ( CC_Batch_Repository::for_course( $course_id ) as $row ) {
			$batch_id  = (int) $row['id'];
			$modules   = array();
			foreach ( CC_Content_Repository::modules_for_batch( $batch_id ) as $module ) {
				$modules[] = array(
					'id'      => (int) $module['id'],
					'title'   => (string) $module['title'],
					'lessons' => array_map(
						static fn( array $lesson ): array => array(
							'id'              => (int) $lesson['id'],
							'title'           => (string) $lesson['title'],
							'scheduled_local' => self::to_local( $lesson['scheduled_at'] ),
							'has_attachment'  => ! empty( $lesson['has_attachment'] ),
							'attachment_name' => (string) $lesson['attachment_name'],
						),
						$module['lessons']
					),
				);
			}
			$batches[] = array(
				'id'                    => $batch_id,
				'name'                  => (string) $row['name'],
				'delivery_mode'         => (string) $row['delivery_mode'],
				'capacity'              => (int) $row['capacity'],
				'seats_taken'           => (int) $row['seats_taken'],
				'price'                 => (float) $row['price'],
				'start_date'            => (string) $row['start_date'],
				'end_date'              => (string) ( $row['end_date'] ?? '' ),
				'schedule_text'         => (string) ( $row['schedule_text'] ?? '' ),
				'status'                => (string) $row['status'],
				'application_open'      => (bool) $row['application_open'],
				'waitlist_enabled'      => (bool) $row['waitlist_enabled'],
				'installments_enabled'  => (bool) $row['installments_enabled'],
				'first_payment_percent' => (int) $row['first_payment_percent'],
				'installment_days'      => (int) $row['installment_days'],
				'modules'               => $modules,
			);
		}
		$tree = array(
			'course'   => array( 'id' => $course_id, 'title' => get_the_title( $course_id ) ),
			'batches'  => $batches,
			'timezone' => wp_timezone_string(),
		);
		$tree['rev'] = self::rev( $batches );
		return $tree;
	}

	/**
	 * Fingerprint of everything an editor can change. seats_taken is left out: admissions move it all the time and it is
	 * not editable, so a new admission must not make every open editor conflict (capacity is still checked against the live count).
	 */
	public static function rev( array $batches ): string {
		foreach ( $batches as $i => $batch ) {
			unset( $batches[ $i ]['seats_taken'] );
		}
		return substr( hash( 'sha256', (string) wp_json_encode( $batches ) ), 0, 32 );
	}

	/**
	 * @param array<string,mixed> $input {rev, batches:[...]} in the shape load() returns.
	 * @return array|WP_Error The fresh tree on success. Errors: forbidden 403, not_found 404, conflict 409, invalid 422 (data.errors by path), failed 500.
	 */
	public static function save( int $course_id, array $input, string $rev, int $actor ) {
		global $wpdb;
		if ( ! user_can( $actor, self::CAP ) ) {
			return new WP_Error( 'forbidden', 'You are not allowed to edit courses.', array( 'status' => 403 ) );
		}
		if ( 'cc_course' !== get_post_type( $course_id ) ) {
			return new WP_Error( 'not_found', 'Course not found.', array( 'status' => 404 ) );
		}
		$errors = array();
		$clean  = self::sanitize( (array) ( $input['batches'] ?? array() ), $errors );
		if ( $errors ) {
			return new WP_Error( 'invalid', 'Please fix the highlighted fields.', array( 'status' => 422, 'errors' => $errors ) );
		}

		$p = $wpdb->prefix;
		$wpdb->query( 'START TRANSACTION' );
		try {
			// Lock the course's batch rows so a concurrent save or admission waits, then compare revisions.
			$wpdb->get_results( $wpdb->prepare( "SELECT id FROM {$p}cc_batches WHERE course_id = %d FOR UPDATE", $course_id ) );
			$current = self::load( $course_id );
			if ( '' === $rev || ! hash_equals( $current['rev'], $rev ) ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'conflict', 'Someone else changed this course. Reload to see their changes, then redo yours.', array( 'status' => 409 ) );
			}
			$files = array();
			$plan  = self::apply( $course_id, $current, $clean, $errors, $files );
			if ( $errors ) {
				$wpdb->query( 'ROLLBACK' );
				return new WP_Error( 'invalid', 'Please fix the highlighted fields.', array( 'status' => 422, 'errors' => $errors ) );
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new RuntimeException( 'Commit failed.' );
			}
		} catch ( Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			error_log( 'CC_Course_Tree save failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return new WP_Error( 'failed', 'The course could not be saved. Nothing was changed.', array( 'status' => 500 ) );
		}

		// After the commit: remove files of deleted lessons, free waitlist seats, refresh caches, log.
		foreach ( $files as $path ) {
			CC_Resource_Store::delete( (string) $path );
		}
		if ( class_exists( 'CC_Waitlist' ) ) {
			CC_Waitlist::fill_all();
		}
		clean_post_cache( $course_id );
		do_action( 'cc_course_tree_saved', $course_id );
		CC_Audit::log( 'course.tree_save', 'course', $course_id, $plan );
		return self::load( $course_id );
	}

	/* ---------------------------------------------------------------- sanitising */

	/**
	 * @param array<int,mixed> $raw
	 * @param array<string,string> $errors Filled with path => message.
	 * @return array<int,array<string,mixed>>
	 */
	public static function sanitize( array $raw, array &$errors ): array {
		$out = array();
		if ( count( $raw ) > self::MAX_BATCHES ) {
			$errors['batches'] = 'Too many batches (up to ' . self::MAX_BATCHES . ').';
			return array();
		}
		$modules_total = 0;
		$lessons_total = 0;
		foreach ( array_values( $raw ) as $i => $b ) {
			if ( ! is_array( $b ) ) {
				$errors["batches.$i"] = 'Invalid batch.';
				continue;
			}
			$at   = "batches.$i";
			$name = self::title( $b['name'] ?? '' );
			if ( '' === $name ) {
				$errors["$at.name"] = 'Give the batch a name.';
			}
			$mode   = (string) ( $b['delivery_mode'] ?? 'physical' );
			$status = (string) ( $b['status'] ?? 'draft' );
			if ( ! in_array( $mode, CC_Batch_Repository::MODES, true ) ) {
				$errors["$at.delivery_mode"] = 'Choose a delivery mode.';
			}
			if ( ! in_array( $status, CC_Batch_Repository::STATUSES, true ) ) {
				$errors["$at.status"] = 'Choose a status.';
			}
			$capacity = $b['capacity'] ?? 0;
			if ( ! is_numeric( $capacity ) || (int) $capacity < 0 || (int) $capacity > 65535 ) {
				$errors["$at.capacity"] = 'Capacity must be 0 to 65535.';
			}
			$price = $b['price'] ?? 0;
			if ( ! is_numeric( $price ) || (float) $price < 0 || (float) $price > 99999999.99 ) {
				$errors["$at.price"] = 'Enter a price of 0 or more.';
			}
			$start = self::date( (string) ( $b['start_date'] ?? '' ) );
			if ( '' === $start ) {
				$errors["$at.start_date"] = 'Enter a valid start date.';
			}
			$end_raw = (string) ( $b['end_date'] ?? '' );
			$end     = '' === $end_raw ? '' : self::date( $end_raw );
			if ( '' !== $end_raw && '' === $end ) {
				$errors["$at.end_date"] = 'Enter a valid end date or leave it empty.';
			} elseif ( '' !== $end && '' !== $start && $end < $start ) {
				$errors["$at.end_date"] = 'The end date is before the start date.';
			}
			$percent = (int) ( $b['first_payment_percent'] ?? 50 );
			if ( ! ( ! empty( $b['installments_enabled'] ) ) ) {
				$percent = CC_Batch_Repository::clamp_percent( $percent );
			} elseif ( $percent < 10 || $percent > 90 ) {
				$errors["$at.first_payment_percent"] = 'The first payment must be 10% to 90%.';
			}
			$days = (int) ( $b['installment_days'] ?? 30 );
			if ( $days < 1 || $days > 365 ) {
				$errors["$at.installment_days"] = 'Use 1 to 365 days.';
			}

			$modules = array();
			$raw_modules = is_array( $b['modules'] ?? null ) ? array_values( $b['modules'] ) : array();
			foreach ( $raw_modules as $j => $m ) {
				$mat = "$at.modules.$j";
				if ( ! is_array( $m ) ) {
					$errors[ $mat ] = 'Invalid module.';
					continue;
				}
				if ( ++$modules_total > self::MAX_MODULES ) {
					$errors['batches'] = 'Too many modules in total (up to ' . self::MAX_MODULES . ').';
					break 2;
				}
				$title = self::title( $m['title'] ?? '' );
				if ( '' === $title ) {
					$errors["$mat.title"] = 'Give the module a title.';
				}
				$lessons = array();
				foreach ( ( is_array( $m['lessons'] ?? null ) ? array_values( $m['lessons'] ) : array() ) as $k => $l ) {
					$lat = "$mat.lessons.$k";
					if ( ! is_array( $l ) ) {
						$errors[ $lat ] = 'Invalid lesson.';
						continue;
					}
					if ( ++$lessons_total > self::MAX_LESSONS ) {
						$errors['batches'] = 'Too many lessons in total (up to ' . self::MAX_LESSONS . ').';
						break 3;
					}
					$ltitle = self::title( $l['title'] ?? '' );
					if ( '' === $ltitle ) {
						$errors["$lat.title"] = 'Give the lesson a title.';
					}
					$local = (string) ( $l['scheduled_local'] ?? '' );
					$utc   = '' === $local ? null : self::to_utc( $local );
					if ( '' !== $local && null === $utc ) {
						$errors["$lat.scheduled_local"] = 'Enter a valid date and time.';
					}
					$lessons[] = array( 'id' => absint( $l['id'] ?? 0 ), 'title' => $ltitle, 'scheduled_at' => $utc );
				}
				$modules[] = array( 'id' => absint( $m['id'] ?? 0 ), 'title' => $title, 'lessons' => $lessons );
			}

			$out[] = array(
				'id'                    => absint( $b['id'] ?? 0 ),
				'name'                  => $name,
				'delivery_mode'         => $mode,
				'capacity'              => (int) $capacity,
				'price'                 => round( (float) $price, 2 ),
				'start_date'            => $start,
				'end_date'              => '' === $end ? null : $end,
				'schedule_text'         => mb_substr( sanitize_text_field( (string) ( $b['schedule_text'] ?? '' ) ), 0, 255 ),
				'status'                => $status,
				'application_open'      => ! empty( $b['application_open'] ) ? 1 : 0,
				'waitlist_enabled'      => ! empty( $b['waitlist_enabled'] ) ? 1 : 0,
				'installments_enabled'  => ! empty( $b['installments_enabled'] ) ? 1 : 0,
				'first_payment_percent' => $percent,
				'installment_days'      => max( 1, min( 365, $days ) ),
				'modules'               => $modules,
			);
		}
		return $out;
	}

	/* ---------------------------------------------------------------- applying (inside the transaction) */

	/**
	 * @param array<int,array<string,mixed>> $clean
	 * @param string[] $files Receives attachment paths to delete after commit.
	 * @return array<string,int> Counts for the audit log.
	 */
	private static function apply( int $course_id, array $current, array $clean, array &$errors, array &$files ): array {
		global $wpdb;
		$p       = $wpdb->prefix;
		$now     = gmdate( self::DB_TIME );
		$counts  = array( 'batches' => 0, 'modules' => 0, 'lessons' => 0, 'deleted' => 0 );
		$existing = array();
		foreach ( $current['batches'] as $b ) {
			$existing[ (int) $b['id'] ] = $b;
		}
		$kept = array();

		foreach ( $clean as $i => $b ) {
			$id = (int) $b['id'];
			if ( $id > 0 && ! isset( $existing[ $id ] ) ) {
				$errors[ "batches.$i" ] = 'That batch does not belong to this course.';
				continue;
			}
			if ( $id > 0 && $b['capacity'] < (int) $existing[ $id ]['seats_taken'] ) {
				$errors[ "batches.$i.capacity" ] = 'Capacity cannot be below the ' . (int) $existing[ $id ]['seats_taken'] . ' seats already taken.';
				continue;
			}
			$row = array(
				'name' => $b['name'], 'delivery_mode' => $b['delivery_mode'], 'capacity' => $b['capacity'], 'price' => $b['price'],
				'start_date' => $b['start_date'], 'end_date' => $b['end_date'], 'schedule_text' => $b['schedule_text'], 'status' => $b['status'],
				'application_open' => $b['application_open'], 'waitlist_enabled' => $b['waitlist_enabled'], 'installments_enabled' => $b['installments_enabled'],
				'first_payment_percent' => $b['first_payment_percent'], 'installment_days' => $b['installment_days'], 'updated_at' => $now,
			);
			if ( $id > 0 ) {
				if ( false === $wpdb->update( "{$p}cc_batches", $row, array( 'id' => $id, 'course_id' => $course_id ) ) ) {
					throw new RuntimeException( 'Batch update failed.' );
				}
			} else {
				if ( ! $wpdb->insert( "{$p}cc_batches", array_merge( $row, array( 'course_id' => $course_id, 'created_at' => $now ) ) ) ) {
					throw new RuntimeException( 'Batch insert failed.' );
				}
				$id = (int) $wpdb->insert_id;
			}
			$kept[ $id ] = true;
			++$counts['batches'];
			self::apply_modules( $id, $i, $b['modules'], $existing[ $id ]['modules'] ?? array(), $errors, $files, $counts, $now );
		}

		foreach ( $existing as $id => $b ) {
			if ( isset( $kept[ $id ] ) ) {
				continue;
			}
			$applications = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}cc_applications WHERE batch_id = %d", $id ) );
			if ( (int) $b['seats_taken'] > 0 || $applications > 0 ) {
				$errors[ 'batches' ] = 'The batch "' . $b['name'] . '" has applications and cannot be deleted. Set its status to Closed instead.';
				continue;
			}
			foreach ( $b['modules'] as $m ) {
				foreach ( self::attachment_paths( array( (int) $m['id'] ) ) as $path ) {
					$files[] = $path;
				}
				$wpdb->delete( "{$p}cc_lessons", array( 'module_id' => (int) $m['id'] ) );
			}
			$wpdb->delete( "{$p}cc_modules", array( 'batch_id' => $id ) );
			$wpdb->delete( "{$p}cc_batches", array( 'id' => $id, 'course_id' => $course_id ) );
			++$counts['deleted'];
		}
		return $counts;
	}

	/**
	 * @param array<int,array<string,mixed>> $modules Clean modules for the batch.
	 * @param array<int,array<string,mixed>> $old     Current modules (with lessons) of the batch.
	 */
	private static function apply_modules( int $batch_id, int $bi, array $modules, array $old, array &$errors, array &$files, array &$counts, string $now ): void {
		global $wpdb;
		$p        = $wpdb->prefix;
		$old_mod  = array();
		$old_less = array(); // lesson id => module id, for every lesson of this batch.
		foreach ( $old as $m ) {
			$old_mod[ (int) $m['id'] ] = $m;
			foreach ( $m['lessons'] as $l ) {
				$old_less[ (int) $l['id'] ] = (int) $m['id'];
			}
		}
		$keep_mod  = array();
		$keep_less = array();
		foreach ( $modules as $j => $m ) {
			$mid = (int) $m['id'];
			if ( $mid > 0 && ! isset( $old_mod[ $mid ] ) ) {
				$errors[ "batches.$bi.modules.$j" ] = 'That module does not belong to this batch.';
				continue;
			}
			if ( $mid > 0 ) {
				if ( false === $wpdb->update( "{$p}cc_modules", array( 'title' => $m['title'], 'sort_order' => $j + 1 ), array( 'id' => $mid ) ) ) {
					throw new RuntimeException( 'Module update failed.' );
				}
			} else {
				if ( ! $wpdb->insert( "{$p}cc_modules", array( 'batch_id' => $batch_id, 'title' => $m['title'], 'sort_order' => $j + 1, 'created_at' => $now ) ) ) {
					throw new RuntimeException( 'Module insert failed.' );
				}
				$mid = (int) $wpdb->insert_id;
			}
			$keep_mod[ $mid ] = true;
			++$counts['modules'];
			foreach ( $m['lessons'] as $k => $l ) {
				$lid = (int) $l['id'];
				if ( $lid > 0 && ! isset( $old_less[ $lid ] ) ) {
					$errors[ "batches.$bi.modules.$j.lessons.$k" ] = 'That lesson does not belong to this batch.';
					continue;
				}
				if ( $lid > 0 && isset( $keep_less[ $lid ] ) ) {
					$errors[ "batches.$bi.modules.$j.lessons.$k" ] = 'This lesson appears twice.';
					continue;
				}
				$data = array( 'module_id' => $mid, 'title' => $l['title'], 'scheduled_at' => $l['scheduled_at'], 'sort_order' => $k + 1 );
				if ( $lid > 0 ) {
					if ( false === $wpdb->update( "{$p}cc_lessons", $data, array( 'id' => $lid ), array( '%d', '%s', '%s', '%d' ), array( '%d' ) ) ) {
						throw new RuntimeException( 'Lesson update failed.' );
					}
				} else {
					if ( ! $wpdb->insert( "{$p}cc_lessons", array_merge( $data, array( 'created_at' => $now ) ) ) ) {
						throw new RuntimeException( 'Lesson insert failed.' );
					}
					$lid = (int) $wpdb->insert_id;
				}
				$keep_less[ $lid ] = true;
				++$counts['lessons'];
			}
		}
		// Lessons that are gone from the submitted tree (deleted, not moved) lose their rows and PDFs.
		foreach ( $old_less as $lid => $_mid ) {
			if ( isset( $keep_less[ $lid ] ) ) {
				continue;
			}
			$path = (string) $wpdb->get_var( $wpdb->prepare( "SELECT attachment_path FROM {$p}cc_lessons WHERE id = %d", $lid ) );
			if ( '' !== $path ) {
				$files[] = $path;
			}
			$wpdb->delete( "{$p}cc_lessons", array( 'id' => $lid ) );
			++$counts['deleted'];
		}
		foreach ( $old_mod as $mid => $m ) {
			if ( ! isset( $keep_mod[ $mid ] ) ) {
				$wpdb->delete( "{$p}cc_modules", array( 'id' => $mid ) );
				++$counts['deleted'];
			}
		}
	}

	/** @param int[] $module_ids @return string[] */
	private static function attachment_paths( array $module_ids ): array {
		global $wpdb;
		if ( ! $module_ids ) {
			return array();
		}
		$in = implode( ',', array_map( 'intval', $module_ids ) );
		return array_map( 'strval', $wpdb->get_col( "SELECT attachment_path FROM {$wpdb->prefix}cc_lessons WHERE module_id IN ($in) AND attachment_path IS NOT NULL" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- integers only.
	}

	/* ---------------------------------------------------------------- helpers */

	private static function title( $value ): string {
		return mb_substr( trim( sanitize_text_field( (string) $value ) ), 0, self::TITLE_MAX );
	}

	private static function date( string $value ): string {
		return 1 === preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ? $value : '';
	}

	/** Site-timezone `Y-m-d\TH:i` to UTC `Y-m-d H:i:s`, or null when it is not a valid date and time. */
	public static function to_utc( string $local ): ?string {
		$date = DateTimeImmutable::createFromFormat( '!' . self::FORM_TIME, $local, wp_timezone() );
		$err  = DateTimeImmutable::getLastErrors();
		if ( false === $date || ( is_array( $err ) && ( $err['warning_count'] > 0 || $err['error_count'] > 0 ) ) ) {
			return null;
		}
		return $date->setTimezone( new DateTimeZone( 'UTC' ) )->format( self::DB_TIME );
	}

	public static function to_local( $utc ): string {
		if ( empty( $utc ) ) {
			return '';
		}
		$date = DateTimeImmutable::createFromFormat( '!' . self::DB_TIME, (string) $utc, new DateTimeZone( 'UTC' ) );
		return false === $date ? '' : $date->setTimezone( wp_timezone() )->format( self::FORM_TIME );
	}

	private static function respond( $body, int $status = 200 ): WP_REST_Response {
		$response = new WP_REST_Response( $body, $status );
		$response->header( 'Cache-Control', 'private, no-store' );
		return $response;
	}
}
