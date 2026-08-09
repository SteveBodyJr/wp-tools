<?php
/**
 * WP-CLI commands.
 *
 * @package BeaverImageOptimizer
 */

defined( 'ABSPATH' ) || exit;

/**
 * Drives conversion, reporting and delivery rules from the command line.
 *
 * The admin bulk optimizer paces itself against `max_execution_time` because it
 * runs inside a web request. Nothing here does: WP-CLI has no request to time
 * out, so the per-attachment time budget is lifted and a whole library is
 * converted in a single pass.
 *
 * @since 1.1.0
 */
class Beaver_Image_CLI {

	/**
	 * Converts media library images to WebP.
	 *
	 * ## OPTIONS
	 *
	 * [--force]
	 * : Reconvert images that already have a current WebP sidecar.
	 *
	 * [--limit=<number>]
	 * : Stop after this many attachments. Default: no limit.
	 *
	 * [--dry-run]
	 * : Report what would be converted without writing any files.
	 *
	 * ## EXAMPLES
	 *
	 *     wp beaver-io optimize
	 *     wp beaver-io optimize --limit=25 --dry-run
	 *     wp beaver-io optimize --force
	 *
	 * @since 1.1.0
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function optimize( $args, $assoc_args ) {
		unset( $args );

		$force   = ! empty( $assoc_args['force'] );
		$dry_run = ! empty( $assoc_args['dry-run'] );
		$limit   = isset( $assoc_args['limit'] ) ? max( 1, (int) $assoc_args['limit'] ) : -1;

		if ( ! Beaver_Image_Converter::is_supported() ) {
			WP_CLI::error( 'This PHP build has neither GD nor Imagick with WebP support, so nothing can be converted.' );
		}

		WP_CLI::log( sprintf( 'Encoder: %s', 'imagick' === Beaver_Image_Converter::engine() ? 'Imagick' : 'GD' ) );

		$ids = Beaver_Image_Optimizer::find_attachments( $force, $limit );

		if ( empty( $ids ) ) {
			WP_CLI::success( 'Nothing to do: every convertible image has already been processed.' );

			return;
		}

		if ( $dry_run ) {
			WP_CLI::success( sprintf( '%d image(s) would be converted. No files were written.', count( $ids ) ) );

			return;
		}

		// A command line run is not bounded by max_execution_time, so the guard
		// that protects web requests would only fragment the work here.
		add_filter( 'beaver_io_time_budget', array( __CLASS__, 'unlimited_time_budget' ), PHP_INT_MAX );

		$progress = WP_CLI\Utils\make_progress_bar( 'Converting', count( $ids ) );
		$tally    = array(
			'optimized' => 0,
			'partial'   => 0,
			'skipped'   => 0,
			'failed'    => 0,
		);
		$saved    = 0;

		foreach ( $ids as $id ) {
			$result = Beaver_Image_Optimizer::optimize_attachment( $id, $force );
			$status = $result['status'];

			if ( isset( $tally[ $status ] ) ) {
				++$tally[ $status ];
			}

			$saved += max( 0, (int) $result['original_bytes'] - (int) $result['webp_bytes'] );

			if ( 'failed' === $status ) {
				WP_CLI::warning( sprintf( '#%d %s', $id, $result['message'] ) );
			}

			$progress->tick();
		}

		$progress->finish();

		remove_filter( 'beaver_io_time_budget', array( __CLASS__, 'unlimited_time_budget' ), PHP_INT_MAX );

		WP_CLI::success(
			sprintf(
				'%d optimized, %d partial, %d skipped, %d failed. %s saved.',
				$tally['optimized'],
				$tally['partial'],
				$tally['skipped'],
				$tally['failed'],
				size_format( $saved, 2 )
			)
		);
	}

	/**
	 * Prints conversion statistics and server health.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Render format.
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
	 *     wp beaver-io status
	 *     wp beaver-io status --format=json
	 *
	 * @since 1.1.0
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function status( $args = array(), $assoc_args = array() ) {
		unset( $args );
		$stats  = Beaver_Image_Optimizer::get_stats();
		$engine = Beaver_Image_Converter::engine();

		$rows = array(
			array(
				'field' => 'WebP encoder',
				'value' => '' !== $engine ? ( 'imagick' === $engine ? 'Imagick' : 'GD' ) : 'UNAVAILABLE',
			),
			array(
				'field' => 'Delivery rules',
				'value' => Beaver_Image_Optimizer::delivery_rules_installed() ? 'installed' : 'not installed',
			),
			array(
				'field' => 'Convertible images',
				'value' => (string) Beaver_Image_Optimizer::count_total(),
			),
			array(
				'field' => 'Awaiting optimization',
				'value' => (string) Beaver_Image_Optimizer::count_pending(),
			),
			array(
				'field' => 'Images optimized',
				'value' => (string) $stats['images'],
			),
			array(
				'field' => 'Files converted',
				'value' => (string) $stats['files'],
			),
			array(
				'field' => 'Bandwidth saved',
				'value' => size_format( $stats['saved_bytes'], 2 ) . ' (' . $stats['saved_percent'] . '%)',
			),
			array(
				'field' => 'Disk space freed',
				'value' => size_format( $stats['disk_freed'], 2 ),
			),
			array(
				'field' => 'Skipped / failed',
				'value' => $stats['skipped'] . ' / ' . $stats['failed'],
			),
		);

		$format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';

		WP_CLI\Utils\format_items( $format, $rows, array( 'field', 'value' ) );
	}

	/**
	 * Installs or removes the uploads delivery rules.
	 *
	 * ## OPTIONS
	 *
	 * [--remove]
	 * : Strip the rules instead of writing them.
	 *
	 * ## EXAMPLES
	 *
	 *     wp beaver-io rules
	 *     wp beaver-io rules --remove
	 *
	 * @since 1.1.0
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function rules( $args, $assoc_args ) {
		unset( $args );

		if ( ! empty( $assoc_args['remove'] ) ) {
			if ( Beaver_Image_Optimizer::remove_delivery_rules() ) {
				WP_CLI::success( 'Delivery rules removed.' );
			} else {
				WP_CLI::error( 'Could not rewrite ' . Beaver_Image_Optimizer::htaccess_path() );
			}

			return;
		}

		if ( Beaver_Image_Optimizer::write_delivery_rules() ) {
			WP_CLI::success( 'Delivery rules installed in ' . Beaver_Image_Optimizer::htaccess_path() );
		} else {
			WP_CLI::error( 'Could not write ' . Beaver_Image_Optimizer::htaccess_path() . '. Check the folder permissions.' );
		}
	}

	/**
	 * Removes the per-request conversion time budget.
	 *
	 * @since 1.1.0
	 *
	 * @return float Seconds.
	 */
	public static function unlimited_time_budget() {
		return (float) PHP_INT_MAX;
	}
}
