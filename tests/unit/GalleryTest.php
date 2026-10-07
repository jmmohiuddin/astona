<?php
/**
 * Dependency-free tests for the pure CC_Gallery / CC_Results helpers.
 * Run: php tests/unit/GalleryTest.php
 */

define( 'ABSPATH', __DIR__ . '/' );
require __DIR__ . '/../../wp-content/plugins/coaching-platform/includes/gallery/class-gallery.php';
require __DIR__ . '/../../wp-content/plugins/coaching-platform/includes/gallery/class-results.php';

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

function item( int $id, int $order, int $time, array $cats ): array {
	return array( 'id' => $id, 'order' => $order, 'time' => $time, 'cats' => $cats );
}

echo "gallery sort\n";
$sorted = CC_Gallery::sort_items( array( item( 1, 5, 100, array() ), item( 2, 1, 100, array() ), item( 3, 1, 200, array() ), item( 4, 1, 200, array() ) ) );
check( 'order asc, then newest, then highest id', array( 4, 3, 2, 1 ), array_column( $sorted, 'id' ) );

echo "gallery grouping\n";
$cats   = array( 'campus' => 'Campus', 'events' => 'Events', 'results' => 'Results' );
$groups = CC_Gallery::group_by_category( array( item( 1, 0, 0, array( 'campus' ) ), item( 2, 0, 0, array( 'campus', 'events' ) ), item( 3, 0, 0, array() ) ), $cats );
check( 'empty category (results) is hidden', array( 'campus', 'events', 'other' ), array_column( $groups, 'slug' ) );
check( 'item in two categories appears in both', array( 1, 2 ), array_column( $groups[0]['items'], 'id' ) );
check( 'uncategorised items land in the extra group', array( 3 ), array_column( $groups[2]['items'], 'id' ) );
check( 'no items means no groups', array(), CC_Gallery::group_by_category( array(), $cats ) );
check( 'unknown category slug counts as uncategorised', array( 'other' ), array_column( CC_Gallery::group_by_category( array( item( 9, 0, 0, array( 'zzz' ) ) ), $cats ), 'slug' ) );

echo "results year\n";
check( 'plain year', 2026, CC_Results::normalise_year( '2026' ) );
check( 'whitespace trimmed', 2025, CC_Results::normalise_year( ' 2025 ' ) );
check( 'two digits rejected', 0, CC_Results::normalise_year( '26' ) );
check( 'text rejected', 0, CC_Results::normalise_year( '2026a' ) );
check( 'too old rejected', 0, CC_Results::normalise_year( '1850' ) );
check( 'too far ahead rejected', 0, CC_Results::normalise_year( '2999' ) );
check( 'empty rejected', 0, CC_Results::normalise_year( '' ) );

echo "results grouping\n";
$years = CC_Results::group_by_year( array( array( 'year' => 2024 ), array( 'year' => 0 ), array( 'year' => 2026 ), array( 'year' => 2024 ) ) );
check( 'newest year first, unknown last', array( 2026, 2024, 0 ), array_keys( $years ) );
check( 'records stay grouped', 2, count( $years[2024] ) );

$fails = count( $GLOBALS['t_fail'] );
echo "\n{$GLOBALS['t_pass']} passed, $fails failed\n";
exit( $fails ? 1 : 0 );
