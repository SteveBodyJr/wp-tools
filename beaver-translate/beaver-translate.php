<?php
/**
 * Plugin Name:       Beaver Translate
 * Plugin URI:        https://digitalbeavertz.com/
 * Description:       AI-drafted translations for posts and pages, reviewed by a human before anything goes live — nothing is ever auto-published. Each language gets its own address (example.com/fr/…), with the site's default language left exactly where it already was. Works with Claude, ChatGPT, DeepSeek, Gemini or any OpenAI-compatible endpoint.
 * Version:           1.5.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Digital Beaver
 * Author URI:        https://digitalbeavertz.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       beaver-translate
 * Domain Path:       /languages
 *
 * @package BeaverTranslate
 */

defined( 'ABSPATH' ) || exit;

define( 'BEAVER_TRANSLATE_VERSION', '1.5.0' );
define( 'BEAVER_TRANSLATE_FILE', __FILE__ );
define( 'BEAVER_TRANSLATE_PATH', plugin_dir_path( __FILE__ ) );
define( 'BEAVER_TRANSLATE_URL', plugin_dir_url( __FILE__ ) );
define( 'BEAVER_TRANSLATE_BASENAME', plugin_basename( __FILE__ ) );

require_once BEAVER_TRANSLATE_PATH . 'includes/class-settings.php';
require_once BEAVER_TRANSLATE_PATH . 'includes/class-languages.php';
require_once BEAVER_TRANSLATE_PATH . 'includes/class-links.php';
require_once BEAVER_TRANSLATE_PATH . 'includes/class-provider.php';
require_once BEAVER_TRANSLATE_PATH . 'includes/class-translator.php';
require_once BEAVER_TRANSLATE_PATH . 'includes/class-metabox.php';
require_once BEAVER_TRANSLATE_PATH . 'includes/class-router.php';
require_once BEAVER_TRANSLATE_PATH . 'includes/class-terms.php';
require_once BEAVER_TRANSLATE_PATH . 'includes/class-permalinks.php';
require_once BEAVER_TRANSLATE_PATH . 'includes/class-switcher.php';
require_once BEAVER_TRANSLATE_PATH . 'includes/class-seo-bridge.php';

if ( is_admin() ) {
	require_once BEAVER_TRANSLATE_PATH . 'includes/class-admin.php';
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once BEAVER_TRANSLATE_PATH . 'includes/class-cli.php';

	WP_CLI::add_command( 'beaver-translate', 'Beaver_Translate_CLI' );
}

/**
 * Plugin bootstrap.
 *
 * Wires every class into WordPress and owns the activation, deactivation
 * and uninstall lifecycle.
 *
 * @since 1.0.0
 */
final class Beaver_Translate_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Beaver_Translate_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Retrieves the singleton instance.
	 *
	 * @since 1.0.0
	 *
	 * @return Beaver_Translate_Plugin
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
		add_action( 'plugins_loaded', array( $this, 'maybe_upgrade_db' ) );

		add_action( 'plugins_loaded', array( 'Beaver_Translate_Metabox', 'init' ) );
		add_action( 'plugins_loaded', array( 'Beaver_Translate_Router', 'init' ) );
		add_action( 'plugins_loaded', array( 'Beaver_Translate_Terms', 'init' ) );
		add_action( 'plugins_loaded', array( 'Beaver_Translate_Permalinks', 'init' ) );
		add_action( 'plugins_loaded', array( 'Beaver_Translate_Switcher', 'init' ) );
		add_action( 'plugins_loaded', array( 'Beaver_Translate_SEO_Bridge', 'init' ) );

		add_action( 'delete_post', array( $this, 'on_delete_post' ) );
		add_action( 'transition_post_status', array( 'Beaver_Translate_Translator', 'sync_status_on_publish' ), 10, 3 );

		if ( is_admin() ) {
			add_action( 'plugins_loaded', array( 'Beaver_Translate_Admin', 'init' ) );
			add_filter( 'plugin_action_links_' . BEAVER_TRANSLATE_BASENAME, array( $this, 'action_links' ) );
		}
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
		load_plugin_textdomain( 'beaver-translate', false, dirname( BEAVER_TRANSLATE_BASENAME ) . '/languages' );
	}

	/**
	 * Brings the links table up to date on a site that activated an older
	 * version of this plugin.
	 *
	 * register_activation_hook() only fires when a plugin transitions from
	 * inactive to active — a site that already has this plugin running and
	 * simply updates the files never sees it, so a schema change added after
	 * the fact needs its own check. dbDelta() is safe to run against a table
	 * that already exists; it only adds or alters what's actually missing.
	 *
	 * @since 1.3.2
	 */
	public function maybe_upgrade_db() {
		if ( get_option( 'beaver_translate_db_version' ) === Beaver_Translate_Links::DB_VERSION ) {
			return;
		}

		Beaver_Translate_Links::install();
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
			esc_url( admin_url( 'admin.php?page=' . Beaver_Translate_Admin::SETTINGS_SLUG ) ),
			esc_html__( 'Settings', 'beaver-translate' )
		);

		array_unshift( $links, $settings );

		return $links;
	}

	/**
	 * Keeps the link table honest when either side of a translation is deleted.
	 *
	 * A source post being permanently deleted does not delete its
	 * translations — only their link record, so a French translation is
	 * never taken down just because the English original was removed. A
	 * translated draft being deleted (including emptying the trash) drops
	 * its own link row so the source post's panel correctly offers
	 * "Generate" again instead of pointing at a post that no longer exists.
	 *
	 * @since 1.0.0
	 *
	 * @param int $post_id Post being permanently deleted.
	 */
	public function on_delete_post( $post_id ) {
		Beaver_Translate_Links::delete_by_source_id( $post_id );
		Beaver_Translate_Links::delete_by_translated_id( $post_id );
		Beaver_Translate_Links::clear_pending_by_post_id( $post_id );
	}

	/**
	 * Runs on activation: creates the link table, seeds defaults, and makes
	 * the new language-prefixed URLs resolve immediately.
	 *
	 * @since 1.0.0
	 */
	public static function activate() {
		Beaver_Translate_Links::install();

		if ( false === get_option( Beaver_Translate_Settings::OPTION ) ) {
			add_option( Beaver_Translate_Settings::OPTION, Beaver_Translate_Settings::defaults() );
		}

		// Beaver_Translate_Router::prefix_rules() is only registered on the
		// `rewrite_rules_array` filter here — it has to actually run once,
		// against this request's own filter registration, before the flush
		// below caches anything, or activation would flush the *unprefixed*
		// rules and every language URL would 404 until something else
		// happened to trigger a second flush.
		Beaver_Translate_Router::init();
		flush_rewrite_rules();
	}

	/**
	 * Runs on deactivation: drops the language-prefixed URLs.
	 *
	 * Languages, settings, the link table and every draft or published
	 * translation are left exactly as they were — deactivating, often done
	 * briefly to rule out a conflict, should never cost rebuilding any of
	 * that.
	 *
	 * @since 1.0.0
	 */
	public static function deactivate() {
		flush_rewrite_rules();
	}

	/**
	 * Runs on uninstall: removes everything this plugin created.
	 *
	 * Translated posts themselves are left alone, the same way Beaver Image
	 * Optimizer leaves converted files alone — they are real content a site
	 * owner may still want, not scratch data this plugin owns outright.
	 *
	 * @since 1.0.0
	 */
	public static function uninstall() {
		global $wpdb;

		// Belt and suspenders: WordPress only offers Delete on an already
		// deactivated plugin, so deactivate() above has normally already
		// flushed this. Cheap to repeat, and it means uninstall never
		// depends on deactivate having run first.
		flush_rewrite_rules();

		delete_option( Beaver_Translate_Settings::OPTION );
		delete_option( 'beaver_translate_db_version' );
		delete_option( Beaver_Translate_Provider::ERROR_OPTION );

		$table = Beaver_Translate_Links::table();
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ( %s, %s )",
				Beaver_Translate_Translator::META_SOURCE_ID,
				Beaver_Translate_Translator::META_LANGUAGE
			)
		);

		// Term name translations, one dynamically-suffixed key per language
		// (_beaver_translate_term_name_fr, _beaver_translate_term_name_de,
		// ...) — a LIKE match rather than a fixed IN() list, since the exact
		// set of keys depends on which languages this site ever configured.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->termmeta} WHERE meta_key LIKE %s",
				$wpdb->esc_like( Beaver_Translate_Terms::META_PREFIX ) . '%'
			)
		);
	}
}

register_activation_hook( __FILE__, array( 'Beaver_Translate_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Beaver_Translate_Plugin', 'deactivate' ) );
register_uninstall_hook( __FILE__, array( 'Beaver_Translate_Plugin', 'uninstall' ) );

Beaver_Translate_Plugin::instance();
