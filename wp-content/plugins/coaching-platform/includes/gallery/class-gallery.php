<?php
defined( 'ABSPATH' ) || exit;

/**
 * Photo gallery: CPT `cc_gallery_item` (never individually public) + taxonomy `cc_gallery_cat`.
 * A published item needs a valid image and alt text; otherwise it is forced back to draft with an admin notice.
 * The public /gallery/ page lists only categories that still have at least one published, renderable item.
 */
final class CC_Gallery {

	const POST_TYPE     = 'cc_gallery_item';
	const TAXONOMY      = 'cc_gallery_cat';
	const CAP           = 'cc_manage_gallery';
	const META_IMAGE    = 'cc_gallery_image';
	const META_CAPTION  = 'cc_gallery_caption';
	const META_ALT      = 'cc_gallery_alt';
	const META_ORDER    = 'cc_gallery_order';
	const NONCE_ACTION  = 'cc_gallery_save';
	const NONCE_FIELD   = 'cc_gallery_nonce';
	const NOTICE_ARG    = 'cc_gallery_notice';
	const MAX_TEXT      = 200;
	const MAX_ORDER     = 9999;
	const MAX_ITEMS     = 500;
	const SEED_KEY_META = '_cc_seed_key';
	const CATEGORIES    = array(
		'campus' => 'Campus',
		'events' => 'Events',
		'results' => 'Results',
	);
	const NOTICES       = array(
		'alt_required'   => 'This gallery item was saved as a draft: alt text is required before it can be published.',
		'image_required' => 'This gallery item was saved as a draft: choose an image before publishing.',
	);

	/** Reason the last save was forced back to draft ('' when it was not). */
	private static string $forced_reason = '';

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'add_meta_boxes_' . self::POST_TYPE, array( __CLASS__, 'add_metabox' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'save_metabox' ), 10, 1 );
		add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'enforce_publish_rules' ), 20, 1 );
		add_filter( 'redirect_post_location', array( __CLASS__, 'forced_draft_redirect' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_notice' ) );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );
		add_action( 'cc_seed_extra', array( __CLASS__, 'seed' ) );
	}

	/** Every primitive capability of a CC content CPT maps to the single gallery capability. */
	public static function cpt_caps(): array {
		$caps = array();
		foreach ( array( 'edit_posts', 'edit_others_posts', 'edit_private_posts', 'edit_published_posts', 'publish_posts', 'read_private_posts', 'delete_posts', 'delete_private_posts', 'delete_published_posts', 'delete_others_posts', 'create_posts' ) as $primitive ) {
			$caps[ $primitive ] = self::CAP;
		}
		return $caps;
	}

	public static function register(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'          => 'Gallery',
					'singular_name' => 'Gallery Item',
					'add_new_item'  => 'Add Gallery Item',
					'edit_item'     => 'Edit Gallery Item',
					'all_items'     => 'All Gallery Items',
					'search_items'  => 'Search Gallery Items',
				),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => true,
				'show_in_rest'        => false,
				'rewrite'             => false,
				'query_var'           => false,
				'has_archive'         => false,
				'menu_icon'           => 'dashicons-format-gallery',
				'supports'            => array( 'title' ),
				'capability_type'     => array( 'cc_gallery_item', 'cc_gallery_items' ),
				'capabilities'        => self::cpt_caps(),
				'map_meta_cap'        => true,
			)
		);

		register_taxonomy(
			self::TAXONOMY,
			array( self::POST_TYPE ),
			array(
				'labels'            => array( 'name' => 'Gallery Categories', 'singular_name' => 'Gallery Category' ),
				'public'            => false,
				'publicly_queryable' => false,
				'show_ui'           => true,
				'show_in_rest'      => false,
				'hierarchical'      => true,
				'show_admin_column' => true,
				'rewrite'           => false,
				'query_var'         => false,
				'capabilities'      => array(
					'manage_terms' => self::CAP,
					'edit_terms'   => self::CAP,
					'delete_terms' => self::CAP,
					'assign_terms' => self::CAP,
				),
			)
		);
	}

	/* ------------------------------------------------------------ pure helpers */

	/** Items sorted by manual order, then newest first, then id (stable and deterministic). */
	public static function sort_items( array $items ): array {
		usort(
			$items,
			static fn( array $a, array $b ): int => $a['order'] <=> $b['order'] ?: $b['time'] <=> $a['time'] ?: $b['id'] <=> $a['id']
		);
		return $items;
	}

	/**
	 * Groups items under the categories that have at least one item; items without any known category go to "Other".
	 *
	 * @param array<int,array{cats:string[]}> $items
	 * @param array<string,string>            $categories slug => name, in display order.
	 * @return array<int,array{slug:string,name:string,items:array}>
	 */
	public static function group_by_category( array $items, array $categories ): array {
		$groups = array();
		foreach ( $categories as $slug => $name ) {
			$in = array_values( array_filter( $items, static fn( array $item ): bool => in_array( $slug, $item['cats'], true ) ) );
			if ( $in ) {
				$groups[] = array( 'slug' => $slug, 'name' => $name, 'items' => $in );
			}
		}
		$known = array_keys( $categories );
		$other = array_values( array_filter( $items, static fn( array $item ): bool => ! array_intersect( $known, $item['cats'] ) ) );
		if ( $other ) {
			$groups[] = array( 'slug' => 'other', 'name' => 'More photos', 'items' => $other );
		}
		return $groups;
	}

	/* ------------------------------------------------------------ data */

	public static function valid_image_id( $id ): int {
		$id = absint( $id );
		return ( $id > 0 && 'attachment' === get_post_type( $id ) && wp_attachment_is_image( $id ) ) ? $id : 0;
	}

	/** '' when the item may be published, otherwise 'image' or 'alt' (the first missing requirement). */
	public static function missing_requirement( int $post_id ): string {
		if ( 0 === self::valid_image_id( get_post_meta( $post_id, self::META_IMAGE, true ) ) ) {
			return 'image';
		}
		return '' === trim( (string) get_post_meta( $post_id, self::META_ALT, true ) ) ? 'alt' : '';
	}

	/** @return array<int,array<string,mixed>> Published, renderable items in display order. */
	public static function public_items(): array {
		$posts = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => self::MAX_ITEMS,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			)
		);
		$items = array();
		foreach ( $posts as $post ) {
			if ( '' !== self::missing_requirement( $post->ID ) ) {
				continue;
			}
			$image_id = (int) get_post_meta( $post->ID, self::META_IMAGE, true );
			$full     = wp_get_attachment_image_src( $image_id, 'large' );
			$terms    = get_the_terms( $post->ID, self::TAXONOMY );
			$items[]  = array(
				'id'      => (int) $post->ID,
				'image'   => $image_id,
				'alt'     => trim( (string) get_post_meta( $post->ID, self::META_ALT, true ) ),
				'caption' => (string) get_post_meta( $post->ID, self::META_CAPTION, true ),
				'order'   => (int) get_post_meta( $post->ID, self::META_ORDER, true ),
				'time'    => (int) strtotime( $post->post_date_gmt . ' UTC' ),
				'cats'    => is_array( $terms ) ? wp_list_pluck( $terms, 'slug' ) : array(),
				'full'    => $full ? array( 'url' => $full[0], 'width' => (int) $full[1], 'height' => (int) $full[2] ) : array( 'url' => '', 'width' => 0, 'height' => 0 ),
			);
		}
		return self::sort_items( $items );
	}

	/** @return array<int,array{slug:string,name:string,items:array}> Categories with at least one published item. */
	public static function public_groups(): array {
		$categories = array();
		$terms      = get_terms( array( 'taxonomy' => self::TAXONOMY, 'hide_empty' => false, 'orderby' => 'term_id' ) );
		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			$categories[ $term->slug ] = $term->name;
		}
		return self::group_by_category( self::public_items(), $categories );
	}

	/* ------------------------------------------------------------ metabox */

	public static function add_metabox(): void {
		add_meta_box( 'cc-gallery-details', 'Image', array( __CLASS__, 'render_metabox' ), self::POST_TYPE, 'normal', 'high' );
	}

	/** Shared "attachment id + preview + link to the Astona media screen" control. */
	public static function render_image_picker( string $field_id, string $name, int $attachment_id ): void {
		$preview = $attachment_id > 0 ? wp_get_attachment_image( $attachment_id, 'thumbnail', false, array( 'style' => 'max-width:150px;height:auto' ) ) : '';
		?>
		<p>
			<label for="<?php echo esc_attr( $field_id ); ?>"><strong>Image (attachment ID)</strong></label><br>
			<input type="number" min="0" class="small-text" id="<?php echo esc_attr( $field_id ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $attachment_id > 0 ? (string) $attachment_id : '' ); ?>">
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=cc-media' ) ); ?>" target="_blank" rel="noopener">Choose from Astona media</a>
		</p>
		<?php if ( '' !== $preview ) : ?>
			<p><?php echo wp_kses_post( $preview ); ?></p>
		<?php else : ?>
			<p class="description">No image selected. Upload on the Media screen (with alt text), then enter the image ID here.</p>
		<?php endif;
	}

	public static function render_metabox( WP_Post $post ): void {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		self::render_image_picker( 'cc-gallery-image', 'cc_gallery_image', (int) get_post_meta( $post->ID, self::META_IMAGE, true ) );
		$order = (int) get_post_meta( $post->ID, self::META_ORDER, true );
		?>
		<p>
			<label for="cc-gallery-alt"><strong>Alt text (required to publish)</strong></label><br>
			<input type="text" class="large-text" maxlength="<?php echo esc_attr( (string) self::MAX_TEXT ); ?>" id="cc-gallery-alt" name="cc_gallery_alt" value="<?php echo esc_attr( (string) get_post_meta( $post->ID, self::META_ALT, true ) ); ?>">
			<span class="description">Describe the picture for people who cannot see it.</span>
		</p>
		<p>
			<label for="cc-gallery-caption"><strong>Caption</strong></label><br>
			<input type="text" class="large-text" maxlength="<?php echo esc_attr( (string) self::MAX_TEXT ); ?>" id="cc-gallery-caption" name="cc_gallery_caption" value="<?php echo esc_attr( (string) get_post_meta( $post->ID, self::META_CAPTION, true ) ); ?>">
		</p>
		<p>
			<label for="cc-gallery-order"><strong>Sort order</strong></label>
			<input type="number" min="0" max="<?php echo esc_attr( (string) self::MAX_ORDER ); ?>" class="small-text" id="cc-gallery-order" name="cc_gallery_order" value="<?php echo esc_attr( (string) $order ); ?>">
			<span class="description">Lower numbers come first; equal numbers show newest first.</span>
		</p>
		<?php
	}

	public static function save_metabox( int $post_id ): void {
		$nonce = isset( $_POST[ self::NONCE_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ) : '';
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
			return;
		}
		if ( ! current_user_can( self::CAP ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		update_post_meta( $post_id, self::META_IMAGE, self::valid_image_id( wp_unslash( $_POST['cc_gallery_image'] ?? 0 ) ) );
		update_post_meta( $post_id, self::META_ALT, mb_substr( trim( sanitize_text_field( wp_unslash( $_POST['cc_gallery_alt'] ?? '' ) ) ), 0, self::MAX_TEXT ) );
		update_post_meta( $post_id, self::META_CAPTION, mb_substr( trim( sanitize_text_field( wp_unslash( $_POST['cc_gallery_caption'] ?? '' ) ) ), 0, self::MAX_TEXT ) );
		update_post_meta( $post_id, self::META_ORDER, min( self::MAX_ORDER, max( 0, absint( wp_unslash( $_POST['cc_gallery_order'] ?? 0 ) ) ) ) );
	}

	/** Runs after the metabox save: a published/scheduled item without image or alt text goes back to draft. */
	public static function enforce_publish_rules( int $post_id ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || ! in_array( $post->post_status, array( 'publish', 'future' ), true ) ) {
			return;
		}
		$missing = self::missing_requirement( $post_id );
		if ( '' === $missing ) {
			return;
		}
		remove_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'enforce_publish_rules' ), 20 );
		wp_update_post( array( 'ID' => $post_id, 'post_status' => 'draft' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'enforce_publish_rules' ), 20, 1 );
		self::$forced_reason = 'image' === $missing ? 'image_required' : 'alt_required';
	}

	public static function forced_draft_redirect( $location ) {
		if ( '' === self::$forced_reason ) {
			return $location;
		}
		return add_query_arg( self::NOTICE_ARG, self::$forced_reason, remove_query_arg( 'message', (string) $location ) );
	}

	public static function render_notice(): void {
		$key = isset( $_GET[ self::NOTICE_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ self::NOTICE_ARG ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- read-only message selection from an allowlist.
		if ( ! isset( self::NOTICES[ $key ] ) || ! current_user_can( self::CAP ) ) {
			return;
		}
		printf( '<div class="notice notice-error is-dismissible"><p>%s</p></div>', esc_html( self::NOTICES[ $key ] ) );
	}

	/* ------------------------------------------------------------ list columns */

	public static function columns( array $columns ): array {
		$out = array();
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'cb' === $key ) {
				$out['cc_thumb'] = 'Image';
			}
		}
		$out['cc_alt'] = 'Alt text';
		return $out;
	}

	public static function render_column( string $column, int $post_id ): void {
		if ( 'cc_thumb' === $column ) {
			$image = self::valid_image_id( get_post_meta( $post_id, self::META_IMAGE, true ) );
			echo $image ? wp_kses_post( wp_get_attachment_image( $image, array( 60, 60 ) ) ) : '&mdash;';
		} elseif ( 'cc_alt' === $column ) {
			$alt = trim( (string) get_post_meta( $post_id, self::META_ALT, true ) );
			echo '' === $alt ? '<em>missing</em>' : esc_html( $alt );
		}
	}

	/* ------------------------------------------------------------ seeding */

	public static function seed(): void {
		self::register();
		$term_ids = array();
		foreach ( self::CATEGORIES as $slug => $name ) {
			$term = term_exists( $slug, self::TAXONOMY ) ?: wp_insert_term( $name, self::TAXONOMY, array( 'slug' => $slug ) );
			if ( is_wp_error( $term ) ) {
				continue;
			}
			$term_ids[ $slug ] = (int) ( is_array( $term ) ? $term['term_id'] : $term );
		}

		$samples = array(
			'gallery-campus-entrance'  => array( 'Main building entrance', 'campus', 'The main entrance of the Astona campus.', array( 67, 56, 202 ) ),
			'gallery-campus-library'   => array( 'Library reading room', 'campus', 'Students studying in the library reading room.', array( 14, 116, 144 ) ),
			'gallery-events-prizes'    => array( 'Annual prize-giving ceremony', 'events', 'Students receiving awards at the annual prize-giving ceremony.', array( 180, 83, 9 ) ),
			'gallery-events-science'   => array( 'Science fair', 'events', 'Students presenting projects at the science fair.', array( 21, 128, 61 ) ),
			'gallery-results-toppers'  => array( 'HSC toppers celebration', 'results', 'HSC toppers celebrating with their teachers.', array( 190, 24, 93 ) ),
			'gallery-results-admission' => array( 'Admission success meet', 'results', 'Students gathered at the university admission success meet.', array( 71, 85, 105 ) ),
		);
		$order = 0;
		foreach ( $samples as $slug => $sample ) {
			++$order;
			if ( get_posts( array( 'post_type' => self::POST_TYPE, 'name' => $slug, 'post_status' => 'any', 'posts_per_page' => 1, 'fields' => 'ids' ) ) ) {
				continue;
			}
			$attachment = self::placeholder_attachment( $slug, $sample[0], $sample[2], $sample[3] );
			if ( 0 === $attachment ) {
				continue;
			}
			$id = wp_insert_post(
				array(
					'post_type'   => self::POST_TYPE,
					'post_name'   => $slug,
					'post_title'  => $sample[0],
					'post_status' => 'publish',
					'meta_input'  => array(
						self::META_IMAGE   => $attachment,
						self::META_ALT     => $sample[2],
						self::META_CAPTION => $sample[0],
						self::META_ORDER   => $order,
					),
				)
			);
			if ( $id && ! is_wp_error( $id ) && isset( $term_ids[ $sample[1] ] ) ) {
				wp_set_object_terms( (int) $id, array( $term_ids[ $sample[1] ] ), self::TAXONOMY );
			}
		}

		self::ensure_page( 'gallery', 'Gallery', 'page-gallery.php' );
		self::ensure_menu_item( 'Gallery', 'gallery' );
	}

	/** Solid-colour 800x600 JPEG saved as a real attachment; reused on re-seed. Returns 0 when GD is unavailable. */
	private static function placeholder_attachment( string $key, string $title, string $alt, array $rgb ): int {
		$existing = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'meta_key' => self::SEED_KEY_META, 'meta_value' => $key, 'posts_per_page' => 1, 'fields' => 'ids' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		if ( $existing ) {
			return (int) $existing[0];
		}
		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			return 0;
		}
		$canvas = imagecreatetruecolor( 800, 600 );
		imagefill( $canvas, 0, 0, imagecolorallocate( $canvas, $rgb[0], $rgb[1], $rgb[2] ) );
		ob_start();
		imagejpeg( $canvas, null, 80 );
		$bytes = (string) ob_get_clean();
		imagedestroy( $canvas );

		$upload = wp_upload_bits( $key . '.jpg', null, $bytes );
		if ( ! empty( $upload['error'] ) ) {
			return 0;
		}
		$attachment_id = wp_insert_attachment(
			array( 'post_mime_type' => 'image/jpeg', 'post_title' => $title, 'post_status' => 'inherit' ),
			$upload['file']
		);
		if ( ! $attachment_id || is_wp_error( $attachment_id ) ) {
			return 0;
		}
		require_once ABSPATH . 'wp-admin/includes/image.php';
		wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $upload['file'] ) );
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );
		update_post_meta( $attachment_id, self::SEED_KEY_META, $key );
		return (int) $attachment_id;
	}

	/** Creates the page with the given slug (using the given template) when it does not exist yet. */
	public static function ensure_page( string $slug, string $title, string $template ): int {
		$found = get_posts( array( 'post_type' => 'page', 'name' => $slug, 'post_status' => array( 'publish', 'draft', 'pending', 'private', 'future' ), 'posts_per_page' => 1, 'fields' => 'ids' ) );
		if ( $found ) {
			return (int) $found[0];
		}
		$id = wp_insert_post( array( 'post_type' => 'page', 'post_name' => $slug, 'post_title' => $title, 'post_status' => 'publish', 'post_content' => '' ) );
		if ( is_wp_error( $id ) || ! $id ) {
			return 0;
		}
		// Set directly: wp_insert_post() drops templates it cannot validate against the theme's cached template list.
		update_post_meta( $id, '_wp_page_template', $template );
		return (int) $id;
	}

	/** Appends a custom link to the Primary menu unless an item with that title or URL is already there. */
	public static function ensure_menu_item( string $label, string $page_slug ): void {
		$menu = wp_get_nav_menu_object( 'Primary' );
		$page = get_page_by_path( $page_slug );
		if ( ! $menu || ! $page ) {
			return;
		}
		$url = get_permalink( $page );
		foreach ( (array) wp_get_nav_menu_items( $menu->term_id ) as $item ) {
			if ( strtolower( $item->title ) === strtolower( $label ) || untrailingslashit( (string) $item->url ) === untrailingslashit( (string) $url ) ) {
				return;
			}
		}
		wp_update_nav_menu_item( $menu->term_id, 0, array( 'menu-item-title' => $label, 'menu-item-url' => $url, 'menu-item-status' => 'publish', 'menu-item-type' => 'custom' ) );
	}
}
