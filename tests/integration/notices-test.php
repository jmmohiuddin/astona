<?php
/**
 * Integration tests for targeted notices (CC_Notice_Service, CC_Notice_Access, CC_Admin_Notices). Run inside the wpcli container:
 *   docker compose run --rm -T wpcli eval-file /tests/integration/notices-test.php
 * Requires CC_SMS_PRIMARY=fake. Creates fixture rows and removes them afterwards.
 */
global $wpdb, $failures, $checks, $cleanup;
$p        = $wpdb->prefix;
$failures = 0;
$checks   = 0;
$cleanup  = array( 'batches' => array(), 'users' => array(), 'posts' => array(), 'enrollments' => array() );

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

foreach ( array( 'CC_Notice_Service', 'CC_Notice_Access', 'CC_Admin_Notices', 'CC_Sms', 'CC_Enrollment_Repository', 'CC_Audit' ) as $required ) {
	if ( ! class_exists( $required ) ) {
		echo "FAIL missing class $required\n";
		exit( 1 );
	}
}

function t_batch( string $name ): int {
	global $wpdb, $cleanup;
	$now = gmdate( 'Y-m-d H:i:s' );
	$wpdb->insert( $wpdb->prefix . 'cc_batches', array(
		'course_id' => 0, 'name' => $name, 'capacity' => 10, 'seats_taken' => 0, 'price' => 1000,
		'start_date' => '2030-01-01', 'status' => 'open', 'application_open' => 1, 'created_at' => $now, 'updated_at' => $now,
	) );
	$cleanup['batches'][] = (int) $wpdb->insert_id;
	return (int) $wpdb->insert_id;
}

function t_student( string $phone_digits ): int {
	global $cleanup;
	$id = wp_insert_user( array( 'user_login' => $phone_digits, 'user_pass' => wp_generate_password(), 'user_email' => $phone_digits . '@students.invalid', 'role' => CC_Roles::ROLE ) );
	$cleanup['users'][] = (int) $id;
	return (int) $id;
}

function t_enroll( int $user, int $batch, string $status = 'active' ): void {
	global $wpdb, $cleanup;
	static $app = 0;
	$app = 910000000 + mt_rand( 1, 80000000 ) + ++$app;
	CC_Enrollment_Repository::create( $user, $batch, $app );
	if ( 'active' !== $status ) {
		$wpdb->update( $wpdb->prefix . 'cc_enrollments', array( 'status' => $status ), array( 'user_id' => $user, 'batch_id' => $batch ) );
	}
	$cleanup['enrollments'][] = array( $user, $batch );
}

function t_cap_user( string $login, bool $cap ): int {
	global $cleanup;
	$id = wp_insert_user( array( 'user_login' => $login, 'user_pass' => wp_generate_password(), 'user_email' => $login . '@example.test', 'role' => 'subscriber' ) );
	$cleanup['users'][] = (int) $id;
	if ( $cap ) {
		( new WP_User( $id ) )->add_cap( 'cc_manage_notices' );
	}
	return (int) $id;
}

/** @param int[] $batches */
function t_notice( string $title, string $status, array $batches = array(), bool $critical = false ): int {
	global $cleanup;
	$id = wp_insert_post( array( 'post_type' => 'cc_notice', 'post_title' => $title, 'post_content' => 'Body of ' . $title, 'post_status' => 'draft' ) );
	$cleanup['posts'][] = (int) $id;
	CC_Notice_Service::set_audience( $id, $batches );
	if ( $critical ) {
		update_post_meta( $id, CC_Notice_Service::META_CRITICAL, '1' );
	}
	if ( 'draft' !== $status ) {
		wp_update_post( array( 'ID' => $id, 'post_status' => $status ) );
	}
	return (int) $id;
}

function t_sms_count( int $notice, string $template = 'notice' ): int {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}cc_sms_log WHERE template = %s AND related_type = 'notice' AND related_id = %d", $template, $notice ) );
}

function t_dies( callable $fn ): bool {
	$filter = static fn() => static function () {
		throw new RuntimeException( 'wp_die' );
	};
	add_filter( 'wp_die_handler', $filter, 999 );
	try {
		$fn();
		return false;
	} catch ( RuntimeException $e ) {
		return 'wp_die' === $e->getMessage();
	} finally {
		remove_filter( 'wp_die_handler', $filter, 999 );
	}
}

/** Runs a handler; returns the redirect Location, or null when it did not redirect. */
function t_redirect( callable $fn ): ?string {
	$location = null;
	$filter   = static function ( $url ) use ( &$location ) {
		$location = $url;
		throw new RuntimeException( 'redirect' );
	};
	add_filter( 'wp_redirect', $filter, 0 );
	try {
		$fn();
	} catch ( RuntimeException $e ) {
		if ( 'redirect' !== $e->getMessage() ) {
			throw $e;
		}
	} finally {
		remove_filter( 'wp_redirect', $filter, 0 );
	}
	return $location;
}

/** Simulates a front-end single request: main WP_Query + the template_redirect decision. @return int HTTP status. */
function t_single( int $notice, int $user ): int {
	$saved_the = $GLOBALS['wp_the_query'];
	$saved_q   = $GLOBALS['wp_query'];
	wp_set_current_user( $user );
	$q                      = new WP_Query();
	$GLOBALS['wp_the_query'] = $q;
	$GLOBALS['wp_query']     = $q;
	$q->query( array( 'p' => $notice, 'post_type' => 'cc_notice' ) );
	$status = 200;
	if ( ! $q->have_posts() || CC_Notice_Access::maybe_404() ) {
		$status = 404;
	}
	$GLOBALS['wp_the_query'] = $saved_the;
	$GLOBALS['wp_query']     = $saved_q;
	wp_set_current_user( 0 );
	return $status;
}

function t_rest( string $path, int $user, array $params = array() ): WP_REST_Response {
	wp_set_current_user( $user );
	$request = new WP_REST_Request( 'GET', $path );
	foreach ( $params as $k => $v ) {
		$request->set_param( $k, $v );
	}
	$response = rest_do_request( $request );
	wp_set_current_user( 0 );
	return $response;
}

$tag = substr( bin2hex( random_bytes( 4 ) ), 0, 8 );
CC_Sms_Factory::override_name( 'primary', 'fake' );

$batch_a = t_batch( "ZZ-NOTICE A $tag" );
$batch_b = t_batch( "ZZ-NOTICE B $tag" );
$s1      = t_student( '88017' . mt_rand( 10000000, 99999999 ) ); // A active
$s2      = t_student( '88018' . mt_rand( 10000000, 99999999 ) ); // A + B active
$s3      = t_student( '88019' . mt_rand( 10000000, 99999999 ) ); // A deactivated
$s4      = t_student( '88016' . mt_rand( 10000000, 99999999 ) ); // B only
t_enroll( $s1, $batch_a );
t_enroll( $s2, $batch_a );
t_enroll( $s2, $batch_b );
t_enroll( $s3, $batch_a, 'deactivated' );
t_enroll( $s4, $batch_b );
$admin  = t_cap_user( "zznotice-admin-$tag", true );
$nocap  = t_cap_user( "zznotice-nocap-$tag", false );
$phone2 = '+' . get_userdata( $s2 )->user_login;

echo "audience / recipients\n";
$n_pub  = t_notice( "ZZ Public $tag", 'publish' );
$n_a    = t_notice( "ZZ Batch A $tag", 'publish', array( $batch_a ) );
$n_ab   = t_notice( "ZZ Batch A+B $tag", 'draft', array( $batch_a, $batch_b ) );
t_assert( array( 'public' => true, 'batch_ids' => array() ) === CC_Notice_Service::audience( $n_pub ), 'no targets means public' );
t_assert( array( 'public' => false, 'batch_ids' => array( $batch_a ) ) === CC_Notice_Service::audience( $n_a ), 'targets are returned' );
$users = array_column( CC_Notice_Service::recipients( $n_a ), 'user_id' );
sort( $users );
t_assert( array( $s1, $s2 ) === $users, 'recipients are active enrollments only (deactivated excluded)' );
$rec_ab = CC_Notice_Service::recipients( $n_ab );
t_assert( 3 === count( $rec_ab ) && 3 === count( array_unique( array_column( $rec_ab, 'user_id' ) ) ), 'student in two target batches is counted once' );
t_assert( in_array( $phone2, array_column( $rec_ab, 'phone' ), true ), 'recipient phone is E.164' );
t_assert( array() === CC_Notice_Service::recipients( $n_pub ), 'public notice has no recipients' );
CC_Notice_Service::set_audience( $n_ab, array( $batch_b, $batch_b, 0 ) );
t_assert( array( $batch_b ) === CC_Notice_Service::audience( $n_ab )['batch_ids'], 'set_audience replaces and de-duplicates' );
CC_Notice_Service::set_audience( $n_ab, array() );
t_assert( true === CC_Notice_Service::audience( $n_ab )['public'], 'set_audience with an empty list makes it public' );
CC_Notice_Service::set_audience( $n_ab, array( $batch_a, $batch_b ) );

echo "visible_to_user\n";
$n_b   = t_notice( "ZZ Batch B $tag", 'publish', array( $batch_b ) );
$n_arc = t_notice( "ZZ Archived $tag", 'publish' );
CC_Notice_Service::set_archived( $n_arc, true );
$mine = static fn( int $u ): array => array_values( array_intersect( array_column( CC_Notice_Service::visible_to_user( $u, 100 ), 'id' ), array( $n_pub, $n_a, $n_ab, $n_b, $n_arc ) ) );
$ids  = static function ( array $v ) { sort( $v ); return $v; };
t_assert( $ids( array( $n_pub, $n_a ) ) === $ids( $mine( $s1 ) ), 'student in batch A sees public + A, not B/draft/archived' );
t_assert( $ids( array( $n_pub, $n_a, $n_b ) ) === $ids( $mine( $s2 ) ), 'student in A and B sees both' );
t_assert( $ids( array( $n_pub ) ) === $ids( $mine( $s3 ) ), 'deactivated enrollment contributes nothing' );
t_assert( $ids( array( $n_pub, $n_b ) ) === $ids( $mine( $s4 ) ), 'student in B sees public + B only' );
$flags = array_column( CC_Notice_Service::visible_to_user( $s1, 100 ), 'public', 'id' );
t_assert( true === $flags[ $n_pub ] && false === $flags[ $n_a ], 'items carry the public flag' );
t_assert( 1 === count( CC_Notice_Service::visible_to_user( $s1, 1 ) ) && CC_Notice_Service::visible_to_user( $s1, 1, 0 ) !== CC_Notice_Service::visible_to_user( $s1, 1, 1 ), 'limit and offset page the feed' );

echo "public exclusion\n";
wp_set_current_user( 0 );
$archive = ( new WP_Query( array( 'post_type' => 'cc_notice', 'posts_per_page' => -1, 'fields' => 'ids' ) ) )->posts;
t_assert( in_array( $n_pub, $archive, true ) && ! array_intersect( array( $n_a, $n_b, $n_ab, $n_arc ), $archive ), 'archive query hides targeted, draft and archived notices' );
$posts = get_posts( array( 'post_type' => 'cc_notice', 'posts_per_page' => -1, 'fields' => 'ids' ) );
t_assert( in_array( $n_pub, $posts, true ) && ! array_intersect( array( $n_a, $n_b, $n_arc ), $posts ), 'get_posts (front-page teaser) hides targeted and archived notices' );
t_assert( 0 === ( new WP_Query( array( 'post_type' => 'cc_notice', 'p' => $n_a ) ) )->found_posts, 'secondary query by id cannot fetch a targeted notice' );
t_assert( 0 === ( new WP_Query( array( 'post_type' => 'cc_notice', 'post__in' => array( $n_a, $n_b ) ) ) )->found_posts, 'post__in cannot fetch targeted notices' );
$search = ( new WP_Query( array( 's' => "ZZ Batch A $tag", 'post_type' => 'any', 'fields' => 'ids' ) ) )->posts;
t_assert( ! in_array( $n_a, $search, true ), 'search hides targeted notices' );
if ( function_exists( 'wp_sitemaps_get_server' ) ) {
	$provider = wp_sitemaps_get_server()->registry->get_provider( 'posts' );
	$urls     = array_column( $provider->get_url_list( 1, 'cc_notice' ), 'loc' );
	t_assert( in_array( get_permalink( $n_pub ), $urls, true ) && ! in_array( get_permalink( $n_a ), $urls, true ), 'sitemap lists public notices only' );
}
wp_set_current_user( $admin );
$front = get_posts( array( 'post_type' => 'cc_notice', 'posts_per_page' => -1, 'fields' => 'ids' ) );
t_assert( in_array( $n_pub, $front, true ) && ! array_intersect( array( $n_a, $n_b, $n_arc ), $front ), 'front-end queries (teaser) are public-only even for a notices manager' );
set_current_screen( 'dashboard' );
$as_admin = get_posts( array( 'post_type' => 'cc_notice', 'posts_per_page' => -1, 'fields' => 'ids', 'post_status' => 'any' ) );
t_assert( in_array( $n_a, $as_admin, true ) && in_array( $n_arc, $as_admin, true ), 'in wp-admin a notices manager sees everything' );
set_current_screen( 'front' );
$teaser = get_posts( array( 'post_type' => 'cc_notice', 'posts_per_page' => 3, 'post__not_in' => CC_Notice_Access::hidden_ids() ) );
t_assert( ! array_intersect( array( $n_a, $n_b, $n_arc ), wp_list_pluck( $teaser, 'ID' ) ), 'the front-page teaser query never contains hidden notices' );
wp_set_current_user( 0 );

echo "REST\n";
$list = array_column( t_rest( '/wp/v2/cc_notice', 0, array( 'per_page' => 100 ) )->get_data(), 'id' );
t_assert( in_array( $n_pub, $list, true ) && ! array_intersect( array( $n_a, $n_b, $n_arc ), $list ), 'anonymous REST list hides targeted and archived' );
t_assert( 200 === t_rest( "/wp/v2/cc_notice/$n_pub", 0 )->get_status(), 'anonymous REST single of a public notice is 200' );
t_assert( 404 === t_rest( "/wp/v2/cc_notice/$n_a", 0 )->get_status(), 'anonymous REST single of a targeted notice is 404' );
t_assert( 404 === t_rest( "/wp/v2/cc_notice/$n_arc", 0 )->get_status(), 'anonymous REST single of an archived notice is 404' );
t_assert( 404 === t_rest( "/wp/v2/cc_notice/$n_a", $s4 )->get_status(), 'REST single is 404 for a student of another batch' );
t_assert( 200 === t_rest( "/wp/v2/cc_notice/$n_a", $s1 )->get_status(), 'REST single is 200 for an enrolled student' );
t_assert( 200 === t_rest( "/wp/v2/cc_notice/$n_a", $admin )->get_status(), 'REST single is 200 for a notices manager' );
$list = array_column( t_rest( '/wp/v2/cc_notice', $admin, array( 'per_page' => 100 ) )->get_data(), 'id' );
t_assert( ! in_array( $n_a, $list, true ), 'REST list outside a REST request context stays public-only for a manager' );
if ( ! defined( 'REST_REQUEST' ) ) {
	define( 'REST_REQUEST', true );
}
$list = array_column( t_rest( '/wp/v2/cc_notice', $admin, array( 'per_page' => 100 ) )->get_data(), 'id' );
t_assert( in_array( $n_a, $list, true ), 'REST list includes targeted notices for an authenticated manager' );
t_assert( ! in_array( $n_a, array_column( t_rest( '/wp/v2/cc_notice', $nocap, array( 'per_page' => 100 ) )->get_data(), 'id' ), true ), 'REST list hides targeted notices from a non-manager' );

echo "REST route spelling, HEAD, search\n";
foreach ( array( 'CC_NOTICE', 'Cc_notice', 'cc_NOTICE' ) as $spelling ) {
	t_assert( 404 === t_rest( "/wp/v2/$spelling/$n_a", 0 )->get_status(), "anonymous /wp/v2/$spelling/ID of a targeted notice is 404" );
	t_assert( 404 === t_rest( "/wp/v2/$spelling/$n_arc", 0 )->get_status(), "anonymous /wp/v2/$spelling/ID of an archived notice is 404" );
	t_assert( 404 === t_rest( "/wp/v2/$spelling/$n_a", $s4 )->get_status(), "/wp/v2/$spelling/ID is 404 for a student of another batch" );
	t_assert( 200 === t_rest( "/wp/v2/$spelling/$n_a", $s1 )->get_status(), "/wp/v2/$spelling/ID is 200 for an enrolled student" );
	t_assert( 200 === t_rest( "/wp/v2/$spelling/$n_pub", 0 )->get_status(), "/wp/v2/$spelling/ID of a public notice is 200" );
}
t_assert( 404 === t_rest( "/wp/v2/cc_notice/$n_a/", 0 )->get_status(), 'trailing slash on the single route is 404' );
$head_status = static function ( string $route, int $user ): int {
	wp_set_current_user( $user );
	$response = rest_do_request( new WP_REST_Request( 'HEAD', $route ) );
	wp_set_current_user( 0 );
	return $response->get_status();
};
t_assert( 404 === $head_status( "/wp/v2/cc_notice/$n_a", 0 ) && 404 === $head_status( "/wp/v2/CC_NOTICE/$n_a", 0 ), 'HEAD on a targeted notice is 404 for anonymous' );
t_assert( 200 === $head_status( "/wp/v2/cc_notice/$n_pub", 0 ), 'HEAD on a public notice is 200' );
// Backstop: the handler check holds even when the route string does not look like the notice route at all.
$handler = array( 'callback' => array( new WP_REST_Posts_Controller( 'cc_notice' ), 'get_item' ) );
$request = new WP_REST_Request( 'GET', '/some/other/spelling' );
$request->set_param( 'id', $n_a );
wp_set_current_user( 0 );
$guarded = CC_Notice_Access::guard_rest_single( null, $handler, $request );
t_assert( is_wp_error( $guarded ) && 'rest_post_invalid_id' === $guarded->get_error_code(), 'controller-level backstop returns rest_post_invalid_id regardless of route spelling' );
$search = array_column( t_rest( '/wp/v2/search', 0, array( 'search' => "ZZ Batch A $tag", 'per_page' => 100 ) )->get_data(), 'id' );
t_assert( ! in_array( $n_a, $search, true ), '/wp/v2/search hides targeted notices' );
$incl = t_rest( '/wp/v2/cc_notice', 0, array( 'include' => array( $n_a ) ) )->get_data();
$slug = t_rest( '/wp/v2/cc_notice', 0, array( 'slug' => get_post_field( 'post_name', $n_a ) ) )->get_data();
t_assert( array() === $incl && array() === $slug, 'collection ?include= and ?slug= cannot fetch a targeted notice' );

echo "oEmbed and canonical redirect\n";
wp_set_current_user( 0 );
t_assert( 0 === CC_Notice_Access::guard_oembed_post_id( $n_a ) && 0 === CC_Notice_Access::guard_oembed_post_id( $n_arc ), 'oembed_request_post_id drops a hidden notice' );
t_assert( $n_pub === CC_Notice_Access::guard_oembed_post_id( $n_pub ) && (int) get_option( 'page_on_front' ) === CC_Notice_Access::guard_oembed_post_id( (int) get_option( 'page_on_front' ) ), 'oembed_request_post_id keeps public notices and other posts' );
t_assert( false === CC_Notice_Access::guard_oembed_data( array( 'title' => 'x' ), get_post( $n_a ) ) && array( 'title' => 'x' ) === CC_Notice_Access::guard_oembed_data( array( 'title' => 'x' ), get_post( $n_pub ) ), 'oembed_response_data backstop blocks only hidden notices' );
$req   = new WP_REST_Request( 'GET', '/oembed/1.0/embed' );
$req->set_param( 'url', home_url( '/?p=' . $n_a ) );
$GLOBALS['post'] = null; // get_oembed_response_data( 0 ) would otherwise fall back to the global post.
$resp = rest_do_request( $req );
t_assert( 404 === $resp->get_status() && false === strpos( (string) wp_json_encode( $resp->get_data() ), "ZZ Batch A $tag" ), 'oEmbed of a hidden notice via ?p=ID is a 404 without the title' );
$req = new WP_REST_Request( 'GET', '/oembed/1.0/embed' );
$req->set_param( 'url', home_url( '/?p=' . $n_pub ) );
t_assert( 200 === rest_do_request( $req )->get_status(), 'oEmbed of a public notice still works' );

$with_query = static function ( array $vars, callable $fn ) {
	$saved_the = $GLOBALS['wp_the_query'];
	$saved_q   = $GLOBALS['wp_query'];
	$q         = new WP_Query();
	$q->query( $vars );
	$GLOBALS['wp_the_query'] = $q;
	$GLOBALS['wp_query']     = $q;
	$q->set( 'p', (int) ( $vars['p'] ?? 0 ) );
	$out = $fn();
	$GLOBALS['wp_the_query'] = $saved_the;
	$GLOBALS['wp_query']     = $saved_q;
	return $out;
};
$permalink = get_permalink( $n_a );
wp_set_current_user( 0 );
t_assert( false === $with_query( array( 'p' => $n_a, 'post_type' => 'cc_notice' ), static fn() => CC_Notice_Access::guard_canonical_redirect( $permalink ) ), '/?p=ID of a hidden notice gets no canonical redirect (anonymous)' );
t_assert( false === $with_query( array( 'p' => $n_arc, 'post_type' => 'cc_notice' ), static fn() => CC_Notice_Access::guard_canonical_redirect( get_permalink( $n_arc ) ) ), '/?p=ID of an archived notice gets no canonical redirect' );
t_assert( false === $with_query( array(), static fn() => CC_Notice_Access::guard_canonical_redirect( $permalink ) ), 'a guessed redirect whose target is a hidden notice is dropped' );
t_assert( get_permalink( $n_pub ) === $with_query( array( 'p' => $n_pub, 'post_type' => 'cc_notice' ), static fn() => CC_Notice_Access::guard_canonical_redirect( get_permalink( $n_pub ) ) ), 'a public notice still redirects to its permalink' );
wp_set_current_user( $s1 );
t_assert( $permalink === $with_query( array( 'p' => $n_a, 'post_type' => 'cc_notice' ), static fn() => CC_Notice_Access::guard_canonical_redirect( $permalink ) ), 'an enrolled student keeps the canonical redirect' );
wp_set_current_user( 0 );

echo "single permalink\n";
t_assert( 200 === t_single( $n_pub, 0 ), 'public notice is 200 for anonymous' );
t_assert( 404 === t_single( $n_a, 0 ), 'targeted notice is 404 for anonymous' );
t_assert( 404 === t_single( $n_a, $s4 ), 'targeted notice is 404 for a student of another batch' );
t_assert( 404 === t_single( $n_a, $s3 ), 'targeted notice is 404 for a deactivated enrollment' );
t_assert( 404 === t_single( $n_a, $nocap ), 'targeted notice is 404 for a logged-in non-student' );
t_assert( 200 === t_single( $n_a, $s1 ), 'targeted notice is 200 for an enrolled student' );
t_assert( 200 === t_single( $n_a, $s2 ), 'targeted notice is 200 for a student enrolled in A and B' );
t_assert( 200 === t_single( $n_a, $admin ), 'targeted notice is 200 for a notices manager' );
t_assert( 404 === t_single( $n_arc, 0 ) && 404 === t_single( $n_arc, $s1 ), 'archived notice is 404 publicly' );
t_assert( 200 === t_single( $n_arc, $admin ), 'archived notice is 200 for a manager' );
t_assert( 404 === t_single( $n_ab, 0 ) && CC_Notice_Access::can_view( $n_ab, $admin ) && ! CC_Notice_Access::can_view( $n_ab, $s1 ), 'draft notice is not public but a manager may view it' );

echo "SMS on publish\n";
$sms_text = CC_Sms::render( 'notice', array( 'title' => str_repeat( 'T', CC_Notice_Service::SMS_TITLE_LENGTH ), 'url' => home_url( '/student/notices/' ) ) );
t_assert( 1 === CC_Sms::segments( $sms_text ), 'notice SMS stays a single segment' );
t_assert( 0 === t_sms_count( $n_a ), 'non-critical targeted notice sends no SMS' );
$n_c = t_notice( "ZZ Critical $tag", 'draft', array( $batch_a ), true );
t_assert( 0 === t_sms_count( $n_c ), 'nothing is sent while the notice is a draft' );
wp_update_post( array( 'ID' => $n_c, 'post_status' => 'publish' ) );
t_assert( 2 === t_sms_count( $n_c ), 'publish queues one SMS per active recipient' );
wp_update_post( array( 'ID' => $n_c, 'post_title' => "ZZ Critical edited $tag" ) );
wp_update_post( array( 'ID' => $n_c, 'post_status' => 'draft' ) );
wp_update_post( array( 'ID' => $n_c, 'post_status' => 'publish' ) );
t_assert( 2 === t_sms_count( $n_c ), 're-publish never resends' );
$n_f = t_notice( "ZZ Future $tag", 'draft', array( $batch_a, $batch_b ), true );
wp_update_post( array( 'ID' => $n_f, 'post_status' => 'future', 'edit_date' => true, 'post_date' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ), 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) ) );
t_assert( 'future' === get_post_status( $n_f ) && 0 === t_sms_count( $n_f ), 'scheduled notice sends nothing yet' );
wp_publish_post( $n_f );
t_assert( 3 === t_sms_count( $n_f ), 'scheduled to publish transition sends once to each recipient' );
wp_publish_post( $n_f );
t_assert( 3 === t_sms_count( $n_f ), 'second publish call does not resend' );
$n_race = t_notice( "ZZ Race $tag", 'draft', array( $batch_a ), true );
$race_post = get_post( $n_race );
$wins      = array( CC_Notice_Service::claim_sms_send( $n_race ), CC_Notice_Service::claim_sms_send( $n_race ) );
t_assert( array( true, false ) === $wins, 'the SMS claim is won by exactly one of two concurrent attempts' );
CC_Notice_Service::release_sms_claim( $n_race );
$race_post->post_status = 'publish';
CC_Notice_Service::on_transition( 'publish', 'draft', $race_post );
CC_Notice_Service::on_transition( 'publish', 'future', $race_post );
t_assert( 2 === t_sms_count( $n_race ), 'two simultaneous publish transitions queue the SMS once' );
$n_ent = t_notice( "ZZ Ent $tag", 'draft', array( $batch_a ), true );
wp_update_post( array( 'ID' => $n_ent, 'post_title' => "Tom's & Jerry's \"quiz\" $tag", 'post_content' => "<p>Tom&#8217;s &amp; Jerry&#039;s class</p>" ) );
wp_update_post( array( 'ID' => $n_ent, 'post_status' => 'publish' ) );
global $wpdb;
$sms_enc = $wpdb->get_var( $wpdb->prepare( "SELECT body_enc FROM {$wpdb->prefix}cc_sms_log WHERE template = 'notice' AND related_type = 'notice' AND related_id = %d LIMIT 1", $n_ent ) );
$sms_row = null === $sms_enc ? '' : (string) CC_Crypto::decrypt( $sms_enc );
t_assert( false !== strpos( $sms_row, "Tom's & Jerry's" ) && false === strpos( $sms_row, '&amp;' ) && false === strpos( $sms_row, '&#' ), 'SMS title uses the raw title (apostrophe and ampersand, no entities)' );
$ex = array_column( CC_Notice_Service::visible_to_user( $s1, 100 ), 'excerpt', 'id' )[ $n_ent ] ?? '';
t_assert( "Tom\u{2019}s & Jerry's class" === $ex, 'excerpt decodes entities for the template to escape once' );
$n_p = t_notice( "ZZ Public critical $tag", 'publish', array(), true );
t_assert( 0 === t_sms_count( $n_p ), 'critical public notice sends no SMS' );

echo "admin operations\n";
$d = CC_Admin_Notices::save( array( 'title' => "ZZ Admin $tag", 'content' => '<p>Hi</p><script>alert(1)</script>', 'audience' => 'batches', 'batch_ids' => array( $batch_a, 99999999 ), 'critical' => true ), $admin );
$cleanup['posts'][] = (int) $d;
t_assert( is_int( $d ) && 'draft' === get_post_status( $d ), 'save creates a draft' );
t_assert( false === strpos( get_post_field( 'post_content', $d ), '<script' ) && false !== strpos( get_post_field( 'post_content', $d ), '<p>Hi</p>' ), 'content is passed through wp_kses_post' );
t_assert( array( $batch_a ) === CC_Notice_Service::audience( $d )['batch_ids'] && CC_Notice_Service::is_critical( $d ), 'audience (unknown batch dropped) and critical flag stored' );
$e = CC_Admin_Notices::save( array( 'title' => '  ', 'audience' => 'public' ), $admin );
t_assert( is_wp_error( $e ) && 'title_needed' === $e->get_error_code(), 'blank title refused' );
$e = CC_Admin_Notices::save( array( 'title' => 'x', 'audience' => 'batches', 'batch_ids' => array( 99999999 ) ), $admin );
t_assert( is_wp_error( $e ) && 'audience_needed' === $e->get_error_code(), 'batch audience with no valid batch refused' );
$e = CC_Admin_Notices::save( array( 'id' => 99999999, 'title' => 'x' ), $admin );
t_assert( is_wp_error( $e ) && 'not_found' === $e->get_error_code(), 'unknown id refused' );
$e = CC_Admin_Notices::save( array( 'id' => (int) get_option( 'page_on_front' ) ?: 1, 'title' => 'x' ), $admin );
t_assert( is_wp_error( $e ), 'a post that is not a notice cannot be edited' );

echo "preview + publish\n";
$pv = CC_Admin_Notices::preview( $d, null, $admin );
t_assert( 2 === $pv['students'] && 2 === $pv['sms'] && false === $pv['public'], 'preview counts students and SMS phones' );
t_assert( "Will be visible to 2 students in ZZ-NOTICE A $tag; SMS to 2 phones." === $pv['message'], 'preview message names the batches and counts' );
$r = CC_Admin_Notices::publish( $d, '', null, $admin );
t_assert( is_wp_error( $r ) && 'preview_required' === $r->get_error_code() && 'draft' === get_post_status( $d ), 'publish without a token is refused' );
$r = CC_Admin_Notices::publish( $d, 'deadbeef', null, $admin );
t_assert( is_wp_error( $r ) && 'draft' === get_post_status( $d ), 'publish with a wrong token is refused' );
$other_admin = t_cap_user( "zznotice-admin2-$tag", true );
$r           = CC_Admin_Notices::publish( $d, $pv['token'], null, $other_admin );
t_assert( is_wp_error( $r ) && 'draft' === get_post_status( $d ), 'a token is bound to the previewing user' );
CC_Admin_Notices::save( array( 'id' => $d, 'title' => "ZZ Admin $tag", 'content' => 'changed', 'audience' => 'batches', 'batch_ids' => array( $batch_a ), 'critical' => true ), $admin );
$r = CC_Admin_Notices::publish( $d, $pv['token'], null, $admin );
t_assert( is_wp_error( $r ) && 'draft' === get_post_status( $d ), 'editing after the preview invalidates the token' );
$pv = CC_Admin_Notices::preview( $d, null, $admin );
$r  = CC_Admin_Notices::publish( $d, $pv['token'], null, $admin );
t_assert( is_array( $r ) && 'publish' === $r['status'] && 2 === $r['students'] && 2 === $r['sms'] && 'publish' === get_post_status( $d ), 'publish with a fresh token succeeds and reports Sent to N' );
t_assert( 2 === t_sms_count( $d ), 'admin publish queued exactly one SMS per recipient' );
$r = CC_Admin_Notices::publish( $d, $pv['token'], null, $admin );
t_assert( is_wp_error( $r ), 'a token is single use / published notice cannot be published again' );

$s_d  = CC_Admin_Notices::save( array( 'title' => "ZZ Admin sched $tag", 'audience' => 'batches', 'batch_ids' => array( $batch_b ), 'critical' => true ), $admin );
$cleanup['posts'][] = (int) $s_d;
$when = gmdate( 'Y-m-d H:i:s', time() + 2 * DAY_IN_SECONDS );
$pv   = CC_Admin_Notices::preview( $s_d, $when, $admin );
$r    = CC_Admin_Notices::publish( $s_d, $pv['token'], gmdate( 'Y-m-d H:i:s', time() + 3 * DAY_IN_SECONDS ), $admin );
t_assert( is_wp_error( $r ), 'changing the schedule after the preview invalidates the token' );
$pv = CC_Admin_Notices::preview( $s_d, $when, $admin );
$r  = CC_Admin_Notices::publish( $s_d, $pv['token'], $when, $admin );
t_assert( is_array( $r ) && 'future' === $r['status'] && 'future' === get_post_status( $s_d ) && 0 === t_sms_count( $s_d ), 'future schedule leaves the notice scheduled with no SMS yet' );
wp_publish_post( $s_d );
t_assert( 2 === t_sms_count( $s_d ), 'cron-style publish sends the scheduled notice SMS once' );
t_assert( get_gmt_from_date( '2030-01-01 10:30:00' ) === CC_Admin_Notices::schedule_to_gmt( '2030-01-01T10:30' ), 'schedule is converted to UTC' );
t_assert( null === CC_Admin_Notices::schedule_to_gmt( '' ) && null === CC_Admin_Notices::schedule_to_gmt( 'garbage' ), 'empty or invalid schedule means publish now' );

wp_set_current_user( $admin );
set_current_screen( 'dashboard' );
$tabs = array_column( CC_Admin_Notices::list_notices( 'published' )['items'], 'id' );
t_assert( in_array( $d, $tabs, true ) && ! in_array( $n_arc, $tabs, true ), 'published tab lists published, non-archived notices' );
CC_Admin_Notices::archive( $d, true, $admin );
t_assert( in_array( $d, array_column( CC_Admin_Notices::list_notices( 'archived' )['items'], 'id' ), true ) && ! in_array( $d, array_column( CC_Admin_Notices::list_notices( 'published' )['items'], 'id' ), true ), 'archive moves a notice to the archived tab' );
t_assert( ! in_array( $d, array_column( CC_Notice_Service::visible_to_user( $s1, 100 ), 'id' ), true ), 'archived notice leaves the student feed' );
CC_Admin_Notices::archive( $d, false, $admin );
t_assert( in_array( $d, array_column( CC_Notice_Service::visible_to_user( $s1, 100 ), 'id' ), true ), 'unarchive restores it' );
t_assert( is_wp_error( CC_Admin_Notices::archive( 99999999, true, $admin ) ), 'archiving an unknown notice refuses' );
$item = array_values( array_filter( CC_Admin_Notices::list_notices( 'published' )['items'], static fn( $i ) => $i['id'] === $d ) )[0];
t_assert( "ZZ-NOTICE A $tag" === $item['audience'] && true === $item['critical'], 'list row shows audience and critical flag' );
$audit = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}cc_audit_log WHERE entity_type = 'notice' AND entity_id = %d", $d ) );
t_assert( $audit >= 4, 'create, publish, archive and unarchive are audited' );
set_current_screen( 'front' );

echo "editing a live notice\n";
$live_in = array( 'id' => $d, 'title' => "ZZ Admin $tag", 'content' => 'live body', 'audience' => 'batches', 'batch_ids' => array( $batch_a ), 'critical' => true );
$sms_before = t_sms_count( $d );
$r = CC_Admin_Notices::save( array_merge( $live_in, array( 'title' => "ZZ Admin retitled $tag", 'content' => 'edited body' ) ), $admin );
t_assert( $d === $r && "ZZ Admin retitled $tag" === get_the_title( $d ) && 'edited body' === get_post_field( 'post_content', $d ), 'a pure title/content edit of a published notice saves without a preview' );
t_assert( $sms_before === t_sms_count( $d ) && 'publish' === get_post_status( $d ), 'a content edit never resends SMS or changes the status' );
$r = CC_Admin_Notices::save( array_merge( $live_in, array( 'batch_ids' => array( $batch_a, $batch_b ) ) ), $admin );
t_assert( is_wp_error( $r ) && 'review_needed' === $r->get_error_code() && array( $batch_a ) === CC_Notice_Service::audience( $d )['batch_ids'], 'adding a batch to a published notice is refused by save()' );
$r = CC_Admin_Notices::save( array_merge( $live_in, array( 'audience' => 'public' ) ), $admin );
t_assert( is_wp_error( $r ) && ! CC_Notice_Service::audience( $d )['public'], 'making a published notice public is refused by save()' );
$r = CC_Admin_Notices::save( array_merge( $live_in, array( 'critical' => false ) ), $admin );
t_assert( is_wp_error( $r ) && CC_Notice_Service::is_critical( $d ), 'clearing the critical flag of a published notice is refused by save()' );
$upd = CC_Admin_Notices::preview_update( array_merge( $live_in, array( 'batch_ids' => array( $batch_a, $batch_b ), 'title' => "ZZ Admin retitled $tag" ) ), $admin );
t_assert( is_array( $upd ) && 3 === $upd['students'] && 1 === $upd['new_students'] && 0 === $upd['sms'], 'update preview counts students including the newly added batch' );
t_assert( false !== strpos( $upd['message'], 'SMS is not resent' ) && false !== strpos( $upd['message'], '1 newly added' ), 'update preview says SMS is not resent' );
t_assert( array( $batch_a ) === CC_Notice_Service::audience( $d )['batch_ids'], 'previewing an update saves nothing' );
$r = CC_Admin_Notices::apply_update( $d, '', $admin );
t_assert( is_wp_error( $r ) && 'preview_required' === $r->get_error_code(), 'apply_update without a token is refused' );
$r = CC_Admin_Notices::apply_update( $d, 'deadbeef', $admin );
t_assert( is_wp_error( $r ) && array( $batch_a ) === CC_Notice_Service::audience( $d )['batch_ids'], 'apply_update with a wrong token is refused' );
$r = CC_Admin_Notices::apply_update( $d, $upd['token'], $other_admin );
t_assert( is_wp_error( $r ), 'an update token is bound to the previewing user' );
wp_update_post( array( 'ID' => $d, 'post_content' => 'changed meanwhile' ) );
$r = CC_Admin_Notices::apply_update( $d, $upd['token'], $admin );
t_assert( is_wp_error( $r ) && array( $batch_a ) === CC_Notice_Service::audience( $d )['batch_ids'], 'the stored notice changing after the preview invalidates the token' );
$upd = CC_Admin_Notices::preview_update( array_merge( $live_in, array( 'batch_ids' => array( $batch_a, $batch_b ) ) ), $admin );
$r   = CC_Admin_Notices::apply_update( $d, $upd['token'], $admin );
t_assert( $d === $r && array( $batch_a, $batch_b ) === CC_Notice_Service::audience( $d )['batch_ids'] && 'publish' === get_post_status( $d ), 'a reviewed audience change is applied' );
t_assert( $sms_before === t_sms_count( $d ), 'newly added batches on an already-sent critical notice trigger no SMS' );
t_assert( is_wp_error( CC_Admin_Notices::apply_update( $d, $upd['token'], $admin ) ), 'an update token is single use' );
CC_Admin_Notices::save( array_merge( $live_in, array( 'batch_ids' => array( $batch_a, $batch_b ), 'content' => 'x' ) ), $admin );
$sched = t_notice( "ZZ Live future $tag", 'draft', array( $batch_a ), true );
wp_update_post( array( 'ID' => $sched, 'post_status' => 'future', 'edit_date' => true, 'post_date' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ), 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) ) );
$r = CC_Admin_Notices::save( array( 'id' => $sched, 'title' => "ZZ Live future $tag", 'audience' => 'batches', 'batch_ids' => array( $batch_a, $batch_b ), 'critical' => true ), $admin );
t_assert( is_wp_error( $r ) && 'review_needed' === $r->get_error_code(), 'a scheduled notice also refuses an unreviewed audience change' );
$upd = CC_Admin_Notices::preview_update( array( 'id' => $sched, 'title' => "ZZ Live future $tag", 'audience' => 'batches', 'batch_ids' => array( $batch_a, $batch_b ), 'critical' => true ), $admin );
t_assert( is_array( $upd ) && 3 === $upd['sms'] && 'future' === get_post_status( $sched ), 'scheduled update preview reports the SMS that will go out at publish' );
$_POST    = array( 'id' => (string) $d, 'title' => "ZZ Admin retitled $tag", 'content' => 'z', 'audience' => 'public' );
$_REQUEST = array( '_wpnonce' => wp_create_nonce( 'cc_notice_save_' . $d ) );
wp_set_current_user( $admin );
$location = t_redirect( array( 'CC_Admin_Notices', 'handle_save' ) );
t_assert( false !== strpos( (string) $location, 'view=update_preview' ) && ! CC_Notice_Service::audience( $d )['public'], 'handle_save routes a risky live edit to the update preview without saving' );
$_POST    = array( 'id' => (string) $d, 'token' => 'nope' );
$_REQUEST = array( '_wpnonce' => wp_create_nonce( 'cc_notice_update_' . $d ) );
t_assert( false !== strpos( (string) t_redirect( array( 'CC_Admin_Notices', 'handle_update' ) ), 'cc_notice=preview_required' ), 'handle_update refuses a wrong token' );
$_REQUEST = array();
wp_set_current_user( $nocap );
t_assert( t_dies( array( 'CC_Admin_Notices', 'handle_update' ) ), 'handle_update refuses a user without the capability' );
wp_set_current_user( 0 );
$_POST = array();

echo "handlers: capability and nonce\n";
$handlers = array( 'handle_save' => 'cc_notice_save_', 'handle_publish' => 'cc_notice_publish_', 'handle_archive' => 'cc_notice_archive_' );
$draft    = t_notice( "ZZ Handler $tag", 'draft' );
$_POST    = array( 'id' => (string) $draft, 'title' => 'hacked', 'archived' => '1' );
foreach ( $handlers as $fn => $action ) {
	wp_set_current_user( 0 );
	t_assert( t_dies( array( 'CC_Admin_Notices', $fn ) ), "$fn refuses an anonymous user" );
	wp_set_current_user( $nocap );
	$_REQUEST['_wpnonce'] = wp_create_nonce( $action . $draft );
	t_assert( t_dies( array( 'CC_Admin_Notices', $fn ) ), "$fn refuses a user without the capability even with a valid nonce" );
	wp_set_current_user( $admin );
	$_REQUEST = array();
	t_assert( t_dies( array( 'CC_Admin_Notices', $fn ) ), "$fn refuses a missing nonce" );
	$_REQUEST['_wpnonce'] = wp_create_nonce( $action . 'x' );
	t_assert( t_dies( array( 'CC_Admin_Notices', $fn ) ), "$fn refuses a nonce for another notice" );
}
t_assert( 'hacked' !== get_the_title( $draft ) && ! CC_Notice_Service::is_archived( $draft ), 'refused handlers changed nothing' );
wp_set_current_user( $nocap );
t_assert( t_dies( array( 'CC_Admin_Notices', 'render' ) ), 'render refuses a user without the capability' );
wp_set_current_user( $admin );
$_REQUEST['_wpnonce'] = wp_create_nonce( 'cc_notice_publish_' . $draft );
$_POST                = array( 'id' => (string) $draft );
$location             = t_redirect( array( 'CC_Admin_Notices', 'handle_publish' ) );
t_assert( null !== $location && false !== strpos( $location, 'cc_notice=preview_required' ) && 'draft' === get_post_status( $draft ), 'handle_publish with a valid nonce but no preview token is refused' );
$_REQUEST['_wpnonce'] = wp_create_nonce( 'cc_notice_archive_' . $draft );
$_POST                = array( 'id' => (string) $draft, 'archived' => '1' );
$location             = t_redirect( array( 'CC_Admin_Notices', 'handle_archive' ) );
t_assert( CC_Notice_Service::is_archived( $draft ) && false !== strpos( (string) $location, 'cc_notice=archived' ), 'handle_archive with cap and nonce archives' );
$_REQUEST['_wpnonce'] = wp_create_nonce( 'cc_notice_save_0' );
$_POST                = array( 'id' => '0', 'title' => "ZZ Posted $tag", 'content' => '<b>x</b>', 'audience' => 'public' );
$location             = t_redirect( array( 'CC_Admin_Notices', 'handle_save' ) );
$posted               = get_posts( array( 'post_type' => 'cc_notice', 'post_status' => 'draft', 'title' => "ZZ Posted $tag", 'fields' => 'ids' ) );
$cleanup['posts']     = array_merge( $cleanup['posts'], $posted );
t_assert( 1 === count( $posted ) && false !== strpos( (string) $location, 'cc_notice=saved' ), 'handle_save with cap and nonce creates the draft' );

wp_set_current_user( 0 );
$_POST = $_GET = $_REQUEST = array();

// Cleanup.
foreach ( array_unique( $cleanup['posts'] ) as $id ) {
	$wpdb->delete( "{$p}cc_notice_targets", array( 'notice_id' => $id ) );
	$wpdb->delete( "{$p}cc_sms_log", array( 'related_type' => 'notice', 'related_id' => $id ) );
	$wpdb->delete( "{$p}cc_audit_log", array( 'entity_type' => 'notice', 'entity_id' => $id ) );
	wp_delete_post( $id, true );
}
foreach ( $cleanup['enrollments'] as $pair ) {
	$wpdb->delete( "{$p}cc_enrollments", array( 'user_id' => $pair[0], 'batch_id' => $pair[1] ) );
}
foreach ( $cleanup['batches'] as $id ) {
	$wpdb->delete( "{$p}cc_batches", array( 'id' => $id ) );
}
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ( $cleanup['users'] as $id ) {
	wp_delete_user( $id );
}

echo "\n$checks checks, $failures failures\n";
exit( $failures ? 1 : 0 );
