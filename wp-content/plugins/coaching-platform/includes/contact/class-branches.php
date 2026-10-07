<?php
defined( 'ABSPATH' ) || exit;

/**
 * Branch details on the existing cc_branch type (stays non-public): address, phone, hours and an optional map embed.
 * Only Google Maps and OpenStreetMap embed URLs are ever kept; the check runs on save, on every meta write and again
 * when rendering. Editing is gated by cc_manage_content.
 */
final class CC_Branches {

	const NONCE_ACTION = 'cc_branch_save';
	const NONCE_FIELD  = 'cc_branch_nonce';
	const CAP          = 'cc_manage_content';
	const POST_TYPE    = 'cc_branch';
	const MAX_URL      = 2000;
	const MAX_LINE     = 255;
	const MAX_HOURS    = 300;

	const META_ADDRESS = 'cc_branch_address';
	const META_PHONE   = 'cc_branch_phone';
	const META_HOURS   = 'cc_branch_hours';
	const META_MAP     = 'cc_branch_map_embed_url';

	// Fixed host and path, query made of URL-safe characters only: no quotes, angle brackets, spaces or backslashes.
	const EMBED_PATTERN = '#^https://(?:www\.google\.com/maps/embed|www\.openstreetmap\.org/export/embed\.html)\?[A-Za-z0-9\-._~%!$&()*+,;=:@/?]+$#';

	const SAMPLES = array(
		'main-branch'    => array(
			'title'   => 'Dhaka Main Branch (sample)',
			'address' => 'House 00, Road 00, Sample Area, Dhaka (sample address)',
			'phone'   => '+880 1700-000001',
			'hours'   => 'Saturday to Thursday, 9:00 AM - 8:00 PM',
			'map'     => 'https://www.openstreetmap.org/export/embed.html?bbox=90.38%2C23.74%2C90.42%2C23.78&layer=mapnik',
		),
		'chattogram-branch' => array(
			'title'   => 'Chattogram Branch (sample)',
			'address' => 'Plot 00, Sample Road, Chattogram (sample address)',
			'phone'   => '+880 1700-000002',
			'hours'   => 'Saturday to Thursday, 10:00 AM - 7:00 PM',
			'map'     => 'https://www.openstreetmap.org/export/embed.html?bbox=91.80%2C22.32%2C91.84%2C22.36&layer=mapnik',
		),
	);

	public static function init(): void {
		add_filter( 'register_post_type_args', array( __CLASS__, 'filter_post_type_args' ), 10, 2 );
		add_action( 'init', array( __CLASS__, 'register_meta' ) );
		add_action( 'add_meta_boxes_' . self::POST_TYPE, array( __CLASS__, 'register_metabox' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'save' ) );
		add_filter( 'cc_seo_graph', array( __CLASS__, 'filter_seo_graph' ) );
		add_action( 'cc_seed_extra', array( __CLASS__, 'seed' ) );
	}

	/** Pure: the URL when it is an allowed embed, otherwise an empty string. */
	public static function sanitize_embed_url( $url ): string {
		if ( ! is_string( $url ) ) {
			return '';
		}
		$url = trim( $url );
		return strlen( $url ) <= self::MAX_URL && 1 === preg_match( self::EMBED_PATTERN, $url ) ? $url : '';
	}

	/**
	 * Published branches in menu order.
	 *
	 * @return array<int,array{id:int,name:string,address:string,phone:string,hours:string,map_url:string}>
	 */
	public static function all(): array {
		$posts = get_posts(
			array(
				'post_type'        => self::POST_TYPE,
				'post_status'      => 'publish',
				'posts_per_page'   => 50,
				'orderby'          => array( 'menu_order' => 'ASC', 'title' => 'ASC' ),
				'suppress_filters' => false,
			)
		);
		$out   = array();
		foreach ( $posts as $post ) {
			$out[] = array(
				'id'      => (int) $post->ID,
				'name'    => get_the_title( $post ),
				'address' => (string) get_post_meta( $post->ID, self::META_ADDRESS, true ),
				'phone'   => (string) get_post_meta( $post->ID, self::META_PHONE, true ),
				'hours'   => (string) get_post_meta( $post->ID, self::META_HOURS, true ),
				'map_url' => self::sanitize_embed_url( get_post_meta( $post->ID, self::META_MAP, true ) ),
			);
		}
		return $out;
	}

	/** The sandboxed lazy map iframe, or an empty string when the URL is not an allowed embed. */
	public static function map_iframe( string $url, string $title ): string {
		$url = self::sanitize_embed_url( $url );
		if ( '' === $url ) {
			return '';
		}
		return sprintf(
			'<iframe class="ct-map" src="%s" title="%s" width="600" height="320" loading="lazy" referrerpolicy="no-referrer" sandbox="allow-scripts allow-same-origin"></iframe>',
			esc_url( $url ),
			esc_attr( 'Map: ' . $title )
		);
	}

	/**
	 * @param array<string,mixed> $args
	 * @return array<string,mixed>
	 */
	public static function filter_post_type_args( array $args, string $post_type ): array {
		if ( self::POST_TYPE !== $post_type ) {
			return $args;
		}
		$args['capability_type'] = array( 'cc_branch', 'cc_branches' );
		$args['map_meta_cap']    = true;
		// Primitive caps only: mapping the meta caps (edit_post, read_post, delete_post) would turn cc_manage_content itself into a meta cap.
		$args['capabilities']    = array_fill_keys(
			array( 'edit_posts', 'edit_others_posts', 'delete_posts', 'publish_posts', 'read_private_posts', 'delete_private_posts', 'delete_published_posts', 'delete_others_posts', 'edit_private_posts', 'edit_published_posts', 'create_posts' ),
			self::CAP
		);
		return $args;
	}

	public static function register_meta(): void {
		foreach ( array(
			self::META_ADDRESS => array( __CLASS__, 'sanitize_line' ),
			self::META_PHONE   => array( __CLASS__, 'sanitize_line' ),
			self::META_HOURS   => array( __CLASS__, 'sanitize_hours' ),
			self::META_MAP     => array( __CLASS__, 'sanitize_embed_url' ),
		) as $key => $callback ) {
			register_post_meta( self::POST_TYPE, $key, array( 'type' => 'string', 'single' => true, 'show_in_rest' => false, 'sanitize_callback' => $callback ) );
		}
	}

	public static function sanitize_line( $value ): string {
		return mb_substr( sanitize_text_field( (string) $value ), 0, self::MAX_LINE );
	}

	public static function sanitize_hours( $value ): string {
		return mb_substr( sanitize_textarea_field( (string) $value ), 0, self::MAX_HOURS );
	}

	public static function register_metabox(): void {
		add_meta_box( 'cc_branch_details', 'Branch details', array( __CLASS__, 'render_metabox' ), self::POST_TYPE, 'normal', 'high' );
	}

	public static function render_metabox( WP_Post $post ): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		?>
		<p><label><strong>Address</strong><br><input type="text" class="widefat" maxlength="<?php echo (int) self::MAX_LINE; ?>" name="cc_branch_address" value="<?php echo esc_attr( (string) get_post_meta( $post->ID, self::META_ADDRESS, true ) ); ?>"></label></p>
		<p><label><strong>Phone</strong><br><input type="text" class="widefat" maxlength="<?php echo (int) self::MAX_LINE; ?>" name="cc_branch_phone" value="<?php echo esc_attr( (string) get_post_meta( $post->ID, self::META_PHONE, true ) ); ?>"></label></p>
		<p><label><strong>Opening hours</strong><br><textarea class="widefat" rows="3" maxlength="<?php echo (int) self::MAX_HOURS; ?>" name="cc_branch_hours"><?php echo esc_textarea( (string) get_post_meta( $post->ID, self::META_HOURS, true ) ); ?></textarea></label></p>
		<p><label><strong>Map embed URL</strong><br><input type="url" class="widefat" maxlength="<?php echo (int) self::MAX_URL; ?>" name="cc_branch_map_embed_url" value="<?php echo esc_attr( (string) get_post_meta( $post->ID, self::META_MAP, true ) ); ?>"></label></p>
		<p class="description">Only a Google Maps embed link (https://www.google.com/maps/embed?...) or an OpenStreetMap embed link (https://www.openstreetmap.org/export/embed.html?...) is accepted. Anything else is discarded.</p>
		<?php
	}

	public static function save( int $post_id ): void {
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ), self::NONCE_ACTION ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		foreach ( array( self::META_ADDRESS, self::META_PHONE, self::META_HOURS, self::META_MAP ) as $key ) {
			// The registered sanitize_callback cleans the value; unslash first because WordPress slashes $_POST.
			update_post_meta( $post_id, $key, wp_unslash( $_POST[ $key ] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised by the registered meta callback.
		}
	}

	/**
	 * Adds branch locations to the home organization entry and, on /contact/, a dedicated organization entry.
	 *
	 * @param array<int,array<string,mixed>> $graph
	 * @return array<int,array<string,mixed>>
	 */
	public static function filter_seo_graph( array $graph ): array {
		$is_home    = is_front_page();
		$is_contact = is_page( 'contact' );
		if ( ! $is_home && ! $is_contact ) {
			return $graph;
		}
		$locations = array();
		foreach ( self::all() as $branch ) {
			$place = array( '@type' => 'Place', 'name' => $branch['name'] );
			if ( '' !== $branch['address'] ) {
				$place['address'] = array( '@type' => 'PostalAddress', 'streetAddress' => $branch['address'] );
			}
			if ( '' !== $branch['phone'] ) {
				$place['telephone'] = $branch['phone'];
			}
			$locations[] = $place;
		}
		if ( ! $locations ) {
			return $graph;
		}
		if ( $is_contact ) {
			$graph[] = array( '@type' => 'EducationalOrganization', 'name' => get_bloginfo( 'name' ), 'url' => home_url( '/' ), 'location' => $locations );
			return $graph;
		}
		foreach ( $graph as $i => $node ) {
			if ( 'EducationalOrganization' === ( $node['@type'] ?? '' ) ) {
				$graph[ $i ]['location'] = $locations;
			}
		}
		return $graph;
	}

	/** Idempotent: keyed by slug, existing branches (including staff edits) are left untouched. */
	public static function seed(): void {
		foreach ( self::SAMPLES as $slug => $sample ) {
			$found = get_posts( array( 'post_type' => self::POST_TYPE, 'name' => $slug, 'post_status' => array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' ), 'posts_per_page' => 1, 'fields' => 'ids' ) );
			if ( $found ) {
				continue;
			}
			$id = wp_insert_post( array( 'post_type' => self::POST_TYPE, 'post_name' => $slug, 'post_title' => $sample['title'], 'post_status' => 'publish', 'menu_order' => 'main-branch' === $slug ? 1 : 2 ), true );
			if ( is_wp_error( $id ) || ! $id ) {
				continue;
			}
			update_post_meta( $id, self::META_ADDRESS, $sample['address'] );
			update_post_meta( $id, self::META_PHONE, $sample['phone'] );
			update_post_meta( $id, self::META_HOURS, $sample['hours'] );
			update_post_meta( $id, self::META_MAP, $sample['map'] );
		}
	}
}
