<?php
/**
 * Structured data (JSON-LD) on translated pages.
 *
 * TranslatePress (free) keeps <script type="application/ld+json"> blocks in the page and hands
 * each one to the `trp_process_other_text_nodes` filter, but translates nothing in them, so
 * search engines read English names, descriptions and FAQ answers on /fr/ pages. Here the
 * text fields go through TranslatePress's own string pipeline (`process_strings()`: stored in
 * the dictionary, translated by the engine with the guard and glossary, reviewable like any
 * other text). Prices, dates, images, @id, @type and every other value are left as they
 * are; links already point at the same language (TranslatePress filters home_url()).
 *
 * @package BeaverPress
 */

defined( 'ABSPATH' ) || exit;

/**
 * JSON-LD translation.
 */
final class BP_Schema {

	/** Keys whose value is text for people. */
	const TEXT_KEYS = array( 'name', 'description', 'headline', 'alternateName', 'alternativeHeadline', 'text', 'caption', 'articleSection', 'abstract', 'disambiguatingDescription' );

	/**
	 * Hooks.
	 */
	public static function init() {
		if ( apply_filters( 'beaver_press_schema', true ) ) {
			add_filter( 'trp_process_other_text_nodes', array( __CLASS__, 'node' ) );
		}
	}

	/**
	 * Translate a JSON-LD block when TranslatePress walks past it.
	 *
	 * @param object $row TranslatePress HTML node.
	 * @return object
	 */
	public static function node( $row ) {
		global $TRP_LANGUAGE;
		$parent = is_object( $row ) ? $row->parent() : null;
		if ( ! $parent || 'script' !== $parent->tag || false === stripos( (string) $parent->getAttribute( 'type' ), 'ld+json' ) ) {
			return $row;
		}
		$trp      = TRP_Translate_Press::get_trp_instance();
		$settings = $trp->get_component( 'settings' )->get_settings();
		if ( empty( $TRP_LANGUAGE ) || $TRP_LANGUAGE === $settings['default-language'] || ( function_exists( 'trp_is_translation_editor' ) && trp_is_translation_editor() ) ) {
			return $row;
		}
		$json = self::translate_json( (string) $row->outertext, $TRP_LANGUAGE );
		if ( null !== $json ) {
			$row->outertext = $json;
		}
		return $row;
	}

	/**
	 * Translate the text fields of a JSON-LD document.
	 *
	 * @param string $json     JSON text.
	 * @param string $language TranslatePress language code.
	 * @return string|null New JSON, or null to leave the block as it is.
	 */
	public static function translate_json( $json, $language ) {
		$data = json_decode( trim( $json ), true );
		if ( ! is_array( $data ) ) {
			return null; // Not valid JSON: never touch it.
		}
		$strings = array();
		self::collect( $data, $strings );
		$strings = array_values( array_unique( $strings ) );

		$map = array();
		if ( $strings ) {
			$trp          = TRP_Translate_Press::get_trp_instance();
			$translations = $trp->get_component( 'translation_render' )->process_strings( $strings, $language );
			foreach ( $strings as $i => $original ) {
				if ( isset( $translations[ $i ] ) && '' !== trim( (string) $translations[ $i ] ) ) {
					$map[ $original ] = (string) $translations[ $i ];
				}
			}
		}
		self::apply( $data, $map );

		$out = wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG );
		return ( false === $out || 'null' === $out ) ? null : $out;
	}

	/**
	 * Gather translatable strings.
	 *
	 * @param mixed    $node    JSON node.
	 * @param string[] $strings Collected strings.
	 * @param string   $key     Key of this node in its parent.
	 */
	private static function collect( $node, array &$strings, $key = '' ) {
		if ( is_array( $node ) ) {
			foreach ( $node as $k => $v ) {
				// Items of a list inherit the list's key (articleSection: ["Kilimanjaro", ...]).
				self::collect( $v, $strings, is_int( $k ) ? $key : (string) $k );
			}
			return;
		}
		if ( is_string( $node ) && in_array( $key, self::TEXT_KEYS, true ) && self::wanted( $node ) ) {
			$strings[] = trim( $node );
		}
	}

	/**
	 * Put the translations back.
	 *
	 * @param mixed  $node JSON node (by reference).
	 * @param array  $map  Original => translation.
	 * @param string $key  Key of this node in its parent.
	 */
	private static function apply( &$node, array $map, $key = '' ) {
		if ( is_array( $node ) ) {
			foreach ( $node as $k => &$v ) {
				self::apply( $v, $map, is_int( $k ) ? $key : (string) $k );
			}
			unset( $v );
			return;
		}
		if ( is_string( $node ) && in_array( $key, self::TEXT_KEYS, true ) && isset( $map[ trim( $node ) ] ) ) {
			$node = $map[ trim( $node ) ];
		}
	}

	/**
	 * Whether a value is worth translating: has letters, is not a link, not the site's name.
	 *
	 * @param string $text Value.
	 * @return bool
	 */
	private static function wanted( $text ) {
		$text = trim( $text );
		return '' !== $text
			&& preg_match( '/\p{L}{2}/u', $text )
			&& false === filter_var( $text, FILTER_VALIDATE_URL )
			&& html_entity_decode( $text, ENT_QUOTES, 'UTF-8' ) !== html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' );
	}
}
