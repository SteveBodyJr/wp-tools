<?php
/**
 * One translation batch: prompt, reply schema, per-string checks, clean-up, and the
 * give-up list for strings a model keeps getting wrong.
 *
 * TranslatePress saves only what the engine returns and asks again later for anything
 * missing. A string that fails the checks is left out (English stays on the page) and its
 * failure counted; after MAX_FAILURES it is returned unchanged, so TranslatePress stores the
 * English and stops paying for it, and it is listed for review. Failures of the whole call
 * (key, network, rate limit, cut-off reply) are not the string's fault and are not counted.
 *
 * @package BeaverPress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Batch helpers.
 */
final class BP_Batch {

	/** Option: strings that failed the checks, keyed by language and hash. */
	const REJECT_OPTION = 'beaver_press_rejected';

	/** Failed attempts before a string is kept as it is. */
	const MAX_FAILURES = 2;

	/** Entries kept in the reject list. */
	const MAX_REJECTS = 500;

	/**
	 * Names that are never translated. Starts with the site name; the glossary step adds
	 * destinations, lodges and routes through the `beaver_press_glossary` filter.
	 *
	 * @return string[]
	 */
	public static function glossary() {
		$terms = apply_filters( 'beaver_press_glossary', array( get_bloginfo( 'name' ) ) );
		$terms = array_values( array_unique( array_filter( array_map( 'trim', array_map( 'strval', (array) $terms ) ) ) ) );
		return array_slice( $terms, 0, 400 );
	}

	/**
	 * Glossary names that occur in these texts (what one batch needs).
	 *
	 * @param string[] $texts Batch texts.
	 * @return string[]
	 */
	public static function glossary_for( array $texts ) {
		return BP_Glossary::matching( self::glossary(), $texts );
	}

	/**
	 * System prompt. Stable for a language pair, so providers can cache it.
	 *
	 * @param string   $source   Source language name (English).
	 * @param string   $target   Target language name (French).
	 * @param string[] $glossary Names never translated.
	 * @return string
	 */
	public static function system_prompt( $source, $target, array $glossary, $target_code = '' ) {
		$lines = array(
			trim( (string) BP_Profiles::get( 'context', 'You translate the texts of a website.' ) ) . sprintf( ' Translate from %1$s into %2$s.', $source, $target ),
			'The user sends a JSON object. Reply with a JSON object that has exactly the same keys, each value translated. Reply with the JSON object only.',
			'Rules:',
			'- Keep every HTML tag, attribute and entity exactly as written, around the matching words.',
			'- Keep placeholders such as 1TP1T, 1TP12T, {n} or {total} exactly as written.',
			'- Keep numbers, prices, currencies, dates, times, URLs, email addresses and phone numbers unchanged.',
			'- Use a plain hyphen (-) where you would use an em dash or en dash.',
			'- Translate only. Do not add, drop, summarise or explain anything. Never add claims, ratings, awards or promises that are not in the text.',
			sprintf( '- If a value is already %s, or is a code or proper name, return it unchanged.', $target ),
			sprintf( '- Keep the tone, capitalisation style and line breaks of each value; write fluent %s as a native speaker would.', $target ),
			'- Keep shortcodes such as [name attr="value"], code, file names and markup syntax exactly as written.',
		);
		// The translation profile (Safari & Tourism): style, terminology, places, what never changes.
		$lines = array_merge( $lines, BP_Profiles::prompt_lines( $target ) );
		if ( $glossary ) {
			$lines[] = '- Keep the spelling of these names exactly, never adapting it to the target language: ' . implode( '; ', $glossary ) . '.';
			$example = (string) BP_Profiles::get( 'name_example', '' );
			$lines[] = '' !== $example
				? '- ' . sprintf( $example, $target )
				: sprintf( '- Translate the generic words around or inside those names the way a %1$s website would, keeping only the name itself unchanged. Leave brand, product and company names as they are.', $target );
		}
		// The owner's instructions come last, under the rules (see BP_Instructions).
		$owner = '' !== $target_code && class_exists( 'BP_Instructions' ) ? BP_Instructions::prompt_part( $target_code, $target ) : '';
		if ( '' !== $owner ) {
			$lines[] = '';
			$lines[] = $owner;
			$lines[] = '';
			$lines[] = 'Reply with the JSON object only.';
		}
		return implode( "\n", $lines );
	}

	/**
	 * User message with the batch as JSON.
	 *
	 * @param array<string, string> $map Batch keyed s1, s2...
	 * @return string
	 */
	public static function user_message( array $map ) {
		return "Translate the values:\n" . wp_json_encode( $map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
	}

	/**
	 * Reply schema: one required string per key.
	 *
	 * @param string[] $keys Batch keys.
	 * @return array
	 */
	public static function schema( array $keys ) {
		$properties = array();
		foreach ( $keys as $key ) {
			$properties[ $key ] = array( 'type' => 'string' );
		}
		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => array_values( $keys ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Whether a translation keeps what must not change. True, or a short reason.
	 *
	 * @param string $original    Source text as sent (with placeholders).
	 * @param mixed  $translation Reply value.
	 * @return true|string
	 */
	public static function check( $original, $translation ) {
		if ( ! is_string( $translation ) || '' === trim( $translation ) ) {
			return 'empty';
		}
		if ( self::placeholders( $original ) !== self::placeholders( $translation ) ) {
			return 'placeholders changed';
		}
		if ( self::tags( $original ) !== self::tags( $translation ) ) {
			return 'HTML tags changed';
		}
		if ( self::links( $original ) !== self::links( $translation ) ) {
			return 'links changed';
		}
		if ( mb_strlen( $translation ) > 4 * mb_strlen( $original ) + 40 ) {
			return 'reply far longer than the text';
		}
		return true;
	}

	/**
	 * House rules on a translation that passed: hyphens for dashes, the original's
	 * leading and trailing whitespace.
	 *
	 * @param string $original    Source text.
	 * @param string $translation Translation.
	 * @return string
	 */
	public static function clean( $original, $translation ) {
		$translation = str_replace( array( "\xE2\x80\x94", "\xE2\x80\x93", '&mdash;', '&ndash;', '&#8212;', '&#8211;' ), '-', $translation );
		preg_match( '/^\s*/u', $original, $lead );
		preg_match( '/\s*$/u', $original, $trail );
		return $lead[0] . trim( $translation ) . $trail[0];
	}

	/**
	 * Whether a string has failed often enough to be kept as it is.
	 *
	 * @param string $original Source text.
	 * @param string $language Target language.
	 * @return bool
	 */
	public static function given_up( $original, $language ) {
		$list = self::rejects();
		$id   = self::id( $original, $language );
		return isset( $list[ $id ] ) && (int) $list[ $id ]['count'] >= self::MAX_FAILURES;
	}

	/**
	 * Count a failed attempt for one string.
	 *
	 * @param string $original Source text.
	 * @param string $language Target language.
	 * @param string $reason   Why it failed.
	 */
	public static function record_failure( $original, $language, $reason ) {
		$list        = self::rejects();
		$id          = self::id( $original, $language );
		$list[ $id ] = array(
			'count'    => (int) ( $list[ $id ]['count'] ?? 0 ) + 1,
			'language' => (string) $language,
			'text'     => mb_substr( (string) $original, 0, 300 ),
			'reason'   => (string) $reason,
			'time'     => time(),
		);
		if ( count( $list ) > self::MAX_REJECTS ) {
			uasort( $list, static fn( $a, $b ) => $b['time'] <=> $a['time'] );
			$list = array_slice( $list, 0, self::MAX_REJECTS, true );
		}
		update_option( self::REJECT_OPTION, $list, false );
	}

	/**
	 * The reject list (for the review screen).
	 *
	 * @return array
	 */
	public static function rejects() {
		$list = get_option( self::REJECT_OPTION, array() );
		return is_array( $list ) ? $list : array();
	}

	/**
	 * List key for a string and language.
	 *
	 * @param string $original Source text.
	 * @param string $language Target language.
	 * @return string
	 */
	private static function id( $original, $language ) {
		return $language . ':' . md5( (string) $original );
	}

	/**
	 * Sorted placeholders (1TP1T..., and {name} parts the theme's scripts fill in).
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	private static function placeholders( $text ) {
		preg_match_all( '/1TP\d+T|\{[a-z_]+\}/', $text, $m );
		sort( $m[0] );
		return $m[0];
	}

	/**
	 * Sorted tag names, opening and closing (<b>, </b> -> b, /b).
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	private static function tags( $text ) {
		preg_match_all( '#<\s*(/?)\s*([a-z][a-z0-9-]*)#i', $text, $m, PREG_SET_ORDER );
		$tags = array_map( static fn( $x ) => $x[1] . strtolower( $x[2] ), $m );
		sort( $tags );
		return $tags;
	}

	/**
	 * Sorted href and src values.
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	private static function links( $text ) {
		preg_match_all( '#\b(?:href|src)\s*=\s*(["\'])(.*?)\1#i', $text, $m );
		sort( $m[2] );
		return $m[2];
	}
}
