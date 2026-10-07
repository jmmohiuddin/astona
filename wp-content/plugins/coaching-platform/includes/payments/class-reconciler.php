<?php
defined( 'ABSPATH' ) || exit;

/** Settles payments whose callback never arrived and cancels ones that were abandoned. */
final class CC_Reconciler {

	const HOOK           = 'cc_reconcile';
	/** Unix time of the last run; the health endpoint reads it as the scheduler heartbeat. */
	const HEARTBEAT_OPTION = 'cc_reconcile_heartbeat';
	const SCHEDULE       = 'cc_every_minute';
	const STALE_SECONDS  = 180;
	const CANCEL_SECONDS = 1800;
	const BATCH_LIMIT    = 50;
	/** Own cap for the provisioning recovery sweep, independent of BATCH_LIMIT. */
	const RECOVERY_LIMIT   = 20;
	/** An approved application without a user younger than this may still be mid-hand-off to the provisioner. */
	const RECOVERY_SECONDS = 120;

	/**
	 * How long after creation a cancelled payment is still re-queried in case the payer paid late.
	 * TODO: verify against the real gateway's session expiry before go-live.
	 */
	const LATE_PAYMENT_WINDOW = DAY_IN_SECONDS;
	/** Minimum gap between late-payment checks of one payment (tracked via updated_at). */
	const LATE_POLL_INTERVAL = 600;

	public static function init(): void {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_filter( 'cron_schedules', array( __CLASS__, 'add_schedule' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ), 20 );
	}

	public static function add_schedule( array $schedules ): array {
		$schedules[ self::SCHEDULE ] = array(
			'interval' => MINUTE_IN_SECONDS,
			'display'  => 'Every minute',
		);
		return $schedules;
	}

	public static function schedule(): void {
		if ( function_exists( 'as_schedule_recurring_action' ) ) {
			if ( false === as_next_scheduled_action( self::HOOK ) ) {
				as_schedule_recurring_action( time(), MINUTE_IN_SECONDS, self::HOOK, array(), 'cc' );
			}
			return;
		}
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, self::SCHEDULE, self::HOOK );
		}
	}

	/** @return int Number of payments examined. */
	public static function run(): int {
		global $wpdb;
		update_option( self::HEARTBEAT_OPTION, time(), false );
		$gateway_id = CC_Gateway_Factory::make()->id();
		$cutoff     = gmdate( 'Y-m-d H:i:s', time() - self::STALE_SECONDS );

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}cc_payments
				WHERE status IN ('initiated','executing') AND gateway = %s AND updated_at < %s
				ORDER BY id ASC LIMIT %d",
				$gateway_id,
				$cutoff,
				self::BATCH_LIMIT
			)
		);

		$processed = 0;
		foreach ( $ids as $id ) {
			try {
				$outcome = CC_Settlement::settle( (int) $id, 'poll' );
				if ( 'not_completed' === $outcome['result'] ) {
					self::cancel_if_abandoned( (int) $id );
				}
			} catch ( Throwable $e ) {
				error_log( sprintf( 'CC_Reconciler: payment %d failed: %s', (int) $id, $e->getMessage() ) );
			}
			++$processed;
		}
		$processed += self::recheck_cancelled( $gateway_id, self::BATCH_LIMIT - count( $ids ) );
		return $processed + self::recover_unprovisioned();
	}

	/**
	 * Re-queues provisioning for approved applications that never got a user, e.g. when the process died between
	 * the settlement COMMIT and do_action('cc_application_settled'). Provisioning is idempotent, so this is safe.
	 *
	 * @return int Number of applications examined.
	 */
	public static function recover_unprovisioned(): int {
		global $wpdb;
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}cc_applications
				WHERE status = 'approved' AND user_id IS NULL AND updated_at < %s
				ORDER BY created_at ASC LIMIT %d",
				gmdate( 'Y-m-d H:i:s', time() - self::RECOVERY_SECONDS ),
				self::RECOVERY_LIMIT
			)
		);
		foreach ( $ids as $id ) {
			try {
				CC_Provisioner::ensure_enqueued( (int) $id );
			} catch ( Throwable $e ) {
				error_log( sprintf( 'CC_Reconciler: provisioning recovery for application %d failed: %s', (int) $id, $e->getMessage() ) );
			}
		}
		return count( $ids );
	}

	/** Re-queries recently cancelled payments; settle() moves them out of 'cancelled' if the gateway reports completion. */
	private static function recheck_cancelled( string $gateway_id, int $limit ): int {
		global $wpdb;
		if ( $limit <= 0 ) {
			return 0;
		}
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}cc_payments
				WHERE status = 'cancelled' AND gateway = %s AND gateway_payment_id <> ''
				AND created_at >= %s AND updated_at < %s
				ORDER BY updated_at ASC LIMIT %d",
				$gateway_id,
				gmdate( 'Y-m-d H:i:s', time() - self::LATE_PAYMENT_WINDOW ),
				gmdate( 'Y-m-d H:i:s', time() - self::LATE_POLL_INTERVAL ),
				$limit
			)
		);

		foreach ( $ids as $id ) {
			try {
				CC_Settlement::settle( (int) $id, 'poll' );
				// Still cancelled means not paid: push the next check out by LATE_POLL_INTERVAL.
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE {$wpdb->prefix}cc_payments SET updated_at = %s WHERE id = %d AND status = 'cancelled'",
						gmdate( 'Y-m-d H:i:s' ),
						(int) $id
					)
				);
			} catch ( Throwable $e ) {
				error_log( sprintf( 'CC_Reconciler: late recheck of payment %d failed: %s', (int) $id, $e->getMessage() ) );
			}
		}
		return count( $ids );
	}

	private static function cancel_if_abandoned( int $payment_id ): void {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}cc_payments SET status = 'cancelled', updated_at = %s
				WHERE id = %d AND status IN ('initiated','executing') AND created_at < %s",
				gmdate( 'Y-m-d H:i:s' ),
				$payment_id,
				gmdate( 'Y-m-d H:i:s', time() - self::CANCEL_SECONDS )
			)
		);
	}
}
