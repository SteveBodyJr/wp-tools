<?php
/**
 * Translated taxonomy term names.
 *
 * @package BeaverTranslate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Gives a taxonomy term a translated display name per language, without
 * duplicating the term itself.
 *
 * Posts get their own real post per language — a separate row, its own
 * meta, its own review. A term does not need that: nothing about a
 * category or a custom taxonomy term changes per language except the one
 * string a visitor reads, and every post already tagged with it should stay
 * tagged with the same term regardless of which language draft is looking
 * at it. So a term stays exactly one row, one slug, one URL, and this class
 * only ever swaps the *name* WordPress renders for it — via termmeta, one
 * key per configured language — when the current request is under that
 * language's prefix.
 *
 * Deliberately not a parallel rewrite structure the way posts are: a
 * translated term keeps the same slug (`/fr/tour-category/luxury/`, not
 * `/fr/tour-category/luxe/`), so nothing here adds a single new rewrite
 * rule or changes what a term archive's own URL is. That is the whole
 * reason this class is safe to build without a live install to click
 * through against — it never touches the routing surface
 * Beaver_Translate_Router does.
 *
 * @since 1.5.0
 */
class Beaver_Translate_Terms {

	/**
	 * Termmeta key prefix. The full key is this plus a language code, e.g.
	 * `_beaver_translate_term_name_fr`.
	 *
	 * @var string
	 */
	const META_PREFIX = '_beaver_translate_term_name_';

	/**
	 * Registers the hooks.
	 *
	 * @since 1.5.0
	 */
	public static function init() {
		add_filter( 'get_term', array( __CLASS__, 'filter_term_name' ) );
	}

	/**
	 * Swaps a term's name for its translation, when one exists for the
	 * language the current request resolved as.
	 *
	 * Hooked to `get_term`, the one low-level filter nearly every term
	 * display path in WordPress funnels through — get_the_terms(), the_terms(),
	 * wp_get_object_terms(), get_term_by(), single_term_title() — so this
	 * covers all of them from one place rather than reimplementing the same
	 * swap in each template tag individually.
	 *
	 * Admin screens are left untouched on purpose: an editor managing terms
	 * in wp-admin should always see the real, canonical name, never a
	 * translation standing in for it.
	 *
	 * @since 1.5.0
	 *
	 * @param WP_Term $term The term being read.
	 * @return WP_Term The same term, name swapped when a translation exists.
	 */
	public static function filter_term_name( $term ) {
		if ( is_admin() || ! ( $term instanceof WP_Term ) ) {
			return $term;
		}

		$language = Beaver_Translate_Router::current_language();
		$default  = Beaver_Translate_Languages::default_code();

		if ( $language === $default ) {
			return $term;
		}

		$translated = get_term_meta( $term->term_id, self::META_PREFIX . $language, true );

		if ( '' !== $translated && is_string( $translated ) ) {
			$term->name = $translated;
		}

		return $term;
	}

	/**
	 * Translates every term name in a taxonomy into a language, skipping
	 * whatever already has a translation stored unless told to redo it.
	 *
	 * One request per taxonomy, not one per term — the same batched
	 * key => text, key => translation shape
	 * Beaver_Translate_Translator::translate_custom_fields() already uses,
	 * for the same reason: a taxonomy can easily hold a few dozen terms, and
	 * a call per term would turn one taxonomy into a slow sequence of round
	 * trips for something a single request already does.
	 *
	 * @since 1.5.0
	 *
	 * @param string $taxonomy      Taxonomy slug.
	 * @param string $language_code Target language, already configured.
	 * @param bool   $force         Re-translate terms that already have a
	 *                              stored translation for this language.
	 * @return int|WP_Error Number of term names written, or the failure.
	 */
	public static function translate_taxonomy( $taxonomy, $language_code, $force = false ) {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new WP_Error( 'beaver_translate_bad_taxonomy', __( 'That taxonomy does not exist.', 'beaver-translate' ) );
		}

		if ( ! Beaver_Translate_Languages::is_valid( $language_code ) ) {
			return new WP_Error( 'beaver_translate_bad_language', __( 'That language is not configured.', 'beaver-translate' ) );
		}

		$language = Beaver_Translate_Languages::get( $language_code );
		$terms    = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false ) );

		if ( is_wp_error( $terms ) ) {
			return $terms;
		}

		$meta_key  = self::META_PREFIX . $language_code;
		$candidates = array();

		foreach ( $terms as $term ) {
			if ( ! $force && '' !== get_term_meta( $term->term_id, $meta_key, true ) ) {
				continue;
			}

			$candidates[ $term->term_id ] = $term->name;
		}

		if ( empty( $candidates ) ) {
			return 0;
		}

		$system = sprintf(
			'You will receive a JSON object. Each value is the name of a website taxonomy term (a category, a label, a classification) unrelated to the others except that they come from the same site. Translate every value into %s and reply with a JSON object using the exact same keys, each mapped to its translation. A short label stays a short label — do not add articles or explanatory words that were not implied by the original. Do not translate, rename, add or remove any key. Reply with the JSON object only — no preamble, no explanation, no code fence.',
			$language['name']
		) . Beaver_Translate_Translator::glossary_instruction();

		$payload  = wp_json_encode( $candidates, JSON_UNESCAPED_UNICODE );
		$settings = Beaver_Translate_Settings::all();

		$settings['max_tokens'] = min( 32000, max( (int) $settings['max_tokens'], 4000, (int) ( strlen( $payload ) / 2 ) ) );

		$response = Beaver_Translate_Provider::complete( $system, $payload, $settings );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$translated = json_decode( trim( (string) $response ), true );

		if ( ! is_array( $translated ) ) {
			return new WP_Error( 'beaver_translate_parse', __( 'The provider returned a response that could not be read.', 'beaver-translate' ) );
		}

		$written = 0;

		foreach ( $candidates as $term_id => $original ) {
			// json_decode() turns the object's keys into strings; $term_id
			// here is an int from get_terms(), so the lookup has to match
			// on the string form the same way translate_custom_fields() does.
			$key = (string) $term_id;

			if ( ! isset( $translated[ $key ] ) || ! is_string( $translated[ $key ] ) ) {
				continue;
			}

			$new_name = trim( $translated[ $key ] );

			if ( '' === $new_name ) {
				continue;
			}

			update_term_meta( $term_id, $meta_key, $new_name );
			++$written;
		}

		return $written;
	}

	/**
	 * Every public taxonomy this plugin can translate term names for.
	 *
	 * @since 1.5.0
	 *
	 * @return string[] Taxonomy slugs.
	 */
	public static function translatable_taxonomies() {
		return array_values( get_taxonomies( array( 'public' => true ) ) );
	}
}
