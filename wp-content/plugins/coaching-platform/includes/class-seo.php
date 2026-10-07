<?php
defined( 'ABSPATH' ) || exit;

/**
 * JSON-LD (FR-016, PRD-FUNC-013): WebSite + EducationalOrganization, ItemList (only with 3 or more courses, the minimum for
 * Google's carousel), Course with CourseInstance + Offer per open batch.
 */
final class CC_Seo {

	const ITEM_LIST_MINIMUM = 3;

	/** schema.org courseMode for each delivery mode. */
	const COURSE_MODES = array( 'physical' => 'onsite', 'online' => 'online', 'hybrid' => 'blended' );

	public static function init(): void {
		add_action( 'wp_head', array( __CLASS__, 'output' ), 20 );
	}

	public static function output(): void {
		$graph = array();

		$graph[] = self::organization();
		if ( is_front_page() ) {
			$graph[] = self::website();
		} elseif ( is_post_type_archive( 'cc_course' ) ) {
			$list = self::item_list( CC_Batch_Repository::course_summaries() );
			if ( null !== $list ) {
				$graph[] = $list;
			}
		} elseif ( is_singular( 'cc_course' ) ) {
			$graph[] = self::course( get_post() );
		}

		$graph = apply_filters( 'cc_seo_graph', $graph );

		if ( ! $graph ) {
			return;
		}

		$json = wp_json_encode(
			array(
				'@context' => 'https://schema.org',
				'@graph'   => $graph,
			),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP
		);
		echo '<script type="application/ld+json">' . $json . "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- JSON with HEX_TAG/AMP flags.
	}

	private static function organization(): array {
		return array(
			'@type' => 'EducationalOrganization',
			'@id'   => home_url( '/#organization' ),
			'name'  => get_bloginfo( 'name' ),
			'url'   => home_url( '/' ),
		);
	}

	private static function website(): array {
		return array(
			'@type'     => 'WebSite',
			'@id'       => home_url( '/#website' ),
			'name'      => get_bloginfo( 'name' ),
			'url'       => home_url( '/' ),
			'publisher' => array( '@id' => home_url( '/#organization' ) ),
		);
	}

	/**
	 * @param array<int,array{url:string,title:string}> $courses
	 * @return array|null Null below the carousel minimum: a one- or two-item list is not eligible and only adds noise.
	 */
	public static function item_list( array $courses ): ?array {
		if ( count( $courses ) < self::ITEM_LIST_MINIMUM ) {
			return null;
		}
		$items = array();
		foreach ( array_values( $courses ) as $i => $course ) {
			$items[] = array(
				'@type'    => 'ListItem',
				'position' => $i + 1,
				'url'      => $course['url'],
				'name'     => $course['title'],
			);
		}
		return array(
			'@type'           => 'ItemList',
			'itemListElement' => $items,
		);
	}

	private static function course( WP_Post $post ): array {
		$course = array(
			'@type'       => 'Course',
			'name'        => get_the_title( $post ),
			'description' => wp_strip_all_tags( get_the_excerpt( $post ) ),
			'url'         => get_permalink( $post ),
			'provider'    => array(
				'@type' => 'EducationalOrganization',
				'name'  => get_bloginfo( 'name' ),
				'url'   => home_url( '/' ),
			),
		);

		$offers    = array();
		$instances = array();
		foreach ( CC_Batch_Repository::for_course( $post->ID, true ) as $batch ) {
			if ( 'completed' === $batch['status'] ) {
				continue;
			}
			$available = CC_Status_Chip::CLOSED !== CC_Status_Chip::for_batch(
				(string) $batch['status'],
				(bool) $batch['application_open'],
				(int) $batch['capacity'],
				(int) $batch['seats_taken']
			);
			$offer     = array(
				'@type'         => 'Offer',
				'category'      => $batch['delivery_mode'],
				'price'         => (string) $batch['price'],
				'priceCurrency' => $batch['currency'],
				'availability'  => $available ? 'https://schema.org/InStock' : 'https://schema.org/SoldOut',
				'url'           => get_permalink( $post ),
			);
			$offers[]    = $offer;
			$instances[] = self::course_instance( $batch, $offer );
		}
		if ( $offers ) {
			$course['offers']            = $offers;
			$course['hasCourseInstance'] = $instances;
		}
		return $course;
	}

	/**
	 * One CourseInstance per batch: mode, dates and the batch's own Offer (PRD-FUNC-013).
	 *
	 * @param array<string,mixed> $batch Row shape of CC_Batch_Repository::for_course().
	 */
	public static function course_instance( array $batch, array $offer ): array {
		$instance = array(
			'@type'      => 'CourseInstance',
			'name'       => (string) ( $batch['name'] ?? '' ),
			'courseMode' => self::COURSE_MODES[ $batch['delivery_mode'] ] ?? 'onsite',
			'offers'     => $offer,
		);
		if ( ! empty( $batch['start_date'] ) ) {
			$instance['startDate'] = (string) $batch['start_date'];
		}
		if ( ! empty( $batch['end_date'] ) ) {
			$instance['endDate'] = (string) $batch['end_date'];
		}
		return $instance;
	}
}
