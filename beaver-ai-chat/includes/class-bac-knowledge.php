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
		$details    = BAC_Settings::to_list( $s['kb_detail_meta'] );
		$detail_max = (int) $s['kb_detail_chars'];

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

				// get_the_title() is display HTML: "Booking &#038; Payment Terms".
				// The model reads plain text, and so does the matcher.
				$title = html_entity_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES, 'UTF-8' );
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

				$head = $line;
				$body = self::clip(
					'' !== trim( (string) $post->post_content ) ? $post->post_content : $post->post_excerpt,
					$item_chars
				);
				if ( '' !== $body ) {
					$line .= "\n  " . $body;
				}

				// The full record, used only for the few items that best match
				// a question: the short line says a tour exists, this says what
				// happens on day three and what the price leaves out.
				$detail = self::detail( $post->ID, $details, $detail_max );
				$full   = '' !== $detail ? $head . "\n  " . str_replace( "\n", "\n  ", $detail ) : $line;

				$items[] = array(
					'group' => strtoupper( $label ),
					'title' => $title,
					'line'  => $line,
					'full'  => $full,
					// Lowercased once here so scoring a message is only string
					// searching, not case folding the whole site per turn.
					'tl'    => self::fold( $title ),
					'bl'    => self::fold( $title . ' ' . $body . ' ' . $meta . ' ' . $detail ),
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
	 * Every item as its one-line summary (title, price, link), no body text.
	 *
	 * @since 2.1.0
	 *
	 * @param array $index Index.
	 * @param array $s     Settings.
	 * @return string
	 */
	private static function compact( $index, $s ) {
		$items = array();

		foreach ( $index['items'] as $item ) {
			$item['line'] = strtok( $item['line'], "\n" );
			$items[]      = $item;
		}

		return self::assemble( $items, $index['taxonomies'], '', $s );
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

		// Nothing matched: the visitor is asking something general ("is it
		// safe?"), or in a language the index is not written in. Send every
		// item's one-line summary rather than every item's text: the model
		// still sees the whole range with prices and links, at a fraction of
		// the size, and the team notes carry the general answers.
		if ( empty( $scored ) ) {
			return self::compact( $index, $s );
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

		// The strongest few matches are sent in full, with their detail fields,
		// and every match carries its rank so the best ones are given room
		// first when the budget is tight.
		foreach ( array_keys( $top ) as $rank ) {
			$top[ $rank ]['item']['rank'] = $rank;

			if ( $rank < (int) $s['kb_expand'] && ! empty( $top[ $rank ]['item']['full'] ) ) {
				$top[ $rank ]['item']['line'] = $top[ $rank ]['item']['full'];
			}
		}

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
			$out[ self::singular( $word ) ] = true;
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
		$budget = (int) $s['kb_budget'];

		// The categories and the catalogue of everything else are what stop
		// the assistant denying something the site offers, so their room is
		// set aside first. Clipped last, they used to be the first thing lost
		// whenever the detailed matches filled the budget.
		$tail = array();
		foreach ( $taxonomies as $line ) {
			$tail[] = $line . "\n";
		}
		if ( '' !== $catalogue ) {
			$tail[] = $catalogue;
		}

		// Each tail chunk is counted as the final loop counts it.
		$reserve = 0;
		foreach ( $tail as $chunk ) {
			$reserve += mb_strlen( $chunk );
		}

		$room = max( (int) ( $budget * 0.4 ), $budget - $reserve );

		// Whole items only, best match first: room is handed out by rank, and
		// the result is then written in the site's own order.
		$order = array_keys( $items );
		usort(
			$order,
			static function ( $a, $b ) use ( $items ) {
				$ra = isset( $items[ $a ]['rank'] ) ? $items[ $a ]['rank'] : PHP_INT_MAX;
				$rb = isset( $items[ $b ]['rank'] ) ? $items[ $b ]['rank'] : PHP_INT_MAX;
				return ( $ra === $rb ) ? $a - $b : $ra - $rb;
			}
		);

		// Every item first gets its first line, so nothing selected can go
		// missing; then, best match first, items are upgraded to their full
		// text while the room lasts.
		// Room is counted exactly as the output is written: each group adds
		// its "NAME:" header line and a closing line break.
		$kept   = array();
		$used   = 0;
		$headed = array();

		foreach ( $order as $key ) {
			$head  = strtok( $items[ $key ]['line'], "\n" );
			$group = $items[ $key ]['group'];
			$cost  = mb_strlen( $head ) + 1 + ( isset( $headed[ $group ] ) ? 0 : mb_strlen( $group ) + 2 );

			if ( $used + $cost > $room ) {
				continue; // Only on a site far larger than the budget.
			}

			$kept[ $key ]     = $head;
			$headed[ $group ] = true;
			$used            += $cost;
		}

		foreach ( $order as $key ) {
			if ( ! isset( $kept[ $key ] ) ) {
				continue;
			}

			$extra = mb_strlen( $items[ $key ]['line'] ) - mb_strlen( $kept[ $key ] );

			if ( $extra > 0 && $used + $extra <= $room ) {
				$kept[ $key ] = $items[ $key ]['line'];
				$used        += $extra;
			}
		}

		ksort( $kept );

		$grouped = array();
		foreach ( $kept as $key => $line ) {
			$grouped[ $items[ $key ]['group'] ][] = $line;
		}

		$parts = array();

		foreach ( $grouped as $group => $lines ) {
			$parts[] = $group . ":\n" . implode( "\n", $lines ) . "\n";
		}

		$parts = array_merge( $parts, $tail );

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
			$left = $budget - $used;
			if ( mb_strlen( $chunk ) > $left ) {
				$chunk = rtrim( mb_substr( $chunk, 0, $left ) ) . "…\n";
			}
			$out  .= $chunk . "\n";
			$used += mb_strlen( $chunk );
		}

		return trim( $out );
	}


	/* ------------------------------------------------------------------ Bits */

	/**
	 * A plural search word cut back to its singular, so "safaris" finds the
	 * pages that say "safari". Matching is by substring, so the singular still
	 * finds the plural, never the other way round. English only, and only the
	 * regular endings; anything else is left as typed.
	 *
	 * @since 2.1.0
	 *
	 * @param string $word Folded word.
	 * @return string
	 */
	private static function singular( $word ) {
		if ( mb_strlen( $word ) <= 4 ) {
			return $word;
		}
		if ( 'ies' === mb_substr( $word, -3 ) ) {
			return mb_substr( $word, 0, -3 ) . 'y';
		}
		if ( 's' === mb_substr( $word, -1 ) && 'ss' !== mb_substr( $word, -2 ) && 'us' !== mb_substr( $word, -2 ) ) {
			return mb_substr( $word, 0, -1 );
		}
		return $word;
	}

	/**
	 * An item's detail fields as readable text.
	 *
	 * A plain key ("overview") becomes "Overview: …". A key with * is a
	 * repeating row, as ACF stores repeaters: itinerary_days_0_day_title,
	 * itinerary_days_1_day_title and so on. Rows sharing a prefix are read
	 * together in order, so itinerary_days_*_day_title with
	 * itinerary_days_*_day_description becomes "Itinerary day 1: title.
	 * description." A repeater with one field per row, such as
	 * included_items_*_item, becomes one list: "Included items: a; b; c."
	 *
	 * Bare numbers in a repeater are skipped: there they are almost always
	 * the ID of a linked post, which means nothing to a visitor.
	 *
	 * @since 2.1.0
	 *
	 * @param int   $post_id Post ID.
	 * @param array $specs   Field names, * for a row number.
	 * @param int   $max     Character limit.
	 * @return string
	 */
	private static function detail( $post_id, $specs, $max ) {
		if ( empty( $specs ) ) {
			return '';
		}

		$all    = get_post_meta( $post_id );
		$blocks = array();
		$rows   = array();

		foreach ( $specs as $spec ) {
			if ( false === strpos( $spec, '*' ) ) {
				$value = self::field_text( isset( $all[ $spec ][0] ) ? $all[ $spec ][0] : '' );
				if ( '' !== $value ) {
					$blocks[ $spec ] = self::label( $spec ) . ': ' . $value;
				}
				continue;
			}

			list( $prefix, $suffix ) = explode( '*', $spec, 2 );
			$pattern = '/^' . preg_quote( $prefix, '/' ) . '(\d+)' . preg_quote( $suffix, '/' ) . '$/';

			if ( ! isset( $rows[ $prefix ] ) ) {
				$rows[ $prefix ]   = array();
				$blocks[ $prefix ] = null; // Keeps its place in the order.
			}

			foreach ( $all as $key => $values ) {
				if ( preg_match( $pattern, $key, $m ) ) {
					$value = self::field_text( $values[0] );
					if ( '' !== $value && ! is_numeric( $value ) ) {
						$rows[ $prefix ][ (int) $m[1] ][ $suffix ] = $value;
					}
				}
			}
		}

		foreach ( $rows as $prefix => $found ) {
			if ( empty( $found ) ) {
				unset( $blocks[ $prefix ] );
				continue;
			}

			ksort( $found );
			$suffixes = array();
			foreach ( $found as $cells ) {
				$suffixes += array_flip( array_keys( $cells ) );
			}

			$label = self::label( $prefix );

			if ( 1 === count( $suffixes ) ) {
				$list = array();
				foreach ( $found as $cells ) {
					$list[] = rtrim( reset( $cells ), '.' );
				}
				$blocks[ $prefix ] = $label . ': ' . implode( '; ', $list ) . '.';
			} else {
				// "Itinerary days" → "Itinerary day 1: …", numbered from 1.
				$row   = ucfirst( self::singular( strtolower( $label ) ) );
				$lines = array();
				$n     = 0;
				foreach ( $found as $cells ) {
					// The first field (a day's title) leads; the rest go
					// shortest first, so short facts such as the night's lodge
					// come before long prose and survive any trimming.
					$first = array_shift( $cells );
					usort(
						$cells,
						static function ( $a, $b ) {
							return mb_strlen( $a ) - mb_strlen( $b );
						}
					);
					array_unshift( $cells, $first );

					$cells   = array_map( 'rtrim', $cells, array_fill( 0, count( $cells ), '. ' ) );
					$lines[] = $row . ' ' . ( ++$n ) . ': ' . implode( '. ', $cells ) . '.';
				}
				$blocks[ $prefix ] = implode( "\n", $lines );
			}
		}

		return self::fit( array_filter( $blocks ), $max );
	}

	/**
	 * Fit detail blocks into a character limit without losing any of them.
	 *
	 * Cutting the joined text at the limit loses whatever was listed last,
	 * which on a long tour was the exclusions: the assistant then told a
	 * visitor they "are not spelled out". Instead the limit is shared out:
	 * blocks shorter than an even share are kept whole (lists, price notes),
	 * and the long ones (an overview, an itinerary) split what is left. A
	 * block of several rows is trimmed row by row, so a long itinerary keeps
	 * every day, each a little shorter, rather than losing its last days.
	 *
	 * @since 2.1.0
	 *
	 * @param array $blocks Text blocks, rows separated by line breaks.
	 * @param int   $max    Character limit.
	 * @return string
	 */
	private static function fit( $blocks, $max ) {
		$blocks = array_values( $blocks );
		$total  = 0;

		foreach ( $blocks as $block ) {
			$total += mb_strlen( $block ) + 1;
		}

		if ( $total <= $max ) {
			return trim( implode( "\n", $blocks ) );
		}

		// Even shares, smallest first; whatever a short block does not use
		// passes on to the longer ones.
		$sizes = array_map( 'mb_strlen', $blocks );
		asort( $sizes );

		$left  = $max - count( $blocks );
		$count = count( $blocks );
		$caps  = array();

		foreach ( $sizes as $i => $size ) {
			$share      = (int) floor( $left / $count );
			$caps[ $i ] = min( $size, $share );
			$left      -= $caps[ $i ];
			$count--;
		}

		foreach ( $blocks as $i => $block ) {
			if ( mb_strlen( $block ) <= $caps[ $i ] ) {
				continue;
			}

			$rows = explode( "\n", $block );
			$each = max( 40, (int) floor( $caps[ $i ] / count( $rows ) ) - 1 );

			foreach ( $rows as $r => $row ) {
				if ( mb_strlen( $row ) > $each ) {
					$rows[ $r ] = rtrim( mb_substr( $row, 0, $each - 1 ) ) . '…';
				}
			}

			$blocks[ $i ] = implode( "\n", $rows );
		}

		return trim( implode( "\n", $blocks ) );
	}

	/**
	 * One stored field value as a single line of plain text: arrays joined,
	 * markup, shortcodes and HTML comments removed, entities decoded.
	 *
	 * @since 2.1.0
	 *
	 * @param mixed $raw Stored value.
	 * @return string
	 */
	private static function field_text( $raw ) {
		$value = maybe_unserialize( $raw );

		if ( is_array( $value ) ) {
			$value = implode( ', ', array_filter( array_map( 'strval', array_filter( $value, 'is_scalar' ) ), 'strlen' ) );
		}
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		return self::clip( (string) $value, 100000 );
	}

	/**
	 * "itinerary_days_" reads as "Itinerary days".
	 *
	 * @since 2.1.0
	 *
	 * @param string $key Field name or prefix.
	 * @return string
	 */
	private static function label( $key ) {
		return ucfirst( trim( str_replace( array( '_', '-' ), ' ', ltrim( $key, '_' ) ) ) );
	}

	/**
	 * Drop the cached index when content it was built from changes, so a new
	 * price or itinerary reaches the assistant on the next message rather than
	 * hours later. Registered from bac_init(); the class only loads when one
	 * of these actually fires.
	 *
	 * @since 2.1.0
	 *
	 * @param int $post_id Post ID, or a term ID for the term hooks.
	 */
	public static function on_change( $post_id = 0 ) {
		if ( doing_action( 'save_post' ) || doing_action( 'deleted_post' ) ) {
			if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || BAC_LEAD_CPT === get_post_type( $post_id ) ) {
				return;
			}
		}

		self::flush_cache();
	}

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

		$own = trim( (string) BAC_Settings::get( 'kb_currency', '' ) );

		if ( '' !== $own ) {
			$symbol = $own;
		} elseif ( function_exists( 'get_woocommerce_currency_symbol' ) ) {
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
