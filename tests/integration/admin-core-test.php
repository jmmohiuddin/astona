<?php
/**
 * Integration tests for the admin core (roles, audit, settings, dashboard). Run inside the wpcli container:
 *   docker compose run --rm -T wpcli eval-file /tests/integration/admin-core-test.php
 */
global $wpdb, $failures, $checks, $cleanup;
$p        = $wpdb->prefix;
$failures = 0;
$checks   = 0;
$cleanup  = array( 'users' => array(), 'payments' => array(), 'invoices' => array(), 'apps' => array(), 'batches' => array() );

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

function t_user( string $role ): WP_User {
	global $cleanup;
	$tag = substr( bin2hex( random_bytes( 5 ) ), 0, 10 );
	$id  = wp_insert_user( array( 'user_login' => "zzadm$tag", 'user_pass' => wp_generate_password(), 'user_email' => "zzadm$tag@example.invalid", 'role' => $role ) );
	if ( is_wp_error( $id ) ) {
		throw new RuntimeException( $id->get_error_message() );
	}
	$cleanup['users'][] = $id;
	return new WP_User( $id );
}

$original_settings = get_option( CC_Admin_Settings::OPTION, null );
$marker            = 'zz.test.' . substr( bin2hex( random_bytes( 4 ) ), 0, 8 );
// Runs on exit(), fatal errors and thrown exceptions too, so an aborted run cannot leave audit rows behind.
register_shutdown_function(
	static function () use ( $marker ): void {
		global $wpdb;
		$wpdb->delete( $wpdb->prefix . 'cc_audit_log', array( 'action' => $marker ) );
	}
);

try {
	echo "roles and capabilities\n";
	CC_Admin_Roles::apply();
	$all = CC_Admin_Roles::all_caps();
	t_assert( 18 === count( $all ), 'eighteen cc_ capabilities defined' );

	$matrix = array(
		'cc_owner'      => $all,
		'cc_staff'      => array( 'cc_review_applications', 'cc_view_students', 'cc_manage_students', 'cc_view_payments', 'cc_export_data', 'cc_view_dashboard', 'cc_manage_content', 'cc_manage_live', 'cc_manage_notices', 'cc_manage_blog', 'cc_manage_gallery', 'cc_view_inquiries', 'cc_manage_inquiries', 'cc_manage_media' ),
		'cc_instructor' => array( 'cc_view_dashboard' ),
		'cc_student'    => array(),
		'administrator' => $all,
	);
	foreach ( $matrix as $role => $granted ) {
		$user = t_user( $role );
		foreach ( $all as $cap ) {
			$expected = in_array( $cap, $granted, true );
			t_assert( $expected === $user->has_cap( $cap ), "$role " . ( $expected ? 'has' : 'lacks' ) . " $cap" );
		}
		if ( 'administrator' !== $role ) {
			t_assert( ! $user->has_cap( 'edit_plugins' ) && ! $user->has_cap( 'manage_options' ) && ! $user->has_cap( 'install_plugins' ), "$role has no core admin caps" );
		}
	}
	$student_role = get_role( 'cc_student' );
	t_assert( array() === array_values( array_filter( array_keys( $student_role->capabilities ), static fn( $c ) => 0 === strpos( $c, 'cc_' ) ) ), 'student role stores no cc_ caps' );
	t_assert( get_role( 'cc_owner' )->has_cap( 'read' ) && get_role( 'cc_instructor' )->has_cap( 'read' ), 'owner and instructor can read' );

	CC_Admin_Roles::apply();
	t_assert( 18 === count( array_filter( array_keys( get_role( 'cc_owner' )->capabilities ), static fn( $c ) => 0 === strpos( $c, 'cc_' ) ) ), 'apply() is idempotent' );
	$owner = t_user( 'cc_owner' );
	wp_set_current_user( $owner->ID );
	t_assert( CC_Admin_Roles::user_can_any( array( 'nope', 'cc_view_audit' ) ) && ! CC_Admin_Roles::user_can_any( array( 'nope', 'edit_plugins' ) ), 'user_can_any' );

	echo "login redirect\n";
	$dash = admin_url( 'admin.php?page=cc-dashboard' );
	foreach ( array( 'cc_owner', 'cc_staff', 'cc_instructor' ) as $role ) {
		$u = $role === 'cc_owner' ? $owner : t_user( $role );
		t_assert( $dash === CC_Admin_Roles::filter_login_redirect( admin_url(), '', $u ), "$role: default admin URL goes to the dashboard" );
		t_assert( $dash === CC_Admin_Roles::filter_login_redirect( admin_url( 'profile.php' ), '', $u ), "$role: empty requested redirect goes to the dashboard" );
	}
	$explicit = admin_url( 'admin.php?page=cc-payments' );
	t_assert( $explicit === CC_Admin_Roles::filter_login_redirect( $explicit, $explicit, $owner ), 'explicit wp-admin redirect_to is respected' );
	t_assert( 'https://evil.example/' === CC_Admin_Roles::filter_login_redirect( 'https://evil.example/', 'https://evil.example/', $owner ), 'foreign redirect_to is passed through untouched for WP to vet' );
	t_assert( $dash === CC_Admin_Roles::filter_login_redirect( admin_url(), admin_url(), $owner ), 'requested == admin_url() counts as default' );
	$stu = t_user( 'cc_student' );
	t_assert( admin_url() === CC_Admin_Roles::filter_login_redirect( admin_url(), '', $stu ), 'students are left alone by this filter' );
	$other = t_user( 'subscriber' );
	t_assert( admin_url() === CC_Admin_Roles::filter_login_redirect( admin_url(), '', $other ), 'other roles are left alone' );
	t_assert( 'x' === CC_Admin_Roles::filter_login_redirect( 'x', '', new WP_Error() ), 'login errors pass through' );

	echo "audit\n";
	$secret = 'Rahim Uddin +8801712345678';
	CC_Audit::log( $marker, 'application', 987001, array( 'name' => $secret ), 'note ok' );
	CC_Audit::log( $marker, 'payment', 987002 );
	$res = CC_Audit::query( array( 'action' => $marker ) );
	t_assert( 2 === $res['total'] && 2 === count( $res['items'] ), 'both rows queryable by action' );
	$row = $res['items'][1];
	t_assert( (int) $owner->ID === (int) $row['actor_id'] && 'application' === $row['entity_type'] && 987001 === (int) $row['entity_id'], 'actor, entity recorded' );
	t_assert( CC_Audit::diff_hash( array( 'name' => $secret ) ) === $row['diff_hash'], 'diff hash stored' );
	t_assert( null === $res['items'][0]['diff_hash'], 'empty diff stores null hash' );
	$dump = wp_json_encode( $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$p}cc_audit_log WHERE action = %s", $marker ), ARRAY_A ) );
	t_assert( false === strpos( $dump, 'Rahim' ) && false === strpos( $dump, '8801712345678' ), 'no PII stored' );
	t_assert( null === $row['ip_hash'] || 64 === strlen( $row['ip_hash'] ), 'ip stored only as hash' );
	t_assert( 1 === CC_Audit::query( array( 'action' => $marker, 'entity_type' => 'payment' ) )['total'], 'entity_type filter' );
	t_assert( 2 === CC_Audit::query( array( 'actor' => $owner->ID, 'from' => gmdate( 'Y-m-d' ), 'to' => gmdate( 'Y-m-d' ), 'action' => $marker ) )['total'], 'actor and date range filter' );
	t_assert( 0 === CC_Audit::query( array( 'action' => $marker, 'to' => '2000-01-01' ) )['total'], 'date range excludes' );
	$paged = CC_Audit::query( array( 'action' => $marker, 'per_page' => 1, 'page' => 2 ) );
	t_assert( 2 === $paged['total'] && 1 === count( $paged['items'] ) && 'application' === $paged['items'][0]['entity_type'], 'paging' );
	t_assert( 0 === CC_Audit::query( array( 'action' => "$marker' OR 1=1 --" ) )['total'], 'filter values are parameterised' );

	echo "settings\n";
	$clean = CC_Admin_Settings::sanitize( array( 'name' => '<b>Astona</b>', 'phone' => '+880 1700-000000 <script>', 'email' => 'bad', 'address' => "Line1\n<script>x</script>" ) );
	t_assert( 'Astona' === $clean['name'], 'name stripped of tags' );
	t_assert( '+880 1700-000000' === $clean['phone'], 'phone keeps only dial characters' );
	t_assert( '' === $clean['email'], 'invalid email rejected' );
	t_assert( false === strpos( $clean['address'], '<' ), 'address stripped of tags' );
	t_assert( 'a@example.com' === CC_Admin_Settings::sanitize( array( 'email' => 'a@example.com' ) )['email'], 'valid email kept' );
	t_assert( 300 === mb_strlen( CC_Admin_Settings::sanitize( array( 'address' => str_repeat( 'a', 500 ) ) )['address'] ), 'address length capped' );

	update_option( CC_Admin_Settings::OPTION, array( 'name' => 'Zed Academy', 'phone' => '+880 1999-111111', 'email' => 'hi@zed.example', 'address' => '' ) );
	$defaults = array( 'phone' => 'P', 'email' => 'E', 'address' => 'A' );
	$out      = apply_filters( 'astona_contact', $defaults );
	t_assert( '+880 1999-111111' === $out['phone'] && 'hi@zed.example' === $out['email'] && 'Zed Academy' === $out['name'], 'astona_contact filter overrides non-empty settings' );
	t_assert( 'A' === $out['address'], 'empty setting keeps theme default' );
	if ( function_exists( 'astona_contact' ) ) {
		t_assert( '+880 1999-111111' === astona_contact()['phone'], 'theme astona_contact() reflects settings' );
	}

	$status = CC_Admin_Settings::status();
	t_assert( isset( $status['Payment gateway'], $status['Private directory'], $status['Encryption key (ENC)'] ), 'status panel has expected rows' );
	$status_dump = wp_json_encode( $status );
	t_assert( false === strpos( $status_dump, (string) getenv( 'CC_ENC_KEY' ) ) || '' === (string) getenv( 'CC_ENC_KEY' ), 'status never contains the key' );

	echo "dashboard stats\n";
	$before = CC_Admin_Dashboard::stats();
	$now    = gmdate( 'Y-m-d H:i:s' );
	$wpdb->insert( "{$p}cc_batches", array( 'course_id' => 0, 'name' => 'ZZ dash', 'capacity' => 5, 'price' => 100, 'start_date' => '2030-01-01', 'status' => 'open', 'application_open' => 1, 'created_at' => $now, 'updated_at' => $now ) );
	$batch                = (int) $wpdb->insert_id;
	$cleanup['batches'][] = $batch;
	$tag                  = strtoupper( substr( bin2hex( random_bytes( 6 ) ), 0, 12 ) );
	$wpdb->insert( "{$p}cc_applications", array(
		'public_ref' => substr( "ZZDASH{$tag}00000000", 0, 26 ), 'idempotency_key' => wp_generate_uuid4(), 'batch_id' => $batch, 'student_phone' => '+8801700000000',
		'full_name' => 'ZZ Dash', 'gender' => 'o', 'dob' => '2008-01-01', 'id_doc_type' => 'nid', 'id_doc_enc' => 'x', 'guardian_name' => 'G',
		'guardian_phone' => '+8801700000001', 'institution' => 'I', 'class_level' => '10', 'consent_at' => $now, 'status' => 'pending', 'created_at' => $now, 'updated_at' => $now,
	) );
	$app                = (int) $wpdb->insert_id;
	$cleanup['apps'][]  = $app;
	$wpdb->insert( "{$p}cc_invoices", array( 'application_id' => $app, 'amount' => 700, 'status' => 'paid', 'number' => "ZZD-$tag", 'created_at' => $now ) );
	$inv                  = (int) $wpdb->insert_id;
	$cleanup['invoices'][] = $inv;
	$old                  = gmdate( 'Y-m-d H:i:s', time() - 3600 );
	// Row 5 is old by created_at but freshly touched: stuck by the shared definition. Row 6 is the reverse: fresh created_at, old updated_at.
	foreach ( array( array( 'completed', 700, $now, $now ), array( 'executing', 50, $old, $old ), array( 'executing', 60, $now, $now ), array( 'reconcile_needed', 70, $now, $now ), array( 'initiated', 80, $old, $now ), array( 'initiated', 90, $now, $old ) ) as $i => $pay ) {
		$wpdb->insert( "{$p}cc_payments", array( 'invoice_id' => $inv, 'gateway' => 'fake', 'method' => 'fake', 'gateway_payment_id' => "zzdash$tag$i", 'amount' => $pay[1], 'status' => $pay[0], 'created_at' => $pay[2], 'updated_at' => $pay[3], 'settled_at' => 'completed' === $pay[0] ? $now : null ) );
		$cleanup['payments'][] = (int) $wpdb->insert_id;
	}
	$wpdb->insert( "{$p}cc_enrollments", array( 'user_id' => 0, 'batch_id' => $batch, 'application_id' => $app, 'status' => 'active', 'enrolled_at' => $now ) );
	$after = CC_Admin_Dashboard::stats();
	t_assert( 1 === $after['pending_applications'] - $before['pending_applications'], 'pending applications counted' );
	t_assert( 1 === $after['applications_today'] - $before['applications_today'] && 1 === $after['applications_7d'] - $before['applications_7d'], 'applications today and 7d counted' );
	t_assert( 700.0 === $after['revenue_today'] - $before['revenue_today'] && 700.0 === $after['revenue_30d'] - $before['revenue_30d'] && 700.0 === $after['revenue_total'] - $before['revenue_total'], 'only completed payments count as revenue' );
	t_assert( 3 === $after['stuck_payments'] - $before['stuck_payments'], 'stuck = created_at-old initiated/executing + reconcile_needed, not fresh ones' );
	$ledger_stuck = CC_Admin_Payments::query( array( 'status' => 'stuck', 'per_page' => 1 ) )['total'];
	t_assert( $after['stuck_payments'] === $ledger_stuck, 'dashboard stuck count equals the filtered payments ledger total' );
	t_assert( 1 === $after['active_enrollments'] - $before['active_enrollments'], 'active enrollments counted' );
} finally {
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}cc_audit_log WHERE action = %s", $marker ) );
	if ( null === $original_settings ) {
		delete_option( CC_Admin_Settings::OPTION );
	} else {
		update_option( CC_Admin_Settings::OPTION, $original_settings );
	}
	foreach ( $cleanup['payments'] as $id ) {
		$wpdb->delete( "{$p}cc_payments", array( 'id' => $id ) );
	}
	foreach ( $cleanup['invoices'] as $id ) {
		$wpdb->delete( "{$p}cc_invoices", array( 'id' => $id ) );
	}
	foreach ( $cleanup['apps'] as $id ) {
		$wpdb->delete( "{$p}cc_enrollments", array( 'application_id' => $id ) );
		$wpdb->delete( "{$p}cc_applications", array( 'id' => $id ) );
	}
	foreach ( $cleanup['batches'] as $id ) {
		$wpdb->delete( "{$p}cc_batches", array( 'id' => $id ) );
	}
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( $cleanup['users'] as $u ) {
		wp_delete_user( $u );
	}
}

echo "\n$checks checks, $failures failures\n";
exit( $failures ? 1 : 0 );
