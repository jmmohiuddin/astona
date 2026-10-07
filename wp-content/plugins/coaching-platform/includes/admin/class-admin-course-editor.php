<?php
defined( 'ABSPATH' ) || exit;

/** Admin screen "Course editor": course picker, then the React Batch > Module > Lesson editor (assets/course-editor.js). */
final class CC_Admin_Course_Editor {

	const PAGE_SLUG = 'cc-course-editor';

	public static function init(): void {}

	public static function editor_url( int $course_id ): string {
		return add_query_arg( array( 'page' => self::PAGE_SLUG, 'course' => $course_id ), admin_url( 'admin.php' ) );
	}

	/** Called by CC_Admin_Menu::enqueue_assets() on this screen only. */
	public static function enqueue(): void {
		$course_id = self::course_id();
		if ( $course_id < 1 ) {
			return;
		}
		$main = dirname( __DIR__, 2 ) . '/coaching-platform.php';
		$ver  = CC_VERSION . '.' . (int) @filemtime( dirname( $main ) . '/assets/course-editor.js' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- cache-busting only.
		wp_enqueue_style( 'cc-course-editor', plugins_url( 'assets/course-editor.css', $main ), array(), $ver );
		wp_enqueue_script( 'cc-course-editor', plugins_url( 'assets/course-editor.js', $main ), array( 'wp-element', 'wp-api-fetch' ), $ver, true );
		wp_localize_script( 'cc-course-editor', 'CC_COURSE_EDITOR', array( 'courseId' => $course_id, 'timezone' => wp_timezone_string() ) );
	}

	private static function course_id(): int {
		$id = isset( $_GET['course'] ) ? absint( $_GET['course'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification -- read-only routing; capability checked by the REST route.
		return $id > 0 && 'cc_course' === get_post_type( $id ) ? $id : 0;
	}

	public static function render(): void {
		if ( ! current_user_can( CC_Course_Tree::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'coaching-platform' ), 403 );
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'Course editor', 'coaching-platform' ) . '</h1>';
		$course_id = self::course_id();
		if ( $course_id > 0 ) {
			echo '<p><a href="' . esc_url( add_query_arg( 'page', self::PAGE_SLUG, admin_url( 'admin.php' ) ) ) . '">&larr; ' . esc_html__( 'All courses', 'coaching-platform' ) . '</a>';
			echo ' &middot; <a href="' . esc_url( (string) get_edit_post_link( $course_id, 'raw' ) ) . '">' . esc_html__( 'Edit description, syllabus and instructors', 'coaching-platform' ) . '</a>';
			echo ' &middot; <a href="' . esc_url( add_query_arg( 'page', CC_Admin_Content::PAGE_SLUG, admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'Upload lesson PDFs', 'coaching-platform' ) . '</a></p>';
			echo '<noscript><p>' . esc_html__( 'The course editor needs JavaScript.', 'coaching-platform' ) . '</p></noscript><div id="cc-course-editor"><p>' . esc_html__( 'Loading...', 'coaching-platform' ) . '</p></div></div>';
			return;
		}
		$courses = get_posts( array( 'post_type' => 'cc_course', 'post_status' => array( 'publish', 'draft', 'pending', 'private' ), 'posts_per_page' => 200, 'orderby' => 'title', 'order' => 'ASC' ) );
		echo '<p>' . esc_html__( 'Pick a course to edit its batches, modules and lessons on one screen.', 'coaching-platform' ) . '</p>';
		echo '<table class="widefat striped" style="max-width:720px"><thead><tr><th scope="col">' . esc_html__( 'Course', 'coaching-platform' ) . '</th><th scope="col">' . esc_html__( 'Batches', 'coaching-platform' ) . '</th><th scope="col"><span class="screen-reader-text">' . esc_html__( 'Action', 'coaching-platform' ) . '</span></th></tr></thead><tbody>';
		if ( ! $courses ) {
			echo '<tr><td colspan="3">' . esc_html__( 'No courses yet. Add one under Courses first.', 'coaching-platform' ) . '</td></tr>';
		}
		foreach ( $courses as $course ) {
			printf(
				'<tr><td>%s</td><td>%d</td><td><a class="button" href="%s">%s</a></td></tr>',
				esc_html( get_the_title( $course ) ),
				count( CC_Batch_Repository::for_course( $course->ID ) ),
				esc_url( self::editor_url( $course->ID ) ),
				esc_html__( 'Edit batches and lessons', 'coaching-platform' )
			);
		}
		echo '</tbody></table></div>';
	}
}
