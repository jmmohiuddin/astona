<?php
defined( 'ABSPATH' ) || exit;

/**
 * Data access for cc_batches plus the catalogue summaries shared by theme and REST.
 */
final class CC_Batch_Repository {

	const MODES    = array( 'physical', 'online', 'hybrid' );
	const STATUSES = array( 'draft', 'open', 'closed', 'completed' );

	/** @return array<int,array<string,mixed>> */
	public static function for_course( int $course_id, bool $public_only = false ): array {
		global $wpdb;
		$table = CC_Migrations::table();
		$extra = $public_only ? " AND status <> 'draft'" : '';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name and static clause only.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE course_id = %d{$extra} ORDER BY start_date ASC, id ASC", $course_id ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	public static function find( int $batch_id ): ?array {
		global $wpdb;
		$table = CC_Migrations::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $batch_id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * @param int[] $course_ids
	 * @return array<int,array<int,array<string,mixed>>> batches keyed by course id
	 */
	public static function for_courses( array $course_ids ): array {
		global $wpdb;
		$course_ids = array_values( array_unique( array_map( 'absint', $course_ids ) ) );
		if ( ! $course_ids ) {
			return array();
		}
		$table        = CC_Migrations::table();
		$placeholders = implode( ',', array_fill( 0, count( $course_ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.UnfinishedPrepare
		$rows    = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE status <> 'draft' AND course_id IN ({$placeholders}) ORDER BY start_date ASC, id ASC", $course_ids ), ARRAY_A );
		$grouped = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$grouped[ (int) $row['course_id'] ][] = $row;
		}
		return $grouped;
	}

	/**
	 * Insert/update/delete a course's batches to match $rows. seats_taken is never taken from input.
	 *
	 * @param array<int,array<string,mixed>> $rows Sanitised rows; optional 'id'.
	 */
	public static function sync( int $course_id, array $rows ): void {
		global $wpdb;
		$table = CC_Migrations::table();
		$now   = gmdate( 'Y-m-d H:i:s' );

		$existing     = self::for_course( $course_id );
		$existing_ids = array_map( 'intval', wp_list_pluck( $existing, 'id' ) );
		$seats_by_id  = array_column( $existing, 'seats_taken', 'id' );
		$kept_ids     = array();

		foreach ( $rows as $row ) {
			$id      = isset( $row['id'] ) ? (int) $row['id'] : 0;
			$payload = array(
				'name'             => $row['name'],
				'delivery_mode'    => $row['delivery_mode'],
				'capacity'         => $row['capacity'],
				'price'            => $row['price'],
				'start_date'       => $row['start_date'],
				'end_date'         => $row['end_date'] ?: null,
				'schedule_text'    => $row['schedule_text'],
				'status'           => $row['status'],
				'application_open' => $row['application_open'] ? 1 : 0,
				'waitlist_enabled' => ! empty( $row['waitlist_enabled'] ) ? 1 : 0,
				'installments_enabled' => ! empty( $row['installments_enabled'] ) ? 1 : 0,
				'first_payment_percent' => self::clamp_percent( (int) ( $row['first_payment_percent'] ?? 50 ) ),
				'installment_days' => max( 1, min( 365, (int) ( $row['installment_days'] ?? 30 ) ) ),
				'updated_at'       => $now,
			);
			if ( $id && in_array( $id, $existing_ids, true ) ) {
				$wpdb->update( $table, $payload, array( 'id' => $id, 'course_id' => $course_id ) );
				$kept_ids[] = $id;
			} else {
				$wpdb->insert( $table, array_merge( $payload, array( 'course_id' => $course_id, 'created_at' => $now ) ) );
			}
		}

		foreach ( array_diff( $existing_ids, $kept_ids ) as $stale_id ) {
			if ( (int) ( $seats_by_id[ $stale_id ] ?? 0 ) > 0 ) {
				// Batches with admissions are never hard-deleted; close them instead.
				$wpdb->update( $table, array( 'status' => 'closed', 'application_open' => 0, 'updated_at' => $now ), array( 'id' => $stale_id, 'course_id' => $course_id ) );
				continue;
			}
			$wpdb->delete( $table, array( 'id' => $stale_id, 'course_id' => $course_id ) );
		}

		// A raised capacity may have freed seats for people on a waitlist.
		if ( class_exists( 'CC_Waitlist' ) ) {
			CC_Waitlist::fill_all();
		}
	}

	/** The first installment must be a real share of the fee: 10% to 90%. */
	public static function clamp_percent( int $percent ): int {
		return max( 10, min( 90, $percent ) );
	}

	/**
	 * What the applicant pays now for a plan, and what remains. The first payment is rounded UP to a whole taka so the
	 * balance never carries paisa.
	 *
	 * @return array{first:float,balance:float}
	 */
	public static function split_amount( float $price, int $percent ): array {
		$first = min( $price, (float) ceil( $price * self::clamp_percent( $percent ) / 100 ) );
		return array( 'first' => round( $first, 2 ), 'balance' => round( $price - $first, 2 ) );
	}

	/**
	 * Prices of batches still accepting applications; falls back to all if none are.
	 *
	 * @param array<int,array<string,mixed>> $batches
	 * @return float[]
	 */
	public static function headline_prices( array $batches ): array {
		$available = array_filter(
			$batches,
			static fn( array $b ): bool => CC_Status_Chip::CLOSED !== CC_Status_Chip::for_row( $b )
		);
		return array_map( 'floatval', wp_list_pluck( $available ?: $batches, 'price' ) );
	}

	/**
	 * Catalogue rows for the archive page and GET /cc/v1/courses.
	 *
	 * @param array{category?:string,mode?:string} $filters
	 * @return array<int,array<string,mixed>>
	 */
	public static function course_summaries( array $filters = array(), int $limit = 100 ): array {
		$args = array(
			'post_type'      => 'cc_course',
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'orderby'        => 'menu_order title',
			'order'          => 'ASC',
		);
		if ( ! empty( $filters['category'] ) ) {
			$args['tax_query'] = array(
				array(
					'taxonomy' => 'cc_category',
					'field'    => 'slug',
					'terms'    => $filters['category'],
				),
			);
		}

		$posts   = get_posts( $args );
		$batches = self::for_courses( wp_list_pluck( $posts, 'ID' ) );
		$mode    = $filters['mode'] ?? '';
		$out     = array();

		foreach ( $posts as $post ) {
			$course_batches = $batches[ $post->ID ] ?? array();
			$modes          = array_values( array_unique( wp_list_pluck( $course_batches, 'delivery_mode' ) ) );
			if ( $mode && ! in_array( $mode, $modes, true ) ) {
				continue;
			}
			$chip   = CC_Status_Chip::for_batches( $course_batches );
			$prices = self::headline_prices( $course_batches );
			$terms  = wp_get_post_terms( $post->ID, 'cc_category', array( 'fields' => 'names' ) );

			$out[] = array(
				'id'            => $post->ID,
				'title'         => get_the_title( $post ),
				'url'           => get_permalink( $post ),
				'excerpt'       => wp_strip_all_tags( get_the_excerpt( $post ) ),
				'thumbnail'     => get_the_post_thumbnail_url( $post, 'medium_large' ) ?: '',
				'categories'    => is_array( $terms ) ? $terms : array(),
				'modes'         => $modes,
				'min_price'     => $prices ? min( $prices ) : null,
				'price_display' => $prices ? '৳ ' . number_format( min( $prices ) ) : '',
				'status'        => $chip,
				'status_label'  => CC_Status_Chip::label( $chip ),
			);
		}
		return $out;
	}
}
