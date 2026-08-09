<?php
/**
 * Turning a list of conversations into a queue somebody works through.
 *
 * Alerts tell you a lead arrived. They cannot tell you whether anyone dealt
 * with it, which is how two people ring the same visitor and nobody rings the
 * next one. Each conversation therefore carries a state and, once someone picks
 * it up, an owner:
 *
 *   New      nobody has touched it
 *   Working  somebody has it
 *   Done     dealt with
 *
 * A conversation marked done that starts moving again goes back to new, because
 * a visitor who came back needs answering again.
 *
 * @package BeaverAIChat
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class BAC_Workflow
 */
class BAC_Workflow {

	/** Meta holding the state. */
	const STATUS_META = '_bac_status';

	/** Meta holding the user who picked it up. */
	const OWNER_META = '_bac_owner';

	/** Transient holding the count for the menu bubble. */
	const COUNT_KEY = 'bac_new_count';

	/** Wire up hooks. */
	public static function init() {
		add_action( 'bac_lead_created', array( __CLASS__, 'on_created' ), 10, 1 );

		if ( ! is_admin() ) {
			return;
		}

		add_filter( 'manage_' . BAC_LEAD_CPT . '_posts_columns', array( __CLASS__, 'columns' ), 20 );
		add_action( 'manage_' . BAC_LEAD_CPT . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
		add_filter( 'views_edit-' . BAC_LEAD_CPT, array( __CLASS__, 'views' ) );
		add_action( 'restrict_manage_posts', array( __CLASS__, 'filters' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'filter_query' ) );
		add_filter( 'bulk_actions-edit-' . BAC_LEAD_CPT, array( __CLASS__, 'bulk_actions' ) );
		add_filter( 'handle_bulk_actions-edit-' . BAC_LEAD_CPT, array( __CLASS__, 'handle_bulk' ), 10, 3 );
		add_action( 'admin_menu', array( __CLASS__, 'menu_bubble' ), 100 );
		add_action( 'wp_ajax_bac_set_status', array( __CLASS__, 'ajax_set_status' ) );
	}

	/**
	 * The states a conversation can be in.
	 *
	 * @return array
	 */
	public static function statuses() {
		return array(
			'new'     => __( 'New', 'beaver-ai-chat' ),
			'working' => __( 'Working on it', 'beaver-ai-chat' ),
			'done'    => __( 'Done', 'beaver-ai-chat' ),
		);
	}

	/**
	 * The state of one conversation, defaulting to new.
	 *
	 * @param int $lead_id Lead post ID.
	 * @return string
	 */
	public static function status( $lead_id ) {
		$status = (string) get_post_meta( $lead_id, self::STATUS_META, true );

		return array_key_exists( $status, self::statuses() ) ? $status : 'new';
	}

	/**
	 * Set the state, and record who did it.
	 *
	 * @param int    $lead_id Lead post ID.
	 * @param string $status  new, working or done.
	 * @param int    $user_id Who owns it now, or 0 to leave the owner alone.
	 */
	public static function set_status( $lead_id, $status, $user_id = 0 ) {
		if ( ! array_key_exists( $status, self::statuses() ) ) {
			return;
		}

		update_post_meta( $lead_id, self::STATUS_META, $status );

		if ( 'new' === $status ) {
			delete_post_meta( $lead_id, self::OWNER_META );
		} elseif ( $user_id > 0 ) {
			update_post_meta( $lead_id, self::OWNER_META, (int) $user_id );
		}

		self::flush_count();

		/**
		 * Fires when a conversation changes state.
		 *
		 * @param int    $lead_id Lead post ID.
		 * @param string $status  New state.
		 * @param int    $user_id Who changed it.
		 */
		do_action( 'bac_lead_status', $lead_id, $status, (int) $user_id );
	}

	/**
	 * A new conversation starts as new.
	 *
	 * @param int $lead_id Lead post ID.
	 */
	public static function on_created( $lead_id ) {
		update_post_meta( $lead_id, self::STATUS_META, 'new' );
		self::flush_count();
	}

	/**
	 * A conversation that was finished with, and then moved again, is not
	 * finished with. Called from the store on every turn.
	 *
	 * @param int $lead_id Lead post ID.
	 */
	public static function maybe_reopen( $lead_id ) {
		if ( 'done' !== self::status( $lead_id ) ) {
			return;
		}

		/*
		 * Straight to the meta rather than through set_status(), because this
		 * deliberately keeps the owner. Pushing a conversation back to new by
		 * hand means "somebody else take this"; a visitor coming back does not,
		 * and the person who dealt with them last is the right person to see it
		 * again. It still lands in the New queue either way.
		 */
		update_post_meta( $lead_id, self::STATUS_META, 'new' );
		update_post_meta( $lead_id, '_bac_reopened', current_time( 'mysql' ) );
		self::flush_count();

		/**
		 * Fires when a closed conversation is reopened by the visitor coming
		 * back. Use it to reassign, or to tell whoever closed it.
		 *
		 * @param int $lead_id Lead post ID.
		 */
		do_action( 'bac_lead_reopened', $lead_id );
	}

	/* --------------------------------------------------------------- Counting */

	/**
	 * How many conversations are waiting, cached because it is read on every
	 * admin page load for the menu bubble.
	 *
	 * @param string $status Status to count.
	 * @return int
	 */
	public static function count( $status = 'new' ) {
		if ( 'new' === $status ) {
			$cached = get_transient( self::COUNT_KEY );
			if ( false !== $cached ) {
				return (int) $cached;
			}
		}

		$query = new WP_Query(
			array(
				'post_type'      => BAC_LEAD_CPT,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => self::status_meta_query( $status ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			)
		);

		$count = (int) $query->found_posts;

		if ( 'new' === $status ) {
			set_transient( self::COUNT_KEY, $count, 5 * MINUTE_IN_SECONDS );
		}

		return $count;
	}

	/** Drop the cached count. */
	public static function flush_count() {
		delete_transient( self::COUNT_KEY );
	}

	/**
	 * Matching a status, treating "no meta at all" as new so conversations
	 * recorded before this existed still show up in the queue.
	 *
	 * @param string $status Status.
	 * @return array
	 */
	private static function status_meta_query( $status ) {
		if ( 'new' !== $status ) {
			return array(
				array(
					'key'   => self::STATUS_META,
					'value' => $status,
				),
			);
		}

		return array(
			'relation' => 'OR',
			array(
				'key'   => self::STATUS_META,
				'value' => 'new',
			),
			array(
				'key'     => self::STATUS_META,
				'compare' => 'NOT EXISTS',
			),
		);
	}

	/* ------------------------------------------------------------ List screen */

	/**
	 * Add the state and owner columns.
	 *
	 * @param array $cols Columns.
	 * @return array
	 */
	public static function columns( $cols ) {
		$out = array();

		foreach ( $cols as $key => $label ) {
			$out[ $key ] = $label;

			// Right after the visitor, where the eye lands first.
			if ( 'title' === $key ) {
				$out['bac_status'] = __( 'Status', 'beaver-ai-chat' );
			}
		}

		$out['bac_owner'] = __( 'With', 'beaver-ai-chat' );

		return $out;
	}

	/**
	 * Render the state and owner columns.
	 *
	 * @param string $col     Column key.
	 * @param int    $post_id Post ID.
	 */
	public static function column( $col, $post_id ) {
		if ( 'bac_status' === $col ) {
			$status = self::status( $post_id );

			printf( '<select class="bac-status" data-bac-lead="%d" data-bac-current="%s">', (int) $post_id, esc_attr( $status ) );
			foreach ( self::statuses() as $value => $label ) {
				printf(
					'<option value="%s" %s>%s</option>',
					esc_attr( $value ),
					selected( $status, $value, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- selected() returns a safe attribute.
					esc_html( $label )
				);
			}
			echo '</select><span class="bac-status-note" aria-live="polite"></span>';
			return;
		}

		if ( 'bac_owner' === $col ) {
			$owner = (int) get_post_meta( $post_id, self::OWNER_META, true );

			if ( ! $owner ) {
				echo '<span class="bac-dash">&mdash;</span>';
				return;
			}

			$user = get_userdata( $owner );
			echo $user ? esc_html( $user->display_name ) : '<span class="bac-dash">&mdash;</span>';
		}
	}

	/**
	 * The All / New / Working / Done links above the list, with counts.
	 *
	 * @param array $views Existing views.
	 * @return array
	 */
	public static function views( $views ) {
		$current = isset( $_GET['bac_status'] ) ? sanitize_key( $_GET['bac_status'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a list filter, not an action.
		$base    = admin_url( 'edit.php?post_type=' . BAC_LEAD_CPT );

		// Keep only "All"; the post statuses of a private type say nothing
		// useful next to the states people actually work through.
		$out = array();

		if ( isset( $views['all'] ) ) {
			$out['all'] = '<a href="' . esc_url( $base ) . '"' . ( '' === $current ? ' class="current"' : '' ) . '>'
				. esc_html__( 'All', 'beaver-ai-chat' ) . '</a>';
		}

		foreach ( self::statuses() as $status => $label ) {
			$count = self::count( $status );
			$url   = add_query_arg( 'bac_status', $status, $base );

			$out[ 'bac_' . $status ] = '<a href="' . esc_url( $url ) . '"' . ( $current === $status ? ' class="current"' : '' ) . '>'
				. esc_html( $label ) . ' <span class="count">(' . (int) $count . ')</span></a>';
		}

		return $out;
	}

	/** The extra dropdown above the list. */
	public static function filters() {
		$screen = get_current_screen();

		if ( ! $screen || BAC_LEAD_CPT !== $screen->post_type || 'edit' !== $screen->base ) {
			return;
		}

		$current = isset( $_GET['bac_has'] ) ? sanitize_key( $_GET['bac_has'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a list filter, not an action.

		$choices = array(
			''         => __( 'Everyone', 'beaver-ai-chat' ),
			'contact'  => __( 'Left contact details', 'beaver-ai-chat' ),
			'nothing'  => __( 'No contact details', 'beaver-ai-chat' ),
			'callback' => __( 'Asked for a callback', 'beaver-ai-chat' ),
			'mine'     => __( 'With me', 'beaver-ai-chat' ),
		);

		echo '<label class="screen-reader-text" for="bac-has">' . esc_html__( 'Filter conversations', 'beaver-ai-chat' ) . '</label>';
		echo '<select name="bac_has" id="bac-has">';
		foreach ( $choices as $value => $label ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $value ),
				selected( $current, $value, false ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- selected() returns a safe attribute.
				esc_html( $label )
			);
		}
		echo '</select>';
	}

	/**
	 * Apply the state link and the dropdown to the list query.
	 *
	 * @param WP_Query $query Query.
	 */
	public static function filter_query( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() || BAC_LEAD_CPT !== $query->get( 'post_type' ) ) {
			return;
		}

		$meta = (array) $query->get( 'meta_query' );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- list filters, not actions.
		$status = isset( $_GET['bac_status'] ) ? sanitize_key( $_GET['bac_status'] ) : '';
		$has    = isset( $_GET['bac_has'] ) ? sanitize_key( $_GET['bac_has'] ) : '';
		// phpcs:enable

		if ( array_key_exists( $status, self::statuses() ) ) {
			$meta[] = self::status_meta_query( $status );
		}

		switch ( $has ) {
			case 'contact':
				$meta[] = array(
					'relation' => 'OR',
					array(
						'key'     => '_bac_email',
						'compare' => 'EXISTS',
					),
					array(
						'key'     => '_bac_phone',
						'compare' => 'EXISTS',
					),
				);
				break;

			case 'nothing':
				$meta[] = array(
					'key'     => '_bac_email',
					'compare' => 'NOT EXISTS',
				);
				break;

			case 'callback':
				$meta[] = array(
					'key'     => '_bac_contact_requested',
					'compare' => 'EXISTS',
				);
				break;

			case 'mine':
				$meta[] = array(
					'key'   => self::OWNER_META,
					'value' => get_current_user_id(),
				);
				break;
		}

		if ( ! empty( $meta ) ) {
			$query->set( 'meta_query', $meta );
		}
	}

	/**
	 * Bulk actions for working through a backlog.
	 *
	 * @param array $actions Existing actions.
	 * @return array
	 */
	public static function bulk_actions( $actions ) {
		$actions['bac_done']    = __( 'Mark as done', 'beaver-ai-chat' );
		$actions['bac_working'] = __( 'Assign to me', 'beaver-ai-chat' );
		$actions['bac_new']     = __( 'Move back to new', 'beaver-ai-chat' );

		return $actions;
	}

	/**
	 * Apply a bulk action.
	 *
	 * @param string $redirect Redirect URL.
	 * @param string $action   Chosen action.
	 * @param array  $ids      Post IDs.
	 * @return string
	 */
	public static function handle_bulk( $redirect, $action, $ids ) {
		$map = array(
			'bac_done'    => 'done',
			'bac_working' => 'working',
			'bac_new'     => 'new',
		);

		if ( ! isset( $map[ $action ] ) ) {
			return $redirect;
		}

		$done = 0;

		foreach ( (array) $ids as $id ) {
			if ( ! current_user_can( 'edit_post', $id ) ) {
				continue;
			}
			self::set_status( (int) $id, $map[ $action ], get_current_user_id() );
			$done++;
		}

		return add_query_arg( 'bac_updated', $done, $redirect );
	}

	/** Change one conversation's state from the list, without a page reload. */
	public static function ajax_set_status() {
		if ( ! check_ajax_referer( 'bac_set_status', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'beaver-ai-chat' ) ) );
		}

		$lead_id = isset( $_POST['lead'] ) ? absint( $_POST['lead'] ) : 0;
		$status  = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';

		if ( ! $lead_id || ! current_user_can( 'edit_post', $lead_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Not allowed.', 'beaver-ai-chat' ) ) );
		}

		$post = get_post( $lead_id );
		if ( ! $post || BAC_LEAD_CPT !== $post->post_type || ! array_key_exists( $status, self::statuses() ) ) {
			wp_send_json_error( array( 'message' => __( 'Not found.', 'beaver-ai-chat' ) ) );
		}

		self::set_status( $lead_id, $status, get_current_user_id() );

		$owner = (int) get_post_meta( $lead_id, self::OWNER_META, true );
		$user  = $owner ? get_userdata( $owner ) : null;

		wp_send_json_success(
			array(
				'owner'   => $user ? $user->display_name : '',
				'message' => __( 'Saved', 'beaver-ai-chat' ),
			)
		);
	}

	/**
	 * Put a count of waiting conversations on the menu, the way WordPress does
	 * for comments and updates, so it is visible without opening anything.
	 */
	public static function menu_bubble() {
		$count = self::count( 'new' );

		if ( $count < 1 ) {
			return;
		}

		global $menu, $submenu;

		$bubble = ' <span class="update-plugins count-' . (int) $count . '"><span class="update-count">'
			. number_format_i18n( $count ) . '</span></span>';

		foreach ( (array) $menu as $key => $item ) {
			if ( isset( $item[2] ) && BAC_Admin::PAGE === $item[2] ) {
				$menu[ $key ][0] .= $bubble; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- the documented way to add a menu count.
				break;
			}
		}

		if ( empty( $submenu[ BAC_Admin::PAGE ] ) ) {
			return;
		}

		foreach ( (array) $submenu[ BAC_Admin::PAGE ] as $key => $item ) {
			if ( isset( $item[2] ) && false !== strpos( (string) $item[2], 'post_type=' . BAC_LEAD_CPT ) ) {
				$submenu[ BAC_Admin::PAGE ][ $key ][0] .= $bubble; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- as above.
				break;
			}
		}
	}
}
