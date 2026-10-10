<?php
/**
 * Temporary user lifecycle.
 *
 * @package BeaverAccess
 */

defined( 'ABSPATH' ) || exit;

/**
 * Creates and retires the accounts links sign in as.
 *
 * A role-based link makes its own user rather than borrowing the client's.
 * That matters for the audit trail: every action is attributed to a visibly
 * temporary account, so a year later nobody is wondering why the owner
 * apparently edited a template at 2am. It also means access can be withdrawn
 * by deleting one account, without touching anybody's password.
 *
 * @since 1.0.0
 */
class Beaver_Access_Users {

	const META_LINK = '_beaver_access_link';

	/**
	 * User meta holding the session tokens each link opened.
	 *
	 * @since 1.0.3
	 */
	const META_SESSIONS = '_beaver_access_sessions';

	/**
	 * Returns the user a link should sign in as, creating one if needed.
	 *
	 * @since 1.0.0
	 *
	 * @param object $link Link row.
	 * @return int|WP_Error User ID, or an error.
	 */
	public static function resolve( $link ) {
		if ( (int) $link->target_user > 0 ) {
			return get_userdata( (int) $link->target_user ) ? (int) $link->target_user : new WP_Error( 'gone', 'User no longer exists.' );
		}

		// Reuse the account from an earlier use of the same link.
		if ( (int) $link->temp_user > 0 && get_userdata( (int) $link->temp_user ) ) {
			return (int) $link->temp_user;
		}

		if ( ! get_role( $link->role ) ) {
			return new WP_Error( 'no_role', 'Role no longer exists.' );
		}

		$login = 'beaver-access-' . $link->selector;
		$host  = wp_parse_url( home_url(), PHP_URL_HOST );

		$user_id = wp_insert_user(
			array(
				'user_login'   => $login,
				'user_pass'    => wp_generate_password( 64, true, true ),
				// Unroutable by design: this account must never be able to
				// start a password reset and become permanent.
				'user_email'   => $login . '@invalid.' . ( $host ? $host : 'localhost' ),
				'display_name' => '' !== $link->label
					? sprintf( '%s (temporary access)', $link->label )
					: __( 'Temporary access', 'beaver-access' ),
				'role'         => $link->role,
			)
		);

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		update_user_meta( $user_id, self::META_LINK, (int) $link->id );
		Beaver_Access_Links::set_temp_user( (int) $link->id, (int) $user_id );

		return (int) $user_id;
	}

	/**
	 * Ends a link's access and removes anything it created.
	 *
	 * @since 1.0.0
	 *
	 * @param object $link Link row.
	 */
	public static function retire( $link ) {
		$user_id = (int) $link->temp_user;

		if ( $user_id <= 0 ) {
			// Nothing was created, but a link pointed at a real account may
			// still have a live session that should end.
			if ( (int) $link->target_user > 0 && ! empty( $link->last_used_at ) ) {
				self::end_link_sessions( (int) $link->target_user, (int) $link->id );
			}

			return;
		}

		if ( ! get_userdata( $user_id ) ) {
			return;
		}

		self::end_sessions( $user_id );

		require_once ABSPATH . 'wp-admin/includes/user.php';

		/*
		 * Anything the temporary account authored is reassigned to whoever
		 * issued the link, so deleting it never deletes a post with it.
		 */
		$reassign = (int) $link->created_by > 0 && get_userdata( (int) $link->created_by )
			? (int) $link->created_by
			: null;

		wp_delete_user( $user_id, $reassign );

		Beaver_Access_Links::set_temp_user( (int) $link->id, 0 );
	}

	/**
	 * Opens a session for a link that ends when the link does.
	 *
	 * WordPress gives a normal sign-in two days. Access through a link should
	 * stop at the link's expiry, not up to two days later, and not only when
	 * the hourly cleanup next runs. The session carries its own expiry, which
	 * WordPress checks on every request.
	 *
	 * The token is kept against the link, so revoking ends this session and
	 * nothing else. Ending every session of an existing account would also
	 * sign its owner out of their own browsers.
	 *
	 * @since 1.0.3
	 *
	 * @param int    $user_id User signing in.
	 * @param object $link    Link used.
	 * @return string Session token for wp_set_auth_cookie().
	 */
	public static function open_session( $user_id, $link ) {
		$expires = strtotime( $link->expires_at . ' UTC' );
		$cap     = time() + ( 2 * DAY_IN_SECONDS );
		$token   = WP_Session_Tokens::get_instance( (int) $user_id )->create( (int) min( $expires, $cap ) );

		$sessions = get_user_meta( (int) $user_id, self::META_SESSIONS, true );
		$sessions = is_array( $sessions ) ? $sessions : array();

		$sessions[ (int) $link->id ][] = $token;

		update_user_meta( (int) $user_id, self::META_SESSIONS, $sessions );

		return $token;
	}

	/**
	 * Ends the sessions one link opened on an account.
	 *
	 * Links issued before 1.0.3 recorded no tokens. For those the only way to
	 * end the link's session is to end them all, as 1.0.2 did, which now
	 * happens once rather than hourly.
	 *
	 * @since 1.0.3
	 *
	 * @param int $user_id Account.
	 * @param int $link_id Link.
	 */
	private static function end_link_sessions( $user_id, $link_id ) {
		$sessions = get_user_meta( $user_id, self::META_SESSIONS, true );
		$sessions = is_array( $sessions ) ? $sessions : array();

		if ( ! isset( $sessions[ $link_id ] ) ) {
			self::end_sessions( $user_id );

			return;
		}

		$manager = WP_Session_Tokens::get_instance( $user_id );

		foreach ( (array) $sessions[ $link_id ] as $token ) {
			$manager->destroy( (string) $token );
		}

		unset( $sessions[ $link_id ] );

		if ( empty( $sessions ) ) {
			delete_user_meta( $user_id, self::META_SESSIONS );
		} else {
			update_user_meta( $user_id, self::META_SESSIONS, $sessions );
		}
	}

	/**
	 * Destroys every session a user has.
	 *
	 * @since 1.0.0
	 *
	 * @param int $user_id User ID.
	 */
	private static function end_sessions( $user_id ) {
		$tokens = WP_Session_Tokens::get_instance( (int) $user_id );

		if ( $tokens ) {
			$tokens->destroy_all();
		}
	}

	/**
	 * Flags temporary accounts in the users list.
	 *
	 * @since 1.0.0
	 *
	 * @param string[] $actions Row actions.
	 * @param WP_User  $user    The user.
	 * @return string[] Filtered actions.
	 */
	public static function row_actions( $actions, $user ) {
		if ( get_user_meta( $user->ID, self::META_LINK, true ) ) {
			$actions['beaver_access'] = '<span style="color:#b26200">' . esc_html__( 'Temporary access', 'beaver-access' ) . '</span>';
		}

		return $actions;
	}
}
