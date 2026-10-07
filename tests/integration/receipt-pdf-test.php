<?php
/**
 * Integration tests for PDF receipts (CC_Receipt_Pdf) and the receipt page's PDF download.
 *   docker compose run --rm -T wpcli eval-file /tests/integration/receipt-pdf-test.php
 * Skips (exit 0) when mPDF is not installed: it is an optional Composer dependency.
 */
global $wpdb, $failures, $checks;
$p        = $wpdb->prefix;
$failures = 0;
$checks   = 0;
function t_assert( bool $cond, string $label ): void {
	global $failures, $checks;
	++$checks;
	echo ( $cond ? '  ok   ' : '  FAIL ' ) . "$label\n";
	if ( ! $cond ) {
		++$failures;
	}
}
if ( ! CC_Receipt_Pdf::available() ) {
	echo "SKIP: mPDF is not installed (composer install --no-dev in the plugin).\n";
	exit( 0 );
}
putenv( 'CC_RATE_LIMIT_DISABLED=1' );
$now  = gmdate( 'Y-m-d H:i:s' );
$wpdb->insert( "{$p}cc_batches", array( 'course_id' => 0, 'name' => 'ZZ-PDF ' . bin2hex( random_bytes( 3 ) ), 'capacity' => 10, 'seats_taken' => 1, 'price' => 10000, 'start_date' => '2030-01-01', 'status' => 'open', 'application_open' => 0, 'created_at' => $now, 'updated_at' => $now ) );
$batch = (int) $wpdb->insert_id;
$mk    = static function ( string $name ) use ( $wpdb, $p, $batch, $now ): array {
	$phone = '+88015' . random_int( 10000000, 99999999 );
	$wpdb->insert( "{$p}cc_applications", array( 'public_ref' => substr( strtoupper( 'ZZPDF' . bin2hex( random_bytes( 11 ) ) ), 0, 26 ), 'idempotency_key' => wp_generate_uuid4(), 'batch_id' => $batch, 'student_phone' => $phone, 'full_name' => $name, 'gender' => 'o', 'dob' => '2008-01-01', 'id_doc_type' => 'nid', 'id_doc_enc' => 'x', 'guardian_name' => 'G', 'guardian_phone' => '+8801700000001', 'institution' => 'I', 'class_level' => '10', 'consent_at' => $now, 'status' => 'approved', 'created_at' => $now, 'updated_at' => $now ) );
	$app = (int) $wpdb->insert_id;
	$uid = wp_insert_user( array( 'user_login' => ltrim( $phone, '+' ), 'user_pass' => $pw = 'Pdf-' . bin2hex( random_bytes( 6 ) ), 'user_email' => bin2hex( random_bytes( 4 ) ) . '@students.invalid', 'role' => 'cc_student' ) );
	$wpdb->update( "{$p}cc_applications", array( 'user_id' => $uid ), array( 'id' => $app ) );
	$wpdb->insert( "{$p}cc_invoices", array( 'application_id' => $app, 'amount' => 10000, 'amount_paid' => 4000, 'plan' => 'installment', 'first_amount' => 4000, 'status' => 'partial', 'number' => 'ZZPDF-' . bin2hex( random_bytes( 4 ) ), 'created_at' => $now ) );
	$inv = (int) $wpdb->insert_id;
	$wpdb->insert( "{$p}cc_payments", array( 'invoice_id' => $inv, 'gateway' => 'fake', 'method' => 'fake', 'gateway_payment_id' => 'ZZP' . bin2hex( random_bytes( 6 ) ), 'trx_id' => 'TXZZ' . random_int( 1000, 9999 ), 'amount' => 4000, 'kind' => 'first', 'status' => 'completed', 'created_at' => $now, 'updated_at' => $now, 'settled_at' => $now ) );
	return array( 'app' => $app, 'uid' => (int) $uid, 'pw' => $pw, 'phone' => $phone, 'inv' => $inv, 'pay' => (int) $wpdb->insert_id );
};
$a = $mk( 'মোহাম্মদ ইসলাম ক্ষ্ম' );
$b = $mk( 'Other Student' );

$receipt = CC_Portal_Data::receipt( $a['uid'], $a['pay'] );
t_assert( is_array( $receipt ) && 'first' === $receipt['kind'] && 10000.0 === (float) $receipt['invoice_total'] && 4000.0 === (float) $receipt['paid_to_date'], 'receipt data carries the plan, fee total and paid-to-date' );
$html = CC_Receipt_Pdf::html( $receipt );
t_assert( str_contains( $html, 'Still to pay' ) && str_contains( $html, 'BDT 6,000.00' ) && str_contains( $html, 'First payment' ), 'the receipt shows what is still to pay for a part payment' );
t_assert( str_contains( $html, 'মোহাম্মদ' ), 'the Bangla name reaches the document as text' );
$pdf = CC_Receipt_Pdf::render( $receipt, false );
t_assert( 0 === strpos( $pdf, '%PDF-' ) && strlen( $pdf ) > 2000, 'a PDF is produced' );
t_assert( 1 === preg_match_all( '#/Type\s*/Page[^s]#', $pdf ), 'it is one page' );
t_assert( str_contains( $pdf, 'NotoSansBengali' ) || str_contains( $pdf, 'Noto' ), 'the Bangla font is embedded' );
t_assert( '<span style="font-family:notobengali">মোহাম্মদ ইসলাম ক্ষ্ম</span>' === CC_Receipt_Pdf::wrap_bangla( 'মোহাম্মদ ইসলাম ক্ষ্ম' ) && 'Karim <span style="font-family:notobengali">করিম</span> Khan' === CC_Receipt_Pdf::wrap_bangla( 'Karim করিম Khan' ), 'Bangla runs (and only those) are wrapped for the Bangla font' );
file_put_contents( '/tmp/receipt-test.pdf', CC_Receipt_Pdf::render( $receipt ) );

echo "download over HTTP\n";
$login = wp_remote_post( 'http://wordpress/wp-json/cc/v1/auth/login', array( 'timeout' => 30, 'headers' => array( 'Content-Type' => 'application/json' ), 'body' => wp_json_encode( array( 'phone' => $a['phone'], 'password' => $a['pw'] ) ) ) );
$ck    = wp_remote_retrieve_cookies( $login );
$get   = static fn( string $q ) => wp_remote_get( 'http://wordpress/student/payments/receipt/?' . $q, array( 'cookies' => $ck, 'timeout' => 60, 'redirection' => 0 ) );
$r     = $get( 'id=' . $a['pay'] . '&format=pdf' );
t_assert( 200 === wp_remote_retrieve_response_code( $r ) && str_contains( (string) wp_remote_retrieve_header( $r, 'content-type' ), 'application/pdf' ) && 0 === strpos( wp_remote_retrieve_body( $r ), '%PDF' ), 'the owner gets an application/pdf download' );
t_assert( str_contains( (string) wp_remote_retrieve_header( $r, 'content-disposition' ), 'attachment' ) && str_contains( (string) wp_remote_retrieve_header( $r, 'cache-control' ), 'no-store' ), 'sent as an attachment and never cached' );
$r = $get( 'id=' . $b['pay'] . '&format=pdf' );
t_assert( ! str_contains( (string) wp_remote_retrieve_header( $r, 'content-type' ), 'application/pdf' ), "another student's receipt is not served as a PDF" );
$r = $get( 'id=' . $a['pay'] );
t_assert( str_contains( wp_remote_retrieve_body( $r ), 'Download PDF' ) && str_contains( wp_remote_retrieve_body( $r ), 'Still to pay' ), 'the HTML receipt offers the PDF and shows the balance' );
$anon = wp_remote_get( 'http://wordpress/student/payments/receipt/?id=' . $a['pay'] . '&format=pdf', array( 'timeout' => 30, 'redirection' => 0 ) );
t_assert( 0 !== strpos( wp_remote_retrieve_body( $anon ), '%PDF' ), 'an anonymous visitor gets no PDF' );

require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ( array( $a, $b ) as $s ) {
	wp_delete_user( $s['uid'] );
	$wpdb->query( "DELETE FROM {$p}cc_payments WHERE invoice_id = {$s['inv']}" );
	$wpdb->query( "DELETE FROM {$p}cc_invoices WHERE id = {$s['inv']}" );
	$wpdb->query( "DELETE FROM {$p}cc_applications WHERE id = {$s['app']}" );
}
$wpdb->delete( "{$p}cc_batches", array( 'id' => $batch ) );
echo "\n$checks checks, $failures failures\n";
exit( $failures ? 1 : 0 );
