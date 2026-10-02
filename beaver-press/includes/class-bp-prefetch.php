<?php
/**
 * Fast language switching: fetch the other language's page before the click.
 *
 * WordPress's own speculative loading only covers links in the current language and waits for
 * the click. Here, as soon as a visitor shows intent (pointer on or touch of the language
 * switcher, or of any link to this page in another language), the page in the other
 * languages is fetched in the background, so the click opens it at once. Chrome and Edge use
 * speculation rules (kept even when the page says no-store); other browsers get
 * <link rel="prefetch">. Nothing is fetched for logged-in users (their visits may start paid
 * translations), on data-saver or on 2G connections.
 *
 * @package BeaverPress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Switcher prefetch.
 */
final class BP_Prefetch {

	/** Switcher containers (theme, TranslatePress shortcode, menu item and floater). */
	const SWITCHERS = '[data-ry-lang], .trp-language-switcher, .trp_language_switcher_shortcode, .trp-ls-shortcode-current-language, .menu-item-object-language_switcher, #trp-floater-ls, [data-bp-switcher]';

	/**
	 * Hooks.
	 */
	public static function init() {
		if ( apply_filters( 'beaver_press_prefetch', true ) ) {
			add_action( 'wp_footer', array( __CLASS__, 'script' ), 99 );
		}
	}

	/**
	 * This page in the other published languages.
	 *
	 * @return string[]
	 */
	public static function urls() {
		global $TRP_LANGUAGE;
		$trp       = TRP_Translate_Press::get_trp_instance();
		$settings  = $trp->get_component( 'settings' )->get_settings();
		$converter = $trp->get_component( 'url_converter' );
		$out       = array();
		$original  = (string) $converter->get_url_for_language( $settings['default-language'], null, '' );
		foreach ( (array) ( $settings['publish-languages'] ?? array() ) as $code ) {
			if ( $code === $TRP_LANGUAGE || BP_Complete::known_incomplete( $code, $original ) ) {
				continue;
			}
			$url = $converter->get_url_for_language( $code, null, '' );
			if ( is_string( $url ) && '' !== $url && false === strpos( $url, '?' ) ) {
				$out[] = $url;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Small inline script in the footer.
	 */
	public static function script() {
		if ( is_admin() || is_user_logged_in() || is_404() || is_search() || ( function_exists( 'trp_is_translation_editor' ) && trp_is_translation_editor() ) ) {
			return;
		}
		$urls = self::urls();
		if ( ! $urls ) {
			return;
		}
		$data = array(
			'urls' => $urls,
			'sel'  => (string) apply_filters( 'beaver_press_switcher_selector', self::SWITCHERS ),
		);
		?>
<script data-no-translation>
(function (d, n) {
	var c = n.connection || {}; if (c.saveData || /2g/.test(c.effectiveType || '')) { return; }
	var cfg = <?php echo wp_json_encode( $data, JSON_UNESCAPED_SLASHES ); ?>, done = {};
	var rules = HTMLScriptElement.supports && HTMLScriptElement.supports('speculationrules');
	function norm(u) { try { var x = new URL(u, location.href); return x.origin + x.pathname; } catch (e) { return ''; } }
	var known = {}; cfg.urls.forEach(function (u) { known[norm(u)] = u; });
	function fetchAll(list) {
		list = list.filter(function (u) { return !done[u]; }); if (!list.length) { return; }
		list.forEach(function (u) { done[u] = 1; });
		if (rules) {
			var s = d.createElement('script'); s.type = 'speculationrules';
			s.textContent = JSON.stringify({ prefetch: [{ source: 'list', urls: list }] }); d.head.appendChild(s);
		} else {
			list.forEach(function (u) { var l = d.createElement('link'); l.rel = 'prefetch'; l.href = u; d.head.appendChild(l); });
		}
	}
	function intent(e) {
		var t = e.target; if (!t || !t.closest) { return; }
		if (t.closest(cfg.sel)) { fetchAll(cfg.urls); return; }
		var a = t.closest('a[href]'); if (a && known[norm(a.href)]) { fetchAll([known[norm(a.href)]]); }
	}
	['pointerover', 'touchstart', 'focusin'].forEach(function (ev) { d.addEventListener(ev, intent, { passive: true }); });
})(document, navigator);
</script>
		<?php
	}
}
