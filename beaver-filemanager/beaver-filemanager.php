<?php
/**
 * Plugin Name:       Beaver FileManager
 * Plugin URI:        https://digitalbeavertz.com/
 * Description:       A full file manager inside wp-admin: browse, search, upload, download, zip, chmod and edit any file on the site with syntax highlighting, PHP syntax checking, automatic backups and a restorable trash.
 * Version:           1.1.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Digital Beaver
 * Author URI:        https://digitalbeavertz.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       beaver-filemanager
 * Domain Path:       /languages
 *
 * @package BeaverFileManager
 */

defined( 'ABSPATH' ) || exit;

define( 'BEAVER_FM_VERSION', '1.1.0' );
define( 'BEAVER_FM_FILE', __FILE__ );
define( 'BEAVER_FM_PATH', plugin_dir_path( __FILE__ ) );
define( 'BEAVER_FM_URL', plugin_dir_url( __FILE__ ) );
define( 'BEAVER_FM_BASENAME', plugin_basename( __FILE__ ) );

require_once BEAVER_FM_PATH . 'includes/class-settings.php';
require_once BEAVER_FM_PATH . 'includes/class-logger.php';
require_once BEAVER_FM_PATH . 'includes/class-filesystem.php';
require_once BEAVER_FM_PATH . 'includes/class-editor.php';
require_once BEAVER_FM_PATH . 'includes/class-admin.php';

/**
 * Plugin bootstrap.
 *
 * Owns the activation / deactivation lifecycle and wires the admin screens in.
 * Nothing in this plugin runs on the front end: every class is loaded but only
 * the admin controller registers hooks, and it does so behind `is_admin()`.
 *
 * @since 1.0.0
 */
final class Beaver_FM_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Beaver_FM_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Retrieves the singleton instance.
	 *
	 * @since 1.0.0
	 *
	 * @return Beaver_FM_Plugin
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

		if ( is_admin() ) {
			add_action( 'plugins_loaded', array( 'Beaver_FM_Admin', 'init' ) );
			add_filter( 'plugin_action_links_' . BEAVER_FM_BASENAME, array( $this, 'action_links' ) );
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
		load_plugin_textdomain( 'beaver-filemanager', false, dirname( BEAVER_FM_BASENAME ) . '/languages' );
	}

	/**
	 * Adds shortcuts to the plugins list row.
	 *
	 * @since 1.0.0
	 *
	 * @param string[] $links Existing action links.
	 * @return string[] Filtered action links.
	 */
	public function action_links( $links ) {
		$shortcuts = array(
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=' . Beaver_FM_Admin::MENU_SLUG ) ),
				esc_html__( 'Browse Files', 'beaver-filemanager' )
			),
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=' . Beaver_FM_Admin::SETTINGS_SLUG ) ),
				esc_html__( 'Settings', 'beaver-filemanager' )
			),
		);

		return array_merge( $shortcuts, $links );
	}

	/**
	 * Runs on activation: seeds defaults and prepares the private storage area.
	 *
	 * @since 1.0.0
	 */
	public static function activate() {
		if ( false === get_option( Beaver_FM_Settings::OPTION ) ) {
			add_option( Beaver_FM_Settings::OPTION, Beaver_FM_Settings::defaults() );
		}

		Beaver_FM_Editor::prepare_storage();
	}

	/**
	 * Runs on deactivation.
	 *
	 * Backups and trash are deliberately preserved — deactivating the plugin
	 * must never be the thing that loses somebody's only copy of a file.
	 *
	 * @since 1.0.0
	 */
	public static function deactivate() {
		delete_transient( 'beaver_fm_storage_ready' );
	}
}

register_activation_hook( __FILE__, array( 'Beaver_FM_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Beaver_FM_Plugin', 'deactivate' ) );

Beaver_FM_Plugin::instance();
