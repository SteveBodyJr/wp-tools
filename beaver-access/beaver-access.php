<?php
/**
 * Plugin Name:       Beaver Access
 * Plugin URI:        https://digitalbeavertz.com/
 * Description:       Temporary login links. Give a developer or supporter time-limited access at the role you choose, without sharing a password — self-expiring, single-use by default, fully logged, and revocable in one click. Find it under Users → Access Links.
 * Version:           1.0.2
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Digital Beaver
 * Author URI:        https://digitalbeavertz.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       beaver-access
 * Domain Path:       /languages
 *
 * @package BeaverAccess
 */

defined( 'ABSPATH' ) || exit;

define( 'BEAVER_ACCESS_VERSION', '1.0.2' );
define( 'BEAVER_ACCESS_FILE', __FILE__ );
define( 'BEAVER_ACCESS_PATH', plugin_dir_path( __FILE__ ) );
define( 'BEAVER_ACCESS_URL', plugin_dir_url( __FILE__ ) );
define( 'BEAVER_ACCESS_BASENAME', plugin_basename( __FILE__ ) );

require_once BEAVER_ACCESS_PATH . 'includes/class-settings.php';
require_once BEAVER_ACCESS_PATH . 'includes/class-log.php';
require_once BEAVER_ACCESS_PATH . 'includes/class-links.php';
require_once BEAVER_ACCESS_PATH . 'includes/class-users.php';
require_once BEAVER_ACCESS_PATH . 'includes/class-session.php';

if ( is_admin() ) {
	require_once BEAVER_ACCESS_PATH . 'includes/class-admin.php';
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once BEAVER_ACCESS_PATH . 'includes/class-cli.php';

	WP_CLI::add_command( 'beaver-access', 'Beaver_Access_CLI' );
}

/*
 * The only front-end hook. It reads one query variable and returns immediately
 * when it is absent, so a site nobody is signing into pays nothing for this.
 */
Beaver_Access_Session::init();

if ( is_admin() ) {
	add_action( 'plugins_loaded', array( 'Beaver_Access_Admin', 'init' ) );
	add_filter( 'plugin_action_links_' . BEAVER_ACCESS_BASENAME, 'beaver_access_action_links' );
}

add_action( 'init', 'beaver_access_load_textdomain' );
add_action( 'beaver_access_cleanup', array( 'Beaver_Access_Links', 'cleanup' ) );

/**
 * Loads the plugin translations.
 *
 * Hooked to `init` because WordPress 6.7 warns when translations are loaded
 * any earlier.
 *
 * @since 1.0.1
 */
function beaver_access_load_textdomain() {
	load_plugin_textdomain( 'beaver-access', false, dirname( BEAVER_ACCESS_BASENAME ) . '/languages' );
}

/**
 * Adds a shortcut to this plugin's row on the Plugins screen.
 *
 * The screen belongs under Users — it is about who can sign in — but that is
 * not where anyone looks straight after activating a plugin, and there is no
 * top-level menu to catch the eye. Without this the plugin is installed,
 * working, and invisible.
 *
 * @since 1.0.1
 *
 * @param string[] $links Existing action links.
 * @return string[] Filtered action links.
 */
function beaver_access_action_links( $links ) {
	$page = admin_url( 'users.php?page=' . Beaver_Access_Admin::MENU_SLUG );

	$shortcuts = array(
		sprintf(
			'<a href="%s">%s</a>',
			esc_url( $page ),
			esc_html__( 'Access Links', 'beaver-access' )
		),
		/*
		 * Settings are a section of that same screen, not a page of their own.
		 * "Settings" is still what people look for in this row, so it is listed
		 * and jumps to the heading rather than being left out.
		 */
		sprintf(
			'<a href="%s#%s">%s</a>',
			esc_url( $page ),
			esc_attr( Beaver_Access_Admin::SETTINGS_ANCHOR ),
			esc_html__( 'Settings', 'beaver-access' )
		),
	);

	return array_merge( $shortcuts, $links );
}

/**
 * Creates the table and schedules cleanup.
 *
 * @since 1.0.0
 */
function beaver_access_activate() {
	Beaver_Access_Links::install();

	if ( false === get_option( Beaver_Access_Settings::OPTION ) ) {
		add_option( Beaver_Access_Settings::OPTION, Beaver_Access_Settings::defaults() );
	}

	if ( ! wp_next_scheduled( 'beaver_access_cleanup' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'beaver_access_cleanup' );
	}
}

/**
 * Revokes everything and stops the schedule.
 *
 * Deactivating has to end access, not merely stop issuing it. A temporary
 * account left signed in after the plugin is switched off would be access
 * nobody can see or withdraw.
 *
 * @since 1.0.0
 */
function beaver_access_deactivate() {
	Beaver_Access_Links::revoke_all();
	wp_clear_scheduled_hook( 'beaver_access_cleanup' );
}

register_activation_hook( __FILE__, 'beaver_access_activate' );
register_deactivation_hook( __FILE__, 'beaver_access_deactivate' );
