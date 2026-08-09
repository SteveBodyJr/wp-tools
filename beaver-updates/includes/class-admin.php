<?php
/**
 * The Tools screen.
 *
 * @package BeaverUpdates
 */

defined( 'ABSPATH' ) || exit;

/**
 * One screen answering one question: is every Digital Beaver plugin on this
 * site current, and if not, why not.
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
				esc_url( admin_url( 'tools.php?page=' . self::MENU_SLUG ) ),
				esc_html__( 'Updates', 'beaver-updates' )
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
	 * Handles the two form posts.
	 *
	 * @since 1.0.0
	 */
	public static function handle_actions() {
		if ( ! isset( $_REQUEST['beaver_updates_action'] ) || ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$action = sanitize_key( wp_unslash( $_REQUEST['beaver_updates_action'] ) );
		$base   = admin_url( 'tools.php?page=' . self::MENU_SLUG );

		check_admin_referer( self::NONCE );

		if ( 'refresh' === $action ) {
			Beaver_Updates_Channel::forget();
			Beaver_Updates_Channel::refresh();

			// Make WordPress rebuild its own picture too, so the Updates screen
			// and this one cannot disagree.
			delete_site_transient( 'update_plugins' );

			wp_safe_redirect( add_query_arg( 'bu_checked', '1', $base ) );
			exit;
		}

		if ( 'auto' === $action ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above.
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

			wp_safe_redirect( add_query_arg( 'bu_saved', '1', $base ) );
			exit;
		}
	}

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

		$manifest  = Beaver_Updates_Channel::get();
		$published = Beaver_Updates_Channel::plugins();
		$installed = get_plugins();
		$ours      = Beaver_Updates_Updates::ours();
		$auto      = (array) get_site_option( 'auto_update_plugins', array() );
		$auto_ok   = function_exists( 'wp_is_auto_update_enabled_for_type' ) ? wp_is_auto_update_enabled_for_type( 'plugin' ) : true;

		$by_slug = array();

		foreach ( $installed as $plugin_file => $data ) {
			$by_slug[ dirname( $plugin_file ) ] = array( $plugin_file, $data );
		}

		$behind = 0;
		?>
		<div class="wrap beaver-updates">
			<h1><?php esc_html_e( 'Beaver Updates', 'beaver-updates' ); ?></h1>
			<p class="beaver-updates-lead">
				<?php esc_html_e( 'The Digital Beaver plugins on this site, checked against the published channel. Anything behind appears under Plugins → Updates with an update button, the same as a plugin from wordpress.org.', 'beaver-updates' ); ?>
			</p>

			<?php if ( isset( $_GET['bu_checked'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Checked.', 'beaver-updates' ); ?></p></div>
			<?php endif; ?>

			<?php if ( isset( $_GET['bu_saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Automatic updates saved.', 'beaver-updates' ); ?></p></div>
			<?php endif; ?>

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
							<th scope="row"><?php esc_html_e( 'Plugins published', 'beaver-updates' ); ?></th>
							<td><?php echo esc_html( (string) count( $published ) ); ?></td>
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
						<?php foreach ( $published as $slug => $entry ) : ?>
							<?php
							$here    = isset( $by_slug[ $slug ] ) ? $by_slug[ $slug ] : null;
							$file    = $here ? $here[0] : '';
							$current = $here ? (string) $here[1]['Version'] : '';

							if ( ! $here ) {
								$state = 'absent';
								$label = __( 'Not installed', 'beaver-updates' );
							} elseif ( version_compare( $entry['version'], $current, '>' ) ) {
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
									<div class="beaver-updates-dim"><?php echo esc_html( $slug ); ?></div>
								</td>
								<td><?php echo $current ? esc_html( $current ) : '<span class="beaver-updates-dim">&mdash;</span>'; ?></td>
								<td><?php echo esc_html( $entry['version'] ); ?></td>
								<td>
									<span class="beaver-updates-state beaver-updates-state--<?php echo esc_attr( $state ); ?>">
										<?php echo esc_html( $label ); ?>
									</span>
									<?php if ( 'behind' === $state ) : ?>
										<a href="<?php echo esc_url( admin_url( 'plugins.php' ) ); ?>"><?php esc_html_e( 'Update', 'beaver-updates' ); ?></a>
									<?php elseif ( 'absent' === $state && $entry['homepage'] ) : ?>
										<a href="<?php echo esc_url( $entry['homepage'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Source', 'beaver-updates' ); ?></a>
									<?php endif; ?>
								</td>
								<td>
									<?php if ( $here ) : ?>
										<label>
											<input type="checkbox" name="auto[]" value="<?php echo esc_attr( $file ); ?>" <?php checked( in_array( $file, $auto, true ) ); ?> <?php disabled( ! $auto_ok ); ?> />
											<?php esc_html_e( 'Yes', 'beaver-updates' ); ?>
										</label>
									<?php else : ?>
										<span class="beaver-updates-dim">&mdash;</span>
									<?php endif; ?>
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
						esc_html( _n( '%d plugin is behind. Update it from the Plugins screen.', '%d plugins are behind. Update them from the Plugins screen.', $behind, 'beaver-updates' ) ),
						(int) $behind
					);
					?>
				</p>
			<?php endif; ?>

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
