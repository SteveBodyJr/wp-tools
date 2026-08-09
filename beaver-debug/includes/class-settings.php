<?php
/**
 * Settings storage.
 *
 * @package BeaverDebug
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the plugin's options.
 *
 * Kept apart from the admin screens because the capture handlers run long
 * before wp-admin exists, and they need these values on every request.
 *
 * @since 1.0.0
 */
class Beaver_Debug_Settings {

	const OPTION = 'beaver_debug_settings';

	/**
	 * Runtime cache.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Returns the shipped defaults.
	 *
	 * @since 1.0.0
	 *
	 * @return array Defaults.
	 */
	public static function defaults() {
		return array(
			'enabled'      => 1,
			// Warnings are worth having; notices on a WordPress site are mostly
			// third-party noise that buries the events you care about.
			'level'        => 'warning',
			'capture_http' => 1,
			'capture_js'   => 1,
			'capture_db'   => 1,
			'slow_request' => 0,
			'retain_days'  => 14,

			// Alerting.
			'alert_on'      => 'fatal',
			'alert_email'   => '',
			'alert_webhook' => '',

			// Fleet digest.
			'hub_url'       => '',
			'hub_key'       => '',

			// Standalone reader.
			'viewer_token'  => '',
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
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback.
	 * @return mixed Value.
	 */
	public static function get( $key, $default = null ) {
		$all = self::all();

		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Drops the runtime cache.
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
	 * @return array Sanitized settings.
	 */
	public static function sanitize( $input ) {
		$defaults = self::defaults();
		$input    = is_array( $input ) ? $input : array();

		$level = sanitize_key( $input['level'] ?? $defaults['level'] );

		return array(
			'enabled'      => empty( $input['enabled'] ) ? 0 : 1,
			'level'        => in_array( $level, array( 'fatal', 'warning', 'all' ), true ) ? $level : $defaults['level'],
			'capture_http' => empty( $input['capture_http'] ) ? 0 : 1,
			'capture_js'   => empty( $input['capture_js'] ) ? 0 : 1,
			'capture_db'   => empty( $input['capture_db'] ) ? 0 : 1,
			'slow_request' => (int) max( 0, min( 60, (int) ( $input['slow_request'] ?? $defaults['slow_request'] ) ) ),
			'retain_days'  => (int) max( 1, min( 90, (int) ( $input['retain_days'] ?? $defaults['retain_days'] ) ) ),

			'alert_on'      => in_array( sanitize_key( $input['alert_on'] ?? '' ), array( 'off', 'fatal', 'fatal_db', 'all' ), true )
				? sanitize_key( $input['alert_on'] )
				: $defaults['alert_on'],
			'alert_email'   => sanitize_email( $input['alert_email'] ?? '' ),
			'alert_webhook' => esc_url_raw( trim( (string) ( $input['alert_webhook'] ?? '' ) ) ),

			'hub_url'       => esc_url_raw( trim( (string) ( $input['hub_url'] ?? '' ) ) ),
			'hub_key'       => trim( sanitize_text_field( $input['hub_key'] ?? '' ) ),

			// Never sanitized away: written by the plugin, not by a form.
			'viewer_token'  => (string) self::get( 'viewer_token', '' ),
		);
	}
}
