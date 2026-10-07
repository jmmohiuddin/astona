<?php
defined( 'ABSPATH' ) || exit;

/**
 * Computed availability chip (FR-001): Open / Filling Fast / Waitlist / Closed.
 * Pure functions, no WordPress calls, unit-testable.
 */
final class CC_Status_Chip {

	const OPEN    = 'open';
	const FILLING = 'filling';
	const WAITLIST = 'waitlist';
	const CLOSED  = 'closed';

	/** Assumption A-07: "Filling Fast" at >= 80% of capacity. */
	const FILLING_THRESHOLD = 0.8;

	public static function for_batch( string $status, bool $application_open, int $capacity, int $seats_taken, bool $waitlist = false ): string {
		if ( 'open' !== $status || ! $application_open || $capacity <= 0 ) {
			return self::CLOSED;
		}
		if ( $seats_taken >= $capacity ) {
			return $waitlist ? self::WAITLIST : self::CLOSED;
		}
		return ( $seats_taken / $capacity ) >= self::FILLING_THRESHOLD ? self::FILLING : self::OPEN;
	}

	/** @param array<string,mixed> $batch A cc_batches row. */
	public static function for_row( array $batch ): string {
		return self::for_batch( (string) $batch['status'], (bool) $batch['application_open'], (int) $batch['capacity'], (int) $batch['seats_taken'], ! empty( $batch['waitlist_enabled'] ) );
	}

	/**
	 * Best chip across batches: any Open batch wins, else Filling, else Closed.
	 *
	 * @param array<int,array<string,mixed>> $batches Rows from cc_batches.
	 */
	public static function for_batches( array $batches ): string {
		$best = self::CLOSED;
		foreach ( $batches as $batch ) {
			$chip = self::for_row( $batch );
			if ( self::OPEN === $chip ) {
				return self::OPEN;
			}
			if ( self::FILLING === $chip ) {
				$best = self::FILLING;
			} elseif ( self::WAITLIST === $chip && self::CLOSED === $best ) {
				$best = self::WAITLIST;
			}
		}
		return $best;
	}

	public static function label( string $chip ): string {
		$labels = array(
			self::OPEN    => 'Open · ভর্তি চলছে',
			self::FILLING => 'Filling Fast · দ্রুত পূর্ণ হচ্ছে',
			self::WAITLIST => 'Full · Waitlist open · অপেক্ষমাণ তালিকা',
			self::CLOSED  => 'Closed · ভর্তি বন্ধ',
		);
		return $labels[ $chip ] ?? $labels[ self::CLOSED ];
	}
}
