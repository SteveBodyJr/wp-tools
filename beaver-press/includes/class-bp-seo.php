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
		add_action( 'wp_head', array( __CLASS__, 'og_locale' ), 2 );
		add_filter( 'trp_translated_html', array( __CLASS__, 'tidy_html' ), 1100, 2 ); // After BP_Complete (1000), before BP_Cache stores the page.
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

	/**
	 * og:locale for link previews: the page's language plus each other published language as
	 * og:locale:alternate. The engine prints hreflang but nothing for Open Graph, so Facebook,
	 * WhatsApp and LinkedIn treated every language as the site's first one.
	 *
	 * Left out when an SEO plugin that prints its own og:locale is active. A theme that prints
	 * one itself turns this off with add_filter( 'beaver_press_og_locale', '__return_false' ).
	 *
	 * @since 3.2.0
	 */
	public static function og_locale() {
		$seo_plugin = defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || defined( 'SEOPRESS_VERSION' ) || defined( 'AIOSEO_VERSION' ) || defined( 'THE_SEO_FRAMEWORK_VERSION' );
		if ( is_admin() || is_feed() || ! apply_filters( 'beaver_press_og_locale', ! $seo_plugin ) || ! class_exists( 'TRP_Translate_Press' ) ) {
			return;
		}
		global $TRP_LANGUAGE;
		$settings  = TRP_Translate_Press::get_trp_instance()->get_component( 'settings' )->get_settings();
		$published = array_values( (array) ( $settings['publish-languages'] ?? array() ) );
		$current   = (string) ( $TRP_LANGUAGE ? $TRP_LANGUAGE : ( $settings['default-language'] ?? '' ) );
		if ( '' === $current || ! in_array( $current, $published, true ) ) {
			return;
		}
		$own = self::og_code( $current );
		if ( '' === $own ) {
			return;
		}
		echo '<meta property="og:locale" content="' . esc_attr( $own ) . '" />' . "\n";
		$seen = array( $own );
		foreach ( $published as $code ) {
			$og = self::og_code( (string) $code );
			if ( '' !== $og && ! in_array( $og, $seen, true ) ) {
				$seen[] = $og;
				echo '<meta property="og:locale:alternate" content="' . esc_attr( $og ) . '" />' . "\n";
			}
		}
	}

	/**
	 * Engine language code to Open Graph form: de_DE_formal -> de_DE, pt_PT_ao90 -> pt_PT.
	 * Facebook only knows language_COUNTRY, so a code without a country gets the usual one:
	 * "ar" -> "ar_AR", "sw" -> "sw_KE", "ja" -> "ja_JP", any other two letters "xx" -> "xx_XX".
	 * Anything unrecognised gives ''.
	 *
	 * @since 3.2.0
	 * @param string $code Engine language code.
	 * @return string
	 */
	public static function og_code( $code ) {
		if ( ! preg_match( '/^([a-z]{2,3})(_[A-Z]{2})?/', $code, $m ) ) {
			return '';
		}
		if ( ! empty( $m[2] ) || 3 === strlen( $m[1] ) ) {
			return $m[0];
		}
		$usual = array(
			'ar' => 'AR', 'bn' => 'IN', 'ca' => 'ES', 'cs' => 'CZ', 'da' => 'DK', 'el' => 'GR', 'en' => 'US', 'et' => 'EE',
			'fa' => 'IR', 'he' => 'IL', 'hi' => 'IN', 'hy' => 'AM', 'ja' => 'JP', 'ka' => 'GE', 'kk' => 'KZ', 'ko' => 'KR',
			'ms' => 'MY', 'nb' => 'NO', 'pt' => 'PT', 'sl' => 'SI', 'sq' => 'AL', 'sr' => 'RS', 'sv' => 'SE', 'sw' => 'KE',
			'ta' => 'IN', 'uk' => 'UA', 'ur' => 'PK', 'vi' => 'VN', 'zh' => 'CN',
		);
		return $m[1] . '_' . ( isset( $usual[ $m[1] ] ) ? $usual[ $m[1] ] : strtoupper( $m[1] ) );
	}

	/**
	 * Final touches on a translated page.
	 *
	 * 1. Soft hyphens (U+00AD) help long words break in visible text, but translations share
	 *    strings, so they also reached <title>, meta descriptions, JSON-LD and attributes
	 *    (alt, title, aria-label, placeholder), where search results, link previews and
	 *    screen readers receive them literally. Removed there, kept in body text.
	 * 2. Translations run longer than the original: a meta, Open Graph or Twitter description
	 *    over 160 characters ends at its last full sentence that fits, when at least 80
	 *    characters remain (otherwise it is left as written). Change the limit with the
	 *    beaver_press_meta_description_max filter; 0 turns this off. Pages in the original
	 *    language keep the owner's descriptions as written.
	 *
	 * @since 3.2.0
	 * @param string      $html     Translated page.
	 * @param string|null $language Language of the page (the engine passes it); null = translated.
	 * @return string
	 */
	public static function tidy_html( $html, $language = null ) {
		if ( ! is_string( $html ) || '' === $html ) {
			return $html;
		}
		$parts = preg_split( '#(</head>)#i', $html, 2, PREG_SPLIT_DELIM_CAPTURE );
		if ( is_array( $parts ) && 3 === count( $parts ) ) {
			$head = self::unshy( $parts[0] );
			$max  = (int) apply_filters( 'beaver_press_meta_description_max', 160 );
			if ( null !== $language && class_exists( 'TRP_Translate_Press' ) ) {
				$settings = TRP_Translate_Press::get_trp_instance()->get_component( 'settings' )->get_settings();
				if ( (string) $language === (string) ( $settings['default-language'] ?? '' ) ) {
					$max = 0; // The original language: the owner's words, not a translation.
				}
			}
			if ( $max > 0 ) {
				$head = (string) preg_replace_callback(
					'#(<meta\s+(?:name=["\'](?:description|twitter:description)["\']|property=["\']og:description["\'])\s+content=")([^"]*)(")#i',
					static function ( $m ) use ( $max ) {
						return $m[1] . self::fit_description( $m[2], $max ) . $m[3];
					},
					$head
				);
			}
			$html = $head . $parts[1] . $parts[2];
		}
		$html = (string) preg_replace_callback(
			'#<script[^>]*application/ld\+json[^>]*>.*?</script>#is',
			static function ( $m ) {
				return self::unshy( $m[0] );
			},
			$html
		);
		return (string) preg_replace_callback(
			'#\s(?:alt|title|aria-label|placeholder|content)="[^"]*"#',
			static function ( $m ) {
				return self::unshy( $m[0] );
			},
			$html
		);
	}

	/**
	 * Remove soft hyphens in every spelling (character, entities, JSON escape).
	 *
	 * @since 3.2.0
	 * @param string $text Text.
	 * @return string
	 */
	private static function unshy( $text ) {
		return str_replace( array( "\u{00AD}", '&shy;', '&#173;', '&#xAD;', '&#xad;', '\\u00ad', '\\u00AD' ), '', $text );
	}

	/**
	 * Shorten a description (attribute value, HTML-escaped) to its last full sentence within $max.
	 *
	 * @since 3.2.0
	 * @param string $text Attribute value.
	 * @param int    $max  Characters.
	 * @return string
	 */
	private static function fit_description( $text, $max ) {
		$plain = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		if ( mb_strlen( $plain ) <= $max ) {
			return $text;
		}
		$head = mb_substr( $plain, 0, $max + 1 );
		if ( preg_match_all( '/(?:[.!?\x{061F}\x{06D4}](?=\s)|\x{3002})/u', $head, $m, PREG_OFFSET_CAPTURE ) ) {
			foreach ( array_reverse( $m[0] ) as $stop ) {
				$cut = rtrim( substr( $head, 0, $stop[1] + strlen( $stop[0] ) ) );
				if ( mb_strlen( $cut ) <= $max ) { // $head is one character longer, to see the space after a stop at $max.
					return mb_strlen( $cut ) >= min( 80, (int) floor( $max / 2 ) ) ? esc_attr( $cut ) : $text;
				}
			}
		}
		return $text;
	}
}
