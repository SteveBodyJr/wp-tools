<?php
/**
 * Telling the team somewhere other than email.
 *
 * Email is where alerts go to be read tomorrow. A sales team that works from a
 * phone reads WhatsApp, and a team that works from a desk reads Slack. The same
 * alert therefore goes wherever the team already is, cut down to the one line
 * that decides whether to act: who it is, what they want, and a link.
 *
 * Every channel is optional and each one is a plain HTTP POST, so nothing here
 * depends on a service being reachable for the rest of the plugin to work.
 *
 * @package BeaverAIChat
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class BAC_Channels
 */
class BAC_Channels {

	/** WhatsApp Cloud API version this speaks. */
	const WA_VERSION = 'v21.0';

	/** How long to wait on any one service. */
	const TIMEOUT = 8;

	/**
	 * The channels this site has actually configured.
	 *
	 * @param array $s Settings.
	 * @return array
	 */
	public static function enabled( $s ) {
		$on = array();

		if ( ! empty( $s['slack_webhook'] ) ) {
			$on[] = 'slack';
		}
		if ( ! empty( $s['telegram_token'] ) && ! empty( $s['telegram_chat'] ) ) {
			$on[] = 'telegram';
		}
		if ( ! empty( $s['wa_token'] ) && ! empty( $s['wa_phone_id'] ) && ! empty( $s['wa_to'] ) ) {
			$on[] = 'whatsapp';
		}
		if ( ! empty( $s['webhook_url'] ) ) {
			$on[] = 'webhook';
		}

		return $on;
	}

	/**
	 * Push one conversation to every configured channel.
	 *
	 * @param array  $lead   Lead data from BAC_Notify::lead_data().
	 * @param array  $s      Settings.
	 * @param string $reason lead, handoff or test.
	 * @return array channel => array( ok, message )
	 */
	public static function send( $lead, $s, $reason = 'lead' ) {
		$channels = self::enabled( $s );

		if ( empty( $channels ) ) {
			return array();
		}

		/*
		 * Wait for a reply only where waiting is free. In cron and in the admin
		 * a failure is worth reporting; inside a visitor's request it would sit
		 * between them and their answer, so the post is fired and forgotten.
		 */
		$blocking = ( wp_doing_cron() || is_admin() || 'test' === $reason );
		$text     = self::text( $lead, $s, $reason );
		$results  = array();

		foreach ( $channels as $channel ) {
			$method = array( __CLASS__, $channel );

			if ( ! is_callable( $method ) ) {
				continue;
			}

			$results[ $channel ] = call_user_func( $method, $text, $lead, $s, $reason, $blocking );
		}

		/**
		 * Fires after a conversation has been pushed to the extra channels.
		 *
		 * @param array  $results Per channel outcome.
		 * @param array  $lead    Lead data.
		 * @param array  $s       Settings.
		 * @param string $reason  Why it was sent.
		 */
		do_action( 'bac_channels_sent', $results, $lead, $s, $reason );

		return $results;
	}

	/**
	 * The one line a phone shows on the lock screen.
	 *
	 * @param array  $lead   Lead data.
	 * @param array  $s      Settings.
	 * @param string $reason Why it is being sent.
	 * @return string
	 */
	public static function text( $lead, $s, $reason = 'lead' ) {
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$who  = '' !== $lead['name'] ? $lead['name'] : ( '' !== $lead['email'] ? $lead['email'] : __( 'A visitor', 'beaver-ai-chat' ) );

		if ( 'handoff' === $reason ) {
			/* translators: 1: visitor, 2: site name. */
			$head = sprintf( __( '%1$s asked for a callback (%2$s)', 'beaver-ai-chat' ), $who, $site );
		} elseif ( 'test' === $reason ) {
			/* translators: %s: site name. */
			$head = sprintf( __( 'Test alert from %s', 'beaver-ai-chat' ), $site );
		} elseif ( '' !== $lead['email'] || '' !== $lead['phone'] ) {
			/* translators: 1: visitor, 2: site name. */
			$head = sprintf( __( 'New chat lead: %1$s (%2$s)', 'beaver-ai-chat' ), $who, $site );
		} else {
			/* translators: %s: site name. */
			$head = sprintf( __( 'New chat conversation on %s', 'beaver-ai-chat' ), $site );
		}

		$lines = array( $head );

		$want = '' !== $lead['summary'] ? $lead['summary'] : $lead['asked'];
		if ( '' !== trim( $want ) ) {
			$lines[] = self::shorten( $want, 280 );
		}

		$contact = array_filter( array( $lead['email'], $lead['phone'] ) );
		if ( ! empty( $contact ) ) {
			$lines[] = implode( '  ', $contact );
		}

		$link = '' !== $lead['view'] ? $lead['view'] : $lead['admin'];
		if ( '' !== $link ) {
			$lines[] = $link;
		}

		return implode( "\n", $lines );
	}

	/* -------------------------------------------------------------- Channels */

	/**
	 * Slack, through an incoming webhook.
	 *
	 * @param string $text     Message.
	 * @param array  $lead     Lead data.
	 * @param array  $s        Settings.
	 * @param string $reason   Why.
	 * @param bool   $blocking Wait for the reply.
	 * @return array
	 */
	private static function slack( $text, $lead, $s, $reason, $blocking ) {
		return self::post(
			$s['slack_webhook'],
			array( 'text' => $text ),
			array(),
			$blocking,
			__( 'Slack', 'beaver-ai-chat' )
		);
	}

	/**
	 * Telegram, through a bot.
	 *
	 * @param string $text     Message.
	 * @param array  $lead     Lead data.
	 * @param array  $s        Settings.
	 * @param string $reason   Why.
	 * @param bool   $blocking Wait for the reply.
	 * @return array
	 */
	private static function telegram( $text, $lead, $s, $reason, $blocking ) {
		$url = 'https://api.telegram.org/bot' . rawurlencode( trim( (string) $s['telegram_token'] ) ) . '/sendMessage';

		return self::post(
			$url,
			array(
				'chat_id'                  => trim( (string) $s['telegram_chat'] ),
				'text'                     => $text,
				'disable_web_page_preview' => true,
			),
			array(),
			$blocking,
			__( 'Telegram', 'beaver-ai-chat' )
		);
	}

	/**
	 * WhatsApp, through Meta's Cloud API.
	 *
	 * A business cannot start a WhatsApp conversation with free text: outside a
	 * 24 hour window opened by the recipient, Meta only delivers an approved
	 * template. So the default sends a template with the alert as its one
	 * variable, and plain text is offered for teams who keep a window open.
	 * Template variables also cannot contain line breaks, hence the flattening.
	 *
	 * @param string $text     Message.
	 * @param array  $lead     Lead data.
	 * @param array  $s        Settings.
	 * @param string $reason   Why.
	 * @param bool   $blocking Wait for the reply.
	 * @return array
	 */
	private static function whatsapp( $text, $lead, $s, $reason, $blocking ) {
		$url = 'https://graph.facebook.com/' . self::WA_VERSION . '/' . rawurlencode( trim( (string) $s['wa_phone_id'] ) ) . '/messages';

		$headers = array(
			'Authorization' => 'Bearer ' . trim( (string) $s['wa_token'] ),
			'Content-Type'  => 'application/json',
		);

		$flat    = trim( preg_replace( '/\s*\n\s*/', ' — ', $text ) );
		$flat    = preg_replace( '/ {2,}/', ' ', $flat );
		$results = array();

		foreach ( self::numbers( $s['wa_to'] ) as $to ) {
			if ( 'text' === $s['wa_api_mode'] ) {
				$body = array(
					'messaging_product' => 'whatsapp',
					'to'                => $to,
					'type'              => 'text',
					'text'              => array(
						'body'        => $text,
						'preview_url' => false,
					),
				);
			} else {
				$body = array(
					'messaging_product' => 'whatsapp',
					'to'                => $to,
					'type'              => 'template',
					'template'          => array(
						'name'       => trim( (string) $s['wa_template'] ),
						'language'   => array( 'code' => trim( (string) $s['wa_language'] ) ),
						'components' => array(
							array(
								'type'       => 'body',
								'parameters' => array(
									array(
										'type' => 'text',
										'text' => self::shorten( $flat, 900 ),
									),
								),
							),
						),
					),
				);
			}

			$results[] = self::post( $url, $body, $headers, $blocking, __( 'WhatsApp', 'beaver-ai-chat' ) );
		}

		return self::combine( $results, __( 'WhatsApp', 'beaver-ai-chat' ) );
	}

	/**
	 * Anywhere else, through a plain JSON POST.
	 *
	 * The full record goes out rather than the one line, because whatever is on
	 * the other end is code, not a person. When a secret is set the body is
	 * signed so the receiver can tell it really came from this site.
	 *
	 * @param string $text     Message.
	 * @param array  $lead     Lead data.
	 * @param array  $s        Settings.
	 * @param string $reason   Why.
	 * @param bool   $blocking Wait for the reply.
	 * @return array
	 */
	private static function webhook( $text, $lead, $s, $reason, $blocking ) {
		$payload = array(
			'event'    => 'bac_lead',
			'reason'   => $reason,
			'site'     => home_url( '/' ),
			'lead'     => array(
				'id'       => $lead['id'],
				'name'     => $lead['name'],
				'email'    => $lead['email'],
				'phone'    => $lead['phone'],
				'interest' => $lead['interest'],
				'summary'  => $lead['summary'],
				'asked'    => $lead['asked'],
				'messages' => $lead['turns'],
				'page'     => $lead['page'],
				'callback' => $lead['callback'],
				'admin'    => $lead['admin'],
				'view'     => $lead['view'],
			),
			'text'     => $text,
			'sent_at'  => gmdate( 'c' ),
		);

		/**
		 * Filter the JSON sent to the outgoing webhook.
		 *
		 * @param array $payload Payload.
		 * @param array $lead    Lead data.
		 * @param array $s       Settings.
		 */
		$payload = (array) apply_filters( 'bac_webhook_payload', $payload, $lead, $s );

		$json    = wp_json_encode( $payload );
		$headers = array( 'Content-Type' => 'application/json' );
		$secret  = trim( (string) $s['webhook_secret'] );

		if ( '' !== $secret ) {
			$headers['X-BAC-Signature'] = 'sha256=' . hash_hmac( 'sha256', (string) $json, $secret );
		}

		return self::post( $s['webhook_url'], $payload, $headers, $blocking, __( 'Webhook', 'beaver-ai-chat' ), $json );
	}

	/* ----------------------------------------------------------------- Plumbing */

	/**
	 * POST JSON somewhere and say plainly whether it worked.
	 *
	 * @param string $url      Endpoint.
	 * @param array  $body     Body, encoded here unless $raw is given.
	 * @param array  $headers  Extra headers.
	 * @param bool   $blocking Wait for the reply.
	 * @param string $label    Channel name for messages.
	 * @param string $raw      Pre-encoded body, when the exact bytes matter.
	 * @return array array( ok, message )
	 */
	private static function post( $url, $body, $headers, $blocking, $label, $raw = '' ) {
		$url = trim( (string) $url );

		if ( '' === $url || ! wp_http_validate_url( $url ) ) {
			/* translators: %s: channel name. */
			return self::result( false, sprintf( __( '%s: that does not look like a valid address.', 'beaver-ai-chat' ), $label ) );
		}

		$response = wp_remote_post(
			$url,
			array(
				'timeout'  => self::TIMEOUT,
				'blocking' => (bool) $blocking,
				'headers'  => array_merge( array( 'Content-Type' => 'application/json' ), $headers ),
				'body'     => '' !== $raw ? $raw : wp_json_encode( $body ),
			)
		);

		if ( ! $blocking ) {
			/* translators: %s: channel name. */
			return self::result( true, sprintf( __( '%s: sent.', 'beaver-ai-chat' ), $label ) );
		}

		if ( is_wp_error( $response ) ) {
			return self::result( false, $label . ': ' . $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 300 ) {
			$reason = self::reason( wp_remote_retrieve_body( $response ) );

			return self::result(
				false,
				/* translators: 1: channel name, 2: HTTP status, 3: the service's own message. */
				trim( sprintf( __( '%1$s: refused it (HTTP %2$d). %3$s', 'beaver-ai-chat' ), $label, $code, $reason ) )
			);
		}

		/* translators: %s: channel name. */
		return self::result( true, sprintf( __( '%s: delivered.', 'beaver-ai-chat' ), $label ) );
	}

	/**
	 * Pull the service's own explanation out of an error body.
	 *
	 * @param string $body Response body.
	 * @return string
	 */
	private static function reason( $body ) {
		$data = json_decode( (string) $body, true );

		if ( is_array( $data ) ) {
			foreach ( array( 'error', 'description', 'message' ) as $key ) {
				if ( isset( $data[ $key ] ) ) {
					if ( is_string( $data[ $key ] ) ) {
						return $data[ $key ];
					}
					if ( is_array( $data[ $key ] ) && isset( $data[ $key ]['message'] ) ) {
						return (string) $data[ $key ]['message'];
					}
				}
			}
		}

		return mb_substr( trim( wp_strip_all_tags( (string) $body ) ), 0, 200 );
	}

	/**
	 * One outcome.
	 *
	 * @param bool   $ok      Whether it worked.
	 * @param string $message What to say about it.
	 * @return array
	 */
	private static function result( $ok, $message ) {
		return array(
			'ok'      => (bool) $ok,
			'message' => $message,
		);
	}

	/**
	 * Fold several sends into one outcome, since one WhatsApp alert can go to
	 * several numbers.
	 *
	 * @param array  $results Individual results.
	 * @param string $label   Channel name.
	 * @return array
	 */
	private static function combine( $results, $label ) {
		if ( empty( $results ) ) {
			/* translators: %s: channel name. */
			return self::result( false, sprintf( __( '%s: no valid recipient.', 'beaver-ai-chat' ), $label ) );
		}

		foreach ( $results as $result ) {
			if ( empty( $result['ok'] ) ) {
				return $result; // The first failure is the one worth reading.
			}
		}

		return $results[0];
	}

	/**
	 * Split and tidy a list of phone numbers.
	 *
	 * Separated on commas, semicolons and line breaks only, never on spaces:
	 * people write "+255 700 000 000", and splitting on the spaces inside a
	 * number turns one good number into four unusable fragments. Anything left
	 * outside the range a real international number can be is dropped.
	 *
	 * @param string $raw Raw value.
	 * @return array
	 */
	public static function numbers( $raw ) {
		$out = array();

		foreach ( preg_split( '/[,;\r\n]+/', (string) $raw ) as $one ) {
			$one = preg_replace( '/[^\d]/', '', (string) $one );

			if ( strlen( $one ) >= 8 && strlen( $one ) <= 15 ) {
				$out[] = $one;
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Trim to length on a word boundary.
	 *
	 * @param string $text  Text.
	 * @param int    $chars Maximum characters.
	 * @return string
	 */
	private static function shorten( $text, $chars ) {
		$text = trim( (string) $text );

		if ( mb_strlen( $text ) <= $chars ) {
			return $text;
		}

		$cut   = mb_substr( $text, 0, $chars );
		$space = mb_strrpos( $cut, ' ' );

		if ( false !== $space && $space > (int) ( $chars * 0.6 ) ) {
			$cut = mb_substr( $cut, 0, $space );
		}

		return rtrim( $cut, " ,.;:" ) . '…';
	}
}
