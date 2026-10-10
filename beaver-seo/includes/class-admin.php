<?php
/**
 * Admin screens: settings and the redirect manager.
 *
 * @package BeaverSEO
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders wp-admin for this plugin and handles its non-AJAX form posts.
 *
 * @since 1.0.0
 */
class Beaver_SEO_Admin {

	const MENU_SLUG      = 'beaver-seo';
	const REDIRECTS_SLUG = 'beaver-seo-redirects';
	const CAPABILITY     = 'manage_options';
	const NONCE_REDIRECT = 'beaver_seo_redirect_action';
	const NONCE_DISMISS  = 'beaver_seo_dismiss_competitor';

	/**
	 * Plugin slugs known to conflict: each also fills the title tag, the
	 * description and Open Graph, and two of them writing the same tag is
	 * how a site ends up with a duplicate <title> or two canonical URls.
	 *
	 * @var string[]
	 */
	const COMPETITORS = array(
		'wordpress-seo/wp-seo.php'                 => 'Yoast SEO',
		'seo-by-rank-math/rank-math.php'            => 'Rank Math',
		'all-in-one-seo-pack/all_in_one_seo_pack.php' => 'All in One SEO',
		'wp-seopress/seopress.php'                  => 'SEOPress',
	);

	/**
	 * Registers the admin hooks.
	 *
	 * @since 1.0.0
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_competitor_notice' ) );

		add_action( 'admin_post_beaver_seo_add_redirect', array( __CLASS__, 'handle_add_redirect' ) );
		add_action( 'admin_post_beaver_seo_delete_redirect', array( __CLASS__, 'handle_delete_redirect' ) );
		add_action( 'admin_post_beaver_seo_dismiss_competitor', array( __CLASS__, 'handle_dismiss_competitor' ) );
		add_action( 'admin_post_beaver_seo_rebuild_sitemap', array( __CLASS__, 'handle_rebuild_sitemap' ) );
	}

	/*
	 * -----------------------------------------------------------------------
	 * Menu and assets
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Registers the admin menu.
	 *
	 * @since 1.0.0
	 */
	public static function register_menu() {
		add_menu_page(
			__( 'Beaver SEO', 'beaver-seo' ),
			__( 'SEO', 'beaver-seo' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( __CLASS__, 'render_settings' ),
			'dashicons-search',
			82
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Settings', 'beaver-seo' ),
			__( 'Settings', 'beaver-seo' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( __CLASS__, 'render_settings' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Redirects', 'beaver-seo' ),
			__( 'Redirects', 'beaver-seo' ),
			self::CAPABILITY,
			self::REDIRECTS_SLUG,
			array( __CLASS__, 'render_redirects' )
		);
	}

	/**
	 * Enqueues the admin stylesheet and script.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook_suffix Current admin page.
	 */
	public static function enqueue_assets( $hook_suffix ) {
		$is_plugin_screen = ( false !== strpos( (string) $hook_suffix, self::MENU_SLUG ) );
		$is_editor        = in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true );
		$is_term_screen   = ( 'term.php' === $hook_suffix || 'edit-tags.php' === $hook_suffix );

		if ( ! $is_plugin_screen && ! $is_editor && ! $is_term_screen ) {
			return;
		}

		wp_enqueue_style( 'beaver-seo-admin', BEAVER_SEO_URL . 'admin/css/admin.css', array(), BEAVER_SEO_VERSION );

		if ( $is_editor || $is_term_screen ) {
			wp_enqueue_media();
			wp_enqueue_script( 'beaver-seo-admin', BEAVER_SEO_URL . 'admin/js/admin.js', array(), BEAVER_SEO_VERSION, true );

			wp_localize_script(
				'beaver-seo-admin',
				'beaverSEO',
				array(
					'i18n' => array(
						'chooseImage' => __( 'Choose a social image', 'beaver-seo' ),
						'useImage'    => __( 'Use this image', 'beaver-seo' ),
						'noTitle'     => __( '(using the default title template)', 'beaver-seo' ),
						'noDescription' => __( '(using an automatic summary)', 'beaver-seo' ),
					),
				)
			);
		}
	}

	/*
	 * -----------------------------------------------------------------------
	 * Settings API
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Registers the settings group.
	 *
	 * @since 1.0.0
	 */
	public static function register_settings() {
		register_setting(
			'beaver_seo_settings_group',
			Beaver_SEO_Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ),
				'default'           => Beaver_SEO_Settings::defaults(),
			)
		);
	}

	/**
	 * Sanitizes submitted settings, gated on capability.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $input Raw settings.
	 * @return array Sanitized settings.
	 */
	public static function sanitize_settings( $input ) {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return Beaver_SEO_Settings::all();
		}

		$clean = Beaver_SEO_Settings::sanitize( $input );

		// Carries the dismissal of the competitor notice through a settings
		// save; it lives in the same option but is never on the form itself.
		$clean['competitor_dismissed'] = (int) Beaver_SEO_Settings::get( 'competitor_dismissed', 0 );

		Beaver_SEO_Settings::flush();
		Beaver_SEO_Sitemap::flush_cache();

		return $clean;
	}

	/*
	 * -----------------------------------------------------------------------
	 * Competitor notice
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Warns when another SEO plugin is also active.
	 *
	 * Detects only; nothing is deactivated automatically. Two SEO plugins
	 * both filtering the title tag and printing meta tags is a site the
	 * administrator broke, not one this plugin should silently fix by
	 * disabling something they may be relying on.
	 *
	 * @since 1.0.0
	 */
	public static function render_competitor_notice() {
		if ( ! current_user_can( self::CAPABILITY ) || Beaver_SEO_Settings::get( 'competitor_dismissed' ) ) {
			return;
		}

		$found = array();

		foreach ( self::COMPETITORS as $file => $name ) {
			if ( is_plugin_active( $file ) ) {
				$found[] = $name;
			}
		}

		if ( empty( $found ) ) {
			return;
		}

		?>
		<div class="notice notice-warning">
			<p>
				<?php
				printf(
					/* translators: %s: comma separated list of other active SEO plugins. */
					esc_html__( 'Beaver SEO is active alongside %s. Running two SEO plugins usually means duplicate title tags, meta descriptions and canonical URLs — deactivate one of them.', 'beaver-seo' ),
					esc_html( implode( ', ', $found ) )
				);
				?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="beaver_seo_dismiss_competitor" />
				<?php wp_nonce_field( self::NONCE_DISMISS ); ?>
				<p><button type="submit" class="button button-small"><?php esc_html_e( 'Dismiss', 'beaver-seo' ); ?></button></p>
			</form>
		</div>
		<?php
	}

	/**
	 * Dismisses the competitor notice.
	 *
	 * @since 1.0.0
	 */
	public static function handle_dismiss_competitor() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'beaver-seo' ) );
		}

		check_admin_referer( self::NONCE_DISMISS );

		$settings                         = Beaver_SEO_Settings::all();
		$settings['competitor_dismissed'] = 1;

		update_option( Beaver_SEO_Settings::OPTION, $settings );
		Beaver_SEO_Settings::flush();

		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}

	/*
	 * -----------------------------------------------------------------------
	 * Redirects
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Handles the "add redirect" form.
	 *
	 * @since 1.0.0
	 */
	public static function handle_add_redirect() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'beaver-seo' ) );
		}

		check_admin_referer( self::NONCE_REDIRECT );

		$source = isset( $_POST['source_path'] ) ? sanitize_text_field( wp_unslash( $_POST['source_path'] ) ) : '';
		$target = isset( $_POST['target_url'] ) ? wp_unslash( $_POST['target_url'] ) : '';
		$status = isset( $_POST['status_code'] ) ? absint( $_POST['status_code'] ) : 301;

		$result = Beaver_SEO_Redirects::create( $source, $target, $status );

		$args = array( 'page' => self::REDIRECTS_SLUG );

		if ( is_wp_error( $result ) ) {
			$args['beaver_seo_error'] = rawurlencode( $result->get_error_message() );
		} else {
			$args['beaver_seo_added'] = 1;
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Handles deleting a redirect.
	 *
	 * @since 1.0.0
	 */
	public static function handle_delete_redirect() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'beaver-seo' ) );
		}

		check_admin_referer( self::NONCE_REDIRECT );

		Beaver_SEO_Redirects::delete( isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0 );

		wp_safe_redirect( add_query_arg( array( 'page' => self::REDIRECTS_SLUG, 'beaver_seo_deleted' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Clears the sitemap cache on demand.
	 *
	 * @since 1.0.0
	 */
	public static function handle_rebuild_sitemap() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'beaver-seo' ) );
		}

		check_admin_referer( self::NONCE_REDIRECT );

		Beaver_SEO_Sitemap::flush_cache();

		wp_safe_redirect( add_query_arg( array( 'page' => self::MENU_SLUG, 'beaver_seo_rebuilt' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Renders the redirect manager screen.
	 *
	 * @since 1.0.0
	 */
	public static function render_redirects() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to view this page.', 'beaver-seo' ) );
		}

		$rows = Beaver_SEO_Redirects::all();
		?>
		<div class="wrap beaver-seo">
			<h1><?php esc_html_e( 'Redirects', 'beaver-seo' ); ?></h1>

			<?php if ( isset( $_GET['beaver_seo_error'] ) ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['beaver_seo_error'] ) ) ); ?></p></div>
			<?php elseif ( isset( $_GET['beaver_seo_added'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Redirect added.', 'beaver-seo' ); ?></p></div>
			<?php elseif ( isset( $_GET['beaver_seo_deleted'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Redirect removed.', 'beaver-seo' ); ?></p></div>
			<?php endif; ?>

			<div class="beaver-seo-panel">
				<h2><?php esc_html_e( 'Add a redirect', 'beaver-seo' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="beaver_seo_add_redirect" />
					<?php wp_nonce_field( self::NONCE_REDIRECT ); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="beaver-seo-source"><?php esc_html_e( 'From', 'beaver-seo' ); ?></label></th>
							<td><input type="text" id="beaver-seo-source" name="source_path" class="regular-text" placeholder="/old-page" required /></td>
						</tr>
						<tr>
							<th scope="row"><label for="beaver-seo-target"><?php esc_html_e( 'To', 'beaver-seo' ); ?></label></th>
							<td><input type="text" id="beaver-seo-target" name="target_url" class="regular-text" placeholder="/new-page" required /></td>
						</tr>
						<tr>
							<th scope="row"><label for="beaver-seo-status"><?php esc_html_e( 'Type', 'beaver-seo' ); ?></label></th>
							<td>
								<select id="beaver-seo-status" name="status_code">
									<option value="301"><?php esc_html_e( '301 — Permanent', 'beaver-seo' ); ?></option>
									<option value="302"><?php esc_html_e( '302 — Temporary', 'beaver-seo' ); ?></option>
									<option value="307"><?php esc_html_e( '307 — Temporary, method preserved', 'beaver-seo' ); ?></option>
								</select>
							</td>
						</tr>
					</table>
					<?php submit_button( __( 'Add redirect', 'beaver-seo' ) ); ?>
				</form>
			</div>

			<div class="beaver-seo-panel">
				<h2><?php esc_html_e( 'Existing redirects', 'beaver-seo' ); ?></h2>
				<?php if ( empty( $rows ) ) : ?>
					<p><?php esc_html_e( 'No redirects yet.', 'beaver-seo' ); ?></p>
				<?php else : ?>
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'From', 'beaver-seo' ); ?></th>
								<th><?php esc_html_e( 'To', 'beaver-seo' ); ?></th>
								<th><?php esc_html_e( 'Type', 'beaver-seo' ); ?></th>
								<th><?php esc_html_e( 'Hits', 'beaver-seo' ); ?></th>
								<th><?php esc_html_e( 'Last hit', 'beaver-seo' ); ?></th>
								<th></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $rows as $row ) : ?>
								<tr>
									<td><code><?php echo esc_html( $row->source_path ); ?></code></td>
									<td><code><?php echo esc_html( $row->target_url ); ?></code></td>
									<td><?php echo esc_html( $row->status_code ); ?></td>
									<td><?php echo esc_html( number_format_i18n( (int) $row->hits ) ); ?></td>
									<td><?php echo $row->last_hit_at ? esc_html( human_time_diff( strtotime( $row->last_hit_at ) ) . ' ' . __( 'ago', 'beaver-seo' ) ) : '&mdash;'; ?></td>
									<td>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Remove this redirect?', 'beaver-seo' ) ); ?>');">
											<input type="hidden" name="action" value="beaver_seo_delete_redirect" />
											<input type="hidden" name="id" value="<?php echo (int) $row->id; ?>" />
											<?php wp_nonce_field( self::NONCE_REDIRECT ); ?>
											<button type="submit" class="button-link beaver-seo-danger"><?php esc_html_e( 'Delete', 'beaver-seo' ); ?></button>
										</form>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>

			<?php self::render_credit(); ?>
		</div>
		<?php
	}

	/*
	 * -----------------------------------------------------------------------
	 * Settings screen
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Renders the settings screen.
	 *
	 * @since 1.0.0
	 */
	public static function render_settings() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to view this page.', 'beaver-seo' ) );
		}

		$settings = Beaver_SEO_Settings::all();
		$option   = Beaver_SEO_Settings::OPTION;
		?>
		<div class="wrap beaver-seo">
			<h1><?php esc_html_e( 'Beaver SEO', 'beaver-seo' ); ?></h1>

			<?php if ( isset( $_GET['beaver_seo_rebuilt'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Sitemap cache cleared. The next crawl will see fresh files.', 'beaver-seo' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'beaver_seo_settings_group' ); ?>

				<h2 class="title"><?php esc_html_e( 'Titles', 'beaver-seo' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="beaver-seo-site-name"><?php esc_html_e( 'Site name', 'beaver-seo' ); ?></label></th>
						<td>
							<input type="text" id="beaver-seo-site-name" class="regular-text" name="<?php echo esc_attr( $option ); ?>[site_name]" value="<?php echo esc_attr( $settings['site_name'] ); ?>" placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>" />
							<p class="description"><?php esc_html_e( 'Used by the %sitename% token. Leave blank to use the site title.', 'beaver-seo' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="beaver-seo-sep"><?php esc_html_e( 'Title separator', 'beaver-seo' ); ?></label></th>
						<td><input type="text" id="beaver-seo-sep" class="small-text" name="<?php echo esc_attr( $option ); ?>[title_separator]" value="<?php echo esc_attr( $settings['title_separator'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="beaver-seo-title-template"><?php esc_html_e( 'Title template', 'beaver-seo' ); ?></label></th>
						<td>
							<input type="text" id="beaver-seo-title-template" class="large-text" name="<?php echo esc_attr( $option ); ?>[title_template]" value="<?php echo esc_attr( $settings['title_template'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Tokens: %title% %sitename% %tagline% %sep% %excerpt% %date% %author% %year%', 'beaver-seo' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="beaver-seo-title-home"><?php esc_html_e( 'Homepage title template', 'beaver-seo' ); ?></label></th>
						<td><input type="text" id="beaver-seo-title-home" class="large-text" name="<?php echo esc_attr( $option ); ?>[title_template_home]" value="<?php echo esc_attr( $settings['title_template_home'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="beaver-seo-desc-home"><?php esc_html_e( 'Homepage meta description', 'beaver-seo' ); ?></label></th>
						<td><textarea id="beaver-seo-desc-home" class="large-text" rows="2" name="<?php echo esc_attr( $option ); ?>[description_home]"><?php echo esc_textarea( $settings['description_home'] ); ?></textarea></td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Visibility', 'beaver-seo' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Noindex', 'beaver-seo' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( $option ); ?>[noindex_search]" value="1" <?php checked( $settings['noindex_search'], 1 ); ?> /> <?php esc_html_e( 'Search results pages', 'beaver-seo' ); ?></label><br />
							<label><input type="checkbox" name="<?php echo esc_attr( $option ); ?>[noindex_archives]" value="1" <?php checked( $settings['noindex_archives'], 1 ); ?> /> <?php esc_html_e( 'Category, tag and other archive pages', 'beaver-seo' ); ?></label><br />
							<label><input type="checkbox" name="<?php echo esc_attr( $option ); ?>[noindex_paginated]" value="1" <?php checked( $settings['noindex_paginated'], 1 ); ?> /> <?php esc_html_e( 'Page 2 and beyond of any listing', 'beaver-seo' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'robots.txt', 'beaver-seo' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( $option ); ?>[robots_txt_managed]" value="1" <?php checked( $settings['robots_txt_managed'], 1 ); ?> /> <?php esc_html_e( 'Let Beaver SEO edit robots.txt', 'beaver-seo' ); ?></label>
							<p class="description">
								<?php
								esc_html_e( 'Only takes effect when the site has no physical robots.txt file in its root directory — a real file on disk always wins.', 'beaver-seo' );
								?>
							</p>
							<textarea class="large-text code" rows="5" name="<?php echo esc_attr( $option ); ?>[robots_txt_content]" placeholder="User-agent: *&#10;Disallow: /wp-admin/&#10;Allow: /wp-admin/admin-ajax.php"><?php echo esc_textarea( $settings['robots_txt_content'] ?? '' ); ?></textarea>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Social sharing', 'beaver-seo' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Default social image', 'beaver-seo' ); ?></th>
						<td>
							<?php $default_image_url = $settings['social_default_image'] ? wp_get_attachment_image_url( (int) $settings['social_default_image'], 'thumbnail' ) : ''; ?>
							<img data-beaver-seo-image-preview src="<?php echo esc_url( $default_image_url ); ?>" style="max-width:100px;max-height:100px;display:block;margin-bottom:8px;<?php echo $default_image_url ? '' : 'display:none;'; ?>" />
							<input type="hidden" name="<?php echo esc_attr( $option ); ?>[social_default_image]" value="<?php echo esc_attr( $settings['social_default_image'] ); ?>" data-beaver-seo-image-id />
							<button type="button" class="button" data-beaver-seo-image-select><?php esc_html_e( 'Choose image', 'beaver-seo' ); ?></button>
							<button type="button" class="button" data-beaver-seo-image-remove <?php echo $default_image_url ? '' : 'style="display:none;"'; ?>><?php esc_html_e( 'Remove', 'beaver-seo' ); ?></button>
							<p class="description"><?php esc_html_e( 'Used when a shared page has no featured image and no image of its own.', 'beaver-seo' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="beaver-seo-twitter-card"><?php esc_html_e( 'Twitter card type', 'beaver-seo' ); ?></label></th>
						<td>
							<select id="beaver-seo-twitter-card" name="<?php echo esc_attr( $option ); ?>[twitter_card_type]">
								<option value="summary_large_image" <?php selected( $settings['twitter_card_type'], 'summary_large_image' ); ?>><?php esc_html_e( 'Large image', 'beaver-seo' ); ?></option>
								<option value="summary" <?php selected( $settings['twitter_card_type'], 'summary' ); ?>><?php esc_html_e( 'Summary', 'beaver-seo' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="beaver-seo-twitter-site"><?php esc_html_e( 'X / Twitter @handle', 'beaver-seo' ); ?></label></th>
						<td><input type="text" id="beaver-seo-twitter-site" class="regular-text" name="<?php echo esc_attr( $option ); ?>[twitter_site]" value="<?php echo esc_attr( $settings['twitter_site'] ); ?>" placeholder="@yoursite" /></td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Structured data', 'beaver-seo' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="beaver-seo-org-type"><?php esc_html_e( 'This site represents a', 'beaver-seo' ); ?></label></th>
						<td>
							<select id="beaver-seo-org-type" name="<?php echo esc_attr( $option ); ?>[organization_type]">
								<option value="Organization" <?php selected( $settings['organization_type'], 'Organization' ); ?>><?php esc_html_e( 'Organization', 'beaver-seo' ); ?></option>
								<option value="LocalBusiness" <?php selected( $settings['organization_type'], 'LocalBusiness' ); ?>><?php esc_html_e( 'Local business', 'beaver-seo' ); ?></option>
								<option value="Person" <?php selected( $settings['organization_type'], 'Person' ); ?>><?php esc_html_e( 'Person', 'beaver-seo' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="beaver-seo-org-name"><?php esc_html_e( 'Name', 'beaver-seo' ); ?></label></th>
						<td>
							<input type="text" id="beaver-seo-org-name" class="regular-text" name="<?php echo esc_attr( $option ); ?>[organization_name]" value="<?php echo esc_attr( $settings['organization_name'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Left blank, no Organization is published and Article pages leave out the publisher reference — an unverifiable guess is worse than nothing.', 'beaver-seo' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Logo', 'beaver-seo' ); ?></th>
						<td>
							<?php $logo_url = $settings['organization_logo'] ? wp_get_attachment_image_url( (int) $settings['organization_logo'], 'thumbnail' ) : ''; ?>
							<img data-beaver-seo-image-preview src="<?php echo esc_url( $logo_url ); ?>" style="max-width:100px;max-height:100px;display:block;margin-bottom:8px;<?php echo $logo_url ? '' : 'display:none;'; ?>" />
							<input type="hidden" name="<?php echo esc_attr( $option ); ?>[organization_logo]" value="<?php echo esc_attr( $settings['organization_logo'] ); ?>" data-beaver-seo-image-id />
							<button type="button" class="button" data-beaver-seo-image-select><?php esc_html_e( 'Choose image', 'beaver-seo' ); ?></button>
							<button type="button" class="button" data-beaver-seo-image-remove <?php echo $logo_url ? '' : 'style="display:none;"'; ?>><?php esc_html_e( 'Remove', 'beaver-seo' ); ?></button>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'XML sitemap', 'beaver-seo' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Sitemap', 'beaver-seo' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( $option ); ?>[sitemap_enabled]" value="1" <?php checked( $settings['sitemap_enabled'], 1 ); ?> /> <?php esc_html_e( 'Publish a sitemap at /sitemap.xml', 'beaver-seo' ); ?></label>
							<?php if ( $settings['sitemap_enabled'] ) : ?>
								<p><a href="<?php echo esc_url( home_url( '/sitemap.xml' ) ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( home_url( '/sitemap.xml' ) ); ?></a></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Included post types', 'beaver-seo' ); ?></th>
						<td>
							<?php foreach ( Beaver_SEO_Settings::public_post_types() as $post_type ) : ?>
								<?php $type_object = get_post_type_object( $post_type ); ?>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( $option ); ?>[sitemap_post_types][]" value="<?php echo esc_attr( $post_type ); ?>" <?php checked( in_array( $post_type, (array) $settings['sitemap_post_types'], true ) ); ?> />
									<?php echo esc_html( $type_object ? $type_object->labels->name : $post_type ); ?>
								</label><br />
							<?php endforeach; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Images', 'beaver-seo' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $option ); ?>[sitemap_images]" value="1" <?php checked( $settings['sitemap_images'], 1 ); ?> /> <?php esc_html_e( 'Include each page\'s image in the sitemap', 'beaver-seo' ); ?></label></td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<div class="beaver-seo-panel">
				<h2><?php esc_html_e( 'Maintenance', 'beaver-seo' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="beaver_seo_rebuild_sitemap" />
					<?php wp_nonce_field( self::NONCE_REDIRECT ); ?>
					<p><?php esc_html_e( 'The sitemap is cached for an hour. Force it to reflect changes immediately.', 'beaver-seo' ); ?></p>
					<?php submit_button( __( 'Clear sitemap cache', 'beaver-seo' ), 'secondary', 'submit', false ); ?>
				</form>
			</div>

			<?php self::render_credit(); ?>
		</div>
		<?php
	}

	/**
	 * Renders the maker's mark shown at the foot of every plugin screen.
	 *
	 * @since 1.0.0
	 */
	private static function render_credit() {
		?>
		<div class="beaver-seo-credit">
			<img class="beaver-seo-credit__logo" width="300" height="152"
			     src="<?php echo esc_url( BEAVER_SEO_URL . 'assets/digital-beaver-logo.png' ); ?>"
			     alt="<?php esc_attr_e( 'Digital Beaver', 'beaver-seo' ); ?>" />
			<div class="beaver-seo-credit__text">
				<strong><?php esc_html_e( 'Designed & built by Digital Beaver', 'beaver-seo' ); ?></strong>
				<?php esc_html_e( 'Need a change, a new feature, or a site as fast as this one?', 'beaver-seo' ); ?>
				<a href="https://digitalbeavertz.com/" target="_blank" rel="noopener noreferrer">digitalbeavertz.com</a>
			</div>
		</div>
		<?php
	}
}
