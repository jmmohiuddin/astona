<?php
/**
 * Plugin Name: Coaching Platform
 * Description: Astona coaching center platform. Sub-project 1: catalogue module (courses, batches, faculty, notices, SEO).
 * Version: 0.1.0
 * Requires PHP: 8.1
 * Text Domain: coaching-platform
 */

defined( 'ABSPATH' ) || exit;

define( 'CC_VERSION', '0.1.0' );
define( 'CC_PATH', plugin_dir_path( __FILE__ ) );

// Optional Composer dependencies (mPDF for PDF receipts). The plugin works without them.
if ( is_readable( CC_PATH . 'vendor/autoload.php' ) ) {
	require_once CC_PATH . 'vendor/autoload.php';
}

foreach ( array( 'migrations', 'post-types', 'status-chip', 'batch-repository', 'batch-metabox', 'rest-courses', 'seo', 'seeder' ) as $cc_file ) {
	require_once CC_PATH . 'includes/class-' . $cc_file . '.php';
}

// Sub-project 2: admission + payment. Files are created by the module owners; loaded only if present.
foreach ( array(
	'support/class-crypto', 'support/class-phone', 'support/class-rate-limiter', 'support/class-idempotency',
	'payments/interface-payment-gateway', 'payments/interface-refundable-gateway', 'payments/class-fake-gateway', 'payments/class-bkash-gateway', 'payments/class-gateway-factory',
	'payments/class-settlement', 'payments/class-refund', 'payments/class-installments', 'class-daily-jobs', 'class-retention', 'payments/class-reconciler', 'payments/class-rest-payments',
	'admissions/class-application-repository', 'admissions/class-photo-store', 'admissions/class-phone-proof', 'admissions/class-rest-phone-proof', 'admissions/class-rest-admissions', 'admissions/class-seat-recount', 'admissions/class-waitlist',
	// Sub-project 3: accounts, SMS, portal.
	'sms/interface-sms-driver', 'sms/class-sms-fake-driver', 'sms/class-sms-http-driver', 'sms/class-sms-bulksmsbd-driver', 'sms/class-sms-greenweb-driver', 'sms/class-sms-factory', 'sms/class-sms',
	'enrollment/class-roles', 'enrollment/class-enrollment-repository', 'enrollment/class-provisioner', 'enrollment/class-student-lockdown',
	'auth/class-otp', 'auth/class-rest-auth', 'auth/class-student-guard',
	'portal/class-portal-router', 'portal/class-portal-data', 'portal/class-phone-change', 'portal/class-receipt-pdf',
	// Sub-project 4: admin.
	'admin/class-admin-roles', 'admin/class-audit', 'admin/class-admin-menu', 'admin/class-admin-settings', 'admin/class-admin-dashboard',
	// Sub-project 5: course content, live classes, targeted notices.
	'content/class-content-repository', 'content/class-resource-store',
	'live/class-live-repository', 'live/class-rest-live',
	'notices/class-notice-service', 'notices/class-notice-access',
	'portal/class-portal-courses',
	'admin/class-admin-content', 'admin/class-course-tree', 'admin/class-admin-course-editor', 'admin/class-admin-live', 'admin/class-admin-notices',
	// Sub-project 6: blog, gallery/results, contact/inquiries, media.
	'blog/class-blog', 'gallery/class-gallery', 'gallery/class-result-photo-store', 'gallery/class-result-photo-access', 'gallery/class-result-photo-migration', 'gallery/class-results',
	'contact/class-inquiry-repository', 'contact/class-rest-contact', 'contact/class-branches',
	'media/class-media-rules', 'admin/class-admin-inquiries', 'admin/class-admin-media',
	'security/class-totp', 'security/class-staff-login', 'security/class-staff-2fa', 'security/class-reauth', 'analytics/class-ga4', 'health/class-rest-health', 'admin/class-csv', 'admin/class-admin-applications', 'admin/class-admin-students', 'admin/class-admin-payments', 'admin/class-admin-audit', 'admin/class-admin-sms-log',
) as $cc_file ) {
	$cc_path = CC_PATH . 'includes/' . $cc_file . '.php';
	if ( is_readable( $cc_path ) ) {
		require_once $cc_path;
	}
}

register_activation_hook(
	__FILE__,
	static function () {
		CC_Migrations::run();
		CC_Post_Types::register();
		flush_rewrite_rules();
	}
);

add_action( 'plugins_loaded', array( 'CC_Migrations', 'maybe_upgrade' ) );
add_action( 'init', array( 'CC_Post_Types', 'register' ) );

CC_Batch_Metabox::init();
CC_Rest_Courses::init();
CC_Seo::init();

foreach ( array( 'CC_Rest_Admissions', 'CC_Rest_Phone_Proof', 'CC_Rest_Payments', 'CC_Reconciler', 'CC_Sms', 'CC_Roles', 'CC_Provisioner', 'CC_Student_Lockdown', 'CC_Rest_Auth', 'CC_Student_Guard', 'CC_Portal_Router', 'CC_Admin_Roles', 'CC_Admin_Menu', 'CC_Admin_Settings', 'CC_Admin_Applications', 'CC_Admin_Students', 'CC_Admin_Payments', 'CC_Admin_Audit', 'CC_Admin_Dashboard', 'CC_Rest_Live', 'CC_Notice_Service', 'CC_Notice_Access', 'CC_Portal_Courses', 'CC_Admin_Content', 'CC_Course_Tree', 'CC_Admin_Course_Editor', 'CC_Admin_Live', 'CC_Admin_Notices', 'CC_Blog', 'CC_Gallery', 'CC_Result_Photo_Access', 'CC_Results', 'CC_Rest_Contact', 'CC_Branches', 'CC_Media_Rules', 'CC_Admin_Inquiries', 'CC_Admin_Media', 'CC_Ga4', 'CC_Rest_Health', 'CC_Daily_Jobs', 'CC_Retention', 'CC_Installments', 'CC_Phone_Change', 'CC_Staff_Login', 'CC_Staff_2fa', 'CC_Reauth' ) as $cc_class ) {
	if ( class_exists( $cc_class ) ) {
		$cc_class::init();
	}
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'cc seed', array( 'CC_Seeder', 'run' ) );
	WP_CLI::add_command( 'cc recount-seats', array( 'CC_Seat_Recount', 'cli' ) );
}
