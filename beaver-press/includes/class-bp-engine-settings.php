<?php
/**
 * The Beaver AI engine inside TranslatePress -> Automatic Translation: registration, the
 * settings panel, saving (keys encrypted, never stored in TranslatePress's settings),
 * "Test connection" and "Load models".
 *
 * Follows the pattern TranslatePress's own paid engines use (engine list + class filters,
 * a panel whose id equals the engine key, fields saved through the sanitize filter), with
 * these differences: keys are password fields stored encrypted, the settings page never
 * calls the provider (the test result is cached), and there is no licence check.
 *
 * @package BeaverPress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Engine registration and settings.
 */
final class BP_Engine_Settings {

	/** Engine key in TranslatePress. */
	const ENGINE = 'beaver_ai';

	/** Last "Test connection" result for the current settings. */
	const TEST_OPTION = 'beaver_press_test';

	/** AJAX nonce action. */
	const NONCE = 'bp_admin';

	/** Providers in the engine's switcher: AI models and DeepL. */
	const PROVIDERS = array( 'claude', 'openai', 'deepseek', 'gemini', 'deepl', 'custom' );

	/**
	 * Hooks. Runs when the plugin file loads: TranslatePress builds its engine on
	 * plugins_loaded priority 2, so the class filter must exist before that.
	 */
	public static function register() {
		add_filter( 'trp_automatic_translation_engines_classes', array( __CLASS__, 'engine_class' ) );
		add_filter( 'trp_machine_translation_engines', array( __CLASS__, 'engine_option' ), 20 );
		add_action( 'trp_machine_translation_extra_settings_middle', array( __CLASS__, 'render_panel' ) );
		add_filter( 'trp_machine_translation_sanitize_settings', array( __CLASS__, 'sanitize' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'wp_ajax_bp_test_connection', array( __CLASS__, 'ajax_test' ) );
		add_action( 'wp_ajax_bp_list_models', array( __CLASS__, 'ajax_models' ) );
	}

	/**
	 * Tell TranslatePress which class runs "beaver_ai".
	 *
	 * @param array $classes Engine key => class.
	 * @return array
	 */
	public static function engine_class( $classes ) {
		if ( class_exists( 'TRP_Machine_Translator' ) ) {
			require_once BP_PATH . 'includes/class-bp-ai-machine-translator.php';
			$classes[ self::ENGINE ] = 'BP_AI_Machine_Translator';
		}
		return $classes;
	}

	/**
	 * Entry in the engine dropdown.
	 *
	 * @param array $engines Engines.
	 * @return array
	 */
	public static function engine_option( $engines ) {
		$engines[] = array(
			'value' => self::ENGINE,
			'label' => __( 'Beaver Press (Claude, ChatGPT, DeepSeek, Gemini, DeepL)', 'beaver-press' ),
		);
		return $engines;
	}

	/**
	 * Engine settings resolved against the presets.
	 *
	 * @param array $mt TranslatePress machine translation settings.
	 * @return array provider, api, url, model, endpoint, chunk, effort, timeout.
	 */
	public static function current( $mt ) {
		$mt       = is_array( $mt ) ? $mt : array();
		$provider = in_array( $mt['bp-provider'] ?? '', self::PROVIDERS, true ) ? $mt['bp-provider'] : 'claude';
		$preset   = BP_Providers::preset( $provider );
		$model    = trim( (string) ( $mt['bp-model'] ?? '' ) );
		$endpoint = (string) ( $mt['bp-endpoint'] ?? '' );
		return array(
			'provider' => $provider,
			'api'      => $preset['api'],
			'url'      => 'custom' === $provider ? $endpoint : $preset['url'],
			'endpoint' => $endpoint,
			'model'    => '' !== $model ? $model : (string) $preset['model'],
			'chunk'    => min( 50, max( 5, (int) ( $mt['bp-chunk-size'] ?? 20 ) ) ),
			'effort'   => in_array( $mt['bp-effort'] ?? '', array( 'low', 'medium', 'high' ), true ) ? $mt['bp-effort'] : 'low',
			'timeout'  => 60,
		);
	}

	/**
	 * Keep our fields when TranslatePress saves its tab, and store typed keys encrypted.
	 *
	 * @param array $settings Cleaned settings (TranslatePress keeps only its own keys).
	 * @param array $raw      Submitted settings.
	 * @return array
	 */
	public static function sanitize( $settings, $raw = array() ) {
		$raw = is_array( $raw ) ? $raw : array();
		$old = get_option( 'trp_machine_translation_settings', array() );
		$old = is_array( $old ) ? $old : array();
		$get = static function ( $key ) use ( $raw, $old ) {
			return $raw[ $key ] ?? ( $old[ $key ] ?? null );
		};

		$provider                 = (string) $get( 'bp-provider' );
		$settings['bp-provider']  = in_array( $provider, self::PROVIDERS, true ) ? $provider : 'claude';
		$settings['bp-model']     = preg_replace( '/\s+/', '', sanitize_text_field( (string) $get( 'bp-model' ) ) );
		$endpoint                 = esc_url_raw( trim( (string) $get( 'bp-endpoint' ) ), array( 'http', 'https' ) );
		$settings['bp-endpoint']  = $endpoint;
		$settings['bp-chunk-size'] = min( 50, max( 5, (int) ( $get( 'bp-chunk-size' ) ?? 20 ) ) );
		$effort                   = (string) $get( 'bp-effort' );
		$settings['bp-effort']    = in_array( $effort, array( 'low', 'medium', 'high' ), true ) ? $effort : 'low';
		$settings['bp-visitor-guard'] = 'no' === $get( 'bp-visitor-guard' ) ? 'no' : 'yes';
		$settings['bp-glossary-auto'] = 'no' === $get( 'bp-glossary-auto' ) ? 'no' : 'yes';
		$names                        = preg_split( '/\R/', (string) $get( 'bp-glossary' ) );
		$names                        = array_unique( array_filter( array_map( static fn( $n ) => trim( sanitize_text_field( $n ) ), (array) $names ) ) );
		$settings['bp-glossary']      = implode( "\n", array_slice( $names, 0, 500 ) );

		// Keys only from TranslatePress's own settings form (options.php checked its nonce).
		if ( isset( $_POST['option_page'] ) && 'trp_machine_translation_settings' === $_POST['option_page'] // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by options.php.
			&& current_user_can( 'manage_options' ) ) {
			// Translated page addresses (same switch as on Beaver Press -> Translate site -> Set-up).
			if ( isset( $_POST['bp_slugs'] ) && class_exists( 'BP_Slugs' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by options.php.
				$slugs = 'yes' === sanitize_key( wp_unslash( $_POST['bp_slugs'] ) ) ? 'yes' : 'no'; // phpcs:ignore WordPress.Security.NonceVerification.Missing
				if ( get_option( BP_Slugs::OPTION, 'no' ) !== $slugs ) {
					update_option( BP_Slugs::OPTION, $slugs, false );
					BP_Slugs::forget_map();
					BP_Cache::clear();
					if ( 'yes' === $slugs ) {
						BP_Slugs::schedule_backfill(); // Every page gets its addresses in the background.
					}
				}
			}
			$typed   = isset( $_POST['bp_api_key'] ) && is_array( $_POST['bp_api_key'] ) ? wp_unslash( $_POST['bp_api_key'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- keys are stored encrypted as typed.
			$removes = isset( $_POST['bp_api_key_remove'] ) && is_array( $_POST['bp_api_key_remove'] ) ? array_map( 'sanitize_key', array_keys( wp_unslash( $_POST['bp_api_key_remove'] ) ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			foreach ( self::PROVIDERS as $slug ) {
				if ( in_array( $slug, $removes, true ) ) {
					BP_Keys::delete( $slug );
				} elseif ( isset( $typed[ $slug ] ) ) {
					BP_Keys::set( $slug, preg_replace( '/\s+/', '', (string) $typed[ $slug ] ) );
				}
			}
		}

		return $settings;
	}

	/**
	 * The panel, shown by TranslatePress's own script when "beaver_ai" is picked.
	 *
	 * @param array $mt TranslatePress machine translation settings.
	 */
	public static function render_panel( $mt ) {
		$engine   = self::current( $mt );
		$selected = self::ENGINE === ( $mt['translation-engine'] ?? '' );
		$presets  = BP_Providers::presets();
		$test     = self::cached_test( $engine );
		$error    = BP_Providers::last_error();
		?>
		<div class="trp-engine trp-automatic-translation-engine__container bp-panel" id="<?php echo esc_attr( self::ENGINE ); ?>"<?php echo $selected ? '' : ' style="display:none"'; ?>>

			<div class="trp-settings-options-item bp-row">
				<label class="trp-primary-text-bold" for="bp-provider"><?php esc_html_e( 'Translate with', 'beaver-press' ); ?></label>
				<div class="trp-select-wrapper">
					<select id="bp-provider" class="trp-select" name="trp_machine_translation_settings[bp-provider]">
						<?php foreach ( self::PROVIDERS as $slug ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $engine['provider'], $slug ); ?>><?php echo esc_html( $presets[ $slug ]['label'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
			</div>

			<?php foreach ( self::PROVIDERS as $slug ) : ?>
				<?php
				$source = BP_Keys::source( $slug );
				$hint   = BP_Keys::hint( $slug );
				?>
				<div class="trp-settings-options-item bp-row bp-key-row" data-provider="<?php echo esc_attr( $slug ); ?>"<?php echo $slug === $engine['provider'] ? '' : ' style="display:none"'; ?>>
					<label class="trp-primary-text-bold" for="bp-key-<?php echo esc_attr( $slug ); ?>">
						<?php
						/* translators: %s: provider name */
						echo esc_html( sprintf( __( '%s API key', 'beaver-press' ), $presets[ $slug ]['label'] ) );
						?>
					</label>
					<?php if ( 'constant' === $source ) : ?>
						<p class="bp-status bp-status--ok"><?php esc_html_e( 'Set in wp-config.php (overrides anything saved here).', 'beaver-press' ); ?></p>
					<?php else : ?>
						<input type="password" id="bp-key-<?php echo esc_attr( $slug ); ?>" class="trp-text-input bp-key" name="bp_api_key[<?php echo esc_attr( $slug ); ?>]" value="" autocomplete="new-password" spellcheck="false"
							placeholder="<?php echo esc_attr( 'saved' === $source ? __( 'Leave empty to keep the saved key', 'beaver-press' ) : __( 'Paste the API key', 'beaver-press' ) ); ?>" />
						<p class="bp-status <?php echo 'saved' === $source ? 'bp-status--ok' : ''; ?>">
							<?php
							if ( 'saved' === $source ) {
								/* translators: %s: last four characters of the key */
								echo esc_html( sprintf( __( 'Saved, encrypted, ending ...%s.', 'beaver-press' ), $hint ) );
								echo ' <label class="bp-remove"><input type="checkbox" name="bp_api_key_remove[' . esc_attr( $slug ) . ']" value="1" /> ' . esc_html__( 'Remove saved key', 'beaver-press' ) . '</label>';
							} else {
								esc_html_e( 'No key saved.', 'beaver-press' );
							}
							if ( ! empty( $presets[ $slug ]['keys_url'] ) ) {
								echo ' <a href="' . esc_url( $presets[ $slug ]['keys_url'] ) . '" target="_blank" rel="noopener">' . esc_html__( 'Get a key', 'beaver-press' ) . '</a>';
							}
							?>
						</p>
						<?php if ( 'deepl' === $slug ) : ?>
							<span class="trp-description-text"><?php esc_html_e( 'DeepL API Free keys end in ":fx" (500,000 characters a month); Pro keys work the same way. The right DeepL server is picked from the key.', 'beaver-press' ); ?></span>
						<?php endif; ?>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>

			<div class="trp-settings-options-item bp-row bp-only-custom"<?php echo 'custom' === $engine['provider'] ? '' : ' style="display:none"'; ?>>
				<label class="trp-primary-text-bold" for="bp-endpoint"><?php esc_html_e( 'Endpoint URL', 'beaver-press' ); ?></label>
				<input type="url" id="bp-endpoint" class="trp-text-input" name="trp_machine_translation_settings[bp-endpoint]" value="<?php echo esc_attr( $engine['endpoint'] ); ?>" placeholder="https://example.com/v1/chat/completions" />
				<span class="trp-description-text"><?php esc_html_e( 'Any OpenAI-compatible chat completions URL (OpenRouter, Groq, Mistral, a local Ollama or LM Studio server...).', 'beaver-press' ); ?></span>
			</div>

			<div class="trp-settings-options-item bp-row bp-not-deepl"<?php echo 'deepl' === $engine['provider'] ? ' style="display:none"' : ''; ?>>
				<label class="trp-primary-text-bold" for="bp-model-select"><?php esc_html_e( 'Model', 'beaver-press' ); ?></label>
				<div class="bp-inline">
					<div class="trp-select-wrapper bp-model-pick">
						<select id="bp-model-select" class="trp-select" aria-label="<?php esc_attr_e( 'Model', 'beaver-press' ); ?>"></select>
					</div>
					<input type="text" id="bp-model" class="trp-text-input" name="trp_machine_translation_settings[bp-model]" value="<?php echo esc_attr( $engine['model'] ); ?>" spellcheck="false" autocomplete="off" placeholder="<?php esc_attr_e( 'Model name', 'beaver-press' ); ?>" aria-label="<?php esc_attr_e( 'Model name', 'beaver-press' ); ?>" />
					<button type="button" class="button" id="bp-load-models"><?php esc_html_e( 'Load models', 'beaver-press' ); ?></button>
					<span id="bp-models-status" class="trp-description-text" aria-live="polite"></span>
				</div>
				<span class="trp-description-text"><?php esc_html_e( 'Pick a model, or press "Load models" to list the ones your key can use; "Other" lets you type any name. Cheaper models cost less per text; stronger ones translate more naturally.', 'beaver-press' ); ?></span>
			</div>

			<div class="trp-settings-options-item bp-row bp-only-claude"<?php echo 'claude' === $engine['provider'] ? '' : ' style="display:none"'; ?>>
				<label class="trp-primary-text-bold" for="bp-effort"><?php esc_html_e( 'Effort (Claude)', 'beaver-press' ); ?></label>
				<div class="trp-select-wrapper">
					<select id="bp-effort" class="trp-select" name="trp_machine_translation_settings[bp-effort]">
						<?php foreach ( array( 'low' => __( 'Low (fast, cheapest)', 'beaver-press' ), 'medium' => __( 'Medium', 'beaver-press' ), 'high' => __( 'High', 'beaver-press' ) ) as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $engine['effort'], $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<span class="trp-description-text"><?php esc_html_e( 'How much the model thinks before answering. Low is enough for website text.', 'beaver-press' ); ?></span>
			</div>

			<div class="trp-settings-options-item bp-row">
				<label class="trp-primary-text-bold" for="bp-chunk-size"><?php esc_html_e( 'Strings per request', 'beaver-press' ); ?></label>
				<input type="number" id="bp-chunk-size" class="trp-text-input bp-narrow" name="trp_machine_translation_settings[bp-chunk-size]" value="<?php echo esc_attr( (string) $engine['chunk'] ); ?>" min="5" max="50" step="1" />
				<span class="trp-description-text"><?php esc_html_e( 'Smaller batches answer faster; 20 suits most models.', 'beaver-press' ); ?></span>
			</div>

			<div class="trp-settings-options-item bp-row">
				<label class="trp-primary-text-bold"><?php esc_html_e( 'Names to keep', 'beaver-press' ); ?></label>
				<span class="trp-description-text">
					<?php
					/* translators: %d: number of names kept */
					echo esc_html( sprintf( __( '%d names from the site are kept as written in every language.', 'beaver-press' ), count( BP_Glossary::auto_terms() ) + count( BP_Glossary::manual_terms( $mt ) ) ) );
					?>
					<a href="<?php echo esc_url( admin_url( 'options-general.php?page=' . BP_Admin::SLUG . '&tab=instructions#bp-names' ) ); ?>"><?php esc_html_e( 'Choose them (Beaver Press -> Instructions)', 'beaver-press' ); ?></a>
				</span>
			</div>

			<div class="trp-settings-options-item bp-row">
				<label class="trp-primary-text-bold" for="bp-visitor-guard"><?php esc_html_e( 'Who can trigger new translations', 'beaver-press' ); ?></label>
				<label class="bp-inline">
					<input type="hidden" name="trp_machine_translation_settings[bp-visitor-guard]" value="no" />
					<input type="checkbox" id="bp-visitor-guard" name="trp_machine_translation_settings[bp-visitor-guard]" value="yes" <?php checked( BP_Guard::enabled( $mt ) ); ?> />
					<span><?php esc_html_e( 'Only logged-in editors and the "Translate site" run (recommended)', 'beaver-press' ); ?></span>
				</label>
				<span class="trp-description-text"><?php esc_html_e( 'Visitors and search bots then see stored translations, or the original text where none is stored yet, and never cause a paid request. Unticked, any page view may send new text to the provider while the visitor waits.', 'beaver-press' ); ?></span>
			</div>

			<div class="trp-settings-options-item bp-row">
				<label class="trp-primary-text-bold" for="bp-slugs"><?php esc_html_e( 'Automatically translate slugs', 'beaver-press' ); ?></label>
				<label class="bp-inline">
					<input type="hidden" name="bp_slugs" value="no" />
					<input type="checkbox" id="bp-slugs" name="bp_slugs" value="yes" <?php checked( class_exists( 'BP_Slugs' ) && BP_Slugs::enabled() ); ?> />
					<span><?php esc_html_e( 'Posts, pages, custom post types (tours, destinations, stays) and categories get an address in each language (/fr/a-propos/)', 'beaver-press' ); ?></span>
				</label>
				<span class="trp-description-text"><?php esc_html_e( 'Made from the translated title, automatically: when the switch is turned on every existing page gets its addresses in the background, and new or edited pages as they are translated. Editable on each edit screen ("Translated addresses"). The old address sends visitors and search engines on with a 301. Addresses in the original language never change; languages written in other scripts (Chinese, Arabic...) keep the original address.', 'beaver-press' ); ?></span>
			</div>

			<div class="trp-settings-options-item bp-row">
				<div class="bp-inline">
					<button type="button" class="button button-secondary" id="bp-test"><?php esc_html_e( 'Test connection', 'beaver-press' ); ?></button>
					<span id="bp-test-result" class="bp-status <?php echo $test ? ( $test['ok'] ? 'bp-status--ok' : 'bp-status--error' ) : ''; ?>" aria-live="polite">
						<?php
						if ( $test ) {
							echo esc_html( $test['message'] );
						}
						?>
					</span>
				</div>
				<span class="trp-description-text"><?php esc_html_e( 'Uses the values above, even before saving: translates one short phrase. Save changes afterwards.', 'beaver-press' ); ?></span>
				<?php if ( $error && ! ( $test && $test['ok'] && $test['time'] > $error['time'] ) ) : ?>
					<p class="bp-status bp-status--error">
						<?php
						/* translators: 1: provider, 2: model, 3: error message */
						echo esc_html( sprintf( __( 'Last error (%1$s, %2$s): %3$s', 'beaver-press' ), $error['provider'], $error['model'], $error['message'] ) );
						?>
					</p>
				<?php endif; ?>
			</div>
			<?php BP_Admin::render_credit(); ?>
		</div>
		<?php
	}

	/**
	 * Script and styles on TranslatePress's Automatic Translation page only.
	 *
	 * @param string $hook Admin page hook.
	 */
	public static function enqueue( $hook ) {
		if ( 'admin_page_trp_machine_translation' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'beaver-press-admin', BP_URL . 'assets/css/bp-admin.css', array(), BP_VERSION );
		wp_enqueue_script( 'beaver-press-admin', BP_URL . 'assets/js/bp-admin.js', array( 'jquery' ), BP_VERSION, true );

		$presets = array();
		$cached  = array();
		foreach ( self::PROVIDERS as $slug ) {
			$preset           = BP_Providers::preset( $slug );
			$presets[ $slug ] = array(
				'model'  => (string) $preset['model'],
				'models' => array_values( (array) $preset['models'] ),
			);
			$list             = get_transient( 'bp_models_' . $slug );
			$cached[ $slug ]  = is_array( $list ) ? $list : array();
		}
		wp_localize_script(
			'beaver-press-admin',
			'BP_ADMIN',
			array(
				'ajax'    => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE ),
				'engine'  => self::ENGINE,
				'presets' => $presets,
				'cached'  => $cached,
				'i18n'    => array(
					'loading' => __( 'Loading...', 'beaver-press' ),
					'testing' => __( 'Testing...', 'beaver-press' ),
					'models'  => __( 'models found: pick one from the list', 'beaver-press' ),
					'other'   => __( 'Other (type a name)...', 'beaver-press' ),
					'failed'  => __( 'Request failed. Check your connection and try again.', 'beaver-press' ),
				),
			)
		);
	}

	/**
	 * AJAX: test the values in the form (typed key, or the saved one).
	 */
	public static function ajax_test() {
		$request = self::ajax_request();
		$result  = self::run_test( $request['engine'], $request['key'] );
		self::remember_test( $request['engine'], $request['key'], $result );
		if ( $result['ok'] ) {
			wp_send_json_success( $result );
		}
		wp_send_json_error( $result );
	}

	/**
	 * AJAX: list the models this key can use.
	 */
	public static function ajax_models() {
		$request = self::ajax_request();
		$models  = BP_Providers::list_models( $request['engine']['provider'], $request['key'], $request['engine']['endpoint'] );
		if ( is_wp_error( $models ) ) {
			wp_send_json_error( array( 'message' => $models->get_error_message() ) );
		}
		set_transient( 'bp_models_' . $request['engine']['provider'], $models, 12 * HOUR_IN_SECONDS );
		wp_send_json_success( array( 'models' => $models ) );
	}

	/**
	 * Check the nonce and capability, then read the form values sent by the panel.
	 *
	 * @return array{engine: array, key: string}
	 */
	private static function ajax_request() {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'beaver-press' ) ), 403 );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked above.
		$mt     = array(
			'bp-provider' => sanitize_key( wp_unslash( $_POST['provider'] ?? '' ) ),
			'bp-model'    => preg_replace( '/\s+/', '', sanitize_text_field( wp_unslash( $_POST['model'] ?? '' ) ) ),
			'bp-endpoint' => esc_url_raw( wp_unslash( $_POST['endpoint'] ?? '' ), array( 'http', 'https' ) ),
			'bp-effort'   => sanitize_key( wp_unslash( $_POST['effort'] ?? '' ) ),
		);
		$typed  = preg_replace( '/\s+/', '', (string) wp_unslash( $_POST['key'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- API key, used as typed, never output.
		// phpcs:enable
		$engine = self::current( $mt );
		return array(
			'engine' => $engine,
			'key'    => '' !== $typed ? $typed : BP_Keys::get( $engine['provider'] ),
		);
	}

	/**
	 * Translate one phrase to French with the given settings.
	 *
	 * @param array  $engine Engine settings (see current()).
	 * @param string $key    API key.
	 * @return array{ok: bool, message: string, time: int}
	 */
	public static function run_test( array $engine, $key ) {
		if ( 'deepl' === $engine['api'] ) {
			return self::run_deepl_test( $key );
		}
		$schema = array(
			'type'                 => 'object',
			'properties'           => array( 's1' => array( 'type' => 'string' ) ),
			'required'             => array( 's1' ),
			'additionalProperties' => false,
		);
		$reply  = BP_Providers::request_json(
			$engine,
			$key,
			'You translate website text. Reply with a JSON object only, using the same keys as the input.',
			"Translate the value from English to French.\nInput JSON: {\"s1\": \"Good morning\"}",
			$schema
		);

		if ( is_wp_error( $reply ) ) {
			return array(
				'ok'      => false,
				'message' => $reply->get_error_message(),
				'time'    => time(),
			);
		}
		$text = isset( $reply['s1'] ) && is_string( $reply['s1'] ) ? trim( $reply['s1'] ) : '';
		if ( '' === $text ) {
			return array(
				'ok'      => false,
				'message' => __( 'Connected, but the reply did not contain the translation.', 'beaver-press' ),
				'time'    => time(),
			);
		}
		return array(
			'ok'      => true,
			/* translators: 1: model, 2: translated phrase */
			'message' => sprintf( __( 'Connected to %1$s: "Good morning" -> "%2$s".', 'beaver-press' ), $engine['model'], wp_strip_all_tags( $text ) ),
			'time'    => time(),
		);
	}

	/**
	 * DeepL test: one phrase, plus this month's character usage.
	 *
	 * @param string $key DeepL key.
	 * @return array{ok: bool, message: string, time: int}
	 */
	private static function run_deepl_test( $key ) {
		$reply = BP_Providers::deepl_translate( array( 'Good morning' ), 'en_US', 'fr_FR', $key, 30 );
		if ( is_wp_error( $reply ) ) {
			return array(
				'ok'      => false,
				'message' => $reply->get_error_message(),
				'time'    => time(),
			);
		}
		$message = sprintf(
			/* translators: 1: DeepL plan, 2: translated phrase */
			__( 'Connected to DeepL %1$s: "Good morning" -> "%2$s".', 'beaver-press' ),
			'https://api-free.deepl.com' === BP_Providers::deepl_base( $key ) ? 'API Free' : 'API Pro',
			wp_strip_all_tags( (string) $reply[0] )
		);
		$usage = BP_Providers::deepl_usage( $key );
		if ( is_array( $usage ) && $usage['limit'] > 0 ) {
			$message .= ' ' . sprintf(
				/* translators: 1: characters used, 2: character limit */
				__( 'Used this month: %1$s of %2$s characters.', 'beaver-press' ),
				number_format_i18n( $usage['used'] ),
				number_format_i18n( $usage['limit'] )
			);
		}
		return array(
			'ok'      => true,
			'message' => $message,
			'time'    => time(),
		);
	}

	/**
	 * Cached test result for these settings and the saved key, or null.
	 *
	 * @param array $engine Engine settings.
	 * @return array|null
	 */
	public static function cached_test( array $engine ) {
		$stored = get_option( self::TEST_OPTION );
		$key    = BP_Keys::get( $engine['provider'] );
		if ( ! is_array( $stored ) || '' === $key || ( $stored['sig'] ?? '' ) !== self::signature( $engine, $key ) ) {
			return null;
		}
		return $stored['result'];
	}

	/**
	 * Store a test result under a signature of provider, model, endpoint and key.
	 *
	 * @param array  $engine Engine settings.
	 * @param string $key    Key used.
	 * @param array  $result Result.
	 */
	private static function remember_test( array $engine, $key, array $result ) {
		if ( '' === $key ) {
			return;
		}
		update_option(
			self::TEST_OPTION,
			array(
				'sig'    => self::signature( $engine, $key ),
				'result' => $result,
			),
			false
		);
	}

	/**
	 * Signature of the settings a test ran with (the key only as a hash).
	 *
	 * @param array  $engine Engine settings.
	 * @param string $key    Key.
	 * @return string
	 */
	private static function signature( array $engine, $key ) {
		return hash( 'sha256', implode( '|', array( $engine['provider'], $engine['model'], $engine['url'], $engine['effort'], hash( 'sha256', (string) $key ) ) ) );
	}
}
