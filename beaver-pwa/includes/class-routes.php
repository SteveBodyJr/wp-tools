<?php
/**
 * Front controller for the manifest, worker, offline page and heartbeat.
 *
 * @package BeaverPWA
 */

defined( 'ABSPATH' ) || exit;

/**
 * Serves the plugin's four endpoints without touching the filesystem.
 *
 * A service worker may only control the directory it is served from and
 * everything below it, so both routing styles resolve to the site root: a
 * rewrite when pretty permalinks are on, and the home URL with a query string
 * when they are not.
 *
 * @since 1.0.0
 */
final class Beaver_PWA_Routes {

	const QUERY_VAR = 'beaver_pwa';

	/**
	 * Pretty path for each endpoint, relative to the home URL.
	 *
	 * @var array
	 */
	private static $paths = array(
		'sw'       => 'beaver-pwa-sw.js',
		'manifest' => 'beaver-pwa-manifest.json',
		'offline'  => 'beaver-pwa-offline',
		'alive'    => 'beaver-pwa-alive',
	);

	/**
	 * Registers rewrites and the request handler.
	 *
	 * @since 1.0.0
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_rules' ) );
		add_filter( 'query_vars', array( __CLASS__, 'register_query_var' ) );
		add_action( 'parse_request', array( __CLASS__, 'dispatch' ) );
	}

	/**
	 * Adds one rewrite rule per endpoint.
	 *
	 * @since 1.0.0
	 */
	public static function register_rules() {
		foreach ( self::$paths as $endpoint => $path ) {
			add_rewrite_rule(
				'^' . preg_quote( $path, '#' ) . '/?$',
				'index.php?' . self::QUERY_VAR . '=' . $endpoint,
				'top'
			);
		}
	}

	/**
	 * Allows the endpoint to be requested without pretty permalinks.
	 *
	 * @since 1.0.0
	 *
	 * @param array $vars Public query vars.
	 * @return array
	 */
	public static function register_query_var( $vars ) {
		$vars[] = self::QUERY_VAR;

		return $vars;
	}

	/**
	 * Whether pretty endpoint URLs can be used.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public static function uses_pretty_urls() {
		$mode = Beaver_PWA_Settings::get( 'sw_route' );

		if ( 'pretty' === $mode ) {
			return true;
		}

		if ( 'query' === $mode ) {
			return false;
		}

		return (bool) get_option( 'permalink_structure' );
	}

	/**
	 * Absolute URL of an endpoint.
	 *
	 * @since 1.0.0
	 *
	 * @param string $endpoint One of sw, manifest, offline, alive.
	 * @return string
	 */
	public static function url( $endpoint ) {
		if ( ! isset( self::$paths[ $endpoint ] ) ) {
			return '';
		}

		if ( self::uses_pretty_urls() ) {
			return home_url( '/' . self::$paths[ $endpoint ] );
		}

		return add_query_arg( self::QUERY_VAR, $endpoint, home_url( '/' ) );
	}

	/**
	 * Both URLs for an endpoint, for the diagnostics screen.
	 *
	 * @since 1.0.0
	 *
	 * @param string $endpoint Endpoint key.
	 * @return array
	 */
	public static function both_urls( $endpoint ) {
		if ( ! isset( self::$paths[ $endpoint ] ) ) {
			return array();
		}

		return array(
			'pretty' => home_url( '/' . self::$paths[ $endpoint ] ),
			'query'  => add_query_arg( self::QUERY_VAR, $endpoint, home_url( '/' ) ),
		);
	}

	/**
	 * Answers an endpoint request before WordPress runs the main query.
	 *
	 * @since 1.0.0
	 *
	 * @param WP $wp Current request.
	 */
	public static function dispatch( $wp ) {
		if ( empty( $wp->query_vars[ self::QUERY_VAR ] ) ) {
			return;
		}

		$endpoint = sanitize_key( $wp->query_vars[ self::QUERY_VAR ] );

		if ( ! isset( self::$paths[ $endpoint ] ) ) {
			return;
		}

		if ( ! Beaver_PWA_Settings::is_enabled() ) {
			self::send_gone();
		}

		switch ( $endpoint ) {
			case 'sw':
				Beaver_PWA_Service_Worker::serve();
				break;

			case 'manifest':
				Beaver_PWA_Manifest::serve();
				break;

			case 'offline':
				Beaver_PWA_Service_Worker::serve_offline();
				break;

			case 'alive':
				Beaver_PWA_Service_Worker::serve_heartbeat();
				break;
		}
	}

	/**
	 * Tells any worker still installed in a browser that the app is switched off.
	 *
	 * @since 1.0.0
	 */
	private static function send_gone() {
		status_header( 410 );

		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Cache-Control: no-cache, no-store, must-revalidate' );

		echo 'beaver-pwa-disabled';

		exit;
	}
}
