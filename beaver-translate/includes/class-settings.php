<?php
/**
 * Settings storage, defaults and sanitisation.
 *
 * No API key ships with this plugin. Each site supplies its own, either in
 * the settings screen (stored in the database, server side only) or by
 * defining BEAVER_TRANSLATE_API_KEY in wp-config.php, which always wins and
 * is never written to the database or rendered back into any form.
 *
 * @package BeaverTranslate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Beaver_Translate_Settings
 *
 * @since 1.0.0
 */
class Beaver_Translate_Settings {

	const OPTION = 'beaver_translate_settings';

	/**
	 * Runtime cache.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Shipped defaults.
	 *
	 * @since 1.0.0
	 *
	 * @return array Defaults.
	 */
	public static function defaults() {
		return array(
			'default_language'          => 'en',
			'languages'                 => array(
				array(
					'code'   => 'en',
					'name'   => 'English',
					'native' => 'English',
				),
			),
			'provider'                  => 'claude',
			'api_key'                   => '',
			'model'                     => '',
			'custom_endpoint'           => '',
			'max_tokens'                => 4096,
			'timeout'                   => 90,
			'translatable_post_types'   => array( 'post', 'page' ),
			'auto_translate_on_publish' => 0,
			'auto_switcher_enabled'     => 0,
			'auto_switcher_position'    => 'bottom-right',
			'glossary'                  => '',
			'glossary_post_types'       => array(),
			'translate_custom_fields'   => 0,
		);
	}

	/**
	 * The providers this plugin can speak to.
	 *
	 * Wire formats mirror beaver-ai-chat exactly: 'anthropic' is Claude's
	 * Messages API, 'openai' is the chat/completions shape spoken by OpenAI,
	 * DeepSeek, Groq, OpenRouter and most self-hosted runtimes, 'gemini' is
	 * Google's generateContent.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string,array> Provider key => config.
	 */
	public static function providers() {
		return array(
			'claude'     => array(
				'label' => 'Claude',
				'api'   => 'anthropic',
				'url'   => 'https://api.anthropic.com/v1/messages',
				'model' => 'claude-opus-5',
			),
			'openai'     => array(
				'label' => 'ChatGPT',
				'api'   => 'openai',
				'url'   => 'https://api.openai.com/v1/chat/completions',
				'model' => 'gpt-4o-mini',
			),
			'deepseek'   => array(
				'label' => 'DeepSeek',
				'api'   => 'openai',
				'url'   => 'https://api.deepseek.com/chat/completions',
				'model' => 'deepseek-chat',
			),
			'gemini'     => array(
				'label' => 'Gemini',
				'api'   => 'gemini',
				'url'   => 'https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent',
				'model' => 'gemini-2.0-flash',
			),
			'groq'       => array(
				'label' => 'Groq',
				'api'   => 'openai',
				'url'   => 'https://api.groq.com/openai/v1/chat/completions',
				'model' => 'llama-3.3-70b-versatile',
			),
			'openrouter' => array(
				'label' => 'OpenRouter',
				'api'   => 'openai',
				'url'   => 'https://openrouter.ai/api/v1/chat/completions',
				'model' => 'openai/gpt-4o-mini',
			),
			'custom'     => array(
				'label' => __( 'Custom (OpenAI-compatible)', 'beaver-translate' ),
				'api'   => 'openai',
				'url'   => '',
				'model' => '',
			),
		);
	}

	/**
	 * Returns all settings.
	 *
	 * @since 1.0.0
	 *
	 * @return array Settings.
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$stored = get_option( self::OPTION, array() );

			self::$cache = wp_parse_args( is_array( $stored ) ? $stored : array(), self::defaults() );
		}

		return self::$cache;
	}

	/**
	 * Reads one setting.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key     Key.
	 * @param mixed  $default Fallback.
	 * @return mixed Value.
	 */
	public static function get( $key, $default = null ) {
		$all = self::all();

		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Drops the runtime cache.
	 *
	 * @since 1.0.0
	 */
	public static function flush() {
		self::$cache = null;
	}

	/**
	 * Returns the API key actually in effect.
	 *
	 * A BEAVER_TRANSLATE_API_KEY constant in wp-config.php always wins, which
	 * lets the key stay out of the database entirely and out of any export.
	 *
	 * @since 1.0.0
	 *
	 * @return string API key, or an empty string when none is configured.
	 */
	public static function api_key() {
		if ( defined( 'BEAVER_TRANSLATE_API_KEY' ) && '' !== trim( (string) BEAVER_TRANSLATE_API_KEY ) ) {
			return trim( (string) BEAVER_TRANSLATE_API_KEY );
		}

		return trim( (string) self::get( 'api_key' ) );
	}

	/**
	 * Whether the key comes from wp-config.php rather than the database.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public static function key_is_constant() {
		return defined( 'BEAVER_TRANSLATE_API_KEY' ) && '' !== trim( (string) BEAVER_TRANSLATE_API_KEY );
	}

	/**
	 * Config for the selected provider, merged with any custom endpoint/model.
	 *
	 * @since 1.0.0
	 *
	 * @param array|null $s Settings (read fresh when omitted).
	 * @return array Provider config, including 'id'.
	 */
	public static function provider_config( $s = null ) {
		$s         = null === $s ? self::all() : $s;
		$providers = self::providers();
		$key       = isset( $providers[ $s['provider'] ] ) ? $s['provider'] : 'claude';
		$cfg       = $providers[ $key ];
		$cfg['id'] = $key;

		if ( 'custom' === $key && '' !== trim( (string) $s['custom_endpoint'] ) ) {
			$cfg['url'] = trim( (string) $s['custom_endpoint'] );
		}

		$cfg['model'] = '' !== trim( (string) $s['model'] ) ? trim( (string) $s['model'] ) : $cfg['model'];

		return $cfg;
	}

	/**
	 * Validates a settings payload from $_POST.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $input Raw input.
	 * @return array Sanitized settings.
	 */
	public static function sanitize( $input ) {
		$defaults = self::defaults();
		$input    = is_array( $input ) ? $input : array();
		$clean    = array();

		$languages = Beaver_Translate_Languages::sanitize_list( $input['languages'] ?? array() );

		if ( empty( $languages ) ) {
			$languages = $defaults['languages'];
		}

		$clean['languages'] = $languages;

		$codes                     = wp_list_pluck( $languages, 'code' );
		$default_language          = isset( $input['default_language'] ) ? sanitize_key( $input['default_language'] ) : '';
		$clean['default_language'] = in_array( $default_language, $codes, true ) ? $default_language : $codes[0];

		$providers          = array_keys( self::providers() );
		$provider            = isset( $input['provider'] ) ? sanitize_key( $input['provider'] ) : '';
		$clean['provider']  = in_array( $provider, $providers, true ) ? $provider : $defaults['provider'];

		/*
		 * The key is never re-read from $input in two cases: when a
		 * constant already overrides it — saving settings must never
		 * overwrite a key that was deliberately kept out of the database —
		 * and when the settings screen's own api_key_unchanged flag is set,
		 * meaning the masked field was left alone rather than "Change"
		 * clicked. Without that second check, submitting the form for any
		 * other reason — adding a language, changing the timeout — would
		 * post the masked display value back as the real key and silently
		 * overwrite a working one with garbage.
		 */
		if ( self::key_is_constant() ) {
			$clean['api_key'] = '';
		} elseif ( ! empty( $input['api_key_unchanged'] ) ) {
			$clean['api_key'] = self::get( 'api_key', '' );
		} else {
			$clean['api_key'] = isset( $input['api_key'] ) ? trim( sanitize_text_field( wp_unslash( $input['api_key'] ) ) ) : '';
		}

		$clean['model']           = isset( $input['model'] ) ? sanitize_text_field( wp_unslash( $input['model'] ) ) : '';
		$clean['custom_endpoint'] = isset( $input['custom_endpoint'] ) ? esc_url_raw( wp_unslash( $input['custom_endpoint'] ) ) : '';
		$clean['max_tokens']      = self::clamp_int( $input['max_tokens'] ?? $defaults['max_tokens'], 256, 32000 );
		$clean['timeout']         = self::clamp_int( $input['timeout'] ?? $defaults['timeout'], 10, 300 );

		$allowed_types                     = Beaver_Translate_Languages::translatable_post_types_available();
		$requested_types                   = isset( $input['translatable_post_types'] ) ? (array) $input['translatable_post_types'] : array();
		$clean['translatable_post_types']  = array_values( array_intersect( array_map( 'sanitize_key', $requested_types ), $allowed_types ) );
		$clean['auto_translate_on_publish'] = empty( $input['auto_translate_on_publish'] ) ? 0 : 1;

		$clean['auto_switcher_enabled'] = empty( $input['auto_switcher_enabled'] ) ? 0 : 1;

		$positions                        = array( 'bottom-left', 'bottom-right', 'top-left', 'top-right' );
		$position                         = isset( $input['auto_switcher_position'] ) ? sanitize_key( $input['auto_switcher_position'] ) : '';
		$clean['auto_switcher_position']  = in_array( $position, $positions, true ) ? $position : $defaults['auto_switcher_position'];

		/*
		 * The glossary is two things, not one list typed out by hand: every
		 * title already sitting in whichever content types are named a
		 * proper name on a safari site — destinations, accommodations,
		 * packages, however many a given site actually has — protected as a
		 * category with one tick rather than one checkbox per property, plus
		 * a small free-text room for anything that doesn't come from a post
		 * title at all. Beaver_Translate_Translator::glossary_instruction()
		 * is what actually combines the two at translation time.
		 */
		$protectable                          = Beaver_Translate_Languages::translatable_post_types_available();
		$requested_glossary_types             = isset( $input['glossary_post_types'] ) ? (array) $input['glossary_post_types'] : array();
		$clean['glossary_post_types']         = array_values( array_intersect( array_map( 'sanitize_key', $requested_glossary_types ), $protectable ) );

		$clean['glossary'] = isset( $input['glossary'] ) ? sanitize_textarea_field( wp_unslash( $input['glossary'] ) ) : '';

		/*
		 * Off by default: translating a custom field's own text (an
		 * itinerary day's description, an FAQ answer, an "included" bullet)
		 * is a meaningfully bigger AI call than title/excerpt/content alone
		 * on a post type with many ACF fields or repeaters, and not every
		 * site has custom fields worth the extra call in the first place.
		 */
		$clean['translate_custom_fields'] = empty( $input['translate_custom_fields'] ) ? 0 : 1;

		return $clean;
	}

	/**
	 * Returns the free-text glossary as a clean list of terms.
	 *
	 * One term per line in the settings textarea; blank lines and
	 * surrounding whitespace are dropped, order is otherwise preserved.
	 * This is only the "anything else" room — titles from a protected post
	 * type are resolved separately, dynamically, by
	 * Beaver_Translate_Translator::glossary_instruction(), not stored here.
	 *
	 * @since 1.3.0
	 *
	 * @return string[] Terms.
	 */
	public static function glossary_terms() {
		$raw = (string) self::get( 'glossary', '' );

		if ( '' === trim( $raw ) ) {
			return array();
		}

		$lines = array_map( 'trim', explode( "\n", $raw ) );

		return array_values( array_filter( $lines, static function ( $line ) {
			return '' !== $line;
		} ) );
	}

	/**
	 * Clamps a value into an integer range.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $value Raw value.
	 * @param int   $min   Lower bound.
	 * @param int   $max   Upper bound.
	 * @return int Clamped integer.
	 */
	private static function clamp_int( $value, $min, $max ) {
		return (int) max( $min, min( $max, (int) $value ) );
	}
}
