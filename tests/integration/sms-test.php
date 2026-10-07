<?php
/**
 * Integration tests for CC_Sms. Run inside the wpcli container:
 *   docker compose run --rm -T wpcli eval-file /tests/integration/sms-test.php
 * Writes cc_sms_log rows and fake-outbox entries for unique recipients; log rows are removed afterwards.
 */
global $wpdb, $failures, $checks, $log_ids;
$failures = 0;
$checks   = 0;
$log_ids  = array();

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

function t_phone(): string {
	return '+88017' . str_pad( (string) random_int( 0, 99999999 ), 8, '0', STR_PAD_LEFT );
}

/** True when CC_Sms schedules through Action Scheduler (mirrors CC_Sms::schedule()); otherwise it uses WP-Cron. */
function t_uses_as(): bool {
	return function_exists( 'as_enqueue_async_action' ) && function_exists( 'as_schedule_single_action' );
}

/** Next scheduled send for a log id on the active backend: timestamp, true (async/running), or false. */
function t_next_send( int $id ) {
	if ( t_uses_as() ) {
		$next = as_next_scheduled_action( CC_Sms::HOOK, array( $id ), CC_Sms::GROUP );
		return null === $next ? false : $next;
	}
	return wp_next_scheduled( CC_Sms::HOOK, array( $id ) );
}

/** Pending Action Scheduler action ids for a log id (empty on the WP-Cron backend). @return int[] */
function t_pending_actions( int $id ): array {
	if ( ! t_uses_as() ) {
		return array();
	}
	return array_map( 'intval', as_get_scheduled_actions( array( 'hook' => CC_Sms::HOOK, 'args' => array( $id ), 'group' => CC_Sms::GROUP, 'status' => ActionScheduler_Store::STATUS_PENDING ), 'ids' ) );
}

/** Removes any scheduled send for a log id on both backends. */
function t_unschedule( int $id ): void {
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( CC_Sms::HOOK, array( $id ), CC_Sms::GROUP );
	}
	wp_clear_scheduled_hook( CC_Sms::HOOK, array( $id ) );
}

/** Queues a message and removes its scheduled send so only this test drives sending. */
function t_queue( string $phone, string $template, array $vars ): int {
	global $log_ids;
	$id         = CC_Sms::queue( $phone, $template, $vars, 'test', 7 );
	$log_ids[]  = $id;
	t_unschedule( $id );
	return $id;
}

function t_row( int $id ): array {
	global $wpdb;
	return (array) $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}cc_sms_log WHERE id = %d", $id ), ARRAY_A );
}

function t_outbox_for( string $phone ): array {
	return array_values( array_filter( CC_Sms_Fake_Driver::outbox(), static fn( $m ) => $phone === $m['to'] ) );
}

/** Echoes the body in its error to prove secrets are redacted before storage. */
final class T_Failing_Driver implements CC_Sms_Driver {
	public function id(): string {
		return 'failing';
	}
	public function send( string $to_e164, string $body ): array {
		return array( false, '', 'rejected: ' . $body );
	}
}

CC_Sms_Factory::register_driver( 'failing', static fn() => new T_Failing_Driver() );
CC_Sms_Factory::register_driver( 'throwing', static fn() => new class() implements CC_Sms_Driver {
	public function id(): string {
		return 'throwing';
	}
	public function send( string $to_e164, string $body ): array {
		throw new RuntimeException( 'boom ' . $body );
	}
} );

echo "queue -> send via fake driver\n";
CC_Sms_Factory::override_name( 'primary', 'fake' );
CC_Sms_Factory::override_name( 'fallback', null );
$phone = t_phone();
$id    = t_queue( $phone, 'otp', array( 'code' => '482913' ) );
$row   = t_row( $id );
t_assert( 'queued' === $row['status'] && '0' === $row['attempts'], 'row starts queued with 0 attempts' );
t_assert( $phone === $row['to_phone'] && 'test' === $row['related_type'] && '7' === $row['related_id'], 'recipient and related fields stored' );
t_assert( false !== get_transient( CC_Sms::PAYLOAD_PREFIX . $id ), 'secret payload held in a transient while queued' );
t_assert( 'sent' === CC_Sms::send( $id ), 'send() reports sent' );
$row = t_row( $id );
t_assert( 'sent' === $row['status'] && 'fake' === $row['provider'] && '1' === $row['attempts'] && null !== $row['sent_at'], 'row marked sent by fake provider' );
t_assert( false === get_transient( CC_Sms::PAYLOAD_PREFIX . $id ), 'secret payload deleted after sending' );
t_assert( 'skipped' === CC_Sms::send( $id ), 'sent message is not sent twice' );

echo "outbox readable\n";
$box = t_outbox_for( $phone );
t_assert( 1 === count( $box ), 'exactly one outbox entry for the recipient' );
t_assert( false !== strpos( $box[0]['body'], '482913' ) && '' !== $box[0]['time'], 'outbox shows the otp body and time' );
t_assert( false === autoload_of( CC_Sms_Fake_Driver::OPTION_OUTBOX ), 'outbox option is not autoloaded' );

function autoload_of( string $option ) {
	global $wpdb;
	$value = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $option ) );
	return in_array( $value, array( 'yes', 'on', 'auto', 'auto-on' ), true ) ? true : false;
}

echo "secrets are not persisted\n";
$cred_phone = t_phone();
$cred_id    = t_queue( $cred_phone, 'credentials', array( 'phone' => $cred_phone, 'password' => 'Zq7Kp3mWx9', 'url' => 'https://astona.test/student/login/' ) );
CC_Sms::send( $cred_id );
foreach ( array( $id => '482913', $cred_id => 'Zq7Kp3mWx9' ) as $log_id => $secret ) {
	$dump = wp_json_encode( t_row( $log_id ) );
	t_assert( false === strpos( $dump, $secret ), "log row #$log_id does not contain the secret" );
	t_assert( null === t_row( $log_id )['body_enc'], "log row #$log_id stores no encrypted body" );
}
t_assert( hash( 'sha256', CC_Sms::render( 'otp', array( 'code' => '482913' ) ) ) !== t_row( $id )['body_hash'], 'otp body_hash is not a hash of the guessable body' );
$cred_box = t_outbox_for( $cred_phone );
t_assert( 1 === count( $cred_box ) && false !== strpos( $cred_box[0]['body'], 'Zq7Kp3mWx9' ), 'credentials still reach the recipient' );

foreach ( array( $id => '482913', $cred_id => 'Zq7Kp3mWx9' ) as $log_id => $secret ) {
	$q_id = t_queue( t_phone(), $log_id === $id ? 'otp' : 'credentials', $log_id === $id ? array( 'code' => $secret ) : array( 'phone' => '+8801700000000', 'password' => $secret, 'url' => 'https://astona.test/' ) );
	$raw  = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name IN (%s, %s)", '_transient_' . CC_Sms::PAYLOAD_PREFIX . $q_id, CC_Sms::PAYLOAD_PREFIX . $q_id ) );
	t_assert( '' !== $raw && false === strpos( $raw, $secret ), 'queued transient option value does not contain the secret' );
}
$tamper_id = t_queue( t_phone(), 'otp', array( 'code' => '908070' ) );
set_transient( CC_Sms::PAYLOAD_PREFIX . $tamper_id, 'k1:garbage', 60 );
t_assert( 'failed' === CC_Sms::send( $tamper_id ), 'undecryptable payload marks the message failed' );
t_assert( false === get_transient( CC_Sms::PAYLOAD_PREFIX . $tamper_id ), 'undecryptable payload is deleted' );

$rec_phone = t_phone();
$rec_id    = t_queue( $rec_phone, 'receipt', array( 'number' => 'INV-1', 'amount' => '1500', 'trx_id' => 'TRX9' ) );
$rec_row   = t_row( $rec_id );
$rec_body  = CC_Sms::render( 'receipt', array( 'number' => 'INV-1', 'amount' => '1500', 'trx_id' => 'TRX9' ) );
t_assert( null !== $rec_row['body_enc'] && false === strpos( $rec_row['body_enc'], 'INV-1' ), 'receipt body is stored encrypted' );
t_assert( hash( 'sha256', $rec_body ) === $rec_row['body_hash'], 'receipt body_hash is sha256 of the body' );
t_assert( 'sent' === CC_Sms::send( $rec_id ) && false !== strpos( t_outbox_for( $rec_phone )[0]['body'], 'INV-1' ), 'receipt decrypts and sends' );

echo "login_hint template\n";
$hint_phone = t_phone();
$hint_url   = 'https://astona.test/student/login/';
$hint_id    = t_queue( $hint_phone, 'login_hint', array( 'url' => $hint_url ) );
$hint_row   = t_row( $hint_id );
t_assert( null !== $hint_row['body_enc'], 'login_hint is a non-secret template, body stored encrypted' );
t_assert( 'sent' === CC_Sms::send( $hint_id ), 'login_hint sends' );
$hint_body = t_outbox_for( $hint_phone )[0]['body'];
t_assert( 'To sign in to Astona, open ' . $hint_url . ' and choose Login with OTP. We will text you a code.' === $hint_body, 'English login_hint text carries the url' );
$hint_bn = CC_Sms::render( 'login_hint', array( 'url' => $hint_url, 'lang' => 'bn' ) );
t_assert( false !== strpos( $hint_bn, $hint_url ) && false === strpos( $hint_bn, '{' ) && $hint_bn !== $hint_body, 'Bangla login_hint renders with the url and no leftover placeholder' );

echo "segments recorded\n";
$bn_phone = t_phone();
$bn_id    = t_queue( $bn_phone, 'enrolled', array( 'course' => 'পদার্থবিজ্ঞান', 'url' => 'https://astona.test/student/', 'lang' => 'bn' ) );
t_assert( (int) t_row( $bn_id )['segments'] >= 1, 'segments column is populated for a Bangla body' );

echo "retry -> fallback\n";
CC_Sms_Factory::override_name( 'primary', 'failing' );
CC_Sms_Factory::override_name( 'fallback', 'fake' );
$fb_phone = t_phone();
$fb_id    = t_queue( $fb_phone, 'otp', array( 'code' => '135790' ) );
t_assert( 'retry' === CC_Sms::send( $fb_id ), 'first primary failure asks for a retry' );
t_assert( 'queued' === t_row( $fb_id )['status'] && '1' === t_row( $fb_id )['attempts'], 'row stays queued with attempts=1' );
t_assert( false === strpos( (string) t_row( $fb_id )['error'], '135790' ) && false !== strpos( (string) t_row( $fb_id )['error'], '***' ), 'driver error is redacted of the otp' );
t_assert( 'retry' === CC_Sms::send( $fb_id ), 'second primary failure asks for a retry' );
t_assert( 'sent' === CC_Sms::send( $fb_id ), 'third primary failure falls back and sends' );
$row = t_row( $fb_id );
t_assert( 'sent' === $row['status'] && 'fake' === $row['provider'] && '3' === $row['attempts'], 'row sent via the fallback provider after 3 attempts' );
t_assert( 1 === count( t_outbox_for( $fb_phone ) ), 'recipient received exactly one message' );

echo "retry -> fallback -> failed\n";
CC_Sms_Factory::override_name( 'primary', 'failing' );
CC_Sms_Factory::override_name( 'fallback', 'throwing' );
$fail_phone = t_phone();
$fail_id    = t_queue( $fail_phone, 'credentials', array( 'phone' => $fail_phone, 'password' => 'Hk4Wn8Rt2P', 'url' => 'https://astona.test/' ) );
CC_Sms::send( $fail_id );
CC_Sms::send( $fail_id );
t_assert( 'failed' === CC_Sms::send( $fail_id ), 'primary x3 then failing fallback ends failed' );
$row = t_row( $fail_id );
t_assert( 'failed' === $row['status'] && '3' === $row['attempts'], 'row marked failed after 3 attempts' );
t_assert( '' !== (string) $row['error'] && false === strpos( wp_json_encode( $row ), 'Hk4Wn8Rt2P' ), 'error recorded and the password is not in the row' );
t_assert( false === get_transient( CC_Sms::PAYLOAD_PREFIX . $fail_id ), 'payload deleted after permanent failure' );
t_assert( 'skipped' === CC_Sms::send( $fail_id ), 'failed message is not retried' );
t_assert( 0 === count( t_outbox_for( $fail_phone ) ), 'nothing reached the outbox' );

CC_Sms_Factory::override_name( 'fallback', null );
$nofb_id = t_queue( t_phone(), 'otp', array( 'code' => '246810' ) );
CC_Sms::send( $nofb_id );
CC_Sms::send( $nofb_id );
t_assert( 'failed' === CC_Sms::send( $nofb_id ), 'no fallback configured ends failed' );
t_assert( false !== strpos( (string) t_row( $nofb_id )['error'], 'no fallback' ) && false === strpos( (string) t_row( $nofb_id )['error'], '246810' ), 'error says no fallback and hides the otp' );

echo "handle() reschedules with backoff (" . ( t_uses_as() ? 'Action Scheduler' : 'WP-Cron' ) . ")\n";
CC_Sms_Factory::override_name( 'primary', 'failing' );
CC_Sms_Factory::override_name( 'fallback', null );
$h_id     = t_queue( t_phone(), 'otp', array( 'code' => '111222' ) );
$h_before = time();
CC_Sms::handle( $h_id );
$h_after  = time();
$next     = t_next_send( $h_id );
t_assert( is_int( $next ) && $next >= $h_before + CC_Sms::RETRY_BACKOFF[0] && $next <= $h_after + CC_Sms::RETRY_BACKOFF[0], 'a retry is scheduled in the future after a failed attempt' );
if ( t_uses_as() ) {
	t_assert( 1 === count( t_pending_actions( $h_id ) ), 'exactly one pending Action Scheduler retry in the cc group with the log id as its only arg' );
	t_assert( false === wp_next_scheduled( CC_Sms::HOOK, array( $h_id ) ), 'no duplicate WP-Cron event when Action Scheduler is active' );
}
t_unschedule( $h_id );
$h_before = time();
CC_Sms::handle( $h_id );
$next     = t_next_send( $h_id );
t_assert( is_int( $next ) && $next >= $h_before + CC_Sms::RETRY_BACKOFF[1] && $next <= time() + CC_Sms::RETRY_BACKOFF[1], 'second failure backs off by the second interval' );
t_unschedule( $h_id );
t_assert( false === t_next_send( 999999999 ), 'no schedule for unknown ids' );
CC_Sms::handle( 999999999 );
t_assert( true, 'handle() on a missing row does not throw' );

echo "factory throws when unset\n";
CC_Sms_Factory::override_name( 'primary', '' );
$threw = false;
try {
	CC_Sms_Factory::primary();
} catch ( RuntimeException $e ) {
	$threw = false !== strpos( $e->getMessage(), 'CC_SMS_PRIMARY' );
}
t_assert( $threw, 'primary() throws RuntimeException naming CC_SMS_PRIMARY' );
$unset_id = t_queue( t_phone(), 'otp', array( 'code' => '333444' ) );
t_assert( 'failed' === CC_Sms::send( $unset_id ), 'send() marks the row failed instead of throwing' );
t_assert( false !== strpos( (string) t_row( $unset_id )['error'], 'CC_SMS_PRIMARY' ), 'failed row carries a clear error' );

echo "fake driver gate\n";
CC_Sms_Factory::override_name( 'primary', 'fake' );
CC_Sms_Fake_Driver::override_environment_type( 'production' );
$gate = false;
try {
	CC_Sms_Factory::primary();
} catch ( RuntimeException $e ) {
	$gate = true;
}
CC_Sms_Fake_Driver::override_environment_type( null );
t_assert( $gate, 'fake driver is refused outside local/development' );

echo "queue -> schedule\n";
CC_Sms_Factory::override_name( 'primary', 'fake' );
$s_id = CC_Sms::queue( t_phone(), 'otp', array( 'code' => '555666' ) );
$log_ids[] = $s_id;
t_assert( false !== t_next_send( $s_id ), 'queue() schedules the send action' );
t_assert( true === (bool) has_action( CC_Sms::HOOK ), 'handler is registered on the send hook' );

echo "Action Scheduler runs the queued send\n";
if ( ! t_uses_as() ) {
	echo "  skip Action Scheduler is not active; WP-Cron backend covered above\n";
} else {
	$runner = ActionScheduler::runner();
	$store  = ActionScheduler::store();
	$run    = static function ( int $log_id ) use ( $runner ): array {
		$ids = t_pending_actions( $log_id );
		foreach ( $ids as $action_id ) {
			$runner->process_action( $action_id, 'cc-sms-test' );
		}
		return $ids;
	};

	CC_Sms_Factory::override_name( 'primary', 'fake' );
	CC_Sms_Factory::override_name( 'fallback', null );
	$as_phone  = t_phone();
	$as_id     = CC_Sms::queue( $as_phone, 'otp', array( 'code' => '777888' ), 'test', 7 );
	$log_ids[] = $as_id;
	$as_ids    = t_pending_actions( $as_id );
	t_assert( 1 === count( $as_ids ) && true === t_next_send( $as_id ), 'queue() enqueues one async action in the cc group' );
	$run( $as_id );
	t_assert( ActionScheduler_Store::STATUS_COMPLETE === $store->get_status( $as_ids[0] ), 'the action completes when run by Action Scheduler' );
	t_assert( 'sent' === t_row( $as_id )['status'] && 'fake' === t_row( $as_id )['provider'], 'the handler sent the row via the fake driver' );
	$as_box = t_outbox_for( $as_phone );
	t_assert( 1 === count( $as_box ) && false !== strpos( $as_box[0]['body'], '777888' ), 'the recipient got exactly one message' );
	t_assert( array() === t_pending_actions( $as_id ), 'nothing left pending after a successful send' );

	CC_Sms_Factory::override_name( 'primary', 'failing' );
	CC_Sms_Factory::override_name( 'fallback', 'fake' );
	$ar_phone  = t_phone();
	$ar_id     = CC_Sms::queue( $ar_phone, 'receipt', array( 'number' => 'INV-AS', 'amount' => '10', 'trx_id' => 'TRXAS' ), 'test', 7 );
	$log_ids[] = $ar_id;
	$run( $ar_id );
	t_assert( 'queued' === t_row( $ar_id )['status'] && 1 === count( t_pending_actions( $ar_id ) ) && t_next_send( $ar_id ) > time(), 'failed attempt via Action Scheduler leaves one future retry' );
	$run( $ar_id );
	t_assert( '2' === t_row( $ar_id )['attempts'] && 1 === count( t_pending_actions( $ar_id ) ), 'second retry is scheduled by the Action Scheduler run' );
	$run( $ar_id );
	t_assert( 'sent' === t_row( $ar_id )['status'] && 'fake' === t_row( $ar_id )['provider'] && '3' === t_row( $ar_id )['attempts'], 'third run falls back and sends' );
	t_assert( 1 === count( t_outbox_for( $ar_phone ) ) && array() === t_pending_actions( $ar_id ), 'one message delivered and no retry left pending' );
}

foreach ( $log_ids as $log_id ) {
	t_unschedule( $log_id );
	delete_transient( CC_Sms::PAYLOAD_PREFIX . $log_id );
	$wpdb->delete( $wpdb->prefix . 'cc_sms_log', array( 'id' => $log_id ) );
}
CC_Sms_Factory::override_name( 'primary', null );
CC_Sms_Factory::override_name( 'fallback', null );

echo "\n$checks checks, $failures failed\n";
exit( $failures > 0 ? 1 : 0 );
