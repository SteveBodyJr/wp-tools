<?php
/**
 * Ready translated pages: a small page cache for visitors on translated pages.
 *
 * A page takes about a second to build and translating it adds a little more. Once a
 * translated page is complete (every text on it has a translation), its final HTML is kept
 * as a file; the next visitor on the same address gets it straight after WordPress starts,
 * before the theme runs. Only for visitors (never logged-in users), only addresses without a
 * query string, only complete pages, at most 6 hours old (form nonces stay valid 12-24 h), and
 * the page's own caching headers (no-store on form pages) are sent again, so browsers and
 * server caches keep treating it as before. Every change to content, menus, settings or a
 * translation starts a new generation, so an edit shows at once.
 *
 * @package BeaverPress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Translated-page cache.
 */
final class BP_Cache {

	/** Option: 'yes' (default) or 'no'. */
	const OPTION = 'beaver_press_cache';

	/** Option: generation number; a new one empties the cache. */
	const GEN_OPTION = 'beaver_press_cache_gen';

	/** Cron hook that deletes old generations. */
	const CRON = 'beaver_press_cache_purge';

	/** Cron hook that builds the ready pages again after they were cleared. */
	const WARM_CRON = 'beaver_press_cache_warm';

	/** Warm-up progress: [ 'gen' => generation, 'offset' => next address ]. */
	const WARM_OPTION = 'beaver_press_cache_warm';

	/** Headers of the original response that are sent again with a kept page. */
	const KEEP_HEADERS = array( 'content-type', 'cache-control', 'pragma', 'expires', 'link', 'x-robots-tag' );

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( self::CRON, array( __CLASS__, 'purge' ) );
		add_action( self::WARM_CRON, array( __CLASS__, 'warm_tick' ) );
		self::flush_hooks();
		add_action( 'init', array( __CLASS__, 'check_build' ), 0 );
		if ( ! self::enabled() ) {
			return;
		}
		// After init (post types, taxonomies and translated addresses are known), before the query.
		add_action( 'wp_loaded', array( __CLASS__, 'serve' ), 1 );
		add_filter( 'trp_translated_html', array( __CLASS__, 'store' ), PHP_INT_MAX, 4 );
		add_action( 'template_redirect', array( __CLASS__, 'buffer_original' ), 0 );
	}

	/**
	 * Whether the cache is on.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return (bool) apply_filters( 'beaver_press_cache', 'no' !== get_option( self::OPTION, 'yes' ) );
	}

	/**
	 * Page lifetime in seconds.
	 *
	 * @return int
	 */
	public static function ttl() {
		return max( 60, (int) apply_filters( 'beaver_press_cache_ttl', 6 * HOUR_IN_SECONDS ) );
	}

	/**
	 * Everything that can change a page empties the cache.
	 */
	private static function flush_hooks() {
		$flush = array( __CLASS__, 'flush' );
		add_action( 'transition_post_status', array( __CLASS__, 'post_changed' ), 10, 3 );
		add_action( 'deleted_post', $flush );
		foreach ( array( 'created_term', 'edited_term', 'delete_term', 'wp_update_nav_menu', 'customize_save_after', 'switch_theme', 'activated_plugin', 'deactivated_plugin', 'upgrader_process_complete', 'update_option_trp_settings', 'update_option_sidebars_widgets', 'update_option_blogname', 'update_option_blogdescription', 'beaver_press_translations_saved' ) as $hook ) {
			add_action( $hook, $flush );
		}
		// Theme Settings (ACF options pages).
		add_action(
			'acf/save_post',
			static function ( $post_id ) {
				if ( is_string( $post_id ) && 0 === strpos( $post_id, 'option' ) ) {
					self::flush();
				}
			}
		);
		// Translations saved in TranslatePress's visual editor.
		foreach ( array( 'wp_ajax_trp_save_translations_regular', 'wp_ajax_trp_save_translations_gettext', 'wp_ajax_trp_save_translations_postslug', 'wp_ajax_trp_save_translations_termslug' ) as $hook ) {
			add_action( $hook, $flush, 1 );
		}
	}

	/**
	 * New plugin or theme code: start afresh (ready pages carry the old markup).
	 */
	public static function check_build() {
		$build = BP_VERSION . '|' . wp_get_theme()->get( 'Version' ) . '|' . (string) apply_filters( 'beaver_press_cache_build', '' );
		if ( get_option( 'beaver_press_cache_build' ) !== $build ) {
			update_option( 'beaver_press_cache_build', $build, true );
			self::flush();
		}
	}

	/**
	 * A post changed: only published content (or content leaving "published") matters.
	 *
	 * @param string  $new  New status.
	 * @param string  $old  Old status.
	 * @param WP_Post $post Post.
	 */
	public static function post_changed( $new, $old, $post ) {
		if ( ( 'publish' === $new || 'publish' === $old ) && $post instanceof WP_Post && ! wp_is_post_revision( $post ) ) {
			self::flush();
		}
	}

	/**
	 * Start a new generation (old pages are no longer served; files deleted a minute later).
	 */
	public static function flush() {
		static $done = false;
		if ( $done ) {
			return; // Once per request is enough.
		}
		$done = true;
		update_option( self::GEN_OPTION, self::gen() + 1, true );
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_single_event( time() + 60, self::CRON );
		}
		// Build the ready pages again in the background, so visitors do not wait for it.
		if ( self::enabled() && apply_filters( 'beaver_press_cache_warm', true ) ) {
			delete_option( self::WARM_OPTION );
			wp_clear_scheduled_hook( self::WARM_CRON );
			wp_schedule_single_event( time() + 120, self::WARM_CRON );
		}
	}

	/**
	 * Delete the ready copies of one page in every language (its other copies keep serving).
	 *
	 * @param string $original_url Address in the original language.
	 */
	public static function forget_page( $original_url ) {
		// The address may come in any language (during a translated request WordPress builds
		// permalinks in that language): bring it back to the original first.
		$trp          = TRP_Translate_Press::get_trp_instance();
		$default      = (string) $trp->get_component( 'settings' )->get_settings()['default-language'];
		$original_url = (string) strtok( (string) $trp->get_component( 'url_converter' )->get_url_for_language( $default, (string) $original_url, '' ), '?#' );
		$languages    = array_merge( array( '' ), array_keys( BP_Run::languages() ) );
		foreach ( $languages as $language ) {
			$url  = '' === $language ? (string) $original_url : BP_Run::url_in( (string) $original_url, $language );
			$path = (string) wp_parse_url( $url, PHP_URL_PATH );
			foreach ( array( 'http://', 'https://' ) as $scheme ) {
				$file = self::base() . '/' . self::gen() . '/' . md5( $scheme . strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) . ( wp_parse_url( $url, PHP_URL_PORT ) ? ':' . wp_parse_url( $url, PHP_URL_PORT ) : '' ) . $path ) . '.html';
				if ( is_file( $file ) ) {
					wp_delete_file( $file );
				}
			}
		}
	}

	/**
	 * Current generation.
	 *
	 * @return int
	 */
	public static function gen() {
		return max( 1, (int) get_option( self::GEN_OPTION, 1 ) );
	}

	/**
	 * Root folder: wp-content/cache/beaver-press when wp-content/cache exists and is writable,
	 * otherwise uploads/beaver-press-cache (uploads is writable on every working site).
	 *
	 * @return string
	 */
	public static function root() {
		static $root = null;
		if ( null === $root ) {
			$cache = WP_CONTENT_DIR . '/cache';
			// Only an existing, writable wp-content/cache (as cache plugins create it), so WP-CLI and
			// the web server (often different users) always agree on the folder.
			if ( is_dir( $cache ) && wp_is_writable( $cache ) ) {
				$root = $cache . '/beaver-press';
			} else {
				$uploads = wp_upload_dir( null, false );
				$root    = $uploads['basedir'] . '/beaver-press-cache';
			}
			$root = (string) apply_filters( 'beaver_press_cache_dir', $root );
		}
		return untrailingslashit( $root );
	}

	/**
	 * Folder of this site.
	 *
	 * @return string
	 */
	public static function base() {
		return self::root() . '/' . get_current_blog_id();
	}

	/**
	 * File for the current address.
	 *
	 * @return string
	 */
	private static function file() {
		return self::base() . '/' . self::gen() . '/' . md5( self::url() ) . '.html';
	}

	/**
	 * Current address (scheme, host, path; no query string).
	 *
	 * @return string
	 */
	private static function url() {
		$host = strtolower( (string) ( $_SERVER['HTTP_HOST'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- hashed, never printed.
		$path = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '/' ), PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- hashed, never printed.
		return ( is_ssl() ? 'https://' : 'http://' ) . $host . $path;
	}

	/**
	 * Whether this request may use the cache: a visitor's plain GET of a translated page.
	 *
	 * @return bool
	 */
	public static function request_ok() {
		$method = strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared only.
		if ( ! in_array( $method, array( 'GET', 'HEAD' ), true ) || ! empty( $_GET ) || false !== strpos( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), '?' ) ) { // phpcs:ignore WordPress.Security -- presence checks only.
			return false;
		}
		if ( 'cli' === PHP_SAPI || is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) || ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) ) {
			return false;
		}
		foreach ( array_keys( $_COOKIE ) as $name ) {
			if ( preg_match( '/^(wordpress_logged_in_|wordpress_sec_|wp-postpass_|comment_author_)/', (string) $name ) ) {
				return false;
			}
		}
		if ( function_exists( 'is_user_logged_in' ) && is_user_logged_in() ) {
			return false;
		}
		$trp      = TRP_Translate_Press::get_trp_instance();
		$settings = $trp->get_component( 'settings' )->get_settings();
		$language = $trp->get_component( 'url_converter' )->get_lang_from_url_string( self::url() );
		$language = empty( $language ) ? (string) $settings['default-language'] : $language; // No language part: the original language.
		if ( $language === $settings['default-language'] && ! apply_filters( 'beaver_press_cache_original', true ) ) {
			return false;
		}
		// An old address that now moves to a translated one: never from (or into) the cache.
		if ( class_exists( 'BP_Slugs' ) && '' !== BP_Slugs::old_address_target() ) {
			return false;
		}
		return (bool) apply_filters( 'beaver_press_cache_request', true );
	}

	/**
	 * Serve a kept page, if there is a fresh one.
	 */
	public static function serve() {
		if ( ! self::request_ok() ) {
			return;
		}
		// A forced reload (and the Translate-site run) always builds the page again.
		$cc = strtolower( (string) ( $_SERVER['HTTP_CACHE_CONTROL'] ?? '' ) . ' ' . ( $_SERVER['HTTP_PRAGMA'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared only.
		if ( false !== strpos( $cc, 'no-cache' ) ) {
			return;
		}
		$file = self::file();
		if ( ! is_readable( $file ) || filemtime( $file ) < time() - self::ttl() ) {
			return;
		}
		$data = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- local cache file.
		$cut  = is_string( $data ) ? strpos( $data, "\n" ) : false;
		if ( false === $cut ) {
			return;
		}
		$headers = json_decode( substr( $data, 0, $cut ), true );
		$html    = substr( $data, $cut + 1 );
		if ( ! is_array( $headers ) || '' === $html || headers_sent() ) {
			return;
		}
		foreach ( $headers as $line ) {
			header( (string) $line, false );
		}
		header( 'X-Beaver-Press-Cache: hit' );
		// TranslatePress's buffer is already open: let the finished page through as it is.
		add_filter( 'trp_stop_translating_page', '__return_true' );
		if ( 'HEAD' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared only.
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput -- the page as built and translated earlier.
		}
		exit;
	}

	/**
	 * Keep a complete translated page.
	 *
	 * @param string $html     Final HTML.
	 * @param string $language Language.
	 * @param string $code     Language code.
	 * @param bool   $preview  Translation editor preview.
	 * @return string
	 */
	public static function store( $html, $language = '', $code = '', $preview = false ) {
		if ( $preview || ! BP_Complete::page_complete() || ! is_string( $html ) || strlen( $html ) < 500 || false === stripos( $html, '</html>' ) ) {
			return $html;
		}
		if ( 200 !== (int) http_response_code() || ( function_exists( 'is_404' ) && ( is_404() || is_search() || is_feed() || is_preview() ) ) || ! self::request_ok() ) {
			return $html;
		}
		$headers = array();
		foreach ( headers_list() as $line ) {
			$name = strtolower( trim( strtok( $line, ':' ) ) );
			if ( 'location' === $name || 'set-cookie' === $name ) {
				return $html; // A redirect or a cookie: never keep.
			}
			if ( in_array( $name, self::KEEP_HEADERS, true ) ) {
				$headers[] = $line;
			}
		}
		$file = self::file();
		if ( ! wp_mkdir_p( dirname( $file ) ) ) {
			return $html;
		}
		self::protect();
		$tmp = $file . '.' . wp_rand() . '.tmp';
		if ( false !== file_put_contents( $tmp, wp_json_encode( $headers ) . "\n" . $html ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions -- local cache file.
			rename( $tmp, $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- atomic replace.
		}
		return $html;
	}

	/**
	 * Pages in the original language are ready pages too: the engine sends them out in small
	 * pieces with no hook, so they are collected here (inside the engine's buffer, outside the
	 * theme's) and stored as the engine would send them.
	 */
	public static function buffer_original() {
		global $TRP_LANGUAGE;
		$settings = TRP_Translate_Press::get_trp_instance()->get_component( 'settings' )->get_settings();
		if ( (string) $TRP_LANGUAGE !== (string) $settings['default-language'] || ! self::request_ok() ) {
			return;
		}
		ob_start( array( __CLASS__, 'store_original' ) );
	}

	/**
	 * Store an original-language page (output handler: no buffers started here).
	 *
	 * @param string $html Page.
	 * @return string The page, unchanged.
	 */
	public static function store_original( $html ) {
		if ( ! is_string( $html ) || strlen( $html ) < 500 || false === stripos( $html, '</html>' ) ) {
			return $html;
		}
		if ( 200 !== (int) http_response_code() || ( function_exists( 'is_404' ) && ( is_404() || is_search() || is_feed() || is_preview() ) ) ) {
			return $html;
		}
		$page = class_exists( 'TRP_Translation_Manager' ) ? TRP_Translation_Manager::strip_gettext_tags( $html ) : $html;
		self::write( $page );
		return $html;
	}

	/**
	 * Write the current page's file with its kept headers (none for redirects or cookies).
	 *
	 * @param string $html Page.
	 */
	private static function write( $html ) {
		$headers = array();
		foreach ( headers_list() as $line ) {
			$name = strtolower( trim( strtok( $line, ':' ) ) );
			if ( 'location' === $name || 'set-cookie' === $name ) {
				return;
			}
			if ( in_array( $name, self::KEEP_HEADERS, true ) ) {
				$headers[] = $line;
			}
		}
		$file = self::file();
		if ( ! wp_mkdir_p( dirname( $file ) ) ) {
			return;
		}
		self::protect();
		$tmp = $file . '.' . wp_rand() . '.tmp';
		if ( false !== file_put_contents( $tmp, wp_json_encode( $headers ) . "\n" . $html ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions -- local cache file.
			rename( $tmp, $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- atomic replace.
		}
	}

	/**
	 * Every public page in every language (original first), as visitors reach them.
	 *
	 * @return string[]
	 */
	public static function warm_urls() {
		$out = array();
		foreach ( BP_Run::urls() as $url ) {
			$out[] = $url;
			foreach ( array_keys( BP_Run::languages() ) as $code ) {
				$out[] = BP_Run::url_in( $url, $code );
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Open pages as a visitor for a while (they are built and kept); true when all are done.
	 *
	 * @param int $seconds Time for this call.
	 * @return array{offset: int, total: int}
	 */
	public static function warm( $seconds = 25 ) {
		$state = get_option( self::WARM_OPTION, array() );
		$gen   = self::gen();
		$off   = is_array( $state ) && (int) ( $state['gen'] ?? 0 ) === $gen ? (int) $state['offset'] : 0;
		$urls  = self::warm_urls();
		$start = microtime( true );
		while ( $off < count( $urls ) && ( microtime( true ) - $start ) < $seconds ) {
			BP_Run::visitor_fetch( $urls[ $off ] ); // A visitor never starts paid translation.
			$off++;
		}
		update_option( self::WARM_OPTION, array( 'gen' => $gen, 'offset' => $off ), false );
		return array( 'offset' => $off, 'total' => count( $urls ) );
	}

	/**
	 * Cron: warm a slice, then again a minute later until every page is done.
	 */
	public static function warm_tick() {
		if ( ! self::enabled() ) {
			return;
		}
		$done = self::warm();
		if ( $done['offset'] < $done['total'] && ! wp_next_scheduled( self::WARM_CRON ) ) {
			wp_schedule_single_event( time() + 60, self::WARM_CRON );
		}
	}

	/**
	 * Keep the folder out of reach from the web.
	 */
	private static function protect() {
		$root = self::root();
		if ( ! file_exists( $root . '/.htaccess' ) ) {
			file_put_contents( $root . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- local file.
			file_put_contents( $root . '/index.php', "<?php\n// Silence.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- local file.
		}
	}

	/**
	 * Delete old generations and expired pages.
	 */
	public static function purge() {
		$base = self::base();
		$gen  = (string) self::gen();
		foreach ( (array) glob( $base . '/*', GLOB_ONLYDIR ) as $dir ) {
			if ( basename( $dir ) !== $gen ) {
				self::remove_dir( $dir );
			}
		}
		foreach ( (array) glob( $base . '/' . $gen . '/*.html' ) as $file ) {
			if ( filemtime( $file ) < time() - self::ttl() ) {
				wp_delete_file( $file );
			}
		}
	}

	/**
	 * Remove a generation folder (only .html and .tmp files are expected).
	 *
	 * @param string $dir Folder.
	 */
	private static function remove_dir( $dir ) {
		if ( 0 !== strpos( wp_normalize_path( $dir ), wp_normalize_path( self::root() . '/' ) ) ) {
			return;
		}
		foreach ( (array) glob( $dir . '/*.{html,tmp}', GLOB_BRACE ) as $file ) {
			wp_delete_file( $file );
		}
		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions -- may hold a file written meanwhile.
	}

	/**
	 * Empty everything now (admin button, uninstall).
	 */
	public static function clear() {
		self::flush();
		foreach ( (array) glob( self::base() . '/*', GLOB_ONLYDIR ) as $dir ) {
			self::remove_dir( $dir );
		}
	}

	/**
	 * Pages ready now, for the admin page.
	 *
	 * @return int
	 */
	public static function count() {
		$n = 0;
		foreach ( (array) glob( self::base() . '/' . self::gen() . '/*.html' ) as $file ) {
			if ( filemtime( $file ) >= time() - self::ttl() ) {
				$n++;
			}
		}
		return $n;
	}
}
