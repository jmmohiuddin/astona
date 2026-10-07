<?php
/**
 * Dependency-free tests for the pure CC_Media_Rules helpers.
 * Run: php tests/unit/MediaRulesTest.php
 */

define( 'ABSPATH', __DIR__ . '/' );
require __DIR__ . '/../../wp-content/plugins/coaching-platform/includes/media/class-media-rules.php';

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

function code( string $name, string $mime, int $size ): string {
	return CC_Media_Rules::decide( $name, $mime, $size )['code'];
}

echo "allowed types\n";
foreach ( array( 'a.jpg' => 'image/jpeg', 'a.JPEG' => 'image/jpeg', 'a.png' => 'image/png', 'a.webp' => 'image/webp', 'a.gif' => 'image/gif', 'a.pdf' => 'application/pdf' ) as $name => $mime ) {
	check( "$name accepted", 'ok', code( $name, $mime, 1000 ) );
}
check( 'jpeg canonicalises to jpg', 'jpg', CC_Media_Rules::decide( 'x.jpeg', 'image/jpeg', 10 )['ext'] );
check( 'dotted stem is fine', 'ok', code( 'my.holiday.photo.jpg', 'image/jpeg', 10 ) );

echo "blocked types\n";
check( 'svg extension refused', 'type', code( 'a.svg', 'image/svg+xml', 100 ) );
check( 'svg content with png extension refused', 'type', code( 'a.png', 'image/svg+xml', 100 ) );
check( 'html refused', 'type', code( 'a.html', 'text/html', 100 ) );
check( 'php refused', 'type', code( 'a.php', 'text/x-php', 100 ) );
check( 'exe refused', 'type', code( 'a.exe', 'application/x-dosexec', 100 ) );
check( 'office refused', 'type', code( 'a.docx', 'application/zip', 100 ) );
check( 'no extension refused', 'type', code( 'photo', 'image/jpeg', 100 ) );
check( 'php content with jpg name refused', 'type', code( 'a.jpg', 'text/x-php', 100 ) );

echo "double extension and names\n";
check( 'shell.php.jpg refused', 'double_ext', code( 'shell.php.jpg', 'image/jpeg', 100 ) );
check( 'shell.jpg.php refused', 'type', code( 'shell.jpg.php', 'image/jpeg', 100 ) );
check( 'x.phtml.png refused', 'double_ext', code( 'x.phtml.png', 'image/png', 100 ) );
check( 'x.exe.pdf refused', 'double_ext', code( 'x.exe.pdf', 'application/pdf', 100 ) );
check( 'control character refused', 'type', code( "a.jpg\0.php", 'image/jpeg', 100 ) );
check( 'path in name uses basename', 'ok', code( '../../a.jpg', 'image/jpeg', 100 ) );

echo "extension vs sniffed mime\n";
check( 'png content named jpg refused', 'mismatch', code( 'a.jpg', 'image/png', 100 ) );
check( 'pdf content named png refused', 'mismatch', code( 'a.png', 'application/pdf', 100 ) );
check( 'gif named webp refused', 'mismatch', code( 'a.webp', 'image/gif', 100 ) );

echo "size limits\n";
check( 'image at 5 MB accepted', 'ok', code( 'a.png', 'image/png', 5242880 ) );
check( 'image over 5 MB refused', 'size', code( 'a.png', 'image/png', 5242881 ) );
check( 'pdf at 10 MB accepted', 'ok', code( 'a.pdf', 'application/pdf', 10485760 ) );
check( 'pdf over 10 MB refused', 'size', code( 'a.pdf', 'application/pdf', 10485761 ) );
check( 'empty refused', 'empty', code( 'a.png', 'image/png', 0 ) );
check( 'size message names the limit', 'File too large (max 5 MB).', CC_Media_Rules::decide( 'a.png', 'image/png', 6000000 )['message'] );

echo "helpers\n";
check( 'image mime detected', true, CC_Media_Rules::is_image_mime( 'image/webp' ) );
check( 'svg is not an image mime here', false, CC_Media_Rules::is_image_mime( 'image/svg+xml' ) );
check( 'pdf is not an image', false, CC_Media_Rules::is_image_mime( 'application/pdf' ) );
check( 'php tag detected case-insensitively', true, CC_Media_Rules::contains_php( "GIF89a<?PHP system('id');" ) );
check( 'plain bytes have no php tag', false, CC_Media_Rules::contains_php( "GIF89a\x00\x01<?=" ) );
check( 'exif marker detected', true, CC_Media_Rules::has_exif( "\xFF\xD8\xFF\xE1\x00\x10Exif\0\0II" ) );
check( 'no exif marker', false, CC_Media_Rules::has_exif( "\xFF\xD8\xFF\xE0\x00\x10JFIF\0" ) );
check( 'random filename shape', 1, preg_match( '/^astona-[a-f0-9]{16}\.jpg$/', CC_Media_Rules::random_filename( 'jpg' ) ) );
check( 'random filenames differ', true, CC_Media_Rules::random_filename( 'png' ) !== CC_Media_Rules::random_filename( 'png' ) );

echo "pixel cap\n";
check( '8000x5000 (40 MP) accepted', false, CC_Media_Rules::exceeds_pixel_cap( 8000, 5000 ) );
check( '8000x5001 refused', true, CC_Media_Rules::exceeds_pixel_cap( 8000, 5001 ) );
check( 'side of 8001 refused', true, CC_Media_Rules::exceeds_pixel_cap( 8001, 10 ) );
check( 'tall side of 8001 refused', true, CC_Media_Rules::exceeds_pixel_cap( 10, 8001 ) );
check( 'ordinary photo accepted', false, CC_Media_Rules::exceeds_pixel_cap( 4000, 3000 ) );

echo "uploads htaccess rules\n";
check( 'denies php-like extensions', true, 0 === strpos( CC_Media_Rules::HTACCESS, "<FilesMatch \"\\.(php|phtml|phar|php[0-9])$\">\n Require all denied\n</FilesMatch>\n" ) );
check( 'disables directory listing', true, false !== strpos( CC_Media_Rules::HTACCESS, "Options -Indexes\n" ) );

$fails = count( $GLOBALS['t_fail'] );
echo "\n{$GLOBALS['t_pass']} passed, $fails failed\n";
exit( $fails > 0 ? 1 : 0 );
