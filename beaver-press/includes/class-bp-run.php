<?php
/**
 * "Translate site": visit every public page once per language as the signed run, so
 * TranslatePress collects and translates all of its text in one go.
 *
 * Pages come from WordPress's own sitemap providers (a manual list of public posts and
 * terms when core sitemaps are off). Each page is fetched with the X-Beaver-Press-Run
 * header, which the visitor guard trusts; for those requests TranslatePress's per-request
 * translation budget is raised so a page is finished in one visit. The admin page drives
 * the run step by step while open; WP-Cron carries on when it is closed; a lock keeps the
 * two from working at the same time. The run pauses itself on errors that would repeat on
 * every page (key rejected, quota used up, no key).
 *
 * @package BeaverPress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Translate-site run.
 */
final class BP_Run {

	/** Run state. */
	const STATE_OPTION = 'beaver_press_run';

	/** Queue of [language, url] (separate, not autoloaded). */
	const QUEUE_OPTION = 'beaver_press_run_queue';

	/** Lock transient. */
	const LOCK = 'bp_run_lock';

	/** Cron hook. */
	const CRON = 'beaver_press_run_tick';

	/** Seconds one step may spend starting new page fetches. */
	const STEP_BUDGET = 20;

	/** Error codes that stop the run (they would repeat on every page). */
	const FATAL = array( 'bp_no_key', 'bp_http_401', 'bp_http_402', 'bp_http_403', 'bp_http_456', 'bp_no_model', 'bp_no_endpoint', 'bp_budget' );

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( self::CRON, array( __CLASS__, 'cron_tick' ) );
		add_action( 'wp_ajax_bp_run_start', array( __CLASS__, 'ajax_start' ) );
		add_action( 'wp_ajax_bp_run_step', array( __CLASS__, 'ajax_step' ) );
		add_action( 'wp_ajax_bp_run_control', array( __CLASS__, 'ajax_control' ) );
		add_action( 'wp_ajax_bp_run_pages', array( __CLASS__, 'ajax_pages' ) );
		add_action( 'wp_ajax_bp_pages_status', array( __CLASS__, 'ajax_pages_status' ) );
		add_action( 'wp_ajax_bp_pages_check', array( __CLASS__, 'ajax_pages_check' ) );
		// The page list is cached; content changes rebuild it.
		foreach ( array( 'save_post', 'deleted_post', 'created_term', 'edited_term', 'delete_term' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'forget_pages' ) );
		}
	}

	/**
	 * Every public page with its title and type, for choosing pages and showing progress.
	 *
	 * @return array[] Each: url, title, type, post_id, key.
	 */
	public static function pages() {
		$urls   = self::urls();
		$cached = get_transient( 'bp_run_pages' );
		if ( is_array( $cached ) && ( $cached['hash'] ?? '' ) === md5( implode( '|', $urls ) ) ) {
			return $cached['pages'];
		}
		// Term links, to name archive pages.
		$terms = array();
		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $taxonomy ) {
			$list = get_terms(
				array(
					'taxonomy'   => $taxonomy->name,
					'hide_empty' => false,
				)
			);
			foreach ( is_array( $list ) ? $list : array() as $term ) {
				$link = get_term_link( $term );
				if ( ! is_wp_error( $link ) ) {
					$terms[ untrailingslashit( $link ) ] = array( $term->name, $taxonomy->labels->singular_name );
				}
			}
		}
		$pages = array();
		foreach ( $urls as $url ) {
			$id    = url_to_postid( $url );
			$title = '';
			$type  = '';
			if ( untrailingslashit( $url ) === untrailingslashit( home_url( '/' ) ) ) {
				$title = __( 'Home', 'beaver-press' );
				$type  = __( 'Home', 'beaver-press' );
				$id    = (int) get_option( 'page_on_front' );
			} elseif ( $id ) {
				$title = html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' );
				$pto   = get_post_type_object( get_post_type( $id ) );
				$type  = $pto ? $pto->labels->singular_name : '';
			} elseif ( isset( $terms[ untrailingslashit( $url ) ] ) ) {
				list( $title, $type ) = $terms[ untrailingslashit( $url ) ];
			} else {
				$title = untrailingslashit( (string) wp_parse_url( $url, PHP_URL_PATH ) );
				$type  = __( 'Other', 'beaver-press' );
			}
			$pages[] = array(
				'url'     => $url,
				'title'   => '' !== $title ? $title : $url,
				'type'    => '' !== $type ? $type : __( 'Other', 'beaver-press' ),
				'post_id' => (int) $id,
				'key'     => BP_Complete::key( $url ),
			);
		}
		set_transient(
			'bp_run_pages',
			array(
				'hash'  => md5( implode( '|', $urls ) ),
				'pages' => $pages,
			),
			DAY_IN_SECONDS
		);
		return $pages;
	}

	/**
	 * Drop the cached page list.
	 */
	public static function forget_pages() {
		delete_transient( 'bp_run_pages' );
	}

	/**
	 * Keep only addresses that are public pages of this site.
	 *
	 * @param string[] $urls Requested addresses.
	 * @return string[]
	 */
	public static function known_urls( array $urls ) {
		$known = array();
		foreach ( self::urls() as $url ) {
			$known[ untrailingslashit( $url ) ] = $url;
		}
		$out = array();
		foreach ( $urls as $url ) {
			$k = untrailingslashit( (string) $url );
			if ( isset( $known[ $k ] ) ) {
				$out[] = $known[ $k ];
			}
		}
		return array_values( array_unique( $out ) );
	}

	/* ------------------------------------------------------------------ */
	/* Pages and languages                                                 */
	/* ------------------------------------------------------------------ */

	/**
	 * Every public URL in the default language.
	 *
	 * @return string[]
	 */
	public static function urls() {
		$urls   = array( home_url( '/' ) );
		$server = function_exists( 'wp_sitemaps_get_server' ) ? wp_sitemaps_get_server() : null;

		if ( $server && $server->sitemaps_enabled() ) {
			foreach ( $server->registry->get_providers() as $name => $provider ) {
				if ( 'users' === $name || BP_Sitemap::NAME === $name ) {
					continue; // Authors are not public pages; the language sitemaps are built from this list.
				}
				$subtypes = array_keys( (array) $provider->get_object_subtypes() );
				foreach ( $subtypes ? $subtypes : array( '' ) as $subtype ) {
					$pages = (int) $provider->get_max_num_pages( $subtype );
					for ( $page = 1; $page <= $pages; $page++ ) {
						foreach ( (array) $provider->get_url_list( $page, $subtype ) as $entry ) {
							if ( ! empty( $entry['loc'] ) ) {
								$urls[] = (string) $entry['loc'];
							}
						}
					}
				}
			}
		} else {
			$ids = get_posts(
				array(
					'post_type'      => array_values( get_post_types( array( 'public' => true ) ) ),
					'post_status'    => 'publish',
					'posts_per_page' => 5000,
					'fields'         => 'ids',
					'has_password'   => false,
				)
			);
			foreach ( $ids as $id ) {
				$urls[] = (string) get_permalink( $id );
			}
			foreach ( get_taxonomies( array( 'public' => true ) ) as $taxonomy ) {
				$terms = get_terms(
					array(
						'taxonomy'   => $taxonomy,
						'hide_empty' => true,
					)
				);
				foreach ( is_array( $terms ) ? $terms : array() as $term ) {
					$link = get_term_link( $term );
					if ( ! is_wp_error( $link ) ) {
						$urls[] = (string) $link;
					}
				}
			}
		}

		$urls = array_values( array_unique( array_filter( $urls ) ) );
		return (array) apply_filters( 'beaver_press_run_urls', $urls );
	}

	/**
	 * Languages visitors can switch to, besides the default one.
	 *
	 * @return array<string, string> code => name
	 */
	public static function languages() {
		$trp      = TRP_Translate_Press::get_trp_instance();
		$settings = $trp->get_component( 'settings' )->get_settings();
		$codes    = array_values( array_diff( (array) $settings['publish-languages'], array( $settings['default-language'] ) ) );
		return (array) $trp->get_component( 'languages' )->get_language_names( $codes, 'english_name' );
	}

	/**
	 * A default-language URL in another language.
	 *
	 * @param string $url      URL.
	 * @param string $language Language code.
	 * @return string
	 */
	public static function url_in( $url, $language ) {
		$converter = TRP_Translate_Press::get_trp_instance()->get_component( 'url_converter' );
		return (string) $converter->get_url_for_language( $language, $url, '' );
	}

	/**
	 * Strings TranslatePress has for a language: translated and total.
	 *
	 * @param string $language Language code.
	 * @return array{done: int, total: int}
	 */
	public static function string_counts( $language ) {
		global $wpdb;
		$table = TRP_Translate_Press::get_trp_instance()->get_component( 'query' )->get_table_name( $language );
		if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
			return array(
				'done'  => 0,
				'total' => 0,
			);
		}
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from TranslatePress.
		$row = $wpdb->get_row( "SELECT COUNT(*) AS total, SUM(status <> 0) AS done FROM `{$table}`", ARRAY_A );
		// phpcs:enable
		return array(
			'done'  => (int) ( $row['done'] ?? 0 ),
			'total' => (int) ( $row['total'] ?? 0 ),
		);
	}

	/* ------------------------------------------------------------------ */
	/* State                                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * Current state (defaults when no run exists).
	 *
	 * @return array
	 */
	public static function state() {
		$state = get_option( self::STATE_OPTION, array() );
		return wp_parse_args(
			is_array( $state ) ? $state : array(),
			array(
				'status'    => 'idle',
				'languages' => array(),
				'total'     => 0,
				'pos'       => 0,
				'done'      => 0,
				'failed'    => array(),
				'message'   => '',
				'started'   => 0,
				'updated'   => 0,
				'finished'  => 0,
				'budget'    => 0,
			)
		);
	}

	/**
	 * Why the run must pause for cost reasons, or ''.
	 *
	 * @param array $state Run state.
	 * @return string
	 */
	private static function cost_stop( array $state ) {
		$daily = BP_Usage::daily_limit();
		if ( $daily['limit'] > 0 && $daily['used'] >= $daily['limit'] ) {
			return sprintf(
				/* translators: %s: character limit */
				__( 'Paused: the daily limit of %s characters is reached. Press Resume tomorrow, or raise the limit in Beaver Press -> Languages -> Automatic Translation.', 'beaver-press' ),
				number_format_i18n( $daily['limit'] )
			);
		}
		if ( (int) $state['budget'] > 0 && (int) BP_Usage::run()['chars'] >= (int) $state['budget'] ) {
			return sprintf(
				/* translators: %s: character budget */
				__( 'Paused: this run\'s budget of %s characters is used. Press Resume to continue past it.', 'beaver-press' ),
				number_format_i18n( (int) $state['budget'] )
			);
		}
		return '';
	}

	/**
	 * Save state.
	 *
	 * @param array $state State.
	 */
	private static function save( array $state ) {
		$state['updated'] = time();
		update_option( self::STATE_OPTION, $state, false );
	}

	/**
	 * Why a run cannot start, or '' when it can.
	 *
	 * @return string
	 */
	public static function blocker() {
		$mt = get_option( 'trp_machine_translation_settings', array() );
		$mt = is_array( $mt ) ? $mt : array();
		if ( BP_Engine_Settings::ENGINE !== ( $mt['translation-engine'] ?? '' ) ) {
			return __( 'Choose "Beaver Press" as the engine in Beaver Press -> Languages -> Automatic Translation and save.', 'beaver-press' );
		}
		$engine = BP_Engine_Settings::current( $mt );
		if ( '' === BP_Keys::get( $engine['provider'] ) ) {
			return __( 'Save an API key for the chosen provider first.', 'beaver-press' );
		}
		if ( ! self::languages() ) {
			return __( 'Add a second language first (Beaver Press -> Languages).', 'beaver-press' );
		}
		return '';
	}

	/**
	 * Start a run for these languages.
	 *
	 * @param string[] $languages Language codes.
	 * @param int      $budget    Stop after this many characters sent (0 = no run budget).
	 * @return array|WP_Error State.
	 */
	public static function start( array $languages, $budget = 0, $urls = null ) {
		$blocker = self::blocker();
		if ( '' !== $blocker ) {
			return new WP_Error( 'bp_run_blocked', $blocker );
		}
		$languages = array_values( array_intersect( $languages, array_keys( self::languages() ) ) );
		if ( ! $languages ) {
			return new WP_Error( 'bp_run_no_language', __( 'Tick at least one language.', 'beaver-press' ) );
		}

		$urls = null === $urls ? self::urls() : self::known_urls( (array) $urls );
		if ( ! $urls ) {
			return new WP_Error( 'bp_run_no_page', __( 'Choose at least one page.', 'beaver-press' ) );
		}
		$queue = array();
		foreach ( $urls as $url ) {
			foreach ( $languages as $language ) {
				$queue[] = array( $language, $url );
			}
		}
		update_option( self::QUEUE_OPTION, $queue, false );
		delete_option( BP_Providers::ERROR_OPTION );
		BP_Usage::reset_run();

		$state = array(
			'budget'    => max( 0, (int) $budget ),
			'status'    => 'running',
			'languages' => $languages,
			'pages'     => count( $urls ),
			'chosen'    => null !== $urls && count( $urls ) < count( self::urls() ),
			'total'     => count( $queue ),
			'pos'       => 0,
			'done'      => 0,
			'failed'    => array(),
			'message'   => '',
			'started'   => time(),
			'finished'  => 0,
		);
		self::save( $state );
		self::schedule();
		return self::state();
	}

	/**
	 * Translate chosen pages: added to the current run when there is one, else a new run.
	 *
	 * @param string[] $urls      Page addresses (default language).
	 * @param string[] $languages Language codes.
	 * @return array|WP_Error State.
	 */
	public static function add( array $urls, array $languages ) {
		$state = self::state();
		if ( ! in_array( $state['status'], array( 'running', 'paused', 'error' ), true ) ) {
			return self::start( $languages, 0, $urls );
		}
		$urls      = self::known_urls( $urls );
		$languages = array_values( array_intersect( $languages, array_keys( self::languages() ) ) );
		if ( ! $urls || ! $languages ) {
			return new WP_Error( 'bp_run_no_page', __( 'Choose at least one page and one language.', 'beaver-press' ) );
		}
		$queue   = get_option( self::QUEUE_OPTION, array() );
		$queue   = is_array( $queue ) ? $queue : array();
		$pending = array();
		foreach ( array_slice( $queue, (int) $state['pos'] ) as $item ) {
			$pending[ $item[0] . '|' . $item[1] ] = true;
		}
		foreach ( $urls as $url ) {
			foreach ( $languages as $language ) {
				if ( ! isset( $pending[ $language . '|' . $url ] ) ) {
					$queue[] = array( $language, $url );
				}
			}
		}
		update_option( self::QUEUE_OPTION, $queue, false );
		$state['total']     = count( $queue );
		$state['languages'] = array_values( array_unique( array_merge( (array) $state['languages'], $languages ) ) );
		self::save( $state );
		if ( 'running' === $state['status'] ) {
			self::schedule();
		}
		return self::state();
	}

	/**
	 * Pause, resume or cancel.
	 *
	 * @param string $action pause|resume|cancel.
	 * @return array State.
	 */
	public static function control( $action ) {
		$state = self::state();
		if ( 'pause' === $action && 'running' === $state['status'] ) {
			$state['status']  = 'paused';
			$state['message'] = __( 'Paused.', 'beaver-press' );
		} elseif ( 'resume' === $action && in_array( $state['status'], array( 'paused', 'error' ), true ) ) {
			$blocker = self::blocker();
			if ( '' === $blocker ) {
				$daily = BP_Usage::daily_limit();
				if ( $daily['limit'] > 0 && $daily['used'] >= $daily['limit'] ) {
					$blocker = __( 'The daily limit is still reached. Try again tomorrow or raise the limit.', 'beaver-press' );
				}
			}
			if ( '' !== $blocker ) {
				$state['message'] = $blocker;
				self::save( $state );
				return self::state();
			}
			// Resuming past a reached run budget means continuing without it.
			if ( (int) $state['budget'] > 0 && (int) BP_Usage::run()['chars'] >= (int) $state['budget'] ) {
				$state['budget'] = 0;
			}
			delete_option( BP_Providers::ERROR_OPTION );
			$state['status']  = 'running';
			$state['message'] = '';
			self::schedule();
		} elseif ( 'cancel' === $action ) {
			delete_option( self::QUEUE_OPTION );
			$state = array( 'status' => 'idle' );
		}
		self::save( $state );
		return self::state();
	}

	/* ------------------------------------------------------------------ */
	/* Processing                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Fetch the next pages until the step budget is used. One page at a time, under a lock.
	 *
	 * @param int $budget Seconds.
	 * @return array State.
	 */
	public static function step( $budget = self::STEP_BUDGET ) {
		$state = self::state();
		if ( 'running' !== $state['status'] ) {
			return $state;
		}
		if ( get_transient( self::LOCK ) ) {
			return $state; // Another step (browser or cron) is fetching a page.
		}
		set_transient( self::LOCK, 1, 10 * MINUTE_IN_SECONDS );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 360 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- one page can take minutes with a slow model.
		}

		$queue          = get_option( self::QUEUE_OPTION, array() );
		$queue          = is_array( $queue ) ? $queue : array();
		$state['total'] = count( $queue ); // Pages added while the run goes are counted too.
		$started        = microtime( true );

		$offered = self::languages();
		while ( $state['pos'] < count( $queue ) && ( microtime( true ) - $started ) < $budget ) {
			list( $language, $url ) = $queue[ $state['pos'] ];
			if ( ! isset( $offered[ $language ] ) ) {
				$state['pos']++; // Language removed since the run started: skip its pages.
				continue;
			}
			$before                 = time();
			$result                 = self::fetch( self::url_in( $url, $language ) );
			$state['pos']++;
			if ( true === $result ) {
				$state['done']++;
			} else {
				$state['failed'][] = array(
					'url'    => self::url_in( $url, $language ),
					'reason' => $result,
				);
				$state['failed']   = array_slice( $state['failed'], -50 );
			}

			// Stop on errors that would repeat on every page.
			// Only errors of the provider this run uses count (another process, a connection test
			// with another provider for example, may write the shared last-error record meanwhile).
			$error    = BP_Providers::last_error( true );
			$provider = BP_Engine_Settings::current( (array) get_option( 'trp_machine_translation_settings', array() ) )['provider'] ?? '';
			if ( $error && (int) $error['time'] >= $before && in_array( $error['code'], self::FATAL, true ) && ( '' === (string) ( $error['provider'] ?? '' ) || $error['provider'] === $provider ) ) {
				$state['status']  = 'error';
				$state['message'] = sprintf( /* translators: %s: provider error */ __( 'Stopped: %s Fix it, then press Resume.', 'beaver-press' ), $error['message'] );
				break;
			}

			// Daily limit or run budget reached: pause instead of fetching pages that would stay untranslated.
			$stop = self::cost_stop( $state );
			if ( '' !== $stop ) {
				$state['status']  = 'paused';
				$state['message'] = $stop;
				break;
			}
			self::save( $state );
		}

		if ( 'running' === $state['status'] && $state['pos'] >= count( $queue ) ) {
			$state['status']   = 'done';
			$state['finished'] = time();
			$state['message']  = __( 'Finished.', 'beaver-press' );
			delete_option( self::QUEUE_OPTION );
		}
		self::save( $state );
		delete_transient( self::LOCK );
		if ( 'running' === $state['status'] ) {
			self::schedule();
		}
		return self::state();
	}

	/**
	 * Fetch one page as the signed run.
	 *
	 * @param string $url URL.
	 * @return true|string True, or why it failed.
	 */
	public static function fetch( $url ) {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 300,
				'redirection' => 3,
				'sslverify'   => (bool) apply_filters( 'https_local_ssl_verify', false ),
				'headers'     => array(
					BP_Guard::HEADER => BP_Guard::run_token(),
					'Cache-Control'  => 'no-cache',
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response->get_error_message();
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		return $code >= 200 && $code < 400 ? true : 'HTTP ' . $code;
	}

	/**
	 * Continue in the background (runs when the admin page is closed).
	 */
	public static function cron_tick() {
		self::step();
	}

	/**
	 * Queue the next background step.
	 */
	private static function schedule() {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_single_event( time() + 30, self::CRON );
		}
	}

	/**
	 * State plus per-language string counts, for the admin page.
	 *
	 * @return array
	 */
	public static function progress() {
		$state   = self::state();
		$names   = self::languages();
		$strings = array();
		foreach ( $names as $code => $name ) {
			$strings[] = array( 'code' => $code, 'name' => $name ) + self::string_counts( $code );
		}
		$state['strings']  = $strings;
		// Where the run is: the page it is on and pages done per language.
		$queue             = get_option( self::QUEUE_OPTION, array() );
		$queue             = is_array( $queue ) ? $queue : array();
		$pos               = (int) ( $state['pos'] ?? 0 );
		$state['current']  = null;
		if ( 'running' === ( $state['status'] ?? '' ) && isset( $queue[ $pos ] ) ) {
			$state['current'] = array(
				'url'      => $queue[ $pos ][1],
				'key'      => BP_Complete::key( $queue[ $pos ][1] ),
				'language' => $queue[ $pos ][0],
				'name'     => $names[ $queue[ $pos ][0] ] ?? $queue[ $pos ][0],
			);
		}
		$per = array();
		foreach ( $queue as $i => $item ) {
			$per[ $item[0] ]['total'] = ( $per[ $item[0] ]['total'] ?? 0 ) + 1;
			$per[ $item[0] ]['done']  = ( $per[ $item[0] ]['done'] ?? 0 ) + ( $i < $pos ? 1 : 0 );
		}
		foreach ( $per as $code => $row ) {
			$per[ $code ]['name'] = $names[ $code ] ?? $code;
		}
		$state['per_language'] = $per;
		$state['rejected'] = count( BP_Batch::rejects() );
		$state['usage']    = BP_Usage::run();
		$state['daily']    = BP_Usage::daily_limit();
		$error             = BP_Providers::last_error( true );
		$state['error']    = $error ? $error['message'] : '';
		return $state;
	}

	/* ------------------------------------------------------------------ */
	/* AJAX                                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * Nonce and capability.
	 */
	private static function ajax_check() {
		check_ajax_referer( 'bp_run', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'beaver-press' ) ), 403 );
		}
	}

	/**
	 * Start.
	 */
	public static function ajax_start() {
		self::ajax_check();
		$languages = isset( $_POST['languages'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['languages'] ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in ajax_check().
		$budget    = isset( $_POST['budget'] ) ? absint( wp_unslash( $_POST['budget'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in ajax_check().
		$state     = self::start( $languages, $budget );
		if ( is_wp_error( $state ) ) {
			wp_send_json_error( array( 'message' => $state->get_error_message() ) );
		}
		wp_send_json_success( self::progress() );
	}

	/**
	 * One step (called in a loop while the page is open).
	 */
	public static function ajax_step() {
		self::ajax_check();
		self::step();
		wp_send_json_success( self::progress() );
	}

	/**
	 * Translate chosen pages (Pages tab).
	 */
	public static function ajax_pages() {
		self::ajax_check();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked in ajax_check().
		$urls      = isset( $_POST['urls'] ) ? array_map( 'esc_url_raw', (array) wp_unslash( $_POST['urls'] ) ) : array();
		$languages = isset( $_POST['languages'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['languages'] ) ) : array();
		// phpcs:enable
		$state = self::add( $urls, $languages );
		if ( is_wp_error( $state ) ) {
			wp_send_json_error( array( 'message' => $state->get_error_message() ) );
		}
		wp_send_json_success( self::progress() );
	}

	/**
	 * Texts missing per page and language (Pages tab refresh).
	 */
	public static function ajax_pages_status() {
		self::ajax_check();
		wp_send_json_success(
			array(
				'map'      => BP_Complete::map( true ),
				'texts'    => BP_Complete::texts_map(),
				'progress' => self::progress(),
			)
		);
	}

	/**
	 * Check progress without translating: open pages as a visitor (the visitor guard never
	 * sends text to the provider), which records the texts still missing. Works through the
	 * list for up to 20 s per call; the page calls again with the next offset.
	 */
	public static function ajax_pages_check() {
		self::ajax_check();
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked in ajax_check().
		$urls      = isset( $_POST['urls'] ) ? self::known_urls( array_map( 'esc_url_raw', (array) wp_unslash( $_POST['urls'] ) ) ) : array();
		$languages = isset( $_POST['languages'] ) ? array_values( array_intersect( array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['languages'] ) ), array_keys( self::languages() ) ) ) : array();
		$offset    = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
		// phpcs:enable
		$pairs = array();
		foreach ( $urls as $url ) {
			foreach ( $languages as $language ) {
				$pairs[] = self::url_in( $url, $language );
			}
		}
		$started = microtime( true );
		while ( $offset < count( $pairs ) && ( microtime( true ) - $started ) < 20 ) {
			self::visitor_fetch( $pairs[ $offset ] );
			$offset++;
		}
		wp_send_json_success(
			array(
				'offset' => $offset,
				'total'  => count( $pairs ),
				'map'    => BP_Complete::map( true ),
				'texts'  => BP_Complete::texts_map(),
			)
		);
	}

	/**
	 * Open a page as a visitor (no cookies, past the ready-page cache): records what is still
	 * missing; the visitor guard means nothing is sent to the provider.
	 *
	 * @param string $url Address in a language.
	 * @return int HTTP status (0 on failure).
	 */
	public static function visitor_fetch( $url ) {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 60,
				'redirection' => 2,
				'sslverify'   => (bool) apply_filters( 'https_local_ssl_verify', false ),
				'cookies'     => array(),
				'headers'     => array( 'Cache-Control' => 'no-cache' ), // Ready pages are counted when built.
			)
		);
		return is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
	}

	/**
	 * Pause, resume, cancel.
	 */
	public static function ajax_control() {
		self::ajax_check();
		$action = isset( $_POST['run_action'] ) ? sanitize_key( wp_unslash( $_POST['run_action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in ajax_check().
		self::control( $action );
		wp_send_json_success( self::progress() );
	}
}
