<?php
/**
 * Admin screens, settings form and AJAX endpoints.
 *
 * @package BeaverPWA
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders the dashboard and the settings screen.
 *
 * Every entry point checks the capability first and the nonce second before it
 * touches anything.
 *
 * @since 1.0.0
 */
final class Beaver_PWA_Admin {

	const MENU_SLUG     = 'beaver-pwa';
	const SETTINGS_SLUG = 'beaver-pwa-settings';
	const GROUP         = 'beaver_pwa_settings_group';
	const NONCE_ACTION  = 'beaver_pwa_ajax';
	const CAPABILITY    = 'manage_options';

	/**
	 * Registers admin hooks.
	 *
	 * @since 1.0.0
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_notices' ) );

		add_filter( 'plugin_action_links_' . BEAVER_PWA_BASENAME, array( __CLASS__, 'add_action_links' ) );

		add_action( 'wp_ajax_beaver_pwa_recheck', array( __CLASS__, 'ajax_recheck' ) );
		add_action( 'wp_ajax_beaver_pwa_clear_cache', array( __CLASS__, 'ajax_clear_cache' ) );
		add_action( 'wp_ajax_beaver_pwa_regenerate', array( __CLASS__, 'ajax_regenerate' ) );
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
			__( 'Beaver PWA', 'beaver-pwa' ),
			__( 'Beaver PWA', 'beaver-pwa' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( __CLASS__, 'render_dashboard' ),
			'dashicons-smartphone',
			81
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Dashboard', 'beaver-pwa' ),
			__( 'Dashboard', 'beaver-pwa' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( __CLASS__, 'render_dashboard' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Settings', 'beaver-pwa' ),
			__( 'Settings', 'beaver-pwa' ),
			self::CAPABILITY,
			self::SETTINGS_SLUG,
			array( __CLASS__, 'render_settings' )
		);
	}

	/**
	 * Adds a settings link to the plugins list.
	 *
	 * @since 1.0.0
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public static function add_action_links( $links ) {
		$settings = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=' . self::SETTINGS_SLUG ) ),
			esc_html__( 'Settings', 'beaver-pwa' )
		);

		array_unshift( $links, $settings );

		return $links;
	}

	/**
	 * Loads the admin stylesheet and script on the plugin screens.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook_suffix Current admin page.
	 */
	public static function enqueue_assets( $hook_suffix ) {
		if ( false === strpos( (string) $hook_suffix, self::MENU_SLUG ) ) {
			return;
		}

		wp_enqueue_style(
			'beaver-pwa-admin',
			BEAVER_PWA_URL . 'admin/css/admin.css',
			array(),
			BEAVER_PWA_VERSION
		);

		wp_enqueue_media();

		wp_enqueue_script(
			'beaver-pwa-admin',
			BEAVER_PWA_URL . 'admin/js/admin.js',
			array(),
			BEAVER_PWA_VERSION,
			true
		);

		wp_localize_script(
			'beaver-pwa-admin',
			'beaverPWAAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
				'i18n'    => array(
					'chooseIcon'  => __( 'Choose an app icon', 'beaver-pwa' ),
					'useIcon'     => __( 'Use this icon', 'beaver-pwa' ),
					'working'     => __( 'Working…', 'beaver-pwa' ),
					'checking'    => __( 'Running checks…', 'beaver-pwa' ),
					'failed'      => __( 'That did not work. Try again.', 'beaver-pwa' ),
					'cacheReset'  => __( 'Done. Every visitor will download a fresh copy on their next visit.', 'beaver-pwa' ),
					'iconsBuilt'  => __( 'App icons rebuilt.', 'beaver-pwa' ),
					'confirmWipe' => __( 'This clears the cached copy of the site in every visitor\'s browser. Continue?', 'beaver-pwa' ),
				),
			)
		);
	}

	/**
	 * Warns about anything that stops the site being installable.
	 *
	 * @since 1.0.0
	 */
	public static function render_notices() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$id     = $screen ? (string) $screen->id : '';

		if ( false === strpos( $id, self::MENU_SLUG ) && 'plugins' !== $id && 'dashboard' !== $id ) {
			return;
		}

		if ( Beaver_PWA_Icons::source_id() ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
			esc_html__( 'Beaver PWA:', 'beaver-pwa' ),
			esc_html__( 'no app icon has been set, so browsers will not offer to install the site.', 'beaver-pwa' ),
			esc_url( admin_url( 'admin.php?page=' . self::SETTINGS_SLUG ) ),
			esc_html__( 'Choose one now', 'beaver-pwa' )
		);
	}

	/*
	 * -----------------------------------------------------------------------
	 * Settings registration
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Registers the settings option.
	 *
	 * @since 1.0.0
	 */
	public static function register_settings() {
		register_setting(
			self::GROUP,
			Beaver_PWA_Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ),
				'default'           => Beaver_PWA_Settings::defaults(),
			)
		);
	}

	/**
	 * Sanitises the submitted settings.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $input Raw settings.
	 * @return array
	 */
	public static function sanitize_settings( $input ) {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return Beaver_PWA_Settings::all();
		}

		$clean = Beaver_PWA_Settings::sanitize( $input );

		delete_transient( Beaver_PWA_Health::TRANSIENT );

		return $clean;
	}

	/*
	 * -----------------------------------------------------------------------
	 * AJAX
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Verifies capability and nonce for every AJAX request.
	 *
	 * @since 1.0.0
	 */
	private static function verify_request() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'beaver-pwa' ) ), 403 );
		}

		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
	}

	/**
	 * Re-runs the installability checks.
	 *
	 * @since 1.0.0
	 */
	public static function ajax_recheck() {
		self::verify_request();

		$checks = Beaver_PWA_Health::run( true );

		ob_start();
		self::render_checklist( $checks );

		wp_send_json_success(
			array(
				'html'    => ob_get_clean(),
				'summary' => Beaver_PWA_Health::summary( $checks ),
				'ready'   => Beaver_PWA_Health::is_installable( $checks ),
			)
		);
	}

	/**
	 * Invalidates every cached copy held in visitors' browsers.
	 *
	 * @since 1.0.0
	 */
	public static function ajax_clear_cache() {
		self::verify_request();

		$version = Beaver_PWA_Settings::bump_cache();

		wp_send_json_success(
			array(
				'version' => $version,
				'message' => __( 'Done. Every visitor will download a fresh copy on their next visit.', 'beaver-pwa' ),
			)
		);
	}

	/**
	 * Rebuilds the icon set.
	 *
	 * @since 1.0.0
	 */
	public static function ajax_regenerate() {
		self::verify_request();

		Beaver_PWA_Icons::flush();

		$set = Beaver_PWA_Icons::maybe_generate( true );

		Beaver_PWA_Settings::bump_cache();

		if ( ! empty( $set['error'] ) ) {
			wp_send_json_error( array( 'message' => $set['error'] ) );
		}

		wp_send_json_success(
			array(
				'message' => __( 'App icons rebuilt.', 'beaver-pwa' ),
				'preview' => Beaver_PWA_Icons::preview_url(),
			)
		);
	}

	/*
	 * -----------------------------------------------------------------------
	 * Dashboard
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Renders the dashboard screen.
	 *
	 * @since 1.0.0
	 */
	public static function render_dashboard() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$checks  = Beaver_PWA_Health::run();
		$ready   = Beaver_PWA_Health::is_installable( $checks );
		$summary = Beaver_PWA_Health::summary( $checks );
		$icon    = Beaver_PWA_Icons::preview_url();

		?>
		<div class="wrap beaver-pwa">
			<h1><?php esc_html_e( 'Beaver PWA', 'beaver-pwa' ); ?></h1>
			<p class="beaver-pwa-lead">
				<?php esc_html_e( 'Everything a browser needs before it will offer to install this site on a phone or desktop, checked against the live URLs rather than against the settings.', 'beaver-pwa' ); ?>
			</p>

			<div class="beaver-pwa-status beaver-pwa-status--<?php echo $ready ? 'ready' : 'blocked'; ?>" id="beaver-pwa-status">
				<div class="beaver-pwa-status__mark" aria-hidden="true"><?php echo $ready ? '&#10003;' : '!'; ?></div>
				<div class="beaver-pwa-status__body">
					<h2>
						<?php
						echo $ready
							? esc_html__( 'This site is installable', 'beaver-pwa' )
							: esc_html__( 'This site is not installable yet', 'beaver-pwa' );
						?>
					</h2>
					<p>
						<?php
						printf(
							/* translators: 1: number of passing checks, 2: number of warnings, 3: number of failures. */
							esc_html__( '%1$d checks passed, %2$d warnings, %3$d blocking.', 'beaver-pwa' ),
							(int) $summary['pass'],
							(int) $summary['warn'],
							(int) $summary['fail']
						);
						?>
					</p>
				</div>
				<div class="beaver-pwa-status__actions">
					<button type="button" class="button" id="beaver-pwa-recheck"><?php esc_html_e( 'Re-run checks', 'beaver-pwa' ); ?></button>
				</div>
			</div>

			<div class="beaver-pwa-columns">
				<div class="beaver-pwa-panel">
					<h2><?php esc_html_e( 'Readiness', 'beaver-pwa' ); ?></h2>
					<div id="beaver-pwa-checklist">
						<?php self::render_checklist( $checks ); ?>
					</div>
				</div>

				<div class="beaver-pwa-side">
					<div class="beaver-pwa-panel">
						<h2><?php esc_html_e( 'How it will look', 'beaver-pwa' ); ?></h2>
						<div class="beaver-pwa-preview" style="--beaver-pwa-theme: <?php echo esc_attr( Beaver_PWA_Settings::get( 'theme_color' ) ); ?>; --beaver-pwa-surface: <?php echo esc_attr( Beaver_PWA_Settings::get( 'background_color' ) ); ?>;">
							<div class="beaver-pwa-preview__phone">
								<div class="beaver-pwa-preview__bar"></div>
								<div class="beaver-pwa-preview__screen">
									<?php if ( $icon ) : ?>
										<img src="<?php echo esc_url( $icon ); ?>" alt="" class="beaver-pwa-preview__icon" id="beaver-pwa-preview-icon">
									<?php else : ?>
										<div class="beaver-pwa-preview__icon beaver-pwa-preview__icon--empty"></div>
									<?php endif; ?>
									<span class="beaver-pwa-preview__label"><?php echo esc_html( Beaver_PWA_Settings::short_name() ); ?></span>
								</div>
							</div>
						</div>
						<p class="beaver-pwa-hint">
							<?php esc_html_e( 'The icon and label as they appear on a home screen, over the splash screen colours.', 'beaver-pwa' ); ?>
						</p>
					</div>

					<div class="beaver-pwa-panel">
						<h2><?php esc_html_e( 'Endpoints', 'beaver-pwa' ); ?></h2>
						<table class="beaver-pwa-table">
							<tbody>
								<tr>
									<th scope="row"><?php esc_html_e( 'Manifest', 'beaver-pwa' ); ?></th>
									<td><a href="<?php echo esc_url( Beaver_PWA_Routes::url( 'manifest' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open', 'beaver-pwa' ); ?></a></td>
								</tr>
								<tr>
									<th scope="row"><?php esc_html_e( 'Service worker', 'beaver-pwa' ); ?></th>
									<td><a href="<?php echo esc_url( Beaver_PWA_Routes::url( 'sw' ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open', 'beaver-pwa' ); ?></a></td>
								</tr>
								<tr>
									<th scope="row"><?php esc_html_e( 'Offline page', 'beaver-pwa' ); ?></th>
									<td><a href="<?php echo esc_url( Beaver_PWA_Service_Worker::offline_url() ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open', 'beaver-pwa' ); ?></a></td>
								</tr>
								<tr>
									<th scope="row"><?php esc_html_e( 'Cache signature', 'beaver-pwa' ); ?></th>
									<td><code id="beaver-pwa-version"><?php echo esc_html( Beaver_PWA_Settings::cache_version() ); ?></code></td>
								</tr>
							</tbody>
						</table>
					</div>

					<div class="beaver-pwa-panel">
						<h2><?php esc_html_e( 'Maintenance', 'beaver-pwa' ); ?></h2>
						<p class="beaver-pwa-hint">
							<?php esc_html_e( 'Saving the settings already refreshes every visitor. Use these when something looks stale on its own.', 'beaver-pwa' ); ?>
						</p>
						<p>
							<button type="button" class="button" id="beaver-pwa-clear"><?php esc_html_e( 'Clear visitor caches', 'beaver-pwa' ); ?></button>
							<button type="button" class="button" id="beaver-pwa-regenerate"><?php esc_html_e( 'Rebuild app icons', 'beaver-pwa' ); ?></button>
						</p>
						<p class="beaver-pwa-feedback" id="beaver-pwa-feedback" role="status"></p>
					</div>
				</div>
			</div>

			<?php self::render_credit(); ?>
		</div>
		<?php
	}

	/**
	 * Renders the maker's mark.
	 *
	 * @since 1.0.0
	 */
	private static function render_credit() {
		?>
		<div class="beaver-pwa-credit">
			<img class="beaver-pwa-credit__logo" width="300" height="152"
			     src="<?php echo esc_url( BEAVER_PWA_URL . 'assets/digital-beaver-logo.png' ); ?>"
			     alt="<?php esc_attr_e( 'Digital Beaver', 'beaver-pwa' ); ?>" />
			<div class="beaver-pwa-credit__text">
				<strong><?php esc_html_e( 'Designed & built by Digital Beaver', 'beaver-pwa' ); ?></strong>
				<?php esc_html_e( 'Need a change, a new feature, or a site as fast as this one?', 'beaver-pwa' ); ?>
				<a href="https://digitalbeavertz.com/" target="_blank" rel="noopener noreferrer">digitalbeavertz.com</a>
			</div>
		</div>
		<?php
	}

	/**
	 * Renders the readiness checklist.
	 *
	 * @since 1.0.0
	 *
	 * @param array $checks Result of the health run.
	 */
	private static function render_checklist( $checks ) {
		?>
		<ul class="beaver-pwa-health">
			<?php foreach ( $checks as $check ) : ?>
				<li class="beaver-pwa-health__item beaver-pwa-health__item--<?php echo esc_attr( $check['status'] ); ?>">
					<span class="beaver-pwa-health__mark" aria-hidden="true"></span>
					<span class="beaver-pwa-health__body">
						<strong><?php echo esc_html( $check['label'] ); ?></strong>
						<?php if ( '' !== $check['message'] ) : ?>
							<span class="beaver-pwa-health__message"><?php echo esc_html( $check['message'] ); ?></span>
						<?php endif; ?>
						<?php if ( ! empty( $check['action']['url'] ) ) : ?>
							<a class="beaver-pwa-health__action" href="<?php echo esc_url( $check['action']['url'] ); ?>">
								<?php echo esc_html( $check['action']['label'] ); ?>
							</a>
						<?php endif; ?>
					</span>
					<span class="screen-reader-text">
						<?php
						echo esc_html(
							'pass' === $check['status'] ? __( 'Passed', 'beaver-pwa' ) : (
								'warn' === $check['status'] ? __( 'Warning', 'beaver-pwa' ) : __( 'Blocking', 'beaver-pwa' )
							)
						);
						?>
					</span>
				</li>
			<?php endforeach; ?>
		</ul>
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
			return;
		}

		$name    = Beaver_PWA_Settings::OPTION;
		$icon_id = (int) Beaver_PWA_Settings::get( 'icon_id' );
		$icon    = Beaver_PWA_Icons::preview_url();

		if ( $icon_id ) {
			$icon = wp_get_attachment_image_url( $icon_id, 'medium' );

			if ( ! $icon ) {
				$icon = wp_get_attachment_image_url( $icon_id, 'full' );
			}
		}

		?>
		<div class="wrap beaver-pwa">
			<h1><?php esc_html_e( 'Beaver PWA settings', 'beaver-pwa' ); ?></h1>
			<p class="beaver-pwa-lead">
				<?php esc_html_e( 'Empty fields fall back to the site title, tagline and site icon, so the defaults are already correct for most sites. Saving refreshes the app for every visitor.', 'beaver-pwa' ); ?>
			</p>

			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>

				<div class="beaver-pwa-panel">
					<h2><?php esc_html_e( 'App identity', 'beaver-pwa' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'App mode', 'beaver-pwa' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[enabled]" value="1" <?php checked( Beaver_PWA_Settings::get( 'enabled' ) ); ?>>
									<?php esc_html_e( 'Serve the manifest and service worker', 'beaver-pwa' ); ?>
								</label>
								<p class="description"><?php esc_html_e( 'Turning this off removes the app from every browser that has already installed it, cleanly.', 'beaver-pwa' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="beaver-pwa-app-name"><?php esc_html_e( 'App name', 'beaver-pwa' ); ?></label></th>
							<td>
								<input type="text" class="regular-text" id="beaver-pwa-app-name" name="<?php echo esc_attr( $name ); ?>[app_name]" value="<?php echo esc_attr( Beaver_PWA_Settings::get( 'app_name' ) ); ?>" placeholder="<?php echo esc_attr( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ); ?>" maxlength="60">
								<p class="description"><?php esc_html_e( 'Shown on the install dialog and the splash screen.', 'beaver-pwa' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="beaver-pwa-short-name"><?php esc_html_e( 'Short name', 'beaver-pwa' ); ?></label></th>
							<td>
								<input type="text" class="regular-text" id="beaver-pwa-short-name" name="<?php echo esc_attr( $name ); ?>[short_name]" value="<?php echo esc_attr( Beaver_PWA_Settings::get( 'short_name' ) ); ?>" placeholder="<?php echo esc_attr( Beaver_PWA_Settings::short_name() ); ?>" maxlength="24">
								<p class="description"><?php esc_html_e( 'The label under the home screen icon. Twelve characters or fewer avoids being clipped.', 'beaver-pwa' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="beaver-pwa-description"><?php esc_html_e( 'Description', 'beaver-pwa' ); ?></label></th>
							<td>
								<textarea class="large-text" rows="2" id="beaver-pwa-description" name="<?php echo esc_attr( $name ); ?>[description]" maxlength="300" placeholder="<?php echo esc_attr( wp_specialchars_decode( get_bloginfo( 'description' ), ENT_QUOTES ) ); ?>"><?php echo esc_textarea( Beaver_PWA_Settings::get( 'description' ) ); ?></textarea>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'App icon', 'beaver-pwa' ); ?></th>
							<td>
								<div class="beaver-pwa-icon-field">
									<img src="<?php echo esc_url( $icon ); ?>" alt="" class="beaver-pwa-icon-field__preview" id="beaver-pwa-icon-preview" <?php echo $icon ? '' : 'hidden'; ?>>
									<div>
										<input type="hidden" id="beaver-pwa-icon-id" name="<?php echo esc_attr( $name ); ?>[icon_id]" value="<?php echo esc_attr( $icon_id ); ?>">
										<button type="button" class="button" id="beaver-pwa-icon-choose"><?php esc_html_e( 'Choose image', 'beaver-pwa' ); ?></button>
										<button type="button" class="button-link beaver-pwa-danger" id="beaver-pwa-icon-clear" <?php echo $icon_id ? '' : 'hidden'; ?>><?php esc_html_e( 'Use the site icon', 'beaver-pwa' ); ?></button>
										<p class="description">
											<?php esc_html_e( 'A square image, at least 512 by 512. Leave this empty to use the site icon. Every size is rendered from the source, so nothing needs uploading twice.', 'beaver-pwa' ); ?>
										</p>
									</div>
								</div>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Maskable icon', 'beaver-pwa' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[maskable]" value="1" <?php checked( Beaver_PWA_Settings::get( 'maskable' ) ); ?>>
									<?php esc_html_e( 'Render a padded icon for Android', 'beaver-pwa' ); ?>
								</label>
								<p class="description"><?php esc_html_e( 'Android crops home screen icons to a circle. This insets the artwork on the background colour so nothing important is cut off.', 'beaver-pwa' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<div class="beaver-pwa-panel">
					<h2><?php esc_html_e( 'Appearance and launch', 'beaver-pwa' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="beaver-pwa-theme-color"><?php esc_html_e( 'Theme colour', 'beaver-pwa' ); ?></label></th>
							<td>
								<input type="color" id="beaver-pwa-theme-color" name="<?php echo esc_attr( $name ); ?>[theme_color]" value="<?php echo esc_attr( Beaver_PWA_Settings::get( 'theme_color' ) ); ?>">
								<p class="description"><?php esc_html_e( 'Tints the title bar and the status bar once installed.', 'beaver-pwa' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="beaver-pwa-background-color"><?php esc_html_e( 'Splash background', 'beaver-pwa' ); ?></label></th>
							<td>
								<input type="color" id="beaver-pwa-background-color" name="<?php echo esc_attr( $name ); ?>[background_color]" value="<?php echo esc_attr( Beaver_PWA_Settings::get( 'background_color' ) ); ?>">
								<p class="description"><?php esc_html_e( 'Fills the screen while the app starts. Match it to the page background to avoid a flash.', 'beaver-pwa' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="beaver-pwa-display"><?php esc_html_e( 'Display mode', 'beaver-pwa' ); ?></label></th>
							<td>
								<select id="beaver-pwa-display" name="<?php echo esc_attr( $name ); ?>[display]">
									<?php
									$displays = array(
										'standalone' => __( 'Standalone: no browser chrome', 'beaver-pwa' ),
										'minimal-ui' => __( 'Minimal: a slim navigation bar', 'beaver-pwa' ),
										'fullscreen' => __( 'Full screen: no status bar either', 'beaver-pwa' ),
										'browser'    => __( 'Browser: opens in a normal tab', 'beaver-pwa' ),
									);

									foreach ( $displays as $value => $label ) {
										printf(
											'<option value="%s" %s>%s</option>',
											esc_attr( $value ),
											selected( Beaver_PWA_Settings::get( 'display' ), $value, false ),
											esc_html( $label )
										);
									}
									?>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="beaver-pwa-orientation"><?php esc_html_e( 'Orientation', 'beaver-pwa' ); ?></label></th>
							<td>
								<select id="beaver-pwa-orientation" name="<?php echo esc_attr( $name ); ?>[orientation]">
									<?php
									$orientations = array(
										'any'       => __( 'Follow the device', 'beaver-pwa' ),
										'portrait'  => __( 'Portrait', 'beaver-pwa' ),
										'landscape' => __( 'Landscape', 'beaver-pwa' ),
									);

									foreach ( $orientations as $value => $label ) {
										printf(
											'<option value="%s" %s>%s</option>',
											esc_attr( $value ),
											selected( Beaver_PWA_Settings::get( 'orientation' ), $value, false ),
											esc_html( $label )
										);
									}
									?>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="beaver-pwa-start-path"><?php esc_html_e( 'Start page', 'beaver-pwa' ); ?></label></th>
							<td>
								<span class="beaver-pwa-prefix"><?php echo esc_html( untrailingslashit( home_url() ) ); ?></span>
								<input type="text" class="regular-text code" id="beaver-pwa-start-path" name="<?php echo esc_attr( $name ); ?>[start_path]" value="<?php echo esc_attr( Beaver_PWA_Settings::get( 'start_path' ) ); ?>">
								<p class="description">
									<label>
										<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[start_tracking]" value="1" <?php checked( Beaver_PWA_Settings::get( 'start_tracking' ) ); ?>>
										<?php esc_html_e( 'Add ?source=pwa so analytics can separate app visits from browser visits', 'beaver-pwa' ); ?>
									</label>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="beaver-pwa-categories"><?php esc_html_e( 'Categories', 'beaver-pwa' ); ?></label></th>
							<td>
								<input type="text" class="regular-text" id="beaver-pwa-categories" name="<?php echo esc_attr( $name ); ?>[categories]" value="<?php echo esc_attr( Beaver_PWA_Settings::get( 'categories' ) ); ?>" placeholder="business, news">
								<p class="description"><?php esc_html_e( 'Optional, comma separated. Some app catalogues use these.', 'beaver-pwa' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<div class="beaver-pwa-panel">
					<h2><?php esc_html_e( 'Shortcuts', 'beaver-pwa' ); ?></h2>
					<p class="beaver-pwa-hint"><?php esc_html_e( 'Up to four pages, reachable by pressing and holding the installed icon.', 'beaver-pwa' ); ?></p>
					<table class="form-table" role="presentation">
						<?php
						$shortcuts = (array) Beaver_PWA_Settings::get( 'shortcuts' );

						for ( $index = 0; $index < 4; $index++ ) {
							$row     = isset( $shortcuts[ $index ] ) ? $shortcuts[ $index ] : array();
							$page_id = isset( $row['page_id'] ) ? (int) $row['page_id'] : 0;
							$label   = isset( $row['label'] ) ? $row['label'] : '';
							?>
							<tr>
								<th scope="row">
									<?php
									printf(
										/* translators: %d: shortcut position. */
										esc_html__( 'Shortcut %d', 'beaver-pwa' ),
										(int) $index + 1
									);
									?>
								</th>
								<td>
									<?php
									wp_dropdown_pages(
										array(
											'name'              => $name . '[shortcuts][' . $index . '][page_id]',
											'id'                => 'beaver-pwa-shortcut-' . $index,
											'selected'          => $page_id,
											'show_option_none'  => __( 'None', 'beaver-pwa' ),
											'option_none_value' => 0,
										)
									);
									?>
									<input type="text" class="regular-text" name="<?php echo esc_attr( $name ); ?>[shortcuts][<?php echo (int) $index; ?>][label]" value="<?php echo esc_attr( $label ); ?>" placeholder="<?php esc_attr_e( 'Label (optional)', 'beaver-pwa' ); ?>" maxlength="40">
								</td>
							</tr>
							<?php
						}
						?>
					</table>
				</div>

				<div class="beaver-pwa-panel">
					<h2><?php esc_html_e( 'Offline and caching', 'beaver-pwa' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Offline fallback', 'beaver-pwa' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[offline_enabled]" value="1" <?php checked( Beaver_PWA_Settings::get( 'offline_enabled' ) ); ?>>
									<?php esc_html_e( 'Show a page of your own instead of the browser error', 'beaver-pwa' ); ?>
								</label>
								<p>
									<?php
									wp_dropdown_pages(
										array(
											'name'              => $name . '[offline_page_id]',
											'id'                => 'beaver-pwa-offline-page',
											'selected'          => (int) Beaver_PWA_Settings::get( 'offline_page_id' ),
											'show_option_none'  => __( 'Built-in offline page', 'beaver-pwa' ),
											'option_none_value' => 0,
										)
									);
									?>
								</p>
								<p class="description"><?php esc_html_e( 'The built-in page carries its own styles, so it renders with nothing else cached. A page of your own needs its theme assets in the cache first.', 'beaver-pwa' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'What to keep', 'beaver-pwa' ); ?></th>
							<td>
								<fieldset>
									<label>
										<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[cache_pages]" value="1" <?php checked( Beaver_PWA_Settings::get( 'cache_pages' ) ); ?>>
										<?php esc_html_e( 'Pages, as an offline copy only', 'beaver-pwa' ); ?>
									</label>
									<br>
									<label>
										<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[cache_assets]" value="1" <?php checked( Beaver_PWA_Settings::get( 'cache_assets' ) ); ?>>
										<?php esc_html_e( 'Stylesheets, scripts and fonts', 'beaver-pwa' ); ?>
									</label>
									<br>
									<label>
										<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[cache_images]" value="1" <?php checked( Beaver_PWA_Settings::get( 'cache_images' ) ); ?>>
										<?php esc_html_e( 'Images', 'beaver-pwa' ); ?>
									</label>
								</fieldset>
								<p class="description"><?php esc_html_e( 'Pages are always fetched from the network first, so nobody reads a stale article. Admin screens, carts, checkouts and anything a logged-in visitor sees are never stored.', 'beaver-pwa' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Cache limits', 'beaver-pwa' ); ?></th>
							<td>
								<label>
									<?php esc_html_e( 'Pages', 'beaver-pwa' ); ?>
									<input type="number" class="small-text" min="5" max="300" name="<?php echo esc_attr( $name ); ?>[page_cache_limit]" value="<?php echo esc_attr( Beaver_PWA_Settings::get( 'page_cache_limit' ) ); ?>">
								</label>
								<label class="beaver-pwa-inline">
									<?php esc_html_e( 'Images', 'beaver-pwa' ); ?>
									<input type="number" class="small-text" min="5" max="500" name="<?php echo esc_attr( $name ); ?>[image_cache_limit]" value="<?php echo esc_attr( Beaver_PWA_Settings::get( 'image_cache_limit' ) ); ?>">
								</label>
								<p class="description"><?php esc_html_e( 'The oldest entries are dropped once a limit is reached, so a large library cannot fill a phone.', 'beaver-pwa' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="beaver-pwa-exclusions"><?php esc_html_e( 'Never cache', 'beaver-pwa' ); ?></label></th>
							<td>
								<textarea class="large-text code" rows="4" id="beaver-pwa-exclusions" name="<?php echo esc_attr( $name ); ?>[exclusions]" placeholder="/members&#10;/booking&#10;action=download"><?php echo esc_textarea( Beaver_PWA_Settings::get( 'exclusions' ) ); ?></textarea>
								<p class="description"><?php esc_html_e( 'One fragment per line, matched against the path and query string. Admin, login, REST, feeds and WooCommerce pages are already excluded.', 'beaver-pwa' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Updates', 'beaver-pwa' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[update_toast]" value="1" <?php checked( Beaver_PWA_Settings::get( 'update_toast' ) ); ?>>
									<?php esc_html_e( 'Offer a refresh when a new version is ready', 'beaver-pwa' ); ?>
								</label>
								<p class="description"><?php esc_html_e( 'Off by default: the new version is applied silently on the next visit instead.', 'beaver-pwa' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Logged-in visitors', 'beaver-pwa' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[register_logged_in]" value="1" <?php checked( Beaver_PWA_Settings::get( 'register_logged_in' ) ); ?>>
									<?php esc_html_e( 'Register the service worker for signed-in visitors too', 'beaver-pwa' ); ?>
								</label>
								<p class="description"><?php esc_html_e( 'Their pages are still never stored: WordPress marks them private, and the worker skips anything private.', 'beaver-pwa' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<div class="beaver-pwa-panel">
					<h2><?php esc_html_e( 'Install prompt', 'beaver-pwa' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Prompt', 'beaver-pwa' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[prompt_enabled]" value="1" <?php checked( Beaver_PWA_Settings::get( 'prompt_enabled' ) ); ?>>
									<?php esc_html_e( 'Show an install card to visitors who can install', 'beaver-pwa' ); ?>
								</label>
								<p class="description">
									<?php
									printf(
										/* translators: %s: shortcode. */
										esc_html__( 'The card only appears once the browser confirms the site is installable. Use %s to place a button in your content instead.', 'beaver-pwa' ),
										'<code>[beaver_pwa_install]</code>'
									);
									?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="beaver-pwa-prompt-position"><?php esc_html_e( 'Position', 'beaver-pwa' ); ?></label></th>
							<td>
								<select id="beaver-pwa-prompt-position" name="<?php echo esc_attr( $name ); ?>[prompt_position]">
									<?php
									$positions = array(
										'bottom-right' => __( 'Bottom right', 'beaver-pwa' ),
										'bottom-left'  => __( 'Bottom left', 'beaver-pwa' ),
										'bottom-full'  => __( 'Bottom, full width', 'beaver-pwa' ),
										'top-full'     => __( 'Top, full width', 'beaver-pwa' ),
									);

									foreach ( $positions as $value => $label ) {
										printf(
											'<option value="%s" %s>%s</option>',
											esc_attr( $value ),
											selected( Beaver_PWA_Settings::get( 'prompt_position' ), $value, false ),
											esc_html( $label )
										);
									}
									?>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Timing', 'beaver-pwa' ); ?></th>
							<td>
								<label>
									<?php esc_html_e( 'Wait', 'beaver-pwa' ); ?>
									<input type="number" class="small-text" min="0" max="120" name="<?php echo esc_attr( $name ); ?>[prompt_delay]" value="<?php echo esc_attr( Beaver_PWA_Settings::get( 'prompt_delay' ) ); ?>">
									<?php esc_html_e( 'seconds', 'beaver-pwa' ); ?>
								</label>
								<label class="beaver-pwa-inline">
									<?php esc_html_e( 'Stay dismissed for', 'beaver-pwa' ); ?>
									<input type="number" class="small-text" min="0" max="365" name="<?php echo esc_attr( $name ); ?>[prompt_dismiss_days]" value="<?php echo esc_attr( Beaver_PWA_Settings::get( 'prompt_dismiss_days' ) ); ?>">
									<?php esc_html_e( 'days', 'beaver-pwa' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="beaver-pwa-prompt-text"><?php esc_html_e( 'Message', 'beaver-pwa' ); ?></label></th>
							<td>
								<input type="text" class="large-text" id="beaver-pwa-prompt-text" name="<?php echo esc_attr( $name ); ?>[prompt_text]" value="<?php echo esc_attr( Beaver_PWA_Settings::get( 'prompt_text' ) ); ?>" placeholder="<?php echo esc_attr( Beaver_PWA_Frontend::prompt_text() ); ?>" maxlength="140">
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="beaver-pwa-prompt-button"><?php esc_html_e( 'Button label', 'beaver-pwa' ); ?></label></th>
							<td>
								<input type="text" class="regular-text" id="beaver-pwa-prompt-button" name="<?php echo esc_attr( $name ); ?>[prompt_button]" value="<?php echo esc_attr( Beaver_PWA_Settings::get( 'prompt_button' ) ); ?>" placeholder="<?php esc_attr_e( 'Install', 'beaver-pwa' ); ?>" maxlength="40">
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'iPhone and iPad', 'beaver-pwa' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[ios_hint]" value="1" <?php checked( Beaver_PWA_Settings::get( 'ios_hint' ) ); ?>>
									<?php esc_html_e( 'Show the Add to Home Screen instructions in Safari', 'beaver-pwa' ); ?>
								</label>
								<p class="description"><?php esc_html_e( 'Safari has no install button, so the only route is the share menu. The hint explains it in place of the button.', 'beaver-pwa' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<div class="beaver-pwa-panel">
					<h2><?php esc_html_e( 'Advanced', 'beaver-pwa' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="beaver-pwa-sw-route"><?php esc_html_e( 'Endpoint style', 'beaver-pwa' ); ?></label></th>
							<td>
								<select id="beaver-pwa-sw-route" name="<?php echo esc_attr( $name ); ?>[sw_route]">
									<?php
									$routes = array(
										'auto'   => __( 'Automatic', 'beaver-pwa' ),
										'pretty' => __( 'Clean URLs', 'beaver-pwa' ),
										'query'  => __( 'Query strings', 'beaver-pwa' ),
									);

									foreach ( $routes as $value => $label ) {
										printf(
											'<option value="%s" %s>%s</option>',
											esc_attr( $value ),
											selected( Beaver_PWA_Settings::get( 'sw_route' ), $value, false ),
											esc_html( $label )
										);
									}
									?>
								</select>
								<p class="description">
									<?php
									$urls = Beaver_PWA_Routes::both_urls( 'sw' );

									printf(
										/* translators: 1: clean URL, 2: query string URL. */
										esc_html__( 'Clean: %1$s. Query: %2$s. Switch to query strings if a server rule swallows the clean URL.', 'beaver-pwa' ),
										'<code>' . esc_html( $urls['pretty'] ) . '</code>',
										'<code>' . esc_html( $urls['query'] ) . '</code>'
									);
									?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Meta tags', 'beaver-pwa' ); ?></th>
							<td>
								<fieldset>
									<label>
										<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[theme_color_meta]" value="1" <?php checked( Beaver_PWA_Settings::get( 'theme_color_meta' ) ); ?>>
										<?php esc_html_e( 'Print the theme colour meta tag', 'beaver-pwa' ); ?>
									</label>
									<br>
									<label>
										<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[apple_meta]" value="1" <?php checked( Beaver_PWA_Settings::get( 'apple_meta' ) ); ?>>
										<?php esc_html_e( 'Print the Apple web app meta tags', 'beaver-pwa' ); ?>
									</label>
								</fieldset>
								<p class="description"><?php esc_html_e( 'Turn one off only if your theme already prints it.', 'beaver-pwa' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<?php submit_button(); ?>
			</form>

			<?php self::render_credit(); ?>
		</div>
		<?php
	}
}
