<?php
/**
 * Admin screen.
 *
 * @package BeaverAccess
 */

defined( 'ABSPATH' ) || exit;

/**
 * Issuing, watching and revoking links.
 *
 * @since 1.0.0
 */
class Beaver_Access_Admin {

	const MENU_SLUG   = 'beaver-access';
	const NONCE       = 'beaver_access_action';
	const CAPABILITY  = 'promote_users';

	/**
	 * Fragment the Settings heading answers to.
	 *
	 * Settings are a section of this one screen rather than a page of their
	 * own, so the Plugins-row "Settings" shortcut has to land on the heading.
	 *
	 * @since 1.0.1
	 */
	const SETTINGS_ANCHOR = 'beaver-access-settings';

	/**
	 * Registers hooks.
	 *
	 * @since 1.0.0
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_actions' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );

		add_action( 'add_option_' . Beaver_Access_Settings::OPTION, array( 'Beaver_Access_Settings', 'flush' ) );
		add_action( 'update_option_' . Beaver_Access_Settings::OPTION, array( 'Beaver_Access_Settings', 'flush' ) );

		add_filter( 'user_row_actions', array( 'Beaver_Access_Users', 'row_actions' ), 10, 2 );
	}

	/**
	 * Adds the screen under Users.
	 *
	 * @since 1.0.0
	 */
	public static function register_menu() {
		add_users_page(
			__( 'Access Links', 'beaver-access' ),
			__( 'Access Links', 'beaver-access' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Registers settings.
	 *
	 * @since 1.0.0
	 */
	public static function register_settings() {
		register_setting(
			'beaver_access_group',
			Beaver_Access_Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'Beaver_Access_Settings', 'sanitize' ),
				'default'           => Beaver_Access_Settings::defaults(),
			)
		);
	}

	/**
	 * Loads assets on this screen only, so no other page pays for them.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook_suffix Current screen.
	 */
	public static function enqueue_assets( $hook_suffix ) {
		if ( false === strpos( (string) $hook_suffix, self::MENU_SLUG ) ) {
			return;
		}

		wp_enqueue_style( 'beaver-access-admin', BEAVER_ACCESS_URL . 'admin/css/admin.css', array(), BEAVER_ACCESS_VERSION );
		wp_enqueue_script( 'beaver-access-admin', BEAVER_ACCESS_URL . 'admin/js/admin.js', array(), BEAVER_ACCESS_VERSION, true );

		wp_localize_script(
			'beaver-access-admin',
			'beaverAccess',
			array(
				'copied'  => __( 'Copied.', 'beaver-access' ),
				'failed'  => __( 'Could not copy — select the link and copy it manually.', 'beaver-access' ),
				'confirm' => __( 'Revoke this link? Anyone signed in with it is signed straight out.', 'beaver-access' ),
				'all'     => __( 'Revoke every live link? Anyone currently signed in through one is signed out.', 'beaver-access' ),
			)
		);
	}

	/**
	 * Handles form posts and row actions.
	 *
	 * @since 1.0.0
	 */
	public static function handle_actions() {
		if ( ! isset( $_REQUEST['beaver_access_action'] ) || ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$action = sanitize_key( wp_unslash( $_REQUEST['beaver_access_action'] ) );
		$base   = admin_url( 'users.php?page=' . self::MENU_SLUG );

		check_admin_referer( self::NONCE );

		if ( 'create' === $action ) {
			$minutes = self::resolve_minutes();

			if ( is_wp_error( $minutes ) ) {
				wp_safe_redirect( add_query_arg( 'ba_error', rawurlencode( $minutes->get_error_message() ), $base ) );
				exit;
			}

			$link = Beaver_Access_Links::create(
				array(
					'label'       => isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '',
					'role'        => isset( $_POST['role'] ) ? sanitize_key( wp_unslash( $_POST['role'] ) ) : 'administrator',
					'target_user' => isset( $_POST['target_user'] ) ? absint( $_POST['target_user'] ) : 0,
					'minutes'     => $minutes,
					'max_uses'    => isset( $_POST['max_uses'] ) ? absint( $_POST['max_uses'] ) : 1,
					'lock_ip'     => ! empty( $_POST['lock_ip'] ),
				)
			);

			if ( is_wp_error( $link ) ) {
				wp_safe_redirect( add_query_arg( 'ba_error', rawurlencode( $link->get_error_message() ), $base ) );
				exit;
			}

			/*
			 * The full link exists only in this response. Handing it back
			 * through a transient keyed to the person who made it avoids
			 * putting it in the address bar, where it would land in history
			 * and server access logs.
			 */
			set_transient( 'beaver_access_new_' . get_current_user_id(), $link->url, 5 * MINUTE_IN_SECONDS );

			wp_safe_redirect( add_query_arg( 'ba_created', '1', $base ) );
			exit;
		}

		if ( 'revoke' === $action ) {
			Beaver_Access_Links::revoke( isset( $_REQUEST['id'] ) ? absint( $_REQUEST['id'] ) : 0 );

			wp_safe_redirect( add_query_arg( 'ba_revoked', '1', $base ) );
			exit;
		}

		if ( 'revoke_all' === $action ) {
			$count = Beaver_Access_Links::revoke_all();

			wp_safe_redirect( add_query_arg( 'ba_revoked', (int) $count, $base ) );
			exit;
		}

		if ( 'clear_log' === $action ) {
			Beaver_Access_Log::clear();

			wp_safe_redirect( $base );
			exit;
		}
	}

	/*
	 * -----------------------------------------------------------------------
	 * Expiry
	 * -----------------------------------------------------------------------
	 */

	/**
	 * The lengths offered without typing anything.
	 *
	 * @since 1.0.2
	 *
	 * @return array<int,string> Minutes => label.
	 */
	private static function duration_presets() {
		return array(
			15    => __( '15 minutes', 'beaver-access' ),
			60    => __( '1 hour', 'beaver-access' ),
			480   => __( '8 hours', 'beaver-access' ),
			1440  => __( '24 hours', 'beaver-access' ),
			4320  => __( '3 days', 'beaver-access' ),
			10080 => __( '7 days', 'beaver-access' ),
		);
	}

	/**
	 * Units a custom length can be entered in.
	 *
	 * @since 1.0.2
	 *
	 * @return array<string,string> Unit => label.
	 */
	private static function duration_units() {
		return array(
			'minutes' => __( 'minutes', 'beaver-access' ),
			'hours'   => __( 'hours', 'beaver-access' ),
			'days'    => __( 'days', 'beaver-access' ),
		);
	}

	/**
	 * Minutes in one of each unit.
	 *
	 * @since 1.0.2
	 *
	 * @return array<string,int>
	 */
	private static function unit_minutes() {
		return array(
			'minutes' => 1,
			'hours'   => 60,
			'days'    => 1440,
		);
	}

	/**
	 * Expresses minutes in the largest unit that divides them exactly.
	 *
	 * Used to prefill the custom fields, so a default of 2880 opens as "2 days"
	 * rather than "2880 minutes".
	 *
	 * @since 1.0.2
	 *
	 * @param int $minutes Minutes, or 0 for the empty default.
	 * @return array{value:int,unit:string}
	 */
	private static function split_minutes( $minutes ) {
		$minutes = (int) $minutes;

		if ( $minutes < 1 ) {
			return array(
				'value' => 2,
				'unit'  => 'hours',
			);
		}

		foreach ( array( 'days', 'hours' ) as $unit ) {
			$size = self::unit_minutes()[ $unit ];

			if ( 0 === $minutes % $size ) {
				return array(
					'value' => (int) ( $minutes / $size ),
					'unit'  => $unit,
				);
			}
		}

		return array(
			'value' => $minutes,
			'unit'  => 'minutes',
		);
	}

	/**
	 * Renders a number of minutes in plain words.
	 *
	 * @since 1.0.2
	 *
	 * @param int $minutes Minutes.
	 * @return string
	 */
	private static function describe_minutes( $minutes ) {
		$parts = self::split_minutes( $minutes );
		$value = $parts['value'];

		if ( 'days' === $parts['unit'] ) {
			/* translators: %s: number of days. */
			return sprintf( _n( '%s day', '%s days', $value, 'beaver-access' ), number_format_i18n( $value ) );
		}

		if ( 'hours' === $parts['unit'] ) {
			/* translators: %s: number of hours. */
			return sprintf( _n( '%s hour', '%s hours', $value, 'beaver-access' ), number_format_i18n( $value ) );
		}

		/* translators: %s: number of minutes. */
		return sprintf( _n( '%s minute', '%s minutes', $value, 'beaver-access' ), number_format_i18n( $value ) );
	}

	/**
	 * Works out how long the submitted form wants the link to last.
	 *
	 * The three routes all end in the same number of minutes: a preset, a
	 * length typed in a unit of your choosing, or a moment on the clock.
	 *
	 * Out of range values are reported rather than quietly clamped. Somebody
	 * who asks for 90 days needs to know they did not get 90 days.
	 *
	 * Called from handle_actions(), which has already checked the nonce and the
	 * capability.
	 *
	 * @since 1.0.2
	 *
	 * @return int|WP_Error Minutes, or an error to show above the form.
	 */
	private static function resolve_minutes() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$choice = isset( $_POST['minutes'] ) ? sanitize_key( wp_unslash( $_POST['minutes'] ) ) : '';

		if ( 'custom' === $choice ) {
			$value = isset( $_POST['custom_value'] ) ? absint( $_POST['custom_value'] ) : 0;
			$unit  = isset( $_POST['custom_unit'] ) ? sanitize_key( wp_unslash( $_POST['custom_unit'] ) ) : '';
			$sizes = self::unit_minutes();

			if ( $value < 1 || ! isset( $sizes[ $unit ] ) ) {
				return new WP_Error(
					'beaver_access_bad_length',
					__( 'Enter how long the link should last.', 'beaver-access' )
				);
			}

			return self::within_bounds( $value * $sizes[ $unit ] );
		}

		if ( 'until' === $choice ) {
			$raw = isset( $_POST['custom_until'] ) ? sanitize_text_field( wp_unslash( $_POST['custom_until'] ) ) : '';

			if ( '' === $raw ) {
				return new WP_Error(
					'beaver_access_no_time',
					__( 'Choose the date and time the link should stop working.', 'beaver-access' )
				);
			}

			// The field carries no time zone, so it is read as the site's own,
			// which is the clock everything else on this screen is shown on.
			$when = date_create_immutable( $raw, wp_timezone() );

			if ( ! $when ) {
				return new WP_Error(
					'beaver_access_bad_time',
					__( 'That date and time could not be read.', 'beaver-access' )
				);
			}

			return self::within_bounds( (int) ceil( ( $when->getTimestamp() - time() ) / MINUTE_IN_SECONDS ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$preset = (int) $choice;

		if ( isset( self::duration_presets()[ $preset ] ) ) {
			return $preset;
		}

		return (int) Beaver_Access_Settings::all()['default_mins'];
	}

	/**
	 * Checks a length against the limits a link may be issued within.
	 *
	 * @since 1.0.2
	 *
	 * @param int $minutes Minutes.
	 * @return int|WP_Error
	 */
	private static function within_bounds( $minutes ) {
		$minutes = (int) $minutes;

		if ( $minutes < Beaver_Access_Links::MIN_MINUTES ) {
			return new WP_Error(
				'beaver_access_too_short',
				sprintf(
					/* translators: %s: the shortest life a link may be given. */
					__( 'A link has to last at least %s, or it expires before it can be sent and used. A time already past does the same.', 'beaver-access' ),
					self::describe_minutes( Beaver_Access_Links::MIN_MINUTES )
				)
			);
		}

		if ( $minutes > Beaver_Access_Links::MAX_MINUTES ) {
			return new WP_Error(
				'beaver_access_too_long',
				sprintf(
					/* translators: %s: the longest life a link may be given. */
					__( 'A link cannot last longer than %s. Anything standing open beyond that is not temporary access; create a real account instead.', 'beaver-access' ),
					self::describe_minutes( Beaver_Access_Links::MAX_MINUTES )
				)
			);
		}

		return $minutes;
	}

	/**
	 * Renders the screen.
	 *
	 * @since 1.0.0
	 */
	public static function render() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to view this page.', 'beaver-access' ) );
		}

		$settings = Beaver_Access_Settings::all();
		$base     = admin_url( 'users.php?page=' . self::MENU_SLUG );
		$fresh    = get_transient( 'beaver_access_new_' . get_current_user_id() );

		if ( $fresh ) {
			delete_transient( 'beaver_access_new_' . get_current_user_id() );
		}
		?>
		<div class="wrap beaver-access">
			<h1><?php esc_html_e( 'Access Links', 'beaver-access' ); ?></h1>
			<p class="beaver-access-lead">
				<?php esc_html_e( 'Give someone temporary access without sharing a password. Each link signs in as a throwaway account with the role you choose, expires on its own, and can be revoked instantly — which also signs out anyone using it.', 'beaver-access' ); ?>
			</p>

			<?php if ( ! is_ssl() ) : ?>
				<div class="notice notice-warning"><p>
					<?php esc_html_e( 'This site is not using HTTPS. A link contains its own key, so on a plain HTTP connection anyone able to watch the traffic can read it and use it. Links are refused over HTTP unless you turn that requirement off in the settings below.', 'beaver-access' ); ?>
				</p></div>
			<?php endif; ?>

			<?php if ( isset( $_GET['ba_error'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-error"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['ba_error'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?></p></div>
			<?php endif; ?>

			<?php if ( isset( $_GET['ba_revoked'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Revoked.', 'beaver-access' ); ?></p></div>
			<?php endif; ?>

			<?php if ( $fresh ) : ?>
				<div class="beaver-access-fresh">
					<h2><?php esc_html_e( 'Your link is ready', 'beaver-access' ); ?></h2>
					<p><?php esc_html_e( 'Copy it now — it is shown once and never stored, so this screen cannot show it to you again. Send it the way you would send a password.', 'beaver-access' ); ?></p>
					<div class="beaver-access-copyrow">
						<input type="text" id="beaver-access-url" class="large-text code" readonly value="<?php echo esc_attr( $fresh ); ?>" onclick="this.select();" />
						<button type="button" class="button button-primary" id="beaver-access-copy"><?php esc_html_e( 'Copy', 'beaver-access' ); ?></button>
						<span id="beaver-access-copied" class="beaver-access-note"></span>
					</div>
				</div>
			<?php endif; ?>

			<div class="beaver-access-columns">
				<div class="beaver-access-new">
					<h2><?php esc_html_e( 'New link', 'beaver-access' ); ?></h2>
					<form method="post" action="<?php echo esc_url( $base ); ?>">
						<?php wp_nonce_field( self::NONCE ); ?>
						<input type="hidden" name="beaver_access_action" value="create" />

						<p>
							<label for="ba-label"><strong><?php esc_html_e( 'What is it for?', 'beaver-access' ); ?></strong></label>
							<input type="text" id="ba-label" name="label" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. Digital Beaver — checkout bug', 'beaver-access' ); ?>" />
						</p>

						<p>
							<label for="ba-role"><strong><?php esc_html_e( 'Role to grant', 'beaver-access' ); ?></strong></label>
							<select id="ba-role" name="role">
								<?php
								foreach ( self::grantable_roles() as $slug => $name ) {
									printf(
										'<option value="%s"%s>%s</option>',
										esc_attr( $slug ),
										selected( $settings['default_role'], $slug, false ),
										esc_html( $name )
									);
								}
								?>
							</select>
							<span class="beaver-access-note"><?php esc_html_e( 'Only roles you could grant yourself are listed.', 'beaver-access' ); ?></span>
						</p>

						<p>
							<label for="ba-user"><strong><?php esc_html_e( 'Or sign in as an existing user', 'beaver-access' ); ?></strong></label>
							<select id="ba-user" name="target_user">
								<option value="0"><?php esc_html_e( '— create a temporary account instead —', 'beaver-access' ); ?></option>
								<?php
								foreach ( get_users( array( 'number' => 50, 'fields' => array( 'ID', 'display_name', 'user_login' ) ) ) as $user ) {
									printf(
										'<option value="%d">%s (%s)</option>',
										(int) $user->ID,
										esc_html( $user->display_name ),
										esc_html( $user->user_login )
									);
								}
								?>
							</select>
							<span class="beaver-access-note"><?php esc_html_e( 'Use sparingly: actions are then indistinguishable from that person\'s own.', 'beaver-access' ); ?></span>
						</p>

						<?php
						$presets = self::duration_presets();
						$default = (int) $settings['default_mins'];

						// A default that is not one of the presets, which the CLI
						// and the filter can both produce, opens on Custom with
						// its own value rather than silently showing the wrong one.
						$is_custom = ! isset( $presets[ $default ] );
						$custom    = self::split_minutes( $is_custom ? $default : 0 );
						?>
						<p>
							<label for="ba-minutes"><strong><?php esc_html_e( 'Expires after', 'beaver-access' ); ?></strong></label>
							<select id="ba-minutes" name="minutes">
								<?php
								foreach ( $presets as $mins => $name ) {
									printf(
										'<option value="%d"%s>%s</option>',
										(int) $mins,
										selected( $default, (int) $mins, false ),
										esc_html( $name )
									);
								}
								?>
								<option value="custom" <?php selected( $is_custom ); ?>><?php esc_html_e( 'Custom length', 'beaver-access' ); ?></option>
								<option value="until"><?php esc_html_e( 'Until a specific time', 'beaver-access' ); ?></option>
							</select>
						</p>

						<p class="beaver-access-custom" id="ba-custom-length" <?php echo $is_custom ? '' : 'hidden'; ?>>
							<label for="ba-custom-value"><strong><?php esc_html_e( 'How long', 'beaver-access' ); ?></strong></label>
							<span class="beaver-access-duration">
								<input type="number" id="ba-custom-value" name="custom_value" class="small-text" min="1" max="<?php echo esc_attr( (string) Beaver_Access_Links::MAX_MINUTES ); ?>" value="<?php echo esc_attr( (string) $custom['value'] ); ?>" />
								<select id="ba-custom-unit" name="custom_unit">
									<?php
									foreach ( self::duration_units() as $unit => $unit_label ) {
										printf(
											'<option value="%s"%s>%s</option>',
											esc_attr( $unit ),
											selected( $custom['unit'], $unit, false ),
											esc_html( $unit_label )
										);
									}
									?>
								</select>
							</span>
							<span class="beaver-access-note">
								<?php
								printf(
									/* translators: 1: shortest allowed life, 2: longest allowed life. */
									esc_html__( 'Anything from %1$s to %2$s.', 'beaver-access' ),
									esc_html( self::describe_minutes( Beaver_Access_Links::MIN_MINUTES ) ),
									esc_html( self::describe_minutes( Beaver_Access_Links::MAX_MINUTES ) )
								);
								?>
							</span>
						</p>

						<p class="beaver-access-custom" id="ba-custom-until" hidden>
							<label for="ba-custom-until-value"><strong><?php esc_html_e( 'Stops working at', 'beaver-access' ); ?></strong></label>
							<input
								type="datetime-local"
								id="ba-custom-until-value"
								name="custom_until"
								value="<?php echo esc_attr( wp_date( 'Y-m-d\TH:i', time() + ( $default * MINUTE_IN_SECONDS ) ) ); ?>"
								min="<?php echo esc_attr( wp_date( 'Y-m-d\TH:i', time() + ( Beaver_Access_Links::MIN_MINUTES * MINUTE_IN_SECONDS ) ) ); ?>"
								max="<?php echo esc_attr( wp_date( 'Y-m-d\TH:i', time() + ( Beaver_Access_Links::MAX_MINUTES * MINUTE_IN_SECONDS ) ) ); ?>"
							/>
							<span class="beaver-access-note">
								<?php
								printf(
									/* translators: 1: site time zone, 2: the current time on the site. */
									esc_html__( 'Site time (%1$s), where it is now %2$s. Say so if the person is in another time zone.', 'beaver-access' ),
									esc_html( wp_timezone_string() ),
									esc_html( wp_date( (string) get_option( 'time_format' ) ) )
								);
								?>
							</span>
						</p>

						<p>
							<label for="ba-uses"><strong><?php esc_html_e( 'Times it can be used', 'beaver-access' ); ?></strong></label>
							<input type="number" id="ba-uses" name="max_uses" class="small-text" min="1" max="100" value="<?php echo esc_attr( (string) $settings['default_uses'] ); ?>" />
						</p>

						<p>
							<label>
								<input type="checkbox" name="lock_ip" value="1" />
								<?php esc_html_e( 'Lock to the first address that uses it', 'beaver-access' ); ?>
							</label>
							<span class="beaver-access-note"><?php esc_html_e( 'Stops a forwarded link working from anywhere else. Avoid for people on mobile connections.', 'beaver-access' ); ?></span>
						</p>

						<?php submit_button( __( 'Create link', 'beaver-access' ) ); ?>
					</form>
				</div>

				<div class="beaver-access-list">
					<h2><?php esc_html_e( 'Links', 'beaver-access' ); ?></h2>
					<?php self::render_links( $base ); ?>

					<h2><?php esc_html_e( 'Activity', 'beaver-access' ); ?></h2>
					<?php self::render_log(); ?>
				</div>
			</div>

			<h2 class="title" id="<?php echo esc_attr( self::SETTINGS_ANCHOR ); ?>"><?php esc_html_e( 'Settings', 'beaver-access' ); ?></h2>
			<form method="post" action="options.php">
				<?php settings_fields( 'beaver_access_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Require HTTPS', 'beaver-access' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( Beaver_Access_Settings::OPTION ); ?>[require_ssl]" value="1" <?php checked( ! empty( $settings['require_ssl'] ) ); ?> />
								<?php esc_html_e( 'Refuse links on plain HTTP', 'beaver-access' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Leave on. A link carries its own key in the address, so over HTTP it is readable by anything between the browser and the server.', 'beaver-access' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Notify', 'beaver-access' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( Beaver_Access_Settings::OPTION ); ?>[notify_admin]" value="1" <?php checked( ! empty( $settings['notify_admin'] ) ); ?> />
								<?php esc_html_e( 'Email the site administrator whenever a link is used', 'beaver-access' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Transparency for the client, and an early warning for you if a link is used when you did not expect it.', 'beaver-access' ); ?></p>
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
	 * Roles the current user is allowed to hand out.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string,string> Slug => name.
	 */
	private static function grantable_roles() {
		$roles = array();

		foreach ( wp_roles()->get_names() as $slug => $name ) {
			if ( Beaver_Access_Links::can_grant( $slug ) ) {
				$roles[ $slug ] = translate_user_role( $name );
			}
		}

		return $roles;
	}

	/**
	 * Renders the links table.
	 *
	 * @since 1.0.0
	 *
	 * @param string $base Screen URL.
	 */
	private static function render_links( $base ) {
		$links = Beaver_Access_Links::all( 50 );

		if ( empty( $links ) ) {
			echo '<p class="beaver-access-empty">' . esc_html__( 'No links yet.', 'beaver-access' ) . '</p>';

			return;
		}
		?>
		<p>
			<a class="button" id="beaver-access-revoke-all"
			   href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'beaver_access_action', 'revoke_all', $base ), self::NONCE ) ); ?>">
				<?php esc_html_e( 'Revoke every live link', 'beaver-access' ); ?>
			</a>
		</p>
		<table class="widefat striped beaver-access-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Link', 'beaver-access' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'beaver-access' ); ?></th>
					<th scope="col"></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $links as $link ) : ?>
					<?php
					$expired = strtotime( $link->expires_at . ' UTC' ) < time();
					$revoked = ! empty( $link->revoked_at );
					$spent   = (int) $link->used >= (int) $link->max_uses;
					$live    = ! $expired && ! $revoked && ! $spent;
					?>
					<tr>
						<td>
							<strong><?php echo esc_html( '' !== $link->label ? $link->label : __( '(no label)', 'beaver-access' ) ); ?></strong>
							<span class="beaver-access-note">
								<?php
								if ( (int) $link->target_user > 0 ) {
									$user = get_userdata( (int) $link->target_user );

									printf(
										/* translators: %s: user login. */
										esc_html__( 'signs in as %s', 'beaver-access' ),
										esc_html( $user ? $user->user_login : __( 'a deleted user', 'beaver-access' ) )
									);
								} else {
									printf(
										/* translators: %s: role name. */
										esc_html__( 'temporary %s', 'beaver-access' ),
										esc_html( $link->role )
									);
								}
								?>
								·
								<?php
								printf(
									/* translators: 1: times used, 2: times allowed. */
									esc_html__( 'used %1$d of %2$d', 'beaver-access' ),
									(int) $link->used,
									(int) $link->max_uses
								);
								?>
								<?php if ( (int) $link->lock_ip ) : ?>
									· <?php echo esc_html( '' !== $link->bound_ip ? sprintf( 'locked to %s', $link->bound_ip ) : 'locks on first use' ); ?>
								<?php endif; ?>
							</span>
						</td>
						<td>
							<?php if ( $revoked ) : ?>
								<span class="beaver-access-badge beaver-access-badge--dead"><?php esc_html_e( 'revoked', 'beaver-access' ); ?></span>
							<?php elseif ( $expired ) : ?>
								<span class="beaver-access-badge beaver-access-badge--dead"><?php esc_html_e( 'expired', 'beaver-access' ); ?></span>
							<?php elseif ( $spent ) : ?>
								<span class="beaver-access-badge beaver-access-badge--dead"><?php esc_html_e( 'used up', 'beaver-access' ); ?></span>
							<?php else : ?>
								<span class="beaver-access-badge beaver-access-badge--live"><?php esc_html_e( 'live', 'beaver-access' ); ?></span>
								<span class="beaver-access-note">
									<?php
									printf(
										/* translators: %s: time until expiry. */
										esc_html__( 'expires in %s', 'beaver-access' ),
										esc_html( human_time_diff( time(), strtotime( $link->expires_at . ' UTC' ) ) )
									);
									?>
								</span>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( $live || ( ! $revoked && (int) $link->temp_user > 0 ) ) : ?>
								<a class="button button-small beaver-access-revoke"
								   href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'beaver_access_action' => 'revoke', 'id' => (int) $link->id ), $base ), self::NONCE ) ); ?>">
									<?php esc_html_e( 'Revoke', 'beaver-access' ); ?>
								</a>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Renders the audit log.
	 *
	 * @since 1.0.0
	 */
	private static function render_log() {
		$entries = Beaver_Access_Log::all( 30 );

		if ( empty( $entries ) ) {
			echo '<p class="beaver-access-empty">' . esc_html__( 'Nothing yet.', 'beaver-access' ) . '</p>';

			return;
		}
		?>
		<table class="widefat striped beaver-access-table">
			<tbody>
				<?php foreach ( $entries as $entry ) : ?>
					<tr>
						<td style="width:10em">
							<span class="beaver-access-badge beaver-access-badge--<?php echo esc_attr( 'denied' === $entry['event'] ? 'dead' : 'live' ); ?>"><?php echo esc_html( $entry['event'] ); ?></span>
						</td>
						<td>
							<?php
							$link = $entry['link'] > 0 ? Beaver_Access_Links::get( (int) $entry['link'] ) : null;

							echo esc_html( $link && '' !== $link->label ? $link->label : __( '(link removed)', 'beaver-access' ) );

							if ( '' !== $entry['detail'] ) {
								echo ' <code>' . esc_html( $entry['detail'] ) . '</code>';
							}
							?>
							<span class="beaver-access-note">
								<?php echo esc_html( $entry['ip'] ); ?>
								<?php if ( '' !== $entry['agent'] ) : ?>
									· <?php echo esc_html( mb_substr( $entry['agent'], 0, 60 ) ); ?>
								<?php endif; ?>
							</span>
						</td>
						<td style="width:9em">
							<?php
							printf(
								/* translators: %s: time difference. */
								esc_html__( '%s ago', 'beaver-access' ),
								esc_html( human_time_diff( (int) $entry['time'], time() ) )
							);
							?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Renders the maker's mark.
	 *
	 * @since 1.0.0
	 */
	private static function render_credit() {
		?>
		<div class="beaver-access-credit">
			<img class="beaver-access-credit__logo" width="300" height="152"
			     src="<?php echo esc_url( BEAVER_ACCESS_URL . 'assets/digital-beaver-logo.png' ); ?>"
			     alt="<?php esc_attr_e( 'Digital Beaver', 'beaver-access' ); ?>" />
			<div class="beaver-access-credit__text">
				<strong><?php esc_html_e( 'Designed & built by Digital Beaver', 'beaver-access' ); ?></strong>
				<?php esc_html_e( 'Need a change, a new feature, or a site as fast as this one?', 'beaver-access' ); ?>
				<a href="https://digitalbeavertz.com/" target="_blank" rel="noopener noreferrer">digitalbeavertz.com</a>
			</div>
		</div>
		<?php
	}
}
