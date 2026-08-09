<?php
/**
 * Settings.
 *
 * @package BeaverShutter
 */

defined( 'ABSPATH' ) || exit;

/**
 * The one setting that matters — how closed the site is — plus the wording of
 * the holding page.
 *
 * @since 1.0.0
 */
class Beaver_Shutter_Settings {

	const OPTION = 'beaver_shutter';

	// How closed the front end is.
	const OPEN     = 'open';     // Live. Nothing shown.
	const VISITORS = 'visitors'; // Public sees the holding page; logged-in users see the site.
	const FULL     = 'full';     // Everyone sees the holding page. The site is dark.

	/**
	 * Runtime cache.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * The levels, in order, with what each one does.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string,array<string,string>> Level => label and description.
	 */
	public static function levels() {
		return array(
			self::OPEN     => array(
				'label' => __( 'Open', 'beaver-shutter' ),
				'desc'  => __( 'The site is live. The plugin shows nothing and behaves as if it were not installed.', 'beaver-shutter' ),
			),
			self::VISITORS => array(
				'label' => __( 'Closed to visitors', 'beaver-shutter' ),
				'desc'  => __( 'The public sees the holding page. Anyone logged in still sees the real site — useful for a launch you are staging, or a soft close.', 'beaver-shutter' ),
			),
			self::FULL     => array(
				'label' => __( 'Dark', 'beaver-shutter' ),
				'desc'  => __( 'Everyone sees the holding page and the front end is effectively offline. Your wp-admin still works normally, so you keep full control of the site while it is down.', 'beaver-shutter' ),
			),
		);
	}

	/**
	 * Shipped defaults.
	 *
	 * @since 1.0.0
	 *
	 * @return array Defaults.
	 */
	public static function defaults() {
		return array(
			'level'       => self::OPEN,
			'title'       => '',
			'body'        => '',
			'contact'     => '',
			'retry_after' => 3600,
			'last_level'  => self::OPEN,
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
	 * Whether the site is closed at all.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True when the level is anything but open.
	 */
	public static function is_closed() {
		return self::OPEN !== self::get( 'level' );
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

		$level = isset( $input['level'] ) ? sanitize_key( $input['level'] ) : self::OPEN;

		if ( ! array_key_exists( $level, self::levels() ) ) {
			$level = self::OPEN;
		}

		return array(
			'level'       => $level,
			'title'       => sanitize_text_field( $input['title'] ?? $defaults['title'] ),
			'body'        => wp_kses_post( $input['body'] ?? $defaults['body'] ),
			'contact'     => sanitize_text_field( $input['contact'] ?? $defaults['contact'] ),
			'retry_after' => (int) max( 60, min( DAY_IN_SECONDS, (int) ( $input['retry_after'] ?? $defaults['retry_after'] ) ) ),
			'last_level'  => sanitize_key( $input['last_level'] ?? $defaults['last_level'] ),
		);
	}
}
