<?php
/**
 * Plugin Name:       Beaver SEO
 * Plugin URI:        https://digitalbeavertz.com/
 * Description:       Titles, meta descriptions, Open Graph and Twitter Card tags, canonical URLs, robots control, an XML sitemap, basic structured data and a redirect manager — all generated from what WordPress already knows, with no external service and no build step.
 * Version:           1.1.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Digital Beaver
 * Author URI:        https://digitalbeavertz.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       beaver-seo
 * Domain Path:       /languages
 *
 * @package BeaverSEO
 */

defined( 'ABSPATH' ) || exit;

define( 'BEAVER_SEO_VERSION', '1.1.0' );
define( 'BEAVER_SEO_FILE', __FILE__ );
define( 'BEAVER_SEO_PATH', plugin_dir_path( __FILE__ ) );
define( 'BEAVER_SEO_URL', plugin_dir_url( __FILE__ ) );
define( 'BEAVER_SEO_BASENAME', plugin_basename( __FILE__ ) );

require_once BEAVER_SEO_PATH . 'includes/class-settings.php';
require_once BEAVER_SEO_PATH . 'includes/class-meta.php';
require_once BEAVER_SEO_PATH . 'includes/class-social.php';
require_once BEAVER_SEO_PATH . 'includes/class-schema.php';
require_once BEAVER_SEO_PATH . 'includes/class-sitemap.php';
require_once BEAVER_SEO_PATH . 'includes/class-redirects.php';
require_once BEAVER_SEO_PATH . 'includes/class-metabox.php';
require_once BEAVER_SEO_PATH . 'includes/class-hreflang.php';

if ( is_admin() ) {
	require_once BEAVER_SEO_PATH . 'includes/class-admin.php';
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once BEAVER_SEO_PATH . 'includes/class-cli.php';

	WP_CLI::add_command( 'beaver-seo', 'Beaver_SEO_CLI' );
}

/**
 * Plugin bootstrap.
 *
 * Wires every class into WordPress and owns the activation, deactivation
 * and uninstall lifecycle.
 *
 * @since 1.0.0
 */
final class Beaver_SEO_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Beaver_SEO_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Retrieves the singleton instance.
	 *
	 * @since 1.0.0
	 *
	 * @return Beaver_SEO_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Registers hooks.
	 *
	 * @since 1.0.0
	 */
	private function __construct() {
		add_action( 'init', array( $this, 'load_textdomain' ) );

		add_action( 'plugins_loaded', array( 'Beaver_SEO_Meta', 'init' ) );
		add_action( 'plugins_loaded', array( 'Beaver_SEO_Social', 'init' ) );
		add_action( 'plugins_loaded', array( 'Beaver_SEO_Schema', 'init' ) );
		add_action( 'plugins_loaded', array( 'Beaver_SEO_Sitemap', 'init' ) );
		add_action( 'plugins_loaded', array( 'Beaver_SEO_Redirects', 'init' ) );
		add_action( 'plugins_loaded', array( 'Beaver_SEO_Metabox', 'init' ) );
		add_action( 'plugins_loaded', array( 'Beaver_SEO_Hreflang', 'init' ) );

		if ( is_admin() ) {
			add_action( 'plugins_loaded', array( 'Beaver_SEO_Admin', 'init' ) );
			add_filter( 'plugin_action_links_' . BEAVER_SEO_BASENAME, array( $this, 'action_links' ) );
		}
	}

	/**
	 * Loads the plugin translations.
	 *
	 * Hooked to `init` because WordPress 6.7 warns when translations are
	 * loaded any earlier.
	 *
	 * @since 1.0.0
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'beaver-seo', false, dirname( BEAVER_SEO_BASENAME ) . '/languages' );
	}

	/**
	 * Adds a Settings shortcut to the plugins list row.
	 *
	 * @since 1.0.0
	 *
	 * @param string[] $links Existing action links.
	 * @return string[] Filtered action links.
	 */
	public function action_links( $links ) {
		$settings = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'admin.php?page=' . Beaver_SEO_Admin::MENU_SLUG ) ),
			esc_html__( 'Settings', 'beaver-seo' )
		);

		array_unshift( $links, $settings );

		return $links;
	}

	/**
	 * Runs on activation: creates the redirects table, seeds defaults, and
	 * makes the sitemap URLs resolve immediately rather than 404 until the
	 * next unrelated permalink flush.
	 *
	 * @since 1.0.0
	 */
	public static function activate() {
		Beaver_SEO_Redirects::install();

		if ( false === get_option( Beaver_SEO_Settings::OPTION ) ) {
			add_option( Beaver_SEO_Settings::OPTION, Beaver_SEO_Settings::defaults() );
		}

		Beaver_SEO_Sitemap::add_rewrite_rules();
		flush_rewrite_rules();
	}

	/**
	 * Runs on deactivation: drops the sitemap URLs and cached pages.
	 *
	 * The redirects table and every setting are deliberately preserved, so
	 * deactivating — often done briefly to rule out a conflict — never costs
	 * a rebuilt redirect list.
	 *
	 * @since 1.0.0
	 */
	public static function deactivate() {
		flush_rewrite_rules();
		Beaver_SEO_Sitemap::flush_cache();
	}

	/**
	 * Runs on uninstall: removes everything this plugin created.
	 *
	 * @since 1.0.0
	 */
	public static function uninstall() {
		global $wpdb;

		flush_rewrite_rules();
		Beaver_SEO_Sitemap::flush_cache();

		delete_option( Beaver_SEO_Settings::OPTION );
		delete_option( 'beaver_seo_redirects_db_version' );

		$table = Beaver_SEO_Redirects::table();
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ( %s, %s, %s, %s, %s )",
				Beaver_SEO_Meta::META_TITLE,
				Beaver_SEO_Meta::META_DESCRIPTION,
				Beaver_SEO_Meta::META_IMAGE,
				Beaver_SEO_Meta::META_NOINDEX,
				Beaver_SEO_Meta::META_NOFOLLOW
			)
		);

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->termmeta} WHERE meta_key IN ( %s, %s, %s, %s, %s, %s )",
				Beaver_SEO_Meta::META_TITLE,
				Beaver_SEO_Meta::META_DESCRIPTION,
				Beaver_SEO_Meta::META_IMAGE,
				Beaver_SEO_Meta::META_NOINDEX,
				Beaver_SEO_Meta::META_NOFOLLOW,
				Beaver_SEO_Meta::META_CANONICAL
			)
		);

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s",
				Beaver_SEO_Meta::META_CANONICAL
			)
		);
	}
}

register_activation_hook( __FILE__, array( 'Beaver_SEO_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Beaver_SEO_Plugin', 'deactivate' ) );
register_uninstall_hook( __FILE__, array( 'Beaver_SEO_Plugin', 'uninstall' ) );

Beaver_SEO_Plugin::instance();
