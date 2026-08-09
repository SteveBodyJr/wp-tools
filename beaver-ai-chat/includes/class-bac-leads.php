<?php
/**
 * Conversation capture: stores every chat as it happens, pulls out contact
 * details, and alerts the team. Self contained, so it works on any site with no
 * other plugin present, while exposing hooks for sites that already have a CRM.
 *
 * A conversation is written on the visitor's very first message and then kept up
 * to date on every turn, so the team can watch a chat unfold live rather than
 * waiting for it to finish.
 *
 * @package BeaverAIChat
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class BAC_Leads
 */
class BAC_Leads {

	/** A chat counts as still going if it moved within this many seconds. */
	const LIVE_WINDOW = 180;

	/** Wire up hooks. */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
		add_filter( 'manage_' . BAC_LEAD_CPT . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . BAC_LEAD_CPT . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
		add_filter( 'manage_edit-' . BAC_LEAD_CPT . '_sortable_columns', array( __CLASS__, 'sortable' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'order_by_activity' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_box' ) );
		add_action( 'bac_enrich_lead', array( __CLASS__, 'enrich' ), 10, 1 );

		if ( is_admin() ) {
			add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_assets' ) );
			add_action( 'wp_ajax_bac_lead_poll', array( __CLASS__, 'ajax_poll' ) );
		}
	}

	/** The private post type conversations are stored in. */
	public static function register_post_type() {
		register_post_type(
			BAC_LEAD_CPT,
			array(
				'labels'              => array(
					'name'          => __( 'Conversations', 'beaver-ai-chat' ),
					'singular_name' => __( 'Conversation', 'beaver-ai-chat' ),
					'menu_name'     => __( 'Conversations', 'beaver-ai-chat' ),
					'all_items'     => __( 'Conversations', 'beaver-ai-chat' ),
					'search_items'  => __( 'Search conversations', 'beaver-ai-chat' ),
					'not_found'     => __( 'No conversations yet.', 'beaver-ai-chat' ),
					'edit_item'     => __( 'Conversation', 'beaver-ai-chat' ),
				),
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => 'beaver-ai-chat',
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
				'supports'            => array( 'title' ),
				'exclude_from_search' => true,
				'has_archive'         => false,
				'rewrite'             => false,
			)
		);
	}

	/* ------------------------------------------------------------ List table */

	/**
	 * Conversation list columns.
	 *
	 * @param array $cols Existing columns.
	 * @return array
	 */
	public static function columns( $cols ) {
		return array(
			'cb'          => isset( $cols['cb'] ) ? $cols['cb'] : '<input type="checkbox" />',
			'title'       => __( 'Visitor', 'beaver-ai-chat' ),
			'bac_email'   => __( 'Email', 'beaver-ai-chat' ),
			'bac_phone'   => __( 'Phone', 'beaver-ai-chat' ),
			'bac_summary' => __( 'What they want', 'beaver-ai-chat' ),
			'bac_turns'   => __( 'Messages', 'beaver-ai-chat' ),
			'bac_active'  => __( 'Last activity', 'beaver-ai-chat' ),
		);
	}

	/**
	 * Make the activity column sortable.
	 *
	 * @param array $cols Sortable columns.
	 * @return array
	 */
	public static function sortable( $cols ) {
		$cols['bac_active'] = 'modified';
		return $cols;
	}

	/**
	 * Show the most recently active conversations first, so a chat happening
	 * right now sits at the top of the list.
	 *
	 * @param WP_Query $query Query.
	 */
	public static function order_by_activity( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		if ( BAC_LEAD_CPT !== $query->get( 'post_type' ) ) {
			return;
		}
		if ( '' !== $query->get( 'orderby' ) ) {
			return; // The user clicked a column header.
		}

		$query->set( 'orderby', 'modified' );
		$query->set( 'order', 'DESC' );
	}

	/**
	 * Render one list column.
	 *
	 * @param string $col     Column key.
	 * @param int    $post_id Post ID.
	 */
	public static function column( $col, $post_id ) {
		$dash = '<span style="color:#b4b9bd;">&mdash;</span>';

		switch ( $col ) {
			case 'bac_email':
				$email = get_post_meta( $post_id, '_bac_email', true );
				echo $email
					? '<a href="mailto:' . esc_attr( $email ) . '">' . esc_html( $email ) . '</a>'
					: wp_kses_post( $dash );
				break;

			case 'bac_phone':
				$phone = get_post_meta( $post_id, '_bac_phone', true );
				echo $phone ? esc_html( $phone ) : wp_kses_post( $dash );
				break;

			case 'bac_summary':
				$summary = get_post_meta( $post_id, '_bac_summary', true );
				echo $summary ? esc_html( wp_trim_words( $summary, 22 ) ) : wp_kses_post( $dash );
				break;

			case 'bac_turns':
				echo (int) get_post_meta( $post_id, '_bac_turns', true );
				break;

			case 'bac_active':
				echo wp_kses_post( self::activity_label( $post_id ) );
				break;
		}
	}

	/**
	 * "Live now" or a relative time, for the activity column.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private static function activity_label( $post_id ) {
		$modified = get_post_modified_time( 'U', true, $post_id );
		$ago      = time() - (int) $modified;

		if ( $ago <= self::LIVE_WINDOW ) {
			return '<span class="bac-live"><span class="bac-live-dot"></span>' . esc_html__( 'Live now', 'beaver-ai-chat' ) . '</span>';
		}

		/* translators: %s: human readable time difference, for example "5 mins". */
		return esc_html( sprintf( __( '%s ago', 'beaver-ai-chat' ), human_time_diff( $modified ) ) );
	}

	/* -------------------------------------------------------------- Edit screen */

	/** Conversation panel on the lead edit screen. */
	public static function meta_box() {
		add_meta_box(
			'bac_lead_box',
			__( 'Conversation', 'beaver-ai-chat' ),
			array( __CLASS__, 'render_meta_box' ),
			BAC_LEAD_CPT,
			'normal',
			'high'
		);
	}

	/**
	 * Load the conversation styles and the live poller on the lead screens.
	 *
	 * @param string $hook Current admin page.
	 */
	public static function admin_assets( $hook ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || BAC_LEAD_CPT !== $screen->post_type ) {
			return;
		}

		wp_enqueue_style( 'bac-admin', BAC_URL . 'assets/css/admin.css', array(), BAC_VERSION );

		if ( ! in_array( $screen->base, array( 'post', 'edit' ), true ) ) {
			return;
		}

		// The list needs the status control; the single screen needs that plus
		// the live poller. One script serves both and skips what does not apply.
		wp_enqueue_script( 'bac-lead', BAC_URL . 'assets/js/lead.js', array(), BAC_VERSION, true );
		wp_localize_script(
			'bac-lead',
			'BAC_LEAD',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( 'bac_lead_poll' ),
				'statusNonce' => wp_create_nonce( 'bac_set_status' ),
				'leadId'      => ( 'post' === $screen->base ) ? (int) get_the_ID() : 0,
				'i18n'        => array(
					'live'    => __( 'Live now', 'beaver-ai-chat' ),
					'updated' => __( 'Updated just now', 'beaver-ai-chat' ),
					'saving'  => __( 'Saving…', 'beaver-ai-chat' ),
					'failed'  => __( 'Could not save', 'beaver-ai-chat' ),
				),
			)
		);
	}

	/**
	 * Render the conversation panel.
	 *
	 * @param WP_Post $post Lead.
	 */
	public static function render_meta_box( $post ) {
		echo '<div id="bac-lead-live" data-bac-lead="' . esc_attr( $post->ID ) . '">';
		echo self::panel_html( $post->ID ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built with escaping inside.
		echo '</div>';
	}

	/**
	 * The details block plus the rendered conversation. Returned as a string so
	 * the live poller can swap it in wholesale.
	 *
	 * @param int    $lead_id Lead post ID.
	 * @param string $context 'admin', or 'share' for the read-only link, which
	 *                        leaves out anything only the team should see.
	 * @return string
	 */
	public static function panel_html( $lead_id, $context = 'admin' ) {
		$post = get_post( $lead_id );
		if ( ! $post ) {
			return '';
		}

		$email    = get_post_meta( $lead_id, '_bac_email', true );
		$phone    = get_post_meta( $lead_id, '_bac_phone', true );
		$interest = get_post_meta( $lead_id, '_bac_interest', true );
		$summary  = get_post_meta( $lead_id, '_bac_summary', true );
		$page     = get_post_meta( $lead_id, '_bac_page', true );
		$turns    = (int) get_post_meta( $lead_id, '_bac_turns', true );
		$dash     = '<span class="bac-dash">&mdash;</span>';

		$rows = array(
			__( 'Email', 'beaver-ai-chat' )    => $email ? '<a href="mailto:' . esc_attr( $email ) . '">' . esc_html( $email ) . '</a>' : $dash,
			__( 'Phone', 'beaver-ai-chat' )    => $phone ? esc_html( $phone ) : $dash,
			__( 'Interest', 'beaver-ai-chat' ) => $interest ? esc_html( $interest ) : $dash,
			__( 'Summary', 'beaver-ai-chat' )  => $summary ? esc_html( $summary ) : $dash,
			__( 'Started on', 'beaver-ai-chat' ) => $page ? '<a href="' . esc_url( $page ) . '" target="_blank" rel="noopener">' . esc_html( $page ) . '</a>' : $dash,
			__( 'Messages', 'beaver-ai-chat' ) => esc_html( (string) $turns ),
		);

		// Whether the team has been told, and who has it, are the first things
		// anyone reading a lead wants to know, and are nobody else's business
		// on a shared link.
		if ( 'admin' === $context ) {
			$statuses = BAC_Workflow::statuses();
			$status   = BAC_Workflow::status( $lead_id );
			$owner    = (int) get_post_meta( $lead_id, BAC_Workflow::OWNER_META, true );
			$user     = $owner ? get_userdata( $owner ) : null;

			$rows[ __( 'Status', 'beaver-ai-chat' ) ] = esc_html( $statuses[ $status ] )
				. ( $user ? ' &middot; ' . esc_html( $user->display_name ) : '' );

			$rows[ __( 'Team alert', 'beaver-ai-chat' ) ] = BAC_Notify::status_label( $lead_id );

			$cost = (float) get_post_meta( $lead_id, '_bac_cost', true );
			$in   = (int) get_post_meta( $lead_id, '_bac_tokens_in', true );
			$out  = (int) get_post_meta( $lead_id, '_bac_tokens_out', true );

			if ( $in > 0 || $out > 0 ) {
				$rows[ __( 'Cost so far', 'beaver-ai-chat' ) ] = esc_html(
					sprintf(
						/* translators: 1: estimated cost, 2: input tokens, 3: output tokens. */
						__( '%1$s (%2$s in, %3$s out)', 'beaver-ai-chat' ),
						BAC_Usage::money( $cost ),
						number_format_i18n( $in ),
						number_format_i18n( $out )
					)
				);
			}
		}

		ob_start();
		?>
		<div class="bac-lead-head">
			<?php echo wp_kses_post( self::activity_label( $lead_id ) ); ?>
		</div>

		<table class="bac-lead-table">
			<?php foreach ( $rows as $label => $value ) : ?>
				<tr>
					<th><?php echo esc_html( $label ); ?></th>
					<td><?php echo wp_kses_post( $value ); ?></td>
				</tr>
			<?php endforeach; ?>
		</table>

		<div class="bac-thread">
			<?php echo self::thread_html( $lead_id, $post ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render the conversation as chat bubbles. Uses the structured copy when it
	 * exists, and falls back to the plain transcript for older records.
	 *
	 * @param int     $lead_id Lead post ID.
	 * @param WP_Post $post    Lead post.
	 * @return string
	 */
	private static function thread_html( $lead_id, $post ) {
		$stored   = get_post_meta( $lead_id, '_bac_messages', true );
		$messages = is_string( $stored ) && '' !== $stored ? json_decode( $stored, true ) : null;

		if ( ! is_array( $messages ) || empty( $messages ) ) {
			return '<pre class="bac-thread-raw">' . esc_html( $post->post_content ) . '</pre>';
		}

		$assistant = (string) get_post_meta( $lead_id, '_bac_assistant', true );
		$assistant = '' !== $assistant ? $assistant : BAC_Settings::get( 'assistant' );

		$out = '';
		foreach ( $messages as $m ) {
			$is_bot = ( isset( $m['role'] ) && 'assistant' === $m['role'] );
			$who    = $is_bot ? $assistant : __( 'Visitor', 'beaver-ai-chat' );
			$text   = isset( $m['content'] ) ? (string) $m['content'] : '';

			$out .= '<div class="bac-msg ' . ( $is_bot ? 'is-bot' : 'is-visitor' ) . '">';
			$out .= '<span class="bac-msg-who">' . esc_html( $who ) . '</span>';
			$out .= '<div class="bac-msg-body">' . nl2br( esc_html( $text ) ) . '</div>';
			$out .= '</div>';
		}

		return $out;
	}

	/** Live poll: return the current panel for an open conversation. */
	public static function ajax_poll() {
		if ( ! check_ajax_referer( 'bac_lead_poll', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'beaver-ai-chat' ) ) );
		}

		$lead_id = isset( $_POST['lead'] ) ? absint( $_POST['lead'] ) : 0;

		if ( ! $lead_id || ! current_user_can( 'edit_post', $lead_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'beaver-ai-chat' ) ) );
		}

		$post = get_post( $lead_id );
		if ( ! $post || BAC_LEAD_CPT !== $post->post_type ) {
			wp_send_json_error( array( 'message' => __( 'Not found.', 'beaver-ai-chat' ) ) );
		}

		wp_send_json_success(
			array(
				'html'     => self::panel_html( $lead_id ),
				'modified' => get_post_modified_time( 'U', true, $lead_id ),
				'title'    => get_the_title( $lead_id ),
			)
		);
	}

	/* ------------------------------------------------------------------ Storage */

	/**
	 * Store or update the conversation for a chat session.
	 *
	 * Best effort throughout: a failure here must never affect the visitor's
	 * reply.
	 *
	 * @param string $sid      Client session id.
	 * @param array  $messages Prior turns.
	 * @param string $reply    Latest assistant reply.
	 * @param array  $s        Settings.
	 * @param string $page     URL the chat happened on.
	 * @return int Lead ID, or 0.
	 */
	public static function store( $sid, $messages, $reply, $s, $page = '' ) {
		if ( empty( $s['leads_enabled'] ) ) {
			return 0;
		}

		$sid = preg_replace( '/[^A-Za-z0-9\-]/', '', (string) $sid );
		if ( '' === $sid ) {
			return 0;
		}

		// Full thread, including the reply we are about to send back.
		$thread = array();
		foreach ( $messages as $m ) {
			$thread[] = array(
				'role'    => ( 'assistant' === $m['role'] ) ? 'assistant' : 'user',
				'content' => (string) $m['content'],
			);
		}
		$thread[] = array(
			'role'    => 'assistant',
			'content' => (string) $reply,
		);

		$lines     = array();
		$user_text = '';
		$first_ask = '';
		$turns     = 0;

		foreach ( $thread as $m ) {
			$is_bot  = ( 'assistant' === $m['role'] );
			$lines[] = ( $is_bot ? $s['assistant'] : __( 'Visitor', 'beaver-ai-chat' ) ) . ': ' . $m['content'];

			if ( ! $is_bot ) {
				$turns++;
				$user_text .= ' ' . $m['content'];
				if ( '' === $first_ask ) {
					$first_ask = $m['content'];
				}
			}
		}

		$transcript = implode( "\n", $lines );
		$email      = self::find_email( $user_text );
		$phone      = self::find_phone( $user_text );
		$lead_id    = self::find_by_sid( $sid );

		// In "contact" mode nothing is written until the visitor identifies
		// themselves. The default is to record every conversation from the
		// first message, so the team can follow chats as they happen.
		if ( ! $lead_id && 'contact' === $s['lead_capture_mode'] && '' === $email ) {
			return 0;
		}

		$is_new = false;

		if ( ! $lead_id ) {
			$lead_id = wp_insert_post(
				array(
					'post_type'    => BAC_LEAD_CPT,
					'post_status'  => 'publish',
					'post_title'   => self::label( $first_ask ),
					'post_content' => $transcript,
				),
				true
			);

			if ( is_wp_error( $lead_id ) || ! $lead_id ) {
				return 0;
			}

			update_post_meta( $lead_id, '_bac_sid', $sid );
			update_post_meta( $lead_id, '_bac_assistant', $s['assistant'] );
			if ( $page ) {
				update_post_meta( $lead_id, '_bac_page', esc_url_raw( $page ) );
			}
			$is_new = true;
		} else {
			wp_update_post(
				array(
					'ID'           => $lead_id,
					'post_content' => $transcript,
				)
			);
		}

		update_post_meta( $lead_id, '_bac_messages', wp_json_encode( $thread ) );
		update_post_meta( $lead_id, '_bac_turns', $turns );

		// A conversation somebody had closed, moving again, needs answering
		// again. Only for existing records: a new one is already new.
		if ( ! $is_new ) {
			BAC_Workflow::maybe_reopen( $lead_id );
		}

		self::add_cost( $lead_id );

		if ( $email ) {
			update_post_meta( $lead_id, '_bac_email', $email );
		}
		if ( $phone ) {
			update_post_meta( $lead_id, '_bac_phone', $phone );
		}
		if ( '' === (string) get_post_meta( $lead_id, '_bac_summary', true ) && '' !== trim( $first_ask ) ) {
			update_post_meta( $lead_id, '_bac_summary', mb_substr( trim( $first_ask ), 0, 200 ) );
		}

		self::maybe_enrich( $lead_id, $turns, $s );

		if ( $is_new ) {
			/**
			 * Fires when a conversation is first recorded.
			 *
			 * @param int   $lead_id Lead post ID.
			 * @param array $s       Settings.
			 */
			do_action( 'bac_lead_created', $lead_id, $s );
		}

		// Hand the alerting decision over: whether this conversation deserves an
		// email, and whether now is the moment, is entirely BAC_Notify's problem.
		BAC_Notify::after_turn( $lead_id, $s );

		return (int) $lead_id;
	}

	/**
	 * Charge this conversation for the call that just answered it.
	 *
	 * The provider records usage without knowing which chat it belonged to, so
	 * the connection is made here, one turn later, while both are still in the
	 * same request.
	 *
	 * @param int $lead_id Lead post ID.
	 */
	private static function add_cost( $lead_id ) {
		$last = BAC_Usage::last();

		if ( ! $last ) {
			return;
		}

		foreach ( array( 'in', 'out' ) as $key ) {
			$meta = '_bac_tokens_' . $key;
			update_post_meta( $lead_id, $meta, (int) get_post_meta( $lead_id, $meta, true ) + (int) $last[ $key ] );
		}

		update_post_meta(
			$lead_id,
			'_bac_cost',
			round( (float) get_post_meta( $lead_id, '_bac_cost', true ) + (float) $last['cost'], 6 )
		);
	}

	/**
	 * A readable label for a conversation before the visitor gives a name.
	 *
	 * @param string $first_ask The visitor's opening message.
	 * @return string
	 */
	private static function label( $first_ask ) {
		$text = trim( preg_replace( '/\s+/', ' ', (string) $first_ask ) );

		if ( '' === $text ) {
			return __( 'Website visitor', 'beaver-ai-chat' );
		}
		if ( mb_strlen( $text ) > 60 ) {
			$text = rtrim( mb_substr( $text, 0, 60 ) ) . '…';
		}

		return $text;
	}

	/**
	 * Schedule the background summary, without spending a call on a one word
	 * chat and without repeating it on every turn.
	 *
	 * @param int   $lead_id Lead post ID.
	 * @param int   $turns   Visitor turns so far.
	 * @param array $s       Settings.
	 */
	private static function maybe_enrich( $lead_id, $turns, $s ) {
		if ( empty( $s['lead_ai_summary'] ) || $turns < 2 ) {
			return;
		}

		$done = (int) get_post_meta( $lead_id, '_bac_enriched_at', true );

		// First run at two turns, then refresh every third turn.
		if ( $done && 0 !== $turns % 3 ) {
			return;
		}
		if ( $done === $turns ) {
			return;
		}
		if ( wp_next_scheduled( 'bac_enrich_lead', array( $lead_id ) ) ) {
			return;
		}

		update_post_meta( $lead_id, '_bac_enriched_at', $turns );
		wp_schedule_single_event( time() + 3, 'bac_enrich_lead', array( $lead_id ) );
	}

	/**
	 * Find an existing conversation by its client session id.
	 *
	 * @param string $sid Session id.
	 * @return int
	 */
	public static function find_by_sid( $sid ) {
		$ids = get_posts(
			array(
				'post_type'      => BAC_LEAD_CPT,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_key'       => '_bac_sid',   // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $sid,          // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * Background: one model call to fill in the visitor's name and a summary.
	 *
	 * @param int $lead_id Lead post ID.
	 */
	public static function enrich( $lead_id ) {
		$lead = get_post( $lead_id );
		if ( ! $lead || BAC_LEAD_CPT !== $lead->post_type ) {
			return;
		}

		$s = BAC_Settings::get();
		if ( '' === BAC_Settings::api_key() || empty( $s['lead_ai_summary'] ) ) {
			return;
		}

		$result = BAC_Provider::chat(
			BAC_Prompt::extraction_prompt( $s ),
			array(
				array(
					'role'    => 'user',
					'content' => $lead->post_content,
				),
			),
			$s
		);

		if ( is_wp_error( $result ) ) {
			return;
		}

		$json = trim( (string) $result );
		if ( preg_match( '/\{.*\}/s', $json, $match ) ) {
			$json = $match[0];
		}

		$data = json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			return;
		}

		// A real name replaces the opening-question label.
		if ( ! empty( $data['name'] ) ) {
			$name = sanitize_text_field( (string) $data['name'] );
			if ( '' !== $name ) {
				update_post_meta( $lead_id, '_bac_name', $name );
				wp_update_post(
					array(
						'ID'         => $lead_id,
						'post_title' => $name,
					)
				);
			}
		}

		// What the assistant could not answer is worth more than what it could.
		BAC_Gaps::store( $lead_id, isset( $data['unanswered'] ) ? (array) $data['unanswered'] : array() );

		$map = array(
			'email'    => '_bac_email',
			'phone'    => '_bac_phone',
			'interest' => '_bac_interest',
			'summary'  => '_bac_summary',
		);

		foreach ( $map as $field => $meta_key ) {
			if ( empty( $data[ $field ] ) ) {
				continue;
			}

			$value = ( 'email' === $field )
				? sanitize_email( (string) $data[ $field ] )
				: sanitize_text_field( (string) $data[ $field ] );

			if ( '' === $value ) {
				continue;
			}

			// Never let the model overwrite a detail we read verbatim.
			if ( in_array( $field, array( 'email', 'phone' ), true ) && '' !== (string) get_post_meta( $lead_id, $meta_key, true ) ) {
				continue;
			}

			update_post_meta( $lead_id, $meta_key, $value );
		}
	}

	/**
	 * Email the team about a conversation worth following up.
	 *
	 * Kept as the public entry point it always was; the email itself now lives
	 * in BAC_Notify, which also decides when one should go out at all.
	 *
	 * @param int   $lead_id Lead post ID.
	 * @param array $s       Settings.
	 */
	public static function notify( $lead_id, $s ) {
		BAC_Notify::send( $lead_id, $s, 'lead' );
	}

	/**
	 * First email address in a blob of visitor text.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function find_email( $text ) {
		if ( preg_match( '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', (string) $text, $m ) ) {
			return sanitize_email( $m[0] );
		}
		return '';
	}

	/**
	 * First plausible phone number in a blob of visitor text.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function find_phone( $text ) {
		if ( preg_match( '/\+?\d[\d\s()\-]{6,}\d/', (string) $text, $m ) ) {
			$candidate = trim( $m[0] );
			if ( preg_match_all( '/\d/', $candidate ) >= 7 ) {
				return mb_substr( $candidate, 0, 24 );
			}
		}
		return '';
	}
}
