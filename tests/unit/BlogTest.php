<?php
/**
 * Dependency-free tests for the pure CC_Blog helpers.
 * Run: php tests/unit/BlogTest.php
 */

define( 'ABSPATH', __DIR__ . '/' );
require __DIR__ . '/../../wp-content/plugins/coaching-platform/includes/blog/class-blog.php';

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

echo "plain text\n";
check( 'tags are stripped', 'Hello world', CC_Blog::plain( '<p>Hello <strong>world</strong></p>' ) );
check( 'script blocks are dropped with their content', 'Hi', CC_Blog::plain( '<p>Hi</p><script>alert(1)</script>' ) );
check( 'style blocks are dropped', 'Hi', CC_Blog::plain( '<style>p{color:red}</style><p>Hi</p>' ) );
check( 'entities are decoded', 'Tom & Jerry', CC_Blog::plain( 'Tom &amp; Jerry' ) );
check( 'whitespace is collapsed', 'a b c', CC_Blog::plain( "a \n\t b   c " ) );

echo "trim\n";
check( 'short text is untouched', 'short', CC_Blog::trim_text( 'short', 160 ) );
check( 'exactly at the limit is untouched', str_repeat( 'a', 160 ), CC_Blog::trim_text( str_repeat( 'a', 160 ), 160 ) );
$long = str_repeat( 'word ', 60 );
check( 'long text is at most 160 characters', true, mb_strlen( CC_Blog::trim_text( $long, 160 ) ) <= 160 );
check( 'long text ends with an ellipsis', '…', mb_substr( CC_Blog::trim_text( $long, 160 ), -1 ) );
check( 'cuts on a word boundary', 'word word…', CC_Blog::trim_text( 'word word word word', 12 ) );
check( 'no spaces cuts hard', str_repeat( 'a', 9 ) . '…', CC_Blog::trim_text( str_repeat( 'a', 50 ), 10 ) );
check( 'bangla is counted in characters', true, mb_strlen( CC_Blog::trim_text( str_repeat( 'বাংলা ', 80 ), 160 ) ) <= 160 );

echo "description fallbacks\n";
check( 'meta description wins', 'Custom', CC_Blog::description( 'Custom', 'Excerpt', '<p>Body</p>' ) );
check( 'excerpt is the fallback', 'Excerpt', CC_Blog::description( '', 'Excerpt', '<p>Body</p>' ) );
check( 'body is the last fallback', 'Body text', CC_Blog::description( '  ', '', '<p>Body <em>text</em></p>' ) );
check( 'nothing at all gives empty', '', CC_Blog::description( '', '', '' ) );
check( 'script-only meta falls through', 'Excerpt', CC_Blog::description( '<script>x()</script>', 'Excerpt', '' ) );
check( 'meta description is capped too', true, mb_strlen( CC_Blog::description( str_repeat( 'x ', 200 ), '', '' ) ) <= 160 );
check( 'html in meta is neutralised', 'bold', CC_Blog::description( '<b>bold</b>', '', '' ) );

echo "meta title\n";
check( 'plain title kept', 'My title', CC_Blog::clean_meta_title( 'My title' ) );
check( 'title is capped at 70', true, mb_strlen( CC_Blog::clean_meta_title( str_repeat( 'ab ', 60 ) ) ) <= 70 );
check( 'tags are removed from the title', 'Hi', CC_Blog::clean_meta_title( '<i>Hi</i>' ) );
check( 'blank stays blank', '', CC_Blog::clean_meta_title( '   ' ) );

echo "language\n";
check( 'bengali text is bn', 'bn', CC_Blog::lang_for( 'ভর্তি প্রস্তুতি' ) );
check( 'mixed text is bn', 'bn', CC_Blog::lang_for( 'IELTS ও ভর্তি' ) );
check( 'english text has no override', '', CC_Blog::lang_for( 'Study tips' ) );

echo "state\n";
check( 'publish is published', 'published', CC_Blog::state_for( 'publish', false ) );
check( 'publish and archived is archived', 'archived', CC_Blog::state_for( 'publish', true ) );
check( 'future is scheduled', 'scheduled', CC_Blog::state_for( 'future', false ) );
check( 'draft is draft', 'draft', CC_Blog::state_for( 'draft', false ) );
check( 'pending is draft', 'draft', CC_Blog::state_for( 'pending', false ) );

$fails = count( $GLOBALS['t_fail'] );
echo "\n{$GLOBALS['t_pass']} passed, $fails failed\n";
exit( $fails > 0 ? 1 : 0 );
