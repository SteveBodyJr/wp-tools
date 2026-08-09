<?php
/**
 * Settings.
 *
 * @package BeaverAccess
 */

defined( 'ABSPATH' ) || exit;

/**
 * Plugin options.
 *
 * @since 1.0.0
 */
class Beaver_Access_Settings {

	const OPTION = 'beaver_access_settings';

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
			// On by default: a token in a URL travels in clear text over plain
			// HTTP, where anyone on the path can read and reuse it.
			'require_ssl'  => 1,
			'notify_admin' => 1,
			'default_role' => 'administrator',
			'default_mins' => 1440,
			'default_uses' => 1,
		);
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
	 * Reads one setting.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key     Key.
	 * @param mixed  $default Fallback.
	 * @return mixed Value.
	 */
	public static function get( $key, $default = null ) {
		$all = self::all();

		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
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

		/*
		 * Absent keys fall back to what is stored, not to the shipped defaults.
		 * The settings form posts only require_ssl and notify_admin, so falling
		 * back to defaults would quietly reset the other three every time
		 * anybody pressed Save.
		 */
		$current = self::all();

		$role = sanitize_key( $input['default_role'] ?? $current['default_role'] );

		return array(
			'require_ssl'  => empty( $input['require_ssl'] ) ? 0 : 1,
			'notify_admin' => empty( $input['notify_admin'] ) ? 0 : 1,
			'default_role' => get_role( $role ) ? $role : $defaults['default_role'],
			'default_mins' => (int) max( Beaver_Access_Links::MIN_MINUTES, min( Beaver_Access_Links::MAX_MINUTES, (int) ( $input['default_mins'] ?? $current['default_mins'] ) ) ),
			'default_uses' => (int) max( 1, min( 100, (int) ( $input['default_uses'] ?? $current['default_uses'] ) ) ),
		);
	}
}
