<?php
/**
 * Translation profiles: the product's translation intelligence in one place.
 *
 * Beaver Press is built for safari and tourism websites. The profile holds everything the AI is
 * told about the kind of site before the site's own instructions: industry context, style,
 * terminology, region, what must never change, per-language suggestions, the rule for names to
 * keep and the examples. The engine and the request code only read from here.
 *
 * Only "Safari & Tourism" exists today (and is the default). More profiles can be added through
 * the `beaver_press_profiles` filter without touching the engine or the request code. A site's
 * own saved instruction, per-language instructions and names rule always win over the profile.
 *
 * @package BeaverPress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Profiles.
 */
final class BP_Profiles {

	/** Option: the chosen profile id. */
	const OPTION = 'beaver_press_profile';

	/** Default profile. */
	const DEFAULT_ID = 'safari';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_post_bp_profile', array( __CLASS__, 'save' ) );
	}

	/**
	 * Every profile (filter `beaver_press_profiles`).
	 *
	 * @return array Id => profile.
	 */
	public static function all() {
		$profiles = array(
			'safari' => array(
				'label'        => __( 'Safari & Tourism', 'beaver-press' ),
				'description'  => __( 'For safari operators, tour companies, travel agencies, lodges, camps, hotels and destination companies, especially in East Africa.', 'beaver-press' ),
				// First line of every request.
				'context'      => 'You translate the website of a safari and tourism business (a safari or tour operator, travel company, lodge, camp, hotel or destination management company), most often in East Africa.',
				// How the text should read.
				'style'        => 'Write natural, persuasive and professional tourism %s for international travellers, as a native travel writer would: clear, warm and precise, never a literal word-for-word translation and never over-selling.',
				// Terms the model should translate with the target language's established tourism words.
				'terms'        => array( 'safari', 'game drive', 'wildlife viewing', 'national park', 'conservation area', 'conservation', 'lodge', 'camp', 'tented camp', 'itinerary', 'accommodation', 'airport transfer', 'airport pickup', 'private safari', 'group safari', 'safari guide', 'walking safari', 'cultural experience', 'great migration', 'trekking', 'mountain trekking', 'destination', 'travel experience' ),
				// Places whose names keep their usual form.
				'places'       => array( 'Tanzania', 'Kenya', 'Uganda', 'Rwanda', 'Serengeti', 'Ngorongoro', 'Tarangire', 'Lake Manyara', 'Arusha', 'Kilimanjaro', 'Zanzibar' ),
				// What must never change (added to the fixed technical rules).
				'protect'      => 'Never translate or alter company names, lodge, camp and hotel names, safari and tour package names, package or booking codes, or brand names; keep park and place names in their usual form (only the generic word around them is translated). Never invent prices, durations, availability, wildlife sightings, distances or other tourism facts.',
				// Example for names with generic words (shown only when the batch has names).
				'name_example' => 'Kilimanjaro stays Kilimanjaro, not Kilimandjaro or Kilimandscharo; translate the generic words around park and place names the way a %1$s travel website would (National Park, Lake, Crater, Island, Mount, Route, Gate): in French "Serengeti National Park" becomes "Parc national du Serengeti" and "Lake Manyara" becomes "lac Manyara".',
				// Suggested site instruction until the owner saves one (%s = the site's own name).
				'site'         => '%s is a safari and tourism business. Write warmly and precisely, like an experienced safari planner talking to a traveller: clear, friendly and never pushy, with no hype or superlatives that are not in the original. Keep the names of the company, lodges, camps, packages and parks as written. If the site describes another country or kind of trip, follow the site.',
				// Suggested per-language instructions.
				'langs'        => array(
					'fr' => 'Address the reader formally (vous). Use French typography: a space before ? ! : ; and « » quotation marks.',
					'de' => 'Address the reader formally (Sie). Write natural German compounds and use „ “ quotation marks.',
					'es' => 'Address the reader informally (tú), as Spanish travel websites do, in neutral Spanish understood in Spain and Latin America.',
					'it' => 'Address the reader informally (tu), as Italian travel websites commonly do.',
					'pt' => 'Address the reader as você.',
					'nl' => 'Address the reader informally (je/jij), as Dutch travel websites do.',
					'da' => 'Address the reader informally (du), as Danish travel websites do.',
					'sv' => 'Address the reader informally (du), as Swedish travel websites do.',
					'nb' => 'Address the reader informally (du), as Norwegian travel websites do.',
					'fi' => 'Address the reader informally (sinä), as Finnish travel websites do.',
					'pl' => 'Address the reader politely with the Państwo forms, as Polish travel websites often do.',
					'cs' => 'Address the reader formally (vy).',
					'ru' => 'Address the reader formally (вы).',
					'tr' => 'Address the reader formally (siz).',
					'ja' => 'Use the polite desu/masu style.',
					'ko' => 'Use the polite formal style (합니다체).',
					'zh' => 'Use Simplified Chinese unless the language is Traditional Chinese (Taiwan, Hong Kong).',
					'ar' => 'Use Modern Standard Arabic.',
					'sw' => 'Use standard Swahili (Kiswahili sanifu), as used by East African tourism businesses.',
					'he' => 'Address the reader in a friendly, neutral way, as Israeli travel websites do.',
				),
				// Suggestion for a language not listed above (%1$s = language name).
				'lang_other'   => 'Write natural %1$s as %1$s travel websites do, with the form of address they usually use.',
				// The rule the AI uses to sort names to keep.
				'names_rule'   => "You are sorting names from a safari and tourism website (most often in East Africa). For each name, decide whether it must stay exactly as written in every language (keep) or may be translated (translate). Think of a traveller reading the page in their own language.\n\n"
					. "Keep a name when it is a proper name: a person, a people or tribe, a town, region, park, mountain, peak, river or island; the name of a business (lodge, camp, hotel, resort, tour company) as it would appear on its sign or booking page; a safari or trek route; a package name; or a word in a local language used as a name (for example Tembo, Twiga, Simba, Karibu). A generic word next to a proper name, such as National Park, Lake, Lodge or Camp, does not stop the name from being kept; the translator handles that word.\n\n"
					. "Translate a name when it is mainly a description in ordinary words, so a reader would expect it in their own language: a mountain hut or campsite named after a landscape feature or a number (Third Cave Camp, School Hut), a plain description of a place or a trip (Northern Circuit, Rest House), or an English phrase used as a title. The proper part inside it still stays as written.\n\n"
					. 'When unsure, keep. Never translate a word from a local language. Give a reason of a few words for each name.',
				// Sentence in the "Try it" box.
				'try_sample'   => 'Your guide meets you at Kilimanjaro Airport. Game drives in Serengeti National Park start at 6:30 and a private safari costs from $250 per person per day. Ask us anything!',
			),
		);
		return (array) apply_filters( 'beaver_press_profiles', $profiles );
	}

	/**
	 * The chosen profile's id (falls back to the default).
	 *
	 * @return string
	 */
	public static function current_id() {
		$id = (string) get_option( self::OPTION, self::DEFAULT_ID );
		return isset( self::all()[ $id ] ) ? $id : self::DEFAULT_ID;
	}

	/**
	 * The chosen profile.
	 *
	 * @return array
	 */
	public static function current() {
		$all = self::all();
		return $all[ self::current_id() ] ?? $all[ self::DEFAULT_ID ];
	}

	/**
	 * One value of the chosen profile.
	 *
	 * @param string $key      Key.
	 * @param mixed  $fallback Value when the profile has none.
	 * @return mixed
	 */
	public static function get( $key, $fallback = '' ) {
		return self::current()[ $key ] ?? $fallback;
	}

	/**
	 * Profile lines for the request (after the fixed technical rules, before the site's own
	 * instructions).
	 *
	 * @param string $target Target language name.
	 * @return string[]
	 */
	public static function prompt_lines( $target ) {
		$lines = array();
		if ( '' !== (string) self::get( 'style' ) ) {
			$lines[] = '- ' . sprintf( (string) self::get( 'style' ), $target );
		}
		if ( self::get( 'terms', array() ) ) {
			$lines[] = '- Translate tourism terms such as ' . implode( ', ', (array) self::get( 'terms', array() ) ) . ' with the established words of the target language, only where they appear; never add them.';
		}
		if ( self::get( 'places', array() ) ) {
			$lines[] = '- Places such as ' . implode( ', ', (array) self::get( 'places', array() ) ) . ' keep their usual name in the target language; never add a place that is not in the text.';
		}
		if ( '' !== (string) self::get( 'protect' ) ) {
			$lines[] = '- ' . (string) self::get( 'protect' );
		}
		return $lines;
	}

	/**
	 * Save the chosen profile.
	 */
	public static function save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'beaver-press' ), 403 );
		}
		check_admin_referer( 'bp_profile' );
		$id = sanitize_key( wp_unslash( $_POST['profile'] ?? '' ) );
		update_option( self::OPTION, isset( self::all()[ $id ] ) ? $id : self::DEFAULT_ID, false );
		wp_safe_redirect( admin_url( 'options-general.php?page=' . BP_Admin::SLUG . '&tab=instructions&profile=1' ) );
		exit;
	}

	/**
	 * Profile card (Instructions tab).
	 */
	public static function render() {
		$all = self::all();
		$cur = self::current_id();
		?>
		<div class="bp-card" id="bp-profile">
			<h2><?php esc_html_e( 'Translation profile', 'beaver-press' ); ?></h2>
			<p class="description"><?php esc_html_e( 'What the AI is told about your kind of website before your own instructions: style, tourism terminology, places, and what must never change. Your own instructions below always come last and win.', 'beaver-press' ); ?></p>
			<?php if ( isset( $_GET['profile'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- notice only. ?>
				<div class="notice notice-success inline"><p><?php esc_html_e( 'Profile saved. It applies to new translations.', 'beaver-press' ); ?></p></div>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="bp_profile" />
				<?php wp_nonce_field( 'bp_profile' ); ?>
				<?php foreach ( $all as $id => $p ) : ?>
					<p><label><input type="radio" name="profile" value="<?php echo esc_attr( (string) $id ); ?>" <?php checked( $cur, $id ); ?> /> <strong><?php echo esc_html( (string) $p['label'] ); ?></strong> - <?php echo esc_html( (string) ( $p['description'] ?? '' ) ); ?></label></p>
				<?php endforeach; ?>
				<?php if ( count( $all ) > 1 ) : ?>
					<p><button class="button"><?php esc_html_e( 'Save profile', 'beaver-press' ); ?></button></p>
				<?php endif; ?>
			</form>
		</div>
		<?php
	}
}
