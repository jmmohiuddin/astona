<?php
defined( 'ABSPATH' ) || exit;

/**
 * Keeps batch-targeted and archived notices out of every public surface: queries (archive, search, feeds, sitemaps,
 * REST collections, get_posts), the single permalink and the REST single route.
 */
final class CC_Notice_Access {

	public static function init(): void {
		add_action( 'pre_get_posts', array( __CLASS__, 'exclude_hidden' ), 5 );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_404' ), 1 );
		add_filter( 'rest_request_before_callbacks', array( __CLASS__, 'guard_rest_single' ), 10, 3 );
		add_filter( 'redirect_canonical', array( __CLASS__, 'guard_canonical_redirect' ), 10, 1 );
		add_filter( 'oembed_request_post_id', array( __CLASS__, 'guard_oembed_post_id' ), 10, 1 );
		add_filter( 'oembed_response_data', array( __CLASS__, 'guard_oembed_data' ), 10, 2 );
	}

	/** Manager visibility of hidden notices in queries applies to wp-admin and REST only, never to public pages. */
	private static function manager_context( int $user_id ): bool {
		return self::can_manage( $user_id ) && ( is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) );
	}

	private static function can_manage( int $user_id ): bool {
		return user_can( $user_id, CC_Admin_Roles::CAP_MANAGE_NOTICES );
	}

	/**
	 * Notice ids that are not public: targeted at one or more batches, or archived.
	 *
	 * @return int[]
	 */
	public static function hidden_ids(): array {
		global $wpdb;
		$targets = $wpdb->prefix . 'cc_notice_targets';
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				WHERE p.post_type = %s AND (
					EXISTS (SELECT 1 FROM {$targets} t WHERE t.notice_id = p.ID)
					OR EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = p.ID AND m.meta_key = %s AND m.meta_value = '1')
				)",
				CC_Notice_Service::POST_TYPE,
				CC_Notice_Service::META_ARCHIVED
			)
		);
		// phpcs:enable
		return array_map( 'intval', (array) $ids );
	}

	private static function may_include_notices( WP_Query $query ): bool {
		$types = $query->get( 'post_type' );
		if ( empty( $types ) ) {
			return $query->is_search() || $query->is_feed();
		}
		$types = (array) $types;
		return in_array( CC_Notice_Service::POST_TYPE, $types, true ) || in_array( 'any', $types, true );
	}

	/** pre_get_posts runs even for get_posts() (suppress_filters), which posts_where would not. */
	public static function exclude_hidden( $query ): void {
		if ( ! $query instanceof WP_Query || ! self::may_include_notices( $query ) ) {
			return;
		}
		if ( $query->is_main_query() && $query->is_singular() ) {
			return; // The permalink decision is made in maybe_404() so enrolled students can still open their notice.
		}
		if ( self::manager_context( get_current_user_id() ) ) {
			return;
		}
		$hidden = self::hidden_ids();
		if ( ! $hidden ) {
			return;
		}
		$query->set( 'post__not_in', array_values( array_unique( array_merge( array_map( 'intval', (array) $query->get( 'post__not_in' ) ), $hidden ) ) ) );
		// WP_Query ignores post__not_in when p or post__in is set, so those need the same treatment.
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

	/** Whether the user may open this notice now: manager, or published, not archived, and public or enrolled in a target batch. */
	public static function can_view( int $notice_id, int $user_id ): bool {
		$post = get_post( $notice_id );
		if ( ! $post instanceof WP_Post || CC_Notice_Service::POST_TYPE !== $post->post_type ) {
			return false;
		}
		if ( $user_id > 0 && self::can_manage( $user_id ) ) {
			return true;
		}
		if ( 'publish' !== $post->post_status || CC_Notice_Service::is_archived( $notice_id ) ) {
			return false;
		}
		$audience = CC_Notice_Service::audience( $notice_id );
		if ( $audience['public'] ) {
			return true;
		}
		if ( $user_id <= 0 ) {
			return false;
		}
		foreach ( $audience['batch_ids'] as $batch_id ) {
			if ( CC_Enrollment_Repository::user_has_active( $user_id, $batch_id ) ) {
				return true;
			}
		}
		return false;
	}

	/** Turns the main query into a 404 when the viewer may not see the notice. @return bool True when it was turned into a 404. */
	public static function maybe_404(): bool {
		if ( ! is_singular( CC_Notice_Service::POST_TYPE ) ) {
			return false;
		}
		$notice_id = (int) get_queried_object_id();
		if ( self::can_view( $notice_id, get_current_user_id() ) ) {
			if ( ! CC_Notice_Service::audience( $notice_id )['public'] && ! headers_sent() ) {
				nocache_headers();
			}
			return false;
		}
		global $wp_query;
		$wp_query->set_404();
		if ( ! headers_sent() ) {
			status_header( 404 );
			nocache_headers();
		}
		return true;
	}

	/**
	 * Same rule for GET/HEAD /wp/v2/cc_notice/{id}, answered with the standard "invalid id" error so existence is not revealed.
	 * WordPress matches routes case-insensitively, so the route check is too; the handler check does not depend on the
	 * spelling at all.
	 */
	public static function guard_rest_single( $response, $handler, $request ) {
		if ( ! $request instanceof WP_REST_Request || ! in_array( $request->get_method(), array( 'GET', 'HEAD' ), true ) ) {
			return $response;
		}
		$notice_id = 0;
		if ( preg_match( '#^/wp/v2/' . CC_Notice_Service::POST_TYPE . '/(\d+)/?$#i', $request->get_route(), $m ) ) {
			$notice_id = (int) $m[1];
		}
		$callback = is_array( $handler ) ? ( $handler['callback'] ?? null ) : null;
		if ( is_array( $callback ) && $callback[0] instanceof WP_REST_Posts_Controller && 'get_item' === $callback[1] ) {
			$notice_id = max( $notice_id, absint( $request['id'] ) );
		}
		if ( $notice_id <= 0 || self::can_view_if_notice( $notice_id ) ) {
			return $response;
		}
		return new WP_Error( 'rest_post_invalid_id', 'Invalid post ID.', array( 'status' => 404 ) );
	}

	/** True for anything that is not a notice, or a notice the current viewer may open. */
	private static function can_view_if_notice( int $post_id ): bool {
		if ( $post_id <= 0 ) {
			return true;
		}
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || CC_Notice_Service::POST_TYPE !== $post->post_type ) {
			return true;
		}
		return self::can_view( $post_id, get_current_user_id() );
	}

	/**
	 * Stops /?p=ID, ?page_id= and ?attachment_id= (and guessed slugs) from 301-ing to the permalink, which would reveal the
	 * title slug of a notice the viewer cannot open. The request then ends as the plain 404 set by maybe_404().
	 *
	 * @param mixed $redirect_url
	 * @return mixed
	 */
	public static function guard_canonical_redirect( $redirect_url ) {
		if ( ! is_string( $redirect_url ) || '' === $redirect_url ) {
			return $redirect_url;
		}
		foreach ( array( 'p', 'page_id', 'attachment_id' ) as $var ) {
			$id = absint( get_query_var( $var ) );
			if ( $id > 0 && ! self::can_view_if_notice( $id ) ) {
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

	/** oEmbed resolves ?p=ID through url_to_postid(); a hidden notice must look like an unknown URL. */
	public static function guard_oembed_post_id( $post_id ) {
		return self::can_view_if_notice( (int) $post_id ) ? $post_id : 0;
	}

	/** Backstop for oembed_request_post_id: no title or permalink for a hidden notice, whatever resolved it. */
	public static function guard_oembed_data( $data, $post ) {
		return $post instanceof WP_Post && ! self::can_view_if_notice( (int) $post->ID ) ? false : $data;
	}
}
