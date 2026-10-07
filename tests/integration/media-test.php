<?php
/**
 * Integration tests for the Media module (rules + admin screen operations). Run inside the wpcli container:
 *   docker compose run --rm -T wpcli eval-file /tests/integration/media-test.php
 */
global $wpdb, $failures, $checks, $cleanup;
$p        = $wpdb->prefix;
$failures = 0;
$checks   = 0;
$cleanup  = array( 'users' => array(), 'attachments' => array(), 'posts' => array() );

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

function t_user( string $role ): WP_User {
	global $cleanup;
	$tag = substr( bin2hex( random_bytes( 5 ) ), 0, 10 );
	$id  = wp_insert_user( array( 'user_login' => "zzmed$tag", 'user_pass' => wp_generate_password(), 'user_email' => "zzmed$tag@example.invalid", 'role' => $role ) );
	if ( is_wp_error( $id ) ) {
		throw new RuntimeException( $id->get_error_message() );
	}
	$cleanup['users'][] = $id;
	return new WP_User( $id );
}

function t_image( string $type = 'jpeg', int $w = 800, int $h = 600 ): string {
	$im = imagecreatetruecolor( $w, $h );
	imagefilledrectangle( $im, 0, 0, $w, $h, imagecolorallocate( $im, 200, 40, 40 ) );
	ob_start();
	'png' === $type ? imagepng( $im ) : ( 'gif' === $type ? imagegif( $im ) : imagejpeg( $im ) );
	imagedestroy( $im );
	return (string) ob_get_clean();
}

/** Inserts an APP1 Exif segment (with a recognisable GPS-looking string) right after the JPEG SOI marker. */
function t_with_exif( string $jpeg, string $secret ): string {
	$payload = "Exif\0\0II*\0\x08\0\0\0" . $secret;
	return substr( $jpeg, 0, 2 ) . "\xFF\xE1" . pack( 'n', strlen( $payload ) + 2 ) . $payload . substr( $jpeg, 2 );
}

function t_track( $id ) {
	global $cleanup;
	if ( is_int( $id ) ) {
		$cleanup['attachments'][] = $id;
	}
	return $id;
}

function t_code( $result ): string {
	return is_wp_error( $result ) ? $result->get_error_code() : 'ok';
}

function t_die_throws(): callable {
	return static fn() => static function ( $message ) {
		throw new RuntimeException( 'wp_die' );
	};
}

function t_handler_dies( callable $handler ): bool {
	add_filter( 'wp_die_handler', $GLOBALS['t_die_filter'], 99 );
	try {
		$handler();
		return false;
	} catch ( RuntimeException $e ) {
		return 'wp_die' === $e->getMessage();
	} finally {
		remove_filter( 'wp_die_handler', $GLOBALS['t_die_filter'], 99 );
	}
}
$GLOBALS['t_die_filter'] = t_die_throws();

try {
	CC_Admin_Roles::apply();
	$staff = t_user( 'cc_staff' );
	$owner = t_user( 'cc_owner' );
	wp_set_current_user( $staff->ID );
	$alt = 'A red test banner';

	echo "capabilities\n";
	foreach ( array( 'cc_owner', 'cc_staff', 'cc_instructor', 'cc_student' ) as $role ) {
		t_assert( ! t_user( $role )->has_cap( 'upload_files' ), "$role lacks upload_files" );
	}

	echo "allowed uploads\n";
	$jpeg = t_image( 'jpeg' );
	foreach ( array( 'photo.jpg' => $jpeg, 'photo.png' => t_image( 'png' ), 'photo.gif' => t_image( 'gif' ) ) as $name => $bytes ) {
		$id = t_track( CC_Admin_Media::upload_bytes( $bytes, $name, $alt, $staff->ID ) );
		t_assert( is_int( $id ) && $id > 0, "$name accepted" );
	}
	$pdf = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";
	$pdf_id = t_track( CC_Admin_Media::upload_bytes( $pdf, 'brochure.pdf', '', $staff->ID ) );
	t_assert( is_int( $pdf_id ), 'pdf accepted without alt text' );

	echo "blocked uploads\n";
	$svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';
	t_assert( 'cc_media_type' === t_code( CC_Admin_Media::upload_bytes( $svg, 'logo.svg', $alt, $staff->ID ) ), 'svg refused' );
	t_assert( 'cc_media_type' === t_code( CC_Admin_Media::upload_bytes( $svg, 'logo.png', $alt, $staff->ID ) ), 'svg renamed to png refused' );
	t_assert( 'cc_media_type' === t_code( CC_Admin_Media::upload_bytes( '<html><body>x</body></html>', 'page.html', $alt, $staff->ID ) ), 'html refused' );
	t_assert( 'cc_media_type' === t_code( CC_Admin_Media::upload_bytes( '<?php system($_GET[1]);', 'shell.php', $alt, $staff->ID ) ), 'php refused' );
	t_assert( 'cc_media_type' === t_code( CC_Admin_Media::upload_bytes( "MZ\x90\x00\x03\x00\x00\x00", 'setup.exe', $alt, $staff->ID ) ), 'exe refused' );
	t_assert( 'cc_media_double_ext' === t_code( CC_Admin_Media::upload_bytes( $jpeg, 'shell.php.jpg', $alt, $staff->ID ) ), 'double extension php.jpg refused' );
	t_assert( 'cc_media_type' === t_code( CC_Admin_Media::upload_bytes( $jpeg, 'shell.jpg.php', $alt, $staff->ID ) ), 'double extension jpg.php refused' );
	t_assert( 'cc_media_mismatch' === t_code( CC_Admin_Media::upload_bytes( t_image( 'png' ), 'photo.jpg', $alt, $staff->ID ) ), 'png content with jpg extension refused' );
	t_assert( 'cc_media_mismatch' === t_code( CC_Admin_Media::upload_bytes( $pdf, 'photo.png', $alt, $staff->ID ) ), 'pdf content with png extension refused' );
	t_assert( 'cc_media_invalid' === t_code( CC_Admin_Media::upload_bytes( "GIF89a\x01\x00\x01\x00\x00\x00\x00;<?php system('id'); ?>", 'x.gif', $alt, $staff->ID ) ), 'polyglot GIF plus PHP refused' );

	echo "size limits\n";
	$big_png = t_image( 'png' ) . str_repeat( "\0", CC_Media_Rules::MAX_IMAGE_BYTES );
	t_assert( 'cc_media_size' === t_code( CC_Admin_Media::upload_bytes( $big_png, 'big.png', $alt, $staff->ID ) ), 'image over 5 MB refused' );
	$big_pdf = $pdf . str_repeat( ' ', CC_Media_Rules::MAX_PDF_BYTES );
	t_assert( 'cc_media_size' === t_code( CC_Admin_Media::upload_bytes( $big_pdf, 'big.pdf', '', $staff->ID ) ), 'pdf over 10 MB refused' );

	echo "alt text\n";
	t_assert( 'cc_media_alt_required' === t_code( CC_Admin_Media::upload_bytes( $jpeg, 'noalt.jpg', '', $staff->ID ) ), 'image without alt refused' );
	t_assert( 'cc_media_alt_required' === t_code( CC_Admin_Media::upload_bytes( $jpeg, 'noalt.jpg', "  \n ", $staff->ID ) ), 'whitespace alt refused' );
	$id = t_track( CC_Admin_Media::upload_bytes( $jpeg, 'alt.jpg', '<b>Hello</b> students', $staff->ID ) );
	t_assert( is_int( $id ) && 'Hello students' === get_post_meta( $id, '_wp_attachment_image_alt', true ), 'alt stored sanitised in _wp_attachment_image_alt' );
	t_assert( 'cc_media_alt_required' === t_code( CC_Admin_Media::update_alt( $id, '', $staff->ID ) ), 'clearing alt refused' );
	t_assert( true === CC_Admin_Media::update_alt( $id, 'New alt', $staff->ID ) && 'New alt' === get_post_meta( $id, '_wp_attachment_image_alt', true ), 'alt can be edited' );

	echo "re-encode, filenames and WebP\n";
	$secret = 'GPS-LAT-23.8103N-LON-90.4125E';
	$id     = t_track( CC_Admin_Media::upload_bytes( t_with_exif( $jpeg, $secret ), 'camera.jpg', $alt, $staff->ID ) );
	$path   = is_int( $id ) ? (string) get_attached_file( $id ) : '';
	$stored = '' !== $path ? (string) file_get_contents( $path ) : '';
	t_assert( '' !== $stored && 'image/jpeg' === ( new finfo( FILEINFO_MIME_TYPE ) )->buffer( $stored ), 'stored file is a JPEG' );
	t_assert( false === strpos( $stored, 'Exif' ) && false === strpos( $stored, $secret ), 'EXIF stripped after re-encode' );
	t_assert( 1 === preg_match( '/^astona-[a-f0-9]{16}\.jpg$/', basename( $path ) ), 'stored filename is random and sanitised' );
	t_assert( false === strpos( basename( $path ), 'camera' ), 'client filename is not kept' );
	$meta = wp_get_attachment_metadata( (int) $id );
	t_assert( ! empty( $meta['sizes'] ), 'intermediate sizes still generated' );
	t_assert( '' !== wp_get_attachment_image( (int) $id, 'medium' ) && false !== strpos( wp_get_attachment_image( (int) $id, 'medium' ), 'srcset' ), 'wp_get_attachment_image keeps srcset' );
	if ( CC_Media_Rules::webp_supported() ) {
		$siblings = (array) get_post_meta( (int) $id, CC_Media_Rules::WEBP_META, true );
		t_assert( array() !== $siblings && is_readable( $path . '.webp' ) && in_array( basename( $path ) . '.webp', $siblings, true ), 'WebP sibling named <full original filename>.webp' );
		$sibling_files = array_map( static fn( $n ) => dirname( $path ) . '/' . $n, $siblings );
	} else {
		echo "  skip WebP sibling (no WebP support in this image editor)\n";
		$sibling_files = array();
	}
	t_assert( 'cc_media_unreadable' === t_code( CC_Admin_Media::upload_bytes( "\xFF\xD8\xFF\xE0garbage", 'broken.jpg', $alt, $staff->ID ) ), 'corrupt image refused' );

	echo "WebP sibling safety\n";
	if ( CC_Media_Rules::webp_supported() ) {
		$victim = t_track( CC_Admin_Media::upload_bytes( $jpeg, 'victim.jpg', $alt, $staff->ID ) );
		$vpath  = (string) get_attached_file( (int) $victim );
		update_post_meta( (int) $victim, CC_Media_Rules::WEBP_META, array() );
		wp_delete_file( $vpath . '.webp' );
		file_put_contents( $vpath . '.webp', 'FOREIGN' );
		CC_Media_Rules::generate_webp_siblings( wp_get_attachment_metadata( (int) $victim ), (int) $victim );
		t_assert( 'FOREIGN' === file_get_contents( $vpath . '.webp' ), 'an existing unrecorded .webp is not overwritten' );
		t_assert( ! in_array( basename( $vpath ) . '.webp', (array) get_post_meta( (int) $victim, CC_Media_Rules::WEBP_META, true ), true ), 'the foreign file is not recorded as this attachment\'s sibling' );
		wp_delete_file( $vpath . '.webp' );
		CC_Media_Rules::generate_webp_siblings( wp_get_attachment_metadata( (int) $victim ), (int) $victim );
		t_assert( is_readable( $vpath . '.webp' ) && 'FOREIGN' !== file_get_contents( $vpath . '.webp' ), 'regeneration writes a missing sibling' );
		CC_Media_Rules::generate_webp_siblings( wp_get_attachment_metadata( (int) $victim ), (int) $victim );
		t_assert( in_array( basename( $vpath ) . '.webp', (array) get_post_meta( (int) $victim, CC_Media_Rules::WEBP_META, true ), true ), 'regeneration may overwrite its own recorded sibling' );
	} else {
		echo "  skip WebP overwrite protection (no WebP support)\n";
		$victim = t_track( CC_Admin_Media::upload_bytes( $jpeg, 'victim.jpg', $alt, $staff->ID ) );
		$vpath  = (string) get_attached_file( (int) $victim );
	}
	$bystander = dirname( $vpath ) . '/zz-bystander-' . bin2hex( random_bytes( 3 ) ) . '.webp';
	file_put_contents( $bystander, 'KEEP' );
	update_post_meta( (int) $victim, CC_Media_Rules::WEBP_META, array( basename( $bystander ), '../' . basename( $bystander ) ) );
	t_assert( true === CC_Admin_Media::remove( (int) $victim, $staff->ID ), 'attachment with a tampered sibling list deleted' );
	t_assert( 'KEEP' === (string) @file_get_contents( $bystander ), 'deletion removes only the attachment\'s own siblings' );
	wp_delete_file( $bystander );

	echo "pixel cap\n";
	$ihdr = static fn( int $w, int $h ): string => "\x89PNG\r\n\x1a\n" . pack( 'N', 13 ) . 'IHDR' . pack( 'NN', $w, $h ) . "\x08\x02\x00\x00\x00" . "\0\0\0\0" . pack( 'N', 0 ) . 'IEND' . "\0\0\0\0";
	t_assert( 'cc_media_dimensions' === t_code( CC_Admin_Media::upload_bytes( $ihdr( 9000, 100 ), 'wide.png', $alt, $staff->ID ) ), 'side over 8000 px refused before decoding' );
	t_assert( 'cc_media_dimensions' === t_code( CC_Admin_Media::upload_bytes( $ihdr( 7000, 6000 ), 'huge.png', $alt, $staff->ID ) ), 'over 40 megapixels refused before decoding' );
	$err = CC_Admin_Media::upload_bytes( $ihdr( 9000, 100 ), 'wide.png', $alt, $staff->ID );
	t_assert( is_wp_error( $err ) && false !== strpos( $err->get_error_message(), '8000' ), 'the refusal message names the limit' );

	echo "core upload path re-encodes\n";
	$admin  = t_user( 'administrator' );
	wp_set_current_user( $admin->ID );
	$core   = wp_tempnam( 'cc-core-upload.jpg' );
	file_put_contents( $core, t_with_exif( $jpeg, $secret ) );
	$result = apply_filters( 'wp_handle_upload', array( 'file' => $core, 'url' => 'http://x.invalid/a.jpg', 'type' => 'image/jpeg' ), 'upload' );
	$after  = (string) file_get_contents( $core );
	t_assert( empty( $result['error'] ) && 'image/jpeg' === CC_Media_Rules::sniff( $after ) && false === strpos( $after, 'Exif' ) && false === strpos( $after, $secret ), 'wp_handle_upload strips EXIF for an administrator' );
	file_put_contents( $core, $ihdr( 9000, 100 ) );
	$result = apply_filters( 'wp_handle_upload', array( 'file' => $core, 'url' => 'http://x.invalid/a.png', 'type' => 'image/png' ), 'upload' );
	t_assert( ! empty( $result['error'] ) && ! file_exists( $core ), 'wp_handle_upload refuses an oversized image and deletes the file' );
	file_put_contents( $core, $pdf );
	$result = apply_filters( 'wp_handle_upload', array( 'file' => $core, 'url' => 'http://x.invalid/a.pdf', 'type' => 'application/pdf' ), 'upload' );
	t_assert( empty( $result['error'] ) && $pdf === file_get_contents( $core ), 'non-image uploads pass through untouched' );
	wp_delete_file( $core );
	wp_set_current_user( $staff->ID );

	echo "uploads .htaccess\n";
	$htaccess = (string) wp_upload_dir( null, false )['basedir'] . '/.htaccess';
	$original = file_exists( $htaccess ) ? (string) file_get_contents( $htaccess ) : null;
	@unlink( $htaccess );
	CC_Media_Rules::ensure_uploads_htaccess();
	t_assert( CC_Media_Rules::HTACCESS === (string) @file_get_contents( $htaccess ), 'created with the execution-denying rules when missing' );
	t_assert( false !== strpos( CC_Media_Rules::HTACCESS, 'Require all denied' ) && false !== strpos( CC_Media_Rules::HTACCESS, 'Options -Indexes' ), 'rules deny php execution and listings' );
	file_put_contents( $htaccess, "# custom\n" );
	CC_Media_Rules::ensure_uploads_htaccess();
	t_assert( "# custom\n" === file_get_contents( $htaccess ), 'an existing file is left alone (idempotent)' );
	@unlink( $htaccess );
	t_track( CC_Admin_Media::upload_bytes( $jpeg, 'ht.jpg', $alt, $staff->ID ) );
	t_assert( CC_Media_Rules::HTACCESS === (string) @file_get_contents( $htaccess ), 'created again on upload' );
	if ( null === $original ) {
		file_put_contents( $htaccess, CC_Media_Rules::HTACCESS );
	} else {
		file_put_contents( $htaccess, $original );
	}

	echo "audit rows\n";
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}cc_audit_log WHERE action = 'media.upload' AND entity_id = %d", (int) $id ), ARRAY_A );
	t_assert( is_array( $row ) && (int) $staff->ID === (int) $row['actor_id'] && 'attachment' === $row['entity_type'], 'upload is audited with actor and entity' );
	t_assert( is_array( $row ) && false === strpos( wp_json_encode( $row ), $secret ) && false === strpos( wp_json_encode( $row ), 'JFIF' ), 'audit row holds no file contents' );

	echo "delete protection\n";
	$used = t_track( CC_Admin_Media::upload_bytes( $jpeg, 'used.jpg', $alt, $staff->ID ) );
	$post = wp_insert_post( array( 'post_title' => 'zz media test', 'post_status' => 'draft', 'post_type' => 'post' ) );
	$cleanup['posts'][] = $post;
	update_post_meta( $post, '_thumbnail_id', $used );
	t_assert( 'cc_media_in_use' === t_code( CC_Admin_Media::remove( $used, $staff->ID ) ), 'featured image cannot be deleted' );
	t_assert( 'cc_media_in_use' === t_code( CC_Admin_Media::remove( $used, $staff->ID, true ) ), 'staff cannot force deletion' );
	t_assert( false !== get_post( $used ), 'attachment still exists after refusals' );
	delete_post_meta( $post, '_thumbnail_id' );
	update_post_meta( $post, 'cc_gallery_image', $used );
	t_assert( 'cc_media_in_use' === t_code( CC_Admin_Media::remove( $used, $staff->ID ) ), 'gallery item reference blocks deletion' );
	delete_post_meta( $post, 'cc_gallery_image' );
	update_post_meta( $post, 'cc_result_photo', $used );
	t_assert( array() === CC_Admin_Media::usages( $used ), 'legacy result-photo meta is not a media usage (result photos are private files, not attachments)' );
	delete_post_meta( $post, 'cc_result_photo' );
	update_post_meta( $post, 'cc_gallery_image', $used );
	t_assert( true === CC_Admin_Media::remove( $used, $owner->ID, true ), 'owner can force deletion' );
	t_assert( null === get_post( $used ) || false === get_post( $used ), 'forced delete removes the attachment' );
	$forced = $wpdb->get_row( $wpdb->prepare( "SELECT note FROM {$p}cc_audit_log WHERE action = 'media.delete' AND entity_id = %d", $used ), ARRAY_A );
	t_assert( is_array( $forced ) && 'forced' === $forced['note'], 'forced delete is audited as forced' );
	echo "delete protection: content references and trash\n";
	$ref    = t_track( CC_Admin_Media::upload_bytes( $jpeg, 'ref.jpg', $alt, $staff->ID ) );
	$ref_url = (string) wp_get_attachment_url( (int) $ref );
	$ref_sized = preg_replace( '/\.jpg$/', '-300x200.jpg', $ref_url );
	$mk_post = static function ( string $type, string $status, string $content ) use ( &$cleanup ): int {
		$pid = (int) wp_insert_post( array( 'post_title' => 'zz media ref', 'post_type' => $type, 'post_status' => $status, 'post_content' => $content ) );
		$cleanup['posts'][] = $pid;
		return $pid;
	};
	t_assert( array() === CC_Admin_Media::usages( (int) $ref ), 'unreferenced image has no usages' );
	$near = $mk_post( 'post', 'publish', '<img class="wp-image-' . ( (int) $ref * 10 ) . '" src="x">' );
	t_assert( array() === CC_Admin_Media::usages( (int) $ref ), 'wp-image-{id}0 is not a match for {id}' );
	$by_class = $mk_post( 'post', 'draft', '<img class="alignnone wp-image-' . (int) $ref . ' size-full" src="x">' );
	t_assert( 'cc_media_in_use' === t_code( CC_Admin_Media::remove( (int) $ref, $staff->ID ) ), 'wp-image class in a draft post blocks deletion' );
	wp_delete_post( $by_class, true );
	$by_url = $mk_post( 'page', 'private', '<img src="' . $ref_url . '">' );
	t_assert( 'cc_media_in_use' === t_code( CC_Admin_Media::remove( (int) $ref, $staff->ID ) ), 'attachment URL in a private page blocks deletion' );
	wp_delete_post( $by_url, true );
	$by_sized = $mk_post( 'post', 'publish', '<img src="' . $ref_sized . '">' );
	t_assert( 'cc_media_in_use' === t_code( CC_Admin_Media::remove( (int) $ref, $staff->ID ) ), 'a resized URL of the attachment blocks deletion' );
	wp_delete_post( $by_sized, true );
	$by_article = $mk_post( 'cc_article', 'trash', '<img class="wp-image-' . (int) $ref . '">' );
	$usage      = CC_Admin_Media::usages( (int) $ref );
	t_assert( 1 === count( $usage ) && $by_article === $usage[0]['post_id'] && 'cc_article' === $usage[0]['post_type'], 'a trashed cc_article embedding the image is reported' );
	t_assert( 'cc_media_in_use' === t_code( CC_Admin_Media::remove( (int) $ref, $staff->ID ) ), 'trashed content referrer blocks deletion' );
	wp_delete_post( $by_article, true );
	$trashed = $mk_post( 'post', 'trash', '' );
	update_post_meta( $trashed, '_thumbnail_id', (int) $ref );
	t_assert( 'cc_media_in_use' === t_code( CC_Admin_Media::remove( (int) $ref, $staff->ID ) ), 'a trashed post with the image as featured image blocks deletion' );
	t_assert( true === CC_Admin_Media::remove( (int) $ref, $owner->ID, true ), 'owner can still force deletion' );
	wp_delete_post( $trashed, true );
	wp_delete_post( $near, true );
	$free = t_track( CC_Admin_Media::upload_bytes( $jpeg, 'free.jpg', $alt, $staff->ID ) );
	t_assert( true === CC_Admin_Media::remove( (int) $free, $staff->ID ), 'unused file can be deleted by staff' );
	t_assert( 'cc_media_not_found' === t_code( CC_Admin_Media::remove( 999999999, $staff->ID ) ), 'unknown id reports not found' );
	if ( $sibling_files ) {
		t_assert( true === CC_Admin_Media::remove( (int) $id, $staff->ID ), 'file with WebP siblings deleted' );
		t_assert( array() === array_filter( $sibling_files, 'file_exists' ), 'WebP siblings removed with the attachment' );
	}

	echo "capability and nonce refusals\n";
	foreach ( array( 'cc_instructor', 'cc_student' ) as $role ) {
		$user = t_user( $role );
		t_assert( 'cc_forbidden' === t_code( CC_Admin_Media::upload_bytes( $jpeg, 'a.jpg', $alt, $user->ID ) ), "$role cannot upload" );
		t_assert( 'cc_forbidden' === t_code( CC_Admin_Media::update_alt( (int) $pdf_id, 'x', $user->ID ) ), "$role cannot edit alt" );
		t_assert( 'cc_forbidden' === t_code( CC_Admin_Media::remove( (int) $pdf_id, $user->ID ) ), "$role cannot delete" );
		wp_set_current_user( $user->ID );
		foreach ( array( 'handle_upload', 'handle_alt', 'handle_delete' ) as $handler ) {
			t_assert( t_handler_dies( array( 'CC_Admin_Media', $handler ) ), "$role refused by $handler (capability)" );
		}
		ob_start();
		t_assert( t_handler_dies( array( 'CC_Admin_Media', 'render' ) ), "$role refused by the Media screen" );
		ob_end_clean();
	}
	wp_set_current_user( $staff->ID );
	$_POST = array( 'id' => (int) $pdf_id );
	foreach ( array( 'handle_upload', 'handle_alt', 'handle_delete' ) as $handler ) {
		t_assert( t_handler_dies( array( 'CC_Admin_Media', $handler ) ), "staff without nonce refused by $handler" );
	}
	$_POST = array( 'id' => (int) $pdf_id, '_wpnonce' => 'bogus' );
	t_assert( t_handler_dies( array( 'CC_Admin_Media', 'handle_delete' ) ), 'staff with a bad nonce refused by handle_delete' );
	t_assert( false !== get_post( (int) $pdf_id ), 'refused handler left the file in place' );
	$_POST = array();

	echo "core upload filters\n";
	$mimes = apply_filters( 'upload_mimes', get_allowed_mime_types() );
	t_assert( ! isset( $mimes['svg'] ) && ! isset( $mimes['docx'] ) && isset( $mimes['pdf'] ) && isset( $mimes['webp'] ), 'upload_mimes narrowed to the allowlist for media managers' );
	$tmp = wp_tempnam( 'cc-media-test' );
	file_put_contents( $tmp, $svg );
	$filtered = apply_filters( 'wp_handle_upload_prefilter', array( 'name' => 'a.svg', 'tmp_name' => $tmp, 'size' => strlen( $svg ), 'error' => 0 ) );
	wp_delete_file( $tmp );
	t_assert( ! empty( $filtered['error'] ), 'upload prefilter rejects svg' );
	t_assert( 'http' === substr( CC_Admin_Media::pick_url(), 0, 4 ) && false !== strpos( CC_Admin_Media::pick_url(), 'page=cc-media' ), 'pick_url points at the media screen' );
} finally {
	foreach ( $cleanup['attachments'] as $id ) {
		wp_delete_attachment( (int) $id, true );
		$wpdb->delete( "{$p}cc_audit_log", array( 'entity_type' => 'attachment', 'entity_id' => (int) $id ) );
	}
	foreach ( $cleanup['posts'] as $id ) {
		wp_delete_post( (int) $id, true );
	}
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( $cleanup['users'] as $u ) {
		wp_delete_user( $u );
	}
}

echo "\n$checks checks, $failures failures\n";
exit( $failures ? 1 : 0 );
