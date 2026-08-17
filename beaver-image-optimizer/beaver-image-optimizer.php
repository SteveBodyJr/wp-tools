<?php
/**
 * Plugin Name:       Beaver Image Optimizer
 * Plugin URI:        https://digitalbeavertz.com/
 * Description:       Converts JPEG and PNG uploads to WebP using only the image libraries bundled with PHP and WordPress. Built for shared hosting: no root access, no CLI tools, no external APIs.
 * Version:           1.3.6
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Digital Beaver
 * Author URI:        https://digitalbeavertz.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       beaver-image-optimizer
 * Domain Path:       /languages
 *
 * @package BeaverImageOptimizer
 */

defined( 'ABSPATH' ) || exit;

define( 'BEAVER_IO_VERSION', '1.3.6' );
define( 'BEAVER_IO_FILE', __FILE__ );
define( 'BEAVER_IO_PATH', plugin_dir_path( __FILE__ ) );
define( 'BEAVER_IO_URL', plugin_dir_url( __FILE__ ) );
define( 'BEAVER_IO_BASENAME', plugin_basename( __FILE__ ) );

require_once BEAVER_IO_PATH . 'includes/class-converter.php';
require_once BEAVER_IO_PATH . 'includes/class-optimizer.php';
require_once BEAVER_IO_PATH . 'includes/class-admin.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once BEAVER_IO_PATH . 'includes/class-cli.php';

	WP_CLI::add_command( 'beaver-io', 'Beaver_Image_CLI' );
}

/**
 * Plugin bootstrap.
 *
 * Wires the converter, optimizer and admin classes into WordPress and owns the
 * activation / deactivation / uninstall lifecycle.
 *
 * @since 1.0.0
 */
final class Beaver_Image_Optimizer_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Beaver_Image_Optimizer_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Retrieves the singleton instance.
	 *
	 * @since 1.0.0
	 *
	 * @return Beaver_Image_Optimizer_Plugin
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
		add_action( 'plugins_loaded', array( 'Beaver_Image_Optimizer', 'init' ) );

		if ( is_admin() ) {
			add_action( 'plugins_loaded', array( 'Beaver_Image_Admin', 'init' ) );
			add_filter( 'plugin_action_links_' . BEAVER_IO_BASENAME, array( $this, 'action_links' ) );
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
		load_plugin_textdomain( 'beaver-image-optimizer', false, dirname( BEAVER_IO_BASENAME ) . '/languages' );
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
			esc_url( admin_url( 'admin.php?page=beaver-io-settings' ) ),
			esc_html__( 'Settings', 'beaver-image-optimizer' )
		);

		array_unshift( $links, $settings );

		return $links;
	}

	/**
	 * Runs on activation: seeds defaults and installs the delivery rules.
	 *
	 * @since 1.0.0
	 */
	public static function activate() {
		if ( false === get_option( Beaver_Image_Optimizer::OPTION_SETTINGS ) ) {
			add_option( Beaver_Image_Optimizer::OPTION_SETTINGS, Beaver_Image_Optimizer::default_settings() );
		}

		if ( false === get_option( Beaver_Image_Optimizer::OPTION_STATS ) ) {
			add_option( Beaver_Image_Optimizer::OPTION_STATS, Beaver_Image_Optimizer::empty_stats(), '', 'no' );
		}

		Beaver_Image_Optimizer::write_delivery_rules();
	}

	/**
	 * Runs on deactivation: removes the rewrite rules and transient state.
	 *
	 * Image files and statistics are deliberately preserved so that deactivating
	 * the plugin never destroys work.
	 *
	 * @since 1.0.0
	 */
	public static function deactivate() {
		Beaver_Image_Optimizer::remove_delivery_rules();
		delete_option( Beaver_Image_Optimizer::OPTION_QUEUE );
		delete_option( Beaver_Image_Optimizer::OPTION_INFLIGHT );
		delete_option( Beaver_Image_Optimizer::OPTION_LOCK );
		delete_transient( 'beaver_io_delivery_test' );
	}

	/**
	 * Runs on uninstall: removes plugin options and per-attachment meta.
	 *
	 * Generated WebP files are left in place because in "delete originals" mode
	 * they are the only remaining copy of the image.
	 *
	 * @since 1.0.0
	 */
	public static function uninstall() {
		global $wpdb;

		Beaver_Image_Optimizer::remove_delivery_rules();

		delete_option( Beaver_Image_Optimizer::OPTION_SETTINGS );
		delete_option( Beaver_Image_Optimizer::OPTION_STATS );
		delete_option( Beaver_Image_Optimizer::OPTION_QUEUE );
		delete_option( Beaver_Image_Optimizer::OPTION_INFLIGHT );
		delete_option( Beaver_Image_Optimizer::OPTION_LOCK );
		delete_transient( 'beaver_io_delivery_test' );

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ( %s, %s, %s )",
				Beaver_Image_Optimizer::META_STATUS,
				Beaver_Image_Optimizer::META_STATS,
				Beaver_Image_Optimizer::META_ERROR
			)
		);
	}
}

register_activation_hook( __FILE__, array( 'Beaver_Image_Optimizer_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Beaver_Image_Optimizer_Plugin', 'deactivate' ) );
register_uninstall_hook( __FILE__, array( 'Beaver_Image_Optimizer_Plugin', 'uninstall' ) );

Beaver_Image_Optimizer_Plugin::instance();
