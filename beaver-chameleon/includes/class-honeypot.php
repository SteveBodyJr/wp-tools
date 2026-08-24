<?php
/**
 * The dynamic CSS honeypot.
 *
 * @package BeaverChameleon
 */

defined( 'ABSPATH' ) || exit;

/**
 * A form field that changes its name every day.
 *
 * A conventional honeypot field keeps the same `name` forever, which is
 * exactly what lets a bot script learn it once from one site and skip it on
 * every future submission, here or anywhere else. This one is derived from
 * the current date and the site's own `wp_salt()`, so it mutates once every
 * midnight and cannot be predicted without the site's secret keys — a
 * scraped field name is worthless again by the next day.
 *
 * The field is hidden purely with CSS (`wp_head`), never with `type="hidden"`
 * or `display:none` written inline on the element itself, because a scraper
 * that only reads the markup — never the stylesheet — would otherwise see an
 * obviously-hidden field and know to leave it alone. A human never sees it
 * either way; a script that fills in every text input it finds does.
 *
 * @since 1.0.0
 */
class Beaver_Chameleon_Honeypot {

	/**
	 * Prefix on the generated field name, so it is always a valid HTML
	 * `name`/`id` token even though a raw SHA-256 digest can start with a
	 * digit.
	 */
	const FIELD_PREFIX = 'bc_';

	/**
	 * Registers hooks.
	 *
	 * @since 1.0.0
	 */
	public static function init() {
		// wp-login.php is a separate template that never fires wp_head — it
		// has its own head hook — so both are needed or the field renders
		// unhidden on the login form.
		add_action( 'wp_head', array( __CLASS__, 'render_css' ) );
		add_action( 'login_enqueue_scripts', array( __CLASS__, 'render_css' ) );
		add_filter( 'comment_form_fields', array( __CLASS__, 'inject_comment_field' ) );
		add_action( 'login_form', array( __CLASS__, 'inject_login_field' ) );
	}

	/**
	 * Today's field name.
	 *
	 * Deterministic and cached for the life of the request: computing it
	 * again at submission time — with no state to look up — yields the exact
	 * same string, as long as it is still the same UTC day.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public static function field_name() {
		static $name = null;

		if ( null === $name ) {
			$hash = hash( 'sha256', gmdate( 'Y-m-d' ) . wp_salt() );
			$name = self::FIELD_PREFIX . substr( $hash, 0, 16 );
		}

		return $name;
	}

	/**
	 * Prints the CSS that hides today's field.
	 *
	 * Belt and braces: `display:none !important` removes it from layout
	 * entirely, and the off-screen absolute position is a second, independent
	 * way to keep it out of sight in case a theme or another plugin's CSS
	 * ever wins a specificity fight over `display`.
	 *
	 * @since 1.0.0
	 */
	public static function render_css() {
		$field = self::field_name();

		// $field is a fixed-format internal hash (regex \A[a-z0-9]+\z) rather
		// than user input, but it is still run through esc_html() before it
		// reaches output, on principle: nothing printed skips escaping merely
		// because this call site currently thinks it is safe.
		printf(
			'<style id="beaver-chameleon-style">.%1$s{position:absolute!important;left:-9999px!important;top:-9999px!important;width:1px!important;height:1px!important;overflow:hidden!important;display:none!important;}</style>' . "\n",
			esc_html( $field )
		);
	}

	/**
	 * Adds the field to the comment form.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string,string> $fields Existing comment form fields.
	 * @return array<string,string> Filtered fields.
	 */
	public static function inject_comment_field( $fields ) {
		$fields['beaver_chameleon_honeypot'] = self::markup();

		return $fields;
	}

	/**
	 * Prints the field inside the login form.
	 *
	 * @since 1.0.0
	 */
	public static function inject_login_field() {
		echo self::markup(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built entirely from esc_attr()/esc_html__() in markup().
	}

	/**
	 * Whether today's field arrived non-empty — the honeypot tripped.
	 *
	 * Uses `isset()` + `trim()` rather than `empty()` on its own: `empty()`
	 * treats the string `"0"` as blank, so a bot that fills every field with
	 * `0` would otherwise walk straight through.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public static function is_tripped() {
		$name = self::field_name();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- read-only trap check; the behavioral token carries the actual CSRF nonce, verified by Beaver_Chameleon_Guard before anything is written.
		if ( ! isset( $_POST[ $name ] ) ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- value is never stored or displayed, only tested for blankness.
		$value = trim( (string) wp_unslash( $_POST[ $name ] ) );

		return '' !== $value;
	}

	/**
	 * The field markup, shared by both injection points.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	private static function markup() {
		$field = esc_attr( self::field_name() );

		return sprintf(
			'<p class="%1$s" aria-hidden="true"><label for="%1$s">%2$s</label><input type="text" name="%1$s" id="%1$s" value="" autocomplete="off" tabindex="-1" /></p>',
			$field,
			esc_html__( 'Leave this field blank', 'beaver-chameleon' )
		);
	}
}
