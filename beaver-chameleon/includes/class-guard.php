<?php
/**
 * The checkpoint both traps report to.
 *
 * @package BeaverChameleon
 */

defined( 'ABSPATH' ) || exit;

/**
 * Where a tripped trap actually stops the request.
 *
 * Honeypot and Behavior only know how to render their own field and answer
 * "did this trip?". Deciding *when* to ask them, and what happens if the
 * answer is yes, lives here — one place, so the 403 response, the logging
 * call and the request-context checks cannot drift out of step between the
 * comment form and the login form.
 *
 * @since 1.0.0
 */
class Beaver_Chameleon_Guard {

	/**
	 * Registers hooks.
	 *
	 * @since 1.0.0
	 */
	public static function init() {
		add_filter( 'preprocess_comment', array( __CLASS__, 'guard_comment' ) );
		add_action( 'wp_authenticate', array( __CLASS__, 'guard_login' ), 1, 2 );
	}

	/**
	 * Checks a comment submission before it is inserted.
	 *
	 * Skipped for REST API and XML-RPC requests: those clients — Jetpack, a
	 * mobile app, a headless front end — never rendered this plugin's HTML or
	 * ran its script, so they were never going to carry either field, and
	 * they authenticate through their own mechanisms already. Trapping them
	 * here would only block traffic this plugin has no way to pass.
	 *
	 * @since 1.0.0
	 *
	 * @param array $commentdata Comment data about to be inserted.
	 * @return array Unmodified comment data, if it gets that far.
	 */
	public static function guard_comment( $commentdata ) {
		if ( Beaver_Chameleon_Plugin::is_rest_or_xmlrpc() ) {
			return $commentdata;
		}

		self::check();

		return $commentdata;
	}

	/**
	 * Checks a login submission.
	 *
	 * `wp_authenticate` also fires for application-password and other
	 * programmatic authentication that never touched wp-login.php, so this
	 * only acts when the request is unmistakably a standard login form POST.
	 *
	 * @since 1.0.0
	 *
	 * @param string $username Unused; required to match the hook signature.
	 * @param string $password Unused; required to match the hook signature.
	 */
	public static function guard_login( $username = '', $password = '' ) {
		if ( ! Beaver_Chameleon_Plugin::is_login_post() ) {
			return;
		}

		self::check();
	}

	/**
	 * Runs both traps and blocks on the first one that trips.
	 *
	 * Honeypot is checked first: a filled honeypot is unambiguous proof of a
	 * script, whereas a missing behavioral token is also what a human with
	 * JavaScript disabled would produce, so it is the weaker of the two
	 * signals and is checked second.
	 *
	 * @since 1.0.0
	 */
	private static function check() {
		if ( Beaver_Chameleon_Honeypot::is_tripped() ) {
			self::block( 'honeypot' );
		}

		if ( ! Beaver_Chameleon_Behavior::is_verified() ) {
			self::block( 'behavior' );
		}
	}

	/**
	 * Logs the block and terminates the request with a 403.
	 *
	 * @since 1.0.0
	 *
	 * @param string $reason 'honeypot' or 'behavior'.
	 */
	private static function block( $reason ) {
		Beaver_Chameleon_Stats::record( $reason );

		wp_die(
			esc_html__( 'Forbidden: this submission was blocked by Chameleon Shield.', 'beaver-chameleon' ),
			esc_html__( '403 Forbidden', 'beaver-chameleon' ),
			array( 'response' => 403 )
		);
	}
}
