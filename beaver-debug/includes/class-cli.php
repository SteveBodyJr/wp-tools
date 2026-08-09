<?php
/**
 * WP-CLI commands.
 *
 * @package BeaverDebug
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads the log without opening a browser.
 *
 * @since 1.0.0
 */
class Beaver_Debug_CLI {

	/**
	 * Shows recent problems.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<number>]
	 * : How many to show. Default 20.
	 *
	 * [--severity=<level>]
	 * : Only show one kind: fatal, warning, notice, http, db, js, slow.
	 *
	 * ## EXAMPLES
	 *
	 *     wp beaver-debug log
	 *     wp beaver-debug log --severity=fatal
	 *
	 * @since 1.0.0
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function log( $args = array(), $assoc_args = array() ) {
		unset( $args );

		$limit    = isset( $assoc_args['limit'] ) ? max( 1, (int) $assoc_args['limit'] ) : 20;
		$severity = isset( $assoc_args['severity'] ) ? sanitize_key( $assoc_args['severity'] ) : '';
		$groups   = Beaver_Debug_Logger::read( 200 );
		$rows     = array();

		foreach ( $groups as $group ) {
			if ( '' !== $severity && $group['severity'] !== $severity ) {
				continue;
			}

			$rows[] = array(
				'severity' => $group['severity'],
				'count'    => $group['count'],
				'source'   => $group['source'],
				'message'  => mb_substr( $group['message'], 0, 90 ),
				'last'     => gmdate( 'Y-m-d H:i', $group['last'] ),
			);

			if ( count( $rows ) >= $limit ) {
				break;
			}
		}

		if ( empty( $rows ) ) {
			WP_CLI::success( 'Nothing recorded.' );

			return;
		}

		WP_CLI\Utils\format_items( 'table', $rows, array( 'severity', 'count', 'source', 'message', 'last' ) );
	}

	/**
	 * Prints the shareable report.
	 *
	 * ## EXAMPLES
	 *
	 *     wp beaver-debug report
	 *     wp beaver-debug report > site-report.txt
	 *
	 * @since 1.0.0
	 */
	public function report() {
		WP_CLI::line( Beaver_Debug_Health::report( 30 ) );
	}

	/**
	 * Deletes everything recorded so far.
	 *
	 * @since 1.0.0
	 */
	public function clear() {
		Beaver_Debug_Logger::clear();
		WP_CLI::success( 'Log cleared.' );
	}
}
