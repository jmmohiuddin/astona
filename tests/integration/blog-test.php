<?php
/**
 * Integration tests for the blog (CC_Blog). Run inside the wpcli container:
 *   docker compose run --rm -T wpcli eval-file /tests/integration/blog-test.php
 * Grants cc_manage_blog with add_cap on throwaway users; creates fixtures and removes them afterwards.
 */
global $wpdb, $failures, $checks, $cleanup;
$failures = 0;
$checks   = 0;
$cleanup  = array( 'posts' => array(), 'users' => array(), 'terms' => array() );

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

if ( ! class_exists( 'CC_Blog' ) || ! class_exists( 'CC_Audit' ) ) {
	echo "FAIL missing class CC_Blog or CC_Audit\n";
	exit( 1 );
}

function t_user( string $login, bool $cap ): int {
	global $cleanup;
	$id = wp_insert_user( array( 'user_login' => $login, 'user_pass' => wp_generate_password(), 'user_email' => $login . '@example.test', 'role' => 'subscriber' ) );
	$cleanup['users'][] = (int) $id;
	if ( $cap ) {
		( new WP_User( $id ) )->add_cap( CC_Blog::CAP );
	}
	return (int) $id;
}

function t_term( string $slug ): int {
	global $cleanup;
	$term = wp_insert_term( 'ZZ ' . $slug, CC_Blog::TAXONOMY, array( 'slug' => $slug ) );
	$id   = (int) ( is_wp_error( $term ) ? $term->get_error_data( 'term_exists' ) : $term['term_id'] );
	$cleanup['terms'][] = $id;
	return $id;
}

function t_article( string $title, string $status, array $extra = array() ): int {
	global $cleanup;
	$id = wp_insert_post( array_merge( array( 'post_type' => CC_Blog::POST_TYPE, 'post_title' => $title, 'post_content' => '<p>Body of ' . $title . '</p>', 'post_status' => $status ), $extra ) );
	$cleanup['posts'][] = (int) $id;
	return (int) $id;
}

function t_rest( string $method, string $route, array $params = array() ) {
	$request = new WP_REST_Request( $method, $route );
	foreach ( $params as $k => $v ) {
		$request->set_param( $k, $v );
	}
	return rest_do_request( $request );
}

function t_go( int $id ): void {
	$query = new WP_Query( array( 'p' => $id, 'post_type' => CC_Blog::POST_TYPE, 'post_status' => 'any' ) );
	$GLOBALS['wp_query']     = $query;
	$GLOBALS['wp_the_query'] = $query;
	$GLOBALS['post']         = get_post( $id );
	setup_postdata( $GLOBALS['post'] );
}

/** An embed request whose main query already holds the article (hiding in pre_get_posts did not apply). */
function t_go_unfiltered( int $id ): void {
	$post  = get_post( $id );
	$query = new WP_Query();
	$query->is_single        = true;
	$query->is_singular      = true;
	$query->is_embed         = true;
	$query->queried_object   = $post;
	$query->queried_object_id = $id;
	$query->posts            = array( $post );
	$query->post             = $post;
	$query->post_count       = 1;
	$GLOBALS['wp_query']     = $query;
	$GLOBALS['wp_the_query'] = $query;
	$GLOBALS['post']         = $post;
}

function t_ids( $items ): array {
	return array_map( 'intval', wp_list_pluck( (array) $items, 'id' ) );
}

$_SERVER['SERVER_NAME'] = $_SERVER['SERVER_NAME'] ?? 'localhost';
wp_set_current_user( 0 );
$tag     = (string) wp_rand( 100000, 999999 );
$leak    = 'zzstaffleak' . $tag;
$staff   = t_user( $leak, true );
$plain   = t_user( 'zzplain' . $tag, false );
$cat_a   = t_term( 'zz-cat-a-' . $tag );
$cat_b   = t_term( 'zz-cat-b-' . $tag );

echo "capabilities\n";
$pto = get_post_type_object( CC_Blog::POST_TYPE );
t_assert( $pto && true === $pto->map_meta_cap, 'map_meta_cap is on' );
$all_mapped = true;
foreach ( array( 'edit_posts', 'edit_others_posts', 'publish_posts', 'read_private_posts', 'delete_posts', 'delete_others_posts', 'delete_published_posts', 'edit_published_posts', 'create_posts' ) as $c ) {
	$all_mapped = $all_mapped && CC_Blog::CAP === ( $pto->cap->$c ?? '' );
}
t_assert( $all_mapped, 'every CPT capability maps to cc_manage_blog' );
t_assert( 'read' === $pto->cap->read, 'reading stays the core read capability' );
$draft = t_article( 'ZZ draft ' . $tag, 'draft', array( 'post_author' => $plain ) );
t_assert( user_can( $staff, 'edit_post', $draft ) && user_can( $staff, 'delete_post', $draft ), 'cc_manage_blog holder can edit and delete another user\'s article' );
t_assert( user_can( $staff, 'publish_post', $draft ), 'cc_manage_blog holder can publish' );
t_assert( ! user_can( $plain, 'edit_post', $draft ), 'user without the capability cannot edit even as the author' );
t_assert( ! user_can( $plain, 'publish_posts' ) && ! user_can( $plain, 'edit_posts' ), 'user without the capability has no generic post caps' );
$tax = get_taxonomy( CC_Blog::TAXONOMY );
t_assert( user_can( $staff, $tax->cap->manage_terms ) && ! user_can( $plain, $tax->cap->manage_terms ), 'category management follows the capability' );
t_assert( 'blog' === $pto->has_archive && 'blog' === $pto->rewrite['slug'] && 'blog/category' === $tax->rewrite['slug'], 'archive and rewrite slugs' );

echo "states and permalink visibility\n";
$pub    = t_article( 'ZZ published ' . $tag, 'publish', array( 'post_author' => $staff, 'post_excerpt' => 'Excerpt ' . $tag ) );
$future = t_article( 'ZZ scheduled ' . $tag, 'future', array( 'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS ), 'post_date' => get_date_from_gmt( gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS ) ) ) );
$arch   = t_article( 'ZZ archived ' . $tag, 'publish' );
CC_Blog::set_archived( $arch, true );
foreach ( array( $pub, $future, $arch, $draft ) as $id ) {
	wp_set_object_terms( $id, array( $cat_a ), CC_Blog::TAXONOMY );
}
t_assert( 'future' === get_post_status( $future ), 'future date stays scheduled' );
t_assert( 'published' === CC_Blog::state_for( get_post_status( $pub ), CC_Blog::is_archived( $pub ) ), 'state published' );
t_assert( 'scheduled' === CC_Blog::state_for( get_post_status( $future ), false ), 'state scheduled' );
t_assert( 'archived' === CC_Blog::state_for( get_post_status( $arch ), CC_Blog::is_archived( $arch ) ), 'state archived' );
t_assert( 'draft' === CC_Blog::state_for( get_post_status( $draft ), false ), 'state draft' );
t_assert( 'publish' === get_post_status( $arch ), 'archiving keeps the WordPress status' );
t_assert( CC_Blog::can_view( $pub, 0 ), 'anonymous can view a published article' );
foreach ( array( 'archived' => $arch, 'scheduled' => $future, 'draft' => $draft ) as $name => $id ) {
	t_assert( ! CC_Blog::can_view( $id, 0 ) && ! CC_Blog::can_view( $id, $plain ), "anonymous and capability-less users cannot view $name" );
	t_assert( CC_Blog::can_view( $id, $staff ), "manager can view $name" );
}
t_assert( in_array( $arch, CC_Blog::hidden_ids(), true ) && ! in_array( $pub, CC_Blog::hidden_ids(), true ), 'hidden_ids lists only archived' );

echo "archived hidden in queries\n";
$q = new WP_Query( array( 'post_type' => CC_Blog::POST_TYPE, 'posts_per_page' => 100, 'fields' => 'ids' ) );
t_assert( in_array( $pub, $q->posts, true ) && ! in_array( $arch, $q->posts, true ) && ! in_array( $future, $q->posts, true ) && ! in_array( $draft, $q->posts, true ), 'archive query lists published only' );
$q = new WP_Query( array( 'post_type' => CC_Blog::POST_TYPE, 'post_status' => 'any', 'posts_per_page' => 100, 'fields' => 'ids' ) );
t_assert( ! in_array( $arch, $q->posts, true ), 'post_status any still hides archived' );
$q = new WP_Query( array( 'post_type' => CC_Blog::POST_TYPE, 'p' => $arch ) );
t_assert( ! $q->have_posts(), 'p=ID query hides archived' );
$q = new WP_Query( array( 'post_type' => CC_Blog::POST_TYPE, 'post__in' => array( $arch, $pub ), 'fields' => 'ids' ) );
t_assert( array( $pub ) === array_map( 'intval', $q->posts ), 'post__in drops archived' );
$q = new WP_Query( array( 'post_type' => CC_Blog::POST_TYPE, 'post__in' => array( $arch ) ) );
t_assert( ! $q->have_posts(), 'post__in with only archived is empty' );
t_assert( ! in_array( $arch, get_posts( array( 'post_type' => CC_Blog::POST_TYPE, 'numberposts' => 100, 'fields' => 'ids' ) ), true ), 'get_posts hides archived' );
t_assert( ! in_array( $arch, get_posts( array( 'post_type' => 'any', 'numberposts' => 200, 'fields' => 'ids' ) ), true ), 'get_posts post_type any hides archived' );
t_assert( ! in_array( $arch, get_posts( array( 'post_type' => CC_Blog::POST_TYPE, 'include' => array( $arch ), 'fields' => 'ids' ) ), true ), 'get_posts include hides archived' );
t_assert( ! in_array( $arch, get_posts( array( 'post_type' => CC_Blog::POST_TYPE, 'suppress_filters' => true, 'numberposts' => 100, 'fields' => 'ids' ) ), true ), 'get_posts with suppress_filters hides archived' );
$q = new WP_Query( array( 's' => 'ZZ archived ' . $tag, 'fields' => 'ids' ) );
t_assert( ! in_array( $arch, $q->posts, true ), 'site search hides archived' );
$q = new WP_Query( array( 'feed' => 'rss2', 'posts_per_page' => 100, 'fields' => 'ids' ) );
t_assert( ! in_array( $arch, $q->posts, true ), 'feed query hides archived' );
$q = new WP_Query( array( CC_Blog::TAXONOMY => 'zz-cat-a-' . $tag, 'fields' => 'ids' ) );
$q->is_tax = true;
t_assert( ! in_array( $arch, $q->posts, true ) && in_array( $pub, $q->posts, true ), 'category archive query hides archived and keeps published' );
$provider = wp_sitemaps_get_server()->registry->get_provider( 'posts' );
$urls     = array_column( $provider->get_url_list( 1, CC_Blog::POST_TYPE ), 'loc' );
t_assert( in_array( get_permalink( $pub ), $urls, true ) && ! in_array( get_permalink( $arch ), $urls, true ) && ! in_array( get_permalink( $future ), $urls, true ), 'sitemap lists published only' );

echo "archived hidden in REST, oEmbed and redirects\n";
foreach ( array( 'cc_article', 'CC_ARTICLE', 'Cc_Article' ) as $route ) {
	foreach ( array( 'GET', 'HEAD' ) as $method ) {
		foreach ( array( 'archived' => $arch, 'scheduled' => $future, 'draft' => $draft ) as $name => $id ) {
			$r = t_rest( $method, "/wp/v2/$route/$id" );
			t_assert( 404 === $r->get_status(), "$method /wp/v2/$route/ID is 404 for $name" );
		}
	}
	t_assert( 200 === t_rest( 'GET', "/wp/v2/$route/$pub" )->get_status(), "GET /wp/v2/$route/ID is 200 for published" );
}
$r = t_rest( 'GET', "/wp/v2/cc_article/$arch" );
t_assert( 'rest_post_invalid_id' === $r->as_error()->get_error_code() && false === strpos( wp_json_encode( $r->get_data() ), 'archived' ), 'archived single error is the generic invalid id' );
$r = t_rest( 'GET', '/wp/v2/cc_article', array( 'per_page' => 100 ) );
$ids = array_map( 'intval', wp_list_pluck( (array) $r->get_data(), 'id' ) );
t_assert( in_array( $pub, $ids, true ) && ! array_intersect( array( $arch, $future, $draft ), $ids ), 'REST collection lists published only' );
$r = t_rest( 'GET', '/wp/v2/cc_article', array( 'include' => array( $arch ) ) );
t_assert( array() === (array) $r->get_data(), 'REST ?include= hides archived' );
$r = t_rest( 'GET', '/wp/v2/cc_article', array( 'slug' => get_post_field( 'post_name', $arch ) ) );
t_assert( array() === (array) $r->get_data(), 'REST ?slug= hides archived' );
$r = t_rest( 'GET', '/wp/v2/cc_article', array( 'search' => 'ZZ archived ' . $tag ) );
t_assert( array() === (array) $r->get_data(), 'REST ?search= hides archived' );
$r = t_rest( 'GET', '/wp/v2/search', array( 'search' => 'ZZ archived ' . $tag ) );
t_assert( ! in_array( $arch, array_map( 'intval', wp_list_pluck( (array) $r->get_data(), 'id' ) ), true ), 'REST search endpoint hides archived' );
$r    = t_rest( 'GET', "/wp/v2/cc_article/$pub" );
$body = wp_json_encode( $r->get_data() );
t_assert( ! isset( $r->get_data()['author'] ) && false === stripos( $body, $leak ), 'REST single exposes neither author id nor login' );
t_assert( false === stripos( wp_json_encode( $r->get_links() ), '/users/' ), 'REST single links no users endpoint' );

t_assert( 0 === apply_filters( 'oembed_request_post_id', $arch ) && 0 === apply_filters( 'oembed_request_post_id', $future ), 'oEmbed post id is dropped for archived and scheduled' );
t_assert( $pub === apply_filters( 'oembed_request_post_id', $pub ), 'oEmbed post id kept for published' );
$GLOBALS['post'] = null; // get_oembed_response_data( 0 ) would otherwise fall back to the global post.
t_assert( 404 === t_rest( 'GET', '/oembed/1.0/embed', array( 'url' => home_url( '/?p=' . $arch ) ) )->get_status(), 'oEmbed ?p=ID is 404 for archived' );
t_assert( 404 === t_rest( 'GET', '/oembed/1.0/embed', array( 'url' => get_permalink( $arch ) ) )->get_status(), 'oEmbed by permalink is 404 for archived' );
t_assert( 200 === t_rest( 'GET', '/oembed/1.0/embed', array( 'url' => home_url( '/?p=' . $pub ) ) )->get_status(), 'oEmbed ?p=ID is 200 for published' );
t_assert( false === CC_Blog::guard_oembed_data( array( 'title' => 'x' ), get_post( $arch ) ) && array( 'title' => 'x' ) === CC_Blog::guard_oembed_data( array( 'title' => 'x' ), get_post( $pub ) ), 'oEmbed response backstop drops archived' );
$wp_query_backup = $GLOBALS['wp_query'];
foreach ( array( 'archived' => $arch, 'draft' => $draft ) as $name => $id ) {
	$GLOBALS['wp_query'] = new WP_Query();
	$GLOBALS['wp_query']->set( 'p', $id );
	t_assert( false === apply_filters( 'redirect_canonical', get_permalink( $id ) ), "canonical redirect from ?p= is blocked for $name" );
}
$GLOBALS['wp_query'] = new WP_Query();
$GLOBALS['wp_query']->set( 'p', $pub );
t_assert( get_permalink( $pub ) === apply_filters( 'redirect_canonical', get_permalink( $pub ) ), 'canonical redirect still works for published' );
$GLOBALS['wp_query'] = $wp_query_backup;
t_assert( false === apply_filters( 'redirect_canonical', get_permalink( $arch ) ), 'canonical redirect to an archived permalink is blocked' );

foreach ( array( 'archived' => $arch, 'scheduled' => $future, 'draft' => $draft ) as $name => $id ) {
	t_go_unfiltered( $id );
	t_assert( true === CC_Blog::maybe_404() && is_404(), "embed of $name article becomes a 404" );
	t_assert( 0 === $GLOBALS['wp_query']->post_count && array() === $GLOBALS['wp_query']->posts && ! have_posts() && null === $GLOBALS['post'], "embed 404 of $name article leaves no post for the template (no title, excerpt or image)" );
}
t_go_unfiltered( $pub );
t_assert( false === CC_Blog::maybe_404() && have_posts(), 'embed of a published article is untouched' );
$GLOBALS['wp_query'] = $wp_query_backup;

echo "SEO output\n";
$desc_post = t_article( 'ZZ seo ' . $tag, 'publish', array( 'post_author' => $staff, 'post_content' => '<p>' . str_repeat( 'Sentence for the body. ', 30 ) . '</p>' ) );
wp_set_object_terms( $desc_post, array( $cat_a ), CC_Blog::TAXONOMY );
t_go( $desc_post );
ob_start();
wp_head();
$head = (string) ob_get_clean();
preg_match( '/<meta name="description" content="([^"]*)"/', $head, $m );
t_assert( isset( $m[1] ) && mb_strlen( html_entity_decode( $m[1] ) ) <= 160 && '' !== $m[1], 'description falls back to the body, at most 160 characters' );
t_assert( false !== strpos( $head, '<meta property="og:type" content="article">' ) && false !== strpos( $head, 'og:title' ) && false !== strpos( $head, 'og:url' ), 'Open Graph basics are printed' );
t_assert( 1 === substr_count( $head, 'rel="canonical"' ), 'exactly one canonical link' );
t_assert( 1 === preg_match( '#<meta name="twitter:card" content="summary">#', $head ), 'summary twitter card without an image' );
preg_match( '#<script type="application/ld\+json">(.*?)</script>#s', $head, $j );
$json = isset( $j[1] ) ? json_decode( $j[1], true ) : null;
$art  = null;
foreach ( (array) ( $json['@graph'] ?? array() ) as $node ) {
	if ( 'Article' === ( $node['@type'] ?? '' ) ) {
		$art = $node;
	}
}
t_assert( is_array( $json ) && is_array( $art ), 'JSON-LD is valid JSON with an Article node' );
t_assert( $art && 'ZZ seo ' . $tag === $art['headline'] && ! empty( $art['datePublished'] ) && ! empty( $art['dateModified'] ), 'Article has headline and dates' );
t_assert( $art && get_bloginfo( 'name' ) === $art['author']['name'], 'Article author is the site name' );
t_assert( false === stripos( $head, $leak ) && false === stripos( $head, 'zzplain' ), 'head output has no staff login' );
t_assert( false !== strpos( wp_get_document_title(), 'ZZ seo ' . $tag ), 'document title falls back to the article title' );

update_post_meta( $desc_post, CC_Blog::META_TITLE, 'Custom <b>SEO</b> title' );
update_post_meta( $desc_post, CC_Blog::META_DESC, 'Hand "written" <script>alert(1)</script>description' );
t_go( $desc_post );
ob_start();
wp_head();
$head = (string) ob_get_clean();
t_assert( false !== strpos( wp_get_document_title(), 'Custom SEO title' ), 'meta title overrides the document title' );
t_assert( false !== strpos( $head, 'content="Hand &quot;written&quot; description"' ), 'meta description is plain and attribute-escaped' );
t_assert( false === strpos( $head, '<script>alert' ), 'meta description cannot inject script' );
delete_post_meta( $desc_post, CC_Blog::META_DESC );
$excerpt_post = t_article( 'ZZ excerpt ' . $tag, 'publish', array( 'post_excerpt' => 'The short excerpt ' . $tag ) );
t_go( $excerpt_post );
ob_start();
wp_head();
t_assert( false !== strpos( (string) ob_get_clean(), 'content="The short excerpt ' . $tag . '"' ), 'description falls back to the excerpt' );
t_go( $arch );
ob_start();
wp_head();
t_assert( false === strpos( (string) ob_get_clean(), 'og:title' ), 'no SEO output for an archived article viewed as the public' );

echo "metabox save\n";
wp_set_current_user( $staff );
$_POST = array( 'cc_article_seo_nonce' => wp_create_nonce( CC_Blog::NONCE_ACTION ), 'cc_meta_title' => '<i>Saved</i> title', 'cc_meta_description' => str_repeat( 'long ', 100 ) );
CC_Blog::save_metabox( $pub );
t_assert( 'Saved title' === get_post_meta( $pub, CC_Blog::META_TITLE, true ), 'metabox saves a sanitised title' );
t_assert( mb_strlen( (string) get_post_meta( $pub, CC_Blog::META_DESC, true ) ) <= 160, 'metabox caps the description' );
$_POST['cc_meta_title'] = 'Changed';
$_POST['cc_article_seo_nonce'] = 'bad';
CC_Blog::save_metabox( $pub );
t_assert( 'Saved title' === get_post_meta( $pub, CC_Blog::META_TITLE, true ), 'bad nonce is refused' );
$_POST['cc_article_seo_nonce'] = wp_create_nonce( CC_Blog::NONCE_ACTION );
wp_set_current_user( $plain );
CC_Blog::save_metabox( $pub );
t_assert( 'Saved title' === get_post_meta( $pub, CC_Blog::META_TITLE, true ), 'user without the capability is refused' );
$_POST = array();

echo "featured image metabox\n";
function t_image( string $mime = 'image/jpeg' ): int {
	global $cleanup;
	ob_start();
	imagejpeg( imagecreatetruecolor( 40, 30 ) );
	$upload = wp_upload_bits( 'zz-blog-test-' . count( $cleanup['posts'] ) . '.jpg', null, (string) ob_get_clean() );
	$id     = wp_insert_attachment( array( 'post_mime_type' => $mime, 'post_title' => 'zz blog', 'post_status' => 'inherit' ), $upload['file'] );
	require_once ABSPATH . 'wp-admin/includes/image.php';
	wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $upload['file'] ) );
	$cleanup['posts'][] = (int) $id;
	return (int) $id;
}
function t_image_post( $image ): array {
	return array( 'cc_article_image_nonce' => wp_create_nonce( CC_Blog::IMAGE_NONCE ), 'cc_article_image' => (string) $image );
}
$img     = t_image();
$img2    = t_image();
$pdf     = t_image( 'application/pdf' );
$article = t_article( 'ZZ image ' . $tag, 'draft' );
wp_set_current_user( $staff );
$_POST = t_image_post( $img );
CC_Blog::save_image_metabox( $article );
t_assert( $img === (int) get_post_thumbnail_id( $article ), 'a valid image attachment id becomes the featured image' );
$_POST = t_image_post( $pdf );
CC_Blog::save_image_metabox( $article );
t_assert( $img === (int) get_post_thumbnail_id( $article ), 'a non-image attachment id is refused' );
$_POST = t_image_post( $article );
CC_Blog::save_image_metabox( $article );
t_assert( $img === (int) get_post_thumbnail_id( $article ), 'a post id that is not an attachment is refused' );
$_POST = t_image_post( 99999999 );
CC_Blog::save_image_metabox( $article );
t_assert( $img === (int) get_post_thumbnail_id( $article ), 'a non-existent id is refused' );
$_POST = t_image_post( 'abc' );
CC_Blog::save_image_metabox( $article );
t_assert( $img === (int) get_post_thumbnail_id( $article ), 'a non-numeric id is refused' );
$_POST = array( 'cc_article_image_nonce' => 'bad', 'cc_article_image' => (string) $img2 );
CC_Blog::save_image_metabox( $article );
t_assert( $img === (int) get_post_thumbnail_id( $article ), 'a bad nonce is refused' );
$_POST = array( 'cc_article_image' => (string) $img2 );
CC_Blog::save_image_metabox( $article );
t_assert( $img === (int) get_post_thumbnail_id( $article ), 'a missing nonce is refused' );
wp_set_current_user( $plain );
$_POST = t_image_post( $img2 );
CC_Blog::save_image_metabox( $article );
t_assert( $img === (int) get_post_thumbnail_id( $article ), 'a user without cc_manage_blog cannot change the image' );
wp_set_current_user( $staff );
$_POST = t_image_post( $img2 );
CC_Blog::save_image_metabox( $article );
t_assert( $img2 === (int) get_post_thumbnail_id( $article ), 'the image can be replaced' );
ob_start();
CC_Blog::render_image_metabox( get_post( $article ) );
$html = (string) ob_get_clean();
t_assert( false !== strpos( $html, 'name="cc_article_image"' ) && false !== strpos( $html, 'page=cc-media' ) && false !== strpos( $html, 'no alt text' ), 'metabox shows the id field, media link and an alt-missing warning' );
update_post_meta( $img2, '_wp_attachment_image_alt', 'A described photo' );
ob_start();
CC_Blog::render_image_metabox( get_post( $article ) );
t_assert( false === strpos( (string) ob_get_clean(), 'no alt text' ), 'no alt warning once the image has alt text' );
$_POST = t_image_post( '' );
CC_Blog::save_image_metabox( $article );
t_assert( 0 === (int) get_post_thumbnail_id( $article ), 'an empty field clears the featured image' );
$_POST = array();

echo "related articles\n";
wp_set_current_user( 0 );
$rel_b   = t_article( 'ZZ related b ' . $tag, 'publish' );
wp_set_object_terms( $rel_b, array( $cat_b ), CC_Blog::TAXONOMY );
$rel_none = t_article( 'ZZ related none ' . $tag, 'publish' );
$related = wp_list_pluck( CC_Blog::related( $pub )->posts, 'ID' );
t_assert( in_array( $desc_post, $related, true ), 'related includes articles of the same category' );
t_assert( ! in_array( $pub, $related, true ), 'related excludes the article itself' );
t_assert( ! array_intersect( array( $arch, $future, $draft, $rel_b ), $related ), 'related excludes archived, scheduled, draft and other categories' );
t_assert( array() === CC_Blog::related( $rel_none )->posts, 'article without a category has no related articles' );
t_assert( count( $related ) <= CC_Blog::RELATED_LIMIT, 'related is limited' );

echo "feed author\n";
t_go( $pub );
t_assert( $leak === get_the_author(), 'outside feeds the author filter leaves the name alone' );
$GLOBALS['wp_query']->is_feed = true;
t_assert( get_bloginfo( 'name' ) === get_the_author() && false === stripos( get_the_author(), $leak ), 'in a feed the author is the site name, not the staff login' );
$GLOBALS['wp_query']->is_feed = false;

echo "content sanitising\n";
$evil = t_article( 'ZZ evil ' . $tag, 'publish', array( 'post_content' => '<p onclick="x()">Hi</p><script>alert(1)</script><iframe src="https://evil.test"></iframe><a href="javascript:alert(2)">l</a>' ) );
t_go( $evil );
$html = apply_filters( 'the_content', get_post_field( 'post_content', $evil ) );
t_assert( false === stripos( $html, '<script' ) && false === stripos( $html, 'onclick' ) && false === stripos( $html, '<iframe' ) && false === stripos( $html, 'javascript:' ), 'rendered body has no script, handler, iframe or javascript: url' );
t_assert( false !== strpos( $html, 'Hi' ), 'rendered body keeps allowed content' );
$r = t_rest( 'GET', "/wp/v2/cc_article/$evil" );
t_assert( false === stripos( (string) ( $r->get_data()['content']['rendered'] ?? '' ), '<script' ), 'REST rendered content is sanitised too' );

echo "archive action and audit\n";
wp_set_current_user( $plain );
t_assert( false === CC_Blog::archive( $pub, true, $plain ) && ! CC_Blog::is_archived( $pub ), 'user without the capability cannot archive' );
wp_set_current_user( $staff );
$before = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}cc_audit_log WHERE entity_type = 'article' AND entity_id = %d", $rel_none ) );
t_assert( true === CC_Blog::archive( $rel_none, true, $staff ) && CC_Blog::is_archived( $rel_none ), 'manager archives an article' );
t_assert( in_array( $rel_none, CC_Blog::hidden_ids(), true ), 'the archived article is hidden immediately' );
t_assert( true === CC_Blog::archive( $rel_none, false, $staff ) && ! CC_Blog::is_archived( $rel_none ), 'manager restores it' );
$after = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}cc_audit_log WHERE entity_type = 'article' AND entity_id = %d", $rel_none ) );
t_assert( 2 === $after - $before, 'archive and restore are audited' );
$row_actions = CC_Blog::row_actions( array(), get_post( $pub ) );
t_assert( isset( $row_actions['cc_archive'] ) && false !== strpos( $row_actions['cc_archive'], '_wpnonce' ), 'row action is nonce-protected' );
wp_set_current_user( $plain );
t_assert( array() === CC_Blog::row_actions( array(), get_post( $pub ) ), 'no row action without the capability' );
t_assert( isset( CC_Blog::bulk_actions( array() )['cc_archive'] ), 'bulk archive action is registered' );

echo "seeder idempotency\n";
wp_set_current_user( 0 );
CC_Blog::seed();
$count_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'cc_article' AND post_name IN ('how-to-plan-your-hsc-revision','bhorti-porikkhar-prostuti','ielts-writing-common-mistakes','old-admission-circular-2024')" );
CC_Blog::seed();
CC_Blog::seed();
$count_after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'cc_article' AND post_name IN ('how-to-plan-your-hsc-revision','bhorti-porikkhar-prostuti','ielts-writing-common-mistakes','old-admission-circular-2024')" );
t_assert( 4 === $count_before && $count_before === $count_after, 'seeding twice creates the four samples once' );
$menu_items = wp_get_nav_menu_items( 'Primary' );
$blog_items = array_filter( (array) $menu_items, static fn( $i ) => untrailingslashit( $i->url ) === untrailingslashit( get_post_type_archive_link( CC_Blog::POST_TYPE ) ) );
t_assert( 1 === count( $blog_items ), 'Blog appears once in the Primary menu' );
$sample_states = array();
foreach ( array( 'how-to-plan-your-hsc-revision', 'bhorti-porikkhar-prostuti', 'ielts-writing-common-mistakes', 'old-admission-circular-2024' ) as $slug ) {
	$sid = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'cc_article' AND post_name = %s", $slug ) );
	$sample_states[ $slug ] = CC_Blog::state_for( (string) get_post_status( $sid ), CC_Blog::is_archived( $sid ) );
}
t_assert( 'published' === $sample_states['how-to-plan-your-hsc-revision'] && 'published' === $sample_states['bhorti-porikkhar-prostuti'] && 'scheduled' === $sample_states['ielts-writing-common-mistakes'] && 'archived' === $sample_states['old-admission-circular-2024'], 'samples cover published (English and Bangla), scheduled and archived' );
t_assert( 2 === count( array_filter( array( 'study-tips', 'admission-guides' ), static fn( $s ) => (bool) term_exists( $s, CC_Blog::TAXONOMY ) ) ), 'two sample categories exist' );

echo "manager visibility in wp-admin and REST only\n";
wp_set_current_user( $staff );
$q = new WP_Query( array( 'post_type' => CC_Blog::POST_TYPE, 'post_status' => 'any', 'posts_per_page' => 100, 'fields' => 'ids' ) );
t_assert( ! in_array( $arch, $q->posts, true ), 'a manager browsing the public site does not see archived in lists');
define( 'REST_REQUEST', true );
$q = new WP_Query( array( 'post_type' => CC_Blog::POST_TYPE, 'post_status' => 'any', 'posts_per_page' => 100, 'fields' => 'ids' ) );
t_assert( in_array( $arch, $q->posts, true ) && in_array( $draft, $q->posts, true ), 'a manager in REST context sees archived and draft' );
t_assert( 200 === t_rest( 'GET', "/wp/v2/cc_article/$arch" )->get_status(), 'manager can fetch an archived article over REST' );
t_assert( isset( t_rest( 'GET', "/wp/v2/cc_article/$pub" )->get_data()['author'] ), 'manager still sees the author id in REST' );

foreach ( $cleanup['posts'] as $id ) {
	$wpdb->delete( $wpdb->prefix . 'cc_audit_log', array( 'entity_type' => 'article', 'entity_id' => $id ) );
	wp_delete_post( $id, true );
}
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ( $cleanup['users'] as $id ) {
	wp_delete_user( $id );
}
foreach ( $cleanup['terms'] as $id ) {
	wp_delete_term( $id, CC_Blog::TAXONOMY );
}

echo "\n$checks checks, $failures failed\n";
exit( $failures > 0 ? 1 : 0 );
