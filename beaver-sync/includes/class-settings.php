<?php
/**
 * Settings.
 *
 * @package BeaverSync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Which end of the wire this site is, and how to reach the other one.
 *
 * @since 1.0.0
 */
class Beaver_Sync_Settings {

	const OPTION = 'beaver_sync';

	/** Serves the list. The live site. */
	const SOURCE = 'source';

	/** Reads the list and downloads the difference. The local copy. */
	const COPY = 'copy';

	/** Nothing chosen yet. */
	const IDLE = 'idle';

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
			'role'       => self::IDLE,
			'key'        => '',
			'source_url' => '',
			'source_key' => '',
			'last_run'   => '',
			'last_result' => '',
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

		update_option( self::OPTION, $settings, 'no' );

		self::$cache = $settings;

		return $settings;
	}

	/** Drops the cache. */
	public static function flush() {
		self::$cache = null;
	}

	/**
	 * The key this site expects on an incoming manifest request.
	 *
	 * A BEAVER_SYNC_KEY constant in wp-config.php always wins, which keeps the
	 * key out of the database and out of any database export.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public static function key() {
		if ( defined( 'BEAVER_SYNC_KEY' ) && '' !== trim( (string) BEAVER_SYNC_KEY ) ) {
			return trim( (string) BEAVER_SYNC_KEY );
		}

		return (string) self::get( 'key' );
	}

	/** Whether the key comes from wp-config.php rather than the database. */
	public static function key_is_constant() {
		return defined( 'BEAVER_SYNC_KEY' ) && '' !== trim( (string) BEAVER_SYNC_KEY );
	}

	/**
	 * Makes a key, if this site is a source and does not have one yet.
	 *
	 * @since 1.0.0
	 *
	 * @return string The key now in force.
	 */
	public static function ensure_key() {
		if ( '' !== self::key() ) {
			return self::key();
		}

		$key = wp_generate_password( 48, false, false );

		self::update( array( 'key' => $key ) );

		return $key;
	}

	/** Is this the site that serves the list? */
	public static function is_source() {
		return self::SOURCE === self::get( 'role' );
	}

	/** Is this the site that pulls? */
	public static function is_copy() {
		return self::COPY === self::get( 'role' );
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

		$role = isset( $input['role'] ) ? sanitize_key( $input['role'] ) : self::IDLE;

		if ( ! in_array( $role, array( self::SOURCE, self::COPY, self::IDLE ), true ) ) {
			$role = self::IDLE;
		}

		// Stored without a trailing slash so it can be concatenated predictably.
		$url = isset( $input['source_url'] ) ? esc_url_raw( trim( (string) $input['source_url'] ) ) : '';
		$url = $url ? untrailingslashit( $url ) : '';

		return array(
			'role'        => $role,
			'key'         => self::clean_key( $input['key'] ?? '' ),
			'source_url'  => $url,
			'source_key'  => self::clean_key( $input['source_key'] ?? '' ),
			'last_run'    => sanitize_text_field( $input['last_run'] ?? $defaults['last_run'] ),
			'last_result' => sanitize_text_field( $input['last_result'] ?? $defaults['last_result'] ),
		);
	}

	/**
	 * A key is letters and digits only, so a stray space pasted from an email
	 * cannot turn into an authentication failure nobody can see.
	 *
	 * @since 1.0.0
	 *
	 * @param string $value Raw key.
	 * @return string
	 */
	private static function clean_key( $value ) {
		return preg_replace( '/[^A-Za-z0-9]/', '', (string) $value );
	}
}
