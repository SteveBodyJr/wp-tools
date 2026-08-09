<?php
/**
 * Plugin Name:       Beaver Updates
 * Plugin URI:        https://github.com/SteveBodyJr/wp-tools
 * Description:       Brings the Digital Beaver plugins into Plugins → Updates, so they update in a click like any wordpress.org plugin. Reads one small manifest, cached, no key required.
 * Version:           1.1.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Digital Beaver
 * Author URI:        https://digitalbeavertz.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       beaver-updates
 * Domain Path:       /languages
 *
 * @package BeaverUpdates
 */

defined( 'ABSPATH' ) || exit;

define( 'BEAVER_UPDATES_VERSION', '1.1.0' );
define( 'BEAVER_UPDATES_FILE', __FILE__ );
define( 'BEAVER_UPDATES_PATH', plugin_dir_path( __FILE__ ) );
define( 'BEAVER_UPDATES_URL', plugin_dir_url( __FILE__ ) );
define( 'BEAVER_UPDATES_BASENAME', plugin_basename( __FILE__ ) );

require_once BEAVER_UPDATES_PATH . 'includes/class-channel.php';
require_once BEAVER_UPDATES_PATH . 'includes/class-updates.php';
require_once BEAVER_UPDATES_PATH . 'includes/class-admin.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once BEAVER_UPDATES_PATH . 'includes/class-cli.php';

	WP_CLI::add_command( 'beaver-updates', 'Beaver_Updates_CLI' );
}

/**
 * Plugin bootstrap.
 *
 * @since 1.0.0
 */
final class Beaver_Updates_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Beaver_Updates_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Retrieves the singleton instance.
	 *
	 * @since 1.0.0
	 *
	 * @return Beaver_Updates_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Wires the update filters in.
	 *
	 * @since 1.0.0
	 */
	private function __construct() {
		add_action( 'init', array( __CLASS__, 'load_textdomain' ) );

		Beaver_Updates_Updates::init();

		if ( is_admin() ) {
			Beaver_Updates_Admin::init();
		}
	}

	/**
	 * Loads translations.
	 *
	 * @since 1.0.0
	 */
	public static function load_textdomain() {
		load_plugin_textdomain( 'beaver-updates', false, dirname( BEAVER_UPDATES_BASENAME ) . '/languages' );
	}

	/**
	 * Activation handler.
	 *
	 * Fetches the manifest straight away so the Updates screen is useful on the
	 * very first visit rather than after the next scheduled check.
	 *
	 * @since 1.0.0
	 */
	public static function activate() {
		Beaver_Updates_Channel::refresh();

		delete_site_transient( 'update_plugins' );
	}

	/**
	 * Deactivation handler.
	 *
	 * @since 1.0.0
	 */
	public static function deactivate() {
		Beaver_Updates_Channel::forget();

		// Drop the update data this plugin injected, so nothing is left
		// offering packages that nothing can now supply.
		delete_site_transient( 'update_plugins' );
	}
}

register_activation_hook( __FILE__, array( 'Beaver_Updates_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Beaver_Updates_Plugin', 'deactivate' ) );

Beaver_Updates_Plugin::instance();
