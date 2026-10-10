<?php
/**
 * JSON-LD structured data.
 *
 * @package BeaverSEO
 */

defined( 'ABSPATH' ) || exit;

/**
 * Prints a small, deliberately conservative set of schema.org markup.
 *
 * Only what WordPress already knows without asking: an Organization/Website
 * graph on every page, and an Article on singular posts. No review stars, no
 * FAQ blocks, no product data — those describe content this plugin has no way
 * to verify is true, and wrong structured data is worse than none.
 *
 * @since 1.0.0
 */
class Beaver_SEO_Schema {

	/**
	 * Registers the front-end hook.
	 *
	 * @since 1.0.0
	 */
	public static function init() {
		add_action( 'wp_head', array( __CLASS__, 'output' ), 3 );
	}

	/**
	 * Prints the JSON-LD script tag.
	 *
	 * @since 1.0.0
	 */
	public static function output() {
		if ( is_admin() || is_feed() || is_search() || is_404() ) {
			return;
		}

		$graph = array( self::website_node() );

		$organization = self::organization_node();

		if ( $organization ) {
			$graph[] = $organization;
		}

		if ( is_singular() && 'post' === get_post_type() ) {
			$article = self::article_node();

			if ( $article ) {
				$graph[] = $article;
			}
		}

		$data = array(
			'@context' => 'https://schema.org',
			'@graph'   => $graph,
		);

		/**
		 * Filters the full JSON-LD graph before it is printed.
		 *
		 * @since 1.0.0
		 *
		 * @param array $data The `@context` / `@graph` structure.
		 */
		$data = apply_filters( 'beaver_seo_schema', $data );

		printf(
			"<script type=\"application/ld+json\">%s</script>\n",
			wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
		);
	}

	/**
	 * Builds the WebSite node, present on every page.
	 *
	 * @since 1.0.0
	 *
	 * @return array Node.
	 */
	private static function website_node() {
		$settings = Beaver_SEO_Settings::all();

		return array(
			'@type' => 'WebSite',
			'@id'   => home_url( '/#website' ),
			'url'   => home_url( '/' ),
			'name'  => '' !== $settings['site_name'] ? $settings['site_name'] : get_bloginfo( 'name' ),
		);
	}

	/**
	 * Builds the Organization (or Person) node, when a name is configured.
	 *
	 * Left out entirely rather than guessed, when nobody has told this plugin
	 * who or what the site represents.
	 *
	 * @since 1.0.0
	 *
	 * @return array|null Node, or null when no organization name is set.
	 */
	private static function organization_node() {
		$settings = Beaver_SEO_Settings::all();

		if ( '' === trim( (string) $settings['organization_name'] ) ) {
			return null;
		}

		$node = array(
			'@type' => $settings['organization_type'],
			'@id'   => home_url( '/#organization' ),
			'name'  => $settings['organization_name'],
			'url'   => home_url( '/' ),
		);

		$logo_id = (int) $settings['organization_logo'];

		if ( $logo_id > 0 ) {
			$logo = wp_get_attachment_image_src( $logo_id, 'medium' );

			if ( $logo ) {
				$node['logo'] = array(
					'@type'  => 'ImageObject',
					'url'    => $logo[0],
					'width'  => (int) $logo[1],
					'height' => (int) $logo[2],
				);
			}
		}

		return $node;
	}

	/**
	 * Builds the Article node for the current singular post.
	 *
	 * @since 1.0.0
	 *
	 * @return array|null Node, or null off a singular post request.
	 */
	private static function article_node() {
		$post = get_queried_object();

		if ( ! $post instanceof WP_Post ) {
			return null;
		}

		$node = array(
			'@type'         => 'Article',
			'headline'      => get_the_title( $post ),
			'url'           => (string) get_permalink( $post ),
			'datePublished' => get_the_date( DATE_W3C, $post ),
			'dateModified'  => get_the_modified_date( DATE_W3C, $post ),
			'mainEntityOfPage' => array(
				'@type' => 'WebPage',
				'@id'   => (string) get_permalink( $post ),
			),
			'author'        => array(
				'@type' => 'Person',
				'name'  => get_the_author_meta( 'display_name', (int) $post->post_author ),
			),
			'publisher'     => array(
				'@id' => home_url( '/#organization' ),
			),
			'isPartOf'      => array(
				'@id' => home_url( '/#website' ),
			),
		);

		$image_id = Beaver_SEO_Meta::resolve_image( $post->ID );

		if ( $image_id > 0 ) {
			$image = wp_get_attachment_image_src( $image_id, 'large' );

			if ( $image ) {
				$node['image'] = array(
					'@type'  => 'ImageObject',
					'url'    => $image[0],
					'width'  => (int) $image[1],
					'height' => (int) $image[2],
				);
			}
		}

		// publisher only makes sense once an Organization node exists to point
		// at; without a name configured, self::organization_node() returns
		// null and the reference above would dangle.
		if ( '' === trim( (string) Beaver_SEO_Settings::get( 'organization_name', '' ) ) ) {
			unset( $node['publisher'] );
		}

		return $node;
	}
}
