<?php
/**
 * Plugin Name:       Beaver App Bar
 * Plugin URI:        https://digitalbeavertz.com/
 * Description:       Gives a site the bottom tab bar of a phone app. Up to five items — pages, sections, WhatsApp, call, email, a menu sheet or search — fixed to the bottom of the screen on mobile, drawn with its own icons and no external assets. Find it under Appearance → App Bar.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Digital Beaver
 * Author URI:        https://digitalbeavertz.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       beaver-appbar
 * Domain Path:       /languages
 *
 * @package BeaverAppBar
 */

defined( 'ABSPATH' ) || exit;

define( 'BEAVER_APPBAR_VERSION', '1.0.0' );
define( 'BEAVER_APPBAR_FILE', __FILE__ );
define( 'BEAVER_APPBAR_PATH', plugin_dir_path( __FILE__ ) );
define( 'BEAVER_APPBAR_URL', plugin_dir_url( __FILE__ ) );
define( 'BEAVER_APPBAR_BASENAME', plugin_basename( __FILE__ ) );
define( 'BEAVER_APPBAR_SLUG', 'beaver-appbar' );

require_once BEAVER_APPBAR_PATH . 'includes/class-icons.php';
require_once BEAVER_APPBAR_PATH . 'includes/class-settings.php';
require_once BEAVER_APPBAR_PATH . 'includes/class-bar.php';

if ( is_admin() ) {
	require_once BEAVER_APPBAR_PATH . 'includes/class-admin.php';
}

/**
 * Plugin bootstrap.
 *
 * The boundaries are the design, and they are deliberate:
 *
 * - It works on any theme. Nothing here reads a theme function, a page builder
 *   or a post type; the bar is drawn on `wp_footer` from its own markup, its own
 *   inline SVG icons and its own stylesheet, and it takes its colours from one
 *   accent setting rather than from anything the theme happens to expose.
 * - It costs nothing when it is off. No stylesheet, no script and no markup are
 *   enqueued unless the bar is actually going to be shown, so a site with it
 *   switched off carries the plugin and nothing else.
 * - It adds no request. The icons are inline SVG and the two asset files are
 *   the plugin's own, so there is no icon font, no CDN and no outbound call.
 *   Nothing about a visitor ever leaves the site.
 * - It never changes content. The bar is drawn in the response and nowhere
 *   else, so deactivating removes it completely and leaves no trace in a post.
 *
 * @since 1.0.0
 */
final class Beaver_AppBar_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Beaver_AppBar_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Retrieves the singleton instance.
	 *
	 * @since 1.0.0
	 *
	 * @return Beaver_AppBar_Plugin
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

		Beaver_AppBar_Bar::init();

		if ( is_admin() ) {
			add_action( 'plugins_loaded', array( 'Beaver_AppBar_Admin', 'init' ) );
			add_filter( 'plugin_action_links_' . BEAVER_APPBAR_BASENAME, array( $this, 'action_links' ) );
		}
	}

	/**
	 * Loads the plugin translations.
	 *
	 * Hooked to `init` because WordPress 6.7 warns when translations are loaded
	 * any earlier.
	 *
	 * @since 1.0.0
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'beaver-appbar', false, dirname( BEAVER_APPBAR_BASENAME ) . '/languages' );
	}

	/**
	 * Adds a shortcut to the plugins list row.
	 *
	 * The screen lives under Appearance, which is not where everyone looks
	 * first, so the row on the Plugins screen is the reliable way in.
	 *
	 * @since 1.0.0
	 *
	 * @param string[] $links Existing action links.
	 * @return string[] Filtered action links.
	 */
	public function action_links( $links ) {
		array_unshift(
			$links,
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'themes.php?page=' . BEAVER_APPBAR_SLUG ) ),
				esc_html__( 'App Bar', 'beaver-appbar' )
			)
		);

		return $links;
	}

	/**
	 * Seeds defaults.
	 *
	 * The bar ships switched off with a starter set of items already in place,
	 * so activating changes nothing on the front end until someone has looked at
	 * the settings and turned it on deliberately.
	 *
	 * @since 1.0.0
	 */
	public static function activate() {
		if ( false === get_option( Beaver_AppBar_Settings::OPTION ) ) {
			// Autoloaded: the front end reads this on every request, and riding
			// along in the alloptions query costs nothing.
			add_option( Beaver_AppBar_Settings::OPTION, Beaver_AppBar_Settings::defaults(), '', 'yes' );
		}
	}
}

Beaver_AppBar_Plugin::instance();

register_activation_hook( __FILE__, array( 'Beaver_AppBar_Plugin', 'activate' ) );
