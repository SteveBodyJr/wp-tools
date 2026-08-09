<?php
/**
 * WP-CLI commands.
 *
 * @package BeaverShutter
 */

defined( 'ABSPATH' ) || exit;

/**
 * Opens and closes the site from the command line.
 *
 * This is the recovery path that does not depend on the browser: if a site is
 * dark and you would rather not click through wp-admin — or you are working
 * across a fleet — a single command reopens it.
 *
 * @since 1.0.0
 */
class Beaver_Shutter_CLI {

	/**
	 * Shows whether the site is open or closed.
	 *
	 * ## EXAMPLES
	 *
	 *     wp beaver-shutter status
	 *
	 * @since 1.0.0
	 */
	public function status() {
		$level  = Beaver_Shutter_Settings::get( 'level' );
		$levels = Beaver_Shutter_Settings::levels();

		if ( defined( 'BEAVER_SHUTTER_OFF' ) && BEAVER_SHUTTER_OFF ) {
			WP_CLI::log( 'BEAVER_SHUTTER_OFF is set in wp-config.php: the site is forced open regardless of the stored level.' );
		}

		WP_CLI::log( sprintf( 'Level: %s — %s', $level, $levels[ $level ]['label'] ) );
		WP_CLI::log( Beaver_Shutter_Settings::is_closed() ? 'The front end is closed.' : 'The site is open.' );
	}

	/**
	 * Closes the site.
	 *
	 * ## OPTIONS
	 *
	 * [--level=<level>]
	 * : How closed. "visitors" shows the holding page to the public only;
	 * "full" (the default) shows it to everyone.
	 * ---
	 * default: full
	 * options:
	 *   - visitors
	 *   - full
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp beaver-shutter close
	 *     wp beaver-shutter close --level=visitors
	 *
	 * @since 1.0.0
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Flags.
	 */
	public function close( $args, $assoc_args ) {
		$level = sanitize_key( $assoc_args['level'] ?? Beaver_Shutter_Settings::FULL );

		if ( ! in_array( $level, array( Beaver_Shutter_Settings::VISITORS, Beaver_Shutter_Settings::FULL ), true ) ) {
			WP_CLI::error( 'Level must be "visitors" or "full".' );
		}

		$levels = Beaver_Shutter_Settings::levels();

		Beaver_Shutter_Settings::update( array( 'level' => $level ) );
		Beaver_Shutter_Log::record( 'closed', sprintf( 'Site closed from WP-CLI — %s.', $levels[ $level ]['label'] ) );

		WP_CLI::success( sprintf( 'Site closed (%s).', $levels[ $level ]['label'] ) );
	}

	/**
	 * Reopens the site.
	 *
	 * ## EXAMPLES
	 *
	 *     wp beaver-shutter off
	 *
	 * @since 1.0.0
	 */
	public function off() {
		Beaver_Shutter_Settings::update( array( 'level' => Beaver_Shutter_Settings::OPEN ) );
		Beaver_Shutter_Log::record( 'reopened', 'Site reopened from WP-CLI.' );

		WP_CLI::success( 'The site is open.' );
	}
}
