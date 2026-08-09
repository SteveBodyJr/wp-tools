<?php
/**
 * Admin screens.
 *
 * @package BeaverDebug
 */

defined( 'ABSPATH' ) || exit;

/**
 * The screens that let you read what was captured.
 *
 * @since 1.0.0
 */
class Beaver_Debug_Admin {

	const MENU_SLUG    = 'beaver-debug';
	const NONCE_ACTION = 'beaver_debug_action';
	const CAPABILITY   = 'manage_options';

	/**
	 * Registers admin hooks.
	 *
	 * @since 1.0.0
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_actions' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );

		add_action( 'add_option_' . Beaver_Debug_Settings::OPTION, array( 'Beaver_Debug_Settings', 'flush' ) );
		add_action( 'update_option_' . Beaver_Debug_Settings::OPTION, array( 'Beaver_Debug_Settings', 'flush' ) );
	}

	/**
	 * Registers the admin menu.
	 *
	 * @since 1.0.0
	 */
	public static function register_menu() {
		$fatals = Beaver_Debug_Logger::summary( time() - DAY_IN_SECONDS )['fatal'];
		$bubble = $fatals > 0 ? sprintf( ' <span class="update-plugins count-%1$d"><span class="update-count">%1$d</span></span>', $fatals ) : '';

		add_management_page(
			__( 'Beaver Debug', 'beaver-debug' ),
			__( 'Beaver Debug', 'beaver-debug' ) . $bubble,
			self::CAPABILITY,
			self::MENU_SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Registers the settings group.
	 *
	 * @since 1.0.0
	 */
	public static function register_settings() {
		register_setting(
			'beaver_debug_group',
			Beaver_Debug_Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'Beaver_Debug_Settings', 'sanitize' ),
				'default'           => Beaver_Debug_Settings::defaults(),
			)
		);
	}

	/**
	 * Enqueues assets on this plugin's screen only.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook_suffix Current screen.
	 */
	public static function enqueue_assets( $hook_suffix ) {
		if ( false === strpos( (string) $hook_suffix, self::MENU_SLUG ) ) {
			return;
		}

		wp_enqueue_style( 'beaver-debug-admin', BEAVER_DEBUG_URL . 'admin/css/admin.css', array(), BEAVER_DEBUG_VERSION );
		wp_enqueue_script( 'beaver-debug-admin', BEAVER_DEBUG_URL . 'admin/js/admin.js', array(), BEAVER_DEBUG_VERSION, true );

		wp_localize_script(
			'beaver-debug-admin',
			'beaverDebug',
			array(
				'copied' => __( 'Copied. Paste it wherever you are asking for help.', 'beaver-debug' ),
				'failed' => __( 'Could not copy. Select the text and copy it manually.', 'beaver-debug' ),
			)
		);
	}

	/**
	 * Handles the clear and download actions.
	 *
	 * @since 1.0.0
	 */
	public static function handle_actions() {
		if ( ! isset( $_GET['beaver_debug_action'] ) || ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$action = sanitize_key( wp_unslash( $_GET['beaver_debug_action'] ) );

		check_admin_referer( self::NONCE_ACTION );

		if ( 'clear' === $action ) {
			Beaver_Debug_Logger::clear();

			wp_safe_redirect( add_query_arg( 'cleared', '1', admin_url( 'tools.php?page=' . self::MENU_SLUG ) ) );
			exit;
		}

		if ( 'newtoken' === $action ) {
			Beaver_Debug_Viewer::ensure( true );

			wp_safe_redirect( add_query_arg( 'tab', 'settings', admin_url( 'tools.php?page=' . self::MENU_SLUG ) ) );
			exit;
		}

		if ( 'download' === $action ) {
			$report = Beaver_Debug_Health::report( 50 );

			nocache_headers();
			header( 'Content-Type: text/plain; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="beaver-debug-' . gmdate( 'Ymd-Hi' ) . '.txt"' );

			echo $report; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
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
			wp_die( esc_html__( 'You are not allowed to view this page.', 'beaver-debug' ) );
		}

		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'problems'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$url = admin_url( 'tools.php?page=' . self::MENU_SLUG );
		?>
		<div class="wrap beaver-debug">
			<h1><?php esc_html_e( 'Beaver Debug', 'beaver-debug' ); ?></h1>

			<?php if ( isset( $_GET['cleared'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Log cleared.', 'beaver-debug' ); ?></p></div>
			<?php endif; ?>

			<?php if ( '' === Beaver_Debug_Logger::file() ) : ?>
				<div class="notice notice-error"><p>
					<?php esc_html_e( 'Nothing can be recorded: the uploads folder is not writable, so there is nowhere to put the log.', 'beaver-debug' ); ?>
				</p></div>
			<?php elseif ( ! Beaver_Debug_Settings::get( 'enabled' ) ) : ?>
				<div class="notice notice-warning"><p>
					<?php esc_html_e( 'Capture is switched off, so nothing new is being recorded.', 'beaver-debug' ); ?>
				</p></div>
			<?php endif; ?>

			<nav class="nav-tab-wrapper">
				<a href="<?php echo esc_url( $url ); ?>" class="nav-tab <?php echo 'problems' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Problems', 'beaver-debug' ); ?></a>
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'health', $url ) ); ?>" class="nav-tab <?php echo 'health' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Server', 'beaver-debug' ); ?></a>
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'upgrade', $url ) ); ?>" class="nav-tab <?php echo 'upgrade' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Upgrade readiness', 'beaver-debug' ); ?></a>
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'report', $url ) ); ?>" class="nav-tab <?php echo 'report' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Share a report', 'beaver-debug' ); ?></a>
				<a href="<?php echo esc_url( add_query_arg( 'tab', 'settings', $url ) ); ?>" class="nav-tab <?php echo 'settings' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Settings', 'beaver-debug' ); ?></a>
			</nav>

			<?php
			if ( 'health' === $tab ) {
				self::render_health();
			} elseif ( 'upgrade' === $tab ) {
				self::render_upgrade();
			} elseif ( 'report' === $tab ) {
				self::render_report();
			} elseif ( 'settings' === $tab ) {
				self::render_settings();
			} else {
				self::render_problems( $url );
			}

			self::render_credit();
			?>
		</div>
		<?php
	}

	/**
	 * Renders the captured problems.
	 *
	 * @since 1.0.0
	 *
	 * @param string $url Base screen URL.
	 */
	private static function render_problems( $url ) {
		$groups  = Beaver_Debug_Logger::read( 100 );
		$summary = Beaver_Debug_Logger::summary( time() - DAY_IN_SECONDS );
		?>
		<div class="beaver-debug-cards">
			<?php
			foreach ( array(
				'fatal'   => __( 'Fatal errors', 'beaver-debug' ),
				'warning' => __( 'Warnings', 'beaver-debug' ),
				'js'      => __( 'Browser errors', 'beaver-debug' ),
				'db'      => __( 'Database errors', 'beaver-debug' ),
			) as $key => $label ) :
				?>
				<div class="beaver-debug-card beaver-debug-card--<?php echo esc_attr( $key ); ?>">
					<span class="beaver-debug-card__label"><?php echo esc_html( $label ); ?></span>
					<strong class="beaver-debug-card__value"><?php echo esc_html( number_format_i18n( $summary[ $key ] ) ); ?></strong>
					<span class="beaver-debug-card__hint"><?php esc_html_e( 'in the last 24 hours', 'beaver-debug' ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>

		<p class="beaver-debug-actions">
			<a class="button" href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'beaver_debug_action', 'download', $url ), self::NONCE_ACTION ) ); ?>"><?php esc_html_e( 'Download report', 'beaver-debug' ); ?></a>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'beaver_debug_action', 'clear', $url ), self::NONCE_ACTION ) ); ?>"
			   onclick="return window.confirm( '<?php echo esc_js( __( 'Delete everything recorded so far?', 'beaver-debug' ) ); ?>' );"><?php esc_html_e( 'Clear log', 'beaver-debug' ); ?></a>
			<span class="beaver-debug-size">
				<?php
				printf(
					/* translators: %s: log size. */
					esc_html__( 'Log size: %s', 'beaver-debug' ),
					esc_html( size_format( Beaver_Debug_Logger::size(), 1 ) )
				);
				?>
			</span>
		</p>

		<?php if ( empty( $groups ) ) : ?>
			<p class="beaver-debug-empty"><?php esc_html_e( 'Nothing has gone wrong since the log was last cleared. That is the result you want.', 'beaver-debug' ); ?></p>
		<?php else : ?>
			<table class="widefat striped beaver-debug-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'What happened', 'beaver-debug' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Where', 'beaver-debug' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Seen', 'beaver-debug' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $groups as $group ) : ?>
						<tr class="beaver-debug-row beaver-debug-row--<?php echo esc_attr( $group['severity'] ); ?>">
							<td>
								<span class="beaver-debug-badge beaver-debug-badge--<?php echo esc_attr( $group['severity'] ); ?>"><?php echo esc_html( $group['severity'] ); ?></span>
								<strong><?php echo esc_html( $group['message'] ); ?></strong>

								<?php if ( '' !== $group['file'] ) : ?>
									<code class="beaver-debug-file">
										<?php
										echo esc_html(
											str_replace( wp_normalize_path( ABSPATH ), '', wp_normalize_path( $group['file'] ) ) . ':' . $group['line']
										);
										?>
									</code>
								<?php endif; ?>

								<?php if ( ! empty( $group['trace'] ) ) : ?>
									<details class="beaver-debug-trace">
										<summary><?php esc_html_e( 'Backtrace', 'beaver-debug' ); ?></summary>
										<pre><?php echo esc_html( $group['trace'] ); ?></pre>
									</details>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( '' !== $group['source'] ) : ?>
									<strong><?php echo esc_html( $group['source'] ); ?></strong><br />
								<?php endif; ?>
								<?php if ( ! empty( $group['context']['where'] ) ) : ?>
									<span class="beaver-debug-context">
										<?php
										echo esc_html( $group['context']['where'] );

										if ( ! empty( $group['context']['action'] ) ) {
											echo ' — ' . esc_html( $group['context']['action'] );
										}
										?>
									</span>
								<?php endif; ?>
								<?php if ( ! empty( $group['context']['uri'] ) ) : ?>
									<span class="beaver-debug-uri"><?php echo esc_html( $group['context']['uri'] ); ?></span>
								<?php endif; ?>
								<?php if ( ! empty( $group['context']['memory'] ) ) : ?>
									<span class="beaver-debug-context">
										<?php
										printf(
											/* translators: 1: peak memory used, 2: configured memory limit. */
											esc_html__( 'peak %1$s of %2$s', 'beaver-debug' ),
											esc_html( size_format( (int) $group['context']['memory'] ) ),
											esc_html( (string) ( $group['context']['limit'] ?? '' ) )
										);
										?>
									</span>
								<?php endif; ?>
							</td>
							<td>
								<strong><?php echo esc_html( number_format_i18n( $group['count'] ) ); ?>&times;</strong><br />
								<span class="beaver-debug-context">
									<?php
									printf(
										/* translators: %s: human readable time difference. */
										esc_html__( '%s ago', 'beaver-debug' ),
										esc_html( human_time_diff( $group['last'], time() ) )
									);
									?>
								</span>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}

	/**
	 * Renders the environment checks.
	 *
	 * @since 1.0.0
	 */
	private static function render_health() {
		?>
		<p class="beaver-debug-lead"><?php esc_html_e( 'What this server actually is, without needing shell access to find out.', 'beaver-debug' ); ?></p>

		<table class="widefat striped beaver-debug-table">
			<tbody>
				<?php foreach ( Beaver_Debug_Health::checks() as $check ) : ?>
					<tr>
						<th scope="row" style="width:16em"><?php echo esc_html( $check['label'] ); ?></th>
						<td>
							<span class="beaver-debug-status beaver-debug-status--<?php echo esc_attr( $check['status'] ); ?>"></span>
							<strong><?php echo esc_html( $check['value'] ); ?></strong>
							<?php if ( '' !== $check['note'] ) : ?>
								<span class="beaver-debug-note"><?php echo esc_html( $check['note'] ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Renders the upgrade readiness view.
	 *
	 * @since 1.1.0
	 */
	private static function render_upgrade() {
		$rows = array();

		foreach ( Beaver_Debug_Logger::read( 300 ) as $group ) {
			if ( 'deprecated' !== $group['severity'] ) {
				continue;
			}

			$rows[ $group['source'] ][] = $group;
		}
		?>
		<p class="beaver-debug-lead">
			<?php esc_html_e( 'What will break when this host moves to the next PHP or WordPress release. Each entry is a plugin or theme still calling something that has been retired — it works today and will stop without warning.', 'beaver-debug' ); ?>
		</p>

		<?php if ( 'all' !== Beaver_Debug_Settings::get( 'level' ) ) : ?>
			<div class="notice notice-info inline"><p>
				<?php esc_html_e( 'Only some deprecations are recorded at the current level. Set Record to "Everything" for a full picture, then come back after browsing the site.', 'beaver-debug' ); ?>
			</p></div>
		<?php endif; ?>

		<?php if ( empty( $rows ) ) : ?>
			<p class="beaver-debug-empty"><?php esc_html_e( 'Nothing deprecated has been seen yet.', 'beaver-debug' ); ?></p>
		<?php else : ?>
			<?php foreach ( $rows as $source => $items ) : ?>
				<h2><?php echo esc_html( '' !== $source ? $source : __( 'unknown', 'beaver-debug' ) ); ?></h2>
				<table class="widefat striped beaver-debug-table">
					<tbody>
						<?php foreach ( $items as $item ) : ?>
							<tr>
								<td><?php echo esc_html( $item['message'] ); ?></td>
								<td style="width:6em"><?php echo esc_html( number_format_i18n( $item['count'] ) ); ?>&times;</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endforeach; ?>
		<?php endif; ?>
		<?php
	}

	/**
	 * Renders the shareable report.
	 *
	 * @since 1.0.0
	 */
	private static function render_report() {
		?>
		<p class="beaver-debug-lead">
			<?php esc_html_e( 'Everything someone needs to diagnose this site from a distance: the environment, and what has recently gone wrong. Copy it into an email or a chat instead of describing the problem from memory.', 'beaver-debug' ); ?>
		</p>

		<p class="beaver-debug-actions">
			<button type="button" class="button button-primary" id="beaver-debug-copy"><?php esc_html_e( 'Copy report', 'beaver-debug' ); ?></button>
			<span class="beaver-debug-copied" id="beaver-debug-copied"></span>
		</p>

		<textarea id="beaver-debug-report" class="large-text code" rows="24" readonly><?php echo esc_textarea( Beaver_Debug_Health::report( 20 ) ); ?></textarea>

		<p class="description"><?php esc_html_e( 'Query strings on outbound URLs are stripped before anything is recorded, so API keys do not end up in this report. Read it before sending it anywhere, as you would any other log.', 'beaver-debug' ); ?></p>
		<?php
	}

	/**
	 * Renders the settings form.
	 *
	 * @since 1.0.0
	 */
	private static function render_settings() {
		$settings = Beaver_Debug_Settings::all();
		$option   = Beaver_Debug_Settings::OPTION;
		?>
		<form method="post" action="options.php">
			<?php settings_fields( 'beaver_debug_group' ); ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Capture', 'beaver-debug' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( $option ); ?>[enabled]" value="1" <?php checked( ! empty( $settings['enabled'] ) ); ?> />
							<?php esc_html_e( 'Record errors as they happen', 'beaver-debug' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'Safe to leave on permanently. Nothing is ever shown to visitors, and the log lives in a protected folder with an unguessable name.', 'beaver-debug' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="beaver-debug-level"><?php esc_html_e( 'Record', 'beaver-debug' ); ?></label></th>
					<td>
						<select id="beaver-debug-level" name="<?php echo esc_attr( $option ); ?>[level]">
							<option value="fatal" <?php selected( $settings['level'], 'fatal' ); ?>><?php esc_html_e( 'Fatal errors only', 'beaver-debug' ); ?></option>
							<option value="warning" <?php selected( $settings['level'], 'warning' ); ?>><?php esc_html_e( 'Fatal errors and warnings', 'beaver-debug' ); ?></option>
							<option value="all" <?php selected( $settings['level'], 'all' ); ?>><?php esc_html_e( 'Everything, including notices', 'beaver-debug' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Warnings is the useful default. Notices on a WordPress site are mostly third-party noise, and they bury the events worth reading.', 'beaver-debug' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Outbound requests', 'beaver-debug' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( $option ); ?>[capture_http]" value="1" <?php checked( ! empty( $settings['capture_http'] ) ); ?> />
							<?php esc_html_e( 'Record failed calls to other services', 'beaver-debug' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'A plugin that cannot reach its API usually fails quietly rather than fatally. These are the events that explain "it just stopped working".', 'beaver-debug' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Browser errors', 'beaver-debug' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( $option ); ?>[capture_js]" value="1" <?php checked( ! empty( $settings['capture_js'] ) ); ?> />
							<?php esc_html_e( 'Record JavaScript errors from real visitors', 'beaver-debug' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'A broken slider or a script fighting jQuery never reaches PHP, so it appears in no server log. This is usually the only way to see it on a site you did not build. Reports are capped per page and per hour.', 'beaver-debug' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Database errors', 'beaver-debug' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( $option ); ?>[capture_db]" value="1" <?php checked( ! empty( $settings['capture_db'] ) ); ?> />
							<?php esc_html_e( 'Record failing queries', 'beaver-debug' ); ?>
						</label>
						<p class="description"><?php esc_html_e( 'A failing query usually does not stop the page — the feature that needed the data just silently does nothing.', 'beaver-debug' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="beaver-debug-slow"><?php esc_html_e( 'Slow pages', 'beaver-debug' ); ?></label></th>
					<td>
						<input type="number" id="beaver-debug-slow" class="small-text" min="0" max="60" step="1"
						       name="<?php echo esc_attr( $option ); ?>[slow_request]"
						       value="<?php echo esc_attr( (string) $settings['slow_request'] ); ?>" />
						<?php esc_html_e( 'seconds — 0 to ignore', 'beaver-debug' ); ?>
						<p class="description"><?php esc_html_e( 'Records any request that takes longer than this, with the URL. Turns "the site feels slow" into a page you can open.', 'beaver-debug' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="beaver-debug-retain"><?php esc_html_e( 'Keep for', 'beaver-debug' ); ?></label></th>
					<td>
						<input type="number" id="beaver-debug-retain" class="small-text" min="1" max="90"
						       name="<?php echo esc_attr( $option ); ?>[retain_days]"
						       value="<?php echo esc_attr( (string) $settings['retain_days'] ); ?>" />
						<?php esc_html_e( 'days', 'beaver-debug' ); ?>
					</td>
				</tr>
			</table>

			<h2 class="title"><?php esc_html_e( 'Tell me when it breaks', 'beaver-debug' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="beaver-debug-alert-on"><?php esc_html_e( 'Send an alert for', 'beaver-debug' ); ?></label></th>
					<td>
						<select id="beaver-debug-alert-on" name="<?php echo esc_attr( $option ); ?>[alert_on]">
							<option value="off" <?php selected( $settings['alert_on'], 'off' ); ?>><?php esc_html_e( 'Nothing — I will look myself', 'beaver-debug' ); ?></option>
							<option value="fatal" <?php selected( $settings['alert_on'], 'fatal' ); ?>><?php esc_html_e( 'Fatal errors', 'beaver-debug' ); ?></option>
							<option value="fatal_db" <?php selected( $settings['alert_on'], 'fatal_db' ); ?>><?php esc_html_e( 'Fatal and database errors', 'beaver-debug' ); ?></option>
							<option value="all" <?php selected( $settings['alert_on'], 'all' ); ?>><?php esc_html_e( 'Everything', 'beaver-debug' ); ?></option>
						</select>
						<p class="description"><?php esc_html_e( 'Each distinct problem is reported once per day, so a fatal inside a loop cannot flood you.', 'beaver-debug' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="beaver-debug-alert-email"><?php esc_html_e( 'Email', 'beaver-debug' ); ?></label></th>
					<td>
						<input type="email" class="regular-text" id="beaver-debug-alert-email"
						       name="<?php echo esc_attr( $option ); ?>[alert_email]"
						       placeholder="<?php echo esc_attr( (string) get_option( 'admin_email' ) ); ?>"
						       value="<?php echo esc_attr( (string) $settings['alert_email'] ); ?>" />
						<p class="description"><?php esc_html_e( 'Blank uses the site admin address. On an agency fleet, point this at yourself rather than the client.', 'beaver-debug' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="beaver-debug-alert-webhook"><?php esc_html_e( 'Webhook', 'beaver-debug' ); ?></label></th>
					<td>
						<input type="url" class="regular-text code" id="beaver-debug-alert-webhook"
						       name="<?php echo esc_attr( $option ); ?>[alert_webhook]"
						       value="<?php echo esc_attr( (string) $settings['alert_webhook'] ); ?>" />
						<p class="description"><?php esc_html_e( 'Optional. Posts JSON with a "text" field, which is what Slack and Discord incoming webhooks render without any setup.', 'beaver-debug' ); ?></p>
					</td>
				</tr>
			</table>

			<h2 class="title"><?php esc_html_e( 'Reading the log when the site is down', 'beaver-debug' ); ?></h2>
			<p class="description" style="max-width:46em">
				<?php esc_html_e( 'A fatal on every request takes wp-admin with it, and this screen stops being reachable exactly when you need it. The address below reads the log directly, without loading WordPress. Treat it as a password: anyone with the link can read the log.', 'beaver-debug' ); ?>
			</p>
			<p>
				<input type="text" class="large-text code" readonly onclick="this.select();"
				       value="<?php echo esc_attr( Beaver_Debug_Viewer::url() ); ?>" />
			</p>
			<p>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'beaver_debug_action', 'newtoken', admin_url( 'tools.php?page=' . self::MENU_SLUG ) ), self::NONCE_ACTION ) ); ?>"><?php esc_html_e( 'Issue a new address', 'beaver-debug' ); ?></a>
				<span class="beaver-debug-size"><?php esc_html_e( 'The old one stops working immediately.', 'beaver-debug' ); ?></span>
			</p>

			<h2 class="title"><?php esc_html_e( 'Fleet digest', 'beaver-debug' ); ?></h2>
			<p class="description" style="max-width:46em">
				<?php esc_html_e( 'Once a day, post a short summary of this site to one place, so a fleet can be checked from a single page instead of logging into each site.', 'beaver-debug' ); ?>
			</p>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="beaver-debug-hub"><?php esc_html_e( 'Send summaries to', 'beaver-debug' ); ?></label></th>
					<td>
						<input type="url" class="regular-text code" id="beaver-debug-hub"
						       name="<?php echo esc_attr( $option ); ?>[hub_url]"
						       value="<?php echo esc_attr( (string) $settings['hub_url'] ); ?>" />
						<p class="description"><?php esc_html_e( 'Leave blank to send nothing. No page content is included — counts, versions, and the messages of recent fatal errors only.', 'beaver-debug' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="beaver-debug-hubkey"><?php esc_html_e( 'Shared key', 'beaver-debug' ); ?></label></th>
					<td>
						<input type="text" class="regular-text code" id="beaver-debug-hubkey"
						       name="<?php echo esc_attr( $option ); ?>[hub_key]"
						       value="<?php echo esc_attr( (string) $settings['hub_key'] ); ?>" />
						<p class="description"><?php esc_html_e( 'Sent with each summary so your endpoint can reject anything else.', 'beaver-debug' ); ?></p>
					</td>
				</tr>
			</table>

			<?php submit_button(); ?>
		</form>

		<h2 class="title"><?php esc_html_e( 'Catching errors earlier', 'beaver-debug' ); ?></h2>
		<p class="description" style="max-width:46em">
			<?php esc_html_e( 'As a normal plugin this starts recording once plugins load, which misses anything that breaks before that. To catch those too, copy mu-loader/beaver-debug-loader.php into wp-content/mu-plugins/. It is one file and it loads first.', 'beaver-debug' ); ?>
		</p>
		<?php
	}

	/**
	 * Renders the maker's mark.
	 *
	 * @since 1.0.0
	 */
	private static function render_credit() {
		?>
		<div class="beaver-debug-credit">
			<img class="beaver-debug-credit__logo" width="300" height="152"
			     src="<?php echo esc_url( BEAVER_DEBUG_URL . 'assets/digital-beaver-logo.png' ); ?>"
			     alt="<?php esc_attr_e( 'Digital Beaver', 'beaver-debug' ); ?>" />
			<div class="beaver-debug-credit__text">
				<strong><?php esc_html_e( 'Designed & built by Digital Beaver', 'beaver-debug' ); ?></strong>
				<?php esc_html_e( 'Need a change, a new feature, or a site as fast as this one?', 'beaver-debug' ); ?>
				<a href="https://digitalbeavertz.com/" target="_blank" rel="noopener noreferrer">digitalbeavertz.com</a>
			</div>
		</div>
		<?php
	}
}
