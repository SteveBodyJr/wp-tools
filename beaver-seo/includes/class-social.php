<?php
/**
 * Open Graph and Twitter Card output.
 *
 * @package BeaverSEO
 */

defined( 'ABSPATH' ) || exit;

/**
 * Prints og: and twitter: tags for the current request.
 *
 * Reuses Beaver_SEO_Meta's already-resolved title and description rather
 * than recomputing them, so the social preview and the search-engine tags
 * can never say two different things about the same page.
 *
 * @since 1.0.0
 */
class Beaver_SEO_Social {

	/**
	 * Registers the front-end hook.
	 *
	 * @since 1.0.0
	 */
	public static function init() {
		add_action( 'wp_head', array( __CLASS__, 'output' ), 2 );
	}

	/**
	 * Prints the tags.
	 *
	 * @since 1.0.0
	 */
	public static function output() {
		if ( is_admin() || is_feed() || is_search() || is_404() ) {
			return;
		}

		$settings = Beaver_SEO_Settings::all();
		$context  = Beaver_SEO_Meta::context();
		$title    = $context['title'];
		$description = Beaver_SEO_Meta::description();
		$url      = self::current_url();
		$post_id  = is_singular() ? (int) get_queried_object_id() : 0;

		$tags = array(
			'og:type'        => is_singular() && 'post' === get_post_type( $post_id ) ? 'article' : 'website',
			'og:title'       => $title,
			'og:url'         => $url,
			'og:site_name'   => '' !== $settings['site_name'] ? $settings['site_name'] : get_bloginfo( 'name' ),
			'og:locale'      => get_locale(),
		);

		if ( '' !== $description ) {
			$tags['og:description'] = $description;
		}

		$image = self::image_data( Beaver_SEO_Meta::resolve_image( $post_id ) );

		if ( $image ) {
			$tags['og:image']        = $image['url'];
			$tags['og:image:width']  = $image['width'];
			$tags['og:image:height'] = $image['height'];
			$tags['og:image:alt']    = $image['alt'];
		}

		if ( is_singular() && 'post' === get_post_type( $post_id ) ) {
			$post = get_post( $post_id );

			if ( $post instanceof WP_Post ) {
				$tags['article:published_time'] = get_the_date( DATE_W3C, $post );
				$tags['article:modified_time']  = get_the_modified_date( DATE_W3C, $post );
			}
		}

		foreach ( $tags as $property => $content ) {
			if ( '' === $content ) {
				continue;
			}

			printf(
				"<meta property=\"%s\" content=\"%s\" />\n",
				esc_attr( $property ),
				esc_attr( $content )
			);
		}

		self::output_twitter( $title, $description, $image, $settings );
	}

	/**
	 * Prints the Twitter Card tags.
	 *
	 * Twitter falls back to the og: tags above when its own are absent, but a
	 * card type still has to be declared explicitly or no card renders at all.
	 *
	 * @since 1.0.0
	 *
	 * @param string     $title       Resolved title.
	 * @param string     $description Resolved description.
	 * @param array|null $image       Image data from self::image_data(), or null.
	 * @param array      $settings    Plugin settings.
	 */
	private static function output_twitter( $title, $description, $image, array $settings ) {
		printf( "<meta name=\"twitter:card\" content=\"%s\" />\n", esc_attr( $image ? $settings['twitter_card_type'] : 'summary' ) );

		if ( '' !== $settings['twitter_site'] ) {
			printf( "<meta name=\"twitter:site\" content=\"%s\" />\n", esc_attr( $settings['twitter_site'] ) );
		}

		printf( "<meta name=\"twitter:title\" content=\"%s\" />\n", esc_attr( $title ) );

		if ( '' !== $description ) {
			printf( "<meta name=\"twitter:description\" content=\"%s\" />\n", esc_attr( $description ) );
		}

		if ( $image ) {
			printf( "<meta name=\"twitter:image\" content=\"%s\" />\n", esc_attr( $image['url'] ) );
		}
	}

	/**
	 * Resolves an attachment to the data a social tag needs.
	 *
	 * @since 1.0.0
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array|null {
	 *     @type string $url    Full-size image URL.
	 *     @type int    $width  Pixel width.
	 *     @type int    $height Pixel height.
	 *     @type string $alt    Alt text, may be empty.
	 * }
	 */
	private static function image_data( $attachment_id ) {
		$attachment_id = (int) $attachment_id;

		if ( $attachment_id <= 0 ) {
			return null;
		}

		$data = wp_get_attachment_image_src( $attachment_id, 'large' );

		if ( ! $data ) {
			return null;
		}

		return array(
			'url'    => $data[0],
			'width'  => (int) $data[1],
			'height' => (int) $data[2],
			'alt'    => get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
		);
	}

	/**
	 * Returns the canonical-preferring URL for the current request.
	 *
	 * @since 1.0.0
	 *
	 * @return string Absolute URL.
	 */
	private static function current_url() {
		if ( is_singular() ) {
			$canonical = get_permalink();

			return $canonical ? $canonical : home_url( add_query_arg( array(), $GLOBALS['wp']->request ?? '' ) );
		}

		global $wp;

		return home_url( add_query_arg( array(), $wp->request ) );
	}
}
