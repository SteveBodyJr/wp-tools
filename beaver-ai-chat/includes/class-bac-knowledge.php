<?php
/**
 * Builds the knowledge the assistant is grounded in.
 *
 * The site is indexed once into a list of items and cached. What gets sent with
 * a message is then chosen per question rather than wholesale: the visitor's
 * words are scored against the index and only the entries that match are
 * expanded in full.
 *
 * This matters because knowledge is paid for on every single message. Sending
 * the whole site each time is the largest line on the bill for a site of any
 * size, and it gets worse as the site grows: the answer to "how much is the
 * three day trip" is somewhere in fourteen thousand characters of everything
 * else, which costs more and reads worse than sending the three day trip.
 *
 * A short catalogue of every title still goes with each message, so the
 * assistant always knows the full range of what exists and never denies
 * something the site offers just because its details were not selected.
 *
 * Nothing here is hard coded to a particular site: the admin chooses which post
 * types and taxonomies to index, and which meta keys hold prices.
 *
 * @package BeaverAIChat
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class BAC_Knowledge
 */
class BAC_Knowledge {

	/** Legacy flat digest, still cleared so upgrades do not leave it behind. */
	const CACHE_KEY = 'bac_knowledge';

	/** The indexed site. */
	const INDEX_KEY = 'bac_knowledge_index';

	/**
	 * The knowledge to send with one message.
	 *
	 * @param array|null  $s     Settings.
	 * @param string      $query What the visitor just asked, when it is known.
	 * @return string
	 */
	public static function get( $s = null, $query = '' ) {
		$s = null === $s ? BAC_Settings::get() : $s;

		if ( empty( $s['kb_enabled'] ) ) {
			return '';
		}

		$index = self::index( $s );

		if ( empty( $index['items'] ) && empty( $index['taxonomies'] ) ) {
			return '';
		}

		$relevant = ( 'relevant' === $s['kb_mode'] );
		$terms    = $relevant ? self::terms( $query ) : array();

		$digest = ( $relevant && ! empty( $terms ) )
			? self::selected( $index, $terms, $query, $s )
			: self::everything( $index, $s );

		/**
		 * Filter the knowledge digest just before it joins the prompt.
		 *
		 * @param string $digest Digest text.
		 * @param array  $s      Settings.
		 * @param string $query  The visitor's message, when known.
		 */
		return (string) apply_filters( 'bac_knowledge_digest', $digest, $s, $query );
	}

	/** Drop the cached index so the next request rebuilds it. */
	public static function flush_cache() {
		delete_transient( self::CACHE_KEY );
		delete_transient( self::INDEX_KEY );
	}

	/* --------------------------------------------------------------- Indexing */

	/**
	 * The indexed site, built on first use and cached.
	 *
	 * @param array $s Settings.
	 * @return array array( items, taxonomies )
	 */
	public static function index( $s ) {
		$hours = (int) $s['kb_cache_hours'];

		if ( $hours > 0 ) {
			$cached = get_transient( self::INDEX_KEY );
			if ( is_array( $cached ) && isset( $cached['items'] ) ) {
				return $cached;
			}
		}

		$index = self::build( $s );

		if ( $hours > 0 ) {
			set_transient( self::INDEX_KEY, $index, $hours * HOUR_IN_SECONDS );
		}

		return $index;
	}

	/**
	 * Read the configured post types and taxonomies into an index.
	 *
	 * @param array $s Settings.
	 * @return array
	 */
	public static function build( $s ) {
		$per_type   = (int) $s['kb_per_type'];
		$item_chars = (int) $s['kb_item_chars'];
		$price_keys = BAC_Settings::to_list( $s['kb_price_meta'] );
		$extra_keys = BAC_Settings::to_list( $s['kb_extra_meta'] );
		$exclude    = array_map( 'sanitize_title', BAC_Settings::to_list( $s['kb_exclude'] ) );

		$items      = array();
		$taxonomies = array();

		foreach ( (array) $s['kb_post_types'] as $post_type ) {
			if ( ! post_type_exists( $post_type ) ) {
				continue;
			}

			$posts = get_posts(
				array(
					'post_type'        => $post_type,
					'post_status'      => 'publish',
					'posts_per_page'   => $per_type,
					'orderby'          => array(
						'menu_order' => 'ASC',
						'title'      => 'ASC',
					),
					'suppress_filters' => false,
					'no_found_rows'    => true,
				)
			);

			if ( empty( $posts ) ) {
				continue;
			}

			$object = get_post_type_object( $post_type );
			$label  = ( $object && isset( $object->labels->name ) ) ? $object->labels->name : $post_type;

			foreach ( $posts as $post ) {
				if ( in_array( $post->post_name, $exclude, true ) ) {
					continue;
				}

				$title = get_the_title( $post );
				$line  = '- ' . $title;
				$meta  = '';

				$price = self::first_meta( $post->ID, $price_keys );
				if ( '' !== $price ) {
					$line .= ' (' . $price . ')';
					$meta .= ' ' . $price;
				}

				foreach ( $extra_keys as $meta_key ) {
					$value = self::meta_value( $post->ID, $meta_key );
					if ( '' !== $value ) {
						$line .= ', ' . $meta_key . ': ' . $value;
						$meta .= ' ' . $meta_key . ' ' . $value;
					}
				}

				$permalink = wp_make_link_relative( get_permalink( $post ) );
				if ( $permalink ) {
					$line .= ' [' . $permalink . ']';
				}

				$body = self::clip(
					'' !== trim( (string) $post->post_content ) ? $post->post_content : $post->post_excerpt,
					$item_chars
				);
				if ( '' !== $body ) {
					$line .= "\n  " . $body;
				}

				$items[] = array(
					'group' => strtoupper( $label ),
					'title' => $title,
					'line'  => $line,
					// Lowercased once here so scoring a message is only string
					// searching, not case folding the whole site per turn.
					'tl'    => self::fold( $title ),
					'bl'    => self::fold( $title . ' ' . $body . ' ' . $meta ),
				);
			}
		}

		foreach ( (array) $s['kb_taxonomies'] as $taxonomy ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}

			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => true,
					'number'     => 60,
				)
			);
			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				continue;
			}

			$object = get_taxonomy( $taxonomy );
			$label  = ( $object && isset( $object->labels->name ) ) ? $object->labels->name : $taxonomy;

			$taxonomies[] = strtoupper( $label ) . ': ' . implode( ', ', wp_list_pluck( $terms, 'name' ) ) . '.';
		}

		$index = array(
			'items'      => $items,
			'taxonomies' => $taxonomies,
		);

		/**
		 * Filter the indexed site before anything is selected from it.
		 *
		 * @param array $index array( items, taxonomies ).
		 * @param array $s     Settings.
		 */
		return (array) apply_filters( 'bac_knowledge_index', $index, $s );
	}

	/* -------------------------------------------------------------- Selecting */

	/**
	 * Everything, in order, clipped to the budget. What the plugin has always
	 * done, and still the right answer when nothing has been asked yet or the
	 * admin prefers it.
	 *
	 * @param array $index Index.
	 * @param array $s     Settings.
	 * @return string
	 */
	private static function everything( $index, $s ) {
		return self::assemble( $index['items'], $index['taxonomies'], '', $s );
	}

	/**
	 * The entries that answer this question, plus a catalogue of everything
	 * else so nothing the site offers can be denied outright.
	 *
	 * @param array  $index Index.
	 * @param array  $terms Search terms.
	 * @param string $query Raw message.
	 * @param array  $s     Settings.
	 * @return string
	 */
	private static function selected( $index, $terms, $query, $s ) {
		$phrase = self::fold( $query );
		$scored = array();

		foreach ( $index['items'] as $position => $item ) {
			$score = self::score( $item, $terms, $phrase );
			if ( $score > 0 ) {
				$scored[] = array(
					'score'    => $score,
					'position' => $position,
					'item'     => $item,
				);
			}
		}

		// Nothing matched: the visitor is asking something general, or in a
		// language the index is not written in. Fall back to the whole digest
		// rather than answering from an empty page.
		if ( empty( $scored ) ) {
			return self::everything( $index, $s );
		}

		usort(
			$scored,
			static function ( $a, $b ) {
				if ( $a['score'] === $b['score'] ) {
					return $a['position'] - $b['position']; // Keep the site's own order.
				}
				return $b['score'] - $a['score'];
			}
		);

		$top = array_slice( $scored, 0, max( 1, (int) $s['kb_top_k'] ) );

		// Put them back in the site's order so the digest still reads as a list
		// rather than a ranking.
		usort(
			$top,
			static function ( $a, $b ) {
				return $a['position'] - $b['position'];
			}
		);

		$catalogue = self::catalogue( $index['items'], wp_list_pluck( wp_list_pluck( $top, 'item' ), 'title' ) );

		return self::assemble( wp_list_pluck( $top, 'item' ), $index['taxonomies'], $catalogue, $s );
	}

	/**
	 * How well one entry answers the question.
	 *
	 * A hit in the title counts for much more than one in the body: someone
	 * asking about "Kilimanjaro" wants the page called Kilimanjaro, not the
	 * page that mentions it once in passing.
	 *
	 * @param array  $item   Index item.
	 * @param array  $terms  Search terms.
	 * @param string $phrase The whole message, folded.
	 * @return int
	 */
	private static function score( $item, $terms, $phrase ) {
		$score = 0;

		foreach ( $terms as $term ) {
			$in_title = substr_count( $item['tl'], $term );
			$in_body  = substr_count( $item['bl'], $term );

			if ( $in_title > 0 ) {
				$score += 6 + min( $in_title, 3 );
			}
			if ( $in_body > 0 ) {
				$score += min( $in_body, 4 );
			}
		}

		// The visitor typed the title, or something very close to it.
		if ( '' !== $phrase && $score > 0 ) {
			if ( false !== strpos( $phrase, $item['tl'] ) && mb_strlen( $item['tl'] ) > 4 ) {
				$score += 12;
			}
		}

		return $score;
	}

	/**
	 * The words worth searching on, out of what the visitor wrote.
	 *
	 * @param string $query Raw message.
	 * @return array
	 */
	public static function terms( $query ) {
		$folded = self::fold( $query );

		if ( '' === $folded ) {
			return array();
		}

		$words = preg_split( '/\s+/u', $folded );
		$stop  = self::stopwords();
		$out   = array();

		foreach ( (array) $words as $word ) {
			if ( mb_strlen( $word ) < 3 || in_array( $word, $stop, true ) ) {
				continue;
			}
			$out[ $word ] = true;
		}

		// Long messages are mostly noise after the first dozen useful words.
		return array_slice( array_keys( $out ), 0, 12 );
	}

	/**
	 * Words too common to tell one page from another.
	 *
	 * English only, deliberately short. A question in another language simply
	 * keeps all its words, which costs a little precision and never breaks:
	 * a question that matches nothing falls back to the whole digest.
	 *
	 * @return array
	 */
	private static function stopwords() {
		/**
		 * Filter the words ignored when matching a question to the site.
		 *
		 * @param array $stopwords Lowercase words.
		 */
		return (array) apply_filters(
			'bac_stopwords',
			array(
				'the', 'and', 'for', 'you', 'your', 'are', 'can', 'not', 'but', 'with', 'from', 'that', 'this',
				'have', 'has', 'had', 'was', 'were', 'will', 'would', 'could', 'should', 'about', 'what', 'when',
				'where', 'which', 'who', 'why', 'how', 'get', 'got', 'any', 'all', 'out', 'our', 'its', 'their',
				'there', 'here', 'been', 'because', 'just', 'like', 'want', 'need', 'please', 'hello', 'thanks',
				'thank', 'yes', 'does', 'did', 'may', 'much', 'many', 'some', 'more', 'most', 'also', 'into',
			)
		);
	}

	/**
	 * A one line list of everything the site offers, minus the entries already
	 * written out in full.
	 *
	 * @param array $items    All items.
	 * @param array $expanded Titles already included.
	 * @return string
	 */
	private static function catalogue( $items, $expanded ) {
		$titles = array();

		foreach ( $items as $item ) {
			if ( ! in_array( $item['title'], $expanded, true ) ) {
				$titles[] = $item['title'];
			}
		}

		if ( empty( $titles ) ) {
			return '';
		}

		return "ALSO ON THIS SITE (ask the visitor if they mean one of these, and offer to fetch the details):\n"
			. implode( ', ', $titles ) . "\n";
	}

	/**
	 * Put the chosen entries together, stopping at the character budget.
	 *
	 * @param array  $items      Items to write out in full.
	 * @param array  $taxonomies Taxonomy summary lines.
	 * @param string $catalogue  Optional list of everything else.
	 * @param array  $s          Settings.
	 * @return string
	 */
	private static function assemble( $items, $taxonomies, $catalogue, $s ) {
		$budget  = (int) $s['kb_budget'];
		$grouped = array();

		foreach ( $items as $item ) {
			$grouped[ $item['group'] ][] = $item['line'];
		}

		$parts = array();

		foreach ( $grouped as $group => $lines ) {
			$parts[] = $group . ":\n" . implode( "\n", $lines ) . "\n";
		}

		foreach ( $taxonomies as $line ) {
			$parts[] = $line . "\n";
		}

		if ( '' !== $catalogue ) {
			$parts[] = $catalogue;
		}

		/**
		 * Filter the knowledge chunks before they are assembled and clipped.
		 *
		 * @param array $parts Chunks of text.
		 * @param array $s     Settings.
		 */
		$parts = (array) apply_filters( 'bac_knowledge_parts', $parts, $s );

		$out  = '';
		$used = 0;

		foreach ( $parts as $chunk ) {
			if ( $used >= $budget ) {
				break;
			}
			$room = $budget - $used;
			if ( mb_strlen( $chunk ) > $room ) {
				$chunk = rtrim( mb_substr( $chunk, 0, $room ) ) . "…\n";
			}
			$out  .= $chunk . "\n";
			$used += mb_strlen( $chunk );
		}

		return trim( $out );
	}

	/* ------------------------------------------------------------------ Bits */

	/**
	 * Lowercase, strip punctuation, collapse whitespace: the form both the
	 * index and the question are compared in.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function fold( $text ) {
		$text = mb_strtolower( wp_strip_all_tags( (string) $text ) );
		$text = preg_replace( '/[^\p{L}\p{N}\s]+/u', ' ', $text );

		return trim( preg_replace( '/\s+/u', ' ', (string) $text ) );
	}

	/**
	 * Strip markup and shortcodes, collapse whitespace, clip to a length.
	 *
	 * @param string $html Raw content.
	 * @param int    $max  Maximum characters.
	 * @return string
	 */
	public static function clip( $html, $max ) {
		$text = wp_strip_all_tags( strip_shortcodes( (string) $html ) );
		$text = preg_replace( '/\s+/', ' ', $text );
		$text = trim( html_entity_decode( $text, ENT_QUOTES, 'UTF-8' ) );

		if ( mb_strlen( $text ) > $max ) {
			$text = rtrim( mb_substr( $text, 0, $max ) ) . '…';
		}

		return $text;
	}

	/**
	 * First non empty value across a list of meta keys, formatted as a price
	 * when it is numeric.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $keys    Meta keys to try in order.
	 * @return string
	 */
	private static function first_meta( $post_id, $keys ) {
		foreach ( $keys as $key ) {
			$value = self::meta_value( $post_id, $key );
			if ( '' === $value ) {
				continue;
			}
			if ( is_numeric( $value ) && (float) $value > 0 ) {
				return self::format_price( (float) $value );
			}
			return $value;
		}
		return '';
	}

	/**
	 * A single meta value flattened to a short string.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key.
	 * @return string
	 */
	private static function meta_value( $post_id, $key ) {
		$value = get_post_meta( $post_id, $key, true );

		if ( is_array( $value ) ) {
			$value = implode( ', ', array_filter( array_map( 'strval', $value ), 'strlen' ) );
		}
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		return self::clip( (string) $value, 120 );
	}

	/**
	 * Format a numeric price using WooCommerce's currency when it is available,
	 * and a plain number otherwise.
	 *
	 * @param float $amount Amount.
	 * @return string
	 */
	private static function format_price( $amount ) {
		$symbol = '';

		if ( function_exists( 'get_woocommerce_currency_symbol' ) ) {
			$symbol = html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' );
		}

		/**
		 * Filter the currency symbol used in the knowledge digest.
		 *
		 * @param string $symbol Currency symbol, may be empty.
		 * @param float  $amount Amount being formatted.
		 */
		$symbol = (string) apply_filters( 'bac_currency_symbol', $symbol, $amount );

		return 'from ' . $symbol . number_format_i18n( $amount, ( $amount == (int) $amount ) ? 0 : 2 ); // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual
	}
}
