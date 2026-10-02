<?php
/**
 * Provider presets and one JSON request/response path per API format.
 *
 * Three wire formats cover the chat models: Anthropic (Claude), OpenAI-compatible
 * (ChatGPT, DeepSeek, any custom endpoint such as OpenRouter, Groq, Mistral or a local
 * Ollama / LM Studio server) and Gemini. DeepL is a translation API with its own engine
 * class; its preset lives here so keys and labels come from one list.
 *
 * All calls go through the WordPress HTTP API (no Composer on these sites, and one
 * transport keeps proxy, SSL and timeout handling the same for every provider).
 *
 * @package BeaverPress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Presets, request builders and response parsers.
 */
final class BP_Providers {

	/** Option with the last failure, shown in the settings panel. */
	const ERROR_OPTION = 'beaver_press_last_error';

	/** HTTP codes worth one retry. */
	const RETRY_CODES = array( 429, 500, 502, 503, 504, 529 );

	/**
	 * Tokens reported by the last reply: array( in, out ), or null when no reply came back.
	 *
	 * @var array|null
	 */
	private static $last_usage = null;

	/**
	 * Tokens of the last reply (see $last_usage).
	 *
	 * @return array{0: int, 1: int}|null
	 */
	public static function last_usage() {
		return self::$last_usage;
	}

	/**
	 * Read token counts from a reply.
	 *
	 * @param string $api  anthropic|openai|gemini.
	 * @param array  $data Decoded reply.
	 * @return array{0: int, 1: int}
	 */
	private static function usage_of( $api, array $data ) {
		switch ( $api ) {
			case 'anthropic':
				$u = (array) ( $data['usage'] ?? array() );
				return array( (int) ( $u['input_tokens'] ?? 0 ) + (int) ( $u['cache_creation_input_tokens'] ?? 0 ) + (int) ( $u['cache_read_input_tokens'] ?? 0 ), (int) ( $u['output_tokens'] ?? 0 ) );
			case 'gemini':
				$u = (array) ( $data['usageMetadata'] ?? array() );
				return array( (int) ( $u['promptTokenCount'] ?? 0 ), (int) ( $u['candidatesTokenCount'] ?? 0 ) + (int) ( $u['thoughtsTokenCount'] ?? 0 ) );
			default:
				$u = (array) ( $data['usage'] ?? array() );
				return array( (int) ( $u['prompt_tokens'] ?? 0 ), (int) ( $u['completion_tokens'] ?? 0 ) );
		}
	}

	/**
	 * Provider presets. `model` is the suggested default; the model field stays free text.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function presets() {
		return array(
			'claude'   => array(
				'label'      => 'Claude (Anthropic)',
				'api'        => 'anthropic',
				'url'        => 'https://api.anthropic.com/v1/messages',
				'models_url' => 'https://api.anthropic.com/v1/models',
				'model'      => 'claude-opus-5-5',
				'models'     => array( 'claude-opus-5-5', 'claude-sonnet-5-5', 'claude-haiku-4-5' ),
				'keys_url'   => 'https://console.anthropic.com/settings/keys',
			),
			'openai'   => array(
				'label'      => 'ChatGPT (OpenAI)',
				'api'        => 'openai',
				'url'        => 'https://api.openai.com/v1/chat/completions',
				'models_url' => 'https://api.openai.com/v1/models',
				'model'      => '',
				'models'     => array(),
				'keys_url'   => 'https://platform.openai.com/api-keys',
			),
			'deepseek' => array(
				'label'      => 'DeepSeek',
				'api'        => 'openai',
				'url'        => 'https://api.deepseek.com/chat/completions',
				'models_url' => 'https://api.deepseek.com/models',
				'model'      => 'deepseek-flash',
				'models'     => array( 'deepseek-flash', 'deepseek-v4-pro' ),
				'keys_url'   => 'https://platform.deepseek.com/api_keys',
			),
			'gemini'   => array(
				'label'      => 'Gemini (Google)',
				'api'        => 'gemini',
				'url'        => 'https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent',
				'models_url' => 'https://generativelanguage.googleapis.com/v1beta/models',
				'model'      => '',
				'models'     => array(),
				'keys_url'   => 'https://aistudio.google.com/apikey',
			),
			'deepl'    => array(
				'label'    => 'DeepL',
				'api'      => 'deepl',
				'url'      => '',
				'model'    => '',
				'models'   => array(),
				'keys_url' => 'https://www.deepl.com/your-account/keys',
			),
			'custom'   => array(
				'label'      => 'Custom (OpenAI-compatible)',
				'api'        => 'openai',
				'url'        => '',
				'models_url' => '',
				'model'      => '',
				'models'     => array(),
				'keys_url'   => '',
			),
		);
	}

	/**
	 * One preset, or null.
	 *
	 * @param string $provider Provider slug.
	 * @return array<string, mixed>|null
	 */
	public static function preset( $provider ) {
		$presets = self::presets();
		return $presets[ $provider ] ?? null;
	}

	/**
	 * Send one prompt and get a decoded JSON object back.
	 *
	 * @param array       $cfg    provider, api, url, model, optional timeout (s), effort, json_mode.
	 * @param string      $key    API key.
	 * @param string      $system System prompt.
	 * @param string      $user   User message.
	 * @param array|null  $schema JSON schema for the reply (Claude enforces it; others are asked for JSON).
	 * @return array|WP_Error
	 */
	public static function request_json( array $cfg, $key, $system, $user, $schema = null ) {
		$cfg = wp_parse_args(
			$cfg,
			array(
				'provider'  => '',
				'api'       => 'openai',
				'url'       => '',
				'model'     => '',
				'timeout'   => 60,
				'effort'    => 'low',
				'json_mode' => true,
			)
		);

		self::$last_usage = null;
		if ( '' === trim( (string) $key ) ) {
			return self::fail( $cfg, new WP_Error( 'bp_no_key', __( 'No API key is saved for this provider.', 'beaver-press' ) ) );
		}
		if ( '' === trim( (string) $cfg['model'] ) ) {
			return self::fail( $cfg, new WP_Error( 'bp_no_model', __( 'No model is set. Enter the model name in the settings.', 'beaver-press' ) ) );
		}
		if ( '' === trim( (string) $cfg['url'] ) ) {
			return self::fail( $cfg, new WP_Error( 'bp_no_endpoint', __( 'No endpoint URL is set for this provider.', 'beaver-press' ) ) );
		}
		$over = class_exists( 'BP_Budget' ) ? BP_Budget::reached() : '';
		if ( '' !== $over ) {
			return self::fail( $cfg, new WP_Error( 'bp_budget', $over ) );
		}

		switch ( $cfg['api'] ) {
			case 'anthropic':
				$request = self::build_anthropic( $cfg, $key, $system, $user, $schema );
				break;
			case 'gemini':
				$request = self::build_gemini( $cfg, $key, $system, $user );
				break;
			default:
				$request = self::build_openai( $cfg, $key, $system, $user );
		}

		$response = self::send( $request, (int) $cfg['timeout'] );

		// Some OpenAI-compatible servers do not know response_format: ask once more without it.
		if ( 'openai' === $cfg['api'] && $cfg['json_mode'] && is_wp_error( $response ) && 'bp_http_400' === $response->get_error_code()
			&& false !== stripos( $response->get_error_message(), 'response_format' ) ) {
			$cfg['json_mode'] = false;
			$response         = self::send( self::build_openai( $cfg, $key, $system, $user ), (int) $cfg['timeout'] );
		}

		if ( is_wp_error( $response ) ) {
			return self::fail( $cfg, $response );
		}
		// A reply came back: it is billed even if it turns out unusable below.
		self::$last_usage = self::usage_of( (string) $cfg['api'], $response );

		switch ( $cfg['api'] ) {
			case 'anthropic':
				$text = self::parse_anthropic( $response );
				break;
			case 'gemini':
				$text = self::parse_gemini( $response );
				break;
			default:
				$text = self::parse_openai( $response );
		}
		if ( is_wp_error( $text ) ) {
			return self::fail( $cfg, $text );
		}

		$data = self::decode_json_text( $text );
		if ( ! is_array( $data ) ) {
			return self::fail( $cfg, new WP_Error( 'bp_bad_json', __( 'The model did not reply with valid JSON.', 'beaver-press' ) ) );
		}

		delete_option( self::ERROR_OPTION );
		return $data;
	}

	/**
	 * DeepL: translate a list of texts, same order back. Inline HTML tags are kept
	 * (tag_handling html). Free keys end in ":fx" and use the api-free host.
	 *
	 * @param string[] $texts   Texts.
	 * @param string   $source  Source WordPress locale (en_US).
	 * @param string   $target  Target WordPress locale (fr_FR).
	 * @param string   $key     DeepL key.
	 * @param int      $timeout     Seconds.
	 * @param string[] $ignore_tags Tags whose content DeepL must leave as is.
	 * @return string[]|WP_Error
	 */
	public static function deepl_translate( array $texts, $source, $target, $key, $timeout = 60, array $ignore_tags = array() ) {
		$cfg              = array(
			'provider' => 'deepl',
			'model'    => 'deepl',
		);
		self::$last_usage = null;
		if ( '' === trim( (string) $key ) ) {
			return self::fail( $cfg, new WP_Error( 'bp_no_key', __( 'No API key is saved for this provider.', 'beaver-press' ) ) );
		}
		if ( ! $texts ) {
			return array();
		}
		$body = array(
			'text'         => array_values( array_map( 'strval', $texts ) ),
			'source_lang'  => strtoupper( substr( (string) $source, 0, 2 ) ),
			'target_lang'  => self::deepl_target( (string) $target ),
			'tag_handling' => 'html',
		);
		if ( $ignore_tags ) {
			$body['ignore_tags'] = array_values( $ignore_tags );
		}
		$response = self::send(
			array(
				'url'     => self::deepl_base( $key ) . '/v2/translate',
				'headers' => array(
					'content-type'  => 'application/json',
					'authorization' => 'DeepL-Auth-Key ' . $key,
				),
				'body'    => $body,
			),
			(int) $timeout
		);
		if ( is_wp_error( $response ) ) {
			if ( 'bp_http_456' === $response->get_error_code() ) {
				$response = new WP_Error( 'bp_http_456', __( 'DeepL quota for this month is used up.', 'beaver-press' ) );
			}
			return self::fail( $cfg, $response );
		}
		self::$last_usage = array( 0, 0 ); // DeepL bills characters, not tokens.
		$out              = array();
		foreach ( (array) ( $response['translations'] ?? array() ) as $row ) {
			$out[] = (string) ( $row['text'] ?? '' );
		}
		if ( count( $out ) !== count( $texts ) ) {
			return self::fail( $cfg, new WP_Error( 'bp_bad_json', __( 'DeepL returned a different number of texts than it was sent.', 'beaver-press' ) ) );
		}
		delete_option( self::ERROR_OPTION );
		return $out;
	}

	/**
	 * DeepL characters used and allowed this billing period.
	 *
	 * @param string $key DeepL key.
	 * @return array{used: int, limit: int}|WP_Error
	 */
	public static function deepl_usage( $key ) {
		$response = wp_remote_get(
			self::deepl_base( $key ) . '/v2/usage',
			array(
				'timeout' => 15,
				'headers' => array( 'authorization' => 'DeepL-Auth-Key ' . $key ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'bp_transport', $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $code || ! is_array( $data ) ) {
			return new WP_Error( 'bp_http_' . $code, self::error_message( $data, $code ) );
		}
		return array(
			'used'  => (int) ( $data['character_count'] ?? 0 ),
			'limit' => (int) ( $data['character_limit'] ?? 0 ),
		);
	}

	/**
	 * API host for a DeepL key.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	public static function deepl_base( $key ) {
		return ':fx' === substr( trim( (string) $key ), -3 ) ? 'https://api-free.deepl.com' : 'https://api.deepl.com';
	}

	/**
	 * DeepL target code for a WordPress locale (regional where DeepL requires it).
	 *
	 * @param string $locale WordPress locale.
	 * @return string
	 */
	public static function deepl_target( $locale ) {
		$map = array(
			'en_US' => 'EN-US',
			'en_GB' => 'EN-GB',
			'en_AU' => 'EN-GB',
			'en_CA' => 'EN-US',
			'pt_BR' => 'PT-BR',
			'pt_PT' => 'PT-PT',
			'zh_CN' => 'ZH-HANS',
			'zh_TW' => 'ZH-HANT',
			'zh_HK' => 'ZH-HANT',
			'nb_NO' => 'NB',
		);
		if ( isset( $map[ $locale ] ) ) {
			return $map[ $locale ];
		}
		$base = strtoupper( substr( $locale, 0, 2 ) );
		return 'EN' === $base ? 'EN-US' : ( 'PT' === $base ? 'PT-PT' : $base );
	}

	/**
	 * Model IDs this key can use, newest first where the provider says so.
	 *
	 * @param string $provider Provider slug.
	 * @param string $key      API key.
	 * @param string $endpoint Custom chat completions URL (custom provider only).
	 * @return string[]|WP_Error
	 */
	public static function list_models( $provider, $key, $endpoint = '' ) {
		$preset = self::preset( $provider );
		if ( ! $preset || 'deepl' === $preset['api'] ) {
			return new WP_Error( 'bp_no_models', __( 'This provider has no model list.', 'beaver-press' ) );
		}
		$url = 'custom' === $provider ? (string) preg_replace( '#/chat/completions/?$#', '/models', (string) $endpoint ) : (string) $preset['models_url'];
		if ( '' === $url ) {
			return new WP_Error( 'bp_no_endpoint', __( 'Enter the endpoint URL first.', 'beaver-press' ) );
		}
		if ( '' === trim( (string) $key ) ) {
			return new WP_Error( 'bp_no_key', __( 'Paste or save an API key first.', 'beaver-press' ) );
		}

		switch ( $preset['api'] ) {
			case 'anthropic':
				$url     = add_query_arg( 'limit', 1000, $url );
				$headers = array(
					'x-api-key'         => (string) $key,
					'anthropic-version' => '2023-06-01',
				);
				break;
			case 'gemini':
				$url     = add_query_arg( 'pageSize', 1000, $url );
				$headers = array( 'x-goog-api-key' => (string) $key );
				break;
			default:
				$headers = array( 'authorization' => 'Bearer ' . $key );
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 20,
				'headers' => $headers,
			)
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'bp_transport', $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 || ! is_array( $data ) ) {
			return new WP_Error( 'bp_http_' . $code, self::error_message( $data, $code ) );
		}

		$ids = array();
		if ( 'gemini' === $preset['api'] ) {
			foreach ( (array) ( $data['models'] ?? array() ) as $model ) {
				if ( in_array( 'generateContent', (array) ( $model['supportedGenerationMethods'] ?? array() ), true ) ) {
					$ids[] = preg_replace( '#^models/#', '', (string) ( $model['name'] ?? '' ) );
				}
			}
		} else {
			foreach ( (array) ( $data['data'] ?? array() ) as $model ) {
				$ids[] = (string) ( $model['id'] ?? '' );
			}
		}
		$ids = array_values( array_unique( array_filter( $ids ) ) );

		// OpenAI's list also holds embedding, image, audio and moderation models.
		if ( 'openai' === $provider ) {
			$ids = array_values( array_filter( $ids, static fn( $id ) => ! preg_match( '/embed|tts|whisper|dall-e|image|audio|moderation|realtime|transcribe|search|computer-use|babbage|davinci/i', $id ) ) );
		}
		if ( 'anthropic' !== $preset['api'] ) {
			rsort( $ids, SORT_NATURAL );
		}
		return $ids;
	}

	/**
	 * Last failure: array( time, provider, code, message ) or null.
	 *
	 * @param bool $fresh Read the database, not this request's cache.
	 * @return array|null
	 */
	public static function last_error( $fresh = false ) {
		if ( $fresh ) {
			// Written by another request (a page fetched by the Translate-site run): skip this
			// request's option cache and read the database.
			global $wpdb;
			$error = maybe_unserialize( $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::ERROR_OPTION ) ) );
		} else {
			$error = get_option( self::ERROR_OPTION );
		}
		return is_array( $error ) ? $error : null;
	}

	/* ------------------------------------------------------------------ */
	/* Request builders                                                    */
	/* ------------------------------------------------------------------ */

	/**
	 * Claude Messages API.
	 *
	 * No `thinking` or `temperature`: current models run adaptive thinking and reject
	 * sampling parameters; depth is set with output_config.effort where the model has it.
	 *
	 * @param array      $cfg    Config.
	 * @param string     $key    Key.
	 * @param string     $system System prompt.
	 * @param string     $user   User message.
	 * @param array|null $schema Reply schema.
	 * @return array
	 */
	private static function build_anthropic( array $cfg, $key, $system, $user, $schema ) {
		$model = (string) $cfg['model'];
		$body  = array(
			'model'      => $model,
			'max_tokens' => 16000,
			'system'     => (string) $system,
			'messages'   => array(
				array(
					'role'    => 'user',
					'content' => (string) $user,
				),
			),
		);

		$output = array();
		if ( is_array( $schema ) ) {
			$output['format'] = array(
				'type'   => 'json_schema',
				'schema' => $schema,
			);
		}
		if ( '' !== (string) $cfg['effort'] && self::claude_has_effort( $model ) ) {
			$output['effort'] = (string) $cfg['effort'];
		}
		if ( $output ) {
			$body['output_config'] = $output;
		}

		$headers = array(
			'content-type'      => 'application/json',
			'x-api-key'         => (string) $key,
			'anthropic-version' => '2023-06-01',
		);

		// A declined batch is retried on another model inside the same call (Claude API only).
		if ( self::claude_has_fallback( $model, (string) $cfg['url'] ) ) {
			$body['fallbacks']         = 'default';
			$headers['anthropic-beta'] = 'server-side-fallback-2026-07-01';
		}

		return array(
			'url'     => (string) $cfg['url'],
			'headers' => $headers,
			'body'    => $body,
		);
	}

	/**
	 * OpenAI-compatible chat completions (ChatGPT, DeepSeek, custom).
	 *
	 * No temperature or max-tokens field: several current models reject non-default values
	 * or use differently named limits, and a 20-string batch fits the defaults.
	 *
	 * @param array  $cfg    Config.
	 * @param string $key    Key.
	 * @param string $system System prompt.
	 * @param string $user   User message.
	 * @return array
	 */
	private static function build_openai( array $cfg, $key, $system, $user ) {
		$body = array(
			'model'    => (string) $cfg['model'],
			'messages' => array(
				array(
					'role'    => 'system',
					'content' => (string) $system,
				),
				array(
					'role'    => 'user',
					'content' => (string) $user,
				),
			),
		);
		if ( $cfg['json_mode'] ) {
			$body['response_format'] = array( 'type' => 'json_object' );
		}

		return array(
			'url'     => (string) $cfg['url'],
			'headers' => array(
				'content-type'  => 'application/json',
				'authorization' => 'Bearer ' . $key,
			),
			'body'    => $body,
		);
	}

	/**
	 * Gemini generateContent.
	 *
	 * @param array  $cfg    Config.
	 * @param string $key    Key.
	 * @param string $system System prompt.
	 * @param string $user   User message.
	 * @return array
	 */
	private static function build_gemini( array $cfg, $key, $system, $user ) {
		return array(
			'url'     => str_replace( '{model}', rawurlencode( (string) $cfg['model'] ), (string) $cfg['url'] ),
			'headers' => array(
				'content-type'   => 'application/json',
				'x-goog-api-key' => (string) $key,
			),
			'body'    => array(
				'systemInstruction' => array( 'parts' => array( array( 'text' => (string) $system ) ) ),
				'contents'          => array(
					array(
						'role'  => 'user',
						'parts' => array( array( 'text' => (string) $user ) ),
					),
				),
				'generationConfig'  => array( 'responseMimeType' => 'application/json' ),
			),
		);
	}

	/* ------------------------------------------------------------------ */
	/* Transport                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * POST JSON; one retry on rate limits and server errors. Returns the decoded body.
	 *
	 * @param array $request url, headers, body.
	 * @param int   $timeout Seconds.
	 * @return array|WP_Error
	 */
	private static function send( array $request, $timeout ) {
		$args = array(
			'timeout' => max( 5, $timeout ),
			'headers' => $request['headers'],
			'body'    => wp_json_encode( $request['body'] ),
		);

		for ( $attempt = 1; $attempt <= 2; $attempt++ ) {
			$response = wp_remote_post( $request['url'], $args );
			if ( is_wp_error( $response ) ) {
				return new WP_Error( 'bp_transport', $response->get_error_message() );
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

			if ( $code >= 200 && $code < 300 ) {
				return is_array( $data ) ? $data : new WP_Error( 'bp_bad_body', __( 'The provider returned a response that is not JSON.', 'beaver-press' ) );
			}

			if ( 1 === $attempt && in_array( $code, self::RETRY_CODES, true ) ) {
				$wait = (int) wp_remote_retrieve_header( $response, 'retry-after' );
				sleep( min( 8, max( 1, $wait ) ) );
				continue;
			}

			return new WP_Error( 'bp_http_' . $code, self::error_message( $data, $code ) );
		}

		return new WP_Error( 'bp_transport', __( 'No response from the provider.', 'beaver-press' ) );
	}

	/**
	 * The provider's own words for a failed call.
	 *
	 * @param mixed $data Decoded body.
	 * @param int   $code HTTP code.
	 * @return string
	 */
	private static function error_message( $data, $code ) {
		$message = '';
		if ( is_array( $data ) ) {
			if ( isset( $data['error']['message'] ) ) {
				$message = (string) $data['error']['message'];     // Anthropic, OpenAI, DeepSeek, Gemini.
			} elseif ( isset( $data['message'] ) ) {
				$message = (string) $data['message'];              // DeepL and some custom servers.
			} elseif ( isset( $data['error'] ) && is_string( $data['error'] ) ) {
				$message = $data['error'];
			}
		}
		$hints = array(
			401 => __( 'The API key was rejected.', 'beaver-press' ),
			403 => __( 'The API key has no access to this model or endpoint.', 'beaver-press' ),
			404 => __( 'Model or endpoint not found. Check the model name.', 'beaver-press' ),
			429 => __( 'Rate limit or quota reached.', 'beaver-press' ),
		);
		$prefix = $hints[ $code ] ?? sprintf( /* translators: %d: HTTP status code */ __( 'HTTP %d from the provider.', 'beaver-press' ), $code );
		return trim( $prefix . ' ' . wp_strip_all_tags( $message ) );
	}

	/* ------------------------------------------------------------------ */
	/* Response parsers: return the reply text or a WP_Error               */
	/* ------------------------------------------------------------------ */

	/**
	 * Claude: join text blocks; refusals and cut-off replies are errors.
	 *
	 * @param array $data Decoded body.
	 * @return string|WP_Error
	 */
	private static function parse_anthropic( array $data ) {
		$stop = (string) ( $data['stop_reason'] ?? '' );
		if ( 'refusal' === $stop ) {
			return new WP_Error( 'bp_refusal', __( 'The model declined this batch; the original text is kept.', 'beaver-press' ) );
		}
		if ( 'max_tokens' === $stop ) {
			return new WP_Error( 'bp_truncated', __( 'The reply was cut off; try a smaller batch size.', 'beaver-press' ) );
		}
		$text = '';
		foreach ( (array) ( $data['content'] ?? array() ) as $block ) {
			if ( is_array( $block ) && 'text' === ( $block['type'] ?? '' ) ) {
				$text .= (string) ( $block['text'] ?? '' );
			}
		}
		return '' === $text ? new WP_Error( 'bp_empty', __( 'The model returned no text.', 'beaver-press' ) ) : $text;
	}

	/**
	 * OpenAI-compatible: first choice.
	 *
	 * @param array $data Decoded body.
	 * @return string|WP_Error
	 */
	private static function parse_openai( array $data ) {
		$choice = $data['choices'][0] ?? null;
		if ( ! is_array( $choice ) ) {
			return new WP_Error( 'bp_empty', __( 'The model returned no choices.', 'beaver-press' ) );
		}
		$finish = (string) ( $choice['finish_reason'] ?? '' );
		if ( 'length' === $finish ) {
			return new WP_Error( 'bp_truncated', __( 'The reply was cut off; try a smaller batch size.', 'beaver-press' ) );
		}
		if ( 'content_filter' === $finish ) {
			return new WP_Error( 'bp_refusal', __( 'The model declined this batch; the original text is kept.', 'beaver-press' ) );
		}
		$text = (string) ( $choice['message']['content'] ?? '' );
		return '' === $text ? new WP_Error( 'bp_empty', __( 'The model returned no text.', 'beaver-press' ) ) : $text;
	}

	/**
	 * Gemini: first candidate's parts; blocked prompts and safety stops are errors.
	 *
	 * @param array $data Decoded body.
	 * @return string|WP_Error
	 */
	private static function parse_gemini( array $data ) {
		if ( empty( $data['candidates'] ) ) {
			if ( ! empty( $data['promptFeedback']['blockReason'] ) ) {
				return new WP_Error( 'bp_refusal', __( 'The model declined this batch; the original text is kept.', 'beaver-press' ) );
			}
			return new WP_Error( 'bp_empty', __( 'The model returned no candidates.', 'beaver-press' ) );
		}
		$candidate = $data['candidates'][0];
		$finish    = (string) ( $candidate['finishReason'] ?? '' );
		if ( 'MAX_TOKENS' === $finish ) {
			return new WP_Error( 'bp_truncated', __( 'The reply was cut off; try a smaller batch size.', 'beaver-press' ) );
		}
		if ( in_array( $finish, array( 'SAFETY', 'PROHIBITED_CONTENT', 'BLOCKLIST', 'RECITATION' ), true ) ) {
			return new WP_Error( 'bp_refusal', __( 'The model declined this batch; the original text is kept.', 'beaver-press' ) );
		}
		$text = '';
		foreach ( (array) ( $candidate['content']['parts'] ?? array() ) as $part ) {
			$text .= (string) ( $part['text'] ?? '' );
		}
		return '' === $text ? new WP_Error( 'bp_empty', __( 'The model returned no text.', 'beaver-press' ) ) : $text;
	}

	/* ------------------------------------------------------------------ */
	/* Helpers                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Decode a JSON reply, tolerating a ```json fence around it.
	 *
	 * @param string $text Reply text.
	 * @return mixed
	 */
	private static function decode_json_text( $text ) {
		$text = trim( (string) $text );
		if ( preg_match( '/^```(?:json)?\s*(.*?)\s*```$/s', $text, $m ) ) {
			$text = $m[1];
		}
		return json_decode( $text, true );
	}

	/**
	 * Whether a Claude model takes output_config.effort (Opus/Sonnet 4.6 and later, Fable, Mythos).
	 *
	 * @param string $model Model ID.
	 * @return bool
	 */
	public static function claude_has_effort( $model ) {
		if ( preg_match( '/^claude-(fable|mythos)-/', $model ) ) {
			return true;
		}
		if ( ! preg_match( '/^claude-(opus|sonnet)-(\d+)(?:-(\d+))?/', $model, $m ) ) {
			return false;
		}
		$major = (int) $m[2];
		$minor = isset( $m[3] ) ? (int) $m[3] : 0;
		return $major >= 5 || ( 4 === $major && $minor >= 6 );
	}

	/**
	 * Whether to send the server-side refusal fallback ("default" form, Claude API only).
	 *
	 * @param string $model Model ID.
	 * @param string $url   Endpoint.
	 * @return bool
	 */
	public static function claude_has_fallback( $model, $url ) {
		$models = array( 'claude-fable-5-1', 'claude-opus-5-5', 'claude-opus-5', 'claude-sonnet-5-5' );
		return in_array( $model, $models, true ) && 'api.anthropic.com' === wp_parse_url( $url, PHP_URL_HOST );
	}

	/**
	 * Remember a failure for the settings panel (never the key or the text sent).
	 *
	 * @param array    $cfg   Config.
	 * @param WP_Error $error Error.
	 * @return WP_Error
	 */
	private static function fail( array $cfg, WP_Error $error ) {
		update_option(
			self::ERROR_OPTION,
			array(
				'time'     => time(),
				'provider' => (string) $cfg['provider'],
				'model'    => (string) $cfg['model'],
				'code'     => $error->get_error_code(),
				'message'  => $error->get_error_message(),
			),
			false
		);
		return $error;
	}
}
