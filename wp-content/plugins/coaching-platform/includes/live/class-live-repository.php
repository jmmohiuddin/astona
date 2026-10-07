<?php
defined( 'ABSPATH' ) || exit;

/**
 * Data access for cc_live_classes. Times are UTC "Y-m-d H:i:s". The meeting URL is encrypted at rest (CC_Crypto)
 * and is only ever returned by meeting_url(); no other method returns it, in any form.
 */
final class CC_Live_Repository {

	const TIME_FORMAT   = 'Y-m-d H:i:s';
	const EARLY_SECONDS = 900;
	const MAX_URL       = 500;
	const MAX_TITLE     = 190;
	const PROVIDERS     = array( 'meet', 'zoom', 'other' );
	/** Plain ASCII DNS labels (IDN must be punycode); no empty labels, so no leading, trailing or doubled dots. */
	const HOST_PATTERN  = '/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*$/';

	const STATE_INACTIVE = 'inactive';
	const STATE_ACTIVE   = 'active';
	const STATE_ENDED    = 'ended';

	/* ---------------------------------------------------------------- pure helpers */

	/** inactive until 15 minutes before start, active from then until ends_at (exclusive), ended afterwards. */
	public static function state( array $row, ?int $now = null ): string {
		$now   = $now ?? time();
		$start = (int) strtotime( $row['starts_at'] . ' UTC' );
		$end   = (int) strtotime( $row['ends_at'] . ' UTC' );
		if ( $now >= $end ) {
			return self::STATE_ENDED;
		}
		return $now >= $start - self::EARLY_SECONDS ? self::STATE_ACTIVE : self::STATE_INACTIVE;
	}

	/** Null when the URL is acceptable for the provider, otherwise a message for the admin. */
	public static function url_error( string $url, string $provider ): ?string {
		if ( '' === $url || strlen( $url ) > self::MAX_URL || 1 === preg_match( '/[\x00-\x20\x7f]/', $url ) ) {
			return 'Enter the meeting link (up to ' . self::MAX_URL . ' characters, no spaces).';
		}
		if ( false !== strpos( $url, '\\' ) || false !== strpos( $url, '%' ) ) {
			return 'The meeting link must not contain backslashes or percent-encoding.';
		}
		$parts = parse_url( $url );
		if ( ! is_array( $parts ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) ) ) {
			return 'The meeting link must start with https://';
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) || ( isset( $parts['port'] ) && 443 !== (int) $parts['port'] ) ) {
			return 'The meeting link must not contain credentials or a custom port.';
		}
		$host = strtolower( (string) ( $parts['host'] ?? '' ) );
		if ( 1 !== preg_match( self::HOST_PATTERN, $host ) ) {
			return 'The meeting link has no valid host.';
		}
		if ( 'meet' === $provider && 'meet.google.com' !== $host ) {
			return 'Google Meet links must be on meet.google.com.';
		}
		if ( 'zoom' === $provider && 'zoom.us' !== $host && '.zoom.us' !== substr( $host, -8 ) ) {
			return 'Zoom links must be on zoom.us.';
		}
		return null;
	}

	/**
	 * Validates the editable fields. $url is the plain meeting URL to check (the caller supplies the stored one when unchanged).
	 *
	 * @param array<string,mixed> $f batch_id, title, starts_at, ends_at (UTC), provider.
	 */
	public static function field_error( array $f, string $url ): ?string {
		$title = trim( (string) ( $f['title'] ?? '' ) );
		if ( '' === $title || mb_strlen( $title ) > self::MAX_TITLE ) {
			return 'Enter a title of up to ' . self::MAX_TITLE . ' characters.';
		}
		if ( (int) ( $f['batch_id'] ?? 0 ) <= 0 ) {
			return 'Choose a batch.';
		}
		$provider = (string) ( $f['provider'] ?? '' );
		if ( ! in_array( $provider, self::PROVIDERS, true ) ) {
			return 'Choose a provider.';
		}
		$start = self::parse_utc( (string) ( $f['starts_at'] ?? '' ) );
		$end   = self::parse_utc( (string) ( $f['ends_at'] ?? '' ) );
		if ( null === $start || null === $end ) {
			return 'Enter valid start and end times.';
		}
		if ( $end <= $start ) {
			return 'The end time must be after the start time.';
		}
		return self::url_error( $url, $provider );
	}

	/** 'https://meet.google.com/…xyz': scheme, host and the last three path characters only; never the query. */
	public static function mask_url( string $url ): string {
		$parts = parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}
		$path = (string) ( $parts['path'] ?? '' );
		return $parts['scheme'] . '://' . $parts['host'] . '/…' . ( strlen( $path ) > 3 ? substr( $path, -3 ) : '' );
	}

	private static function parse_utc( string $value ): ?int {
		$date = DateTimeImmutable::createFromFormat( '!' . self::TIME_FORMAT, $value, new DateTimeZone( 'UTC' ) );
		return ( false !== $date && $date->format( self::TIME_FORMAT ) === $value ) ? $date->getTimestamp() : null;
	}

	/* ---------------------------------------------------------------- persistence */

	/**
	 * @param array<string,mixed> $f batch_id, lesson_id (optional), title, starts_at, ends_at, provider, meeting_url.
	 * @return int|WP_Error
	 */
	public static function create( array $f, int $actor ) {
		global $wpdb;
		$url   = trim( (string) ( $f['meeting_url'] ?? '' ) );
		$error = self::field_error( $f, $url ) ?? self::relation_error( (int) $f['batch_id'], (int) ( $f['lesson_id'] ?? 0 ) );
		if ( null !== $error ) {
			return new WP_Error( 'cc_live_invalid', $error );
		}
		$ok = $wpdb->insert(
			self::table(),
			array(
				'batch_id'        => (int) $f['batch_id'],
				'lesson_id'       => ( (int) ( $f['lesson_id'] ?? 0 ) ) > 0 ? (int) $f['lesson_id'] : null,
				'title'           => trim( (string) $f['title'] ),
				'starts_at'       => $f['starts_at'],
				'ends_at'         => $f['ends_at'],
				'provider'        => $f['provider'],
				'meeting_url_enc' => CC_Crypto::encrypt( $url ),
				'created_by'      => $actor,
				'updated_at'      => gmdate( self::TIME_FORMAT ),
			)
		);
		return false === $ok ? new WP_Error( 'cc_live_db', 'The live class could not be saved.' ) : (int) $wpdb->insert_id;
	}

	/**
	 * Fields not supplied keep their stored value; a blank meeting_url keeps the stored link.
	 *
	 * @param array<string,mixed> $f
	 * @return bool|WP_Error
	 */
	public static function update( int $id, array $f ) {
		global $wpdb;
		$existing = self::find( $id );
		if ( null === $existing ) {
			return new WP_Error( 'cc_live_not_found', 'Live class not found.' );
		}
		$merged = array_merge( $existing, array_intersect_key( $f, $existing ) );
		$url    = trim( (string) ( $f['meeting_url'] ?? '' ) );
		$data   = array(
			'batch_id'   => (int) $merged['batch_id'],
			'lesson_id'  => ( (int) $merged['lesson_id'] ) > 0 ? (int) $merged['lesson_id'] : null,
			'title'      => trim( (string) $merged['title'] ),
			'starts_at'  => $merged['starts_at'],
			'ends_at'    => $merged['ends_at'],
			'provider'   => $merged['provider'],
			'updated_at' => gmdate( self::TIME_FORMAT ),
		);
		$check = '' !== $url ? $url : self::meeting_url( $id );
		$error = self::field_error( $merged, $check ) ?? self::relation_error( $data['batch_id'], (int) $data['lesson_id'] );
		if ( null !== $error ) {
			return new WP_Error( 'cc_live_invalid', $error );
		}
		if ( '' !== $url ) {
			$data['meeting_url_enc'] = CC_Crypto::encrypt( $url );
		}
		return false === $wpdb->update( self::table(), $data, array( 'id' => $id ) ) ? new WP_Error( 'cc_live_db', 'The live class could not be saved.' ) : true;
	}

	public static function delete( int $id ): bool {
		global $wpdb;
		return 1 === (int) $wpdb->delete( self::table(), array( 'id' => $id ) );
	}

	/** The row without meeting_url_enc. */
	public static function find( int $id ): ?array {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, batch_id, lesson_id, title, starts_at, ends_at, provider, created_by, updated_at FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/** @return array<int,array> Ordered by start, soonest first; no meeting URL. */
	public static function for_batch( int $batch_id ): array {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, batch_id, lesson_id, title, starts_at, ends_at, provider, created_by, updated_at FROM {$table} WHERE batch_id = %d ORDER BY starts_at ASC, id ASC", $batch_id ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Decrypts on every call; never cached.
	 *
	 * @throws RuntimeException When the stored blob cannot be decrypted.
	 */
	public static function meeting_url( int $id ): string {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		$blob = $wpdb->get_var( $wpdb->prepare( "SELECT meeting_url_enc FROM {$table} WHERE id = %d", $id ) );
		return is_string( $blob ) ? CC_Crypto::decrypt( $blob ) : '';
	}

	/**
	 * Classes of the user's ACTIVE enrollments that have not ended, soonest first. Never includes the meeting URL.
	 *
	 * @return array<int,array> id, batch_id, batch_name, lesson_id, title, starts_at, ends_at, starts_ts, ends_ts, provider, state.
	 */
	public static function upcoming_for_user( int $user_id, int $limit = 5 ): array {
		global $wpdb;
		$l   = self::table();
		$e   = $wpdb->prefix . 'cc_enrollments';
		$b   = $wpdb->prefix . 'cc_batches';
		$now = time();
		$utc = gmdate( self::TIME_FORMAT, $now );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names only.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l.id, l.batch_id, b.name AS batch_name, l.lesson_id, l.title, l.starts_at, l.ends_at, l.provider
				FROM {$l} l
				JOIN {$e} e ON e.batch_id = l.batch_id AND e.user_id = %d AND e.status = 'active' AND (e.expires_at IS NULL OR e.expires_at > %s)
				JOIN {$b} b ON b.id = l.batch_id
				WHERE l.ends_at > %s
				ORDER BY l.starts_at ASC, l.id ASC
				LIMIT %d",
				$user_id,
				$utc,
				$utc,
				max( 1, $limit )
			),
			ARRAY_A
		);
		// phpcs:enable
		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$row['starts_ts'] = (int) strtotime( $row['starts_at'] . ' UTC' );
			$row['ends_ts']   = (int) strtotime( $row['ends_at'] . ' UTC' );
			$row['state']     = self::state( $row, $now );
			$out[]            = $row;
		}
		return $out;
	}

	private static function relation_error( int $batch_id, int $lesson_id ): ?string {
		global $wpdb;
		$batches = $wpdb->prefix . 'cc_batches';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		if ( null === $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$batches} WHERE id = %d", $batch_id ) ) ) {
			return 'That batch does not exist.';
		}
		if ( $lesson_id <= 0 ) {
			return null;
		}
		$lessons = $wpdb->prefix . 'cc_lessons';
		$modules = $wpdb->prefix . 'cc_modules';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names only.
		$found = $wpdb->get_var( $wpdb->prepare( "SELECT l.id FROM {$lessons} l JOIN {$modules} m ON m.id = l.module_id WHERE l.id = %d AND m.batch_id = %d", $lesson_id, $batch_id ) );
		return null === $found ? 'That lesson does not belong to the chosen batch.' : null;
	}

	private static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'cc_live_classes';
	}
}
