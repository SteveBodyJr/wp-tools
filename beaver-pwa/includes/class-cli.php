<?php
/**
 * WP-CLI commands.
 *
 * @package BeaverPWA
 */

defined( 'ABSPATH' ) || exit;

/**
 * Manages the progressive web app from the command line.
 *
 * @since 1.0.0
 */
class Beaver_PWA_CLI {

	/**
	 * Reports whether the site is installable and what is missing.
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
	 *     wp beaver-pwa status
	 *
	 * @since 1.0.0
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function status( $args, $assoc_args ) {
		$checks = Beaver_PWA_Health::run( true );
		$rows   = array();

		foreach ( $checks as $check ) {
			$rows[] = array(
				'check'  => $check['id'],
				'status' => strtoupper( $check['status'] ),
				'detail' => $check['label'] . ( '' !== $check['message'] ? ': ' . $check['message'] : '' ),
			);
		}

		$format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';

		WP_CLI\Utils\format_items( $format, $rows, array( 'check', 'status', 'detail' ) );

		if ( Beaver_PWA_Health::is_installable( $checks ) ) {
			WP_CLI::success( sprintf( 'Installable. Cache signature %s.', Beaver_PWA_Settings::cache_version() ) );

			return;
		}

		WP_CLI::warning( 'Not installable yet. Resolve the FAIL rows above.' );
	}

	/**
	 * Invalidates the copy of the site held in every visitor's browser.
	 *
	 * ## EXAMPLES
	 *
	 *     wp beaver-pwa flush
	 *
	 * @since 1.0.0
	 */
	public function flush() {
		$version = Beaver_PWA_Settings::bump_cache();

		WP_CLI::success( sprintf( 'Caches invalidated. New signature: %s.', $version ) );
	}

	/**
	 * Rebuilds the app icon set from the source image.
	 *
	 * ## EXAMPLES
	 *
	 *     wp beaver-pwa icons
	 *
	 * @since 1.0.0
	 */
	public function icons() {
		Beaver_PWA_Icons::flush();

		$set = Beaver_PWA_Icons::maybe_generate( true );

		if ( ! empty( $set['error'] ) ) {
			WP_CLI::error( $set['error'] );
		}

		foreach ( (array) $set['files'] as $key => $url ) {
			WP_CLI::log( sprintf( '%-9s %s', $key, $url ) );
		}

		Beaver_PWA_Settings::bump_cache();

		WP_CLI::success( 'App icons rebuilt.' );
	}

	/**
	 * Prints the manifest exactly as browsers receive it.
	 *
	 * ## EXAMPLES
	 *
	 *     wp beaver-pwa manifest
	 *
	 * @since 1.0.0
	 */
	public function manifest() {
		WP_CLI::log( wp_json_encode( Beaver_PWA_Manifest::data(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}
}
