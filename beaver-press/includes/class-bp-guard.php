<?php
/**
 * Visitor guard: who may make the engine call a paid provider.
 *
 * TranslatePress translates new text while the page is being built, for whoever opened it.
 * With the guard on (default), only these requests may send text to the provider:
 * logged-in editors and administrators, WP-Cron / WP-CLI, and Beaver Press's own
 * "Translate site" run (a short-lived signed header). Visitors and bots get the stored
 * translations, or the original text where none is stored yet, and never cost money.
 *
 * @package BeaverPress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Guard.
 */
final class BP_Guard {

	/** Header sent by the Translate-site run. */
	const HEADER = 'X-Beaver-Press-Run';

	/** Token lifetime window in seconds (the current and previous window are accepted). */
	const WINDOW = 900;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_filter( 'trp_machine_translator_is_available', array( __CLASS__, 'filter_available' ), 20, 3 );

		// The Translate-site run finishes a page in one visit: more time for its requests only.
		if ( self::is_run_request() ) {
			add_filter( 'trp_machine_translation_time_budget', static fn() => 240 );
			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- may be disabled by the host.
			}
		}
	}

	/**
	 * Whether this request is the signed Translate-site run.
	 *
	 * @return bool
	 */
	public static function is_run_request() {
		static $is = null;
		if ( null === $is ) {
			$is = self::valid_run_header();
		}
		return $is;
	}

	/**
	 * Whether the guard setting is on.
	 *
	 * @param array|null $mt TranslatePress machine translation settings.
	 * @return bool
	 */
	public static function enabled( $mt = null ) {
		$mt = is_array( $mt ) ? $mt : get_option( 'trp_machine_translation_settings', array() );
		return 'no' !== ( is_array( $mt ) ? ( $mt['bp-visitor-guard'] ?? 'yes' ) : 'yes' );
	}

	/**
	 * Whether this request may send text to the provider.
	 *
	 * @param array|null $mt TranslatePress machine translation settings.
	 * @return bool
	 */
	public static function may_translate( $mt = null ) {
		if ( ! self::enabled( $mt ) ) {
			return true;
		}
		if ( ! self::trusted_request() ) {
			return false;
		}
		// Editors browsing the site do not start paid translations (it made their page views
		// slow): only the run, WP-CLI, cron, the visual editor and actions in wp-admin do.
		if ( function_exists( 'is_user_logged_in' ) && is_user_logged_in() && ! is_admin() && ! wp_doing_ajax() && ! wp_doing_cron()
			&& ! ( defined( 'WP_CLI' ) && WP_CLI ) && ! self::valid_run_header()
			&& ! ( function_exists( 'trp_is_translation_editor' ) && trp_is_translation_editor() ) ) {
			return (bool) apply_filters( 'beaver_press_translate_while_browsing', false );
		}
		return true;
	}

	/**
	 * Editors, cron, CLI, or the signed Translate-site run.
	 *
	 * @return bool
	 */
	public static function trusted_request() {
		if ( wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return true;
		}
		if ( self::valid_run_header() ) {
			return true;
		}
		$can = function_exists( 'is_user_logged_in' ) && is_user_logged_in()
			&& ( current_user_can( 'edit_others_posts' ) || current_user_can( apply_filters( 'trp_translating_capability', 'manage_options' ) ) );
		return (bool) apply_filters( 'beaver_press_can_trigger_translation', $can );
	}

	/**
	 * TranslatePress asks whether machine translation may run for this request.
	 *
	 * @param bool  $available Availability so far.
	 * @param array $languages Languages involved.
	 * @param array $mt        TranslatePress machine translation settings.
	 * @return bool
	 */
	public static function filter_available( $available, $languages = array(), $mt = array() ) {
		if ( BP_Engine_Settings::ENGINE !== ( is_array( $mt ) ? ( $mt['translation-engine'] ?? '' ) : '' ) ) {
			return $available;
		}
		// The run translates even with "Enable Automatic Translation" switched off, so the
		// owner can keep it off and still fill the site in one go.
		if ( ! $available && self::is_run_request() && 'yes' !== ( $mt['machine-translation'] ?? '' ) ) {
			return true;
		}
		if ( ! $available ) {
			return false;
		}
		return self::may_translate( $mt );
	}

	/**
	 * Token for the Translate-site run's requests.
	 *
	 * @param int|null $window Time window (defaults to now).
	 * @return string
	 */
	public static function run_token( $window = null ) {
		$window = null === $window ? (int) floor( time() / self::WINDOW ) : (int) $window;
		// Not home_url(): TranslatePress adds the language to it (/fr) on translated pages. The salt is per site.
		return hash_hmac( 'sha256', 'beaver-press-run|' . $window . '|' . get_current_blog_id(), wp_salt( 'auth' ) );
	}

	/**
	 * Whether the request carries a current run token.
	 *
	 * @return bool
	 */
	private static function valid_run_header() {
		$key = 'HTTP_' . strtoupper( str_replace( '-', '_', self::HEADER ) );
		if ( empty( $_SERVER[ $key ] ) ) {
			return false;
		}
		$sent = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
		$now  = (int) floor( time() / self::WINDOW );
		return hash_equals( self::run_token( $now ), $sent ) || hash_equals( self::run_token( $now - 1 ), $sent );
	}
}
