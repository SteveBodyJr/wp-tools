<?php
/**
 * Installability checks.
 *
 * @package BeaverPWA
 */

defined( 'ABSPATH' ) || exit;

/**
 * Answers the only question that matters: will a browser offer to install this
 * site, and if not, what is missing.
 *
 * Every remote check requests the real URL over HTTP rather than inspecting
 * settings, because a rewrite rule that has not been flushed or a security
 * plugin blocking a path is exactly the sort of failure that settings cannot
 * reveal.
 *
 * @since 1.0.0
 */
final class Beaver_PWA_Health {

	const TRANSIENT = 'beaver_pwa_health';

	/**
	 * Runs every check.
	 *
	 * @since 1.0.0
	 *
	 * @param bool $force Skip the cached result.
	 * @return array List of checks keyed by id.
	 */
	public static function run( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::TRANSIENT );

			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$checks = array(
			self::check_enabled(),
			self::check_secure(),
			self::check_icons(),
			self::check_names(),
			self::check_manifest(),
			self::check_worker(),
			self::check_offline(),
			self::check_routing(),
		);

		$keyed = array();

		foreach ( $checks as $check ) {
			$keyed[ $check['id'] ] = $check;
		}

		set_transient( self::TRANSIENT, $keyed, 5 * MINUTE_IN_SECONDS );

		return $keyed;
	}

	/**
	 * Whether every blocking check passes.
	 *
	 * @since 1.0.0
	 *
	 * @param array $checks Result of run().
	 * @return bool
	 */
	public static function is_installable( array $checks ) {
		foreach ( $checks as $check ) {
			if ( 'fail' === $check['status'] ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Counts checks by status.
	 *
	 * @since 1.0.0
	 *
	 * @param array $checks Result of run().
	 * @return array
	 */
	public static function summary( array $checks ) {
		$counts = array(
			'pass' => 0,
			'warn' => 0,
			'fail' => 0,
		);

		foreach ( $checks as $check ) {
			if ( isset( $counts[ $check['status'] ] ) ) {
				$counts[ $check['status'] ]++;
			}
		}

		return $counts;
	}

	/*
	 * -----------------------------------------------------------------------
	 * Individual checks
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Master switch.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	private static function check_enabled() {
		if ( Beaver_PWA_Settings::is_enabled() ) {
			return self::result( 'enabled', __( 'App mode is switched on', 'beaver-pwa' ), 'pass', __( 'The manifest and service worker are being served.', 'beaver-pwa' ) );
		}

		return self::result(
			'enabled',
			__( 'App mode is switched off', 'beaver-pwa' ),
			'fail',
			__( 'Nothing is served while this is off, and workers already installed in a visitor\'s browser remove themselves.', 'beaver-pwa' )
		);
	}

	/**
	 * Service workers only run in a secure context.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	private static function check_secure() {
		$host = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );

		if ( is_ssl() || 0 === strpos( home_url( '/' ), 'https://' ) ) {
			return self::result( 'secure', __( 'The site is served over HTTPS', 'beaver-pwa' ), 'pass', '' );
		}

		if ( self::is_local_host( $host ) ) {
			return self::result(
				'secure',
				__( 'Running on a local host', 'beaver-pwa' ),
				'warn',
				__( 'Browsers treat localhost as secure, so everything works here. A live site must be served over HTTPS or nothing will install.', 'beaver-pwa' )
			);
		}

		return self::result(
			'secure',
			__( 'The site is not served over HTTPS', 'beaver-pwa' ),
			'fail',
			__( 'A service worker cannot be registered on an insecure origin. Install a certificate and move the site to HTTPS.', 'beaver-pwa' )
		);
	}

	/**
	 * A 192 and a 512 pixel icon are the minimum for an install prompt.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	private static function check_icons() {
		$icons = Beaver_PWA_Icons::manifest_icons();
		$sizes = wp_list_pluck( $icons, 'sizes' );

		if ( ! Beaver_PWA_Icons::source_id() ) {
			return self::result(
				'icons',
				__( 'No app icon', 'beaver-pwa' ),
				'fail',
				__( 'Choose a square image of at least 512 by 512 pixels. The site icon is used unless a different one is set below.', 'beaver-pwa' ),
				array(
					'url'   => admin_url( 'options-general.php' ),
					'label' => __( 'Set a site icon', 'beaver-pwa' ),
				)
			);
		}

		$missing = array_diff( array( '192x192', '512x512' ), (array) $sizes );

		if ( $missing ) {
			return self::result(
				'icons',
				__( 'App icons are incomplete', 'beaver-pwa' ),
				'fail',
				sprintf(
					/* translators: %s: comma separated list of icon sizes. */
					__( 'These sizes could not be produced: %s. Regenerate the icons, or check that the uploads folder is writable.', 'beaver-pwa' ),
					implode( ', ', $missing )
				)
			);
		}

		$set = get_option( Beaver_PWA_Icons::OPTION, array() );

		if ( ! empty( $set['error'] ) ) {
			return self::result( 'icons', __( 'App icons fell back to the site icon', 'beaver-pwa' ), 'warn', $set['error'] );
		}

		$message = Beaver_PWA_Settings::get( 'maskable' )
			? __( 'A 192, a 512 and a padded maskable icon are ready.', 'beaver-pwa' )
			: __( 'A 192 and a 512 pixel icon are ready.', 'beaver-pwa' );

		return self::result( 'icons', __( 'App icons are ready', 'beaver-pwa' ), 'pass', $message );
	}

	/**
	 * Name length affects how the icon label renders on a home screen.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	private static function check_names() {
		$short = Beaver_PWA_Settings::short_name();

		if ( mb_strlen( $short ) > 12 ) {
			return self::result(
				'names',
				__( 'The short name may be clipped', 'beaver-pwa' ),
				'warn',
				sprintf(
					/* translators: %s: the configured short name. */
					__( '"%s" is longer than 12 characters, so a home screen will cut it short.', 'beaver-pwa' ),
					$short
				)
			);
		}

		return self::result(
			'names',
			__( 'App name and short name look right', 'beaver-pwa' ),
			'pass',
			sprintf(
				/* translators: 1: application name, 2: short name. */
				__( 'Installed as "%1$s", labelled "%2$s" under the icon.', 'beaver-pwa' ),
				Beaver_PWA_Settings::app_name(),
				$short
			)
		);
	}

	/**
	 * Fetches the manifest and parses it.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	private static function check_manifest() {
		$url      = Beaver_PWA_Routes::url( 'manifest' );
		$response = self::fetch( $url );

		if ( is_wp_error( $response ) ) {
			return self::result( 'manifest', __( 'The manifest could not be checked', 'beaver-pwa' ), 'warn', $response->get_error_message() );
		}

		if ( 200 !== $response['code'] ) {
			return self::result(
				'manifest',
				__( 'The manifest is not reachable', 'beaver-pwa' ),
				'fail',
				sprintf(
					/* translators: 1: HTTP status code, 2: URL. */
					__( '%1$d was returned for %2$s. Re-save the permalinks, or switch the endpoint style to query strings.', 'beaver-pwa' ),
					$response['code'],
					$url
				),
				array(
					'url'   => admin_url( 'options-permalink.php' ),
					'label' => __( 'Open permalinks', 'beaver-pwa' ),
				)
			);
		}

		$decoded = json_decode( $response['body'], true );

		if ( ! is_array( $decoded ) || empty( $decoded['icons'] ) ) {
			return self::result( 'manifest', __( 'The manifest is not valid', 'beaver-pwa' ), 'fail', __( 'The response was not readable JSON. Another plugin may be adding output to every page.', 'beaver-pwa' ) );
		}

		return self::result(
			'manifest',
			__( 'The manifest is being served', 'beaver-pwa' ),
			'pass',
			$url
		);
	}

	/**
	 * Fetches the worker and confirms it is served as a script at the right scope.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	private static function check_worker() {
		$url      = Beaver_PWA_Routes::url( 'sw' );
		$response = self::fetch( $url );

		if ( is_wp_error( $response ) ) {
			return self::result( 'worker', __( 'The service worker could not be checked', 'beaver-pwa' ), 'warn', $response->get_error_message() );
		}

		if ( 200 !== $response['code'] ) {
			return self::result(
				'worker',
				__( 'The service worker is not reachable', 'beaver-pwa' ),
				'fail',
				sprintf(
					/* translators: 1: HTTP status code, 2: URL. */
					__( '%1$d was returned for %2$s. Re-save the permalinks, or switch the endpoint style to query strings.', 'beaver-pwa' ),
					$response['code'],
					$url
				),
				array(
					'url'   => admin_url( 'options-permalink.php' ),
					'label' => __( 'Open permalinks', 'beaver-pwa' ),
				)
			);
		}

		if ( false === strpos( (string) $response['type'], 'javascript' ) ) {
			return self::result(
				'worker',
				__( 'The service worker is served with the wrong type', 'beaver-pwa' ),
				'fail',
				sprintf(
					/* translators: %s: content type header. */
					__( 'Browsers refuse a worker that is not JavaScript. This one arrived as "%s", which usually means a caching layer rewrote it.', 'beaver-pwa' ),
					$response['type']
				)
			);
		}

		if ( false === strpos( $response['body'], 'BPWA' ) ) {
			return self::result( 'worker', __( 'The service worker response is unexpected', 'beaver-pwa' ), 'fail', __( 'Something else is answering this URL. Try the query string endpoint style instead.', 'beaver-pwa' ) );
		}

		$scope   = Beaver_PWA_Settings::scope_path();
		$allowed = isset( $response['headers']['service-worker-allowed'] ) ? self::flatten( $response['headers']['service-worker-allowed'] ) : '';

		if ( '' !== $allowed && 0 !== strpos( $scope, $allowed ) ) {
			return self::result( 'worker', __( 'The service worker scope is narrower than the site', 'beaver-pwa' ), 'warn', __( 'A proxy has rewritten the Service-Worker-Allowed header. Pages outside the scope will not work offline.', 'beaver-pwa' ) );
		}

		return self::result(
			'worker',
			__( 'The service worker is being served', 'beaver-pwa' ),
			'pass',
			sprintf(
				/* translators: 1: worker URL, 2: scope path. */
				__( '%1$s, controlling %2$s', 'beaver-pwa' ),
				$url,
				$scope
			)
		);
	}

	/**
	 * Confirms the offline fallback exists.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	private static function check_offline() {
		if ( ! Beaver_PWA_Settings::get( 'offline_enabled' ) ) {
			return self::result( 'offline', __( 'Offline fallback is switched off', 'beaver-pwa' ), 'warn', __( 'Visitors will see the browser\'s own error page when they lose connection.', 'beaver-pwa' ) );
		}

		$url      = Beaver_PWA_Service_Worker::offline_url();
		$response = self::fetch( $url );

		if ( is_wp_error( $response ) ) {
			return self::result( 'offline', __( 'The offline page could not be checked', 'beaver-pwa' ), 'warn', $response->get_error_message() );
		}

		if ( 200 !== $response['code'] ) {
			return self::result(
				'offline',
				__( 'The offline page is not reachable', 'beaver-pwa' ),
				'fail',
				sprintf(
					/* translators: 1: HTTP status code, 2: URL. */
					__( '%1$d was returned for %2$s.', 'beaver-pwa' ),
					$response['code'],
					$url
				)
			);
		}

		$custom = (int) Beaver_PWA_Settings::get( 'offline_page_id' );

		return self::result(
			'offline',
			__( 'The offline page is ready', 'beaver-pwa' ),
			'pass',
			$custom ? $url : __( 'Using the built-in offline page, which needs no theme assets to render.', 'beaver-pwa' )
		);
	}

	/**
	 * Explains which endpoint style is in use.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	private static function check_routing() {
		if ( Beaver_PWA_Routes::uses_pretty_urls() ) {
			return self::result(
				'routing',
				__( 'Endpoints use clean URLs', 'beaver-pwa' ),
				'pass',
				__( 'The worker is served from the site root, so it can control every page.', 'beaver-pwa' )
			);
		}

		return self::result(
			'routing',
			__( 'Endpoints use query strings', 'beaver-pwa' ),
			'warn',
			__( 'This works, and still gives the worker the whole site, but clean URLs are tidier. Turn on pretty permalinks to switch.', 'beaver-pwa' ),
			array(
				'url'   => admin_url( 'options-permalink.php' ),
				'label' => __( 'Open permalinks', 'beaver-pwa' ),
			)
		);
	}

	/*
	 * -----------------------------------------------------------------------
	 * Helpers
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Builds a check result.
	 *
	 * @since 1.0.0
	 *
	 * @param string $id      Check id.
	 * @param string $label   Short headline.
	 * @param string $status  pass, warn or fail.
	 * @param string $message Detail line.
	 * @param array  $action  Optional link.
	 * @return array
	 */
	private static function result( $id, $label, $status, $message, $action = array() ) {
		return array(
			'id'      => $id,
			'label'   => $label,
			'status'  => $status,
			'message' => $message,
			'action'  => $action,
		);
	}

	/**
	 * Requests a URL on this site.
	 *
	 * @since 1.0.0
	 *
	 * @param string $url Absolute URL.
	 * @return array|WP_Error
	 */
	private static function fetch( $url ) {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );

		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => 12,
				'sslverify'  => ! self::is_local_host( $host ),
				'redirection' => 2,
				'headers'    => array( 'Cache-Control' => 'no-cache' ),
				'user-agent' => 'BeaverPWA/' . BEAVER_PWA_VERSION . '; ' . home_url( '/' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$headers = wp_remote_retrieve_headers( $response );

		if ( is_object( $headers ) && method_exists( $headers, 'getAll' ) ) {
			$headers = $headers->getAll();
		}

		return array(
			'code'    => (int) wp_remote_retrieve_response_code( $response ),
			'body'    => (string) wp_remote_retrieve_body( $response ),
			'type'    => self::flatten( wp_remote_retrieve_header( $response, 'content-type' ) ),
			'headers' => is_array( $headers ) ? array_change_key_case( $headers ) : array(),
		);
	}

	/**
	 * Reduces a header value to a string.
	 *
	 * A header sent more than once arrives as an array, which is rare but real.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value Header value.
	 * @return string
	 */
	private static function flatten( $value ) {
		if ( is_array( $value ) ) {
			$value = reset( $value );
		}

		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Whether a host is a development host that browsers treat as secure.
	 *
	 * @since 1.0.0
	 *
	 * @param string $host Host name.
	 * @return bool
	 */
	private static function is_local_host( $host ) {
		$host = strtolower( (string) $host );

		if ( in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
			return true;
		}

		return (bool) preg_match( '/\.(test|local|localhost|invalid|example)$/', $host );
	}
}
