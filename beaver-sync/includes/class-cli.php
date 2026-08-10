<?php
/**
 * WP-CLI commands.
 *
 * @package BeaverSync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Pull media from the live site.
 *
 * A library of three thousand images is a long job, and a long job belongs on
 * the command line where nothing times out and the progress is honest. The
 * browser can do it too, in batches, but this is the one to reach for when the
 * whole library is coming down for the first time.
 *
 * @since 1.0.0
 */
class Beaver_Sync_CLI {

	/**
	 * Show what would be copied, and copy it.
	 *
	 * ## OPTIONS
	 *
	 * [--live]
	 * : Actually download. Without it this is a dry run, which is the default
	 * on purpose.
	 *
	 * [--batch=<n>]
	 * : Files per pass. Default 8.
	 *
	 * ## EXAMPLES
	 *
	 *     wp beaver-sync pull
	 *     wp beaver-sync pull --live
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Flags.
	 */
	public function pull( $args, $assoc_args ) {
		if ( ! Beaver_Sync_Settings::is_copy() ) {
			WP_CLI::error( 'This site is not set up as the local copy. Set the role on Tools -> Beaver Sync first.' );
		}

		$plan = Beaver_Sync_Puller::plan();

		if ( is_wp_error( $plan ) ) {
			WP_CLI::error( $plan->get_error_message() );
		}

		$todo = count( $plan['missing'] ) + count( $plan['changed'] );

		WP_CLI::log( sprintf( 'The live site holds %d files.', (int) $plan['there'] ) );
		WP_CLI::log( sprintf( '  %d missing here', count( $plan['missing'] ) ) );
		WP_CLI::log( sprintf( '  %d a different size', count( $plan['changed'] ) ) );
		WP_CLI::log( sprintf( '  %d here but not there, left alone', count( $plan['extra'] ) ) );
		WP_CLI::log( sprintf( '  %s to download', size_format( (int) $plan['bytes'], 1 ) ) );

		if ( 0 === $todo ) {
			WP_CLI::success( 'Nothing to copy.' );

			return;
		}

		if ( empty( $assoc_args['live'] ) ) {
			WP_CLI::log( '' );
			WP_CLI::success( 'Dry run. Add --live to copy these ' . $todo . ' files.' );

			return;
		}

		Beaver_Sync_Puller::queue( $plan );

		$size     = isset( $assoc_args['batch'] ) ? max( 1, (int) $assoc_args['batch'] ) : 8;
		$progress = \WP_CLI\Utils\make_progress_bar( 'Copying', $todo );
		$failed   = array();

		do {
			$result = Beaver_Sync_Puller::run_batch( $size );

			if ( is_wp_error( $result ) ) {
				WP_CLI::error( $result->get_error_message() );
			}

			$progress->tick( $size );

			$failed = $result['failed'];
		} while ( empty( $result['finished'] ) );

		$progress->finish();

		foreach ( $failed as $path => $why ) {
			WP_CLI::warning( $path . ': ' . $why );
		}

		WP_CLI::success(
			sprintf(
				'%d copied, %s written, %d failed.',
				(int) $result['done'],
				size_format( (int) $result['bytes'], 1 ),
				count( $failed )
			)
		);
	}

	/**
	 * Print this site's own sync key, for a site acting as the source.
	 *
	 * ## EXAMPLES
	 *
	 *     wp beaver-sync key
	 */
	public function key() {
		if ( ! Beaver_Sync_Settings::is_source() ) {
			WP_CLI::error( 'This site is not set up as the live source.' );
		}

		WP_CLI::line( Beaver_Sync_Settings::ensure_key() );
	}
}
