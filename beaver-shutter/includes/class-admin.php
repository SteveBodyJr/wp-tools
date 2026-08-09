<?php
/**
 * Admin screen.
 *
 * @package BeaverShutter
 */

defined( 'ABSPATH' ) || exit;

/**
 * One screen: is the site open, and what do visitors see when it is not.
 *
 * @since 1.0.0
 */
class Beaver_Shutter_Admin {

	const NONCE      = 'beaver_shutter_action';
	const CAPABILITY = 'manage_options';

	/**
	 * Registers hooks.
	 *
	 * @since 1.0.0
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_actions' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar' ), 90 );
	}

	/**
	 * Adds the screen under Tools.
	 *
	 * @since 1.0.0
	 */
	public static function register_menu() {
		add_management_page(
			__( 'Site Shutter', 'beaver-shutter' ),
			__( 'Shutter', 'beaver-shutter' ) . self::bubble(),
			self::CAPABILITY,
			BEAVER_SHUTTER_SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * A standing reminder in the toolbar while the site is closed.
	 *
	 * The one failure mode of a maintenance mode is forgetting it is on. A
	 * marker on every admin page, on every site, is cheap insurance against a
	 * site left dark for a week by accident.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_Admin_Bar $bar Toolbar.
	 */
	public static function admin_bar( $bar ) {
		if ( ! current_user_can( self::CAPABILITY ) || ! Beaver_Shutter_Settings::is_closed() ) {
			return;
		}

		$bar->add_node(
			array(
				'id'    => 'beaver-shutter',
				'title' => '⚠ ' . __( 'Site is closed', 'beaver-shutter' ),
				'href'  => admin_url( 'tools.php?page=' . BEAVER_SHUTTER_SLUG ),
				'meta'  => array( 'title' => __( 'Beaver Shutter — the front end is showing a holding page', 'beaver-shutter' ) ),
			)
		);
	}

	/**
	 * The count bubble on the menu item.
	 *
	 * @since 1.0.0
	 *
	 * @return string Markup, or an empty string.
	 */
	private static function bubble() {
		if ( ! Beaver_Shutter_Settings::is_closed() ) {
			return '';
		}

		return ' <span class="awaiting-mod"><span class="pending-count">1</span></span>';
	}

	/**
	 * Loads assets on this screen only.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook_suffix Current screen.
	 */
	public static function enqueue_assets( $hook_suffix ) {
		if ( false === strpos( (string) $hook_suffix, BEAVER_SHUTTER_SLUG ) ) {
			return;
		}

		wp_enqueue_style( 'beaver-shutter-admin', BEAVER_SHUTTER_URL . 'admin/css/admin.css', array(), BEAVER_SHUTTER_VERSION );
		wp_enqueue_script( 'beaver-shutter-admin', BEAVER_SHUTTER_URL . 'admin/js/admin.js', array(), BEAVER_SHUTTER_VERSION, true );

		wp_localize_script(
			'beaver-shutter-admin',
			'beaverShutter',
			array(
				'i18n' => array(
					'confirmDark' => __( 'Take the site dark? Every visitor will see the holding page instead of the site until you reopen it. Your wp-admin keeps working.', 'beaver-shutter' ),
					'clearLog'    => __( 'Delete the record of when the site was opened and closed?', 'beaver-shutter' ),
				),
			)
		);
	}

	/**
	 * Handles form posts.
	 *
	 * @since 1.0.0
	 */
	public static function handle_actions() {
		if ( ! isset( $_POST['beaver_shutter_action'] ) || ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		check_admin_referer( self::NONCE );

		$action = sanitize_key( wp_unslash( $_POST['beaver_shutter_action'] ) );
		$base   = admin_url( 'tools.php?page=' . BEAVER_SHUTTER_SLUG );

		if ( 'save' === $action ) {
			self::save();

			wp_safe_redirect( add_query_arg( 'bs_saved', '1', $base ) );
			exit;
		}

		if ( 'reopen' === $action ) {
			self::apply_level( Beaver_Shutter_Settings::OPEN );

			wp_safe_redirect( add_query_arg( 'bs_reopened', '1', $base ) );
			exit;
		}

		if ( 'clear_log' === $action ) {
			Beaver_Shutter_Log::clear();

			wp_safe_redirect( $base );
			exit;
		}
	}

	/**
	 * Saves the level and the wording.
	 *
	 * @since 1.0.0
	 */
	private static function save() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in handle_actions().
		$level = isset( $_POST['level'] ) ? sanitize_key( wp_unslash( $_POST['level'] ) ) : Beaver_Shutter_Settings::OPEN;

		Beaver_Shutter_Settings::update(
			array(
				'title'       => isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '',
				'body'        => isset( $_POST['body'] ) ? wp_kses_post( wp_unslash( $_POST['body'] ) ) : '',
				'contact'     => isset( $_POST['contact'] ) ? sanitize_text_field( wp_unslash( $_POST['contact'] ) ) : '',
				'retry_after' => isset( $_POST['retry_after'] ) ? absint( wp_unslash( $_POST['retry_after'] ) ) : 3600,
			)
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		self::apply_level( $level );
	}

	/**
	 * Moves to a level and logs the move.
	 *
	 * @since 1.0.0
	 *
	 * @param string $level Target level.
	 */
	private static function apply_level( $level ) {
		$levels = Beaver_Shutter_Settings::levels();

		if ( ! array_key_exists( $level, $levels ) ) {
			$level = Beaver_Shutter_Settings::OPEN;
		}

		$before = Beaver_Shutter_Settings::get( 'level' );

		Beaver_Shutter_Settings::update(
			array(
				'level'      => $level,
				'last_level' => $before,
			)
		);

		if ( $level === $before ) {
			return;
		}

		if ( Beaver_Shutter_Settings::OPEN === $level ) {
			Beaver_Shutter_Log::record( 'reopened', __( 'Site reopened.', 'beaver-shutter' ) );
		} else {
			Beaver_Shutter_Log::record(
				'closed',
				sprintf(
					/* translators: %s: level name. */
					__( 'Site closed — %s.', 'beaver-shutter' ),
					$levels[ $level ]['label']
				)
			);
		}
	}

	/**
	 * Renders the screen.
	 *
	 * @since 1.0.0
	 */
	public static function render() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to view this page.', 'beaver-shutter' ) );
		}

		$settings = Beaver_Shutter_Settings::all();
		$levels   = Beaver_Shutter_Settings::levels();
		$closed   = Beaver_Shutter_Settings::is_closed();
		$base     = admin_url( 'tools.php?page=' . BEAVER_SHUTTER_SLUG );
		$forced   = defined( 'BEAVER_SHUTTER_OFF' ) && BEAVER_SHUTTER_OFF;
		?>
		<div class="wrap beaver-shutter">
			<h1><?php esc_html_e( 'Site Shutter', 'beaver-shutter' ); ?></h1>

			<?php if ( isset( $_GET['bs_saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Saved.', 'beaver-shutter' ); ?></p></div>
			<?php endif; ?>

			<?php if ( isset( $_GET['bs_reopened'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'The site is open again.', 'beaver-shutter' ); ?></p></div>
			<?php endif; ?>

			<?php if ( $forced ) : ?>
				<div class="notice notice-warning"><p>
					<?php esc_html_e( 'BEAVER_SHUTTER_OFF is set in wp-config.php, so the site stays open whatever you choose here. Remove that line to use the shutter again.', 'beaver-shutter' ); ?>
				</p></div>
			<?php endif; ?>

			<div class="beaver-shutter-status beaver-shutter-status--<?php echo esc_attr( $closed ? $settings['level'] : 'open' ); ?>">
				<div>
					<strong class="beaver-shutter-state">
						<?php echo esc_html( $levels[ $settings['level'] ]['label'] ); ?>
					</strong>
					<span class="beaver-shutter-note"><?php echo esc_html( $levels[ $settings['level'] ]['desc'] ); ?></span>
				</div>
				<div class="beaver-shutter-status__actions">
					<?php if ( $closed ) : ?>
						<form method="post" action="<?php echo esc_url( $base ); ?>">
							<?php wp_nonce_field( self::NONCE ); ?>
							<input type="hidden" name="beaver_shutter_action" value="reopen" />
							<button type="submit" class="button button-primary button-hero"><?php esc_html_e( 'Reopen the site', 'beaver-shutter' ); ?></button>
						</form>
					<?php endif; ?>
					<a class="button" href="<?php echo esc_url( add_query_arg( Beaver_Shutter_Lockdown::PREVIEW_ARG, '1', home_url( '/' ) ) ); ?>" target="_blank" rel="noopener noreferrer">
						<?php esc_html_e( 'Preview the holding page', 'beaver-shutter' ); ?>
					</a>
				</div>
			</div>

			<form method="post" action="<?php echo esc_url( $base ); ?>">
				<?php wp_nonce_field( self::NONCE ); ?>
				<input type="hidden" name="beaver_shutter_action" value="save" />

				<h2><?php esc_html_e( 'State', 'beaver-shutter' ); ?></h2>
				<table class="widefat striped beaver-shutter-levels">
					<tbody>
						<?php foreach ( $levels as $slug => $meta ) : ?>
							<tr>
								<td class="beaver-shutter-levels__pick">
									<input type="radio"
									       id="bs-level-<?php echo esc_attr( $slug ); ?>"
									       name="level"
									       value="<?php echo esc_attr( $slug ); ?>"
									       <?php checked( $settings['level'], $slug ); ?>
									       <?php echo Beaver_Shutter_Settings::FULL === $slug ? 'data-confirm="dark"' : ''; ?> />
								</td>
								<td>
									<label for="bs-level-<?php echo esc_attr( $slug ); ?>"><strong><?php echo esc_html( $meta['label'] ); ?></strong></label>
									<span class="beaver-shutter-note"><?php echo esc_html( $meta['desc'] ); ?></span>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<h2><?php esc_html_e( 'The holding page', 'beaver-shutter' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="bs-title"><?php esc_html_e( 'Heading', 'beaver-shutter' ); ?></label></th>
						<td><input type="text" id="bs-title" name="title" class="large-text" value="<?php echo esc_attr( $settings['title'] ); ?>" placeholder="<?php esc_attr_e( 'We will be right back', 'beaver-shutter' ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="bs-body"><?php esc_html_e( 'Message', 'beaver-shutter' ); ?></label></th>
						<td><textarea id="bs-body" name="body" rows="4" class="large-text" placeholder="<?php esc_attr_e( 'This site is temporarily closed for maintenance. Please check back shortly.', 'beaver-shutter' ); ?>"><?php echo esc_textarea( $settings['body'] ); ?></textarea></td>
					</tr>
					<tr>
						<th scope="row"><label for="bs-contact"><?php esc_html_e( 'Contact line', 'beaver-shutter' ); ?></label></th>
						<td><input type="text" id="bs-contact" name="contact" class="regular-text" value="<?php echo esc_attr( $settings['contact'] ); ?>" placeholder="hello@example.com" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="bs-retry"><?php esc_html_e( 'Retry-After', 'beaver-shutter' ); ?></label></th>
						<td>
							<input type="number" id="bs-retry" name="retry_after" class="small-text" min="60" max="86400" value="<?php echo esc_attr( (string) $settings['retry_after'] ); ?>" />
							<span class="beaver-shutter-note"><?php esc_html_e( 'Seconds. Sent with the 503 so crawlers know when to come back. An hour (3600) is a sensible default.', 'beaver-shutter' ); ?></span>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save', 'beaver-shutter' ) ); ?>
			</form>

			<?php self::render_recovery(); ?>

			<h2><?php esc_html_e( 'Activity', 'beaver-shutter' ); ?></h2>
			<?php self::render_log( $base ); ?>

			<?php self::render_credit(); ?>
		</div>
		<?php
	}

	/**
	 * The "you can always get back in" box.
	 *
	 * A maintenance mode has to be impossible to get trapped behind, so the
	 * ways out are documented on the screen itself rather than buried in a
	 * readme nobody reads at the moment they need it.
	 *
	 * @since 1.0.0
	 */
	private static function render_recovery() {
		?>
		<div class="beaver-shutter-recovery">
			<h3><?php esc_html_e( 'Three ways to reopen the site', 'beaver-shutter' ); ?></h3>
			<p><?php esc_html_e( 'You can never be locked out by this plugin. Any one of these reopens the site:', 'beaver-shutter' ); ?></p>
			<ol>
				<li><?php esc_html_e( 'This screen — the shutter never closes wp-admin, so this page always loads.', 'beaver-shutter' ); ?></li>
				<li>
					<?php esc_html_e( 'WP-CLI, if you have shell access:', 'beaver-shutter' ); ?>
					<code>wp beaver-shutter off</code>
				</li>
				<li>
					<?php
					printf(
						/* translators: %s: PHP constant line. */
						esc_html__( 'A line in wp-config.php, which overrides everything: %s', 'beaver-shutter' ),
						'<code>define( \'BEAVER_SHUTTER_OFF\', true );</code>'
					);
					?>
				</li>
			</ol>
		</div>
		<?php
	}

	/**
	 * The log.
	 *
	 * @since 1.0.0
	 *
	 * @param string $base Screen URL.
	 */
	private static function render_log( $base ) {
		$entries = Beaver_Shutter_Log::all( 40 );

		if ( empty( $entries ) ) {
			echo '<p class="beaver-shutter-note">' . esc_html__( 'Nothing yet.', 'beaver-shutter' ) . '</p>';

			return;
		}
		?>
		<table class="widefat striped beaver-shutter-log">
			<tbody>
				<?php foreach ( $entries as $entry ) : ?>
					<tr>
						<td class="beaver-shutter-log__when">
							<?php echo esc_html( wp_date( 'j M Y', (int) $entry['time'] ) ); ?>
							<span class="beaver-shutter-note"><?php echo esc_html( wp_date( 'H:i', (int) $entry['time'] ) ); ?></span>
						</td>
						<td>
							<span class="beaver-shutter-badge beaver-shutter-badge--<?php echo esc_attr( $entry['event'] ); ?>"><?php echo esc_html( $entry['event'] ); ?></span>
							<?php echo esc_html( $entry['message'] ); ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<form method="post" action="<?php echo esc_url( $base ); ?>">
			<?php wp_nonce_field( self::NONCE ); ?>
			<input type="hidden" name="beaver_shutter_action" value="clear_log" />
			<button type="submit" class="button-link beaver-shutter-danger" id="beaver-shutter-clear-log">
				<?php esc_html_e( 'Clear the record', 'beaver-shutter' ); ?>
			</button>
		</form>
		<?php
	}

	/**
	 * Renders the maker's mark.
	 *
	 * @since 1.0.0
	 */
	private static function render_credit() {
		?>
		<div class="beaver-shutter-credit">
			<img class="beaver-shutter-credit__logo" width="300" height="152"
			     src="<?php echo esc_url( BEAVER_SHUTTER_URL . 'assets/digital-beaver-logo.png' ); ?>"
			     alt="<?php esc_attr_e( 'Digital Beaver', 'beaver-shutter' ); ?>" />
			<div class="beaver-shutter-credit__text">
				<strong><?php esc_html_e( 'Designed & built by Digital Beaver', 'beaver-shutter' ); ?></strong>
				<?php esc_html_e( 'Need a change, a new feature, or a site as fast as this one?', 'beaver-shutter' ); ?>
				<a href="https://digitalbeavertz.com/" target="_blank" rel="noopener noreferrer">digitalbeavertz.com</a>
			</div>
		</div>
		<?php
	}
}
