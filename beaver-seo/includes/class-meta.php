<?php
/**
 * Title, meta description, canonical and robots output.
 *
 * @package BeaverSEO
 */

defined( 'ABSPATH' ) || exit;

/**
 * Resolves and prints the tags every page needs, from a template plus
 * whatever override the current post, term or archive carries.
 *
 * Deliberately built on WordPress's own hooks rather than replacing them:
 * `wp_robots` and `get_canonical_url` are filters core already calls from
 * `wp_head()`, so this plugin adds directives to what is already there
 * instead of fighting core, a theme, or another plugin for the same tag.
 *
 * @since 1.0.0
 */
class Beaver_SEO_Meta {

	const META_TITLE       = '_beaver_seo_title';
	const META_DESCRIPTION = '_beaver_seo_description';
	const META_IMAGE       = '_beaver_seo_image';
	const META_NOINDEX     = '_beaver_seo_noindex';
	const META_NOFOLLOW    = '_beaver_seo_nofollow';
	const META_CANONICAL   = '_beaver_seo_canonical';

	/**
	 * The description resolved for the current request.
	 *
	 * Computed once by self::description() and read again by
	 * Beaver_SEO_Social so the og:description always matches the meta tag,
	 * rather than the two independently guessing at an excerpt.
	 *
	 * @var string|null
	 */
	private static $resolved_description = null;

	/**
	 * Registers the front-end hooks.
	 *
	 * @since 1.0.0
	 */
	public static function init() {
		add_filter( 'pre_get_document_title', array( __CLASS__, 'title' ), 15 );
		add_filter( 'wp_title', array( __CLASS__, 'title' ), 15 );

		add_action( 'wp_head', array( __CLASS__, 'output_description' ), 1 );
		add_filter( 'get_canonical_url', array( __CLASS__, 'canonical' ), 10, 2 );
		add_filter( 'wp_robots', array( __CLASS__, 'robots' ) );
		add_filter( 'robots_txt', array( __CLASS__, 'filter_robots_txt' ), 10, 1 );
	}

	/**
	 * Replaces the virtual robots.txt content, when the setting allows it.
	 *
	 * WordPress only calls this filter when there is no physical robots.txt
	 * file in the site root — a real file on disk always takes over the
	 * request before PHP is involved at all, and correctly so.
	 *
	 * @since 1.0.0
	 *
	 * @param string $output Default robots.txt content core built.
	 * @return string Filtered content.
	 */
	public static function filter_robots_txt( $output ) {
		if ( ! Beaver_SEO_Settings::get( 'robots_txt_managed' ) ) {
			return $output;
		}

		$content = trim( (string) Beaver_SEO_Settings::get( 'robots_txt_content', '' ) );

		return '' !== $content ? $content . "\n" : $output;
	}

	/*
	 * -----------------------------------------------------------------------
	 * Title
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Builds the document title for the current request.
	 *
	 * @since 1.0.0
	 *
	 * @param string $title Title so far, ignored — the whole line is owned here.
	 * @return string Rendered title.
	 */
	public static function title( $title ) {
		unset( $title );

		if ( is_admin() || is_feed() ) {
			return '';
		}

		$settings = Beaver_SEO_Settings::all();
		$override = self::current_override( self::META_TITLE );

		if ( '' !== $override ) {
			$rendered = self::replace_tokens( $override, self::context() );
		} else {
			$template = ( is_front_page() && ! is_paged() ) ? $settings['title_template_home'] : $settings['title_template'];
			$rendered = self::replace_tokens( $template, self::context() );
		}

		if ( is_paged() || is_search() ) {
			$page = max( 1, (int) get_query_var( 'paged' ) );

			if ( $page > 1 ) {
				$rendered .= sprintf(
					/* translators: %d: page number. */
					' ' . __( '- Page %d', 'beaver-seo' ),
					$page
				);
			}
		}

		return trim( preg_replace( '/\s+/', ' ', $rendered ) );
	}

	/**
	 * Replaces the tokens a template may contain.
	 *
	 * @since 1.0.0
	 *
	 * @param string $template Raw template.
	 * @param array  $context  Values from self::context().
	 * @return string Rendered string.
	 */
	public static function replace_tokens( $template, array $context ) {
		$settings = Beaver_SEO_Settings::all();

		$site_name = '' !== $settings['site_name'] ? $settings['site_name'] : get_bloginfo( 'name' );

		$tokens = array(
			'%title%'    => $context['title'],
			'%sitename%' => $site_name,
			'%tagline%'  => get_bloginfo( 'description' ),
			'%sep%'      => $settings['title_separator'],
			'%excerpt%'  => $context['excerpt'],
			'%date%'     => $context['date'],
			'%author%'   => $context['author'],
			'%year%'     => gmdate( 'Y' ),
		);

		$rendered = strtr( $template, $tokens );

		// Collapses "Title -  Site" into "Title - Site" when a token on either
		// side of the separator resolved to nothing, without asking whoever
		// writes the template to special-case that themselves.
		return trim( preg_replace( '/\s{2,}/', ' ', $rendered ) );
	}

	/**
	 * Describes the object the current request is about, for token replacement.
	 *
	 * @since 1.0.0
	 *
	 * @return array {
	 *     @type string $title   Bare title with no template applied.
	 *     @type string $excerpt Short summary, already trimmed.
	 *     @type string $date    Published date, localized, or empty.
	 *     @type string $author  Display name, or empty.
	 * }
	 */
	public static function context() {
		$out = array(
			'title'   => '',
			'excerpt' => '',
			'date'    => '',
			'author'  => '',
		);

		if ( is_front_page() ) {
			$out['title'] = get_bloginfo( 'name' );

			return $out;
		}

		if ( is_home() ) {
			$page_id      = (int) get_option( 'page_for_posts' );
			$out['title'] = $page_id ? get_the_title( $page_id ) : __( 'Blog', 'beaver-seo' );

			return $out;
		}

		if ( is_singular() ) {
			$post = get_queried_object();

			if ( $post instanceof WP_Post ) {
				$out['title']   = get_the_title( $post );
				$out['excerpt'] = self::auto_excerpt( $post );
				$out['date']    = get_the_date( '', $post );
				$out['author']  = get_the_author_meta( 'display_name', (int) $post->post_author );
			}

			return $out;
		}

		if ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();

			if ( $term instanceof WP_Term ) {
				$out['title']   = $term->name;
				$out['excerpt'] = wp_strip_all_tags( $term->description );
			}

			return $out;
		}

		if ( is_post_type_archive() ) {
			$out['title'] = post_type_archive_title( '', false );

			return $out;
		}

		if ( is_author() ) {
			$author       = get_queried_object();
			$out['title'] = $author instanceof WP_User ? $author->display_name : '';

			return $out;
		}

		if ( is_date() ) {
			$out['title'] = is_day() ? get_the_date() : ( is_month() ? get_the_date( 'F Y' ) : get_the_date( 'Y' ) );

			return $out;
		}

		if ( is_search() ) {
			$out['title'] = sprintf(
				/* translators: %s: search query. */
				__( 'Search results for "%s"', 'beaver-seo' ),
				get_search_query()
			);

			return $out;
		}

		if ( is_404() ) {
			$out['title'] = __( 'Page not found', 'beaver-seo' );

			return $out;
		}

		$out['title'] = wp_get_document_title();

		return $out;
	}

	/*
	 * -----------------------------------------------------------------------
	 * Description
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Resolves and prints the meta description, if any applies here.
	 *
	 * @since 1.0.0
	 */
	public static function output_description() {
		$description = self::description();

		if ( '' === $description ) {
			return;
		}

		printf( "<meta name=\"description\" content=\"%s\" />\n", esc_attr( $description ) );
	}

	/**
	 * Returns the resolved meta description for the current request.
	 *
	 * Cached for the request so Beaver_SEO_Social can reuse the identical
	 * value for og:description instead of recomputing it.
	 *
	 * @since 1.0.0
	 *
	 * @return string Description, or an empty string when none applies.
	 */
	public static function description() {
		if ( null !== self::$resolved_description ) {
			return self::$resolved_description;
		}

		if ( is_admin() || is_feed() || is_search() || is_404() ) {
			self::$resolved_description = '';

			return '';
		}

		$settings = Beaver_SEO_Settings::all();
		$override = self::current_override( self::META_DESCRIPTION );

		if ( '' !== $override ) {
			self::$resolved_description = self::truncate( self::replace_tokens( $override, self::context() ) );

			return self::$resolved_description;
		}

		if ( is_front_page() ) {
			// Applies whether the front page is a static page or, as is just
			// as common, the default "latest posts" listing — a blog used
			// as a homepage still benefits from a hand-written description,
			// and nothing later in this method would ever produce one for it.
			self::$resolved_description = self::truncate( $settings['description_home'] );

			return self::$resolved_description;
		}

		if ( is_singular() ) {
			$post = get_queried_object();

			self::$resolved_description = $post instanceof WP_Post ? self::truncate( self::auto_excerpt( $post ) ) : '';

			return self::$resolved_description;
		}

		if ( is_category() || is_tag() || is_tax() || is_author() ) {
			$term = get_queried_object();

			if ( $term instanceof WP_Term ) {
				self::$resolved_description = self::truncate( wp_strip_all_tags( $term->description ) );

				return self::$resolved_description;
			}

			if ( $term instanceof WP_User ) {
				self::$resolved_description = self::truncate( wp_strip_all_tags( (string) get_the_author_meta( 'description', $term->ID ) ) );

				return self::$resolved_description;
			}
		}

		self::$resolved_description = '';

		return '';
	}

	/**
	 * Builds a short summary for a post: its excerpt, or a trim of its content.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_Post $post Post object.
	 * @return string Plain text summary.
	 */
	private static function auto_excerpt( $post ) {
		if ( '' !== trim( (string) $post->post_excerpt ) ) {
			return wp_strip_all_tags( $post->post_excerpt );
		}

		$content = wp_strip_all_tags( strip_shortcodes( $post->post_content ) );

		return $content;
	}

	/**
	 * Trims text to a length search engines will not cut off mid-word.
	 *
	 * @since 1.0.0
	 *
	 * @param string $text   Plain text.
	 * @param int    $length Maximum characters. Default 155.
	 * @return string Trimmed text.
	 */
	public static function truncate( $text, $length = 155 ) {
		$text = trim( preg_replace( '/\s+/', ' ', (string) $text ) );

		if ( function_exists( 'mb_strlen' ) ? mb_strlen( $text ) <= $length : strlen( $text ) <= $length ) {
			return $text;
		}

		$trimmed = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $length ) : substr( $text, 0, $length );
		$trimmed = preg_replace( '/\s+?\S*$/', '', $trimmed );

		return rtrim( $trimmed, " \t\n\r\0\x0B,.;:-" ) . '…';
	}

	/*
	 * -----------------------------------------------------------------------
	 * Canonical
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Overrides the canonical URL when this post, term or setting asks for one.
	 *
	 * @since 1.0.0
	 *
	 * @param string $canonical_url URL core resolved.
	 * @param WP_Post|null $post    Post being requested, when applicable.
	 * @return string Possibly overridden canonical URL.
	 */
	public static function canonical( $canonical_url, $post = null ) {
		if ( $post instanceof WP_Post ) {
			$override = trim( (string) get_post_meta( $post->ID, self::META_CANONICAL, true ) );

			if ( '' !== $override ) {
				return esc_url_raw( $override );
			}
		}

		return $canonical_url;
	}

	/*
	 * -----------------------------------------------------------------------
	 * Robots
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Adds this plugin's directives to the robots meta tag core assembles.
	 *
	 * Added to, never replaced: `wp_robots_no_robot()` already covers a site
	 * set to discourage search engines, and that has to keep winning
	 * regardless of anything configured here.
	 *
	 * @since 1.0.0
	 *
	 * @param array $robots Directives collected so far.
	 * @return array Filtered directives.
	 */
	public static function robots( $robots ) {
		if ( is_admin() ) {
			return $robots;
		}

		$settings = Beaver_SEO_Settings::all();

		if ( is_singular() ) {
			$post = get_queried_object();

			if ( $post instanceof WP_Post ) {
				if ( '1' === get_post_meta( $post->ID, self::META_NOINDEX, true ) ) {
					$robots['noindex'] = true;
				}

				if ( '1' === get_post_meta( $post->ID, self::META_NOFOLLOW, true ) ) {
					$robots['nofollow'] = true;
				}
			}
		} elseif ( ( is_category() || is_tag() || is_tax() ) && ! empty( $settings['noindex_archives'] ) ) {
			$robots['noindex'] = true;
		} elseif ( is_search() && ! empty( $settings['noindex_search'] ) ) {
			$robots['noindex'] = true;
		}

		if ( is_paged() && ! empty( $settings['noindex_paginated'] ) ) {
			$robots['noindex'] = true;
		}

		return $robots;
	}

	/*
	 * -----------------------------------------------------------------------
	 * Shared helpers
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Reads a title/description override for whatever the request is about.
	 *
	 * @since 1.0.0
	 *
	 * @param string $meta_key One of the META_* constants.
	 * @return string Override, or an empty string.
	 */
	public static function current_override( $meta_key ) {
		if ( is_singular() ) {
			$post = get_queried_object();

			return $post instanceof WP_Post ? trim( (string) get_post_meta( $post->ID, $meta_key, true ) ) : '';
		}

		if ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();

			return $term instanceof WP_Term ? trim( (string) get_term_meta( $term->term_id, $meta_key, true ) ) : '';
		}

		return '';
	}

	/**
	 * Resolves the social/schema image for the current singular request.
	 *
	 * Falls back from a per-post override, to the featured image, to the
	 * site-wide default configured in settings.
	 *
	 * @since 1.0.0
	 *
	 * @param int $post_id Post ID. 0 to use the site-wide default only.
	 * @return int Attachment ID, or 0 when none is available.
	 */
	public static function resolve_image( $post_id = 0 ) {
		$post_id = (int) $post_id;

		if ( $post_id > 0 ) {
			$override = (int) get_post_meta( $post_id, self::META_IMAGE, true );

			if ( $override > 0 ) {
				return $override;
			}

			$thumbnail = (int) get_post_thumbnail_id( $post_id );

			if ( $thumbnail > 0 ) {
				return $thumbnail;
			}
		}

		return (int) Beaver_SEO_Settings::get( 'social_default_image', 0 );
	}
}
