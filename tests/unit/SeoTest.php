<?php
/**
 * Dependency-free tests for the pure JSON-LD builders in CC_Seo.
 * Run: php tests/unit/SeoTest.php
 */

define( 'ABSPATH', __DIR__ . '/' );
require __DIR__ . '/../../wp-content/plugins/coaching-platform/includes/class-seo.php';

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

$c = static fn( int $n ): array => array_map( static fn( $i ) => array( 'url' => "https://s.test/courses/c$i/", 'title' => "Course $i" ), range( 1, $n ) );

echo "ItemList\n";
check( 'none for 0 courses', null, CC_Seo::item_list( array() ) );
check( 'none for 2 courses', null, CC_Seo::item_list( $c( 2 ) ) );
$list = CC_Seo::item_list( $c( 3 ) );
check( 'present for 3 courses', 'ItemList', $list['@type'] ?? '' );
check( 'positions start at 1 and follow order', array( 1, 2, 3 ), array_column( $list['itemListElement'], 'position' ) );
check( 'positions stay sequential for a non-list input', array( 1, 2, 3 ), array_column( CC_Seo::item_list( array( 5 => $c( 3 )[0], 9 => $c( 3 )[1], 11 => $c( 3 )[2] ) )['itemListElement'], 'position' ) );

echo "CourseInstance\n";
$offer = array( '@type' => 'Offer', 'price' => '6000.00', 'priceCurrency' => 'BDT' );
$i     = CC_Seo::course_instance( array( 'name' => 'Evening A', 'delivery_mode' => 'hybrid', 'start_date' => '2026-11-01', 'end_date' => '2027-03-01' ), $offer );
check( 'type and name', array( 'CourseInstance', 'Evening A' ), array( $i['@type'], $i['name'] ) );
check( 'hybrid maps to blended', 'blended', $i['courseMode'] );
check( 'physical maps to onsite', 'onsite', CC_Seo::course_instance( array( 'delivery_mode' => 'physical' ), $offer )['courseMode'] );
check( 'online maps to online', 'online', CC_Seo::course_instance( array( 'delivery_mode' => 'online' ), $offer )['courseMode'] );
check( 'dates carried', array( '2026-11-01', '2027-03-01' ), array( $i['startDate'], $i['endDate'] ) );
check( 'open-ended batch has no endDate', false, isset( CC_Seo::course_instance( array( 'delivery_mode' => 'online', 'start_date' => '2026-11-01', 'end_date' => null ), $offer )['endDate'] ) );
check( 'offer is nested with its price', '6000.00', $i['offers']['price'] );

echo "\n" . $GLOBALS['t_pass'] . ' passed, ' . count( $GLOBALS['t_fail'] ) . " failed\n";
exit( $GLOBALS['t_fail'] ? 1 : 0 );
