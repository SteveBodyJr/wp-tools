<?php
/**
 * Plugin Name:       Beaver Chameleon
 * Plugin URI:        https://digitalbeavertz.com/
 * Description:       A daily-mutating honeypot and a human-interaction trap for the comment and login forms — invisible to visitors, expensive for a bot to reverse-engineer, and every block is logged rather than just discarded. Find the counts and the last ten under Chameleon Shield.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Digital Beaver
 * Author URI:        https://digitalbeavertz.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       beaver-chameleon
 * Domain Path:       /languages
 *
 * @package BeaverChameleon
 */

defined( 'ABSPATH' ) || exit;

define( 'BEAVER_CHAMELEON_VERSION', '1.0.0' );
define( 'BEAVER_CHAMELEON_FILE', __FILE__ );
define( 'BEAVER_CHAMELEON_PATH', plugin_dir_path( __FILE__ ) );
define( 'BEAVER_CHAMELEON_URL', plugin_dir_url( __FILE__ ) );
define( 'BEAVER_CHAMELEON_BASENAME', plugin_basename( __FILE__ ) );
define( 'BEAVER_CHAMELEON_SLUG', 'beaver-chameleon' );

require_once BEAVER_CHAMELEON_PATH . 'includes/class-stats.php';
require_once BEAVER_CHAMELEON_PATH . 'includes/class-honeypot.php';
require_once BEAVER_CHAMELEON_PATH . 'includes/class-behavior.php';
require_once BEAVER_CHAMELEON_PATH . 'includes/class-guard.php';

if ( is_admin() ) {
	require_once BEAVER_CHAMELEON_PATH . 'includes/class-admin.php';
}

/**
 * Plugin bootstrap.
 *
 * The boundaries are deliberate:
 *
 * - Both traps render on every comment and login form, but only ever add one
 *   hidden field and a few bytes of CSS/JS each — nothing is loaded that
 *   changes what a visitor sees or how the form behaves for them.
 * - Neither trap can lock out a real visitor for longer than it takes to
 *   move a mouse, tap a screen or press a key, and neither ever runs against
 *   the REST API or XML-RPC, which authenticate through their own
 *   mechanisms and never rendered this plugin's markup in the first place.
 * - A `BEAVER_CHAMELEON_OFF` constant in wp-config.php suspends every trap at
 *   once, for the same reason Beaver Shield carries one: whatever is
 *   guarding the front door needs a way to be switched off from outside it.
 * - Nothing here writes to `.htaccess`, touches rewrite rules, or requires
 *   anything beyond two small options and one self-expiring transient.
 *
 * @since 1.0.0
 */
final class Beaver_Chameleon_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Beaver_Chameleon_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Retrieves the singleton instance.
	 *
	 * @since 1.0.0
	 *
	 * @return Beaver_Chameleon_Plugin
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
			Beaver_Chameleon_Honeypot::init();
			Beaver_Chameleon_Behavior::init();
			Beaver_Chameleon_Guard::init();
		}

		if ( is_admin() ) {
			add_action( 'plugins_loaded', array( 'Beaver_Chameleon_Admin', 'init' ) );
			add_filter( 'plugin_action_links_' . BEAVER_CHAMELEON_BASENAME, array( $this, 'action_links' ) );
		}
	}

	/**
	 * Whether the escape hatch is engaged.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True when BEAVER_CHAMELEON_OFF is set.
	 */
	public static function suspended() {
		return defined( 'BEAVER_CHAMELEON_OFF' ) && BEAVER_CHAMELEON_OFF;
	}

	/**
	 * True during a REST API or XML-RPC request.
	 *
	 * Shared by the Guard so a client authenticating through either of those
	 * — Jetpack, a mobile app, a headless front end — is never mistaken for
	 * the kind of bot these traps exist to catch: it never rendered this
	 * plugin's form fields or ran its script, so it was never going to carry
	 * either one.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public static function is_rest_or_xmlrpc() {
		return ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST );
	}

	/**
	 * True only for a standard wp-login.php form submission.
	 *
	 * Deliberately narrow: `wp_authenticate` also fires for application
	 * passwords and other programmatic authentication that never touched
	 * wp-login.php, none of which this plugin has any business examining.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public static function is_login_post() {
		return isset( $GLOBALS['pagenow'] ) && 'wp-login.php' === $GLOBALS['pagenow']
			&& isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD']
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- context check only (is this the standard login form?); no request data is read or used.
			&& isset( $_POST['wp-submit'] );
	}

	/**
	 * Loads the plugin translations.
	 *
	 * Hooked to `init` because WordPress 6.7 warns when translations are
	 * loaded any earlier.
	 *
	 * @since 1.0.0
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'beaver-chameleon', false, dirname( BEAVER_CHAMELEON_BASENAME ) . '/languages' );
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
				esc_url( admin_url( 'admin.php?page=' . BEAVER_CHAMELEON_SLUG ) ),
				esc_html__( 'Chameleon Shield', 'beaver-chameleon' )
			)
		);

		return $links;
	}

	/**
	 * Runs on activation.
	 *
	 * There is deliberately nothing to seed: both traps derive everything
	 * they need — the honeypot's field name, the behavioral nonce — on the
	 * fly, and the statistics options are created lazily by the first block
	 * rather than reserved in advance.
	 *
	 * @since 1.0.0
	 */
	public static function activate() {}

	/**
	 * Runs on deactivation.
	 *
	 * Nothing to reverse: no server files or rewrite rules are touched.
	 * Statistics are left in place so re-activating does not lose them —
	 * `uninstall.php` is where data actually goes away.
	 *
	 * @since 1.0.0
	 */
	public static function deactivate() {}
}

Beaver_Chameleon_Plugin::instance();

register_activation_hook( __FILE__, array( 'Beaver_Chameleon_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Beaver_Chameleon_Plugin', 'deactivate' ) );
