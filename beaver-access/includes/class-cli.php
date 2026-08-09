<?php
/**
 * WP-CLI commands.
 *
 * @package BeaverAccess
 */

defined( 'ABSPATH' ) || exit;

/**
 * Issues and revokes links from the command line.
 *
 * @since 1.0.0
 */
class Beaver_Access_CLI {

	/**
	 * Creates a link and prints it.
	 *
	 * ## OPTIONS
	 *
	 * [--role=<role>]
	 * : Role to grant. Default: administrator.
	 *
	 * [--label=<label>]
	 * : What the link is for.
	 *
	 * [--minutes=<minutes>]
	 * : Minutes until it expires, from 5 to 43200 (30 days). Default 1440.
	 *
	 * [--uses=<uses>]
	 * : How many times it may be used. Default 1.
	 *
	 * [--lock-ip]
	 * : Bind it to the first address that uses it.
	 *
	 * ## EXAMPLES
	 *
	 *     wp beaver-access create --label="Support" --minutes=60
	 *
	 * @since 1.0.0
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function create( $args = array(), $assoc_args = array() ) {
		unset( $args );

		$link = Beaver_Access_Links::create(
			array(
				'label'    => isset( $assoc_args['label'] ) ? (string) $assoc_args['label'] : 'WP-CLI',
				'role'     => isset( $assoc_args['role'] ) ? (string) $assoc_args['role'] : 'administrator',
				'minutes'  => isset( $assoc_args['minutes'] ) ? (int) $assoc_args['minutes'] : 1440,
				'max_uses' => isset( $assoc_args['uses'] ) ? (int) $assoc_args['uses'] : 1,
				'lock_ip'  => isset( $assoc_args['lock-ip'] ),
			)
		);

		if ( is_wp_error( $link ) ) {
			WP_CLI::error( $link->get_error_message() );
		}

		WP_CLI::success( 'Link created. It is shown once:' );
		WP_CLI::line( $link->url );
	}

	/**
	 * Lists links.
	 *
	 * ## EXAMPLES
	 *
	 *     wp beaver-access list
	 *
	 * @since 1.0.0
	 */
	public function list() {
		$rows = array();

		foreach ( Beaver_Access_Links::all( 100 ) as $link ) {
			$rows[] = array(
				'id'      => $link->id,
				'label'   => $link->label,
				'grants'  => (int) $link->target_user > 0 ? 'user #' . $link->target_user : $link->role,
				'uses'    => $link->used . '/' . $link->max_uses,
				'status'  => ! empty( $link->revoked_at )
					? 'revoked'
					: ( strtotime( $link->expires_at . ' UTC' ) < time() ? 'expired' : 'live' ),
				'expires' => $link->expires_at,
			);
		}

		if ( empty( $rows ) ) {
			WP_CLI::success( 'No links.' );

			return;
		}

		WP_CLI\Utils\format_items( 'table', $rows, array( 'id', 'label', 'grants', 'uses', 'status', 'expires' ) );
	}

	/**
	 * Revokes a link, or every live one.
	 *
	 * ## OPTIONS
	 *
	 * [<id>]
	 * : The link to revoke. Omit with --all.
	 *
	 * [--all]
	 * : Revoke every live link.
	 *
	 * ## EXAMPLES
	 *
	 *     wp beaver-access revoke 3
	 *     wp beaver-access revoke --all
	 *
	 * @since 1.0.0
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function revoke( $args = array(), $assoc_args = array() ) {
		if ( isset( $assoc_args['all'] ) ) {
			WP_CLI::success( sprintf( '%d link(s) revoked.', Beaver_Access_Links::revoke_all() ) );

			return;
		}

		if ( empty( $args[0] ) ) {
			WP_CLI::error( 'Give a link id, or --all.' );
		}

		Beaver_Access_Links::revoke( (int) $args[0] );
		WP_CLI::success( 'Revoked.' );
	}
}
