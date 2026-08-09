<?php
/**
 * WP-CLI commands.
 *
 * @package BeaverUpdates
 */

defined( 'ABSPATH' ) || exit;

/**
 * Checks the update channel from the command line.
 *
 * @since 1.0.0
 */
class Beaver_Updates_CLI {

	/**
	 * Lists every Digital Beaver plugin and whether this site is current.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format. table, json, csv or yaml.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp beaver-updates status
	 *
	 * @since 1.0.0
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function status( $args, $assoc_args ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$manifest = Beaver_Updates_Channel::get();

		if ( '' !== $manifest['error'] ) {
			WP_CLI::warning( $manifest['error'] );
		}

		$installed = array();

		foreach ( get_plugins() as $file => $data ) {
			$installed[ dirname( $file ) ] = $data['Version'];
		}

		$rows   = array();
		$behind = 0;

		foreach ( Beaver_Updates_Channel::plugins() as $slug => $entry ) {
			$here = isset( $installed[ $slug ] ) ? $installed[ $slug ] : '';

			if ( '' === $here ) {
				$state = 'not installed';
			} elseif ( version_compare( $entry['version'], $here, '>' ) ) {
				$state = 'UPDATE';
				$behind++;
			} elseif ( version_compare( $here, $entry['version'], '>' ) ) {
				$state = 'ahead';
			} else {
				$state = 'current';
			}

			$rows[] = array(
				'plugin'    => $slug,
				'installed' => '' === $here ? '-' : $here,
				'channel'   => $entry['version'],
				'status'    => $state,
			);
		}

		WP_CLI\Utils\format_items(
			isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table',
			$rows,
			array( 'plugin', 'installed', 'channel', 'status' )
		);

		if ( $behind ) {
			WP_CLI::warning( sprintf( '%d behind. Run: wp plugin update <slug>', $behind ) );

			return;
		}

		WP_CLI::success( 'Everything installed from this channel is current.' );
	}

	/**
	 * Fetches the manifest again, ignoring the cache.
	 *
	 * ## EXAMPLES
	 *
	 *     wp beaver-updates check
	 *
	 * @since 1.0.0
	 */
	public function check() {
		Beaver_Updates_Channel::forget();

		$manifest = Beaver_Updates_Channel::refresh();

		delete_site_transient( 'update_plugins' );

		if ( '' !== $manifest['error'] ) {
			WP_CLI::error( $manifest['error'] );
		}

		WP_CLI::success( sprintf( 'Channel read: %d plugins published.', count( $manifest['plugins'] ) ) );
	}
}
