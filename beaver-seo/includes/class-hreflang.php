<?php
/**
 * hreflang tags for beaver-translate's language versions.
 *
 * @package BeaverSEO
 */

defined( 'ABSPATH' ) || exit;

/**
 * Prints `<link rel="alternate" hreflang="...">` for every published
 * language version of the current singular post, when beaver-translate is
 * active — and prints nothing at all when it is not.
 *
 * A soft dependency in the same direction beaver-translate already reaches
 * into this plugin: `Beaver_Translate_Translator::approve()` only touches
 * this plugin's own SEO fields behind `class_exists( 'Beaver_SEO_Meta' )`.
 * This class is the other half of that interop, gated the same way, so
 * either plugin works alone and the pair works together the moment both are
 * active — nothing to configure on either side.
 *
 * Deliberately singular-only: beaver-translate's link table can only answer
 * "what are every language's live URLs for this one post", and an archive
 * has no single post to ask that about.
 *
 * @since 1.1.0
 */
class Beaver_SEO_Hreflang {

	/**
	 * Registers the hook, only when there is anything for it to read from.
	 *
	 * @since 1.1.0
	 */
	public static function init() {
		if ( ! class_exists( 'Beaver_Translate_Links' ) ) {
			return;
		}

		// Priority 4: after this plugin's own description (1), social (2)
		// and schema (3) output, all of which already share wp_head without
		// stepping on each other.
		add_action( 'wp_head', array( __CLASS__, 'output' ), 4 );
	}

	/**
	 * Prints one `<link>` per published language version, plus `x-default`
	 * pointing at the site's own default-language URL.
	 *
	 * @since 1.1.0
	 */
	public static function output() {
		if ( ! is_singular() ) {
			return;
		}

		$post = get_queried_object();

		if ( ! ( $post instanceof WP_Post ) ) {
			return;
		}

		$alternates = self::alternates( $post );

		// A page that exists in only one language has nothing to relate
		// itself to — printing hreflang for it would be noise, not signal.
		if ( count( $alternates ) < 2 ) {
			return;
		}

		foreach ( $alternates as $code => $url ) {
			printf(
				'<link rel="alternate" hreflang="%s" href="%s" />' . "\n",
				esc_attr( $code ),
				esc_url( $url )
			);
		}

		$default_code = Beaver_Translate_Languages::default_code();

		if ( isset( $alternates[ $default_code ] ) ) {
			printf(
				'<link rel="alternate" hreflang="x-default" href="%s" />' . "\n",
				esc_url( $alternates[ $default_code ] )
			);
		}
	}

	/**
	 * Every language this post is actually, currently live in.
	 *
	 * Resolves to the source post first, whether the request landed on the
	 * source itself or on one of its translations, since beaver-translate's
	 * link table is always keyed from the source post's side, never the
	 * translation's.
	 *
	 * @since 1.1.0
	 *
	 * @param WP_Post $post The queried post — the source, or one of its translations.
	 * @return array<string,string> Language code => live permalink.
	 */
	private static function alternates( $post ) {
		$source_id = (int) get_post_meta( $post->ID, Beaver_Translate_Translator::META_SOURCE_ID, true );
		$source_id = $source_id > 0 ? $source_id : $post->ID;
		$source    = get_post( $source_id );

		if ( ! ( $source instanceof WP_Post ) || 'publish' !== $source->post_status ) {
			return array();
		}

		$out = array(
			Beaver_Translate_Languages::default_code() => get_permalink( $source_id ),
		);

		foreach ( Beaver_Translate_Links::for_source( $source_id ) as $row ) {
			if ( Beaver_Translate_Links::STATUS_PUBLISHED !== $row->status || ! $row->translated_post_id ) {
				continue;
			}

			$live = get_post( (int) $row->translated_post_id );

			if ( ! ( $live instanceof WP_Post ) || 'publish' !== $live->post_status ) {
				continue;
			}

			$out[ $row->language_code ] = get_permalink( $live->ID );
		}

		return $out;
	}
}
