<?php
/**
 * Provider dispatcher. Everything here runs server side, and the API key never
 * leaves this file's request.
 *
 * Three wire formats cover every supported provider:
 *   anthropic - Claude's Messages API
 *   openai    - the chat/completions shape spoken by OpenAI, DeepSeek, Groq,
 *               OpenRouter and most self-hosted runtimes
 *   gemini    - Google's generateContent
 *
 * @package BeaverAIChat
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class BAC_Provider
 */
class BAC_Provider {

	/** Where the last provider failure is recorded, for the admin to read. */
	const ERROR_OPTION = 'bac_last_error';

	/**
	 * Send a conversation and return the assistant's reply text.
	 *
	 * @param string $system   System prompt.
	 * @param array  $messages List of array( 'role' => user|assistant, 'content' => string ).
	 * @param array  $s        Settings (read fresh when omitted).
	 * @return string|WP_Error
	 */
	public static function chat( $system, $messages, $s = null ) {
		$s   = null === $s ? BAC_Settings::get() : $s;
		$key = BAC_Settings::api_key();

		if ( '' === $key ) {
			return self::fail( new WP_Error( 'bac_no_key', __( 'No API key is configured.', 'beaver-ai-chat' ) ), $s );
		}

		$messages = self::normalize( $messages );
		if ( empty( $messages ) ) {
			return new WP_Error( 'bac_no_messages', __( 'There is no message to send.', 'beaver-ai-chat' ) );
		}

		$cfg = BAC_Settings::provider_config( $s );
		if ( '' === trim( (string) $cfg['url'] ) ) {
			return self::fail( new WP_Error( 'bac_no_endpoint', __( 'No endpoint is configured for this provider.', 'beaver-ai-chat' ) ), $s, $cfg );
		}
		if ( '' === trim( (string) $cfg['model'] ) ) {
			return self::fail( new WP_Error( 'bac_no_model', __( 'No model is configured. Enter a model name in the settings.', 'beaver-ai-chat' ) ), $s, $cfg );
		}

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
	 * Remember why the last call failed, so the admin can see the provider's own
	 * words instead of only the friendly message the visitor gets. Stored server
	 * side and never sent to the browser.
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
			error_log( '[beaver-ai-chat] ' . $error->get_error_code() . ': ' . $error->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		return $error;
	}

	/**
	 * Clear the recorded failure once a call succeeds, so a stale error never
	 * sits in the admin looking like a live problem.
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
	 * @return array|null
	 */
	public static function last_error() {
		$error = get_option( self::ERROR_OPTION, null );

		return is_array( $error ) && ! empty( $error['message'] ) ? $error : null;
	}

	/**
	 * Anthropic Messages API.
	 *
	 * Notes that matter for correctness on current Claude models:
	 *  - temperature, top_p and top_k are rejected, so they are never sent.
	 *  - max_tokens caps thinking and reply text together. Thinking is on by
	 *    default on the newest models, so a chat widget that wants short, fast
	 *    replies should leave reasoning off; when it is switched on we raise the
	 *    token ceiling so replies cannot truncate mid sentence.
	 *
	 * @param array  $cfg      Provider config.
	 * @param string $key      API key.
	 * @param string $system   System prompt.
	 * @param array  $messages Conversation.
	 * @param array  $s        Settings.
	 * @return string|WP_Error
	 */
	private static function call_anthropic( $cfg, $key, $system, $messages, $s ) {
		$max_tokens = (int) $s['max_tokens'];
		$body       = array(
			'model'    => $cfg['model'],
			'system'   => $system,
			'messages' => $messages,
		);

		if ( 'off' === $s['claude_thinking'] ) {
			$body['thinking'] = array( 'type' => 'disabled' );
		} elseif ( 'adaptive' === $s['claude_thinking'] ) {
			$body['thinking'] = array( 'type' => 'adaptive' );
			$max_tokens       = max( $max_tokens, 4096 ); // Reasoning shares this budget.
		}

		$body['max_tokens'] = $max_tokens;

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

		self::meter( $data, $cfg );

		// Safety classifiers can decline with a 200 and no content, so check
		// stop_reason before reading the content blocks.
		if ( isset( $data['stop_reason'] ) && 'refusal' === $data['stop_reason'] ) {
			return new WP_Error( 'bac_refusal', __( 'The model declined to answer that request.', 'beaver-ai-chat' ) );
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

		return new WP_Error( 'bac_empty', __( 'The assistant returned no text.', 'beaver-ai-chat' ) );
	}

	/**
	 * OpenAI-compatible chat/completions.
	 *
	 * @param array  $cfg      Provider config.
	 * @param string $key      API key.
	 * @param string $system   System prompt.
	 * @param array  $messages Conversation.
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

		if ( '' !== $s['temperature'] ) {
			$body['temperature'] = (float) $s['temperature'];
		}

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

		self::meter( $data, $cfg );

		$text = isset( $data['choices'][0]['message']['content'] ) ? $data['choices'][0]['message']['content'] : '';
		if ( '' === trim( (string) $text ) ) {
			return new WP_Error( 'bac_empty', __( 'The assistant returned no text.', 'beaver-ai-chat' ) );
		}

		return trim( (string) $text );
	}

	/**
	 * Google Gemini generateContent.
	 *
	 * @param array  $cfg      Provider config.
	 * @param string $key      API key.
	 * @param string $system   System prompt.
	 * @param array  $messages Conversation.
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

		$generation = array( 'maxOutputTokens' => (int) $s['max_tokens'] );
		if ( '' !== $s['temperature'] ) {
			$generation['temperature'] = (float) $s['temperature'];
		}

		$body = array(
			'system_instruction' => array( 'parts' => array( array( 'text' => $system ) ) ),
			'contents'           => $contents,
			'generationConfig'   => $generation,
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

		self::meter( $data, $cfg );

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
				return new WP_Error( 'bac_refusal', __( 'The model declined to answer that request.', 'beaver-ai-chat' ) );
			}
			return new WP_Error( 'bac_empty', __( 'The assistant returned no text.', 'beaver-ai-chat' ) );
		}

		return trim( $text );
	}

	/**
	 * Record what a successful call cost.
	 *
	 * Every provider reports its own token counts in its own shape, and some
	 * omit them; nothing is recorded when nothing was reported, so the totals
	 * never contain invented zeroes.
	 *
	 * @param array $data Decoded response body.
	 * @param array $cfg  Provider config.
	 */
	private static function meter( $data, $cfg ) {
		list( $in, $out ) = BAC_Usage::read( $data, $cfg['api'] );

		// Providers echo the model they actually served, which can differ from
		// the alias that was asked for. Price what ran.
		$model = isset( $data['model'] ) && is_string( $data['model'] ) ? $data['model'] : $cfg['model'];

		BAC_Usage::record( $model, $in, $out );
	}

	/**
	 * Turn an HTTP response into a decoded array, or a WP_Error carrying the
	 * provider's own message so the admin test button can show something useful.
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
			return new WP_Error( 'bac_api', self::error_message( $data, $code ) );
		}
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'bac_parse', __( 'The provider returned a response that could not be read.', 'beaver-ai-chat' ) );
		}

		return $data;
	}

	/**
	 * Pull a human readable message out of a provider error body.
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
		return sprintf( __( 'The provider returned HTTP %d.', 'beaver-ai-chat' ), (int) $code );
	}

	/**
	 * Last chance to adjust the outgoing request body.
	 *
	 * @param array $body Request body.
	 * @param array $cfg  Provider config.
	 * @return array
	 */
	private static function filter_body( $body, $cfg ) {
		/**
		 * Filter the request body sent to the provider.
		 *
		 * @param array $body Request body.
		 * @param array $cfg  Provider config, including `id` and `api`.
		 */
		return (array) apply_filters( 'bac_request_body', $body, $cfg );
	}

	/**
	 * Make a message list valid for every provider: drop empties, drop leading
	 * assistant turns, and merge consecutive turns from the same speaker.
	 *
	 * @param array $messages Raw messages.
	 * @return array
	 */
	public static function normalize( $messages ) {
		$clean = array();

		foreach ( (array) $messages as $m ) {
			$role = ( isset( $m['role'] ) && 'assistant' === $m['role'] ) ? 'assistant' : 'user';
			$text = isset( $m['content'] ) ? trim( (string) $m['content'] ) : '';
			if ( '' === $text ) {
				continue;
			}
			$clean[] = array(
				'role'    => $role,
				'content' => $text,
			);
		}

		while ( ! empty( $clean ) && 'assistant' === $clean[0]['role'] ) {
			array_shift( $clean );
		}

		$merged = array();
		foreach ( $clean as $m ) {
			$last = count( $merged ) - 1;
			if ( $last >= 0 && $merged[ $last ]['role'] === $m['role'] ) {
				$merged[ $last ]['content'] .= "\n\n" . $m['content'];
			} else {
				$merged[] = $m;
			}
		}

		return $merged;
	}
}
