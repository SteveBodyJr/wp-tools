<?php
/**
 * Plugin Name:       Beaver Debug
 * Plugin URI:        https://digitalbeavertz.com/
 * Description:       Records what actually goes wrong on a site you cannot SSH into — PHP fatals with context, JavaScript errors from real visitors, failed API calls, database errors and slow pages — and turns it into a report you can paste into a message.
 * Version:           1.1.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Digital Beaver
 * Author URI:        https://digitalbeavertz.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       beaver-debug
 * Domain Path:       /languages
 *
 * @package BeaverDebug
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'BEAVER_DEBUG_VERSION' ) ) {
	// Already loaded by the mu-plugin loader. Loading twice would register the
	// handlers twice and record everything in duplicate.
	return;
}

define( 'BEAVER_DEBUG_VERSION', '1.1.0' );
define( 'BEAVER_DEBUG_FILE', __FILE__ );
define( 'BEAVER_DEBUG_PATH', plugin_dir_path( __FILE__ ) );
define( 'BEAVER_DEBUG_URL', plugin_dir_url( __FILE__ ) );
define( 'BEAVER_DEBUG_BASENAME', plugin_basename( __FILE__ ) );

if ( ! defined( 'BEAVER_DEBUG_START' ) ) {
	define( 'BEAVER_DEBUG_START', microtime( true ) );
}

require_once BEAVER_DEBUG_PATH . 'includes/class-settings.php';
require_once BEAVER_DEBUG_PATH . 'includes/class-logger.php';
require_once BEAVER_DEBUG_PATH . 'includes/class-capture.php';
require_once BEAVER_DEBUG_PATH . 'includes/class-health.php';
require_once BEAVER_DEBUG_PATH . 'includes/class-frontend.php';
require_once BEAVER_DEBUG_PATH . 'includes/class-alerts.php';
require_once BEAVER_DEBUG_PATH . 'includes/class-viewer.php';
require_once BEAVER_DEBUG_PATH . 'includes/class-changes.php';
require_once BEAVER_DEBUG_PATH . 'includes/class-admin.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once BEAVER_DEBUG_PATH . 'includes/class-cli.php';

	WP_CLI::add_command( 'beaver-debug', 'Beaver_Debug_CLI' );
}

/*
 * Capture is registered immediately rather than on a hook: an error thrown
 * while another plugin is still loading happens before any hook has fired, and
 * those are exactly the errors worth catching.
 */
Beaver_Debug_Capture::init();

add_action( 'plugins_loaded', array( 'Beaver_Debug_Frontend', 'init' ) );
add_action( 'plugins_loaded', array( 'Beaver_Debug_Alerts', 'init' ) );
add_action( 'plugins_loaded', array( 'Beaver_Debug_Changes', 'init' ) );

if ( is_admin() ) {
	add_action( 'plugins_loaded', array( 'Beaver_Debug_Admin', 'init' ) );
}

add_action( 'beaver_debug_prune', array( 'Beaver_Debug_Logger', 'prune' ) );

/**
 * Seeds defaults and schedules pruning.
 *
 * @since 1.0.0
 */
function beaver_debug_activate() {
	if ( false === get_option( Beaver_Debug_Settings::OPTION ) ) {
		add_option( Beaver_Debug_Settings::OPTION, Beaver_Debug_Settings::defaults() );
	}

	if ( ! wp_next_scheduled( 'beaver_debug_prune' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'beaver_debug_prune' );
	}

	if ( ! wp_next_scheduled( 'beaver_debug_digest' ) ) {
		wp_schedule_event( time() + ( 2 * HOUR_IN_SECONDS ), 'daily', 'beaver_debug_digest' );
	}

	// Creates the token and writes the config the standalone reader needs.
	Beaver_Debug_Viewer::ensure();
}

/**
 * Stops the scheduled prune.
 *
 * @since 1.0.0
 */
function beaver_debug_deactivate() {
	wp_clear_scheduled_hook( 'beaver_debug_prune' );
	wp_clear_scheduled_hook( 'beaver_debug_digest' );

	// The reader must not outlive the plugin: an orphaned config file would
	// keep serving the log after someone believed they had turned it off.
	Beaver_Debug_Viewer::remove_config();
}

/*
 * Named functions rather than closures, and uninstall handled by uninstall.php
 * rather than register_uninstall_hook(): that function stores its callback in
 * the database, so a closure passed to it is fatal at activation time — the
 * option cannot be serialized. uninstall.php has no callback to store.
 */
register_activation_hook( __FILE__, 'beaver_debug_activate' );
register_deactivation_hook( __FILE__, 'beaver_debug_deactivate' );
