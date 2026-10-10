<?php
/**
 * Terms and search words per language.
 *
 * Terms: how a tourism term is translated, per language, the same way on every page
 * ("game drive" = "Pirschfahrt"). The rows start from the translation profile's terms; the owner
 * fills or corrects the translations (or asks the AI to suggest the empty ones) and saves. A batch
 * that contains a term is told to use exactly that translation, adapting only grammar.
 *
 * Search words: the phrases travellers type into a search engine in each language ("Tansania
 * Safari"). The AI is told to prefer that wording where a text already says the same thing, above
 * all in titles, headings and descriptions, and never to add it where the text does not say it.
 * Suggestions from the AI are only suggestions: the owner checks them (Search Console, a keyword
 * tool) and saves what fits.
 *
 * Both reach language models only; DeepL takes no instructions.
 *
 * @package BeaverPress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Terms.
 */
final class BP_Terms {

	/** Option: [ 'terms' => [ term => [ code => translation ] ], 'search' => [ code => [ phrase ] ] ]. */
	const OPTION = 'beaver_press_terms';

	/** Most terms kept. */
	const MAX_TERMS = 80;

	/** Most search phrases per language. */
	const MAX_PHRASES = 12;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_post_bp_terms', array( __CLASS__, 'save' ) );
		add_action( 'wp_ajax_bp_terms_suggest', array( __CLASS__, 'ajax_suggest_terms' ) );
		add_action( 'wp_ajax_bp_search_suggest', array( __CLASS__, 'ajax_suggest_search' ) );
	}

	/**
	 * Saved state.
	 *
	 * @return array{terms: array, search: array, saved: bool}
	 */
	public static function get() {
		$saved = get_option( self::OPTION );
		if ( ! is_array( $saved ) ) {
			$terms = array();
			foreach ( (array) BP_Profiles::get( 'terms', array() ) as $term ) {
				$terms[ (string) $term ] = array();
			}
			return array(
				'terms'  => $terms,
				'search' => array(),
				'saved'  => false,
			);
		}
		return array(
			'terms'  => (array) ( $saved['terms'] ?? array() ),
			'search' => (array) ( $saved['search'] ?? array() ),
			'saved'  => true,
		);
	}

	/**
	 * Term translations set for a language.
	 *
	 * @param string $code Language code.
	 * @return array Term => translation.
	 */
	public static function for_language( $code ) {
		$out = array();
		foreach ( self::get()['terms'] as $term => $langs ) {
			$t = trim( (string) ( $langs[ $code ] ?? '' ) );
			if ( '' !== $t ) {
				$out[ (string) $term ] = $t;
			}
		}
		return $out;
	}

	/**
	 * Search phrases for a language.
	 *
	 * @param string $code Language code.
	 * @return string[]
	 */
	public static function search_for( $code ) {
		return array_values( array_filter( array_map( 'strval', (array) ( self::get()['search'][ $code ] ?? array() ) ) ) );
	}

	/**
	 * Lines for the request.
	 *
	 * @param string     $code   Target language code.
	 * @param string     $target Target language name.
	 * @param array|null $texts  The batch (terms that occur in it), or null for all (the full-prompt view).
	 * @return string[]
	 */
	public static function prompt_lines( $code, $target, $texts = null ) {
		$lines = array();
		$terms = self::for_language( $code );
		if ( $terms && null !== $texts ) {
			$plain = wp_strip_all_tags( implode( "\n", array_map( 'strval', $texts ) ) );
			$terms = array_filter( $terms, static fn( $t, $term ) => self::occurs( (string) $term, $plain ), ARRAY_FILTER_USE_BOTH );
		}
		if ( $terms ) {
			$pairs = array();
			foreach ( $terms as $term => $translation ) {
				$pairs[] = $term . ' = ' . $translation;
			}
			$lines[] = '- Translate these terms exactly this way, changing only grammar such as plural, case or article: ' . implode( '; ', $pairs ) . '.';
		}
		$phrases = self::search_for( $code );
		if ( $phrases ) {
			$lines[] = sprintf( '- Travellers searching in %1$s use these words: %2$s. Where a text already says the same thing, prefer this wording, above all in page titles, headings and descriptions. Never add one where the text does not say it, and never repeat them.', $target, implode( '; ', $phrases ) );
		}
		return $lines;
	}

	/**
	 * Whether a term occurs in a text as whole words, also with a plural -s or -es ("game drives").
	 *
	 * @param string $term Term.
	 * @param string $text Plain text.
	 * @return bool
	 */
	public static function occurs( $term, $text ) {
		$words = array_map( static fn( $w ) => preg_quote( $w, '/' ), preg_split( '/\s+/u', trim( $term ) ) );
		return '' !== trim( $term ) && 1 === preg_match( '/(?<![\p{L}\p{N}])' . implode( '\s+', $words ) . '(?:e?s)?(?![\p{L}\p{N}])/iu', $text );
	}

	/**
	 * Save the card.
	 */
	public static function save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'beaver-press' ), 403 );
		}
		check_admin_referer( 'bp_terms' );
		$codes = array_keys( BP_Run::languages() );
		$rows  = isset( $_POST['bp_terms'] ) && is_array( $_POST['bp_terms'] ) ? wp_unslash( $_POST['bp_terms'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each value sanitised below.
		$old   = get_option( self::OPTION );
		$terms = array();
		foreach ( $rows as $row ) {
			$term = mb_substr( trim( sanitize_text_field( (string) ( $row['term'] ?? '' ) ) ), 0, 60 );
			if ( '' === $term || count( $terms ) >= self::MAX_TERMS ) {
				continue;
			}
			$langs = array();
			foreach ( $codes as $code ) {
				$t = mb_substr( trim( sanitize_text_field( (string) ( $row['lang'][ $code ] ?? '' ) ) ), 0, 80 );
				if ( '' !== $t ) {
					$langs[ $code ] = $t;
				}
			}
			// Languages not offered right now keep their translations.
			foreach ( (array) ( is_array( $old ) ? ( $old['terms'][ $term ] ?? array() ) : array() ) as $code => $t ) {
				if ( ! in_array( $code, $codes, true ) ) {
					$langs[ $code ] = (string) $t;
				}
			}
			$terms[ $term ] = $langs;
		}
		$search = is_array( $old ) ? (array) ( $old['search'] ?? array() ) : array();
		foreach ( $codes as $code ) {
			$raw             = (string) wp_unslash( $_POST['bp_search'][ $code ] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- split and sanitised below.
			$search[ $code ] = self::phrases( $raw );
		}
		update_option(
			self::OPTION,
			array(
				'terms'  => $terms,
				'search' => array_filter( $search ),
			),
			false
		);
		wp_safe_redirect( admin_url( 'options-general.php?page=' . BP_Admin::SLUG . '&tab=instructions&terms=1#bp-terms' ) );
		exit;
	}

	/**
	 * Phrases from a box: one per line or separated by commas.
	 *
	 * @param string $raw Text.
	 * @return string[]
	 */
	public static function phrases( $raw ) {
		$out = array();
		foreach ( preg_split( '/[\r\n,;]+/', (string) $raw ) as $p ) {
			$p = mb_substr( trim( sanitize_text_field( $p ) ), 0, 60 );
			if ( '' !== $p && ! in_array( mb_strtolower( $p ), array_map( 'mb_strtolower', $out ), true ) ) {
				$out[] = $p;
			}
		}
		return array_slice( $out, 0, self::MAX_PHRASES );
	}

	/* ------------------------------------------------------------------ */
	/* Suggestions (one small request per language)                        */
	/* ------------------------------------------------------------------ */

	/**
	 * Check an AJAX request and return the engine to ask, or send the error.
	 *
	 * @return array{engine: array, key: string}
	 */
	private static function request_engine() {
		check_ajax_referer( 'bp_instructions', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'beaver-press' ) ), 403 );
		}
		$mt     = get_option( 'trp_machine_translation_settings', array() );
		$mt     = is_array( $mt ) ? $mt : array();
		$engine = BP_Engine_Settings::current( $mt );
		if ( 'deepl' === $engine['api'] ) {
			wp_send_json_error( array( 'message' => __( 'DeepL cannot make suggestions. Choose a language model (Claude, ChatGPT, DeepSeek, Gemini) in the engine settings.', 'beaver-press' ) ) );
		}
		if ( ! BP_Guard::may_translate( $mt ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed from this request.', 'beaver-press' ) ) );
		}
		$engine['timeout'] = 120;
		return array(
			'engine' => $engine,
			'key'    => (string) BP_Keys::get( $engine['provider'] ),
		);
	}

	/**
	 * Ask once and count the cost.
	 *
	 * @param array  $ask    Engine and key.
	 * @param string $system System prompt.
	 * @param string $user   User message.
	 * @param array  $schema Reply schema.
	 * @return array|WP_Error
	 */
	private static function ask( array $ask, $system, $user, array $schema ) {
		$reply = BP_Providers::request_json( $ask['engine'], $ask['key'], $system, $user, $schema );
		$usage = BP_Providers::last_usage();
		if ( null !== $usage ) {
			BP_Usage::add( $ask['engine']['provider'], $ask['engine']['model'], mb_strlen( $user ), $usage[0], $usage[1] );
		}
		return $reply;
	}

	/**
	 * Language from the request, if offered.
	 *
	 * @return array{0: string, 1: string} Code and name.
	 */
	private static function posted_language() {
		$code      = sanitize_text_field( wp_unslash( $_POST['language'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked in request_engine().
		$languages = BP_Run::languages();
		if ( ! isset( $languages[ $code ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown language.', 'beaver-press' ) ) );
		}
		return array( $code, (string) $languages[ $code ] );
	}

	/**
	 * Suggest translations for the terms given (one language).
	 */
	public static function ajax_suggest_terms() {
		$ask = self::request_engine();
		list( $code, $name ) = self::posted_language();
		$terms = array_slice( array_values( array_filter( array_map( static fn( $t ) => mb_substr( trim( sanitize_text_field( (string) $t ) ), 0, 60 ), (array) wp_unslash( $_POST['terms'] ?? array() ) ) ) ), 0, self::MAX_TERMS ); // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- checked in request_engine(); sanitised here.
		if ( ! $terms ) {
			wp_send_json_error( array( 'message' => __( 'No terms without a translation.', 'beaver-press' ) ) );
		}
		// Translations already in the boxes: longer terms are built from them (2.9.1).
		$known = array();
		foreach ( (array) wp_unslash( $_POST['known'] ?? array() ) as $term => $translation ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- checked in request_engine(); sanitised here.
			$term        = mb_substr( trim( sanitize_text_field( (string) $term ) ), 0, 60 );
			$translation = mb_substr( trim( sanitize_text_field( (string) $translation ) ), 0, 80 );
			if ( '' !== $term && '' !== $translation ) {
				$known[ $term ] = $translation;
			}
		}
		$out = self::suggest_terms( $ask, $code, $name, $terms, $known );
		if ( is_wp_error( $out ) ) {
			wp_send_json_error( array( 'message' => $out->get_error_message() ) );
		}
		wp_send_json_success(
			array(
				'language' => $code,
				'terms'    => $out,
			)
		);
	}

	/**
	 * Ask the engine how a professional travel website in the language writes each term.
	 *
	 * Each term goes with a sentence from the site that uses it, so the meaning is clear; the
	 * rules forbid adding a quality the English does not have (luxury, photo, budget), keep the
	 * answer short, and build longer terms from the base ones (private safari from safari). On
	 * DeepSeek the answers are steadied (low temperature) so asking twice gives the same words.
	 *
	 * @since 2.9.1
	 *
	 * @param array  $ask   Engine and key (request_engine()).
	 * @param string $code  Language code.
	 * @param string $name  Language name.
	 * @param array  $terms English terms to suggest.
	 * @param array  $known Term => translation already chosen.
	 * @return array|WP_Error Term => suggestion.
	 */
	public static function suggest_terms( array $ask, $code, $name, array $terms, array $known = array() ) {
		usort( $terms, static fn( $a, $b ) => mb_strlen( $a ) <=> mb_strlen( $b ) ); // Base words first.
		$items = array();
		foreach ( $terms as $term ) {
			$items[] = array(
				'term'    => $term,
				'example' => self::example( $term, $code ),
			);
		}
		$system = trim( (string) BP_Profiles::get( 'context', 'You translate the texts of a website.' ) )
			. sprintf( ' For each English term, give the word or phrase a professional %1$s travel website uses for it, as used in its example sentence from this site.', $name )
			. "\nRules:"
			. "\n- Exactly the same meaning. Never add a quality or a kind the English term does not have (luxury, budget, photographic, guided, small): \"tented camp\" is a camp with tents, not a luxury camp; \"game drive\" is a wildlife drive in a vehicle, not a photo safari; \"lodge\" is a safari lodge or hotel, not a mountain hut."
			. sprintf( "\n- As short as %s allows: a term, never a description or a whole sentence.", $name )
			. "\n- One word, one translation: when a term contains another term of the list or of the decided ones (\"private safari\" contains \"safari\"), build it from that term's translation."
			. sprintf( "\n- Keep a term in English only where %s travel websites usually do.", $name )
			. ( $known ? "\n- Already decided (use these, do not change them): " . implode( '; ', array_map( static fn( $t, $tr ) => $t . ' = ' . $tr, array_keys( $known ), $known ) ) . '.' : '' )
			. "\nReply with a JSON object only: {\"terms\": [{\"term\": \"<the term exactly as given>\", \"translation\": \"<" . $name . ">\"}]}, one entry per term, in the same order.";
		$schema = array(
			'type'                 => 'object',
			'properties'           => array(
				'terms' => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'properties'           => array(
							'term'        => array( 'type' => 'string' ),
							'translation' => array( 'type' => 'string' ),
						),
						'required'             => array( 'term', 'translation' ),
						'additionalProperties' => false,
					),
				),
			),
			'required'             => array( 'terms' ),
			'additionalProperties' => false,
		);
		$ask['engine']['temperature'] = 0.0;
		$reply                        = self::ask( $ask, $system, 'Terms (JSON): ' . wp_json_encode( array( 'terms' => $items ), JSON_UNESCAPED_UNICODE ), $schema );
		if ( is_wp_error( $reply ) ) {
			return $reply;
		}
		$given = array_combine( array_map( 'mb_strtolower', $terms ), $terms );
		$out   = array();
		foreach ( (array) ( $reply['terms'] ?? array() ) as $row ) {
			$key = mb_strtolower( trim( (string) ( $row['term'] ?? '' ) ) );
			$t   = mb_substr( trim( sanitize_text_field( (string) ( $row['translation'] ?? '' ) ) ), 0, 80 );
			if ( isset( $given[ $key ] ) && '' !== $t ) {
				$out[ $given[ $key ] ] = $t;
			}
		}
		return $out;
	}

	/**
	 * A short English sentence from the site that uses the term, or ''.
	 *
	 * @since 2.9.1
	 *
	 * @param string $term Term.
	 * @param string $code Language code (its table holds the site's English texts).
	 * @return string
	 */
	public static function example( $term, $code ) {
		global $wpdb;
		$table = TRP_Translate_Press::get_trp_instance()->get_component( 'query' )->get_table_name( $code );
		$rows  = $wpdb->get_col( $wpdb->prepare( "SELECT original FROM `{$table}` WHERE original LIKE %s LIMIT 40", '%' . $wpdb->esc_like( $term ) . '%' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery -- engine table name.
		$best  = '';
		foreach ( (array) $rows as $row ) {
			$text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( html_entity_decode( (string) $row, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) ) );
			$len  = mb_strlen( $text );
			if ( $len >= mb_strlen( $term ) + 12 && $len <= 220 && self::occurs( $term, $text ) && ( '' === $best || $len < mb_strlen( $best ) ) ) {
				$best = $text;
			}
		}
		return $best;
	}

	/**
	 * Suggest search phrases for one language.
	 */
	public static function ajax_suggest_search() {
		$ask = self::request_engine();
		list( $code, $name ) = self::posted_language();
		$about  = trim( (string) BP_Instructions::get()['site'] );
		$about  = '' !== $about ? $about : sprintf( (string) BP_Profiles::get( 'site', '%s' ), get_bloginfo( 'name' ) );
		// Up to 40 page titles, taken in turn from each kind of page (tours, destinations, posts...).
		$by = array();
		foreach ( BP_Run::pages() as $page ) {
			$by[ (string) $page['type'] ][] = (string) $page['title'];
		}
		$titles = array();
		for ( $i = 0; count( $titles ) < 40 && $by; $i++ ) {
			foreach ( $by as $type => $list ) {
				if ( ! isset( $list[ $i ] ) ) {
					unset( $by[ $type ] );
					continue;
				}
				$titles[] = $list[ $i ];
			}
		}
		$titles = array_slice( $titles, 0, 40 );
		$system = sprintf( 'You help a travel website be found by travellers who search in %1$s. Suggest up to 10 short phrases that people in %1$s-speaking countries commonly type into a search engine when looking for what this website offers, written the way they type them. Only phrases that match what the site offers; no brand names of other companies; no invented offers. Reply with a JSON object only: {"phrases": ["..."]}.', $name );
		$user   = "About the site:\n" . $about . "\n\nSome of its pages:\n- " . implode( "\n- ", array_map( 'strval', $titles ) );
		$ask['engine']['temperature'] = 0.3; // Steadier suggestions on DeepSeek (2.9.1).
		$reply  = self::ask(
			$ask,
			$system,
			$user,
			array(
				'type'                 => 'object',
				'properties'           => array(
					'phrases' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
				),
				'required'             => array( 'phrases' ),
				'additionalProperties' => false,
			)
		);
		if ( is_wp_error( $reply ) ) {
			wp_send_json_error( array( 'message' => $reply->get_error_message() ) );
		}
		wp_send_json_success(
			array(
				'language' => $code,
				'phrases'  => self::phrases( implode( "\n", array_map( 'strval', (array) ( $reply['phrases'] ?? array() ) ) ) ),
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Card                                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * Card on the Instructions tab.
	 */
	public static function render() {
		$state     = self::get();
		$languages = BP_Run::languages();
		if ( ! $languages ) {
			return;
		}
		$rows = $state['terms'];
		for ( $i = 0; $i < 3; $i++ ) {
			$rows[ '__new' . $i ] = array(); // Empty rows for new terms.
		}
		?>
		<div class="bp-card bp-terms" id="bp-terms">
			<h2><?php esc_html_e( 'Terms and search words', 'beaver-press' ); ?></h2>
			<?php if ( isset( $_GET['terms'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- notice only. ?>
				<div class="notice notice-success inline"><p><?php esc_html_e( 'Saved. They apply to new translations.', 'beaver-press' ); ?></p></div>
			<?php endif; ?>
			<p class="description"><?php esc_html_e( 'Terms: how a tourism term is translated in each language, the same way on every page (game drive = Pirschfahrt). Empty: the translator decides. Search words: what travellers type into a search engine in that language; the translator prefers this wording where a text already says the same thing, above all in titles, headings and descriptions, and never adds it otherwise. Used by language models (not DeepL).', 'beaver-press' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="bp_terms" />
				<?php wp_nonce_field( 'bp_terms' ); ?>
				<h3><?php esc_html_e( 'Terms', 'beaver-press' ); ?></h3>
				<div class="bp-scroll">
					<table class="widefat striped bp-terms__table">
						<thead><tr>
							<th><?php esc_html_e( 'Term (your language)', 'beaver-press' ); ?></th>
							<?php foreach ( $languages as $name ) : ?>
								<th><?php echo esc_html( $name ); ?></th>
							<?php endforeach; ?>
						</tr></thead>
						<tbody>
							<?php
							$i = 0;
							foreach ( $rows as $term => $langs ) :
								$new = 0 === strpos( (string) $term, '__new' );
								?>
								<tr>
									<td><input type="text" class="bp-terms__term" name="bp_terms[<?php echo (int) $i; ?>][term]" value="<?php echo esc_attr( $new ? '' : (string) $term ); ?>" placeholder="<?php esc_attr_e( 'New term', 'beaver-press' ); ?>" /></td>
									<?php foreach ( $languages as $code => $name ) : ?>
										<td><input type="text" class="bp-terms__in" data-lang="<?php echo esc_attr( $code ); ?>" name="bp_terms[<?php echo (int) $i; ?>][lang][<?php echo esc_attr( $code ); ?>]" value="<?php echo esc_attr( (string) ( $langs[ $code ] ?? '' ) ); ?>" aria-label="<?php echo esc_attr( ( $new ? '' : $term . ' - ' ) . $name ); ?>" /></td>
									<?php endforeach; ?>
								</tr>
								<?php
								++$i;
							endforeach;
							?>
						</tbody>
					</table>
				</div>
				<p><button type="button" class="button bp-terms__suggest"><?php esc_html_e( 'Suggest empty translations with AI', 'beaver-press' ); ?></button> <span class="bp-terms__msg" aria-live="polite"></span></p>
				<h3><?php esc_html_e( 'Search words', 'beaver-press' ); ?></h3>
				<p class="description"><?php esc_html_e( 'One phrase per line, up to 12 per language. AI suggestions are a starting point: check them in Google Search Console or a keyword tool and keep what fits your trips.', 'beaver-press' ); ?></p>
				<div class="bp-terms__search">
					<?php foreach ( $languages as $code => $name ) : ?>
						<p>
							<label for="bp-search-<?php echo esc_attr( $code ); ?>"><strong><?php echo esc_html( $name ); ?></strong></label>
							<textarea id="bp-search-<?php echo esc_attr( $code ); ?>" class="large-text bp-terms__phrases" data-lang="<?php echo esc_attr( $code ); ?>" rows="3" name="bp_search[<?php echo esc_attr( $code ); ?>]"><?php echo esc_textarea( implode( "\n", self::search_for( $code ) ) ); ?></textarea>
							<button type="button" class="button button-small bp-terms__suggest-search" data-lang="<?php echo esc_attr( $code ); ?>"><?php esc_html_e( 'Suggest', 'beaver-press' ); ?></button>
						</p>
					<?php endforeach; ?>
				</div>
				<p class="notice notice-warning inline bp-terms__unsaved" hidden><?php esc_html_e( 'Unsaved changes: check the suggestions, then press Save terms and search words. Nothing is used until it is saved.', 'beaver-press' ); ?></p>
				<p><button class="button button-primary"><?php esc_html_e( 'Save terms and search words', 'beaver-press' ); ?></button></p>
			</form>
		</div>
		<?php
	}
}
