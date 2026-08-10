<?php
/**
 * REST endpoints the widget talks to.
 *
 *   Browser  --POST-->  /beaver-ai-chat/v1/chat  -->  AI provider (server side)
 *
 * The browser never sees the API key. It only ever talks to this site.
 *
 * @package BeaverAIChat
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class BAC_Rest
 */
class BAC_Rest {

	const NAMESPACE_V1 = 'beaver-ai-chat/v1';

	/** Register routes. */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/** Route definitions. */
	public static function routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/chat',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'chat' ),
				// Public by design: this is a visitor facing chat. Requests are
				// checked against a rotating widget token and rate limited below.
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/contact',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'contact' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * A user independent daily widget token.
	 *
	 * A WP nonce is bound to the logged in user and trips the REST cookie auth
	 * gate, which 403s anonymous visitors. This works identically for everyone,
	 * rotates daily, and together with the rate limiter is a light deterrent for
	 * drive-by bots. Yesterday's token is accepted so a page loaded just before
	 * midnight keeps working.
	 *
	 * @param int $day_offset 0 for today, -1 for yesterday.
	 * @return string
	 */
	public static function token( $day_offset = 0 ) {
		return substr( wp_hash( 'bac_widget|' . gmdate( 'Y-m-d', time() + $day_offset * DAY_IN_SECONDS ) ), 0, 20 );
	}

	/**
	 * Whether a submitted token is one we issued.
	 *
	 * @param string $token Token from the request.
	 * @return bool
	 */
	private static function token_ok( $token ) {
		$token = (string) $token;
		return hash_equals( self::token( 0 ), $token ) || hash_equals( self::token( -1 ), $token );
	}

	/**
	 * Client IP, lightly sanitised. Used only as a rate limit key.
	 *
	 * @return string
	 */
	private static function ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0.0.0.0';
		return preg_replace( '/[^0-9a-fA-F:.]/', '', $ip );
	}

	/**
	 * Per IP rate limit, counted in fixed one minute windows.
	 *
	 * The minute is part of the key rather than the expiry, and that is the
	 * whole point. Counting into a single transient and refreshing its expiry on
	 * every message means the count never resets for anyone who keeps talking:
	 * each message pushes the sixty seconds out again, so a visitor chatting at
	 * a steady one message every fifty seconds — well inside any sane per minute
	 * limit — still accumulates until they reach it, and the assistant then
	 * refuses them in the middle of the conversation. Keying on the minute gives
	 * every minute a counter of its own, which is what "per minute" means.
	 *
	 * The row is kept for two minutes so it survives the whole of the minute it
	 * belongs to, then expires by itself.
	 *
	 * @param int $limit Messages allowed per minute.
	 * @return bool True when the caller is over the limit.
	 */
	private static function rate_limited( $limit ) {
		$limit = max( 1, (int) $limit );
		$key   = 'bac_rl_' . md5( self::ip() ) . '_' . floor( time() / MINUTE_IN_SECONDS );
		$count = (int) get_transient( $key );

		if ( $count >= $limit ) {
			return true;
		}

		set_transient( $key, $count + 1, 2 * MINUTE_IN_SECONDS );
		return false;
	}

	/**
	 * Handle one chat turn.
	 *
	 * Always answers HTTP 200 with a friendly `reply`, so the widget never shows
	 * a raw error to a visitor. Real errors go to the log when WP_DEBUG is on.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function chat( WP_REST_Request $request ) {
		$s = BAC_Settings::get();

		if ( empty( $s['enabled'] ) ) {
			return self::reply( __( 'The assistant is offline right now. Please contact us directly and we will help.', 'beaver-ai-chat' ) );
		}

		$body = $request->get_json_params();
		$body = is_array( $body ) ? $body : array();

		if ( ! self::token_ok( isset( $body['token'] ) ? $body['token'] : '' ) ) {
			return self::reply( __( 'Your session just refreshed. Please reload the page and ask again.', 'beaver-ai-chat' ) );
		}

		if ( '' === BAC_Settings::api_key() ) {
			return self::reply( __( 'I am not fully set up yet. Please contact our team directly and they will help you right away.', 'beaver-ai-chat' ) );
		}

		if ( self::rate_limited( (int) $s['rate_limit'] ) ) {
			return self::reply( __( 'That is a lot of messages at once. Give me a moment, or contact our team directly.', 'beaver-ai-chat' ) );
		}

		// The spend ceiling is checked before the call, not after: the point of
		// it is that the bill stops, so the visitor gets the contact details
		// instead of one more paid-for reply.
		if ( BAC_Usage::cap_reached( $s ) ) {
			BAC_Usage::announce_cap( $s );
			return self::reply( self::cap_message( $s ) );
		}

		$history  = ( isset( $body['messages'] ) && is_array( $body['messages'] ) ) ? $body['messages'] : array();
		$messages = array();

		foreach ( array_slice( $history, -1 * (int) $s['history_turns'] ) as $m ) {
			$role = ( isset( $m['role'] ) && 'assistant' === $m['role'] ) ? 'assistant' : 'user';
			$text = isset( $m['content'] ) ? trim( wp_strip_all_tags( (string) $m['content'] ) ) : '';

			if ( '' === $text ) {
				continue;
			}

			$messages[] = array(
				'role'    => $role,
				'content' => mb_substr( $text, 0, (int) $s['msg_max_chars'] ),
			);
		}

		if ( empty( $messages ) ) {
			return self::reply( __( 'What would you like to know?', 'beaver-ai-chat' ) );
		}

		// The last thing the visitor said decides which parts of the site are
		// worth sending with this turn.
		$last  = end( $messages );
		$query = ( $last && 'user' === $last['role'] ) ? $last['content'] : '';

		$reply = BAC_Provider::chat( BAC_Prompt::build( $s, $query ), $messages, $s );

		if ( is_wp_error( $reply ) ) {
			self::log( $reply->get_error_message() );
			return self::reply( __( 'I hit a brief snag just then. Please try again in a moment, or contact our team and they will help right away.', 'beaver-ai-chat' ) );
		}

		// Lead capture is best effort and must never break the visitor's reply.
		$sid = isset( $body['sid'] ) ? (string) $body['sid'] : '';
		if ( '' !== $sid ) {
			try {
				BAC_Leads::store(
					$sid,
					$messages,
					$reply,
					$s,
					isset( $body['page'] ) ? esc_url_raw( (string) $body['page'] ) : ''
				);
			} catch ( \Throwable $e ) {
				self::log( 'lead store: ' . $e->getMessage() );
			}
		}

		return self::reply( $reply );
	}

	/**
	 * "Ask the team to contact me": mark the conversation as a request for a
	 * human, notify the team, and let other plugins file it wherever they like.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function contact( WP_REST_Request $request ) {
		$s = BAC_Settings::get();

		if ( empty( $s['enabled'] ) || empty( $s['cta_enabled'] ) ) {
			return new WP_REST_Response(
				array(
					'ok'    => false,
					'reply' => __( 'That option is not available right now.', 'beaver-ai-chat' ),
				),
				200
			);
		}

		$body = $request->get_json_params();
		$body = is_array( $body ) ? $body : array();

		if ( ! self::token_ok( isset( $body['token'] ) ? $body['token'] : '' ) ) {
			return new WP_REST_Response(
				array(
					'ok'    => false,
					'reply' => __( 'Your session just refreshed. Please reload the page.', 'beaver-ai-chat' ),
				),
				200
			);
		}

		$sid     = isset( $body['sid'] ) ? preg_replace( '/[^A-Za-z0-9\-]/', '', (string) $body['sid'] ) : '';
		$lead_id = $sid ? BAC_Leads::find_by_sid( $sid ) : 0;
		$email   = $lead_id ? (string) get_post_meta( $lead_id, '_bac_email', true ) : '';

		if ( ! $lead_id || '' === $email || ! is_email( $email ) ) {
			return new WP_REST_Response(
				array(
					'ok'         => false,
					'need_email' => true,
					'reply'      => __( 'Happy to arrange that. What is the best email for the team to reach you on?', 'beaver-ai-chat' ),
				),
				200
			);
		}

		if ( get_post_meta( $lead_id, '_bac_contact_requested', true ) ) {
			return new WP_REST_Response(
				array(
					'ok'    => true,
					'done'  => true,
					'reply' => __( 'You are all set, the team already has your request and will be in touch shortly. Anything else I can help with?', 'beaver-ai-chat' ),
				),
				200
			);
		}

		update_post_meta( $lead_id, '_bac_contact_requested', current_time( 'mysql' ) );

		/**
		 * Fires when a visitor asks to be contacted by a human. Use this to push
		 * the lead into a CRM, a form plugin's inbox, or anywhere else.
		 *
		 * @param int   $lead_id Lead post ID.
		 * @param array $s       Settings.
		 */
		do_action( 'bac_contact_requested', $lead_id, $s );

		// A callback request is not a notification, it is an errand. It goes out
		// now whatever the alert timing says, even if this conversation has
		// already been reported once.
		if ( ! empty( $s['notify_enabled'] ) ) {
			BAC_Notify::send_handoff( $lead_id, $s );
		}

		// Only greet them by name once we actually know it; the conversation
		// title may still be their opening question.
		$name  = (string) get_post_meta( $lead_id, '_bac_name', true );
		$first = '' !== trim( $name ) ? trim( explode( ' ', trim( $name ) )[0] ) : '';

		$reply = ( '' !== $first )
			/* translators: %s: visitor first name. */
			? sprintf( __( 'Thank you, %s! I have passed your details and notes to our team and they will be in touch shortly.', 'beaver-ai-chat' ), $first )
			: __( 'Thank you! I have passed your details and notes to our team and they will be in touch shortly.', 'beaver-ai-chat' );

		return new WP_REST_Response(
			array(
				'ok'    => true,
				'done'  => true,
				'reply' => $reply . ' ' . __( 'Is there anything else I can help you with in the meantime?', 'beaver-ai-chat' ),
			),
			200
		);
	}

	/**
	 * What a visitor sees once the monthly ceiling is reached. Never mentions
	 * money or limits: that is the site's business, not theirs.
	 *
	 * @param array $s Settings.
	 * @return string
	 */
	private static function cap_message( $s ) {
		$where = array();

		if ( '' !== trim( (string) $s['contact_email'] ) ) {
			$where[] = $s['contact_email'];
		}
		if ( '' !== trim( (string) $s['phone'] ) ) {
			$where[] = $s['phone'];
		}

		if ( empty( $where ) ) {
			return __( 'I am not available right now, but our team still is. Please get in touch and they will help you straight away.', 'beaver-ai-chat' );
		}

		return sprintf(
			/* translators: %s: contact details, for example an email address. */
			__( 'I am not available right now, but our team still is. Reach them on %s and they will help you straight away.', 'beaver-ai-chat' ),
			implode( ' ' . __( 'or', 'beaver-ai-chat' ) . ' ', $where )
		);
	}

	/**
	 * Standard reply envelope.
	 *
	 * @param string $text Reply text.
	 * @return WP_REST_Response
	 */
	private static function reply( $text ) {
		return new WP_REST_Response( array( 'reply' => $text ), 200 );
	}

	/**
	 * Log an internal error when debugging is on.
	 *
	 * @param string $message Message.
	 */
	private static function log( $message ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[beaver-ai-chat] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
