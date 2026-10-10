<?php
/**
 * Link storage and token handling.
 *
 * @package BeaverAccess
 */

defined( 'ABSPATH' ) || exit;

/**
 * Creates, verifies and revokes access links.
 *
 * Tokens use the split selector/verifier design that WordPress itself uses for
 * password resets, for two reasons. The selector is a plain indexed column, so
 * finding a link is one keyed lookup rather than a scan of every row compared
 * one at a time — which is both faster and immune to timing analysis. The
 * verifier is only ever stored hashed, so a leaked database backup contains no
 * usable link: an attacker with the whole table still cannot log in.
 *
 * @since 1.0.0
 */
class Beaver_Access_Links {

	const DB_VERSION = '1';

	/**
	 * Option listing links whose access has already been ended.
	 *
	 * @since 1.0.3
	 */
	const RETIRED_OPTION = 'beaver_access_retired';

	/**
	 * Shortest life a link may be given.
	 *
	 * Below this the link expires before it can realistically be sent, read and
	 * used.
	 *
	 * @since 1.0.2
	 */
	const MIN_MINUTES = 5;

	/**
	 * Longest life a link may be given.
	 *
	 * Thirty days. Anything standing open longer than that is not temporary
	 * access, it is an account, and should be created as one.
	 *
	 * @since 1.0.2
	 */
	const MAX_MINUTES = 43200;

	/**
	 * Returns the links table name.
	 *
	 * @since 1.0.0
	 *
	 * @return string Table name.
	 */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'beaver_access_links';
	}

	/**
	 * Creates the table.
	 *
	 * @since 1.0.0
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				selector varchar(24) NOT NULL DEFAULT '',
				verifier char(64) NOT NULL DEFAULT '',
				label varchar(191) NOT NULL DEFAULT '',
				role varchar(64) NOT NULL DEFAULT '',
				target_user bigint(20) unsigned NOT NULL DEFAULT 0,
				temp_user bigint(20) unsigned NOT NULL DEFAULT 0,
				max_uses smallint(5) unsigned NOT NULL DEFAULT 1,
				used smallint(5) unsigned NOT NULL DEFAULT 0,
				lock_ip tinyint(1) NOT NULL DEFAULT 0,
				bound_ip varchar(45) NOT NULL DEFAULT '',
				created_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				expires_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				last_used_at datetime NULL DEFAULT NULL,
				revoked_at datetime NULL DEFAULT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY selector (selector),
				KEY expires_at (expires_at)
			) {$collate};"
		);

		update_option( 'beaver_access_db_version', self::DB_VERSION, false );
	}

	/**
	 * Issues a new link.
	 *
	 * @since 1.0.0
	 *
	 * @param array $args {
	 *     @type string $label       What the link is for.
	 *     @type string $role        Role to grant a temporary user.
	 *     @type int    $target_user Log in as this existing user instead.
	 *     @type int    $minutes     Minutes until expiry.
	 *     @type int    $max_uses    Times the link may be used.
	 *     @type bool   $lock_ip     Bind the link to the first address that uses it.
	 * }
	 * @return array|WP_Error The new link with its one-time URL, or an error.
	 */
	public static function create( $args ) {
		global $wpdb;

		$args = wp_parse_args(
			$args,
			array(
				'label'       => '',
				'role'        => 'administrator',
				'target_user' => 0,
				'minutes'     => DAY_IN_SECONDS / MINUTE_IN_SECONDS,
				'max_uses'    => 1,
				'lock_ip'     => false,
			)
		);

		$target = (int) $args['target_user'];
		$role   = sanitize_key( $args['role'] );

		if ( $target > 0 ) {
			$user = get_userdata( $target );

			if ( ! $user ) {
				return new WP_Error( 'beaver_access_no_user', __( 'That user does not exist.', 'beaver-access' ) );
			}

			/*
			 * Signing in as an existing account hands out everything that
			 * account can do, so it faces the same ceiling as a role link.
			 * Before 1.0.3 this branch skipped the check entirely, and anyone
			 * able to reach this screen could mint a link into an
			 * administrator's account.
			 */
			if ( ! self::can_target( $user ) ) {
				return new WP_Error( 'beaver_access_escalation', __( 'You cannot create a link for an account with more capabilities than your own.', 'beaver-access' ) );
			}

			$role = '';
		} else {
			if ( ! get_role( $role ) ) {
				return new WP_Error( 'beaver_access_no_role', __( 'That role does not exist on this site.', 'beaver-access' ) );
			}

			/*
			 * A link cannot hand out more power than the person issuing it has.
			 * Without this an editor-level account able to reach this screen
			 * could mint itself an administrator link.
			 */
			if ( ! self::can_grant( $role ) ) {
				return new WP_Error( 'beaver_access_escalation', __( 'You cannot create a link for a role with more capabilities than your own.', 'beaver-access' ) );
			}
		}

		$selector = wp_generate_password( 16, false, false );
		$verifier = wp_generate_password( 40, false, false );

		$minutes  = (int) max( self::MIN_MINUTES, min( self::MAX_MINUTES, (int) $args['minutes'] ) );
		$max_uses = (int) max( 1, min( 100, (int) $args['max_uses'] ) );

		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'selector'    => $selector,
				// sha256 rather than a password hash: the input is 40 random
				// characters, so there is nothing to brute force, and the check
				// happens on a request that has not authenticated yet.
				'verifier'    => hash( 'sha256', $verifier ),
				'label'       => sanitize_text_field( $args['label'] ),
				'role'        => $role,
				'target_user' => $target,
				'max_uses'    => $max_uses,
				'lock_ip'     => empty( $args['lock_ip'] ) ? 0 : 1,
				'created_by'  => get_current_user_id(),
				'created_at'  => current_time( 'mysql', true ),
				'expires_at'  => gmdate( 'Y-m-d H:i:s', time() + ( $minutes * MINUTE_IN_SECONDS ) ),
			),
			array( '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s' )
		);

		if ( ! $inserted ) {
			return new WP_Error( 'beaver_access_db', __( 'The link could not be saved.', 'beaver-access' ) );
		}

		$link = self::get( (int) $wpdb->insert_id );

		Beaver_Access_Log::record( (int) $wpdb->insert_id, 'created', '' );

		/*
		 * The only moment the full token exists. It is returned, shown once,
		 * and never stored — which is what makes a database leak harmless.
		 */
		$link->url = add_query_arg( Beaver_Access_Session::QUERY_VAR, $selector . '.' . $verifier, home_url( '/' ) );

		return $link;
	}

	/**
	 * Whether the current user may issue a link for a role.
	 *
	 * @since 1.0.0
	 *
	 * @param string $role Role slug.
	 * @return bool Whether it is allowed.
	 */
	public static function can_grant( $role ) {
		// Someone running WP-CLI already controls the server, and has no
		// signed-in user to measure against unless --user was passed.
		if ( self::is_cli_without_user() ) {
			return true;
		}

		// Issuing a link is handing out a role, so the bar is the same as
		// changing somebody's role in the users screen.
		if ( ! current_user_can( 'promote_users' ) ) {
			return false;
		}

		if ( is_multisite() && is_super_admin() ) {
			return true;
		}

		if ( ! function_exists( 'get_editable_roles' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}

		/*
		 * get_editable_roles() is WordPress's own answer to "which roles may
		 * this user assign", and it respects the editable_roles filter that
		 * multisite and membership plugins hook into.
		 *
		 * Comparing capability lists by hand looks stricter but is simply
		 * wrong: the editor role carries manage_links, which a modern
		 * administrator does not have unless the legacy Links Manager is
		 * switched on — so an administrator would be refused permission to
		 * issue an editor link.
		 */
		return array_key_exists( $role, (array) get_editable_roles() );
	}

	/**
	 * Whether the current user may issue a link into an existing account.
	 *
	 * Three conditions, all required: WordPress must let them edit that user,
	 * which already keeps a site administrator away from a network super
	 * admin; they must be able to grant every role the account holds; and a
	 * super admin can only be targeted by another super admin.
	 *
	 * @since 1.0.3
	 *
	 * @param WP_User|int $user The account.
	 * @return bool Whether it is allowed.
	 */
	public static function can_target( $user ) {
		$user = $user instanceof WP_User ? $user : get_userdata( (int) $user );

		if ( ! $user || ! $user->exists() ) {
			return false;
		}

		if ( self::is_cli_without_user() ) {
			return true;
		}

		if ( ! current_user_can( 'promote_users' ) || ! current_user_can( 'edit_user', $user->ID ) ) {
			return false;
		}

		if ( is_multisite() && is_super_admin( $user->ID ) && ! is_super_admin() ) {
			return false;
		}

		foreach ( (array) $user->roles as $role ) {
			if ( ! self::can_grant( $role ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether this is a WP-CLI command run without --user.
	 *
	 * @since 1.0.3
	 *
	 * @return bool Whether it is.
	 */
	private static function is_cli_without_user() {
		return defined( 'WP_CLI' ) && WP_CLI && 0 === get_current_user_id();
	}

	/**
	 * Finds a link by its selector.
	 *
	 * @since 1.0.0
	 *
	 * @param string $selector Selector.
	 * @return object|null Row, or null.
	 */
	public static function by_selector( $selector ) {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE selector = %s LIMIT 1", $selector ) );
	}

	/**
	 * Loads one link.
	 *
	 * @since 1.0.0
	 *
	 * @param int $id Link ID.
	 * @return object|null Row, or null.
	 */
	public static function get( $id ) {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", (int) $id ) );
	}

	/**
	 * Lists links, newest first.
	 *
	 * @since 1.0.0
	 *
	 * @param int $limit Maximum rows.
	 * @return array<int,object> Rows.
	 */
	public static function all( $limit = 100 ) {
		global $wpdb;

		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", (int) $limit ) );
	}

	/**
	 * Explains why a link cannot be used, if it cannot.
	 *
	 * @since 1.0.0
	 *
	 * @param object $link Link row.
	 * @param string $ip   Address making the request.
	 * @return string Machine-readable reason, or an empty string when usable.
	 */
	public static function reason_unusable( $link, $ip ) {
		if ( ! empty( $link->revoked_at ) ) {
			return 'revoked';
		}

		if ( strtotime( $link->expires_at . ' UTC' ) < time() ) {
			return 'expired';
		}

		if ( (int) $link->used >= (int) $link->max_uses ) {
			return 'exhausted';
		}

		if ( (int) $link->lock_ip && '' !== $link->bound_ip && ! hash_equals( $link->bound_ip, $ip ) ) {
			return 'wrong_ip';
		}

		return '';
	}

	/**
	 * Claims one use of a link, atomically.
	 *
	 * The count is raised by a single UPDATE that only matches while a use is
	 * still left, the link is live, and the address is allowed. Reading the
	 * count and writing it back, as 1.0.2 did, let two requests arriving
	 * together both see "unused" and both sign in on a single-use link.
	 * Whichever UPDATE the database applies second matches no row and is
	 * refused.
	 *
	 * @since 1.0.3
	 *
	 * @param object $link Link row.
	 * @param string $ip   Address using it.
	 * @return bool Whether this request got the use.
	 */
	public static function claim_use( $link, $ip ) {
		global $wpdb;

		$table = self::table();
		$now   = current_time( 'mysql', true );

		// The address is bound on first use, so a link can be sent before
		// knowing where it will be opened from.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$claimed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				 SET used = used + 1,
				     last_used_at = %s,
				     bound_ip = IF( lock_ip = 1 AND bound_ip = '', %s, bound_ip )
				 WHERE id = %d
				   AND used < max_uses
				   AND revoked_at IS NULL
				   AND expires_at > %s
				   AND ( lock_ip = 0 OR bound_ip = '' OR bound_ip = %s )",
				$now,
				$ip,
				(int) $link->id,
				$now,
				$ip
			)
		);

		return 1 === (int) $claimed;
	}

	/**
	 * Stores the temporary user a link created.
	 *
	 * @since 1.0.0
	 *
	 * @param int $id      Link ID.
	 * @param int $user_id User ID.
	 */
	public static function set_temp_user( $id, $user_id ) {
		global $wpdb;

		$wpdb->update( self::table(), array( 'temp_user' => (int) $user_id ), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Revokes a link and removes anything it created.
	 *
	 * @since 1.0.0
	 *
	 * @param int $id Link ID.
	 */
	public static function revoke( $id ) {
		global $wpdb;

		$link = self::get( $id );

		if ( ! $link || ! empty( $link->revoked_at ) ) {
			return;
		}

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array( 'revoked_at' => current_time( 'mysql', true ) ),
			array( 'id' => (int) $id )
		);

		// Revoking has to end the session too. Marking the row alone would
		// leave whoever is already signed in exactly where they were.
		self::retire( $link );

		Beaver_Access_Log::record( (int) $id, 'revoked', '' );
	}

	/**
	 * Revokes every link that is still live.
	 *
	 * @since 1.0.0
	 *
	 * @return int How many were revoked.
	 */
	public static function revoke_all() {
		$count = 0;

		foreach ( self::all( 500 ) as $link ) {
			if ( empty( $link->revoked_at ) && strtotime( $link->expires_at . ' UTC' ) > time() ) {
				self::revoke( (int) $link->id );
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Ends a link's access, once.
	 *
	 * Expired links stay in the table for 30 days so the screen can show
	 * them. 1.0.2 retired every one of them on every hourly run, and for a
	 * link into an existing account that meant ending all of that person's
	 * sessions every hour for a month: the owner kept being logged out of
	 * their own site. A retired link is now remembered and skipped.
	 *
	 * @since 1.0.3
	 *
	 * @param object $link Link row.
	 */
	public static function retire( $link ) {
		$retired = self::retired_ids();
		$id      = (int) $link->id;

		if ( isset( $retired[ $id ] ) ) {
			return;
		}

		Beaver_Access_Users::retire( $link );

		$retired[ $id ] = time();

		update_option( self::RETIRED_OPTION, $retired, false );
	}

	/**
	 * Returns the IDs of links already retired.
	 *
	 * @since 1.0.3
	 *
	 * @return array<int,int> Link ID => when it was retired.
	 */
	private static function retired_ids() {
		$retired = get_option( self::RETIRED_OPTION, array() );

		return is_array( $retired ) ? $retired : array();
	}

	/**
	 * Cleans up expired links.
	 *
	 * @since 1.0.0
	 * @since 1.0.3 Retires each expired link once rather than every run.
	 */
	public static function cleanup() {
		global $wpdb;

		foreach ( self::all( 500 ) as $link ) {
			if ( strtotime( $link->expires_at . ' UTC' ) < time() ) {
				self::retire( $link );
			}
		}

		$table = self::table();
		$cut   = gmdate( 'Y-m-d H:i:s', time() - ( 30 * DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE expires_at < %s", $cut ) );

		// Forget links whose rows are gone, so the list cannot grow forever.
		$retired = self::retired_ids();

		if ( ! empty( $retired ) ) {
			$ids  = implode( ',', array_map( 'intval', array_keys( $retired ) ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$live = array_map( 'intval', (array) $wpdb->get_col( "SELECT id FROM {$table} WHERE id IN ( {$ids} )" ) );

			update_option( self::RETIRED_OPTION, array_intersect_key( $retired, array_flip( $live ) ), false );
		}
	}
}
