<?php
/**
 * Integration tests for CC_Seat_Recount. Run inside the wpcli container:
 *   docker compose run --rm -T wpcli eval-file /tests/integration/seat-recount-test.php
 * Works on throwaway batches and applications only (the real batches are touched by a dry run at most) and restores the
 * seat baseline option afterwards.
 */
global $wpdb, $failures, $checks, $cleanup;
$p        = $wpdb->prefix;
$failures = 0;
$checks   = 0;
$cleanup  = array( 'batches' => array() );
$saved_baseline = get_option( CC_Seat_Recount::BASELINE_OPTION, null );

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

function t_batch( int $capacity, int $seats ): int {
	global $wpdb, $cleanup;
	$now = gmdate( 'Y-m-d H:i:s' );
	$wpdb->insert( "{$wpdb->prefix}cc_batches", array(
		'course_id' => 0, 'name' => 'ZZ-TEST ' . bin2hex( random_bytes( 6 ) ), 'capacity' => $capacity, 'seats_taken' => $seats,
		'price' => 100, 'start_date' => '2030-01-01', 'status' => 'open', 'application_open' => 1, 'created_at' => $now, 'updated_at' => $now,
	) );
	$cleanup['batches'][] = (int) $wpdb->insert_id;
	return (int) $wpdb->insert_id;
}

function t_app( int $batch, string $status ): void {
	global $wpdb;
	$now = gmdate( 'Y-m-d H:i:s' );
	$wpdb->insert( "{$wpdb->prefix}cc_applications", array(
		'public_ref' => strtoupper( substr( bin2hex( random_bytes( 13 ) ), 0, 26 ) ), 'idempotency_key' => wp_generate_uuid4(), 'batch_id' => $batch,
		'student_phone' => '+8801700000000', 'full_name' => 'ZZ Test', 'gender' => 'o', 'dob' => '2008-01-01',
		'id_doc_type' => 'nid', 'id_doc_enc' => 'x', 'guardian_name' => 'G', 'guardian_phone' => '+8801700000001',
		'institution' => 'I', 'class_level' => '10', 'consent_at' => $now, 'status' => $status, 'created_at' => $now, 'updated_at' => $now,
	) );
}

function t_seats( int $batch ): int {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT seats_taken FROM {$wpdb->prefix}cc_batches WHERE id = %d", $batch ) );
}

try {
	update_option( CC_Seat_Recount::BASELINE_OPTION, array(), false );

	echo "recount from approved applications\n";
	$drifted = t_batch( 10, 10 );
	foreach ( array( 'approved', 'approved', 'pending', 'rejected', 'cancelled', 'waitlisted' ) as $status ) {
		t_app( $drifted, $status );
	}
	$untouched = t_batch( 10, 3 );
	$report    = CC_Seat_Recount::run( $drifted, true );
	t_assert( 1 === count( $report ) && array( 'batch_id' => $drifted, 'before' => 10, 'after' => 2, 'approved' => 2, 'baseline' => 0 ) === $report[0], 'dry run reports before 10, after 2 (only approved count)' );
	t_assert( 10 === t_seats( $drifted ), 'dry run changes nothing' );
	$report = CC_Seat_Recount::run( $drifted );
	t_assert( 2 === t_seats( $drifted ) && 2 === $report[0]['after'], 'recount sets seats_taken to the approved count' );
	t_assert( 3 === t_seats( $untouched ), 'a batch that was not asked for is left alone' );
	t_assert( array( 'batch_id' => $drifted, 'before' => 2, 'after' => 2, 'approved' => 2, 'baseline' => 0 ) === CC_Seat_Recount::run( $drifted )[0], 'running again is a no-op' );
	$by_id = array_column( CC_Seat_Recount::run( null, true ), null, 'batch_id' );
	t_assert( isset( $by_id[ $drifted ], $by_id[ $untouched ] ) && 0 === $by_id[ $untouched ]['after'] && 3 === $by_id[ $untouched ]['before'], 'all-batches dry run lists every batch with before/after' );
	t_assert( 3 === t_seats( $untouched ), 'all-batches dry run changes nothing' );
	t_assert( array() === CC_Seat_Recount::run( 999999999 ), 'an unknown batch yields an empty report' );
	$empty = t_batch( 5, 5 );
	CC_Seat_Recount::run( $empty );
	t_assert( 0 === t_seats( $empty ), 'a batch without approved applications drops to 0' );

	echo "seeded baseline\n";
	$seeded = t_batch( 30, 12 );
	t_app( $seeded, 'approved' );
	t_app( $seeded, 'approved' );
	$baseline = CC_Seat_Recount::capture_baseline();
	t_assert( 10 === $baseline[ $seeded ], 'capture_baseline records seats that no approved application accounts for' );
	$wpdb->update( "{$p}cc_batches", array( 'seats_taken' => 30 ), array( 'id' => $seeded ) );
	$report = CC_Seat_Recount::run( $seeded );
	t_assert( 12 === t_seats( $seeded ) && 10 === $report[0]['baseline'], 'recount keeps the baseline: 10 seeded + 2 approved' );
	t_assert( 0 === CC_Seat_Recount::capture_baseline()[ $empty ], 'baseline is never negative' );
} finally {
	if ( null === $saved_baseline ) {
		delete_option( CC_Seat_Recount::BASELINE_OPTION );
	} else {
		update_option( CC_Seat_Recount::BASELINE_OPTION, $saved_baseline, false );
	}
	foreach ( $cleanup['batches'] as $batch_id ) {
		$wpdb->delete( "{$p}cc_applications", array( 'batch_id' => $batch_id ) );
		$wpdb->delete( "{$p}cc_batches", array( 'id' => $batch_id ) );
	}
}

echo "\n$checks checks, $failures failed\n";
exit( $failures ? 1 : 0 );
