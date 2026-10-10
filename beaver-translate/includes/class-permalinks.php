<?php
/**
 * Language-prefixed permalinks.
 *
 * @package BeaverTranslate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Adds a translated post's language segment to its own permalink, and
 * leaves every default-language permalink exactly as it already was.
 *
 * @since 1.1.0
 */
class Beaver_Translate_Permalinks {

	/**
	 * Registers the hooks.
	 *
	 * @since 1.1.0
	 */
	public static function init() {
		add_filter( 'post_link', array( __CLASS__, 'filter_post_link' ), 10, 2 );
		add_filter( 'page_link', array( __CLASS__, 'filter_page_link' ), 10, 2 );
		add_filter( 'post_type_link', array( __CLASS__, 'filter_post_type_link' ), 10, 2 );
	}

	/**
	 * Filters a standard post's permalink.
	 *
	 * @since 1.1.0
	 *
	 * @param string  $url  Permalink.
	 * @param WP_Post $post Post.
	 * @return string Filtered permalink.
	 */
	public static function filter_post_link( $url, $post ) {
		return self::add_prefix( $url, $post );
	}

	/**
	 * Filters a page's permalink.
	 *
	 * @since 1.1.0
	 *
	 * @param string $url     Permalink.
	 * @param int    $post_id Page ID.
	 * @return string Filtered permalink.
	 */
	public static function filter_page_link( $url, $post_id ) {
		$post = get_post( $post_id );

		return $post ? self::add_prefix( $url, $post ) : $url;
	}

	/**
	 * Filters a custom post type's permalink.
	 *
	 * Registered for completeness alongside `post_link`/`page_link` — most
	 * custom post types resolve through this filter instead of either of
	 * the other two. Only ever changes anything for a post type the
	 * settings screen actually offers for translation to begin with.
	 *
	 * @since 1.1.0
	 *
	 * @param string  $url  Permalink.
	 * @param WP_Post $post Post.
	 * @return string Filtered permalink.
	 */
	public static function filter_post_type_link( $url, $post ) {
		return self::add_prefix( $url, $post );
	}

	/**
	 * Inserts a translated post's language segment into its permalink.
	 *
	 * @since 1.1.0
	 *
	 * @param string  $url  Permalink core resolved.
	 * @param WP_Post $post The post it belongs to.
	 * @return string Filtered permalink.
	 */
	private static function add_prefix( $url, $post ) {
		$language = get_post_meta( $post->ID, Beaver_Translate_Translator::META_LANGUAGE, true );

		if ( '' === $language || Beaver_Translate_Languages::default_code() === $language ) {
			return $url;
		}

		if ( ! Beaver_Translate_Languages::is_valid( $language ) ) {
			return $url;
		}

		$parsed = wp_parse_url( $url );

		if ( empty( $parsed['path'] ) ) {
			return $url;
		}

		$home_path = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$home_path = '' === $home_path ? '/' : $home_path;

		// Insert the language segment right after the site's own subdirectory
		// (if any), so a subdirectory install ends up `/blog/fr/post/` rather
		// than `/fr/blog/post/`.
		$relative = substr( $parsed['path'], strlen( $home_path ) );
		$new_path = rtrim( $home_path, '/' ) . '/' . $language . '/' . ltrim( $relative, '/' );

		return substr_replace( $url, $new_path, strpos( $url, $parsed['path'] ), strlen( $parsed['path'] ) );
	}
}
