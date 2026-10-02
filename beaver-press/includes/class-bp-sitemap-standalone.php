<?php
/**
 * Language sitemaps when WordPress's own sitemaps are off (Yoast SEO, Rank Math, SEOPress and
 * other SEO plugins switch them off and serve their own).
 *
 * Beaver Press's language sitemaps live inside WordPress's sitemap (BP_Sitemap). With
 * that switched off, translated pages would be in no sitemap at all. Then Beaver Press serves
 * the same lists itself, at /bp-sitemap.xml (index) and /bp-sitemap-<language>-<n>.xml, adds
 * them to the SEO plugin's own sitemap index through that plugin's filter, and to robots.txt.
 * The SEO plugin keeps owning the original-language sitemaps; nothing is listed twice.
 * No rewrite rules: the two addresses are recognised before WordPress parses the request.
 *
 * @package BeaverPress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Standalone language sitemaps.
 */
final class BP_Sitemap_Standalone {

	/** Addresses per sitemap file. */
	const PER_FILE = 2000;

	/**
	 * Hooks.
	 */
	public static function init() {
		if ( ! apply_filters( 'beaver_press_sitemap', true ) ) {
			return;
		}
		add_action( 'parse_request', array( __CLASS__, 'serve' ), 0 );
		add_filter( 'robots_txt', array( __CLASS__, 'robots' ), 20, 2 );
		add_filter( 'wpseo_sitemap_index', array( __CLASS__, 'index_entries' ) );
		add_filter( 'rank_math/sitemap/index', array( __CLASS__, 'index_entries' ) );
		add_filter( 'seopress_sitemaps_xml_index_item', array( __CLASS__, 'index_entries' ) );
	}

	/**
	 * Whether this site needs them (WordPress's sitemaps off, languages to list).
	 *
	 * @return bool
	 */
	public static function active() {
		return function_exists( 'wp_sitemaps_get_server' ) && ! wp_sitemaps_get_server()->sitemaps_enabled() && BP_Sitemap::languages();
	}

	/**
	 * Language sitemap files: URL => [ language code, page ].
	 *
	 * @return array
	 */
	public static function files() {
		$out = array();
		foreach ( BP_Sitemap::languages() as $slug => $code ) {
			$count = count( BP_Sitemap::urls( $code ) );
			if ( ! $count ) {
				continue; // No complete page in this language yet: no sitemap to list.
			}
			$pages = (int) ceil( $count / self::PER_FILE );
			for ( $n = 1; $n <= $pages; $n++ ) {
				$out[ home_url( '/bp-sitemap-' . $slug . '-' . $n . '.xml' ) ] = array( $code, $n );
			}
		}
		return $out;
	}

	/**
	 * <sitemap> entries for an SEO plugin's index.
	 *
	 * @param string $xml Entries so far.
	 * @return string
	 */
	public static function index_entries( $xml = '' ) {
		if ( ! self::active() ) {
			return $xml;
		}
		$add = '';
		foreach ( array_keys( self::files() ) as $loc ) {
			$add .= "\n<sitemap>\n<loc>" . esc_url( $loc ) . "</loc>\n</sitemap>";
		}
		return (string) $xml . $add . "\n";
	}

	/**
	 * robots.txt: point to the language sitemaps.
	 *
	 * @param string $output Robots.txt.
	 * @param bool   $public Whether the site is public.
	 * @return string
	 */
	public static function robots( $output, $public ) {
		if ( $public && self::active() ) {
			$output .= "\nSitemap: " . esc_url( home_url( '/bp-sitemap.xml' ) ) . "\n";
		}
		return $output;
	}

	/**
	 * Answer /bp-sitemap.xml and /bp-sitemap-<language>-<n>.xml.
	 *
	 * @param WP $wp Request.
	 */
	public static function serve( $wp ) {
		$path = trim( (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH ), '/' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- compared only.
		$base = trim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		$path = '' !== $base && 0 === strpos( $path, $base . '/' ) ? substr( $path, strlen( $base ) + 1 ) : $path;
		if ( 0 !== strpos( $path, 'bp-sitemap' ) || ! self::active() ) {
			return;
		}
		$xml = null;
		if ( 'bp-sitemap.xml' === $path ) {
			$xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . self::index_entries() . '</sitemapindex>';
		} elseif ( preg_match( '/^bp-sitemap-([a-z\d_-]+)-(\d+)\.xml$/', $path, $m ) ) {
			$code = BP_Sitemap::languages()[ $m[1] ] ?? '';
			$urls = '' !== $code ? array_slice( BP_Sitemap::urls( $code ), ( (int) $m[2] - 1 ) * self::PER_FILE, self::PER_FILE ) : array();
			if ( $urls ) {
				$xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
				foreach ( $urls as $u ) {
					$xml .= "\n<url><loc>" . esc_url( $u ) . '</loc></url>';
				}
				$xml .= "\n</urlset>";
			}
		}
		if ( null === $xml ) {
			return; // Not one of ours: WordPress answers (404).
		}
		status_header( 200 );
		header( 'Content-Type: application/xml; charset=UTF-8' );
		header( 'X-Robots-Tag: noindex, follow' );
		echo $xml; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped URLs.
		exit;
	}
}
