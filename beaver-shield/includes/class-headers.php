<?php
/**
 * Security headers.
 *
 * @package BeaverShield
 */

defined( 'ABSPATH' ) || exit;

/**
 * Sends the response headers a modern site should be sending anyway.
 *
 * Four of these are free. Framing, MIME sniffing, referrer leakage and unused
 * browser features cost a visitor nothing to switch off and are what a security
 * scan looks for first.
 *
 * The fifth, a content security policy, is not free and is treated differently
 * everywhere in this plugin. A policy that has not been fitted to the site it
 * protects will silently stop a page builder, an analytics tag or a font from
 * loading, and the failure shows up as a blank section rather than an error. So
 * it ships off, and when it is switched on the first stop is report-only mode,
 * where the browser reports what the policy *would* have blocked and blocks
 * nothing. Read the console, widen the policy until it is quiet, then enforce.
 *
 * @since 1.0.0
 */
class Beaver_Shield_Headers {

	/**
	 * Registers hooks.
	 *
	 * @since 1.0.0
	 */
	public static function init() {
		add_action( 'send_headers', array( __CLASS__, 'send' ), 20 );
	}

	/**
	 * Sends the configured headers.
	 *
	 * @since 1.0.0
	 */
	public static function send() {
		if ( is_admin() || headers_sent() ) {
			return;
		}

		foreach ( self::collect() as $name => $value ) {
			header( $name . ': ' . $value );
		}
	}

	/**
	 * Builds the header set from the settings.
	 *
	 * Kept separate from sending it so the admin screen can show exactly what
	 * this site is emitting, and the scanner can compare that against what
	 * actually arrives.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string,string> Header name to value.
	 */
	public static function collect() {
		$headers = array();

		$frame = (string) Beaver_Shield_Settings::get( 'header_frame', '' );

		if ( '' !== $frame ) {
			$headers['X-Frame-Options'] = $frame;
		}

		if ( Beaver_Shield_Settings::on( 'header_nosniff' ) ) {
			$headers['X-Content-Type-Options'] = 'nosniff';
		}

		$referrer = (string) Beaver_Shield_Settings::get( 'header_referrer', '' );

		if ( '' !== $referrer ) {
			$headers['Referrer-Policy'] = $referrer;
		}

		if ( Beaver_Shield_Settings::on( 'header_permissions' ) ) {
			$headers['Permissions-Policy'] = Beaver_Shield_Settings::default_permissions_policy();
		}

		// Sent only over HTTPS. On a plain HTTP response the header is ignored
		// by browsers anyway, and sending it from a site whose certificate is
		// not yet permanent is how a domain becomes unreachable for six months.
		if ( Beaver_Shield_Settings::on( 'header_hsts' ) && is_ssl() ) {
			$headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
		}

		$mode = Beaver_Shield_Settings::get( 'csp_mode' );

		if ( Beaver_Shield_Settings::CSP_OFF !== $mode ) {
			$name = Beaver_Shield_Settings::CSP_REPORT === $mode
				? 'Content-Security-Policy-Report-Only'
				: 'Content-Security-Policy';

			$headers[ $name ] = (string) Beaver_Shield_Settings::get( 'csp_value' );
		}

		/**
		 * Filters the security headers before they are sent.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string,string> $headers Header name to value.
		 */
		return apply_filters( 'beaver_shield_headers', $headers );
	}
}
