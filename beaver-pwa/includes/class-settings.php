<?php
/**
 * Settings storage, defaults and sanitisation.
 *
 * @package BeaverPWA
 */

defined( 'ABSPATH' ) || exit;

/**
 * Single source of truth for every configurable value.
 *
 * Nothing else in the plugin reads the option directly: helpers here resolve
 * empty fields to sensible site-wide fallbacks so the plugin works the moment
 * it is activated.
 *
 * @since 1.0.0
 */
final class Beaver_PWA_Settings {

	const OPTION      = 'beaver_pwa_settings';
	const OPTION_BUMP = 'beaver_pwa_cache_bump';

	/**
	 * Runtime cache of the merged settings.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Returns the shipped defaults.
	 *
	 * Empty strings mean "derive from the site" and are resolved by the
	 * helpers below rather than being frozen into the database at activation.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'enabled'             => 1,
			'app_name'            => '',
			'short_name'          => '',
			'description'         => '',
			'theme_color'         => '#1d2327',
			'background_color'    => '#ffffff',
			'display'             => 'standalone',
			'orientation'         => 'any',
			'start_path'          => '/',
			'start_tracking'      => 1,
			'categories'          => '',
			'icon_id'             => 0,
			'maskable'            => 1,
			'shortcuts'           => array(),
			'offline_enabled'     => 1,
			'offline_page_id'     => 0,
			'cache_pages'         => 1,
			'cache_assets'        => 1,
			'cache_images'        => 1,
			'page_cache_limit'    => 40,
			'image_cache_limit'   => 60,
			'exclusions'          => '',
			'update_toast'        => 0,
			'register_logged_in'  => 1,
			'prompt_enabled'      => 1,
			'prompt_position'     => 'bottom-right',
			'prompt_delay'        => 5,
			'prompt_text'         => '',
			'prompt_button'       => '',
			'prompt_dismiss_days' => 30,
			'ios_hint'            => 1,
			'apple_meta'          => 1,
			'theme_color_meta'    => 1,
			'sw_route'            => 'auto',
		);
	}

	/**
	 * Returns every setting merged over the defaults.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$stored = get_option( self::OPTION, array() );

			if ( ! is_array( $stored ) ) {
				$stored = array();
			}

			self::$cache = array_merge( self::defaults(), $stored );
		}

		return self::$cache;
	}

	/**
	 * Reads a single setting.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Value returned when the key is unknown.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$all = self::all();

		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Writes settings and clears the runtime cache.
	 *
	 * @since 1.0.0
	 *
	 * @param array $values Sanitised settings.
	 */
	public static function update( array $values ) {
		self::$cache = null;

		update_option( self::OPTION, $values, true );
	}

	/**
	 * Stores the defaults on first activation without clobbering an upgrade.
	 *
	 * @since 1.0.0
	 */
	public static function install_defaults() {
		$stored = get_option( self::OPTION, null );

		if ( ! is_array( $stored ) ) {
			add_option( self::OPTION, self::defaults(), '', true );

			return;
		}

		self::update( array_merge( self::defaults(), $stored ) );
	}

	/*
	 * -----------------------------------------------------------------------
	 * Sanitisation
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Sanitises a submitted settings array.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $input Raw input.
	 * @return array Sanitised settings.
	 */
	public static function sanitize( $input ) {
		$defaults = self::defaults();
		$current  = self::all();
		$input    = is_array( $input ) ? $input : array();
		$clean    = array();

		$booleans = array(
			'enabled',
			'start_tracking',
			'maskable',
			'offline_enabled',
			'cache_pages',
			'cache_assets',
			'cache_images',
			'update_toast',
			'register_logged_in',
			'prompt_enabled',
			'ios_hint',
			'apple_meta',
			'theme_color_meta',
		);

		foreach ( $booleans as $key ) {
			$clean[ $key ] = empty( $input[ $key ] ) ? 0 : 1;
		}

		$clean['app_name']    = self::clean_text( $input, 'app_name', 60 );
		$clean['short_name']  = self::clean_text( $input, 'short_name', 24 );
		$clean['description'] = self::clean_text( $input, 'description', 300 );
		$clean['categories']  = self::clean_text( $input, 'categories', 200 );

		$clean['prompt_text']   = self::clean_text( $input, 'prompt_text', 140 );
		$clean['prompt_button'] = self::clean_text( $input, 'prompt_button', 40 );

		$clean['theme_color']      = self::clean_color( $input, 'theme_color', $defaults['theme_color'] );
		$clean['background_color'] = self::clean_color( $input, 'background_color', $defaults['background_color'] );

		$clean['display']     = self::clean_choice( $input, 'display', array( 'standalone', 'fullscreen', 'minimal-ui', 'browser' ), $defaults['display'] );
		$clean['orientation'] = self::clean_choice( $input, 'orientation', array( 'any', 'portrait', 'landscape', 'portrait-primary', 'landscape-primary' ), $defaults['orientation'] );
		$clean['sw_route']    = self::clean_choice( $input, 'sw_route', array( 'auto', 'pretty', 'query' ), $defaults['sw_route'] );

		$clean['prompt_position'] = self::clean_choice(
			$input,
			'prompt_position',
			array( 'bottom-right', 'bottom-left', 'bottom-full', 'top-full' ),
			$defaults['prompt_position']
		);

		$clean['start_path'] = self::clean_path( isset( $input['start_path'] ) ? $input['start_path'] : '/' );

		$clean['icon_id']         = isset( $input['icon_id'] ) ? absint( $input['icon_id'] ) : 0;
		$clean['offline_page_id'] = isset( $input['offline_page_id'] ) ? absint( $input['offline_page_id'] ) : 0;

		$clean['page_cache_limit']    = self::clean_range( $input, 'page_cache_limit', 5, 300, $defaults['page_cache_limit'] );
		$clean['image_cache_limit']   = self::clean_range( $input, 'image_cache_limit', 5, 500, $defaults['image_cache_limit'] );
		$clean['prompt_delay']        = self::clean_range( $input, 'prompt_delay', 0, 120, $defaults['prompt_delay'] );
		$clean['prompt_dismiss_days'] = self::clean_range( $input, 'prompt_dismiss_days', 0, 365, $defaults['prompt_dismiss_days'] );

		$clean['exclusions'] = self::clean_exclusions( isset( $input['exclusions'] ) ? $input['exclusions'] : '' );
		$clean['shortcuts']  = self::clean_shortcuts( isset( $input['shortcuts'] ) ? $input['shortcuts'] : array() );

		// A settings change is a cache change: visitors must not keep the old shell.
		$before = array_intersect_key( $current, $clean );
		$after  = $clean;

		ksort( $before );
		ksort( $after );

		$changed = wp_json_encode( $before ) !== wp_json_encode( $after );

		self::$cache = null;

		if ( $changed ) {
			self::bump_cache();
		}

		if ( (int) $clean['icon_id'] !== (int) self::get( 'icon_id' ) || (int) $clean['maskable'] !== (int) self::get( 'maskable' ) ) {
			Beaver_PWA_Icons::flush();
		}

		return $clean;
	}

	/**
	 * Sanitises a bounded text field.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $input  Raw input.
	 * @param string $key    Field key.
	 * @param int    $length Maximum length.
	 * @return string
	 */
	private static function clean_text( $input, $key, $length ) {
		if ( ! isset( $input[ $key ] ) || ! is_scalar( $input[ $key ] ) ) {
			return '';
		}

		$value = sanitize_text_field( wp_unslash( (string) $input[ $key ] ) );

		return trim( mb_substr( $value, 0, $length ) );
	}

	/**
	 * Sanitises a hex colour with a fallback.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $input    Raw input.
	 * @param string $key      Field key.
	 * @param string $fallback Fallback colour.
	 * @return string
	 */
	private static function clean_color( $input, $key, $fallback ) {
		$value = isset( $input[ $key ] ) ? sanitize_hex_color( trim( (string) wp_unslash( $input[ $key ] ) ) ) : '';

		return $value ? $value : $fallback;
	}

	/**
	 * Restricts a value to a known list.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $input    Raw input.
	 * @param string $key      Field key.
	 * @param array  $allowed  Allowed values.
	 * @param string $fallback Fallback value.
	 * @return string
	 */
	private static function clean_choice( $input, $key, $allowed, $fallback ) {
		$value = isset( $input[ $key ] ) ? sanitize_key( wp_unslash( $input[ $key ] ) ) : '';

		return in_array( $value, $allowed, true ) ? $value : $fallback;
	}

	/**
	 * Clamps an integer.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $input    Raw input.
	 * @param string $key      Field key.
	 * @param int    $min      Minimum.
	 * @param int    $max      Maximum.
	 * @param int    $fallback Fallback value.
	 * @return int
	 */
	private static function clean_range( $input, $key, $min, $max, $fallback ) {
		if ( ! isset( $input[ $key ] ) || '' === $input[ $key ] ) {
			return (int) $fallback;
		}

		return (int) max( $min, min( $max, (int) $input[ $key ] ) );
	}

	/**
	 * Normalises a site-relative path.
	 *
	 * @since 1.0.0
	 *
	 * @param string $path Raw path.
	 * @return string Path beginning with a slash.
	 */
	private static function clean_path( $path ) {
		$path = trim( (string) wp_unslash( $path ) );

		if ( '' === $path ) {
			return '/';
		}

		// Accept a pasted absolute URL as long as it belongs to this site.
		if ( preg_match( '#^https?://#i', $path ) ) {
			$home = untrailingslashit( home_url( '/' ) );

			if ( 0 !== stripos( $path, $home ) ) {
				return '/';
			}

			$path = substr( $path, strlen( $home ) );
		}

		$path = '/' . ltrim( $path, '/' );
		$path = esc_url_raw( $path );

		return $path ? $path : '/';
	}

	/**
	 * Normalises the exclusion list to one path fragment per line.
	 *
	 * @since 1.0.0
	 *
	 * @param string $raw Raw textarea content.
	 * @return string
	 */
	private static function clean_exclusions( $raw ) {
		$lines = preg_split( '/[\r\n]+/', (string) wp_unslash( $raw ) );
		$clean = array();

		foreach ( (array) $lines as $line ) {
			$line = trim( sanitize_text_field( $line ) );

			if ( '' === $line || in_array( $line, $clean, true ) ) {
				continue;
			}

			$clean[] = $line;

			if ( count( $clean ) >= 50 ) {
				break;
			}
		}

		return implode( "\n", $clean );
	}

	/**
	 * Sanitises the app shortcut rows.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $raw Raw rows.
	 * @return array
	 */
	private static function clean_shortcuts( $raw ) {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$clean = array();

		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$page_id = isset( $row['page_id'] ) ? absint( $row['page_id'] ) : 0;

			if ( ! $page_id ) {
				continue;
			}

			$clean[] = array(
				'page_id' => $page_id,
				'label'   => self::clean_text( $row, 'label', 40 ),
			);

			if ( count( $clean ) >= 4 ) {
				break;
			}
		}

		return $clean;
	}

	/*
	 * -----------------------------------------------------------------------
	 * Derived values
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Whether the manifest and service worker should be served at all.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return (bool) self::get( 'enabled' );
	}

	/**
	 * Application name, falling back to the site title.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public static function app_name() {
		$name = self::get( 'app_name' );

		if ( '' === $name ) {
			$name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		}

		return $name ? $name : __( 'Web App', 'beaver-pwa' );
	}

	/**
	 * Short name shown under the home screen icon.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public static function short_name() {
		$short = self::get( 'short_name' );

		if ( '' !== $short ) {
			return $short;
		}

		$name = self::app_name();

		if ( mb_strlen( $name ) <= 12 ) {
			return $name;
		}

		// Prefer a clean word boundary over a hard truncation.
		$words = preg_split( '/\s+/', $name );
		$short = '';

		foreach ( (array) $words as $word ) {
			$candidate = '' === $short ? $word : $short . ' ' . $word;

			if ( mb_strlen( $candidate ) > 12 ) {
				break;
			}

			$short = $candidate;
		}

		return '' !== $short ? $short : mb_substr( $name, 0, 12 );
	}

	/**
	 * Application description.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public static function description() {
		$description = self::get( 'description' );

		if ( '' === $description ) {
			$description = wp_specialchars_decode( get_bloginfo( 'description' ), ENT_QUOTES );
		}

		return $description;
	}

	/**
	 * Path the service worker and manifest are scoped to.
	 *
	 * On a subdirectory install this is the subdirectory, never the domain
	 * root, which is the widest scope the browser will allow.
	 *
	 * @since 1.0.0
	 *
	 * @return string Path with leading and trailing slashes.
	 */
	public static function scope_path() {
		$path = wp_parse_url( home_url( '/' ), PHP_URL_PATH );

		if ( ! is_string( $path ) || '' === $path ) {
			$path = '/';
		}

		return trailingslashit( $path );
	}

	/**
	 * Absolute start URL for the manifest.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public static function start_url() {
		$url = home_url( self::get( 'start_path' ) );

		if ( self::get( 'start_tracking' ) ) {
			$url = add_query_arg( 'source', 'pwa', $url );
		}

		return $url;
	}

	/**
	 * Manifest identity, kept stable so an install survives a start URL change.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public static function app_id() {
		return self::scope_path();
	}

	/**
	 * Manifest categories as an array.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public static function categories() {
		$raw = self::get( 'categories' );

		if ( '' === $raw ) {
			return array();
		}

		$parts = array_map( 'trim', explode( ',', $raw ) );
		$parts = array_filter( array_map( 'sanitize_title', $parts ) );

		return array_values( array_slice( array_unique( $parts ), 0, 6 ) );
	}

	/**
	 * User supplied exclusion fragments as an array.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public static function exclusion_list() {
		$raw = trim( (string) self::get( 'exclusions' ) );

		if ( '' === $raw ) {
			return array();
		}

		return array_values( array_filter( array_map( 'trim', preg_split( '/[\r\n]+/', $raw ) ) ) );
	}

	/*
	 * -----------------------------------------------------------------------
	 * Cache signature
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Signature embedded in the service worker and used to name its caches.
	 *
	 * Any change to the settings, the plugin version or the manual bump
	 * counter produces a new signature, which changes the worker byte for byte
	 * and makes every browser install the new one.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public static function cache_version() {
		$parts = array(
			BEAVER_PWA_VERSION,
			(string) get_option( self::OPTION_BUMP, '0' ),
			wp_json_encode( self::all() ),
			(string) get_option( 'site_icon', 0 ),
			home_url( '/' ),
		);

		return substr( md5( implode( '|', $parts ) ), 0, 12 );
	}

	/**
	 * Invalidates every cached asset in every visitor's browser.
	 *
	 * @since 1.0.0
	 *
	 * @return string The new cache signature.
	 */
	public static function bump_cache() {
		$bump = (int) get_option( self::OPTION_BUMP, 0 );

		update_option( self::OPTION_BUMP, $bump + 1, true );

		delete_transient( Beaver_PWA_Health::TRANSIENT );

		return self::cache_version();
	}
}
