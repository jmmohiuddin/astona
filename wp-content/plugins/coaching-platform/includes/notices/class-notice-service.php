<?php
defined( 'ABSPATH' ) || exit;

/**
 * Targeted notices. A cc_notice with no rows in cc_notice_targets is public; otherwise it is visible only to students
 * with an ACTIVE enrollment in a target batch. Archiving is a meta flag so the underlying WP status is kept.
 */
final class CC_Notice_Service {

	const POST_TYPE        = 'cc_notice';
	const META_CRITICAL    = 'cc_notice_critical';
	const META_ARCHIVED    = 'cc_notice_archived';
	const META_SMS_SENT    = 'cc_notice_sms_sent';
	const SMS_CLAIM_PREFIX = 'cc_notice_sms_claim_';
	const SMS_TITLE_LENGTH = 60;
	const MAX_PAGE_SIZE    = 100;

	public static function init(): void {
		add_action( 'transition_post_status', array( __CLASS__, 'on_transition' ), 10, 3 );
		add_action( 'before_delete_post', array( __CLASS__, 'release_sms_claim' ) );
	}

	private static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'cc_' . $name;
	}

	/** @return array{public:bool,batch_ids:int[]} */
	public static function audience( int $notice_id ): array {
		global $wpdb;
		$targets = self::table( 'notice_targets' );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT batch_id FROM {$targets} WHERE notice_id = %d ORDER BY batch_id", $notice_id ) );
		$ids = array_map( 'intval', (array) $ids );
		return array( 'public' => array() === $ids, 'batch_ids' => $ids );
	}

	/** Replaces the audience. An empty list makes the notice public. */
	public static function set_audience( int $notice_id, array $batch_ids ): void {
		global $wpdb;
		$targets   = self::table( 'notice_targets' );
		$batch_ids = array_values( array_unique( array_filter( array_map( 'absint', $batch_ids ) ) ) );
		$wpdb->delete( $targets, array( 'notice_id' => $notice_id ), array( '%d' ) );
		foreach ( $batch_ids as $batch_id ) {
			$wpdb->insert( $targets, array( 'notice_id' => $notice_id, 'batch_id' => $batch_id ), array( '%d', '%d' ) );
		}
		do_action( 'cc_catalogue_changed' );
	}

	public static function is_archived( int $notice_id ): bool {
		return '1' === (string) get_post_meta( $notice_id, self::META_ARCHIVED, true );
	}

	public static function set_archived( int $notice_id, bool $archived ): void {
		if ( $archived ) {
			update_post_meta( $notice_id, self::META_ARCHIVED, '1' );
		} else {
			delete_post_meta( $notice_id, self::META_ARCHIVED );
		}
		do_action( 'cc_catalogue_changed' );
	}

	public static function is_critical( int $notice_id ): bool {
		return '1' === (string) get_post_meta( $notice_id, self::META_CRITICAL, true );
	}

	/**
	 * Students with an active enrollment in a target batch, once each. Public notices have no recipients.
	 *
	 * @return array<int,array{user_id:int,phone:string}>
	 */
	public static function recipients( int $notice_id ): array {
		return self::recipients_for_batches( self::audience( $notice_id )['batch_ids'] );
	}

	/**
	 * Same as recipients() for a batch list that is not saved yet (audience preview). No batches means a public notice.
	 *
	 * @param int[] $batch_ids
	 * @return array<int,array{user_id:int,phone:string}>
	 */
	public static function recipients_for_batches( array $batch_ids ): array {
		global $wpdb;
		$batch_ids = array_values( array_unique( array_filter( array_map( 'absint', $batch_ids ) ) ) );
		if ( ! $batch_ids ) {
			return array();
		}
		$enrollments  = self::table( 'enrollments' );
		$placeholders = implode( ',', array_fill( 0, count( $batch_ids ), '%d' ) );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.UnfinishedPrepare -- table name and placeholder list only.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT u.ID AS user_id, u.user_login
				FROM {$enrollments} e JOIN {$wpdb->users} u ON u.ID = e.user_id
				WHERE e.batch_id IN ({$placeholders}) AND e.status = 'active' AND (e.expires_at IS NULL OR e.expires_at > %s)
				ORDER BY u.ID",
				array_merge( $batch_ids, array( gmdate( 'Y-m-d H:i:s' ) ) )
			),
			ARRAY_A
		);
		// phpcs:enable
		$out  = array();
		$seen = array();
		foreach ( (array) $rows as $row ) {
			$phone = CC_Phone::normalize( '+' . $row['user_login'] );
			if ( null === $phone || isset( $seen[ $phone ] ) ) {
				continue;
			}
			$seen[ $phone ] = true;
			$out[]          = array( 'user_id' => (int) $row['user_id'], 'phone' => $phone );
		}
		return $out;
	}

	/**
	 * Published, non-archived notices a student may read: those targeted at an ACTIVE batch of theirs plus public ones.
	 *
	 * @return array<int,array{id:int,title:string,excerpt:string,content:string,date_gmt:string,url:string,public:bool,critical:bool}>
	 */
	public static function visible_to_user( int $user_id, int $limit = 20, int $offset = 0 ): array {
		global $wpdb;
		$limit       = max( 1, min( self::MAX_PAGE_SIZE, $limit ) );
		$offset      = max( 0, $offset );
		$targets     = self::table( 'notice_targets' );
		$enrollments = self::table( 'enrollments' );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names only.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				WHERE p.post_type = %s AND p.post_status = 'publish'
				AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = p.ID AND m.meta_key = %s AND m.meta_value = '1')
				AND (
					NOT EXISTS (SELECT 1 FROM {$targets} t WHERE t.notice_id = p.ID)
					OR EXISTS (
						SELECT 1 FROM {$targets} t JOIN {$enrollments} e ON e.batch_id = t.batch_id
						WHERE t.notice_id = p.ID AND e.user_id = %d AND e.status = 'active' AND (e.expires_at IS NULL OR e.expires_at > %s)
					)
				)
				ORDER BY p.post_date_gmt DESC, p.ID DESC LIMIT %d OFFSET %d",
				self::POST_TYPE,
				self::META_ARCHIVED,
				$user_id,
				gmdate( 'Y-m-d H:i:s' ),
				$limit,
				$offset
			)
		);
		// phpcs:enable
		$items = array();
		foreach ( (array) $ids as $id ) {
			$post = get_post( (int) $id );
			if ( ! $post instanceof WP_Post ) {
				continue;
			}
			$items[] = array(
				'id'       => (int) $post->ID,
				'title'    => get_the_title( $post ),
				'excerpt'  => wp_trim_words( html_entity_decode( wp_strip_all_tags( $post->post_content ), ENT_QUOTES, 'UTF-8' ), 30 ),
				'content'  => $post->post_content,
				'date_gmt' => $post->post_date_gmt,
				'url'      => get_permalink( $post ),
				'public'   => self::audience( (int) $post->ID )['public'],
				'critical' => self::is_critical( (int) $post->ID ),
			);
		}
		return $items;
	}

	/**
	 * Claims the one-time SMS send for a notice. option_name is a UNIQUE key, so of any number of concurrent publishes
	 * exactly one INSERT IGNORE affects a row; a postmeta add_post_meta( ..., unique ) is only check-then-insert.
	 */
	public static function claim_sms_send( int $notice_id ): bool {
		global $wpdb;
		if ( metadata_exists( 'post', $notice_id, self::META_SMS_SENT ) ) {
			return false; // Sent (or claimed) before the claim row existed.
		}
		$claimed = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '1', 'no')", self::SMS_CLAIM_PREFIX . $notice_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		return 1 === (int) $claimed;
	}

	public static function release_sms_claim( $post_id ): void {
		if ( self::POST_TYPE === get_post_type( (int) $post_id ) ) {
			delete_option( self::SMS_CLAIM_PREFIX . (int) $post_id );
		}
	}

	/** Covers draft/scheduled to publish too. claim_sms_send() makes it at-most-once, so a republish or a race never resends. */
	public static function on_transition( $new_status, $old_status, $post ): void {
		if ( 'publish' !== $new_status || 'publish' === $old_status || ! $post instanceof WP_Post || self::POST_TYPE !== $post->post_type ) {
			return;
		}
		do_action( 'cc_catalogue_changed' );
		$notice_id = (int) $post->ID;
		if ( ! self::is_critical( $notice_id ) || self::audience( $notice_id )['public'] ) {
			return;
		}
		if ( ! self::claim_sms_send( $notice_id ) ) {
			return;
		}
		update_post_meta( $notice_id, self::META_SMS_SENT, '0' );
		// Raw post_title, decoded: get_the_title() texturizes and kses stores "&" as "&amp;", neither of which SMS renders.
		$title  = mb_substr( html_entity_decode( wp_strip_all_tags( $post->post_title ), ENT_QUOTES, 'UTF-8' ), 0, self::SMS_TITLE_LENGTH );
		$url    = home_url( '/student/notices/' );
		$queued = 0;
		foreach ( self::recipients( $notice_id ) as $recipient ) {
			try {
				CC_Sms::queue( $recipient['phone'], 'notice', array( 'title' => $title, 'url' => $url ), 'notice', $notice_id );
				++$queued;
			} catch ( Throwable $e ) {
				error_log( sprintf( '[cc-notices] notice #%d SMS not queued for user #%d: %s', $notice_id, $recipient['user_id'], get_class( $e ) ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		}
		update_post_meta( $notice_id, self::META_SMS_SENT, (string) $queued );
	}
}
