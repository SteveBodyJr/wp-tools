<?php
/**
 * Page title and meta tags on translated pages.
 *
 * TranslatePress (free) skips the text of <title> and has no rule for meta tags, so search
 * results and link previews stayed in the original language. Two node accessors add them to
 * TranslatePress's own pipeline (collected on the page, translated once by the engine,
 * stored, reviewable like any other text):
 * - the `content` of the text meta tags (description, Open Graph, Twitter);
 * - the text inside <title> (also SVG <title> tooltips).
 * Canonical and og:url are already language-specific; image meta tags are left to
 * TranslatePress's media handling.
 *
 * @package BeaverPress
 */

defined( 'ABSPATH' ) || exit;

/**
 * SEO accessors.
 */
final class BP_Seo {

	/** Meta tags whose `content` is text for people. */
	const META_SELECTOR = 'meta[name="description"],meta[property="og:title"],meta[property="og:description"],meta[property="og:site_name"],meta[property="og:image:alt"],meta[name="twitter:title"],meta[name="twitter:description"],meta[name="twitter:image:alt"]';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'xdefault_default' ), 20 ); // Also after an automatic update, before anyone opens wp-admin.
		add_filter( 'trp_node_accessors', array( __CLASS__, 'accessors' ) );
	}

	/**
	 * Add the title and meta accessors.
	 *
	 * @param array $accessors TranslatePress node accessors.
	 * @return array
	 */
	public static function accessors( $accessors ) {
		$accessors['bp_meta_text'] = array(
			'selector'  => (string) apply_filters( 'beaver_press_meta_selector', self::META_SELECTOR ),
			'accessor'  => 'content',
			'attribute' => true,
		);
		// Image alt text: the engine (free) has no rule for it. Translated and stored like any
		// text; not counted for a page's completeness (BP_Complete::note), so adding it never
		// sends a page back to the original language.
		$accessors['bp_image_alt'] = array(
			'selector'  => 'img[alt]',
			'accessor'  => 'alt',
			'attribute' => true,
		);
		$accessors['bp_page_title'] = array(
			'selector'  => 'title',
			'accessor'  => 'innertext',
			'attribute' => false,
		);
		return $accessors;
	}

	/**
	 * hreflang x-default on by default, pointing to the original language (once; the owner can
	 * change or turn it off in the engine's advanced settings afterwards).
	 */
	public static function xdefault_default() {
		if ( get_option( 'beaver_press_xdefault_set' ) ) {
			return;
		}
		$adv = get_option( 'trp_advanced_settings', array() );
		$adv = is_array( $adv ) ? $adv : array();
		if ( empty( $adv['enable_hreflang_xdefault'] ) ) {
			$settings                        = TRP_Translate_Press::get_trp_instance()->get_component( 'settings' )->get_settings();
			$adv['enable_hreflang_xdefault'] = (string) $settings['default-language'];
			update_option( 'trp_advanced_settings', $adv );
		}
		update_option( 'beaver_press_xdefault_set', 1, true ); // Autoloaded: read on every request, no extra query.
	}
}
