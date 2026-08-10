<?php
/**
 * Plugin Name:       Beaver Sync
 * Plugin URI:        https://digitalbeavertz.com/
 * Description:       Brings the live site's media down to a local copy over HTTPS, no SSH and no FTP. The live site only ever publishes a read-only list of what it has; the copy compares that against its own uploads folder and downloads the difference. Nothing is ever written to the live site. Find it under Tools → Beaver Sync.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Digital Beaver
 * Author URI:        https://digitalbeavertz.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       beaver-sync
 * Domain Path:       /languages
 *
 * @package BeaverSync
 */

defined( 'ABSPATH' ) || exit;

define( 'BEAVER_SYNC_VERSION', '1.0.0' );
define( 'BEAVER_SYNC_FILE', __FILE__ );
define( 'BEAVER_SYNC_PATH', plugin_dir_path( __FILE__ ) );
define( 'BEAVER_SYNC_URL', plugin_dir_url( __FILE__ ) );
define( 'BEAVER_SYNC_BASENAME', plugin_basename( __FILE__ ) );
define( 'BEAVER_SYNC_SLUG', 'beaver-sync' );

require_once BEAVER_SYNC_PATH . 'includes/class-settings.php';
require_once BEAVER_SYNC_PATH . 'includes/class-manifest.php';
require_once BEAVER_SYNC_PATH . 'includes/class-endpoint.php';
require_once BEAVER_SYNC_PATH . 'includes/class-puller.php';

if ( is_admin() ) {
	require_once BEAVER_SYNC_PATH . 'includes/class-admin.php';
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once BEAVER_SYNC_PATH . 'includes/class-cli.php';

	WP_CLI::add_command( 'beaver-sync', 'Beaver_Sync_CLI' );
}

/**
 * Plugin bootstrap.
 *
 * The same plugin runs on both machines and does opposite jobs, chosen by the
 * role setting. That is what keeps the two halves honest: one codebase, one
 * definition of what a file listing looks like.
 *
 * The shape of it is deliberate, and the deliberate part is what it refuses to
 * do:
 *
 * - **The live site is never written to.** Its only job is to answer with a
 *   list of the files it already serves publicly. There is no upload endpoint,
 *   no delete, no write of any kind, so a leaked key exposes a media inventory
 *   and nothing else. A sync tool that can push files to production is a remote
 *   code execution endpoint wearing a friendly name, and that is a much larger
 *   thing to hang on a public website than this job needs.
 * - **It carries media only.** Code belongs in the update channel, where a
 *   version number says what is installed; the database belongs to production,
 *   which is where the client writes.
 * - **It never deletes locally.** A file the copy has and the source does not
 *   is reported and left alone. Deleting on a guess is how a local library gets
 *   quietly emptied.
 * - **It only writes media.** Anything the source offers that is not a known
 *   media extension is refused, so a compromised source cannot post PHP into
 *   the copy's uploads folder.
 *
 * @since 1.0.0
 */
final class Beaver_Sync_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Beaver_Sync_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Retrieves the singleton instance.
	 *
	 * @since 1.0.0
	 *
	 * @return Beaver_Sync_Plugin
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

		Beaver_Sync_Endpoint::init();

		if ( is_admin() ) {
			add_action( 'plugins_loaded', array( 'Beaver_Sync_Admin', 'init' ) );
			add_filter( 'plugin_action_links_' . BEAVER_SYNC_BASENAME, array( $this, 'action_links' ) );
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
		load_plugin_textdomain( 'beaver-sync', false, dirname( BEAVER_SYNC_BASENAME ) . '/languages' );
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
				esc_url( admin_url( 'tools.php?page=' . BEAVER_SYNC_SLUG ) ),
				esc_html__( 'Sync', 'beaver-sync' )
			)
		);

		return $links;
	}

	/**
	 * Seeds defaults.
	 *
	 * No role is chosen on activation. Which end of the wire a site is on is
	 * the one thing that must never be guessed.
	 *
	 * @since 1.0.0
	 */
	public static function activate() {
		if ( false === get_option( Beaver_Sync_Settings::OPTION ) ) {
			add_option( Beaver_Sync_Settings::OPTION, Beaver_Sync_Settings::defaults(), '', 'no' );
		}
	}
}

Beaver_Sync_Plugin::instance();

register_activation_hook( __FILE__, array( 'Beaver_Sync_Plugin', 'activate' ) );
