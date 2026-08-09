<?php
/**
 * Standalone reader support.
 *
 * @package BeaverDebug
 */

defined( 'ABSPATH' ) || exit;

/**
 * Keeps the log readable when WordPress will not load.
 *
 * A fatal that fires on every request takes wp-admin with it, which makes the
 * admin screen unreachable at precisely the moment the log matters most. The
 * reader in viewer.php runs without WordPress; this class writes the small
 * config file it needs, because that file cannot ask the database anything.
 *
 * @since 1.1.0
 */
class Beaver_Debug_Viewer {

	const CONFIG = 'viewer-config.php';

	/**
	 * Ensures a token exists and the reader's config matches it.
	 *
	 * @since 1.1.0
	 *
	 * @param bool $regenerate Issue a new token, invalidating the old URL.
	 * @return string The token.
	 */
	public static function ensure( $regenerate = false ) {
		$token = (string) Beaver_Debug_Settings::get( 'viewer_token', '' );

		if ( $regenerate || 32 !== strlen( $token ) ) {
			$token = wp_generate_password( 32, false, false );

			$settings                 = Beaver_Debug_Settings::all();
			$settings['viewer_token'] = $token;

			update_option( Beaver_Debug_Settings::OPTION, $settings );
			Beaver_Debug_Settings::flush();
		}

		self::write_config( $token );

		return $token;
	}

	/**
	 * Writes the config the standalone reader loads.
	 *
	 * Only a hash of the token is stored. Someone who can read this file can
	 * already read the log next to it, but a hash means the file cannot hand
	 * out a working URL.
	 *
	 * Hashed with PHP's own password_hash() rather than wp_hash_password():
	 * WordPress 6.8 and later pre-hash the input and prefix the result with
	 * `$wp$`, which plain password_verify() cannot check. The reader has no
	 * WordPress to ask, so the hash it is given has to be one PHP can verify
	 * on its own.
	 *
	 * @since 1.1.0
	 *
	 * @param string $token The token.
	 */
	private static function write_config( $token ) {
		$dir = Beaver_Debug_Logger::dir();

		if ( '' === $dir ) {
			return;
		}

		$config = sprintf(
			"<?php\n// Written by Beaver Debug. Do not edit.\nreturn %s;\n",
			var_export( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export
				array(
					'hash'     => password_hash( $token, PASSWORD_DEFAULT ),
					'log'      => $dir . 'events.log',
					// Kept beside the log rather than in the plugin folder: a
					// plugin directory is routinely read-only to the web server,
					// and a rate limit that cannot write is not a rate limit.
					'attempts' => $dir . 'viewer-attempts.json',
					'site'     => home_url( '/' ),
				),
				true
			)
		);

		file_put_contents( BEAVER_DEBUG_PATH . self::CONFIG, $config ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	/**
	 * Returns the reader URL.
	 *
	 * @since 1.1.0
	 *
	 * @return string URL, or an empty string when no token exists.
	 */
	public static function url() {
		$token = (string) Beaver_Debug_Settings::get( 'viewer_token', '' );

		if ( '' === $token ) {
			return '';
		}

		return add_query_arg( 'token', rawurlencode( $token ), BEAVER_DEBUG_URL . 'viewer.php' );
	}

	/**
	 * Removes the reader's config.
	 *
	 * @since 1.1.0
	 */
	public static function remove_config() {
		$path = BEAVER_DEBUG_PATH . self::CONFIG;

		if ( file_exists( $path ) ) {
			wp_delete_file( $path );
		}
	}
}
