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
	public static function system_prompt( $source, $target, array $glossary, $target_code = '', $texts = null ) {
		$lines = array(
			trim( (string) BP_Profiles::get( 'context', 'You translate the texts of a website.' ) ) . sprintf( ' Translate from %1$s into %2$s.', $source, $target ),
			'The user sends a JSON object. Reply with a JSON object that has exactly the same keys, each value translated. Reply with the JSON object only.',
			'Rules:',
			'- Keep every HTML tag, attribute and entity exactly as written, around the matching words.',
			'- Keep placeholders such as 1TP1T, 1TP12T, {n} or {total} exactly as written.',
			'- Keep numbers, prices, currencies, dates, times, URLs, email addresses and phone numbers unchanged.',
			'- Use a plain hyphen (-) where you would use an em dash or en dash.',
			'- Translate only. Do not add, drop, summarise or explain anything. Never add claims, ratings, awards or promises that are not in the text.',
			'- Keep the exact meaning: never drop or reverse a negation (not, no, never, without), and keep what is included or excluded, optional or required, private or shared, one-way or return exactly as the text says.',
			'- Keep buttons, menu items, headings and image descriptions about as short as the original.',
			sprintf( '- If a value is already %s, or is a code or proper name, return it unchanged.', $target ),
			sprintf( '- Keep the tone, capitalisation style and line breaks of each value; write fluent %s as a native speaker would.', $target ),
			'- Keep shortcodes such as [name attr="value"], code, file names and markup syntax exactly as written.',
		);
		// The translation profile (Safari & Tourism): style, terminology, places, what never changes.
		$lines = array_merge( $lines, BP_Profiles::prompt_lines( $target ) );
		// The owner's term translations (those in this batch) and search words for the language.
		if ( '' !== $target_code && class_exists( 'BP_Terms' ) ) {
			$lines = array_merge( $lines, BP_Terms::prompt_lines( $target_code, $target, $texts ) );
		}
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
	 * Whether a translation keeps what must not change (structure, then facts). True, or a
	 * short reason.
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
		return self::facts( $original, $translation );
	}

	/**
	 * Whether the facts of the original survive in the translation: every number (prices,
	 * dates, times, durations, phone numbers), e-mail address, web address and shortcode.
	 * True, or a short reason.
	 *
	 * Numbers are compared by value, not by format: 3,550, 3.550 and 3 550 are the same
	 * number, so a language may write it its own way. A translation may hold numbers the
	 * original does not (Chinese writes "October" as 10月); it may not lose or change one.
	 *
	 * @since 2.6.3
	 *
	 * @param string $original    Source text as sent.
	 * @param string $translation Reply value.
	 * @return true|string
	 */
	public static function facts( $original, $translation ) {
		$source = self::plain( $original );
		$target = self::plain( $translation );
		if ( ! self::numbers_kept( $source, $target ) ) {
			return 'numbers changed';
		}
		if ( ! self::contains( self::addresses( $target ), self::addresses( $source ) ) ) {
			return 'e-mail or web address changed';
		}
		if ( self::shortcodes( $original ) !== self::shortcodes( $translation ) ) {
			return 'shortcodes changed';
		}
		return true;
	}

	/**
	 * Readable text only: no placeholders, tags or entities (their digits are not facts),
	 * full-width digits as plain ones.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function plain( $text ) {
		// Entities first: the engine stores some markup escaped (&lt;b&gt;), and an id in it is no fact.
		$text = html_entity_decode( (string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = preg_replace( '/1TP\d+T/', ' ', $text );
		$text = preg_replace( '/<[^>]*>/', ' ', $text );
		return strtr( $text, array_combine( preg_split( '//u', '０１２３４５６７８９', -1, PREG_SPLIT_NO_EMPTY ), range( 0, 9 ) ) );
	}

	/**
	 * Numbers by value: digit groups of three joined (thousands written with a comma, dot
	 * or space, also the full-width ones), everything else split on any non-digit. Chinese
	 * and Japanese number words up to ten count as their digit, so 五星级 keeps "5-Star".
	 *
	 * @param string $text Plain text.
	 * @return string[]
	 */
	private static function numbers( $text ) {
		preg_match_all( '/\d{1,3}(?:[,.\x{00A0}\x{202F}\x{2009} \'’，．]\d{3})+(?!\d)|\d+|[〇零一二两兩三四五六七八九十]/u', $text, $m );
		$words = array( '〇' => '0', '零' => '0', '一' => '1', '二' => '2', '两' => '2', '兩' => '2', '三' => '3', '四' => '4', '五' => '5', '六' => '6', '七' => '7', '八' => '8', '九' => '9', '十' => '10' );
		return array_map( static fn( $n ) => ltrim( $words[ $n ] ?? preg_replace( '/\D/', '', $n ), '0' ) . '#', $m[0] );
	}

	/**
	 * Whether every number of the source is in the target. Allowed other ways of writing
	 * the same thing: 11 pm as 23, the 19th century as XIX or 1800, and terms that are names
	 * rather than numbers (4x4, 24-hour, 24/7), which a language may say in words.
	 *
	 * @param string $source Plain source text.
	 * @param string $target Plain translation.
	 * @return bool
	 */
	private static function numbers_kept( $source, $target ) {
		$needed = array(); // Each entry: the values any one of which will do.
		$source = preg_replace( '/\b(?:4\s*[x×]\s*4|24\s*\/\s*7|24[- ]hours?)\b/iu', ' ', $source );
		$source = preg_replace_callback(
			'/\b(\d{1,2})(?:st|nd|rd|th)[- ]centur(?:y|ies)\b/i',
			static function ( $m ) use ( $target, &$needed ) {
				$n = (int) $m[1];
				if ( ! preg_match( '/(?<![IVXLC])' . self::roman( $n ) . '(?![IVXLC])|' . ( $n - 1 ) . '00/u', $target ) ) {
					$needed[] = array( $n . '#' );
				}
				return ' ';
			},
			$source
		);
		// "1.5 million" may be written in units of ten thousand (150万).
		$source = preg_replace_callback(
			'/(\d+(?:[.,]\d+)?)\s*million\b/i',
			static function ( $m ) use ( $target ) {
				$wan = (string) round( (float) str_replace( ',', '.', $m[1] ) * 100, 2 );
				return preg_match( '/(?<![\d.])' . preg_quote( $wan, '/' ) . '\s*[万萬]/u', $target ) ? ' ' : $m[0];
			},
			$source
		);
		// A discount written as the price paid (Chinese): save 10% as 9折, 15% as 85折 or 8.5折.
		if ( false !== strpos( $target, '折' ) ) {
			$source = preg_replace_callback(
				'/(?<![\d.])(\d{1,2})\s*%/u',
				static function ( $m ) use ( $target ) {
					$paid  = 100 - (int) $m[1];
					$forms = 0 === $paid % 10 ? array( (string) ( $paid / 10 ) ) : array( (string) $paid, (string) ( $paid / 10 ) );
					foreach ( $forms as $form ) {
						if ( preg_match( '/(?<![\d.])' . preg_quote( $form, '/' ) . '\s*折/u', $target ) ) {
							return ' ';
						}
					}
					return $m[0];
				},
				$source
			);
		}
		// Any large number likewise: 200,000 as 20万.
		$source = preg_replace_callback(
			'/\d{1,3}(?:[,.\x{00A0}\x{202F} ]\d{3})+(?!\d)|\d{5,}/u',
			static function ( $m ) use ( $target ) {
				$value = (int) preg_replace( '/\D/', '', $m[0] );
				if ( $value < 10000 ) {
					return $m[0];
				}
				$wan = rtrim( rtrim( number_format( $value / 10000, 4, '.', '' ), '0' ), '.' );
				return preg_match( '/(?<![\d.])' . preg_quote( $wan, '/' ) . '\s*[万萬]/u', $target ) ? ' ' : $m[0];
			},
			$source
		);
		$source = preg_replace_callback(
			'/\b(\d{1,2})(?:[:.](\d{2}))?\s*([ap])\.?m\b\.?/i',
			static function ( $m ) use ( &$needed ) {
				$hour  = (int) $m[1];
				$alts  = array( $hour . '#' );
				$alts[] = ( 'p' === strtolower( $m[3] ) ? ( $hour % 12 ) + 12 : $hour % 12 ) . '#';
				$needed[] = array_unique( array_map( static fn( $a ) => '0#' === $a ? '#' : $a, $alts ) );
				if ( '' !== ( $m[2] ?? '' ) ) {
					$needed[] = array( ltrim( $m[2], '0' ) . '#' );
				}
				return ' ';
			},
			$source
		);
		foreach ( self::numbers( $source ) as $n ) {
			$needed[] = array( $n );
		}
		$have = array_count_values( self::numbers( $target ) );
		foreach ( $needed as $alts ) {
			$found = false;
			foreach ( $alts as $a ) {
				if ( ! empty( $have[ $a ] ) ) {
					--$have[ $a ];
					$found = true;
					break;
				}
			}
			if ( ! $found ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Roman numeral for a century (1-39).
	 *
	 * @param int $n Number.
	 * @return string
	 */
	private static function roman( $n ) {
		$out = '';
		foreach ( array( 10 => 'X', 9 => 'IX', 5 => 'V', 4 => 'IV', 1 => 'I' ) as $v => $r ) {
			for ( ; $n >= $v; $n -= $v ) {
				$out .= $r;
			}
		}
		return $out;
	}

	/**
	 * E-mail and web addresses written in the text, lower case.
	 *
	 * @param string $text Plain text.
	 * @return string[]
	 */
	private static function addresses( $text ) {
		preg_match_all( '#[\w.+-]+@[\w-]+(?:\.[\w-]+)+|\bhttps?://[^\s<>"\']+|\bwww\.[^\s<>"\']+#iu', $text, $m );
		return array_map( static fn( $a ) => strtolower( rtrim( $a, '.,;:!?)' ) ), $m[0] );
	}

	/**
	 * Sorted shortcodes the site really has, exactly as written.
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	private static function shortcodes( $text ) {
		preg_match_all( '/\[\/?([a-zA-Z0-9_-]+)(?:\s[^\]]*)?\]/', (string) $text, $m, PREG_SET_ORDER );
		$out = array();
		foreach ( $m as $x ) {
			if ( function_exists( 'shortcode_exists' ) && shortcode_exists( $x[1] ) ) {
				$out[] = $x[0];
			}
		}
		sort( $out );
		return $out;
	}

	/**
	 * Whether every item of $needed is in $have, counting repeats.
	 *
	 * @param string[] $have   Found in the translation.
	 * @param string[] $needed Found in the original.
	 * @return bool
	 */
	private static function contains( array $have, array $needed ) {
		$count = array_count_values( $have );
		foreach ( $needed as $item ) {
			if ( empty( $count[ $item ] ) ) {
				return false;
			}
			--$count[ $item ];
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
