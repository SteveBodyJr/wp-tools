<?php
/**
 * The Tools screen.
 *
 * @package BeaverUpdates
 */

defined( 'ABSPATH' ) || exit;

/**
 * One screen answering two questions: is every Digital Beaver plugin on this
 * site current, and what else is there that this site does not have yet.
 *
 * @since 1.0.0
 */
final class Beaver_Updates_Admin {

	const MENU_SLUG  = 'beaver-updates';
	const NONCE      = 'beaver_updates_action';
	const CAPABILITY = 'update_plugins';

	/**
	 * Registers hooks.
	 *
	 * @since 1.0.0
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_actions' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );

		add_filter( 'plugin_action_links_' . BEAVER_UPDATES_BASENAME, array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Adds the screen under Tools.
	 *
	 * @since 1.0.0
	 */
	public static function register_menu() {
		add_management_page(
			__( 'Beaver Updates', 'beaver-updates' ),
			__( 'Beaver Updates', 'beaver-updates' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Adds a shortcut to the plugin's row.
	 *
	 * @since 1.0.0
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public static function action_links( $links ) {
		array_unshift(
			$links,
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( self::url() ),
				esc_html__( 'Plugins', 'beaver-updates' )
			)
		);

		return $links;
	}

	/**
	 * Loads the stylesheet on this screen only.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook_suffix Current screen.
	 */
	public static function enqueue_assets( $hook_suffix ) {
		if ( false === strpos( (string) $hook_suffix, self::MENU_SLUG ) ) {
			return;
		}

		wp_enqueue_style( 'beaver-updates-admin', BEAVER_UPDATES_URL . 'admin/css/admin.css', array(), BEAVER_UPDATES_VERSION );
	}

	/**
	 * This screen's URL.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	private static function url() {
		return admin_url( 'tools.php?page=' . self::MENU_SLUG );
	}

	/*
	 * -----------------------------------------------------------------------
	 * Actions
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Handles the form posts.
	 *
	 * @since 1.0.0
	 */
	public static function handle_actions() {
		if ( ! isset( $_REQUEST['beaver_updates_action'] ) || ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$action = sanitize_key( wp_unslash( $_REQUEST['beaver_updates_action'] ) );
		$base   = self::url();

		check_admin_referer( self::NONCE );

		if ( 'refresh' === $action ) {
			Beaver_Updates_Channel::forget();
			Beaver_Updates_Channel::refresh();

			// Make WordPress rebuild its own picture too, so the Updates screen
			// and this one cannot disagree.
			delete_site_transient( 'update_plugins' );

			self::redirect( $base, 'bu_checked', '1' );
		}

		if ( 'auto' === $action ) {
			self::save_auto_updates();
			self::redirect( $base, 'bu_saved', '1' );
		}

		if ( 'install' === $action ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above.
			$slug   = isset( $_POST['slug'] ) ? sanitize_key( wp_unslash( $_POST['slug'] ) ) : '';
			$result = self::install( $slug );

			if ( is_wp_error( $result ) ) {
				self::redirect( $base, 'bu_error', $result->get_error_message() );
			}

			self::redirect( $base, 'bu_installed', $slug );
		}

		if ( 'activate' === $action ) {
			// Arrives as a nonce protected link rather than a post, because the
			// row it belongs to already sits inside the automatic updates form
			// and forms do not nest. This is how core's own plugin activation
			// links work.
			$file   = isset( $_REQUEST['plugin'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['plugin'] ) ) : '';
			$result = self::activate( $file );

			if ( is_wp_error( $result ) ) {
				self::redirect( $base, 'bu_error', $result->get_error_message() );
			}

			self::redirect( $base, 'bu_activated', dirname( $file ) );
		}
	}

	/**
	 * Redirects back to the screen with one message argument.
	 *
	 * @since 1.0.0
	 *
	 * @param string $base  Screen URL.
	 * @param string $key   Query argument.
	 * @param string $value Value.
	 */
	private static function redirect( $base, $key, $value ) {
		wp_safe_redirect( add_query_arg( $key, rawurlencode( $value ), $base ) );

		exit;
	}

	/**
	 * Writes the automatic update choices.
	 *
	 * @since 1.0.0
	 */
	private static function save_auto_updates() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked by the caller.
		$wanted = isset( $_POST['auto'] ) ? array_map( 'sanitize_text_field', wp_unslash( (array) $_POST['auto'] ) ) : array();

		$ours    = Beaver_Updates_Updates::ours();
		$enabled = (array) get_site_option( 'auto_update_plugins', array() );

		// Only ever add or remove our own plugins. Whatever the site has
		// decided about everything else is none of this plugin's business.
		$enabled = array_values( array_diff( $enabled, array_keys( $ours ) ) );

		foreach ( $wanted as $plugin_file ) {
			if ( isset( $ours[ $plugin_file ] ) ) {
				$enabled[] = $plugin_file;
			}
		}

		update_site_option( 'auto_update_plugins', array_values( array_unique( $enabled ) ) );
	}

	/**
	 * Installs a plugin from the channel.
	 *
	 * The package URL comes from the channel, which only ever returns one
	 * published where it publishes, so there is no user supplied URL anywhere
	 * in this path: the form posts a slug and nothing else.
	 *
	 * @since 1.0.0
	 *
	 * @param string $slug Plugin slug.
	 * @return true|WP_Error
	 */
	private static function install( $slug ) {
		if ( ! current_user_can( 'install_plugins' ) ) {
			return new WP_Error( 'beaver_updates_cap', __( 'You are not allowed to install plugins.', 'beaver-updates' ) );
		}

		$entry = Beaver_Updates_Channel::plugin( $slug );

		if ( ! $entry ) {
			return new WP_Error( 'beaver_updates_unknown', __( 'That plugin is not on the channel.', 'beaver-updates' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		if ( 'direct' !== get_filesystem_method() ) {
			return new WP_Error(
				'beaver_updates_filesystem',
				__( 'WordPress cannot write to the plugins folder on this host without credentials, so it cannot install from here. Use the download link and upload the zip instead.', 'beaver-updates' )
			);
		}

		$upgrader = new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
		$result   = $upgrader->install( $entry['package'] );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( true !== $result ) {
			$messages = $upgrader->skin->get_upgrade_messages();

			return new WP_Error(
				'beaver_updates_install',
				$messages ? (string) end( $messages ) : __( 'The install did not finish.', 'beaver-updates' )
			);
		}

		// The new plugin belongs in WordPress's picture of what is installed.
		delete_site_transient( 'update_plugins' );

		return true;
	}

	/**
	 * Activates a plugin, provided it is one of ours.
	 *
	 * @since 1.0.0
	 *
	 * @param string $plugin_file Plugin file.
	 * @return true|WP_Error
	 */
	private static function activate( $plugin_file ) {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return new WP_Error( 'beaver_updates_cap', __( 'You are not allowed to activate plugins.', 'beaver-updates' ) );
		}

		// Never activate on the strength of a posted path. It has to be a
		// plugin this channel publishes and that is already on disk.
		$ours = Beaver_Updates_Updates::ours();

		if ( ! isset( $ours[ $plugin_file ] ) ) {
			return new WP_Error( 'beaver_updates_unknown', __( 'That is not a plugin from this channel.', 'beaver-updates' ) );
		}

		$activated = activate_plugin( $plugin_file );

		return is_wp_error( $activated ) ? $activated : true;
	}

	/*
	 * -----------------------------------------------------------------------
	 * Screen
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Renders the screen.
	 *
	 * @since 1.0.0
	 */
	public static function render() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to view this page.', 'beaver-updates' ) );
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		$manifest  = Beaver_Updates_Channel::get();
		$published = Beaver_Updates_Channel::plugins();
		$installed = get_plugins();
		$auto      = (array) get_site_option( 'auto_update_plugins', array() );
		$auto_ok   = function_exists( 'wp_is_auto_update_enabled_for_type' ) ? wp_is_auto_update_enabled_for_type( 'plugin' ) : true;
		$can_add   = current_user_can( 'install_plugins' ) && 'direct' === get_filesystem_method();

		$by_slug = array();

		foreach ( $installed as $plugin_file => $data ) {
			$by_slug[ dirname( $plugin_file ) ] = array( $plugin_file, $data );
		}

		$here = array();
		$away = array();

		foreach ( $published as $slug => $entry ) {
			if ( isset( $by_slug[ $slug ] ) ) {
				$here[ $slug ] = $entry;
			} else {
				$away[ $slug ] = $entry;
			}
		}

		?>
		<div class="wrap beaver-updates">
			<h1><?php esc_html_e( 'Beaver Updates', 'beaver-updates' ); ?></h1>
			<p class="beaver-updates-lead">
				<?php esc_html_e( 'Every Digital Beaver plugin, whether it is on this site or not. The ones here update from Plugins → Updates like anything from wordpress.org. The ones that are not can be added from this screen.', 'beaver-updates' ); ?>
			</p>

			<?php self::render_notices(); ?>

			<?php if ( '' !== $manifest['error'] ) : ?>
				<div class="notice notice-error">
					<p>
						<strong><?php esc_html_e( 'The channel could not be read.', 'beaver-updates' ); ?></strong>
						<?php echo esc_html( $manifest['error'] ); ?>
					</p>
					<p class="description">
						<?php esc_html_e( 'Nothing breaks while this is true: the plugins carry on working and no update is offered. The failure is remembered for an hour so the site is not retrying on every page load.', 'beaver-updates' ); ?>
					</p>
				</div>
			<?php endif; ?>

			<div class="beaver-updates-panel">
				<h2><?php esc_html_e( 'Channel', 'beaver-updates' ); ?></h2>
				<table class="beaver-updates-meta">
					<tbody>
						<tr>
							<th scope="row"><?php esc_html_e( 'Manifest', 'beaver-updates' ); ?></th>
							<td><code><?php echo esc_html( Beaver_Updates_Channel::MANIFEST_URL ); ?></code></td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Last checked', 'beaver-updates' ); ?></th>
							<td>
								<?php
								printf(
									/* translators: %s: human readable time difference. */
									esc_html__( '%s ago', 'beaver-updates' ),
									esc_html( human_time_diff( (int) $manifest['fetched'], time() ) )
								);

								if ( $manifest['code'] ) {
									printf( ' <span class="beaver-updates-dim">(HTTP %d)</span>', (int) $manifest['code'] );
								}
								?>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php esc_html_e( 'Published', 'beaver-updates' ); ?></th>
							<td>
								<?php
								printf(
									/* translators: 1: plugins on this site, 2: total published. */
									esc_html__( '%1$d of %2$d installed here', 'beaver-updates' ),
									count( $here ),
									count( $published )
								);
								?>
							</td>
						</tr>
					</tbody>
				</table>

				<form method="post" action="">
					<?php wp_nonce_field( self::NONCE ); ?>
					<input type="hidden" name="beaver_updates_action" value="refresh" />
					<?php submit_button( __( 'Check now', 'beaver-updates' ), 'secondary', 'submit', false ); ?>
					<span class="beaver-updates-dim">
						<?php esc_html_e( 'Checks happen on their own about twice a day. This forces one.', 'beaver-updates' ); ?>
					</span>
				</form>
			</div>

			<h2><?php esc_html_e( 'On this site', 'beaver-updates' ); ?></h2>
			<?php self::render_installed( $here, $by_slug, $auto, $auto_ok ); ?>

			<h2><?php esc_html_e( 'Available to add', 'beaver-updates' ); ?></h2>
			<?php self::render_available( $away, $can_add ); ?>

			<?php self::render_credit(); ?>
		</div>
		<?php
	}

	/**
	 * Prints whatever the last action had to say.
	 *
	 * @since 1.0.0
	 */
	private static function render_notices() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['bu_error'] ) ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html( sanitize_text_field( wp_unslash( $_GET['bu_error'] ) ) )
			);
		}

		if ( isset( $_GET['bu_checked'] ) ) {
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html__( 'Checked.', 'beaver-updates' ) );
		}

		if ( isset( $_GET['bu_saved'] ) ) {
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html__( 'Automatic updates saved.', 'beaver-updates' ) );
		}

		if ( isset( $_GET['bu_installed'] ) ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: plugin slug. */
						__( '%s installed. It is not active yet.', 'beaver-updates' ),
						sanitize_key( wp_unslash( $_GET['bu_installed'] ) )
					)
				)
			);
		}

		if ( isset( $_GET['bu_activated'] ) ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: plugin slug. */
						__( '%s activated.', 'beaver-updates' ),
						sanitize_key( wp_unslash( $_GET['bu_activated'] ) )
					)
				)
			);
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * The plugins from this channel that are on this site.
	 *
	 * @since 1.0.0
	 *
	 * @param array $here    Manifest entries installed here.
	 * @param array $by_slug Installed plugins keyed by directory.
	 * @param array $auto    Plugin files set to update on their own.
	 * @param bool  $auto_ok Whether the site allows automatic plugin updates.
	 */
	private static function render_installed( array $here, array $by_slug, array $auto, $auto_ok ) {
		if ( ! $here ) {
			?>
			<p class="beaver-updates-empty">
				<?php esc_html_e( 'None of these plugins are on this site yet. Everything published is listed below.', 'beaver-updates' ); ?>
			</p>
			<?php

			return;
		}

		$behind = 0;
		?>
		<form method="post" action="">
			<?php wp_nonce_field( self::NONCE ); ?>
			<input type="hidden" name="beaver_updates_action" value="auto" />

			<table class="wp-list-table widefat striped beaver-updates-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Plugin', 'beaver-updates' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Installed', 'beaver-updates' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Channel', 'beaver-updates' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Status', 'beaver-updates' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Update on its own', 'beaver-updates' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $here as $slug => $entry ) : ?>
						<?php
						$file    = $by_slug[ $slug ][0];
						$current = (string) $by_slug[ $slug ][1]['Version'];
						$active  = is_plugin_active( $file );

						if ( version_compare( $entry['version'], $current, '>' ) ) {
							$state = 'behind';
							$label = __( 'Update available', 'beaver-updates' );
							$behind++;
						} elseif ( version_compare( $current, $entry['version'], '>' ) ) {
							$state = 'ahead';
							$label = __( 'Newer than the channel', 'beaver-updates' );
						} else {
							$state = 'current';
							$label = __( 'Up to date', 'beaver-updates' );
						}
						?>
						<tr>
							<td>
								<strong><?php echo esc_html( $entry['name'] ); ?></strong>
								<div class="beaver-updates-dim">
									<?php
									echo esc_html( $slug );
									echo $active
										? ' &middot; ' . esc_html__( 'active', 'beaver-updates' )
										: ' &middot; ' . esc_html__( 'inactive', 'beaver-updates' );
									?>
								</div>
							</td>
							<td><?php echo esc_html( $current ); ?></td>
							<td><?php echo esc_html( $entry['version'] ); ?></td>
							<td>
								<span class="beaver-updates-state beaver-updates-state--<?php echo esc_attr( $state ); ?>">
									<?php echo esc_html( $label ); ?>
								</span>
								<?php if ( 'behind' === $state ) : ?>
									<a href="<?php echo esc_url( admin_url( 'plugins.php' ) ); ?>"><?php esc_html_e( 'Update', 'beaver-updates' ); ?></a>
								<?php endif; ?>
								<?php if ( ! $active && current_user_can( 'activate_plugins' ) ) : ?>
									<a href="
									<?php
									echo esc_url(
										wp_nonce_url(
											add_query_arg(
												array(
													'beaver_updates_action' => 'activate',
													'plugin'                => rawurlencode( $file ),
												),
												self::url()
											),
											self::NONCE
										)
									);
									?>
									"><?php esc_html_e( 'Activate', 'beaver-updates' ); ?></a>
								<?php endif; ?>
							</td>
							<td>
								<label>
									<input type="checkbox" name="auto[]" value="<?php echo esc_attr( $file ); ?>" <?php checked( in_array( $file, $auto, true ) ); ?> <?php disabled( ! $auto_ok ); ?> />
									<?php esc_html_e( 'Yes', 'beaver-updates' ); ?>
								</label>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php if ( ! $auto_ok ) : ?>
				<p class="description">
					<?php esc_html_e( 'Automatic plugin updates are switched off for this whole site, by a constant or a filter, so these boxes cannot do anything until that changes.', 'beaver-updates' ); ?>
				</p>
			<?php endif; ?>

			<p class="description">
				<?php esc_html_e( 'Leaving a plugin to update on its own is worth it for the ones that cannot lock you out. Think twice about the file manager, the access links and the debug log: if a release of one of those is broken, you want to be the one who pressed the button.', 'beaver-updates' ); ?>
			</p>

			<?php submit_button( __( 'Save automatic updates', 'beaver-updates' ) ); ?>
		</form>

		<?php if ( $behind ) : ?>
			<p>
				<?php
				printf(
					/* translators: %d: number of plugins behind. */
					esc_html( _n( '%d plugin is behind.', '%d plugins are behind.', $behind, 'beaver-updates' ) ),
					(int) $behind
				);
				?>
			</p>
			<?php
		endif;
	}

	/**
	 * The plugins from this channel that this site does not have.
	 *
	 * @since 1.0.0
	 *
	 * @param array $away    Manifest entries not installed here.
	 * @param bool  $can_add Whether this site can install from here.
	 */
	private static function render_available( array $away, $can_add ) {
		if ( ! $away ) {
			?>
			<p class="beaver-updates-empty">
				<?php esc_html_e( 'This site has all of them. Anything published later appears here on its own, with nothing to set up.', 'beaver-updates' ); ?>
			</p>
			<?php

			return;
		}
		?>
		<p class="beaver-updates-lead">
			<?php esc_html_e( 'Not on this site yet. Anything published later joins this list on its own.', 'beaver-updates' ); ?>
		</p>

		<?php if ( ! $can_add ) : ?>
			<p class="description">
				<?php esc_html_e( 'This site cannot install plugins directly, either because of your role or because WordPress needs credentials to write to the plugins folder. Download a zip instead and upload it under Plugins → Add New → Upload.', 'beaver-updates' ); ?>
			</p>
		<?php endif; ?>

		<div class="beaver-updates-cards">
			<?php foreach ( $away as $slug => $entry ) : ?>
				<div class="beaver-updates-card">
					<h3 class="beaver-updates-card__title"><?php echo esc_html( $entry['name'] ); ?></h3>
					<p class="beaver-updates-card__version">
						<?php
						printf(
							/* translators: %s: version number. */
							esc_html__( 'Version %s', 'beaver-updates' ),
							esc_html( $entry['version'] )
						);
						?>
					</p>
					<?php if ( '' !== $entry['description'] ) : ?>
						<p class="beaver-updates-card__text"><?php echo esc_html( $entry['description'] ); ?></p>
					<?php endif; ?>
					<p class="beaver-updates-card__actions">
						<?php if ( $can_add ) : ?>
							<form method="post" action="">
								<?php wp_nonce_field( self::NONCE ); ?>
								<input type="hidden" name="beaver_updates_action" value="install" />
								<input type="hidden" name="slug" value="<?php echo esc_attr( $slug ); ?>" />
								<?php submit_button( __( 'Install', 'beaver-updates' ), 'primary', 'submit', false ); ?>
							</form>
						<?php endif; ?>
						<a class="button" href="<?php echo esc_url( $entry['package'] ); ?>"><?php esc_html_e( 'Download zip', 'beaver-updates' ); ?></a>
						<?php if ( $entry['homepage'] ) : ?>
							<a href="<?php echo esc_url( $entry['homepage'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Read more', 'beaver-updates' ); ?></a>
						<?php endif; ?>
					</p>
				</div>
			<?php endforeach; ?>
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
		<div class="beaver-updates-credit">
			<img class="beaver-updates-credit__logo" width="300" height="152"
			     src="<?php echo esc_url( BEAVER_UPDATES_URL . 'assets/digital-beaver-logo.png' ); ?>"
			     alt="<?php esc_attr_e( 'Digital Beaver', 'beaver-updates' ); ?>" />
			<div class="beaver-updates-credit__text">
				<strong><?php esc_html_e( 'Designed & built by Digital Beaver', 'beaver-updates' ); ?></strong>
				<?php esc_html_e( 'Need a change, a new feature, or a site as fast as this one?', 'beaver-updates' ); ?>
				<a href="https://digitalbeavertz.com/" target="_blank" rel="noopener noreferrer">digitalbeavertz.com</a>
			</div>
		</div>
		<?php
	}
}
