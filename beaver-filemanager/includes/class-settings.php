<?php
/**
 * Settings storage, sanitization and the Settings screen.
 *
 * @package BeaverFileManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Owns the single settings option and every policy question derived from it.
 *
 * The rest of the plugin never reads the option directly — it asks this class
 * questions like "may I write?" so the safety rules live in exactly one place.
 *
 * @since 1.0.0
 */
class Beaver_FM_Settings {

	const OPTION       = 'beaver_fm_settings';
	const GROUP        = 'beaver_fm_settings_group';
	const CAPABILITY   = 'manage_options';

	/**
	 * Runtime cache of the merged settings.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Registers the option with the Settings API.
	 *
	 * @since 1.0.0
	 */
	public static function register() {
		register_setting(
			self::GROUP,
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	/**
	 * Default settings.
	 *
	 * The defaults are deliberately the cautious end of every choice: the site
	 * root is browsable, but backups and the trash are on and a site that has
	 * `DISALLOW_FILE_EDIT` set stays read-only until somebody opts out here.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'root'               => 'abspath',
			'custom_root'        => '',
			'readonly'           => 0,
			'override_disallow'  => 0,
			'use_trash'          => 1,
			'backups'            => 1,
			'backup_keep'        => 10,
			'lint_php'           => 1,
			'show_hidden'        => 1,
			'max_edit_mb'        => 4,
			'max_upload_mb'      => 0,
			'blocked_extensions' => '',
			'search_max_files'   => 20000,
			'log_enabled'        => 1,
			'log_limit'          => 300,
		);
	}

	/**
	 * Retrieves the settings, merged over the defaults.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public static function get() {
		if ( null === self::$cache ) {
			$stored = get_option( self::OPTION, array() );
			$stored = is_array( $stored ) ? $stored : array();

			self::$cache = array_merge( self::defaults(), $stored );
		}

		return self::$cache;
	}

	/**
	 * Retrieves a single setting.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Value returned when the key is unknown.
	 * @return mixed
	 */
	public static function value( $key, $default = null ) {
		$settings = self::get();

		return array_key_exists( $key, $settings ) ? $settings[ $key ] : $default;
	}

	/**
	 * Sanitizes the submitted settings.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $input Raw form input.
	 * @return array Clean settings.
	 */
	public static function sanitize( $input ) {
		self::$cache = null;

		$input    = is_array( $input ) ? $input : array();
		$defaults = self::defaults();
		$clean    = $defaults;

		$roots = array( 'abspath', 'wp-content', 'uploads', 'custom' );

		if ( isset( $input['root'] ) && in_array( $input['root'], $roots, true ) ) {
			$clean['root'] = $input['root'];
		}

		if ( isset( $input['custom_root'] ) ) {
			$custom = wp_normalize_path( trim( (string) $input['custom_root'] ) );
			$custom = str_replace( "\0", '', $custom );
			$real   = '' === $custom ? false : realpath( $custom );

			if ( $real && is_dir( $real ) ) {
				$clean['custom_root'] = untrailingslashit( wp_normalize_path( $real ) );
			} else {
				$clean['custom_root'] = '';

				if ( '' !== $custom ) {
					/*
					 * This callback also runs for any update_option() call, and
					 * outside the Settings screen wp-admin's template functions
					 * are not loaded — so check before reaching for one.
					 */
					if ( function_exists( 'add_settings_error' ) ) {
						add_settings_error(
							self::OPTION,
							'beaver_fm_bad_root',
							__( 'That custom root is not a directory this server can reach, so the root was left unchanged.', 'beaver-filemanager' ),
							'error'
						);
					}

					$clean['root'] = 'abspath';
				}
			}
		}

		if ( 'custom' === $clean['root'] && '' === $clean['custom_root'] ) {
			$clean['root'] = 'abspath';
		}

		foreach ( array( 'readonly', 'override_disallow', 'use_trash', 'backups', 'lint_php', 'show_hidden', 'log_enabled' ) as $flag ) {
			$clean[ $flag ] = empty( $input[ $flag ] ) ? 0 : 1;
		}

		$clean['backup_keep']      = min( 100, max( 1, absint( $input['backup_keep'] ?? $defaults['backup_keep'] ) ) );
		$clean['max_edit_mb']      = min( 64, max( 1, absint( $input['max_edit_mb'] ?? $defaults['max_edit_mb'] ) ) );
		$clean['max_upload_mb']    = min( 4096, absint( $input['max_upload_mb'] ?? $defaults['max_upload_mb'] ) );
		$clean['search_max_files'] = min( 500000, max( 100, absint( $input['search_max_files'] ?? $defaults['search_max_files'] ) ) );
		$clean['log_limit']        = min( 5000, max( 20, absint( $input['log_limit'] ?? $defaults['log_limit'] ) ) );

		$blocked = isset( $input['blocked_extensions'] ) ? (string) $input['blocked_extensions'] : '';
		$blocked = preg_replace( '/[^a-z0-9,\s.]/i', '', $blocked );
		$parts   = preg_split( '/[\s,]+/', strtolower( $blocked ), -1, PREG_SPLIT_NO_EMPTY );

		$clean['blocked_extensions'] = implode( ', ', array_unique( array_map( static function ( $ext ) {
			return ltrim( $ext, '.' );
		}, (array) $parts ) ) );

		return $clean;
	}

	/**
	 * The capability required to use the file manager.
	 *
	 * Filterable so a site can hand the manager to a narrower role, but never
	 * below `manage_options` by accident — the filter is the deliberate act.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public static function capability() {
		/**
		 * Filters the capability required to use Beaver FileManager.
		 *
		 * @since 1.0.0
		 *
		 * @param string $capability Capability name.
		 */
		$capability = apply_filters( 'beaver_fm_capability', self::CAPABILITY );

		return is_string( $capability ) && '' !== $capability ? $capability : self::CAPABILITY;
	}

	/**
	 * Whether the current user may use the file manager at all.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public static function user_can_browse() {
		return current_user_can( self::capability() );
	}

	/**
	 * Whether `DISALLOW_FILE_EDIT` is blocking writes.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public static function blocked_by_constant() {
		return defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT && ! self::value( 'override_disallow' );
	}

	/**
	 * Whether any write operation is permitted right now.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public static function can_write() {
		if ( ! self::user_can_browse() ) {
			return false;
		}

		if ( self::value( 'readonly' ) ) {
			return false;
		}

		if ( self::blocked_by_constant() ) {
			return false;
		}

		/**
		 * Filters whether write operations are allowed.
		 *
		 * @since 1.0.0
		 *
		 * @param bool $allowed Whether writes are allowed.
		 */
		return (bool) apply_filters( 'beaver_fm_can_write', true );
	}

	/**
	 * Explains, in one sentence, why writing is currently refused.
	 *
	 * @since 1.0.0
	 *
	 * @return string Empty string when writing is allowed.
	 */
	public static function write_block_reason() {
		if ( self::can_write() ) {
			return '';
		}

		if ( ! self::user_can_browse() ) {
			return __( 'Your account cannot manage files on this site.', 'beaver-filemanager' );
		}

		if ( self::value( 'readonly' ) ) {
			return __( 'Beaver FileManager is in read-only mode. Turn it off in Settings to make changes.', 'beaver-filemanager' );
		}

		if ( self::blocked_by_constant() ) {
			return __( 'This site defines DISALLOW_FILE_EDIT in wp-config.php. Allow it in Settings if you want to edit files anyway.', 'beaver-filemanager' );
		}

		return __( 'Writing is disabled on this site.', 'beaver-filemanager' );
	}

	/**
	 * File extensions that may never be uploaded.
	 *
	 * @since 1.0.0
	 *
	 * @return string[]
	 */
	public static function blocked_extensions() {
		$raw = (string) self::value( 'blocked_extensions', '' );

		return preg_split( '/[\s,]+/', strtolower( $raw ), -1, PREG_SPLIT_NO_EMPTY ) ?: array();
	}

	/**
	 * The largest file the editor will open, in bytes.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public static function max_edit_bytes() {
		return absint( self::value( 'max_edit_mb', 4 ) ) * MB_IN_BYTES;
	}

	/**
	 * The largest upload accepted, in bytes, clamped to what PHP will take.
	 *
	 * @since 1.0.0
	 *
	 * @return int
	 */
	public static function max_upload_bytes() {
		$server    = (int) wp_max_upload_size();
		$configured = absint( self::value( 'max_upload_mb', 0 ) ) * MB_IN_BYTES;

		if ( $server <= 0 ) {
			return $configured;
		}

		if ( $configured <= 0 ) {
			return $server;
		}

		return min( $server, $configured );
	}

	/*
	 * -----------------------------------------------------------------------
	 * Settings screen
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Renders the Settings screen.
	 *
	 * @since 1.0.0
	 */
	public static function render() {
		if ( ! self::user_can_browse() ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'beaver-filemanager' ) );
		}

		$settings = self::get();
		$root     = Beaver_FM_Filesystem::root_path();
		$name     = static function ( $key ) {
			return esc_attr( self::OPTION . '[' . $key . ']' );
		};

		?>
		<div class="wrap beaver-fm-settings">
			<h1><?php esc_html_e( 'Beaver FileManager Settings', 'beaver-filemanager' ); ?></h1>

			<?php settings_errors( self::OPTION ); ?>

			<?php if ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) : ?>
				<div class="notice notice-warning inline">
					<p>
						<strong><?php esc_html_e( 'This site defines DISALLOW_FILE_EDIT in wp-config.php.', 'beaver-filemanager' ); ?></strong>
						<?php esc_html_e( 'Browsing, searching and downloading still work. Editing, uploading and deleting stay switched off until you tick “Ignore DISALLOW_FILE_EDIT” below.', 'beaver-filemanager' ); ?>
					</p>
				</div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>

				<h2 class="title"><?php esc_html_e( 'What you can reach', 'beaver-filemanager' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'The manager cannot open anything outside the folder you choose here — paths are resolved and re-checked on every request, and symlinks that point outside are refused.', 'beaver-filemanager' ); ?>
				</p>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Root folder', 'beaver-filemanager' ); ?></th>
						<td>
							<fieldset>
								<?php
								$roots = array(
									'abspath'    => array( __( 'WordPress root', 'beaver-filemanager' ), untrailingslashit( wp_normalize_path( ABSPATH ) ) ),
									'wp-content' => array( __( 'wp-content', 'beaver-filemanager' ), untrailingslashit( wp_normalize_path( WP_CONTENT_DIR ) ) ),
									'uploads'    => array( __( 'Uploads only', 'beaver-filemanager' ), untrailingslashit( wp_normalize_path( Beaver_FM_Filesystem::uploads_dir() ) ) ),
									'custom'     => array( __( 'Custom path', 'beaver-filemanager' ), '' ),
								);

								foreach ( $roots as $key => $info ) :
									?>
									<label style="display:block;margin-bottom:6px;">
										<input type="radio" name="<?php echo $name( 'root' ); ?>" value="<?php echo esc_attr( $key ); ?>" <?php checked( $settings['root'], $key ); ?>>
										<strong><?php echo esc_html( $info[0] ); ?></strong>
										<?php if ( '' !== $info[1] ) : ?>
											<code><?php echo esc_html( $info[1] ); ?></code>
										<?php endif; ?>
									</label>
								<?php endforeach; ?>

								<p>
									<input type="text" class="regular-text code" name="<?php echo $name( 'custom_root' ); ?>"
										value="<?php echo esc_attr( $settings['custom_root'] ); ?>"
										placeholder="<?php esc_attr_e( '/absolute/path/on/this/server', 'beaver-filemanager' ); ?>">
								</p>
								<p class="description"><?php esc_html_e( 'Only used when “Custom path” is selected. It must already exist on this server.', 'beaver-filemanager' ); ?></p>

								<?php if ( '' !== $root ) : ?>
									<p class="description">
										<?php
										printf(
											/* translators: %s: absolute path. */
											esc_html__( 'Currently browsing: %s', 'beaver-filemanager' ),
											'<code>' . esc_html( $root ) . '</code>'
										);
										?>
									</p>
								<?php endif; ?>
							</fieldset>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Hidden files', 'beaver-filemanager' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo $name( 'show_hidden' ); ?>" value="1" <?php checked( $settings['show_hidden'], 1 ); ?>>
								<?php esc_html_e( 'Show dotfiles such as .htaccess and .env', 'beaver-filemanager' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Safety', 'beaver-filemanager' ); ?></h2>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Read-only mode', 'beaver-filemanager' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo $name( 'readonly' ); ?>" value="1" <?php checked( $settings['readonly'], 1 ); ?>>
								<?php esc_html_e( 'Allow browsing, searching and downloading only', 'beaver-filemanager' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Every write endpoint refuses while this is on, not just the buttons in the interface.', 'beaver-filemanager' ); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'DISALLOW_FILE_EDIT', 'beaver-filemanager' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo $name( 'override_disallow' ); ?>" value="1" <?php checked( $settings['override_disallow'], 1 ); ?>>
								<?php esc_html_e( 'Ignore DISALLOW_FILE_EDIT and let this plugin write files', 'beaver-filemanager' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Leave this off unless you set that constant yourself and still want a way in.', 'beaver-filemanager' ); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Deleting', 'beaver-filemanager' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo $name( 'use_trash' ); ?>" value="1" <?php checked( $settings['use_trash'], 1 ); ?>>
								<?php esc_html_e( 'Move deleted items to a private trash instead of erasing them', 'beaver-filemanager' ); ?>
							</label>
							<p class="description">
								<?php
								printf(
									/* translators: %s: absolute path. */
									esc_html__( 'Trash lives in %s, outside the web root where possible and blocked from HTTP either way.', 'beaver-filemanager' ),
									'<code>' . esc_html( Beaver_FM_Editor::trash_dir() ) . '</code>'
								);
								?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Backups', 'beaver-filemanager' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo $name( 'backups' ); ?>" value="1" <?php checked( $settings['backups'], 1 ); ?>>
								<?php esc_html_e( 'Keep a copy of every file before it is overwritten', 'beaver-filemanager' ); ?>
							</label>
							<p>
								<label>
									<?php esc_html_e( 'Versions kept per file', 'beaver-filemanager' ); ?>
									<input type="number" min="1" max="100" class="small-text" name="<?php echo $name( 'backup_keep' ); ?>" value="<?php echo esc_attr( $settings['backup_keep'] ); ?>">
								</label>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'PHP syntax check', 'beaver-filemanager' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo $name( 'lint_php' ); ?>" value="1" <?php checked( $settings['lint_php'], 1 ); ?>>
								<?php esc_html_e( 'Refuse to save a PHP file that would not parse', 'beaver-filemanager' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'This is what stops a stray semicolon from taking the site down. You can still force a save from the editor if you really mean it.', 'beaver-filemanager' ); ?></p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Limits', 'beaver-filemanager' ); ?></h2>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="beaver-fm-max-edit"><?php esc_html_e( 'Largest file to open', 'beaver-filemanager' ); ?></label></th>
						<td>
							<input type="number" id="beaver-fm-max-edit" min="1" max="64" class="small-text" name="<?php echo $name( 'max_edit_mb' ); ?>" value="<?php echo esc_attr( $settings['max_edit_mb'] ); ?>"> <?php esc_html_e( 'MB', 'beaver-filemanager' ); ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="beaver-fm-max-upload"><?php esc_html_e( 'Largest upload', 'beaver-filemanager' ); ?></label></th>
						<td>
							<input type="number" id="beaver-fm-max-upload" min="0" max="4096" class="small-text" name="<?php echo $name( 'max_upload_mb' ); ?>" value="<?php echo esc_attr( $settings['max_upload_mb'] ); ?>"> <?php esc_html_e( 'MB', 'beaver-filemanager' ); ?>
							<p class="description">
								<?php
								printf(
									/* translators: %s: formatted file size. */
									esc_html__( 'Zero means “whatever PHP allows”, which on this server is %s.', 'beaver-filemanager' ),
									esc_html( size_format( wp_max_upload_size() ) )
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="beaver-fm-blocked"><?php esc_html_e( 'Never accept uploads of', 'beaver-filemanager' ); ?></label></th>
						<td>
							<input type="text" id="beaver-fm-blocked" class="regular-text code" name="<?php echo $name( 'blocked_extensions' ); ?>" value="<?php echo esc_attr( $settings['blocked_extensions'] ); ?>" placeholder="php, phtml, sh">
							<p class="description"><?php esc_html_e( 'Comma separated extensions. Leave empty to accept anything — this is an administrator tool, not a public form.', 'beaver-filemanager' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="beaver-fm-search-max"><?php esc_html_e( 'Files scanned per search', 'beaver-filemanager' ); ?></label></th>
						<td>
							<input type="number" id="beaver-fm-search-max" min="100" max="500000" step="100" class="regular-text" name="<?php echo $name( 'search_max_files' ); ?>" value="<?php echo esc_attr( $settings['search_max_files'] ); ?>">
							<p class="description"><?php esc_html_e( 'A ceiling so a search inside a huge uploads folder cannot hang the request.', 'beaver-filemanager' ); ?></p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Activity log', 'beaver-filemanager' ); ?></h2>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Record changes', 'beaver-filemanager' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo $name( 'log_enabled' ); ?>" value="1" <?php checked( $settings['log_enabled'], 1 ); ?>>
								<?php esc_html_e( 'Log who changed which file, and when', 'beaver-filemanager' ); ?>
							</label>
							<p>
								<label>
									<?php esc_html_e( 'Entries kept', 'beaver-filemanager' ); ?>
									<input type="number" min="20" max="5000" step="10" class="small-text" name="<?php echo $name( 'log_limit' ); ?>" value="<?php echo esc_attr( $settings['log_limit'] ); ?>">
								</label>
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<?php Beaver_FM_Admin::render_credit(); ?>
		</div>
		<?php
	}
}
