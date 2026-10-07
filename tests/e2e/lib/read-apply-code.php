<?php
/**
 * Dev/test helper (wpcli eval-file): delivers the newest queued admission verification SMS for a phone through the
 * fake SMS driver (cron may already have) and prints "CODE:<6 digits>" read from the fake outbox.
 *   docker compose run --rm -T wpcli eval-file /tests/e2e/lib/read-apply-code.php +8801XXXXXXXXX
 */
global $wpdb;
$to = (string) ( $args[0] ?? '' );
$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}cc_sms_log WHERE to_phone = %s AND template = 'apply_otp' ORDER BY id DESC LIMIT 1", $to ) );
if ( ! $id ) {
	return;
}
CC_Sms::send( $id );
$mine = array_values( array_filter( CC_Sms_Fake_Driver::outbox(), static fn( $m ) => $m['to'] === $to ) );
$body = $mine ? (string) end( $mine )['body'] : '';
if ( 1 === preg_match( '/application is (\d{6})/', $body, $m ) ) {
	echo 'CODE:' . $m[1] . "\n";
}
