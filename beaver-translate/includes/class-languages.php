<?php
/**
 * The configured language list.
 *
 * @package BeaverTranslate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads and validates the site's configured languages.
 *
 * There is no fixed list of "supported" languages baked into this plugin —
 * a language is just a code, a name and a native name that an admin typed
 * in, which the AI provider is perfectly capable of translating into
 * without this plugin needing to know anything about it in advance.
 *
 * @since 1.0.0
 */
class Beaver_Translate_Languages {

	/**
	 * Returns every configured language, including the default.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int,array> List of 'code', 'name', 'native'.
	 */
	public static function all() {
		return (array) Beaver_Translate_Settings::get( 'languages', array() );
	}

	/**
	 * Returns every configured language except the site's default.
	 *
	 * This is the list a post can actually be translated into — the default
	 * language is what the original content already is.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int,array> List of 'code', 'name', 'native'.
	 */
	public static function targets() {
		$default = self::default_code();

		return array_values(
			array_filter(
				self::all(),
				static function ( $language ) use ( $default ) {
					return $language['code'] !== $default;
				}
			)
		);
	}

	/**
	 * Returns the site's default (source) language code.
	 *
	 * @since 1.0.0
	 *
	 * @return string Language code.
	 */
	public static function default_code() {
		return (string) Beaver_Translate_Settings::get( 'default_language', 'en' );
	}

	/**
	 * Finds one language by code.
	 *
	 * @since 1.0.0
	 *
	 * @param string $code Language code.
	 * @return array|null Language, or null when the code is not configured.
	 */
	public static function get( $code ) {
		foreach ( self::all() as $language ) {
			if ( $language['code'] === $code ) {
				return $language;
			}
		}

		return null;
	}

	/**
	 * Whether a code is one of the site's configured languages.
	 *
	 * @since 1.0.0
	 *
	 * @param string $code Language code.
	 * @return bool
	 */
	public static function is_valid( $code ) {
		return null !== self::get( $code );
	}

	/**
	 * A human label for a language code, for use in the admin.
	 *
	 * @since 1.0.0
	 *
	 * @param string $code Language code.
	 * @return string Label, or the bare code when it is not configured.
	 */
	public static function label( $code ) {
		$language = self::get( $code );

		if ( ! $language ) {
			return (string) $code;
		}

		return ( $language['native'] && $language['native'] !== $language['name'] )
			? sprintf( '%s (%s)', $language['name'], $language['native'] )
			: $language['name'];
	}

	/**
	 * Post types this plugin is willing to translate at all.
	 *
	 * Excludes attachments the same way Beaver SEO does; a media item has no
	 * body copy worth sending to an AI provider.
	 *
	 * @since 1.0.0
	 *
	 * @return string[] Post type names.
	 */
	public static function translatable_post_types_available() {
		return array_values(
			array_diff(
				get_post_types( array( 'public' => true ), 'names' ),
				array( 'attachment' )
			)
		);
	}

	/**
	 * Validates and normalises a submitted language list.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $input Raw list from $_POST.
	 * @return array<int,array> Cleaned list, codes unique, in submission order.
	 */
	public static function sanitize_list( $input ) {
		$clean = array();
		$seen  = array();

		foreach ( (array) $input as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$code = isset( $row['code'] ) ? self::sanitize_code( wp_unslash( $row['code'] ) ) : '';
			$name = isset( $row['name'] ) ? sanitize_text_field( wp_unslash( $row['name'] ) ) : '';

			if ( '' === $code || '' === $name || isset( $seen[ $code ] ) ) {
				continue;
			}

			$seen[ $code ] = true;

			$clean[] = array(
				'code'   => $code,
				'name'   => $name,
				'native' => isset( $row['native'] ) ? sanitize_text_field( wp_unslash( $row['native'] ) ) : $name,
			);
		}

		return $clean;
	}

	/**
	 * Sanitizes a single language code.
	 *
	 * Kept loose — "zh-Hans", "pt-BR" and "en" are all real, useful codes —
	 * but restricted to what could never break a URL segment, a meta key
	 * suffix or an HTML lang attribute.
	 *
	 * @since 1.0.0
	 *
	 * @param string $code Raw code.
	 * @return string Sanitized code, lowercased except for a region suffix.
	 */
	public static function sanitize_code( $code ) {
		$code = preg_replace( '/[^A-Za-z-]/', '', (string) $code );

		return substr( trim( $code, '-' ), 0, 12 );
	}
}
