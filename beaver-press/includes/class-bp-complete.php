<?php
/**
 * Show a translated page only when it is complete.
 *
 * TranslatePress shows whatever is translated and leaves the rest in the original language,
 * so a page half-way through translation is a mix of two languages. With this on, a visitor
 * on a translated address whose page still has texts without a translation gets the original
 * page (clean, marked noindex, with a short "not available in Français yet" note in that
 * language) instead of the mix. Editors and the Translate-site run still get the translated
 * page, so they can see and finish it. Each render records whether the page was complete; that
 * record drops incomplete versions from the hreflang links, the suggestion bar and the
 * switcher prefetch, and gives the admin page its per-language count.
 *
 * @package BeaverPress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Completeness of translated pages.
 */
final class BP_Complete {

	/** Option: 'yes' (default) or 'no'. */
	const OPTION = 'beaver_press_complete_only';

	/**
	 * Record rows: one option per page and language, "bp_pg_<language>_<md5 of path>" = texts
	 * still missing (0 = complete), not autoloaded. One row each, so the run and visitors
	 * writing at the same time never overwrite each other's results.
	 */
	const PREFIX = 'bp_pg_';

	/**
	 * Which texts are missing: "bp_pt_<language>_<md5 of path>" = up to TEXTS_KEPT texts,
	 * present only while the page is incomplete (Pages tab, so the owner sees what is left).
	 */
	const TEXTS_PREFIX = 'bp_pt_';

	/**
	 * Pages the site marks noindex: "bp_ni_<language>_<md5 of path>" = 1, present only while the
	 * page (as the theme or SEO plugin prints it) says noindex. Language sitemaps leave them out.
	 */
	const NOINDEX_PREFIX = 'bp_ni_';

	/** Missing texts kept per page and language. */
	const TEXTS_KEPT = 5;

	/** Days on which each missing text was seen: md5(text) => [ text, [ days ] ] (last 300). */
	const SEEN_OPTION = 'beaver_press_missing_seen';

	/** A missing text seen on this many different days "keeps coming back". */
	const RECURRING_DAYS = 3;

	/** Earlier format (1 complete / 0 incomplete), removed on first load. */
	const OLD_MAP_OPTION = 'beaver_press_complete_map';

	/** "Not available yet" note per language (%s = language name). */
	const NOTES = array(
		'en' => 'This page is not available in %s yet.',
		'fr' => 'Cette page n’est pas encore disponible en %s.',
		'es' => 'Esta página aún no está disponible en %s.',
		'de' => 'Diese Seite ist noch nicht auf %s verfügbar.',
		'da' => 'Denne side findes endnu ikke på %s.',
		'sv' => 'Den här sidan finns inte på %s ännu.',
		'nb' => 'Denne siden finnes ikke på %s ennå.',
		'nn' => 'Denne sida finst ikkje på %s enno.',
		'it' => 'Questa pagina non è ancora disponibile in %s.',
		'pt' => 'Esta página ainda não está disponível em %s.',
		'nl' => 'Deze pagina is nog niet beschikbaar in het %s.',
		'fi' => 'Tämä sivu ei ole vielä saatavilla kielellä %s.',
		'pl' => 'Ta strona nie jest jeszcze dostępna w języku: %s.',
		'cs' => 'Tato stránka zatím není k dispozici v jazyce %s.',
		'ru' => 'Эта страница пока недоступна на языке: %s.',
		'tr' => 'Bu sayfa henüz %s dilinde mevcut değil.',
		'sw' => 'Ukurasa huu bado haupatikani kwa %s.',
		'ar' => 'هذه الصفحة غير متوفرة بعد باللغة %s.',
		'zh' => '此页面尚无%s版本。',
		'ja' => 'このページはまだ%sでご覧いただけません。',
		'ko' => '이 페이지는 아직 %s(으)로 제공되지 않습니다.',
	);

	/** Texts on this request's page still without a translation. */
	private static $missing = 0;

	/** The first few of them. */
	private static $missing_texts = array();

	/** Whether TranslatePress translated a page in this request. */
	private static $seen = false;

	/** The page before translation (outermost call only). */
	private static $original = null;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'trp_translateable_information', array( __CLASS__, 'note' ), 10, 2 );
		add_filter( 'trp_before_translate_content', array( __CLASS__, 'keep_original' ), 0 );
		add_filter( 'trp_translated_html', array( __CLASS__, 'finish' ), 1000, 4 );
		add_action( 'init', array( __CLASS__, 'hreflang_hooks' ), 20 );
		add_action( 'update_option_trp_settings', array( __CLASS__, 'languages_changed' ), 10, 2 );
		add_action( 'switch_theme', array( __CLASS__, 'theme_switched' ) );
		foreach ( array( self::OLD_MAP_OPTION, 'beaver_press_pages' ) as $old ) {
			if ( false !== get_option( $old ) ) {
				delete_option( $old ); // Earlier formats; rebuilt as pages are visited.
			}
		}
	}

	/**
	 * Whether "only complete pages" is on.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return (bool) apply_filters( 'beaver_press_complete_only', 'no' !== get_option( self::OPTION, 'yes' ) );
	}

	/**
	 * Whether a text is one a translation is expected for (emails, links and texts without
	 * words are shown as they are).
	 *
	 * @param string $text Text.
	 * @return bool
	 */
	public static function needs_translation( $text ) {
		$text = trim( wp_strip_all_tags( html_entity_decode( (string) $text, ENT_QUOTES, 'UTF-8' ) ) );
		return '' !== $text
			&& (bool) preg_match( '/\p{L}{2}/u', $text )
			&& false === filter_var( $text, FILTER_VALIDATE_EMAIL )
			&& false === filter_var( $text, FILTER_VALIDATE_URL );
	}

	/**
	 * TranslatePress reports the texts of the page and their translations.
	 *
	 * @param array $info       Translatable strings and nodes.
	 * @param array $translated Translations by index.
	 */
	public static function note( $info, $translated ) {
		self::$seen = true;
		$strings    = is_array( $info ) && isset( $info['translateable_strings'] ) ? (array) $info['translateable_strings'] : array();
		$nodes      = is_array( $info ) && isset( $info['nodes'] ) ? (array) $info['nodes'] : array();
		foreach ( $strings as $i => $text ) {
			if ( 'bp_image_alt' === ( $nodes[ $i ]['type'] ?? '' ) ) {
				continue; // Alt text is translated but does not decide whether a page is complete.
			}
			if ( ( ! isset( $translated[ $i ] ) || '' === trim( (string) $translated[ $i ] ) ) && self::needs_translation( $text ) ) {
				self::$missing++;
				$plain = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( html_entity_decode( (string) $text, ENT_QUOTES, 'UTF-8' ) ) ) );
				if ( count( self::$missing_texts ) < self::TEXTS_KEPT && ! in_array( $plain, self::$missing_texts, true ) ) {
					self::$missing_texts[] = mb_substr( $plain, 0, 160 );
				}
			}
		}
	}

	/**
	 * The page as it was before translation in this request ('' when none).
	 *
	 * @return string
	 */
	public static function original_html() {
		return (string) self::$original;
	}

	/**
	 * Whether the page translated in this request is complete.
	 *
	 * @return bool
	 */
	public static function page_complete() {
		return self::$seen && 0 === self::$missing;
	}

	/**
	 * Keep the page as it was before translation (only the outermost call is the page).
	 *
	 * @param string $output HTML.
	 * @return string
	 */
	public static function keep_original( $output ) {
		if ( null === self::$original && is_string( $output ) && false !== stripos( $output, '</html>' ) ) {
			self::$original = $output;
		}
		return $output;
	}

	/**
	 * Record completeness; give visitors the original page when it is not complete.
	 *
	 * @param string $html     Translated HTML.
	 * @param string $language Language.
	 * @param string $code     Language code.
	 * @param bool   $preview  Translation editor preview.
	 * @return string
	 */
	public static function finish( $html, $language = '', $code = '', $preview = false ) {
		global $TRP_LANGUAGE;
		if ( $preview || ! self::$seen || ! is_string( $html ) || false === stripos( $html, '</html>' ) || ! self::is_page_response() ) {
			return $html;
		}
		// A form result (?att_status=...): always the visitor's language, and not recorded (its
		// messages are extra texts that say nothing about the page itself).
		if ( class_exists( 'BP_Forms' ) && BP_Forms::is_result_page() ) {
			return $html;
		}
		$key    = self::original_url();
		self::remember_noindex( (string) $TRP_LANGUAGE, $key, self::says_noindex( $html ) );
		$before = self::map()[ (string) $TRP_LANGUAGE ][ $key ] ?? null;
		self::remember( (string) $TRP_LANGUAGE, $key, self::$missing );
		// Unknown pages count as available, so unknown -> incomplete is a change too.
		if ( ( null === $before || 0 === $before ) !== ( 0 === self::$missing ) && class_exists( 'BP_Cache' ) ) {
			// Became complete or stopped being complete: the language links of this page's ready
			// copies in the other languages list it or must stop listing it.
			BP_Cache::forget_page( self::original_address() );
		}
		if ( 0 === self::$missing || ! self::enabled() || null === self::$original || ! self::for_visitor() ) {
			return $html;
		}
		return self::original_page( (string) $TRP_LANGUAGE );
	}

	/**
	 * A normal page view (200, not a search, feed or preview).
	 *
	 * @return bool
	 */
	private static function is_page_response() {
		return 200 === (int) http_response_code() && ! wp_doing_ajax() && ! ( function_exists( 'is_404' ) && ( is_404() || is_search() || is_feed() || is_preview() ) );
	}

	/**
	 * Whether this request is a visitor's (not an editor, translator or the Translate-site run).
	 *
	 * @return bool
	 */
	private static function for_visitor() {
		return ! BP_Guard::trusted_request() && ! ( function_exists( 'trp_is_translation_editor' ) && trp_is_translation_editor() );
	}

	/**
	 * The original page, cleaned of TranslatePress's markers, marked noindex, with the note.
	 *
	 * @param string $language Requested language.
	 * @return string
	 */
	private static function original_page( $language ) {
		$trp      = TRP_Translate_Press::get_trp_instance();
		$settings = $trp->get_component( 'settings' )->get_settings();
		$html     = $trp->get_component( 'translation_render' )->remove_trp_html_tags( self::$original );
		// The engine strips its link marker while translating; this page skips that step.
		$html = str_replace( '#TRPLINKPROCESSED', '', $html );
		$default  = str_replace( '_', '-', (string) $settings['default-language'] );

		// The content is in the original language: say so to browsers and screen readers.
		$html = preg_replace( '/(<html\b[^>]*\blang=")[^"]*(")/i', '${1}' . esc_attr( $default ) . '${2}', $html, 1 );
		// Not the translated page: keep it out of search results, point search engines to the
		// original (this page is a copy of it) and leave the language links to the real versions.
		$original = strtok( self::original_address(), '?#' ); // A canonical never carries a query string.
		$html     = preg_replace( '/(<link\s+rel=["\']canonical["\']\s+href=["\'])[^"\']*/i', '${1}' . esc_url( $original ), $html );
		$html     = preg_replace( '/(<meta\s+property=["\']og:url["\']\s+content=["\'])[^"\']*/i', '${1}' . esc_url( $original ), $html );
		$html     = preg_replace( '/<link\s+rel=["\']alternate["\']\s+hreflang=["\'][^"\']*["\'][^>]*>\s*/i', '', $html );
		$html = preg_replace( '/<meta\s+name=["\']robots["\'][^>]*>/i', '', $html );
		$html = preg_replace( '/<head\b[^>]*>/i', '$0' . "\n" . '<meta name="robots" content="noindex, follow" />', $html, 1 );

		$note = self::note_html( $language );
		$pos  = strripos( $html, '</body>' );
		if ( false !== $pos && '' !== $note ) {
			$html = substr( $html, 0, $pos ) . $note . substr( $html, $pos );
		}
		return (string) apply_filters( 'beaver_press_original_page', $html, $language );
	}

	/**
	 * "Not available in Français yet" note, written in the requested language.
	 *
	 * @param string $language Language code.
	 * @return string
	 */
	private static function note_html( $language ) {
		$trp   = TRP_Translate_Press::get_trp_instance();
		$tag   = strtolower( str_replace( '_', '-', $language ) );
		$base  = strtok( $tag, '-' );
		$base  = 'no' === $base ? 'nb' : $base;
		$name  = BP_Suggest::NAMES_IN_SENTENCE[ $base ] ?? (string) ( $trp->get_component( 'languages' )->get_language_names( array( $language ), 'native_name' )[ $language ] ?? $language );
		$text  = sprintf( self::NOTES[ $base ] ?? self::NOTES['en'], $name );
		$dir   = in_array( $base, array( 'ar', 'he', 'fa', 'ur' ), true ) ? 'rtl' : 'ltr';
		// Built as a string: this runs inside TranslatePress's output handler, where ob_start() is not allowed.
		return '<div class="bp-suggest bp-untranslated" id="bp-untranslated" role="status" lang="' . esc_attr( $tag ) . '" dir="' . esc_attr( $dir ) . '" data-no-translation translate="no">'
			. '<p class="bp-suggest__text">' . esc_html( $text ) . '</p>'
			. '<button type="button" class="bp-suggest__no" onclick="this.parentNode.hidden=true">OK</button></div>'
			. '<style>.bp-untranslated{position:fixed;left:16px;right:16px;bottom:16px;z-index:99990;display:flex;flex-wrap:wrap;align-items:center;gap:8px 16px;max-width:560px;margin:0 auto;padding:12px 16px;background:var(--bp-suggest-bg,#fff);color:var(--bp-suggest-fg,#1d1d1d);border:1px solid var(--bp-suggest-line,rgba(0,0,0,.14));box-shadow:0 10px 30px rgba(0,0,0,.16);font-size:15px;line-height:1.4}.bp-untranslated[hidden]{display:none}.bp-untranslated .bp-suggest__text{flex:1 1 220px;margin:0}.bp-untranslated .bp-suggest__no{padding:8px 4px;background:none;border:0;color:inherit;font:inherit;text-decoration:underline;cursor:pointer}</style>';
	}

	/**
	 * This page's address in the default language (the key of the record).
	 *
	 * @return string
	 */
	private static function original_url() {
		return self::key( self::original_address() );
	}

	/**
	 * This request's address in the original language.
	 *
	 * @return string
	 */
	private static function original_address() {
		$trp      = TRP_Translate_Press::get_trp_instance();
		$settings = $trp->get_component( 'settings' )->get_settings();
		return (string) $trp->get_component( 'url_converter' )->get_url_for_language( $settings['default-language'], null, '' );
	}

	/**
	 * Record key for an address (path only, no query string).
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function key( $url ) {
		return md5( untrailingslashit( (string) wp_parse_url( $url, PHP_URL_PATH ) ) );
	}

	/**
	 * Store how many texts a page still misses in a language (written only when it changes).
	 *
	 * @param string $language Language.
	 * @param string $key      Record key.
	 * @param int    $missing  Texts without translation.
	 */
	private static function remember( $language, $key, $missing ) {
		if ( ! preg_match( '/^[A-Za-z_]+$/', $language ) ) {
			return;
		}
		$name = self::PREFIX . $language . '_' . $key;
		if ( (string) (int) $missing !== (string) get_option( $name, '' ) ) {
			update_option( $name, (int) $missing, false );
		}
		$texts_name = self::TEXTS_PREFIX . $language . '_' . $key;
		if ( 0 === (int) $missing ) {
			if ( false !== get_option( $texts_name ) ) {
				delete_option( $texts_name );
			}
			return;
		}
		if ( get_option( $texts_name ) !== self::$missing_texts ) {
			update_option( $texts_name, self::$missing_texts, false );
		}
		self::note_days( self::$missing_texts );
	}

	/**
	 * Whether a built page asks not to be indexed (robots or googlebot meta tag, or an
	 * X-Robots-Tag header), whoever printed it: theme, SEO plugin or WordPress.
	 *
	 * @param string $html Page as built (before Beaver Press's own fallback).
	 * @return bool
	 */
	public static function says_noindex( $html ) {
		$head = (string) strstr( (string) $html, '</head>', true );
		if ( preg_match_all( '/<meta\s[^>]*name=["\'](?:robots|googlebot)["\'][^>]*>/i', $head, $m ) ) {
			foreach ( $m[0] as $tag ) {
				if ( preg_match( '/content=["\'][^"\']*\b(?:noindex|none)\b/i', $tag ) ) {
					return true;
				}
			}
		}
		foreach ( headers_list() as $header ) {
			if ( preg_match( '/^x-robots-tag:\s*(?:[a-z0-9_-]+:\s*)?[^\n]*\b(?:noindex|none)\b/i', $header ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Remember (or forget) that a page is noindex in a language (written only on a change).
	 *
	 * @param string $language Language.
	 * @param string $key      Record key.
	 * @param bool   $noindex  Says noindex.
	 */
	private static function remember_noindex( $language, $key, $noindex ) {
		if ( ! preg_match( '/^[A-Za-z_]+$/', $language ) ) {
			return;
		}
		$name = self::NOINDEX_PREFIX . $language . '_' . $key;
		$had  = false !== get_option( $name );
		if ( $noindex && ! $had ) {
			update_option( $name, 1, false );
		} elseif ( ! $noindex && $had ) {
			delete_option( $name );
		}
	}

	/**
	 * Pages known to be noindex: language => [ key => true ] (one query).
	 *
	 * @return array
	 */
	public static function noindex_map() {
		global $wpdb;
		$out  = array();
		$rows = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( self::NOINDEX_PREFIX ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one read of small rows.
		foreach ( (array) $rows as $name ) {
			if ( preg_match( '/^' . self::NOINDEX_PREFIX . '([A-Za-z_]+)_([0-9a-f]{32})$/', $name, $m ) ) {
				$out[ $m[1] ][ $m[2] ] = true;
			}
		}
		return $out;
	}

	/**
	 * Remember on which days each missing text was seen (written once per text per day).
	 *
	 * @param string[] $texts Missing texts.
	 */
	private static function note_days( array $texts ) {
		$today = wp_date( 'Y-m-d' );
		$seen  = get_option( self::SEEN_OPTION, array() );
		$seen  = is_array( $seen ) ? $seen : array();
		$dirty = false;
		foreach ( $texts as $text ) {
			$id   = md5( $text );
			$days = (array) ( $seen[ $id ][1] ?? array() );
			if ( ! in_array( $today, $days, true ) ) {
				$days[]      = $today;
				$seen[ $id ] = array( $text, array_slice( $days, -7 ) );
				$dirty       = true;
			}
		}
		if ( $dirty ) {
			update_option( self::SEEN_OPTION, array_slice( $seen, -300, null, true ), false );
		}
	}

	/**
	 * Texts a page still misses in a language (empty when complete or not recorded).
	 *
	 * @param string $language Language.
	 * @param string $key      Record key.
	 * @return string[]
	 */
	public static function missing_texts( $language, $key ) {
		$texts = get_option( self::TEXTS_PREFIX . $language . '_' . $key, array() );
		return is_array( $texts ) ? array_values( array_map( 'strval', $texts ) ) : array();
	}

	/**
	 * Missing texts of every incomplete page: language => [ key => [ [ text, recurring ] ] ].
	 *
	 * @return array
	 */
	public static function texts_map() {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( self::TEXTS_PREFIX ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one read of many small rows.
		$seen = get_option( self::SEEN_OPTION, array() );
		$seen = is_array( $seen ) ? $seen : array();
		$out  = array();
		foreach ( (array) $rows as $row ) {
			if ( ! preg_match( '/^' . self::TEXTS_PREFIX . '([A-Za-z_]+)_([0-9a-f]{32})$/', $row->option_name, $m ) ) {
				continue;
			}
			foreach ( (array) maybe_unserialize( $row->option_value ) as $text ) {
				$text                     = (string) $text;
				$out[ $m[1] ][ $m[2] ][] = array( $text, count( (array) ( $seen[ md5( $text ) ][1] ?? array() ) ) >= self::RECURRING_DAYS );
			}
		}
		return $out;
	}

	/**
	 * Whether a missing text keeps coming back (seen on several different days): usually a
	 * number or date that changes, which should be wrapped in data-no-translation.
	 *
	 * @param string $text Text.
	 * @return bool
	 */
	public static function recurring( $text ) {
		$seen = get_option( self::SEEN_OPTION, array() );
		return is_array( $seen ) && count( (array) ( $seen[ md5( (string) $text ) ][1] ?? array() ) ) >= self::RECURRING_DAYS;
	}

	/**
	 * The whole record: language => [ key => missing ] (one query, kept for the request).
	 *
	 * @param bool $fresh Read again.
	 * @return array
	 */
	public static function map( $fresh = false ) {
		static $map = null;
		if ( null !== $map && ! $fresh ) {
			return $map;
		}
		global $wpdb;
		$map  = array();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( self::PREFIX ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one read of many small rows.
		foreach ( (array) $rows as $row ) {
			if ( preg_match( '/^' . self::PREFIX . '([A-Za-z_]+)_([0-9a-f]{32})$/', $row->option_name, $m ) ) {
				$map[ $m[1] ][ $m[2] ] = (int) $row->option_value;
			}
		}
		return $map;
	}

	/**
	 * Languages removed in TranslatePress: drop their progress rows (translations stay in
	 * TranslatePress's tables, so adding the language back later loses nothing).
	 *
	 * @param mixed $old Old settings.
	 * @param mixed $new New settings.
	 */
	public static function languages_changed( $old, $new ) {
		$before  = (array) ( $old['translation-languages'] ?? array() );
		$after   = (array) ( $new['translation-languages'] ?? array() );
		$removed = array_diff( $before, $after );
		global $wpdb;
		foreach ( $removed as $language ) {
			if ( preg_match( '/^[A-Za-z_]+$/', (string) $language ) ) {
				$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( self::PREFIX . $language . '_' ) . '%', $wpdb->esc_like( self::TEXTS_PREFIX . $language . '_' ) . '%', $wpdb->esc_like( self::NOINDEX_PREFIX . $language . '_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- small cleanup.
				foreach ( $names as $name ) {
					delete_option( $name ); // Through the API, so cached copies go too.
				}
			}
		}
		if ( $removed || array_diff( $after, $before ) ) {
			BP_Run::forget_pages();
		}
	}

	/**
	 * Another theme: every page shows other texts now, so the records (and the language links,
	 * sitemaps and ready pages built from them) start afresh as pages are visited.
	 */
	public static function theme_switched() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( self::PREFIX ) . '%', $wpdb->esc_like( self::TEXTS_PREFIX ) . '%', $wpdb->esc_like( self::NOINDEX_PREFIX ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- rare, whole record.
		wp_cache_flush();
		if ( class_exists( 'BP_Cache' ) ) {
			BP_Cache::flush();
		}
	}

	/**
	 * Delete the whole record (uninstall).
	 */
	public static function forget_all() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( self::PREFIX ) . '%', $wpdb->esc_like( self::TEXTS_PREFIX ) . '%', $wpdb->esc_like( self::NOINDEX_PREFIX ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- uninstall.
		delete_option( self::SEEN_OPTION );
		wp_cache_flush();
	}

	/**
	 * Whether a page is known to be incomplete in a language (unknown counts as available).
	 *
	 * @param string $language      Language code.
	 * @param string $original_url  Address in the default language.
	 * @return bool
	 */
	public static function known_incomplete( $language, $original_url ) {
		if ( ! self::enabled() ) {
			return false;
		}
		$map = self::map();
		$key = self::key( $original_url );
		return isset( $map[ $language ][ $key ] ) && (int) $map[ $language ][ $key ] > 0;
	}

	/**
	 * Per language: pages known complete and known incomplete, for the admin page.
	 *
	 * @return array Language => [ complete, incomplete ].
	 */
	public static function counts() {
		$out = array();
		foreach ( self::map() as $language => $pages ) {
			$done             = count( array_filter( (array) $pages, static fn( $m ) => 0 === (int) $m ) );
			$out[ $language ] = array( $done, count( (array) $pages ) - $done );
		}
		return $out;
	}

	/**
	 * Hreflang: TranslatePress prints one link per language; leave out versions known to be
	 * incomplete (visitors would get the original page there, marked noindex).
	 */
	public static function hreflang_hooks() {
		if ( ! self::enabled() ) {
			return;
		}
		$converter = TRP_Translate_Press::get_trp_instance()->get_component( 'url_converter' );
		$priority  = has_action( 'wp_head', array( $converter, 'add_hreflang_to_head' ) );
		if ( false === $priority ) {
			return;
		}
		remove_action( 'wp_head', array( $converter, 'add_hreflang_to_head' ), $priority );
		add_action(
			'wp_head',
			static function () use ( $converter ) {
				ob_start();
				$converter->add_hreflang_to_head();
				echo self::filter_hreflang( (string) ob_get_clean() ); // phpcs:ignore WordPress.Security.EscapeOutput -- TranslatePress's escaped links, some removed.
			},
			$priority
		);
	}

	/**
	 * Remove hreflang links of incomplete versions of this page.
	 *
	 * @param string $links Link tags.
	 * @return string
	 */
	public static function filter_hreflang( $links ) {
		$trp      = TRP_Translate_Press::get_trp_instance();
		$settings = $trp->get_component( 'settings' )->get_settings();
		$original = $trp->get_component( 'url_converter' )->get_url_for_language( $settings['default-language'], null, '' );
		$drop     = array();
		foreach ( (array) $settings['publish-languages'] as $language ) {
			if ( $language !== $settings['default-language'] && self::known_incomplete( $language, (string) $original ) ) {
				$drop[] = (string) strtok( (string) $trp->get_component( 'url_converter' )->get_url_for_language( $language, null, '' ), '?#' ); // Compared without query (e.g. ?utm_source=...).
			}
		}
		if ( ! $drop ) {
			return $links;
		}
		$keep = array();
		foreach ( preg_split( '/\R/', $links ) as $line ) {
			$href = preg_match( '/href="([^"]*)"/', $line, $m ) ? (string) strtok( html_entity_decode( $m[1] ), '?#' ) : '';
			if ( '' !== $href && in_array( $href, $drop, true ) ) {
				continue;
			}
			$keep[] = $line;
		}
		return implode( "\n", $keep );
	}
}
