<?php
/**
 * Plugin Name:       Beaver Alt Text
 * Plugin URI:        https://digitalbeavertz.com/
 * Description:       Writes alt text for images that have none, using a vision model. Proposals are reviewed before they are published, human-written alt text is never overwritten, and decorative images are correctly left with empty alt.
 * Version:           1.2.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Digital Beaver
 * Author URI:        https://digitalbeavertz.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       beaver-alt-text
 * Domain Path:       /languages
 *
 * @package BeaverAltText
 */

defined( 'ABSPATH' ) || exit;

define( 'BEAVER_ALT_VERSION', '1.2.0' );
define( 'BEAVER_ALT_FILE', __FILE__ );
define( 'BEAVER_ALT_PATH', plugin_dir_path( __FILE__ ) );
define( 'BEAVER_ALT_URL', plugin_dir_url( __FILE__ ) );
define( 'BEAVER_ALT_BASENAME', plugin_basename( __FILE__ ) );

require_once BEAVER_ALT_PATH . 'includes/class-provider.php';
require_once BEAVER_ALT_PATH . 'includes/class-generator.php';
require_once BEAVER_ALT_PATH . 'includes/class-queue.php';
require_once BEAVER_ALT_PATH . 'includes/class-admin.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once BEAVER_ALT_PATH . 'includes/class-cli.php';

	WP_CLI::add_command( 'beaver-alt', 'Beaver_Alt_CLI' );
}

/**
 * Plugin bootstrap.
 *
 * @since 1.0.0
 */
final class Beaver_Alt_Text_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Beaver_Alt_Text_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Retrieves the singleton instance.
	 *
	 * @since 1.0.0
	 *
	 * @return Beaver_Alt_Text_Plugin
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
			add_action( 'plugins_loaded', array( 'Beaver_Alt_Admin', 'init' ) );
			add_filter( 'plugin_action_links_' . BEAVER_ALT_BASENAME, array( $this, 'action_links' ) );
		}
	}

	/**
	 * Loads translations.
	 *
	 * Hooked to `init` because WordPress 6.7 warns when translations load earlier.
	 *
	 * @since 1.0.0
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'beaver-alt-text', false, dirname( BEAVER_ALT_BASENAME ) . '/languages' );
	}

	/**
	 * Adds a Settings shortcut to the plugins list row.
	 *
	 * @since 1.0.0
	 *
	 * @param string[] $links Existing links.
	 * @return string[] Filtered links.
	 */
	public function action_links( $links ) {
		array_unshift(
			$links,
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=beaver-alt-settings' ) ),
				esc_html__( 'Settings', 'beaver-alt-text' )
			)
		);

		return $links;
	}

	/**
	 * Seeds defaults on activation.
	 *
	 * @since 1.0.0
	 */
	public static function activate() {
		if ( false === get_option( Beaver_Alt_Generator::OPTION_SETTINGS ) ) {
			add_option( Beaver_Alt_Generator::OPTION_SETTINGS, Beaver_Alt_Generator::default_settings() );
		}
	}

	/**
	 * Clears transient run state on deactivation.
	 *
	 * Proposals and generated alt text are deliberately preserved: deactivating
	 * must never destroy reviewed work.
	 *
	 * @since 1.0.0
	 */
	public static function deactivate() {
		Beaver_Alt_Queue::clear();
		Beaver_Alt_Queue::clear_inflight();
		Beaver_Alt_Queue::release_lock();
		Beaver_Alt_Queue::flush_counts();
	}

	/**
	 * Removes plugin data on uninstall.
	 *
	 * Alt text already written to attachments is left in place — once approved it
	 * belongs to the site, not to this plugin.
	 *
	 * @since 1.0.0
	 */
	public static function uninstall() {
		global $wpdb;

		delete_option( Beaver_Alt_Generator::OPTION_SETTINGS );
		delete_option( Beaver_Alt_Generator::OPTION_STATS );
		delete_option( Beaver_Alt_Queue::OPTION_QUEUE );
		delete_option( Beaver_Alt_Queue::OPTION_INFLIGHT );
		delete_option( Beaver_Alt_Queue::OPTION_LOCK );

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ( %s, %s, %s )",
				Beaver_Alt_Generator::META_PROPOSAL,
				Beaver_Alt_Generator::META_GENERATED,
				Beaver_Alt_Generator::META_ERROR
			)
		);
	}
}

register_activation_hook( __FILE__, array( 'Beaver_Alt_Text_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Beaver_Alt_Text_Plugin', 'deactivate' ) );
register_uninstall_hook( __FILE__, array( 'Beaver_Alt_Text_Plugin', 'uninstall' ) );

Beaver_Alt_Text_Plugin::instance();
