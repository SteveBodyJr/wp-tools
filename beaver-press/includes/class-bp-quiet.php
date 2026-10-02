<?php
/**
 * Quiet mode: TranslatePress without the parts a client site does not need.
 *
 * Removed: the support / community chat (and its hourly forum-feed request), the review
 * request, promotional and licence notifications (the "daily quota exceeded" one is kept),
 * the AI-words notice, onboarding and opt-in pages, the Addons, TranslatePress AI and
 * Glossary tabs (their URLs go to General), "Upgrade now" and Support header links, upsell
 * boxes, the DeepL upsell and TranslatePress AI engine options (unless in use), and the
 * "Go Pro" / licence links on the Plugins screen.
 * Kept: General, Language Switcher, Automatic Translation (with Beaver Press), Advanced, the
 * visual editor and its admin-bar link, Documentation, the error and database pages.
 *
 * Only TranslatePress's own hooks, filters and component instances are used; none of its
 * files is edited. One switch on Settings -> Beaver Press turns all of it off again.
 *
 * @package BeaverPress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Quiet mode.
 */
final class BP_Quiet {

	/** Option: 'yes' (default) or 'no'. */
	const OPTION = 'beaver_press_quiet';

	/** TranslatePress pages that only sell something: redirected to General. */
	const SALES_PAGES = array( 'trp_addons_page', 'trp_ai_api_key', 'trp_machine_translation_glossary', 'trp_glossary_replace', 'trp_optin_page', 'trp_onboarding' );

	/** Notifications kept (id prefixes). */
	const KEEP_NOTIFICATIONS = array( 'trp_machine_translation_quota_exceeded' );

	/**
	 * Whether quiet mode is on.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return (bool) apply_filters( 'beaver_press_quiet', 'no' !== get_option( self::OPTION, 'yes' ) );
	}

	/**
	 * Hooks (runs on plugins_loaded 5, after TranslatePress registered its own on priority 1).
	 */
	public static function init() {
		if ( ! self::enabled() || ( ! is_admin() && ! wp_doing_cron() ) ) {
			return;
		}
		$trp = TRP_Translate_Press::get_trp_instance();
		$get = static function ( $name ) use ( $trp ) {
			try {
				return $trp->get_component( $name );
			} catch ( Throwable $e ) {
				return null;
			}
		};

		// Support / community chat (started from its own file on admin_init).
		remove_action( 'admin_init', array( 'TRP_Support_Chat', 'get_instance' ) );

		// Review request.
		$reviews = $get( 'reviews' );
		if ( $reviews ) {
			remove_action( 'admin_init', array( $reviews, 'display_review_notice' ) );
		}

		// Usage opt-in page and its redirect (already answered "no" on Beaver Press sites).
		$optin = $get( 'plugin_optin' );
		if ( $optin ) {
			remove_action( 'admin_init', array( $optin, 'redirect_to_plugin_optin_page' ), 1 );
			remove_action( 'admin_menu', array( $optin, 'add_submenu_page_optin' ) );
		}

		// Onboarding wizard and its redirect.
		$onboarding = $get( 'onboarding_setup' );
		if ( $onboarding ) {
			remove_action( 'admin_init', array( $onboarding, 'run_onboarding_admin' ) );
			remove_action( 'admin_menu', array( $onboarding, 'register_onboarding' ) );
		}

		// TranslatePress AI word-count emails.
		$ai_words = $get( 'ai_words_notification' );
		if ( $ai_words ) {
			remove_action( 'set_transient_trp_mtapi_cached_quota', array( $ai_words, 'check_quota_and_notify' ), 10 );
			remove_action( 'trp_ai_words_delayed_notification', array( $ai_words, 'send_delayed_notification_email' ) );
		}

		add_action( 'admin_init', array( __CLASS__, 'prune_notifications' ), 20 );
		add_action( 'admin_init', array( __CLASS__, 'redirect_sales_pages' ), 0 );
		add_filter( 'plugin_action_links_' . ( defined( 'TRP_PLUGIN_BASE' ) ? TRP_PLUGIN_BASE : 'translatepress-multilingual/index.php' ), array( __CLASS__, 'plugin_links' ), 20 );
		add_filter( 'trp_machine_translation_engines', array( __CLASS__, 'engines' ), 30 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'styles' ) );
	}

	/**
	 * Drop promotional and licence notifications; keep the ones on the list.
	 */
	public static function prune_notifications() {
		if ( ! class_exists( 'TRP_Plugin_Notifications' ) ) {
			return;
		}
		$keep = self::KEEP_NOTIFICATIONS;
		// Real problems stay: every notice TranslatePress's error manager logged (SQL errors,
		// automatic translation switched off) is kept by its exact id.
		$errors = get_option( 'trp_db_errors', array() );
		$ids    = array();
		foreach ( (array) ( is_array( $errors ) ? ( $errors['notifications'] ?? array() ) : array() ) as $logged ) {
			if ( ! empty( $logged['notification_id'] ) ) {
				$ids[] = (string) $logged['notification_id'];
			}
		}
		$prune = function () use ( $keep, $ids ) {
			foreach ( array_keys( (array) $this->notifications ) as $id ) {
				$kept = in_array( (string) $id, $ids, true );
				foreach ( $keep as $prefix ) {
					$kept = $kept || 0 === strpos( (string) $id, $prefix );
				}
				if ( ! $kept ) {
					unset( $this->notifications[ $id ] );
				}
			}
		};
		\Closure::bind( $prune, TRP_Plugin_Notifications::get_instance(), 'TRP_Plugin_Notifications' )();
	}

	/**
	 * Sales-only pages go to TranslatePress's General tab.
	 */
	public static function redirect_sales_pages() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- navigation only.
		if ( in_array( $page, self::SALES_PAGES, true ) && ! wp_doing_ajax() ) {
			wp_safe_redirect( admin_url( 'options-general.php?page=translate-press' ) );
			exit;
		}
	}

	/**
	 * Plugins screen: keep "Settings", drop "Go Pro" and licence links.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public static function plugin_links( $links ) {
		unset( $links['go_pro'], $links['license'] );
		return $links;
	}

	/**
	 * Engine list: no DeepL upsell; TranslatePress AI only when it is the engine in use.
	 *
	 * @param array $engines Engines.
	 * @return array
	 */
	public static function engines( $engines ) {
		$mt      = get_option( 'trp_machine_translation_settings', array() );
		$current = is_array( $mt ) ? (string) ( $mt['translation-engine'] ?? '' ) : '';
		return array_values(
			array_filter(
				(array) $engines,
				static fn( $e ) => ! in_array( $e['value'] ?? '', array( 'deepl_upsell', 'mtapi' ), true ) || ( $e['value'] ?? '' ) === $current && 'deepl_upsell' !== $current
			)
		);
	}

	/**
	 * Hide the upsell boxes and links that have no hook, on TranslatePress's screens only.
	 *
	 * @param string $hook Admin page hook.
	 */
	public static function styles( $hook ) {
		if ( 'settings_page_translate-press' !== $hook && 0 !== strpos( (string) $hook, 'admin_page_trp_' ) ) {
			return;
		}
		wp_enqueue_style( 'beaver-press-quiet', BP_URL . 'assets/css/bp-quiet.css', array(), BP_VERSION );
	}
}
