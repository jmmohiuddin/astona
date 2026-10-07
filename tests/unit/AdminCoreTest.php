<?php
/**
 * Dependency-free tests for CC_Csv::neutralise and the CC_Audit canonical-hash helpers.
 * Run: php tests/unit/AdminCoreTest.php
 */

define( 'ABSPATH', __DIR__ . '/' );

$dir = __DIR__ . '/../../wp-content/plugins/coaching-platform/includes/admin/';
require $dir . 'class-csv.php';
require $dir . 'class-audit.php';

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

echo "csv neutraliser\n";
foreach ( array( '=' => '=SUM(A1)', '+' => '+1', '-' => '-1', '@' => '@cmd', 'tab' => "\tx", 'CR' => "\rx" ) as $label => $cell ) {
	check( "leading $label gets a quote prefix", "'" . $cell, CC_Csv::neutralise( $cell ) );
}
check( 'plain text untouched', 'Rahim Uddin', CC_Csv::neutralise( 'Rahim Uddin' ) );
check( 'Bangla text untouched', 'রহিম', CC_Csv::neutralise( 'রহিম' ) );
check( 'operator in the middle untouched', 'a=b', CC_Csv::neutralise( 'a=b' ) );
check( 'empty string stays empty', '', CC_Csv::neutralise( '' ) );
check( 'null becomes empty', '', CC_Csv::neutralise( null ) );
check( 'int passes through', '-5', CC_Csv::neutralise( -5 ) );
check( 'float passes through', '12.5', CC_Csv::neutralise( 12.5 ) );
check( 'phone with plus is neutralised', "'+8801700000000", CC_Csv::neutralise( '+8801700000000' ) );
foreach ( array(
	'leading space then =' => ' =SUM(A1)',
	'leading newline then @' => "\n@cmd",
	'ideographic space then +' => "\u{3000}+1",
	'mixed whitespace then -' => " \t \u{3000}-2",
	'full-width =' => "\u{FF1D}1+1",
	'full-width +' => "\u{FF0B}1",
	'full-width -' => "\u{FF0D}1",
	'invalid UTF-8' => "\xC3\x28",
) as $label => $cell ) {
	check( "$label is neutralised", "'" . $cell, CC_Csv::neutralise( $cell ) );
}
check( 'spaces then plain text untouched', '  hello', CC_Csv::neutralise( '  hello' ) );
check( 'only whitespace untouched', '   ', CC_Csv::neutralise( '   ' ) );
check( 'negative numeric string is still neutralised', "'-5", CC_Csv::neutralise( '-5' ) );
check( 'phone helper drops the plus', '8801700000000', CC_Csv::phone( '+8801700000000' ) );
check( 'exported phone is not neutralised', '8801700000000', CC_Csv::neutralise( CC_Csv::phone( '+8801700000000' ) ) );
check( 'masked phone keeps no plus', '88017****000', CC_Csv::phone( '+88017****000' ) );

echo "audit canonical hash\n";
check( 'key order does not change the hash', CC_Audit::diff_hash( array( 'a' => 1, 'b' => 2 ) ), CC_Audit::diff_hash( array( 'b' => 2, 'a' => 1 ) ) );
check(
	'nested key order does not change the hash',
	CC_Audit::diff_hash( array( 'x' => array( 'b' => 1, 'a' => 2 ) ) ),
	CC_Audit::diff_hash( array( 'x' => array( 'a' => 2, 'b' => 1 ) ) )
);
check( 'list order is significant', true, CC_Audit::diff_hash( array( 'l' => array( 1, 2 ) ) ) !== CC_Audit::diff_hash( array( 'l' => array( 2, 1 ) ) ) );
check( 'different values differ', true, CC_Audit::diff_hash( array( 'a' => 1 ) ) !== CC_Audit::diff_hash( array( 'a' => 2 ) ) );
check( 'canonical json is sorted and unescaped', '{"a":"আ/b","z":1}', CC_Audit::canonical_json( array( 'z' => 1, 'a' => 'আ/b' ) ) );
check( 'hash is sha256 of canonical json', hash( 'sha256', '{"a":1}' ), CC_Audit::diff_hash( array( 'a' => 1 ) ) );
check( 'empty diff has no hash', null, CC_Audit::diff_hash( array() ) );
check( 'hash is 64 hex chars', 1, preg_match( '/^[a-f0-9]{64}$/', (string) CC_Audit::diff_hash( array( 'a' => 1 ) ) ) );

$fails = count( $GLOBALS['t_fail'] );
echo "\n{$GLOBALS['t_pass']} passed, $fails failed\n";
exit( $fails > 0 ? 1 : 0 );
