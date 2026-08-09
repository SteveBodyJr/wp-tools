<?php
/**
 * The login request handler.
 *
 * @package BeaverAccess
 */

defined( 'ABSPATH' ) || exit;

/**
 * Turns a valid link into a signed-in session.
 *
 * This is the only part of the plugin that runs on a normal front-end request,
 * so it is built to cost nothing when it has no work to do: a single isset()
 * on the query string, before any option is read or any query is made. A site
 * nobody is signing into never notices this plugin is installed.
 *
 * @since 1.0.0
 */
class Beaver_Access_Session {

	const QUERY_VAR   = 'beaver-access';
	const RATE_OPTION = 'beaver_access_attempts';

	/**
	 * Registers the handler.
	 *
	 * @since 1.0.0
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'maybe_handle' ), 1 );
	}

	/**
	 * Handles a request carrying a token.
	 *
	 * @since 1.0.0
	 */
	public static function maybe_handle() {
		// The whole cost of this plugin on an ordinary page load.
		if ( ! isset( $_GET[ self::QUERY_VAR ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		// Nothing about a login link should ever be indexed, cached or leaked
		// through a referrer header to the next site the browser visits.
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Referrer-Policy: no-referrer' );

		$raw = sanitize_text_field( wp_unslash( $_GET[ self::QUERY_VAR ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$ip  = self::ip();

		if ( self::is_throttled( $ip ) ) {
			self::fail( __( 'Too many attempts from this address. Try again later.', 'beaver-access' ), 429 );
		}

		$parts = explode( '.', $raw, 2 );

		if ( 2 !== count( $parts ) || '' === $parts[0] || '' === $parts[1] ) {
			self::deny( 0, 'malformed', $ip );
		}

		$link = Beaver_Access_Links::by_selector( $parts[0] );

		if ( ! $link ) {
			self::deny( 0, 'unknown', $ip );
		}

		if ( ! hash_equals( (string) $link->verifier, hash( 'sha256', $parts[1] ) ) ) {
			self::deny( (int) $link->id, 'bad_token', $ip );
		}

		$reason = Beaver_Access_Links::reason_unusable( $link, $ip );

		if ( '' !== $reason ) {
			self::deny( (int) $link->id, $reason, $ip );
		}

		if ( ! is_ssl() && Beaver_Access_Settings::get( 'require_ssl' ) ) {
			self::deny( (int) $link->id, 'insecure', $ip );
		}

		$user_id = Beaver_Access_Users::resolve( $link );

		if ( is_wp_error( $user_id ) ) {
			self::deny( (int) $link->id, 'no_user', $ip );
		}

		Beaver_Access_Links::mark_used( $link, $ip );
		Beaver_Access_Log::record( (int) $link->id, 'used', $ip );

		self::clear_throttle( $ip );

		wp_set_current_user( $user_id );

		/*
		 * Never a "remember me" session. Access granted by a temporary link
		 * should not outlive the browser it was opened in.
		 */
		wp_set_auth_cookie( $user_id, false );

		do_action( 'wp_login', get_userdata( $user_id )->user_login, get_userdata( $user_id ) );

		/**
		 * Fires after a link has signed someone in.
		 *
		 * @since 1.0.0
		 *
		 * @param int    $user_id The user now signed in.
		 * @param object $link    The link that was used.
		 */
		do_action( 'beaver_access_granted', $user_id, $link );

		if ( Beaver_Access_Settings::get( 'notify_admin' ) ) {
			self::notify( $link, $ip );
		}

		/*
		 * Redirect rather than render. The token is in the address bar right
		 * now; bouncing to a clean URL keeps it out of browser history, out of
		 * bookmarks, and out of anything the next page might send onwards.
		 */
		wp_safe_redirect( self::destination( $link ) );
		exit;
	}

	/**
	 * Where to send someone after a successful sign-in.
	 *
	 * @since 1.0.0
	 *
	 * @param object $link Link row.
	 * @return string URL.
	 */
	private static function destination( $link ) {
		$url = admin_url();

		/**
		 * Filters where a link sends someone.
		 *
		 * @since 1.0.0
		 *
		 * @param string $url  Destination.
		 * @param object $link The link used.
		 */
		return (string) apply_filters( 'beaver_access_destination', $url, $link );
	}

	/**
	 * Records a refusal and stops.
	 *
	 * Every failure looks identical from outside. Telling the difference
	 * between "no such link" and "that link expired" would confirm which
	 * guesses were close, which is precisely what a guesser wants to know.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $link_id Link involved, if known.
	 * @param string $reason  Internal reason.
	 * @param string $ip      Address.
	 */
	private static function deny( $link_id, $reason, $ip ) {
		self::add_attempt( $ip );
		Beaver_Access_Log::record( $link_id, 'denied', $ip, $reason );

		self::fail( __( 'This link is not valid. It may have expired, been used already, or been revoked.', 'beaver-access' ), 403 );
	}

	/**
	 * Ends the request with a plain message.
	 *
	 * @since 1.0.0
	 *
	 * @param string $message Message.
	 * @param int    $code    HTTP status.
	 */
	private static function fail( $message, $code ) {
		wp_die(
			esc_html( $message ),
			esc_html__( 'Access link', 'beaver-access' ),
			array( 'response' => (int) $code )
		);
	}

	/**
	 * Emails the site administrator that a link was used.
	 *
	 * @since 1.0.0
	 *
	 * @param object $link Link row.
	 * @param string $ip   Address.
	 */
	private static function notify( $link, $ip ) {
		$to = (string) get_option( 'admin_email', '' );

		if ( '' === $to ) {
			return;
		}

		$body = sprintf(
			/* translators: 1: link label, 2: site, 3: address, 4: time. */
			__( "An access link was used to sign in.\n\nLink: %1\$s\nSite: %2\$s\nFrom: %3\$s\nWhen: %4\$s UTC\n\nIf you did not expect this, revoke the link under Users → Access Links.", 'beaver-access' ),
			'' !== $link->label ? $link->label : __( '(no label)', 'beaver-access' ),
			home_url( '/' ),
			$ip,
			gmdate( 'Y-m-d H:i:s' )
		);

		wp_mail(
			$to,
			sprintf(
				/* translators: %s: site host. */
				__( '[%s] An access link was used', 'beaver-access' ),
				wp_parse_url( home_url(), PHP_URL_HOST )
			),
			$body
		);
	}

	/*
	 * -----------------------------------------------------------------------
	 * Throttling
	 * -----------------------------------------------------------------------
	 */

	/**
	 * Returns the requesting address.
	 *
	 * @since 1.0.0
	 *
	 * @return string Address.
	 */
	public static function ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		/**
		 * Filters the address a request is attributed to.
		 *
		 * Deliberately REMOTE_ADDR by default. Forwarded headers can be set by
		 * the client, so trusting one without a proxy in front turns the rate
		 * limit into a formality.
		 *
		 * @since 1.0.0
		 *
		 * @param string $ip The address.
		 */
		$ip = (string) apply_filters( 'beaver_access_client_ip', $ip );

		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '0.0.0.0';
	}

	/**
	 * Whether an address has failed too often lately.
	 *
	 * @since 1.0.0
	 *
	 * @param string $ip Address.
	 * @return bool Whether it is throttled.
	 */
	private static function is_throttled( $ip ) {
		$attempts = get_transient( self::RATE_OPTION . '_' . md5( $ip ) );

		return is_array( $attempts ) && count( $attempts ) >= 10;
	}

	/**
	 * Records a failed attempt.
	 *
	 * @since 1.0.0
	 *
	 * @param string $ip Address.
	 */
	private static function add_attempt( $ip ) {
		$key      = self::RATE_OPTION . '_' . md5( $ip );
		$attempts = get_transient( $key );
		$attempts = is_array( $attempts ) ? $attempts : array();

		$attempts[] = time();

		set_transient( $key, array_slice( $attempts, -20 ), 15 * MINUTE_IN_SECONDS );
	}

	/**
	 * Forgets an address's failures.
	 *
	 * @since 1.0.0
	 *
	 * @param string $ip Address.
	 */
	private static function clear_throttle( $ip ) {
		delete_transient( self::RATE_OPTION . '_' . md5( $ip ) );
	}
}
