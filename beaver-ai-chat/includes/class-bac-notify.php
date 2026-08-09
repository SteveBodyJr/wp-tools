<?php
/**
 * Team alerts: what is emailed about a conversation, when it is sent, and the
 * read-only link a recipient can open.
 *
 * The hard part of a chat alert is not the email, it is the timing. A chat is
 * written to the database on the visitor's first message and keeps changing for
 * as long as they keep typing, so an email sent the moment a lead appears
 * describes a conversation that has barely started: no summary, often no name,
 * and no idea what the visitor actually wanted.
 *
 * So the default is to wait for the chat to go quiet. Every new message pushes
 * the alert back, and it is only sent once the visitor has stopped, by which
 * point the AI summary has landed and the email can say what they asked for in
 * one line. One complete email per conversation instead of a stream of
 * fragments.
 *
 *   instant  send during the turn that qualifies, with whatever is known then
 *   settled  wait for N quiet minutes, fill in the summary, then send (default)
 *   digest   stay silent and send one roundup every few hours or once a day
 *
 * A visitor pressing "Ask the team to contact me" always overrides the timing
 * and sends immediately: that one is not a notification, it is a request.
 *
 * @package BeaverAIChat
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class BAC_Notify
 */
class BAC_Notify {

	/** Single event: send the alert for one conversation. */
	const CRON_LEAD = 'bac_notify_lead';

	/** Recurring event: the roundup. */
	const CRON_DIGEST = 'bac_notify_digest';

	/** Option holding the timestamp of the last roundup. */
	const LAST_DIGEST = 'bac_digest_last';

	/** Meta flag written once an alert has gone out for a conversation. */
	const SENT_META = '_bac_notified';

	/** Wire up hooks. */
	public static function init() {
		add_action( self::CRON_LEAD, array( __CLASS__, 'run_scheduled' ), 10, 1 );
		add_action( self::CRON_DIGEST, array( __CLASS__, 'run_digest' ) );
		add_action( 'init', array( __CLASS__, 'sync_schedule' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render_view' ) );
	}

	/* --------------------------------------------------------------- Decisions */

	/**
	 * Called after every stored turn. Decides whether this conversation should
	 * produce an email, and whether now is the moment.
	 *
	 * Runs inside the visitor's own request, so it must stay cheap: no model
	 * calls, and no work at all in the common case where an alert has already
	 * gone out or the chat has not earned one yet.
	 *
	 * @param int   $lead_id Lead post ID.
	 * @param array $s       Settings.
	 */
	public static function after_turn( $lead_id, $s ) {
		if ( ! $lead_id || empty( $s['notify_enabled'] ) ) {
			return;
		}
		if ( self::already_sent( $lead_id ) ) {
			return;
		}

		$timing = isset( $s['notify_timing'] ) ? $s['notify_timing'] : 'settled';

		// The roundup sweeps up everything on its own schedule.
		if ( 'digest' === $timing ) {
			return;
		}

		if ( 'instant' === $timing ) {
			if ( self::qualifies( $lead_id, $s ) ) {
				self::send( $lead_id, $s, 'lead' );
			}
			return;
		}

		/*
		 * Settled: push the alert back on every message. Only when the visitor
		 * stops does the event survive long enough to fire, so the team gets one
		 * email describing a finished conversation rather than five describing
		 * the same one growing.
		 *
		 * The event is scheduled even when the conversation does not qualify yet,
		 * because "yet" is the point: a visitor who leaves their email on the
		 * fourth message should still be reported.
		 */
		self::reschedule( $lead_id, $s );
	}

	/**
	 * Whether a conversation has earned an email.
	 *
	 * @param int   $lead_id Lead post ID.
	 * @param array $s       Settings.
	 * @return bool
	 */
	public static function qualifies( $lead_id, $s ) {
		$turns = (int) get_post_meta( $lead_id, '_bac_turns', true );
		$email = (string) get_post_meta( $lead_id, '_bac_email', true );
		$phone = (string) get_post_meta( $lead_id, '_bac_phone', true );

		$ok = true;

		// Nobody wants an email because someone typed "hi" and left.
		if ( $turns < max( 1, (int) $s['notify_min_turns'] ) ) {
			$ok = false;
		}

		if ( 'contact' === $s['notify_when'] && '' === $email && '' === $phone ) {
			$ok = false;
		}

		/**
		 * Filter whether this conversation should be emailed about.
		 *
		 * @param bool  $ok      Decision so far.
		 * @param int   $lead_id Lead post ID.
		 * @param array $s       Settings.
		 */
		return (bool) apply_filters( 'bac_should_notify', $ok, $lead_id, $s );
	}

	/**
	 * Whether an alert already went out for this conversation.
	 *
	 * @param int $lead_id Lead post ID.
	 * @return bool
	 */
	private static function already_sent( $lead_id ) {
		return '' !== (string) get_post_meta( $lead_id, self::SENT_META, true );
	}

	/* --------------------------------------------------------------- Scheduling */

	/**
	 * (Re)arm the quiet-window event for one conversation.
	 *
	 * @param int   $lead_id Lead post ID.
	 * @param array $s       Settings.
	 */
	private static function reschedule( $lead_id, $s ) {
		$args = array( (int) $lead_id );
		$when = time() + max( 1, (int) $s['notify_delay'] ) * MINUTE_IN_SECONDS;

		self::unschedule( $lead_id );
		wp_schedule_single_event( $when, self::CRON_LEAD, $args );
	}

	/**
	 * Drop any pending event for one conversation.
	 *
	 * @param int $lead_id Lead post ID.
	 */
	private static function unschedule( $lead_id ) {
		$args = array( (int) $lead_id );
		$next = wp_next_scheduled( self::CRON_LEAD, $args );

		if ( $next ) {
			wp_unschedule_event( $next, self::CRON_LEAD, $args );
		}
	}

	/**
	 * The quiet window elapsed. Fill in anything missing, then send.
	 *
	 * This runs outside the visitor's request, which is what makes it safe to
	 * spend a model call here on a summary the email would otherwise go without.
	 *
	 * @param int $lead_id Lead post ID.
	 */
	public static function run_scheduled( $lead_id ) {
		$lead_id = (int) $lead_id;
		$s       = BAC_Settings::get();

		if ( empty( $s['notify_enabled'] ) || self::already_sent( $lead_id ) ) {
			return;
		}

		$post = get_post( $lead_id );
		if ( ! $post || BAC_LEAD_CPT !== $post->post_type ) {
			return;
		}

		// The visitor started typing again between the event firing and now.
		if ( ( time() - (int) get_post_modified_time( 'U', true, $lead_id ) ) < 30 ) {
			self::reschedule( $lead_id, $s );
			return;
		}

		if ( ! self::qualifies( $lead_id, $s ) ) {
			return;
		}

		self::ensure_summary( $lead_id, $s );
		self::send( $lead_id, $s, 'lead' );
	}

	/**
	 * Make sure the email has something to say about what the visitor wanted.
	 *
	 * The background enrichment normally has this covered, but it skips short
	 * chats and refreshes only every third turn, so a conversation can settle
	 * with the summary still missing. Cron context, so the call costs the
	 * visitor nothing.
	 *
	 * @param int   $lead_id Lead post ID.
	 * @param array $s       Settings.
	 */
	private static function ensure_summary( $lead_id, $s ) {
		if ( empty( $s['lead_ai_summary'] ) || '' === BAC_Settings::api_key() ) {
			return;
		}

		$summary  = (string) get_post_meta( $lead_id, '_bac_summary', true );
		$interest = (string) get_post_meta( $lead_id, '_bac_interest', true );

		// A summary that is only ever the opening question means the model has
		// not looked at this conversation yet.
		if ( '' !== $interest && '' !== $summary ) {
			return;
		}

		BAC_Leads::enrich( $lead_id );
	}

	/**
	 * Keep the roundup event in step with the settings, and stop it dead when
	 * the site switches back to per-conversation alerts.
	 */
	public static function sync_schedule() {
		$s   = BAC_Settings::get();
		$on  = ! empty( $s['notify_enabled'] ) && 'digest' === $s['notify_timing'];
		$has = wp_next_scheduled( self::CRON_DIGEST );

		if ( $on && ! $has ) {
			// Start the clock now, so switching the roundup on does not
			// immediately email a day of conversations nobody asked about.
			update_option( self::LAST_DIGEST, time(), false );
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::CRON_DIGEST );
		} elseif ( ! $on && $has ) {
			wp_unschedule_event( $has, self::CRON_DIGEST );
		}
	}

	/* -------------------------------------------------------------- Single email */

	/**
	 * Send the alert for one conversation and mark it as reported.
	 *
	 * @param int    $lead_id Lead post ID.
	 * @param array  $s       Settings.
	 * @param string $reason  'lead', 'handoff' or 'test'.
	 * @return bool Whether the mailer accepted it.
	 */
	public static function send( $lead_id, $s, $reason = 'lead' ) {
		$to = self::recipients( $s );
		if ( empty( $to ) ) {
			return false;
		}

		$lead = self::lead_data( $lead_id, $s );
		if ( ! $lead ) {
			return false;
		}

		/*
		 * Mark it before sending, not after. Two requests can reach this at the
		 * same moment, and a duplicate alert is worse than a missing one. It
		 * also means a mailer that fails is not retried on every later turn: the
		 * reason is recorded on the conversation instead.
		 */
		self::unschedule( $lead_id );
		update_post_meta( $lead_id, self::SENT_META, current_time( 'mysql' ) );
		update_post_meta( $lead_id, '_bac_notify_reason', $reason );
		delete_post_meta( $lead_id, '_bac_notify_error' );

		// Slack, WhatsApp and the rest go out alongside the email, not instead
		// of it, and before the email filter so suppressing the email in favour
		// of a CRM does not silently take the team's phones with it.
		BAC_Channels::send( $lead, $s, $reason );

		$email = array(
			'to'      => implode( ', ', $to ),
			'subject' => self::subject( $lead, $s, $reason ),
			'message' => self::lead_email_html( $lead, $s, $reason ),
			'headers' => self::headers( $lead ),
		);

		/**
		 * Filter the notification before it is sent. Return false to skip the
		 * plugin's own email, for instance when a CRM already handles it.
		 *
		 * @param array  $email   array( to, subject, message, headers ).
		 * @param int    $lead_id Lead post ID.
		 * @param array  $s       Settings.
		 * @param string $reason  Why it is being sent.
		 */
		$email = apply_filters( 'bac_lead_email', $email, $lead_id, $s, $reason );

		if ( false === $email || empty( $email['to'] ) ) {
			return false;
		}

		$sent = wp_mail( $email['to'], $email['subject'], $email['message'], $email['headers'] );

		if ( ! $sent ) {
			update_post_meta( $lead_id, '_bac_notify_error', current_time( 'mysql' ) );
		}

		return (bool) $sent;
	}

	/**
	 * A visitor asked for a human. Jump the queue whatever the timing setting
	 * says, and send again even if this conversation was already reported.
	 *
	 * @param int   $lead_id Lead post ID.
	 * @param array $s       Settings.
	 */
	public static function send_handoff( $lead_id, $s ) {
		delete_post_meta( $lead_id, self::SENT_META );
		self::send( $lead_id, $s, 'handoff' );
	}

	/**
	 * Everyone who should receive alerts, falling back to the site admin.
	 *
	 * @param array $s Settings.
	 * @return array
	 */
	public static function recipients( $s ) {
		$raw = trim( (string) $s['notify_email'] );
		$raw = '' !== $raw ? $raw : (string) get_option( 'admin_email' );
		$out = array();

		foreach ( preg_split( '/[\s,;]+/', $raw ) as $one ) {
			$one = sanitize_email( trim( $one ) );
			if ( $one && is_email( $one ) ) {
				$out[] = $one;
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Mail headers. The visitor's own address becomes the Reply-To, so hitting
	 * reply in the inbox answers the person rather than the website.
	 *
	 * @param array $lead Lead data.
	 * @return array
	 */
	private static function headers( $lead ) {
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		if ( '' !== $lead['email'] ) {
			$name      = '' !== $lead['name'] ? $lead['name'] : $lead['email'];
			$headers[] = 'Reply-To: ' . $name . ' <' . $lead['email'] . '>';
		}

		return $headers;
	}

	/**
	 * The subject line. Blank setting means the plugin writes one that says
	 * what happened, which beats a fixed string in a busy inbox.
	 *
	 * @param array  $lead   Lead data.
	 * @param array  $s      Settings.
	 * @param string $reason Why it is being sent.
	 * @return string
	 */
	private static function subject( $lead, $s, $reason ) {
		$site   = self::site_name();
		$custom = trim( (string) $s['notify_subject'] );

		if ( '' !== $custom ) {
			return self::fill_tokens( $custom, $lead, $site );
		}

		/*
		 * "wants a 4 day safari" only reads correctly when the model has boiled
		 * the chat down to a phrase. Before that all we have is the visitor's
		 * own sentence, which is quoted rather than glued into one of ours.
		 */
		$interest = self::shorten( $lead['interest'], 60 );
		$asked    = self::shorten( $lead['asked'], 70 );

		if ( 'handoff' === $reason ) {
			/* translators: 1: visitor name or email, 2: site name. */
			return sprintf( __( '%1$s asked the team to call back (%2$s)', 'beaver-ai-chat' ), self::who( $lead ), $site );
		}

		if ( '' !== $lead['email'] || '' !== $lead['phone'] ) {
			if ( '' !== $interest ) {
				/* translators: 1: visitor name or email, 2: what they want. */
				return sprintf( __( 'New chat lead: %1$s wants %2$s', 'beaver-ai-chat' ), self::who( $lead ), $interest );
			}

			if ( '' !== $asked ) {
				/* translators: 1: visitor name or email, 2: the visitor's own opening question. */
				return sprintf( __( 'New chat lead from %1$s: "%2$s"', 'beaver-ai-chat' ), self::who( $lead ), $asked );
			}

			/* translators: %s: site name. */
			return sprintf( __( 'New chat lead on %s', 'beaver-ai-chat' ), $site );
		}

		if ( '' !== $interest ) {
			/* translators: 1: what the visitor asked about, 2: site name. */
			return sprintf( __( 'Chat question about %1$s (%2$s)', 'beaver-ai-chat' ), $interest, $site );
		}

		if ( '' !== $asked ) {
			/* translators: 1: the visitor's own opening question, 2: site name. */
			return sprintf( __( 'Chat question: "%1$s" (%2$s)', 'beaver-ai-chat' ), $asked, $site );
		}

		/* translators: %s: site name. */
		return sprintf( __( 'New chat on %s', 'beaver-ai-chat' ), $site );
	}

	/**
	 * Replace the tokens allowed in a custom subject.
	 *
	 * @param string $text Raw subject.
	 * @param array  $lead Lead data.
	 * @param string $site Site name.
	 * @return string
	 */
	private static function fill_tokens( $text, $lead, $site ) {
		return strtr(
			$text,
			array(
				'{site}'     => $site,
				'{name}'     => '' !== $lead['name'] ? $lead['name'] : $lead['title'],
				'{email}'    => $lead['email'],
				'{phone}'    => $lead['phone'],
				'{interest}' => '' !== $lead['interest'] ? $lead['interest'] : self::shorten( $lead['asked'], 60 ),
				'{summary}'  => self::shorten( $lead['summary'], 120 ),
				'{messages}' => (string) $lead['turns'],
			)
		);
	}

	/**
	 * The best label available for a visitor.
	 *
	 * @param array $lead Lead data.
	 * @return string
	 */
	private static function who( $lead ) {
		if ( '' !== $lead['name'] ) {
			return $lead['name'];
		}
		if ( '' !== $lead['email'] ) {
			return $lead['email'];
		}
		return __( 'A visitor', 'beaver-ai-chat' );
	}

	/* ----------------------------------------------------------------- Roundup */

	/**
	 * Hourly tick. Sends the roundup only when one is due.
	 */
	public static function run_digest() {
		$s = BAC_Settings::get();

		if ( empty( $s['notify_enabled'] ) || 'digest' !== $s['notify_timing'] ) {
			return;
		}
		if ( ! self::digest_due( $s ) ) {
			return;
		}

		self::send_digest( $s );
	}

	/**
	 * Whether enough time has passed for the next roundup.
	 *
	 * @param array $s Settings.
	 * @return bool
	 */
	private static function digest_due( $s ) {
		$last  = (int) get_option( self::LAST_DIGEST, 0 );
		$every = max( 1, (int) $s['notify_digest_every'] );

		if ( $every < 24 ) {
			// A minute of slack, so an hourly cron that drifts early still counts.
			return ( time() - $last ) >= ( $every * HOUR_IN_SECONDS - MINUTE_IN_SECONDS );
		}

		// Once a day, at the hour the site chose, in the site's own timezone.
		return (int) current_time( 'G' ) === (int) $s['notify_digest_hour']
			&& ( time() - $last ) >= 12 * HOUR_IN_SECONDS;
	}

	/**
	 * Gather everything since the last roundup and send one email.
	 *
	 * @param array $s Settings.
	 * @return bool Whether an email went out.
	 */
	public static function send_digest( $s ) {
		$since = (int) get_option( self::LAST_DIGEST, 0 );
		$since = $since ? $since : time() - DAY_IN_SECONDS;

		// Move the marker first: a mail failure must not cause the next run to
		// report the same conversations all over again.
		update_option( self::LAST_DIGEST, time(), false );

		$leads = self::digest_leads( $since, $s );

		if ( empty( $leads ) && empty( $s['notify_digest_empty'] ) ) {
			return false;
		}

		$to = self::recipients( $s );
		if ( empty( $to ) ) {
			return false;
		}

		foreach ( $leads as $lead ) {
			update_post_meta( $lead['id'], self::SENT_META, current_time( 'mysql' ) );
			update_post_meta( $lead['id'], '_bac_notify_reason', 'digest' );
			self::unschedule( $lead['id'] );
		}

		$site  = self::site_name();
		$count = count( $leads );

		$email = array(
			'to'      => implode( ', ', $to ),
			'subject' => $count > 0
				/* translators: 1: number of conversations, 2: site name. */
				? sprintf( _n( '%1$d chat conversation on %2$s', '%1$d chat conversations on %2$s', $count, 'beaver-ai-chat' ), $count, $site )
				/* translators: %s: site name. */
				: sprintf( __( 'No chat conversations on %s', 'beaver-ai-chat' ), $site ),
			'message' => self::digest_email_html( $leads, $s ),
			'headers' => array( 'Content-Type: text/html; charset=UTF-8' ),
		);

		/**
		 * Filter the roundup email. Return false to suppress it.
		 *
		 * @param array $email array( to, subject, message, headers ).
		 * @param array $leads Lead data included in this roundup.
		 * @param array $s     Settings.
		 */
		$email = apply_filters( 'bac_digest_email', $email, $leads, $s );

		if ( false === $email || empty( $email['to'] ) ) {
			return false;
		}

		return (bool) wp_mail( $email['to'], $email['subject'], $email['message'], $email['headers'] );
	}

	/**
	 * Conversations to include in a roundup: active since the last one, quiet
	 * long enough to be finished, not already reported, and worth reporting.
	 *
	 * A chat still in progress is deliberately left for the next roundup rather
	 * than reported half finished.
	 *
	 * @param int   $since Unix timestamp of the last roundup.
	 * @param array $s     Settings.
	 * @return array
	 */
	private static function digest_leads( $since, $s ) {
		$settled = time() - max( 1, (int) $s['notify_delay'] ) * MINUTE_IN_SECONDS;

		$ids = get_posts(
			array(
				'post_type'      => BAC_LEAD_CPT,
				'post_status'    => 'any',
				'posts_per_page' => 200,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'orderby'        => 'modified',
				'order'          => 'ASC',
				'date_query'     => array(
					array(
						'column' => 'post_modified_gmt',
						'after'  => gmdate( 'Y-m-d H:i:s', $since ),
						'before' => gmdate( 'Y-m-d H:i:s', $settled ),
					),
				),
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => self::SENT_META,
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		$out = array();

		foreach ( $ids as $id ) {
			if ( ! self::qualifies( $id, $s ) ) {
				continue;
			}
			$lead = self::lead_data( $id, $s );
			if ( $lead ) {
				$out[] = $lead;
			}
		}

		return $out;
	}

	/* -------------------------------------------------------------- Lead data */

	/**
	 * Everything either email needs about one conversation, read once.
	 *
	 * @param int   $lead_id Lead post ID.
	 * @param array $s       Settings.
	 * @return array|null
	 */
	public static function lead_data( $lead_id, $s ) {
		$post = get_post( $lead_id );

		if ( ! $post || BAC_LEAD_CPT !== $post->post_type ) {
			return null;
		}

		$messages = json_decode( (string) get_post_meta( $lead_id, '_bac_messages', true ), true );
		$messages = is_array( $messages ) ? $messages : array();
		$asked    = '';

		foreach ( $messages as $m ) {
			if ( isset( $m['role'] ) && 'assistant' !== $m['role'] ) {
				$asked = (string) $m['content'];
				break;
			}
		}

		return array(
			'id'       => (int) $lead_id,
			'title'    => get_the_title( $lead_id ),
			'name'     => (string) get_post_meta( $lead_id, '_bac_name', true ),
			'email'    => (string) get_post_meta( $lead_id, '_bac_email', true ),
			'phone'    => (string) get_post_meta( $lead_id, '_bac_phone', true ),
			'interest' => (string) get_post_meta( $lead_id, '_bac_interest', true ),
			'summary'  => (string) get_post_meta( $lead_id, '_bac_summary', true ),
			'asked'    => $asked,
			'page'     => (string) get_post_meta( $lead_id, '_bac_page', true ),
			'turns'    => (int) get_post_meta( $lead_id, '_bac_turns', true ),
			'callback' => '' !== (string) get_post_meta( $lead_id, '_bac_contact_requested', true ),
			'when'     => get_post_modified_time( 'U', true, $lead_id ),
			'messages' => $messages,
			'raw'      => $post->post_content,
			'admin'    => admin_url( 'post.php?post=' . (int) $lead_id . '&action=edit' ),
			'view'     => self::view_url( $lead_id, $s ),
		);
	}

	/* ------------------------------------------------------------ Email bodies */

	/**
	 * The alert for a single conversation.
	 *
	 * @param array  $lead   Lead data.
	 * @param array  $s      Settings.
	 * @param string $reason Why it is being sent.
	 * @return string
	 */
	private static function lead_email_html( $lead, $s, $reason ) {
		$accent = BAC_Settings::get( 'accent' );
		$has    = ( '' !== $lead['email'] || '' !== $lead['phone'] );

		if ( 'handoff' === $reason ) {
			$kicker = __( 'Callback requested', 'beaver-ai-chat' );
		} elseif ( 'test' === $reason ) {
			$kicker = __( 'Test alert', 'beaver-ai-chat' );
		} elseif ( $has ) {
			$kicker = __( 'New chat lead', 'beaver-ai-chat' );
		} else {
			$kicker = __( 'New chat conversation', 'beaver-ai-chat' );
		}

		$body = '';

		/* What they wanted, first and largest: it is the reason to read on. */
		$want = '' !== $lead['summary'] ? $lead['summary'] : $lead['asked'];

		if ( '' !== trim( $want ) ) {
			$body .= '<div style="margin:0 0 20px;padding:14px 16px;background:#f6f8f7;border-left:3px solid ' . esc_attr( $accent ) . ';border-radius:0 8px 8px 0;">';
			$body .= '<div style="font-size:11px;letter-spacing:1.2px;text-transform:uppercase;color:#6b7c73;margin-bottom:5px;">' . esc_html__( 'What they asked for', 'beaver-ai-chat' ) . '</div>';
			$body .= '<div style="font-size:15px;line-height:1.6;color:#22312a;">' . nl2br( esc_html( $want ) ) . '</div>';

			// The verbatim opening question, when the summary has replaced it.
			if ( '' !== trim( $lead['asked'] ) && trim( $lead['asked'] ) !== trim( $want ) ) {
				$body .= '<div style="margin-top:10px;font-size:13px;color:#6b7c73;">'
					. esc_html__( 'Opened with:', 'beaver-ai-chat' ) . ' &ldquo;' . esc_html( self::shorten( $lead['asked'], 180 ) ) . '&rdquo;</div>';
			}

			$body .= '</div>';
		}

		/* Contact details, and nothing pretending to be one. */
		$rows = array();

		if ( '' !== $lead['name'] || '' !== $lead['title'] ) {
			$rows[ __( 'Name', 'beaver-ai-chat' ) ] = esc_html( '' !== $lead['name'] ? $lead['name'] : $lead['title'] );
		}
		if ( '' !== $lead['email'] ) {
			$rows[ __( 'Email', 'beaver-ai-chat' ) ] = '<a href="mailto:' . esc_attr( $lead['email'] ) . '" style="color:' . esc_attr( $accent ) . ';">' . esc_html( $lead['email'] ) . '</a>';
		}
		if ( '' !== $lead['phone'] ) {
			$rows[ __( 'Phone', 'beaver-ai-chat' ) ] = '<a href="tel:' . esc_attr( preg_replace( '/[^\d+]/', '', $lead['phone'] ) ) . '" style="color:' . esc_attr( $accent ) . ';">' . esc_html( $lead['phone'] ) . '</a>';
		}
		if ( '' !== $lead['interest'] ) {
			$rows[ __( 'Interest', 'beaver-ai-chat' ) ] = esc_html( $lead['interest'] );
		}

		$rows[ __( 'Messages', 'beaver-ai-chat' ) ] = esc_html( (string) $lead['turns'] );

		if ( '' !== $lead['page'] ) {
			$rows[ __( 'Chatting on', 'beaver-ai-chat' ) ] = '<a href="' . esc_url( $lead['page'] ) . '" style="color:' . esc_attr( $accent ) . ';">' . esc_html( self::shorten( $lead['page'], 60 ) ) . '</a>';
		}

		$rows[ __( 'Last active', 'beaver-ai-chat' ) ] = esc_html( self::when_label( $lead['when'] ) );

		if ( ! $has ) {
			$rows[ __( 'Contact details', 'beaver-ai-chat' ) ] = '<span style="color:#a4802a;">' . esc_html__( 'None given', 'beaver-ai-chat' ) . '</span>';
		}

		$body .= '<table role="presentation" style="width:100%;border-collapse:collapse;font-size:14px;color:#22312a;">';
		foreach ( $rows as $label => $value ) {
			$body .= '<tr><td style="padding:8px 0;width:36%;color:#6b7c73;vertical-align:top;">' . esc_html( $label ) . '</td>';
			$body .= '<td style="padding:8px 0;font-weight:600;">' . $value . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		}
		$body .= '</table>';

		$body .= self::buttons( $lead, $accent );

		if ( ! empty( $s['notify_transcript'] ) ) {
			$body .= self::transcript_html( $lead, $s );
		}

		return self::shell( $kicker, self::site_name(), $body, $accent );
	}

	/**
	 * The roundup.
	 *
	 * @param array $leads Lead data.
	 * @param array $s     Settings.
	 * @return string
	 */
	private static function digest_email_html( $leads, $s ) {
		$accent = BAC_Settings::get( 'accent' );

		$with    = array();
		$without = array();

		foreach ( $leads as $lead ) {
			if ( '' !== $lead['email'] || '' !== $lead['phone'] ) {
				$with[] = $lead;
			} else {
				$without[] = $lead;
			}
		}

		$body = '';

		if ( empty( $leads ) ) {
			$body .= '<p style="margin:0 0 18px;font-size:14px;color:#6b7c73;">' . esc_html__( 'No conversations since the last roundup.', 'beaver-ai-chat' ) . '</p>';
		} else {
			$body .= '<p style="margin:0 0 18px;font-size:14px;color:#22312a;">';
			$body .= esc_html(
				sprintf(
					/* translators: 1: total conversations, 2: how many left contact details. */
					_n( '%1$d conversation, %2$d with contact details.', '%1$d conversations, %2$d with contact details.', count( $leads ), 'beaver-ai-chat' ),
					count( $leads ),
					count( $with )
				)
			);
			$body .= '</p>';
		}

		if ( ! empty( $with ) ) {
			$body .= self::digest_group( __( 'Left their details', 'beaver-ai-chat' ), $with, $accent );
		}
		if ( ! empty( $without ) ) {
			$body .= self::digest_group( __( 'Questions only', 'beaver-ai-chat' ), $without, $accent );
		}

		$all   = admin_url( 'edit.php?post_type=' . BAC_LEAD_CPT );
		$body .= '<p style="margin:24px 0 0;">'
			. '<a href="' . esc_url( $all ) . '" style="display:inline-block;background:' . esc_attr( $accent ) . ';color:#fff;text-decoration:none;font-weight:700;padding:12px 20px;border-radius:9px;font-size:14px;">'
			. esc_html__( 'Open all conversations', 'beaver-ai-chat' ) . '</a></p>';

		return self::shell( __( 'Chat roundup', 'beaver-ai-chat' ), self::site_name(), $body, $accent );
	}

	/**
	 * One titled group of conversation cards inside the roundup.
	 *
	 * @param string $title  Group heading.
	 * @param array  $leads  Lead data.
	 * @param string $accent Accent colour.
	 * @return string
	 */
	private static function digest_group( $title, $leads, $accent ) {
		$html = '<div style="font-size:11px;letter-spacing:1.2px;text-transform:uppercase;color:#6b7c73;margin:22px 0 10px;">' . esc_html( $title ) . '</div>';

		foreach ( $leads as $lead ) {
			$want = '' !== $lead['summary'] ? $lead['summary'] : $lead['asked'];
			$link = '' !== $lead['view'] ? $lead['view'] : $lead['admin'];

			$html .= '<div style="border:1px solid #e4e9e6;border-radius:10px;padding:14px 16px;margin:0 0 10px;">';
			$html .= '<div style="font-size:15px;font-weight:700;color:#22312a;">' . esc_html( '' !== $lead['name'] ? $lead['name'] : $lead['title'] ) . '</div>';

			$meta = array();
			if ( '' !== $lead['email'] ) {
				$meta[] = '<a href="mailto:' . esc_attr( $lead['email'] ) . '" style="color:' . esc_attr( $accent ) . ';text-decoration:none;">' . esc_html( $lead['email'] ) . '</a>';
			}
			if ( '' !== $lead['phone'] ) {
				$meta[] = esc_html( $lead['phone'] );
			}
			if ( $lead['callback'] ) {
				$meta[] = '<strong style="color:#a4802a;">' . esc_html__( 'asked for a callback', 'beaver-ai-chat' ) . '</strong>';
			}

			if ( ! empty( $meta ) ) {
				$html .= '<div style="font-size:13px;margin-top:3px;color:#6b7c73;">' . implode( ' &middot; ', $meta ) . '</div>';
			}

			if ( '' !== trim( $want ) ) {
				$html .= '<div style="font-size:14px;line-height:1.6;color:#22312a;margin-top:8px;">' . esc_html( self::shorten( $want, 260 ) ) . '</div>';
			}

			$html .= '<div style="font-size:12px;color:#8a998f;margin-top:9px;">';
			$html .= esc_html(
				sprintf(
					/* translators: %d: number of messages in the conversation. */
					_n( '%d message', '%d messages', $lead['turns'], 'beaver-ai-chat' ),
					$lead['turns']
				)
			);
			$html .= ' &middot; ' . esc_html( self::when_label( $lead['when'] ) );
			$html .= ' &middot; <a href="' . esc_url( $link ) . '" style="color:' . esc_attr( $accent ) . ';">' . esc_html__( 'read it', 'beaver-ai-chat' ) . '</a>';
			$html .= '</div></div>';
		}

		return $html;
	}

	/**
	 * The call-to-action buttons under a single alert.
	 *
	 * @param array  $lead   Lead data.
	 * @param string $accent Accent colour.
	 * @return string
	 */
	private static function buttons( $lead, $accent ) {
		$html = '<p style="margin:22px 0 0;">';
		$html .= '<a href="' . esc_url( $lead['admin'] ) . '" style="display:inline-block;background:' . esc_attr( $accent ) . ';color:#fff;text-decoration:none;font-weight:700;padding:12px 20px;border-radius:9px;font-size:14px;">';
		$html .= esc_html__( 'View the full conversation', 'beaver-ai-chat' ) . '</a>';

		if ( '' !== $lead['view'] ) {
			$html .= ' <a href="' . esc_url( $lead['view'] ) . '" style="display:inline-block;color:' . esc_attr( $accent ) . ';text-decoration:none;font-weight:600;padding:12px 8px;font-size:14px;">';
			$html .= esc_html__( 'Open without logging in', 'beaver-ai-chat' ) . '</a>';
		}

		if ( '' !== $lead['email'] ) {
			$html .= '<br><span style="display:inline-block;margin-top:10px;font-size:13px;color:#6b7c73;">';
			$html .= esc_html__( 'Reply to this email to answer the visitor directly.', 'beaver-ai-chat' ) . '</span>';
		}

		return $html . '</p>';
	}

	/**
	 * The conversation itself, folded into the email.
	 *
	 * @param array $lead Lead data.
	 * @param array $s    Settings.
	 * @return string
	 */
	private static function transcript_html( $lead, $s ) {
		if ( empty( $lead['messages'] ) ) {
			return '' !== trim( (string) $lead['raw'] )
				? '<div style="margin-top:24px;font-size:13px;white-space:pre-wrap;color:#3c4b43;">' . esc_html( $lead['raw'] ) . '</div>'
				: '';
		}

		$assistant = trim( (string) $s['assistant'] );
		$assistant = '' !== $assistant ? $assistant : __( 'Assistant', 'beaver-ai-chat' );

		$html = '<div style="margin:26px 0 0;padding-top:18px;border-top:1px solid #e4e9e6;">';
		$html .= '<div style="font-size:11px;letter-spacing:1.2px;text-transform:uppercase;color:#6b7c73;margin-bottom:12px;">' . esc_html__( 'The conversation', 'beaver-ai-chat' ) . '</div>';

		foreach ( $lead['messages'] as $m ) {
			$is_bot = ( isset( $m['role'] ) && 'assistant' === $m['role'] );
			$who    = $is_bot ? $assistant : __( 'Visitor', 'beaver-ai-chat' );
			$text   = isset( $m['content'] ) ? (string) $m['content'] : '';

			$html .= '<div style="margin:0 0 12px;">';
			$html .= '<div style="font-size:11px;font-weight:700;letter-spacing:.4px;text-transform:uppercase;color:' . ( $is_bot ? '#8a998f' : '#6b7c73' ) . ';margin-bottom:3px;">' . esc_html( $who ) . '</div>';
			$html .= '<div style="font-size:13px;line-height:1.6;color:#22312a;padding:9px 12px;border-radius:9px;background:' . ( $is_bot ? '#f6f8f7' : '#eef4f1' ) . ';">' . nl2br( esc_html( $text ) ) . '</div>';
			$html .= '</div>';
		}

		return $html . '</div>';
	}

	/**
	 * The card every email is built inside, so a roundup and an alert look like
	 * they came from the same place.
	 *
	 * @param string $kicker Small label above the site name.
	 * @param string $site   Site name.
	 * @param string $body   Inner HTML.
	 * @param string $accent Accent colour.
	 * @return string
	 */
	private static function shell( $kicker, $site, $body, $accent ) {
		$html  = '<div style="background:#f4f6f5;padding:28px 12px;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;">';
		$html .= '<div style="max-width:600px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;border:1px solid #e4e9e6;">';
		$html .= '<div style="background:' . esc_attr( $accent ) . ';padding:20px 24px;color:#fff;">';
		$html .= '<div style="font-size:12px;letter-spacing:1.4px;text-transform:uppercase;opacity:.85;">' . esc_html( $kicker ) . '</div>';
		$html .= '<div style="font-size:19px;font-weight:700;margin-top:4px;">' . esc_html( $site ) . '</div></div>';
		$html .= '<div style="padding:22px 24px;">' . $body . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with escaping inside.
		$html .= '</div></div>';

		return $html;
	}

	/* --------------------------------------------------------- Read-only link */

	/**
	 * A signed, expiring link that shows one conversation without a login.
	 *
	 * The email already carries the summary and, optionally, the whole
	 * transcript, so this exposes nothing the recipient was not already sent. It
	 * exists so the person who has to act on a lead does not need a WordPress
	 * account to read it. Off by default; turning it off kills every link that
	 * is already out there.
	 *
	 * @param int   $lead_id Lead post ID.
	 * @param array $s       Settings.
	 * @return string Empty when the feature is off.
	 */
	public static function view_url( $lead_id, $s ) {
		if ( empty( $s['notify_share_links'] ) ) {
			return '';
		}

		$expires = time() + max( 1, (int) $s['notify_link_days'] ) * DAY_IN_SECONDS;

		return add_query_arg(
			array(
				'bac_view' => (int) $lead_id,
				'exp'      => $expires,
				'k'        => self::sign( $lead_id, $expires ),
			),
			home_url( '/' )
		);
	}

	/**
	 * Signature for a view link. Derived from this site's own salts, so a link
	 * cannot be forged and cannot be replayed on another site.
	 *
	 * @param int $lead_id Lead post ID.
	 * @param int $expires Expiry timestamp.
	 * @return string
	 */
	private static function sign( $lead_id, $expires ) {
		return substr( wp_hash( 'bac_view|' . (int) $lead_id . '|' . (int) $expires ), 0, 24 );
	}

	/**
	 * Serve a view link, when one is being asked for.
	 */
	public static function maybe_render_view() {
		if ( ! isset( $_GET['bac_view'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- signed link, not a form.
			return;
		}

		$s = BAC_Settings::get();

		// The switch is checked at read time on purpose: turning share links off
		// must revoke the ones already sitting in people's inboxes.
		if ( empty( $s['notify_share_links'] ) ) {
			self::deny( __( 'This link is no longer available.', 'beaver-ai-chat' ) );
		}

		$lead_id = absint( $_GET['bac_view'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$expires = isset( $_GET['exp'] ) ? absint( $_GET['exp'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$key     = isset( $_GET['k'] ) ? sanitize_text_field( wp_unslash( $_GET['k'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! $lead_id || ! $expires || ! hash_equals( self::sign( $lead_id, $expires ), $key ) ) {
			self::deny( __( 'That link is not valid.', 'beaver-ai-chat' ) );
		}

		if ( $expires < time() ) {
			self::deny( __( 'That link has expired. Open the conversation from the dashboard instead.', 'beaver-ai-chat' ) );
		}

		$post = get_post( $lead_id );
		if ( ! $post || BAC_LEAD_CPT !== $post->post_type ) {
			self::deny( __( 'That conversation no longer exists.', 'beaver-ai-chat' ) );
		}

		self::render_view( $lead_id );
	}

	/**
	 * Refuse a view link.
	 *
	 * @param string $message Reason.
	 */
	private static function deny( $message ) {
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow', true );
		wp_die( esc_html( $message ), esc_html__( 'Conversation', 'beaver-ai-chat' ), array( 'response' => 403 ) );
	}

	/**
	 * Render one conversation as a small standalone page.
	 *
	 * @param int $lead_id Lead post ID.
	 */
	private static function render_view( $lead_id ) {
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow, noarchive', true );
		header( 'Content-Type: text/html; charset=utf-8' );

		$title = get_the_title( $lead_id );
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title><?php echo esc_html( $title ); ?></title>
	<link rel="stylesheet" href="<?php echo esc_url( BAC_URL . 'assets/css/admin.css?ver=' . BAC_VERSION ); ?>">
	<style>
		body { margin: 0; background: #f4f6f5; font: 14px/1.6 -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; color: #1d2327; }
		.bac-view { max-width: 720px; margin: 0 auto; padding: 32px 16px 48px; }
		.bac-view-card { background: #fff; border: 1px solid #e4e9e6; border-radius: 12px; padding: 24px; }
		.bac-view h1 { font-size: 20px; margin: 0 0 4px; }
		.bac-view .bac-view-sub { color: #6b7c73; font-size: 13px; margin: 0 0 20px; }
		.bac-view .bac-thread { max-height: none; }
		.bac-view-foot { color: #8a998f; font-size: 12px; text-align: center; margin: 18px 0 0; }
	</style>
</head>
<body>
	<div class="bac-view">
		<div class="bac-view-card">
			<h1><?php echo esc_html( $title ); ?></h1>
			<p class="bac-view-sub"><?php echo esc_html( self::site_name() ); ?></p>
			<?php echo BAC_Leads::panel_html( $lead_id, 'share' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with escaping inside. ?>
		</div>
		<p class="bac-view-foot"><?php esc_html_e( 'Shared read-only link. Anyone with it can read this conversation until it expires.', 'beaver-ai-chat' ); ?></p>
	</div>
</body>
</html>
		<?php
		exit;
	}

	/**
	 * Where this conversation stands with the team, in one line, for the
	 * conversation screen. Answers the question anyone reading a lead actually
	 * has: does someone already know about this?
	 *
	 * @param int $lead_id Lead post ID.
	 * @return string Escaped HTML, empty when alerts are off.
	 */
	public static function status_label( $lead_id ) {
		$s = BAC_Settings::get();

		if ( empty( $s['notify_enabled'] ) ) {
			return '<span class="bac-dash">' . esc_html__( 'Alerts are switched off', 'beaver-ai-chat' ) . '</span>';
		}

		$sent = (string) get_post_meta( $lead_id, self::SENT_META, true );

		if ( '' !== $sent ) {
			$when   = strtotime( get_gmt_from_date( $sent ) . ' UTC' );
			$reason = (string) get_post_meta( $lead_id, '_bac_notify_reason', true );

			if ( '' !== (string) get_post_meta( $lead_id, '_bac_notify_error', true ) ) {
				return '<strong class="bac-alert-bad">' . esc_html__( 'The alert could not be sent. Check the site\'s outgoing mail.', 'beaver-ai-chat' ) . '</strong>';
			}

			$label = ( 'digest' === $reason )
				? __( 'Included in the roundup %s', 'beaver-ai-chat' )
				: __( 'Emailed to the team %s', 'beaver-ai-chat' );

			/* translators: %s: relative time, for example "5 mins ago". */
			return esc_html( sprintf( $label, self::when_label( $when ) ) );
		}

		if ( ! self::qualifies( $lead_id, $s ) ) {
			return 'contact' === $s['notify_when']
				? '<span class="bac-dash">' . esc_html__( 'Not emailed: no contact details yet', 'beaver-ai-chat' ) . '</span>'
				: '<span class="bac-dash">' . esc_html__( 'Not emailed yet', 'beaver-ai-chat' ) . '</span>';
		}

		if ( 'digest' === $s['notify_timing'] ) {
			return esc_html__( 'Waiting for the next roundup', 'beaver-ai-chat' );
		}

		$next = wp_next_scheduled( self::CRON_LEAD, array( (int) $lead_id ) );

		if ( $next ) {
			/* translators: %s: relative time, for example "4 mins". */
			return esc_html( sprintf( __( 'Emailing the team once the chat has been quiet for %s', 'beaver-ai-chat' ), human_time_diff( time(), $next ) ) );
		}

		return esc_html__( 'Due to be emailed to the team', 'beaver-ai-chat' );
	}

	/* ------------------------------------------------------------------ Helpers */

	/** The site name, with entities decoded so it reads correctly in a subject. */
	private static function site_name() {
		return wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	}

	/**
	 * Trim to length on a word boundary.
	 *
	 * @param string $text  Text.
	 * @param int    $chars Maximum characters.
	 * @return string
	 */
	private static function shorten( $text, $chars ) {
		$text = trim( preg_replace( '/\s+/', ' ', (string) $text ) );

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

	/**
	 * "12 minutes ago", or a date once that stops being useful.
	 *
	 * @param int $timestamp Unix timestamp, UTC.
	 * @return string
	 */
	private static function when_label( $timestamp ) {
		$timestamp = (int) $timestamp;
		$ago       = time() - $timestamp;

		if ( $ago < DAY_IN_SECONDS ) {
			/* translators: %s: human readable time difference, for example "5 mins". */
			return sprintf( __( '%s ago', 'beaver-ai-chat' ), human_time_diff( $timestamp ) );
		}

		return wp_date( get_option( 'date_format' ) . ', ' . get_option( 'time_format' ), $timestamp );
	}

	/**
	 * Send a sample alert to the configured recipients, using the most recent
	 * real conversation so the test shows exactly what they will receive.
	 *
	 * @param array $s Settings.
	 * @return array array( ok, message )
	 */
	public static function send_test( $s ) {
		$to = self::recipients( $s );

		if ( empty( $to ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'No valid recipient. Add an address above, or set the site admin email.', 'beaver-ai-chat' ),
			);
		}

		$ids = get_posts(
			array(
				'post_type'      => BAC_LEAD_CPT,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'orderby'        => 'modified',
				'order'          => 'DESC',
			)
		);

		$lead = ! empty( $ids ) ? self::lead_data( $ids[0], $s ) : null;
		$lead = $lead ? $lead : self::sample_lead();

		$failure = '';
		$capture = static function ( $error ) use ( &$failure ) {
			$failure = $error->get_error_message();
		};

		add_action( 'wp_mail_failed', $capture );

		$sent = wp_mail(
			implode( ', ', $to ),
			/* translators: %s: site name. */
			sprintf( __( 'Test: chat alert from %s', 'beaver-ai-chat' ), self::site_name() ),
			self::lead_email_html( $lead, $s, 'test' ),
			self::headers( $lead )
		);

		remove_action( 'wp_mail_failed', $capture );

		// Every configured channel is exercised too, so one press tells you
		// which of them actually works rather than only the email.
		$notes = array();

		foreach ( BAC_Channels::send( $lead, $s, 'test' ) as $result ) {
			$notes[] = $result['message'];
		}

		$extra = empty( $notes ) ? '' : ' ' . implode( ' ', $notes );

		if ( ! $sent ) {
			return array(
				'ok'      => false,
				'message' => ( '' !== $failure
					? $failure
					: __( 'WordPress could not send the email. Check your SMTP plugin or ask your host about outgoing mail.', 'beaver-ai-chat' ) ) . $extra,
			);
		}

		return array(
			'ok'      => true,
			/* translators: %s: comma separated email addresses. */
			'message' => sprintf( __( 'Sent to %s. If it does not arrive, check spam and your SMTP setup.', 'beaver-ai-chat' ), implode( ', ', $to ) ) . $extra,
		);
	}

	/**
	 * A stand-in conversation for a test on a site with no chats yet.
	 *
	 * @return array
	 */
	private static function sample_lead() {
		$asked = __( 'Hi, do you have anything available in March for two people, and roughly what does it cost?', 'beaver-ai-chat' );

		return array(
			'id'       => 0,
			'title'    => __( 'Sample visitor', 'beaver-ai-chat' ),
			'name'     => __( 'Sample visitor', 'beaver-ai-chat' ),
			'email'    => '',
			'phone'    => '',
			'interest' => __( 'Availability and pricing', 'beaver-ai-chat' ),
			'summary'  => __( 'Wants to know what is available in March for two people and what it costs. No dates fixed yet.', 'beaver-ai-chat' ),
			'asked'    => $asked,
			'page'     => home_url( '/' ),
			'turns'    => 2,
			'callback' => false,
			'when'     => time(),
			'messages' => array(
				array(
					'role'    => 'user',
					'content' => $asked,
				),
				array(
					'role'    => 'assistant',
					'content' => __( 'This is a test alert, so this reply is not from a real conversation. Real alerts contain the visitor\'s own words.', 'beaver-ai-chat' ),
				),
			),
			'raw'      => '',
			'admin'    => admin_url( 'edit.php?post_type=' . BAC_LEAD_CPT ),
			'view'     => '',
		);
	}
}
