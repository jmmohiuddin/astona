<?php
/**
 * Dependency-free tests for the pure CC_Resource_Store helpers.
 * Run: php tests/unit/ContentTest.php
 */

define( 'ABSPATH', __DIR__ . '/' );
require __DIR__ . '/../../wp-content/plugins/coaching-platform/includes/content/class-resource-store.php';

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

echo "pdf detection\n";
check( 'pdf header and mime pass', true, CC_Resource_Store::looks_like_pdf( "%PDF-1.4\n...", 'application/pdf' ) );
check( 'pdf mime without header fails', false, CC_Resource_Store::looks_like_pdf( 'hello', 'application/pdf' ) );
check( 'pdf header with text mime fails', false, CC_Resource_Store::looks_like_pdf( "%PDF-1.4\n", 'text/plain' ) );
check( 'empty input fails', false, CC_Resource_Store::looks_like_pdf( '', 'application/pdf' ) );
check( 'leading whitespace before header fails', false, CC_Resource_Store::looks_like_pdf( " %PDF-1.4", 'application/pdf' ) );

echo "display name\n";
check( 'plain name keeps stem and gets .pdf', 'Week 1 notes.pdf', CC_Resource_Store::display_name( 'Week 1 notes.pdf' ) );
check( 'other extension is replaced', 'notes.pdf', CC_Resource_Store::display_name( 'notes.exe' ) );
check( 'unix path is dropped', 'passwd.pdf', CC_Resource_Store::display_name( '../../etc/passwd' ) );
check( 'windows path is dropped', 'a.pdf', CC_Resource_Store::display_name( 'C:\\x\\a.pdf' ) );
check( 'header-injection characters are removed', 'ab.pdf', CC_Resource_Store::display_name( "a\r\nb\".pdf" ) );
check( 'empty name falls back', 'resource.pdf', CC_Resource_Store::display_name( '' ) );
check( 'only symbols falls back', 'resource.pdf', CC_Resource_Store::display_name( '***.pdf' ) );
check( 'long name is capped at 190 chars', 190, mb_strlen( CC_Resource_Store::display_name( str_repeat( 'a', 400 ) . '.pdf' ) ) );

$fails = count( $GLOBALS['t_fail'] );
echo "\n{$GLOBALS['t_pass']} passed, $fails failed\n";
exit( $fails > 0 ? 1 : 0 );
