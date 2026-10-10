<?php
/**
 * Redirect storage and matching.
 *
 * @package BeaverSEO
 */

defined( 'ABSPATH' ) || exit;

/**
 * A simple, exact-path 301/302/307 redirect table.
 *
 * Deliberately exact-match only, no regular expressions: a wildcard rule
 * mistyped in an admin screen is how a redirect manager takes a whole site
 * offline, and nothing in the request queue here needs anything cleverer
 * than "this old path now lives here."
 *
 * @since 1.0.0
 */
class Beaver_SEO_Redirects {

	const DB_VERSION = '1';

	/**
	 * Returns the redirects table name.
	 *
	 * @since 1.0.0
	 *
	 * @return string Table name.
	 */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'beaver_seo_redirects';
	}

	/**
	 * Creates the table.
	 *
	 * @since 1.0.0
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				source_path varchar(255) NOT NULL DEFAULT '',
				target_url varchar(2000) NOT NULL DEFAULT '',
				status_code smallint(3) unsigned NOT NULL DEFAULT 301,
				hits bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				last_hit_at datetime NULL DEFAULT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY source_path (source_path)
			) {$collate};"
		);

		update_option( 'beaver_seo_redirects_db_version', self::DB_VERSION, false );
	}

	/**
	 * Registers the front-end hook.
	 *
	 * @since 1.0.0
	 */
	public static function init() {
		// Priority 0: a redirect has to win before a theme or another plugin
		// starts deciding what this request even is.
		add_action( 'template_redirect', array( __CLASS__, 'maybe_redirect' ), 0 );
	}

	/**
	 * Redirects the current request, when its path matches a stored rule.
	 *
	 * @since 1.0.0
	 */
	public static function maybe_redirect() {
		if ( is_admin() || is_preview() ) {
			return;
		}

		$path = self::normalize( self::current_path() );

		if ( '' === $path ) {
			return;
		}

		$row = self::find( $path );

		if ( ! $row ) {
			return;
		}

		self::record_hit( (int) $row->id );

		/*
		 * wp_safe_redirect() would refuse most cross-domain targets, which is
		 * the wrong default here: every rule was written by an administrator
		 * in wp-admin, not taken from the request, so there is no untrusted
		 * input for that guard to protect against — and redirecting an old
		 * domain to a new one is a normal, intended use of this table.
		 */
		wp_redirect( esc_url_raw( $row->target_url ), (int) $row->status_code, 'Beaver SEO' ); // phpcs:ignore WordPress.Security.SafeRedirect
		exit;
	}

	/**
	 * Returns the request path, stripped of the site subdirectory and query.
	 *
	 * @since 1.0.0
	 *
	 * @return string Path beginning with a slash, or an empty string.
	 */
	private static function current_path() {
		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return '';
		}

		$request = esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) );
		$path    = (string) wp_parse_url( $request, PHP_URL_PATH );
		$home    = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );

		if ( '' !== $home && '/' !== $home && 0 === strpos( $path, $home ) ) {
			$path = substr( $path, strlen( $home ) - 1 );
		}

		return rawurldecode( $path );
	}

	/**
	 * Normalizes a path for storage and comparison.
	 *
	 * A leading slash and no trailing one, so "/old-page" and "/old-page/"
	 * are always the same rule.
	 *
	 * @since 1.0.0
	 *
	 * @param string $path Raw path.
	 * @return string Normalized path.
	 */
	public static function normalize( $path ) {
		$path = '/' . ltrim( trim( (string) $path ), '/' );
		$path = untrailingslashit( $path );

		return '' === $path ? '/' : $path;
	}

	/**
	 * Finds the rule for a path.
	 *
	 * @since 1.0.0
	 *
	 * @param string $path Normalized path.
	 * @return object|null Row, or null when no rule matches.
	 */
	public static function find( $path ) {
		global $wpdb;

		$table = self::table();

		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE source_path = %s", $path ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Records a hit against a rule.
	 *
	 * @since 1.0.0
	 *
	 * @param int $id Rule ID.
	 */
	private static function record_hit( $id ) {
		global $wpdb;

		$table = self::table();

		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET hits = hits + 1, last_hit_at = %s WHERE id = %d", current_time( 'mysql' ), $id ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Lists every rule, newest first.
	 *
	 * @since 1.0.0
	 *
	 * @return object[] Rows.
	 */
	public static function all() {
		global $wpdb;

		$table = self::table();

		return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC" ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Creates a rule.
	 *
	 * @since 1.0.0
	 *
	 * @param string $source_path Path to redirect from.
	 * @param string $target_url  URL to redirect to.
	 * @param int    $status_code 301, 302 or 307.
	 * @return int|WP_Error New rule ID, or an error.
	 */
	public static function create( $source_path, $target_url, $status_code ) {
		global $wpdb;

		$source_path = self::normalize( $source_path );
		$target_url  = esc_url_raw( trim( (string) $target_url ) );
		$status_code = in_array( (int) $status_code, array( 301, 302, 307 ), true ) ? (int) $status_code : 301;

		if ( '' === $target_url ) {
			return new WP_Error( 'beaver_seo_redirect_target', __( 'A destination is required.', 'beaver-seo' ) );
		}

		$target_host = (string) wp_parse_url( $target_url, PHP_URL_HOST );
		$home_host   = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$target_path = self::normalize( (string) wp_parse_url( $target_url, PHP_URL_PATH ) );

		if ( '' !== $target_host && $target_host === $home_host && $target_path === $source_path ) {
			return new WP_Error( 'beaver_seo_redirect_loop', __( 'The destination is the same as the source.', 'beaver-seo' ) );
		}

		$table = self::table();

		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB
			$table,
			array(
				'source_path' => $source_path,
				'target_url'  => $target_url,
				'status_code' => $status_code,
				'created_at'  => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%d', '%s' )
		);

		if ( ! $inserted ) {
			return new WP_Error( 'beaver_seo_redirect_exists', __( 'A redirect already exists for that path.', 'beaver-seo' ) );
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Deletes a rule.
	 *
	 * @since 1.0.0
	 *
	 * @param int $id Rule ID.
	 * @return bool True on success.
	 */
	public static function delete( $id ) {
		global $wpdb;

		return (bool) $wpdb->delete( self::table(), array( 'id' => (int) $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB
	}
}
