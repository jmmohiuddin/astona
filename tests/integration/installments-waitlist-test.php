<?php
/**
 * Integration tests for two-part payment (CC_Installments + settlement/refund kinds), the waitlist (CC_Waitlist) and
 * refunds through the gateway. Run: docker compose run --rm -T wpcli eval-file /tests/integration/installments-waitlist-test.php
 * Inserts fixtures and removes them afterwards.
 */
global $wpdb, $failures, $checks, $made;
$p        = $wpdb->prefix;
$failures = 0;
$checks   = 0;
$made     = array( 'batches' => array(), 'apps' => array(), 'users' => array(), 'gpids' => array() );
putenv( 'CC_RATE_LIMIT_DISABLED=1' );

function t_assert( bool $cond, string $label ): void {
	global $failures, $checks;
	++$checks;
	echo ( $cond ? '  ok   ' : '  FAIL ' ) . "$label\n";
	if ( ! $cond ) {
		++$failures;
	}
}

$hooks = array( 'settled' => 0, 'balance' => 0, 'seat' => array() );
add_action( 'cc_application_settled', static function () use ( &$hooks ) { ++$hooks['settled']; } );
add_action( 'cc_balance_settled', static function () use ( &$hooks ) { ++$hooks['balance']; } );
add_action( 'cc_seat_released', static function ( $b ) use ( &$hooks ) { $hooks['seat'][] = (int) $b; } );
// Provisioning and SMS are asynchronous in real life; this test drives the pieces directly.
remove_all_actions( 'cc_application_settled' );
add_action( 'cc_application_settled', static function () use ( &$hooks ) { ++$hooks['settled']; } );

function t_batch( array $o = array() ): int {
	global $wpdb, $made;
	$now = gmdate( 'Y-m-d H:i:s' );
	$wpdb->insert( $wpdb->prefix . 'cc_batches', array_merge( array(
		'course_id' => 0, 'name' => 'ZZ-IW ' . bin2hex( random_bytes( 3 ) ), 'capacity' => 5, 'seats_taken' => 0, 'price' => 10000, 'start_date' => '2030-01-01',
		'status' => 'open', 'application_open' => 1, 'created_at' => $now, 'updated_at' => $now,
	), $o ) );
	$made['batches'][] = (int) $wpdb->insert_id;
	return (int) $wpdb->insert_id;
}

/** @return array|WP_Error application row with invoice */
function t_apply( int $batch, string $plan = 'full' ) {
	global $made;
	$phone = '+88017' . str_pad( (string) random_int( 0, 99999999 ), 8, '0', STR_PAD_LEFT );
	$res   = CC_Application_Repository::create( array(
		'public_ref' => CC_Application_Repository::ulid(), 'student_phone' => $phone, 'full_name' => 'ZZ IW', 'gender' => 'o', 'dob' => '2008-01-01', 'id_doc_type' => 'nid', 'id_doc_enc' => 'x',
		'guardian_name' => 'G', 'guardian_phone' => '+8801700000001', 'institution' => 'I', 'class_level' => '10', 'consent_at' => gmdate( 'Y-m-d H:i:s' ),
		'batch_id' => $batch, 'phone_verified_at' => gmdate( 'Y-m-d H:i:s' ), 'payment_plan' => $plan,
	), wp_generate_uuid4() );
	if ( ! is_wp_error( $res ) ) {
		$made['apps'][] = (int) $res['id'];
	}
	return $res;
}

/** Starts a first/full payment the way start_payment() does and returns [payment_id, gpid]. */
function t_pay_start( array $invoice ): array {
	global $made;
	$gw  = new CC_Fake_Gateway();
	$amt = CC_Application_Repository::initial_amount( $invoice );
	$pid = CC_Application_Repository::insert_payment( $invoice, 'fake', $amt );
	list( $gpid ) = $gw->create_payment( array_merge( $invoice, array( 'amount' => (float) $amt ) ), rest_url( 'cc/v1/payments/callback' ) );
	CC_Application_Repository::attach_gateway_payment( $pid, $gpid, 'x' );
	$made['gpids'][] = $gpid;
	return array( $pid, $gpid );
}
function t_val( string $table, string $col, int $id ) {
	global $wpdb;
	return $wpdb->get_var( $wpdb->prepare( "SELECT {$col} FROM {$wpdb->prefix}{$table} WHERE id = %d", $id ) );
}
function t_user(): int {
	global $made;
	$id = wp_insert_user( array( 'user_login' => '88018' . random_int( 10000000, 99999999 ), 'user_pass' => wp_generate_password(), 'user_email' => bin2hex( random_bytes( 4 ) ) . '@students.invalid', 'role' => 'cc_student' ) );
	$made['users'][] = (int) $id;
	return (int) $id;
}

echo "split_amount\n";
t_assert( array( 'first' => 4000.0, 'balance' => 6000.0 ) === CC_Batch_Repository::split_amount( 10000, 40 ), '40% of 10000 is 4000 now, 6000 later' );
$sp = CC_Batch_Repository::split_amount( 9999, 33 );
t_assert( 3300.0 === $sp['first'] && abs( $sp['first'] + $sp['balance'] - 9999 ) < 0.001, 'first part rounds up to a whole taka; parts add up' );
t_assert( 10 === CC_Batch_Repository::clamp_percent( 1 ) && 90 === CC_Batch_Repository::clamp_percent( 99 ), 'percent is clamped to 10..90' );

echo "reminder schedule (pure)\n";
$due = 1_000_000_000;
$d   = DAY_IN_SECONDS;
t_assert( '' === CC_Installments::reminder_for( $due, $due - 10 * $d, '' ), 'nothing 10 days ahead' );
t_assert( 'due3' === CC_Installments::reminder_for( $due, $due - 2 * $d, '' ), 'a reminder inside 3 days' );
t_assert( '' === CC_Installments::reminder_for( $due, $due - 2 * $d, 'due3' ), 'not repeated' );
t_assert( 'due0' === CC_Installments::reminder_for( $due, $due + 3600, 'due3' ), 'a reminder on the due day' );
t_assert( 'over1' === CC_Installments::reminder_for( $due, $due + $d + 60, 'due0' ), 'first overdue reminder the day after' );
t_assert( '' === CC_Installments::reminder_for( $due, $due + 5 * $d, 'over1' ), 'then quiet for a week' );
t_assert( 'over8' === CC_Installments::reminder_for( $due, $due + 8 * $d + 60, 'over1' ), 'then weekly' );

echo "installment application\n";
$b_inst = t_batch( array( 'installments_enabled' => 1, 'first_payment_percent' => 40, 'installment_days' => 30 ) );
$b_full = t_batch();
$a      = t_apply( $b_inst, 'installment' );
t_assert( ! is_wp_error( $a ) && 'installment' === $a['invoice']['plan'] && '4000.00' === $a['invoice']['first_amount'] && '10000.00' === $a['invoice']['amount'], 'plan, first amount and full amount stored on the invoice' );
$plain = t_apply( $b_full, 'installment' );
t_assert( ! is_wp_error( $plain ) && 'full' === $plain['invoice']['plan'] && null === $plain['invoice']['first_amount'], 'a batch without installments quietly takes a full payment' );
t_assert( '4000.00' === CC_Application_Repository::initial_amount( $a['invoice'] ) && '10000.00' === CC_Application_Repository::initial_amount( $plain['invoice'] ), 'the first charge is the first part, or the whole fee' );

echo "REST: plan validation and the amount sent to the gateway\n";
$validate = new ReflectionMethod( 'CC_Rest_Admissions', 'validate' );
$validate->setAccessible( true );
$mk_req = static function ( int $batch, string $plan ): WP_REST_Request {
	$r = new WP_REST_Request( 'POST', '/cc/v1/applications' );
	foreach ( array( 'batch_id' => (string) $batch, 'payment_plan' => $plan, 'full_name' => 'X' ) as $k => $v ) {
		$r->set_param( $k, $v );
	}
	return $r;
};
$errs = $validate->invoke( null, $mk_req( $b_full, 'installment' ) )['errors'] ?? array();
t_assert( isset( $errs['payment_plan'] ), 'two-part plan is refused on a batch that does not allow it' );
$errs = $validate->invoke( null, $mk_req( $b_inst, 'monthly' ) )['errors'] ?? array();
t_assert( isset( $errs['payment_plan'] ), 'an unknown plan is refused' );
$errs = $validate->invoke( null, $mk_req( $b_inst, 'installment' ) )['errors'] ?? array();
t_assert( ! isset( $errs['payment_plan'] ), 'a two-part plan is accepted where allowed' );
$retry = new WP_REST_Request( 'POST', '/cc/v1/applications/' . $a['public_ref'] . '/payment' );
$retry->set_param( 'ref', $a['public_ref'] );
$resp = CC_Rest_Admissions::retry_payment( $retry );
$rp   = $wpdb->get_row( "SELECT * FROM {$p}cc_payments WHERE invoice_id = {$a['invoice']['id']} ORDER BY id DESC LIMIT 1", ARRAY_A );
t_assert( 200 === $resp->get_status() && is_array( $rp ) && '4000.00' === $rp['amount'] && 'first' === $rp['kind'], 'the payment started from the admission flow charges the first part and is kind=first' );
$made['gpids'][] = $rp['gateway_payment_id'];
$gstate = CC_Fake_Gateway::get_state( (string) $rp['gateway_payment_id'] );
t_assert( 4000.0 === (float) $gstate['amount'], 'the gateway was asked for 4000, not the whole fee' );
$wpdb->update( "{$p}cc_payments", array( 'status' => 'cancelled' ), array( 'id' => $rp['id'] ) );

echo "first payment settles part of the fee\n";
list( $p1, $g1 ) = t_pay_start( $a['invoice'] );
t_assert( 'first' === t_val( 'cc_payments', 'kind', $p1 ) && '4000.00' === t_val( 'cc_payments', 'amount', $p1 ), 'payment row carries kind=first and the first amount' );
CC_Fake_Gateway::set_state( $g1, 'completed', 10000.0 );
$r = CC_Settlement::settle( $p1, 'poll' );
t_assert( 'mismatch' === $r['result'] && 'reconcile_needed' === t_val( 'cc_payments', 'status', $p1 ), 'a charge of the whole fee does not match the first part: mismatch' );
$wpdb->update( "{$p}cc_payments", array( 'status' => 'initiated' ), array( 'id' => $p1 ) );
CC_Fake_Gateway::set_state( $g1, 'completed', 4000.0 );
$r = CC_Settlement::settle( $p1, 'poll' );
$inv = CC_Application_Repository::find_invoice( (int) $a['id'] );
t_assert( 'settled' === $r['result'] && 'approved' === t_val( 'cc_applications', 'status', (int) $a['id'] ), 'the first part approves the application' );
t_assert( 'partial' === $inv['status'] && '4000.00' === $inv['amount_paid'] && ! empty( $inv['due_at'] ), 'invoice is partial with 4000 paid and a due date' );
$days = ( strtotime( $inv['due_at'] . ' UTC' ) - time() ) / DAY_IN_SECONDS;
t_assert( $days > 29 && $days < 31, 'due about 30 days out (the batch setting)' );
t_assert( 1 === (int) t_val( 'cc_batches', 'seats_taken', $b_inst ) && 1 === $hooks['settled'], 'one seat taken and the account hook fired once' );
t_assert( 'already_settled' === CC_Settlement::settle( $p1, 'ipn' )['result'] && 1 === (int) t_val( 'cc_batches', 'seats_taken', $b_inst ), 'a second settle is a no-op' );

echo "balance payment\n";
$uid = t_user();
$wpdb->update( "{$p}cc_applications", array( 'user_id' => $uid ), array( 'id' => $a['id'] ) );
$wpdb->insert( "{$p}cc_enrollments", array( 'user_id' => $uid, 'batch_id' => $b_inst, 'application_id' => (int) $a['id'], 'status' => 'active', 'enrolled_at' => gmdate( 'Y-m-d H:i:s' ) ) );
$out = CC_Installments::outstanding( $uid );
t_assert( 1 === count( $out ) && 6000.0 === (float) $out[0]['balance'] && ! $out[0]['overdue'], 'outstanding() lists a 6000 balance, not overdue' );
$other = t_user();
$res   = CC_Installments::start_balance_payment( $other, (int) $inv['id'] );
t_assert( is_wp_error( $res ) && 'not_found' === $res->get_error_code(), "another student cannot pay (or probe) someone else's invoice" );
$url = CC_Installments::start_balance_payment( $uid, (int) $inv['id'] );
t_assert( is_string( $url ) && str_contains( $url, 'fake/checkout' ), 'a checkout URL is returned' );
$bal = $wpdb->get_row( "SELECT * FROM {$p}cc_payments WHERE invoice_id = {$inv['id']} AND kind = 'balance'", ARRAY_A );
t_assert( is_array( $bal ) && '6000.00' === $bal['amount'], 'a balance payment of 6000 exists' );
$again = CC_Installments::start_balance_payment( $uid, (int) $inv['id'] );
t_assert( is_wp_error( $again ) && 'payment_in_progress' === $again->get_error_code(), 'a second start while one is open is refused' );
$made['gpids'][] = $bal['gateway_payment_id'];
CC_Fake_Gateway::set_state( $bal['gateway_payment_id'], 'completed', 5000.0 );
$r = CC_Settlement::settle( (int) $bal['id'], 'poll' );
t_assert( 'mismatch' === $r['result'] && 'partial' === t_val( 'cc_invoices', 'status', (int) $inv['id'] ), 'a short balance charge is a mismatch and the invoice is unchanged' );
$wpdb->update( "{$p}cc_payments", array( 'status' => 'initiated' ), array( 'id' => $bal['id'] ) );
CC_Fake_Gateway::set_state( $bal['gateway_payment_id'], 'completed', 6000.0 );
$r = CC_Settlement::settle( (int) $bal['id'], 'callback' );
$inv2 = CC_Application_Repository::find_invoice( (int) $a['id'] );
t_assert( 'balance_settled' === $r['result'] && 'paid' === $inv2['status'] && '10000.00' === $inv2['amount_paid'], 'the balance pays the invoice off' );
t_assert( 1 === (int) t_val( 'cc_batches', 'seats_taken', $b_inst ) && 1 === $hooks['balance'] && 1 === $hooks['settled'], 'no second seat; balance hook fired; account hook not fired again' );
t_assert( 'already_settled' === CC_Settlement::settle( (int) $bal['id'], 'ipn' )['result'], 'balance settle is idempotent' );
$none = CC_Installments::start_balance_payment( $uid, (int) $inv['id'] );
t_assert( is_wp_error( $none ) && 'nothing_due' === $none->get_error_code(), 'nothing is due once paid' );

echo "refund of installments\n";
$owner = wp_insert_user( array( 'user_login' => 'zziw' . bin2hex( random_bytes( 3 ) ), 'user_pass' => wp_generate_password(), 'user_email' => bin2hex( random_bytes( 3 ) ) . '@example.test', 'role' => 'cc_owner' ) );
$made['users'][] = (int) $owner;
wp_set_current_user( $owner );
$rf = CC_Admin_Payments::mark_refunded( $p1, 'other', '', $owner );
t_assert( is_wp_error( $rf ) && 'refund_balance_first' === $rf->get_error_code(), 'the first part cannot go back while the balance is paid' );
$rf = CC_Admin_Payments::mark_refunded( (int) $bal['id'], 'other', '', $owner );
$invr = CC_Application_Repository::find_invoice( (int) $a['id'] );
t_assert( ! is_wp_error( $rf ) && 'refunded_balance' === $rf['result'] && 'partial' === $invr['status'] && '4000.00' === $invr['amount_paid'], 'refunding the balance reopens it; the invoice is partial again' );
t_assert( 1 === (int) t_val( 'cc_batches', 'seats_taken', $b_inst ) && 'approved' === t_val( 'cc_applications', 'status', (int) $a['id'] ), 'the student keeps the seat' );
$hooks['seat'] = array();
$rf = CC_Admin_Payments::mark_refunded( $p1, 'student_withdrew', '', $owner );
t_assert( ! is_wp_error( $rf ) && 'refunded' === $rf['result'] && $rf['seat_released'] && 'void' === t_val( 'cc_invoices', 'status', (int) $inv['id'] ) && '0.00' === t_val( 'cc_invoices', 'amount_paid', (int) $inv['id'] ), 'refunding the first part ends the enrolment and releases the seat' );
t_assert( array( $b_inst ) === $hooks['seat'], 'cc_seat_released fired for the batch' );

echo "reminders and pausing\n";
$b2 = t_batch( array( 'installments_enabled' => 1, 'first_payment_percent' => 50, 'installment_days' => 10 ) );
$a2 = t_apply( $b2, 'installment' );
list( $q1, $h1 ) = t_pay_start( $a2['invoice'] );
CC_Fake_Gateway::set_state( $h1, 'completed' );
CC_Settlement::settle( $q1, 'poll' );
$uid2 = t_user();
$wpdb->update( "{$p}cc_applications", array( 'user_id' => $uid2 ), array( 'id' => $a2['id'] ) );
$wpdb->insert( "{$p}cc_enrollments", array( 'user_id' => $uid2, 'batch_id' => $b2, 'application_id' => (int) $a2['id'], 'status' => 'active', 'enrolled_at' => gmdate( 'Y-m-d H:i:s' ) ) );
$due2 = (int) strtotime( t_val( 'cc_invoices', 'due_at', (int) $a2['invoice']['id'] ) . ' UTC' );
$count_sms = static fn( string $tpl ): int => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}cc_sms_log WHERE template = %s AND related_type = 'invoice' AND related_id = %d", $tpl, $a2['invoice']['id'] ) );
$run = CC_Installments::run_reminders( $due2 - 2 * DAY_IN_SECONDS );
t_assert( $run['reminded'] >= 1 && 1 === $count_sms( 'balance_due' ), 'a reminder SMS is queued 2 days before the due date' );
CC_Installments::run_reminders( $due2 - 2 * DAY_IN_SECONDS + 3600 );
t_assert( 1 === $count_sms( 'balance_due' ), 'running again does not send it twice' );
CC_Installments::run_reminders( $due2 + 2 * DAY_IN_SECONDS );
t_assert( 1 === $count_sms( 'balance_overdue' ) || $count_sms( 'balance_due' ) >= 2, 'an overdue reminder follows' );
delete_option( CC_Installments::OPTION_SUSPEND_DAYS );
CC_Installments::run_reminders( $due2 + 30 * DAY_IN_SECONDS );
t_assert( 'active' === t_val( 'cc_enrollments', 'status', (int) $wpdb->get_var( "SELECT id FROM {$p}cc_enrollments WHERE application_id = {$a2['id']}" ) ), 'with pausing off (default) access continues however late' );
update_option( CC_Installments::OPTION_SUSPEND_DAYS, 5 );
$s = CC_Installments::run_reminders( $due2 + 4 * DAY_IN_SECONDS );
t_assert( 0 === $s['suspended'], 'not paused before the grace period ends' );
$s = CC_Installments::run_reminders( $due2 + 6 * DAY_IN_SECONDS );
$enr = (int) $wpdb->get_var( "SELECT id FROM {$p}cc_enrollments WHERE application_id = {$a2['id']}" );
t_assert( $s['suspended'] >= 1 && 'deactivated' === t_val( 'cc_enrollments', 'status', $enr ) && ! empty( t_val( 'cc_invoices', 'suspended_at', (int) $a2['invoice']['id'] ) ) && 1 === $count_sms( 'balance_suspended' ), 'after 5 days overdue access is paused, once, with an SMS' );
$url2 = CC_Installments::start_balance_payment( $uid2, (int) $a2['invoice']['id'] );
$bal2 = $wpdb->get_row( "SELECT * FROM {$p}cc_payments WHERE invoice_id = {$a2['invoice']['id']} AND kind = 'balance'", ARRAY_A );
$made['gpids'][] = $bal2['gateway_payment_id'];
CC_Fake_Gateway::set_state( $bal2['gateway_payment_id'], 'completed' );
CC_Settlement::settle( (int) $bal2['id'], 'poll' );
t_assert( 'active' === t_val( 'cc_enrollments', 'status', $enr ) && empty( t_val( 'cc_invoices', 'suspended_at', (int) $a2['invoice']['id'] ) ), 'paying the balance resumes access' );
delete_option( CC_Installments::OPTION_SUSPEND_DAYS );

echo "refund through the gateway\n";
$b3 = t_batch();
$a3 = t_apply( $b3 );
list( $z1, $zg ) = t_pay_start( $a3['invoice'] );
CC_Fake_Gateway::set_state( $zg, 'completed' );
CC_Settlement::settle( $z1, 'poll' );
CC_Fake_Gateway::refuse_refund( $zg );
$rf = CC_Admin_Payments::refund_via_gateway( $z1, 'error', $owner );
t_assert( is_wp_error( $rf ) && 'refund_gateway_failed' === $rf->get_error_code() && 'completed' === t_val( 'cc_payments', 'status', $z1 ), 'when the gateway refuses, nothing changes here' );
$st = CC_Fake_Gateway::get_state( $zg );
unset( $st['refuse_refund'] );
update_option( CC_Fake_Gateway::OPTION_PREFIX . $zg, $st, false );
update_option( 'cc_refund_window_days', 1 );
$wpdb->update( "{$p}cc_payments", array( 'settled_at' => gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS ) ), array( 'id' => $z1 ) );
$rf = CC_Admin_Payments::refund_via_gateway( $z1, 'error', $owner );
t_assert( is_wp_error( $rf ) && 'refund_window_passed' === $rf->get_error_code(), 'outside the refund window the gateway refund is refused' );
update_option( 'cc_refund_window_days', 0 );
$staff = wp_insert_user( array( 'user_login' => 'zziws' . bin2hex( random_bytes( 3 ) ), 'user_pass' => wp_generate_password(), 'user_email' => bin2hex( random_bytes( 3 ) ) . '@example.test', 'role' => 'cc_staff' ) );
$made['users'][] = (int) $staff;
$rf = CC_Admin_Payments::refund_via_gateway( $z1, 'error', $staff );
t_assert( is_wp_error( $rf ) && 'refund_forbidden' === $rf->get_error_code(), 'staff cannot send money back' );
$rf = CC_Admin_Payments::refund_via_gateway( $z1, 'error', $owner );
t_assert( ! is_wp_error( $rf ) && 'refunded' === $rf['result'] && 'refunded' === t_val( 'cc_payments', 'status', $z1 ), 'gateway refund succeeds and is recorded' );
t_assert( ! empty( ( CC_Fake_Gateway::get_state( $zg ) )['refunded'] ), 'the gateway was asked to refund the full amount' );
$ev = (string) $wpdb->get_var( "SELECT payload_json FROM {$p}cc_payment_events WHERE payment_id = {$z1} AND source = 'admin'" );
t_assert( str_contains( $ev, 'RF' ), 'the gateway refund reference is kept on the payment event' );

echo "waitlist\n";
$bw = t_batch( array( 'capacity' => 1, 'seats_taken' => 1, 'waitlist_enabled' => 1 ) );
$bn = t_batch( array( 'capacity' => 1, 'seats_taken' => 1 ) );
$w1 = t_apply( $bw );
$w2 = t_apply( $bw );
t_assert( ! is_wp_error( $w1 ) && 'waitlisted' === $w1['status'] && ! empty( $w1['waitlisted_at'] ) && ! empty( $w1['invoice'] ), 'a full batch with a waitlist accepts the application as waitlisted' );
t_assert( 1 === CC_Waitlist::position( (int) $w1['id'] ) && 2 === CC_Waitlist::position( (int) $w2['id'] ), 'positions follow arrival order' );
$retry = new WP_REST_Request( 'POST', '/cc/v1/applications/' . $w1['public_ref'] . '/payment' );
$retry->set_param( 'ref', $w1['public_ref'] );
t_assert( 409 === CC_Rest_Admissions::retry_payment( $retry )->get_status(), 'a waitlisted applicant cannot start a payment yet' );
$closed = t_apply( $bn );
t_assert( is_wp_error( $closed ) && 'batch_closed' === $closed->get_error_code(), 'a full batch without a waitlist still refuses' );
$view = CC_Application_Repository::status_view( (string) $w2['public_ref'] );
t_assert( 'waitlisted' === $view['status'] && 2 === $view['waitlist_position'], 'the public status shows place 2' );
$o = CC_Waitlist::offer( (int) $w1['id'] );
t_assert( is_wp_error( $o ) && 'no_free_seat' === $o->get_error_code(), 'no offer without a free seat' );
$wpdb->update( "{$p}cc_batches", array( 'seats_taken' => 0 ), array( 'id' => $bw ) );
$o = CC_Waitlist::offer( (int) $w1['id'], $owner );
t_assert( true === $o && 'pending' === t_val( 'cc_applications', 'status', (int) $w1['id'] ) && ! empty( t_val( 'cc_applications', 'offered_at', (int) $w1['id'] ) ), 'an offer turns the application pending' );
t_assert( 0 === CC_Waitlist::free_seats( $bw ) && 1 === CC_Waitlist::outstanding_offers( $bw ), 'the offered seat is no longer free' );
$late = t_apply( $bw );
t_assert( ! is_wp_error( $late ) && 'waitlisted' === $late['status'], 'a new applicant cannot take the offered seat; they join the waitlist' );
t_assert( true === CC_Waitlist::offer( (int) $w2['id'], 0, true ) , 'staff can force a second offer past capacity' );
$wpdb->update( "{$p}cc_applications", array( 'status' => 'waitlisted', 'offered_at' => null ), array( 'id' => $w2['id'] ) );

$offers_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}cc_sms_log WHERE template = 'waitlist_offer' AND related_id = {$w1['id']}" );
t_assert( 1 === $offers_before, 'the offer SMS was queued once' );

$wpdb->update( "{$p}cc_batches", array( 'capacity' => 3 ), array( 'id' => $bw ) );
$n = CC_Waitlist::fill( $bw );
t_assert( 2 === $n && 0 === CC_Waitlist::free_seats( $bw ), 'raising capacity offers the next two in order' );
t_assert( 'pending' === t_val( 'cc_applications', 'status', (int) $w2['id'] ) && 'pending' === t_val( 'cc_applications', 'status', (int) $late['id'] ), 'w2 and the late applicant got the seats' );

echo "offers lapse\n";
update_option( 'cc_waitlist_offer_hours', 24 );
$wpdb->update( "{$p}cc_applications", array( 'offered_at' => gmdate( 'Y-m-d H:i:s', time() - 30 * HOUR_IN_SECONDS ) ), array( 'id' => $w1['id'] ) );
$extra = t_apply( $bw );
$exp   = CC_Waitlist::expire_offers();
t_assert( 1 === $exp && 'cancelled' === t_val( 'cc_applications', 'status', (int) $w1['id'] ) && 'void' === t_val( 'cc_invoices', 'status', (int) $w1['invoice']['id'] ), 'an unpaid offer older than 24 h is cancelled and its invoice voided' );
t_assert( 'pending' === t_val( 'cc_applications', 'status', (int) $extra['id'] ), 'the freed seat went to the next person' );
t_assert( 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}cc_sms_log WHERE template = 'waitlist_expired' AND related_id = {$w1['id']}" ), 'the expired applicant was told' );
$wpdb->update( "{$p}cc_applications", array( 'offered_at' => gmdate( 'Y-m-d H:i:s', time() - 30 * HOUR_IN_SECONDS ) ), array( 'id' => $w2['id'] ) );
list( $wp1, $wg1 ) = t_pay_start( CC_Application_Repository::find_invoice( (int) $w2['id'] ) );
$exp = CC_Waitlist::expire_offers();
t_assert( 0 === $exp && 'pending' === t_val( 'cc_applications', 'status', (int) $w2['id'] ), 'an offer with a payment under way is left alone' );
delete_option( 'cc_waitlist_offer_hours' );

echo "SMS once\n";
CC_Waitlist::notify_joined( $w2 );
CC_Waitlist::notify_joined( $w2 );
t_assert( 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}cc_sms_log WHERE template = 'waitlist_joined' AND related_id = {$w2['id']}" ), 'the joined SMS is sent only once per application' );

// Cleanup.
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ( $made['users'] as $id ) {
	wp_delete_user( $id );
}
$app_ids = implode( ',', array_map( 'intval', $made['apps'] ) ) ?: '0';
$wpdb->query( "DELETE FROM {$p}cc_payment_events WHERE payment_id IN ( SELECT id FROM {$p}cc_payments WHERE invoice_id IN ( SELECT id FROM {$p}cc_invoices WHERE application_id IN ($app_ids) ) )" );
$wpdb->query( "DELETE FROM {$p}cc_payments WHERE invoice_id IN ( SELECT id FROM {$p}cc_invoices WHERE application_id IN ($app_ids) )" );
$wpdb->query( "DELETE FROM {$p}cc_enrollments WHERE application_id IN ($app_ids)" );
$wpdb->query( "DELETE FROM {$p}cc_invoices WHERE application_id IN ($app_ids)" );
$wpdb->query( "DELETE FROM {$p}cc_sms_log WHERE related_id IN ($app_ids) AND related_type = 'application'" );
$wpdb->query( "DELETE FROM {$p}cc_applications WHERE id IN ($app_ids)" );
foreach ( $made['batches'] as $id ) {
	$wpdb->delete( "{$p}cc_batches", array( 'id' => $id ) );
}
foreach ( $made['gpids'] as $g ) {
	CC_Fake_Gateway::delete_state( (string) $g );
}
$wpdb->query( "DELETE FROM {$p}cc_audit_log WHERE action LIKE 'payment.refund%' AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR)" );
echo "\n$checks checks, $failures failures\n";
exit( $failures ? 1 : 0 );
