<?php
/**
 * Hardening.
 *
 * @package BeaverShield
 */

defined( 'ABSPATH' ) || exit;

/**
 * Closes the doors a normal site never uses.
 *
 * This is the part of the plugin that is worth more than the fingerprint work.
 * Hiding a version number slows an attacker down; closing user enumeration and
 * the admin file editor removes the two steps an automated attack actually
 * depends on. Neither costs a visitor anything, and neither is visible in the
 * page.
 *
 * @since 1.0.0
 */
class Beaver_Shield_Hardening {

	/**
	 * Registers hooks.
	 *
	 * @since 1.0.0
	 */
	public static function init() {
		if ( Beaver_Shield_Settings::on( 'disable_file_edit' ) && ! defined( 'DISALLOW_FILE_EDIT' ) ) {
			// Read by map_meta_cap() at request time, so defining it here is in
			// time for every capability check that matters.
			define( 'DISALLOW_FILE_EDIT', true );
		}

		if ( Beaver_Shield_Settings::on( 'block_user_enum' ) ) {
			add_action( 'parse_request', array( __CLASS__, 'block_author_query' ) );
			add_filter( 'rest_endpoints', array( __CLASS__, 'hide_user_routes' ) );
			add_filter( 'oembed_response_data', array( __CLASS__, 'strip_oembed_author' ) );
			add_filter( 'wp_sitemaps_add_provider', array( __CLASS__, 'drop_users_sitemap' ), 10, 2 );
		}

		if ( Beaver_Shield_Settings::on( 'generic_login_errors' ) ) {
			add_filter( 'login_errors', array( __CLASS__, 'generic_login_error' ) );
		}

		if ( Beaver_Shield_Settings::on( 'disable_xmlrpc' ) ) {
			add_filter( 'xmlrpc_enabled', '__return_false' );
			add_filter( 'xmlrpc_methods', array( __CLASS__, 'remove_pingback_methods' ) );
			add_filter( 'pings_open', '__return_false', 20 );
		}
	}

	/**
	 * Turns `?author=1` into a 404 for anyone not logged in.
	 *
	 * Walking the author IDs is how a username list is built, and a username is
	 * half of a login. Editors and anyone else with a session are unaffected,
	 * so author archives keep working everywhere they are legitimately used.
	 *
	 * The permalink check is not optional. On a site with plain permalinks,
	 * `?author=1` is not an enumeration trick, it *is* the author archive: the
	 * only URL that page has. Blocking it there would 404 a real, linked, very
	 * possibly indexed page, and Google drops a URL from the index once it
	 * starts answering 403 or 404. So on a plain permalink site this does
	 * nothing, and the admin screen says so.
	 *
	 * @since 1.0.0
	 *
	 * @param WP $wp Current request.
	 */
	public static function block_author_query( $wp ) {
		if ( is_user_logged_in() || is_admin() || ! self::author_query_is_redundant() ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading a public query string, not acting on a form.
		if ( ! isset( $_GET['author'] ) ) {
			return;
		}

		$wp->query_vars = array( 'error' => '404' );
	}

	/**
	 * Whether author archives have a URL of their own to fall back on.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True when pretty permalinks are in use.
	 */
	public static function author_query_is_redundant() {
		return '' !== (string) get_option( 'permalink_structure', '' );
	}

	/**
	 * Hides the users REST routes from anonymous callers.
	 *
	 * The whole route cannot simply be unregistered: the block editor lists
	 * authors through it, and half the plugins on a normal site read it while
	 * someone is logged in. Only anonymous requests lose it, which is the only
	 * case enumeration cares about.
	 *
	 * One site in a hundred genuinely needs it open to anonymous callers: a
	 * headless or block-rendered front end that fetches author names without a
	 * token. Google renders pages in headless Chromium before indexing them, so
	 * on such a site this would leave Googlebot rendering a page with a hole in
	 * it. Hence the filter, and hence the scan checking that everything the page
	 * loads still answers.
	 *
	 * @since 1.0.0
	 *
	 * @param array $endpoints REST endpoints.
	 * @return array Filtered endpoints.
	 */
	public static function hide_user_routes( $endpoints ) {
		if ( is_user_logged_in() ) {
			return $endpoints;
		}

		/**
		 * Filters whether the users routes are hidden from anonymous callers.
		 *
		 * Return false on a front end that reads them without authenticating.
		 *
		 * @since 1.0.0
		 *
		 * @param bool $hide Whether to hide them.
		 */
		if ( ! apply_filters( 'beaver_shield_hide_rest_users', true ) ) {
			return $endpoints;
		}

		unset( $endpoints['/wp/v2/users'] );
		unset( $endpoints['/wp/v2/users/(?P<id>[\d]+)'] );

		return $endpoints;
	}

	/**
	 * Removes the author name and URL from oEmbed responses.
	 *
	 * @since 1.0.0
	 *
	 * @param array $data Response data.
	 * @return array Filtered data.
	 */
	public static function strip_oembed_author( $data ) {
		unset( $data['author_name'], $data['author_url'] );

		return $data;
	}

	/**
	 * Keeps the users sitemap out of the core sitemap index.
	 *
	 * Closing `?author=` and the REST route while leaving `wp-sitemap-users-1.xml`
	 * publishing the same list would be theatre.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_Sitemaps_Provider $provider Provider instance.
	 * @param string               $name     Provider name.
	 * @return WP_Sitemaps_Provider|false The provider, or false to drop it.
	 */
	public static function drop_users_sitemap( $provider, $name ) {
		return 'users' === $name ? false : $provider;
	}

	/**
	 * Replaces login failure messages with one that says nothing.
	 *
	 * WordPress helpfully distinguishes "unknown username" from "the password
	 * you entered for that username is incorrect", which confirms an account
	 * exists. One message for every failure removes the oracle.
	 *
	 * @since 1.0.0
	 *
	 * @return string Message.
	 */
	public static function generic_login_error() {
		return __( 'Those details were not recognised.', 'beaver-shield' );
	}

	/**
	 * Strips the pingback methods from the XML-RPC surface.
	 *
	 * `pingback.ping` is the one worth removing on its own: it makes any
	 * WordPress site available as a reflector for traffic aimed at someone else.
	 *
	 * @since 1.0.0
	 *
	 * @param array $methods XML-RPC methods.
	 * @return array Filtered methods.
	 */
	public static function remove_pingback_methods( $methods ) {
		unset( $methods['pingback.ping'], $methods['pingback.extensions.getPingbacks'] );

		return $methods;
	}
}
