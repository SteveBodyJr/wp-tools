<?php
/**
 * Settings.
 *
 * @package BeaverShield
 */

defined( 'ABSPATH' ) || exit;

/**
 * Every switch the plugin has, in one option.
 *
 * The defaults are the whole safety argument. Anything that can only reduce
 * what a scanner reads ships on; anything that can change how the site behaves
 * for a real visitor ships off, and says why on its own row in the admin
 * screen. Turning this plugin on should never be the change that broke a site.
 *
 * @since 1.0.0
 */
class Beaver_Shield_Settings {

	const OPTION = 'beaver_shield';

	// Content security policy modes.
	const CSP_OFF     = 'off';
	const CSP_REPORT  = 'report';
	const CSP_ENFORCE = 'enforce';

	// What the copy deterrent does with a copy attempt.
	const COPY_BLOCK     = 'block';
	const COPY_ATTRIBUTE = 'attribute';

	/**
	 * Runtime cache.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Shipped defaults.
	 *
	 * @since 1.0.0
	 *
	 * @return array Defaults.
	 */
	public static function defaults() {
		return array(
			// Fingerprint. All safe: they remove output, they never add any.
			'strip_generator'      => true,
			'strip_versions'       => true,
			'strip_discovery'      => true,
			'strip_headers'        => true,
			'disable_emoji'        => true,
			'block_meta_files'     => true,

			// Hardening.
			'block_user_enum'      => true,
			'disable_file_edit'    => true,
			'generic_login_errors' => true,
			'block_php_in_uploads' => true,
			'no_directory_listing' => true,
			'disable_xmlrpc'       => false,
			'hotlink_protection'   => false,

			// Headers.
			'header_frame'         => 'SAMEORIGIN',
			'header_nosniff'       => true,
			'header_referrer'      => 'strict-origin-when-cross-origin',
			'header_permissions'   => true,
			'header_hsts'          => false,
			'csp_mode'             => self::CSP_OFF,
			'csp_value'            => self::default_csp(),

			// Login. Empty means the login URL is untouched.
			'login_slug'           => '',

			// Crawlers.
			'llms_txt'             => true,
			'llms_txt_intro'       => '',
			'block_ai_crawlers'    => false,

			// Front end copy deterrent.
			'copy_guard'           => false,
			'copy_guard_mode'      => self::COPY_BLOCK,
			'copy_guard_exclude'   => '',
			'image_guard'          => false,

			// State.
			'scan'                 => array(),
		);
	}

	/**
	 * The site's real base URL, before anything rewrote it.
	 *
	 * `home_url()` is one of the most heavily filtered functions in WordPress.
	 * A multilingual plugin returns it with a language prefix on it, so on a
	 * Polylang or WPML site `home_url()` is `https://example.com/en` while
	 * viewing an English page and `https://example.com/fr` on a French one.
	 * Multi-domain, staging and URL rewriting plugins change it too.
	 *
	 * That is correct for building a link, and wrong for every other use in this
	 * plugin. Three things here do arithmetic on the path: mapping an asset URL
	 * back to a file on disk, matching the request against the custom login
	 * slug, and building the login URL itself. All three break when the base
	 * they measure against moves per request. An asset would stop resolving, so
	 * cache busting would silently switch itself off; the login slug would stop
	 * matching on every page but the default language.
	 *
	 * The stored option is the fixed answer, so that is what those three use.
	 * Anything user facing still goes through `home_url()`.
	 *
	 * @since 1.0.0
	 *
	 * @return string Base URL with no trailing slash.
	 */
	public static function home_base() {
		$home = get_option( 'home' );

		if ( ! is_string( $home ) || '' === $home ) {
			$home = home_url();
		}

		return untrailingslashit( set_url_scheme( $home ) );
	}

	/**
	 * The starting content security policy.
	 *
	 * Deliberately loose. A policy tight enough to be worth having has to be
	 * built against the site it protects, so this one exists to be edited, and
	 * to be run in report-only mode until the report is empty.
	 *
	 * @since 1.0.0
	 *
	 * @return string Policy.
	 */
	public static function default_csp() {
		return implode(
			'; ',
			array(
				"default-src 'self'",
				"script-src 'self' 'unsafe-inline' 'unsafe-eval'",
				"style-src 'self' 'unsafe-inline'",
				"img-src 'self' data: blob:",
				"font-src 'self' data:",
				"connect-src 'self'",
				"frame-ancestors 'self'",
				"base-uri 'self'",
				"form-action 'self'",
			)
		);
	}

	/**
	 * The default permissions policy.
	 *
	 * Switches off browser features this site has no reason to ask for. Nothing
	 * here is used by a normal WordPress page, so denying them costs nothing and
	 * removes a class of abuse from any script that ends up on the page.
	 *
	 * @since 1.0.0
	 *
	 * @return string Policy.
	 */
	public static function default_permissions_policy() {
		return 'accelerometer=(), autoplay=(), camera=(), display-capture=(), encrypted-media=(), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), midi=(), payment=(), usb=()';
	}

	/**
	 * Returns all settings.
	 *
	 * @since 1.0.0
	 *
	 * @return array Settings.
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$stored = get_option( self::OPTION, array() );

			self::$cache = wp_parse_args( is_array( $stored ) ? $stored : array(), self::defaults() );
		}

		return self::$cache;
	}

	/**
	 * Reads one field.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key     Field.
	 * @param mixed  $default Fallback.
	 * @return mixed Value.
	 */
	public static function get( $key, $default = null ) {
		$all = self::all();

		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Whether a boolean switch is on.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key Field.
	 * @return bool True when on.
	 */
	public static function on( $key ) {
		return (bool) self::get( $key, false );
	}

	/**
	 * Merges changes in and saves.
	 *
	 * @since 1.0.0
	 *
	 * @param array $changes Fields to change.
	 * @return array The saved settings.
	 */
	public static function update( array $changes ) {
		$settings = self::sanitize( array_merge( self::all(), $changes ) );

		update_option( self::OPTION, $settings, 'yes' );

		self::$cache = $settings;

		return $settings;
	}

	/**
	 * Drops the cache.
	 *
	 * @since 1.0.0
	 */
	public static function flush() {
		self::$cache = null;
	}

	/**
	 * Validates a settings payload.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $input Raw settings.
	 * @return array Clean settings.
	 */
	public static function sanitize( $input ) {
		$defaults = self::defaults();
		$input    = is_array( $input ) ? $input : array();
		$clean    = array();

		foreach ( $defaults as $key => $default ) {
			if ( is_bool( $default ) ) {
				$clean[ $key ] = ! empty( $input[ $key ] );
			}
		}

		$frame               = strtoupper( sanitize_text_field( $input['header_frame'] ?? $defaults['header_frame'] ) );
		$clean['header_frame'] = in_array( $frame, array( '', 'SAMEORIGIN', 'DENY' ), true ) ? $frame : 'SAMEORIGIN';

		$referrer                 = sanitize_text_field( $input['header_referrer'] ?? $defaults['header_referrer'] );
		$clean['header_referrer'] = in_array( $referrer, self::referrer_policies(), true ) ? $referrer : $defaults['header_referrer'];

		$mode              = sanitize_key( $input['csp_mode'] ?? $defaults['csp_mode'] );
		$clean['csp_mode'] = in_array( $mode, array( self::CSP_OFF, self::CSP_REPORT, self::CSP_ENFORCE ), true ) ? $mode : self::CSP_OFF;

		// A policy is a header value: no line breaks, no control characters.
		$csp                = (string) ( $input['csp_value'] ?? $defaults['csp_value'] );
		$csp                = trim( preg_replace( '/[\r\n\t]+/', ' ', $csp ) );
		$clean['csp_value'] = '' === $csp ? $defaults['csp_value'] : sanitize_text_field( $csp );

		$clean['login_slug'] = self::sanitize_login_slug( $input['login_slug'] ?? '' );

		$copy_mode                = sanitize_key( $input['copy_guard_mode'] ?? $defaults['copy_guard_mode'] );
		$clean['copy_guard_mode'] = in_array( $copy_mode, array( self::COPY_BLOCK, self::COPY_ATTRIBUTE ), true ) ? $copy_mode : self::COPY_BLOCK;

		$clean['copy_guard_exclude'] = self::sanitize_id_list( $input['copy_guard_exclude'] ?? '' );

		$clean['llms_txt_intro'] = sanitize_textarea_field( $input['llms_txt_intro'] ?? '' );

		$clean['scan'] = is_array( $input['scan'] ?? null ) ? $input['scan'] : array();

		return $clean;
	}

	/**
	 * Cleans a login slug.
	 *
	 * A slug that collides with a real path would take a page off the site, and
	 * one containing a slash would never match, so anything questionable is
	 * thrown away rather than half accepted.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value Raw slug.
	 * @return string Clean slug, or an empty string to leave the login URL alone.
	 */
	public static function sanitize_login_slug( $value ) {
		$slug = sanitize_title( (string) $value );

		if ( '' === $slug ) {
			return '';
		}

		$reserved = array( 'wp-admin', 'wp-login', 'wp-content', 'wp-includes', 'wp-json', 'admin', 'login', 'feed', 'robots', 'sitemap' );

		if ( in_array( $slug, $reserved, true ) ) {
			return '';
		}

		return $slug;
	}

	/**
	 * Cleans a comma separated list of post IDs.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value Raw list.
	 * @return string Comma separated IDs.
	 */
	public static function sanitize_id_list( $value ) {
		$ids = array_filter( array_map( 'absint', preg_split( '/[\s,]+/', (string) $value ) ) );

		return implode( ',', array_unique( $ids ) );
	}

	/**
	 * The referrer policies worth offering.
	 *
	 * @since 1.0.0
	 *
	 * @return string[] Policy values.
	 */
	public static function referrer_policies() {
		return array(
			'no-referrer',
			'same-origin',
			'strict-origin',
			'strict-origin-when-cross-origin',
			'no-referrer-when-downgrade',
		);
	}

	/**
	 * The post IDs excluded from the copy deterrent.
	 *
	 * @since 1.0.0
	 *
	 * @return int[] Post IDs.
	 */
	public static function copy_guard_exclusions() {
		$raw = (string) self::get( 'copy_guard_exclude', '' );

		return array_filter( array_map( 'absint', array_filter( explode( ',', $raw ) ) ) );
	}
}
