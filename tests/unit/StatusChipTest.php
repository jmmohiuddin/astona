<?php
/**
 * Dependency-free tests for CC_Status_Chip. Run: php tests/unit/StatusChipTest.php
 */

define( 'ABSPATH', __DIR__ . '/' );
require __DIR__ . '/../../wp-content/plugins/coaching-platform/includes/class-status-chip.php';

$GLOBALS['t_pass'] = 0;
$GLOBALS['t_fail'] = array();

function check( string $name, $expected, $actual ): void {
	if ( $expected === $actual ) {
		$GLOBALS['t_pass']++;
		echo "  ok   $name\n";
		return;
	}
	$GLOBALS['t_fail'][] = $name;
	echo "  FAIL $name\n       expected " . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . "\n";
}

function batch( array $o = array() ): array {
	return array_merge(
		array( 'status' => 'open', 'application_open' => 1, 'capacity' => 100, 'seats_taken' => 0 ),
		$o
	);
}

function chip_of( array $b ): string {
	return CC_Status_Chip::for_batch( $b['status'], (bool) $b['application_open'], $b['capacity'], $b['seats_taken'] );
}

echo "for_batch\n";
check( 'open batch with free seats is open', 'open', chip_of( batch() ) );
check( 'draft batch is closed', 'closed', chip_of( batch( array( 'status' => 'draft' ) ) ) );
check( 'closed batch is closed', 'closed', chip_of( batch( array( 'status' => 'closed' ) ) ) );
check( 'unknown status is closed', 'closed', chip_of( batch( array( 'status' => '' ) ) ) );
check( 'application closed is closed', 'closed', chip_of( batch( array( 'application_open' => 0 ) ) ) );
check( 'zero capacity is closed', 'closed', chip_of( batch( array( 'capacity' => 0 ) ) ) );
check( 'negative capacity is closed', 'closed', chip_of( batch( array( 'capacity' => -5 ) ) ) );
check( 'full batch is closed', 'closed', chip_of( batch( array( 'seats_taken' => 100 ) ) ) );
check( 'over-subscribed batch is closed', 'closed', chip_of( batch( array( 'seats_taken' => 120 ) ) ) );
check( 'exactly 80% is filling', 'filling', chip_of( batch( array( 'seats_taken' => 80 ) ) ) );
check( '79% is open', 'open', chip_of( batch( array( 'seats_taken' => 79 ) ) ) );
check( 'one seat left is filling', 'filling', chip_of( batch( array( 'seats_taken' => 99 ) ) ) );
check( 'just under threshold (7/10 of 9 cap -> 7/9=77%) is open', 'open', chip_of( batch( array( 'capacity' => 9, 'seats_taken' => 7 ) ) ) );
check( '8 of 10 is filling', 'filling', chip_of( batch( array( 'capacity' => 10, 'seats_taken' => 8 ) ) ) );

echo "waitlist\n";
check( 'full batch with a waitlist is waitlist', 'waitlist', CC_Status_Chip::for_row( batch( array( 'seats_taken' => 100, 'waitlist_enabled' => 1 ) ) ) );
check( 'full batch without a waitlist stays closed', 'closed', CC_Status_Chip::for_row( batch( array( 'seats_taken' => 100 ) ) ) );
check( 'waitlist does not open a closed batch', 'closed', CC_Status_Chip::for_row( batch( array( 'seats_taken' => 100, 'waitlist_enabled' => 1, 'application_open' => 0 ) ) ) );
check( 'a batch with seats left ignores the waitlist flag', 'open', CC_Status_Chip::for_row( batch( array( 'waitlist_enabled' => 1 ) ) ) );
check( 'a waitlist batch ranks above closed', 'waitlist', CC_Status_Chip::for_batches( array( batch( array( 'status' => 'closed' ) ), batch( array( 'seats_taken' => 100, 'waitlist_enabled' => 1 ) ) ) ) );
check( 'but below filling', 'filling', CC_Status_Chip::for_batches( array( batch( array( 'seats_taken' => 100, 'waitlist_enabled' => 1 ) ), batch( array( 'seats_taken' => 90 ) ) ) ) );
check( 'waitlist has a label', true, str_contains( CC_Status_Chip::label( 'waitlist' ), 'Waitlist' ) );

echo "for_batches\n";
check( 'empty list is closed', 'closed', CC_Status_Chip::for_batches( array() ) );
check( 'all closed is closed', 'closed', CC_Status_Chip::for_batches( array( batch( array( 'status' => 'closed' ) ), batch( array( 'capacity' => 0 ) ) ) ) );
check( 'single filling batch is filling', 'filling', CC_Status_Chip::for_batches( array( batch( array( 'seats_taken' => 90 ) ) ) ) );
check( 'filling beats closed', 'filling', CC_Status_Chip::for_batches( array( batch( array( 'status' => 'draft' ) ), batch( array( 'seats_taken' => 85 ) ) ) ) );
check( 'any open wins over filling (open last)', 'open', CC_Status_Chip::for_batches( array( batch( array( 'seats_taken' => 90 ) ), batch() ) ) );
check( 'any open wins over filling (open first)', 'open', CC_Status_Chip::for_batches( array( batch(), batch( array( 'seats_taken' => 90 ) ) ) ) );
check(
	'string-typed DB rows are coerced',
	'filling',
	CC_Status_Chip::for_batches( array( array( 'status' => 'open', 'application_open' => '1', 'capacity' => '50', 'seats_taken' => '45' ) ) )
);
check(
	'string "0" application_open is closed',
	'closed',
	CC_Status_Chip::for_batches( array( array( 'status' => 'open', 'application_open' => '0', 'capacity' => '50', 'seats_taken' => '1' ) ) )
);

echo "label\n";
check( 'open label', 'Open · ভর্তি চলছে', CC_Status_Chip::label( 'open' ) );
check( 'filling label', 'Filling Fast · দ্রুত পূর্ণ হচ্ছে', CC_Status_Chip::label( 'filling' ) );
check( 'closed label', 'Closed · ভর্তি বন্ধ', CC_Status_Chip::label( 'closed' ) );
check( 'unknown chip falls back to closed label', 'Closed · ভর্তি বন্ধ', CC_Status_Chip::label( 'bogus' ) );

$fails = count( $GLOBALS['t_fail'] );
echo "\n{$GLOBALS['t_pass']} passed, $fails failed\n";
exit( $fails > 0 ? 1 : 0 );
