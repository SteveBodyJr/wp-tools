<?php
/**
 * WP-CLI commands.
 *
 * @package BeaverSEO
 */

defined( 'ABSPATH' ) || exit;

/**
 * Manages the sitemap cache and the redirect table from the command line.
 *
 * @since 1.0.0
 */
class Beaver_SEO_CLI {

	/**
	 * Clears the cached sitemap files.
	 *
	 * ## EXAMPLES
	 *
	 *     wp beaver-seo sitemap flush
	 *
	 * @since 1.0.0
	 */
	public function sitemap( $args, $assoc_args ) {
		unset( $assoc_args );

		$sub = isset( $args[0] ) ? $args[0] : 'flush';

		if ( 'flush' !== $sub ) {
			WP_CLI::error( "Unknown subcommand '{$sub}'. Try: wp beaver-seo sitemap flush" );
		}

		Beaver_SEO_Sitemap::flush_cache();
		WP_CLI::success( 'Sitemap cache cleared.' );
	}

	/**
	 * Lists, adds or removes redirects.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : One of 'list', 'add' or 'delete'.
	 *
	 * [<from>]
	 * : Source path. Required for 'add'.
	 *
	 * [<to>]
	 * : Destination URL. Required for 'add'.
	 *
	 * [--status=<code>]
	 * : HTTP status for 'add'. Default 301.
	 *
	 * [--id=<id>]
	 * : Redirect ID. Required for 'delete'.
	 *
	 * ## EXAMPLES
	 *
	 *     wp beaver-seo redirects list
	 *     wp beaver-seo redirects add /old-page /new-page
	 *     wp beaver-seo redirects add /old-page https://example.com/moved --status=302
	 *     wp beaver-seo redirects delete --id=4
	 *
	 * @since 1.0.0
	 */
	public function redirects( $args, $assoc_args ) {
		$action = isset( $args[0] ) ? $args[0] : 'list';

		switch ( $action ) {
			case 'list':
				$rows = array_map(
					static function ( $row ) {
						return array(
							'id'     => $row->id,
							'from'   => $row->source_path,
							'to'     => $row->target_url,
							'status' => $row->status_code,
							'hits'   => $row->hits,
						);
					},
					Beaver_SEO_Redirects::all()
				);

				WP_CLI\Utils\format_items( isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table', $rows, array( 'id', 'from', 'to', 'status', 'hits' ) );
				break;

			case 'add':
				if ( empty( $args[1] ) || empty( $args[2] ) ) {
					WP_CLI::error( 'Usage: wp beaver-seo redirects add <from> <to> [--status=301]' );
				}

				$result = Beaver_SEO_Redirects::create( $args[1], $args[2], isset( $assoc_args['status'] ) ? (int) $assoc_args['status'] : 301 );

				if ( is_wp_error( $result ) ) {
					WP_CLI::error( $result->get_error_message() );
				}

				WP_CLI::success( "Redirect #{$result} added." );
				break;

			case 'delete':
				if ( empty( $assoc_args['id'] ) ) {
					WP_CLI::error( 'Usage: wp beaver-seo redirects delete --id=<id>' );
				}

				if ( Beaver_SEO_Redirects::delete( (int) $assoc_args['id'] ) ) {
					WP_CLI::success( 'Redirect removed.' );
				} else {
					WP_CLI::error( 'No redirect with that ID.' );
				}
				break;

			default:
				WP_CLI::error( "Unknown action '{$action}'. Try: list, add, delete" );
		}
	}
}
