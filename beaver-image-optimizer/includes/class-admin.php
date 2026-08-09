<?php
/**
 * Admin interface, settings and AJAX endpoints.
 *
 * @package BeaverImageOptimizer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders the dashboard, tools and settings screens.
 *
 * Every entry point in this class performs a capability check followed by a
 * nonce check before touching any data.
 *
 * @since 1.0.0
 */
class Beaver_Image_Admin {

	const MENU_SLUG    = 'beaver-io';
	const TOOLS_SLUG   = 'beaver-io-tools';
	const SETTINGS_SLUG = 'beaver-io-settings';
	const NONCE_ACTION = 'beaver_io_ajax';
	const CAPABILITY   = 'manage_options';

	/**
	 * Memory held back so a fatal error still has room to build a response.
	 *
	 * @var string|null
	 */
	private static $fatal_reserve = null;

	/**
	 * Whether the current batch reached its normal end.
	 *
	 * @var bool
	 */
	private static $batch_done = true;

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

		add_filter( 'manage_media_columns', array( __CLASS__, 'add_media_column' ) );
		add_action( 'manage_media_custom_column', array( __CLASS__, 'render_media_column' ), 10, 2 );

		add_action( 'wp_ajax_beaver_io_scan', array( __CLASS__, 'ajax_scan' ) );
		add_action( 'wp_ajax_beaver_io_batch', array( __CLASS__, 'ajax_batch' ) );
		add_action( 'wp_ajax_beaver_io_cancel', array( __CLASS__, 'ajax_cancel' ) );
		add_action( 'wp_ajax_beaver_io_test_delivery', array( __CLASS__, 'ajax_test_delivery' ) );
		add_action( 'wp_ajax_beaver_io_write_rules', array( __CLASS__, 'ajax_write_rules' ) );
		add_action( 'wp_ajax_beaver_io_reset_stats', array( __CLASS__, 'ajax_reset_stats' ) );
		add_action( 'wp_ajax_beaver_io_single', array( __CLASS__, 'ajax_single' ) );
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
			__( 'Beaver Image Optimizer', 'beaver-image-optimizer' ),
			__( 'Beaver Optimizer', 'beaver-image-optimizer' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( __CLASS__, 'render_dashboard' ),
			'dashicons-images-alt2',
			81
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Dashboard', 'beaver-image-optimizer' ),
			__( 'Dashboard', 'beaver-image-optimizer' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( __CLASS__, 'render_dashboard' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Bulk Optimize', 'beaver-image-optimizer' ),
			__( 'Bulk Optimize', 'beaver-image-optimizer' ),
			self::CAPABILITY,
			self::TOOLS_SLUG,
			array( __CLASS__, 'render_tools' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Settings', 'beaver-image-optimizer' ),
			__( 'Settings', 'beaver-image-optimizer' ),
			self::CAPABILITY,
			self::SETTINGS_SLUG,
			array( __CLASS__, 'render_settings' )
		);
	}

	/**
	 * Enqueues the admin stylesheet and script on plugin screens.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook_suffix Current admin page.
	 */
	public static function enqueue_assets( $hook_suffix ) {
		$is_plugin_screen = ( false !== strpos( (string) $hook_suffix, self::MENU_SLUG ) );
		$is_media_screen  = ( 'upload.php' === $hook_suffix );

		if ( ! $is_plugin_screen && ! $is_media_screen ) {
			return;
		}

		wp_enqueue_style(
			'beaver-io-admin',
			BEAVER_IO_URL . 'admin/css/admin.css',
			array(),
			BEAVER_IO_VERSION
		);

		wp_enqueue_script(
			'beaver-io-admin',
			BEAVER_IO_URL . 'admin/js/admin.js',
			array(),
			BEAVER_IO_VERSION,
			true
		);

		wp_localize_script(
			'beaver-io-admin',
			'beaverIO',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
				'i18n'    => array(
					'scanning'   => __( 'Scanning the media library…', 'beaver-image-optimizer' ),
					'noImages'   => __( 'No images need optimizing right now.', 'beaver-image-optimizer' ),
					'working'    => __( 'Optimizing…', 'beaver-image-optimizer' ),
					'complete'   => __( 'All done.', 'beaver-image-optimizer' ),
					'cancelled'  => __( 'Stopped. Progress has been saved — press Start to resume.', 'beaver-image-optimizer' ),
					'failed'     => __( 'Something went wrong. Check the log below.', 'beaver-image-optimizer' ),
					'confirm'    => __( 'This rebuilds every WebP file in the library. Continue?', 'beaver-image-optimizer' ),
					'optimize'   => __( 'Optimize', 'beaver-image-optimizer' ),
					'saved'      => __( 'saved', 'beaver-image-optimizer' ),
					'testing'    => __( 'Testing…', 'beaver-image-optimizer' ),
					'recovering' => __( 'The server stopped on one image. Skipping it and continuing…', 'beaver-image-optimizer' ),
				),
			)
		);
	}

	/**
	 * Warns when the server cannot produce WebP images.
	 *
	 * @since 1.0.0
	 */
	public static function render_notices() {
		if ( ! current_user_can( self::CAPABILITY ) || Beaver_Image_Converter::is_supported() ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( $screen && false === strpos( (string) $screen->id, self::MENU_SLUG ) && 'plugins' !== $screen->id ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
			esc_html__( 'Beaver Image Optimizer:', 'beaver-image-optimizer' ),
			esc_html__( 'this server has neither GD with WebP support nor Imagick with WebP support, so no images can be converted. Ask your host to enable WebP in PHP.', 'beaver-image-optimizer' )
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
			'beaver_io_settings_group',
			Beaver_Image_Optimizer::OPTION_SETTINGS,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ),
				'default'           => Beaver_Image_Optimizer::default_settings(),
			)
		);
	}

	/**
	 * Sanitizes submitted settings and refreshes the delivery rules.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $input Raw settings.
	 * @return array Sanitized settings.
	 */
	public static function sanitize_settings( $input ) {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return Beaver_Image_Optimizer::get_settings();
		}

		$clean = Beaver_Image_Optimizer::sanitize_settings( $input );

		if ( ! Beaver_Image_Optimizer::delivery_rules_installed() ) {
			Beaver_Image_Optimizer::write_delivery_rules();
		}

		delete_transient( 'beaver_io_delivery_test' );

		return $clean;
	}

	/*
	 * -----------------------------------------------------------------------
	 * AJAX endpoints
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Verifies capability and nonce for every AJAX request.
	 *
	 * @since 1.0.0
	 */
	private static function verify_request() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'beaver-image-optimizer' ) ), 403 );
		}

		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
	}

	/**
	 * Builds the work queue.
	 *
	 * @since 1.0.0
	 */
	public static function ajax_scan() {
		self::verify_request();

		$force  = ! empty( $_POST['force'] );
		$resume = ! empty( $_POST['resume'] );

		if ( $resume ) {
			$queue = Beaver_Image_Optimizer::get_queue();

			if ( '' !== $queue['ids'] ) {
				wp_send_json_success(
					array(
						'total'     => (int) $queue['total'],
						'done'      => (int) $queue['done'],
						'remaining' => count( explode( ',', $queue['ids'] ) ),
						'resumed'   => true,
					)
				);
			}
		}

		$ids   = Beaver_Image_Optimizer::find_attachments( $force, -1 );
		$queue = Beaver_Image_Optimizer::set_queue( $ids, $force );

		wp_send_json_success(
			array(
				'total'     => (int) $queue['total'],
				'done'      => 0,
				'remaining' => (int) $queue['total'],
				'resumed'   => false,
			)
		);
	}

	/**
	 * Processes one batch from the queue.
	 *
	 * @since 1.0.0
	 */
	public static function ajax_batch() {
		self::verify_request();

		$batch = isset( $_POST['batch'] ) ? absint( $_POST['batch'] ) : 0;

		/*
		 * Converting an image can exhaust the memory limit or the execution
		 * time, and PHP answers that with a bare HTTP 500 that carries no
		 * explanation and stops the run dead. The guard below reserves a slice
		 * of memory and hands it back during shutdown, which is enough headroom
		 * to attribute the crash and reply with a normal batch response so the
		 * queue keeps moving.
		 */
		self::$fatal_reserve = str_repeat( '0', 256 * 1024 );
		self::$batch_done    = false;

		register_shutdown_function( array( __CLASS__, 'handle_batch_fatal' ) );

		$run = Beaver_Image_Optimizer::run_queue_batch( $batch );

		$run['stats'] = self::stats_payload();

		self::$batch_done    = true;
		self::$fatal_reserve = null;

		wp_send_json_success( $run );
	}

	/**
	 * Turns a fatal error during a batch into a valid response.
	 *
	 * Runs on shutdown. It only acts when the batch did not finish and PHP
	 * recorded a fatal, so a normal request passes straight through.
	 *
	 * @since 1.3.2
	 */
	public static function handle_batch_fatal() {
		if ( self::$batch_done ) {
			return;
		}

		// Hand the reserve back before doing anything that needs to allocate.
		self::$fatal_reserve = null;

		$error = error_get_last();

		$fatal = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR );

		if ( null === $error || ! in_array( $error['type'], $fatal, true ) ) {
			return;
		}

		if ( headers_sent() ) {
			return;
		}

		// Name the image that died, drop it from the queue and count it failed.
		// The error is handed over so the report quotes what PHP actually said
		// rather than guessing at a cause.
		$recovered = Beaver_Image_Optimizer::recover_inflight( $error );
		$queue     = Beaver_Image_Optimizer::get_queue();

		if ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		status_header( 200 );

		wp_send_json_success(
			array(
				'processed' => 1,
				'done'      => (int) $queue['done'],
				'total'     => (int) $queue['total'],
				'complete'  => '' === $queue['ids'],
				'recovered' => true,
				'items'     => null === $recovered ? array() : array( $recovered ),
				'stats'     => self::stats_payload(),
			)
		);
	}

	/**
	 * Discards the queue.
	 *
	 * @since 1.0.0
	 */
	public static function ajax_cancel() {
		self::verify_request();
		Beaver_Image_Optimizer::clear_queue();

		// Stopping is a decision, not a crash. Leaving the marker behind would
		// let the next run blame whichever image happened to be in progress.
		Beaver_Image_Optimizer::clear_inflight();

		wp_send_json_success( array( 'message' => __( 'Queue cleared.', 'beaver-image-optimizer' ) ) );
	}

	/**
	 * Runs the live WebP delivery test.
	 *
	 * @since 1.0.0
	 */
	public static function ajax_test_delivery() {
		self::verify_request();

		wp_send_json_success( Beaver_Image_Optimizer::test_delivery( true ) );
	}

	/**
	 * Re-installs the uploads rewrite rules.
	 *
	 * @since 1.0.0
	 */
	public static function ajax_write_rules() {
		self::verify_request();

		$written = Beaver_Image_Optimizer::write_delivery_rules();

		wp_send_json_success(
			array(
				'written' => $written,
				'message' => $written
					? __( 'Rewrite rules installed in the uploads folder.', 'beaver-image-optimizer' )
					: __( 'Could not write to wp-content/uploads/.htaccess. Check the folder permissions, or enable the picture element fallback.', 'beaver-image-optimizer' ),
			)
		);
	}

	/**
	 * Clears the aggregate statistics.
	 *
	 * @since 1.0.0
	 */
	public static function ajax_reset_stats() {
		self::verify_request();
		Beaver_Image_Optimizer::reset_stats();

		wp_send_json_success( array( 'stats' => self::stats_payload() ) );
	}

	/**
	 * Optimizes or restores a single attachment from the media list table.
	 *
	 * @since 1.0.0
	 */
	public static function ajax_single() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$attachment_id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$mode          = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'optimize';

		if ( ! $attachment_id || 'attachment' !== get_post_type( $attachment_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown attachment.', 'beaver-image-optimizer' ) ), 404 );
		}

		if ( ! current_user_can( 'edit_post', $attachment_id ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to edit this image.', 'beaver-image-optimizer' ) ), 403 );
		}

		if ( 'restore' === $mode ) {
			$removed = Beaver_Image_Optimizer::remove_sidecars( $attachment_id );

			wp_send_json_success(
				array(
					'html'    => self::media_column_html( $attachment_id ),
					'message' => sprintf(
						/* translators: %d: number of files removed. */
						_n( '%d WebP file removed.', '%d WebP files removed.', $removed, 'beaver-image-optimizer' ),
						$removed
					),
				)
			);
		}

		$result = Beaver_Image_Optimizer::optimize_attachment( $attachment_id, true );

		wp_send_json_success(
			array(
				'html'    => self::media_column_html( $attachment_id ),
				'message' => $result['message'],
				'status'  => $result['status'],
			)
		);
	}

	/**
	 * Builds the statistics payload shared by AJAX responses and the dashboard.
	 *
	 * @since 1.0.0
	 *
	 * @return array Formatted statistics.
	 */
	private static function stats_payload() {
		$stats = Beaver_Image_Optimizer::get_stats();

		return array(
			'images'         => number_format_i18n( $stats['images'] ),
			'files'          => number_format_i18n( $stats['files'] ),
			'originalBytes'  => size_format( $stats['original_bytes'], 2 ),
			'webpBytes'      => size_format( $stats['webp_bytes'], 2 ),
			'savedBytes'     => size_format( $stats['saved_bytes'], 2 ),
			'diskFreed'      => size_format( $stats['disk_freed'], 2 ),
			'savedPercent'   => $stats['saved_percent'],
			'skipped'        => number_format_i18n( $stats['skipped'] ),
			'failed'         => number_format_i18n( $stats['failed'] ),
		);
	}

	/*
	 * -----------------------------------------------------------------------
	 * Media library column
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Adds the WebP column to the media list table.
	 *
	 * @since 1.0.0
	 *
	 * @param array $columns Existing columns.
	 * @return array Filtered columns.
	 */
	public static function add_media_column( $columns ) {
		$columns['beaver_io'] = __( 'WebP', 'beaver-image-optimizer' );

		return $columns;
	}

	/**
	 * Renders the WebP column.
	 *
	 * @since 1.0.0
	 *
	 * @param string $column_name   Column key.
	 * @param int    $attachment_id Attachment ID.
	 */
	public static function render_media_column( $column_name, $attachment_id ) {
		if ( 'beaver_io' !== $column_name ) {
			return;
		}

		echo wp_kses_post( self::media_column_html( $attachment_id ) );
	}

	/**
	 * Builds the markup for one media column cell.
	 *
	 * @since 1.0.0
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string HTML.
	 */
	private static function media_column_html( $attachment_id ) {
		$mime = get_post_mime_type( $attachment_id );

		if ( ! in_array( $mime, array( 'image/jpeg', 'image/png', 'image/webp' ), true ) ) {
			return '<span class="beaver-io-muted">&mdash;</span>';
		}

		$status = get_post_meta( $attachment_id, Beaver_Image_Optimizer::META_STATUS, true );
		$stats  = get_post_meta( $attachment_id, Beaver_Image_Optimizer::META_STATS, true );
		$out    = '';

		if ( in_array( $status, array( 'optimized', 'partial' ), true ) && is_array( $stats ) ) {
			$saved   = max( 0, (int) $stats['original_bytes'] - (int) $stats['webp_bytes'] );
			$percent = $stats['original_bytes'] > 0 ? round( ( $saved / $stats['original_bytes'] ) * 100 ) : 0;

			$out .= sprintf(
				'<span class="beaver-io-badge beaver-io-badge--%1$s">%2$s</span> <span class="beaver-io-saving">%3$s (%4$s%%)</span>',
				esc_attr( $status ),
				'partial' === $status ? esc_html__( 'Partial', 'beaver-image-optimizer' ) : esc_html__( 'Optimized', 'beaver-image-optimizer' ),
				esc_html( size_format( $saved, 1 ) ),
				esc_html( number_format_i18n( $percent ) )
			);
		} elseif ( 'skipped' === $status ) {
			$out .= '<span class="beaver-io-badge beaver-io-badge--skipped">' . esc_html__( 'No gain', 'beaver-image-optimizer' ) . '</span>';
		} elseif ( 'failed' === $status ) {
			$out .= '<span class="beaver-io-badge beaver-io-badge--failed">' . esc_html__( 'Failed', 'beaver-image-optimizer' ) . '</span>';
		} else {
			$out .= '<span class="beaver-io-badge beaver-io-badge--pending">' . esc_html__( 'Not optimized', 'beaver-image-optimizer' ) . '</span>';
		}

		// A badge on its own tells you something broke but not what, which
		// leaves no way to act on it. Show the stored reason next to it.
		$error = Beaver_Image_Optimizer::get_error( $attachment_id );

		if ( null !== $error ) {
			$out .= '<span class="beaver-io-error">' . esc_html( $error['message'] ) . '</span>';
		}

		if ( 'image/webp' !== $mime ) {
			$out .= sprintf(
				' <button type="button" class="button-link beaver-io-row-action" data-id="%d" data-mode="optimize">%s</button>',
				(int) $attachment_id,
				esc_html__( 'Optimize', 'beaver-image-optimizer' )
			);

			if ( $status ) {
				$out .= sprintf(
					' <button type="button" class="button-link beaver-io-row-action beaver-io-row-action--danger" data-id="%d" data-mode="restore">%s</button>',
					(int) $attachment_id,
					esc_html__( 'Remove WebP', 'beaver-image-optimizer' )
				);
			}
		}

		return $out;
	}

	/*
	 * -----------------------------------------------------------------------
	 * Screens
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Lists images that failed to convert, with the reason for each.
	 *
	 * Renders nothing when there is nothing wrong, so a healthy install never
	 * grows a permanent empty "errors" box.
	 *
	 * @since 1.3.0
	 */
	private static function render_errors() {
		$errors = Beaver_Image_Optimizer::recent_errors( 10 );

		if ( empty( $errors ) ) {
			return;
		}

		?>
		<div class="beaver-io-panel beaver-io-panel--errors">
			<h2><?php esc_html_e( 'Images that could not be optimized', 'beaver-image-optimizer' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Each image below reports why it failed. Fix the cause, then retry — an image leaves this list as soon as it converts.', 'beaver-image-optimizer' ); ?>
			</p>

			<table class="widefat striped beaver-io-errors">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Image', 'beaver-image-optimizer' ); ?></th>
						<th scope="col"><?php esc_html_e( 'What went wrong', 'beaver-image-optimizer' ); ?></th>
						<th scope="col"><?php esc_html_e( 'When', 'beaver-image-optimizer' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Action', 'beaver-image-optimizer' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $errors as $error ) : ?>
						<tr>
							<td>
								<?php
								$link  = get_edit_post_link( $error['id'] );
								$title = '' !== $error['title'] ? $error['title'] : sprintf( '#%d', $error['id'] );

								if ( $link ) {
									printf( '<a href="%1$s">%2$s</a>', esc_url( $link ), esc_html( $title ) );
								} else {
									echo esc_html( $title );
								}
								?>
							</td>
							<td>
								<?php echo esc_html( $error['message'] ); ?>
								<?php if ( '' !== $error['code'] ) : ?>
									<code class="beaver-io-error-code"><?php echo esc_html( $error['code'] ); ?></code>
								<?php endif; ?>
							</td>
							<td>
								<?php
								if ( $error['timestamp'] > 0 ) {
									printf(
										/* translators: %s: human readable time difference, e.g. "2 hours". */
										esc_html__( '%s ago', 'beaver-image-optimizer' ),
										esc_html( human_time_diff( $error['timestamp'], time() ) )
									);
								} else {
									echo '&mdash;';
								}
								?>
							</td>
							<td>
								<span class="beaver-io-error-action">
									<button type="button" class="button button-small beaver-io-row-action" data-id="<?php echo (int) $error['id']; ?>" data-mode="optimize">
										<?php esc_html_e( 'Retry', 'beaver-image-optimizer' ); ?>
									</button>
								</span>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Renders the maker's mark shown at the foot of every plugin screen.
	 *
	 * Attribution only — it never renders on the front end, and nothing in the
	 * plugin depends on it. Mirrors the credit block the Digital Beaver
	 * mu-plugin puts on ACF settings screens so the branding reads the same
	 * wherever a client meets it.
	 *
	 * @since 1.2.0
	 */
	private static function render_credit() {
		?>
		<div class="beaver-io-credit">
			<img class="beaver-io-credit__logo" width="300" height="152"
			     src="<?php echo esc_url( BEAVER_IO_URL . 'assets/digital-beaver-logo.png' ); ?>"
			     alt="<?php esc_attr_e( 'Digital Beaver', 'beaver-image-optimizer' ); ?>" />
			<div class="beaver-io-credit__text">
				<strong><?php esc_html_e( 'Designed & built by Digital Beaver', 'beaver-image-optimizer' ); ?></strong>
				<?php esc_html_e( 'Need a change, a new feature, or a site as fast as this one?', 'beaver-image-optimizer' ); ?>
				<a href="https://digitalbeavertz.com/" target="_blank" rel="noopener noreferrer">digitalbeavertz.com</a>
			</div>
		</div>
		<?php
	}

	/**
	 * Renders the dashboard screen.
	 *
	 * @since 1.0.0
	 */
	public static function render_dashboard() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to view this page.', 'beaver-image-optimizer' ) );
		}

		$stats    = Beaver_Image_Optimizer::get_stats();
		$settings = Beaver_Image_Optimizer::get_settings();
		$pending  = Beaver_Image_Optimizer::count_pending();
		$total    = Beaver_Image_Optimizer::count_total();
		$delivery = Beaver_Image_Optimizer::test_delivery();
		?>
		<div class="wrap beaver-io">
			<h1><?php esc_html_e( 'Beaver Image Optimizer', 'beaver-image-optimizer' ); ?></h1>
			<p class="beaver-io-lead">
				<?php esc_html_e( 'WebP conversion that runs entirely inside PHP — no root access, no command line, no third party service.', 'beaver-image-optimizer' ); ?>
			</p>

			<div class="beaver-io-cards">
				<div class="beaver-io-card">
					<span class="beaver-io-card__label"><?php esc_html_e( 'Images optimized', 'beaver-image-optimizer' ); ?></span>
					<strong class="beaver-io-card__value" data-stat="images"><?php echo esc_html( number_format_i18n( $stats['images'] ) ); ?></strong>
					<span class="beaver-io-card__hint">
						<?php
						printf(
							/* translators: %s: number of individual files. */
							esc_html__( '%s files converted', 'beaver-image-optimizer' ),
							esc_html( number_format_i18n( $stats['files'] ) )
						);
						?>
					</span>
				</div>

				<div class="beaver-io-card">
					<span class="beaver-io-card__label"><?php esc_html_e( 'Bandwidth saved', 'beaver-image-optimizer' ); ?></span>
					<strong class="beaver-io-card__value" data-stat="savedBytes"><?php echo esc_html( size_format( $stats['saved_bytes'], 2 ) ); ?></strong>
					<span class="beaver-io-card__hint">
						<?php
						printf(
							/* translators: 1: original total size, 2: optimized total size. */
							esc_html__( '%1$s → %2$s', 'beaver-image-optimizer' ),
							esc_html( size_format( $stats['original_bytes'], 1 ) ),
							esc_html( size_format( $stats['webp_bytes'], 1 ) )
						);
						?>
					</span>
				</div>

				<div class="beaver-io-card">
					<span class="beaver-io-card__label"><?php esc_html_e( 'Average compression', 'beaver-image-optimizer' ); ?></span>
					<strong class="beaver-io-card__value" data-stat="savedPercent"><?php echo esc_html( $stats['saved_percent'] ); ?></strong><span class="beaver-io-card__unit">%</span>
					<span class="beaver-io-card__hint">
						<?php
						printf(
							/* translators: %s: quality preset name. */
							esc_html__( 'Quality preset: %s', 'beaver-image-optimizer' ),
							esc_html( self::preset_label( $settings['quality_preset'] ) )
						);
						?>
					</span>
				</div>

				<div class="beaver-io-card">
					<span class="beaver-io-card__label"><?php esc_html_e( 'Disk space freed', 'beaver-image-optimizer' ); ?></span>
					<strong class="beaver-io-card__value" data-stat="diskFreed"><?php echo esc_html( size_format( $stats['disk_freed'], 2 ) ); ?></strong>
					<span class="beaver-io-card__hint">
						<?php
						echo empty( $settings['keep_originals'] )
							? esc_html__( 'Originals are being deleted', 'beaver-image-optimizer' )
							: esc_html__( 'Originals are kept as backup', 'beaver-image-optimizer' );
						?>
					</span>
				</div>
			</div>

			<div class="beaver-io-columns">
				<div class="beaver-io-panel">
					<h2><?php esc_html_e( 'Library status', 'beaver-image-optimizer' ); ?></h2>
					<table class="beaver-io-table">
						<tbody>
							<tr>
								<th scope="row"><?php esc_html_e( 'Convertible images', 'beaver-image-optimizer' ); ?></th>
								<td><?php echo esc_html( number_format_i18n( $total ) ); ?></td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Waiting to be optimized', 'beaver-image-optimizer' ); ?></th>
								<td>
									<?php echo esc_html( number_format_i18n( $pending ) ); ?>
									<?php if ( $pending > 0 ) : ?>
										<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::TOOLS_SLUG ) ); ?>"><?php esc_html_e( 'Optimize now', 'beaver-image-optimizer' ); ?></a>
									<?php endif; ?>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Skipped (no gain)', 'beaver-image-optimizer' ); ?></th>
								<td><?php echo esc_html( number_format_i18n( $stats['skipped'] ) ); ?></td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Failed', 'beaver-image-optimizer' ); ?></th>
								<td><?php echo esc_html( number_format_i18n( $stats['failed'] ) ); ?></td>
							</tr>
						</tbody>
					</table>
				</div>

				<div class="beaver-io-panel">
					<h2><?php esc_html_e( 'Server health', 'beaver-image-optimizer' ); ?></h2>
					<?php self::render_health( $delivery ); ?>
				</div>
			</div>

			<?php self::render_errors(); ?>

			<?php self::render_credit(); ?>
		</div>
		<?php
	}

	/**
	 * Renders the server health checklist.
	 *
	 * @since 1.0.0
	 *
	 * @param array $delivery Result of the delivery test.
	 */
	private static function render_health( $delivery ) {
		$engine    = Beaver_Image_Converter::engine();
		$htaccess  = Beaver_Image_Optimizer::htaccess_path();
		$installed = Beaver_Image_Optimizer::delivery_rules_installed();

		$checks = array(
			array(
				'ok'    => '' !== $engine,
				'label' => __( 'WebP encoder', 'beaver-image-optimizer' ),
				'value' => '' !== $engine
					? sprintf(
						/* translators: %s: imaging library name. */
						__( 'Available via %s', 'beaver-image-optimizer' ),
						'imagick' === $engine ? 'Imagick' : 'GD'
					)
					: __( 'Not available on this server', 'beaver-image-optimizer' ),
			),
			array(
				'ok'    => Beaver_Image_Optimizer::server_supports_htaccess(),
				'label' => __( 'Web server', 'beaver-image-optimizer' ),
				'value' => Beaver_Image_Optimizer::server_supports_htaccess()
					? __( 'Apache or LiteSpeed — rewrite delivery supported', 'beaver-image-optimizer' )
					: __( 'No .htaccess support detected — use the picture element fallback', 'beaver-image-optimizer' ),
			),
			array(
				'ok'    => $installed,
				'label' => __( 'Rewrite rules', 'beaver-image-optimizer' ),
				'value' => $installed
					? __( 'Installed in wp-content/uploads/.htaccess', 'beaver-image-optimizer' )
					: __( 'Not installed', 'beaver-image-optimizer' ),
			),
			array(
				'ok'    => 'working' === $delivery['status'],
				'warn'  => 'untested' === $delivery['status'],
				'label' => __( 'Live delivery test', 'beaver-image-optimizer' ),
				'value' => $delivery['message'],
			),
			array(
				'ok'    => '' !== $htaccess && ( ! file_exists( $htaccess ) ? is_writable( dirname( $htaccess ) ) : is_writable( $htaccess ) ),
				'label' => __( 'Uploads folder writable', 'beaver-image-optimizer' ),
				'value' => '' === $htaccess
					? __( 'Uploads directory unavailable', 'beaver-image-optimizer' )
					: esc_html( $htaccess ),
			),
			array(
				'ok'    => true,
				'label' => __( 'PHP memory limit', 'beaver-image-optimizer' ),
				'value' => (string) ini_get( 'memory_limit' ),
			),
			array(
				'ok'    => true,
				'label' => __( 'Max execution time', 'beaver-image-optimizer' ),
				'value' => sprintf(
					/* translators: %s: number of seconds. */
					__( '%s seconds', 'beaver-image-optimizer' ),
					(string) ini_get( 'max_execution_time' )
				),
			),
		);

		echo '<ul class="beaver-io-health">';

		foreach ( $checks as $check ) {
			$state = ! empty( $check['warn'] ) ? 'warn' : ( $check['ok'] ? 'ok' : 'bad' );

			printf(
				'<li class="beaver-io-health__item beaver-io-health__item--%1$s"><span class="beaver-io-health__label">%2$s</span><span class="beaver-io-health__value">%3$s</span></li>',
				esc_attr( $state ),
				esc_html( $check['label'] ),
				esc_html( $check['value'] )
			);
		}

		echo '</ul>';
		?>
		<p class="beaver-io-actions">
			<button type="button" class="button" id="beaver-io-test-delivery"><?php esc_html_e( 'Re-run delivery test', 'beaver-image-optimizer' ); ?></button>
			<button type="button" class="button" id="beaver-io-write-rules"><?php esc_html_e( 'Reinstall rewrite rules', 'beaver-image-optimizer' ); ?></button>
		</p>
		<div class="beaver-io-inline-notice" id="beaver-io-health-notice" hidden></div>
		<?php
	}

	/**
	 * Renders the bulk optimize screen.
	 *
	 * @since 1.0.0
	 */
	public static function render_tools() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to view this page.', 'beaver-image-optimizer' ) );
		}

		$queue     = Beaver_Image_Optimizer::get_queue();
		$has_queue = '' !== $queue['ids'];
		$pending   = Beaver_Image_Optimizer::count_pending();
		$total     = Beaver_Image_Optimizer::count_total();
		?>
		<div class="wrap beaver-io">
			<h1><?php esc_html_e( 'Bulk Optimize', 'beaver-image-optimizer' ); ?></h1>
			<p class="beaver-io-lead">
				<?php esc_html_e( 'Images are processed in small batches straight from your browser, so the job never trips a shared host execution limit. You can close this tab and resume later.', 'beaver-image-optimizer' ); ?>
			</p>

			<div class="beaver-io-panel">
				<h2><?php esc_html_e( 'Scan media library', 'beaver-image-optimizer' ); ?></h2>
				<p>
					<?php
					printf(
						/* translators: 1: pending image count, 2: total image count. */
						esc_html__( '%1$s of %2$s images have not been optimized yet.', 'beaver-image-optimizer' ),
						'<strong>' . esc_html( number_format_i18n( $pending ) ) . '</strong>',
						'<strong>' . esc_html( number_format_i18n( $total ) ) . '</strong>'
					);
					?>
				</p>

				<?php if ( $has_queue ) : ?>
					<div class="notice notice-info inline">
						<p>
							<?php
							printf(
								/* translators: 1: completed count, 2: queue total. */
								esc_html__( 'An unfinished job is waiting: %1$s of %2$s processed.', 'beaver-image-optimizer' ),
								'<strong>' . esc_html( number_format_i18n( $queue['done'] ) ) . '</strong>',
								'<strong>' . esc_html( number_format_i18n( $queue['total'] ) ) . '</strong>'
							);
							?>
						</p>
					</div>
				<?php endif; ?>

				<p class="beaver-io-actions">
					<button type="button" class="button button-primary" id="beaver-io-start" data-resume="<?php echo $has_queue ? '1' : '0'; ?>">
						<?php echo $has_queue ? esc_html__( 'Resume optimizing', 'beaver-image-optimizer' ) : esc_html__( 'Optimize all images', 'beaver-image-optimizer' ); ?>
					</button>
					<button type="button" class="button" id="beaver-io-force">
						<?php esc_html_e( 'Re-optimize everything', 'beaver-image-optimizer' ); ?>
					</button>
					<button type="button" class="button" id="beaver-io-stop" hidden>
						<?php esc_html_e( 'Stop', 'beaver-image-optimizer' ); ?>
					</button>
				</p>

				<div class="beaver-io-progress" id="beaver-io-progress" hidden>
					<div class="beaver-io-progress__bar"><span id="beaver-io-progress-fill"></span></div>
					<p class="beaver-io-progress__text" id="beaver-io-progress-text"></p>
				</div>

				<div class="beaver-io-log" id="beaver-io-log" hidden></div>
			</div>

			<div class="beaver-io-panel">
				<h2><?php esc_html_e( 'Maintenance', 'beaver-image-optimizer' ); ?></h2>
				<p><?php esc_html_e( 'Resetting statistics only clears the counters shown on the dashboard. No image files are touched.', 'beaver-image-optimizer' ); ?></p>
				<p class="beaver-io-actions">
					<button type="button" class="button" id="beaver-io-reset-stats"><?php esc_html_e( 'Reset statistics', 'beaver-image-optimizer' ); ?></button>
				</p>
				<div class="beaver-io-inline-notice" id="beaver-io-tools-notice" hidden></div>
			</div>

			<?php self::render_credit(); ?>
		</div>
		<?php
	}

	/**
	 * Renders the settings screen.
	 *
	 * @since 1.0.0
	 */
	public static function render_settings() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to view this page.', 'beaver-image-optimizer' ) );
		}

		$settings = Beaver_Image_Optimizer::get_settings();
		$presets  = Beaver_Image_Converter::quality_presets();
		$option   = Beaver_Image_Optimizer::OPTION_SETTINGS;
		?>
		<div class="wrap beaver-io">
			<h1><?php esc_html_e( 'Beaver Image Optimizer Settings', 'beaver-image-optimizer' ); ?></h1>

			<form method="post" action="options.php">
				<?php settings_fields( 'beaver_io_settings_group' ); ?>

				<h2 class="title"><?php esc_html_e( 'Conversion', 'beaver-image-optimizer' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'WebP quality', 'beaver-image-optimizer' ); ?></th>
						<td>
							<fieldset>
								<legend class="screen-reader-text"><?php esc_html_e( 'WebP quality', 'beaver-image-optimizer' ); ?></legend>
								<?php foreach ( $presets as $key => $value ) : ?>
									<label class="beaver-io-radio">
										<input type="radio" name="<?php echo esc_attr( $option ); ?>[quality_preset]" value="<?php echo esc_attr( $key ); ?>" <?php checked( $settings['quality_preset'], $key ); ?> />
										<span><?php echo esc_html( self::preset_label( $key ) ); ?> <code><?php echo esc_html( $value ); ?>%</code></span>
										<em><?php echo esc_html( self::preset_hint( $key ) ); ?></em>
									</label>
								<?php endforeach; ?>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Auto optimize uploads', 'beaver-image-optimizer' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $option ); ?>[auto_optimize]" value="1" <?php checked( $settings['auto_optimize'], 1 ); ?> />
								<?php esc_html_e( 'Convert new images as soon as they are uploaded', 'beaver-image-optimizer' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'File types', 'beaver-image-optimizer' ); ?></th>
						<td>
							<fieldset>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( $option ); ?>[file_types][]" value="image/jpeg" <?php checked( in_array( 'image/jpeg', (array) $settings['file_types'], true ) ); ?> />
									<?php esc_html_e( 'JPEG', 'beaver-image-optimizer' ); ?>
								</label><br />
								<label>
									<input type="checkbox" name="<?php echo esc_attr( $option ); ?>[file_types][]" value="image/png" <?php checked( in_array( 'image/png', (array) $settings['file_types'], true ) ); ?> />
									<?php esc_html_e( 'PNG', 'beaver-image-optimizer' ); ?>
								</label>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="beaver-io-min-gain"><?php esc_html_e( 'Minimum saving', 'beaver-image-optimizer' ); ?></label></th>
						<td>
							<input type="number" id="beaver-io-min-gain" class="small-text" min="0" max="90" name="<?php echo esc_attr( $option ); ?>[min_gain]" value="<?php echo esc_attr( $settings['min_gain'] ); ?>" /> %
							<p class="description"><?php esc_html_e( 'Discard the WebP version unless it is at least this much smaller. Stops flat PNG graphics from growing.', 'beaver-image-optimizer' ); ?></p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Originals', 'beaver-image-optimizer' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Backup originals', 'beaver-image-optimizer' ); ?></th>
						<td>
							<fieldset>
								<label class="beaver-io-radio">
									<input type="radio" name="<?php echo esc_attr( $option ); ?>[keep_originals]" value="1" <?php checked( $settings['keep_originals'], 1 ); ?> />
									<span><?php esc_html_e( 'Keep originals', 'beaver-image-optimizer' ); ?></span>
									<em><?php esc_html_e( 'Recommended. WebP is served automatically to supported browsers and the original stays as a fallback.', 'beaver-image-optimizer' ); ?></em>
								</label>
								<label class="beaver-io-radio">
									<input type="radio" name="<?php echo esc_attr( $option ); ?>[keep_originals]" value="0" <?php checked( $settings['keep_originals'], 0 ); ?> />
									<span><?php esc_html_e( 'Delete originals after successful conversion', 'beaver-image-optimizer' ); ?></span>
									<em><?php esc_html_e( 'Frees disk space, but there is no way back. Only files that converted successfully are removed.', 'beaver-image-optimizer' ); ?></em>
								</label>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Large image limit', 'beaver-image-optimizer' ); ?></th>
						<td>
							<label>
								<?php esc_html_e( 'Width', 'beaver-image-optimizer' ); ?>
								<input type="number" class="small-text" min="0" max="10000" name="<?php echo esc_attr( $option ); ?>[max_width]" value="<?php echo esc_attr( $settings['max_width'] ); ?>" />
							</label>
							<label>
								<?php esc_html_e( 'Height', 'beaver-image-optimizer' ); ?>
								<input type="number" class="small-text" min="0" max="10000" name="<?php echo esc_attr( $option ); ?>[max_height]" value="<?php echo esc_attr( $settings['max_height'] ); ?>" />
							</label>
							<p class="description"><?php esc_html_e( 'Oversized full size images are scaled down in the WebP copy only. Set either value to 0 to disable.', 'beaver-image-optimizer' ); ?></p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Delivery and performance', 'beaver-image-optimizer' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Lazy loading', 'beaver-image-optimizer' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $option ); ?>[lazy_load]" value="1" <?php checked( $settings['lazy_load'], 1 ); ?> />
								<?php esc_html_e( 'Use native browser lazy loading for images below the fold', 'beaver-image-optimizer' ); ?>
							</label>
							<p>
								<label>
									<?php esc_html_e( 'Never lazy load the first', 'beaver-image-optimizer' ); ?>
									<input type="number" class="small-text" min="0" max="10" name="<?php echo esc_attr( $option ); ?>[lazy_skip_first]" value="<?php echo esc_attr( $settings['lazy_skip_first'] ); ?>" />
									<?php esc_html_e( 'images', 'beaver-image-optimizer' ); ?>
								</label>
							</p>
							<p class="description"><?php esc_html_e( 'Lazy loading the largest image at the top of a page delays it, which hurts Core Web Vitals.', 'beaver-image-optimizer' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Picture element fallback', 'beaver-image-optimizer' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $option ); ?>[picture_fallback]" value="1" <?php checked( $settings['picture_fallback'], 1 ); ?> />
								<?php esc_html_e( 'Wrap post content images in a picture element', 'beaver-image-optimizer' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Only needed when the rewrite rules cannot run, for example on Nginx. Leave this off on Apache and LiteSpeed hosts such as Namecheap.', 'beaver-image-optimizer' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="beaver-io-batch"><?php esc_html_e( 'Bulk batch size', 'beaver-image-optimizer' ); ?></label></th>
						<td>
							<input type="number" id="beaver-io-batch" class="small-text" min="1" max="20" name="<?php echo esc_attr( $option ); ?>[batch_size]" value="<?php echo esc_attr( $settings['batch_size'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Images per request during bulk optimization. Lower this if your host times out.', 'beaver-image-optimizer' ); ?></p>
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
	 * Returns the translated label for a quality preset.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key Preset key.
	 * @return string Label.
	 */
	private static function preset_label( $key ) {
		$labels = array(
			'high'     => __( 'High quality', 'beaver-image-optimizer' ),
			'balanced' => __( 'Balanced', 'beaver-image-optimizer' ),
			'max'      => __( 'Maximum compression', 'beaver-image-optimizer' ),
		);

		return isset( $labels[ $key ] ) ? $labels[ $key ] : $key;
	}

	/**
	 * Returns the translated hint for a quality preset.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key Preset key.
	 * @return string Hint.
	 */
	private static function preset_hint( $key ) {
		$hints = array(
			'high'     => __( 'Visually lossless. Best for photography portfolios and print-like detail.', 'beaver-image-optimizer' ),
			'balanced' => __( 'The sweet spot for most sites. Roughly 60-70% smaller than the JPEG original.', 'beaver-image-optimizer' ),
			'max'      => __( 'Smallest files. Fine for thumbnails and background imagery.', 'beaver-image-optimizer' ),
		);

		return isset( $hints[ $key ] ) ? $hints[ $key ] : '';
	}
}
