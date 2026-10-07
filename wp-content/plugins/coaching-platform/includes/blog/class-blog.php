<?php
defined( 'ABSPATH' ) || exit;

/**
 * Blog (PRD-FUNC-014): cc_article CPT + cc_article_category taxonomy, capabilities mapped to cc_manage_blog, an archive
 * flag that hides an article from every public surface, SEO metabox and head output, related articles, sample content.
 * States: Draft, Scheduled (future), Published, Archived (published + meta flag). The access guards mirror CC_Notice_Access.
 */
final class CC_Blog {

	const POST_TYPE       = 'cc_article';
	const TAXONOMY        = 'cc_article_category';
	const CAP             = 'cc_manage_blog';
	const META_ARCHIVED   = 'cc_archived';
	const META_TITLE      = 'cc_meta_title';
	const META_DESC       = 'cc_meta_description';
	const MAX_DESCRIPTION = 160;
	const MAX_META_TITLE  = 70;
	const RELATED_LIMIT   = 3;
	const EXCERPT_WORDS   = 30;
	const NONCE_ACTION    = 'cc_article_seo';
	const IMAGE_NONCE     = 'cc_article_image';

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'exclude_hidden' ), 5 );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_404' ), 1 );
		add_filter( 'template_include', array( __CLASS__, 'hidden_embed_template' ), 99 );
		add_filter( 'rest_request_before_callbacks', array( __CLASS__, 'guard_rest_single' ), 10, 3 );
		add_filter( 'redirect_canonical', array( __CLASS__, 'guard_canonical_redirect' ), 10, 1 );
		add_filter( 'oembed_request_post_id', array( __CLASS__, 'guard_oembed_post_id' ), 10, 1 );
		add_filter( 'oembed_response_data', array( __CLASS__, 'guard_oembed_data' ), 10, 2 );
		add_filter( 'rest_prepare_' . self::POST_TYPE, array( __CLASS__, 'strip_rest_author' ), 10, 1 );
		add_filter( 'the_content', array( __CLASS__, 'sanitize_content' ), 20 );
		add_filter( 'the_author', array( __CLASS__, 'feed_author' ) );

		add_action( 'wp_head', array( __CLASS__, 'output_head' ), 5 );
		add_filter( 'document_title_parts', array( __CLASS__, 'filter_title_parts' ) );
		add_filter( 'cc_seo_graph', array( __CLASS__, 'add_json_ld' ) );

		add_action( 'add_meta_boxes_' . self::POST_TYPE, array( __CLASS__, 'add_metabox' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'save_metabox' ), 10, 1 );
		add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'save_image_metabox' ), 10, 1 );
		add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
		add_action( 'admin_post_cc_article_archive', array( __CLASS__, 'handle_archive' ) );
		add_filter( 'bulk_actions-edit-' . self::POST_TYPE, array( __CLASS__, 'bulk_actions' ) );
		add_filter( 'handle_bulk_actions-edit-' . self::POST_TYPE, array( __CLASS__, 'handle_bulk' ), 10, 3 );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'column_value' ), 10, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'admin_notice' ) );

		add_action( 'cc_seed_extra', array( __CLASS__, 'seed' ) );
	}

	/* ------------------------------------------------------------ pure helpers */

	/** Visible text of an HTML fragment: scripts/styles dropped, tags stripped, entities decoded, whitespace collapsed. */
	public static function plain( string $html ): string {
		$html = (string) preg_replace( '#<(script|style)\b[^>]*>.*?</\1\s*>#is', ' ', $html );
		$text = html_entity_decode( strip_tags( $html ), ENT_QUOTES, 'UTF-8' );
		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}

	/** Shortens to at most $max characters on a word boundary, ending in an ellipsis. */
	public static function trim_text( string $text, int $max ): string {
		if ( mb_strlen( $text ) <= $max ) {
			return $text;
		}
		$cut   = mb_substr( $text, 0, $max - 1 );
		$space = mb_strrpos( $cut, ' ' );
		if ( false !== $space && $space > (int) ( $max / 2 ) ) {
			$cut = mb_substr( $cut, 0, $space );
		}
		return rtrim( $cut, " \t\n\r,.;:-" ) . '…';
	}

	/** Meta description: the staff-written one, else the excerpt, else the body; plain text, at most 160 characters. */
	public static function description( string $meta, string $excerpt, string $content, int $max = self::MAX_DESCRIPTION ): string {
		foreach ( array( $meta, $excerpt, $content ) as $candidate ) {
			$text = self::plain( $candidate );
			if ( '' !== $text ) {
				return self::trim_text( $text, $max );
			}
		}
		return '';
	}

	/** SEO title override: plain text, at most 70 characters; empty means "use the article title". */
	public static function clean_meta_title( string $value, int $max = self::MAX_META_TITLE ): string {
		return self::trim_text( self::plain( $value ), $max );
	}

	/** "bn" for text containing Bengali script, otherwise "" (inherit the page language). */
	public static function lang_for( string $text ): string {
		return 1 === preg_match( '/\p{Bengali}/u', $text ) ? 'bn' : '';
	}

	/** Draft, Scheduled, Published or Archived. */
	public static function state_for( string $status, bool $archived ): string {
		if ( 'publish' === $status ) {
			return $archived ? 'archived' : 'published';
		}
		return 'future' === $status ? 'scheduled' : 'draft';
	}

	/* ------------------------------------------------------------ registration */

	private static function caps_map(): array {
		$caps = array();
		foreach ( array(
			'edit_posts', 'edit_others_posts', 'edit_published_posts', 'edit_private_posts',
			'publish_posts', 'read_private_posts', 'delete_posts', 'delete_others_posts',
			'delete_published_posts', 'delete_private_posts', 'create_posts',
		) as $key ) {
			$caps[ $key ] = self::CAP;
		}
		return $caps;
	}

	public static function register(): void {
		// Registered first so blog/category/{term} is matched before the article attachment rule blog/{slug}/{name}.
		register_taxonomy(
			self::TAXONOMY,
			array( self::POST_TYPE ),
			array(
				'labels'       => array( 'name' => 'Article Categories', 'singular_name' => 'Article Category', 'all_items' => 'All Categories' ),
				'public'       => true,
				'hierarchical' => true,
				'rewrite'      => array( 'slug' => 'blog/category', 'with_front' => false ),
				'show_in_rest' => true,
				'capabilities' => array(
					'manage_terms' => self::CAP,
					'edit_terms'   => self::CAP,
					'delete_terms' => self::CAP,
					'assign_terms' => self::CAP,
				),
			)
		);

		register_post_type(
			self::POST_TYPE,
			array(
				'labels'          => array(
					'name'          => 'Articles',
					'singular_name' => 'Article',
					'menu_name'     => 'Blog',
					'add_new_item'  => 'Add New Article',
					'edit_item'     => 'Edit Article',
					'view_item'     => 'View Article',
					'all_items'     => 'All Articles',
					'search_items'  => 'Search Articles',
					'not_found'     => 'No articles found.',
				),
				'public'          => true,
				'has_archive'     => 'blog',
				'rewrite'         => array( 'slug' => 'blog', 'with_front' => false ),
				'menu_icon'       => 'dashicons-edit-page',
				'supports'        => array( 'title', 'editor', 'excerpt', 'thumbnail', 'author' ),
				'show_in_rest'    => true,
				'capability_type' => array( 'cc_article', 'cc_articles' ),
				'capabilities'    => self::caps_map(),
				'map_meta_cap'    => true,
			)
		);
	}

	/* ------------------------------------------------------------ archived / visibility */

	public static function is_archived( int $post_id ): bool {
		return '1' === (string) get_post_meta( $post_id, self::META_ARCHIVED, true );
	}

	public static function set_archived( int $post_id, bool $archived ): void {
		if ( $archived ) {
			update_post_meta( $post_id, self::META_ARCHIVED, '1' );
		} else {
			delete_post_meta( $post_id, self::META_ARCHIVED );
		}
	}

	/** @return int[] */
	public static function hidden_ids(): array {
		global $wpdb;
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				WHERE p.post_type = %s AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = p.ID AND m.meta_key = %s AND m.meta_value = '1')",
				self::POST_TYPE,
				self::META_ARCHIVED
			)
		);
		// phpcs:enable
		return array_map( 'intval', (array) $ids );
	}

	private static function can_manage( int $user_id ): bool {
		return $user_id > 0 && user_can( $user_id, self::CAP );
	}

	/** Managers see hidden articles in wp-admin and REST only, never on public pages. */
	private static function manager_context( int $user_id ): bool {
		return self::can_manage( $user_id ) && ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) );
	}

	private static function may_include_articles( WP_Query $query ): bool {
		$types = $query->get( 'post_type' );
		if ( empty( $types ) ) {
			return $query->is_search() || $query->is_feed() || $query->is_tax( self::TAXONOMY ) || '' !== (string) $query->get( self::TAXONOMY );
		}
		$types = (array) $types;
		return in_array( self::POST_TYPE, $types, true ) || in_array( 'any', $types, true );
	}

	/** pre_get_posts also covers get_posts() (suppress_filters), which posts_where would not. */
	public static function exclude_hidden( $query ): void {
		if ( ! $query instanceof WP_Query || ! self::may_include_articles( $query ) ) {
			return;
		}
		if ( $query->is_main_query() && $query->is_singular() ) {
			return; // The permalink decision is made in maybe_404().
		}
		if ( self::manager_context( get_current_user_id() ) ) {
			return;
		}
		$hidden = self::hidden_ids();
		if ( ! $hidden ) {
			return;
		}
		$query->set( 'post__not_in', array_values( array_unique( array_merge( array_map( 'intval', (array) $query->get( 'post__not_in' ) ), $hidden ) ) ) );
		// WP_Query ignores post__not_in when p, page_id or post__in is set.
		foreach ( array( 'p', 'page_id' ) as $var ) {
			if ( in_array( (int) $query->get( $var ), $hidden, true ) ) {
				$query->set( $var, 0 );
				$query->set( 'post__in', array( 0 ) );
			}
		}
		$requested = array_map( 'intval', (array) $query->get( 'post__in' ) );
		if ( $requested ) {
			$query->set( 'post__in', array_values( array_diff( $requested, $hidden ) ) ?: array( 0 ) );
		}
	}

	/** A manager, or anyone for a published, non-archived article. Drafts and scheduled articles are 404 for the public. */
	public static function can_view( int $post_id, int $user_id ): bool {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || self::POST_TYPE !== $post->post_type ) {
			return false;
		}
		if ( self::can_manage( $user_id ) ) {
			return true;
		}
		return 'publish' === $post->post_status && ! self::is_archived( $post_id );
	}

	/** True for anything that is not an article, or an article the current viewer may open. */
	private static function can_view_if_article( int $post_id ): bool {
		if ( $post_id <= 0 ) {
			return true;
		}
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || self::POST_TYPE !== $post->post_type ) {
			return true;
		}
		return self::can_view( $post_id, get_current_user_id() );
	}

	public static function maybe_404(): bool {
		if ( ! is_singular( self::POST_TYPE ) || self::can_view( (int) get_queried_object_id(), get_current_user_id() ) ) {
			return false;
		}
		global $wp_query, $post;
		$wp_query->set_404();
		// The embed template prints the loop's post, so a bare 404 flag would still leak title, excerpt and image.
		$wp_query->posts      = array();
		$wp_query->post_count = 0;
		$wp_query->post       = null;
		$post                 = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
		if ( ! headers_sent() ) {
			status_header( 404 );
			nocache_headers();
		}
		return true;
	}

	/** Backstop: whatever flags a hidden article's embed ends up with, it renders the 404 template. */
	public static function hidden_embed_template( $template ) {
		if ( ! is_404() ) {
			return $template;
		}
		$not_found = get_404_template();
		return $not_found ? $not_found : $template;
	}

	/** GET/HEAD /wp/v2/cc_article/{id}: the route check is case-insensitive like WordPress routing; the controller check is spelling-independent. */
	public static function guard_rest_single( $response, $handler, $request ) {
		if ( ! $request instanceof WP_REST_Request || ! in_array( $request->get_method(), array( 'GET', 'HEAD' ), true ) ) {
			return $response;
		}
		$post_id = 0;
		if ( preg_match( '#^/wp/v2/' . self::POST_TYPE . '/(\d+)/?$#i', $request->get_route(), $m ) ) {
			$post_id = (int) $m[1];
		}
		$callback = is_array( $handler ) ? ( $handler['callback'] ?? null ) : null;
		if ( is_array( $callback ) && $callback[0] instanceof WP_REST_Posts_Controller && 'get_item' === $callback[1] ) {
			$post_id = max( $post_id, absint( $request['id'] ) );
		}
		if ( $post_id <= 0 || self::can_view_if_article( $post_id ) ) {
			return $response;
		}
		return new WP_Error( 'rest_post_invalid_id', 'Invalid post ID.', array( 'status' => 404 ) );
	}

	/** Stops /?p=ID (and guessed permalinks) from 301-ing to the slug of an article the viewer cannot open. */
	public static function guard_canonical_redirect( $redirect_url ) {
		if ( ! is_string( $redirect_url ) || '' === $redirect_url ) {
			return $redirect_url;
		}
		foreach ( array( 'p', 'page_id', 'attachment_id' ) as $var ) {
			$id = absint( get_query_var( $var ) );
			if ( $id > 0 && ! self::can_view_if_article( $id ) ) {
				return false;
			}
		}
		$target = strtok( untrailingslashit( $redirect_url ), '?' );
		foreach ( self::hidden_ids() as $hidden_id ) {
			if ( ! self::can_view( $hidden_id, get_current_user_id() ) && untrailingslashit( (string) get_permalink( $hidden_id ) ) === $target ) {
				return false;
			}
		}
		return $redirect_url;
	}

	public static function guard_oembed_post_id( $post_id ) {
		return self::can_view_if_article( (int) $post_id ) ? $post_id : 0;
	}

	public static function guard_oembed_data( $data, $post ) {
		return $post instanceof WP_Post && ! self::can_view_if_article( (int) $post->ID ) ? false : $data;
	}

	/** The author id is a staff user id: not shown to anyone who cannot manage the blog. */
	public static function strip_rest_author( $response ) {
		if ( ! $response instanceof WP_REST_Response || self::can_manage( get_current_user_id() ) ) {
			return $response;
		}
		$data = $response->get_data();
		unset( $data['author'] );
		$response->set_data( $data );
		$response->remove_link( 'author' );
		return $response;
	}

	/** Feeds print the author's display name (which defaults to the login) as dc:creator; publish the site instead. */
	public static function feed_author( $name ) {
		return is_feed() && self::POST_TYPE === get_post_type() ? get_bloginfo( 'name' ) : $name;
	}

	/** Staff lack unfiltered_html, so the rendered body (page, REST, feed) is always passed through kses. */
	public static function sanitize_content( $content ) {
		return self::POST_TYPE === get_post_type() ? wp_kses_post( (string) $content ) : $content;
	}

	/* ------------------------------------------------------------ SEO output */

	private static function viewable_article(): ?WP_Post {
		if ( ! is_singular( self::POST_TYPE ) ) {
			return null;
		}
		$post = get_queried_object();
		return $post instanceof WP_Post ? $post : null;
	}

	public static function meta_description_for( WP_Post $post ): string {
		return self::description( (string) get_post_meta( $post->ID, self::META_DESC, true ), $post->post_excerpt, $post->post_content );
	}

	public static function filter_title_parts( $parts ) {
		$post = self::viewable_article();
		if ( $post && is_array( $parts ) ) {
			$override = self::clean_meta_title( (string) get_post_meta( $post->ID, self::META_TITLE, true ) );
			if ( '' !== $override ) {
				$parts['title'] = $override;
			}
		}
		return $parts;
	}

	/** Description and Open Graph basics. WordPress core already prints rel=canonical for singular posts. */
	public static function output_head(): void {
		$post = self::viewable_article();
		if ( ! $post ) {
			return;
		}
		$title = self::clean_meta_title( (string) get_post_meta( $post->ID, self::META_TITLE, true ) );
		$title = '' !== $title ? $title : self::plain( get_the_title( $post ) );
		$desc  = self::meta_description_for( $post );
		$tags  = array(
			'og:type'      => 'article',
			'og:title'     => $title,
			'og:url'       => (string) get_permalink( $post ),
			'og:site_name' => get_bloginfo( 'name' ),
		);
		if ( '' !== $desc ) {
			echo '<meta name="description" content="' . esc_attr( $desc ) . '">' . "\n";
			$tags['og:description'] = $desc;
		}
		$image = get_the_post_thumbnail_url( $post, 'large' );
		if ( $image ) {
			$tags['og:image'] = $image;
		}
		foreach ( $tags as $property => $value ) {
			echo '<meta property="' . esc_attr( $property ) . '" content="' . esc_attr( $value ) . '">' . "\n";
		}
		echo '<meta name="twitter:card" content="' . ( $image ? 'summary_large_image' : 'summary' ) . '">' . "\n";
	}

	/** Article JSON-LD for CC_Seo. The author is the site, never the staff user. */
	public static function add_json_ld( $graph ) {
		$post = self::viewable_article();
		if ( ! $post || ! is_array( $graph ) ) {
			return $graph;
		}
		$site    = array( '@type' => 'Organization', 'name' => get_bloginfo( 'name' ), 'url' => home_url( '/' ) );
		$article = array(
			'@type'            => 'Article',
			'headline'         => self::plain( get_the_title( $post ) ),
			'description'      => self::meta_description_for( $post ),
			'url'              => (string) get_permalink( $post ),
			'datePublished'    => get_post_time( 'c', true, $post ),
			'dateModified'     => get_post_modified_time( 'c', true, $post ),
			'author'           => $site,
			'publisher'        => $site,
			'mainEntityOfPage' => (string) get_permalink( $post ),
		);
		$image   = get_the_post_thumbnail_url( $post, 'large' );
		if ( $image ) {
			$article['image'] = $image;
		}
		$graph[] = $article;
		return $graph;
	}

	/* ------------------------------------------------------------ theme helpers */

	/** @return WP_Query Published, non-archived articles sharing a category with $post_id (archived are excluded by pre_get_posts). */
	public static function related( int $post_id, int $limit = self::RELATED_LIMIT ): WP_Query {
		$terms = wp_get_object_terms( $post_id, self::TAXONOMY, array( 'fields' => 'ids' ) );
		$args  = array(
			'post_type'           => self::POST_TYPE,
			'post_status'         => 'publish',
			'posts_per_page'      => max( 1, $limit ),
			'post__not_in'        => array( $post_id ),
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
		);
		if ( is_wp_error( $terms ) || ! $terms ) {
			$args['post__in'] = array( 0 );
		} else {
			$args['tax_query'] = array( array( 'taxonomy' => self::TAXONOMY, 'field' => 'term_id', 'terms' => array_map( 'intval', $terms ) ) );
		}
		return new WP_Query( $args );
	}

	public static function excerpt_for( WP_Post $post ): string {
		$text = self::plain( '' !== $post->post_excerpt ? $post->post_excerpt : $post->post_content );
		return wp_trim_words( $text, self::EXCERPT_WORDS );
	}

	/* ------------------------------------------------------------ admin */

	public static function add_metabox(): void {
		add_meta_box( 'cc_article_image', 'Featured image', array( __CLASS__, 'render_image_metabox' ), self::POST_TYPE, 'side', 'default' );
		add_meta_box( 'cc_article_seo', 'Search & sharing (SEO)', array( __CLASS__, 'render_metabox' ), self::POST_TYPE, 'normal', 'default' );
	}

	public static function render_metabox( WP_Post $post ): void {
		wp_nonce_field( self::NONCE_ACTION, 'cc_article_seo_nonce' );
		$title = (string) get_post_meta( $post->ID, self::META_TITLE, true );
		$desc  = (string) get_post_meta( $post->ID, self::META_DESC, true );
		echo '<p><label for="cc_meta_title"><strong>SEO title</strong> (max ' . (int) self::MAX_META_TITLE . ' characters; blank uses the article title)</label><br>';
		echo '<input type="text" class="widefat" id="cc_meta_title" name="cc_meta_title" maxlength="' . (int) self::MAX_META_TITLE . '" value="' . esc_attr( $title ) . '"></p>';
		echo '<p><label for="cc_meta_description"><strong>Meta description</strong> (max ' . (int) self::MAX_DESCRIPTION . ' characters; blank uses the excerpt)</label><br>';
		echo '<textarea class="widefat" id="cc_meta_description" name="cc_meta_description" rows="3" maxlength="' . (int) self::MAX_DESCRIPTION . '">' . esc_textarea( $desc ) . '</textarea></p>';
	}

	/** Staff lack upload_files, so the core featured-image picker cannot open the library; this takes an Astona media ID instead. */
	public static function render_image_metabox( WP_Post $post ): void {
		wp_nonce_field( self::IMAGE_NONCE, 'cc_article_image_nonce' );
		$image_id = (int) get_post_thumbnail_id( $post );
		CC_Gallery::render_image_picker( 'cc-article-image', 'cc_article_image', $image_id );
		if ( $image_id > 0 && '' === trim( (string) get_post_meta( $image_id, '_wp_attachment_image_alt', true ) ) ) {
			echo '<p class="notice notice-warning inline"><strong>This image has no alt text.</strong> Add it on the Astona media screen.</p>';
		}
	}

	public static function save_image_metabox( $post_id ): void {
		$post_id = (int) $post_id;
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		$nonce = isset( $_POST['cc_article_image_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['cc_article_image_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::IMAGE_NONCE ) || ! current_user_can( self::CAP ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$raw = isset( $_POST['cc_article_image'] ) ? trim( (string) wp_unslash( $_POST['cc_article_image'] ) ) : '';
		if ( '' === $raw || '0' === $raw ) {
			delete_post_thumbnail( $post_id );
			return;
		}
		$image_id = CC_Gallery::valid_image_id( $raw );
		if ( $image_id > 0 ) {
			set_post_thumbnail( $post_id, $image_id );
		}
	}

	public static function save_metabox( $post_id ): void {
		$post_id = (int) $post_id;
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		$nonce = isset( $_POST['cc_article_seo_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['cc_article_seo_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$title = self::clean_meta_title( sanitize_text_field( wp_unslash( $_POST['cc_meta_title'] ?? '' ) ) );
		$desc  = self::trim_text( self::plain( sanitize_textarea_field( wp_unslash( $_POST['cc_meta_description'] ?? '' ) ) ), self::MAX_DESCRIPTION );
		foreach ( array( self::META_TITLE => $title, self::META_DESC => $desc ) as $key => $value ) {
			if ( '' === $value ) {
				delete_post_meta( $post_id, $key );
			} else {
				update_post_meta( $post_id, $key, $value );
			}
		}
	}

	public static function archive( int $post_id, bool $archived, int $actor ): bool {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || self::POST_TYPE !== $post->post_type || ! user_can( $actor, 'edit_post', $post_id ) ) {
			return false;
		}
		self::set_archived( $post_id, $archived );
		CC_Audit::log( $archived ? 'article.archive' : 'article.unarchive', 'article', $post_id );
		return true;
	}

	public static function row_actions( $actions, $post ) {
		if ( ! $post instanceof WP_Post || self::POST_TYPE !== $post->post_type || ! current_user_can( 'edit_post', $post->ID ) ) {
			return $actions;
		}
		$archived = self::is_archived( $post->ID );
		$url      = wp_nonce_url(
			add_query_arg( array( 'action' => 'cc_article_archive', 'post' => $post->ID, 'archived' => $archived ? 0 : 1 ), admin_url( 'admin-post.php' ) ),
			'cc_article_archive_' . $post->ID
		);
		$actions['cc_archive'] = '<a href="' . esc_url( $url ) . '">' . ( $archived ? 'Unarchive' : 'Archive' ) . '</a>';
		return $actions;
	}

	public static function handle_archive(): void {
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification -- verified next line.
		check_admin_referer( 'cc_article_archive_' . $post_id );
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html( 'You are not allowed to do that.' ), '', array( 'response' => 403 ) );
		}
		$archived = ! empty( $_GET['archived'] );
		$done     = self::archive( $post_id, $archived, get_current_user_id() );
		wp_safe_redirect( add_query_arg( array( 'post_type' => self::POST_TYPE, 'cc_archived' => $done ? ( $archived ? 1 : -1 ) : 0 ), admin_url( 'edit.php' ) ) );
		exit;
	}

	public static function bulk_actions( $actions ) {
		$actions['cc_archive']   = 'Archive';
		$actions['cc_unarchive'] = 'Unarchive';
		return $actions;
	}

	public static function handle_bulk( $redirect, $action, $post_ids ) {
		if ( ! in_array( $action, array( 'cc_archive', 'cc_unarchive' ), true ) ) {
			return $redirect;
		}
		$archive = 'cc_archive' === $action;
		$count   = 0;
		foreach ( (array) $post_ids as $post_id ) {
			if ( self::archive( (int) $post_id, $archive, get_current_user_id() ) ) {
				++$count;
			}
		}
		return add_query_arg( 'cc_archived', $archive ? $count : -$count, $redirect );
	}

	public static function admin_notice(): void {
		if ( ! isset( $_GET['cc_archived'], $_GET['post_type'] ) || self::POST_TYPE !== $_GET['post_type'] || ! current_user_can( self::CAP ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$n = (int) $_GET['cc_archived']; // phpcs:ignore WordPress.Security.NonceVerification
		if ( 0 === $n ) {
			echo '<div class="notice notice-error is-dismissible"><p>Nothing was changed.</p></div>';
			return;
		}
		printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $n > 0 ? 'Archived: hidden from the public site.' : 'Restored to the public site.' ) );
	}

	public static function columns( $columns ) {
		$columns = is_array( $columns ) ? $columns : array();
		$out     = array();
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'title' === $key ) {
				$out['cc_state'] = 'State';
			}
		}
		return $out;
	}

	public static function column_value( $column, $post_id ): void {
		if ( 'cc_state' !== $column ) {
			return;
		}
		$status = (string) get_post_status( (int) $post_id );
		echo esc_html( ucfirst( self::state_for( $status, self::is_archived( (int) $post_id ) ) ) );
	}

	/* ------------------------------------------------------------ sample content */

	/** Direct query: get_posts() would hide archived samples from the seeder and duplicate them on every run. */
	private static function find_by_slug( string $slug ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_name = %s LIMIT 1", self::POST_TYPE, $slug ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
	}

	public static function seed(): void {
		$terms = array();
		foreach ( array( 'study-tips' => 'Study Tips', 'admission-guides' => 'Admission Guides' ) as $slug => $name ) {
			$term = term_exists( $slug, self::TAXONOMY ) ?: wp_insert_term( $name, self::TAXONOMY, array( 'slug' => $slug ) );
			if ( is_wp_error( $term ) ) {
				throw new RuntimeException( 'Could not create article category ' . $slug );
			}
			$terms[ $slug ] = (int) ( is_array( $term ) ? $term['term_id'] : $term );
		}

		$future  = gmdate( 'Y-m-d H:i:s', strtotime( '+30 days' ) );
		$samples = array(
			'how-to-plan-your-hsc-revision' => array(
				'title' => 'How to plan your HSC revision in 8 weeks', 'cat' => 'study-tips', 'status' => 'publish',
				'excerpt' => 'A simple weekly plan for covering the whole HSC syllabus without last-minute stress.',
				'body'    => '<p>Start by listing every chapter, then give each week one theme: two weeks of concepts, four weeks of practice, two weeks of mock tests.</p><h2>Keep a short daily routine</h2><ul><li>Two focused study blocks of 90 minutes</li><li>One past-paper question set</li><li>A ten-minute review before sleep</li></ul>',
			),
			'bhorti-porikkhar-prostuti' => array(
				'title' => 'ভর্তি পরীক্ষার প্রস্তুতি: প্রথম ৩০ দিনের পরিকল্পনা', 'cat' => 'admission-guides', 'status' => 'publish',
				'excerpt' => 'বিশ্ববিদ্যালয় ভর্তি পরীক্ষার জন্য প্রথম এক মাসে কী পড়বেন এবং কীভাবে অনুশীলন করবেন, তার সহজ গাইড।',
				'body'    => '<p>প্রথম সপ্তাহে সিলেবাস ভাগ করে নিন। দ্বিতীয় সপ্তাহ থেকে প্রতিদিন অন্তত ৩০টি প্রশ্ন সমাধান করুন এবং ভুলগুলো একটি খাতায় লিখে রাখুন।</p>',
			),
			'ielts-writing-common-mistakes' => array(
				'title' => 'Five common IELTS writing mistakes (coming soon)', 'cat' => 'study-tips', 'status' => 'future', 'date' => $future,
				'excerpt' => 'The errors that cost most candidates band score in Task 2, and how to avoid them.',
				'body'    => '<p>This article is scheduled and will appear on the blog automatically.</p>',
			),
			'old-admission-circular-2024' => array(
				'title' => 'Admission circular 2024 (archived)', 'cat' => 'admission-guides', 'status' => 'publish', 'archived' => true,
				'excerpt' => 'An outdated circular kept for staff reference.',
				'body'    => '<p>This article is archived and hidden from the public site.</p>',
			),
		);

		foreach ( $samples as $slug => $s ) {
			if ( self::find_by_slug( $slug ) ) {
				continue;
			}
			$args = array(
				'post_type' => self::POST_TYPE, 'post_name' => $slug, 'post_title' => $s['title'], 'post_excerpt' => $s['excerpt'],
				'post_content' => $s['body'], 'post_status' => $s['status'], 'post_author' => 0,
			);
			if ( isset( $s['date'] ) ) {
				$args['post_date_gmt'] = $s['date'];
				$args['post_date']     = get_date_from_gmt( $s['date'] );
			}
			$id = wp_insert_post( $args, true );
			if ( is_wp_error( $id ) || ! $id ) {
				throw new RuntimeException( 'Could not create article ' . $slug );
			}
			wp_set_object_terms( (int) $id, array( $terms[ $s['cat'] ] ), self::TAXONOMY );
			if ( ! empty( $s['archived'] ) ) {
				self::set_archived( (int) $id, true );
			}
		}

		self::ensure_menu_item();
	}

	private static function ensure_menu_item(): void {
		$menu = wp_get_nav_menu_object( 'Primary' );
		$url  = get_post_type_archive_link( self::POST_TYPE );
		if ( ! $menu || ! $url ) {
			return;
		}
		foreach ( (array) wp_get_nav_menu_items( $menu->term_id ) as $item ) {
			if ( untrailingslashit( (string) $item->url ) === untrailingslashit( $url ) ) {
				return;
			}
		}
		wp_update_nav_menu_item( $menu->term_id, 0, array( 'menu-item-title' => 'Blog', 'menu-item-url' => $url, 'menu-item-status' => 'publish', 'menu-item-type' => 'custom' ) );
	}
}
