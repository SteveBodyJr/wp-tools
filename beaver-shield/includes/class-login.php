<?php
/**
 * Custom login URL.
 *
 * @package BeaverShield
 */

defined( 'ABSPATH' ) || exit;

/**
 * Moves wp-login.php to a path only you know.
 *
 * This is the one feature in the plugin that can lock somebody out of a site,
 * so it ships off, it says so on its own row, and it has a way back that does
 * not need a browser: `BEAVER_SHIELD_OFF` in wp-config.php stands the whole
 * plugin down, and `wp beaver-shield login reset` clears the slug from the
 * command line.
 *
 * It is worth having anyway. Nearly all password guessing traffic is a script
 * posting to /wp-login.php on a list of domains. It does not look for the form,
 * it does not follow redirects, and moving the file removes that entire volume
 * of traffic from the site. It is not a substitute for a strong password, and
 * it is not claimed to be: anyone who finds the new path is exactly where they
 * were before.
 *
 * A note on caching. If a page cache is in front of the site, exclude the new
 * path from it, the same way wp-login.php would already be excluded.
 *
 * @since 1.0.0
 */
class Beaver_Shield_Login {

	/**
	 * The configured slug.
	 *
	 * @var string
	 */
	private static $slug = '';

	/**
	 * Whether this request is the login page arriving on the new path.
	 *
	 * @var bool
	 */
	private static $serving = false;

	/**
	 * Whether this request is a direct hit on wp-login.php to be hidden.
	 *
	 * @var bool
	 */
	private static $hiding = false;

	/**
	 * Registers hooks.
	 *
	 * @since 1.0.0
	 */
	public static function init() {
		$slug = (string) Beaver_Shield_Settings::get( 'login_slug', '' );

		if ( '' === $slug ) {
			return;
		}

		self::$slug = $slug;

		add_action( 'plugins_loaded', array( __CLASS__, 'route' ), 1 );
		add_action( 'wp_loaded', array( __CLASS__, 'serve' ), 0 );

		add_filter( 'site_url', array( __CLASS__, 'filter_url' ), 10, 2 );
		add_filter( 'network_site_url', array( __CLASS__, 'filter_url' ), 10, 2 );
		add_filter( 'wp_redirect', array( __CLASS__, 'filter_redirect' ) );
	}

	/**
	 * The login path currently in force.
	 *
	 * @since 1.0.0
	 *
	 * @return string Absolute URL.
	 */
	public static function login_url() {
		$slug = (string) Beaver_Shield_Settings::get( 'login_slug', '' );

		if ( '' === $slug ) {
			return site_url( 'wp-login.php' );
		}

		// Built from the stored base rather than home_url(), so a multilingual
		// plugin cannot hand out /en/beaver-door/ as the login URL while the
		// matching below is only ever looking for /beaver-door/.
		return trailingslashit( Beaver_Shield_Settings::home_base() ) . $slug . '/';
	}

	/**
	 * Decides what this request is.
	 *
	 * @since 1.0.0
	 */
	public static function route() {
		if ( self::request_path() === self::$slug ) {
			self::$serving      = true;
			$GLOBALS['pagenow'] = 'wp-login.php';

			return;
		}

		if ( isset( $GLOBALS['pagenow'] ) && 'wp-login.php' === $GLOBALS['pagenow'] ) {
			self::hide();
		}
	}

	/**
	 * Makes a direct request for wp-login.php land on the theme's 404 page.
	 *
	 * A redirect, or a bare "access denied", would confirm the file is there and
	 * has been moved, which is the one thing the whole feature is trying not to
	 * say. The theme's own 404 page is indistinguishable from a URL that was
	 * never a page.
	 *
	 * The timing is the fiddly part. A direct request for wp-login.php is
	 * already executing wp-login.php: it loaded WordPress from its own first
	 * line, so by the time any plugin can act, the file is mid-run and will
	 * carry on printing a login form the moment control returns to it. Setting
	 * globals here is not enough. The request has to be ended before it gets
	 * that far, and `wp_loaded` is the last hook that fires while WordPress is
	 * still inside wp-load.php.
	 *
	 * @since 1.0.0
	 */
	private static function hide() {
		self::$hiding = true;

		$_SERVER['REQUEST_URI'] = '/' . str_repeat( '-/', 10 );

		$GLOBALS['pagenow'] = 'index.php';

		add_action( 'wp_loaded', array( __CLASS__, 'render_404' ), 0 );
	}

	/**
	 * Ends the request with the theme's 404 page.
	 *
	 * @since 1.0.0
	 */
	public static function render_404() {
		if ( ! self::$hiding ) {
			return;
		}

		global $wp_query;

		if ( $wp_query instanceof WP_Query ) {
			$wp_query->set_404();
		}

		status_header( 404 );
		nocache_headers();

		$template = get_404_template();

		if ( $template && file_exists( $template ) ) {
			include $template;

			exit;
		}

		// A theme with no 404 template of its own still has to answer with
		// something that is not a login form.
		wp_die(
			esc_html__( 'Page not found.', 'beaver-shield' ),
			esc_html__( 'Page not found', 'beaver-shield' ),
			array( 'response' => 404 )
		);
	}

	/**
	 * Hands the request to wp-login.php.
	 *
	 * Including the file after WordPress has loaded is safe: its own first line
	 * pulls in wp-load.php, which loads the config with `require_once`, so the
	 * second pass is a no-op rather than a redeclaration.
	 *
	 * @since 1.0.0
	 */
	public static function serve() {
		if ( ! self::$serving ) {
			return;
		}

		// wp-login.php reads all of these from the global scope.
		global $error, $interim_login, $action, $user_login, $user, $redirect_to, $pagenow;

		$pagenow = 'wp-login.php';

		require_once ABSPATH . 'wp-login.php';

		exit;
	}

	/**
	 * Rewrites URLs that WordPress builds for the login page.
	 *
	 * @since 1.0.0
	 *
	 * @param string $url  Built URL.
	 * @param string $path Path passed to site_url().
	 * @return string Filtered URL.
	 */
	public static function filter_url( $url, $path = '' ) {
		if ( ! is_string( $path ) || 0 !== strpos( ltrim( $path, '/' ), 'wp-login.php' ) ) {
			return $url;
		}

		return self::swap( $url );
	}

	/**
	 * Rewrites redirects aimed at the login page.
	 *
	 * Everything that sends a logged out visitor to wp-login.php goes through
	 * here, including the redirect from wp-admin, so the door still opens for
	 * anyone who is meant to be walking through it.
	 *
	 * @since 1.0.0
	 *
	 * @param string $location Redirect target.
	 * @return string Filtered target.
	 */
	public static function filter_redirect( $location ) {
		if ( ! is_string( $location ) || false === strpos( $location, 'wp-login.php' ) ) {
			return $location;
		}

		return self::swap( $location );
	}

	/**
	 * Swaps wp-login.php for the slug in a URL, leaving the query string alone.
	 *
	 * The query string is skipped on purpose: `redirect_to` regularly carries an
	 * encoded wp-login.php URL of its own, and rewriting that would send people
	 * somewhere they did not ask to go.
	 *
	 * @since 1.0.0
	 *
	 * @param string $url URL.
	 * @return string URL.
	 */
	private static function swap( $url ) {
		$parts = explode( '?', $url, 2 );

		$parts[0] = str_replace( 'wp-login.php', self::$slug . '/', $parts[0] );
		$parts[0] = str_replace( '//' . self::$slug, '/' . self::$slug, $parts[0] );

		return implode( '?', $parts );
	}

	/**
	 * The requested path, relative to the site root.
	 *
	 * @since 1.0.0
	 *
	 * @return string Path with no slashes at either end.
	 */
	private static function request_path() {
		$uri = (string) ( $_SERVER['REQUEST_URI'] ?? '' );

		if ( '' === $uri ) {
			return '';
		}

		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$home = untrailingslashit( (string) wp_parse_url( Beaver_Shield_Settings::home_base(), PHP_URL_PATH ) );

		if ( '' !== $home && 0 === strpos( $path, $home . '/' ) ) {
			$path = substr( $path, strlen( $home ) );
		}

		$path = trim( $path, '/' );

		/**
		 * Filters the request path the login slug is matched against.
		 *
		 * The hook a multilingual site needs. With language prefixes switched
		 * on, /fr/my-login/ arrives here as "fr/my-login" and will not match, so
		 * a site that wants the login page reachable under every prefix strips
		 * the prefix here.
		 *
		 * @since 1.0.0
		 *
		 * @param string $path Request path, relative to the site root.
		 */
		return (string) apply_filters( 'beaver_shield_request_path', $path );
	}
}
