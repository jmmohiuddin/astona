<?php
defined( 'ABSPATH' ) || exit;

/**
 * JSON-LD (FR-016): EducationalOrganization, ItemList, Course + Offer.
 */
final class CC_Seo {

	public static function init(): void {
		add_action( 'wp_head', array( __CLASS__, 'output' ), 20 );
	}

	public static function output(): void {
		$graph = array();

		if ( is_front_page() ) {
			$graph[] = self::organization();
		} elseif ( is_post_type_archive( 'cc_course' ) ) {
			$graph[] = self::item_list();
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
			'name'  => get_bloginfo( 'name' ),
			'url'   => home_url( '/' ),
		);
	}

	private static function item_list(): array {
		$items = array();
		foreach ( CC_Batch_Repository::course_summaries() as $i => $course ) {
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

		$offers = array();
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
			$offers[]  = array(
				'@type'         => 'Offer',
				'category'      => $batch['delivery_mode'],
				'price'         => (string) $batch['price'],
				'priceCurrency' => $batch['currency'],
				'availability'  => $available ? 'https://schema.org/InStock' : 'https://schema.org/SoldOut',
				'url'           => get_permalink( $post ),
			);
		}
		if ( $offers ) {
			$course['offers'] = $offers;
		}
		return $course;
	}
}
