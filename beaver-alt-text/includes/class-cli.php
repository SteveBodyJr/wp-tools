<?php
/**
 * WP-CLI commands.
 *
 * @package BeaverAltText
 */

defined( 'ABSPATH' ) || exit;

/**
 * Drives alt text generation from the command line.
 *
 * The browser loop exists because shared hosting kills long requests. Where a
 * host offers WP-CLI, this is the better route: no request limit, no tab to
 * keep open, and a whole library in one pass.
 *
 * @since 1.0.0
 */
class Beaver_Alt_CLI {

	/**
	 * Writes alt text for images that have none.
	 *
	 * ## OPTIONS
	 *
	 * [--force]
	 * : Re-describe images this plugin has already handled. Alt text written by
	 * a person is still never touched.
	 *
	 * [--limit=<number>]
	 * : Stop after this many images.
	 *
	 * [--apply]
	 * : Publish each suggestion immediately instead of queuing it for review.
	 *
	 * [--dry-run]
	 * : Report what would be described without calling the model.
	 *
	 * ## EXAMPLES
	 *
	 *     wp beaver-alt generate
	 *     wp beaver-alt generate --limit=25 --apply
	 *     wp beaver-alt generate --dry-run
	 *
	 * @since 1.0.0
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function generate( $args = array(), $assoc_args = array() ) {
		unset( $args );

		$force  = ! empty( $assoc_args['force'] );
		$apply  = ! empty( $assoc_args['apply'] );
		$dry    = ! empty( $assoc_args['dry-run'] );
		$limit  = isset( $assoc_args['limit'] ) ? max( 0, (int) $assoc_args['limit'] ) : 0;

		if ( ! $dry && ! Beaver_Alt_Provider::is_configured() ) {
			WP_CLI::error( 'No API key is configured. Set BEAVER_ALT_API_KEY in wp-config.php or add one under Settings.' );
		}

		$ids = Beaver_Alt_Queue::pending_ids( $force, $limit );

		if ( empty( $ids ) ) {
			WP_CLI::success( 'Every image already has alt text.' );

			return;
		}

		if ( $dry ) {
			foreach ( $ids as $id ) {
				WP_CLI::log( sprintf( '#%d %s', $id, get_the_title( $id ) ) );
			}

			WP_CLI::success( sprintf( '%d image(s) would be described.', count( $ids ) ) );

			return;
		}

		$tally    = array( 'proposed' => 0, 'applied' => 0, 'skipped' => 0, 'failed' => 0 );
		$progress = WP_CLI\Utils\make_progress_bar( 'Writing alt text', count( $ids ) );

		foreach ( $ids as $id ) {
			$result = Beaver_Alt_Generator::generate( $id, $force, 60 );
			$status = $result['status'];

			if ( isset( $tally[ $status ] ) ) {
				++$tally[ $status ];
			}

			if ( 'failed' === $status ) {
				WP_CLI::warning( sprintf( '#%d %s', $id, $result['message'] ) );
			}

			if ( $apply && 'proposed' === $status ) {
				$applied = Beaver_Alt_Generator::apply( $id );

				if ( ! is_wp_error( $applied ) ) {
					--$tally['proposed'];
					++$tally['applied'];
				}
			}

			$progress->tick();
		}

		$progress->finish();

		WP_CLI::success(
			sprintf(
				'%d published, %d waiting for review, %d skipped, %d failed.',
				$tally['applied'],
				$tally['proposed'],
				$tally['skipped'],
				$tally['failed']
			)
		);
	}

	/**
	 * Publishes every suggestion currently waiting for review.
	 *
	 * ## OPTIONS
	 *
	 * [--min-confidence=<level>]
	 * : Only publish suggestions at or above this level. One of: low, medium, high.
	 * Default: high.
	 *
	 * ## EXAMPLES
	 *
	 *     wp beaver-alt approve
	 *     wp beaver-alt approve --min-confidence=medium
	 *
	 * @since 1.0.0
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function approve( $args = array(), $assoc_args = array() ) {
		unset( $args );

		$rank      = array( 'low' => 1, 'medium' => 2, 'high' => 3 );
		$level     = isset( $assoc_args['min-confidence'] ) ? (string) $assoc_args['min-confidence'] : 'high';
		$threshold = $rank[ $level ] ?? 3;

		$ids     = Beaver_Alt_Admin::pending_review_ids( 1000 );
		$applied = 0;
		$held    = 0;

		foreach ( $ids as $id ) {
			$proposal = get_post_meta( $id, Beaver_Alt_Generator::META_PROPOSAL, true );

			if ( ! is_array( $proposal ) ) {
				continue;
			}

			$confidence = $rank[ (string) ( $proposal['confidence'] ?? 'low' ) ] ?? 1;

			if ( $confidence < $threshold ) {
				++$held;
				continue;
			}

			if ( ! is_wp_error( Beaver_Alt_Generator::apply( $id ) ) ) {
				++$applied;
			}
		}

		WP_CLI::success( sprintf( '%d published, %d left for review.', $applied, $held ) );
	}

	/**
	 * Reports what the plugin knows about the library.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format. One of: table, json, yaml. Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp beaver-alt status
	 *
	 * @since 1.0.0
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function status( $args = array(), $assoc_args = array() ) {
		unset( $args );

		$stats = Beaver_Alt_Generator::get_stats();

		$rows = array(
			array( 'metric' => 'Images in library', 'value' => Beaver_Alt_Queue::count_total() ),
			array( 'metric' => 'Missing alt text', 'value' => Beaver_Alt_Queue::count_pending() ),
			array( 'metric' => 'Waiting for review', 'value' => Beaver_Alt_Admin::pending_review_count() ),
			array( 'metric' => 'Published', 'value' => $stats['applied'] ),
			array( 'metric' => 'Discarded', 'value' => $stats['rejected'] ),
			array( 'metric' => 'Failed', 'value' => $stats['failed'] ),
			array( 'metric' => 'Tokens used', 'value' => $stats['input_tokens'] + $stats['output_tokens'] ),
			array( 'metric' => 'API key configured', 'value' => Beaver_Alt_Provider::is_configured() ? 'yes' : 'no' ),
		);

		WP_CLI\Utils\format_items(
			isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table',
			$rows,
			array( 'metric', 'value' )
		);
	}
}
