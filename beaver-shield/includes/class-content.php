<?php
/**
 * Front end copy deterrent.
 *
 * @package BeaverShield
 */

defined( 'ABSPATH' ) || exit;

/**
 * Makes casual copying awkward, and nothing more than that.
 *
 * The honest description first, because this feature is usually sold as
 * something it is not. Text has to reach the browser to be read, so it is in
 * the page, in the source, and one `curl` away from anyone who wants it. This
 * stops a visitor highlighting a paragraph and pressing Ctrl+C. It does not
 * stop a competitor, a scraper, or an AI crawler, none of which run JavaScript
 * or need to.
 *
 * That is why it ships off, and why the alternative mode exists. Rather than
 * blocking the copy, "attribute" lets it through and appends a line naming the
 * source and linking back. Someone who was going to quote the page still can,
 * the quote arrives carrying a link, and nobody is fought with. On most sites
 * that is worth more than the block.
 *
 * What it never does:
 *
 * - Interfere with a form. Inputs, textareas and anything editable are exempt,
 *   so a contact form, a search box and a checkout all behave normally.
 * - Run for anyone who can edit the site, which would make the site tedious to
 *   work on for the only people who work on it.
 * - Block right click, F12 or Ctrl+U. Those cannot be stopped, the attempts are
 *   trivially bypassed, and the loops used to try are read as hostile by some
 *   scanners.
 * - Change what a crawler receives. The same HTML goes to everyone.
 *
 * @since 1.0.0
 */
class Beaver_Shield_Content {

	/**
	 * Registers hooks.
	 *
	 * @since 1.0.0
	 */
	public static function init() {
		if ( ! Beaver_Shield_Settings::on( 'copy_guard' ) && ! Beaver_Shield_Settings::on( 'image_guard' ) ) {
			return;
		}

		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_filter( 'body_class', array( __CLASS__, 'body_class' ) );
	}

	/**
	 * Whether the deterrent applies to the page being rendered.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True when it should load.
	 */
	public static function applies() {
		if ( is_admin() || is_feed() || is_embed() ) {
			return false;
		}

		// The people who maintain the site are exempt. Nothing is protected from
		// them, and a site that fights its own editors does not get maintained.
		if ( current_user_can( 'edit_posts' ) ) {
			return false;
		}

		$excluded = Beaver_Shield_Settings::copy_guard_exclusions();

		if ( ! empty( $excluded ) && is_singular() && in_array( (int) get_queried_object_id(), $excluded, true ) ) {
			return false;
		}

		/**
		 * Filters whether the copy deterrent loads on this request.
		 *
		 * @since 1.0.0
		 *
		 * @param bool $applies Whether it loads.
		 */
		return (bool) apply_filters( 'beaver_shield_copy_guard_applies', true );
	}

	/**
	 * Loads the assets.
	 *
	 * @since 1.0.0
	 */
	public static function enqueue() {
		if ( ! self::applies() ) {
			return;
		}

		$copy  = Beaver_Shield_Settings::on( 'copy_guard' );
		$image = Beaver_Shield_Settings::on( 'image_guard' );

		if ( $copy ) {
			wp_enqueue_style( 'beaver-shield', BEAVER_SHIELD_URL . 'public/css/shield.css', array(), BEAVER_SHIELD_VERSION );
		}

		wp_enqueue_script( 'beaver-shield', BEAVER_SHIELD_URL . 'public/js/shield.js', array(), BEAVER_SHIELD_VERSION, true );

		wp_localize_script(
			'beaver-shield',
			'beaverShield',
			array(
				'text'   => $copy,
				'images' => $image,
				'mode'   => (string) Beaver_Shield_Settings::get( 'copy_guard_mode' ),
				'source' => sprintf(
					/* translators: 1: site name, 2: page URL. */
					__( 'Source: %1$s — %2$s', 'beaver-shield' ),
					wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
					self::current_url()
				),
			)
		);
	}

	/**
	 * Adds the hook the stylesheet needs.
	 *
	 * @since 1.0.0
	 *
	 * @param string[] $classes Body classes.
	 * @return string[] Filtered classes.
	 */
	public static function body_class( $classes ) {
		if ( Beaver_Shield_Settings::on( 'copy_guard' ) && self::applies() ) {
			$classes[] = 'beaver-shield-guard';
		}

		return $classes;
	}

	/**
	 * The URL of the page being viewed.
	 *
	 * @since 1.0.0
	 *
	 * @return string URL.
	 */
	private static function current_url() {
		if ( is_singular() ) {
			$permalink = get_permalink();

			if ( $permalink ) {
				return $permalink;
			}
		}

		return home_url( '/' );
	}
}
