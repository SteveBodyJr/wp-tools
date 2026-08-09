<?php
/**
 * Plugin Name:       Beaver AI Chat
 * Plugin URI:        https://digitalbeavertz.com/
 * Description:       A customisable AI chat assistant that answers visitor questions using your own site content, captures leads, and hands off to your team. Works with Claude, ChatGPT, DeepSeek, Gemini or any OpenAI-compatible endpoint. Your API key is stored on your server and never exposed to visitors.
 * Version:           1.6.1
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Digital Beaver
 * Author URI:        https://digitalbeavertz.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       beaver-ai-chat
 * Domain Path:       /languages
 *
 * @package BeaverAIChat
 */

defined( 'ABSPATH' ) || exit;

/*
 * Stand down if another copy of this plugin is already loaded — a duplicate
 * folder, a must-use copy, or a staging leftover. Without this the second copy
 * would redefine the constants and re-register the hooks.
 *
 * The function declarations below are additionally guarded with
 * function_exists(). This return alone is not enough: PHP binds a file's
 * top-level functions when it COMPILES the file, which happens before this
 * statement runs, so an unguarded declaration would still fatal on
 * "Cannot redeclare" and take the whole site down.
 */
if ( defined( 'BAC_VERSION' ) ) {
	return;
}

define( 'BAC_VERSION', '1.6.1' );
define( 'BAC_FILE', __FILE__ );
define( 'BAC_PATH', plugin_dir_path( __FILE__ ) );
define( 'BAC_URL', plugin_dir_url( __FILE__ ) );
define( 'BAC_BASENAME', plugin_basename( __FILE__ ) );

/** Option name holding every setting. */
define( 'BAC_OPTION', 'beaver_ai_chat_settings' );

/** Post type used to store captured conversations. */
define( 'BAC_LEAD_CPT', 'bac_lead' );

/**
 * Load classes only when they are actually used. A normal page view touches
 * Settings and Widget; the provider, knowledge builder and admin screens are
 * never read from disk unless something asks for them.
 *
 * @param string $class Class name.
 */
if ( ! function_exists( 'bac_autoload' ) ) {
	function bac_autoload( $class ) {
		if ( 0 !== strpos( $class, 'BAC_' ) ) {
			return;
		}

		$file = BAC_PATH . 'includes/class-' . strtolower( str_replace( '_', '-', $class ) ) . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
}
spl_autoload_register( 'bac_autoload' );

/**
 * Boot the plugin.
 */
if ( ! function_exists( 'bac_init' ) ) {
	function bac_init() {
		load_plugin_textdomain( 'beaver-ai-chat', false, dirname( BAC_BASENAME ) . '/languages' );

		BAC_Settings::init();
		BAC_Leads::init();
		BAC_Workflow::init();
		BAC_Notify::init();
		BAC_Rest::init();
		BAC_Widget::init();

		if ( is_admin() ) {
			BAC_Admin::init();
			BAC_Import::init();
			BAC_Gaps::init();
		}
	}
}
add_action( 'plugins_loaded', 'bac_init' );

/**
 * Activation: register the lead post type once so its rewrite state is clean,
 * and seed defaults without overwriting an existing configuration.
 */
if ( ! function_exists( 'bac_activate' ) ) {
	function bac_activate() {
		BAC_Leads::register_post_type();

		if ( false === get_option( BAC_OPTION, false ) ) {
			add_option( BAC_OPTION, BAC_Settings::defaults() );
		}

		flush_rewrite_rules();
	}
}
register_activation_hook( __FILE__, 'bac_activate' );

/**
 * Deactivation: clear scheduled work and cached knowledge. Settings and leads
 * are preserved; only uninstall.php removes data.
 */
if ( ! function_exists( 'bac_deactivate' ) ) {
	function bac_deactivate() {
		// wp_unschedule_hook(), not wp_clear_scheduled_hook(): the per-lead
		// events carry the lead ID as an argument, and clearing only matches
		// events whose arguments are identical to the ones passed in.
		wp_unschedule_hook( 'bac_enrich_lead' );
		wp_unschedule_hook( 'bac_notify_lead' );
		wp_clear_scheduled_hook( 'bac_notify_digest' );
		BAC_Knowledge::flush_cache();
		flush_rewrite_rules();
	}
}
register_deactivation_hook( __FILE__, 'bac_deactivate' );

/** Settings link on the Plugins screen. */
add_filter(
	'plugin_action_links_' . BAC_BASENAME,
	function ( $links ) {
		$url = admin_url( 'admin.php?page=beaver-ai-chat' );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'beaver-ai-chat' ) . '</a>' );
		return $links;
	}
);
