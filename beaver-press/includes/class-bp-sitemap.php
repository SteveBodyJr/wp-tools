<?php
/**
 * Language sitemaps: every public page in each translated language, in WordPress's own
 * sitemap (wp-sitemap.xml -> wp-sitemap-languages-fr-1.xml ...).
 *
 * The pages are the ones the other sitemaps list (same exclusions: hidden destinations,
 * testimonials, authors), each at its address in that language (translated address when there
 * is one). With "only complete pages" on, a page is listed only once it is known to be
 * complete in that language: until then visitors and search engines get the original page
 * marked noindex, and a sitemap must not list pages that ask not to be indexed. hreflang links
 * in each page's head tie the languages together.
 *
 * @package BeaverPress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sitemap provider "languages"; one sub-sitemap per language (subtype = the language's URL slug).
 */
class BP_Sitemap_Provider extends WP_Sitemaps_Provider {

	/**
	 * Name and object type.
	 */
	public function __construct() {
		$this->name        = BP_Sitemap::NAME;
		$this->object_type = 'language';
	}

	/**
	 * Languages as subtypes, keyed by URL slug (sitemap names allow a-z, digits, - and _).
	 *
	 * @return array Slug => object with name and label.
	 */
	public function get_object_subtypes() {
		$out = array();
		foreach ( BP_Sitemap::languages() as $slug => $code ) {
			$out[ $slug ] = (object) array(
				'name'  => $slug,
				'label' => $code,
			);
		}
		return $out;
	}

	/**
	 * Addresses of one language.
	 *
	 * @param int    $page_num Page of the sitemap.
	 * @param string $subtype  Language URL slug.
	 * @return array[]
	 */
	public function get_url_list( $page_num, $subtype = '' ) {
		$languages = BP_Sitemap::languages();
		if ( ! isset( $languages[ $subtype ] ) ) {
			return array();
		}
		$urls = BP_Sitemap::urls( $languages[ $subtype ] );
		$max  = wp_sitemaps_get_max_urls( $this->object_type );
		$list = array();
		foreach ( array_slice( $urls, ( max( 1, (int) $page_num ) - 1 ) * $max, $max ) as $url ) {
			$list[] = array( 'loc' => $url );
		}
		return $list;
	}

	/**
	 * Number of sitemap pages of one language.
	 *
	 * @param string $subtype Language URL slug.
	 * @return int
	 */
	public function get_max_num_pages( $subtype = '' ) {
		$languages = BP_Sitemap::languages();
		if ( ! isset( $languages[ $subtype ] ) ) {
			return 0;
		}
		return (int) ceil( count( BP_Sitemap::urls( $languages[ $subtype ] ) ) / max( 1, wp_sitemaps_get_max_urls( $this->object_type ) ) );
	}
}

/**
 * Language sitemaps.
 */
final class BP_Sitemap {

	/** Provider name (letters only: it is part of the sitemap address). */
	const NAME = 'languages';

	/**
	 * Hooks.
	 */
	public static function init() {
		if ( apply_filters( 'beaver_press_sitemap', true ) ) {
			add_action( 'init', array( __CLASS__, 'register' ), 20 );
		}
	}

	/**
	 * Register the provider when WordPress sitemaps are on.
	 */
	public static function register() {
		if ( function_exists( 'wp_register_sitemap_provider' ) && wp_sitemaps_get_server()->sitemaps_enabled() && self::languages() ) {
			wp_register_sitemap_provider( self::NAME, new BP_Sitemap_Provider() );
		}
	}

	/**
	 * Whether an address can stand in a sitemap: on this site, not wp-admin or the login page,
	 * and, with pretty permalinks, without a query string (e.g. ?elementor_library=, ?preview=):
	 * such an address is never a page's canonical one. With plain permalinks (?p=123) every
	 * address has a query string, so it is allowed there.
	 *
	 * @param string $url Address in the original language.
	 * @return bool
	 */
	public static function listable( $url ) {
		$home = untrailingslashit( home_url() );
		if ( 0 !== strpos( $url, $home ) || preg_match( '#/(wp-admin|wp-login\.php|wp-json)(/|$|\?)#', $url ) ) {
			return false;
		}
		if ( preg_match( '/[?&](preview|preview_id|preview_nonce)=/', $url ) ) {
			return false; // A preview is never a public address.
		}
		return false === strpos( $url, '?' ) || '' === (string) get_option( 'permalink_structure' );
	}

	/**
	 * Translated languages: URL slug => language code.
	 *
	 * @return array
	 */
	public static function languages() {
		$settings = TRP_Translate_Press::get_trp_instance()->get_component( 'settings' )->get_settings();
		$out      = array();
		foreach ( array_keys( BP_Run::languages() ) as $code ) {
			$slug = strtolower( (string) ( $settings['url-slugs'][ $code ] ?? '' ) );
			if ( '' !== $slug && preg_match( '/^[a-z\d_-]+$/', $slug ) ) {
				$out[ $slug ] = $code;
			}
		}
		return $out;
	}

	/**
	 * A language's addresses: every page of the other sitemaps, in that language; with "only
	 * complete pages" on, only those known to be complete.
	 *
	 * @param string $language Language code.
	 * @return string[]
	 */
	public static function urls( $language ) {
		static $cache = array();
		if ( isset( $cache[ $language ] ) ) {
			return $cache[ $language ];
		}
		$only_complete = BP_Complete::enabled();
		$map           = $only_complete ? BP_Complete::map() : array();
		$noindex       = BP_Complete::noindex_map()[ $language ] ?? array();
		$out           = array();
		foreach ( BP_Run::urls() as $url ) {
			if ( ! self::listable( (string) $url ) ) {
				continue;
			}
			$key = BP_Complete::key( $url );
			if ( $only_complete && 0 !== ( $map[ $language ][ $key ] ?? -1 ) ) {
				continue;
			}
			if ( isset( $noindex[ $key ] ) ) {
				continue; // The page asks not to be indexed (theme or SEO plugin): not in a sitemap.
			}
			$out[] = BP_Run::url_in( $url, $language );
		}
		return $cache[ $language ] = array_values( array_unique( array_filter( $out ) ) );
	}
}
