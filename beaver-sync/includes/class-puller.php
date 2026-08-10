<?php
/**
 * The copy end: read the list, work out the difference, fetch it.
 *
 * @package BeaverSync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Downloads what the live site has and this one does not.
 *
 * Files come down over ordinary HTTPS from the URLs the source already serves
 * to the public, so nothing has to be tunnelled and nothing has to be granted.
 * The endpoint is consulted for the list; the files themselves are just fetched.
 *
 * @since 1.0.0
 */
class Beaver_Sync_Puller {

	/** Where a plan waits between batches. */
	const QUEUE = 'beaver_sync_queue';

	/** How long a plan is worth resuming. */
	const QUEUE_TTL = 6 * HOUR_IN_SECONDS;

	/**
	 * Ask the source what it has.
	 *
	 * @since 1.0.0
	 *
	 * @return array|WP_Error The decoded manifest, or an error to show as is.
	 */
	public static function fetch_manifest() {
		$url = (string) Beaver_Sync_Settings::get( 'source_url' );
		$key = (string) Beaver_Sync_Settings::get( 'source_key' );

		if ( '' === $url || '' === $key ) {
			return new WP_Error( 'beaver_sync_unset', __( 'Set the live site address and its sync key first.', 'beaver-sync' ) );
		}

		$response = wp_remote_get(
			$url . '/wp-json/' . Beaver_Sync_Endpoint::NAMESPACE_V1 . '/manifest',
			array(
				'timeout' => 60,
				'headers' => array( 'X-Beaver-Sync-Key' => $key ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 401 === $code ) {
			return new WP_Error( 'beaver_sync_denied', __( 'The live site refused the key. Check it was copied whole.', 'beaver-sync' ) );
		}

		if ( 404 === $code ) {
			return new WP_Error(
				'beaver_sync_missing',
				__( 'No sync endpoint there. Check Beaver Sync is active on the live site and its role is set to "the live site".', 'beaver-sync' )
			);
		}

		if ( 200 !== $code || ! is_array( $body ) || ! isset( $body['files'] ) || ! is_array( $body['files'] ) ) {
			/* translators: %d: HTTP status code. */
			return new WP_Error( 'beaver_sync_bad', sprintf( __( 'The live site answered %d with something that is not a file list.', 'beaver-sync' ), $code ) );
		}

		return $body;
	}

	/**
	 * Work out what would change, without changing anything.
	 *
	 * @since 1.0.0
	 *
	 * @return array|WP_Error The plan.
	 */
	public static function plan() {
		$manifest = self::fetch_manifest();

		if ( is_wp_error( $manifest ) ) {
			return $manifest;
		}

		$there = array();

		// Filter the source's list through our own idea of a safe path, so a
		// bad entry is dropped here rather than at the moment of writing.
		foreach ( $manifest['files'] as $path => $meta ) {
			if ( Beaver_Sync_Manifest::path_is_safe( $path ) && is_array( $meta ) ) {
				$there[ $path ] = array( 's' => (int) ( $meta['s'] ?? 0 ) );
			}
		}

		$diff = Beaver_Sync_Manifest::compare( $there, Beaver_Sync_Manifest::build() );

		$diff['base_url'] = isset( $manifest['base_url'] ) ? esc_url_raw( (string) $manifest['base_url'] ) : '';
		$diff['site']     = isset( $manifest['site'] ) ? esc_url_raw( (string) $manifest['site'] ) : '';
		$diff['there']    = count( $there );
		$diff['skipped']  = count( $manifest['files'] ) - count( $there );

		return $diff;
	}

	/**
	 * Store a plan so the batches have something to work through.
	 *
	 * @since 1.0.0
	 *
	 * @param array $plan Plan from plan().
	 * @return int Number of files queued.
	 */
	public static function queue( array $plan ) {
		$todo = array_merge( array_keys( $plan['missing'] ), array_keys( $plan['changed'] ) );

		set_transient(
			self::QUEUE,
			array(
				'base_url' => $plan['base_url'],
				'todo'     => array_values( $todo ),
				'done'     => 0,
				'failed'   => array(),
				'bytes'    => 0,
				'total'    => count( $todo ),
			),
			self::QUEUE_TTL
		);

		return count( $todo );
	}

	/** The plan currently in progress, or false. */
	public static function queued() {
		$q = get_transient( self::QUEUE );

		return is_array( $q ) ? $q : false;
	}

	/** Throw the current plan away. */
	public static function clear() {
		delete_transient( self::QUEUE );
	}

	/**
	 * Fetch the next few files.
	 *
	 * Small batches on purpose. Shared hosting kills a long request, and a
	 * library of three thousand images is not going to arrive inside one, so
	 * the work is done a handful at a time and the caller comes back for more.
	 *
	 * @since 1.0.0
	 *
	 * @param int $size How many files to take this time.
	 * @return array|WP_Error Progress, or an error if there is nothing to do.
	 */
	public static function run_batch( $size = 8 ) {
		$q = self::queued();

		if ( ! $q ) {
			return new WP_Error( 'beaver_sync_noqueue', __( 'There is nothing queued. Check for changes first.', 'beaver-sync' ) );
		}

		$batch = array_splice( $q['todo'], 0, max( 1, (int) $size ) );

		foreach ( $batch as $path ) {
			$result = self::fetch_one( $q['base_url'], $path );

			if ( is_wp_error( $result ) ) {
				$q['failed'][ $path ] = $result->get_error_message();
				continue;
			}

			$q['done']  = (int) $q['done'] + 1;
			$q['bytes'] = (int) $q['bytes'] + (int) $result;
		}

		$q['remaining'] = count( $q['todo'] );

		if ( empty( $q['todo'] ) ) {
			Beaver_Sync_Settings::update(
				array(
					'last_run'    => current_time( 'mysql' ),
					/* translators: 1: files copied, 2: files that failed. */
					'last_result' => sprintf( __( '%1$d copied, %2$d failed', 'beaver-sync' ), (int) $q['done'], count( $q['failed'] ) ),
				)
			);

			self::clear();

			$q['finished'] = true;

			return $q;
		}

		set_transient( self::QUEUE, $q, self::QUEUE_TTL );

		$q['finished'] = false;

		return $q;
	}

	/**
	 * Fetch one file into the uploads folder.
	 *
	 * Written to a temporary name and moved into place only once it has fully
	 * arrived, so a request that dies half way leaves no truncated image behind
	 * pretending to be the real one.
	 *
	 * @since 1.0.0
	 *
	 * @param string $base_url The source's uploads URL.
	 * @param string $path     Relative path.
	 * @return int|WP_Error Bytes written, or an error.
	 */
	private static function fetch_one( $base_url, $path ) {
		// Checked again here, at the point of use. The plan filtered the list
		// already, but this is the line that actually touches the disk and it
		// should not be trusting a decision made somewhere else.
		if ( ! Beaver_Sync_Manifest::path_is_safe( $path ) ) {
			return new WP_Error( 'beaver_sync_path', __( 'Refused: not a media path.', 'beaver-sync' ) );
		}

		$target = Beaver_Sync_Manifest::base_dir() . '/' . $path;
		$dir    = dirname( $target );

		if ( ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'beaver_sync_mkdir', __( 'Could not create the folder.', 'beaver-sync' ) );
		}

		$url = $base_url . '/' . implode( '/', array_map( 'rawurlencode', explode( '/', $path ) ) );

		// download_url() lives in an admin include that is not loaded during
		// AJAX or WP-CLI, which is exactly when this runs.
		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$temp = download_url( $url, 120 );

		if ( is_wp_error( $temp ) ) {
			return $temp;
		}

		$bytes = (int) filesize( $temp );

		// rename() rather than copy(): it is atomic within a filesystem, so the
		// file at the target path is either the old one or the whole new one.
		if ( ! @rename( $temp, $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- handled below.
			$moved = @copy( $temp, $target ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- handled below.
			@unlink( $temp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best effort cleanup.

			if ( ! $moved ) {
				return new WP_Error( 'beaver_sync_write', __( 'Could not write the file.', 'beaver-sync' ) );
			}
		}

		return $bytes;
	}
}
