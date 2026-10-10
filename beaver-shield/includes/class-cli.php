<?php
/**
 * WP-CLI commands.
 *
 * @package BeaverShield
 */

defined( 'ABSPATH' ) || exit;

/**
 * Runs the shield from the command line.
 *
 * Two jobs. The scan is worth having here because it is the one thing in the
 * plugin that can be run across a fleet and diffed. The login reset is the
 * recovery path that does not need a browser, which is the whole reason moving
 * the login page is safe to offer at all.
 *
 * @since 1.0.0
 */
class Beaver_Shield_CLI {

	/**
	 * Reports what the site currently gives away.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp beaver-shield scan
	 *     wp beaver-shield scan --format=json
	 *
	 * @since 1.0.0
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Flags.
	 */
	public function scan( $args, $assoc_args ) {
		WP_CLI::log( 'Reading the site over HTTP as an anonymous visitor…' );

		$scan = Beaver_Shield_Scan::run();

		WP_CLI\Utils\format_items(
			$assoc_args['format'] ?? 'table',
			$scan['checks'],
			array( 'status', 'label', 'detail' )
		);

		$tally = Beaver_Shield_Scan::tally( $scan );

		if ( $tally['fail'] > 0 ) {
			WP_CLI::warning( sprintf( '%d checks came back exposed.', $tally['fail'] ) );

			return;
		}

		WP_CLI::success( sprintf( '%d checks clear, %d worth a look.', $tally['pass'], $tally['warn'] ) );
	}

	/**
	 * Shows what is switched on.
	 *
	 * ## EXAMPLES
	 *
	 *     wp beaver-shield status
	 *
	 * @since 1.0.0
	 */
	public function status() {
		if ( Beaver_Shield_Plugin::suspended() ) {
			WP_CLI::warning( 'BEAVER_SHIELD_OFF is set in wp-config.php. Nothing is being applied.' );
		}

		$rows = array();

		foreach ( Beaver_Shield_Settings::defaults() as $key => $default ) {
			if ( ! is_bool( $default ) ) {
				continue;
			}

			$rows[] = array(
				'setting' => $key,
				'state'   => Beaver_Shield_Settings::on( $key ) ? 'on' : 'off',
			);
		}

		WP_CLI\Utils\format_items( 'table', $rows, array( 'setting', 'state' ) );

		WP_CLI::log( 'Login URL: ' . Beaver_Shield_Login::login_url() );
	}

	/**
	 * Manages the login URL.
	 *
	 * ## OPTIONS
	 *
	 * <operation>
	 * : "show" prints the current login URL, "reset" puts it back to
	 * wp-login.php.
	 * ---
	 * options:
	 *   - show
	 *   - reset
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp beaver-shield login show
	 *     wp beaver-shield login reset
	 *
	 * @since 1.0.0
	 *
	 * @param array $args Positional arguments.
	 */
	public function login( $args ) {
		$operation = $args[0] ?? 'show';

		if ( 'reset' === $operation ) {
			Beaver_Shield_Settings::update( array( 'login_slug' => '' ) );

			WP_CLI::success( 'The login page is back at wp-login.php.' );

			return;
		}

		WP_CLI::log( Beaver_Shield_Login::login_url() );
	}

	/**
	 * Rewrites the server rules from the current settings.
	 *
	 * ## EXAMPLES
	 *
	 *     wp beaver-shield sync
	 *
	 * @since 1.0.0
	 */
	public function sync() {
		$blocked = Beaver_Shield_Htaccess::blocked_reason();

		if ( '' !== $blocked ) {
			WP_CLI::warning( $blocked );

			return;
		}

		$written = Beaver_Shield_Htaccess::sync();

		if ( empty( $written['root'] ) ) {
			WP_CLI::error( 'Could not write ' . Beaver_Shield_Htaccess::root_file() . '. Check the file permissions.' );
		}

		WP_CLI::success( 'Server rules written.' );
	}

	/**
	 * Rebuilds llms.txt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp beaver-shield llms
	 *
	 * @since 1.0.0
	 */
	public function llms() {
		Beaver_Shield_Crawlers::flush_llms_txt();

		WP_CLI::log( Beaver_Shield_Crawlers::build_llms_txt() );
	}
}
