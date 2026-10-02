<?php
/**
 * Names to keep, sorted by the AI with one written rule.
 *
 * The owner does not keep word lists: a plain-language rule ("How to decide", built-in
 * default, editable on the Instructions tab) tells the configured language model whether
 * each name stays as written in every language or may be translated. Three uses:
 *
 * - Suggest with AI: every name on the card in one request (or a few for long lists);
 *   the answers are shown as tags and only change the ticks when the owner applies them
 *   and saves.
 * - New names: names that join a group later are sorted in the daily 04:00 run (only the
 *   new ones). A name the owner has seen and saved is never changed by the AI.
 * - Find names I missed: capitalised phrases from the site's own texts (the engine's list
 *   of originals) are checked against the rule, and proper names not on the card yet are
 *   offered for "Your own names".
 *
 * Stored in BP_Glossary::NAMES_OPTION next to the owner's ticks:
 * 'rule' (string, '' = built-in), 'auto' ('yes'|'no'), 'ai' (md5 => [ k => 1|0, r => reason ]),
 * 'seen' (md5 => 1, names the owner has had on the card).
 *
 * @package BeaverPress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Names AI.
 */
final class BP_Names_AI {

	/** Names per request. */
	const CHUNK = 100;

	/** Phrases sent by "Find names I missed". */
	const FIND_MAX = 250;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'wp_ajax_bp_names_suggest', array( __CLASS__, 'ajax_suggest' ) );
		add_action( 'wp_ajax_bp_names_find', array( __CLASS__, 'ajax_find' ) );
		add_action( BP_Topup::CRON, array( __CLASS__, 'sort_new' ), 20 );
	}

	/**
	 * The built-in rule (filter `beaver_press_names_rule`).
	 *
	 * @return string
	 */
	public static function default_rule() {
		$rule = (string) BP_Profiles::get( 'names_rule', 'You are sorting names from a website. For each name, decide whether it must stay exactly as written in every language (keep) or may be translated (translate). When unsure, keep. Give a reason of a few words for each name.' );
		return (string) apply_filters( 'beaver_press_names_rule', $rule );
	}

	/**
	 * The rule in use: the owner's, or the built-in one.
	 *
	 * @return string
	 */
	public static function rule() {
		$own = trim( (string) ( self::state()['rule'] ?? '' ) );
		return '' !== $own ? $own : self::default_rule();
	}

	/**
	 * Whether new names are sorted in the daily run.
	 *
	 * @return bool
	 */
	public static function auto_enabled() {
		return 'no' !== ( self::state()['auto'] ?? 'yes' );
	}

	/**
	 * Everything stored with the names.
	 *
	 * @return array
	 */
	public static function state() {
		$saved = get_option( BP_Glossary::NAMES_OPTION );
		return is_array( $saved ) ? $saved : array();
	}

	/**
	 * Merge keys into the stored names option.
	 *
	 * @param array $changes Keys to set.
	 */
	public static function store( array $changes ) {
		update_option( BP_Glossary::NAMES_OPTION, array_merge( self::state(), $changes ), false );
	}

	/**
	 * Every name on the card, hash => name.
	 *
	 * @return array
	 */
	public static function all_names() {
		$out = array();
		foreach ( BP_Glossary::groups() as $group ) {
			foreach ( $group['names'] as $name ) {
				$out[ md5( $name ) ] = $name;
			}
		}
		return $out;
	}

	/**
	 * Ask the model about a list of names.
	 *
	 * @param string[] $names Names.
	 * @param string   $rule  Rule (system prompt).
	 * @param string   $extra Fixed sentence added after the rule.
	 * @return array|WP_Error Name => [ 'keep' => bool, 'reason' => string ], and '_cost' => float|null.
	 */
	public static function ask( array $names, $rule, $extra = '' ) {
		$mt     = get_option( 'trp_machine_translation_settings', array() );
		$mt     = is_array( $mt ) ? $mt : array();
		$engine = BP_Engine_Settings::current( $mt );
		if ( 'deepl' === $engine['api'] ) {
			return new WP_Error( 'bp_names_deepl', __( 'DeepL cannot sort names. Choose a language model (Claude, ChatGPT, DeepSeek, Gemini) in the engine settings.', 'beaver-press' ) );
		}
		if ( ! BP_Guard::may_translate( $mt ) ) {
			return new WP_Error( 'bp_names_guard', __( 'Not allowed from this request.', 'beaver-press' ) );
		}
		$engine['timeout'] = 120;
		$key               = BP_Keys::get( $engine['provider'] );
		$price             = BP_Usage::price( (string) $engine['model'] );
		$system            = trim( $rule ) . ( '' !== $extra ? "\n\n" . $extra : '' )
			. "\n\nReply with a JSON object only: {\"names\": [{\"name\": \"<the name exactly as given>\", \"keep\": true or false, \"reason\": \"<a few words in English>\"}]}, one entry for every name in the input, in the same order.";
		$schema            = array(
			'type'                 => 'object',
			'properties'           => array(
				'names' => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'properties'           => array(
							'name'   => array( 'type' => 'string' ),
							'keep'   => array( 'type' => 'boolean' ),
							'reason' => array( 'type' => 'string' ),
						),
						'required'             => array( 'name', 'keep', 'reason' ),
						'additionalProperties' => false,
					),
				),
			),
			'required'             => array( 'names' ),
			'additionalProperties' => false,
		);

		$out  = array();
		$cost = null;
		foreach ( array_chunk( array_values( array_unique( $names ) ), self::CHUNK ) as $chunk ) {
			$reply = BP_Providers::request_json( $engine, $key, $system, 'Names (JSON): ' . wp_json_encode( array( 'names' => $chunk ), JSON_UNESCAPED_UNICODE ), $schema );
			$usage = BP_Providers::last_usage();
			if ( null !== $usage ) {
				BP_Usage::add( $engine['provider'], $engine['model'], array_sum( array_map( 'mb_strlen', $chunk ) ), $usage[0], $usage[1] );
				if ( $price ) {
					$cost = (float) $cost + ( $usage[0] * $price[0] + $usage[1] * $price[1] ) / 1000000;
				}
			}
			if ( is_wp_error( $reply ) ) {
				return $reply;
			}
			$given = array();
			foreach ( $chunk as $name ) {
				$given[ mb_strtolower( $name ) ] = $name;
			}
			foreach ( (array) ( $reply['names'] ?? array() ) as $row ) {
				if ( ! is_array( $row ) || ! isset( $row['name'] ) ) {
					continue;
				}
				$name = $given[ mb_strtolower( trim( (string) $row['name'] ) ) ] ?? null;
				if ( null === $name ) {
					continue; // Not one we asked about.
				}
				$keep = $row['keep'] ?? true;
				$keep = is_bool( $keep ) ? $keep : ! in_array( strtolower( (string) $keep ), array( 'false', 'no', '0', 'translate' ), true );
				$out[ $name ] = array(
					'keep'   => $keep,
					'reason' => mb_substr( sanitize_text_field( (string) ( $row['reason'] ?? '' ) ), 0, 140 ),
				);
			}
		}
		if ( ! $out && $names ) {
			return new WP_Error( 'bp_names_empty', __( 'The model replied, but without usable answers. Try again.', 'beaver-press' ) );
		}
		$out['_cost'] = $cost;
		return $out;
	}

	/**
	 * Rule sent from the card (unsaved edits count), else the saved one.
	 *
	 * @return string
	 */
	private static function posted_rule() {
		$rule = trim( sanitize_textarea_field( wp_unslash( $_POST['rule'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked by the caller.
		return '' !== $rule ? mb_substr( $rule, 0, 4000 ) : self::rule();
	}

	/**
	 * Cost line for a reply.
	 *
	 * @param float|null $cost Cost in dollars.
	 * @return string
	 */
	private static function cost_text( $cost ) {
		if ( null === $cost ) {
			return '';
		}
		/* translators: %s: cost in US dollars */
		return ' ' . sprintf( __( 'Cost about $%s.', 'beaver-press' ), number_format_i18n( max( 0.0001, $cost ), 4 ) );
	}

	/**
	 * Check the request from the card.
	 */
	private static function check_request() {
		check_ajax_referer( 'bp_instructions', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'beaver-press' ) ), 403 );
		}
	}

	/**
	 * "Suggest with AI": every name on the card. Answers are stored as tags; ticks change
	 * only when the owner applies them and saves.
	 */
	public static function ajax_suggest() {
		self::check_request();
		$all    = self::all_names();
		$result = self::ask( array_values( $all ), self::posted_rule() );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		$cost = $result['_cost'];
		unset( $result['_cost'] );
		$ai   = (array) ( self::state()['ai'] ?? array() );
		$tags = array();
		$kept = 0;
		foreach ( $result as $name => $row ) {
			$hash          = md5( (string) $name );
			$ai[ $hash ]   = array(
				'k' => $row['keep'] ? 1 : 0,
				'r' => $row['reason'],
			);
			$tags[ $hash ] = $row;
			$kept         += $row['keep'] ? 1 : 0;
		}
		self::store( array( 'ai' => array_intersect_key( $ai, $all ) ) );
		wp_send_json_success(
			array(
				'tags'    => $tags,
				'message' => sprintf(
					/* translators: 1: names answered, 2: keep, 3: translate */
					__( '%1$s names sorted: %2$s keep, %3$s translate. Nothing is changed until you apply the suggestions and save.', 'beaver-press' ),
					number_format_i18n( count( $result ) ),
					number_format_i18n( $kept ),
					number_format_i18n( count( $result ) - $kept )
				) . self::cost_text( $cost ),
			)
		);
	}

	/**
	 * Capitalised phrases used in the middle of sentences on the site, most frequent first,
	 * without the names already on the card or in "Your own names".
	 *
	 * @param int $max Most phrases returned.
	 * @return string[]
	 */
	public static function candidates( $max = self::FIND_MAX ) {
		global $wpdb;
		$table = $wpdb->prefix . 'trp_original_strings';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return array();
		}
		$rows  = $wpdb->get_col( "SELECT original FROM {$table} LIMIT 50000" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery -- engine table, no input.
		$count = array();
		foreach ( (array) $rows as $row ) {
			$text = html_entity_decode( wp_strip_all_tags( (string) $row ), ENT_QUOTES, 'UTF-8' );
			if ( ! preg_match_all( '/(?<=[\p{Ll}\d,;:(] )(\p{Lu}[\p{L}\'’-]+(?: (?:of |the |de |la )?\p{Lu}[\p{L}\'’-]+){0,3})/u', $text, $m ) ) {
				continue;
			}
			foreach ( $m[1] as $phrase ) {
				$phrase           = preg_replace( '/[\'’]s$/u', '', $phrase );
				$count[ $phrase ] = ( $count[ $phrase ] ?? 0 ) + 1;
			}
		}
		$known = array( mb_strtolower( (string) get_bloginfo( 'name' ) ) => 1 );
		$mt    = get_option( 'trp_machine_translation_settings', array() );
		foreach ( array_merge( array_values( self::all_names() ), BP_Glossary::manual_terms( is_array( $mt ) ? $mt : array() ) ) as $name ) {
			$known[ mb_strtolower( $name ) ] = 1;
		}
		$out = array();
		arsort( $count );
		foreach ( $count as $phrase => $n ) {
			if ( $n < 2 || mb_strlen( $phrase ) < 3 || isset( $known[ mb_strtolower( $phrase ) ] ) ) {
				continue;
			}
			$out[] = $phrase;
			if ( count( $out ) >= $max ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * "Find names I missed": proper names from the site's texts that are not on the card.
	 */
	public static function ajax_find() {
		self::check_request();
		$phrases = self::candidates();
		if ( ! $phrases ) {
			wp_send_json_success(
				array(
					'names'   => array(),
					'message' => __( 'No new names found in the site\'s texts.', 'beaver-press' ),
				)
			);
		}
		$result = self::ask(
			$phrases,
			self::posted_rule(),
			'These phrases were picked out of the website\'s texts automatically, so some are not names at all. Answer keep = true only for a proper name that should stay as written; answer keep = false for month names, ordinary words, product or page words, and pieces of a longer name.'
		);
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		$cost  = $result['_cost'];
		$names = array();
		unset( $result['_cost'] );
		foreach ( $result as $name => $row ) {
			if ( $row['keep'] ) {
				$names[] = array(
					'name'   => (string) $name,
					'reason' => $row['reason'],
				);
			}
		}
		wp_send_json_success(
			array(
				'names'   => $names,
				'message' => sprintf(
					/* translators: 1: phrases checked, 2: names found */
					__( '%1$s phrases checked, %2$s names to add. Click a name to add it to "Your own names", then save.', 'beaver-press' ),
					number_format_i18n( count( $phrases ) ),
					number_format_i18n( count( $names ) )
				) . self::cost_text( $cost ),
			)
		);
	}

	/**
	 * Daily run: sort names that joined a group since the owner last saved the card. A name
	 * the rule says to translate is unticked; the owner can tick it back.
	 */
	public static function sort_new() {
		if ( ! self::auto_enabled() ) {
			return;
		}
		$state = self::state();
		$all   = self::all_names();
		if ( ! isset( $state['seen'] ) ) {
			self::store( array( 'seen' => array_fill_keys( array_keys( $all ), 1 ) ) ); // First run: today's names are the owner's.
			return;
		}
		$new = array_diff_key( $all, (array) $state['seen'] );
		if ( ! $new ) {
			return;
		}
		$result = self::ask( array_values( $new ), self::rule() );
		if ( is_wp_error( $result ) ) {
			return; // Tried again tomorrow.
		}
		unset( $result['_cost'] );
		$ai  = (array) ( $state['ai'] ?? array() );
		$off = (array) ( $state['off'] ?? array() );
		foreach ( $result as $name => $row ) {
			$hash        = md5( (string) $name );
			$ai[ $hash ] = array(
				'k' => $row['keep'] ? 1 : 0,
				'r' => $row['reason'],
				'a' => 1,
			);
			if ( ! $row['keep'] ) {
				$off[ $hash ] = 1;
			}
		}
		self::store(
			array(
				'ai'   => $ai,
				'off'  => $off,
				'seen' => (array) $state['seen'] + array_fill_keys( array_keys( $new ), 1 ),
			)
		);
	}
}
