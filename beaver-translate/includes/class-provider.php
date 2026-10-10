<?php
/**
 * Provider dispatcher. Everything here runs server side, and the API key
 * never leaves this file's request.
 *
 * Adapted from beaver-ai-chat's BAC_Provider, which speaks the same three
 * wire formats — kept as a single-turn "system prompt + one block of text
 * in, one block of text out" call here, since translation has no
 * conversation to carry across turns.
 *
 * @package BeaverTranslate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Beaver_Translate_Provider
 *
 * @since 1.0.0
 */
class Beaver_Translate_Provider {

	/** Where the last provider failure is recorded, for the admin to read. */
	const ERROR_OPTION = 'beaver_translate_last_error';

	/**
	 * Sends one block of text with a system prompt and returns the reply.
	 *
	 * @since 1.0.0
	 *
	 * @param string     $system Instructions for the model.
	 * @param string     $text   The text to act on.
	 * @param array|null $s      Settings (read fresh when omitted).
	 * @return string|WP_Error Reply text, or the failure.
	 */
	public static function complete( $system, $text, $s = null ) {
		$s   = null === $s ? Beaver_Translate_Settings::all() : $s;
		$key = Beaver_Translate_Settings::api_key();

		if ( '' === $key ) {
			return self::fail( new WP_Error( 'beaver_translate_no_key', __( 'No API key is configured.', 'beaver-translate' ) ), $s );
		}

		$text = trim( (string) $text );

		if ( '' === $text ) {
			return '';
		}

		$cfg = Beaver_Translate_Settings::provider_config( $s );

		if ( '' === trim( (string) $cfg['url'] ) ) {
			return self::fail( new WP_Error( 'beaver_translate_no_endpoint', __( 'No endpoint is configured for this provider.', 'beaver-translate' ) ), $s, $cfg );
		}

		if ( '' === trim( (string) $cfg['model'] ) ) {
			return self::fail( new WP_Error( 'beaver_translate_no_model', __( 'No model is configured. Enter a model name in the settings.', 'beaver-translate' ) ), $s, $cfg );
		}

		$messages = array( array( 'role' => 'user', 'content' => $text ) );

		switch ( $cfg['api'] ) {
			case 'anthropic':
				$result = self::call_anthropic( $cfg, $key, $system, $messages, $s );
				break;
			case 'gemini':
				$result = self::call_gemini( $cfg, $key, $system, $messages, $s );
				break;
			default:
				$result = self::call_openai( $cfg, $key, $system, $messages, $s );
		}

		return is_wp_error( $result ) ? self::fail( $result, $s, $cfg ) : self::succeed( $result );
	}

	/**
	 * Remember why the last call failed, so the admin can see the provider's
	 * own words instead of only a generic message.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_Error   $error Failure.
	 * @param array      $s     Settings.
	 * @param array|null $cfg   Provider config, when it was resolved.
	 * @return WP_Error The same error, so callers can return it directly.
	 */
	private static function fail( $error, $s, $cfg = null ) {
		update_option(
			self::ERROR_OPTION,
			array(
				'code'     => $error->get_error_code(),
				'message'  => $error->get_error_message(),
				'provider' => isset( $s['provider'] ) ? $s['provider'] : '',
				'model'    => $cfg && isset( $cfg['model'] ) ? $cfg['model'] : '',
				'time'     => time(),
			),
			false
		);

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[beaver-translate] ' . $error->get_error_code() . ': ' . $error->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		return $error;
	}

	/**
	 * Clears the recorded failure once a call succeeds.
	 *
	 * @since 1.0.0
	 *
	 * @param string $reply Reply text.
	 * @return string
	 */
	private static function succeed( $reply ) {
		if ( get_option( self::ERROR_OPTION, false ) ) {
			delete_option( self::ERROR_OPTION );
		}

		return $reply;
	}

	/**
	 * The last recorded failure, if any.
	 *
	 * @since 1.0.0
	 *
	 * @return array|null
	 */
	public static function last_error() {
		$error = get_option( self::ERROR_OPTION, null );

		return is_array( $error ) && ! empty( $error['message'] ) ? $error : null;
	}

	/**
	 * Anthropic Messages API.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $cfg      Provider config.
	 * @param string $key      API key.
	 * @param string $system   System prompt.
	 * @param array  $messages Single-entry conversation.
	 * @param array  $s        Settings.
	 * @return string|WP_Error
	 */
	private static function call_anthropic( $cfg, $key, $system, $messages, $s ) {
		$body = array(
			'model'      => $cfg['model'],
			'system'     => $system,
			'messages'   => $messages,
			'max_tokens' => (int) $s['max_tokens'],
		);

		$resp = wp_remote_post(
			$cfg['url'],
			array(
				'timeout' => (int) $s['timeout'],
				'headers' => array(
					'x-api-key'         => $key,
					'anthropic-version' => '2023-06-01',
					'content-type'      => 'application/json',
				),
				'body'    => wp_json_encode( self::filter_body( $body, $cfg ) ),
			)
		);

		$data = self::unwrap( $resp );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		if ( isset( $data['stop_reason'] ) && 'refusal' === $data['stop_reason'] ) {
			return new WP_Error( 'beaver_translate_refusal', __( 'The model declined to translate this content.', 'beaver-translate' ) );
		}

		if ( ! empty( $data['content'] ) && is_array( $data['content'] ) ) {
			$text = '';

			foreach ( $data['content'] as $block ) {
				if ( isset( $block['type'], $block['text'] ) && 'text' === $block['type'] ) {
					$text .= $block['text'];
				}
			}

			$text = trim( $text );

			if ( '' !== $text ) {
				return $text;
			}
		}

		return new WP_Error( 'beaver_translate_empty', __( 'The provider returned no text.', 'beaver-translate' ) );
	}

	/**
	 * OpenAI-compatible chat/completions.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $cfg      Provider config.
	 * @param string $key      API key.
	 * @param string $system   System prompt.
	 * @param array  $messages Single-entry conversation.
	 * @param array  $s        Settings.
	 * @return string|WP_Error
	 */
	private static function call_openai( $cfg, $key, $system, $messages, $s ) {
		$body = array(
			'model'      => $cfg['model'],
			'messages'   => array_merge(
				array(
					array(
						'role'    => 'system',
						'content' => $system,
					),
				),
				$messages
			),
			'max_tokens' => (int) $s['max_tokens'],
		);

		$headers = array(
			'Authorization' => 'Bearer ' . $key,
			'Content-Type'  => 'application/json',
		);

		// OpenRouter asks callers to identify themselves.
		if ( 'openrouter' === $cfg['id'] ) {
			$headers['HTTP-Referer'] = home_url( '/' );
			$headers['X-Title']      = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		}

		$resp = wp_remote_post(
			$cfg['url'],
			array(
				'timeout' => (int) $s['timeout'],
				'headers' => $headers,
				'body'    => wp_json_encode( self::filter_body( $body, $cfg ) ),
			)
		);

		$data = self::unwrap( $resp );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$text = isset( $data['choices'][0]['message']['content'] ) ? $data['choices'][0]['message']['content'] : '';

		if ( '' === trim( (string) $text ) ) {
			return new WP_Error( 'beaver_translate_empty', __( 'The provider returned no text.', 'beaver-translate' ) );
		}

		return trim( (string) $text );
	}

	/**
	 * Google Gemini generateContent.
	 *
	 * @since 1.0.0
	 *
	 * @param array  $cfg      Provider config.
	 * @param string $key      API key.
	 * @param string $system   System prompt.
	 * @param array  $messages Single-entry conversation.
	 * @param array  $s        Settings.
	 * @return string|WP_Error
	 */
	private static function call_gemini( $cfg, $key, $system, $messages, $s ) {
		$contents = array();

		foreach ( $messages as $m ) {
			$contents[] = array(
				'role'  => ( 'assistant' === $m['role'] ) ? 'model' : 'user',
				'parts' => array( array( 'text' => $m['content'] ) ),
			);
		}

		$body = array(
			'system_instruction' => array( 'parts' => array( array( 'text' => $system ) ) ),
			'contents'           => $contents,
			'generationConfig'   => array( 'maxOutputTokens' => (int) $s['max_tokens'] ),
		);

		$url = str_replace( '{model}', rawurlencode( $cfg['model'] ), $cfg['url'] );

		$resp = wp_remote_post(
			$url,
			array(
				'timeout' => (int) $s['timeout'],
				'headers' => array(
					'x-goog-api-key' => $key,
					'Content-Type'   => 'application/json',
				),
				'body'    => wp_json_encode( self::filter_body( $body, $cfg ) ),
			)
		);

		$data = self::unwrap( $resp );

		if ( is_wp_error( $data ) ) {
			return $data;
		}

		$text = '';

		if ( ! empty( $data['candidates'][0]['content']['parts'] ) ) {
			foreach ( $data['candidates'][0]['content']['parts'] as $part ) {
				if ( isset( $part['text'] ) ) {
					$text .= $part['text'];
				}
			}
		}

		if ( '' === trim( $text ) ) {
			$blocked = isset( $data['promptFeedback']['blockReason'] ) ? $data['promptFeedback']['blockReason'] : '';

			if ( $blocked ) {
				return new WP_Error( 'beaver_translate_refusal', __( 'The model declined to translate this content.', 'beaver-translate' ) );
			}

			return new WP_Error( 'beaver_translate_empty', __( 'The provider returned no text.', 'beaver-translate' ) );
		}

		return trim( $text );
	}

	/**
	 * Turns an HTTP response into a decoded array, or a WP_Error carrying the
	 * provider's own message.
	 *
	 * @since 1.0.0
	 *
	 * @param array|WP_Error $resp Response from wp_remote_post().
	 * @return array|WP_Error
	 */
	private static function unwrap( $resp ) {
		if ( is_wp_error( $resp ) ) {
			return $resp;
		}

		$code = (int) wp_remote_retrieve_response_code( $resp );
		$data = json_decode( wp_remote_retrieve_body( $resp ), true );

		if ( 200 !== $code ) {
			return new WP_Error( 'beaver_translate_api', self::error_message( $data, $code ) );
		}

		if ( ! is_array( $data ) ) {
			return new WP_Error( 'beaver_translate_parse', __( 'The provider returned a response that could not be read.', 'beaver-translate' ) );
		}

		return $data;
	}

	/**
	 * Pulls a human readable message out of a provider error body.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $data Decoded body.
	 * @param int   $code HTTP status.
	 * @return string
	 */
	private static function error_message( $data, $code ) {
		if ( is_array( $data ) && isset( $data['error'] ) ) {
			if ( is_array( $data['error'] ) && isset( $data['error']['message'] ) ) {
				return (string) $data['error']['message'];
			}

			if ( is_string( $data['error'] ) ) {
				return $data['error'];
			}
		}

		if ( is_array( $data ) && isset( $data['message'] ) && is_string( $data['message'] ) ) {
			return $data['message'];
		}

		/* translators: %d: HTTP status code. */
		return sprintf( __( 'The provider returned HTTP %d.', 'beaver-translate' ), (int) $code );
	}

	/**
	 * Last chance to adjust the outgoing request body.
	 *
	 * @since 1.0.0
	 *
	 * @param array $body Request body.
	 * @param array $cfg  Provider config.
	 * @return array
	 */
	private static function filter_body( $body, $cfg ) {
		/**
		 * Filters the request body sent to the provider.
		 *
		 * @since 1.0.0
		 *
		 * @param array $body Request body.
		 * @param array $cfg  Provider config, including `id` and `api`.
		 */
		return (array) apply_filters( 'beaver_translate_request_body', $body, $cfg );
	}
}
