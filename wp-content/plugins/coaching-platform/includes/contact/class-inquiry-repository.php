<?php
defined( 'ABSPATH' ) || exit;

/**
 * Contact inquiries (cc_inquiries). validate() is pure apart from the optional course lookup so the rules can be
 * unit-tested; every query is prepared. Phones are stored in E.164 form. Message bodies and phones never go to the audit log.
 */
final class CC_Inquiry_Repository {

	const TOPICS         = array( 'course', 'admission', 'fees', 'other' );
	const STATUSES       = array( 'new', 'handled', 'spam' );
	const NAME_MIN       = 2;
	const NAME_MAX       = 190;
	const EMAIL_MAX      = 190;
	const MESSAGE_MIN    = 10;
	const MESSAGE_MAX    = 2000;
	const NOTE_MAX       = 500;
	const PER_PAGE       = 20;
	const MAX_PER_PAGE   = 100;
	const EXPORT_CHUNK   = 500;
	const RETENTION_DAYS = 365;
	const NEW_RETENTION_MULTIPLIER = 2;
	const PURGE_HOOK     = 'cc_inquiry_purge';

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'cc_inquiries';
	}

	/**
	 * Validates already-trimmed input. course_id is checked against published cc_course posts only when one is given.
	 *
	 * @param array<string,mixed> $input name, phone, email, topic, course_id, message.
	 * @return array{errors:array<string,string>,data:array<string,mixed>}
	 */
	public static function validate( array $input ): array {
		$errors  = array();
		$name    = trim( (string) ( $input['name'] ?? '' ) );
		$email   = trim( (string) ( $input['email'] ?? '' ) );
		$topic   = trim( (string) ( $input['topic'] ?? '' ) );
		$message = trim( str_replace( "\r\n", "\n", (string) ( $input['message'] ?? '' ) ) );
		$course  = trim( (string) ( $input['course_id'] ?? '' ) );

		if ( mb_strlen( $name ) < self::NAME_MIN || mb_strlen( $name ) > self::NAME_MAX ) {
			$errors['name'] = 'Enter your name (2 to 190 characters).';
		}
		$phone = CC_Phone::normalize( (string) ( $input['phone'] ?? '' ) );
		if ( null === $phone ) {
			$errors['phone'] = 'Enter a valid Bangladeshi mobile number.';
		}
		if ( '' !== $email && ( mb_strlen( $email ) > self::EMAIL_MAX || false === filter_var( $email, FILTER_VALIDATE_EMAIL ) ) ) {
			$errors['email'] = 'Enter a valid email address.';
		}
		if ( ! in_array( $topic, self::TOPICS, true ) ) {
			$errors['topic'] = 'Choose a topic.';
		}
		if ( mb_strlen( $message ) < self::MESSAGE_MIN || mb_strlen( $message ) > self::MESSAGE_MAX ) {
			$errors['message'] = 'Write a message of 10 to 2000 characters.';
		}
		if ( '' !== $course && ( ! ctype_digit( $course ) || ! self::is_published_course( (int) $course ) ) ) {
			$errors['course_id'] = 'Choose a valid course.';
		}

		return array(
			'errors' => $errors,
			'data'   => array(
				'name'      => $name,
				'phone'     => (string) $phone,
				'email'     => '' === $email ? null : $email,
				'topic'     => $topic,
				'course_id' => '' === $course || isset( $errors['course_id'] ) ? null : (int) $course,
				'message'   => $message,
			),
		);
	}

	/**
	 * @param array<string,mixed> $data Same keys as validate().
	 * @return int|WP_Error The inquiry id.
	 */
	public static function create( array $data ) {
		global $wpdb;
		$checked = self::validate( $data );
		if ( $checked['errors'] ) {
			return new WP_Error( 'cc_inquiry_invalid', 'The inquiry is not valid.', array( 'status' => 422, 'details' => $checked['errors'] ) );
		}
		$row = array_merge(
			$checked['data'],
			array(
				'status'     => 'new',
				'ip_hash'    => hash_hmac( 'sha256', CC_Rate_Limiter::client_ip(), wp_salt( 'auth' ) ),
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		if ( ! $wpdb->insert( self::table(), $row ) ) {
			error_log( 'CC_Inquiry_Repository::create: insert failed' );
			return new WP_Error( 'cc_inquiry_failed', 'Could not save the inquiry.', array( 'status' => 500 ) );
		}
		return (int) $wpdb->insert_id;
	}

	/** @return array<string,mixed>|null */
	public static function find( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name only.
		return is_array( $row ) ? $row : null;
	}

	/**
	 * @param array{status?:string,search?:string,from?:string,to?:string,page?:int,per_page?:int} $args from/to are Y-m-d in the site timezone.
	 * @return array{items:array<int,array<string,mixed>>,total:int,page:int,per_page:int}
	 */
	public static function query( array $args = array() ): array {
		global $wpdb;
		$table                 = self::table();
		list( $clause, $vals ) = self::where( $args );
		$per_page              = min( self::MAX_PER_PAGE, max( 1, (int) ( $args['per_page'] ?? self::PER_PAGE ) ) );
		$page                  = max( 1, (int) ( $args['page'] ?? 1 ) );

		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$clause}";
		$total     = (int) ( $vals ? $wpdb->get_var( $wpdb->prepare( $count_sql, $vals ) ) : $wpdb->get_var( $count_sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$rows_sql = "SELECT * FROM {$table} WHERE {$clause} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d";
		$items    = $wpdb->get_results( $wpdb->prepare( $rows_sql, array_merge( $vals, array( $per_page, ( $page - 1 ) * $per_page ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array( 'items' => is_array( $items ) ? $items : array(), 'total' => $total, 'page' => $page, 'per_page' => $per_page );
	}

	/** @return array<string,int> status => count, every status present. */
	public static function counts(): array {
		global $wpdb;
		$counts = array_fill_keys( self::STATUSES, 0 );
		$rows   = $wpdb->get_results( 'SELECT status, COUNT(*) AS n FROM ' . self::table() . ' GROUP BY status', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- no input.
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$counts[ (string) $row['status'] ] = (int) $row['n'];
		}
		return $counts;
	}

	/**
	 * 'new' reopens: handled_by/handled_at are cleared and the note is kept.
	 *
	 * @return bool|WP_Error
	 */
	public static function set_status( int $id, string $status, int $actor, string $note = '' ) {
		global $wpdb;
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return new WP_Error( 'cc_inquiry_status', 'Unknown status.' );
		}
		if ( mb_strlen( $note ) > self::NOTE_MAX ) {
			return new WP_Error( 'cc_inquiry_note', 'The note must be 500 characters or fewer.' );
		}
		if ( null === self::find( $id ) ) {
			return new WP_Error( 'cc_inquiry_not_found', 'Inquiry not found.' );
		}
		if ( 'new' === $status ) {
			$update = array( 'status' => 'new', 'handled_by' => null, 'handled_at' => null );
		} else {
			$update = array( 'status' => $status, 'handled_by' => $actor, 'handled_at' => gmdate( 'Y-m-d H:i:s' ) );
			if ( '' !== $note ) {
				$update['staff_note'] = $note;
			}
		}
		return false !== $wpdb->update( self::table(), $update, array( 'id' => $id ) );
	}

	/**
	 * Deletes handled and spam inquiries created more than $days ago, and unattended 'new' ones older than twice that
	 * (730 days by default) so personal data in an ignored inbox does not live forever.
	 */
	public static function purge_older_than( int $days = self::RETENTION_DAYS ): int {
		global $wpdb;
		$days       = max( 1, $days );
		$cutoff     = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$new_cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * self::NEW_RETENTION_MULTIPLIER * DAY_IN_SECONDS );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . " WHERE (status IN ('handled','spam') AND created_at < %s) OR (status = 'new' AND created_at < %s)", $cutoff, $new_cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name only.
		return (int) $wpdb->rows_affected;
	}

	public static function schedule_purge(): void {
		add_action( self::PURGE_HOOK, array( __CLASS__, 'purge_older_than' ) );
		if ( ! wp_next_scheduled( self::PURGE_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::PURGE_HOOK );
		}
	}

	/**
	 * Same filters as query(), streamed in chunks. Phones are digits with the country code (see CC_Csv::phone).
	 *
	 * @param array<string,mixed> $args
	 * @return Generator<int,array<int,mixed>>
	 */
	public static function export_rows( array $args = array() ): Generator {
		$page = 1;
		do {
			$result = self::query( array_merge( $args, array( 'page' => $page, 'per_page' => self::EXPORT_CHUNK ) ) );
			foreach ( $result['items'] as $row ) {
				yield array(
					(int) $row['id'], (string) $row['created_at'], (string) $row['name'], CC_Csv::phone( (string) $row['phone'] ),
					(string) $row['email'], (string) $row['topic'], (string) $row['course_id'], (string) $row['status'],
					(string) $row['message'], (string) $row['staff_note'],
				);
			}
			++$page;
		} while ( count( $result['items'] ) === self::EXPORT_CHUNK );
	}

	/** @return string[] */
	public static function export_header(): array {
		return array( 'ID', 'Received (UTC)', 'Name', 'Phone', 'Email', 'Topic', 'Course ID', 'Status', 'Message', 'Staff note' );
	}

	private static function is_published_course( int $id ): bool {
		return $id > 0 && 'cc_course' === get_post_type( $id ) && 'publish' === get_post_status( $id );
	}

	/** @return array{0:string,1:array<int,mixed>} */
	private static function where( array $args ): array {
		global $wpdb;
		$where = array( '1=1' );
		$vals  = array();

		$status = (string) ( $args['status'] ?? '' );
		if ( in_array( $status, self::STATUSES, true ) ) {
			$where[] = 'status = %s';
			$vals[]  = $status;
		}
		$search = trim( (string) ( $args['search'] ?? '' ) );
		if ( '' !== $search ) {
			$like    = '%' . $wpdb->esc_like( $search ) . '%';
			$phone   = CC_Phone::normalize( $search );
			$where[] = '(name LIKE %s OR message LIKE %s OR phone LIKE %s' . ( null === $phone ? '' : ' OR phone = %s' ) . ')';
			array_push( $vals, $like, $like, $like );
			if ( null !== $phone ) {
				$vals[] = $phone;
			}
		}
		$from = (string) ( $args['from'] ?? '' );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ) {
			$where[] = 'created_at >= %s';
			$vals[]  = get_gmt_from_date( $from . ' 00:00:00' );
		}
		$to = (string) ( $args['to'] ?? '' );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) ) {
			$where[] = 'created_at <= %s';
			$vals[]  = get_gmt_from_date( $to . ' 23:59:59' );
		}
		return array( implode( ' AND ', $where ), $vals );
	}
}
