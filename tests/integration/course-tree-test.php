<?php
/**
 * Integration tests for CC_Course_Tree (the nested Batch > Module > Lesson editor backend).
 *   docker compose run --rm -T wpcli eval-file /tests/integration/course-tree-test.php
 */
global $wpdb, $failures, $checks;
$p        = $wpdb->prefix;
$failures = 0;
$checks   = 0;
function t_assert( bool $cond, string $label ): void {
	global $failures, $checks;
	++$checks;
	echo ( $cond ? '  ok   ' : '  FAIL ' ) . "$label\n";
	if ( ! $cond ) {
		++$failures;
	}
}
$old_tz = get_option( 'timezone_string' );
update_option( 'timezone_string', 'Asia/Dhaka' );
$course = wp_insert_post( array( 'post_type' => 'cc_course', 'post_title' => 'ZZ Tree Course', 'post_status' => 'publish' ) );
$other  = wp_insert_post( array( 'post_type' => 'cc_course', 'post_title' => 'ZZ Other Course', 'post_status' => 'publish' ) );
$staff  = wp_insert_user( array( 'user_login' => 'zztree' . bin2hex( random_bytes( 3 ) ), 'user_pass' => wp_generate_password(), 'user_email' => bin2hex( random_bytes( 3 ) ) . '@example.test', 'role' => 'cc_staff' ) );
$instr  = wp_insert_user( array( 'user_login' => 'zzins' . bin2hex( random_bytes( 3 ) ), 'user_pass' => wp_generate_password(), 'user_email' => bin2hex( random_bytes( 3 ) ) . '@example.test', 'role' => 'cc_instructor' ) );
$sub    = wp_insert_user( array( 'user_login' => 'zzsub' . bin2hex( random_bytes( 3 ) ), 'user_pass' => wp_generate_password(), 'user_email' => bin2hex( random_bytes( 3 ) ) . '@example.test', 'role' => 'subscriber' ) );
$batch  = static fn( string $name = 'B' ): array => array( 'name' => $name, 'delivery_mode' => 'hybrid', 'capacity' => 20, 'price' => 12000, 'start_date' => '2030-02-01', 'end_date' => '2030-08-01', 'schedule_text' => 'Sat 6pm', 'status' => 'open', 'application_open' => true, 'waitlist_enabled' => false, 'installments_enabled' => true, 'first_payment_percent' => 40, 'installment_days' => 21, 'modules' => array() );
$lesson = static fn( string $t, string $at = '' ): array => array( 'title' => $t, 'scheduled_local' => $at );
$count  = static fn( string $t, string $w = '1=1' ): int => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}{$t} WHERE $w" );

echo "load and permissions\n";
$tree = CC_Course_Tree::load( $course );
t_assert( array() === $tree['batches'] && 32 === strlen( $tree['rev'] ) && 'Asia/Dhaka' === $tree['timezone'], 'an empty course loads with a revision and the site timezone' );
$r = CC_Course_Tree::save( $course, array( 'batches' => array() ), $tree['rev'], $sub );
t_assert( is_wp_error( $r ) && 'forbidden' === $r->get_error_code(), 'a subscriber cannot save' );
$r = CC_Course_Tree::save( $course, array( 'batches' => array() ), $tree['rev'], $instr );
t_assert( is_wp_error( $r ) && 'forbidden' === $r->get_error_code(), 'an instructor cannot save (no content capability)' );
$r = CC_Course_Tree::save( 999999, array( 'batches' => array() ), $tree['rev'], $staff );
t_assert( is_wp_error( $r ) && 'not_found' === $r->get_error_code(), 'an unknown course is a 404' );

echo "build a tree\n";
$b1 = $batch( 'Evening' );
$b1['modules'] = array(
	array( 'title' => 'Physics', 'lessons' => array( $lesson( 'Motion', '2030-02-02T18:00' ), $lesson( 'Force' ) ) ),
	array( 'title' => 'Chemistry', 'lessons' => array( $lesson( 'Atoms' ) ) ),
);
$saved = CC_Course_Tree::save( $course, array( 'batches' => array( $b1 ) ), $tree['rev'], $staff );
t_assert( ! is_wp_error( $saved ) && 1 === count( $saved['batches'] ) && 2 === count( $saved['batches'][0]['modules'] ), 'a batch with two modules saves in one call' );
$bid = $saved['batches'][0]['id'];
$m1  = $saved['batches'][0]['modules'][0];
t_assert( 'Motion' === $m1['lessons'][0]['title'] && 'Force' === $m1['lessons'][1]['title'], 'lessons keep their order' );
t_assert( '2030-02-02T18:00' === $m1['lessons'][0]['scheduled_local'] && '2030-02-02 12:00:00' === $wpdb->get_var( "SELECT scheduled_at FROM {$p}cc_lessons WHERE id = {$m1['lessons'][0]['id']}" ), 'a lesson time is entered in Dhaka time and stored as UTC' );
$row = $wpdb->get_row( "SELECT * FROM {$p}cc_batches WHERE id = $bid", ARRAY_A );
t_assert( 1 === (int) $row['installments_enabled'] && 40 === (int) $row['first_payment_percent'] && 21 === (int) $row['installment_days'] && 'hybrid' === $row['delivery_mode'] && 0 === (int) $row['seats_taken'], 'all batch fields stored; seats start at 0' );
t_assert( array( 1, 2 ) === array_map( 'intval', $wpdb->get_col( "SELECT sort_order FROM {$p}cc_modules WHERE batch_id = $bid ORDER BY sort_order" ) ), 'module order stored as 1, 2' );

echo "reorder, rename, move\n";
$t = $saved;
$mods = $t['batches'][0]['modules'];
$phys = $mods[0];
$chem = $mods[1];
$wpdb->update( "{$p}cc_lessons", array( 'attachment_path' => 'ZZ/notes.pdf', 'attachment_name' => 'notes.pdf' ), array( 'id' => $phys['lessons'][0]['id'] ) );
$t = CC_Course_Tree::load( $course );
$moved = $phys['lessons'][0];
$t['batches'][0]['modules'] = array(
	array( 'id' => $chem['id'], 'title' => 'Chemistry 101', 'lessons' => array_merge( $chem['lessons'], array( $moved ) ) ),
	array( 'id' => $phys['id'], 'title' => 'Physics', 'lessons' => array( $phys['lessons'][1] ) ),
);
$t['batches'][0]['name'] = 'Evening A';
$t2 = CC_Course_Tree::save( $course, $t, $t['rev'], $staff );
$mods2 = $t2['batches'][0]['modules'];
t_assert( ! is_wp_error( $t2 ) && 'Chemistry 101' === $mods2[0]['title'] && 'Physics' === $mods2[1]['title'] && 'Evening A' === $t2['batches'][0]['name'], 'modules reorder and rename' );
t_assert( 2 === count( $mods2[0]['lessons'] ) && 'Motion' === $mods2[0]['lessons'][1]['title'] && $moved['id'] === $mods2[0]['lessons'][1]['id'], 'a lesson moves to another module and keeps its id' );
t_assert( 'ZZ/notes.pdf' === $wpdb->get_var( "SELECT attachment_path FROM {$p}cc_lessons WHERE id = {$moved['id']}" ), 'and keeps its PDF' );
t_assert( $count( 'cc_lessons', "module_id IN (SELECT id FROM {$p}cc_modules WHERE batch_id = $bid)" ) === 3, 'no lesson was duplicated or lost' );

echo "conflicts and atomicity\n";
$stale = CC_Course_Tree::save( $course, $t, $t['rev'], $staff );
t_assert( is_wp_error( $stale ) && 'conflict' === $stale->get_error_code() && 409 === $stale->get_error_data()['status'], 'saving from an old revision is refused with 409' );
t_assert( 'Evening A' === $wpdb->get_var( "SELECT name FROM {$p}cc_batches WHERE id = $bid" ), 'and changes nothing' );
$cur = CC_Course_Tree::load( $course );
$bad = $cur;
$bad['batches'][0]['name'] = 'Should not stick';
$bad['batches'][0]['modules'][0]['lessons'][0]['title'] = '';
$r = CC_Course_Tree::save( $course, $bad, $cur['rev'], $staff );
t_assert( is_wp_error( $r ) && 422 === $r->get_error_data()['status'] && isset( $r->get_error_data()['errors']['batches.0.modules.0.lessons.0.title'] ), 'an empty lesson title is a 422 with the path of the field' );
t_assert( 'Evening A' === $wpdb->get_var( "SELECT name FROM {$p}cc_batches WHERE id = $bid" ), 'nothing saved: the valid batch rename did not stick' );
$bad = $cur;
$bad['batches'][0]['modules'][0]['lessons'][0]['scheduled_local'] = '2030-13-45T99:99';
$r = CC_Course_Tree::save( $course, $bad, $cur['rev'], $staff );
t_assert( is_wp_error( $r ) && isset( $r->get_error_data()['errors']['batches.0.modules.0.lessons.0.scheduled_local'] ), 'an impossible lesson time is refused' );
$bad = $cur;
$bad['batches'][0]['price'] = -5;
$bad['batches'][0]['start_date'] = '2030-02-30';
$bad['batches'][0]['end_date'] = '2029-01-01';
$bad['batches'][0]['delivery_mode'] = 'telepathy';
$r = CC_Course_Tree::save( $course, $bad, $cur['rev'], $staff );
$e = is_wp_error( $r ) ? $r->get_error_data()['errors'] : array();
t_assert( isset( $e['batches.0.price'], $e['batches.0.start_date'], $e['batches.0.delivery_mode'] ), 'price, date and mode errors are all reported at once' );
$foreign = $cur;
$other_batch = t_other_batch( $other );
$foreign['batches'][0]['modules'][0]['id'] = $other_batch['module'];
$r = CC_Course_Tree::save( $course, $foreign, $cur['rev'], $staff );
t_assert( is_wp_error( $r ) && 422 === $r->get_error_data()['status'], "a module id from another course's batch is refused" );
t_assert( $count( 'cc_modules', "id = {$other_batch['module']} AND title = 'Other module'" ) === 1, "and the other course is untouched" );
$foreign = $cur;
$foreign['batches'][0]['id'] = $other_batch['batch'];
$r = CC_Course_Tree::save( $course, $foreign, $cur['rev'], $staff );
t_assert( is_wp_error( $r ), "a batch id from another course is refused" );

echo "seats\n";
$wpdb->update( "{$p}cc_batches", array( 'seats_taken' => 5 ), array( 'id' => $bid ) );
$cur2 = CC_Course_Tree::load( $course );
t_assert( $cur['rev'] === $cur2['rev'], 'a new admission (seats change) does not change the revision' );
$low = $cur2;
$low['batches'][0]['capacity'] = 3;
$r = CC_Course_Tree::save( $course, $low, $cur2['rev'], $staff );
t_assert( is_wp_error( $r ) && isset( $r->get_error_data()['errors']['batches.0.capacity'] ), 'capacity below the seats taken is refused' );
$hack = $cur2;
$hack['batches'][0]['seats_taken'] = 0;
$r = CC_Course_Tree::save( $course, $hack, $cur2['rev'], $staff );
t_assert( ! is_wp_error( $r ) && 5 === (int) $wpdb->get_var( "SELECT seats_taken FROM {$p}cc_batches WHERE id = $bid" ), 'seats_taken in the input is ignored' );
$gone = CC_Course_Tree::load( $course );
$gone['batches'] = array();
$r = CC_Course_Tree::save( $course, $gone, $gone['rev'], $staff );
t_assert( is_wp_error( $r ) && isset( $r->get_error_data()['errors']['batches'] ) && 1 === $count( 'cc_batches', "id = $bid" ), 'a batch with seats cannot be deleted' );
$wpdb->update( "{$p}cc_batches", array( 'seats_taken' => 0 ), array( 'id' => $bid ) );

echo "add and delete\n";
$cur = CC_Course_Tree::load( $course );
$cur['batches'][] = $batch( 'Morning' );
$r = CC_Course_Tree::save( $course, $cur, $cur['rev'], $staff );
t_assert( ! is_wp_error( $r ) && 2 === count( $r['batches'] ), 'a second batch is added' );
$cur = $r;
$cur['batches'] = array( $cur['batches'][0] );
$r = CC_Course_Tree::save( $course, $cur, $cur['rev'], $staff );
t_assert( ! is_wp_error( $r ) && 1 === count( $r['batches'] ), 'an empty batch is deleted' );
$cur = $r;
$cur['batches'][0]['modules'] = array( $cur['batches'][0]['modules'][0] );
$cur['batches'][0]['modules'][0]['lessons'] = array();
$r = CC_Course_Tree::save( $course, $cur, $cur['rev'], $staff );
t_assert( ! is_wp_error( $r ) && 1 === count( $r['batches'][0]['modules'] ) && 0 === $count( 'cc_lessons', "module_id IN (SELECT id FROM {$p}cc_modules WHERE batch_id = $bid)" ), 'removing a module and its lessons works' );
t_assert( 0 === $count( 'cc_lessons', "id = {$moved['id']}" ), 'the deleted lesson row is gone (its PDF is removed after commit)' );

echo "REST\n";
wp_set_current_user( $staff );
$req = new WP_REST_Request( 'GET', "/cc/v1/admin/courses/$course/tree" );
$res = rest_do_request( $req );
t_assert( 200 === $res->get_status() && isset( $res->get_data()['rev'] ) && str_contains( (string) ( $res->get_headers()['Cache-Control'] ?? '' ), 'no-store' ), 'GET returns the tree, uncached' );
$tree = $res->get_data();
$put  = new WP_REST_Request( 'PUT', "/cc/v1/admin/courses/$course/tree" );
$put->set_header( 'content-type', 'application/json' );
$tree['batches'][0]['name'] = 'Via REST';
$put->set_body( wp_json_encode( $tree ) );
$res = rest_do_request( $put );
t_assert( 200 === $res->get_status() && 'Via REST' === $res->get_data()['batches'][0]['name'], 'PUT saves and returns the fresh tree' );
$res = rest_do_request( $put );
t_assert( 409 === $res->get_status() && 'conflict' === $res->get_data()['code'], 'repeating the same PUT is a 409 (the revision moved on)' );
$tree = CC_Course_Tree::load( $course );
$tree['batches'][0]['name'] = '';
$put2 = new WP_REST_Request( 'PUT', "/cc/v1/admin/courses/$course/tree" );
$put2->set_header( 'content-type', 'application/json' );
$put2->set_body( wp_json_encode( $tree ) );
$res = rest_do_request( $put2 );
t_assert( 422 === $res->get_status() && isset( $res->get_data()['errors']['batches.0.name'] ), 'a validation error is a 422 with field paths' );
wp_set_current_user( $sub );
t_assert( in_array( rest_do_request( new WP_REST_Request( 'GET', "/cc/v1/admin/courses/$course/tree" ) )->get_status(), array( 401, 403 ), true ), 'a subscriber is refused on GET' );
wp_set_current_user( 0 );
t_assert( in_array( rest_do_request( new WP_REST_Request( 'GET', "/cc/v1/admin/courses/$course/tree" ) )->get_status(), array( 401, 403 ), true ), 'anonymous is refused' );
wp_set_current_user( $staff );
t_assert( in_array( rest_do_request( new WP_REST_Request( 'GET', '/cc/v1/admin/courses/' . get_option( 'page_on_front' ) . '/tree' ) )->get_status(), array( 401, 403, 404 ), true ), 'a non-course post id is refused' );
wp_set_current_user( 0 );

echo "waitlist is filled when capacity is raised\n";
wp_set_current_user( $staff );
$cur = CC_Course_Tree::load( $course );
$cur['batches'][0]['capacity'] = 1;
$cur['batches'][0]['waitlist_enabled'] = true;
$cur['batches'][0]['status'] = 'open';
$cur['batches'][0]['application_open'] = true;
$r = CC_Course_Tree::save( $course, $cur, $cur['rev'], $staff );
$bid = $r['batches'][0]['id'];
$wpdb->update( "{$p}cc_batches", array( 'seats_taken' => 1 ), array( 'id' => $bid ) );
$now = gmdate( 'Y-m-d H:i:s' );
$wpdb->insert( "{$p}cc_applications", array( 'public_ref' => substr( strtoupper( 'ZZTREE' . bin2hex( random_bytes( 10 ) ) ), 0, 26 ), 'idempotency_key' => wp_generate_uuid4(), 'batch_id' => $bid, 'student_phone' => '+8801711111111', 'full_name' => 'ZZ W', 'gender' => 'o', 'dob' => '2008-01-01', 'id_doc_type' => 'nid', 'id_doc_enc' => 'x', 'guardian_name' => 'G', 'guardian_phone' => '+8801700000001', 'institution' => 'I', 'class_level' => '10', 'consent_at' => $now, 'status' => 'waitlisted', 'waitlisted_at' => $now, 'created_at' => $now, 'updated_at' => $now ) );
$wl = (int) $wpdb->insert_id;
$cur = CC_Course_Tree::load( $course );
$cur['batches'][0]['capacity'] = 2;
CC_Course_Tree::save( $course, $cur, $cur['rev'], $staff );
t_assert( 'pending' === $wpdb->get_var( "SELECT status FROM {$p}cc_applications WHERE id = $wl" ), 'raising capacity from the editor offers the freed seat to the waitlist' );

// Cleanup.
wp_set_current_user( 0 );
require_once ABSPATH . 'wp-admin/includes/user.php';
$wpdb->query( "DELETE FROM {$p}cc_sms_log WHERE related_id = $wl AND related_type = 'application'" );
$wpdb->delete( "{$p}cc_applications", array( 'id' => $wl ) );
foreach ( array( $course, $other ) as $cid ) {
	foreach ( $wpdb->get_col( "SELECT id FROM {$p}cc_batches WHERE course_id = $cid" ) as $b ) {
		$wpdb->query( "DELETE FROM {$p}cc_lessons WHERE module_id IN (SELECT id FROM {$p}cc_modules WHERE batch_id = $b)" );
		$wpdb->delete( "{$p}cc_modules", array( 'batch_id' => $b ) );
		$wpdb->delete( "{$p}cc_batches", array( 'id' => $b ) );
	}
	wp_delete_post( $cid, true );
}
foreach ( array( $staff, $instr, $sub ) as $u ) {
	wp_delete_user( $u );
}
$wpdb->query( "DELETE FROM {$p}cc_audit_log WHERE action = 'course.tree_save'" );
update_option( 'timezone_string', $old_tz );
echo "\n$checks checks, $failures failures\n";
exit( $failures ? 1 : 0 );

/** A batch with one module in another course, to prove ids cannot cross courses. */
function t_other_batch( int $course ): array {
	global $wpdb;
	$p   = $wpdb->prefix;
	$now = gmdate( 'Y-m-d H:i:s' );
	$wpdb->insert( "{$p}cc_batches", array( 'course_id' => $course, 'name' => 'Other', 'capacity' => 5, 'price' => 1, 'start_date' => '2030-01-01', 'status' => 'draft', 'created_at' => $now, 'updated_at' => $now ) );
	$b = (int) $wpdb->insert_id;
	$wpdb->insert( "{$p}cc_modules", array( 'batch_id' => $b, 'title' => 'Other module', 'sort_order' => 1, 'created_at' => $now ) );
	return array( 'batch' => $b, 'module' => (int) $wpdb->insert_id );
}
