<?php
/**
 * The manifest: fetching it, caching it, and answering questions about it.
 *
 * @package BeaverUpdates
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads the published manifest and keeps a local copy.
 *
 * WordPress calls the update filters once per plugin, so a naive fetch would
 * make ten HTTP requests every time a site checked. Everything here is answered
 * from a single cached document instead: one request per site per twelve hours,
 * however many plugins are installed.
 *
 * @since 1.0.0
 */
final class Beaver_Updates_Channel {

	/**
	 * Where the manifest is published.
	 *
	 * Served by GitHub's CDN as a static file, so no server of ours is involved
	 * in an update check and there is nothing to authenticate against.
	 */
	const MANIFEST_URL = 'https://raw.githubusercontent.com/SteveBodyJr/wp-tools/main/plugins.json';

	/**
	 * Only packages under this prefix are ever offered to a site.
	 *
	 * A manifest that has been tampered with cannot point an install at
	 * somebody else's archive.
	 */
	const PACKAGE_PREFIX = 'https://github.com/SteveBodyJr/wp-tools/releases/download/';

	const TRANSIENT = 'beaver_updates_manifest';

	/**
	 * How long a good answer is kept.
	 *
	 * Matches the twelve hours WordPress itself waits between plugin checks, so
	 * a fetch happens roughly once per check rather than once per page.
	 */
	const TTL_OK = 12 * HOUR_IN_SECONDS;

	/**
	 * How long a failure is remembered.
	 *
	 * Caching the failure is the whole point. Without it, an unreachable
	 * manifest means every admin page load on every site retries, and a fleet
	 * hammers an endpoint that is already unhappy.
	 */
	const TTL_FAIL = HOUR_IN_SECONDS;

	/**
	 * Runtime copy, so several filter calls in one request share one read.
	 *
	 * @var array|null
	 */
	private static $runtime = null;

	/**
	 * Returns the cached manifest, fetching it when the cache is cold.
	 *
	 * @since 1.0.0
	 *
	 * @return array {
	 *     @type array  $plugins Entries keyed by slug.
	 *     @type int    $fetched Timestamp of the attempt.
	 *     @type int    $code    HTTP status, 0 when the request never completed.
	 *     @type string $error   Human readable failure, empty on success.
	 * }
	 */
	public static function get() {
		if ( null !== self::$runtime ) {
			return self::$runtime;
		}

		$cached = get_site_transient( self::TRANSIENT );

		if ( is_array( $cached ) ) {
			self::$runtime = $cached;

			return $cached;
		}

		// Never spend a visitor's page load on this. Update data is only ever
		// read in the admin or by cron, so the front end has nothing to gain.
		if ( ! is_admin() && ! wp_doing_cron() && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return self::empty_result( __( 'Not fetched on front end requests.', 'beaver-updates' ) );
		}

		return self::refresh();
	}

	/**
	 * Fetches the manifest now, whatever the cache says.
	 *
	 * @since 1.0.0
	 *
	 * @return array Result, in the shape get() returns.
	 */
	public static function refresh() {
		/**
		 * Filters the manifest URL, for pointing a site at a staging channel.
		 *
		 * @since 1.0.0
		 *
		 * @param string $url Manifest URL.
		 */
		$url = (string) apply_filters( 'beaver_updates_manifest_url', self::MANIFEST_URL );

		$response = wp_remote_get(
			$url,
			array(
				// Short on purpose. The risk here is not the server being busy,
				// it is somebody's wp-admin hanging while it waits.
				'timeout'    => 5,
				'headers'    => array( 'Accept' => 'application/json' ),
				'user-agent' => 'BeaverUpdates/' . BEAVER_UPDATES_VERSION . '; ' . home_url( '/' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return self::store( self::empty_result( $response->get_error_message() ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 !== $code ) {
			$result         = self::empty_result(
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'The manifest answered %d.', 'beaver-updates' ),
					$code
				)
			);
			$result['code'] = $code;

			return self::store( $result );
		}

		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $decoded ) || empty( $decoded['plugins'] ) || ! is_array( $decoded['plugins'] ) ) {
			$result         = self::empty_result( __( 'The manifest was not readable JSON.', 'beaver-updates' ) );
			$result['code'] = $code;

			return self::store( $result );
		}

		$result = array(
			'plugins' => self::clean( $decoded['plugins'] ),
			'fetched' => time(),
			'code'    => $code,
			'error'   => '',
			'updated' => isset( $decoded['updated'] ) ? (string) $decoded['updated'] : '',
		);

		return self::store( $result );
	}

	/**
	 * Drops the cached manifest.
	 *
	 * @since 1.0.0
	 */
	public static function forget() {
		self::$runtime = null;

		delete_site_transient( self::TRANSIENT );
	}

	/**
	 * Returns one plugin's entry.
	 *
	 * @since 1.0.0
	 *
	 * @param string $slug Plugin directory name.
	 * @return array|null
	 */
	public static function plugin( $slug ) {
		$plugins = self::plugins();

		return isset( $plugins[ $slug ] ) ? $plugins[ $slug ] : null;
	}

	/**
	 * Returns every entry that is safe to act on.
	 *
	 * The package URL is checked here as well as at fetch time, deliberately.
	 * Validating only on the way in trusts whatever is in the cache, and this
	 * URL is about to be handed to the installer: it is checked immediately
	 * before use, however it got there.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public static function plugins() {
		$manifest = self::get();
		$plugins  = isset( $manifest['plugins'] ) && is_array( $manifest['plugins'] ) ? $manifest['plugins'] : array();

		foreach ( $plugins as $slug => $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['package'] ) || empty( $entry['version'] ) ) {
				unset( $plugins[ $slug ] );

				continue;
			}

			if ( ! self::is_allowed_package( $entry['package'] ) ) {
				unset( $plugins[ $slug ] );
			}
		}

		return $plugins;
	}

	/**
	 * Whether a package URL is published where this channel publishes.
	 *
	 * @since 1.0.0
	 *
	 * @param string $package Package URL.
	 * @return bool
	 */
	public static function is_allowed_package( $package ) {
		return 0 === strpos( (string) $package, self::PACKAGE_PREFIX );
	}

	/**
	 * Whether the last attempt produced a usable manifest.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public static function is_healthy() {
		$manifest = self::get();

		return '' === $manifest['error'] && ! empty( $manifest['plugins'] );
	}

	/*
	 * -----------------------------------------------------------------------
	 * Internals
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Discards anything in the manifest that is not a complete, safe entry.
	 *
	 * Validation happens once, here, so nothing downstream has to wonder
	 * whether a field is present or where a package points.
	 *
	 * @since 1.0.0
	 *
	 * @param array $raw Decoded plugins member.
	 * @return array
	 */
	private static function clean( array $raw ) {
		$clean = array();

		foreach ( $raw as $slug => $entry ) {
			$slug = sanitize_key( is_string( $slug ) ? $slug : '' );

			if ( '' === $slug || ! is_array( $entry ) ) {
				continue;
			}

			$version = isset( $entry['version'] ) ? trim( (string) $entry['version'] ) : '';
			$package = isset( $entry['package'] ) ? esc_url_raw( (string) $entry['package'] ) : '';

			if ( '' === $version || '' === $package ) {
				continue;
			}

			// The package must live where this channel publishes. Without this
			// check a tampered manifest could point an install anywhere. It is
			// checked again on the way out, in plugins().
			if ( ! self::is_allowed_package( $package ) ) {
				continue;
			}

			$clean[ $slug ] = array(
				'slug'         => $slug,
				'name'         => isset( $entry['name'] ) ? sanitize_text_field( (string) $entry['name'] ) : $slug,
				'version'      => $version,
				'package'      => $package,
				'homepage'     => isset( $entry['homepage'] ) ? esc_url_raw( (string) $entry['homepage'] ) : '',
				'requires'     => isset( $entry['requires'] ) ? trim( (string) $entry['requires'] ) : '',
				'requires_php' => isset( $entry['requires_php'] ) ? trim( (string) $entry['requires_php'] ) : '',
				'tested'       => isset( $entry['tested'] ) ? trim( (string) $entry['tested'] ) : '',
				'author'       => isset( $entry['author'] ) ? sanitize_text_field( (string) $entry['author'] ) : '',
			);
		}

		return $clean;
	}

	/**
	 * Caches a result and returns it.
	 *
	 * @since 1.0.0
	 *
	 * @param array $result Result.
	 * @return array
	 */
	private static function store( array $result ) {
		self::$runtime = $result;

		set_site_transient(
			self::TRANSIENT,
			$result,
			'' === $result['error'] ? self::TTL_OK : self::TTL_FAIL
		);

		return $result;
	}

	/**
	 * An empty result carrying a reason.
	 *
	 * @since 1.0.0
	 *
	 * @param string $error Why there is nothing.
	 * @return array
	 */
	private static function empty_result( $error ) {
		return array(
			'plugins' => array(),
			'fetched' => time(),
			'code'    => 0,
			'error'   => (string) $error,
			'updated' => '',
		);
	}
}
