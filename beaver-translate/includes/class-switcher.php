<?php
/**
 * The front-end language switcher.
 *
 * @package BeaverTranslate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders `[beaver_translate_switcher]`: a link to every language the
 * current post has a genuinely published translation in.
 *
 * A shortcode rather than a Gutenberg block, matching the rest of this
 * plugin suite — every other front-end insert across these plugins
 * (beaver-ai-chat, beaver-pwa) is a shortcode, and none of them carry a
 * JavaScript build step to compile a block against.
 *
 * There are two ways it ends up on a page. The shortcode is the flexible
 * one: paste it into content, or into a theme's own hook if it has one —
 * african-travel-tour, the theme this plugin was first deployed against,
 * happens to have exactly that. Most themes, and most Elementor or other
 * page-builder sites, have no such hook and no template file worth editing
 * by hand. For those, self::render_auto() — off by default, one settings
 * toggle — drops a small floating switcher into `wp_footer`, which every
 * theme calls, so the plugin does something sensible with zero setup
 * rather than only working on the one site it happened to be built next to.
 *
 * @since 1.1.0
 */
class Beaver_Translate_Switcher {

	/**
	 * Whether the inline stylesheet has already been printed this request.
	 *
	 * @var bool
	 */
	private static $styles_printed = false;

	/**
	 * Registers the shortcode and the optional automatic placement.
	 *
	 * @since 1.1.0
	 */
	public static function init() {
		add_shortcode( 'beaver_translate_switcher', array( __CLASS__, 'shortcode' ) );
		add_action( 'wp_footer', array( __CLASS__, 'render_auto' ) );
	}

	/**
	 * Prints a floating switcher, only when the setting is on.
	 *
	 * Deliberately opt-in rather than on by default: a site that already
	 * places the shortcode itself, or wires it into a theme hook the way
	 * african-travel-tour does, does not need — and should not get — a
	 * second switcher appearing on top of the first.
	 *
	 * @since 1.3.0
	 */
	public static function render_auto() {
		if ( ! Beaver_Translate_Settings::get( 'auto_switcher_enabled' ) ) {
			return;
		}

		$html = self::shortcode( array() );

		if ( '' === $html ) {
			return;
		}

		$position = Beaver_Translate_Settings::get( 'auto_switcher_position', 'bottom-right' );

		printf(
			'<div class="beaver-translate-floating beaver-translate-floating--%s">%s</div>',
			esc_attr( $position ),
			$html // phpcs:ignore WordPress.Security.EscapeOutput -- already-escaped markup from self::shortcode().
		);
	}

	/**
	 * Renders the shortcode.
	 *
	 * @since 1.1.0
	 *
	 * @param array $atts Shortcode attributes (none used; accepted for the
	 *                    standard shortcode callback signature).
	 * @return string HTML, or an empty string when there is nothing to switch to.
	 */
	public static function shortcode( $atts ) {
		unset( $atts );

		/*
		 * WordPress sets the global $post to the first post of the main
		 * query on every request that has one — including an archive or the
		 * blog home, not just a single post view. Without this guard, a
		 * switcher placed in a theme's header (rendered on every page, not
		 * just inside post content) would silently show translation links
		 * for whatever the first listed post happens to be while looking at
		 * an archive, which has nothing to do with what the visitor is
		 * actually looking at. This plugin has no concept of a translated
		 * archive, so the only correct behaviour there is to show nothing.
		 */
		if ( ! is_singular() ) {
			return '';
		}

		$post = get_post();

		if ( ! $post instanceof WP_Post ) {
			return '';
		}

		$group = self::resolve_group( $post->ID );

		if ( count( $group ) < 2 ) {
			return '';
		}

		return self::render( $group, $post->ID );
	}

	/**
	 * Finds every language the same piece of content is published in.
	 *
	 * Works from either side: called on the source post or on one of its
	 * translations, the result is the same complete group.
	 *
	 * @since 1.1.0
	 *
	 * @param int $post_id The post the shortcode was used on.
	 * @return array<string,int> Language code => post ID, including the
	 *                           default language. Empty when nothing to switch to.
	 */
	private static function resolve_group( $post_id ) {
		$source_id = (int) get_post_meta( $post_id, Beaver_Translate_Translator::META_SOURCE_ID, true );
		$source_id = $source_id > 0 ? $source_id : $post_id;

		if ( 'publish' !== get_post_status( $source_id ) ) {
			return array();
		}

		$group = array( Beaver_Translate_Languages::default_code() => $source_id );

		foreach ( Beaver_Translate_Links::for_source( $source_id ) as $link ) {
			if ( Beaver_Translate_Links::STATUS_PUBLISHED !== $link->status || ! $link->translated_post_id ) {
				continue;
			}

			// Belt and suspenders: the link table can go stale if a
			// translation is unpublished by hand outside this plugin's own
			// approve/reject actions. The post's real status is the one
			// thing that can never lie.
			if ( 'publish' === get_post_status( $link->translated_post_id ) ) {
				$group[ $link->language_code ] = (int) $link->translated_post_id;
			}
		}

		return $group;
	}

	/**
	 * Renders the switcher markup.
	 *
	 * @since 1.1.0
	 *
	 * @param array<string,int> $group            Language code => post ID.
	 * @param int                $current_post_id The post the shortcode is on.
	 * @return string HTML.
	 */
	private static function render( $group, $current_post_id ) {
		$items = '';

		foreach ( $group as $code => $post_id ) {
			$label = esc_html( Beaver_Translate_Languages::label( $code ) );

			if ( (int) $post_id === (int) $current_post_id ) {
				$items .= sprintf(
					'<li class="beaver-translate-switcher__item beaver-translate-switcher__item--current"><span aria-current="page">%s</span></li>',
					$label
				);

				continue;
			}

			$url = get_permalink( $post_id );

			if ( ! $url ) {
				continue;
			}

			$items .= sprintf(
				'<li class="beaver-translate-switcher__item"><a href="%s" hreflang="%s" lang="%s">%s</a></li>',
				esc_url( $url ),
				esc_attr( $code ),
				esc_attr( $code ),
				$label
			);
		}

		return self::styles() . '<ul class="beaver-translate-switcher">' . $items . '</ul>';
	}

	/**
	 * The switcher's minimal styling, printed inline once per request.
	 *
	 * Inline rather than an enqueued stylesheet: the shortcode is small
	 * enough that a whole separate CSS request would cost more than it
	 * saves, and this way nothing loads at all on the vast majority of pages
	 * that never use the shortcode.
	 *
	 * Deliberately carries no colour or margin opinion of its own. This can
	 * end up in genuinely unrelated contexts — dropped into post content,
	 * or, on sites that wire it into a theme's own template hook (as
	 * african-travel-tour's `att_render_translation_shortcode()` does), a
	 * dark header bar sitting on a light page. Every colour here is
	 * `currentColor` so it always matches whatever text colour the
	 * surrounding context already set, and there is no margin for a tight
	 * single-line bar to fight.
	 *
	 * @since 1.1.0
	 *
	 * @return string A `<style>` tag, or an empty string after the first call.
	 */
	private static function styles() {
		if ( self::$styles_printed ) {
			return '';
		}

		self::$styles_printed = true;

		return '<style>'
			. '.beaver-translate-switcher{list-style:none;display:inline-flex;flex-wrap:wrap;align-items:center;gap:.35em .85em;margin:0;padding:0;}'
			. '.beaver-translate-switcher a{color:inherit;text-decoration:none;opacity:.8;}'
			. '.beaver-translate-switcher a:hover,.beaver-translate-switcher a:focus{opacity:1;text-decoration:underline;}'
			. '.beaver-translate-switcher__item--current span{font-weight:700;opacity:1;}'
			/*
			 * The floating wrapper is the one place this plugin sets its own
			 * background and text colour outright, rather than inheriting —
			 * it sits on top of arbitrary page content, so there is no
			 * surrounding context to inherit from that would reliably stay
			 * legible. A dark, neutral pill was chosen deliberately over
			 * anything brand-coloured: this can land on any theme, and a
			 * colour this plugin invented has no reason to match one it did
			 * not choose.
			 */
			. '.beaver-translate-floating{position:fixed;z-index:99990;background:#1d2327;color:#fff;padding:.5em .9em;border-radius:999px;box-shadow:0 2px 10px rgba(0,0,0,.25);font:14px/1.4 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;}'
			. '.beaver-translate-floating .beaver-translate-switcher{gap:.5em .9em;}'
			. '.beaver-translate-floating--bottom-right{right:20px;bottom:20px;}'
			. '.beaver-translate-floating--bottom-left{left:20px;bottom:20px;}'
			. '.beaver-translate-floating--top-right{right:20px;top:20px;}'
			. '.beaver-translate-floating--top-left{left:20px;top:20px;}'
			. '@media (max-width:480px){.beaver-translate-floating{font-size:13px;padding:.45em .75em;}}'
			. '</style>';
	}
}
