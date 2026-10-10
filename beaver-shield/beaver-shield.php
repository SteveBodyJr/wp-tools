<?php
/**
 * Plugin Name:       Beaver Shield
 * Plugin URI:        https://digitalbeavertz.com/
 * Description:       Reduces what a site tells the outside world about itself. Strips the WordPress version and generator tags, blocks plugin readme files and user enumeration, adds security headers, and can put a copy deterrent on the front end. Every switch is reversible, nothing is served differently to search engines, and a wp-config flag turns the whole thing off. Find it under Tools → Beaver Shield.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Digital Beaver
 * Author URI:        https://digitalbeavertz.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       beaver-shield
 * Domain Path:       /languages
 *
 * @package BeaverShield
 */

defined( 'ABSPATH' ) || exit;

define( 'BEAVER_SHIELD_VERSION', '1.0.0' );
define( 'BEAVER_SHIELD_FILE', __FILE__ );
define( 'BEAVER_SHIELD_PATH', plugin_dir_path( __FILE__ ) );
define( 'BEAVER_SHIELD_URL', plugin_dir_url( __FILE__ ) );
define( 'BEAVER_SHIELD_BASENAME', plugin_basename( __FILE__ ) );
define( 'BEAVER_SHIELD_SLUG', 'beaver-shield' );

require_once BEAVER_SHIELD_PATH . 'includes/class-settings.php';
require_once BEAVER_SHIELD_PATH . 'includes/class-htaccess.php';
require_once BEAVER_SHIELD_PATH . 'includes/class-fingerprint.php';
require_once BEAVER_SHIELD_PATH . 'includes/class-hardening.php';
require_once BEAVER_SHIELD_PATH . 'includes/class-headers.php';
require_once BEAVER_SHIELD_PATH . 'includes/class-login.php';
require_once BEAVER_SHIELD_PATH . 'includes/class-crawlers.php';
require_once BEAVER_SHIELD_PATH . 'includes/class-content.php';
require_once BEAVER_SHIELD_PATH . 'includes/class-scan.php';

if ( is_admin() ) {
	require_once BEAVER_SHIELD_PATH . 'includes/class-admin.php';
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once BEAVER_SHIELD_PATH . 'includes/class-cli.php';

	WP_CLI::add_command( 'beaver-shield', 'Beaver_Shield_CLI' );
}

/**
 * Plugin bootstrap.
 *
 * The boundaries are the design, and they are deliberate:
 *
 * - Everyone gets the same HTML. No user agent is sniffed, no output varies for
 *   a crawler. Serving search engines something different from visitors is
 *   cloaking, and no amount of obscurity is worth a manual action.
 * - It never claims to hide the source. Anything the browser renders, the
 *   browser downloaded, so the page is readable by definition. What this
 *   reduces is the version and component detail a scanner reads for free, which
 *   is the part that actually matters.
 * - It cannot lock you out. A `BEAVER_SHIELD_OFF` constant in wp-config.php
 *   suspends every front end behaviour, including the custom login URL, and
 *   WP-CLI can reset the same settings without a browser.
 * - It writes only inside its own marked block in .htaccess, backed up first
 *   and removed on deactivation. On nginx it writes nothing at all and shows
 *   you the config to paste.
 *
 * @since 1.0.0
 */
final class Beaver_Shield_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Beaver_Shield_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Retrieves the singleton instance.
	 *
	 * @since 1.0.0
	 *
	 * @return Beaver_Shield_Plugin
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

		if ( ! self::suspended() ) {
			Beaver_Shield_Fingerprint::init();
			Beaver_Shield_Hardening::init();
			Beaver_Shield_Headers::init();
			Beaver_Shield_Login::init();
			Beaver_Shield_Crawlers::init();
			Beaver_Shield_Content::init();
		}

		if ( is_admin() ) {
			add_action( 'plugins_loaded', array( 'Beaver_Shield_Admin', 'init' ) );
			add_filter( 'plugin_action_links_' . BEAVER_SHIELD_BASENAME, array( $this, 'action_links' ) );
		}
	}

	/**
	 * Whether the escape hatch is engaged.
	 *
	 * One line in wp-config.php stands the plugin down without touching the
	 * database or the file system. It is the way back in when a header, a
	 * policy or a renamed login URL has made a site unreachable, and it is the
	 * reason none of those settings can strand anyone.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True when BEAVER_SHIELD_OFF is set.
	 */
	public static function suspended() {
		return defined( 'BEAVER_SHIELD_OFF' ) && BEAVER_SHIELD_OFF;
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
		load_plugin_textdomain( 'beaver-shield', false, dirname( BEAVER_SHIELD_BASENAME ) . '/languages' );
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
				esc_url( admin_url( 'tools.php?page=' . BEAVER_SHIELD_SLUG ) ),
				esc_html__( 'Shield', 'beaver-shield' )
			)
		);

		return $links;
	}

	/**
	 * Seeds defaults and writes the server rules.
	 *
	 * Activation turns on only what cannot break a site: the fingerprint
	 * strippers and the uncontroversial headers. Everything with a cost —
	 * XML-RPC, a content security policy, a renamed login URL, the copy
	 * deterrent — ships off, so installing the plugin is never the thing that
	 * broke the site.
	 *
	 * Note what is deliberately absent: `flush_rewrite_rules()`. This plugin
	 * registers no rewrite rules, so it has nothing to flush, and a hard flush
	 * rewrites the "# BEGIN WordPress" block in .htaccess. Core finds that block
	 * by searching each line for the marker text, which means a hand written
	 * comment that merely quotes the marker is read as the start of the block
	 * and everything down to the real end marker is replaced. Sites with a
	 * carefully built .htaccess are exactly the sites likely to contain such a
	 * comment. Calling it here would have been a free habit with nothing to gain
	 * and someone's hardening block to lose.
	 *
	 * @since 1.0.0
	 */
	public static function activate() {
		if ( false === get_option( Beaver_Shield_Settings::OPTION ) ) {
			// Autoloaded: the front end reads these on every request, and riding
			// along in the alloptions query costs nothing.
			add_option( Beaver_Shield_Settings::OPTION, Beaver_Shield_Settings::defaults(), '', 'yes' );
		}

		Beaver_Shield_Htaccess::sync();
	}

	/**
	 * Removes the server rules.
	 *
	 * Deactivating has to leave the server exactly as it was found, because a
	 * rule left behind in .htaccess by a plugin that is no longer installed is
	 * the hardest kind of problem to trace.
	 *
	 * @since 1.0.0
	 */
	public static function deactivate() {
		Beaver_Shield_Htaccess::remove_all();
	}
}

Beaver_Shield_Plugin::instance();

register_activation_hook( __FILE__, array( 'Beaver_Shield_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Beaver_Shield_Plugin', 'deactivate' ) );
