<?php
defined( 'ABSPATH' ) || exit;

/**
 * One daily job hook, `cc_daily`, that the policy modules attach to (waitlist offers, installment reminders, data
 * retention). Driven by Action Scheduler when present, else WP-Cron, exactly like the payment reconciler.
 */
final class CC_Daily_Jobs {

	const HOOK = 'cc_daily';

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'schedule' ), 20 );
		add_action( self::HOOK, array( 'CC_Waitlist', 'expire_offers' ) );
		add_action( self::HOOK, array( 'CC_Waitlist', 'fill_all' ), 20 );
		// A refunded seat goes to the next person on the waitlist at once, not at the next daily run.
		add_action( 'cc_seat_released', array( 'CC_Waitlist', 'fill' ) );
	}

	public static function schedule(): void {
		if ( function_exists( 'as_schedule_recurring_action' ) ) {
			if ( false === as_next_scheduled_action( self::HOOK ) ) {
				as_schedule_recurring_action( time() + HOUR_IN_SECONDS, DAY_IN_SECONDS, self::HOOK, array(), 'cc' );
			}
			return;
		}
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK );
		}
	}
}
