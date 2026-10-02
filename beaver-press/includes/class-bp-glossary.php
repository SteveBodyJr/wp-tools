<?php
/**
 * Names that keep their spelling in every language.
 *
 * Two sources: an automatic list from the site (destinations, destination areas, places to
 * stay, Kilimanjaro route names from att-core, when present) and the owner's own list from
 * the engine panel. Each batch only carries the names that appear in it.
 *
 * Language models get the names in the prompt and may still translate the generic words
 * around them ("Serengeti National Park" -> "Parc national du Serengeti"). DeepL has no
 * prompt: the core of each name ("Serengeti", "Manyara") is wrapped in <bp-keep>, which
 * DeepL is told to ignore, and the wrapper is removed from the reply.
 *
 * @package BeaverPress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Glossary.
 */
final class BP_Glossary {

	/** Cache of the name groups found on the site (names only; labels are added when read). */
	const TRANSIENT = 'bp_glossary_groups_v2';

	/** The engine's hidden gettext markers (front-end requests wrap translated strings in them). */
	const MARKERS = '/#!trpst#.*?#!trpen#/s';

	/** Owner's choices: [ 'groups' => [ id => 'yes'|'no' ], 'off' => [ md5(name) => 1 ] ]. */
	const NAMES_OPTION = 'beaver_press_names';

	/** Tag DeepL leaves untranslated. */
	const KEEP_TAG = 'bp-keep';

	/** Post types and taxonomy whose names are collected (skipped when absent). */
	const POST_TYPES = array( 'destination', 'accommodation' );
	const TAXONOMY   = 'destination_area';

	/** Generic words stripped to get a name's core for DeepL. */
	const GENERIC = '/^(?:lake|mount|mt\.?)\s+|\s+(?:national\s+park|game\s+reserve|conservation\s+area|marine\s+park|forest\s+reserve|crater|island|mountains?)$/i';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_filter( 'beaver_press_glossary', array( __CLASS__, 'add_terms' ) );
		add_action( 'save_post', array( __CLASS__, 'maybe_flush_post' ), 10, 2 );
		add_action( 'deleted_post', array( __CLASS__, 'flush' ) );
		foreach ( array( 'created_', 'edited_', 'delete_' ) as $prefix ) {
			add_action( $prefix . self::TAXONOMY, array( __CLASS__, 'flush' ) );
		}
	}

	/**
	 * Add the automatic and manual names to the glossary.
	 *
	 * @param array $terms Names so far (the site name).
	 * @return array
	 */
	public static function add_terms( $terms ) {
		$mt = get_option( 'trp_machine_translation_settings', array() );
		$mt = is_array( $mt ) ? $mt : array();
		return array_merge( (array) $terms, self::auto_terms(), self::manual_terms( $mt ) );
	}

	/**
	 * Name groups found on the site, each with a label and its names (cached for a day,
	 * refreshed when a source changes). More groups: filter `beaver_press_name_groups`.
	 *
	 * The cache holds names only. Labels are looked up on every call: a cache rebuilt during
	 * a front-end request would otherwise keep the engine's hidden gettext markers around
	 * them and print them on the Instructions tab.
	 *
	 * @return array Id => [ 'label' => string, 'names' => string[] ].
	 */
	public static function groups() {
		$groups = get_transient( self::TRANSIENT );
		if ( ! is_array( $groups ) ) {
			$groups = self::collect();
			set_transient( self::TRANSIENT, $groups, DAY_IN_SECONDS );
		}
		foreach ( $groups as $id => $group ) {
			$groups[ $id ]['label'] = self::group_label( (string) $id, (string) ( $group['label'] ?? '' ) );
		}
		return $groups;
	}

	/**
	 * Label of a group: the built-in ones translated now, others as their filter gave them.
	 *
	 * @param string $id       Group id.
	 * @param string $fallback Label stored with the group.
	 * @return string
	 */
	public static function group_label( $id, $fallback = '' ) {
		$labels = array(
			'accommodation' => __( 'Accommodation names', 'beaver-press' ),
			'destination'   => __( 'Destination names', 'beaver-press' ),
			'area'          => __( 'Destination areas', 'beaver-press' ),
			'route'         => __( 'Kilimanjaro routes', 'beaver-press' ),
		);
		$label = $labels[ $id ] ?? ( '' !== $fallback ? $fallback : $id );
		return self::unmark( $label );
	}

	/**
	 * Remove the engine's hidden gettext markers from a string.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function unmark( $text ) {
		$text = (string) $text;
		return false === strpos( $text, '#!trp' ) ? $text : trim( (string) preg_replace( self::MARKERS, '', $text ) );
	}

	/**
	 * Read the groups from the site (no labels for the built-in ones; see group_label()).
	 *
	 * @return array Id => [ 'label' => string, 'names' => string[] ].
	 */
	private static function collect() {
		$groups = array();
		foreach ( array( 'accommodation', 'destination' ) as $type ) {
			if ( ! post_type_exists( $type ) ) {
				continue;
			}
			$ids             = get_posts(
				array(
					'post_type'        => $type,
					'post_status'      => 'publish',
					'posts_per_page'   => 1000,
					'fields'           => 'ids',
					'suppress_filters' => true,
				)
			);
			$groups[ $type ] = array(
				'label' => '',
				'names' => array_map( static fn( $id ) => html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ), $ids ),
			);
		}
		if ( taxonomy_exists( self::TAXONOMY ) ) {
			$terms          = get_terms(
				array(
					'taxonomy'   => self::TAXONOMY,
					'hide_empty' => false,
					'fields'     => 'names',
				)
			);
			$groups['area'] = array(
				'label' => '',
				'names' => is_array( $terms ) ? array_map( static fn( $n ) => html_entity_decode( (string) $n, ENT_QUOTES, 'UTF-8' ), $terms ) : array(),
			);
		}
		if ( function_exists( 'att_core_kili_routes' ) ) {
			$routes = array();
			foreach ( (array) att_core_kili_routes() as $route ) {
				if ( is_array( $route ) && ! empty( $route['name'] ) ) {
					$routes[] = (string) $route['name'];
				}
			}
			$groups['route'] = array(
				'label' => '',
				'names' => $routes,
			);
		}
		$groups = (array) apply_filters( 'beaver_press_name_groups', $groups );
		foreach ( $groups as $id => $group ) {
			$names = array_map( static fn( $n ) => trim( self::unmark( (string) $n ) ), (array) ( $group['names'] ?? array() ) );
			$names = array_values( array_unique( array_filter( $names, static fn( $n ) => mb_strlen( $n ) >= 3 ) ) );
			sort( $names, SORT_NATURAL | SORT_FLAG_CASE );
			$groups[ $id ] = array(
				'label' => self::unmark( (string) ( $group['label'] ?? '' ) ),
				'names' => $names,
			);
		}
		return $groups;
	}

	/**
	 * The owner's choices, with defaults: every group on (off when the older "also keep the
	 * names found on the site" switch was off), no single name off.
	 *
	 * @return array{groups: array, off: array}
	 */
	public static function choices() {
		$saved = get_option( self::NAMES_OPTION );
		$mt    = get_option( 'trp_machine_translation_settings', array() );
		$on    = is_array( $mt ) && 'no' === ( $mt['bp-glossary-auto'] ?? 'yes' ) ? 'no' : 'yes';
		$out   = array(
			'groups' => array(),
			'off'    => is_array( $saved ) ? (array) ( $saved['off'] ?? array() ) : array(),
		);
		foreach ( array_keys( self::groups() ) as $id ) {
			$out['groups'][ $id ] = is_array( $saved ) && isset( $saved['groups'][ $id ] ) ? ( 'no' === $saved['groups'][ $id ] ? 'no' : 'yes' ) : $on;
		}
		return $out;
	}

	/**
	 * The owner's own names, one per line.
	 *
	 * @param array $mt Machine translation settings.
	 * @return string[]
	 */
	public static function manual_terms( $mt ) {
		$lines = preg_split( '/\R/', (string) ( $mt['bp-glossary'] ?? '' ) );
		return array_values( array_filter( array_map( 'trim', (array) $lines ) ) );
	}

	/**
	 * Names collected from the site (cached for a day, refreshed when a source changes).
	 *
	 * @return string[]
	 */
	public static function auto_terms() {
		$choices = self::choices();
		$names   = array();
		foreach ( self::groups() as $id => $group ) {
			if ( 'yes' !== ( $choices['groups'][ $id ] ?? 'yes' ) ) {
				continue;
			}
			foreach ( $group['names'] as $name ) {
				if ( empty( $choices['off'][ md5( $name ) ] ) ) {
					$names[] = $name;
				}
			}
		}
		$names = array_values( array_unique( $names ) );
		sort( $names, SORT_NATURAL | SORT_FLAG_CASE );
		return $names;
	}

	/**
	 * Names from the list that occur in these texts, longest first.
	 *
	 * @param string[] $terms Glossary.
	 * @param string[] $texts Batch texts.
	 * @return string[]
	 */
	public static function matching( array $terms, array $texts ) {
		$plain = wp_strip_all_tags( implode( "\n", array_map( 'strval', $texts ) ) );
		$found = array();
		foreach ( $terms as $term ) {
			$term = (string) $term;
			if ( '' !== $term && false !== mb_stripos( $plain, $term ) && preg_match( self::word_regex( $term ), $plain ) ) {
				$found[] = $term;
			}
		}
		usort( $found, static fn( $a, $b ) => mb_strlen( $b ) <=> mb_strlen( $a ) );
		return $found;
	}

	/**
	 * Core of a name for DeepL: "Serengeti National Park" -> "Serengeti", "Lake Manyara" -> "Manyara".
	 *
	 * @param string $term Name.
	 * @return string
	 */
	public static function core( $term ) {
		$core = trim( (string) preg_replace( self::GENERIC, '', (string) $term ) );
		return mb_strlen( $core ) >= 3 ? $core : (string) $term;
	}

	/**
	 * Wrap the core of each name in <bp-keep> in text (never inside tags or attributes).
	 *
	 * @param string   $text  Text.
	 * @param string[] $terms Names occurring in the text.
	 * @return string
	 */
	public static function protect( $text, array $terms ) {
		$cores = array_values( array_unique( array_map( array( __CLASS__, 'core' ), $terms ) ) );
		usort( $cores, static fn( $a, $b ) => mb_strlen( $b ) <=> mb_strlen( $a ) );
		if ( ! $cores ) {
			return $text;
		}
		$pattern = '/(?<![\p{L}\p{N}_])(' . implode( '|', array_map( static fn( $c ) => preg_quote( $c, '/' ), $cores ) ) . ')(?![\p{L}\p{N}_])/iu';
		$parts   = preg_split( '/(<[^>]*>)/', (string) $text, -1, PREG_SPLIT_DELIM_CAPTURE );
		foreach ( $parts as $i => $part ) {
			if ( '' !== $part && '<' !== $part[0] ) {
				$parts[ $i ] = preg_replace( $pattern, '<' . self::KEEP_TAG . '>$1</' . self::KEEP_TAG . '>', $part );
			}
		}
		return implode( '', $parts );
	}

	/**
	 * Remove the <bp-keep> wrappers.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function unprotect( $text ) {
		return (string) preg_replace( '#</?\s*' . self::KEEP_TAG . '\s*>#i', '', (string) $text );
	}

	/**
	 * Refresh the automatic list when a destination or place to stay is saved.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 */
	public static function maybe_flush_post( $post_id, $post ) {
		if ( $post instanceof WP_Post && in_array( $post->post_type, self::POST_TYPES, true ) ) {
			self::flush();
		}
	}

	/**
	 * Drop the cached automatic list.
	 */
	public static function flush() {
		delete_transient( self::TRANSIENT );
		delete_transient( 'bp_glossary_groups' ); // Earlier cache names.
		delete_transient( 'bp_glossary_auto' );
	}

	/**
	 * Whole-word, case-insensitive, Unicode-aware match for a literal name.
	 *
	 * @param string $term Name.
	 * @return string
	 */
	private static function word_regex( $term ) {
		return '/(?<![\p{L}\p{N}_])' . preg_quote( $term, '/' ) . '(?![\p{L}\p{N}_])/iu';
	}
}
