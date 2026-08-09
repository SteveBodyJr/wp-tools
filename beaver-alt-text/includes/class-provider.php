<?php
/**
 * Vision model transport.
 *
 * @package BeaverAltText
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sends an image to a vision model and returns a validated description.
 *
 * Two wire formats are spoken: Anthropic's Messages API, and the OpenAI
 * chat-completions shape that OpenRouter, Groq, and most self-hosted gateways
 * also implement. Everything above this class deals in a result array and
 * never sees an HTTP response.
 *
 * @since 1.0.0
 */
class Beaver_Alt_Provider {

	const API_VERSION = '2023-06-01';

	/**
	 * Media types the plugin will send.
	 */
	const SUPPORTED_MIME_TYPES = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );

	/**
	 * The services this plugin can talk to.
	 *
	 * `vision` is the load-bearing field: a provider whose served models cannot
	 * accept an image is useless for alt text no matter how good it is at text,
	 * and is refused up front rather than failing later with an opaque API
	 * error. DeepSeek is the current example — image understanding exists in
	 * their web chat but is not exposed as an API content type.
	 *
	 * @since 1.1.0
	 *
	 * @return array<string,array> Provider definitions keyed by id.
	 */
	public static function providers() {
		$providers = array(
			'claude'     => array(
				'label'   => 'Claude (Anthropic)',
				'api'     => 'anthropic',
				'url'     => 'https://api.anthropic.com/v1/messages',
				'model'   => 'claude-opus-5',
				'vision'  => true,
				'keys_at' => 'platform.claude.com',
				'note'    => __( 'Recommended. Strongest at saying "I am not sure" instead of guessing, which is what you want when a wrong description gets read aloud.', 'beaver-alt-text' ),
			),
			'openai'     => array(
				'label'   => 'OpenAI',
				'api'     => 'openai',
				'url'     => 'https://api.openai.com/v1/chat/completions',
				'model'   => 'gpt-4o-mini',
				'vision'  => true,
				'keys_at' => 'platform.openai.com',
				'note'    => __( 'Capable and cheap for captioning.', 'beaver-alt-text' ),
			),
			'openrouter' => array(
				'label'   => 'OpenRouter',
				'api'     => 'openai',
				'url'     => 'https://openrouter.ai/api/v1/chat/completions',
				'model'   => 'anthropic/claude-opus-5',
				'vision'  => true,
				'keys_at' => 'openrouter.ai',
				'note'    => __( 'One key, many models. Pick any vision-capable model slug from openrouter.ai/models.', 'beaver-alt-text' ),
			),
			'groq'       => array(
				'label'   => 'Groq',
				'api'     => 'openai',
				'url'     => 'https://api.groq.com/openai/v1/chat/completions',
				'model'   => 'meta-llama/llama-4-scout-17b-16e-instruct',
				'vision'  => true,
				'keys_at' => 'console.groq.com',
				'note'    => __( 'Very fast and very cheap. Weaker at admitting uncertainty, so keep review on.', 'beaver-alt-text' ),
			),
			'deepseek'   => array(
				'label'   => 'DeepSeek',
				'api'     => 'openai',
				'url'     => 'https://api.deepseek.com/chat/completions',
				'model'   => '',
				'vision'  => false,
				'keys_at' => 'platform.deepseek.com',
				'note'    => __( 'Cannot be used here. DeepSeek reads images in its own chat app, but its API accepts text only, so it cannot describe a picture it is never sent. Use it in Beaver AI Chat instead, where the work is text.', 'beaver-alt-text' ),
			),
			'custom'     => array(
				'label'   => 'Custom (OpenAI-compatible endpoint)',
				'api'     => 'openai',
				'url'     => '',
				'model'   => '',
				'vision'  => true,
				'keys_at' => __( 'your own provider', 'beaver-alt-text' ),
				'note'    => __( 'Any gateway that speaks the OpenAI chat-completions format and accepts image_url content.', 'beaver-alt-text' ),
			),
		);

		/**
		 * Filters the providers offered in the settings screen.
		 *
		 * @since 1.1.0
		 *
		 * @param array $providers Provider definitions.
		 */
		return apply_filters( 'beaver_alt_providers', $providers );
	}

	/**
	 * Returns the resolved configuration for the selected provider.
	 *
	 * @since 1.1.0
	 *
	 * @return array Provider config with 'id', 'url' and 'model' filled in.
	 */
	public static function config() {
		$providers = self::providers();
		$id        = (string) Beaver_Alt_Generator::get_setting( 'provider', 'claude' );

		if ( ! isset( $providers[ $id ] ) ) {
			$id = 'claude';
		}

		$config       = $providers[ $id ];
		$config['id'] = $id;

		$model = trim( (string) Beaver_Alt_Generator::get_setting( 'model', '' ) );

		if ( '' !== $model ) {
			$config['model'] = $model;
		}

		$endpoint = trim( (string) Beaver_Alt_Generator::get_setting( 'endpoint', '' ) );

		if ( '' !== $endpoint ) {
			$config['url'] = $endpoint;
		}

		return $config;
	}

	/**
	 * Returns the API key, in order of precedence.
	 *
	 * A constant beats the database so a key never has to live in a
	 * client-visible settings screen. Beaver AI Chat's key is accepted as a
	 * last resort: on a site running both, one key for both is what people
	 * actually want.
	 *
	 * @since 1.0.0
	 *
	 * @return string API key, or an empty string.
	 */
	public static function api_key() {
		if ( defined( 'BEAVER_ALT_API_KEY' ) && '' !== (string) BEAVER_ALT_API_KEY ) {
			return (string) BEAVER_ALT_API_KEY;
		}

		$stored = Beaver_Alt_Generator::get_setting( 'api_key', '' );

		if ( '' !== (string) $stored ) {
			return (string) $stored;
		}

		if ( defined( 'BAC_API_KEY' ) && '' !== (string) BAC_API_KEY ) {
			return (string) BAC_API_KEY;
		}

		if ( class_exists( 'BAC_Settings' ) && method_exists( 'BAC_Settings', 'api_key' ) ) {
			return (string) BAC_Settings::api_key();
		}

		return '';
	}

	/**
	 * Whether the plugin can currently describe an image.
	 *
	 * @since 1.0.0
	 *
	 * @return bool Whether a usable provider and key are configured.
	 */
	public static function is_configured() {
		$config = self::config();

		return '' !== self::api_key()
			&& ! empty( $config['vision'] )
			&& '' !== (string) $config['url']
			&& '' !== (string) $config['model'];
	}

	/**
	 * Explains why the plugin cannot run, if it cannot.
	 *
	 * @since 1.1.0
	 *
	 * @return string Translated reason, or an empty string when all is well.
	 */
	public static function configuration_problem() {
		$config = self::config();

		if ( empty( $config['vision'] ) ) {
			return sprintf(
				/* translators: %s: provider label. */
				__( '%s cannot read images through its API, so it cannot write alt text. Choose a provider that accepts image input.', 'beaver-alt-text' ),
				$config['label']
			);
		}

		if ( '' === (string) $config['url'] ) {
			return __( 'No endpoint is set for this provider.', 'beaver-alt-text' );
		}

		if ( '' === (string) $config['model'] ) {
			return __( 'No model is set. Enter a model name in the settings.', 'beaver-alt-text' );
		}

		if ( '' === self::api_key() ) {
			return __( 'No API key is configured.', 'beaver-alt-text' );
		}

		return '';
	}

	/**
	 * The JSON shape the model must return.
	 *
	 * @since 1.0.0
	 *
	 * @return array JSON Schema.
	 */
	private static function schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'decorative' => array(
					'type'        => 'boolean',
					'description' => 'True when the image carries no information a reader would miss: spacers, dividers, background textures, pure ornament.',
				),
				'alt'        => array(
					'type'        => 'string',
					'description' => 'The alt text. One sentence, under 125 characters, no "image of" or "photo of" prefix. Empty string when decorative is true.',
				),
				'caption'    => array(
					'type'        => 'string',
					'description' => 'An optional longer caption for editorial use. May be empty.',
				),
				'confidence' => array(
					'type'        => 'string',
					'enum'        => array( 'high', 'medium', 'low' ),
					'description' => 'How certain you are that the description is factually correct. Use low whenever you are naming something specific you cannot verify, such as a species, a place, or a person.',
				),
				'reason'     => array(
					'type'        => 'string',
					'description' => 'Why confidence is not high. Empty when confidence is high.',
				),
			),
			'required'             => array( 'decorative', 'alt', 'caption', 'confidence', 'reason' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * The language alt text should be written in.
	 *
	 * Alt text is read aloud by a screen reader set to the page's language, so
	 * English descriptions on a Swahili page are announced with English words
	 * in a Swahili voice. Following the site locale is the default; the setting
	 * exists for sites whose content language differs from their admin locale.
	 *
	 * @since 1.2.0
	 *
	 * @return string Language name, or an empty string to leave it to the model.
	 */
	private static function language() {
		$configured = trim( (string) Beaver_Alt_Generator::get_setting( 'language', '' ) );

		if ( '' !== $configured ) {
			return $configured;
		}

		$locale = function_exists( 'get_locale' ) ? (string) get_locale() : 'en_US';

		if ( 0 === strpos( $locale, 'en' ) ) {
			return '';
		}

		$names = array(
			'sw' => 'Swahili',
			'fr' => 'French',
			'de' => 'German',
			'es' => 'Spanish',
			'pt' => 'Portuguese',
			'it' => 'Italian',
			'nl' => 'Dutch',
			'ar' => 'Arabic',
			'zh' => 'Chinese',
			'ru' => 'Russian',
		);

		$code = substr( $locale, 0, 2 );

		return $names[ $code ] ?? $locale;
	}

	/**
	 * Builds the system prompt.
	 *
	 * Identical for every image in a run, which is what makes it worth caching.
	 *
	 * @since 1.0.0
	 *
	 * @return string System prompt.
	 */
	private static function system_prompt() {
		$context = trim( (string) Beaver_Alt_Generator::get_setting( 'site_context', '' ) );

		$prompt = __( 'You write alt text for images on a website. Alt text is read aloud by screen readers, so it describes what a sighted reader would take from the image, in one plain sentence.

Write the alt text itself, not a description of the file: never begin with "image of", "photo of", "picture of", or the site name. Keep it under 125 characters. Do not add keywords that are not visibly in the image.

Some images carry no information: spacers, dividers, background textures, decorative flourishes, and logos used purely as ornament. For those, set decorative to true and return an empty alt string. An empty alt is the correct, accessible result for a decorative image — inventing a description for one makes the page worse for screen reader users, so do not describe an image just because you can.

Be accurate before you are specific. If you name a species, a landmark, a place, or a person, you are asserting a fact about the photograph. When you cannot verify that from the image alone, describe what is plainly visible instead and set confidence to low, explaining what you were unsure about. A generic but true description is worth more than a specific guess.

Reply with a single JSON object and nothing else.', 'beaver-alt-text' );

		$language = self::language();

		if ( '' !== $language ) {
			$prompt .= "\n\n" . sprintf(
				/* translators: %s: language name, e.g. Swahili. */
				__( 'Write the alt text and caption in %s.', 'beaver-alt-text' ),
				$language
			);
		}

		if ( '' !== $context ) {
			$prompt .= "\n\n" . sprintf(
				/* translators: %s: site-specific context entered in the settings. */
				__( 'Context for this site, which may help you read the images: %s', 'beaver-alt-text' ),
				$context
			);
		}

		/**
		 * Filters the system prompt sent with every image.
		 *
		 * @since 1.1.0
		 *
		 * @param string $prompt  The prompt.
		 * @param string $context The site context setting.
		 */
		return (string) apply_filters( 'beaver_alt_system_prompt', $prompt, $context );
	}

	/**
	 * Builds the per-image instruction.
	 *
	 * @since 1.1.0
	 *
	 * @param array $image Prepared image payload.
	 * @return string User text.
	 */
	private static function user_text( $image ) {
		$hint = '';

		if ( ! empty( $image['filename'] ) ) {
			$hint .= sprintf(
				/* translators: %s: original file name. */
				__( 'Original file name, which may or may not be meaningful: %s', 'beaver-alt-text' ),
				$image['filename']
			) . "\n";
		}

		if ( ! empty( $image['context'] ) ) {
			$hint .= sprintf(
				/* translators: %s: text surrounding the image on the page. */
				__( 'The image appears in this context: %s', 'beaver-alt-text' ),
				$image['context']
			) . "\n";
		}

		return trim( $hint . __( 'Write the alt text for this image.', 'beaver-alt-text' ) );
	}

	/**
	 * Requests alt text for one image.
	 *
	 * @since 1.0.0
	 *
	 * @param array $image {
	 *     Prepared image payload.
	 *
	 *     @type string $data       Base64 image data.
	 *     @type string $media_type Image MIME type.
	 *     @type string $filename   Original file name, used as a hint.
	 *     @type string $context    Surrounding page text, if any.
	 * }
	 * @param float $timeout Seconds to wait for a reply. Clamped to 10–60.
	 * @return array|WP_Error Validated result, or an error.
	 */
	public static function describe( $image, $timeout = 60 ) {
		$problem = self::configuration_problem();

		if ( '' !== $problem ) {
			return new WP_Error( 'beaver_alt_not_configured', $problem );
		}

		if ( ! in_array( $image['media_type'], self::SUPPORTED_MIME_TYPES, true ) ) {
			return new WP_Error(
				'beaver_alt_unsupported_type',
				sprintf(
					/* translators: %s: image MIME type. */
					__( 'The model cannot read %s images.', 'beaver-alt-text' ),
					$image['media_type']
				)
			);
		}

		$config  = self::config();
		$timeout = (float) max( 10, min( 60, $timeout ) );

		if ( 'anthropic' === $config['api'] ) {
			$response = self::call_anthropic( $config, $image, $timeout );
		} else {
			$response = self::call_openai( $config, $image, $timeout );
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return self::parse( $response['text'], $response['usage'], $response['model'] );
	}

	/**
	 * Calls the Anthropic Messages API.
	 *
	 * @since 1.1.0
	 *
	 * @param array $config  Provider config.
	 * @param array $image   Prepared image payload.
	 * @param float $timeout Request timeout.
	 * @return array|WP_Error Raw text, usage and model, or an error.
	 */
	private static function call_anthropic( $config, $image, $timeout ) {
		$body = array(
			'model'         => $config['model'],
			'max_tokens'    => 2000,
			'system'        => array(
				array(
					'type'          => 'text',
					'text'          => self::system_prompt(),
					// Identical for every image in a run, so the instructions
					// are paid for once rather than per image.
					'cache_control' => array( 'type' => 'ephemeral' ),
				),
			),
			'output_config' => array(
				// Captioning is not a reasoning task; low effort keeps a run of
				// several hundred images cheap without hurting the description.
				'effort' => 'low',
				'format' => array(
					'type'   => 'json_schema',
					'schema' => self::schema(),
				),
			),
			'messages'      => array(
				array(
					'role'    => 'user',
					'content' => array(
						array(
							'type'   => 'image',
							'source' => array(
								'type'       => 'base64',
								'media_type' => $image['media_type'],
								'data'       => $image['data'],
							),
						),
						array(
							'type' => 'text',
							'text' => self::user_text( $image ),
						),
					),
				),
			),
		);

		$response = self::post(
			$config['url'],
			array(
				'x-api-key'         => self::api_key(),
				'anthropic-version' => self::API_VERSION,
				'content-type'      => 'application/json',
			),
			$body,
			$timeout
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data = $response;

		/*
		 * A declined request is a successful HTTP response with an empty or
		 * partial content array, so the stop reason has to be checked before
		 * anything reads content — indexing straight into content[0] is exactly
		 * how a refusal turns into a fatal error.
		 */
		if ( 'refusal' === ( $data['stop_reason'] ?? '' ) ) {
			return new WP_Error( 'beaver_alt_refusal', __( 'The model declined to describe this image.', 'beaver-alt-text' ) );
		}

		if ( 'max_tokens' === ( $data['stop_reason'] ?? '' ) ) {
			return new WP_Error( 'beaver_alt_truncated', __( 'The response was cut off before it was complete.', 'beaver-alt-text' ) );
		}

		$text = '';

		foreach ( (array) ( $data['content'] ?? array() ) as $block ) {
			if ( isset( $block['type'], $block['text'] ) && 'text' === $block['type'] ) {
				$text .= $block['text'];
			}
		}

		return array(
			'text'  => $text,
			'model' => (string) ( $data['model'] ?? $config['model'] ),
			'usage' => array(
				'input'       => (int) ( $data['usage']['input_tokens'] ?? 0 ),
				'output'      => (int) ( $data['usage']['output_tokens'] ?? 0 ),
				'cache_read'  => (int) ( $data['usage']['cache_read_input_tokens'] ?? 0 ),
				'cache_write' => (int) ( $data['usage']['cache_creation_input_tokens'] ?? 0 ),
			),
		);
	}

	/**
	 * Calls an OpenAI-compatible chat-completions endpoint.
	 *
	 * @since 1.1.0
	 *
	 * @param array $config  Provider config.
	 * @param array $image   Prepared image payload.
	 * @param float $timeout Request timeout.
	 * @return array|WP_Error Raw text, usage and model, or an error.
	 */
	private static function call_openai( $config, $image, $timeout ) {
		$body = array(
			'model'      => $config['model'],
			'max_tokens' => 2000,
			'messages'   => array(
				array(
					'role'    => 'system',
					'content' => self::system_prompt(),
				),
				array(
					'role'    => 'user',
					'content' => array(
						array(
							'type'      => 'image_url',
							'image_url' => array(
								'url' => 'data:' . $image['media_type'] . ';base64,' . $image['data'],
							),
						),
						array(
							'type' => 'text',
							'text' => self::user_text( $image ),
						),
					),
				),
			),
			/*
			 * Not every OpenAI-compatible gateway implements json_schema, and a
			 * strict one rejects the request outright rather than degrading. The
			 * system prompt also asks for a bare JSON object, so a provider that
			 * ignores this still returns something parseable.
			 */
			'response_format' => array(
				'type'        => 'json_schema',
				'json_schema' => array(
					'name'   => 'alt_text',
					'strict' => true,
					'schema' => self::schema(),
				),
			),
		);

		$headers = array(
			'Authorization' => 'Bearer ' . self::api_key(),
			'Content-Type'  => 'application/json',
		);

		// OpenRouter asks callers to identify themselves.
		if ( 'openrouter' === $config['id'] ) {
			$headers['HTTP-Referer'] = home_url( '/' );
			$headers['X-Title']      = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		}

		$data = self::post( $config['url'], $headers, $body, $timeout );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$choice = $data['choices'][0] ?? array();

		if ( 'content_filter' === ( $choice['finish_reason'] ?? '' ) ) {
			return new WP_Error( 'beaver_alt_refusal', __( 'The model declined to describe this image.', 'beaver-alt-text' ) );
		}

		if ( 'length' === ( $choice['finish_reason'] ?? '' ) ) {
			return new WP_Error( 'beaver_alt_truncated', __( 'The response was cut off before it was complete.', 'beaver-alt-text' ) );
		}

		$content = $choice['message']['content'] ?? '';

		// Some gateways return content as an array of parts rather than a string.
		if ( is_array( $content ) ) {
			$joined = '';

			foreach ( $content as $part ) {
				$joined .= is_array( $part ) ? (string) ( $part['text'] ?? '' ) : (string) $part;
			}

			$content = $joined;
		}

		return array(
			'text'  => (string) $content,
			'model' => (string) ( $data['model'] ?? $config['model'] ),
			'usage' => array(
				'input'       => (int) ( $data['usage']['prompt_tokens'] ?? 0 ),
				'output'      => (int) ( $data['usage']['completion_tokens'] ?? 0 ),
				'cache_read'  => 0,
				'cache_write' => 0,
			),
		);
	}

	/**
	 * Performs the HTTP request and decodes the envelope.
	 *
	 * @since 1.1.0
	 *
	 * @param string $url     Endpoint.
	 * @param array  $headers Request headers.
	 * @param array  $body    Request body.
	 * @param float  $timeout Request timeout.
	 * @return array|WP_Error Decoded body, or an error.
	 */
	private static function post( $url, $headers, $body, $timeout ) {
		$deadline = microtime( true ) + $timeout;
		$payload  = wp_json_encode( $body );
		$attempt  = 0;

		/**
		 * Filters how many times a throttled or failing request is retried.
		 *
		 * @since 1.2.0
		 *
		 * @param int $retries Additional attempts after the first. Default 2.
		 */
		$retries = (int) apply_filters( 'beaver_alt_max_retries', 2 );

		while ( true ) {
			$remaining = $deadline - microtime( true );

			if ( $remaining < 5 ) {
				return new WP_Error( 'beaver_alt_no_time', __( 'There was not enough time left in this request to reach the model.', 'beaver-alt-text' ) );
			}

			$response = wp_remote_post(
				$url,
				array(
					/*
					 * Bounded by the caller's remaining execution time. Waiting
					 * 60 seconds for a reply inside a 30-second request just
					 * moves the failure from a handled error to a killed process.
					 */
					'timeout' => $remaining,
					'headers' => $headers,
					'body'    => $payload,
				)
			);

			if ( is_wp_error( $response ) ) {
				return new WP_Error(
					'beaver_alt_http',
					sprintf(
						/* translators: %s: transport error message. */
						__( 'Could not reach the model: %s', 'beaver-alt-text' ),
						$response->get_error_message()
					)
				);
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

			if ( $code >= 200 && $code < 300 ) {
				if ( ! is_array( $data ) ) {
					return new WP_Error( 'beaver_alt_bad_json', __( 'The model returned a response that could not be read.', 'beaver-alt-text' ) );
				}

				return $data;
			}

			/*
			 * Rate limits and server errors are temporary, and a bulk run walks
			 * straight into them. Failing the image permanently on the first 429
			 * means hunting it down by hand later, so back off and try again for
			 * as long as this request has time to spare.
			 */
			$retryable = ( 429 === $code || $code >= 500 );

			if ( ! $retryable || $attempt >= $retries ) {
				$message = is_array( $data ) ? (string) ( $data['error']['message'] ?? '' ) : '';

				if ( '' === $message ) {
					/* translators: %d: HTTP status code. */
					$message = sprintf( __( 'The model returned HTTP %d.', 'beaver-alt-text' ), $code );
				}

				return new WP_Error( 'beaver_alt_api', $message, array( 'status' => $code ) );
			}

			// Honour the server's own advice when it gives any.
			$after = (int) wp_remote_retrieve_header( $response, 'retry-after' );
			$wait  = $after > 0 ? $after : (int) pow( 2, $attempt );
			$wait  = min( $wait, 10 );

			if ( ( $deadline - microtime( true ) ) - $wait < 5 ) {
				return new WP_Error(
					'beaver_alt_rate_limited',
					__( 'The model is rate limiting requests and there was no time left to wait. The image has been left for the next run.', 'beaver-alt-text' )
				);
			}

			sleep( $wait );
			++$attempt;
		}
	}

	/**
	 * Validates the model's JSON reply.
	 *
	 * @since 1.1.0
	 *
	 * @param string $text  Raw reply text.
	 * @param array  $usage Token usage.
	 * @param string $model Model that answered.
	 * @return array|WP_Error Validated result, or an error.
	 */
	private static function parse( $text, $usage, $model ) {
		$text = trim( (string) $text );

		if ( '' === $text ) {
			return new WP_Error( 'beaver_alt_empty', __( 'The model returned nothing for this image.', 'beaver-alt-text' ) );
		}

		$parsed = json_decode( $text, true );

		/*
		 * Providers that ignore the response-format request tend to wrap the
		 * object in prose or a fenced code block. Recovering the object is
		 * cheap and turns a hard failure into a normal result.
		 */
		if ( ! is_array( $parsed ) ) {
			$start = strpos( $text, '{' );
			$end   = strrpos( $text, '}' );

			if ( false !== $start && false !== $end && $end > $start ) {
				$parsed = json_decode( substr( $text, $start, $end - $start + 1 ), true );
			}
		}

		if ( ! is_array( $parsed ) || ! array_key_exists( 'alt', $parsed ) ) {
			return new WP_Error( 'beaver_alt_bad_shape', __( 'The model returned an unexpected result for this image.', 'beaver-alt-text' ) );
		}

		return array(
			'decorative' => ! empty( $parsed['decorative'] ),
			'alt'        => sanitize_text_field( (string) ( $parsed['alt'] ?? '' ) ),
			'caption'    => sanitize_text_field( (string) ( $parsed['caption'] ?? '' ) ),
			'confidence' => in_array( ( $parsed['confidence'] ?? '' ), array( 'high', 'medium', 'low' ), true )
				? (string) $parsed['confidence']
				: 'low',
			'reason'     => sanitize_text_field( (string) ( $parsed['reason'] ?? '' ) ),
			'usage'      => $usage,
			'model'      => $model,
		);
	}
}
