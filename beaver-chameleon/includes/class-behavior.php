<?php
/**
 * The behavioral unlock trap.
 *
 * @package BeaverChameleon
 */

defined( 'ABSPATH' ) || exit;

/**
 * A token that only a browser a human is using ever fills in.
 *
 * Each form carries a hidden field that starts empty and a valid one-time
 * token that WordPress's own nonce store already generated for the page. The
 * two are never joined until a real interaction event fires — `mousemove`,
 * `touchstart`, `keydown` or `pointerdown` — at which point a tiny inline
 * script copies the token into the field.
 *
 * That ordering is the whole point. A plain HTTP client never runs the script
 * at all, so the field stays empty. A headless browser that does run
 * JavaScript but submits programmatically — the way most scripted form-fillers
 * work — never dispatches a *trusted* input event either, so the field still
 * stays empty: the unlock handler checks `event.isTrusted` and ignores an
 * event a script constructed and dispatched itself, which is otherwise the
 * single easiest way to fake this trap once someone reads the page source.
 * Only a browser a person is actually moving a mouse, finger or key on ever
 * populates it.
 *
 * A second, independent signal rides alongside the token: a signed render
 * timestamp, present the instant the page loads rather than only after
 * interaction. A submission that arrives less than a second or two after the
 * page was rendered is almost certainly a script — no human reads a form,
 * types into it and submits it in under a second — so this catches a bot
 * fast enough to fire a synthetic event but not slow enough to look human.
 * The timestamp is HMAC-signed with the site's own salt so a submitted value
 * can be trusted without WordPress having to remember anything about the
 * page it came from.
 *
 * @since 1.0.0
 */
class Beaver_Chameleon_Behavior {

	/**
	 * Nonce action the token is created and verified against.
	 */
	const NONCE_ACTION = 'beaver_chameleon_behavior';

	/**
	 * The hidden token field's `name` attribute.
	 */
	const FIELD_NAME = 'beaver_chameleon_token';

	/**
	 * The hidden, signed render-timestamp field's `name` attribute.
	 */
	const TS_FIELD_NAME = 'beaver_chameleon_ts';

	/**
	 * Registers hooks.
	 *
	 * @since 1.0.0
	 */
	public static function init() {
		add_filter( 'comment_form_fields', array( __CLASS__, 'inject_comment_field' ) );
		add_action( 'login_form', array( __CLASS__, 'inject_login_field' ) );
		add_action( 'wp_footer', array( __CLASS__, 'render_script' ) );
		add_action( 'login_footer', array( __CLASS__, 'render_script' ) );
	}

	/**
	 * Adds the token and timestamp fields to the comment form.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string,string> $fields Existing comment form fields.
	 * @return array<string,string> Filtered fields.
	 */
	public static function inject_comment_field( $fields ) {
		$fields['beaver_chameleon_token'] = self::markup();

		return $fields;
	}

	/**
	 * Prints the token and timestamp fields inside the login form.
	 *
	 * @since 1.0.0
	 */
	public static function inject_login_field() {
		echo self::markup(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built entirely from esc_attr()/self::sign_timestamp() in markup().
	}

	/**
	 * Prints the unlock script.
	 *
	 * Deliberately small: one nonce, one function, four `{ once: true }`
	 * listeners that unregister themselves the moment they fire, and nothing
	 * else. It targets every matching field on the page by class rather than
	 * assuming a single form, so a theme that shows the comment form more than
	 * once is still covered by one script block.
	 *
	 * @since 1.0.0
	 */
	public static function render_script() {
		$token = wp_create_nonce( self::NONCE_ACTION );
		?>
		<script>
		( function () {
			"use strict";
			var t = <?php echo wp_json_encode( $token ); ?>;
			function unlock( event ) {
				// A script that dispatches its own event to fake this trap
				// produces an event object indistinguishable from a real one
				// except for this one flag, which only a genuine user action
				// sets. Ignore anything that isn't trusted and keep waiting.
				if ( event && false === event.isTrusted ) {
					return;
				}
				var fields = document.getElementsByClassName( 'beaver-chameleon-token' );
				for ( var i = 0; i < fields.length; i++ ) {
					fields[ i ].value = t;
				}
			}
			[ 'mousemove', 'touchstart', 'keydown', 'pointerdown' ].forEach( function ( evt ) {
				window.addEventListener( evt, unlock, { passive: true, once: true } );
			} );
		} )();
		</script>
		<?php
	}

	/**
	 * Whether a valid token, backed by a plausible render timestamp, arrived
	 * with the submission.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public static function is_verified() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- this *is* the nonce check.
		$token = isset( $_POST[ self::FIELD_NAME ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::FIELD_NAME ] ) ) : '';

		if ( '' === $token || false === wp_verify_nonce( $token, self::NONCE_ACTION ) ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- signature is verified explicitly in verify_timestamp().
		$signed_ts = isset( $_POST[ self::TS_FIELD_NAME ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::TS_FIELD_NAME ] ) ) : '';

		return self::verify_timestamp( $signed_ts );
	}

	/**
	 * Checks the signed render timestamp: well-formed, correctly signed, and
	 * old enough to rule out a submission that outran a human.
	 *
	 * @since 1.0.0
	 *
	 * @param string $signed_ts Raw `timestamp.signature` value from the form.
	 * @return bool
	 */
	private static function verify_timestamp( $signed_ts ) {
		$parts = explode( '.', $signed_ts, 2 );

		if ( 2 !== count( $parts ) || ! ctype_digit( $parts[0] ) ) {
			return false;
		}

		list( $rendered_at, $signature ) = $parts;

		if ( ! hash_equals( self::sign( $rendered_at ), $signature ) ) {
			return false;
		}

		$elapsed = time() - (int) $rendered_at;

		/**
		 * Fastest a genuine submission is expected to arrive after the page
		 * rendered, in seconds. Anything faster is treated as a script.
		 *
		 * @since 1.0.0
		 *
		 * @param int $min_seconds Default 1.
		 */
		$min_seconds = (int) apply_filters( 'beaver_chameleon_min_seconds', 1 );

		/**
		 * Oldest a render timestamp is still accepted, in seconds — a stale
		 * or replayed value past this point is rejected even if correctly
		 * signed. An hour comfortably covers a slow human filling out a
		 * comment, while keeping a captured token+timestamp pair's window
		 * for replay far shorter than the underlying nonce's own ~day-long
		 * lifetime would otherwise allow.
		 *
		 * @since 1.0.0
		 *
		 * @param int $max_seconds Default one hour.
		 */
		$max_seconds = (int) apply_filters( 'beaver_chameleon_max_seconds', HOUR_IN_SECONDS );

		return $elapsed >= $min_seconds && $elapsed <= $max_seconds;
	}

	/**
	 * HMAC-signs a value with the site's own salt.
	 *
	 * @since 1.0.0
	 *
	 * @param string $value Value to sign.
	 * @return string Hex-encoded signature.
	 */
	private static function sign( $value ) {
		return hash_hmac( 'sha256', self::TS_FIELD_NAME . '|' . $value, wp_salt() );
	}

	/**
	 * The token and timestamp field markup, shared by both injection points.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	private static function markup() {
		$now = time();

		return sprintf(
			'<input type="hidden" class="beaver-chameleon-token" name="%s" value="" />' .
			'<input type="hidden" name="%s" value="%s" />',
			esc_attr( self::FIELD_NAME ),
			esc_attr( self::TS_FIELD_NAME ),
			esc_attr( $now . '.' . self::sign( (string) $now ) )
		);
	}
}
