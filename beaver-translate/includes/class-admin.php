<?php
/**
 * Admin screens: settings and the review queue.
 *
 * @package BeaverTranslate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders wp-admin for this plugin.
 *
 * @since 1.0.0
 */
class Beaver_Translate_Admin {

	const MENU_SLUG     = 'beaver-translate';
	const SETTINGS_SLUG = 'beaver-translate-settings';
	const QUEUE_SLUG    = 'beaver-translate-queue';
	const CAPABILITY    = 'manage_options';
	const NONCE_QUEUE   = 'beaver_translate_queue_action';

	/**
	 * Registers the admin hooks.
	 *
	 * @since 1.0.0
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_notices' ) );

		add_action( 'admin_post_beaver_translate_reject', array( __CLASS__, 'handle_reject' ) );
		add_action( 'wp_ajax_beaver_translate_dashboard_scan', array( __CLASS__, 'ajax_dashboard_scan' ) );
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
			__( 'Beaver Translate', 'beaver-translate' ),
			__( 'Translate', 'beaver-translate' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( __CLASS__, 'render_dashboard' ),
			'dashicons-translation',
			83
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Dashboard', 'beaver-translate' ),
			__( 'Dashboard', 'beaver-translate' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( __CLASS__, 'render_dashboard' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Settings', 'beaver-translate' ),
			__( 'Settings', 'beaver-translate' ),
			self::CAPABILITY,
			self::SETTINGS_SLUG,
			array( __CLASS__, 'render_settings' )
		);

		$count = Beaver_Translate_Links::count_awaiting_review();

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Review queue', 'beaver-translate' ),
			$count > 0
				? sprintf( '%s <span class="awaiting-mod">%d</span>', esc_html__( 'Review queue', 'beaver-translate' ), $count )
				: __( 'Review queue', 'beaver-translate' ),
			self::CAPABILITY,
			self::QUEUE_SLUG,
			array( __CLASS__, 'render_queue' )
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

		if ( ! $is_plugin_screen && ! $is_editor ) {
			return;
		}

		wp_enqueue_style( 'beaver-translate-admin', BEAVER_TRANSLATE_URL . 'admin/css/admin.css', array(), BEAVER_TRANSLATE_VERSION );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading which screen this is, not acting on input.
		$current_page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		if ( $is_editor ) {
			wp_enqueue_script( 'beaver-translate-admin', BEAVER_TRANSLATE_URL . 'admin/js/admin.js', array(), BEAVER_TRANSLATE_VERSION, true );

			wp_localize_script(
				'beaver-translate-admin',
				'beaverTranslate',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( Beaver_Translate_Metabox::NONCE_ACTION ),
					'i18n'    => array(
						'working'        => __( 'Generating…', 'beaver-translate' ),
						'failed'         => __( 'Something went wrong.', 'beaver-translate' ),
						'confirmApprove' => __( 'Publish this translation? It will become visible to visitors immediately.', 'beaver-translate' ),
					),
				)
			);
		} elseif ( self::SETTINGS_SLUG === $current_page ) {
			wp_enqueue_script( 'beaver-translate-settings', BEAVER_TRANSLATE_URL . 'admin/js/settings.js', array(), BEAVER_TRANSLATE_VERSION, true );
		} elseif ( self::MENU_SLUG === $current_page ) {
			wp_enqueue_script( 'beaver-translate-dashboard', BEAVER_TRANSLATE_URL . 'admin/js/dashboard.js', array(), BEAVER_TRANSLATE_VERSION, true );

			wp_localize_script(
				'beaver-translate-dashboard',
				'beaverTranslateDashboard',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'scanNonce'     => wp_create_nonce( 'beaver_translate_dashboard_scan' ),
					'generateNonce' => wp_create_nonce( Beaver_Translate_Metabox::NONCE_ACTION ),
					'i18n'          => array(
						'scanning' => __( 'Finding untranslated content…', 'beaver-translate' ),
						'working'  => __( 'Translating…', 'beaver-translate' ),
						'complete' => __( 'Done.', 'beaver-translate' ),
						'none'     => __( 'Nothing left to translate.', 'beaver-translate' ),
						'failed'   => __( 'Something went wrong.', 'beaver-translate' ),
					),
				)
			);
		}
	}

	/**
	 * Warns when no API key is configured yet.
	 *
	 * @since 1.0.0
	 */
	public static function render_notices() {
		if ( ! current_user_can( self::CAPABILITY ) || '' !== Beaver_Translate_Settings::api_key() ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( $screen && false === strpos( (string) $screen->id, self::MENU_SLUG ) && 'plugins' !== $screen->id ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
			esc_html__( 'Beaver Translate:', 'beaver-translate' ),
			esc_html__( 'no AI provider is configured yet, so nothing can be translated.', 'beaver-translate' ),
			esc_url( admin_url( 'admin.php?page=' . self::SETTINGS_SLUG ) ),
			esc_html__( 'Add an API key', 'beaver-translate' )
		);
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
			'beaver_translate_settings_group',
			Beaver_Translate_Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ),
				'default'           => Beaver_Translate_Settings::defaults(),
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
			return Beaver_Translate_Settings::all();
		}

		$before_codes   = wp_list_pluck( (array) Beaver_Translate_Settings::get( 'languages', array() ), 'code' );
		$before_default = Beaver_Translate_Settings::get( 'default_language', '' );

		$clean = Beaver_Translate_Settings::sanitize( $input );

		Beaver_Translate_Settings::flush();

		/*
		 * Beaver_Translate_Router builds its language-prefixed rewrite
		 * rules directly from this language list — one prefix per
		 * non-default language. Nothing else ever rebuilds WordPress's
		 * cached rules when that list changes, so a language added here
		 * would otherwise sit completely unreachable at its own URL (every
		 * post in it 404ing) until something unrelated happened to trigger
		 * a flush — deactivating the plugin, or resaving Permalinks.
		 *
		 * The flush is deliberately scoped to only this case rather than
		 * running on every settings save. flush_rewrite_rules() is
		 * expensive, and this exact codebase already has a documented
		 * incident (see INTERNAL.md) from a plugin flushing when it had no
		 * need to — worth staying deliberate about even here, where there
		 * usually is a need to.
		 */
		$after_codes   = wp_list_pluck( $clean['languages'], 'code' );
		$after_default = $clean['default_language'];

		sort( $before_codes );
		sort( $after_codes );

		if ( $before_codes !== $after_codes || $before_default !== $after_default ) {
			flush_rewrite_rules();
		}

		return $clean;
	}

	/*
	 * -----------------------------------------------------------------------
	 * Dashboard
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Renders the dashboard: per-language coverage and a bulk-generate action.
	 *
	 * @since 1.2.0
	 */
	public static function render_dashboard() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to view this page.', 'beaver-translate' ) );
		}

		$post_types = (array) Beaver_Translate_Settings::get( 'translatable_post_types', array() );
		$targets    = Beaver_Translate_Languages::targets();
		$pending    = Beaver_Translate_Links::count_awaiting_review();
		?>
		<div class="wrap beaver-translate">
			<h1><?php esc_html_e( 'Beaver Translate', 'beaver-translate' ); ?></h1>

			<?php if ( '' === Beaver_Translate_Settings::api_key() ) : ?>
				<div class="notice notice-warning inline">
					<p>
						<?php esc_html_e( 'No AI provider is configured yet.', 'beaver-translate' ); ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SETTINGS_SLUG ) ); ?>"><?php esc_html_e( 'Add an API key', 'beaver-translate' ); ?></a>
					</p>
				</div>
			<?php elseif ( empty( $targets ) ) : ?>
				<div class="notice notice-warning inline">
					<p>
						<?php esc_html_e( 'No target languages are configured yet — only the default language exists.', 'beaver-translate' ); ?>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SETTINGS_SLUG ) ); ?>"><?php esc_html_e( 'Add a language', 'beaver-translate' ); ?></a>
					</p>
				</div>
			<?php else : ?>
				<div class="beaver-translate-panel">
					<h2><?php esc_html_e( 'Translation coverage', 'beaver-translate' ); ?></h2>
					<table class="widefat striped beaver-translate-coverage">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Language', 'beaver-translate' ); ?></th>
								<th><?php esc_html_e( 'Published', 'beaver-translate' ); ?></th>
								<th><?php esc_html_e( 'Needs review', 'beaver-translate' ); ?></th>
								<th><?php esc_html_e( 'Failed', 'beaver-translate' ); ?></th>
								<th><?php esc_html_e( 'Not started', 'beaver-translate' ); ?></th>
								<th></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $targets as $language ) : ?>
								<?php
								$stats       = Beaver_Translate_Links::coverage( $language['code'], $post_types );
								$has_pending_work = ( $stats['not_started'] + $stats['failed'] ) > 0;
								?>
								<tr data-beaver-translate-language="<?php echo esc_attr( $language['code'] ); ?>">
									<td><?php echo esc_html( Beaver_Translate_Languages::label( $language['code'] ) ); ?></td>
									<td>
										<?php
										printf(
											/* translators: 1: published count, 2: total eligible posts. */
											esc_html__( '%1$s of %2$s', 'beaver-translate' ),
											esc_html( number_format_i18n( $stats['published'] ) ),
											esc_html( number_format_i18n( $stats['total'] ) )
										);
										?>
									</td>
									<td data-beaver-translate-count="needs_review"><?php echo esc_html( number_format_i18n( $stats['needs_review'] ) ); ?></td>
									<td data-beaver-translate-count="failed"><?php echo esc_html( number_format_i18n( $stats['failed'] ) ); ?></td>
									<td data-beaver-translate-count="not_started"><?php echo esc_html( number_format_i18n( $stats['not_started'] ) ); ?></td>
									<td data-beaver-translate-dashboard-action>
										<?php if ( $has_pending_work ) : ?>
											<button type="button" class="button button-small" data-beaver-translate-bulk-generate><?php esc_html_e( 'Generate remaining', 'beaver-translate' ); ?></button>
										<?php else : ?>
											<em><?php esc_html_e( 'All caught up', 'beaver-translate' ); ?></em>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>

					<div class="beaver-translate-progress" id="beaver-translate-dashboard-progress" hidden>
						<div class="beaver-translate-progress__bar"><span id="beaver-translate-dashboard-progress-fill"></span></div>
						<p class="beaver-translate-progress__text" id="beaver-translate-dashboard-progress-text"></p>
						<button type="button" class="button" id="beaver-translate-dashboard-stop"><?php esc_html_e( 'Stop', 'beaver-translate' ); ?></button>
					</div>
					<div class="beaver-translate-inline-notice" id="beaver-translate-dashboard-notice" hidden></div>
				</div>
			<?php endif; ?>

			<div class="beaver-translate-panel">
				<h2><?php esc_html_e( 'Review queue', 'beaver-translate' ); ?></h2>
				<p>
					<?php
					printf(
						/* translators: %d: number of translations awaiting review. */
						esc_html( _n( '%d translation is waiting for approval.', '%d translations are waiting for approval.', $pending, 'beaver-translate' ) ),
						(int) $pending
					);
					?>
					<?php if ( $pending > 0 ) : ?>
						<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::QUEUE_SLUG ) ); ?>"><?php esc_html_e( 'Review now', 'beaver-translate' ); ?></a>
					<?php endif; ?>
				</p>
			</div>

			<?php self::render_credit(); ?>
		</div>
		<?php
	}

	/**
	 * Finds the untranslated content a "Generate remaining" click should
	 * work through.
	 *
	 * @since 1.2.0
	 */
	public static function ajax_dashboard_scan() {
		check_ajax_referer( 'beaver_translate_dashboard_scan', 'nonce' );

		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'beaver-translate' ) ), 403 );
		}

		$language = isset( $_POST['language'] ) ? Beaver_Translate_Languages::sanitize_code( wp_unslash( $_POST['language'] ) ) : '';

		if ( ! Beaver_Translate_Languages::is_valid( $language ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown language.', 'beaver-translate' ) ) );
		}

		$post_types = (array) Beaver_Translate_Settings::get( 'translatable_post_types', array() );
		$ids        = Beaver_Translate_Links::queue_ids( $language, $post_types );

		wp_send_json_success(
			array(
				'ids'   => $ids,
				'total' => count( $ids ),
			)
		);
	}

	/*
	 * -----------------------------------------------------------------------
	 * Review queue
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Discards a draft translation, or a rejected regeneration attempt,
	 * instead of approving it.
	 *
	 * Delegates to Beaver_Translate_Translator::reject(), which is the one
	 * place that knows which post is actually safe to trash: a first-time
	 * draft that never went live, or — critically — only the pending post
	 * when this is a regeneration of a translation that is already
	 * published, never the live post itself.
	 *
	 * @since 1.0.0
	 */
	public static function handle_reject() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'beaver-translate' ) );
		}

		check_admin_referer( self::NONCE_QUEUE );

		$link_id = isset( $_POST['link_id'] ) ? absint( $_POST['link_id'] ) : 0;

		global $wpdb;

		$table = Beaver_Translate_Links::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $link_id ) ); // phpcs:ignore WordPress.DB

		if ( $row ) {
			Beaver_Translate_Translator::reject( $row );
		}

		wp_safe_redirect( add_query_arg( array( 'page' => self::QUEUE_SLUG, 'beaver_translate_rejected' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Renders the review queue screen.
	 *
	 * @since 1.0.0
	 */
	public static function render_queue() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to view this page.', 'beaver-translate' ) );
		}

		$rows = Beaver_Translate_Links::awaiting_review();
		?>
		<div class="wrap beaver-translate">
			<h1><?php esc_html_e( 'Review queue', 'beaver-translate' ); ?></h1>

			<?php if ( isset( $_GET['beaver_translate_rejected'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Draft rejected and moved to the trash.', 'beaver-translate' ); ?></p></div>
			<?php endif; ?>

			<div class="beaver-translate-panel">
				<?php if ( empty( $rows ) ) : ?>
					<p><?php esc_html_e( 'Nothing is waiting for review right now.', 'beaver-translate' ); ?></p>
				<?php else : ?>
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Original', 'beaver-translate' ); ?></th>
								<th><?php esc_html_e( 'Language', 'beaver-translate' ); ?></th>
								<th><?php esc_html_e( 'Draft', 'beaver-translate' ); ?></th>
								<th><?php esc_html_e( 'Generated with', 'beaver-translate' ); ?></th>
								<th></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $rows as $row ) : ?>
								<?php
								// A pending regeneration of an already-live translation has
								// to be reviewed at the *pending* post — the live one this
								// row also references was never touched by it.
								$is_regeneration = $row->pending_post_id > 0;
								$reviewing_id    = $is_regeneration ? (int) $row->pending_post_id : (int) $row->translated_post_id;

								$source_link = get_edit_post_link( $row->source_post_id );
								$draft_link  = get_edit_post_link( $reviewing_id );
								$live_link   = $is_regeneration ? get_permalink( $row->translated_post_id ) : '';
								?>
								<tr>
									<td>
										<?php if ( $source_link ) : ?>
											<a href="<?php echo esc_url( $source_link ); ?>"><?php echo esc_html( get_the_title( $row->source_post_id ) ); ?></a>
										<?php else : ?>
											<?php echo esc_html( get_the_title( $row->source_post_id ) ); ?>
										<?php endif; ?>
									</td>
									<td><?php echo esc_html( Beaver_Translate_Languages::label( $row->language_code ) ); ?></td>
									<td>
										<?php if ( $draft_link ) : ?>
											<a href="<?php echo esc_url( $draft_link ); ?>"><?php echo $is_regeneration ? esc_html__( 'Open pending changes', 'beaver-translate' ) : esc_html__( 'Open draft', 'beaver-translate' ); ?></a>
										<?php endif; ?>
										<?php if ( $live_link ) : ?>
											<br /><a href="<?php echo esc_url( $live_link ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View current live version', 'beaver-translate' ); ?></a>
										<?php endif; ?>
									</td>
									<td><code><?php echo esc_html( $row->provider . ' / ' . $row->model ); ?></code></td>
									<td>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;" onsubmit="return confirm('<?php echo esc_js( __( 'Reject this translation? The live version, if any, is not affected — only the pending draft is discarded.', 'beaver-translate' ) ); ?>');">
											<input type="hidden" name="action" value="beaver_translate_reject" />
											<input type="hidden" name="link_id" value="<?php echo (int) $row->id; ?>" />
											<?php wp_nonce_field( self::NONCE_QUEUE ); ?>
											<button type="submit" class="button-link beaver-translate-danger"><?php esc_html_e( 'Reject', 'beaver-translate' ); ?></button>
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
			wp_die( esc_html__( 'You are not allowed to view this page.', 'beaver-translate' ) );
		}

		$settings = Beaver_Translate_Settings::all();
		$option   = Beaver_Translate_Settings::OPTION;
		$key_locked = Beaver_Translate_Settings::key_is_constant();
		?>
		<div class="wrap beaver-translate">
			<h1><?php esc_html_e( 'Beaver Translate', 'beaver-translate' ); ?></h1>

			<form method="post" action="options.php">
				<?php settings_fields( 'beaver_translate_settings_group' ); ?>

				<h2 class="title"><?php esc_html_e( 'Languages', 'beaver-translate' ); ?></h2>
				<p class="description"><?php esc_html_e( 'The first language is the site\'s original content language — the one everything else is translated from. Add every language you want available as a translation target.', 'beaver-translate' ); ?></p>

				<table class="widefat beaver-translate-languages" id="beaver-translate-languages">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Default', 'beaver-translate' ); ?></th>
							<th><?php esc_html_e( 'Code', 'beaver-translate' ); ?></th>
							<th><?php esc_html_e( 'Name', 'beaver-translate' ); ?></th>
							<th><?php esc_html_e( 'Native name', 'beaver-translate' ); ?></th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $settings['languages'] as $i => $language ) : ?>
							<?php self::render_language_row( $option, $i, $language, $settings['default_language'] ); ?>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p><button type="button" class="button" id="beaver-translate-add-language"><?php esc_html_e( 'Add language', 'beaver-translate' ); ?></button></p>
				<template id="beaver-translate-language-template">
					<?php self::render_language_row( $option, '__INDEX__', array( 'code' => '', 'name' => '', 'native' => '' ), '' ); ?>
				</template>

				<h2 class="title"><?php esc_html_e( 'AI provider', 'beaver-translate' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="beaver-translate-provider"><?php esc_html_e( 'Provider', 'beaver-translate' ); ?></label></th>
						<td>
							<select id="beaver-translate-provider" name="<?php echo esc_attr( $option ); ?>[provider]">
								<?php foreach ( Beaver_Translate_Settings::providers() as $key => $provider ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $settings['provider'], $key ); ?>><?php echo esc_html( $provider['label'] ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="beaver-translate-key"><?php esc_html_e( 'API key', 'beaver-translate' ); ?></label></th>
						<td>
							<?php if ( $key_locked ) : ?>
								<input type="text" class="regular-text" value="<?php esc_attr_e( 'Set via BEAVER_TRANSLATE_API_KEY in wp-config.php', 'beaver-translate' ); ?>" disabled />
							<?php elseif ( '' !== $settings['api_key'] ) : ?>
								<!--
									A key is already saved. Shown masked and
									locked rather than as an editable field a
									blank submission could silently wipe —
									nothing about the stored key changes
									unless "Change" is clicked, which is what
									api_key_unchanged exists to signal to
									Beaver_Translate_Settings::sanitize().
								-->
								<span data-beaver-translate-key-saved>
									<input type="text" class="regular-text" value="<?php echo esc_attr( self::mask_key( $settings['api_key'] ) ); ?>" disabled />
									<button type="button" class="button" data-beaver-translate-key-change><?php esc_html_e( 'Change', 'beaver-translate' ); ?></button>
									<input type="hidden" name="<?php echo esc_attr( $option ); ?>[api_key_unchanged]" value="1" data-beaver-translate-key-unchanged-field />
								</span>
								<span data-beaver-translate-key-edit hidden>
									<input type="password" class="regular-text" autocomplete="off" name="<?php echo esc_attr( $option ); ?>[api_key]" placeholder="<?php esc_attr_e( 'Paste the new key', 'beaver-translate' ); ?>" />
								</span>
							<?php else : ?>
								<input type="password" id="beaver-translate-key" class="regular-text" autocomplete="off" name="<?php echo esc_attr( $option ); ?>[api_key]" value="" />
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="beaver-translate-model"><?php esc_html_e( 'Model', 'beaver-translate' ); ?></label></th>
						<td>
							<input type="text" id="beaver-translate-model" class="regular-text" name="<?php echo esc_attr( $option ); ?>[model]" value="<?php echo esc_attr( $settings['model'] ); ?>" placeholder="<?php echo esc_attr( Beaver_Translate_Settings::provider_config( $settings )['model'] ); ?>" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="beaver-translate-endpoint"><?php esc_html_e( 'Custom endpoint', 'beaver-translate' ); ?></label></th>
						<td>
							<input type="url" id="beaver-translate-endpoint" class="regular-text" name="<?php echo esc_attr( $option ); ?>[custom_endpoint]" value="<?php echo esc_attr( $settings['custom_endpoint'] ); ?>" placeholder="https://…/v1/chat/completions" />
							<p class="description"><?php esc_html_e( 'Only used when the provider above is set to Custom.', 'beaver-translate' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="beaver-translate-timeout"><?php esc_html_e( 'Timeout (seconds)', 'beaver-translate' ); ?></label></th>
						<td><input type="number" id="beaver-translate-timeout" class="small-text" min="10" max="300" name="<?php echo esc_attr( $option ); ?>[timeout]" value="<?php echo esc_attr( $settings['timeout'] ); ?>" /></td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'What gets translated', 'beaver-translate' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Post types', 'beaver-translate' ); ?></th>
						<td>
							<?php foreach ( Beaver_Translate_Languages::translatable_post_types_available() as $post_type ) : ?>
								<?php $type_object = get_post_type_object( $post_type ); ?>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( $option ); ?>[translatable_post_types][]" value="<?php echo esc_attr( $post_type ); ?>" <?php checked( in_array( $post_type, (array) $settings['translatable_post_types'], true ) ); ?> />
									<?php echo esc_html( $type_object ? $type_object->labels->name : $post_type ); ?>
								</label><br />
							<?php endforeach; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Custom fields', 'beaver-translate' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $option ); ?>[translate_custom_fields]" value="1" <?php checked( $settings['translate_custom_fields'], 1 ); ?> />
								<?php esc_html_e( 'Also translate the text inside custom fields — an itinerary day, an FAQ answer, an included/excluded item, and similar', 'beaver-translate' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Off by default. Every custom field is always copied across so nothing renders blank; this additionally sends whichever of those actually read as prose through translation too, in one extra request per post. Dropdown values, IDs and image references are left alone either way.', 'beaver-translate' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Glossary', 'beaver-translate' ); ?></h2>
				<p class="description" style="margin-top:-8px;">
					<?php esc_html_e( 'Never translated, never transliterated, wherever they appear — no matter how fluent a translation of them would sound.', 'beaver-translate' ); ?>
				</p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Protect names from', 'beaver-translate' ); ?></th>
						<td>
							<fieldset>
								<?php foreach ( Beaver_Translate_Languages::translatable_post_types_available() as $post_type ) : ?>
									<?php $type_object = get_post_type_object( $post_type ); ?>
									<label>
										<input type="checkbox" name="<?php echo esc_attr( $option ); ?>[glossary_post_types][]" value="<?php echo esc_attr( $post_type ); ?>" <?php checked( in_array( $post_type, (array) $settings['glossary_post_types'], true ) ); ?> />
										<?php echo esc_html( $type_object ? $type_object->labels->name : $post_type ); ?>
									</label><br />
								<?php endforeach; ?>
							</fieldset>
							<p class="description"><?php esc_html_e( 'Every published title in a checked type — every destination, every accommodation, however many exist — is protected as a whole category. No need to tick each one by name, and new ones are covered automatically as they\'re added.', 'beaver-translate' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="beaver-translate-glossary"><?php esc_html_e( 'Anything else', 'beaver-translate' ); ?></label></th>
						<td>
							<textarea id="beaver-translate-glossary" class="large-text code" rows="4" name="<?php echo esc_attr( $option ); ?>[glossary]" placeholder="Serengeti&#10;Ngorongoro&#10;Maasai&#10;Big Five"><?php echo esc_textarea( $settings['glossary'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One per line — place names, cultural terms, brand words, anything not already covered by a post type above.', 'beaver-translate' ); ?></p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Language switcher', 'beaver-translate' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Automatic placement', 'beaver-translate' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $option ); ?>[auto_switcher_enabled]" value="1" <?php checked( $settings['auto_switcher_enabled'], 1 ); ?> />
								<?php esc_html_e( 'Show a floating switcher automatically, on every translated page', 'beaver-translate' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Leave this off if the switcher is already placed another way — the [beaver_translate_switcher] shortcode dropped into content or an Elementor template, or wired into a theme\'s own hook. Turning this on as well would show it twice.', 'beaver-translate' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="beaver-translate-switcher-position"><?php esc_html_e( 'Position', 'beaver-translate' ); ?></label></th>
						<td>
							<select id="beaver-translate-switcher-position" name="<?php echo esc_attr( $option ); ?>[auto_switcher_position]">
								<option value="bottom-right" <?php selected( $settings['auto_switcher_position'], 'bottom-right' ); ?>><?php esc_html_e( 'Bottom right', 'beaver-translate' ); ?></option>
								<option value="bottom-left" <?php selected( $settings['auto_switcher_position'], 'bottom-left' ); ?>><?php esc_html_e( 'Bottom left', 'beaver-translate' ); ?></option>
								<option value="top-right" <?php selected( $settings['auto_switcher_position'], 'top-right' ); ?>><?php esc_html_e( 'Top right', 'beaver-translate' ); ?></option>
								<option value="top-left" <?php selected( $settings['auto_switcher_position'], 'top-left' ); ?>><?php esc_html_e( 'Top left', 'beaver-translate' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Pick a corner your theme is not already using for a chat widget, WhatsApp button or cookie notice.', 'beaver-translate' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<?php self::render_credit(); ?>
		</div>
		<?php
	}

	/**
	 * Masks an API key for display, keeping enough visible to tell keys apart.
	 *
	 * @since 1.1.2
	 *
	 * @param string $key Raw key.
	 * @return string Masked key.
	 */
	private static function mask_key( $key ) {
		$key    = (string) $key;
		$length = strlen( $key );

		if ( $length <= 8 ) {
			return str_repeat( '•', max( 8, $length ) );
		}

		return substr( $key, 0, 4 ) . str_repeat( '•', 12 ) . substr( $key, -4 );
	}

	/**
	 * Renders one row of the languages table.
	 *
	 * @since 1.0.0
	 *
	 * @param string     $option  Option name.
	 * @param int|string $index   Row index, or '__INDEX__' for the JS template.
	 * @param array      $language Row values.
	 * @param string     $default_code Currently configured default language code.
	 */
	private static function render_language_row( $option, $index, $language, $default_code ) {
		$name = sprintf( '%s[languages][%s]', $option, $index );
		?>
		<tr class="beaver-translate-language-row">
			<td>
				<input type="radio" name="<?php echo esc_attr( $option ); ?>[default_language]" value="<?php echo esc_attr( $language['code'] ); ?>" <?php checked( $language['code'] === $default_code && '' !== $language['code'] ); ?> />
			</td>
			<td><input type="text" class="small-text" name="<?php echo esc_attr( $name ); ?>[code]" value="<?php echo esc_attr( $language['code'] ); ?>" placeholder="fr" maxlength="12" /></td>
			<td><input type="text" name="<?php echo esc_attr( $name ); ?>[name]" value="<?php echo esc_attr( $language['name'] ); ?>" placeholder="French" /></td>
			<td><input type="text" name="<?php echo esc_attr( $name ); ?>[native]" value="<?php echo esc_attr( $language['native'] ); ?>" placeholder="Français" /></td>
			<td><button type="button" class="button-link beaver-translate-danger" data-beaver-translate-remove-language><?php esc_html_e( 'Remove', 'beaver-translate' ); ?></button></td>
		</tr>
		<?php
	}

	/**
	 * Renders the maker's mark shown at the foot of every plugin screen.
	 *
	 * @since 1.0.0
	 */
	private static function render_credit() {
		?>
		<div class="beaver-translate-credit">
			<img class="beaver-translate-credit__logo" width="300" height="152"
			     src="<?php echo esc_url( BEAVER_TRANSLATE_URL . 'assets/digital-beaver-logo.png' ); ?>"
			     alt="<?php esc_attr_e( 'Digital Beaver', 'beaver-translate' ); ?>" />
			<div class="beaver-translate-credit__text">
				<strong><?php esc_html_e( 'Designed & built by Digital Beaver', 'beaver-translate' ); ?></strong>
				<?php esc_html_e( 'Need a change, a new feature, or a site as fast as this one?', 'beaver-translate' ); ?>
				<a href="https://digitalbeavertz.com/" target="_blank" rel="noopener noreferrer">digitalbeavertz.com</a>
			</div>
		</div>
		<?php
	}
}
