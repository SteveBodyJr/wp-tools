<?php
/**
 * Plugin Name:       Beaver Shutter
 * Plugin URI:        https://digitalbeavertz.com/
 * Description:       Takes a site dark and brings it back on command. Visitors get a holding page returning 503 while the front end is closed; you keep full wp-admin the whole time, and one click — or one WP-CLI command, or a wp-config flag — reopens it. Nothing is ever deleted. Find it under Tools → Shutter.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Digital Beaver
 * Author URI:        https://digitalbeavertz.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       beaver-shutter
 * Domain Path:       /languages
 *
 * @package BeaverShutter
 */

defined( 'ABSPATH' ) || exit;

define( 'BEAVER_SHUTTER_VERSION', '1.0.0' );
define( 'BEAVER_SHUTTER_FILE', __FILE__ );
define( 'BEAVER_SHUTTER_PATH', plugin_dir_path( __FILE__ ) );
define( 'BEAVER_SHUTTER_URL', plugin_dir_url( __FILE__ ) );
define( 'BEAVER_SHUTTER_BASENAME', plugin_basename( __FILE__ ) );
define( 'BEAVER_SHUTTER_SLUG', 'beaver-shutter' );

require_once BEAVER_SHUTTER_PATH . 'includes/class-settings.php';
require_once BEAVER_SHUTTER_PATH . 'includes/class-log.php';
require_once BEAVER_SHUTTER_PATH . 'includes/class-lockdown.php';

if ( is_admin() ) {
	require_once BEAVER_SHUTTER_PATH . 'includes/class-admin.php';
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once BEAVER_SHUTTER_PATH . 'includes/class-cli.php';

	WP_CLI::add_command( 'beaver-shutter', 'Beaver_Shutter_CLI' );
}

/**
 * Plugin bootstrap.
 *
 * The boundaries are the design, and they are deliberate:
 *
 * - It closes the front end only. wp-admin, WP-CLI and cron are never touched,
 *   so whoever owns the site keeps full control of it while it is dark. This is
 *   a shutter over the shop window, not a new lock on the owner's own door.
 * - It never deletes or edits anything. The blackout is drawn at render time on
 *   the `template_redirect` hook, so removing the plugin removes it completely.
 * - It cannot lock you out of your own recovery. Three independent switches turn
 *   it off — the admin screen, WP-CLI, and a `BEAVER_SHUTTER_OFF` constant in
 *   wp-config.php that overrides the database.
 * - It appears in the plugins list under its own name, with a description that
 *   says what it does.
 *
 * @since 1.0.0
 */
final class Beaver_Shutter_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Beaver_Shutter_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Retrieves the singleton instance.
	 *
	 * @since 1.0.0
	 *
	 * @return Beaver_Shutter_Plugin
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

		Beaver_Shutter_Lockdown::init();

		if ( is_admin() ) {
			add_action( 'plugins_loaded', array( 'Beaver_Shutter_Admin', 'init' ) );
			add_filter( 'plugin_action_links_' . BEAVER_SHUTTER_BASENAME, array( $this, 'action_links' ) );
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
		load_plugin_textdomain( 'beaver-shutter', false, dirname( BEAVER_SHUTTER_BASENAME ) . '/languages' );
	}

	/**
	 * Adds a shortcut to the plugins list row.
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
				esc_url( admin_url( 'tools.php?page=' . BEAVER_SHUTTER_SLUG ) ),
				esc_html__( 'Shutter', 'beaver-shutter' )
			)
		);

		return $links;
	}

	/**
	 * Seeds defaults.
	 *
	 * The site is left open on activation. Closing it is always a deliberate
	 * act, never a side effect of installing the plugin.
	 *
	 * @since 1.0.0
	 */
	public static function activate() {
		if ( false === get_option( Beaver_Shutter_Settings::OPTION ) ) {
			// Autoloaded: the front end reads the level on every request, and
			// riding along in the alloptions query costs nothing.
			add_option( Beaver_Shutter_Settings::OPTION, Beaver_Shutter_Settings::defaults(), '', 'yes' );
		}

		Beaver_Shutter_Log::record( 'installed', __( 'Plugin activated. The site is open.', 'beaver-shutter' ) );
	}

	/**
	 * Reopens the site.
	 *
	 * Deactivating must never leave a site dark with no plugin left to reopen
	 * it, so the level is forced back to open on the way out.
	 *
	 * @since 1.0.0
	 */
	public static function deactivate() {
		Beaver_Shutter_Settings::update( array( 'level' => Beaver_Shutter_Settings::OPEN ) );

		Beaver_Shutter_Log::record( 'deactivated', __( 'Plugin deactivated. The site was reopened.', 'beaver-shutter' ) );
	}
}

Beaver_Shutter_Plugin::instance();

register_activation_hook( __FILE__, array( 'Beaver_Shutter_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Beaver_Shutter_Plugin', 'deactivate' ) );
