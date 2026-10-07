<?php
/**
 * Integration tests for CC_Student_Lockdown and the provisioner's account-takeover fixes. Run inside the wpcli container:
 *   docker compose run --rm -T wpcli eval-file /tests/integration/security-test.php
 * Creates a throwaway student (via the provisioner) and a subscriber; removes everything afterwards.
 * The live-endpoint checks call http://wordpress/ from inside the compose network.
 */
global $wpdb, $failures, $checks;
$p        = $wpdb->prefix;
$failures = 0;
$checks   = 0;

function t_assert( bool $cond, string $label ): void {
	global $failures, $checks;
	++$checks;
	if ( $cond ) {
		echo "  ok   $label\n";
		return;
	}
	++$failures;
	echo "  FAIL $label\n";
}

foreach ( array( 'CC_Student_Lockdown', 'CC_Provisioner', 'CC_Rest_Auth', 'CC_Roles' ) as $class ) {
	if ( ! class_exists( $class ) ) {
		echo "SKIP: $class not loaded.\n";
		exit( 1 );
	}
}

add_filter( 'pre_wp_mail', '__return_true' ); // never send mail from tests
$now = gmdate( 'Y-m-d H:i:s' );
$wpdb->insert( "{$p}cc_batches", array(
	'course_id' => 0, 'name' => 'ZZ-SEC ' . bin2hex( random_bytes( 4 ) ), 'capacity' => 10, 'seats_taken' => 1, 'price' => 100,
	'start_date' => '2030-01-01', 'schedule_text' => 'Sat', 'status' => 'open', 'application_open' => 1, 'created_at' => $now, 'updated_at' => $now,
) );
$batch = (int) $wpdb->insert_id;

$phone       = '+88019' . str_pad( (string) random_int( 0, 99999999 ), 8, '0', STR_PAD_LEFT );
$login       = ltrim( $phone, '+' );
$victim_mail = 'zz-sec-' . bin2hex( random_bytes( 3 ) ) . '@example.test';
$wpdb->insert( "{$p}cc_applications", array(
	'public_ref' => substr( strtoupper( 'ZZSEC' . bin2hex( random_bytes( 10 ) ) ), 0, 26 ), 'idempotency_key' => wp_generate_uuid4(),
	'batch_id' => $batch, 'student_phone' => $phone, 'full_name' => 'ZZ Sec Student', 'gender' => 'o', 'dob' => '2008-01-01',
	'id_doc_type' => 'nid', 'id_doc_enc' => 'x', 'guardian_name' => 'G', 'guardian_phone' => '+8801700000001',
	'email' => $victim_mail, 'institution' => 'I', 'class_level' => '10', 'consent_at' => $now, 'status' => 'approved',
	'created_at' => $now, 'updated_at' => $now,
) );
$app = (int) $wpdb->insert_id;

echo "H1: provisioning does not trust the applicant email\n";
$r       = CC_Provisioner::provision( $app );
$student = get_userdata( $r['user_id'] );
$pw      = 'SecTest-' . bin2hex( random_bytes( 6 ) );
wp_set_password( $pw, $student->ID );
$student = get_userdata( $student->ID );
t_assert( $login . '@students.invalid' === $student->user_email, 'user_email is the .invalid placeholder' );
t_assert( $victim_mail === get_user_meta( $student->ID, 'cc_application_email', true ), 'application email stored in cc_application_email meta' );
t_assert( false === email_exists( $victim_mail ), 'applicant email is not a WP account email' );

$sub_id = wp_insert_user( array( 'user_login' => 'zzsec' . bin2hex( random_bytes( 3 ) ), 'user_pass' => wp_generate_password(), 'user_email' => 'zz-sub-' . bin2hex( random_bytes( 3 ) ) . '@example.test', 'role' => 'subscriber' ) );
$sub    = get_userdata( (int) $sub_id );

echo "H1: lostpassword / reset denied for students\n";
t_assert( is_wp_error( apply_filters( 'allow_password_reset', true, $student->ID ) ), 'allow_password_reset filter denies a student' );
t_assert( true === apply_filters( 'allow_password_reset', true, $sub->ID ), 'allow_password_reset still allows a non-student' );
$rp = retrieve_password( $login );
t_assert( is_wp_error( $rp ) && 'password_reset_not_allowed' === $rp->get_error_code(), 'retrieve_password() refuses the student login' );
$rp = retrieve_password( $student->user_email );
t_assert( is_wp_error( $rp ), 'retrieve_password() refuses the student by email' );
t_assert( is_wp_error( get_password_reset_key( $student ) ), 'get_password_reset_key() refuses a student' );
t_assert( true === retrieve_password( $sub->user_login ), 'retrieve_password() still works for a non-student' );
$errors = new WP_Error();
do_action( 'lostpassword_post', $errors, $student );
t_assert( $errors->has_errors(), 'lostpassword_post adds an error for a student' );
$errors = new WP_Error();
do_action( 'lostpassword_post', $errors, $sub );
t_assert( ! $errors->has_errors(), 'lostpassword_post adds no error for a non-student' );

echo "H2: core login path rejects students, REST login works\n";
$auth = wp_authenticate( $login, $pw );
t_assert( is_wp_error( $auth ) && 'invalid_username' === $auth->get_error_code() && 'Invalid credentials' === $auth->get_error_message(), 'wp_authenticate() rejects a student with the correct password' );
t_assert( wp_check_password( $pw, $student->user_pass, $student->ID ), 'precondition: that password is correct' );
t_assert( false === wp_is_application_passwords_available_for_user( $student ), 'application passwords unavailable for students' );
$sub_pw = 'SubTest-' . bin2hex( random_bytes( 6 ) );
wp_set_password( $sub_pw, $sub->ID );
t_assert( $sub->ID === ( wp_authenticate( $sub->user_login, $sub_pw )->ID ?? 0 ), 'wp_authenticate() still logs a non-student in' );

$live = wp_remote_post( 'http://wordpress/wp-json/cc/v1/auth/login', array(
	'headers' => array( 'Content-Type' => 'application/json' ),
	'body'    => wp_json_encode( array( 'phone' => $phone, 'password' => $pw ) ),
	'timeout' => 20,
) );
if ( is_wp_error( $live ) ) {
	t_assert( false, 'live REST login reachable: ' . $live->get_error_message() );
} else {
	$cookies = wp_remote_retrieve_cookies( $live );
	$has_auth = false;
	foreach ( $cookies as $c ) {
		$has_auth = $has_auth || 0 === strpos( $c->name, 'wordpress_logged_in_' );
	}
	t_assert( 200 === wp_remote_retrieve_response_code( $live ) && $has_auth, 'live /cc/v1/auth/login signs the student in (200 + auth cookie)' );
}
$form = wp_remote_post( 'http://wordpress/wp-login.php', array( 'body' => array( 'log' => $login, 'pwd' => $pw ), 'timeout' => 20, 'redirection' => 0 ) );
if ( is_wp_error( $form ) ) {
	t_assert( false, 'live wp-login.php reachable: ' . $form->get_error_message() );
} else {
	$logged_in = false;
	foreach ( wp_remote_retrieve_cookies( $form ) as $c ) {
		$logged_in = $logged_in || 0 === strpos( $c->name, 'wordpress_logged_in_' );
	}
	t_assert( ! $logged_in && 200 === wp_remote_retrieve_response_code( $form ), 'live wp-login.php refuses the student (no auth cookie)' );
}

echo "H3: core users REST closed to students\n";
$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0] ?? null;
if ( $admin ) {
	wp_set_current_user( $admin->ID );
	$res = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/users' ) );
	t_assert( 200 === $res->get_status(), 'control: administrators can still list users' );
}
wp_set_current_user( $student->ID );
foreach ( array( array( 'GET', '/wp/v2/users' ), array( 'GET', '/wp/v2/users/' . $student->ID ), array( 'GET', '/wp/v2/users/me' ) ) as $case ) {
	$res = rest_do_request( new WP_REST_Request( $case[0], $case[1] ) );
	t_assert( in_array( $res->get_status(), array( 403, 404 ), true ), "student: {$case[0]} {$case[1]} denied (" . $res->get_status() . ')' );
}
$routes = rest_get_server()->get_routes();
t_assert( array() === array_filter( array_keys( $routes ), static fn( $r ) => 0 === strpos( $r, '/wp/v2/users' ) ), 'no /wp/v2/users* routes registered for a student' );
$hash_before = $wpdb->get_var( $wpdb->prepare( "SELECT user_pass FROM {$wpdb->users} WHERE ID = %d", $student->ID ) );
$req = new WP_REST_Request( 'POST', '/wp/v2/users/me' );
$req->set_body_params( array( 'password' => 'NewPassw0rd!!zz', 'email' => 'attacker@example.test' ) );
$res = rest_do_request( $req );
$after = get_userdata( $student->ID );
t_assert( $res->get_status() >= 400 && $after->user_email === $student->user_email && $hash_before === $wpdb->get_var( $wpdb->prepare( "SELECT user_pass FROM {$wpdb->users} WHERE ID = %d", $student->ID ) ), 'POST /wp/v2/users/me with password+email is refused and changes nothing' );
$guard = CC_Student_Lockdown::guard_users_dispatch( null, rest_get_server(), new WP_REST_Request( 'POST', '/wp/v2/users/me' ) );
t_assert( is_wp_error( $guard ) && 403 === ( $guard->get_error_data()['status'] ?? 0 ), 'rest_pre_dispatch guard returns 403 for users routes' );
t_assert( is_wp_error( CC_Student_Lockdown::guard_user_write( (object) array( 'user_pass' => 'x' ), new WP_REST_Request( 'POST', '/wp/v2/users/me' ) ) ), 'rest_pre_insert_user guard rejects student writes' );
t_assert( is_wp_error( apply_filters( 'rest_pre_insert_user', (object) array( 'user_email' => 'a@example.test' ), new WP_REST_Request( 'POST', '/wp/v2/users/me' ) ) ), 'rest_pre_insert_user guard is hooked' );

echo "H3: other core writes denied for students\n";
foreach ( array( '/wp/v2/posts', '/wp/v2/pages', '/wp/v2/media', '/wp/v2/comments' ) as $route ) {
	$req = new WP_REST_Request( 'POST', $route );
	$req->set_body_params( array( 'title' => 'x', 'content' => 'x', 'status' => 'publish' ) );
	$res = rest_do_request( $req );
	t_assert( in_array( $res->get_status(), array( 401, 403 ), true ), "student POST $route denied (" . $res->get_status() . ')' );
}
$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_title = 'x' AND post_author = {$student->ID}" );
t_assert( 0 === $count, 'no posts were created by the student' );
wp_set_current_user( 0 );

echo "L6: no DB error text in repository exceptions\n";
t_assert( ! str_contains( (string) file_get_contents( CC_PATH . 'includes/enrollment/class-enrollment-repository.php' ) . (string) file_get_contents( CC_PATH . 'includes/enrollment/class-provisioner.php' ), 'last_error' ), 'enrollment code does not reference $wpdb->last_error' );

echo "students are redirected from every wp-admin request at init\n";
t_assert( false !== has_action( 'init', array( 'CC_Roles', 'block_admin_early' ) ), 'block_admin_early is hooked on init' );
$redirects = array();
add_filter( 'wp_redirect', static function ( $location ) use ( &$redirects ) {
	$redirects[] = $location;
	throw new RuntimeException( 'redirect' );
} );
$run_early_block = static function ( bool $admin, bool $ajax, WP_User $user ) use ( &$redirects ): array {
	$redirects = array();
	wp_set_current_user( $user->ID );
	unset( $GLOBALS['current_screen'] );
	if ( $admin ) {
		$GLOBALS['current_screen'] = WP_Screen::get( 'admin_page_cc-students' );
	}
	add_filter( 'wp_doing_ajax', $ajax ? '__return_true' : '__return_false' );
	try {
		CC_Roles::block_admin_early();
	} catch ( RuntimeException $e ) {
		unset( $e );
	}
	remove_all_filters( 'wp_doing_ajax' );
	return $redirects;
};
$redirected = $run_early_block( true, false, $student );
t_assert( array( CC_Roles::portal_url() ) === $redirected, 'student in wp-admin is redirected to /student/' );
t_assert( array() === $run_early_block( true, true, $student ), 'student admin-ajax.php is not redirected' );
t_assert( array() === $run_early_block( false, false, $student ), 'student on the front end is not redirected' );
t_assert( array() === $run_early_block( true, false, $sub ), 'non-student in wp-admin is not redirected' );
unset( $GLOBALS['current_screen'] );
wp_set_current_user( 0 );

echo "core update / maintenance nags are hidden from users without update_core\n";
t_assert( function_exists( 'cc_hide_core_update_nags_for_non_updaters' ), 'cc-hardening defines the nag filter' );
t_assert( 10 === has_action( 'admin_init', 'cc_hide_core_update_nags_for_non_updaters' ), 'nag filter is hooked on admin_init' );
$nag_hooks = array(
	array( 'admin_notices', 'update_nag', 3 ),
	array( 'admin_notices', 'maintenance_nag', 10 ),
	array( 'network_admin_notices', 'update_nag', 3 ),
	array( 'network_admin_notices', 'maintenance_nag', 10 ),
);
// WP-CLI does not load wp-admin/includes/admin-filters.php, so register the nags exactly as core does.
$register_core_nags = static function () use ( $nag_hooks ): void {
	foreach ( $nag_hooks as $h ) {
		add_action( $h[0], $h[1], $h[2] );
	}
};
$nags_hooked = static function () use ( $nag_hooks ): array {
	return array_map( static fn( array $h ): bool => $h[2] === has_action( $h[0], $h[1] ), $nag_hooks );
};
$hide_nags = static function (): void {
	if ( function_exists( 'cc_hide_core_update_nags_for_non_updaters' ) ) {
		cc_hide_core_update_nags_for_non_updaters();
	}
};
$nag_staff = wp_insert_user( array( 'user_login' => 'cc_nagtest_' . wp_generate_password( 6, false ), 'user_pass' => wp_generate_password( 24 ), 'role' => 'cc_staff' ) );
t_assert( ! is_wp_error( $nag_staff ), 'throwaway cc_staff user created' );
if ( ! is_wp_error( $nag_staff ) ) {
	$register_core_nags();
	wp_set_current_user( $nag_staff );
	t_assert( ! current_user_can( 'update_core' ), 'cc_staff lacks update_core' );
	$hide_nags();
	t_assert( array( false, false, false, false ) === $nags_hooked(), 'cc_staff: update_nag and maintenance_nag unhooked from admin_notices and network_admin_notices' );
	wp_delete_user( $nag_staff );
}
$nag_admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0] ?? null;
t_assert( $nag_admin instanceof WP_User, 'an administrator exists' );
if ( $nag_admin instanceof WP_User ) {
	$register_core_nags();
	wp_set_current_user( $nag_admin->ID );
	$hide_nags();
	t_assert( array( true, true, true, true ) === $nags_hooked(), 'administrator keeps the core update and maintenance nags' );
}
foreach ( $nag_hooks as $h ) {
	remove_action( $h[0], $h[1], $h[2] );
}
wp_set_current_user( 0 );

echo "core version footer is hidden from users without update_core\n";
$footer_hooked = static fn(): bool => 10 === has_filter( 'update_footer', 'core_update_footer' );
$footer_staff = wp_insert_user( array( 'user_login' => 'cc_footertest_' . wp_generate_password( 6, false ), 'user_pass' => wp_generate_password( 24 ), 'role' => 'cc_staff' ) );
t_assert( ! is_wp_error( $footer_staff ), 'throwaway cc_staff user created for the footer check' );
if ( ! is_wp_error( $footer_staff ) ) {
	add_filter( 'update_footer', 'core_update_footer' ); // not loaded under WP-CLI
	wp_set_current_user( $footer_staff );
	$hide_nags();
	t_assert( ! $footer_hooked(), 'cc_staff: core_update_footer unhooked from update_footer' );
	t_assert( '' === apply_filters( 'update_footer', '' ), 'cc_staff: right footer renders no core version' );
	wp_delete_user( $footer_staff );
	remove_filter( 'update_footer', '__return_empty_string', 11 );
}
if ( $nag_admin instanceof WP_User ) {
	add_filter( 'update_footer', 'core_update_footer' );
	wp_set_current_user( $nag_admin->ID );
	$hide_nags();
	t_assert( $footer_hooked(), 'administrator keeps core_update_footer' );
}
remove_filter( 'update_footer', 'core_update_footer' );
wp_set_current_user( 0 );

// Cleanup.
require_once ABSPATH . 'wp-admin/includes/user.php';
$wpdb->delete( "{$p}cc_students", array( 'user_id' => $student->ID ) );
$wpdb->delete( "{$p}cc_enrollments", array( 'application_id' => $app ) );
foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$p}cc_sms_log WHERE related_type = 'application' AND related_id = %d", $app ) ) as $sms_id ) {
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( 'cc_send_sms', array( (int) $sms_id ), 'cc' );
	}
}
$wpdb->delete( "{$p}cc_sms_log", array( 'related_type' => 'application', 'related_id' => $app ) );
$wpdb->delete( "{$p}cc_applications", array( 'id' => $app ) );
$wpdb->delete( "{$p}cc_batches", array( 'id' => $batch ) );
wp_delete_user( $student->ID );
wp_delete_user( $sub->ID );
for ( $attempt = 1; $attempt <= CC_Provisioner::MAX_ATTEMPTS; $attempt++ ) {
	wp_clear_scheduled_hook( CC_Provisioner::HOOK, array( $app, $attempt ) );
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( CC_Provisioner::HOOK, array( $app, $attempt ), CC_Provisioner::GROUP );
	}
}

echo "\n$checks checks, $failures failures\n";
if ( $failures > 0 ) {
	WP_CLI::halt( 1 );
}
