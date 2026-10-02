<?php
/**
 * Numbers look the same on every language's pages before translation.
 *
 * On a translated page WordPress runs in that language, so number_format_i18n() writes
 * "From $5 675" (French, with a non-breaking space) where the English page has "From $5,675".
 * That makes a second text per language: it has to be translated again, and TranslatePress
 * does not reliably store translations for texts with a non-breaking space, so it stayed in
 * English and was sent again on later runs. Formatting numbers as on the default-language
 * pages keeps one text per sentence; the translation still writes the number the local way.
 * Applied only on the front end of translated pages, and only when the default language is
 * English (whose separators are known here).
 *
 * @package BeaverPress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Number formatting on translated pages.
 */
final class BP_Numbers {

	/**
	 * Hooks.
	 */
	public static function init() {
		if ( is_admin() || wp_doing_ajax() ) {
			return;
		}
		add_filter( 'number_format_i18n', array( __CLASS__, 'format' ), 10, 3 );
	}

	/**
	 * English separators on translated pages when the default language is English.
	 *
	 * @param string $formatted Number as WordPress formatted it.
	 * @param float  $number    Number.
	 * @param int    $decimals  Decimals.
	 * @return string
	 */
	public static function format( $formatted, $number, $decimals ) {
		global $TRP_LANGUAGE;
		$settings = get_option( 'trp_settings', array() );
		$default  = is_array( $settings ) ? (string) ( $settings['default-language'] ?? '' ) : '';
		if ( '' === $default || 0 !== strpos( $default, 'en' ) || empty( $TRP_LANGUAGE ) || $TRP_LANGUAGE === $default ) {
			return $formatted;
		}
		return number_format( (float) $number, absint( $decimals ), '.', ',' );
	}
}
