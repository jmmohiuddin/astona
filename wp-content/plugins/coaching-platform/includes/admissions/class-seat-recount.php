<?php
defined( 'ABSPATH' ) || exit;

/**
 * Recomputes cc_batches.seats_taken from the applications, which are the source of truth: a seat is taken by each
 * application with status 'approved' (settlement approves and increments in one transaction). Test runs and manual SQL
 * can leave the counter drifted until the admission form reports "batch full".
 *
 * An approved application holds its seat in the batch of its ENROLLMENT when one exists (staff can move an enrollment to
 * another batch; the application keeps its original batch_id as the admission record) and otherwise in its own batch_id.
 * Enrollment status does not matter: a deactivated enrollment keeps its seat, as before.
 *
 * Seats that exist without an application (the sample batches the seeder creates with some seats already taken) are the
 * baseline in option `cc_seat_baseline` (batch id => seats), recorded right after seeding by `wp cc recount-seats
 * --capture-baseline`. seats_taken = approved applications + baseline.
 *
 * Per batch: transaction, batch row locked (FOR UPDATE, the same lock settlement takes), count, update, commit.
 *
 *   wp cc recount-seats [--batch=<id>] [--dry-run] [--capture-baseline]
 */
final class CC_Seat_Recount {

	const BASELINE_OPTION = 'cc_seat_baseline';
	const MAX_SEATS       = 65535;

	/**
	 * @return array<int,array{batch_id:int,before:int,after:int,approved:int,baseline:int}> One row per batch.
	 */
	public static function run( ?int $batch_id = null, bool $dry_run = false ): array {
		global $wpdb;
		$batches  = CC_Migrations::table();
		$baseline = (array) get_option( self::BASELINE_OPTION, array() );
		$ids      = null === $batch_id ? array_map( 'intval', $wpdb->get_col( "SELECT id FROM {$batches} ORDER BY id" ) ) : array( $batch_id );
		$report   = array();
		foreach ( $ids as $id ) {
			$wpdb->query( 'START TRANSACTION' );
			try {
				$row = $wpdb->get_row( $wpdb->prepare( "SELECT seats_taken FROM {$batches} WHERE id = %d FOR UPDATE", $id ), ARRAY_A );
				if ( null === $row ) {
					$wpdb->query( 'ROLLBACK' );
					continue;
				}
				$approved = self::approved_in_batch( $id );
				$base     = max( 0, (int) ( $baseline[ $id ] ?? 0 ) );
				$after    = min( self::MAX_SEATS, $approved + $base );
				if ( ! $dry_run && (int) $row['seats_taken'] !== $after ) {
					$wpdb->update( $batches, array( 'seats_taken' => $after ), array( 'id' => $id ) );
				}
				$wpdb->query( 'COMMIT' );
			} catch ( Throwable $e ) {
				$wpdb->query( 'ROLLBACK' );
				throw $e;
			}
			$report[] = array( 'batch_id' => $id, 'before' => (int) $row['seats_taken'], 'after' => $after, 'approved' => $approved, 'baseline' => $base );
		}
		return $report;
	}

	/** Approved applications holding a seat in the batch: unmoved ones plus those whose enrollment was moved in. */
	private static function approved_in_batch( int $batch_id ): int {
		global $wpdb;
		$apps = $wpdb->prefix . 'cc_applications';
		$enr  = $wpdb->prefix . 'cc_enrollments';
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names only.
		$staying = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$apps} a WHERE a.batch_id = %d AND a.status = 'approved'
				AND NOT EXISTS (SELECT 1 FROM {$enr} e WHERE e.application_id = a.id AND e.batch_id <> %d)",
				$batch_id,
				$batch_id
			)
		);
		$moved_in = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$enr} e JOIN {$apps} a ON a.id = e.application_id WHERE e.batch_id = %d AND a.batch_id <> %d AND a.status = 'approved'",
				$batch_id,
				$batch_id
			)
		);
		// phpcs:enable
		return $staying + $moved_in;
	}

	/**
	 * Records, for every batch, the seats that no application accounts for (seats_taken minus approved applications, never
	 * negative). Meant for a freshly seeded database; on a drifted one it would record the drift as baseline.
	 *
	 * @return array<int,int>
	 */
	public static function capture_baseline(): array {
		global $wpdb;
		$rows     = $wpdb->get_results( 'SELECT id, seats_taken FROM ' . CC_Migrations::table(), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL -- table name only.
		$baseline = array();
		foreach ( (array) $rows as $row ) {
			$baseline[ (int) $row['id'] ] = max( 0, (int) $row['seats_taken'] - self::approved_in_batch( (int) $row['id'] ) );
		}
		update_option( self::BASELINE_OPTION, $baseline, false );
		return $baseline;
	}

	/** WP-CLI entry point. @param string[] $args @param array<string,string> $assoc */
	public static function cli( array $args, array $assoc ): void {
		if ( isset( $assoc['capture-baseline'] ) ) {
			WP_CLI::success( 'Seat baseline recorded: ' . wp_json_encode( self::capture_baseline() ) );
			return;
		}
		$dry    = isset( $assoc['dry-run'] );
		$batch  = isset( $assoc['batch'] ) ? absint( $assoc['batch'] ) : null;
		$report = self::run( $batch, $dry );
		if ( array() === $report ) {
			WP_CLI::error( 'No such batch.' );
		}
		WP_CLI\Utils\format_items( 'table', $report, array( 'batch_id', 'before', 'after', 'approved', 'baseline' ) );
		$changed = count( array_filter( $report, static fn( array $r ): bool => $r['before'] !== $r['after'] ) );
		WP_CLI::success( sprintf( '%s %d of %d batch(es).', $dry ? 'Dry run: would correct' : 'Corrected', $changed, count( $report ) ) );
	}
}
