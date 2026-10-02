<?php
/**
 * Translated page addresses: /fr/tours/safari-camping-4-jours/ instead of the English slug.
 *
 * Each post and term can have its own address slug per language (post/term meta `_bp_slug`,
 * language => slug), drafted from the translated title and editable on the edit screen. A
 * two-way map (original path <-> translated path, per language) drives everything:
 * - links: TranslatePress sends every link it converts (page content, switcher, hreflang, form
 *   actions) through `trp_get_url_for_language`; permalinks built on translated pages
 *   (canonical, menus) go through the WordPress link filters;
 * - incoming: a translated address is turned back into the original before WordPress reads
 *   the request, and restored right after, so the canonical check sees the address asked for;
 * - old addresses: an original slug asked in a language that has a translated one is sent on
 *   with a 301 by WordPress's own canonical redirect.
 * The last part of the address (and parent pages' parts) is translated here; post-type and
 * taxonomy prefixes (tours/, destination-area/) by BP_Slug_Bases when the owner saved them.
 * The default language is never changed. Off until switched on (changing addresses of a live site is a choice).
 *
 * @package BeaverPress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Translated slugs.
 */
final class BP_Slugs {

	/** Option: 'yes' or 'no' (default). */
	const OPTION = 'beaver_press_slugs';

	/** Post and term meta: language => slug. */
	const META = '_bp_slug';

	/** Cached map. */
	const MAP_TRANSIENT = 'bp_slug_map';

	/** The map for this request (see map()). */
	private static $map_cache = null;

	/** The request as it arrived, while WordPress reads the original. */
	private static $asked = null;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( self::BACKFILL_CRON, array( __CLASS__, 'backfill_tick' ) );
		// The map is rebuilt whenever addresses may change.
		foreach ( array( 'save_post', 'deleted_post', 'edited_term', 'delete_term', 'update_option_permalink_structure', 'update_option_trp_settings' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'forget_map' ) );
		}
		if ( is_admin() ) {
			add_action( 'add_meta_boxes', array( __CLASS__, 'meta_box' ) );
			add_action( 'save_post', array( __CLASS__, 'save_post' ), 5, 2 );
			foreach ( get_taxonomies( array( 'public' => true ) ) as $taxonomy ) {
				add_action( $taxonomy . '_edit_form_fields', array( __CLASS__, 'term_fields' ), 20, 2 );
			}
			add_action( 'edited_term', array( __CLASS__, 'save_term' ), 5, 3 );
			add_action( 'wp_ajax_bp_slugs_draft', array( __CLASS__, 'ajax_draft' ) );
		}
		if ( ! self::enabled() ) {
			return;
		}
		add_filter( 'trp_get_url_for_language', array( __CLASS__, 'convert' ), 20, 3 );
		foreach ( array( 'post_link', 'page_link', 'post_type_link', 'term_link', 'post_type_archive_link' ) as $hook ) {
			add_filter( $hook, array( __CLASS__, 'permalink' ), 99 );
		}
		add_filter( 'do_parse_request', array( __CLASS__, 'incoming' ), 1 );
		add_action( 'parse_request', array( __CLASS__, 'restore' ), 1 );
		add_filter( 'trp_translated_html', array( __CLASS__, 'auto_draft' ), 1001, 4 );
		add_action( 'template_redirect', array( __CLASS__, 'old_address' ), 1 );
	}

	/**
	 * The original address was asked in a language that has a translated one: 301 there.
	 */
	public static function old_address() {
		global $TRP_LANGUAGE;
		if ( empty( $TRP_LANGUAGE ) || is_admin() || wp_doing_ajax() || ! in_array( strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ), array( 'GET', 'HEAD' ), true ) || ( function_exists( 'trp_is_translation_editor' ) && trp_is_translation_editor() ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared only.
			return;
		}
		$target = self::old_address_target();
		if ( '' === $target && is_404() ) {
			$target = self::moved_address_target();
		}
		if ( '' !== $target ) {
			wp_safe_redirect( $target, 301, 'Beaver Press' );
			exit;
		}
	}

	/**
	 * Where the current request should be sent, if it asks an old (original) address in a
	 * language that has a translated one; '' otherwise. Also used by the ready-page cache.
	 *
	 * @return string
	 */
	public static function old_address_target() {
		global $TRP_LANGUAGE;
		if ( ! self::enabled() || empty( $TRP_LANGUAGE ) ) {
			return '';
		}
		$uri   = (string) wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared with the map, rebuilt from it.
		$host  = ( is_ssl() ? 'https://' : 'http://' ) . (string) ( $_SERVER['HTTP_HOST'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- rebuilt URL.
		$parts = self::split( $host . $uri );
		if ( ! $parts || $parts['language'] !== $TRP_LANGUAGE ) {
			return '';
		}
		$to = self::translate_path( $parts['path'], $TRP_LANGUAGE, $TRP_LANGUAGE ); // Original slug or prefix -> this language's.
		if ( '' === $parts['path'] || $to === $parts['path'] ) {
			return '';
		}
		return self::build( $parts['home'], $TRP_LANGUAGE, $to, $parts['rest'], $parts['slash'] );
	}

	/**
	 * A translated address that no longer exists (the permalink structure or a parent changed):
	 * the address in this language whose last part is the same translated slug, if exactly one.
	 *
	 * @return string '' when none.
	 */
	public static function moved_address_target() {
		global $TRP_LANGUAGE;
		$uri   = (string) wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared with the map only.
		$host  = ( is_ssl() ? 'https://' : 'http://' ) . (string) ( $_SERVER['HTTP_HOST'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- rebuilt URL.
		$parts = self::split( $host . $uri );
		if ( ! $parts || $parts['language'] !== $TRP_LANGUAGE || '' === $parts['path'] ) {
			return '';
		}
		$last  = basename( $parts['path'] );
		$found = array();
		foreach ( array_keys( self::map()[ $TRP_LANGUAGE ]['back'] ?? array() ) as $translated ) {
			if ( basename( $translated ) === $last && $translated !== $parts['path'] ) {
				$found[] = $translated;
			}
		}
		if ( 1 !== count( $found ) ) {
			return '';
		}
		return self::build( $parts['home'], $TRP_LANGUAGE, BP_Slug_Bases::to_language( $found[0], $TRP_LANGUAGE ), $parts['rest'], $parts['slash'] );
	}

	/**
	 * After the run or an editor translated a page, draft its address from the stored title
	 * translation (no extra request). Visitors never trigger this.
	 *
	 * @param string $html     Page.
	 * @param string $language Language.
	 * @param string $code     Language code.
	 * @param bool   $preview  Editor preview.
	 * @return string
	 */
	public static function auto_draft( $html, $language = '', $code = '', $preview = false ) {
		global $TRP_LANGUAGE;
		if ( $preview || ! is_string( $html ) || false === stripos( $html, '</html>' ) || ! BP_Guard::trusted_request() || empty( $TRP_LANGUAGE ) || ! isset( BP_Run::languages()[ $TRP_LANGUAGE ] ) ) {
			return $html;
		}
		// A new address only needs a new map: ready pages that link to the old one still
		// work (it answers with a 301), so the whole cache is not emptied for it.
		if ( is_singular() && ! is_front_page() ) {
			self::draft_post( (int) get_queried_object_id(), array( $TRP_LANGUAGE ), false, false );
		} elseif ( is_tax() || is_category() || is_tag() ) {
			self::draft_term( (int) get_queried_object_id(), array( $TRP_LANGUAGE ), false, false );
		}
		return $html;
	}

	/**
	 * Whether translated addresses are on.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return (bool) apply_filters( 'beaver_press_slugs', 'yes' === get_option( self::OPTION, 'no' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Map                                                                  */
	/* ------------------------------------------------------------------ */

	/**
	 * Language => [ 'to' => [ original path => translated path ], 'back' => [ translated => original ] ].
	 * Paths are relative to the site home, without slashes at the ends ("tours/foo").
	 *
	 * @return array
	 */
	public static function map() {
		if ( null !== self::$map_cache ) {
			return self::$map_cache;
		}
		if ( ! did_action( 'wp_loaded' ) ) {
			// Post types and taxonomies register on init: a map built earlier would miss them.
			$early = get_transient( self::MAP_TRANSIENT );
			return is_array( $early ) ? $early : array();
		}
		$cached = get_transient( self::MAP_TRANSIENT );
		if ( is_array( $cached ) ) {
			return self::$map_cache = $cached;
		}
		self::$map_cache = self::build_map();
		set_transient( self::MAP_TRANSIENT, self::$map_cache, DAY_IN_SECONDS );
		return self::$map_cache;
	}

	/**
	 * Drop the cached map.
	 */
	public static function forget_map() {
		delete_transient( self::MAP_TRANSIENT );
		self::$map_cache = null; // Long processes (WP-CLI, the run) build it again too.
	}

	/**
	 * Build the map from post and term meta.
	 *
	 * @return array
	 */
	private static function build_map() {
		global $wpdb;
		$map = array();
		// Posts with translated slugs (and their ancestors, for page paths).
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", self::META ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- rebuilt rarely, cached.
		foreach ( array_unique( array_map( 'intval', $ids ) ) as $id ) {
			$post = get_post( $id );
			if ( ! $post || 'publish' !== $post->post_status ) {
				continue;
			}
			$original = self::path( self::raw_permalink( $post ) );
			if ( '' === $original ) {
				continue;
			}
			foreach ( self::post_languages( $post ) as $language ) {
				$translated = self::translated_post_path( $post, $language, $original );
				if ( $translated !== $original ) {
					$map[ $language ]['to'][ $original ]     = $translated;
					$map[ $language ]['back'][ $translated ] = $original;
				}
			}
		}
		// Terms.
		$terms = $wpdb->get_col( $wpdb->prepare( "SELECT term_id FROM {$wpdb->termmeta} WHERE meta_key = %s", self::META ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- rebuilt rarely, cached.
		foreach ( array_unique( array_map( 'intval', $terms ) ) as $term_id ) {
			$term = get_term( $term_id );
			if ( ! $term instanceof WP_Term ) {
				continue;
			}
			$link = self::raw_term_link( $term );
			if ( '' === $link ) {
				continue;
			}
			$original = self::path( $link );
			$slugs    = (array) get_term_meta( $term_id, self::META, true );
			foreach ( $slugs as $language => $slug ) {
				if ( '' === (string) $slug || $slug === $term->slug ) {
					continue;
				}
				$translated                              = self::replace_last( $original, $term->slug, (string) $slug );
				$map[ $language ]['to'][ $original ]     = $translated;
				$map[ $language ]['back'][ $translated ] = $original;
			}
		}
		return $map;
	}

	/**
	 * Languages a post has a slug for.
	 *
	 * @param WP_Post $post Post.
	 * @return string[]
	 */
	private static function post_languages( WP_Post $post ) {
		$slugs = get_post_meta( $post->ID, self::META, true );
		return array_keys( array_filter( is_array( $slugs ) ? $slugs : array() ) );
	}

	/**
	 * A post's path in a language: its own slug and its parents' translated slugs.
	 *
	 * @param WP_Post $post     Post.
	 * @param string  $language Language.
	 * @param string  $original Original path.
	 * @return string
	 */
	private static function translated_post_path( WP_Post $post, $language, $original ) {
		$parts = explode( '/', $original );
		$chain = array_reverse( array_merge( array( $post->ID ), get_post_ancestors( $post ) ) ); // Top parent first.
		// The last segments of the path belong to the chain, in order.
		$offset = count( $parts ) - count( $chain );
		foreach ( $chain as $i => $id ) {
			$slugs = get_post_meta( $id, self::META, true );
			$slug  = is_array( $slugs ) ? (string) ( $slugs[ $language ] ?? '' ) : '';
			$index = $offset + $i;
			if ( '' !== $slug && isset( $parts[ $index ] ) && $parts[ $index ] === get_post_field( 'post_name', $id ) ) {
				$parts[ $index ] = $slug;
			}
		}
		return implode( '/', $parts );
	}

	/**
	 * Replace the last occurrence of a segment.
	 *
	 * @param string $path    Path.
	 * @param string $segment Original segment.
	 * @param string $with    Replacement.
	 * @return string
	 */
	private static function replace_last( $path, $segment, $with ) {
		$parts = explode( '/', $path );
		for ( $i = count( $parts ) - 1; $i >= 0; $i-- ) {
			if ( $parts[ $i ] === $segment ) {
				$parts[ $i ] = $with;
				break;
			}
		}
		return implode( '/', $parts );
	}

	/**
	 * Permalink in the default language (our link filter off).
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	private static function raw_permalink( WP_Post $post ) {
		self::$raw = true;
		$link      = (string) get_permalink( $post );
		self::$raw = false;
		return $link;
	}

	/**
	 * Term link in the default language (our link filter off).
	 *
	 * @param WP_Term $term Term.
	 * @return string
	 */
	private static function raw_term_link( WP_Term $term ) {
		self::$raw = true;
		$link      = get_term_link( $term );
		self::$raw = false;
		return is_wp_error( $link ) ? '' : (string) $link;
	}

	/** While true, link filters leave links alone (building the map). */
	private static $raw = false;

	/**
	 * Parts of a URL: home (with language prefix removed), language code, path, rest (query, fragment).
	 *
	 * @param string $url URL.
	 * @return array|null
	 */
	private static function split( $url ) {
		$trp       = TRP_Translate_Press::get_trp_instance();
		$converter = $trp->get_component( 'url_converter' );
		$settings  = $trp->get_component( 'settings' )->get_settings();
		$home      = untrailingslashit( (string) $converter->get_abs_home() );
		if ( 0 !== strpos( $url, $home ) ) {
			return null;
		}
		$tail  = substr( $url, strlen( $home ) );
		$query = '';
		$cut   = strcspn( $tail, '?#' );
		if ( $cut < strlen( $tail ) ) {
			$query = substr( $tail, $cut );
			$tail  = substr( $tail, 0, $cut );
		}
		$path     = trim( $tail, '/' );
		$language = (string) $settings['default-language'];
		$first    = strtok( $path, '/' );
		foreach ( (array) $settings['url-slugs'] as $code => $slug ) {
			if ( '' !== (string) $slug && $first === $slug && in_array( $code, (array) $settings['translation-languages'], true ) ) {
				$language = (string) $code;
				$path     = trim( (string) substr( $path, strlen( $slug ) ), '/' );
				break;
			}
		}
		return array(
			'home'     => $home,
			'language' => $language,
			'path'     => $path,
			'rest'     => $query,
			'slash'    => '' === $tail || '/' === substr( $tail, -1 ),
		);
	}

	/**
	 * Path of a URL relative to home (without language prefix).
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function path( $url ) {
		$parts = self::split( $url );
		return $parts ? $parts['path'] : '';
	}

	/**
	 * Language slug in URLs ("fr"), '' for the default language.
	 *
	 * @param string $language Code.
	 * @return string
	 */
	private static function url_slug( $language ) {
		$settings = TRP_Translate_Press::get_trp_instance()->get_component( 'settings' )->get_settings();
		if ( $language === $settings['default-language'] && 'no' === ( $settings['add-subdirectory-to-default-language'] ?? 'no' ) ) {
			return '';
		}
		return (string) ( $settings['url-slugs'][ $language ] ?? '' );
	}

	/**
	 * Map a path (in any language) to the path in another language.
	 *
	 * @param string $path Path.
	 * @param string $from Language the path is in.
	 * @param string $to   Wanted language.
	 * @return string
	 */
	public static function translate_path( $path, $from, $to ) {
		$map      = self::map();
		$path     = BP_Slug_Bases::to_original( (string) $path, $from ); // Translated prefix -> original.
		$original = $map[ $from ]['back'][ $path ] ?? $path;
		return BP_Slug_Bases::to_language( $map[ $to ]['to'][ $original ] ?? $original, $to );
	}

	/* ------------------------------------------------------------------ */
	/* Links                                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * TranslatePress converted a link to a language: use that language's address.
	 *
	 * @param string $new_url  Converted URL.
	 * @param string $url      Original URL.
	 * @param string $language Target language.
	 * @return string
	 */
	public static function convert( $new_url, $url, $language ) {
		$marker = '';
		if ( '#TRPLINKPROCESSED' === substr( (string) $new_url, -17 ) ) {
			$marker  = '#TRPLINKPROCESSED';
			$new_url = substr( $new_url, 0, -17 );
		}
		$from  = self::split( (string) $url );
		$parts = self::split( (string) $new_url );
		if ( ! $parts || ! $from || '' === $parts['path'] ) {
			return $new_url . $marker;
		}
		$path = self::translate_path( $from['path'], $from['language'], $language );
		if ( $path === $parts['path'] ) {
			return $new_url . $marker;
		}
		return self::build( $parts['home'], $language, $path, $parts['rest'], $parts['slash'] ) . $marker;
	}

	/**
	 * WordPress built a permalink on a translated page (canonical, menus, feeds...).
	 *
	 * @param string $link Link.
	 * @return string
	 */
	public static function permalink( $link ) {
		global $TRP_LANGUAGE;
		if ( self::$raw || empty( $TRP_LANGUAGE ) ) {
			return $link;
		}
		$parts = self::split( (string) $link );
		if ( ! $parts || $parts['language'] !== $TRP_LANGUAGE ) {
			return $link;
		}
		$path = self::translate_path( $parts['path'], $TRP_LANGUAGE, $TRP_LANGUAGE );
		return $path === $parts['path'] ? $link : self::build( $parts['home'], $TRP_LANGUAGE, $path, $parts['rest'], $parts['slash'] );
	}

	/**
	 * Put a URL together.
	 *
	 * @param string $home     Home (no slash).
	 * @param string $language Language.
	 * @param string $path     Path.
	 * @param string $rest     Query and fragment.
	 * @param bool   $slash    Trailing slash.
	 * @return string
	 */
	private static function build( $home, $language, $path, $rest, $slash ) {
		$slug = self::url_slug( $language );
		$url  = $home . '/' . ( '' !== $slug ? $slug . '/' : '' ) . $path;
		return ( $slash ? trailingslashit( $url ) : $url ) . $rest;
	}

	/* ------------------------------------------------------------------ */
	/* Incoming requests                                                    */
	/* ------------------------------------------------------------------ */

	/**
	 * A translated address was asked: let WordPress read the original one.
	 *
	 * @param bool $parse Whether WordPress parses the request.
	 * @return bool
	 */
	public static function incoming( $parse ) {
		global $TRP_LANGUAGE;
		if ( ! $parse || empty( $_SERVER['REQUEST_URI'] ) || empty( $TRP_LANGUAGE ) ) {
			return $parse;
		}
		$uri   = (string) wp_unslash( $_SERVER['REQUEST_URI'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared with the map only.
		$host  = ( is_ssl() ? 'https://' : 'http://' ) . (string) ( $_SERVER['HTTP_HOST'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- rebuilt URL, compared only.
		$parts = self::split( $host . $uri );
		if ( ! $parts || $parts['language'] !== $TRP_LANGUAGE ) {
			return $parse;
		}
		$map      = self::map();
		$asked    = BP_Slug_Bases::to_original( $parts['path'], $TRP_LANGUAGE );
		$original = $map[ $TRP_LANGUAGE ]['back'][ $asked ] ?? $asked;
		if ( $original === $parts['path'] ) {
			return $parse;
		}
		self::$asked = $_SERVER['REQUEST_URI']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- restored as it was.
		$GLOBALS['TRP_ORIGINAL_REQUEST_URI'] = self::$asked; // Redirect plugins read this (TranslatePress compatibility).
		$new = substr( self::build( $parts['home'], $TRP_LANGUAGE, $original, $parts['rest'], $parts['slash'] ), strlen( $host ) );
		$_SERVER['REQUEST_URI'] = $new;
		return $parse;
	}

	/**
	 * Request read: put the asked address back (canonical checks compare against it).
	 */
	public static function restore() {
		if ( null !== self::$asked ) {
			$_SERVER['REQUEST_URI'] = self::$asked;
			self::$asked            = null;
		}
	}

	/* ------------------------------------------------------------------ */
	/* Drafting and editing                                                 */
	/* ------------------------------------------------------------------ */

	/**
	 * Slug from a translated title.
	 *
	 * @param string $text Translated title.
	 * @return string
	 */
	public static function slugify( $text ) {
		$text = html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES, 'UTF-8' );
		$slug = sanitize_title( remove_accents( $text ) );
		// Non-Latin scripts (Chinese, Japanese, Arabic...) would give an address of %-codes:
		// such pages keep the original address, as is usual for those languages.
		return false !== strpos( $slug, '%' ) ? '' : $slug;
	}

	/**
	 * Slug for a text in a language: from the stored translation, or the engine when allowed.
	 *
	 * @param string $text     Text in the original language.
	 * @param string $language Language.
	 * @param bool   $machine  Ask the engine when nothing is stored.
	 * @return string '' when none.
	 */
	public static function suggest( $text, $language, $machine = false ) {
		if ( ! self::latin_language( $language ) ) {
			return '';
		}
		$translated = self::stored_translation( (string) $text, $language );
		if ( '' === $translated && $machine ) {
			$translated = self::machine( (string) $text, $language );
		}
		return '' !== $translated ? self::slugify( $translated ) : '';
	}

	/**
	 * Translation of a text from TranslatePress's dictionary (several spellings of the title).
	 *
	 * @param string $text     Text.
	 * @param string $language Language.
	 * @return string
	 */
	private static function stored_translation( $text, $language ) {
		$query    = TRP_Translate_Press::get_trp_instance()->get_component( 'query' );
		$variants = array_unique(
			array(
				$text,
				esc_html( $text ),
				str_replace( array( '–', '—' ), '-', esc_html( $text ) ),
				str_replace( array( '–', '—', '&#8211;', '&#8212;' ), '-', wptexturize( $text ) ),
				wptexturize( $text ),
			)
		);
		$found = $query->get_existing_translations( array_values( $variants ), $language );
		foreach ( $variants as $v ) {
			if ( isset( $found[ $v ] ) && '' !== trim( (string) $found[ $v ]->translated ) && (int) $found[ $v ]->status > 0 ) {
				return (string) $found[ $v ]->translated;
			}
		}
		return '';
	}

	/**
	 * Whether a language's addresses can be written in plain letters. Chinese, Japanese,
	 * Korean, Arabic, Hebrew, Persian, Russian, Greek, Thai, Hindi... keep the original address
	 * (an address in those scripts is a string of %-codes).
	 *
	 * @param string $language Language code.
	 * @return bool
	 */
	public static function latin_language( $language ) {
		$base = strtolower( (string) strtok( (string) $language, '_' ) );
		$non  = array( 'zh', 'ja', 'ko', 'ar', 'he', 'fa', 'ur', 'ru', 'uk', 'bg', 'be', 'mk', 'sr', 'el', 'th', 'hi', 'bn', 'ta', 'te', 'mr', 'gu', 'kn', 'ml', 'pa', 'ne', 'si', 'km', 'lo', 'my', 'ka', 'hy', 'am', 'kk', 'ky', 'mn', 'tg' );
		return (bool) apply_filters( 'beaver_press_slug_language', ! in_array( $base, $non, true ), $language );
	}

	/** Cron hook: draft missing addresses for every page. */
	const BACKFILL_CRON = 'beaver_press_slugs_backfill';

	/** Backfill progress (offset). */
	const BACKFILL_OPTION = 'beaver_press_slugs_backfill';

	/**
	 * Start drafting every missing address in the background (when the switch is turned on).
	 */
	public static function schedule_backfill() {
		delete_option( self::BACKFILL_OPTION );
		wp_clear_scheduled_hook( self::BACKFILL_CRON );
		wp_schedule_single_event( time() + 30, self::BACKFILL_CRON );
	}

	/**
	 * Draft missing addresses for pages, posts, tours and categories in every language: the
	 * stored translation of the title, else a translation of the title from the engine.
	 *
	 * @param int  $seconds Time for this call.
	 * @param bool $machine Whether titles not translated yet may be sent to the engine.
	 * @return array{offset: int, total: int, drafted: int}
	 */
	public static function backfill( $seconds = 20, $machine = true, $languages = array(), $urls = array() ) {
		$urls  = array_map( 'untrailingslashit', (array) $urls );
		$items = array();
		foreach ( BP_Run::pages() as $page ) {
			if ( untrailingslashit( $page['url'] ) === untrailingslashit( home_url( '/' ) ) ) {
				continue; // The home page has no slug.
			}
			if ( $urls && ! in_array( untrailingslashit( $page['url'] ), $urls, true ) ) {
				continue;
			}
			$items[] = $page;
		}
		$languages = $languages ? array_values( array_intersect( (array) $languages, array_keys( BP_Run::languages() ) ) ) : array_keys( BP_Run::languages() );
		$offset    = (int) get_option( self::BACKFILL_OPTION, 0 );
		$start     = microtime( true );
		$drafted   = 0;
		while ( $offset < count( $items ) && ( microtime( true ) - $start ) < $seconds ) {
			$page = $items[ $offset ];
			if ( $page['post_id'] ) {
				$drafted += count( self::draft_post( $page['post_id'], $languages, $machine, false ) );
			} else {
				$term = self::term_for_url( $page['url'] );
				if ( $term ) {
					$drafted += count( self::draft_term( $term->term_id, $languages, $machine, false ) );
				}
			}
			$offset++;
		}
		update_option( self::BACKFILL_OPTION, $offset, false );
		if ( $drafted ) {
			self::forget_map();
			BP_Cache::flush();
		}
		return array(
			'offset'  => $offset,
			'total'   => count( $items ),
			'drafted' => $drafted,
		);
	}

	/**
	 * Cron: a slice of the backfill, then the next a minute later until all are done.
	 */
	public static function backfill_tick() {
		if ( ! self::enabled() ) {
			return;
		}
		$step = self::backfill( 20, '' === BP_Run::blocker() );
		if ( $step['offset'] < $step['total'] ) {
			wp_schedule_single_event( time() + 60, self::BACKFILL_CRON );
		}
	}

	/**
	 * Draft missing slugs of a post for languages: from the stored title translation, or (when
	 * allowed) a fresh machine translation of the title.
	 *
	 * @param int      $post_id   Post.
	 * @param string[] $languages Languages.
	 * @param bool     $machine   Whether a machine translation may be requested.
	 * @return array Language => slug drafted.
	 */
	public static function draft_post( $post_id, array $languages, $machine = false, $flush = true ) {
		$post = get_post( $post_id );
		if ( ! $post || '' === trim( $post->post_title ) ) {
			return array();
		}
		$slugs = get_post_meta( $post->ID, self::META, true );
		$slugs = is_array( $slugs ) ? $slugs : array();
		$out   = array();
		foreach ( $languages as $language ) {
			if ( ! empty( $slugs[ $language ] ) || ! self::latin_language( $language ) ) {
				continue;
			}
			$title = self::stored_translation( $post->post_title, $language );
			if ( '' === $title && $machine ) {
				$title = self::machine( $post->post_title, $language );
			}
			$slug = self::slugify( $title );
			if ( '' !== $slug && $slug !== $post->post_name ) {
				$slugs[ $language ] = self::unique( $slug, $language, $post->ID );
				$out[ $language ]   = $slugs[ $language ];
			}
		}
		if ( $out ) {
			update_post_meta( $post->ID, self::META, $slugs );
			if ( $flush ) {
				self::changed();
			} else {
				// Drafted while a page is being built: this page's ready copies in every language
				// carry its old address in their language links, the rest can stay.
				self::forget_map();
				BP_Cache::forget_page( self::raw_permalink( $post ) );
			}
		}
		return $out;
	}

	/**
	 * Draft missing slugs of a term.
	 *
	 * @param int      $term_id   Term.
	 * @param string[] $languages Languages.
	 * @param bool     $machine   Whether a machine translation may be requested.
	 * @return array
	 */
	public static function draft_term( $term_id, array $languages, $machine = false, $flush = true ) {
		$term = get_term( $term_id );
		if ( ! $term instanceof WP_Term ) {
			return array();
		}
		$slugs = get_term_meta( $term->term_id, self::META, true );
		$slugs = is_array( $slugs ) ? $slugs : array();
		$out   = array();
		foreach ( $languages as $language ) {
			if ( ! empty( $slugs[ $language ] ) || ! self::latin_language( $language ) ) {
				continue;
			}
			$name = self::stored_translation( $term->name, $language );
			if ( '' === $name && $machine ) {
				$name = self::machine( $term->name, $language );
			}
			$slug = self::slugify( $name );
			if ( '' !== $slug && $slug !== $term->slug ) {
				$slugs[ $language ] = $slug;
				$out[ $language ]   = $slug;
			}
		}
		if ( $out ) {
			update_term_meta( $term->term_id, self::META, $slugs );
			$flush ? self::changed() : self::forget_map();
		}
		return $out;
	}

	/**
	 * Machine-translate a title with the Beaver Press engine (trusted requests only).
	 *
	 * @param string $text     Title.
	 * @param string $language Language.
	 * @return string
	 */
	private static function machine( $text, $language ) {
		if ( '' !== BP_Run::blocker() ) {
			return '';
		}
		require_once BP_PATH . 'includes/class-bp-ai-machine-translator.php';
		$settings = TRP_Translate_Press::get_trp_instance()->get_component( 'settings' )->get_settings();
		$engine   = new BP_AI_Machine_Translator( $settings );
		BP_Provenance::$paused = true; // Only a slug is kept from this.
		$result   = $engine->translate_array( array( 'x' => $text ), $language, $settings['default-language'] );
		BP_Provenance::$paused = false;
		return (string) ( $result['x'] ?? '' );
	}

	/**
	 * Keep a translated slug unique among translated addresses of the language.
	 *
	 * @param string $slug     Slug.
	 * @param string $language Language.
	 * @param int    $post_id  Post that gets it.
	 * @return string
	 */
	private static function unique( $slug, $language, $post_id ) {
		global $wpdb;
		$taken = array();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND post_id <> %d", self::META, $post_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- small, admin only.
		foreach ( (array) $rows as $row ) {
			$v = maybe_unserialize( $row->meta_value );
			if ( is_array( $v ) && ! empty( $v[ $language ] ) ) {
				$taken[ $v[ $language ] ] = true;
			}
		}
		// Original slugs of other posts are taken too (they are real addresses in every language).
		$candidate = $slug;
		for ( $n = 2; isset( $taken[ $candidate ] ) || self::original_slug_used( $candidate, $post_id ); $n++ ) {
			$candidate = $slug . '-' . $n;
		}
		return $candidate;
	}

	/**
	 * Whether another post already uses a slug as its own (original) slug.
	 *
	 * @param string $slug    Slug.
	 * @param int    $post_id Post to ignore.
	 * @return bool
	 */
	private static function original_slug_used( $slug, $post_id ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND ID <> %d AND post_status = 'publish' LIMIT 1", $slug, $post_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- admin only.
	}

	/**
	 * Addresses changed: new map and fresh ready pages.
	 */
	private static function changed() {
		self::forget_map();
		BP_Cache::flush();
	}

	/**
	 * Draft addresses for chosen pages (Pages tab), from stored title translations; a
	 * machine translation of the title only when asked.
	 */
	public static function ajax_draft() {
		check_ajax_referer( 'bp_run', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'beaver-press' ) ), 403 );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked above.
		$urls      = BP_Run::known_urls( array_map( 'esc_url_raw', (array) wp_unslash( $_POST['urls'] ?? array() ) ) );
		$languages = array_values( array_intersect( array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['languages'] ?? array() ) ), array_keys( BP_Run::languages() ) ) );
		$machine   = ! empty( $_POST['machine'] );
		// phpcs:enable
		$done = 0;
		foreach ( BP_Run::pages() as $page ) {
			if ( ! in_array( $page['url'], $urls, true ) ) {
				continue;
			}
			if ( $page['post_id'] && untrailingslashit( $page['url'] ) !== untrailingslashit( home_url( '/' ) ) ) {
				$done += count( self::draft_post( $page['post_id'], $languages, $machine ) );
			} elseif ( ! $page['post_id'] ) {
				$term = self::term_for_url( $page['url'] );
				if ( $term ) {
					$done += count( self::draft_term( $term->term_id, $languages, $machine ) );
				}
			}
		}
		wp_send_json_success(
			array(
				/* translators: %s: number of addresses */
				'message' => sprintf( _n( '%s translated address drafted.', '%s translated addresses drafted.', $done, 'beaver-press' ), number_format_i18n( $done ) ),
			)
		);
	}

	/**
	 * Term whose archive is at a URL.
	 *
	 * @param string $url URL.
	 * @return WP_Term|null
	 */
	private static function term_for_url( $url ) {
		foreach ( get_taxonomies( array( 'public' => true ) ) as $taxonomy ) {
			$slug = basename( untrailingslashit( (string) wp_parse_url( $url, PHP_URL_PATH ) ) );
			$term = get_term_by( 'slug', $slug, $taxonomy );
			if ( $term && untrailingslashit( self::raw_term_link( $term ) ) === untrailingslashit( $url ) ) {
				return $term;
			}
		}
		return null;
	}

	/**
	 * "Translated addresses" box on the edit screen of public post types.
	 */
	public static function meta_box() {
		foreach ( get_post_types( array( 'public' => true ) ) as $type ) {
			if ( 'attachment' !== $type ) {
				add_meta_box( 'bp-slugs', __( 'Translated addresses', 'beaver-press' ), array( __CLASS__, 'render_box' ), $type, 'side', 'low' );
			}
		}
	}

	/**
	 * The box: one slug field per language.
	 *
	 * @param WP_Post $post Post.
	 */
	public static function render_box( $post ) {
		$slugs = get_post_meta( $post->ID, self::META, true );
		$slugs = is_array( $slugs ) ? $slugs : array();
		wp_nonce_field( 'bp_slugs_' . $post->ID, 'bp_slugs_nonce' );
		echo '<p class="description">' . esc_html__( 'The last part of this page\'s address in each language. Empty: the original address is used. Drafted from the translated title when "Automatically translate slugs" is on.', 'beaver-press' ) . '</p>';
		if ( ! self::enabled() ) {
			echo '<p class="description"><strong>' . esc_html__( 'Translated addresses are switched off (Settings -> Beaver Press).', 'beaver-press' ) . '</strong></p>';
		}
		foreach ( BP_Run::languages() as $code => $name ) {
			printf(
				'<p><label for="bp-slug-%1$s">%2$s</label><br /><input type="text" class="widefat" id="bp-slug-%1$s" name="bp_slug[%1$s]" value="%3$s" placeholder="%4$s" /></p>',
				esc_attr( $code ),
				esc_html( $name ),
				esc_attr( (string) ( $slugs[ $code ] ?? '' ) ),
				esc_attr( $post->post_name )
			);
		}
		echo '<p><label><input type="checkbox" name="bp_slug_draft" value="1" /> ' . esc_html__( 'Draft empty ones from the translated title on Update', 'beaver-press' ) . '</label></p>';
	}

	/**
	 * Save the box.
	 *
	 * @param int     $post_id Post.
	 * @param WP_Post $post    Post.
	 */
	public static function save_post( $post_id, $post ) {
		if ( ! isset( $_POST['bp_slugs_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['bp_slugs_nonce'] ), 'bp_slugs_' . $post_id ) || ! current_user_can( 'edit_post', $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		$raw   = isset( $_POST['bp_slug'] ) && is_array( $_POST['bp_slug'] ) ? wp_unslash( $_POST['bp_slug'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- slugified below.
		$slugs = array();
		foreach ( BP_Run::languages() as $code => $name ) {
			$slug = isset( $raw[ $code ] ) ? self::slugify( (string) $raw[ $code ] ) : '';
			if ( '' !== $slug && $slug !== $post->post_name ) {
				$slugs[ $code ] = self::unique( $slug, $code, $post_id );
			}
		}
		$slugs ? update_post_meta( $post_id, self::META, $slugs ) : delete_post_meta( $post_id, self::META );
		if ( ! empty( $_POST['bp_slug_draft'] ) ) {
			self::draft_post( $post_id, array_keys( BP_Run::languages() ), true );
		}
		self::changed();
	}

	/**
	 * Term edit screen fields.
	 *
	 * @param WP_Term $term     Term.
	 * @param string  $taxonomy Taxonomy.
	 */
	public static function term_fields( $term, $taxonomy ) {
		$slugs = get_term_meta( $term->term_id, self::META, true );
		$slugs = is_array( $slugs ) ? $slugs : array();
		wp_nonce_field( 'bp_slugs_term_' . $term->term_id, 'bp_slugs_nonce' );
		foreach ( BP_Run::languages() as $code => $name ) {
			printf(
				'<tr class="form-field"><th scope="row"><label for="bp-slug-%1$s">%2$s</label></th><td><input type="text" id="bp-slug-%1$s" name="bp_slug[%1$s]" value="%3$s" placeholder="%4$s" /><p class="description">%5$s</p></td></tr>',
				esc_attr( $code ),
				/* translators: %s: language */
				esc_html( sprintf( __( 'Address in %s', 'beaver-press' ), $name ) ),
				esc_attr( (string) ( $slugs[ $code ] ?? '' ) ),
				esc_attr( $term->slug ),
				esc_html__( 'Beaver Press: the last part of this archive\'s address in that language. Empty: the original.', 'beaver-press' )
			);
		}
	}

	/**
	 * Save term fields.
	 *
	 * @param int    $term_id  Term.
	 * @param int    $tt_id    Term taxonomy.
	 * @param string $taxonomy Taxonomy.
	 */
	public static function save_term( $term_id, $tt_id = 0, $taxonomy = '' ) {
		if ( ! isset( $_POST['bp_slugs_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['bp_slugs_nonce'] ), 'bp_slugs_term_' . $term_id ) || ! current_user_can( 'manage_categories' ) ) {
			return;
		}
		$term  = get_term( $term_id );
		$raw   = isset( $_POST['bp_slug'] ) && is_array( $_POST['bp_slug'] ) ? wp_unslash( $_POST['bp_slug'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- slugified below.
		$slugs = array();
		foreach ( BP_Run::languages() as $code => $name ) {
			$slug = isset( $raw[ $code ] ) ? self::slugify( (string) $raw[ $code ] ) : '';
			if ( '' !== $slug && $term instanceof WP_Term && $slug !== $term->slug ) {
				$slugs[ $code ] = $slug;
			}
		}
		$slugs ? update_term_meta( $term_id, self::META, $slugs ) : delete_term_meta( $term_id, self::META );
		self::changed();
	}
}
