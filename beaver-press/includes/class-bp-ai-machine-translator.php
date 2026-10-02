<?php
/**
 * TranslatePress engine "Beaver AI": Claude, ChatGPT, DeepSeek, Gemini or a custom
 * OpenAI-compatible model, chosen in the engine's panel.
 *
 * Loaded only when TranslatePress resolves its engine classes, after its own base class
 * TRP_Machine_Translator exists. TranslatePress does the rest around each call:
 * de-duplication, skipping short strings, placeholders for % $ # (1TP1T...), saving each
 * chunk, locks against paying twice, the daily character limit and the log.
 *
 * @package BeaverPress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Beaver AI engine.
 */
class BP_AI_Machine_Translator extends TRP_Machine_Translator {

	/**
	 * Engine settings (provider, model, endpoint, chunk size, effort).
	 *
	 * @return array
	 */
	protected function engine() {
		return BP_Engine_Settings::current( $this->settings['trp_machine_translation_settings'] ?? array() );
	}

	/**
	 * Translate strings, keeping TranslatePress's keys.
	 *
	 * Sent in batches of the chosen size. Each reply value must keep the original's tags,
	 * links and placeholders (BP_Batch::check); one that does not is left out and counted,
	 * and is kept as it is after repeated failures so it stops costing money.
	 *
	 * @param array       $new_strings          Strings keyed by TranslatePress.
	 * @param string      $target_language_code Target language (WordPress code).
	 * @param string|null $source_language_code Source language.
	 * @return array Translations under the same keys; missing keys are tried again later.
	 */
	public function translate_array( $new_strings, $target_language_code, $source_language_code = null ) {
		if ( null === $source_language_code ) {
			$source_language_code = $this->settings['default-language'];
		}
		if ( empty( $new_strings ) || ! $this->verify_request_parameters( $target_language_code, $source_language_code ) ) {
			return array();
		}
		// Second line of the visitor guard, whatever path called the engine.
		if ( ! BP_Guard::may_translate( $this->settings['trp_machine_translation_settings'] ?? null ) ) {
			return array();
		}

		$engine = $this->engine();
		$key    = $this->get_api_key();
		$out    = array();
		$todo   = array();
		foreach ( $new_strings as $id => $text ) {
			if ( BP_Batch::given_up( (string) $text, $target_language_code ) ) {
				$out[ $id ] = (string) $text;
			} else {
				$todo[ $id ] = (string) $text;
			}
		}

		// A changed original whose manual translation is kept: no machine translation for it.
		if ( $todo && class_exists( 'BP_Provenance' ) && ! BP_Provenance::$explicit && ! BP_Provenance::$paused ) {
			$carried = BP_Provenance::carry( $target_language_code, $todo );
			$out     = $carried + $out;
			$todo    = array_diff_key( $todo, $carried );
		}

		foreach ( array_chunk( $todo, max( 1, $this->get_chunk_size() ), true ) as $chunk ) {
			$reply = 'deepl' === $engine['api']
				? $this->send_deepl( $chunk, $source_language_code, $target_language_code, $key, $engine )
				: $this->send_model( $chunk, $source_language_code, $target_language_code, $key, $engine );

			// Anything that reached the provider and came back is billed: record it.
			$usage = BP_Providers::last_usage();
			if ( null !== $usage ) {
				BP_Usage::add( $engine['provider'], 'deepl' === $engine['api'] ? '' : $engine['model'], array_sum( array_map( 'mb_strlen', $chunk ) ), $usage[0], $usage[1] );
			}

			$this->machine_translator_logger->log(
				array(
					'strings'     => serialize( $chunk ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- TranslatePress's log format.
					'response'    => serialize( is_wp_error( $reply ) ? $reply->get_error_code() . ': ' . $reply->get_error_message() : $reply ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
					'lang_source' => $source_language_code,
					'lang_target' => $target_language_code,
				)
			);

			if ( is_wp_error( $reply ) ) {
				// A declined batch is about its content: count it against each string.
				if ( 'bp_refusal' === $reply->get_error_code() ) {
					foreach ( $chunk as $text ) {
						BP_Batch::record_failure( $text, $target_language_code, 'declined by the model' );
					}
				}
				continue;
			}

			$this->machine_translator_logger->count_towards_quota( $chunk );
			$done = array();
			foreach ( $chunk as $id => $text ) {
				$check = BP_Batch::check( $text, $reply[ $id ] ?? null );
				if ( true === $check ) {
					$out[ $id ] = BP_Batch::clean( $text, (string) $reply[ $id ] );
					$done[]     = (string) $text;
				} else {
					BP_Batch::record_failure( $text, $target_language_code, $check );
				}
			}
			if ( $done && class_exists( 'BP_Provenance' ) && ! BP_Provenance::$paused && ! BP_Provenance::$explicit ) {
				BP_Provenance::machine( $target_language_code, $done, $engine );
			}

			if ( $this->machine_translator_logger->quota_exceeded() ) {
				break;
			}
		}

		return $out;
	}

	/**
	 * One batch to a language model. Keys are sent as s1, s2... and mapped back.
	 *
	 * @param array  $chunk  Strings keyed by TranslatePress.
	 * @param string $source Source language code.
	 * @param string $target Target language code.
	 * @param string $key    API key.
	 * @param array  $engine Engine settings.
	 * @return array|WP_Error Translations keyed like $chunk.
	 */
	protected function send_model( array $chunk, $source, $target, $key, array $engine ) {
		$ids = array_keys( $chunk );
		$map = array();
		foreach ( $ids as $i => $id ) {
			$map[ 's' . ( $i + 1 ) ] = $chunk[ $id ];
		}
		$reply = BP_Providers::request_json(
			$engine,
			$key,
			BP_Batch::system_prompt( $this->language_name( $source ), $this->language_name( $target ), BP_Batch::glossary_for( array_values( $chunk ) ), $target ),
			BP_Batch::user_message( $map ),
			BP_Batch::schema( array_keys( $map ) )
		);
		if ( is_wp_error( $reply ) ) {
			return $reply;
		}
		$out = array();
		foreach ( $ids as $i => $id ) {
			if ( isset( $reply[ 's' . ( $i + 1 ) ] ) ) {
				$out[ $id ] = $reply[ 's' . ( $i + 1 ) ];
			}
		}
		return $out;
	}

	/**
	 * One batch to DeepL (same order back).
	 *
	 * @param array  $chunk  Strings keyed by TranslatePress.
	 * @param string $source Source language code.
	 * @param string $target Target language code.
	 * @param string $key    DeepL key.
	 * @param array  $engine Engine settings.
	 * @return array|WP_Error
	 */
	protected function send_deepl( array $chunk, $source, $target, $key, array $engine ) {
		// DeepL has no prompt: wrap glossary names in a tag it is told to leave alone.
		$texts     = array();
		$protected = false;
		foreach ( $chunk as $id => $text ) {
			$terms        = BP_Batch::glossary_for( array( $text ) );
			$texts[ $id ] = $terms ? BP_Glossary::protect( $text, $terms ) : $text;
			$protected    = $protected || $texts[ $id ] !== $text;
		}
		$reply = BP_Providers::deepl_translate( array_values( $texts ), $source, $target, $key, (int) $engine['timeout'], $protected ? array( BP_Glossary::KEEP_TAG ) : array() );
		if ( is_wp_error( $reply ) ) {
			return $reply;
		}
		return array_combine( array_keys( $chunk ), array_map( array( 'BP_Glossary', 'unprotect' ), $reply ) );
	}

	/**
	 * English name of a language ("French"), for the prompt.
	 *
	 * @param string $code WordPress language code.
	 * @return string
	 */
	protected function language_name( $code ) {
		$names = (array) $this->trp_languages->get_language_names( array( $code ), 'english_name' );
		return (string) ( $names[ $code ] ?? $code );
	}

	/**
	 * Strings per request.
	 *
	 * @return int
	 */
	public function get_chunk_size() {
		return (int) $this->engine()['chunk'];
	}

	/**
	 * Key for the selected provider.
	 *
	 * @return string
	 */
	public function get_api_key() {
		return BP_Keys::get( $this->engine()['provider'] );
	}

	/**
	 * One small request, shaped like a WordPress HTTP response for TranslatePress's callers.
	 * Never includes the key.
	 *
	 * @return array
	 */
	public function test_request() {
		$result = BP_Engine_Settings::run_test( $this->engine(), $this->get_api_key() );
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( $result ),
			'response' => array(
				'code'    => $result['ok'] ? 200 : 400,
				'message' => $result['message'],
			),
		);
	}

	/**
	 * Result of the last "Test connection" for the current settings. Cached: loading the
	 * settings page never calls the provider.
	 *
	 * @return array{message: string, error: bool}
	 */
	public function check_api_key_validity() {
		$engine = $this->engine();
		if ( '' === $this->get_api_key() ) {
			return array(
				'message' => __( 'No API key saved for this provider yet.', 'beaver-press' ),
				'error'   => true,
			);
		}
		$test = BP_Engine_Settings::cached_test( $engine );
		if ( null === $test ) {
			return array(
				'message' => __( 'Not tested yet: press "Test connection".', 'beaver-press' ),
				'error'   => true,
			);
		}
		return array(
			'message' => $test['message'],
			'error'   => ! $test['ok'],
		);
	}

	/**
	 * Language models translate between any of TranslatePress's languages, so every
	 * language TranslatePress knows is "supported" and no request is made to find out.
	 *
	 * @return array
	 */
	public function get_supported_languages() {
		$codes = array_keys( (array) $this->trp_languages->get_languages( 'english_name' ) );
		return array_values( array_unique( array_map( 'strtolower', $this->trp_languages->get_iso_codes( $codes ) ) ) );
	}

	/**
	 * Codes in the same form as get_supported_languages().
	 *
	 * @param array $languages WordPress language codes.
	 * @return array
	 */
	public function get_engine_specific_language_codes( $languages ) {
		return array_values( array_map( 'strtolower', $this->trp_languages->get_iso_codes( (array) $languages ) ) );
	}
}
