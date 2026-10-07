<?php
/**
 * Integration tests for course content (repository, resource store, admin operations and handlers). Run inside the wpcli container:
 *   docker compose run --rm -T wpcli eval-file /tests/integration/content-test.php
 * Inserts fixture rows and files directly and removes them afterwards.
 */
global $wpdb, $failures, $checks, $cleanup;
$p        = $wpdb->prefix;
$failures = 0;
$checks   = 0;
$cleanup  = array( 'batches' => array(), 'users' => array(), 'files' => array(), 'links' => array() );

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

foreach ( array( 'CC_Content_Repository', 'CC_Resource_Store', 'CC_Admin_Content', 'CC_Audit', 'CC_Photo_Store' ) as $required ) {
	if ( ! class_exists( $required ) ) {
		echo "FAIL missing class $required\n";
		exit( 1 );
	}
}

function t_pdf( string $extra = '' ): string {
	return "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Kids[]/Count 0>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n" . $extra;
}

function t_batch( string $tag ): int {
	global $wpdb, $cleanup;
	$now = gmdate( 'Y-m-d H:i:s' );
	$wpdb->insert( $wpdb->prefix . 'cc_batches', array(
		'course_id' => 0, 'name' => "ZZ-CONTENT $tag", 'capacity' => 10, 'seats_taken' => 0, 'price' => 1000,
		'start_date' => '2030-01-01', 'status' => 'open', 'application_open' => 1, 'created_at' => $now, 'updated_at' => $now,
	) );
	$cleanup['batches'][] = (int) $wpdb->insert_id;
	return (int) $wpdb->insert_id;
}

function t_user( string $login, string $role ): int {
	global $cleanup;
	$id = wp_insert_user( array( 'user_login' => $login, 'user_pass' => wp_generate_password(), 'user_email' => $login . '@example.test', 'role' => $role ) );
	$cleanup['users'][] = (int) $id;
	return (int) $id;
}

function t_stop( callable $fn, string $hook, string $marker ): bool {
	$filter = 'wp_die_handler' === $hook
		? static fn() => static function () use ( $marker ) {
			throw new RuntimeException( $marker );
		}
		: static function () use ( $marker ) {
			throw new RuntimeException( $marker );
		};
	add_filter( $hook, $filter, 999 );
	try {
		$fn();
		return false;
	} catch ( RuntimeException $e ) {
		return $marker === $e->getMessage();
	} finally {
		remove_filter( $hook, $filter, 999 );
	}
}

function t_dies( callable $fn ): bool {
	return t_stop( $fn, 'wp_die_handler', 'wp_die' );
}

function t_redirects( callable $fn ): bool {
	return t_stop( $fn, 'wp_redirect', 'redirected' );
}

function t_file( string $relative ): string {
	return CC_Photo_Store::base_dir() . '/' . $relative;
}

$tag      = substr( bin2hex( random_bytes( 4 ) ), 0, 8 );
$original_timezone = get_option( 'timezone_string' );
$original_offset   = get_option( 'gmt_offset' );

try {
	CC_Admin_Roles::apply();
	$batch  = t_batch( $tag );
	$other  = t_batch( $tag . 'b' );
	$owner  = t_user( "zzcont-owner-$tag", 'cc_owner' );
	$staff  = t_user( "zzcont-staff-$tag", 'cc_staff' );
	$instr  = t_user( "zzcont-instr-$tag", 'cc_instructor' );
	$nobody = t_user( "zzcont-none-$tag", 'subscriber' );

	echo "upload error messages\n";
	$ten = 10 * 1048576;
	t_assert( 'File too large (max 10 MB).' === CC_Resource_Store::upload_error_message( UPLOAD_ERR_INI_SIZE, $ten ), 'INI_SIZE names the limit' );
	t_assert( 'File too large (max 2 MB).' === CC_Resource_Store::upload_error_message( UPLOAD_ERR_FORM_SIZE, 2 * 1048576 ), 'FORM_SIZE uses the real limit' );
	t_assert( false !== strpos( CC_Resource_Store::upload_error_message( UPLOAD_ERR_PARTIAL, $ten ), 'interrupted' ), 'PARTIAL says interrupted' );
	t_assert( false !== strpos( CC_Resource_Store::upload_error_message( UPLOAD_ERR_NO_TMP_DIR, $ten ), 'could not receive' ), 'NO_TMP_DIR says server problem' );
	t_assert( false !== strpos( CC_Resource_Store::upload_error_message( UPLOAD_ERR_CANT_WRITE, $ten ), 'could not receive' ), 'CANT_WRITE says server problem' );
	t_assert( 'Upload a PDF file.' === CC_Resource_Store::upload_error_message( UPLOAD_ERR_NO_FILE, $ten ), 'other codes keep the generic message' );
	$stored_err = CC_Resource_Store::store( array( 'error' => UPLOAD_ERR_INI_SIZE, 'tmp_name' => '', 'size' => 0, 'name' => 'a.pdf' ) );
	t_assert( is_wp_error( $stored_err ) && 0 === strpos( $stored_err->get_error_message(), 'File too large' ), 'store() reports an oversize upload specifically' );
	t_assert( CC_Resource_Store::max_upload_bytes() <= CC_Resource_Store::MAX_BYTES, 'upload limit never exceeds 10 MB' );

	echo "resource store accepts and rejects\n";
	$stored = CC_Resource_Store::store_bytes( t_pdf(), "../Week 1 \"notes\".pdf" );
	t_assert( ! is_wp_error( $stored ) && 1 === preg_match( '#^resources/[a-f0-9]{32}\.pdf$#', $stored['path'] ), 'a real PDF is accepted with a random name under resources/' );
	if ( ! is_wp_error( $stored ) ) {
		$cleanup['files'][] = $stored['path'];
		t_assert( 'Week 1 notes.pdf' === $stored['name'], 'stored name is sanitised' );
		$abs = CC_Resource_Store::path( $stored['path'] );
		t_assert( null !== $abs && is_file( $abs ) && t_pdf() === file_get_contents( $abs ), 'path() resolves the stored file' );
		t_assert( 0640 === ( fileperms( $abs ) & 0777 ), 'file mode is 0640' );
		t_assert( 0 !== strpos( realpath( $abs ), (string) realpath( ABSPATH ) ), 'file is outside the web root' );
	}
	$rejects = array(
		'plain text'                  => array( 'hello world', 'not a pdf' ),
		'HTML named .pdf'             => array( '<html><script>alert(1)</script></html>', 'x.pdf' ),
		'fake %PDF without version'   => array( "%PDF\nhello", 'x.pdf' ),
		'fake %PDF after other bytes' => array( "<?php echo 1; ?>\n" . t_pdf(), 'x.pdf' ),
		'PNG image'                   => array( base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==' ), 'x.pdf' ),
		'zero bytes'                  => array( '', 'x.pdf' ),
		'oversize PDF'                => array( t_pdf( str_repeat( 'a', CC_Resource_Store::MAX_BYTES ) ), 'big.pdf' ),
	);
	foreach ( $rejects as $label => $case ) {
		$result = CC_Resource_Store::store_bytes( $case[0], $case[1] );
		t_assert( is_wp_error( $result ) && 'validation_failed' === $result->get_error_code(), "rejects $label" );
	}
	$exact = CC_Resource_Store::store_bytes( str_pad( t_pdf(), CC_Resource_Store::MAX_BYTES, ' ' ), 'max.pdf' );
	t_assert( ! is_wp_error( $exact ), 'a PDF of exactly 10 MB is accepted' );
	if ( ! is_wp_error( $exact ) ) {
		$cleanup['files'][] = $exact['path'];
	}
	$tmp = tempnam( sys_get_temp_dir(), 'zzpdf' );
	file_put_contents( $tmp, t_pdf() );
	$cleanup['links'][] = $tmp;
	$not_uploaded = CC_Resource_Store::store( array( 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => filesize( $tmp ), 'name' => 'a.pdf' ) );
	t_assert( is_wp_error( $not_uploaded ), 'store() refuses a file that was not an HTTP upload' );
	t_assert( is_wp_error( CC_Resource_Store::store( array( 'error' => UPLOAD_ERR_NO_FILE ) ) ), 'store() refuses a missing upload' );

	echo "resource store path safety\n";
	$dir = CC_Photo_Store::base_dir() . '/resources';
	foreach ( array( 'resources/../photos/x.pdf', '../etc/passwd', '/etc/passwd', 'resources/../../etc/passwd', 'photos/' . str_repeat( 'a', 32 ) . '.jpg', 'resources/' . str_repeat( 'a', 32 ) . '.pdf', 'resources/' . str_repeat( 'a', 32 ) . '.pdf/..', '' ) as $bad ) {
		t_assert( null === CC_Resource_Store::path( $bad ), 'path() rejects ' . ( '' === $bad ? '(empty)' : $bad ) );
	}
	$link_name = 'resources/' . bin2hex( random_bytes( 16 ) ) . '.pdf';
	t_assert( symlink( $tmp, t_file( $link_name ) ), 'fixture: symlink out of the directory' );
	$cleanup['links'][] = t_file( $link_name );
	t_assert( null === CC_Resource_Store::path( $link_name ), 'path() rejects a symlink pointing outside the directory' );
	if ( ! is_wp_error( $stored ) ) {
		$tampered = $stored['path'];
		$copy     = CC_Resource_Store::store_bytes( t_pdf(), 'c.pdf' );
		$cleanup['files'][] = $copy['path'];
		file_put_contents( t_file( $copy['path'] ), '<html>replaced</html>' );
		t_assert( null === CC_Resource_Store::path( $copy['path'] ), 'path() re-validates content on read' );
		CC_Resource_Store::delete( $tampered );
		t_assert( null === CC_Resource_Store::path( $tampered ) && ! file_exists( t_file( $tampered ) ), 'delete() removes the file and path() then returns null' );
		CC_Resource_Store::delete( 'resources/../../../etc/hostname' );
		t_assert( file_exists( '/etc/hostname' ), 'delete() ignores a traversal reference' );
	}

	echo "repository CRUD and ordering\n";
	$m1 = CC_Content_Repository::create_module( $batch, 'Module A' );
	$m2 = CC_Content_Repository::create_module( $batch, 'Module B' );
	$m3 = CC_Content_Repository::create_module( $batch, 'Module C', 9 );
	t_assert( $m1 > 0 && $m2 > $m1, 'modules created' );
	CC_Content_Repository::update_module( $m3, array( 'sort_order' => 0 ) );
	t_assert( array( $m3, $m1, $m2 ) === array_column( CC_Content_Repository::modules_for_batch( $batch ), 'id' ), 'modules ordered by sort_order then id' );
	t_assert( CC_Content_Repository::update_module( $m1, array( 'title' => 'Renamed A' ) ) && 'Renamed A' === CC_Content_Repository::find_module( $m1 )['title'], 'module updated' );
	t_assert( array() === CC_Content_Repository::modules_for_batch( $other ), 'other batch has no modules' );

	$file_a = CC_Resource_Store::store_bytes( t_pdf( 'a' ), 'lesson-a.pdf' );
	$file_b = CC_Resource_Store::store_bytes( t_pdf( 'b' ), 'lesson-b.pdf' );
	$cleanup['files'][] = $file_a['path'];
	$cleanup['files'][] = $file_b['path'];
	$l1 = CC_Content_Repository::create_lesson( $m1, array( 'title' => 'L1', 'scheduled_at' => '2030-05-01 10:00:00', 'attachment_path' => $file_a['path'], 'attachment_name' => $file_a['name'] ) );
	$l2 = CC_Content_Repository::create_lesson( $m1, array( 'title' => 'L2', 'scheduled_at' => '2030-05-03 10:00:00' ) );
	$l3 = CC_Content_Repository::create_lesson( $m1, array( 'title' => 'L3' ) );
	$l4 = CC_Content_Repository::create_lesson( $m2, array( 'title' => 'L4', 'scheduled_at' => '2030-05-02 08:00:00', 'attachment_path' => $file_b['path'], 'attachment_name' => $file_b['name'] ) );
	t_assert( $l1 > 0 && $l2 > $l1 && $l3 > $l2, 'lessons created, append order' );
	$tree = CC_Content_Repository::modules_for_batch( $batch );
	t_assert( array( $m3, $m1, $m2 ) === array_column( $tree, 'id' ) && array( $l1, $l2, $l3 ) === array_column( $tree[1]['lessons'], 'id' ), 'modules_for_batch nests ordered lessons' );
	t_assert( true === $tree[1]['lessons'][0]['has_attachment'] && 'lesson-a.pdf' === $tree[1]['lessons'][0]['attachment_name'] && false === $tree[1]['lessons'][1]['has_attachment'], 'shape exposes has_attachment and name' );
	t_assert( false === strpos( wp_json_encode( $tree ), 'resources/' ) && false === strpos( wp_json_encode( $tree ), 'attachment_path' ), 'shape never contains the private path' );
	$found = CC_Content_Repository::find_lesson( $l1 );
	t_assert( $batch === (int) $found['batch_id'] && $file_a['path'] === $found['attachment_path'], 'find_lesson returns batch_id and attachment_path' );
	t_assert( null === CC_Content_Repository::find_lesson( 999999999 ), 'find_lesson unknown is null' );

	t_assert( CC_Content_Repository::move_lesson( $l3, -1 ), 'move lesson up' );
	t_assert( array( $l1, $l3, $l2 ) === array_column( CC_Content_Repository::modules_for_batch( $batch )[1]['lessons'], 'id' ), 'lesson order after move' );
	t_assert( ! CC_Content_Repository::move_lesson( $l1, -1 ) && ! CC_Content_Repository::move_lesson( $l2, 1 ), 'moving past either end is refused' );
	t_assert( CC_Content_Repository::move_module( $m2, -1 ) && array( $m3, $m2, $m1 ) === array_column( CC_Content_Repository::modules_for_batch( $batch ), 'id' ), 'module move swaps neighbours' );

	echo "scheduled between\n";
	$sched = CC_Content_Repository::lessons_scheduled_between( array( $batch ), '2030-05-01 00:00:00', '2030-05-02 23:59:59' );
	t_assert( array( $l1, $l4 ) === array_column( $sched, 'id' ), 'window returns matching lessons earliest first' );
	t_assert( $batch === $sched[0]['batch_id'] && 'Renamed A' === $sched[0]['module_title'] && false === strpos( wp_json_encode( $sched ), 'resources/' ), 'rows carry batch and module title but no path' );
	t_assert( array( $l1 ) === array_column( CC_Content_Repository::lessons_scheduled_between( array( $batch ), '2030-05-01 10:00:00', '2030-05-01 10:00:00' ), 'id' ), 'bounds are inclusive' );
	t_assert( array() === CC_Content_Repository::lessons_scheduled_between( array( $other ), '2030-01-01 00:00:00', '2031-01-01 00:00:00' ), 'other batch excluded' );
	t_assert( array() === CC_Content_Repository::lessons_scheduled_between( array(), '2030-01-01 00:00:00', '2031-01-01 00:00:00' ), 'empty batch list returns nothing' );
	t_assert( array( $l1, $l4, $l2 ) === array_column( CC_Content_Repository::lessons_scheduled_between( array( $batch, $other ), '2030-01-01 00:00:00', '2031-01-01 00:00:00' ), 'id' ), 'unscheduled lessons never match' );

	echo "attachment replacement and cascade\n";
	t_assert( CC_Content_Repository::update_lesson( $l1, array( 'title' => 'L1 new', 'attachment_path' => $file_b['path'], 'attachment_name' => 'b.pdf' ) ), 'lesson updated with a new reference' );
	t_assert( ! file_exists( t_file( $file_a['path'] ) ), 'the replaced PDF file is deleted' );
	t_assert( CC_Content_Repository::update_lesson( $l1, array( 'title' => 'L1 again' ) ) && file_exists( t_file( $file_b['path'] ) ), 'updating other fields keeps the file' );
	$file_c = CC_Resource_Store::store_bytes( t_pdf( 'c' ), 'c.pdf' );
	$cleanup['files'][] = $file_c['path'];
	CC_Content_Repository::update_lesson( $l2, array( 'attachment_path' => $file_c['path'], 'attachment_name' => 'c.pdf' ) );
	t_assert( CC_Content_Repository::delete_lesson( $l2 ) && null === CC_Content_Repository::find_lesson( $l2 ) && ! file_exists( t_file( $file_c['path'] ) ), 'delete_lesson removes row and file' );
	t_assert( ! CC_Content_Repository::delete_lesson( $l2 ), 'deleting a missing lesson reports false' );
	t_assert( CC_Content_Repository::delete_module( $m1 ), 'delete_module succeeds' );
	t_assert( null === CC_Content_Repository::find_module( $m1 ) && null === CC_Content_Repository::find_lesson( $l1 ) && null === CC_Content_Repository::find_lesson( $l3 ), 'module delete cascades to its lessons' );
	t_assert( ! file_exists( t_file( $file_b['path'] ) ), 'module delete removes lesson files' );
	t_assert( null !== CC_Content_Repository::find_module( $m2 ) && null !== CC_Content_Repository::find_lesson( $l4 ), 'sibling module untouched' );

	echo "admin operations\n";
	wp_set_current_user( $owner );
	update_option( 'timezone_string', 'Asia/Dhaka' );
	t_assert( '2030-03-01 04:00:00' === CC_Admin_Content::site_time_to_utc( '2030-03-01T10:00' ), 'site-timezone input is stored as UTC (Dhaka +6)' );
	t_assert( '2030-03-01T10:00' === CC_Admin_Content::utc_to_site_time( '2030-03-01 04:00:00' ), 'UTC is shown back in site time' );
	t_assert( null === CC_Admin_Content::site_time_to_utc( '  ' ), 'blank time clears the schedule' );
	t_assert( is_wp_error( CC_Admin_Content::site_time_to_utc( '2030-02-31T10:00' ) ) && is_wp_error( CC_Admin_Content::site_time_to_utc( 'tomorrow' ) ), 'impossible and free-form dates are rejected' );
	update_option( 'timezone_string', 'America/New_York' );
	t_assert( '2030-07-01 14:00:00' === CC_Admin_Content::site_time_to_utc( '2030-07-01T10:00' ), 'DST offset is applied' );
	update_option( 'timezone_string', 'Asia/Dhaka' );

	foreach ( array( 'nobody' => $nobody, 'instructor' => $instr ) as $who => $uid ) {
		t_assert( is_wp_error( CC_Admin_Content::save_module( $batch, 0, 'X', $uid ) ) && is_wp_error( CC_Admin_Content::delete_module( $m2, $uid ) ) && is_wp_error( CC_Admin_Content::save_lesson( $m2, 0, array( 'title' => 'X' ), null, $uid ) ) && is_wp_error( CC_Admin_Content::move_lesson( $l4, 1, $uid ) ), "$who is refused by every operation" );
	}
	$new_module = CC_Admin_Content::save_module( $batch, 0, '  Fresh module ', $staff );
	t_assert( is_int( $new_module ) && 'Fresh module' === CC_Content_Repository::find_module( $new_module )['title'], 'staff creates a module (title trimmed)' );
	t_assert( is_wp_error( CC_Admin_Content::save_module( $batch, 0, '   ', $owner ) ) && is_wp_error( CC_Admin_Content::save_module( 999999999, 0, 'X', $owner ) ), 'blank title and unknown batch rejected' );
	t_assert( $new_module === CC_Admin_Content::save_module( $batch, $new_module, 'Fresh renamed', $owner ), 'module renamed' );

	$lesson = CC_Admin_Content::save_lesson( $new_module, 0, array( 'title' => 'Timed', 'scheduled_at' => '2030-03-01T10:00' ), null, $owner );
	t_assert( is_int( $lesson ) && '2030-03-01 04:00:00' === CC_Content_Repository::find_lesson( $lesson )['scheduled_at'], 'lesson saved with UTC time' );
	t_assert( is_wp_error( CC_Admin_Content::save_lesson( $new_module, 0, array( 'title' => 'Bad', 'scheduled_at' => 'soon' ), null, $owner ) ) && is_wp_error( CC_Admin_Content::save_lesson( $new_module, 0, array( 'title' => '' ), null, $owner ) ), 'bad time and empty title rejected' );
	t_assert( is_wp_error( CC_Admin_Content::save_lesson( $new_module, 0, array( 'title' => 'Up' ), array( 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => 10, 'name' => 'a.pdf' ), $owner ) ), 'a non-uploaded file fails the lesson save' );
	t_assert( 1 === count( CC_Content_Repository::modules_for_batch( $batch )[ array_search( $new_module, array_column( CC_Content_Repository::modules_for_batch( $batch ), 'id' ), true ) ]['lessons'] ), 'failed saves create no lesson' );
	$with_pdf = CC_Resource_Store::store_bytes( t_pdf( 'z' ), 'z.pdf' );
	$cleanup['files'][] = $with_pdf['path'];
	CC_Content_Repository::update_lesson( $lesson, array( 'attachment_path' => $with_pdf['path'], 'attachment_name' => $with_pdf['name'] ) );
	t_assert( $lesson === CC_Admin_Content::save_lesson( $new_module, $lesson, array( 'title' => 'Timed', 'scheduled_at' => '', 'remove_attachment' => true ), null, $owner ), 'lesson saved with remove_attachment' );
	$after = CC_Content_Repository::find_lesson( $lesson );
	t_assert( null === $after['attachment_path'] && null === $after['scheduled_at'] && ! file_exists( t_file( $with_pdf['path'] ) ), 'PDF removed, file deleted, schedule cleared' );
	t_assert( true === CC_Admin_Content::move_module( $new_module, -1, $owner ) && is_wp_error( CC_Admin_Content::move_module( 999999999, 1, $owner ) ), 'module move through the admin class' );
	t_assert( true === CC_Admin_Content::delete_lesson( $lesson, $owner ) && is_wp_error( CC_Admin_Content::delete_lesson( $lesson, $owner ) ), 'lesson delete through the admin class' );
	t_assert( true === CC_Admin_Content::delete_module( $new_module, $owner ), 'module delete through the admin class' );
	$audit = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}cc_audit_log WHERE action LIKE %s AND actor_id IN (%d,%d,%d)", 'content.%', $owner, $staff, $nobody ) );
	t_assert( $audit >= 7, 'operations are audited' );
	$dump = wp_json_encode( $wpdb->get_results( "SELECT * FROM {$p}cc_audit_log WHERE action LIKE 'content.%' ORDER BY id DESC LIMIT 30", ARRAY_A ) );
	t_assert( false === strpos( $dump, 'resources/' ) && false === strpos( $dump, 'Fresh' ), 'audit rows hold no paths or titles' );

	echo "handler capability and nonce refusals\n";
	$handlers = array(
		'handle_module_save'   => array( array( 'batch_id' => $batch, 'module_id' => 0, 'title' => 'Via handler' ), 'cc_content_module_save_0_' . $batch ),
		'handle_module_delete' => array( array( 'module_id' => $m2 ), 'cc_content_module_delete_' . $m2 ),
		'handle_module_move'   => array( array( 'module_id' => $m2, 'direction' => 'up' ), 'cc_content_module_move_' . $m2 ),
		'handle_lesson_save'   => array( array( 'module_id' => $m2, 'lesson_id' => 0, 'title' => 'Via handler' ), 'cc_content_lesson_save_0_' . $m2 ),
		'handle_lesson_delete' => array( array( 'lesson_id' => $l4 ), 'cc_content_lesson_delete_' . $l4 ),
		'handle_lesson_move'   => array( array( 'lesson_id' => $l4, 'direction' => 'down' ), 'cc_content_lesson_move_' . $l4 ),
	);
	$count_modules = static fn(): int => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}cc_modules WHERE batch_id = %d", $batch ) );
	$count_lessons = static fn(): int => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}cc_lessons" );
	$modules_before = $count_modules();
	$lessons_before = $count_lessons();

	foreach ( array( 'nobody' => $nobody, 'instructor' => $instr ) as $who => $uid ) {
		wp_set_current_user( $uid );
		foreach ( $handlers as $handler => $spec ) {
			$_POST    = $spec[0];
			$_REQUEST = array_merge( $spec[0], array( '_wpnonce' => wp_create_nonce( $spec[1] ) ) );
			t_assert( t_dies( array( 'CC_Admin_Content', $handler ) ), "$handler refuses $who even with a valid nonce" );
		}
	}
	t_assert( t_dies( array( 'CC_Admin_Content', 'render' ) ), 'render refuses a user without capability' );
	wp_set_current_user( $owner );
	foreach ( $handlers as $handler => $spec ) {
		$_POST    = $spec[0];
		$_REQUEST = $spec[0];
		t_assert( t_dies( array( 'CC_Admin_Content', $handler ) ), "$handler refuses a missing nonce" );
		$_REQUEST['_wpnonce'] = wp_create_nonce( $spec[1] . 'x' );
		t_assert( t_dies( array( 'CC_Admin_Content', $handler ) ), "$handler refuses a nonce for another action" );
	}
	t_assert( $modules_before === $count_modules() && $lessons_before === $count_lessons() && null !== CC_Content_Repository::find_lesson( $l4 ), 'refused handlers changed nothing' );

	wp_set_current_user( $staff );
	$_POST    = $handlers['handle_module_save'][0];
	$_REQUEST = array_merge( $_POST, array( '_wpnonce' => wp_create_nonce( $handlers['handle_module_save'][1] ) ) );
	t_assert( t_redirects( array( 'CC_Admin_Content', 'handle_module_save' ) ), 'staff with a valid nonce is accepted and redirected' );
	t_assert( $modules_before + 1 === $count_modules(), 'the accepted handler created the module' );
	$_POST    = $handlers['handle_lesson_delete'][0];
	$_REQUEST = array_merge( $_POST, array( '_wpnonce' => wp_create_nonce( $handlers['handle_lesson_delete'][1] ) ) );
	t_assert( t_redirects( array( 'CC_Admin_Content', 'handle_lesson_delete' ) ) && null === CC_Content_Repository::find_lesson( $l4 ), 'lesson delete handler works and removes the lesson' );
	t_assert( ! file_exists( t_file( $file_b['path'] ) ), 'handler cascade left no orphan file' );

	echo "render\n";
	wp_set_current_user( $owner );
	$_GET = array( 'batch' => $batch );
	ob_start();
	CC_Admin_Content::render();
	$html = (string) ob_get_clean();
	t_assert( false !== strpos( $html, 'Course content' ) && false !== strpos( $html, 'Via handler' ), 'owner sees the batch modules' );
	t_assert( false === strpos( $html, 'resources/' ), 'rendered page never contains a private path' );
	t_assert( false !== strpos( $html, 'File too large (max ' ) && false !== strpos( $html, "'notice notice-error'" ) && false === strpos( $html, 'window.alert' ), 'client-side size refusal renders an error notice, not an alert' );
} finally {
	$_POST = $_GET = $_REQUEST = array();
	if ( false === $original_timezone || '' === $original_timezone ) {
		update_option( 'timezone_string', '' );
	} else {
		update_option( 'timezone_string', $original_timezone );
	}
	update_option( 'gmt_offset', $original_offset );
	foreach ( $cleanup['batches'] as $id ) {
		$module_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$p}cc_modules WHERE batch_id = %d", $id ) );
		foreach ( $module_ids as $module_id ) {
			CC_Content_Repository::delete_module( (int) $module_id );
		}
		$wpdb->delete( "{$p}cc_batches", array( 'id' => $id ) );
	}
	foreach ( $cleanup['files'] as $relative ) {
		CC_Resource_Store::delete( $relative );
	}
	foreach ( $cleanup['links'] as $path ) {
		if ( is_link( $path ) || is_file( $path ) ) {
			unlink( $path );
		}
	}
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( $cleanup['users'] as $uid ) {
		$wpdb->delete( "{$p}cc_audit_log", array( 'actor_id' => $uid ) );
		wp_delete_user( $uid );
	}
}

echo "\n$checks checks, $failures failures\n";
exit( $failures ? 1 : 0 );
