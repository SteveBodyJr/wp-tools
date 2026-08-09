<?php
/**
 * Admin screens, review queue and AJAX endpoints.
 *
 * @package BeaverAltText
 */

defined( 'ABSPATH' ) || exit;

/**
 * Everything the site owner sees.
 *
 * @since 1.0.0
 */
class Beaver_Alt_Admin {

	const MENU_SLUG     = 'beaver-alt';
	const REVIEW_SLUG   = 'beaver-alt-review';
	const SETTINGS_SLUG = 'beaver-alt-settings';
	const NONCE_ACTION  = 'beaver_alt_ajax';
	const CAPABILITY    = 'manage_options';

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

		add_action( 'add_option_' . Beaver_Alt_Generator::OPTION_SETTINGS, array( 'Beaver_Alt_Generator', 'flush_settings_cache' ) );
		add_action( 'update_option_' . Beaver_Alt_Generator::OPTION_SETTINGS, array( 'Beaver_Alt_Generator', 'flush_settings_cache' ) );

		add_filter( 'manage_media_columns', array( __CLASS__, 'add_media_column' ) );
		add_action( 'manage_media_custom_column', array( __CLASS__, 'render_media_column' ), 10, 2 );

		add_action( 'wp_ajax_beaver_alt_scan', array( __CLASS__, 'ajax_scan' ) );
		add_action( 'wp_ajax_beaver_alt_batch', array( __CLASS__, 'ajax_batch' ) );
		add_action( 'wp_ajax_beaver_alt_cancel', array( __CLASS__, 'ajax_cancel' ) );
		add_action( 'wp_ajax_beaver_alt_decide', array( __CLASS__, 'ajax_decide' ) );
		add_action( 'wp_ajax_beaver_alt_single', array( __CLASS__, 'ajax_single' ) );
		add_action( 'wp_ajax_beaver_alt_bulk_approve', array( __CLASS__, 'ajax_bulk_approve' ) );
		add_action( 'wp_ajax_beaver_alt_reset_stats', array( __CLASS__, 'ajax_reset_stats' ) );
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
		$pending = self::pending_review_count();
		$bubble  = $pending > 0 ? sprintf( ' <span class="update-plugins count-%1$d"><span class="update-count">%1$d</span></span>', $pending ) : '';

		add_menu_page(
			__( 'Beaver Alt Text', 'beaver-alt-text' ),
			__( 'Alt Text', 'beaver-alt-text' ) . $bubble,
			self::CAPABILITY,
			self::MENU_SLUG,
			array( __CLASS__, 'render_dashboard' ),
			'dashicons-universal-access-alt',
			82
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Dashboard', 'beaver-alt-text' ),
			__( 'Dashboard', 'beaver-alt-text' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( __CLASS__, 'render_dashboard' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Review', 'beaver-alt-text' ),
			__( 'Review', 'beaver-alt-text' ) . $bubble,
			self::CAPABILITY,
			self::REVIEW_SLUG,
			array( __CLASS__, 'render_review' )
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Settings', 'beaver-alt-text' ),
			__( 'Settings', 'beaver-alt-text' ),
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

		wp_enqueue_style( 'beaver-alt-admin', BEAVER_ALT_URL . 'admin/css/admin.css', array(), BEAVER_ALT_VERSION );
		wp_enqueue_script( 'beaver-alt-admin', BEAVER_ALT_URL . 'admin/js/admin.js', array(), BEAVER_ALT_VERSION, true );

		wp_localize_script(
			'beaver-alt-admin',
			'beaverAlt',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
				'i18n'    => array(
					'scanning'   => __( 'Looking for images without alt text…', 'beaver-alt-text' ),
					'none'       => __( 'Every image already has alt text.', 'beaver-alt-text' ),
					'working'    => __( 'Writing alt text…', 'beaver-alt-text' ),
					'complete'   => __( 'All done. Anything waiting for review is on the Review screen.', 'beaver-alt-text' ),
					'cancelled'  => __( 'Stopped. Press Start to pick up where you left off.', 'beaver-alt-text' ),
					'recovering' => __( 'The server stopped on one image. Skipping it and continuing…', 'beaver-alt-text' ),
					'failed'     => __( 'Something went wrong. Check the log below.', 'beaver-alt-text' ),
					'confirm'    => __( 'This re-describes every image, including ones already done. Continue?', 'beaver-alt-text' ),
					'working1'   => __( 'Working…', 'beaver-alt-text' ),
					'proceed'    => __( 'Start writing alt text?', 'beaver-alt-text' ),
					'locked'     => __( 'Another run is already in progress, possibly in a different browser tab. Wait for it to finish.', 'beaver-alt-text' ),
				),
			)
		);
	}

	/**
	 * Warns when the plugin cannot reach a model.
	 *
	 * @since 1.0.0
	 */
	public static function render_notices() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || false === strpos( (string) $screen->id, self::MENU_SLUG ) ) {
			return;
		}

		$problem = Beaver_Alt_Provider::configuration_problem();

		if ( '' === $problem ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
			esc_html__( 'Not ready yet.', 'beaver-alt-text' ),
			esc_html( $problem ),
			esc_url( admin_url( 'admin.php?page=' . self::SETTINGS_SLUG ) ),
			esc_html__( 'Open settings', 'beaver-alt-text' )
		);
	}

	/*
	 * -----------------------------------------------------------------------
	 * Settings
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Registers the settings group.
	 *
	 * @since 1.0.0
	 */
	public static function register_settings() {
		register_setting(
			'beaver_alt_settings_group',
			Beaver_Alt_Generator::OPTION_SETTINGS,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'Beaver_Alt_Generator', 'sanitize_settings' ),
				'default'           => Beaver_Alt_Generator::default_settings(),
			)
		);
	}

	/*
	 * -----------------------------------------------------------------------
	 * AJAX
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Rejects a request that is not a legitimate admin action.
	 *
	 * @since 1.0.0
	 */
	private static function verify_request() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'beaver-alt-text' ) ), 403 );
		}

		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
	}

	/**
	 * Builds the queue.
	 *
	 * @since 1.0.0
	 */
	public static function ajax_scan() {
		self::verify_request();

		$force  = ! empty( $_POST['force'] );
		$resume = ! empty( $_POST['resume'] );

		if ( $resume ) {
			$queue = Beaver_Alt_Queue::get();

			if ( '' !== $queue['ids'] ) {
				wp_send_json_success(
					array(
						'total' => (int) $queue['total'],
						'done'  => (int) $queue['done'],
						'stats' => self::stats_payload(),
					)
				);
			}
		}

		$queue    = Beaver_Alt_Queue::build( $force );
		$estimate = Beaver_Alt_Queue::estimate( (int) $queue['total'] );

		wp_send_json_success(
			array(
				'total'    => (int) $queue['total'],
				'done'     => 0,
				'stats'    => self::stats_payload(),
				'estimate' => self::format_estimate( $estimate ),
			)
		);
	}

	/**
	 * Runs one slice of the queue.
	 *
	 * @since 1.0.0
	 */
	public static function ajax_batch() {
		self::verify_request();

		/*
		 * An image can exhaust the memory limit or the execution time, and PHP
		 * answers that with a bare HTTP 500 carrying no explanation. Reserving a
		 * slice of memory and handing it back during shutdown leaves enough room
		 * to attribute the crash and reply with a normal batch response.
		 */
		self::$fatal_reserve = str_repeat( '0', 256 * 1024 );
		self::$batch_done    = false;

		register_shutdown_function( array( __CLASS__, 'handle_batch_fatal' ) );

		$run = Beaver_Alt_Queue::run_batch();

		$run['stats'] = self::stats_payload();

		self::$batch_done    = true;
		self::$fatal_reserve = null;

		wp_send_json_success( $run );
	}

	/**
	 * Turns a fatal error during a batch into a valid response.
	 *
	 * @since 1.0.0
	 */
	public static function handle_batch_fatal() {
		if ( self::$batch_done ) {
			return;
		}

		self::$fatal_reserve = null;

		$error = error_get_last();
		$fatal = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR );

		if ( null === $error || ! in_array( $error['type'], $fatal, true ) || headers_sent() ) {
			return;
		}

		Beaver_Alt_Queue::release_lock();

		$recovered = Beaver_Alt_Queue::recover_inflight( $error );
		$queue     = Beaver_Alt_Queue::get();

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

		Beaver_Alt_Queue::clear();
		Beaver_Alt_Queue::clear_inflight();

		wp_send_json_success( array( 'message' => __( 'Stopped.', 'beaver-alt-text' ) ) );
	}

	/**
	 * Approves, edits or rejects a proposal.
	 *
	 * @since 1.0.0
	 */
	public static function ajax_decide() {
		self::verify_request();

		$id       = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$decision = isset( $_POST['decision'] ) ? sanitize_key( wp_unslash( $_POST['decision'] ) ) : '';

		if ( $id <= 0 || 'attachment' !== get_post_type( $id ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown image.', 'beaver-alt-text' ) ), 404 );
		}

		if ( ! current_user_can( 'edit_post', $id ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to edit this image.', 'beaver-alt-text' ) ), 403 );
		}

		if ( 'reject' === $decision ) {
			Beaver_Alt_Generator::reject( $id );

			wp_send_json_success(
				array(
					'id'      => $id,
					'message' => __( 'Discarded.', 'beaver-alt-text' ),
					'pending' => self::pending_review_count(),
				)
			);
		}

		if ( 'approve' !== $decision ) {
			wp_send_json_error( array( 'message' => __( 'Unknown decision.', 'beaver-alt-text' ) ), 400 );
		}

		// An edited value from the review screen replaces the proposal before it
		// is written, so a correction is saved as the reviewer typed it.
		if ( isset( $_POST['alt'] ) ) {
			$proposal = get_post_meta( $id, Beaver_Alt_Generator::META_PROPOSAL, true );

			if ( is_array( $proposal ) ) {
				$edited = sanitize_text_field( wp_unslash( $_POST['alt'] ) );

				if ( $edited !== (string) $proposal['alt'] ) {
					$proposal['alt']        = $edited;
					$proposal['decorative'] = ( '' === $edited );
					$proposal['edited']     = true;

					update_post_meta( $id, Beaver_Alt_Generator::META_PROPOSAL, $proposal );
				}
			}
		}

		$applied = Beaver_Alt_Generator::apply( $id );

		if ( is_wp_error( $applied ) ) {
			wp_send_json_error( array( 'message' => $applied->get_error_message() ), 409 );
		}

		wp_send_json_success(
			array(
				'id'      => $id,
				'message' => __( 'Saved.', 'beaver-alt-text' ),
				'pending' => self::pending_review_count(),
				'stats'   => self::stats_payload(),
			)
		);
	}

	/**
	 * Describes one image on demand.
	 *
	 * @since 1.0.0
	 */
	public static function ajax_single() {
		self::verify_request();

		$id    = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$force = ! empty( $_POST['force'] );

		if ( $id <= 0 || 'attachment' !== get_post_type( $id ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown image.', 'beaver-alt-text' ) ), 404 );
		}

		if ( ! current_user_can( 'edit_post', $id ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to edit this image.', 'beaver-alt-text' ) ), 403 );
		}

		$result = Beaver_Alt_Generator::generate( $id, $force, Beaver_Alt_Queue::time_budget() );

		wp_send_json_success(
			array(
				'id'      => $id,
				'status'  => $result['status'],
				'message' => $result['message'],
				'html'    => self::media_column_html( $id ),
				'pending' => self::pending_review_count(),
			)
		);
	}

	/**
	 * Approves every waiting suggestion at or above a confidence level.
	 *
	 * @since 1.2.0
	 */
	public static function ajax_bulk_approve() {
		self::verify_request();

		$rank      = array( 'low' => 1, 'medium' => 2, 'high' => 3 );
		$level     = isset( $_POST['confidence'] ) ? sanitize_key( wp_unslash( $_POST['confidence'] ) ) : 'high';
		$threshold = $rank[ $level ] ?? 3;

		$applied = 0;
		$held    = 0;
		$blocked = 0;

		foreach ( self::pending_review_ids( 200 ) as $id ) {
			$proposal = get_post_meta( $id, Beaver_Alt_Generator::META_PROPOSAL, true );

			if ( ! is_array( $proposal ) || ! current_user_can( 'edit_post', $id ) ) {
				continue;
			}

			if ( ( $rank[ (string) ( $proposal['confidence'] ?? 'low' ) ] ?? 1 ) < $threshold ) {
				++$held;
				continue;
			}

			if ( is_wp_error( Beaver_Alt_Generator::apply( $id ) ) ) {
				++$blocked;
				continue;
			}

			++$applied;
		}

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: 1: number published, 2: number left for review. */
					__( '%1$s published, %2$s left for review.', 'beaver-alt-text' ),
					number_format_i18n( $applied ),
					number_format_i18n( $held + $blocked )
				),
				'applied' => $applied,
				'pending' => self::pending_review_count(),
				'stats'   => self::stats_payload(),
			)
		);
	}

	/**
	 * Turns a raw estimate into a sentence.
	 *
	 * @since 1.2.0
	 *
	 * @param array $estimate Result of Beaver_Alt_Queue::estimate().
	 * @return string Translated sentence.
	 */
	private static function format_estimate( $estimate ) {
		if ( $estimate['images'] < 1 ) {
			return '';
		}

		return sprintf(
			/* translators: 1: image count, 2: token count, 3: approximate cost. */
			__( '%1$s images, roughly %2$s tokens — about %3$s at the rates in your settings.', 'beaver-alt-text' ),
			number_format_i18n( $estimate['images'] ),
			number_format_i18n( $estimate['input'] + $estimate['output'] ),
			'$' . number_format_i18n( round( $estimate['cost'], 2 ), 2 )
		);
	}

	/**
	 * Clears the counters.
	 *
	 * @since 1.0.0
	 */
	public static function ajax_reset_stats() {
		self::verify_request();
		Beaver_Alt_Generator::reset_stats();

		wp_send_json_success( array( 'stats' => self::stats_payload() ) );
	}

	/**
	 * Formats the counters for the browser.
	 *
	 * @since 1.0.0
	 *
	 * @return array Stats.
	 */
	private static function stats_payload() {
		$stats = Beaver_Alt_Generator::get_stats();

		return array(
			'generated' => number_format_i18n( $stats['generated'] ),
			'applied'   => number_format_i18n( $stats['applied'] ),
			'rejected'  => number_format_i18n( $stats['rejected'] ),
			'failed'    => number_format_i18n( $stats['failed'] ),
			'tokens'    => number_format_i18n( $stats['input_tokens'] + $stats['output_tokens'] ),
			'pending'   => number_format_i18n( self::pending_review_count() ),
		);
	}

	/*
	 * -----------------------------------------------------------------------
	 * Review data
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Returns attachment IDs with a proposal waiting for review.
	 *
	 * @since 1.0.0
	 *
	 * @param int $limit Maximum rows.
	 * @return int[] Attachment IDs.
	 */
	public static function pending_review_ids( $limit = 50, $page = 1 ) {
		return array_map(
			'intval',
			(array) get_posts(
				array(
					'post_type'              => 'attachment',
					'post_status'            => 'inherit',
					'posts_per_page'         => (int) $limit,
					'paged'                  => max( 1, (int) $page ),
					'fields'                 => 'ids',
					'orderby'                => 'modified',
					'order'                  => 'DESC',
					'no_found_rows'          => true,
					'update_post_term_cache' => false,
					'meta_query'             => array(
						array(
							'key'     => Beaver_Alt_Generator::META_PROPOSAL,
							'compare' => 'EXISTS',
						),
					),
				)
			)
		);
	}

	/**
	 * Counts proposals waiting for review.
	 *
	 * @since 1.0.0
	 *
	 * @return int Count.
	 */
	public static function pending_review_count() {
		$cached = get_transient( Beaver_Alt_Queue::TRANSIENT_REVIEW );

		if ( false !== $cached ) {
			return (int) $cached;
		}

		/*
		 * This drives the menu bubble, so it runs on every admin page load —
		 * counting 500 rows there was pure overhead. One COUNT query, cached
		 * until something changes a proposal.
		 */
		$query = new WP_Query(
			array(
				'post_type'              => 'attachment',
				'post_status'            => 'inherit',
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => array(
					array(
						'key'     => Beaver_Alt_Generator::META_PROPOSAL,
						'compare' => 'EXISTS',
					),
				),
			)
		);

		$count = (int) $query->found_posts;

		set_transient( Beaver_Alt_Queue::TRANSIENT_REVIEW, $count, 5 * MINUTE_IN_SECONDS );

		return $count;
	}

	/*
	 * -----------------------------------------------------------------------
	 * Media library column
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Adds the alt text column.
	 *
	 * @since 1.0.0
	 *
	 * @param array $columns Existing columns.
	 * @return array Filtered columns.
	 */
	public static function add_media_column( $columns ) {
		$columns['beaver_alt'] = __( 'Alt text', 'beaver-alt-text' );

		return $columns;
	}

	/**
	 * Renders the alt text column.
	 *
	 * @since 1.0.0
	 *
	 * @param string $column_name   Column key.
	 * @param int    $attachment_id Attachment ID.
	 */
	public static function render_media_column( $column_name, $attachment_id ) {
		if ( 'beaver_alt' !== $column_name ) {
			return;
		}

		echo wp_kses_post( self::media_column_html( $attachment_id ) );
	}

	/**
	 * Builds one media column cell.
	 *
	 * @since 1.0.0
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string HTML.
	 */
	private static function media_column_html( $attachment_id ) {
		$mime = get_post_mime_type( $attachment_id );

		if ( ! in_array( $mime, Beaver_Alt_Provider::SUPPORTED_MIME_TYPES, true ) ) {
			return '<span class="beaver-alt-muted">&mdash;</span>';
		}

		$alt       = (string) get_post_meta( $attachment_id, Beaver_Alt_Generator::META_ALT, true );
		$generated = get_post_meta( $attachment_id, Beaver_Alt_Generator::META_GENERATED, true );
		$proposal  = get_post_meta( $attachment_id, Beaver_Alt_Generator::META_PROPOSAL, true );
		$error     = get_post_meta( $attachment_id, Beaver_Alt_Generator::META_ERROR, true );
		$out       = '';

		if ( is_array( $proposal ) ) {
			$out .= '<span class="beaver-alt-badge beaver-alt-badge--review">' . esc_html__( 'Waiting for review', 'beaver-alt-text' ) . '</span>';
			$out .= '<span class="beaver-alt-preview">' . esc_html( '' === $proposal['alt'] ? __( '(decorative — empty alt)', 'beaver-alt-text' ) : $proposal['alt'] ) . '</span>';
		} elseif ( is_array( $generated ) && ! empty( $generated['decorative'] ) && '' === trim( $alt ) ) {
			$out .= '<span class="beaver-alt-badge beaver-alt-badge--decorative">' . esc_html__( 'Decorative', 'beaver-alt-text' ) . '</span>';
		} elseif ( '' !== trim( $alt ) ) {
			$is_ours = is_array( $generated ) && isset( $generated['hash'] ) && hash_equals( (string) $generated['hash'], md5( $alt ) );

			$out .= sprintf(
				'<span class="beaver-alt-badge beaver-alt-badge--%1$s">%2$s</span>',
				$is_ours ? 'generated' : 'human',
				$is_ours ? esc_html__( 'Generated', 'beaver-alt-text' ) : esc_html__( 'Written by a person', 'beaver-alt-text' )
			);
			$out .= '<span class="beaver-alt-preview">' . esc_html( $alt ) . '</span>';
		} else {
			$out .= '<span class="beaver-alt-badge beaver-alt-badge--missing">' . esc_html__( 'Missing', 'beaver-alt-text' ) . '</span>';
		}

		if ( is_array( $error ) && ! empty( $error['message'] ) && ! is_array( $proposal ) ) {
			$out .= '<span class="beaver-alt-error">' . esc_html( $error['message'] ) . '</span>';
		}

		$out .= sprintf(
			' <button type="button" class="button-link beaver-alt-row-action" data-id="%d">%s</button>',
			(int) $attachment_id,
			esc_html__( 'Write alt text', 'beaver-alt-text' )
		);

		return $out;
	}

	/*
	 * -----------------------------------------------------------------------
	 * Screens
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Renders the maker's mark shown at the foot of every plugin screen.
	 *
	 * Attribution only — it never renders on the front end.
	 *
	 * @since 1.0.0
	 */
	private static function render_credit() {
		?>
		<div class="beaver-alt-credit">
			<img class="beaver-alt-credit__logo" width="300" height="152"
			     src="<?php echo esc_url( BEAVER_ALT_URL . 'assets/digital-beaver-logo.png' ); ?>"
			     alt="<?php esc_attr_e( 'Digital Beaver', 'beaver-alt-text' ); ?>" />
			<div class="beaver-alt-credit__text">
				<strong><?php esc_html_e( 'Designed & built by Digital Beaver', 'beaver-alt-text' ); ?></strong>
				<?php esc_html_e( 'Need a change, a new feature, or a site as fast as this one?', 'beaver-alt-text' ); ?>
				<a href="https://digitalbeavertz.com/" target="_blank" rel="noopener noreferrer">digitalbeavertz.com</a>
			</div>
		</div>
		<?php
	}

	/**
	 * Renders the dashboard.
	 *
	 * @since 1.0.0
	 */
	public static function render_dashboard() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to view this page.', 'beaver-alt-text' ) );
		}

		$stats   = Beaver_Alt_Generator::get_stats();
		$queue   = Beaver_Alt_Queue::get();
		$pending = Beaver_Alt_Queue::count_pending();
		$total   = Beaver_Alt_Queue::count_total();
		$review  = self::pending_review_count();
		?>
		<div class="wrap beaver-alt">
			<h1><?php esc_html_e( 'Beaver Alt Text', 'beaver-alt-text' ); ?></h1>
			<p class="beaver-alt-lead">
				<?php esc_html_e( 'Writes alt text for images that have none. Nothing is published until you approve it, and alt text written by a person is never touched.', 'beaver-alt-text' ); ?>
			</p>

			<div class="beaver-alt-cards">
				<div class="beaver-alt-card">
					<span class="beaver-alt-card__label"><?php esc_html_e( 'Images missing alt text', 'beaver-alt-text' ); ?></span>
					<strong class="beaver-alt-card__value"><?php echo esc_html( number_format_i18n( $pending ) ); ?></strong>
					<span class="beaver-alt-card__hint">
						<?php
						printf(
							/* translators: %s: total number of images. */
							esc_html__( 'of %s images in the library', 'beaver-alt-text' ),
							esc_html( number_format_i18n( $total ) )
						);
						?>
					</span>
				</div>
				<div class="beaver-alt-card">
					<span class="beaver-alt-card__label"><?php esc_html_e( 'Waiting for review', 'beaver-alt-text' ); ?></span>
					<strong class="beaver-alt-card__value" data-stat="pending"><?php echo esc_html( number_format_i18n( $review ) ); ?></strong>
					<span class="beaver-alt-card__hint">
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::REVIEW_SLUG ) ); ?>"><?php esc_html_e( 'Open the review screen', 'beaver-alt-text' ); ?></a>
					</span>
				</div>
				<div class="beaver-alt-card">
					<span class="beaver-alt-card__label"><?php esc_html_e( 'Published', 'beaver-alt-text' ); ?></span>
					<strong class="beaver-alt-card__value" data-stat="applied"><?php echo esc_html( number_format_i18n( $stats['applied'] ) ); ?></strong>
					<span class="beaver-alt-card__hint"><?php esc_html_e( 'approved and written to the library', 'beaver-alt-text' ); ?></span>
				</div>
				<div class="beaver-alt-card">
					<span class="beaver-alt-card__label"><?php esc_html_e( 'Tokens used', 'beaver-alt-text' ); ?></span>
					<strong class="beaver-alt-card__value" data-stat="tokens"><?php echo esc_html( number_format_i18n( $stats['input_tokens'] + $stats['output_tokens'] ) ); ?></strong>
					<span class="beaver-alt-card__hint"><?php esc_html_e( 'input and output combined', 'beaver-alt-text' ); ?></span>
				</div>
			</div>

			<div class="beaver-alt-panel">
				<h2><?php esc_html_e( 'Write alt text', 'beaver-alt-text' ); ?></h2>

				<?php if ( '' !== $queue['ids'] ) : ?>
					<p class="beaver-alt-resume">
						<?php
						printf(
							/* translators: 1: images done, 2: images in the queue. */
							esc_html__( 'An unfinished run is waiting: %1$s of %2$s done.', 'beaver-alt-text' ),
							esc_html( number_format_i18n( $queue['done'] ) ),
							esc_html( number_format_i18n( $queue['total'] ) )
						);
						?>
					</p>
				<?php endif; ?>

				<p class="beaver-alt-actions">
					<button type="button" class="button button-primary" id="beaver-alt-start" data-resume="<?php echo '' !== $queue['ids'] ? '1' : '0'; ?>">
						<?php echo '' !== $queue['ids'] ? esc_html__( 'Resume', 'beaver-alt-text' ) : esc_html__( 'Start', 'beaver-alt-text' ); ?>
					</button>
					<button type="button" class="button" id="beaver-alt-force"><?php esc_html_e( 'Re-describe everything', 'beaver-alt-text' ); ?></button>
					<button type="button" class="button" id="beaver-alt-stop" hidden><?php esc_html_e( 'Stop', 'beaver-alt-text' ); ?></button>
				</p>

				<div class="beaver-alt-progress" id="beaver-alt-progress" hidden>
					<div class="beaver-alt-progress__bar"><span id="beaver-alt-progress-fill"></span></div>
					<p class="beaver-alt-progress__label" id="beaver-alt-progress-label"></p>
				</div>

				<div class="beaver-alt-inline-notice" id="beaver-alt-notice" hidden></div>
				<div class="beaver-alt-log" id="beaver-alt-log" hidden></div>

				<h2 class="title"><?php esc_html_e( 'Maintenance', 'beaver-alt-text' ); ?></h2>
				<p><?php esc_html_e( 'Resetting counters only clears the numbers above. No alt text is changed.', 'beaver-alt-text' ); ?></p>
				<p><button type="button" class="button" id="beaver-alt-reset"><?php esc_html_e( 'Reset counters', 'beaver-alt-text' ); ?></button></p>
			</div>

			<?php self::render_credit(); ?>
		</div>
		<?php
	}

	/**
	 * Renders the review queue.
	 *
	 * @since 1.0.0
	 */
	public static function render_review() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to view this page.', 'beaver-alt-text' ) );
		}

		$per_page = 25;
		$page     = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$total    = self::pending_review_count();
		$pages    = (int) ceil( $total / $per_page );
		$ids      = self::pending_review_ids( $per_page, $page );
		?>
		<div class="wrap beaver-alt">
			<h1><?php esc_html_e( 'Review alt text', 'beaver-alt-text' ); ?></h1>

			<?php if ( empty( $ids ) ) : ?>
				<p class="beaver-alt-lead"><?php esc_html_e( 'Nothing is waiting for review.', 'beaver-alt-text' ); ?></p>
			<?php else : ?>
				<p class="beaver-alt-lead">
					<?php esc_html_e( 'Each suggestion below is unpublished. Edit anything that is wrong before you approve it — what you approve is what gets written to the image. An empty box means the image is decorative, which is the correct result for spacers and ornament.', 'beaver-alt-text' ); ?>
				</p>

				<div class="beaver-alt-bulkbar">
					<label for="beaver-alt-bulk-confidence"><?php esc_html_e( 'Approve everything with confidence:', 'beaver-alt-text' ); ?></label>
					<select id="beaver-alt-bulk-confidence">
						<option value="high"><?php esc_html_e( 'High only', 'beaver-alt-text' ); ?></option>
						<option value="medium"><?php esc_html_e( 'Medium or high', 'beaver-alt-text' ); ?></option>
					</select>
					<button type="button" class="button" id="beaver-alt-bulk-approve"><?php esc_html_e( 'Approve them', 'beaver-alt-text' ); ?></button>
					<span class="beaver-alt-bulkbar__status" id="beaver-alt-bulk-status"></span>
					<p class="description">
						<?php esc_html_e( 'Low-confidence suggestions are never included — those are the ones worth your eyes. Anything a person has since written alt text for is skipped.', 'beaver-alt-text' ); ?>
					</p>
				</div>

				<?php if ( $pages > 1 ) : ?>
					<div class="tablenav"><div class="tablenav-pages">
						<span class="displaying-num">
							<?php
							printf(
								/* translators: %s: number of suggestions. */
								esc_html__( '%s waiting', 'beaver-alt-text' ),
								esc_html( number_format_i18n( $total ) )
							);
							?>
						</span>
						<?php
						echo wp_kses_post(
							paginate_links(
								array(
									'base'    => add_query_arg( 'paged', '%#%' ),
									'format'  => '',
									'current' => $page,
									'total'   => $pages,
								)
							)
						);
						?>
					</div></div>
				<?php endif; ?>

				<div class="beaver-alt-review">
					<?php
					foreach ( $ids as $id ) :
						$proposal = get_post_meta( $id, Beaver_Alt_Generator::META_PROPOSAL, true );

						if ( ! is_array( $proposal ) ) {
							continue;
						}

						$confidence = (string) ( $proposal['confidence'] ?? 'low' );
						?>
						<div class="beaver-alt-review__row" data-id="<?php echo (int) $id; ?>">
							<div class="beaver-alt-review__thumb">
								<?php echo wp_kses_post( wp_get_attachment_image( $id, 'medium' ) ); ?>
							</div>
							<div class="beaver-alt-review__body">
								<p class="beaver-alt-review__meta">
									<a href="<?php echo esc_url( (string) get_edit_post_link( $id ) ); ?>"><?php echo esc_html( get_the_title( $id ) ); ?></a>
									<span class="beaver-alt-confidence beaver-alt-confidence--<?php echo esc_attr( $confidence ); ?>">
										<?php
										printf(
											/* translators: %s: confidence level. */
											esc_html__( '%s confidence', 'beaver-alt-text' ),
											esc_html( $confidence )
										);
										?>
									</span>
									<?php if ( ! empty( $proposal['decorative'] ) ) : ?>
										<span class="beaver-alt-badge beaver-alt-badge--decorative"><?php esc_html_e( 'Decorative', 'beaver-alt-text' ); ?></span>
									<?php endif; ?>
								</p>

								<?php if ( ! empty( $proposal['reason'] ) ) : ?>
									<p class="beaver-alt-review__reason"><?php echo esc_html( $proposal['reason'] ); ?></p>
								<?php endif; ?>

								<label class="screen-reader-text" for="beaver-alt-input-<?php echo (int) $id; ?>"><?php esc_html_e( 'Alt text', 'beaver-alt-text' ); ?></label>
								<input type="text" class="regular-text beaver-alt-review__input" id="beaver-alt-input-<?php echo (int) $id; ?>"
								       value="<?php echo esc_attr( (string) $proposal['alt'] ); ?>"
								       placeholder="<?php esc_attr_e( 'Empty — this image is decorative', 'beaver-alt-text' ); ?>" />

								<p class="beaver-alt-review__actions">
									<button type="button" class="button button-primary beaver-alt-approve"><?php esc_html_e( 'Approve', 'beaver-alt-text' ); ?></button>
									<button type="button" class="button beaver-alt-reject"><?php esc_html_e( 'Discard', 'beaver-alt-text' ); ?></button>
									<span class="beaver-alt-review__status"></span>
								</p>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

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
			wp_die( esc_html__( 'You are not allowed to view this page.', 'beaver-alt-text' ) );
		}

		$settings   = Beaver_Alt_Generator::get_settings();
		$option     = Beaver_Alt_Generator::OPTION_SETTINGS;
		$key_locked = defined( 'BEAVER_ALT_API_KEY' ) && '' !== (string) BEAVER_ALT_API_KEY;
		?>
		<div class="wrap beaver-alt">
			<h1><?php esc_html_e( 'Beaver Alt Text Settings', 'beaver-alt-text' ); ?></h1>

			<form method="post" action="options.php">
				<?php settings_fields( 'beaver_alt_settings_group' ); ?>

				<h2 class="title"><?php esc_html_e( 'Model', 'beaver-alt-text' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="beaver-alt-provider"><?php esc_html_e( 'Provider', 'beaver-alt-text' ); ?></label></th>
						<td>
							<?php $providers = Beaver_Alt_Provider::providers(); ?>
							<select id="beaver-alt-provider" name="<?php echo esc_attr( $option ); ?>[provider]">
								<?php foreach ( $providers as $id => $provider ) : ?>
									<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $settings['provider'], $id ); ?>>
										<?php
										echo esc_html( $provider['label'] );

										if ( empty( $provider['vision'] ) ) {
											echo ' — ' . esc_html__( 'no image support', 'beaver-alt-text' );
										}
										?>
									</option>
								<?php endforeach; ?>
							</select>

							<ul class="beaver-alt-providers">
								<?php foreach ( $providers as $id => $provider ) : ?>
									<li class="<?php echo empty( $provider['vision'] ) ? 'is-unavailable' : ''; ?>">
										<strong><?php echo esc_html( $provider['label'] ); ?></strong>
										<?php echo esc_html( $provider['note'] ); ?>
										<?php if ( ! empty( $provider['keys_at'] ) && ! empty( $provider['vision'] ) ) : ?>
											<em><?php
											printf(
												/* translators: %s: where to get an API key. */
												esc_html__( 'Keys: %s', 'beaver-alt-text' ),
												esc_html( $provider['keys_at'] )
											);
											?></em>
										<?php endif; ?>
									</li>
								<?php endforeach; ?>
							</ul>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="beaver-alt-key"><?php esc_html_e( 'API key', 'beaver-alt-text' ); ?></label></th>
						<td>
							<?php if ( $key_locked ) : ?>
								<p class="description">
									<?php esc_html_e( 'Set in wp-config.php as BEAVER_ALT_API_KEY. That key wins and is never stored in the database.', 'beaver-alt-text' ); ?>
								</p>
							<?php else : ?>
								<input type="password" class="regular-text" id="beaver-alt-key" autocomplete="off"
								       name="<?php echo esc_attr( $option ); ?>[api_key]"
								       value="<?php echo esc_attr( (string) $settings['api_key'] ); ?>" />
								<p class="description">
									<?php esc_html_e( 'Better still, define BEAVER_ALT_API_KEY in wp-config.php so the key never sits in the database. If this site also runs Beaver AI Chat, its key is used when this is empty.', 'beaver-alt-text' ); ?>
								</p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="beaver-alt-model"><?php esc_html_e( 'Model', 'beaver-alt-text' ); ?></label></th>
						<td>
							<?php $current = Beaver_Alt_Provider::config(); ?>
							<input type="text" class="regular-text code" id="beaver-alt-model"
							       name="<?php echo esc_attr( $option ); ?>[model]"
							       placeholder="<?php echo esc_attr( (string) ( $providers[ $settings['provider'] ]['model'] ?? '' ) ); ?>"
							       value="<?php echo esc_attr( (string) $settings['model'] ); ?>" />
							<p class="description">
								<?php esc_html_e( 'Leave blank to use the provider default. The model must accept image input — a text-only model will fail on every image.', 'beaver-alt-text' ); ?>
								<?php
								printf(
									/* translators: %s: model name currently in use. */
									esc_html__( 'Currently using: %s', 'beaver-alt-text' ),
									'' !== (string) $current['model'] ? esc_html( $current['model'] ) : esc_html__( 'nothing — set one', 'beaver-alt-text' )
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="beaver-alt-endpoint"><?php esc_html_e( 'Endpoint', 'beaver-alt-text' ); ?></label></th>
						<td>
							<input type="url" class="regular-text code" id="beaver-alt-endpoint"
							       name="<?php echo esc_attr( $option ); ?>[endpoint]"
							       placeholder="<?php echo esc_attr( (string) ( $providers[ $settings['provider'] ]['url'] ?? '' ) ); ?>"
							       value="<?php echo esc_attr( (string) $settings['endpoint'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Only needed for a custom OpenAI-compatible gateway. Leave blank otherwise.', 'beaver-alt-text' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="beaver-alt-language"><?php esc_html_e( 'Language', 'beaver-alt-text' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="beaver-alt-language"
							       name="<?php echo esc_attr( $option ); ?>[language]"
							       placeholder="<?php esc_attr_e( 'Follow the site language', 'beaver-alt-text' ); ?>"
							       value="<?php echo esc_attr( (string) $settings['language'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Leave blank to follow the site language. A screen reader announces alt text using the page language, so a description in the wrong language is read out with the wrong pronunciation.', 'beaver-alt-text' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="beaver-alt-context"><?php esc_html_e( 'About this site', 'beaver-alt-text' ); ?></label></th>
						<td>
							<textarea class="large-text" rows="3" id="beaver-alt-context"
							          name="<?php echo esc_attr( $option ); ?>[site_context]"><?php echo esc_textarea( (string) $settings['site_context'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'A sentence or two about what the site sells and where. Helps the model read your images. Example: a Tanzanian safari operator; most photographs are wildlife, camps and Kilimanjaro treks.', 'beaver-alt-text' ); ?></p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Review', 'beaver-alt-text' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Publishing', 'beaver-alt-text' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $option ); ?>[auto_apply]" value="1" <?php checked( ! empty( $settings['auto_apply'] ) ); ?> />
								<?php esc_html_e( 'Publish suggestions without review', 'beaver-alt-text' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Off by default. Alt text is read aloud to people who cannot see the image, so a wrong description is worse than none — review is the safer default on a client site.', 'beaver-alt-text' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="beaver-alt-threshold"><?php esc_html_e( 'Publish only when confidence is', 'beaver-alt-text' ); ?></label></th>
						<td>
							<select id="beaver-alt-threshold" name="<?php echo esc_attr( $option ); ?>[apply_below]">
								<option value="high" <?php selected( $settings['apply_below'], 'high' ); ?>><?php esc_html_e( 'High only', 'beaver-alt-text' ); ?></option>
								<option value="medium" <?php selected( $settings['apply_below'], 'medium' ); ?>><?php esc_html_e( 'Medium or high', 'beaver-alt-text' ); ?></option>
								<option value="low" <?php selected( $settings['apply_below'], 'low' ); ?>><?php esc_html_e( 'Anything', 'beaver-alt-text' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Anything below the threshold waits for review even when publishing without review is on.', 'beaver-alt-text' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Page context', 'beaver-alt-text' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $option ); ?>[use_context]" value="1" <?php checked( ! empty( $settings['use_context'] ) ); ?> />
								<?php esc_html_e( 'Send a short excerpt of the page the image is attached to', 'beaver-alt-text' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Improves accuracy on photographs that are ambiguous on their own. Turn off if page content is sensitive.', 'beaver-alt-text' ); ?></p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Performance', 'beaver-alt-text' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="beaver-alt-batch"><?php esc_html_e( 'Images per request', 'beaver-alt-text' ); ?></label></th>
						<td>
							<input type="number" id="beaver-alt-batch" class="small-text" min="1" max="10"
							       name="<?php echo esc_attr( $option ); ?>[batch_size]"
							       value="<?php echo esc_attr( (string) $settings['batch_size'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Lower this if your host times out. Each image is one call to the model, so this is bounded by how long your host allows a request to run.', 'beaver-alt-text' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="beaver-alt-edge"><?php esc_html_e( 'Image size sent', 'beaver-alt-text' ); ?></label></th>
						<td>
							<input type="number" id="beaver-alt-edge" class="small-text" min="320" max="1568" step="64"
							       name="<?php echo esc_attr( $option ); ?>[max_edge]"
							       value="<?php echo esc_attr( (string) $settings['max_edge'] ); ?>" />
							<?php esc_html_e( 'pixels on the longest edge', 'beaver-alt-text' ); ?>
							<p class="description"><?php esc_html_e( 'A description does not need a large image. 768 is plenty and keeps the cost down; raise it only if small details matter.', 'beaver-alt-text' ); ?></p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Cost estimate', 'beaver-alt-text' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Used only to show an approximate cost before a run starts. Published rates change, so enter what your provider currently charges per million tokens rather than trusting a number baked into a plugin.', 'beaver-alt-text' ); ?>
				</p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="beaver-alt-price-in"><?php esc_html_e( 'Input price', 'beaver-alt-text' ); ?></label></th>
						<td>
							<input type="number" id="beaver-alt-price-in" class="small-text" min="0" step="0.01"
							       name="<?php echo esc_attr( $option ); ?>[price_input]"
							       value="<?php echo esc_attr( (string) $settings['price_input'] ); ?>" />
							<?php esc_html_e( 'per million tokens', 'beaver-alt-text' ); ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="beaver-alt-price-out"><?php esc_html_e( 'Output price', 'beaver-alt-text' ); ?></label></th>
						<td>
							<input type="number" id="beaver-alt-price-out" class="small-text" min="0" step="0.01"
							       name="<?php echo esc_attr( $option ); ?>[price_output]"
							       value="<?php echo esc_attr( (string) $settings['price_output'] ); ?>" />
							<?php esc_html_e( 'per million tokens', 'beaver-alt-text' ); ?>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<?php self::render_credit(); ?>
		</div>
		<?php
	}
}
