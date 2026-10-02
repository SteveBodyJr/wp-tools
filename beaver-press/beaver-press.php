<?php
/**
 * Plugin Name:       Beaver Press
 * Plugin URI:        https://digitalbeavertz.com/
 * Description:       Translate the whole website into other languages with Claude, ChatGPT, DeepSeek, Gemini, DeepL or any OpenAI-compatible model: language switcher, visual editor, translated addresses, sitemaps and forms. API keys stay encrypted in wp-admin.
 * Version:           2.6.2
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            Digital Beaver
 * Author URI:        https://digitalbeavertz.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       beaver-press
 *
 * @package BeaverPress
 */

defined( 'ABSPATH' ) || exit;

define( 'BP_VERSION', '2.6.2' );
define( 'BP_FILE', __FILE__ );
define( 'BP_PATH', plugin_dir_path( __FILE__ ) );
define( 'BP_URL', plugin_dir_url( __FILE__ ) );

/*
 * The translation engine ships inside Beaver Press (engine/: TranslatePress free, GPL, from
 * wordpress.org, unchanged; see readme.txt). It is loaded unless the separate TranslatePress
 * plugin is still active on this site, so a site moves over by deactivating that plugin: the
 * translations stay where they are (same tables and options).
 */
define( 'BP_ENGINE_DIR', BP_PATH . 'engine/' );

/**
 * Whether the separate TranslatePress plugin is active.
 *
 * @return bool
 */
function bp_separate_engine_active() {
	$active = (array) get_option( 'active_plugins', array() );
	if ( is_multisite() ) {
		$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
	}
	return in_array( 'translatepress-multilingual/index.php', $active, true );
}

if ( is_readable( BP_ENGINE_DIR . 'index.php' ) && ! bp_separate_engine_active() && ! class_exists( 'TRP_Translate_Press' ) ) {
	// The engine finds its edition from its plugin folder name; bundled here it would guess a
	// paid edition (and show licence notices). It is the free edition: say so.
	if ( ! defined( 'TRANSLATE_PRESS' ) ) {
		define( 'TRANSLATE_PRESS', 'TranslatePress' );
	}
	require_once BP_ENGINE_DIR . 'index.php';
	define( 'BP_ENGINE_BUNDLED', true );
	// The engine looks for its translation files relative to the plugins folder.
	add_action(
		'init',
		static function () {
			$locale = determine_locale();
			if ( 'en_US' !== $locale ) {
				load_textdomain( 'translatepress-multilingual', BP_ENGINE_DIR . 'languages/translatepress-multilingual-' . $locale . '.mo' );
			}
		},
		1
	);
}

require_once BP_PATH . 'includes/class-bp-brand.php';
require_once BP_PATH . 'includes/class-bp-keys.php';
require_once BP_PATH . 'includes/class-bp-providers.php';
require_once BP_PATH . 'includes/class-bp-batch.php';
require_once BP_PATH . 'includes/class-bp-profiles.php';
require_once BP_PATH . 'includes/class-bp-guard.php';
require_once BP_PATH . 'includes/class-bp-glossary.php';
require_once BP_PATH . 'includes/class-bp-seo.php';
require_once BP_PATH . 'includes/class-bp-schema.php';
require_once BP_PATH . 'includes/class-bp-roles.php';
require_once BP_PATH . 'includes/class-bp-cache.php';
require_once BP_PATH . 'includes/class-bp-prefetch.php';
require_once BP_PATH . 'includes/class-bp-suggest.php';
require_once BP_PATH . 'includes/class-bp-complete.php';
require_once BP_PATH . 'includes/class-bp-instructions.php';
require_once BP_PATH . 'includes/class-bp-slugs.php';
require_once BP_PATH . 'includes/class-bp-slug-bases.php';
require_once BP_PATH . 'includes/class-bp-sitemap.php';
require_once BP_PATH . 'includes/class-bp-sitemap-standalone.php';
require_once BP_PATH . 'includes/class-bp-transfer.php';
require_once BP_PATH . 'includes/class-bp-forms.php';
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once BP_PATH . 'includes/class-bp-cli.php';
	WP_CLI::add_command( 'beaver-press', 'BP_CLI' );
}
require_once BP_PATH . 'includes/class-bp-quiet.php';
require_once BP_PATH . 'includes/class-bp-numbers.php';
require_once BP_PATH . 'includes/class-bp-usage.php';
require_once BP_PATH . 'includes/class-bp-run.php';
require_once BP_PATH . 'includes/class-bp-auto.php';
require_once BP_PATH . 'includes/class-bp-topup.php';
require_once BP_PATH . 'includes/class-bp-names-ai.php';
require_once BP_PATH . 'includes/class-bp-alerts.php';
require_once BP_PATH . 'includes/class-bp-budget.php';
require_once BP_PATH . 'includes/class-bp-history.php';
require_once BP_PATH . 'includes/class-bp-backup.php';
require_once BP_PATH . 'includes/class-bp-provenance.php';
require_once BP_PATH . 'includes/class-bp-debug.php';
require_once BP_PATH . 'includes/class-bp-admin.php';
require_once BP_PATH . 'includes/class-bp-languages.php';
require_once BP_PATH . 'includes/class-bp-engine-settings.php';
require_once BP_PATH . 'includes/class-bp-plugin.php';

// TranslatePress picks its engine on plugins_loaded priority 2: register before that.
BP_Engine_Settings::register();

// Everything else starts once TranslatePress exists (it loads on plugins_loaded priority 1).
add_action( 'plugins_loaded', array( 'BP_Plugin', 'boot' ), 5 );
