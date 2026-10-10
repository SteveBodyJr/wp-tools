<?php
/**
 * Per-post and per-term SEO fields.
 *
 * @package BeaverSEO
 */

defined( 'ABSPATH' ) || exit;

/**
 * Adds the title/description/image/robots/canonical fields to the post
 * editor and to term edit screens, and saves them.
 *
 * @since 1.0.0
 */
class Beaver_SEO_Metabox {

	const NONCE_ACTION = 'beaver_seo_meta';
	const NONCE_FIELD  = 'beaver_seo_meta_nonce';

	/**
	 * Registers the hooks.
	 *
	 * @since 1.0.0
	 */
	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_post_box' ) );
		add_action( 'save_post', array( __CLASS__, 'save_post' ), 10, 2 );

		/*
		 * This class is wired up on `plugins_loaded`, which runs before
		 * WordPress registers any taxonomy — including the built-in
		 * category and post_tag, via create_initial_taxonomies() on `init`.
		 * Reading get_taxonomies() here would see none of them and silently
		 * register no term hooks at all. Priority 20 gives plugins and
		 * themes registering their own taxonomies at the default priority
		 * a chance to run first.
		 */
		add_action( 'init', array( __CLASS__, 'register_taxonomy_hooks' ), 20 );
	}

	/**
	 * Registers the per-taxonomy field and save hooks.
	 *
	 * Split out from self::init() so it can run on `init`, once taxonomies
	 * actually exist to loop over.
	 *
	 * @since 1.0.0
	 */
	public static function register_taxonomy_hooks() {
		foreach ( Beaver_SEO_Settings::public_taxonomies() as $taxonomy ) {
			add_action( "{$taxonomy}_edit_form_fields", array( __CLASS__, 'render_term_fields' ), 10, 2 );
			add_action( "edited_{$taxonomy}", array( __CLASS__, 'save_term' ) );
		}
	}

	/*
	 * -----------------------------------------------------------------------
	 * Posts
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Registers the meta box on every public post type.
	 *
	 * @since 1.0.0
	 */
	public static function add_post_box() {
		foreach ( Beaver_SEO_Settings::public_post_types() as $post_type ) {
			add_meta_box(
				'beaver-seo-post',
				__( 'SEO', 'beaver-seo' ),
				array( __CLASS__, 'render_post_box' ),
				$post_type,
				'normal',
				'high'
			);
		}
	}

	/**
	 * Renders the meta box.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_Post $post Post being edited.
	 */
	public static function render_post_box( $post ) {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );

		$title       = get_post_meta( $post->ID, Beaver_SEO_Meta::META_TITLE, true );
		$description = get_post_meta( $post->ID, Beaver_SEO_Meta::META_DESCRIPTION, true );
		$image_id    = (int) get_post_meta( $post->ID, Beaver_SEO_Meta::META_IMAGE, true );
		$canonical   = get_post_meta( $post->ID, Beaver_SEO_Meta::META_CANONICAL, true );
		$noindex     = '1' === get_post_meta( $post->ID, Beaver_SEO_Meta::META_NOINDEX, true );
		$nofollow    = '1' === get_post_meta( $post->ID, Beaver_SEO_Meta::META_NOFOLLOW, true );

		self::render_fields( 'beaver_seo', $title, $description, $image_id, $canonical, $noindex, $nofollow, get_permalink( $post ) );
	}

	/**
	 * Saves the meta box.
	 *
	 * @since 1.0.0
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public static function save_post( $post_id, $post ) {
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) || ! wp_verify_nonce( wp_unslash( $_POST[ self::NONCE_FIELD ] ), self::NONCE_ACTION ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		self::save_fields( 'post', $post_id );

		unset( $post );
	}

	/*
	 * -----------------------------------------------------------------------
	 * Terms
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Renders the fields on a term's edit screen.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_Term $term     Term being edited.
	 * @param string  $taxonomy Taxonomy name.
	 */
	public static function render_term_fields( $term, $taxonomy ) {
		unset( $taxonomy );

		if ( ! current_user_can( 'manage_categories' ) ) {
			return;
		}

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );

		$title       = get_term_meta( $term->term_id, Beaver_SEO_Meta::META_TITLE, true );
		$description = get_term_meta( $term->term_id, Beaver_SEO_Meta::META_DESCRIPTION, true );
		$image_id    = (int) get_term_meta( $term->term_id, Beaver_SEO_Meta::META_IMAGE, true );
		$canonical   = get_term_meta( $term->term_id, Beaver_SEO_Meta::META_CANONICAL, true );
		$noindex     = '1' === get_term_meta( $term->term_id, Beaver_SEO_Meta::META_NOINDEX, true );
		$nofollow    = '1' === get_term_meta( $term->term_id, Beaver_SEO_Meta::META_NOFOLLOW, true );
		$url         = get_term_link( $term );

		?>
		<tr class="form-field beaver-seo-term-row">
			<th scope="row"><?php esc_html_e( 'SEO', 'beaver-seo' ); ?></th>
			<td>
				<?php self::render_fields( 'beaver_seo', $title, $description, $image_id, $canonical, $noindex, $nofollow, is_wp_error( $url ) ? '' : $url ); ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Saves a term's fields.
	 *
	 * @since 1.0.0
	 *
	 * @param int $term_id Term ID.
	 */
	public static function save_term( $term_id ) {
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) || ! wp_verify_nonce( wp_unslash( $_POST[ self::NONCE_FIELD ] ), self::NONCE_ACTION ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_categories' ) ) {
			return;
		}

		self::save_fields( 'term', $term_id );
	}

	/*
	 * -----------------------------------------------------------------------
	 * Shared
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Renders the shared field markup for a post or a term.
	 *
	 * @since 1.0.0
	 *
	 * @param string $prefix      Field name prefix.
	 * @param string $title       Current title override.
	 * @param string $description Current description override.
	 * @param int    $image_id    Current image attachment ID.
	 * @param string $canonical   Current canonical override.
	 * @param bool   $noindex     Current noindex state.
	 * @param bool   $nofollow    Current nofollow state.
	 * @param string $preview_url URL shown under the snippet preview.
	 */
	private static function render_fields( $prefix, $title, $description, $image_id, $canonical, $noindex, $nofollow, $preview_url ) {
		$image_url = $image_id > 0 ? wp_get_attachment_image_url( $image_id, 'thumbnail' ) : '';
		?>
		<div class="beaver-seo-fields">
			<p class="beaver-seo-preview" data-beaver-seo-preview>
				<span class="beaver-seo-preview__url"><?php echo esc_html( $preview_url ); ?></span>
				<span class="beaver-seo-preview__title" data-beaver-seo-preview-title><?php echo esc_html( '' !== $title ? $title : __( '(using the default title template)', 'beaver-seo' ) ); ?></span>
				<span class="beaver-seo-preview__description" data-beaver-seo-preview-description><?php echo esc_html( '' !== $description ? $description : __( '(using an automatic summary)', 'beaver-seo' ) ); ?></span>
			</p>

			<p>
				<label for="<?php echo esc_attr( $prefix ); ?>_title"><?php esc_html_e( 'SEO title', 'beaver-seo' ); ?></label><br />
				<input type="text" class="widefat" id="<?php echo esc_attr( $prefix ); ?>_title" name="<?php echo esc_attr( $prefix ); ?>_title" value="<?php echo esc_attr( $title ); ?>" data-beaver-seo-title />
			</p>

			<p>
				<label for="<?php echo esc_attr( $prefix ); ?>_description"><?php esc_html_e( 'Meta description', 'beaver-seo' ); ?></label><br />
				<textarea class="widefat" rows="3" id="<?php echo esc_attr( $prefix ); ?>_description" name="<?php echo esc_attr( $prefix ); ?>_description" data-beaver-seo-description><?php echo esc_textarea( $description ); ?></textarea>
			</p>

			<p>
				<label><?php esc_html_e( 'Social image', 'beaver-seo' ); ?></label><br />
				<img src="<?php echo esc_url( $image_url ); ?>" data-beaver-seo-image-preview style="max-width:80px;max-height:80px;<?php echo $image_url ? '' : 'display:none;'; ?>" />
				<input type="hidden" name="<?php echo esc_attr( $prefix ); ?>_image" value="<?php echo esc_attr( $image_id ); ?>" data-beaver-seo-image-id />
				<button type="button" class="button" data-beaver-seo-image-select><?php esc_html_e( 'Choose image', 'beaver-seo' ); ?></button>
				<button type="button" class="button" data-beaver-seo-image-remove <?php echo $image_url ? '' : 'style="display:none;"'; ?>><?php esc_html_e( 'Remove', 'beaver-seo' ); ?></button>
				<p class="description"><?php esc_html_e( 'Falls back to the featured image, then the site default.', 'beaver-seo' ); ?></p>
			</p>

			<p>
				<label for="<?php echo esc_attr( $prefix ); ?>_canonical"><?php esc_html_e( 'Canonical URL', 'beaver-seo' ); ?></label><br />
				<input type="url" class="widefat" id="<?php echo esc_attr( $prefix ); ?>_canonical" name="<?php echo esc_attr( $prefix ); ?>_canonical" value="<?php echo esc_attr( $canonical ); ?>" placeholder="<?php echo esc_attr( $preview_url ); ?>" />
				<p class="description"><?php esc_html_e( 'Only needed when this content is also published somewhere else.', 'beaver-seo' ); ?></p>
			</p>

			<p>
				<label>
					<input type="checkbox" name="<?php echo esc_attr( $prefix ); ?>_noindex" value="1" <?php checked( $noindex ); ?> />
					<?php esc_html_e( 'Hide from search results (noindex)', 'beaver-seo' ); ?>
				</label><br />
				<label>
					<input type="checkbox" name="<?php echo esc_attr( $prefix ); ?>_nofollow" value="1" <?php checked( $nofollow ); ?> />
					<?php esc_html_e( "Don't follow links from this page (nofollow)", 'beaver-seo' ); ?>
				</label>
			</p>
		</div>
		<?php
	}

	/**
	 * Saves the shared fields for a post or a term.
	 *
	 * @since 1.0.0
	 *
	 * @param string $type   Either 'post' or 'term'.
	 * @param int    $object_id Post or term ID.
	 */
	private static function save_fields( $type, $object_id ) {
		$prefix = 'beaver_seo';

		$title       = isset( $_POST[ "{$prefix}_title" ] ) ? sanitize_text_field( wp_unslash( $_POST[ "{$prefix}_title" ] ) ) : '';
		$description = isset( $_POST[ "{$prefix}_description" ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ "{$prefix}_description" ] ) ) : '';
		$image_id    = isset( $_POST[ "{$prefix}_image" ] ) ? absint( $_POST[ "{$prefix}_image" ] ) : 0;
		$canonical   = isset( $_POST[ "{$prefix}_canonical" ] ) ? esc_url_raw( wp_unslash( $_POST[ "{$prefix}_canonical" ] ) ) : '';
		$noindex     = ! empty( $_POST[ "{$prefix}_noindex" ] );
		$nofollow    = ! empty( $_POST[ "{$prefix}_nofollow" ] );

		$writer = 'term' === $type
			? array( 'update' => 'update_term_meta', 'delete' => 'delete_term_meta' )
			: array( 'update' => 'update_post_meta', 'delete' => 'delete_post_meta' );

		self::write( $writer, $object_id, Beaver_SEO_Meta::META_TITLE, $title );
		self::write( $writer, $object_id, Beaver_SEO_Meta::META_DESCRIPTION, $description );
		self::write( $writer, $object_id, Beaver_SEO_Meta::META_CANONICAL, $canonical );

		if ( $image_id > 0 ) {
			call_user_func( $writer['update'], $object_id, Beaver_SEO_Meta::META_IMAGE, $image_id );
		} else {
			call_user_func( $writer['delete'], $object_id, Beaver_SEO_Meta::META_IMAGE );
		}

		if ( $noindex ) {
			call_user_func( $writer['update'], $object_id, Beaver_SEO_Meta::META_NOINDEX, '1' );
		} else {
			call_user_func( $writer['delete'], $object_id, Beaver_SEO_Meta::META_NOINDEX );
		}

		if ( $nofollow ) {
			call_user_func( $writer['update'], $object_id, Beaver_SEO_Meta::META_NOFOLLOW, '1' );
		} else {
			call_user_func( $writer['delete'], $object_id, Beaver_SEO_Meta::META_NOFOLLOW );
		}
	}

	/**
	 * Writes or clears one text field, keeping empty values out of the database.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $writer    Callable pair from self::save_fields().
	 * @param int    $object_id Post or term ID.
	 * @param string $meta_key  Meta key.
	 * @param string $value     New value.
	 */
	private static function write( array $writer, $object_id, $meta_key, $value ) {
		if ( '' !== $value ) {
			call_user_func( $writer['update'], $object_id, $meta_key, $value );
		} else {
			call_user_func( $writer['delete'], $object_id, $meta_key );
		}
	}
}
