<?php
/**
 * Bootstrap: wires each part into TranslatePress, or explains why it cannot.
 *
 * @package BeaverPress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Starts Beaver Press once TranslatePress has loaded.
 */
final class BP_Plugin {

	/**
	 * Runs on plugins_loaded (priority 5, after TranslatePress's priority 1).
	 */
	public static function boot() {
		if ( ! class_exists( 'TRP_Translate_Press' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'notice_missing_core' ) );
			return;
		}
		if ( ! defined( 'BP_ENGINE_BUNDLED' ) && is_readable( BP_ENGINE_DIR . 'index.php' ) ) {
			// The separate plugin is still active: it runs, the bundled copy waits.
			add_action( 'admin_notices', array( __CLASS__, 'notice_separate_engine' ) );
		}

		BP_Brand::init();
		BP_Roles::init();
		BP_Languages::init();
		BP_Guard::init();
		BP_Glossary::init();
		BP_Profiles::init();
		BP_Seo::init();
		BP_Schema::init();
		BP_Complete::init();
		BP_Instructions::init();
		BP_Slugs::init();
		BP_Slug_Bases::init();
		BP_Sitemap::init();
		BP_Sitemap_Standalone::init();
		BP_Transfer::init();
		BP_Forms::init();
		BP_Cache::init();
		BP_Prefetch::init();
		BP_Suggest::init();
		BP_Quiet::init();
		BP_Numbers::init();
		BP_Run::init();
		BP_Auto::init();
		BP_Topup::init();
		BP_Names_AI::init();
		BP_Alerts::init();
		BP_Budget::init();
		BP_History::init();
		BP_Backup::init();
		BP_Provenance::init();
		BP_Debug::init();
		if ( is_admin() ) {
			BP_Admin::init();
		}
	}

	/**
	 * TranslatePress is not active: Beaver Press has nothing to plug into.
	 */
	public static function notice_missing_core() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>';
		echo esc_html__( 'Beaver Press: its translation engine (the engine folder) is missing. Reinstall Beaver Press.', 'beaver-press' );
		echo '</p></div>';
	}

	/**
	 * The separate TranslatePress plugin is still active next to the one inside Beaver Press.
	 */
	public static function notice_separate_engine() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-info"><p>';
		echo esc_html__( 'Beaver Press now includes its translation engine. Deactivate the separate TranslatePress plugin on the Plugins screen: your languages, translations and settings stay exactly as they are.', 'beaver-press' );
		echo ' <a href="' . esc_url( admin_url( 'plugins.php' ) ) . '">' . esc_html__( 'Plugins', 'beaver-press' ) . '</a></p></div>';
	}
}
