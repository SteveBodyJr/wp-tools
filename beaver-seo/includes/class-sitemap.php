<?php
/**
 * XML sitemap.
 *
 * @package BeaverSEO
 */

defined( 'ABSPATH' ) || exit;

/**
 * Serves /sitemap.xml and the per-post-type files it links to.
 *
 * Generated on request rather than written to disk, so there is never a
 * stale file to regenerate and nothing to fail writing on a read-only
 * filesystem. Each file is capped well under the search engines' own
 * 50,000-URL limit and cached for an hour, which keeps a large library from
 * re-running the same queries on every crawl.
 *
 * @since 1.0.0
 */
class Beaver_SEO_Sitemap {

	/**
	 * URLs per sitemap file.
	 *
	 * Comfortably under the 50,000-URL ceiling search engines enforce, and
	 * small enough that generating one page never risks a shared host's
	 * execution time limit even on a library of tens of thousands of posts.
	 *
	 * @var int
	 */
	const PER_PAGE = 1000;

	const CACHE_TTL = HOUR_IN_SECONDS;

	/**
	 * Registers the rewrite rules and the request handler.
	 *
	 * @since 1.0.0
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'add_rewrite_rules' ) );
		add_filter( 'query_vars', array( __CLASS__, 'add_query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_serve' ) );

		// Runs after Beaver_SEO_Meta::filter_robots_txt(), so the Sitemap: line
		// is appended to whichever content wins — the default or a managed one.
		add_filter( 'robots_txt', array( __CLASS__, 'filter_robots_txt' ), 20 );
	}

	/**
	 * Registers the sitemap URLs.
	 *
	 * @since 1.0.0
	 */
	public static function add_rewrite_rules() {
		add_rewrite_rule( '^sitemap\.xml$', 'index.php?beaver_seo_sitemap=index', 'top' );
		add_rewrite_rule(
			'^sitemap-([a-z0-9_-]+)-([0-9]+)\.xml$',
			'index.php?beaver_seo_sitemap=urlset&beaver_seo_sitemap_type=$matches[1]&beaver_seo_sitemap_page=$matches[2]',
			'top'
		);
	}

	/**
	 * Registers the query variables the rewrite rules feed.
	 *
	 * @since 1.0.0
	 *
	 * @param string[] $vars Existing query variables.
	 * @return string[] Filtered query variables.
	 */
	public static function add_query_vars( $vars ) {
		$vars[] = 'beaver_seo_sitemap';
		$vars[] = 'beaver_seo_sitemap_type';
		$vars[] = 'beaver_seo_sitemap_page';

		return $vars;
	}

	/**
	 * Serves a sitemap request, or does nothing on any other page.
	 *
	 * @since 1.0.0
	 */
	public static function maybe_serve() {
		$which = get_query_var( 'beaver_seo_sitemap' );

		if ( '' === $which || ! Beaver_SEO_Settings::get( 'sitemap_enabled' ) ) {
			return;
		}

		nocache_headers();
		header( 'Content-Type: application/xml; charset=' . get_bloginfo( 'charset' ) );

		if ( 'index' === $which ) {
			echo self::index_xml(); // phpcs:ignore WordPress.Security.EscapeOutput -- pre-escaped XML.
			exit;
		}

		$type = sanitize_key( (string) get_query_var( 'beaver_seo_sitemap_type' ) );
		$page = max( 1, (int) get_query_var( 'beaver_seo_sitemap_page' ) );

		if ( ! in_array( $type, self::enabled_types(), true ) ) {
			status_header( 404 );
			exit;
		}

		echo self::urlset_xml( $type, $page ); // phpcs:ignore WordPress.Security.EscapeOutput -- pre-escaped XML.
		exit;
	}

	/**
	 * Returns the post types this site's settings actually publish.
	 *
	 * @since 1.0.0
	 *
	 * @return string[] Post type names.
	 */
	private static function enabled_types() {
		return array_values(
			array_intersect(
				(array) Beaver_SEO_Settings::get( 'sitemap_post_types', array() ),
				Beaver_SEO_Settings::public_post_types()
			)
		);
	}

	/**
	 * Builds the sitemap index.
	 *
	 * @since 1.0.0
	 *
	 * @return string XML document.
	 */
	private static function index_xml() {
		$cache_key = 'beaver_seo_sitemap_index';
		$cached    = get_transient( $cache_key );

		if ( is_string( $cached ) ) {
			return $cached;
		}

		$entries = array();

		foreach ( self::enabled_types() as $type ) {
			$total = self::count_published( $type );

			if ( 0 === $total ) {
				continue;
			}

			$pages   = (int) ceil( $total / self::PER_PAGE );
			$lastmod = self::latest_modified( $type );

			for ( $page = 1; $page <= $pages; $page++ ) {
				$entries[] = sprintf(
					"\t<sitemap>\n\t\t<loc>%s</loc>\n\t\t<lastmod>%s</lastmod>\n\t</sitemap>\n",
					esc_url( home_url( "/sitemap-{$type}-{$page}.xml" ) ),
					esc_html( $lastmod )
				);
			}
		}

		$xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
			. '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n"
			. implode( '', $entries )
			. '</sitemapindex>';

		set_transient( $cache_key, $xml, self::CACHE_TTL );

		return $xml;
	}

	/**
	 * Builds one post type's sitemap page.
	 *
	 * @since 1.0.0
	 *
	 * @param string $type Post type.
	 * @param int    $page Page number, 1-indexed.
	 * @return string XML document.
	 */
	private static function urlset_xml( $type, $page ) {
		$cache_key = "beaver_seo_sitemap_{$type}_{$page}";
		$cached    = get_transient( $cache_key );

		if ( is_string( $cached ) ) {
			return $cached;
		}

		$include_images = (bool) Beaver_SEO_Settings::get( 'sitemap_images' );

		$query = new WP_Query(
			array(
				'post_type'              => $type,
				'post_status'            => 'publish',
				'posts_per_page'         => self::PER_PAGE,
				'paged'                  => $page,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				// Left on deliberately: the loop below reads noindex and
				// image meta for every post in the page via get_post_meta().
				// Disabling this would trade one bulk meta query for up to
				// PER_PAGE individual ones — exactly the cost this cache
				// exists to avoid.
				'update_post_meta_cache' => true,
				'ignore_sticky_posts'    => true,
			)
		);

		$entries = array();

		foreach ( $query->posts as $post ) {
			if ( '1' === get_post_meta( $post->ID, Beaver_SEO_Meta::META_NOINDEX, true ) ) {
				continue;
			}

			$image_xml = '';

			if ( $include_images ) {
				$image_id = Beaver_SEO_Meta::resolve_image( $post->ID );

				if ( $image_id > 0 ) {
					$src = wp_get_attachment_image_src( $image_id, 'large' );

					if ( $src ) {
						$image_xml = sprintf(
							"\t\t<image:image>\n\t\t\t<image:loc>%s</image:loc>\n\t\t</image:image>\n",
							esc_url( $src[0] )
						);
					}
				}
			}

			$entries[] = sprintf(
				"\t<url>\n\t\t<loc>%s</loc>\n\t\t<lastmod>%s</lastmod>\n%s\t</url>\n",
				esc_url( get_permalink( $post ) ),
				esc_html( get_the_modified_date( DATE_W3C, $post ) ),
				$image_xml
			);
		}

		wp_reset_postdata();

		$xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
			. '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n"
			. implode( '', $entries )
			. '</urlset>';

		set_transient( $cache_key, $xml, self::CACHE_TTL );

		return $xml;
	}

	/**
	 * Counts the published posts of one type.
	 *
	 * @since 1.0.0
	 *
	 * @param string $type Post type.
	 * @return int Count.
	 */
	private static function count_published( $type ) {
		$counts = wp_count_posts( $type );

		return isset( $counts->publish ) ? (int) $counts->publish : 0;
	}

	/**
	 * Finds the most recent modification date among a post type's published posts.
	 *
	 * @since 1.0.0
	 *
	 * @param string $type Post type.
	 * @return string W3C date, or the current time when the type is empty.
	 */
	private static function latest_modified( $type ) {
		global $wpdb;

		$date = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_modified_gmt FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish' ORDER BY post_modified_gmt DESC LIMIT 1",
				$type
			)
		);

		return $date ? mysql2date( DATE_W3C, $date, false ) : gmdate( DATE_W3C );
	}

	/**
	 * Adds the sitemap to what robots.txt points crawlers at.
	 *
	 * @since 1.0.0
	 *
	 * @param string $output Existing robots.txt content.
	 * @return string Filtered content.
	 */
	public static function filter_robots_txt( $output ) {
		if ( ! Beaver_SEO_Settings::get( 'sitemap_enabled' ) ) {
			return $output;
		}

		return rtrim( (string) $output ) . "\nSitemap: " . home_url( '/sitemap.xml' ) . "\n";
	}

	/**
	 * Clears every cached sitemap page.
	 *
	 * There is no per-type index of transient keys to target individually, so
	 * this walks the option table for the plugin's own prefix. Only ever
	 * called from admin actions — settings save, and the manual "Rebuild
	 * sitemap" button — never from a front-end request.
	 *
	 * @since 1.0.0
	 */
	public static function flush_cache() {
		global $wpdb;

		$like = $wpdb->esc_like( '_transient_beaver_seo_sitemap_' ) . '%';
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like ) ); // phpcs:ignore WordPress.DB

		$like_timeout = $wpdb->esc_like( '_transient_timeout_beaver_seo_sitemap_' ) . '%';
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like_timeout ) ); // phpcs:ignore WordPress.DB

		delete_transient( 'beaver_seo_sitemap_index' );
	}
}
