<?php
/**
 * One product in wp-admin: the translation engine's own name, logo and links are replaced by
 * Beaver Press.
 *
 * The engine's texts pass through WordPress's translation functions (text domain
 * translatepress-multilingual), so its name is replaced there: the Settings menu item reads
 * "Languages", titles and help texts read "Beaver Press". Its logo and links to its website
 * are hidden on its screens. Nothing in the engine's files is changed; its licence and
 * copyright notices stay in its code (see readme.txt).
 *
 * @package BeaverPress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Branding.
 */
final class BP_Brand {

	/** The engine's text domain. */
	const DOMAIN = 'translatepress-multilingual';

	/**
	 * Hooks.
	 */
	public static function init() {
		if ( ! apply_filters( 'beaver_press_brand', true ) ) {
			return;
		}
		add_filter( 'gettext_' . self::DOMAIN, array( __CLASS__, 'text' ), 20, 2 );
		add_filter( 'ngettext_' . self::DOMAIN, array( __CLASS__, 'text_n' ), 20, 4 );
		add_filter( 'gettext_with_context_' . self::DOMAIN, array( __CLASS__, 'text' ), 20, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'styles' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'editor_styles' ), 100 );
		add_filter( 'admin_title', array( __CLASS__, 'title' ), 20 );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 999 );
		add_action( 'current_screen', array( __CLASS__, 'buffer_admin' ) );
		add_action( 'template_redirect', array( __CLASS__, 'buffer_editor' ), 0 );
		add_filter( 'trp_editor_data', array( __CLASS__, 'editor_data' ), 20 );
		add_filter( 'trp-styles-for-editor', array( __CLASS__, 'editor_page_styles' ) );
		// The engine's "share usage data" opt-in: never offered (no redirect to its opt-in page on a
		// new site, no "Marketing optin" setting), so the bundled engine never sends data home.
		add_filter( 'pre_option_trp_plugin_optin', array( __CLASS__, 'optin_no' ) );
		add_filter( 'trp_register_advanced_settings', array( __CLASS__, 'drop_optin_setting' ), 2000 );
		// Never a "Powered by ..." line under the engine's floating language switcher.
		add_filter(
			'option_trp_settings',
			static function ( $settings ) {
				if ( is_array( $settings ) ) {
					$settings['trp-ls-show-poweredby'] = 'no';
				}
				return $settings;
			}
		);
	}

	/**
	 * The engine's data opt-in is always "no".
	 *
	 * @return string
	 */
	public static function optin_no() {
		return 'no';
	}

	/**
	 * Remove the engine's "Marketing optin" advanced setting.
	 *
	 * @param array $settings Advanced settings.
	 * @return array
	 */
	public static function drop_optin_setting( $settings ) {
		return is_array( $settings ) ? array_values(
			array_filter(
				$settings,
				static function ( $item ) {
					return ! is_array( $item ) || 'plugin_optin_setting' !== ( $item['name'] ?? '' );
				}
			)
		) : $settings;
	}

	/**
	 * Settings menu: the engine's item is called "Languages" (its label is not translatable).
	 */
	public static function menu() {
		// The engine's screens are the Languages tab of Beaver Press: no menu item of their own
		// (the pages stay registered and reachable).
		remove_submenu_page( 'options-general.php', 'translate-press' );
		add_filter( 'submenu_file', array( __CLASS__, 'current_menu' ) );
		add_filter(
			'parent_file',
			static function ( $parent ) {
				return self::is_engine_screen() ? 'options-general.php' : $parent;
			}
		);
	}

	/**
	 * On the engine's screens, Settings -> Beaver Press is the highlighted menu item.
	 *
	 * @param string|null $file Current submenu file.
	 * @return string|null
	 */
	public static function current_menu( $file ) {
		return self::is_engine_screen() ? BP_Admin::SLUG : $file;
	}

	/**
	 * Whether the current admin screen is one of the engine's.
	 *
	 * @param string $id Screen id (default: current).
	 * @return bool
	 */
	public static function is_engine_screen( $id = '' ) {
		if ( '' === $id ) {
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			$id     = $screen ? (string) $screen->id : '';
		}
		return 'settings_page_translate-press' === $id || 0 === strpos( $id, 'admin_page_trp_' ) || 0 === strpos( $id, 'settings_page_trp' );
	}

	/**
	 * The engine's screens: names written into its templates (not translatable) replaced too.
	 *
	 * @param WP_Screen $screen Screen.
	 */
	public static function buffer_admin( $screen ) {
		$id = is_object( $screen ) ? (string) $screen->id : '';
		if ( ! self::is_engine_screen( $id ) ) {
			return;
		}
		// Built now: the output handler below may not start buffers of its own.
		$head   = '<div class="wrap bp-page bp-engine-head">' . BP_Admin::header_html( 'languages' ) . '</div>';
		$credit = '<div class="wrap bp-page bp-engine-foot">' . BP_Admin::credit_html() . '</div>';
		ob_start(
			static function ( $html ) use ( $head, $credit ) {
				$html = self::replace( (string) $html );
				$html = preg_replace( '/(<div id="wpbody-content"[^>]*>)/', '$1' . $head, $html, 1 );
				$pos  = strpos( $html, '<!-- wpbody-content -->' );
				if ( false !== $pos && false === strpos( $html, 'class="bp-credit"' ) ) { // One credit per screen.
					$cut  = strrpos( substr( $html, 0, $pos ), '<div class="clear"></div>' );
					$html = false !== $cut ? substr( $html, 0, $cut ) . $credit . substr( $html, $cut ) : $html;
				}
				return $html;
			}
		);
	}

	/**
	 * The visual editor page (its title and panels are written in its template).
	 */
	public static function buffer_editor() {
		if ( function_exists( 'trp_is_translation_editor' ) && trp_is_translation_editor( 'true' ) ) {
			ob_start( array( __CLASS__, 'replace' ) );
		}
	}

	/**
	 * Replace the engine's name in a text.
	 *
	 * @param string $translation Text.
	 * @param string $text        Original text.
	 * @return string
	 */
	public static function text( $translation, $text = '' ) {
		if ( 'TranslatePress' === $text || 'TranslatePress' === $translation ) {
			return __( 'Languages', 'beaver-press' ); // Menu item and short titles.
		}
		if ( 'Translate Site' === $text ) {
			return __( 'Visual editor', 'beaver-press' ); // Its tab and admin-bar button (Beaver Press has its own "Translate site").
		}
		return self::replace( (string) $translation );
	}

	/**
	 * Plural texts.
	 *
	 * @param string $translation Text.
	 * @param string $single      Singular.
	 * @param string $plural      Plural.
	 * @param int    $number      Number.
	 * @return string
	 */
	public static function text_n( $translation, $single = '', $plural = '', $number = 0 ) {
		return self::replace( (string) $translation );
	}

	/**
	 * The replacement itself (longest names first).
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function replace( $text ) {
		if ( false === stripos( $text, 'translatepress' ) ) {
			return $text;
		}
		return str_replace(
			array( 'TranslatePress - Multilingual', 'TranslatePress Multilingual', 'TranslatePress AI', 'TranslatePress Pro', 'TranslatePress', 'Translatepress' ),
			array( 'Beaver Press', 'Beaver Press', 'Beaver Press', 'Beaver Press', 'Beaver Press', 'Beaver Press' ),
			$text
		);
	}

	/**
	 * Browser tab titles of the engine's screens.
	 *
	 * @param string $title Title.
	 * @return string
	 */
	public static function title( $title ) {
		$title = self::replace( (string) $title );
		// Without its own menu item the General screen has no title of its own.
		if ( self::is_engine_screen() && 0 === strpos( ltrim( $title ), '&lsaquo;' ) ) {
			$title = __( 'Languages', 'beaver-press' ) . ' ' . ltrim( $title );
		}
		return $title;
	}

	/**
	 * Hide the engine's logo and links to its website on its screens.
	 *
	 * @param string $hook Admin page hook.
	 */
	public static function styles( $hook ) {
		if ( 'settings_page_translate-press' !== $hook && 0 !== strpos( (string) $hook, 'admin_page_trp_' ) && 'toplevel_page_trp' !== $hook ) {
			return;
		}
		wp_register_style( 'beaver-press-brand', false, array(), BP_VERSION );
		wp_enqueue_style( 'beaver-press-brand' );
		wp_add_inline_style( 'beaver-press-brand', self::css() );
		// Beaver Press's own styles for the header, tabs and credit shown on these screens.
		wp_enqueue_style( 'beaver-press-admin', BP_URL . 'assets/css/bp-admin.css', array(), BP_VERSION );
	}

	/**
	 * Same in the visual editor (front end, editor only).
	 */
	public static function editor_styles() {
		if ( ! function_exists( 'trp_is_translation_editor' ) || ! trp_is_translation_editor() ) {
			return;
		}
		wp_register_style( 'beaver-press-brand', false, array(), BP_VERSION );
		wp_enqueue_style( 'beaver-press-brand' );
		wp_add_inline_style( 'beaver-press-brand', self::css() );
	}

	/**
	 * The editor's own page prints only the styles in this list: add the brand rules to it.
	 *
	 * @param string[] $handles Style handles.
	 * @return string[]
	 */
	public static function editor_page_styles( $handles ) {
		if ( ! wp_style_is( 'beaver-press-brand', 'registered' ) ) {
			wp_register_style( 'beaver-press-brand', false, array(), BP_VERSION );
			wp_add_inline_style( 'beaver-press-brand', self::css() );
		}
		$handles   = (array) $handles;
		$handles[] = 'beaver-press-brand';
		return $handles;
	}

	/**
	 * Visual editor: no "Extra Translation Features / Upgrade to PRO" box (its texts and link
	 * emptied here, its container hidden in css()). Nothing is unlocked: the editor still knows
	 * it runs the free engine.
	 *
	 * @param array $data Editor data.
	 * @return array
	 */
	public static function editor_data( $data ) {
		array_walk_recursive(
			$data,
			static function ( &$value, $key ) {
				if ( is_string( $key ) && 0 === strpos( $key, 'extra_upsell' ) ) {
					$value = '';
				}
			}
		);
		return $data;
	}

	/**
	 * CSS: logo images and website links hidden.
	 *
	 * @return string
	 */
	private static function css() {
		return 'img[src*="translatepress"][src*="logo"],img[src*="tp-logo"],img[src*="trp-logo"],img[alt*="TranslatePress"],'
			. '.trp-settings-header__logo,.trp-header-logo,.trp-logo,.trp-editor-logo,'
			. 'a[href*="translatepress.com"],a[href*="cozmoslabs.com"],#trp-upsell-section-container{display:none!important}'
			// The engine's own slug switch is a paid-edition feature and always locked; Beaver Press's
			// "Automatically translate slugs" (in its panel on the same screen) does this instead.
			. '.trp-settings-options-item:has(#trp-auto-translate-slugs){display:none!important}';
	}
}
