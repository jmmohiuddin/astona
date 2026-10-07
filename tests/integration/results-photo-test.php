<?php
/**
 * Integration tests for private result photos (CC_Result_Photo_Store, CC_Result_Photo_Access, CC_Result_Photo_Migration,
 * the cc_result metabox photo control). Run inside the wpcli container:
 *   docker compose run --rm -T wpcli eval-file /tests/integration/results-photo-test.php
 * Creates fixture posts, attachments, users and private files and removes them afterwards. The HTTP side (route, headers,
 * uniform 404 bodies, nothing reachable under /wp-content/uploads) is covered by tests/e2e/results-photo.sh.
 */
global $wpdb, $failures, $checks, $cleanup;
$failures = 0;
$checks   = 0;
$cleanup  = array( 'users' => array(), 'posts' => array(), 'files' => array() );

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

foreach ( array( 'CC_Gallery', 'CC_Results', 'CC_Audit', 'CC_Result_Photo_Store', 'CC_Result_Photo_Access', 'CC_Result_Photo_Migration', 'CC_Photo_Store' ) as $required ) {
	if ( ! class_exists( $required ) ) {
		echo "FAIL missing class $required\n";
		exit( 1 );
	}
}
require_once ABSPATH . 'wp-admin/includes/image.php';

$tag  = substr( bin2hex( random_bytes( 4 ) ), 0, 8 );
$base = CC_Photo_Store::base_dir();
if ( null === $base ) {
	echo "FAIL CC_PRIVATE_DIR is not available in this environment\n";
	exit( 1 );
}
$uploads = (string) wp_upload_dir( null, false )['basedir'];

function t_user( bool $with_cap ): int {
	global $cleanup;
	$tag = substr( bin2hex( random_bytes( 5 ) ), 0, 10 );
	$id  = wp_insert_user( array( 'user_login' => "zzrp$tag", 'user_pass' => wp_generate_password(), 'user_email' => "zzrp$tag@example.invalid", 'role' => 'subscriber' ) );
	if ( is_wp_error( $id ) ) {
		throw new RuntimeException( $id->get_error_message() );
	}
	if ( $with_cap ) {
		( new WP_User( $id ) )->add_cap( CC_Gallery::CAP );
	}
	$cleanup['users'][] = $id;
	return (int) $id;
}

function t_jpeg( int $w = 120, int $h = 90, bool $exif = false ): string {
	$canvas = imagecreatetruecolor( $w, $h );
	imagefill( $canvas, 0, 0, imagecolorallocate( $canvas, 30, 90, 160 ) );
	ob_start();
	imagejpeg( $canvas, null, 85 );
	$bytes = (string) ob_get_clean();
	if ( $exif ) {
		$payload = "Exif\0\0" . str_repeat( 'x', 20 );
		$bytes   = substr( $bytes, 0, 2 ) . "\xFF\xE1" . pack( 'n', strlen( $payload ) + 2 ) . $payload . substr( $bytes, 2 );
	}
	return $bytes;
}

function t_encoded( callable $fn, int $w = 60, int $h = 40 ): string {
	$canvas = imagecreatetruecolor( $w, $h );
	ob_start();
	$fn( $canvas );
	return (string) ob_get_clean();
}

/** PNG signature + IHDR only: enough for sniffing and getimagesize, never decoded because the pixel cap refuses it first. */
function t_png_header( int $w, int $h ): string {
	$chunk = static fn( string $type, string $data ): string => pack( 'N', strlen( $data ) ) . $type . $data . pack( 'N', crc32( $type . $data ) );
	return "\x89PNG\r\n\x1a\n" . $chunk( 'IHDR', pack( 'NNCCCCC', $w, $h, 8, 2, 0, 0, 0 ) ) . $chunk( 'IEND', '' );
}

function t_store( string $bytes ): string {
	global $cleanup;
	$stored = CC_Result_Photo_Store::store_bytes( $bytes );
	if ( is_wp_error( $stored ) ) {
		throw new RuntimeException( 'fixture photo not stored: ' . $stored->get_error_message() );
	}
	$cleanup['files'][] = $stored;
	return $stored;
}

function t_result( string $name, bool $verified, bool $consent, string $status = 'publish', ?string $photo = null ): int {
	global $cleanup;
	$meta = array(
		CC_Results::META_VERIFIED => $verified ? '1' : '0', CC_Results::META_CONSENT => $consent ? '1' : '0',
		CC_Results::META_YEAR => '2026', CC_Results::META_EXAM => 'HSC', CC_Results::META_SCORE => 'GPA 5.00',
	);
	if ( null !== $photo ) {
		$meta[ CC_Results::META_PHOTO_PATH ] = $photo;
		$meta[ CC_Results::META_PHOTO_ALT ]  = "Photo of $name";
	}
	$id = wp_insert_post( array( 'post_type' => CC_Results::POST_TYPE, 'post_title' => $name, 'post_status' => $status, 'meta_input' => $meta ) );
	$cleanup['posts'][] = (int) $id;
	return (int) $id;
}

function t_parts( string $url ): array {
	preg_match( '#/' . CC_Result_Photo_Access::URL_PREFIX . '/(.*)$#', $url, $m );
	preg_match( CC_Result_Photo_Access::REQUEST_PATTERN, $m[1] ?? '', $p );
	return array( (int) ( $p[1] ?? 0 ), (int) ( $p[2] ?? 0 ), (string) ( $p[3] ?? '' ), (string) ( $p[4] ?? '' ) );
}

function t_resolve( string $url, ?int $now = null ): ?array {
	list( $id, $expiry, $sig, $ext ) = t_parts( $url );
	return CC_Result_Photo_Access::resolve( $id, $expiry, $sig, $ext, $now );
}

function t_audit_count( string $action, int $entity_id ): int {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}cc_audit_log WHERE action = %s AND entity_type = 'result' AND entity_id = %d", $action, $entity_id ) );
}

function t_private_files(): array {
	return (array) glob( CC_Photo_Store::base_dir() . '/' . CC_Result_Photo_Store::SUBDIR . '/*' );
}

/** @return string[] Original, every generated size and each ".webp" sibling of an attachment. */
function t_attachment_files( int $id ): array {
	$path  = (string) get_attached_file( $id );
	$meta  = wp_get_attachment_metadata( $id );
	$names = array( basename( $path ) );
	foreach ( (array) ( $meta['sizes'] ?? array() ) as $size ) {
		$names[] = (string) $size['file'];
	}
	$files = array();
	foreach ( $names as $name ) {
		$files[] = dirname( $path ) . '/' . $name;
		$files[] = dirname( $path ) . '/' . $name . '.webp';
	}
	return $files;
}

function t_legacy_attachment( string $tag, string $alt = '' ): int {
	global $cleanup;
	$upload = wp_upload_bits( "zz-rp-legacy-$tag-" . count( $cleanup['posts'] ) . '.jpg', null, t_jpeg( 400, 300 ) );
	$id     = wp_insert_attachment( array( 'post_mime_type' => 'image/jpeg', 'post_title' => 'zz legacy', 'post_status' => 'inherit' ), $upload['file'] );
	wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $upload['file'] ) );
	if ( '' !== $alt ) {
		update_post_meta( $id, '_wp_attachment_image_alt', $alt );
	}
	$cleanup['posts'][] = (int) $id;
	return (int) $id;
}

function t_legacy_result( string $name, $attachment ): int {
	global $cleanup;
	$id = wp_insert_post( array( 'post_type' => CC_Results::POST_TYPE, 'post_title' => $name, 'post_status' => 'publish', 'meta_input' => array( CC_Results::META_VERIFIED => '1', CC_Results::META_CONSENT => '1', CC_Results::META_PHOTO_LEGACY => $attachment ) ) );
	$cleanup['posts'][] = (int) $id;
	return (int) $id;
}

try {
	$staff = t_user( true );
	$other = t_user( false );

	echo "store rules: types\n";
	$jpeg   = t_store( t_jpeg( 120, 90, true ) );
	$png    = t_store( t_encoded( static fn( $c ) => imagepng( $c ) ) );
	$webp   = t_store( t_encoded( static fn( $c ) => imagewebp( $c ) ) );
	t_assert( 1 === preg_match( CC_Result_Photo_Store::PATH_PATTERN, $jpeg ) && str_ends_with( $jpeg, '.jpg' ), 'JPEG is stored under result-photos/ with a random 32-hex name' );
	t_assert( str_ends_with( $png, '.png' ) && str_ends_with( $webp, '.webp' ), 'PNG and WebP are accepted and keep their type' );
	t_assert( CC_Result_Photo_Store::store_bytes( t_encoded( static fn( $c ) => imagegif( $c ) ) ) instanceof WP_Error, 'GIF is refused' );
	t_assert( CC_Result_Photo_Store::store_bytes( '<html><body>x</body></html>' ) instanceof WP_Error, 'HTML is refused' );
	t_assert( CC_Result_Photo_Store::store_bytes( "%PDF-1.4\n%fake" ) instanceof WP_Error, 'PDF is refused' );
	t_assert( CC_Result_Photo_Store::store_bytes( '' ) instanceof WP_Error, 'empty upload is refused' );
	t_assert( CC_Result_Photo_Store::store_bytes( t_jpeg() . '<?php system($_GET[1]); ?>' ) instanceof WP_Error, 'image with an appended PHP payload is refused' );

	echo "store rules: size and pixels\n";
	$too_big = CC_Result_Photo_Store::store_bytes( str_repeat( 'a', CC_Result_Photo_Store::MAX_BYTES + 1 ) );
	t_assert( $too_big instanceof WP_Error && str_contains( $too_big->get_error_message(), '5 MB' ), 'more than 5 MB is refused with the size message' );
	$side = CC_Result_Photo_Store::store_bytes( t_encoded( static fn( $c ) => imagepng( $c ), 8001, 8 ) );
	t_assert( $side instanceof WP_Error && str_contains( $side->get_error_message(), '8000' ), 'more than 8000 px on a side is refused' );
	$mp = CC_Result_Photo_Store::store_bytes( t_png_header( 7000, 6000 ) );
	t_assert( $mp instanceof WP_Error && str_contains( $mp->get_error_message(), '40 megapixels' ), 'more than 40 megapixels is refused before decoding' );
	t_assert( 3 === count( array_intersect( t_private_files(), array( $base . '/' . $jpeg, $base . '/' . $png, $base . '/' . $webp ) ) ), 'refused uploads left no file behind' );

	echo "store rules: re-encode strips EXIF, WebP sibling\n";
	t_assert( CC_Media_Rules::has_exif( t_jpeg( 120, 90, true ) ), 'fixture really carries EXIF' );
	t_assert( ! CC_Media_Rules::has_exif( (string) file_get_contents( $base . '/' . $jpeg ) ), 'stored JPEG has no EXIF' );
	t_assert( 'image/jpeg' === ( new finfo( FILEINFO_MIME_TYPE ) )->file( $base . '/' . $jpeg ), 'stored JPEG is a JPEG' );
	t_assert( CC_Result_Photo_Store::has_webp_variant( $jpeg ) && 'image/webp' === ( new finfo( FILEINFO_MIME_TYPE ) )->file( $base . '/' . $jpeg . '.webp' ), 'JPEG gets a WebP sibling in the private dir' );
	t_assert( CC_Result_Photo_Store::has_webp_variant( $png ) && ! CC_Result_Photo_Store::has_webp_variant( $webp ), 'PNG gets a sibling, a WebP original needs none' );
	t_assert( '0640' === substr( sprintf( '%o', fileperms( $base . '/' . $jpeg ) ), -4 ), 'private file mode is 0640' );

	echo "private dir containment\n";
	$real_base = (string) realpath( $base );
	t_assert( str_starts_with( (string) realpath( $base . '/' . $jpeg ), $real_base . '/' . CC_Result_Photo_Store::SUBDIR . '/' ), 'file is inside CC_PRIVATE_DIR/result-photos' );
	$real_uploads = (string) realpath( $uploads );
	t_assert( ! str_starts_with( $real_base . '/', $real_uploads . '/' ) && ! str_starts_with( (string) realpath( $base . '/' . $jpeg ), $real_uploads . '/' ), 'file is NOT under the public uploads directory' );
	$name = basename( $jpeg, '.jpg' );
	$hits = trim( (string) shell_exec( 'find ' . escapeshellarg( $uploads ) . ' -name ' . escapeshellarg( $name . '*' ) . ' 2>/dev/null' ) );
	t_assert( '' === $hits, 'no file of that name exists anywhere under uploads (original or .webp)' );
	t_assert( null === CC_Result_Photo_Store::path( 'result-photos/../photos/' . basename( $jpeg ) ) && null === CC_Result_Photo_Store::path( '../' . $jpeg ) && null === CC_Result_Photo_Store::path( 'photos/' . basename( $jpeg ) ) && null === CC_Result_Photo_Store::path( $jpeg . '/../x.jpg' ), 'traversal and foreign-directory references resolve to nothing' );
	$outside = $base . '/zz-outside-' . $tag . '.jpg';
	copy( $base . '/' . $jpeg, $outside );
	$link_name = 'result-photos/' . bin2hex( random_bytes( 16 ) ) . '.jpg';
	symlink( $outside, $base . '/' . $link_name );
	t_assert( null === CC_Result_Photo_Store::path( $link_name ), 'a symlink pointing outside result-photos is refused by realpath containment' );
	unlink( $base . '/' . $link_name );
	unlink( $outside );

	echo "signed URLs\n";
	$state_ok = t_result( "Zz Signed $tag", true, true, 'publish', t_store( t_jpeg() ) );
	wp_set_current_user( 0 );
	$url = CC_Result_Photo_Access::signed_url( $state_ok );
	list( $rid, $expiry, $sig, $ext ) = t_parts( $url );
	t_assert( $rid === $state_ok && 64 === strlen( $sig ) && 'jpg' === $ext && $expiry > time() && $expiry <= time() + CC_Result_Photo_Access::TTL, 'URL shape is /cc-result-photo/{id}/{expiry}/{sig}.jpg with a 10 minute expiry' );
	t_assert( str_starts_with( $url, home_url( '/cc-result-photo/' ) ) && ! str_contains( $url, 'wp-content' ), 'URL is on the app route, not under wp-content' );
	$found = t_resolve( $url );
	t_assert( null !== $found && 'image/jpeg' === $found['mime'] && str_starts_with( $found['file'], $real_base . '/' ), 'valid URL resolves to the private JPEG' );
	$webp_url = CC_Result_Photo_Access::signed_url( $state_ok, CC_Result_Photo_Access::VARIANT_WEBP );
	$webp_hit = t_resolve( $webp_url );
	t_assert( str_ends_with( $webp_url, '.webp' ) && null !== $webp_hit && 'image/webp' === $webp_hit['mime'], 'WebP variant URL resolves to the WebP sibling' );
	t_assert( null === t_resolve( $url, $expiry + 1 ), 'expired URL is refused' );
	t_assert( null !== t_resolve( $url, $expiry ), 'URL is still valid at the exact expiry second' );
	$old = CC_Result_Photo_Access::signed_url( $state_ok, CC_Result_Photo_Access::VARIANT_ORIGINAL, -5 );
	t_assert( null === t_resolve( $old ), 'URL signed with a past expiry is refused' );
	$long = CC_Result_Photo_Access::signed_url( $state_ok, CC_Result_Photo_Access::VARIANT_ORIGINAL, 7200 );
	t_assert( null === t_resolve( $long ), 'a correctly signed URL with a lifetime beyond 10 minutes is refused' );
	$flip = ( 'a' === $sig[0] ? 'b' : 'a' ) . substr( $sig, 1 );
	t_assert( null === CC_Result_Photo_Access::resolve( $rid, $expiry, $flip, $ext ), 'tampered signature is refused' );
	t_assert( null === CC_Result_Photo_Access::resolve( $rid, $expiry + 5, $sig, $ext ), 'tampered expiry is refused' );
	t_assert( null === CC_Result_Photo_Access::resolve( $rid, $expiry, strtoupper( $sig ), $ext ), 'signature is compared exactly (case changed)' );
	$other_result = t_result( "Zz Signed Other $tag", true, true, 'publish', t_store( t_jpeg() ) );
	t_assert( null === CC_Result_Photo_Access::resolve( $other_result, $expiry, $sig, $ext ), 'signature of one result does not open another result' );
	t_assert( null === CC_Result_Photo_Access::resolve( $rid, $expiry, $sig, 'webp' ), 'orig signature does not open the webp variant' );
	t_assert( null === CC_Result_Photo_Access::resolve( $rid, $expiry, $sig, 'png' ), 'wrong extension is refused' );
	list( , $w_expiry, $w_sig ) = t_parts( $webp_url );
	t_assert( null === CC_Result_Photo_Access::resolve( $rid, $w_expiry, $w_sig, 'jpg' ), 'webp signature does not open the original' );
	t_assert( null === CC_Result_Photo_Access::resolve( 0, $expiry, $sig, $ext ) && null === CC_Result_Photo_Access::resolve( 999999999, $expiry, $sig, $ext ), 'unknown result id is refused' );
	t_assert( '' === CC_Result_Photo_Access::signed_url( t_result( "Zz No Photo $tag", true, true ) ), 'a result without a photo yields no URL' );
	$rules = get_option( 'rewrite_rules' );
	t_assert( is_array( $rules ) && array_key_exists( '^cc-result-photo(?:/(.*))?$', $rules ), 'rewrite rule for the route is registered' );
	t_assert( in_array( CC_Result_Photo_Access::QUERY_VAR, apply_filters( 'query_vars', array() ), true ), 'query var is registered' );

	echo "state matrix: verified x consent x status\n";
	$cases = array();
	foreach ( array( true, false ) as $verified ) {
		foreach ( array( true, false ) as $consent ) {
			foreach ( array( 'publish', 'draft', 'trash' ) as $status ) {
				$id      = t_result( "Zz M $tag " . (int) $verified . (int) $consent . $status, $verified, $consent, $status, t_store( t_jpeg() ) );
				$cases[] = array( $id, CC_Result_Photo_Access::signed_url( $id ), $verified && $consent && 'publish' === $status, "verified=" . (int) $verified . " consent=" . (int) $consent . " $status" );
			}
		}
	}
	foreach ( array( 'anonymous' => 0, 'logged-in without the capability' => $other ) as $who => $uid ) {
		wp_set_current_user( $uid );
		$wrong = array();
		foreach ( $cases as $case ) {
			if ( ( null !== t_resolve( $case[1] ) ) !== $case[2] ) {
				$wrong[] = $case[3];
			}
		}
		t_assert( array() === $wrong, "$who: streams only published + verified + consented (" . count( $cases ) . ' combinations)' . ( $wrong ? ' wrong: ' . implode( ', ', $wrong ) : '' ) );
	}
	wp_set_current_user( $staff );
	$blocked = array_filter( $cases, static fn( $c ) => null === t_resolve( $c[1] ) );
	t_assert( array() === $blocked, 'manager (cc_manage_gallery) can preview every state including draft and trash' );
	t_assert( 1 === preg_match( CC_Result_Photo_Access::REQUEST_PATTERN, "$rid/$expiry/$sig.$ext" ), 'canonical path matches the route pattern' );
	t_assert( 0 === preg_match( CC_Result_Photo_Access::REQUEST_PATTERN, "0$rid/$expiry/$sig.$ext" ) && 0 === preg_match( CC_Result_Photo_Access::REQUEST_PATTERN, "$rid/0$expiry/$sig.$ext" ), 'leading zeros in id or expiry are rejected by the route pattern' );
	wp_set_current_user( 0 );
	wp_update_post( array( 'ID' => $state_ok, 'post_password' => 'secret' ) );
	t_assert( null === t_resolve( $url ) && ! CC_Results::is_public( $state_ok ), 'password-protected result: not public, issued photo URL refused' );
	wp_update_post( array( 'ID' => $state_ok, 'post_password' => '' ) );
	t_assert( null !== t_resolve( $url ) && CC_Results::is_public( $state_ok ), 'removing the password makes it public again' );
	$forged = CC_Result_Photo_Access::resolve( $cases[1][0], time() + 60, str_repeat( 'a', 64 ), 'jpg' );
	t_assert( null === $forged, 'manager still needs a valid signature' );

	echo "immediate revocation\n";
	wp_set_current_user( 0 );
	$live = t_result( "Zz Live $tag", true, true, 'publish', t_store( t_jpeg() ) );
	$live_url = CC_Result_Photo_Access::signed_url( $live );
	$live_webp = CC_Result_Photo_Access::signed_url( $live, CC_Result_Photo_Access::VARIANT_WEBP );
	t_assert( null !== t_resolve( $live_url ) && null !== t_resolve( $live_webp ), 'URLs handed out while public work' );
	wp_set_current_user( $staff );
	CC_Results::set_flag( $live, 'consent', false );
	wp_set_current_user( 0 );
	t_assert( null === t_resolve( $live_url ) && null === t_resolve( $live_webp ), 'turning consent off stops already issued URLs at once' );
	wp_set_current_user( $staff );
	CC_Results::set_flag( $live, 'consent', true );
	CC_Results::set_flag( $live, 'verified', false );
	wp_set_current_user( 0 );
	t_assert( null === t_resolve( $live_url ), 'turning verification off stops issued URLs at once' );
	wp_set_current_user( $staff );
	CC_Results::set_flag( $live, 'verified', true );
	wp_set_current_user( 0 );
	t_assert( null !== t_resolve( $live_url ), 'restoring both flags makes the same URL work again (state is re-read per request)' );
	wp_trash_post( $live );
	t_assert( null === t_resolve( $live_url ), 'trashing the result stops issued URLs at once' );
	wp_untrash_post( $live );
	wp_publish_post( $live );
	t_assert( null !== t_resolve( $live_url ), 'restoring from trash works again' );
	wp_update_post( array( 'ID' => $live, 'post_status' => 'draft' ) );
	t_assert( null === t_resolve( $live_url ), 'unpublishing stops issued URLs at once' );

	echo "metabox photo operations (apply_photo_change)\n";
	wp_set_current_user( $other );
	$target = t_result( "Zz Ops $tag", true, true );
	$before = count( t_private_files() );
	$denied = CC_Results::apply_photo_change( $target, t_jpeg(), 'alt text', false );
	t_assert( $denied instanceof WP_Error && 'cc_forbidden' === $denied->get_error_code() && '' === CC_Results::photo_path( $target ) && $before === count( t_private_files() ), 'user without the capability cannot set a photo (no file, no meta)' );
	wp_set_current_user( $staff );
	$no_alt = CC_Results::apply_photo_change( $target, t_jpeg(), '   ', false );
	t_assert( $no_alt instanceof WP_Error && 'cc_result_photo_alt_required' === $no_alt->get_error_code() && '' === CC_Results::photo_path( $target ) && $before === count( t_private_files() ), 'a new photo without alt text is refused and nothing is written' );
	$bad = CC_Results::apply_photo_change( $target, 'not an image', 'alt', false );
	t_assert( $bad instanceof WP_Error && '' === CC_Results::photo_path( $target ), 'an invalid file is refused through the same path' );
	t_assert( true === CC_Results::apply_photo_change( $target, t_jpeg(), 'First <b>photo</b>', false ), 'staff can set a photo with alt text' );
	$first = CC_Results::photo_path( $target );
	$cleanup['files'][] = $first;
	t_assert( '' !== $first && is_file( $base . '/' . $first ) && is_file( $base . '/' . $first . '.webp' ) && 'First photo' === get_post_meta( $target, CC_Results::META_PHOTO_ALT, true ), 'photo and WebP sibling are stored, alt text is sanitised' );
	t_assert( 1 === t_audit_count( 'result.photo_set', $target ), 'setting the photo is audited once' );
	$audit = $wpdb->get_row( $wpdb->prepare( "SELECT actor_id, diff_hash, note FROM {$wpdb->prefix}cc_audit_log WHERE action = 'result.photo_set' AND entity_id = %d", $target ), ARRAY_A );
	t_assert( (int) $audit['actor_id'] === $staff && ! empty( $audit['diff_hash'] ) && null === $audit['note'], 'audit row has the actor and a size hash, no file data' );
	t_assert( true === CC_Results::apply_photo_change( $target, null, 'Updated alt', false ) && 'Updated alt' === get_post_meta( $target, CC_Results::META_PHOTO_ALT, true ), 'alt text can be changed without a new file' );
	$blank = CC_Results::apply_photo_change( $target, null, '', false );
	t_assert( $blank instanceof WP_Error && 'Updated alt' === get_post_meta( $target, CC_Results::META_PHOTO_ALT, true ), 'blanking the alt text while a photo exists is refused and the old text is kept' );
	t_assert( true === CC_Results::apply_photo_change( $target, t_jpeg( 50, 50 ), 'Second photo', false ), 'replacing the photo works' );
	$second = CC_Results::photo_path( $target );
	$cleanup['files'][] = $second;
	t_assert( $second !== $first && is_file( $base . '/' . $second ) && ! file_exists( $base . '/' . $first ) && ! file_exists( $base . '/' . $first . '.webp' ), 'replacing deletes the old private file including its WebP sibling' );
	t_assert( 1 === t_audit_count( 'result.photo_replace', $target ), 'replacement is audited' );
	t_assert( true === CC_Results::apply_photo_change( $target, null, 'Second photo', true ) && '' === CC_Results::photo_path( $target ) && '' === (string) get_post_meta( $target, CC_Results::META_PHOTO_ALT, true ), 'removing clears the meta' );
	t_assert( ! file_exists( $base . '/' . $second ) && ! file_exists( $base . '/' . $second . '.webp' ), 'removing deletes the private files including the WebP sibling' );
	t_assert( 1 === t_audit_count( 'result.photo_remove', $target ), 'removal is audited' );
	t_assert( true === CC_Results::apply_photo_change( $target, null, '', true ), 'removing when there is no photo is a no-op' );

	echo "metabox save: nonce, capability, non-uploaded files\n";
	$form = t_result( "Zz Form $tag", true, true, 'draft', t_store( t_jpeg() ) );
	$form_path = CC_Results::photo_path( $form );
	$post_base = array( 'cc_result_exam' => 'SSC', 'cc_result_year' => '2026', 'cc_result_score' => 'A+', 'cc_result_institution' => '', CC_Results::PHOTO_ALT_FIELD => 'Form alt' );
	$_POST = array( CC_Results::NONCE_FIELD => 'bad-nonce', CC_Results::PHOTO_REMOVE_FIELD => '1' ) + $post_base;
	wp_update_post( array( 'ID' => $form, 'post_title' => "Zz Form $tag" ) );
	t_assert( $form_path === CC_Results::photo_path( $form ) && is_file( $base . '/' . $form_path ), 'bad nonce: photo untouched even with the remove box ticked' );
	wp_set_current_user( $other );
	$_POST = array( CC_Results::NONCE_FIELD => wp_create_nonce( CC_Results::NONCE_ACTION ), CC_Results::PHOTO_REMOVE_FIELD => '1' ) + $post_base;
	CC_Results::save_metabox( $form );
	t_assert( $form_path === CC_Results::photo_path( $form ), 'no capability: photo untouched' );
	wp_set_current_user( $staff );
	$tmp_fake = $base . '/zz-fake-upload-' . $tag . '.jpg';
	file_put_contents( $tmp_fake, t_jpeg() );
	$_FILES = array( CC_Results::PHOTO_FILE_FIELD => array( 'name' => 'x.jpg', 'type' => 'image/jpeg', 'tmp_name' => $tmp_fake, 'error' => UPLOAD_ERR_OK, 'size' => filesize( $tmp_fake ) ) );
	$_POST  = array( CC_Results::NONCE_FIELD => wp_create_nonce( CC_Results::NONCE_ACTION ) ) + $post_base;
	wp_update_post( array( 'ID' => $form, 'post_title' => "Zz Form $tag" ) );
	t_assert( $form_path === CC_Results::photo_path( $form ), 'a file that was not an HTTP upload (is_uploaded_file) is refused and the photo kept' );
	t_assert( is_string( get_transient( 'cc_result_photo_err_' . $staff ) ), 'the refusal is reported to the editor' );
	delete_transient( 'cc_result_photo_err_' . $staff );
	unlink( $tmp_fake );
	$_FILES = array();
	$_POST  = array( CC_Results::NONCE_FIELD => wp_create_nonce( CC_Results::NONCE_ACTION ), CC_Results::PHOTO_REMOVE_FIELD => '1' ) + $post_base;
	wp_update_post( array( 'ID' => $form, 'post_title' => "Zz Form $tag" ) );
	t_assert( '' === CC_Results::photo_path( $form ) && ! file_exists( $base . '/' . $form_path ), 'valid nonce + capability: the remove box deletes the photo files' );
	$_POST = array();

	echo "public records and metabox helpers\n";
	$pub = t_result( "Zz Pub $tag", true, true, 'publish', t_store( t_jpeg() ) );
	$rec = array_values( array_filter( CC_Results::public_records(), static fn( $r ) => $r['id'] === $pub ) );
	t_assert( 1 === count( $rec ) && true === $rec[0]['photo'] && "Photo of Zz Pub $tag" === $rec[0]['photo_alt'], 'public record exposes has-photo and alt text, never a file URL' );
	t_assert( ! is_numeric( get_post_meta( $pub, CC_Results::META_PHOTO_PATH, true ) ), 'photo meta is a relative private path, not an attachment id' );
	ob_start();
	$GLOBALS['post'] = get_post( $pub );
	CC_Results::multipart_form();
	t_assert( str_contains( (string) ob_get_clean(), 'multipart/form-data' ), 'result edit form is multipart' );

	echo "deletion cleanup\n";
	$del_path = t_store( t_jpeg() );
	$del      = t_result( "Zz Del $tag", true, true, 'publish', $del_path );
	wp_trash_post( $del );
	t_assert( is_file( $base . '/' . $del_path ), 'trashing keeps the private file (result can be restored)' );
	wp_delete_post( $del, true );
	t_assert( ! file_exists( $base . '/' . $del_path ) && ! file_exists( $base . '/' . $del_path . '.webp' ), 'permanently deleting the result deletes the private photo and its WebP sibling' );

	echo "legacy migration\n";
	ini_set( 'error_log', '/dev/null' ); // phpcs:ignore WordPress.PHP.IniSet
	$a1    = t_legacy_attachment( $tag, "Legacy alt $tag" );
	$a1_files = t_attachment_files( $a1 );
	$res_a = t_legacy_result( "Zz Legacy A $tag", $a1 );
	$res_b = t_legacy_result( "Zz Legacy B $tag", 0 );
	$a2    = t_legacy_attachment( $tag );
	$res_c = t_legacy_result( "Zz Legacy C $tag", $a2 );
	$gallery_item = wp_insert_post( array( 'post_type' => CC_Gallery::POST_TYPE, 'post_title' => "Zz Legacy Gallery $tag", 'post_status' => 'draft', 'meta_input' => array( CC_Gallery::META_IMAGE => $a2 ) ) );
	$cleanup['posts'][] = (int) $gallery_item;
	$a3    = t_legacy_attachment( $tag );
	$a3_files = t_attachment_files( $a3 );
	$res_d = t_legacy_result( "Zz Legacy D $tag", $a3 );
	$res_e = t_legacy_result( "Zz Legacy E $tag", $a3 );
	$a4    = t_legacy_attachment( $tag );
	foreach ( t_attachment_files( $a4 ) as $f ) {
		@unlink( $f ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}
	$res_f = t_legacy_result( "Zz Legacy F $tag", $a4 );
	t_assert( count( array_filter( $a1_files, 'file_exists' ) ) >= 3 && file_exists( $a1_files[1] ), 'legacy fixture has the original, generated sizes and a .webp sibling in public uploads' );
	CC_Results::flush_hidden_cache();
	t_assert( in_array( $a1, CC_Results::hidden_photo_ids(), true ) && in_array( $a3, CC_Results::hidden_photo_ids(), true ) && in_array( $a4, CC_Results::hidden_photo_ids(), true ), 'legacy result attachments are hidden from the public until migrated' );
	$summary = CC_Result_Photo_Migration::run();
	t_assert( array( 'migrated' => 3, 'cleared' => 1, 'kept_shared' => 1, 'failed' => 1 ) === $summary, 'first run: 3 migrated, 1 empty meta cleared, 1 kept (shared), 1 failed -> ' . wp_json_encode( $summary ) );
	$path_a = CC_Results::photo_path( $res_a );
	$cleanup['files'][] = $path_a;
	t_assert( '' !== $path_a && is_file( $base . '/' . $path_a ) && '' === (string) get_post_meta( $res_a, CC_Results::META_PHOTO_LEGACY, true ), 'result A: private photo set, old meta removed' );
	t_assert( "Legacy alt $tag" === get_post_meta( $res_a, CC_Results::META_PHOTO_ALT, true ), 'result A: alt text carried over from the attachment' );
	t_assert( null === get_post( $a1 ) || false === get_post( $a1 ), 'result A: old attachment deleted' );
	t_assert( array() === array_filter( $a1_files, 'file_exists' ), 'result A: original, generated sizes and .webp siblings are gone from public uploads' );
	t_assert( CC_Result_Photo_Store::has_webp_variant( $path_a ), 'result A: private copy has its own WebP sibling' );
	t_assert( 1 === t_audit_count( 'result.photo_migrate', $res_a ), 'result A: migration audited' );
	t_assert( '' === CC_Results::photo_path( $res_b ) && '' === (string) get_post_meta( $res_b, CC_Results::META_PHOTO_LEGACY, true ), 'result B: empty legacy meta removed, no photo' );
	$path_c = CC_Results::photo_path( $res_c );
	$cleanup['files'][] = $path_c;
	t_assert( '' !== $path_c && null !== get_post( $a2 ) && file_exists( (string) get_attached_file( $a2 ) ), 'result C: photo migrated but the attachment stays because a gallery item uses it' );
	$path_d = CC_Results::photo_path( $res_d );
	$path_e = CC_Results::photo_path( $res_e );
	$cleanup['files'][] = $path_d;
	$cleanup['files'][] = $path_e;
	t_assert( '' !== $path_d && '' !== $path_e && $path_d !== $path_e, 'results D and E sharing one attachment each get their own private copy' );
	t_assert( ( null === get_post( $a3 ) || false === get_post( $a3 ) ) && array() === array_filter( $a3_files, 'file_exists' ), 'the shared attachment is deleted once its last result migrated' );
	t_assert( '' === CC_Results::photo_path( $res_f ) && (string) $a4 === (string) get_post_meta( $res_f, CC_Results::META_PHOTO_LEGACY, true ) && null !== get_post( $a4 ), 'result F: unreadable file keeps legacy meta and attachment for a retry' );
	t_assert( '1' === (string) get_option( CC_Result_Photo_Migration::PENDING_OPTION ) && false !== wp_next_scheduled( CC_Result_Photo_Migration::RETRY_HOOK ), 'a failure sets the pending flag and schedules the retry event' );
	t_assert( in_array( $a4, CC_Results::hidden_photo_ids(), true ) && ! in_array( $a1, CC_Results::hidden_photo_ids(), true ), 'the failed photo stays hidden, migrated ones are no longer legacy' );
	wp_set_current_user( $staff );
	ob_start();
	CC_Result_Photo_Migration::render_notice();
	$notice = (string) ob_get_clean();
	t_assert( str_contains( $notice, 'could not be moved to private storage' ) && str_contains( $notice, 'Retry now' ) && str_contains( $notice, '_wpnonce=' ), 'gallery managers see the notice with a nonce-protected retry link' );
	wp_set_current_user( $other );
	ob_start();
	CC_Result_Photo_Migration::render_notice();
	t_assert( '' === (string) ob_get_clean(), 'users without cc_manage_gallery do not see it' );
	wp_set_current_user( 0 );
	$files_before = t_private_files();
	$again        = CC_Result_Photo_Migration::run();
	t_assert( array( 'migrated' => 0, 'cleared' => 0, 'kept_shared' => 0, 'failed' => 1 ) === $again, 'second run is a no-op apart from the still-failing one -> ' . wp_json_encode( $again ) );
	t_assert( $path_a === CC_Results::photo_path( $res_a ) && $files_before === t_private_files(), 'second run changes no photo and creates no files' );
	delete_post_meta( $res_f, CC_Results::META_PHOTO_LEGACY );
	t_assert( array( 'migrated' => 0, 'cleared' => 0, 'kept_shared' => 0, 'failed' => 0 ) === CC_Result_Photo_Migration::run(), 'with nothing left to migrate every counter is zero' );
	$rec_a = array_values( array_filter( CC_Results::public_records(), static fn( $r ) => $r['id'] === $res_a ) );
	t_assert( 1 === count( $rec_a ) && $rec_a[0]['photo'], 'migrated result shows its photo on the public list' );
	t_assert( null !== t_resolve( CC_Result_Photo_Access::signed_url( $res_a ) ), 'migrated photo streams through the signed route' );
	t_assert( (int) CC_Migrations::VERSION >= 8, 'db version 8 or later runs the migration on upgrade' );
	t_assert( false === get_option( CC_Result_Photo_Migration::PENDING_OPTION ) && false === wp_next_scheduled( CC_Result_Photo_Migration::RETRY_HOOK ), 'pending flag and retry event are cleared once nothing is left' );
	t_assert( 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND post_id IN (%d,%d,%d,%d,%d)", CC_Results::META_PHOTO_LEGACY, $res_a, $res_b, $res_c, $res_d, $res_e ) ), 'no legacy meta left on migrated results' );

	echo "migration locks and deferral\n";
	$held = new mysqli( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME );
	$a5   = t_legacy_attachment( $tag );
	$res_g = t_legacy_result( "Zz Legacy G $tag", $a5 );
	$held->query( "SELECT GET_LOCK('cc_rpm_$res_g', 0)" );
	$locked_run = CC_Result_Photo_Migration::run();
	t_assert( 0 === $locked_run['migrated'] && '' === CC_Results::photo_path( $res_g ) && (string) $a5 === (string) get_post_meta( $res_g, CC_Results::META_PHOTO_LEGACY, true ), 'a result locked by another process is skipped untouched' );
	t_assert( '1' === (string) get_option( CC_Result_Photo_Migration::PENDING_OPTION ), 'a skipped result keeps the migration pending' );
	$held->query( "SELECT RELEASE_LOCK('cc_rpm_$res_g')" );
	update_post_meta( $res_g, CC_Results::META_PHOTO_PATH, t_store( t_jpeg() ) );
	$files_mid = t_private_files();
	CC_Result_Photo_Migration::run();
	t_assert( $files_mid === t_private_files() && '' === (string) get_post_meta( $res_g, CC_Results::META_PHOTO_LEGACY, true ), 'a result that already has its private photo gets no second copy' );
	$cleanup['files'][] = CC_Results::photo_path( $res_g );

	$deferred = array();
	for ( $i = 0; $i <= CC_Result_Photo_Migration::INLINE_LIMIT; $i++ ) {
		$deferred[] = t_legacy_result( "Zz Legacy Deferred $i $tag", t_legacy_attachment( $tag ) );
	}
	CC_Migrations::run();
	t_assert( CC_Result_Photo_Migration::remaining_count() > CC_Result_Photo_Migration::INLINE_LIMIT && '' === CC_Results::photo_path( $deferred[0] ), 'more than the inline limit: the upgrade leaves the copy to the cron' );
	t_assert( '1' === (string) get_option( CC_Result_Photo_Migration::PENDING_OPTION ) && false !== wp_next_scheduled( CC_Result_Photo_Migration::RETRY_HOOK ), 'deferred work is flagged and scheduled' );
	do_action( CC_Result_Photo_Migration::RETRY_HOOK );
	foreach ( $deferred as $deferred_id ) {
		$cleanup['files'][] = CC_Results::photo_path( $deferred_id );
	}
	t_assert( '' !== CC_Results::photo_path( $deferred[0] ) && 0 === CC_Result_Photo_Migration::remaining_count() && false === get_option( CC_Result_Photo_Migration::PENDING_OPTION ), 'the retry event migrates everything and clears the flag' );

	$upgrade_lock = new mysqli( DB_HOST, DB_USER, DB_PASSWORD, DB_NAME );
	$upgrade_lock->query( "SELECT GET_LOCK('" . CC_Migrations::LOCK . "', 0)" );
	update_option( CC_Migrations::OPTION, '1' );
	CC_Migrations::maybe_upgrade();
	t_assert( '1' === get_option( CC_Migrations::OPTION ), 'maybe_upgrade skips while another request holds the migration lock' );
	$upgrade_lock->query( "SELECT RELEASE_LOCK('" . CC_Migrations::LOCK . "')" );
	CC_Migrations::maybe_upgrade();
	t_assert( CC_Migrations::VERSION === get_option( CC_Migrations::OPTION ), 'maybe_upgrade migrates once the lock is free' );
	$free = (int) $wpdb->get_var( "SELECT IS_FREE_LOCK('" . CC_Migrations::LOCK . "')" );
	t_assert( 1 === $free, 'the migration lock is released afterwards' );
	$held->close();
	$upgrade_lock->close();
	ini_restore( 'error_log' );

	echo "seeder uses the private store\n";
	do_action( 'cc_seed_extra' );
	$seeded = get_posts( array( 'post_type' => CC_Results::POST_TYPE, 'name' => 'result-demo-ayesha', 'post_status' => 'any', 'posts_per_page' => 1, 'fields' => 'ids' ) );
	$seed_path = $seeded ? CC_Results::photo_path( (int) $seeded[0] ) : '';
	t_assert( '' !== $seed_path && is_file( $base . '/' . $seed_path ) && '' === (string) get_post_meta( (int) $seeded[0], CC_Results::META_PHOTO_LEGACY, true ), 'seeded sample result has a private placeholder photo and no attachment' );
	$seed_before = t_private_files();
	do_action( 'cc_seed_extra' );
	t_assert( $seed_before === t_private_files() && $seed_path === CC_Results::photo_path( (int) $seeded[0] ), 'seeding again adds no photo' );

	echo "fail closed without CC_PRIVATE_DIR outside local\n";
	$script = tempnam( sys_get_temp_dir(), 'ccfc' );
	file_put_contents(
		$script,
		"<?php\ndefine('ABSPATH','/x/');\nclass WP_Error{public \$code;function __construct(\$c='',\$m='',\$d=''){\$this->code=\$c;}}\nfunction wp_get_environment_type(){return 'production';}\n"
		. "require '" . CC_PATH . "includes/admissions/class-photo-store.php';\nrequire '" . CC_PATH . "includes/gallery/class-result-photo-store.php';\n"
		. "echo json_encode([CC_Photo_Store::base_dir(), CC_Result_Photo_Store::store_bytes(str_repeat('a',100))->code, CC_Result_Photo_Store::path('result-photos/" . str_repeat( 'a', 32 ) . ".jpg')]);\n"
	);
	$out = json_decode( (string) shell_exec( 'env CC_PRIVATE_DIR= php ' . escapeshellarg( $script ) . ' 2>&1' ), true );
	unlink( $script );
	t_assert( array( null, 'server_error', null ) === $out, 'base_dir is null, storing fails with server_error and nothing is readable (never falls back into the web root)' );
} finally {
	foreach ( $cleanup['posts'] as $post_id ) {
		wp_delete_post( $post_id, true );
	}
	foreach ( $cleanup['files'] as $relative ) {
		CC_Result_Photo_Store::delete( $relative );
	}
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( $cleanup['users'] as $user_id ) {
		wp_delete_user( $user_id );
	}
	$wpdb->query( "DELETE FROM {$wpdb->prefix}cc_audit_log WHERE entity_type = 'result' AND entity_id NOT IN (SELECT ID FROM {$wpdb->posts})" ); // phpcs:ignore WordPress.DB.PreparedSQL
	$_POST  = array();
	$_FILES = array();
}

echo "\n$checks checks, $failures failed\n";
exit( $failures ? 1 : 0 );
