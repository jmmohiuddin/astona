<?php
defined( 'ABSPATH' ) || exit;

/** Registers the "Astona" wp-admin menu. Items whose renderer class does not exist yet are skipped. */
final class CC_Admin_Menu {

	const TOP_SLUG = 'cc-dashboard';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/** @return array<int,array{slug:string,title:string,cap:string,class:string}> In workflow order. */
	public static function items(): array {
		return array(
			array( 'slug' => 'cc-dashboard', 'title' => 'Dashboard', 'cap' => CC_Admin_Roles::CAP_VIEW_DASHBOARD, 'class' => 'CC_Admin_Dashboard' ),
			array( 'slug' => 'cc-applications', 'title' => 'Applications', 'cap' => CC_Admin_Roles::CAP_REVIEW_APPLICATIONS, 'class' => 'CC_Admin_Applications' ),
			array( 'slug' => 'cc-students', 'title' => 'Students', 'cap' => CC_Admin_Roles::CAP_VIEW_STUDENTS, 'class' => 'CC_Admin_Students' ),
			array( 'slug' => 'cc-course-editor', 'title' => 'Course editor', 'cap' => CC_Admin_Roles::CAP_MANAGE_CONTENT, 'class' => 'CC_Admin_Course_Editor' ),
			array( 'slug' => 'cc-content', 'title' => 'Course content', 'cap' => CC_Admin_Roles::CAP_MANAGE_CONTENT, 'class' => 'CC_Admin_Content' ),
			array( 'slug' => 'cc-live', 'title' => 'Live classes', 'cap' => CC_Admin_Roles::CAP_MANAGE_LIVE, 'class' => 'CC_Admin_Live' ),
			array( 'slug' => 'cc-notices', 'title' => 'Notices', 'cap' => CC_Admin_Roles::CAP_MANAGE_NOTICES, 'class' => 'CC_Admin_Notices' ),
			array( 'slug' => 'cc-inquiries', 'title' => 'Inquiries', 'cap' => CC_Admin_Roles::CAP_VIEW_INQUIRIES, 'class' => 'CC_Admin_Inquiries' ),
			array( 'slug' => 'cc-media', 'title' => 'Media', 'cap' => CC_Admin_Roles::CAP_MANAGE_MEDIA, 'class' => 'CC_Admin_Media' ),
			array( 'slug' => 'cc-payments', 'title' => 'Payments', 'cap' => CC_Admin_Roles::CAP_VIEW_PAYMENTS, 'class' => 'CC_Admin_Payments' ),
			array( 'slug' => 'cc-audit', 'title' => 'Audit log', 'cap' => CC_Admin_Roles::CAP_VIEW_AUDIT, 'class' => 'CC_Admin_Audit' ),
			array( 'slug' => 'cc-sms-log', 'title' => 'SMS log', 'cap' => CC_Admin_Roles::CAP_VIEW_AUDIT, 'class' => 'CC_Admin_Sms_Log' ),
			array( 'slug' => 'cc-settings', 'title' => 'Settings', 'cap' => CC_Admin_Roles::CAP_MANAGE_SETTINGS, 'class' => 'CC_Admin_Settings' ),
		);
	}

	public static function register(): void {
		$available = array_filter(
			self::items(),
			static fn( array $item ): bool => is_callable( array( $item['class'], 'render' ) )
		);
		if ( ! $available ) {
			return;
		}

		add_menu_page( 'Astona', 'Astona', CC_Admin_Roles::CAP_VIEW_DASHBOARD, self::TOP_SLUG, '__return_null', 'dashicons-welcome-learn-more', 25 );

		foreach ( $available as $item ) {
			$title = esc_html( $item['title'] );
			if ( 'cc-applications' === $item['slug'] ) {
				$pending = self::pending_applications();
				if ( $pending > 0 && current_user_can( $item['cap'] ) ) {
					$title .= sprintf( ' <span class="awaiting-mod">%d</span>', $pending );
				}
			}
			if ( 'cc-inquiries' === $item['slug'] && is_callable( array( 'CC_Admin_Inquiries', 'new_count' ) ) && current_user_can( $item['cap'] ) ) {
				$new = (int) CC_Admin_Inquiries::new_count();
				if ( $new > 0 ) {
					$title .= sprintf( ' <span class="awaiting-mod">%d</span>', $new );
				}
			}
			add_submenu_page( self::TOP_SLUG, $item['title'], $title, $item['cap'], $item['slug'], array( $item['class'], 'render' ) );
		}
	}

	/** @param mixed $hook Null when admin-header.php is loaded outside an admin page (admin-post.php). */
	public static function enqueue_assets( $hook ): void {
		if ( ! is_string( $hook ) || false === strpos( $hook, 'cc-' ) ) {
			return;
		}
		$main = dirname( __DIR__, 2 ) . '/coaching-platform.php';
		if ( false !== strpos( $hook, 'cc-course-editor' ) && class_exists( 'CC_Admin_Course_Editor' ) ) {
			CC_Admin_Course_Editor::enqueue();
		}
		wp_enqueue_style( 'cc-admin', plugins_url( 'assets/admin.css', $main ), array(), CC_VERSION . '.' . (int) @filemtime( dirname( $main ) . '/assets/admin.css' ) );
	}

	private static function pending_applications(): int {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}cc_applications WHERE status = 'pending'" );
	}
}
