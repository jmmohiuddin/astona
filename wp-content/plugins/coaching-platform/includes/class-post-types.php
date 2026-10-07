<?php
defined( 'ABSPATH' ) || exit;

/**
 * Content types: courses, faculty, branches, notices + course category taxonomy.
 */
final class CC_Post_Types {

	public static function register(): void {
		register_post_type(
			'cc_course',
			array(
				'labels'       => self::labels( 'Course', 'Courses' ),
				'public'       => true,
				'has_archive'  => 'courses',
				'rewrite'      => array( 'slug' => 'courses' ),
				'menu_icon'    => 'dashicons-welcome-learn-more',
				'supports'     => array( 'title', 'editor', 'excerpt', 'thumbnail' ),
				'show_in_rest' => true,
			)
		);

		register_post_type(
			'cc_faculty',
			array(
				'labels'       => self::labels( 'Faculty Member', 'Faculty' ),
				'public'       => true,
				'has_archive'  => 'faculty',
				'rewrite'      => array( 'slug' => 'faculty-member' ),
				'menu_icon'    => 'dashicons-businessperson',
				'supports'     => array( 'title', 'editor', 'thumbnail' ),
				'show_in_rest' => true,
			)
		);

		register_post_type(
			'cc_branch',
			array(
				'labels'             => self::labels( 'Branch', 'Branches' ),
				'public'             => false,
				'show_ui'            => true,
				'menu_icon'          => 'dashicons-location',
				'supports'           => array( 'title', 'editor' ),
				'publicly_queryable' => false,
			)
		);

		register_post_type(
			'cc_notice',
			array(
				'labels'       => self::labels( 'Notice', 'Notices' ),
				'public'       => true,
				'has_archive'  => 'notices',
				'rewrite'      => array( 'slug' => 'notices' ),
				'menu_icon'    => 'dashicons-megaphone',
				'supports'     => array( 'title', 'editor', 'excerpt' ),
				'show_in_rest' => true,
			)
		);

		register_taxonomy(
			'cc_category',
			array( 'cc_course' ),
			array(
				'labels'       => self::labels( 'Course Category', 'Course Categories' ),
				'public'       => true,
				'hierarchical' => true,
				'rewrite'      => array( 'slug' => 'course-category' ),
				'show_in_rest' => true,
			)
		);
	}

	private static function labels( string $singular, string $plural ): array {
		return array(
			'name'          => $plural,
			'singular_name' => $singular,
			'add_new_item'  => 'Add New ' . $singular,
			'edit_item'     => 'Edit ' . $singular,
			'all_items'     => 'All ' . $plural,
			'search_items'  => 'Search ' . $plural,
		);
	}
}
