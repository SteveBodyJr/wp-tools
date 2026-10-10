<?php
/**
 * Fingerprint reduction.
 *
 * @package BeaverShield
 */

defined( 'ABSPATH' ) || exit;

/**
 * Removes the version and component detail a page volunteers about itself.
 *
 * None of this hides WordPress, and the plugin never pretends otherwise. The
 * REST route, the feed, the block class names and the comment form all still
 * say what the site runs on, and a determined tool will read them. What goes
 * away here is the *specific* part: which version of core, which version of
 * each plugin, which of them is out of date. That is the detail a scanner turns
 * straight into a list of known vulnerabilities, and it is worth nothing to a
 * visitor.
 *
 * Everything in this class removes output. Nothing is added, nothing is served
 * conditionally, and no request is inspected to decide what to send, so a
 * crawler and a visitor receive byte for byte the same page.
 *
 * @since 1.0.0
 */
class Beaver_Shield_Fingerprint {

	/**
	 * Cache of asset URL to short revision, for the life of the request.
	 *
	 * @var array<string,string>
	 */
	private static $revisions = array();

	/**
	 * Registers hooks.
	 *
	 * @since 1.0.0
	 */
	public static function init() {
		if ( Beaver_Shield_Settings::on( 'strip_generator' ) ) {
			self::strip_generator();
		}

		if ( Beaver_Shield_Settings::on( 'strip_discovery' ) ) {
			self::strip_discovery();
		}

		if ( Beaver_Shield_Settings::on( 'strip_versions' ) ) {
			add_filter( 'script_loader_src', array( __CLASS__, 'filter_asset_src' ), 20 );
			add_filter( 'style_loader_src', array( __CLASS__, 'filter_asset_src' ), 20 );
		}

		if ( Beaver_Shield_Settings::on( 'strip_headers' ) ) {
			add_action( 'send_headers', array( __CLASS__, 'strip_response_headers' ), 1 );
			add_filter( 'wp_headers', array( __CLASS__, 'filter_wp_headers' ), 20 );
		}

		if ( Beaver_Shield_Settings::on( 'disable_emoji' ) ) {
			self::disable_emoji();
		}
	}

	/**
	 * Removes the generator tag and the version string that follows it around.
	 *
	 * The meta tag is the obvious one. The same version is also stamped into
	 * every feed, which is why `the_generator` is emptied rather than only the
	 * `wp_head` action being unhooked.
	 *
	 * @since 1.0.0
	 */
	private static function strip_generator() {
		remove_action( 'wp_head', 'wp_generator' );

		add_filter( 'the_generator', '__return_empty_string' );

		foreach ( array( 'html', 'xhtml', 'atom', 'rss2', 'rdf', 'comment', 'export' ) as $type ) {
			add_filter( 'get_the_generator_' . $type, '__return_empty_string' );
		}
	}

	/**
	 * Removes the discovery links nobody uses any more.
	 *
	 * RSD and wlwmanifest exist for Windows Live Writer and its contemporaries.
	 * They have no modern client, they point at `xmlrpc.php` and the includes
	 * directory by name, and detectors read them as a WordPress signature. The
	 * shortlink and the adjacent post links are equally free to lose: search
	 * engines use the canonical tag, not these.
	 *
	 * The REST discovery link goes too. The route itself stays open, because
	 * blocking it would break the block editor and half the plugins on a normal
	 * site. Only the advertisement in the head and the `Link` header go.
	 *
	 * @since 1.0.0
	 */
	private static function strip_discovery() {
		remove_action( 'wp_head', 'rsd_link' );
		remove_action( 'wp_head', 'wlwmanifest_link' );
		remove_action( 'wp_head', 'wp_shortlink_wp_head' );
		remove_action( 'wp_head', 'adjacent_posts_rel_link_wp_head' );
		remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
		remove_action( 'wp_head', 'rest_output_link_wp_head' );

		remove_action( 'template_redirect', 'wp_shortlink_header', 11 );
		remove_action( 'template_redirect', 'rest_output_link_header', 11 );
	}

	/**
	 * Replaces the version on every script and style URL with a content hash.
	 *
	 * Dropping `?ver=` outright is the usual advice and it is wrong: the query
	 * string is what tells a browser its cached copy is stale, and a site
	 * without it serves yesterday's CSS after every deploy. So the parameter is
	 * kept and its value replaced with eight characters of the file's
	 * modification time. Cache busting still works exactly as before, and the
	 * value no longer announces which release of core or of a plugin produced
	 * the file.
	 *
	 * Assets that are not local files, which is to say anything on a CDN, lose
	 * the parameter instead. There is nothing on disk to hash and their URLs are
	 * already versioned by whoever serves them.
	 *
	 * @since 1.0.0
	 *
	 * @param string $src Asset URL.
	 * @return string Filtered URL.
	 */
	public static function filter_asset_src( $src ) {
		if ( ! is_string( $src ) || '' === $src || false === strpos( $src, 'ver=' ) ) {
			return $src;
		}

		$revision = self::revision_for( $src );

		$src = remove_query_arg( 'ver', $src );

		if ( '' !== $revision ) {
			$src = add_query_arg( 'v', $revision, $src );
		}

		return $src;
	}

	/**
	 * Works out a short revision string for an asset.
	 *
	 * @since 1.0.0
	 *
	 * @param string $src Asset URL.
	 * @return string Eight hex characters, or an empty string when the file is not local.
	 */
	private static function revision_for( $src ) {
		$path = self::local_path( $src );

		if ( '' === $path ) {
			return '';
		}

		if ( isset( self::$revisions[ $path ] ) ) {
			return self::$revisions[ $path ];
		}

		$revision = '';

		if ( is_readable( $path ) ) {
			$mtime = @filemtime( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a missing file is a valid answer, not an error worth logging on every page view.

			if ( $mtime ) {
				$revision = substr( md5( $path . $mtime ), 0, 8 );
			}
		}

		self::$revisions[ $path ] = $revision;

		return $revision;
	}

	/**
	 * Maps an asset URL back to a file on disk.
	 *
	 * @since 1.0.0
	 *
	 * @param string $src Asset URL.
	 * @return string Absolute path, or an empty string when the asset is not served from this site.
	 */
	private static function local_path( $src ) {
		static $home_host = null;
		static $home_path = null;

		if ( null === $home_host ) {
			// The stored base, not home_url(), so a language prefix added by a
			// multilingual plugin cannot make every asset stop resolving to a
			// file and quietly take cache busting with it.
			$home      = wp_parse_url( Beaver_Shield_Settings::home_base() );
			$home_host = $home['host'] ?? '';
			$home_path = untrailingslashit( $home['path'] ?? '' );
		}

		$parts = wp_parse_url( $src );

		if ( ! empty( $parts['host'] ) && $parts['host'] !== $home_host ) {
			return '';
		}

		$path = $parts['path'] ?? '';

		if ( '' === $path ) {
			return '';
		}

		// A WordPress installed in a subdirectory has that directory in every
		// asset URL but not in ABSPATH, so it comes off before they are joined.
		if ( '' !== $home_path && 0 === strpos( $path, $home_path . '/' ) ) {
			$path = substr( $path, strlen( $home_path ) );
		}

		return untrailingslashit( ABSPATH ) . '/' . ltrim( $path, '/' );
	}

	/**
	 * Drops the headers that name the software.
	 *
	 * `X-Powered-By` is PHP's, added before any of this runs, and it carries the
	 * exact PHP version. It can only be removed, never prevented, so it is
	 * removed as early in the response as a plugin can act.
	 *
	 * @since 1.0.0
	 */
	public static function strip_response_headers() {
		if ( headers_sent() ) {
			return;
		}

		header_remove( 'X-Powered-By' );
		header_remove( 'X-Pingback' );
	}

	/**
	 * Removes the pingback advertisement from the response.
	 *
	 * @since 1.0.0
	 *
	 * @param array $headers Response headers.
	 * @return array Filtered headers.
	 */
	public static function filter_wp_headers( $headers ) {
		unset( $headers['X-Pingback'] );

		return $headers;
	}

	/**
	 * Stops the emoji polyfill loading.
	 *
	 * `wp-emoji-release.min.js` is a named WordPress file on a fixed path, which
	 * makes it a reliable detection signal, and it exists to render emoji on
	 * browsers that have supported them natively for a decade. Removing it takes
	 * a request and a script off every page as well.
	 *
	 * @since 1.0.0
	 */
	private static function disable_emoji() {
		remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
		remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
		remove_action( 'wp_print_styles', 'print_emoji_styles' );
		remove_action( 'admin_print_styles', 'print_emoji_styles' );
		remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
		remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
		remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );

		add_filter( 'tiny_mce_plugins', array( __CLASS__, 'remove_emoji_tinymce' ) );
		add_filter( 'wp_resource_hints', array( __CLASS__, 'remove_emoji_prefetch' ), 10, 2 );
	}

	/**
	 * Removes the emoji plugin from the classic editor.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $plugins TinyMCE plugins.
	 * @return array Filtered plugins.
	 */
	public static function remove_emoji_tinymce( $plugins ) {
		return is_array( $plugins ) ? array_diff( $plugins, array( 'wpemoji' ) ) : array();
	}

	/**
	 * Removes the DNS prefetch for the emoji CDN.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $hints Resource hints.
	 * @param string $type  Hint type.
	 * @return array Filtered hints.
	 */
	public static function remove_emoji_prefetch( $hints, $type ) {
		if ( 'dns-prefetch' !== $type ) {
			return $hints;
		}

		foreach ( $hints as $index => $hint ) {
			if ( is_string( $hint ) && false !== strpos( $hint, 's.w.org' ) ) {
				unset( $hints[ $index ] );
			}
		}

		return array_values( $hints );
	}
}
