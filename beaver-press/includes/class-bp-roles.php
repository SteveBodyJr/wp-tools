<?php
/**
 * Translator role.
 *
 * A "Translator" account can open TranslatePress's visual editor and the Review page, and
 * nothing else: no posts, no settings, no API keys, no Translate-site run. Administrators
 * always have the same capability. TranslatePress asks for its editor capability through
 * `trp_translating_capability`, so pointing that at `bp_translate` covers the editor, its
 * save requests and the admin-bar button.
 *
 * @package BeaverPress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Translator role and capability.
 */
final class BP_Roles {

	/** Role slug. */
	const ROLE = 'bp_translator';

	/** Capability for the visual editor and Review. */
	const CAP = 'bp_translate';

	/** Option holding the role definition version (role is rewritten when it changes). */
	const VERSION_OPTION = 'beaver_press_roles';

	/** Role definition version. */
	const VERSION = 1;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_filter( 'trp_translating_capability', array( __CLASS__, 'capability' ), 20 );
		add_filter( 'user_has_cap', array( __CLASS__, 'grant' ) );
		// TranslatePress lets its translators preview pages "as" another role; keep that to administrators.
		add_filter( 'trp_allow_translator_role_to_view_page_as_other_roles', array( __CLASS__, 'is_admin_user' ) );
		add_filter( 'login_redirect', array( __CLASS__, 'login_redirect' ), 10, 3 );
		add_action( 'init', array( __CLASS__, 'ensure_role' ) );
	}

	/**
	 * TranslatePress's editor capability.
	 *
	 * @return string
	 */
	public static function capability() {
		return self::CAP;
	}

	/**
	 * Administrators (anyone who can manage options) always have the capability.
	 *
	 * @param array $allcaps Capabilities of the user.
	 * @return array
	 */
	public static function grant( $allcaps ) {
		if ( ! empty( $allcaps['manage_options'] ) ) {
			$allcaps[ self::CAP ] = true;
		}
		return $allcaps;
	}

	/**
	 * Whether the current user is an administrator.
	 *
	 * @return bool
	 */
	public static function is_admin_user() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Create (or update) the role once; roles are stored in the database.
	 */
	public static function ensure_role() {
		if ( self::VERSION === (int) get_option( self::VERSION_OPTION ) && get_role( self::ROLE ) ) {
			return;
		}
		remove_role( self::ROLE );
		add_role(
			self::ROLE,
			__( 'Translator', 'beaver-press' ),
			array(
				'read'    => true,
				self::CAP => true,
			)
		);
		update_option( self::VERSION_OPTION, self::VERSION, false );
	}

	/**
	 * Translators land in the visual editor after logging in (that is where they translate).
	 *
	 * @param string           $to        Redirect URL.
	 * @param string           $requested Requested redirect.
	 * @param WP_User|WP_Error $user      User.
	 * @return string
	 */
	public static function login_redirect( $to, $requested, $user ) {
		if ( $user instanceof WP_User && in_array( self::ROLE, (array) $user->roles, true ) && ! $user->has_cap( 'manage_options' )
			&& ( '' === (string) $requested || admin_url() === $to ) ) {
			return add_query_arg( 'trp-edit-translation', 'true', home_url( '/' ) );
		}
		return $to;
	}
}
