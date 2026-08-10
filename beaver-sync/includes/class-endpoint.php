<?php
/**
 * The read-only endpoint the live site publishes.
 *
 * @package BeaverSync
 */

defined( 'ABSPATH' ) || exit;

/**
 * One route, one verb, no writes.
 *
 * GET /wp-json/beaver-sync/v1/manifest answers with the list of media files
 * this site holds. That is the whole of the live site's involvement. There is
 * no route that accepts a file, so there is nothing here that can put code on
 * a production server, however the key is handled.
 *
 * The route exists only while the role is set to source, so a site that pulls
 * does not also quietly offer its own library to anyone who asks.
 *
 * @since 1.0.0
 */
class Beaver_Sync_Endpoint {

	const NAMESPACE_V1 = 'beaver-sync/v1';

	/** Registers hooks. */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/** Route definitions. */
	public static function routes() {
		if ( ! Beaver_Sync_Settings::is_source() ) {
			return;
		}

		register_rest_route(
			self::NAMESPACE_V1,
			'/manifest',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'manifest' ),
				'permission_callback' => array( __CLASS__, 'allowed' ),
			)
		);
	}

	/**
	 * Whether the caller presented the key.
	 *
	 * The key is not protecting the files: everything listed is already served
	 * publicly at a URL anyone can request. It protects the *index* — the
	 * complete inventory of what a site holds, including anything uploaded but
	 * never linked from a page. That is worth not handing out.
	 *
	 * hash_equals, not ===, so the comparison cannot be timed.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public static function allowed( WP_REST_Request $request ) {
		$expected = Beaver_Sync_Settings::key();

		if ( '' === $expected ) {
			return new WP_Error( 'beaver_sync_no_key', __( 'This site has no sync key set.', 'beaver-sync' ), array( 'status' => 503 ) );
		}

		$given = (string) $request->get_header( 'x_beaver_sync_key' );

		if ( '' === $given || ! hash_equals( $expected, $given ) ) {
			return new WP_Error( 'beaver_sync_denied', __( 'Not authorised.', 'beaver-sync' ), array( 'status' => 401 ) );
		}

		return true;
	}

	/**
	 * The list.
	 *
	 * @since 1.0.0
	 *
	 * @return WP_REST_Response
	 */
	public static function manifest() {
		$files = Beaver_Sync_Manifest::build();
		$bytes = 0;

		foreach ( $files as $meta ) {
			$bytes += $meta['s'];
		}

		return new WP_REST_Response(
			array(
				'schema'   => 1,
				'site'     => home_url( '/' ),
				'base_url' => Beaver_Sync_Manifest::base_url(),
				'count'    => count( $files ),
				'bytes'    => $bytes,
				'built'    => gmdate( 'c' ),
				'files'    => $files,
			),
			200
		);
	}
}
