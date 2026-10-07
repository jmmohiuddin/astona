<?php
/**
 * Integration tests for the gallery and results modules (CC_Gallery, CC_Results). Run inside the wpcli container:
 *   docker compose run --rm -T wpcli eval-file /tests/integration/gallery-test.php
 * Creates fixture posts, users and attachments and removes them afterwards. Result photos (private files) are covered by results-photo-test.php. Caps are granted with add_cap on throwaway users.
 */
global $wpdb, $failures, $checks, $cleanup;
$failures = 0;
$checks   = 0;
$cleanup  = array( 'users' => array(), 'posts' => array(), 'terms' => array() );

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

foreach ( array( 'CC_Gallery', 'CC_Results', 'CC_Audit' ) as $required ) {
	if ( ! class_exists( $required ) ) {
		echo "FAIL missing class $required\n";
		exit( 1 );
	}
}

$tag = substr( bin2hex( random_bytes( 4 ) ), 0, 8 );

function t_user( bool $with_cap ): int {
	global $cleanup;
	$tag = substr( bin2hex( random_bytes( 5 ) ), 0, 10 );
	$id  = wp_insert_user( array( 'user_login' => "zzgal$tag", 'user_pass' => wp_generate_password(), 'user_email' => "zzgal$tag@example.invalid", 'role' => 'subscriber' ) );
	if ( is_wp_error( $id ) ) {
		throw new RuntimeException( $id->get_error_message() );
	}
	if ( $with_cap ) {
		( new WP_User( $id ) )->add_cap( CC_Gallery::CAP );
	}
	$cleanup['users'][] = $id;
	return (int) $id;
}

function t_image( string $tag ): int {
	global $cleanup;
	$canvas = imagecreatetruecolor( 40, 30 );
	ob_start();
	imagejpeg( $canvas );
	$bytes  = (string) ob_get_clean();
	$upload = wp_upload_bits( "zz-gallery-test-$tag-" . count( $cleanup['posts'] ) . '.jpg', null, $bytes );
	$id     = wp_insert_attachment( array( 'post_mime_type' => 'image/jpeg', 'post_title' => 'zz test', 'post_status' => 'inherit' ), $upload['file'] );
	require_once ABSPATH . 'wp-admin/includes/image.php';
	wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $upload['file'] ) );
	$cleanup['posts'][] = (int) $id;
	return (int) $id;
}

function t_item( string $title, array $meta, string $status = 'publish' ): int {
	global $cleanup;
	$id = wp_insert_post( array( 'post_type' => CC_Gallery::POST_TYPE, 'post_title' => $title, 'post_status' => $status, 'meta_input' => $meta ) );
	$cleanup['posts'][] = (int) $id;
	return (int) $id;
}

function t_result( string $name, bool $verified, bool $consent, string $status = 'publish' ): int {
	global $cleanup;
	$id = wp_insert_post(
		array(
			'post_type' => CC_Results::POST_TYPE, 'post_title' => $name, 'post_status' => $status,
			'meta_input' => array(
				CC_Results::META_VERIFIED => $verified ? '1' : '0', CC_Results::META_CONSENT => $consent ? '1' : '0',
				CC_Results::META_YEAR => '2026', CC_Results::META_EXAM => 'HSC', CC_Results::META_SCORE => 'GPA 5.00',
			),
		)
	);
	$cleanup['posts'][] = (int) $id;
	return (int) $id;
}

function t_audit_count( string $action, int $entity_id ): int {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}cc_audit_log WHERE action = %s AND entity_type = 'result' AND entity_id = %d", $action, $entity_id ) );
}

function t_group_slugs(): array {
	return array_column( CC_Gallery::public_groups(), 'slug' );
}

try {
	$image = t_image( $tag );
	$alt   = "Zz alt $tag";

	echo "CPTs are not individually public\n";
	foreach ( array( CC_Gallery::POST_TYPE, CC_Results::POST_TYPE ) as $type ) {
		$object = get_post_type_object( $type );
		t_assert( $object && ! $object->public && ! $object->publicly_queryable && ! $object->show_in_rest && $object->exclude_from_search, "$type is not public, queryable or in REST" );
		wp_set_current_user( 0 );
		$response = rest_do_request( new WP_REST_Request( 'GET', "/wp/v2/$type" ) );
		t_assert( 404 === $response->get_status(), "anonymous REST /wp/v2/$type is 404" );
		t_assert( 404 === rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/' . strtoupper( $type ) . '/1' ) )->get_status(), "REST single route in other casing for $type is 404" );
	}
	$public_types = get_post_types( array( 'public' => true ) );
	t_assert( ! isset( $public_types[ CC_Gallery::POST_TYPE ] ) && ! isset( $public_types[ CC_Results::POST_TYPE ] ), 'neither CPT is a public post type, so core sitemaps skip them' );
	t_assert( ! taxonomy_exists( CC_Gallery::TAXONOMY ) || ! get_taxonomy( CC_Gallery::TAXONOMY )->public, 'gallery category taxonomy is not public' );

	echo "capabilities map to cc_manage_gallery\n";
	$staff = t_user( true );
	$other = t_user( false );
	$cats  = array( 'edit_posts', 'edit_others_posts', 'publish_posts', 'delete_posts', 'create_posts', 'read_private_posts' );
	foreach ( array( CC_Gallery::POST_TYPE, CC_Results::POST_TYPE ) as $type ) {
		$caps = get_post_type_object( $type )->cap;
		$same = true;
		foreach ( $cats as $c ) {
			$same = $same && CC_Gallery::CAP === $caps->$c;
		}
		t_assert( $same, "$type primitive capabilities are all cc_manage_gallery" );
	}
	$probe = t_item( "Zz cap probe $tag", array(), 'draft' );
	t_assert( user_can( $staff, 'edit_post', $probe ) && user_can( $staff, 'delete_post', $probe ), 'user with cc_manage_gallery can edit and delete gallery items' );
	t_assert( ! user_can( $other, 'edit_post', $probe ) && ! user_can( $other, 'publish_post', $probe ), 'user without it cannot edit or publish' );
	t_assert( user_can( $staff, get_taxonomy( CC_Gallery::TAXONOMY )->cap->manage_terms ) && ! user_can( $other, get_taxonomy( CC_Gallery::TAXONOMY )->cap->manage_terms ), 'gallery category management needs the cap' );

	echo "alt text and image are required to publish\n";
	$no_alt = t_item( "Zz no alt $tag", array( CC_Gallery::META_IMAGE => $image ) );
	t_assert( 'draft' === get_post_status( $no_alt ), 'published item without alt text is forced to draft' );
	$blank_alt = t_item( "Zz blank alt $tag", array( CC_Gallery::META_IMAGE => $image, CC_Gallery::META_ALT => '   ' ) );
	t_assert( 'draft' === get_post_status( $blank_alt ), 'whitespace-only alt text is forced to draft' );
	$no_image = t_item( "Zz no image $tag", array( CC_Gallery::META_ALT => $alt ) );
	t_assert( 'draft' === get_post_status( $no_image ), 'published item without an image is forced to draft' );
	$bad_image = t_item( "Zz bad image $tag", array( CC_Gallery::META_ALT => $alt, CC_Gallery::META_IMAGE => $probe ) );
	t_assert( 'draft' === get_post_status( $bad_image ), 'image id that is not an image attachment is forced to draft' );
	$good = t_item( "Zz good $tag", array( CC_Gallery::META_IMAGE => $image, CC_Gallery::META_ALT => $alt ) );
	t_assert( 'publish' === get_post_status( $good ), 'item with image and alt text publishes' );
	wp_update_post( array( 'ID' => $good, 'post_status' => 'draft' ) );
	delete_post_meta( $good, CC_Gallery::META_ALT );
	wp_update_post( array( 'ID' => $good, 'post_status' => 'publish' ) );
	t_assert( 'draft' === get_post_status( $good ), 're-publishing after the alt text was removed is refused' );
	update_post_meta( $good, CC_Gallery::META_ALT, $alt );
	wp_update_post( array( 'ID' => $good, 'post_status' => 'publish' ) );
	t_assert( 'publish' === get_post_status( $good ), 'publishing works again once alt text is back' );

	echo "metabox save and notice\n";
	wp_set_current_user( $staff );
	$_POST = array(
		CC_Gallery::NONCE_FIELD => wp_create_nonce( CC_Gallery::NONCE_ACTION ),
		'cc_gallery_image'      => (string) $image, 'cc_gallery_alt' => '', 'cc_gallery_caption' => 'Cap <b>x</b>', 'cc_gallery_order' => '7',
	);
	$form = t_item( "Zz form $tag", array(), 'draft' );
	wp_update_post( array( 'ID' => $form, 'post_status' => 'publish' ) );
	t_assert( 'draft' === get_post_status( $form ), 'form save with empty alt text keeps the item a draft' );
	t_assert( 'Cap x' === get_post_meta( $form, CC_Gallery::META_CAPTION, true ) && '7' === (string) get_post_meta( $form, CC_Gallery::META_ORDER, true ), 'caption is sanitised and order saved' );
	t_assert( str_contains( (string) apply_filters( 'redirect_post_location', 'post.php?post=1&message=6', $form ), 'cc_gallery_notice=alt_required' ), 'redirect carries the alt-required notice instead of the published message' );
	$_POST['cc_gallery_alt'] = $alt;
	wp_update_post( array( 'ID' => $form, 'post_status' => 'publish' ) );
	t_assert( 'publish' === get_post_status( $form ) && $alt === get_post_meta( $form, CC_Gallery::META_ALT, true ), 'form save with alt text publishes' );
	$_POST = array( CC_Gallery::NONCE_FIELD => 'bad-nonce', 'cc_gallery_alt' => 'tampered' );
	wp_update_post( array( 'ID' => $form, 'post_title' => "Zz form $tag v2" ) );
	t_assert( $alt === get_post_meta( $form, CC_Gallery::META_ALT, true ), 'save with a bad nonce changes nothing' );
	$_POST = array();
	wp_set_current_user( $other );
	$_POST = array( CC_Gallery::NONCE_FIELD => wp_create_nonce( CC_Gallery::NONCE_ACTION ), 'cc_gallery_alt' => 'no cap' );
	CC_Gallery::save_metabox( $form );
	t_assert( $alt === get_post_meta( $form, CC_Gallery::META_ALT, true ), 'save without the capability changes nothing' );
	$_POST = array();
	wp_set_current_user( 0 );

	echo "empty categories are hidden\n";
	$term = wp_insert_term( "Zz Cat $tag", CC_Gallery::TAXONOMY, array( 'slug' => "zz-cat-$tag" ) );
	$cleanup['terms'][] = (int) $term['term_id'];
	t_assert( ! in_array( "zz-cat-$tag", t_group_slugs(), true ), 'category without items is not listed' );
	$draft_item = t_item( "Zz in cat $tag", array( CC_Gallery::META_IMAGE => $image, CC_Gallery::META_ALT => $alt ), 'draft' );
	wp_set_object_terms( $draft_item, array( (int) $term['term_id'] ), CC_Gallery::TAXONOMY );
	t_assert( ! in_array( "zz-cat-$tag", t_group_slugs(), true ), 'category with only a draft item is hidden' );
	wp_update_post( array( 'ID' => $draft_item, 'post_status' => 'publish' ) );
	t_assert( in_array( "zz-cat-$tag", t_group_slugs(), true ), 'category appears once an item is published' );
	$listed = array_values( array_filter( CC_Gallery::public_groups(), static fn( $g ) => "zz-cat-$tag" === $g['slug'] ) );
	t_assert( 1 === count( $listed ) && 1 === count( $listed[0]['items'] ) && $alt === $listed[0]['items'][0]['alt'] && ! empty( $listed[0]['items'][0]['full']['width'] ), 'listed item carries alt text and width/height' );
	wp_trash_post( $draft_item );
	t_assert( ! in_array( "zz-cat-$tag", t_group_slugs(), true ), 'category hides again when its item is trashed' );
	$all_ids = array_column( array_merge( ...array_column( CC_Gallery::public_groups(), 'items' ) ), 'id' );
	t_assert( ! in_array( $no_alt, $all_ids, true ) && ! in_array( $no_image, $all_ids, true ), 'drafts never reach the public list' );

	echo "results visibility matrix (verified x consent)\n";
	$matrix = array(
		'vc' => array( true, true, 'publish', true ),
		'v-' => array( true, false, 'publish', false ),
		'-c' => array( false, true, 'publish', false ),
		'--' => array( false, false, 'publish', false ),
		'vcd' => array( true, true, 'draft', false ),
	);
	$ids = array();
	foreach ( $matrix as $key => $row ) {
		$ids[ $key ] = t_result( "Zz Result {$key} $tag", $row[0], $row[1], $row[2] );
	}
	$names = array_column( CC_Results::public_records(), 'name' );
	foreach ( $matrix as $key => $row ) {
		t_assert( $row[3] === in_array( "Zz Result {$key} $tag", $names, true ), "record $key is " . ( $row[3] ? 'shown' : 'excluded entirely' ) );
	}
	$by_year = CC_Results::public_groups();
	t_assert( isset( $by_year[2026] ), 'public records are grouped by year' );

	echo "images of unpublished gallery items stay out of media surfaces\n";
	$photo_hidden = t_image( $tag . 'h' );
	$photo_public = t_image( $tag . 'p' );
	t_item( "Zz Photo Hidden $tag", array( CC_Gallery::META_IMAGE => $photo_hidden, CC_Gallery::META_ALT => 'alt' ), 'draft' );
	t_item( "Zz Photo Public $tag", array( CC_Gallery::META_IMAGE => $photo_public, CC_Gallery::META_ALT => 'alt' ) );
	$hidden_photos = CC_Results::hidden_photo_ids();
	t_assert( in_array( $photo_hidden, $hidden_photos, true ) && ! in_array( $photo_public, $hidden_photos, true ), 'only the draft item image is hidden' );
	wp_set_current_user( 0 );
	$media = new WP_Query( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post__in' => array( $photo_hidden, $photo_public ), 'fields' => 'ids' ) );
	t_assert( array( $photo_public ) === array_map( 'intval', $media->posts ), 'anonymous attachment query omits the hidden image' );
	t_assert( 404 === rest_do_request( new WP_REST_Request( 'GET', "/wp/v2/media/$photo_hidden" ) )->get_status(), 'REST media single for the hidden photo is 404' );
	t_assert( 404 === rest_do_request( new WP_REST_Request( 'GET', "/wp/v2/MEDIA/$photo_hidden" ) )->get_status(), 'REST media single in other casing is 404' );
	t_assert( 404 === rest_do_request( new WP_REST_Request( 'GET', "/wp/v2/media/$photo_public" ) )->get_status(), 'anonymous REST media single is closed entirely' );
	t_assert( 404 === rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/media' ) )->get_status(), 'anonymous REST media collection is closed entirely' );

	echo "logged-in non-managers still cannot reach hidden photos\n";
	wp_set_current_user( $other );
	t_assert( 404 === rest_do_request( new WP_REST_Request( 'GET', "/wp/v2/media/$photo_hidden" ) )->get_status(), 'logged-in REST media single for the hidden photo is 404' );
	t_assert( 404 === rest_do_request( new WP_REST_Request( 'HEAD', "/wp/v2/MEDIA/$photo_hidden" ) )->get_status(), 'logged-in HEAD in other casing is 404' );
	$collection = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/media' ) );
	t_assert( 200 === $collection->get_status() && ! in_array( $photo_hidden, array_column( (array) $collection->get_data(), 'id' ), true ), 'logged-in REST media collection omits the hidden photo' );
	$by_id = new WP_REST_Request( 'GET', '/wp/v2/media' );
	$by_id->set_param( 'include', array( $photo_hidden ) );
	t_assert( array() === (array) rest_do_request( $by_id )->get_data(), 'REST media ?include= of the hidden photo is empty' );
	t_assert( 0 === apply_filters( 'oembed_request_post_id', $photo_hidden ) && $photo_public === apply_filters( 'oembed_request_post_id', $photo_public ), 'oEmbed id dropped for the hidden photo only' );
	t_assert( false === CC_Results::guard_oembed_data( array( 'title' => 'x' ), get_post( $photo_hidden ) ), 'oEmbed response backstop drops the hidden photo' );
	t_assert( false === wp_get_attachment_url( $photo_hidden ) && false === wp_get_attachment_image_src( $photo_hidden ) && false !== wp_get_attachment_url( $photo_public ), 'file URL helpers print nothing for the hidden photo' );
	$_GET['attachment_id'] = (string) $photo_hidden;
	t_assert( false === CC_Results::guard_canonical_redirect( get_attachment_link( $photo_hidden ) ), 'canonical redirect from ?attachment_id= of the hidden photo is blocked' );
	$_GET['attachment_id'] = (string) $photo_public;
	t_assert( get_attachment_link( $photo_public ) === CC_Results::guard_canonical_redirect( get_attachment_link( $photo_public ) ), 'canonical redirect for the public photo still works' );
	unset( $_GET['attachment_id'] );
	$backup = $GLOBALS['wp_query'];
	$GLOBALS['wp_query'] = new WP_Query();
	$GLOBALS['wp_query']->is_attachment      = true;
	$GLOBALS['wp_query']->queried_object_id = $photo_hidden;
	$GLOBALS['wp_query']->queried_object    = get_post( $photo_hidden );
	t_assert( true === CC_Results::maybe_404() && is_404(), 'attachment page of the hidden photo is a 404' );
	$GLOBALS['wp_query'] = $backup;
	wp_set_current_user( $staff );
	t_assert( 200 === rest_do_request( new WP_REST_Request( 'GET', "/wp/v2/media/$photo_hidden" ) )->get_status(), 'manager can still open the hidden photo' );
	t_assert( 0 !== apply_filters( 'oembed_request_post_id', $photo_hidden ), 'manager is not blocked from the hidden photo' );
	wp_set_current_user( 0 );

	echo "hidden image ids: trashed, uncapped, uncached\n";
	$photo_trashed = t_image( $tag . 't' );
	$trashed       = t_item( "Zz Photo Trashed $tag", array( CC_Gallery::META_IMAGE => $photo_trashed, CC_Gallery::META_ALT => 'alt' ) );
	t_assert( ! in_array( $photo_trashed, CC_Results::hidden_photo_ids(), true ), 'image of a published item is visible' );
	wp_trash_post( $trashed );
	t_assert( in_array( $photo_trashed, CC_Results::hidden_photo_ids(), true ), 'image of a trashed item is hidden immediately' );
	wp_untrash_post( $trashed );
	wp_publish_post( $trashed );
	t_assert( ! in_array( $photo_trashed, CC_Results::hidden_photo_ids(), true ), 'restoring the item makes the image visible again without a stale cache' );
	delete_post_meta( $trashed, CC_Gallery::META_ALT );
	t_assert( in_array( $photo_trashed, CC_Results::hidden_photo_ids(), true ), 'removing the alt text hides the image immediately' );
	wp_delete_post( $trashed, true );
	t_assert( ! in_array( $photo_trashed, CC_Results::hidden_photo_ids(), true ), 'deleting the item drops its image from the hidden list' );
	$shared = t_image( $tag . 's' );
	t_item( "Zz Shared A $tag", array( CC_Gallery::META_IMAGE => $shared, CC_Gallery::META_ALT => 'alt' ), 'draft' );
	t_item( "Zz Shared B $tag", array( CC_Gallery::META_IMAGE => $shared, CC_Gallery::META_ALT => 'alt' ) );
	t_assert( ! in_array( $shared, CC_Results::hidden_photo_ids(), true ), 'an image also used by a public item stays visible' );
	$draft_gallery_photo = t_image( $tag . 'g' );
	t_item( "Zz Gallery Draft $tag", array( CC_Gallery::META_IMAGE => $draft_gallery_photo, CC_Gallery::META_ALT => 'alt' ), 'draft' );
	t_assert( in_array( $draft_gallery_photo, CC_Results::hidden_photo_ids(), true ), 'the image of a draft gallery item is hidden' );
	$legacy_probe = t_image( $tag . 'l' );
	t_result( "Zz Legacy Meta $tag", true, false );
	update_post_meta( $cleanup['posts'][ array_key_last( $cleanup['posts'] ) ], CC_Results::META_PHOTO_LEGACY, $legacy_probe );
	t_assert( in_array( $legacy_probe, CC_Results::hidden_photo_ids(), true ), 'a legacy result photo (meta not yet migrated to the private store) is hidden from the public' );
	delete_post_meta( $cleanup['posts'][ array_key_last( $cleanup['posts'] ) ], CC_Results::META_PHOTO_LEGACY );
	t_assert( ! in_array( $legacy_probe, CC_Results::hidden_photo_ids(), true ), 'once the legacy meta is gone (migrated) the attachment is no longer treated as a result photo' );

	echo "staff toggles are capability-gated and audited\n";
	$target = $ids['v-'];
	wp_set_current_user( $other );
	t_assert( false === CC_Results::set_flag( $target, 'consent', true ) && ! CC_Results::flag( $target, 'consent' ), 'user without the capability cannot confirm consent' );
	t_assert( 0 === t_audit_count( 'result.consent', $target ), 'refused toggle writes no audit row' );
	wp_set_current_user( $staff );
	t_assert( true === CC_Results::set_flag( $target, 'consent', true ) && CC_Results::flag( $target, 'consent' ), 'staff can confirm consent' );
	t_assert( 1 === t_audit_count( 'result.consent', $target ), 'consent change is audited once' );
	CC_Results::set_flag( $target, 'consent', true );
	t_assert( 1 === t_audit_count( 'result.consent', $target ), 'setting the same value again is not audited' );
	t_assert( in_array( "Zz Result v- $tag", array_column( CC_Results::public_records(), 'name' ), true ), 'record appears once verified and consented' );
	CC_Results::set_flag( $target, 'verified', false );
	t_assert( 1 === t_audit_count( 'result.verified', $target ), 'verified change is audited' );
	t_assert( ! in_array( "Zz Result v- $tag", array_column( CC_Results::public_records(), 'name' ), true ), 'record disappears again when verification is withdrawn' );
	CC_Results::set_flag( $target, 'verified', true );
	wp_update_post( array( 'ID' => $target, 'post_password' => 'secret' ) );
	t_assert( ! in_array( "Zz Result v- $tag", array_column( CC_Results::public_records(), 'name' ), true ) && ! CC_Results::is_public( $target ), 'password-protected result is not public even when published, verified and consented' );
	wp_update_post( array( 'ID' => $target, 'post_password' => '' ) );
	t_assert( in_array( "Zz Result v- $tag", array_column( CC_Results::public_records(), 'name' ), true ) && CC_Results::is_public( $target ), 'without the password it is public again' );
	CC_Results::set_flag( $target, 'verified', false );
	t_assert( false === CC_Results::set_flag( $target, 'bogus', true ), 'unknown flag is refused' );
	$audit_row = $wpdb->get_row( $wpdb->prepare( "SELECT actor_id, diff_hash FROM {$wpdb->prefix}cc_audit_log WHERE action = 'result.consent' AND entity_id = %d", $target ), ARRAY_A );
	t_assert( (int) $audit_row['actor_id'] === $staff && ! empty( $audit_row['diff_hash'] ), 'audit row records the actor and a diff hash' );
	wp_set_current_user( 0 );

	echo "results metabox save\n";
	wp_set_current_user( $staff );
	$form_result = t_result( "Zz Form Result $tag", false, false, 'draft' );
	$_POST       = array(
		CC_Results::NONCE_FIELD => wp_create_nonce( CC_Results::NONCE_ACTION ),
		'cc_result_exam' => 'SSC', 'cc_result_year' => '20x6', 'cc_result_score' => 'A+', 'cc_result_institution' => '',
		'cc_result_verified' => '1', 'cc_result_consent' => '1',
	);
	wp_update_post( array( 'ID' => $form_result, 'post_title' => "Zz Form Result $tag" ) );
	t_assert( CC_Results::flag( $form_result, 'verified' ) && CC_Results::flag( $form_result, 'consent' ), 'metabox sets both flags' );
	t_assert( 1 === t_audit_count( 'result.verified', $form_result ) && 1 === t_audit_count( 'result.consent', $form_result ), 'metabox flag changes are audited' );
	t_assert( '' === (string) get_post_meta( $form_result, CC_Results::META_YEAR, true ), 'invalid year is dropped' );
	unset( $_POST['cc_result_consent'] );
	wp_update_post( array( 'ID' => $form_result, 'post_title' => "Zz Form Result $tag" ) );
	t_assert( ! CC_Results::flag( $form_result, 'consent' ) && 2 === t_audit_count( 'result.consent', $form_result ), 'unticking consent clears it and is audited' );
	$_POST = array();
	wp_set_current_user( 0 );

	echo "seeder is idempotent\n";
	$count = static function () use ( $wpdb ): array {
		$menu  = wp_get_nav_menu_object( 'Primary' );
		$items = $menu ? array_map( static fn( $i ) => strtolower( $i->title ), (array) wp_get_nav_menu_items( $menu->term_id ) ) : array();
		return array(
			'gallery' => (int) wp_count_posts( CC_Gallery::POST_TYPE )->publish,
			'results' => count( get_posts( array( 'post_type' => CC_Results::POST_TYPE, 'post_status' => 'any', 'post_name__in' => array( 'result-demo-ayesha', 'result-demo-farhan', 'result-demo-nabila', 'result-demo-noconsent', 'result-demo-unverified' ), 'posts_per_page' => -1, 'fields' => 'ids' ) ) ),
			'seed_attachments' => count( get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'meta_key' => CC_Gallery::SEED_KEY_META, 'posts_per_page' => -1, 'fields' => 'ids' ) ) ),
			'pages'   => count( get_posts( array( 'post_type' => 'page', 'post_name__in' => array( 'gallery', 'results' ), 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids' ) ) ),
			'menu_gallery' => count( array_keys( $items, 'gallery', true ) ),
			'menu_results' => count( array_keys( $items, 'results', true ) ),
		);
	};
	do_action( 'cc_seed_extra' );
	$first = $count();
	do_action( 'cc_seed_extra' );
	$second = $count();
	t_assert( $first === $second, 'second seed run changes nothing' );
	t_assert( 5 === $first['results'] && 2 === $first['pages'] && 1 === $first['menu_gallery'] && 1 === $first['menu_results'], 'five sample results, two pages, one menu entry each' );
	t_assert( $first['gallery'] >= 6 && $first['seed_attachments'] >= 6, 'at least six gallery items with their own placeholder images' );
	$seed_groups = array_column( CC_Gallery::public_groups(), 'slug' );
	t_assert( array() === array_diff( array( 'campus', 'events', 'results' ), $seed_groups ), 'Campus, Events and Results categories all have seeded items' );
	$seed_names = array_column( CC_Results::public_records(), 'name' );
	t_assert( 3 === count( array_filter( $seed_names, static fn( $n ) => str_contains( $n, '(sample)' ) ) ) && ! in_array( 'Hidden Sample Not Consented', $seed_names, true ) && ! in_array( 'Hidden Sample Unverified', $seed_names, true ), 'seeded public results are the three verified and consented ones' );
	foreach ( array( 'gallery' => 'page-gallery.php', 'results' => 'page-results.php' ) as $slug => $template ) {
		$page = get_page_by_path( $slug );
		t_assert( $page && get_post_meta( $page->ID, '_wp_page_template', true ) === $template, "page $slug uses $template" );
	}
} finally {
	foreach ( $cleanup['posts'] as $post_id ) {
		wp_delete_post( $post_id, true );
	}
	foreach ( $cleanup['terms'] as $term_id ) {
		wp_delete_term( $term_id, CC_Gallery::TAXONOMY );
	}
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( $cleanup['users'] as $user_id ) {
		wp_delete_user( $user_id );
	}
	$wpdb->query( "DELETE FROM {$wpdb->prefix}cc_audit_log WHERE entity_type = 'result' AND entity_id NOT IN (SELECT ID FROM {$wpdb->posts})" ); // phpcs:ignore WordPress.DB.PreparedSQL
	$_POST = array();
}

echo "\n$checks checks, $failures failed\n";
exit( $failures ? 1 : 0 );
