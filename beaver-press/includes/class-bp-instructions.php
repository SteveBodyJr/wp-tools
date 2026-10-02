<?php
/**
 * Translator instructions: the owner's own words added to Beaver Press's prompt.
 *
 * One box for the whole site (voice, audience, words to keep) and one per language (form of
 * address, spelling, typography). They are added after Beaver Press's fixed rules, which keep
 * the output safe (JSON reply, tags, placeholders, numbers, names) and always win, so an
 * instruction can change the style but never break a page. Until the owner saves, suggested
 * instructions from the translation profile (Safari & Tourism) are used. Language models only: DeepL has no instructions.
 *
 * @package BeaverPress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Translator instructions.
 */
final class BP_Instructions {

	/** Option: [ 'site' => text, 'langs' => [ code => text ] ] (not autoloaded). */
	const OPTION = 'beaver_press_instructions';

	/** Longest site-wide instruction, characters. */
	const MAX_SITE = 2000;

	/** Longest instruction per language, characters. */
	const MAX_LANG = 1000;

	/**
	 * Suggested instruction for a language: the written one, or a general one for any language.
	 *
	 * @param string $code Language code.
	 * @return string
	 */
	public static function suggestion( $code ) {
		$base  = self::base( $code );
		$langs = (array) BP_Profiles::get( 'langs', array() ); // Suggestions come from the translation profile.
		if ( isset( $langs[ $base ] ) ) {
			return (string) $langs[ $base ];
		}
		$name = (string) ( BP_Run::languages()[ $code ] ?? ( TRP_Translate_Press::get_trp_instance()->get_component( 'languages' )->get_language_names( array( $code ), 'english_name' )[ $code ] ?? $code ) );
		return sprintf( (string) BP_Profiles::get( 'lang_other', 'Write natural %1$s with the form of address %1$s websites usually use.' ), $name );
	}

	/** Settings of the Try-it request (unsaved form values), or null. */
	private static $override = null;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_post_bp_instructions', array( __CLASS__, 'save' ) );
		add_action( 'wp_ajax_bp_try_instructions', array( __CLASS__, 'ajax_try' ) );
		add_action( 'wp_ajax_bp_redo_language', array( __CLASS__, 'ajax_redo' ) );
		add_action( 'admin_post_bp_names', array( __CLASS__, 'save_names' ) );
	}

	/**
	 * Suggested site-wide instruction.
	 *
	 * @return string
	 */
	public static function site_default() {
		// From the translation profile; %s is the site's own name.
		return sprintf(
			(string) BP_Profiles::get( 'site', 'This is the website of %s. Translate so a native speaker reads natural, fluent text that keeps the meaning and tone of the original.' ),
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
		);
	}

	/**
	 * Saved instructions, or the suggestions until the owner saves.
	 *
	 * @return array{site: string, langs: array<string, string>, saved: bool}
	 */
	public static function get() {
		if ( null !== self::$override ) {
			return self::$override;
		}
		$saved = get_option( self::OPTION );
		$langs = array();
		foreach ( array_keys( BP_Run::languages() ) as $code ) {
			if ( is_array( $saved ) && array_key_exists( $code, (array) ( $saved['langs'] ?? array() ) ) ) {
				$langs[ $code ] = (string) $saved['langs'][ $code ]; // Saved, possibly empty on purpose.
			} else {
				// A language added after the last save starts with the suggestion.
				$langs[ $code ] = self::suggestion( $code );
			}
		}
		if ( is_array( $saved ) ) {
			// Languages removed from the site keep their text, in case they come back.
			foreach ( (array) ( $saved['langs'] ?? array() ) as $code => $text ) {
				if ( ! isset( $langs[ $code ] ) ) {
					$langs[ $code ] = (string) $text;
				}
			}
		}
		return array(
			'site'  => is_array( $saved ) ? (string) ( $saved['site'] ?? '' ) : self::site_default(),
			'langs' => $langs,
			'saved' => is_array( $saved ),
		);
	}

	/**
	 * ISO 639-1 part of a TranslatePress code (fr_FR -> fr, nb_NO -> nb).
	 *
	 * @param string $code Language code.
	 * @return string
	 */
	private static function base( $code ) {
		return strtolower( (string) strtok( (string) $code, '_' ) );
	}

	/**
	 * Instruction text for the prompt, or '' when there is none.
	 *
	 * @param string $target_code Target language code (fr_FR).
	 * @param string $target_name Target language name (French).
	 * @return string
	 */
	public static function prompt_part( $target_code, $target_name ) {
		$ins   = self::get();
		$site  = trim( (string) $ins['site'] );
		$lang  = trim( (string) ( $ins['langs'][ $target_code ] ?? '' ) );
		$lines = array();
		if ( '' !== $site ) {
			$lines[] = $site;
		}
		if ( '' !== $lang ) {
			$lines[] = sprintf( 'For %1$s: %2$s', $target_name, $lang );
		}
		if ( ! $lines ) {
			return '';
		}
		// {site} (used in exported settings) is this site's name.
		$lines = str_replace( '{site}', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $lines );
		return "Instructions from the site owner (follow them as long as they do not conflict with the rules above; the rules above always win):\n" . implode( "\n", $lines );
	}

	/**
	 * Clean posted instructions.
	 *
	 * @param array $raw Raw form values.
	 * @return array{site: string, langs: array<string, string>, saved: bool}
	 */
	public static function clean( array $raw ) {
		$langs = array();
		foreach ( array_keys( BP_Run::languages() ) as $code ) {
			$text           = isset( $raw['langs'][ $code ] ) ? sanitize_textarea_field( (string) $raw['langs'][ $code ] ) : '';
			$langs[ $code ] = mb_substr( trim( $text ), 0, self::MAX_LANG ); // Empty = none, on purpose.
		}
		// Keep the text of languages that are not offered right now.
		$old = get_option( self::OPTION );
		foreach ( is_array( $old ) ? (array) ( $old['langs'] ?? array() ) : array() as $code => $text ) {
			if ( ! isset( $langs[ $code ] ) ) {
				$langs[ $code ] = (string) $text;
			}
		}
		return array(
			'site'  => mb_substr( trim( sanitize_textarea_field( (string) ( $raw['site'] ?? '' ) ) ), 0, self::MAX_SITE ),
			'langs' => $langs,
			'saved' => true,
		);
	}

	/**
	 * Save the form (or go back to the suggestions).
	 */
	public static function save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'beaver-press' ), 403 );
		}
		check_admin_referer( 'bp_instructions' );
		if ( isset( $_POST['reset'] ) ) {
			delete_option( self::OPTION );
		} else {
			$clean = self::clean( (array) wp_unslash( $_POST['bp_ins'] ?? array() ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cleaned in clean().
			update_option(
				self::OPTION,
				array(
					'site'  => $clean['site'],
					'langs' => $clean['langs'],
				),
				false
			);
		}
		wp_safe_redirect( admin_url( 'options-general.php?page=' . BP_Admin::SLUG . '&tab=instructions&saved=1' ) );
		exit;
	}

	/**
	 * The full system prompt for a language, as it is sent (names: a sample from the list).
	 *
	 * @param string $code Language code.
	 * @return string
	 */
	public static function full_prompt( $code ) {
		$trp      = TRP_Translate_Press::get_trp_instance();
		$settings = $trp->get_component( 'settings' )->get_settings();
		$names    = $trp->get_component( 'languages' )->get_language_names( array( $settings['default-language'], $code ) );
		$glossary = array_slice( BP_Batch::glossary(), 0, 6 );
		return BP_Batch::system_prompt( (string) ( $names[ $settings['default-language'] ] ?? 'English' ), (string) ( $names[ $code ] ?? $code ), $glossary, $code );
	}

	/**
	 * Try the (unsaved) instructions on a sentence.
	 */
	public static function ajax_try() {
		check_ajax_referer( 'bp_instructions', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'beaver-press' ) ), 403 );
		}
		$language = sanitize_text_field( wp_unslash( $_POST['language'] ?? '' ) );
		$text     = trim( sanitize_textarea_field( wp_unslash( $_POST['text'] ?? '' ) ) );
		if ( ! isset( BP_Run::languages()[ $language ] ) || '' === $text ) {
			wp_send_json_error( array( 'message' => __( 'Write a sentence and choose a language.', 'beaver-press' ) ) );
		}
		$mt = get_option( 'trp_machine_translation_settings', array() );
		if ( 'deepl' === ( $mt['bp-provider'] ?? '' ) ) {
			wp_send_json_error( array( 'message' => __( 'DeepL does not take instructions; choose a language model (Claude, ChatGPT, DeepSeek, Gemini) to use them.', 'beaver-press' ) ) );
		}
		self::$override = self::clean( (array) wp_unslash( $_POST['bp_ins'] ?? array() ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cleaned in clean().
		require_once BP_PATH . 'includes/class-bp-ai-machine-translator.php';
		$trp      = TRP_Translate_Press::get_trp_instance();
		$settings = $trp->get_component( 'settings' )->get_settings();
		$engine   = new BP_AI_Machine_Translator( $settings );
		$started  = microtime( true );
		BP_Provenance::$paused = true; // A trial, not a stored translation.
		$result   = $engine->translate_array( array( 'x' => mb_substr( $text, 0, 600 ) ), $language, $settings['default-language'] );
		BP_Provenance::$paused = false;
		self::$override = null;
		if ( empty( $result['x'] ) ) {
			$error = BP_Providers::last_error( true );
			wp_send_json_error( array( 'message' => $error ? $error['message'] : __( 'No usable translation came back.', 'beaver-press' ) ) );
		}
		wp_send_json_success(
			array(
				'translation' => (string) $result['x'],
				'seconds'     => round( microtime( true ) - $started, 1 ),
			)
		);
	}

	/**
	 * Machine translations of a language that "Redo" would send again.
	 *
	 * @param string $code Language code.
	 * @return array{strings: int, chars: int}
	 */
	public static function machine_counts( $code ) {
		global $wpdb;
		$table = TRP_Translate_Press::get_trp_instance()->get_component( 'query' )->get_table_name( $code );
		if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
			return array( 'strings' => 0, 'chars' => 0 );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from TranslatePress.
		$row = $wpdb->get_row( "SELECT COUNT(*) AS n, COALESCE(SUM(CHAR_LENGTH(original)),0) AS c FROM `{$table}` WHERE status = 1" );
		return array(
			'strings' => (int) ( $row->n ?? 0 ),
			'chars'   => (int) ( $row->c ?? 0 ),
		);
	}

	/**
	 * Redo a language's machine translations with the current instructions: they are set back
	 * to "not translated" (reviewed texts are kept) and every page is queued for that language.
	 */
	public static function ajax_redo() {
		check_ajax_referer( 'bp_instructions', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'beaver-press' ) ), 403 );
		}
		$language = sanitize_text_field( wp_unslash( $_POST['language'] ?? '' ) );
		if ( ! isset( BP_Run::languages()[ $language ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown language.', 'beaver-press' ) ) );
		}
		$blocker = BP_Run::blocker();
		if ( '' !== $blocker ) {
			wp_send_json_error( array( 'message' => $blocker ) );
		}
		global $wpdb;
		$table = TRP_Translate_Press::get_trp_instance()->get_component( 'query' )->get_table_name( $language );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from TranslatePress.
		do_action( 'beaver_press_before_reset', __( 'Redo', 'beaver-press' ), (string) ( BP_Run::languages()[ $language ] ?? $language ) ); // Backup first.
		$reset = (int) $wpdb->query( "UPDATE `{$table}` SET translated = '', status = 0 WHERE status = 1" );
		do_action( 'beaver_press_translations_saved', $language );
		$state = BP_Run::add( BP_Run::urls(), array( $language ) );
		if ( is_wp_error( $state ) ) {
			wp_send_json_error( array( 'message' => $state->get_error_message() ) );
		}
		wp_send_json_success(
			array(
				/* translators: 1: texts, 2: language */
				'message' => sprintf( __( '%1$s machine translations of %2$s will be redone; every page is queued for the run (see Translate site or Pages).', 'beaver-press' ), number_format_i18n( $reset ), BP_Run::languages()[ $language ] ),
			)
		);
	}

	/**
	 * "Names to keep" card: groups and every name, ticked = kept as written in every language.
	 * The AI sorts them with one written rule ("How to decide"); see BP_Names_AI.
	 */
	public static function render_names() {
		$groups  = BP_Glossary::groups();
		$choices = BP_Glossary::choices();
		$state   = BP_Names_AI::state();
		$ai      = (array) ( $state['ai'] ?? array() );
		$rule    = BP_Names_AI::rule();
		$mt      = get_option( 'trp_machine_translation_settings', array() );
		$own     = is_array( $mt ) ? (string) ( $mt['bp-glossary'] ?? '' ) : '';
		$deepl   = is_array( $mt ) && 'deepl' === ( $mt['bp-provider'] ?? '' );
		?>
		<div class="bp-card bp-names" id="bp-names">
			<h2><?php esc_html_e( 'Names to keep in every language', 'beaver-press' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Ticked names keep their spelling in every translation (generic words around them, such as "National Park" or "Lake", are still translated). Unticked names are translated. The list follows the site, and the AI sorts new names with the rule under "How to decide". Your own ticks always win. The site name is always kept.', 'beaver-press' ); ?></p>
			<?php if ( isset( $_GET['names'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- notice only. ?>
				<div class="notice notice-success inline"><p><?php esc_html_e( 'Names saved. They apply to new translations.', 'beaver-press' ); ?></p></div>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="bp_names" />
				<?php wp_nonce_field( 'bp_names' ); ?>

				<details class="bp-names__rule">
					<summary><strong><?php esc_html_e( 'How to decide', 'beaver-press' ); ?></strong> <span class="bp-names__count"><?php echo esc_html( trim( (string) ( $state['rule'] ?? '' ) ) !== '' ? __( 'your own rule', 'beaver-press' ) : __( 'built-in rule', 'beaver-press' ) ); ?></span></summary>
					<p class="description"><?php esc_html_e( 'The AI reads this rule to sort the names. Write it like a note to a colleague: what to keep, what to translate, a few examples. Changes are used by the buttons below straight away and by the daily run once saved.', 'beaver-press' ); ?></p>
					<textarea name="bp_names_rule" id="bp-names-rule" rows="10" class="large-text" data-default="<?php echo esc_attr( BP_Names_AI::default_rule() ); ?>"><?php echo esc_textarea( $rule ); ?></textarea>
					<p>
						<button type="button" class="button-link bp-names__reset"><?php esc_html_e( 'Reset to the built-in rule', 'beaver-press' ); ?></button>
					</p>
					<p><label><input type="checkbox" name="bp_names_auto" value="yes" <?php checked( BP_Names_AI::auto_enabled() ); ?> /> <?php esc_html_e( 'Sort new names with this rule in the daily run (only new names, one small request)', 'beaver-press' ); ?></label></p>
				</details>

				<div class="bp-names__bar">
					<input type="search" class="bp-names__search" placeholder="<?php esc_attr_e( 'Search names', 'beaver-press' ); ?>" aria-label="<?php esc_attr_e( 'Search names', 'beaver-press' ); ?>" />
					<span class="bp-names__shown" hidden>
						<button type="button" class="button-link bp-names__tick-shown"><?php esc_html_e( 'Tick shown', 'beaver-press' ); ?></button> ·
						<button type="button" class="button-link bp-names__untick-shown"><?php esc_html_e( 'Untick shown', 'beaver-press' ); ?></button>
					</span>
					<span class="bp-names__ai-tools">
						<button type="button" class="button bp-names__suggest" <?php disabled( $deepl ); ?>><?php esc_html_e( 'Suggest with AI', 'beaver-press' ); ?></button>
						<button type="button" class="button bp-names__apply-all" <?php echo $ai ? '' : 'hidden'; ?>><?php esc_html_e( 'Apply all AI suggestions', 'beaver-press' ); ?></button>
					</span>
				</div>
				<p class="bp-names__msg" aria-live="polite"><?php echo $deepl ? esc_html__( 'DeepL cannot sort names; choose a language model in the engine settings to use the AI buttons.', 'beaver-press' ) : ''; ?></p>

				<?php foreach ( $groups as $id => $group ) : ?>
					<?php
					$kept = 0;
					foreach ( $group['names'] as $n ) {
						$kept += empty( $choices['off'][ md5( $n ) ] ) ? 1 : 0;
					}
					?>
					<details class="bp-names__group" data-group="<?php echo esc_attr( $id ); ?>">
						<summary>
							<label onclick="event.stopPropagation()"><input type="checkbox" class="bp-names__on" name="bp_names_groups[<?php echo esc_attr( $id ); ?>]" value="yes" <?php checked( 'yes' === ( $choices['groups'][ $id ] ?? 'yes' ) ); ?> /> <strong><?php echo esc_html( $group['label'] ); ?></strong></label>
							<span class="bp-names__count">
								<?php
								echo esc_html(
									'yes' === ( $choices['groups'][ $id ] ?? 'yes' )
										/* translators: 1: names kept, 2: names in the group */
										? sprintf( __( '%1$s of %2$s kept', 'beaver-press' ), number_format_i18n( $kept ), number_format_i18n( count( $group['names'] ) ) )
										/* translators: %s: names in the group */
										: sprintf( __( 'group off: its %s names are translated', 'beaver-press' ), number_format_i18n( count( $group['names'] ) ) )
								);
								?>
							</span>
						</summary>
						<p class="bp-names__tools">
							<button type="button" class="button-link bp-names__all"><?php esc_html_e( 'Tick all', 'beaver-press' ); ?></button> ·
							<button type="button" class="button-link bp-names__none"><?php esc_html_e( 'Untick all', 'beaver-press' ); ?></button> ·
							<button type="button" class="button-link bp-names__apply"><?php esc_html_e( 'Apply AI suggestions', 'beaver-press' ); ?></button>
						</p>
						<div class="bp-names__list">
							<?php foreach ( $group['names'] as $n ) : ?>
								<?php
								$hash = md5( $n );
								$tag  = $ai[ $hash ] ?? null;
								?>
								<label data-name="<?php echo esc_attr( mb_strtolower( $n ) ); ?>"><input type="checkbox" name="bp_names_keep[]" value="<?php echo esc_attr( $hash ); ?>" <?php checked( empty( $choices['off'][ $hash ] ) ); ?> /> <?php echo esc_html( $n ); ?><?php
								if ( $tag ) :
									?>
									<span class="bp-names__ai bp-names__ai--<?php echo $tag['k'] ? 'keep' : 'translate'; ?>" data-keep="<?php echo $tag['k'] ? '1' : '0'; ?>" title="<?php echo esc_attr( (string) $tag['r'] ); ?>"><?php echo esc_html( $tag['k'] ? __( 'AI: keep', 'beaver-press' ) : __( 'AI: translate', 'beaver-press' ) ); ?><?php echo empty( $tag['a'] ) ? '' : ' ' . esc_html__( '(sorted automatically)', 'beaver-press' ); ?></span>
									<?php
								endif;
								?></label>
							<?php endforeach; ?>
						</div>
					</details>
				<?php endforeach; ?>

				<p>
					<label for="bp-names-own"><strong><?php esc_html_e( 'Your own names', 'beaver-press' ); ?></strong></label>
					<textarea id="bp-names-own" name="bp_names_own" rows="4" class="large-text" placeholder="<?php esc_attr_e( 'One per line, e.g. Karibu, Big Five, a guide\'s name or a brand', 'beaver-press' ); ?>"><?php echo esc_textarea( $own ); ?></textarea>
				</p>
				<p class="bp-names__find-bar">
					<button type="button" class="button bp-names__find" <?php disabled( $deepl ); ?>><?php esc_html_e( 'Find names I missed', 'beaver-press' ); ?></button>
					<span class="description"><?php esc_html_e( 'Looks through the site\'s texts for names that are not on this card and asks the AI which ones to keep (one small request).', 'beaver-press' ); ?></span>
				</p>
				<div class="bp-names__found" hidden></div>
				<p><button class="button button-primary"><?php esc_html_e( 'Save names', 'beaver-press' ); ?></button></p>
			</form>
		</div>
		<?php
	}

	/**
	 * Save the names card.
	 */
	public static function save_names() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'beaver-press' ), 403 );
		}
		check_admin_referer( 'bp_names' );
		$on   = isset( $_POST['bp_names_groups'] ) && is_array( $_POST['bp_names_groups'] ) ? array_map( 'sanitize_key', array_keys( wp_unslash( $_POST['bp_names_groups'] ) ) ) : array();
		$keep = isset( $_POST['bp_names_keep'] ) && is_array( $_POST['bp_names_keep'] ) ? array_flip( array_map( 'sanitize_key', wp_unslash( $_POST['bp_names_keep'] ) ) ) : array();
		$save = array(
			'groups' => array(),
			'off'    => array(),
		);
		foreach ( BP_Glossary::groups() as $id => $group ) {
			$save['groups'][ $id ] = in_array( $id, $on, true ) ? 'yes' : 'no';
			foreach ( $group['names'] as $n ) {
				if ( ! isset( $keep[ md5( $n ) ] ) ) {
					$save['off'][ md5( $n ) ] = 1;
				}
			}
		}
		// The rule: stored only when it differs from the built-in one, so updates reach it.
		$rule         = trim( str_replace( "\r\n", "\n", sanitize_textarea_field( wp_unslash( $_POST['bp_names_rule'] ?? '' ) ) ) );
		$save['rule'] = ( '' === $rule || trim( str_replace( "\r\n", "\n", BP_Names_AI::default_rule() ) ) === $rule ) ? '' : mb_substr( $rule, 0, 4000 );
		$save['auto'] = isset( $_POST['bp_names_auto'] ) ? 'yes' : 'no';
		// Every name on the card now is the owner's choice: the daily run leaves it alone.
		$save['seen'] = array_fill_keys( array_keys( BP_Names_AI::all_names() ), 1 );
		BP_Names_AI::store( $save );
		// Own names live with the engine settings (used by the translator).
		$mt    = get_option( 'trp_machine_translation_settings', array() );
		$mt    = is_array( $mt ) ? $mt : array();
		$lines = preg_split( '/\R/', (string) wp_unslash( $_POST['bp_names_own'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each line sanitized below.
		$lines = array_unique( array_filter( array_map( static fn( $l ) => trim( sanitize_text_field( $l ) ), (array) $lines ) ) );
		$mt['bp-glossary'] = implode( "\n", array_slice( $lines, 0, 500 ) );
		update_option( 'trp_machine_translation_settings', $mt );
		wp_safe_redirect( admin_url( 'options-general.php?page=' . BP_Admin::SLUG . '&tab=instructions&names=1#bp-names' ) );
		exit;
	}

	/**
	 * Instructions tab.
	 */
	public static function render() {
		$ins       = self::get();
		$languages = BP_Run::languages();
		$mt        = get_option( 'trp_machine_translation_settings', array() );
		$deepl     = 'deepl' === ( $mt['bp-provider'] ?? '' );
		$first     = (string) array_key_first( $languages );
		?>
		<?php BP_Profiles::render(); ?>
		<div class="bp-card bp-ins" id="bp-ins">
			<h2><?php esc_html_e( 'Instructions for the translator', 'beaver-press' ); ?></h2>
			<?php if ( isset( $_GET['saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- notice only. ?>
				<div class="notice notice-success inline"><p><?php esc_html_e( 'Instructions saved. They apply to new translations; use "Redo" below to apply them to a language already translated.', 'beaver-press' ); ?></p></div>
			<?php endif; ?>
			<?php if ( $deepl ) : ?>
				<p class="bp-status bp-status--error"><?php esc_html_e( 'The engine is set to DeepL, which does not take instructions. They are used with Claude, ChatGPT, DeepSeek, Gemini and custom models.', 'beaver-press' ); ?></p>
			<?php endif; ?>
			<p class="description">
				<?php esc_html_e( 'Tell the translator how your site speaks: audience, tone, form of address, spelling, words to keep. Plain sentences work best. They are added after Beaver Press\'s fixed rules (JSON reply, tags, placeholders, numbers, names), which always win, so an instruction can change the style but cannot break a page.', 'beaver-press' ); ?>
				<?php if ( ! $ins['saved'] ) : ?>
					<strong><?php esc_html_e( 'Suggested instructions are shown and already in use; edit them and Save to make them yours.', 'beaver-press' ); ?></strong>
				<?php endif; ?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bp-ins__form">
				<input type="hidden" name="action" value="bp_instructions" />
				<?php wp_nonce_field( 'bp_instructions' ); ?>
				<p>
					<label for="bp-ins-site"><strong><?php esc_html_e( 'Master instruction (all languages)', 'beaver-press' ); ?></strong></label>
					<textarea id="bp-ins-site" name="bp_ins[site]" rows="5" class="large-text" maxlength="<?php echo esc_attr( self::MAX_SITE ); ?>"><?php echo esc_textarea( $ins['site'] ); ?></textarea>
				</p>
				<?php
				$saved_langs = get_option( self::OPTION );
				$saved_langs = is_array( $saved_langs ) ? (array) ( $saved_langs['langs'] ?? array() ) : array();
				?>
				<?php foreach ( $languages as $code => $name ) : ?>
					<p>
						<label for="bp-ins-<?php echo esc_attr( $code ); ?>"><strong><?php echo esc_html( $name ); ?></strong>
							<?php if ( ! array_key_exists( $code, $saved_langs ) ) : ?>
								<span class="description"><?php esc_html_e( '(suggested)', 'beaver-press' ); ?></span>
							<?php endif; ?>
						</label>
						<textarea id="bp-ins-<?php echo esc_attr( $code ); ?>" name="bp_ins[langs][<?php echo esc_attr( $code ); ?>]" rows="2" class="large-text" maxlength="<?php echo esc_attr( self::MAX_LANG ); ?>"><?php echo esc_textarea( $ins['langs'][ $code ] ?? '' ); ?></textarea>
					</p>
				<?php endforeach; ?>
				<p class="bp-ins__buttons">
					<button class="button button-primary"><?php esc_html_e( 'Save instructions', 'beaver-press' ); ?></button>
					<button class="button" name="reset" value="1" onclick="return window.confirm(this.getAttribute('data-confirm'));" data-confirm="<?php esc_attr_e( 'Go back to the suggested instructions?', 'beaver-press' ); ?>"><?php esc_html_e( 'Use the suggestions', 'beaver-press' ); ?></button>
				</p>
			</form>
		</div>

		<?php self::render_names(); ?>

		<div class="bp-card bp-ins-try" id="bp-ins-try">
			<h2><?php esc_html_e( 'Try it', 'beaver-press' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Translates one text with the instructions as they are in the boxes above, saved or not. Nothing is stored; it costs a fraction of a cent.', 'beaver-press' ); ?></p>
			<textarea class="large-text bp-ins-try__text" rows="3"><?php echo esc_textarea( (string) BP_Profiles::get( 'try_sample', 'Our team answers within one working day. Questions? Write to hello@example.com.' ) ); ?></textarea>
			<p class="bp-ins-try__row">
				<select class="bp-ins-try__lang">
					<?php foreach ( $languages as $code => $name ) : ?>
						<option value="<?php echo esc_attr( $code ); ?>"><?php echo esc_html( $name ); ?></option>
					<?php endforeach; ?>
				</select>
				<button type="button" class="button bp-ins-try__go" <?php disabled( $deepl || '' !== BP_Run::blocker() ); ?>><?php esc_html_e( 'Translate', 'beaver-press' ); ?></button>
				<span class="bp-ins-try__msg" aria-live="polite"></span>
			</p>
			<blockquote class="bp-ins-try__out" hidden></blockquote>
		</div>

		<div class="bp-card bp-ins-prompt">
			<h2><?php esc_html_e( 'The full prompt', 'beaver-press' ); ?></h2>
			<p class="description"><?php esc_html_e( 'What the model receives with each batch of texts (with the saved instructions). The rules part is fixed. Names shown are a sample of the site\'s names list; each batch gets the names that appear in it.', 'beaver-press' ); ?></p>
			<?php foreach ( $languages as $code => $name ) : ?>
				<details <?php echo $code === $first ? 'open' : ''; ?>>
					<summary><?php echo esc_html( $name ); ?></summary>
					<pre class="bp-ins-prompt__text"><?php echo esc_html( self::full_prompt( $code ) ); ?></pre>
				</details>
			<?php endforeach; ?>
		</div>

		<div class="bp-card bp-ins-redo">
			<h2><?php esc_html_e( 'Apply to texts already translated', 'beaver-press' ); ?></h2>
			<p class="description"><?php esc_html_e( 'New instructions apply to new translations. To redo a language\'s machine translations with them: reviewed and edited texts are kept, the rest are set back to "not translated" and every page is queued for that language. Until the run reaches a page, visitors see it in the original language.', 'beaver-press' ); ?></p>
			<ul class="bp-ins-redo__list">
				<?php foreach ( $languages as $code => $name ) : ?>
					<?php $c = self::machine_counts( $code ); ?>
					<li>
						<button type="button" class="button bp-ins-redo__go" data-language="<?php echo esc_attr( $code ); ?>" <?php disabled( 0 === $c['strings'] || $deepl ); ?>
							data-confirm="<?php echo esc_attr( sprintf( /* translators: 1: texts, 2: language, 3: characters */ __( 'Redo %1$s machine translations of %2$s (about %3$s characters sent again)?', 'beaver-press' ), number_format_i18n( $c['strings'] ), $name, number_format_i18n( $c['chars'] ) ) ); ?>">
							<?php echo esc_html( sprintf( /* translators: %s: language */ __( 'Redo %s', 'beaver-press' ), $name ) ); ?>
						</button>
						<span class="description"><?php echo esc_html( sprintf( /* translators: 1: texts, 2: characters */ __( '%1$s machine translations, %2$s characters', 'beaver-press' ), number_format_i18n( $c['strings'] ), number_format_i18n( $c['chars'] ) ) ); ?></span>
					</li>
				<?php endforeach; ?>
			</ul>
			<p class="bp-ins-redo__msg" aria-live="polite"></p>
		</div>
		<?php
	}
}
