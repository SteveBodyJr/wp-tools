<?php
/**
 * Plugin Name:       Beaver PWA
 * Plugin URI:        https://digitalbeavertz.com/
 * Description:       Turns a WordPress site into an installable Progressive Web App: web app manifest, offline service worker, generated app icons and a native-feeling install prompt. No build step, no external services.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Digital Beaver
 * Author URI:        https://digitalbeavertz.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       beaver-pwa
 * Domain Path:       /languages
 *
 * @package BeaverPWA
 */

defined( 'ABSPATH' ) || exit;

define( 'BEAVER_PWA_VERSION', '1.0.0' );
define( 'BEAVER_PWA_FILE', __FILE__ );
define( 'BEAVER_PWA_PATH', plugin_dir_path( __FILE__ ) );
define( 'BEAVER_PWA_URL', plugin_dir_url( __FILE__ ) );
define( 'BEAVER_PWA_BASENAME', plugin_basename( __FILE__ ) );

require_once BEAVER_PWA_PATH . 'includes/class-settings.php';
require_once BEAVER_PWA_PATH . 'includes/class-icons.php';
require_once BEAVER_PWA_PATH . 'includes/class-manifest.php';
require_once BEAVER_PWA_PATH . 'includes/class-service-worker.php';
require_once BEAVER_PWA_PATH . 'includes/class-routes.php';
require_once BEAVER_PWA_PATH . 'includes/class-frontend.php';
require_once BEAVER_PWA_PATH . 'includes/class-health.php';
require_once BEAVER_PWA_PATH . 'includes/class-admin.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once BEAVER_PWA_PATH . 'includes/class-cli.php';

	WP_CLI::add_command( 'beaver-pwa', 'Beaver_PWA_CLI' );
}

/**
 * Plugin bootstrap.
 *
 * Wires the settings, routing, front end and admin classes into WordPress and
 * owns the activation / deactivation lifecycle.
 *
 * @since 1.0.0
 */
final class Beaver_PWA_Plugin {

	/**
	 * Rewrite rules revision. Bump to force a flush on the next request.
	 */
	const RULES_VERSION = '1';

	/**
	 * Option holding the rewrite rules revision last written to the database.
	 */
	const OPTION_RULES = 'beaver_pwa_rules_version';

	/**
	 * Singleton instance.
	 *
	 * @var Beaver_PWA_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Retrieves the singleton instance.
	 *
	 * @since 1.0.0
	 *
	 * @return Beaver_PWA_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Boots the plugin.
	 *
	 * @since 1.0.0
	 */
	private function __construct() {
		add_action( 'init', array( __CLASS__, 'load_textdomain' ) );
		add_action( 'init', array( __CLASS__, 'maybe_flush_rules' ), 99 );

		Beaver_PWA_Routes::init();
		Beaver_PWA_Frontend::init();

		if ( is_admin() ) {
			Beaver_PWA_Admin::init();
		}
	}

	/**
	 * Loads translations.
	 *
	 * @since 1.0.0
	 */
	public static function load_textdomain() {
		load_plugin_textdomain( 'beaver-pwa', false, dirname( BEAVER_PWA_BASENAME ) . '/languages' );
	}

	/**
	 * Flushes rewrite rules once after an install or an upgrade.
	 *
	 * Activation alone is not enough: the rules also need rewriting when the
	 * plugin is updated in place or when another plugin resets permalinks.
	 *
	 * @since 1.0.0
	 */
	public static function maybe_flush_rules() {
		if ( self::RULES_VERSION === get_option( self::OPTION_RULES ) ) {
			return;
		}

		flush_rewrite_rules( false );
		update_option( self::OPTION_RULES, self::RULES_VERSION, true );
	}

	/**
	 * Activation handler.
	 *
	 * @since 1.0.0
	 */
	public static function activate() {
		Beaver_PWA_Settings::install_defaults();
		Beaver_PWA_Routes::register_rules();

		flush_rewrite_rules( false );
		update_option( self::OPTION_RULES, self::RULES_VERSION, true );

		Beaver_PWA_Icons::maybe_generate();

		set_transient( 'beaver_pwa_activated', 1, MINUTE_IN_SECONDS );
	}

	/**
	 * Deactivation handler.
	 *
	 * Service workers outlive the page that registered them, so the cache
	 * signature is bumped here. Any worker still installed in a visitor's
	 * browser will fail its next heartbeat and remove itself.
	 *
	 * @since 1.0.0
	 */
	public static function deactivate() {
		Beaver_PWA_Settings::bump_cache();

		delete_option( self::OPTION_RULES );
		delete_transient( Beaver_PWA_Health::TRANSIENT );

		flush_rewrite_rules( false );
	}
}

register_activation_hook( __FILE__, array( 'Beaver_PWA_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Beaver_PWA_Plugin', 'deactivate' ) );

Beaver_PWA_Plugin::instance();
