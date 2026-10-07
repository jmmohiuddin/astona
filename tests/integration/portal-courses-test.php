<?php
/**
 * Integration tests for CC_Portal_Courses (dashboard schedule/notices, course page, gated PDF authorisation).
 *   docker compose run --rm -T wpcli eval-file /tests/integration/portal-courses-test.php
 * Inserts fixtures directly and removes them afterwards. The real HTTP stream is covered by tests/e2e/portal-courses.sh.
 */
global $wpdb, $failures, $checks, $cleanup, $secret;
$p        = $wpdb->prefix;
$failures = 0;
$checks   = 0;
$cleanup  = array( 'batches' => array(), 'users' => array(), 'posts' => array(), 'files' => array() );
$secret   = 'https://meet.google.com/zzq-secret-marker';

foreach ( array( 'CC_Content_Repository', 'CC_Resource_Store', 'CC_Live_Repository', 'CC_Notice_Service', 'CC_Portal_Courses' ) as $dependency ) {
	if ( ! class_exists( $dependency ) ) {
		echo "SKIP: $dependency is not loaded yet\n";
		return;
	}
}

function t_assert( bool $cond, string $label ): void {
	global $failures, $checks;
	++$checks;
	echo ( $cond ? '  ok   ' : '  FAIL ' ) . $label . "\n";
	if ( ! $cond ) {
		++$failures;
	}
}

function t_student(): int {
	global $cleanup;
	if ( ! get_role( 'cc_student' ) ) {
		add_role( 'cc_student', 'Student', array( 'read' => true ) );
	}
	$tag = substr( bin2hex( random_bytes( 5 ) ), 0, 10 );
	$id  = wp_insert_user( array( 'user_login' => "zzcourse$tag", 'user_pass' => wp_generate_password(), 'user_email' => "zzcourse$tag@students.invalid", 'role' => 'cc_student' ) );
	if ( is_wp_error( $id ) ) {
		throw new RuntimeException( $id->get_error_message() );
	}
	$cleanup['users'][] = (int) $id;
	return (int) $id;
}

function t_batch( string $name ): int {
	global $wpdb, $cleanup;
	$now = gmdate( 'Y-m-d H:i:s' );
	$wpdb->insert( $wpdb->prefix . 'cc_batches', array(
		'course_id' => 0, 'name' => $name, 'capacity' => 10, 'price' => 5000, 'start_date' => '2030-01-01',
		'schedule_text' => 'Sat 5pm', 'status' => 'open', 'application_open' => 1, 'created_at' => $now, 'updated_at' => $now,
	) );
	$cleanup['batches'][] = (int) $wpdb->insert_id;
	return (int) $wpdb->insert_id;
}

function t_enroll( int $user, int $batch, string $status = 'active' ): int {
	global $wpdb;
	static $fake_app = 900000000;
	$wpdb->insert( $wpdb->prefix . 'cc_enrollments', array(
		'user_id' => $user, 'batch_id' => $batch, 'application_id' => ++$fake_app + random_int( 1, 99999 ), 'status' => $status, 'enrolled_at' => gmdate( 'Y-m-d H:i:s' ),
	) );
	return (int) $wpdb->insert_id;
}

function t_live( int $batch, string $title, int $start_offset, int $duration = 3600 ): int {
	global $wpdb, $secret;
	$now = time();
	$wpdb->insert( $wpdb->prefix . 'cc_live_classes', array(
		'batch_id' => $batch, 'title' => $title, 'starts_at' => gmdate( 'Y-m-d H:i:s', $now + $start_offset ), 'ends_at' => gmdate( 'Y-m-d H:i:s', $now + $start_offset + $duration ),
		'provider' => 'meet', 'meeting_url_enc' => CC_Crypto::encrypt( $secret ), 'created_by' => 1, 'updated_at' => gmdate( 'Y-m-d H:i:s' ),
	) );
	return (int) $wpdb->insert_id;
}

function t_has_secret( $data, string $needle ): bool {
	return false !== strpos( wp_json_encode( $data ), 'zzq-secret-marker' ) || false !== strpos( wp_json_encode( $data ), $needle );
}

try {
	$alice = t_student();
	$bob   = t_student();
	$carol = t_student();
	$b1    = t_batch( 'ZZ-COURSE A' );
	$b2    = t_batch( 'ZZ-COURSE B' );
	$b3    = t_batch( 'ZZ-COURSE C' );
	t_enroll( $alice, $b1 );
	t_enroll( $alice, $b2 );
	t_enroll( $bob, $b3 );
	$dead = t_enroll( $carol, $b1, 'deactivated' );

	$m1  = CC_Content_Repository::create_module( $b1, 'Mechanics' );
	$m3  = CC_Content_Repository::create_module( $b3, 'Other batch module' );
	$pdf = CC_Resource_Store::store_bytes( "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n", 'Notes: "week 1"/../x.pdf' );
	if ( is_wp_error( $pdf ) ) {
		throw new RuntimeException( 'PDF fixture: ' . $pdf->get_error_message() );
	}
	$cleanup['files'][] = $pdf['path'];
	$soon   = gmdate( 'Y-m-d H:i:s', time() + 2 * HOUR_IN_SECONDS );
	$l_pdf  = CC_Content_Repository::create_lesson( $m1, array( 'title' => 'Lesson with PDF', 'scheduled_at' => $soon, 'attachment_path' => $pdf['path'], 'attachment_name' => $pdf['name'] ) );
	$l_none = CC_Content_Repository::create_lesson( $m1, array( 'title' => 'Lesson without PDF' ) );
	$l_far  = CC_Content_Repository::create_lesson( $m1, array( 'title' => 'Far lesson', 'scheduled_at' => gmdate( 'Y-m-d H:i:s', time() + 20 * DAY_IN_SECONDS ) ) );
	$l_b3   = CC_Content_Repository::create_lesson( $m3, array( 'title' => 'Bob only', 'scheduled_at' => $soon, 'attachment_path' => $pdf['path'], 'attachment_name' => $pdf['name'] ) );
	$live_a = t_live( $b1, 'Live A', 600 );
	$live_b = t_live( $b2, 'Live B', 3 * HOUR_IN_SECONDS );
	$live_3 = t_live( $b3, 'Live C (bob)', 600 );
	t_live( $b1, 'Live A far', 30 * DAY_IN_SECONDS );

	echo "course_view(): own active batch only\n";
	$view = CC_Portal_Courses::course_view( $alice, $b1 );
	t_assert( null !== $view && 1 === count( $view['modules'] ) && 3 === count( $view['modules'][0]['lessons'] ), 'alice sees her batch modules and lessons' );
	t_assert( null === CC_Portal_Courses::course_view( $alice, $b3 ), "alice cannot open bob's batch" );
	t_assert( null === CC_Portal_Courses::course_view( $carol, $b1 ), 'deactivated enrolment gets no course view' );
	t_assert( null === CC_Portal_Courses::course_view( $alice, 999999999 ), 'unknown batch gets no course view' );
	$lessons = array_column( $view['modules'][0]['lessons'], null, 'id' );
	t_assert( '' !== $lessons[ $l_pdf ]['download_url'] && '' === $lessons[ $l_none ]['download_url'], 'download link only for lessons with a PDF' );
	t_assert( '' !== $lessons[ $l_pdf ]['when_text'] && '' === $lessons[ $l_none ]['when_text'], 'scheduled time shown only when set' );
	t_assert( 2 === count( array_filter( $view['live'], static fn( $l ) => in_array( $l['title'], array( 'Live A', 'Live A far' ), true ) ) ) && ! in_array( 'Live B', array_column( $view['live'], 'title' ), true ), 'live rows are this batch only' );
	t_assert( 'active' === array_column( $view['live'], 'state', 'id' )[ $live_a ], 'class starting in 10 minutes is active' );
	t_assert( ! t_has_secret( $view, $secret ) && false === strpos( wp_json_encode( $view ), 'resources/' ), 'course view carries no meeting URL or private path' );
	CC_Enrollment_Repository::user_has_active( $alice, $b1 );
	$wpdb->update( "{$p}cc_enrollments", array( 'expires_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ), array( 'user_id' => $bob, 'batch_id' => $b3 ) );
	t_assert( null === CC_Portal_Courses::course_view( $bob, $b3 ), 'expired enrolment gets no course view' );
	$wpdb->update( "{$p}cc_enrollments", array( 'expires_at' => null ), array( 'user_id' => $bob, 'batch_id' => $b3 ) );

	echo "schedule(): next 7 days, aggregated, per-batch labels\n";
	$sched = CC_Portal_Courses::schedule( $alice );
	$all   = array_merge( $sched['today'], $sched['later'] );
	$names = array_column( $all, 'title' );
	t_assert( in_array( 'Live A', $names, true ) && in_array( 'Live B', $names, true ) && in_array( 'Lesson with PDF', $names, true ), 'live classes and scheduled lessons from both batches' );
	t_assert( ! in_array( 'Live A far', $names, true ) && ! in_array( 'Far lesson', $names, true ) && ! in_array( 'Bob only', $names, true ) && ! in_array( 'Live C (bob)', $names, true ), 'beyond 7 days and other batches excluded' );
	t_assert( count( array_filter( $all, static fn( $i ) => '' !== $i['batch_label'] ) ) === count( $all ), 'every item labelled with its batch' );
	$starts = array_column( $all, 'start_ts' );
	$sorted = $starts;
	sort( $sorted );
	t_assert( $starts === $sorted, 'items are in time order' );
	t_assert( ! t_has_secret( $sched, $secret ), 'schedule carries no meeting URL' );
	$wpdb->update( "{$p}cc_enrollments", array( 'status' => 'deactivated' ), array( 'user_id' => $alice, 'batch_id' => $b2 ) );
	$after = array_column( array_merge( CC_Portal_Courses::schedule( $alice )['today'], CC_Portal_Courses::schedule( $alice )['later'] ), 'title' );
	t_assert( ! in_array( 'Live B', $after, true ) && in_array( 'Live A', $after, true ), 'deactivated batch contributes nothing, other batch remains' );
	$wpdb->update( "{$p}cc_enrollments", array( 'status' => 'active' ), array( 'user_id' => $alice, 'batch_id' => $b2 ) );

	echo "schedule(): next class fallback and empty states\n";
	$wpdb->delete( "{$p}cc_live_classes", array( 'batch_id' => $b1 ) );
	$wpdb->delete( "{$p}cc_live_classes", array( 'batch_id' => $b2 ) );
	$wpdb->update( "{$p}cc_lessons", array( 'scheduled_at' => null ), array( 'id' => $l_pdf ) );
	$wpdb->update( "{$p}cc_lessons", array( 'scheduled_at' => null ), array( 'id' => $l_far ) );
	$far_live = t_live( $b1, 'Distant live', 30 * DAY_IN_SECONDS );
	$fb       = CC_Portal_Courses::schedule( $alice );
	t_assert( array() === $fb['today'] && array() === $fb['later'] && null !== $fb['next'] && 'Distant live' === $fb['next']['title'] && '' !== $fb['message'], 'nothing this week falls back to the next class with a message' );
	$wpdb->delete( "{$p}cc_live_classes", array( 'id' => $far_live ) );
	$none = CC_Portal_Courses::schedule( $alice );
	t_assert( null === $none['next'] && '' !== $none['message'], 'no classes at all gives a clear empty message' );
	$nobody = CC_Portal_Courses::schedule( t_student() );
	t_assert( '' !== $nobody['message'] && array() === $nobody['today'], 'student with no enrolment gets an empty message' );
	t_assert( array_key_exists( 'schedule', CC_Portal_Data::dashboard( $alice ) ) && ! t_has_secret( CC_Portal_Data::dashboard( $alice ), $secret ), 'dashboard() exposes schedule without meeting URLs' );

	echo "notices: dashboard top 3, feed paging, batch filter\n";
	$mk = static function ( string $title, array $batches ) use ( &$cleanup ): int {
		$id = wp_insert_post( array( 'post_type' => 'cc_notice', 'post_status' => 'publish', 'post_title' => $title, 'post_content' => 'Body of ' . $title, 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - count( $cleanup['posts'] ) ), 'post_date' => gmdate( 'Y-m-d H:i:s', time() - count( $cleanup['posts'] ) ) ) );
		$cleanup['posts'][] = $id;
		CC_Notice_Service::set_audience( $id, $batches );
		return $id;
	};
	$n_a  = $mk( 'ZZ notice A', array( $b1 ) );
	$n_b  = $mk( 'ZZ notice B', array( $b2 ) );
	$n_3  = $mk( 'ZZ notice for C only', array( $b3 ) );
	$n_p  = $mk( 'ZZ public notice', array() );
	$feed = CC_Portal_Courses::notices_page( $alice, 1 );
	$ids  = array_column( $feed['items'], 'id' );
	t_assert( in_array( $n_a, $ids, true ) && in_array( $n_b, $ids, true ) && in_array( $n_p, $ids, true ) && ! in_array( $n_3, $ids, true ), 'feed has own-batch and public notices, not other batches' );
	t_assert( 3 === count( CC_Portal_Courses::dashboard_notices( $alice ) ), 'dashboard shows the top three notices' );
	$only_a = array_column( CC_Portal_Courses::notices_page( $alice, 1, $b1 )['items'], 'id' );
	t_assert( in_array( $n_a, $only_a, true ) && in_array( $n_p, $only_a, true ) && ! in_array( $n_b, $only_a, true ), 'batch filter keeps that batch plus public notices' );
	t_assert( CC_Portal_Courses::notices_page( $alice, 1, $b3 )['batch_id'] === 0, "filtering by a batch the student is not in is ignored" );
	t_assert( false === $feed['has_more'] || count( $feed['items'] ) === CC_Portal_Courses::NOTICE_PAGE_SIZE, 'has_more only when a full page is shown' );
	t_assert( ! t_has_secret( $feed, $secret ), 'notice feed carries no meeting URL' );

	echo "authorize_resource(): download gate matrix\n";
	$ok = CC_Portal_Courses::authorize_resource( $alice, $l_pdf );
	t_assert( null !== $ok && is_file( $ok['path'] ) && str_ends_with( $ok['name'], '.pdf' ) && false === strpbrk( $ok['name'], "\"/\\;\r\n" ), 'enrolled student authorised with a sanitised .pdf name' );
	t_assert( null === CC_Portal_Courses::authorize_resource( $alice, $l_b3 ), "other batch's lesson: null" );
	t_assert( null === CC_Portal_Courses::authorize_resource( $alice, 999999999 ), 'unknown lesson: null' );
	t_assert( null === CC_Portal_Courses::authorize_resource( $alice, $l_none ), 'lesson without file: null' );
	t_assert( null === CC_Portal_Courses::authorize_resource( $carol, $l_pdf ), 'deactivated student: null' );
	t_assert( null === CC_Portal_Courses::authorize_resource( 0, $l_pdf ) && null === CC_Portal_Courses::authorize_resource( $alice, 0 ) && null === CC_Portal_Courses::authorize_resource( $alice, -5 ), 'zero/negative ids: null' );
	$wpdb->update( "{$p}cc_lessons", array( 'attachment_path' => 'resources/../../wp-config.php' ), array( 'id' => $l_pdf ) );
	t_assert( null === CC_Portal_Courses::authorize_resource( $alice, $l_pdf ), 'traversal in stored path: null' );
	$wpdb->update( "{$p}cc_lessons", array( 'attachment_path' => $pdf['path'] ), array( 'id' => $l_pdf ) );
	t_assert( 'lesson-7.pdf' === CC_Portal_Courses::download_name( '', 7 ) && 'a-b.pdf' === CC_Portal_Courses::download_name( "../a\" b\r\n.pdf", 1 ), 'download_name sanitises header-breaking characters' );
} finally {
	foreach ( $cleanup['posts'] as $id ) {
		wp_delete_post( $id, true );
	}
	foreach ( $cleanup['batches'] as $id ) {
		foreach ( CC_Content_Repository::modules_for_batch( $id ) as $module ) {
			CC_Content_Repository::delete_module( $module['id'] );
		}
		$wpdb->delete( "{$p}cc_live_classes", array( 'batch_id' => $id ) );
		$wpdb->delete( "{$p}cc_enrollments", array( 'batch_id' => $id ) );
		$wpdb->delete( "{$p}cc_batches", array( 'id' => $id ) );
	}
	foreach ( $cleanup['files'] as $rel ) {
		CC_Resource_Store::delete( $rel );
	}
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( $cleanup['users'] as $id ) {
		wp_delete_user( $id );
	}
}

echo "\n$checks checks, $failures failures\n";
exit( $failures ? 1 : 0 );
