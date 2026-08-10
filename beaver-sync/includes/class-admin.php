<?php
/**
 * Admin screen.
 *
 * @package BeaverSync
 */

defined( 'ABSPATH' ) || exit;

/**
 * One screen that looks different depending on which end of the wire you are.
 *
 * @since 1.0.0
 */
class Beaver_Sync_Admin {

	const NONCE      = 'beaver_sync_action';
	const CAPABILITY = 'manage_options';

	/** Registers hooks. */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_actions' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_ajax_beaver_sync_batch', array( __CLASS__, 'ajax_batch' ) );
	}

	/** Adds the screen under Tools. */
	public static function register_menu() {
		add_management_page(
			__( 'Beaver Sync', 'beaver-sync' ),
			__( 'Beaver Sync', 'beaver-sync' ),
			self::CAPABILITY,
			BEAVER_SYNC_SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Loads assets on this screen only.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook_suffix Current screen.
	 */
	public static function enqueue_assets( $hook_suffix ) {
		if ( false === strpos( (string) $hook_suffix, BEAVER_SYNC_SLUG ) ) {
			return;
		}

		wp_enqueue_style( 'beaver-sync-admin', BEAVER_SYNC_URL . 'admin/css/admin.css', array(), BEAVER_SYNC_VERSION );
		wp_enqueue_script( 'beaver-sync-admin', BEAVER_SYNC_URL . 'admin/js/admin.js', array(), BEAVER_SYNC_VERSION, true );

		wp_localize_script(
			'beaver-sync-admin',
			'beaverSync',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'beaver_sync_batch' ),
				'i18n'    => array(
					'working'  => __( 'Copying…', 'beaver-sync' ),
					'done'     => __( 'Finished.', 'beaver-sync' ),
					'failed'   => __( 'Stopped: ', 'beaver-sync' ),
					'progress' => __( '%1$d of %2$d copied', 'beaver-sync' ),
				),
			)
		);
	}

	/** Handles form posts. */
	public static function handle_actions() {
		if ( ! isset( $_POST['beaver_sync_action'] ) || ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		check_admin_referer( self::NONCE );

		$action = sanitize_key( wp_unslash( $_POST['beaver_sync_action'] ) );
		$base   = admin_url( 'tools.php?page=' . BEAVER_SYNC_SLUG );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified above.
		if ( 'save' === $action ) {
			$role = isset( $_POST['role'] ) ? sanitize_key( wp_unslash( $_POST['role'] ) ) : '';

			Beaver_Sync_Settings::update(
				array(
					'role'       => $role,
					'source_url' => isset( $_POST['source_url'] ) ? wp_unslash( $_POST['source_url'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised in Settings::sanitize().
					'source_key' => isset( $_POST['source_key'] ) ? wp_unslash( $_POST['source_key'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised in Settings::sanitize().
				)
			);

			if ( Beaver_Sync_Settings::SOURCE === $role ) {
				Beaver_Sync_Settings::ensure_key();
			}

			wp_safe_redirect( add_query_arg( 'bs_saved', '1', $base ) );
			exit;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( 'check' === $action ) {
			$plan = Beaver_Sync_Puller::plan();

			if ( is_wp_error( $plan ) ) {
				set_transient( 'beaver_sync_error', $plan->get_error_message(), 60 );
			} else {
				set_transient( 'beaver_sync_plan', $plan, HOUR_IN_SECONDS );
				Beaver_Sync_Puller::clear();
			}

			wp_safe_redirect( add_query_arg( 'bs_checked', '1', $base ) );
			exit;
		}

		if ( 'start' === $action ) {
			$plan = get_transient( 'beaver_sync_plan' );

			if ( is_array( $plan ) ) {
				Beaver_Sync_Puller::queue( $plan );
			}

			wp_safe_redirect( add_query_arg( 'bs_started', '1', $base ) );
			exit;
		}

		if ( 'cancel' === $action ) {
			Beaver_Sync_Puller::clear();
			delete_transient( 'beaver_sync_plan' );

			wp_safe_redirect( $base );
			exit;
		}

		if ( 'newkey' === $action ) {
			Beaver_Sync_Settings::update( array( 'key' => wp_generate_password( 48, false, false ) ) );

			wp_safe_redirect( add_query_arg( 'bs_newkey', '1', $base ) );
			exit;
		}
	}

	/** One batch of downloads, called repeatedly by the browser. */
	public static function ajax_batch() {
		if ( ! current_user_can( self::CAPABILITY ) || ! check_ajax_referer( 'beaver_sync_batch', '_ajax_nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'beaver-sync' ) ), 403 );
		}

		$result = Beaver_Sync_Puller::run_batch( 8 );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'done'      => (int) $result['done'],
				'total'     => (int) $result['total'],
				'remaining' => (int) $result['remaining'],
				'bytes'     => (int) $result['bytes'],
				'failed'    => $result['failed'],
				'finished'  => ! empty( $result['finished'] ),
			)
		);
	}

	/** Renders the screen. */
	public static function render() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to view this page.', 'beaver-sync' ) );
		}

		$s    = Beaver_Sync_Settings::all();
		$base = admin_url( 'tools.php?page=' . BEAVER_SYNC_SLUG );
		?>
		<div class="wrap beaver-sync">
			<h1><?php esc_html_e( 'Beaver Sync', 'beaver-sync' ); ?></h1>

			<p class="description beaver-sync-lede">
				<?php esc_html_e( 'Brings the live site\'s media down to this one over HTTPS. The live site publishes a read-only list of what it holds; this site compares that with its own uploads folder and downloads only the difference. Nothing is ever written to the live site, and nothing here is ever deleted.', 'beaver-sync' ); ?>
			</p>

			<?php self::render_notices(); ?>

			<form method="post" action="<?php echo esc_url( $base ); ?>">
				<?php wp_nonce_field( self::NONCE ); ?>
				<input type="hidden" name="beaver_sync_action" value="save" />

				<h2><?php esc_html_e( 'Which site is this?', 'beaver-sync' ); ?></h2>
				<table class="widefat striped beaver-sync-roles">
					<tbody>
						<?php
						$roles = array(
							Beaver_Sync_Settings::COPY   => array(
								__( 'The local copy', 'beaver-sync' ),
								__( 'Pulls media down. This is the machine you work on.', 'beaver-sync' ),
							),
							Beaver_Sync_Settings::SOURCE => array(
								__( 'The live site', 'beaver-sync' ),
								__( 'Publishes the list, and nothing else. No file is ever written here by this plugin.', 'beaver-sync' ),
							),
							Beaver_Sync_Settings::IDLE   => array(
								__( 'Neither, for now', 'beaver-sync' ),
								__( 'The plugin sits idle and registers no endpoint at all.', 'beaver-sync' ),
							),
						);

						foreach ( $roles as $value => $meta ) :
							?>
							<tr>
								<td class="beaver-sync-roles__pick">
									<input type="radio" id="bs-role-<?php echo esc_attr( $value ); ?>" name="role" value="<?php echo esc_attr( $value ); ?>" <?php checked( $s['role'], $value ); ?> />
								</td>
								<td>
									<label for="bs-role-<?php echo esc_attr( $value ); ?>"><strong><?php echo esc_html( $meta[0] ); ?></strong></label>
									<span class="beaver-sync-note"><?php echo esc_html( $meta[1] ); ?></span>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<div class="beaver-sync-copyonly">
					<h2><?php esc_html_e( 'The live site to pull from', 'beaver-sync' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="bs-url"><?php esc_html_e( 'Address', 'beaver-sync' ); ?></label></th>
							<td>
								<input type="url" id="bs-url" name="source_url" class="regular-text code" value="<?php echo esc_attr( $s['source_url'] ); ?>" placeholder="https://example.com" />
								<p class="description"><?php esc_html_e( 'The home address of the live site, nothing after it.', 'beaver-sync' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="bs-key"><?php esc_html_e( 'Its sync key', 'beaver-sync' ); ?></label></th>
							<td>
								<input type="text" id="bs-key" name="source_key" class="regular-text code" value="<?php echo esc_attr( $s['source_key'] ); ?>" spellcheck="false" autocomplete="off" />
								<p class="description"><?php esc_html_e( 'Copy it from the live site\'s own Beaver Sync screen.', 'beaver-sync' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<?php submit_button( __( 'Save', 'beaver-sync' ) ); ?>
			</form>

			<?php
			if ( Beaver_Sync_Settings::is_source() ) {
				self::render_source( $base );
			}

			if ( Beaver_Sync_Settings::is_copy() ) {
				self::render_copy( $base );
			}

			self::render_credit();
			?>
		</div>
		<?php
	}

	/** The live site's half: show the key, and where to point at it. */
	private static function render_source( $base ) {
		$key = Beaver_Sync_Settings::ensure_key();
		?>
		<h2><?php esc_html_e( 'This site is publishing its file list', 'beaver-sync' ); ?></h2>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><?php esc_html_e( 'Endpoint', 'beaver-sync' ); ?></th>
				<td><code><?php echo esc_html( rest_url( Beaver_Sync_Endpoint::NAMESPACE_V1 . '/manifest' ) ); ?></code></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Sync key', 'beaver-sync' ); ?></th>
				<td>
					<input type="text" class="regular-text code" value="<?php echo esc_attr( $key ); ?>" readonly onfocus="this.select()" />
					<?php if ( Beaver_Sync_Settings::key_is_constant() ) : ?>
						<p class="description"><?php esc_html_e( 'Set by BEAVER_SYNC_KEY in wp-config.php, which is the better place for it.', 'beaver-sync' ); ?></p>
					<?php else : ?>
						<p class="description">
							<?php esc_html_e( 'Paste this into the local copy. It does not protect the files, which are already public; it protects the list of them.', 'beaver-sync' ); ?>
						</p>
					<?php endif; ?>
				</td>
			</tr>
		</table>

		<?php if ( ! Beaver_Sync_Settings::key_is_constant() ) : ?>
			<form method="post" action="<?php echo esc_url( $base ); ?>">
				<?php wp_nonce_field( self::NONCE ); ?>
				<input type="hidden" name="beaver_sync_action" value="newkey" />
				<?php submit_button( __( 'Issue a new key', 'beaver-sync' ), 'secondary', 'submit', false ); ?>
				<span class="beaver-sync-note"><?php esc_html_e( 'The old one stops working immediately.', 'beaver-sync' ); ?></span>
			</form>
		<?php endif; ?>
		<?php
	}

	/** The local copy's half: check, then copy. */
	private static function render_copy( $base ) {
		$plan  = get_transient( 'beaver_sync_plan' );
		$queue = Beaver_Sync_Puller::queued();
		?>
		<h2><?php esc_html_e( 'Media', 'beaver-sync' ); ?></h2>

		<?php if ( $queue ) : ?>
			<div class="beaver-sync-run" data-beaver-sync-run>
				<p>
					<strong><?php esc_html_e( 'A copy is in progress.', 'beaver-sync' ); ?></strong>
					<span data-beaver-sync-status>
						<?php
						printf(
							/* translators: 1: files done, 2: files in total. */
							esc_html__( '%1$d of %2$d copied', 'beaver-sync' ),
							(int) $queue['done'],
							(int) $queue['total']
						);
						?>
					</span>
				</p>
				<div class="beaver-sync-bar"><span data-beaver-sync-bar style="width:<?php echo esc_attr( $queue['total'] ? round( 100 * $queue['done'] / $queue['total'] ) : 0 ); ?>%"></span></div>
				<p>
					<button type="button" class="button button-primary" data-beaver-sync-go><?php esc_html_e( 'Continue', 'beaver-sync' ); ?></button>
				</p>
				<ul class="beaver-sync-failed" data-beaver-sync-failed></ul>
			</div>

			<form method="post" action="<?php echo esc_url( $base ); ?>">
				<?php wp_nonce_field( self::NONCE ); ?>
				<input type="hidden" name="beaver_sync_action" value="cancel" />
				<button type="submit" class="button-link beaver-sync-danger"><?php esc_html_e( 'Abandon this run', 'beaver-sync' ); ?></button>
			</form>

		<?php elseif ( is_array( $plan ) ) : ?>
			<?php self::render_plan( $plan, $base ); ?>

		<?php else : ?>
			<p><?php esc_html_e( 'Check what the live site has that this one does not. Nothing is downloaded until you have seen the list.', 'beaver-sync' ); ?></p>
			<form method="post" action="<?php echo esc_url( $base ); ?>">
				<?php wp_nonce_field( self::NONCE ); ?>
				<input type="hidden" name="beaver_sync_action" value="check" />
				<?php submit_button( __( 'Check for differences', 'beaver-sync' ), 'primary', 'submit', false ); ?>
			</form>
		<?php endif; ?>

		<?php
		$s = Beaver_Sync_Settings::all();

		if ( $s['last_run'] ) :
			?>
			<p class="beaver-sync-note">
				<?php
				printf(
					/* translators: 1: date and time, 2: result summary. */
					esc_html__( 'Last run %1$s: %2$s.', 'beaver-sync' ),
					esc_html( $s['last_run'] ),
					esc_html( $s['last_result'] )
				);
				?>
			</p>
		<?php endif; ?>
		<?php
	}

	/**
	 * The dry run.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $plan The plan.
	 * @param string $base Screen URL.
	 */
	private static function render_plan( $plan, $base ) {
		$missing = count( $plan['missing'] );
		$changed = count( $plan['changed'] );
		$extra   = count( $plan['extra'] );
		$total   = $missing + $changed;
		?>
		<div class="beaver-sync-plan">
			<p class="beaver-sync-plan__head">
				<?php
				printf(
					/* translators: 1: files on the live site, 2: files to download, 3: total size. */
					esc_html__( 'The live site holds %1$d files. %2$d would be copied here, %3$s in total.', 'beaver-sync' ),
					(int) $plan['there'],
					(int) $total,
					esc_html( size_format( (int) $plan['bytes'], 1 ) )
				);
				?>
			</p>

			<ul class="beaver-sync-counts">
				<li><strong><?php echo esc_html( number_format_i18n( $missing ) ); ?></strong> <?php esc_html_e( 'missing here', 'beaver-sync' ); ?></li>
				<li><strong><?php echo esc_html( number_format_i18n( $changed ) ); ?></strong> <?php esc_html_e( 'a different size', 'beaver-sync' ); ?></li>
				<li><strong><?php echo esc_html( number_format_i18n( $extra ) ); ?></strong> <?php esc_html_e( 'here but not there, left alone', 'beaver-sync' ); ?></li>
				<?php if ( ! empty( $plan['skipped'] ) ) : ?>
					<li><strong><?php echo esc_html( number_format_i18n( (int) $plan['skipped'] ) ); ?></strong> <?php esc_html_e( 'refused, not media', 'beaver-sync' ); ?></li>
				<?php endif; ?>
			</ul>

			<?php if ( $total ) : ?>
				<?php self::render_list( __( 'Would be copied', 'beaver-sync' ), array_merge( $plan['missing'], $plan['changed'] ), true ); ?>
			<?php endif; ?>

			<?php if ( $extra ) : ?>
				<?php self::render_list( __( 'Here but not on the live site', 'beaver-sync' ), array_fill_keys( $plan['extra'], null ), false ); ?>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( $base ); ?>" class="beaver-sync-plan__actions">
				<?php wp_nonce_field( self::NONCE ); ?>
				<input type="hidden" name="beaver_sync_action" value="<?php echo $total ? 'start' : 'cancel'; ?>" />
				<?php
				if ( $total ) {
					submit_button(
						/* translators: %d: number of files. */
						sprintf( __( 'Copy these %d files', 'beaver-sync' ), (int) $total ),
						'primary',
						'submit',
						false
					);
				} else {
					submit_button( __( 'Nothing to do, clear this', 'beaver-sync' ), 'secondary', 'submit', false );
				}
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * A collapsed list of paths.
	 *
	 * @since 1.0.0
	 *
	 * @param string $title  Heading.
	 * @param array  $items  Path => size, or path => null.
	 * @param bool   $sizes  Whether to show sizes.
	 */
	private static function render_list( $title, $items, $sizes ) {
		?>
		<details class="beaver-sync-list">
			<summary><?php echo esc_html( $title ); ?> (<?php echo esc_html( number_format_i18n( count( $items ) ) ); ?>)</summary>
			<ul>
				<?php
				$shown = 0;

				foreach ( $items as $path => $size ) {
					if ( $shown >= 500 ) {
						break;
					}
					$shown++;
					?>
					<li>
						<code><?php echo esc_html( $path ); ?></code>
						<?php if ( $sizes && null !== $size ) : ?>
							<span class="beaver-sync-note"><?php echo esc_html( size_format( (int) $size, 1 ) ); ?></span>
						<?php endif; ?>
					</li>
					<?php
				}

				if ( count( $items ) > $shown ) {
					// A three thousand item list is not a useful thing to print,
					// and the counts above already say the whole truth.
					echo '<li class="beaver-sync-note">' . esc_html(
						sprintf(
							/* translators: %d: number of further files. */
							__( '…and %d more.', 'beaver-sync' ),
							count( $items ) - $shown
						)
					) . '</li>';
				}
				?>
			</ul>
		</details>
		<?php
	}

	/** Notices from the last action. */
	private static function render_notices() {
		$error = get_transient( 'beaver_sync_error' );

		if ( $error ) {
			delete_transient( 'beaver_sync_error' );

			echo '<div class="notice notice-error"><p>' . esc_html( $error ) . '</p></div>';
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['bs_saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Saved.', 'beaver-sync' ) . '</p></div>';
		}

		if ( isset( $_GET['bs_newkey'] ) ) {
			echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'A new key is in force. Paste it into the local copy, the old one no longer works.', 'beaver-sync' ) . '</p></div>';
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
	}

	/** Renders the maker's mark. */
	private static function render_credit() {
		?>
		<div class="beaver-sync-credit">
			<img class="beaver-sync-credit__logo" width="300" height="152"
				src="<?php echo esc_url( BEAVER_SYNC_URL . 'assets/digital-beaver-logo.png' ); ?>"
				alt="<?php esc_attr_e( 'Digital Beaver', 'beaver-sync' ); ?>" />
			<div class="beaver-sync-credit__text">
				<strong><?php esc_html_e( 'Designed & built by Digital Beaver', 'beaver-sync' ); ?></strong>
				<?php esc_html_e( 'Need a change, a new feature, or a site as fast as this one?', 'beaver-sync' ); ?>
				<a href="https://digitalbeavertz.com/" target="_blank" rel="noopener noreferrer">digitalbeavertz.com</a>
			</div>
		</div>
		<?php
	}
}
