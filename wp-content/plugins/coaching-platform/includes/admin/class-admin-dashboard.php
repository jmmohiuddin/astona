<?php
defined( 'ABSPATH' ) || exit;

/** Landing screen: operational counters for staff, a reduced notice for instructors. */
final class CC_Admin_Dashboard {

	const RECENT_AUDIT  = 5;

	/** The plugin loader calls init() on every admin class; the menu wires render(), so there is nothing to hook. */
	public static function init(): void {}

	/** @return array<string,int|float> */
	public static function stats(): array {
		global $wpdb;
		$p       = $wpdb->prefix;
		$now     = time();
		$today   = ( new DateTimeImmutable( 'today', wp_timezone() ) )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
		$week    = gmdate( 'Y-m-d H:i:s', $now - 7 * DAY_IN_SECONDS );
		$month   = gmdate( 'Y-m-d H:i:s', $now - 30 * DAY_IN_SECONDS );
		$revenue = "SELECT COALESCE(SUM(amount),0) FROM {$p}cc_payments WHERE status = 'completed'";

		$count = static fn( string $sql, array $args = array() ): int => (int) ( $args ? $wpdb->get_var( $wpdb->prepare( $sql, $args ) ) : $wpdb->get_var( $sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$money = static fn( string $since ): float => (float) $wpdb->get_var( $wpdb->prepare( $revenue . ' AND COALESCE(settled_at, created_at) >= %s', $since ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array(
			'pending_applications' => $count( "SELECT COUNT(*) FROM {$p}cc_applications WHERE status = 'pending'" ),
			'waitlisted'           => $count( "SELECT COUNT(*) FROM {$p}cc_applications WHERE status = 'waitlisted'" ),
			'balances_due'         => (float) $wpdb->get_var( "SELECT COALESCE(SUM(amount - amount_paid),0) FROM {$p}cc_invoices WHERE status = 'partial'" ),
			'balances_overdue'     => $count( "SELECT COUNT(*) FROM {$p}cc_invoices WHERE status = 'partial' AND due_at IS NOT NULL AND due_at < UTC_TIMESTAMP()" ),
			'applications_today'   => $count( "SELECT COUNT(*) FROM {$p}cc_applications WHERE created_at >= %s", array( $today ) ),
			'applications_7d'      => $count( "SELECT COUNT(*) FROM {$p}cc_applications WHERE created_at >= %s", array( $week ) ),
			'revenue_today'        => $money( $today ),
			'revenue_30d'          => $money( $month ),
			'revenue_total'        => (float) $wpdb->get_var( $revenue ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			'active_enrollments'   => $count( "SELECT COUNT(*) FROM {$p}cc_enrollments WHERE status = 'active'" ),
			'new_inquiries'        => $count( "SELECT COUNT(*) FROM {$p}cc_inquiries WHERE status = 'new'" ),
			'stuck_payments'       => $count(
				'SELECT COUNT(*) FROM ' . $p . 'cc_payments pay WHERE ' . CC_Admin_Payments::stuck_sql(),
				array( CC_Admin_Payments::stuck_cutoff( $now ) )
			),
		);
	}

	public static function render(): void {
		if ( ! current_user_can( CC_Admin_Roles::CAP_VIEW_DASHBOARD ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'coaching-platform' ), 403 );
		}
		echo '<div class="wrap"><h1>Astona dashboard</h1>';

		$can_apps  = current_user_can( CC_Admin_Roles::CAP_REVIEW_APPLICATIONS );
		$can_money = current_user_can( CC_Admin_Roles::CAP_VIEW_PAYMENTS );
		$can_inq   = current_user_can( CC_Admin_Roles::CAP_VIEW_INQUIRIES );
		if ( ! $can_apps && ! $can_money && ! $can_inq ) {
			echo '<p>Welcome. Your batch schedule and notices will appear here.</p></div>';
			return;
		}

		$s = self::stats();
		echo '<div class="cc-cards">';
		if ( $can_apps ) {
			$link = add_query_arg( array( 'page' => 'cc-applications', 'status' => 'pending' ), admin_url( 'admin.php' ) );
			self::card( 'Pending applications', (string) $s['pending_applications'], '', $link, $s['pending_applications'] > 0 );
			$wl_link = add_query_arg( array( 'page' => 'cc-applications', 'status' => 'waitlisted' ), admin_url( 'admin.php' ) );
			self::card( 'Waitlisted', (string) $s['waitlisted'], 'Offer seats from the application page', $wl_link, $s['waitlisted'] > 0 );
			self::card( 'Applications today', (string) $s['applications_today'], 'Last 7 days: ' . $s['applications_7d'] );
		}
		if ( $can_money ) {
			self::card( 'Revenue today', self::money( $s['revenue_today'] ), '30 days: ' . self::money( $s['revenue_30d'] ) . ' / total: ' . self::money( $s['revenue_total'] ) );
			self::card( 'Balances due', self::money( $s['balances_due'] ), $s['balances_overdue'] . ' overdue', '', $s['balances_overdue'] > 0 );
			$link = add_query_arg( array( 'page' => 'cc-payments', 'status' => 'stuck' ), admin_url( 'admin.php' ) );
			self::card( 'Stuck payments', (string) $s['stuck_payments'], 'Initiated/executing over ' . CC_Admin_Payments::STUCK_MINUTES . ' min, or needs reconcile', $link, $s['stuck_payments'] > 0 );
		}
		if ( $can_inq ) {
			$link = add_query_arg( array( 'page' => 'cc-inquiries', 'status' => 'new' ), admin_url( 'admin.php' ) );
			self::card( 'New inquiries', (string) $s['new_inquiries'], '', $link, $s['new_inquiries'] > 0 );
		}
		self::card( 'Active enrollments', (string) $s['active_enrollments'] );
		echo '</div>';

		if ( current_user_can( CC_Admin_Roles::CAP_VIEW_AUDIT ) ) {
			self::render_recent_audit();
		}
		echo '</div>';
	}

	private static function render_recent_audit(): void {
		$recent = CC_Audit::query( array( 'per_page' => self::RECENT_AUDIT ) );
		echo '<div class="cc-panel"><h2>Recent activity</h2><table class="cc-table">';
		foreach ( $recent['items'] as $row ) {
			$user = get_userdata( (int) $row['actor_id'] );
			printf(
				'<tr><td>%s</td><td>%s</td><td>%s #%d</td><td>%s</td></tr>',
				esc_html( (string) $row['created_at'] ),
				esc_html( $user ? $user->display_name : '#' . (int) $row['actor_id'] ),
				esc_html( (string) $row['entity_type'] ),
				(int) $row['entity_id'],
				esc_html( (string) $row['action'] )
			);
		}
		if ( ! $recent['items'] ) {
			echo '<tr><td>No activity yet.</td></tr>';
		}
		echo '</table></div>';
	}

	private static function money( float $amount ): string {
		return '৳ ' . number_format_i18n( $amount );
	}

	private static function card( string $title, string $value, string $meta = '', string $link = '', bool $alert = false ): void {
		$value_html = esc_html( $value );
		if ( '' !== $link ) {
			$value_html = sprintf( '<a href="%s">%s</a>', esc_url( $link ), $value_html );
		}
		printf(
			'<div class="cc-card%s"><h3>%s</h3><div class="cc-card__value">%s</div>%s</div>',
			$alert ? ' cc-card--alert' : '',
			esc_html( $title ),
			$value_html, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
			'' === $meta ? '' : '<p class="cc-card__meta">' . esc_html( $meta ) . '</p>'
		);
	}
}
