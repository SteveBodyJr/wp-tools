<?php
/**
 * Language suggestion: "View this page in Français?" for visitors whose browser prefers
 * another language this site offers.
 *
 * Runs in the browser (navigator.languages), so every visitor gets the same HTML and ready
 * translated pages keep working. The bar is written in the suggested language, never
 * translated by TranslatePress, and remembered once the visitor answers or picks a language
 * in the switcher (cookie `bp_lang`, one year). Optional, off by default: send a first-time
 * visitor on a default-language page straight to their language (people only; never search
 * engines, logged-in users or someone who has already chosen), since automatic redirects can
 * stop search engines from indexing the original pages.
 *
 * @package BeaverPress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Suggestion bar and optional first-visit redirect.
 */
final class BP_Suggest {

	/** Option: suggestion bar, 'yes' (default) or 'no'. */
	const OPTION = 'beaver_press_suggest';

	/** Option: first-visit redirect, 'no' (default) or 'yes'. */
	const REDIRECT_OPTION = 'beaver_press_redirect';

	/**
	 * Bar texts in each language: question (%s = language name), yes, no.
	 * Keyed by ISO 639-1 code; other languages fall back to English with the native name.
	 */
	const TEXTS = array(
		'en' => array( 'View this page in %s?', 'Yes', 'No thanks' ),
		'fr' => array( 'Voir cette page en %s ?', 'Oui', 'Non merci' ),
		'es' => array( '¿Ver esta página en %s?', 'Sí', 'No, gracias' ),
		'de' => array( 'Diese Seite auf %s ansehen?', 'Ja', 'Nein, danke' ),
		'da' => array( 'Se denne side på %s?', 'Ja', 'Nej tak' ),
		'sv' => array( 'Visa den här sidan på %s?', 'Ja', 'Nej tack' ),
		'nb' => array( 'Se denne siden på %s?', 'Ja', 'Nei takk' ),
		'nn' => array( 'Sjå denne sida på %s?', 'Ja', 'Nei takk' ),
		'it' => array( 'Vedere questa pagina in %s?', 'Sì', 'No, grazie' ),
		'pt' => array( 'Ver esta página em %s?', 'Sim', 'Não, obrigado' ),
		'nl' => array( 'Deze pagina in het %s bekijken?', 'Ja', 'Nee, bedankt' ),
		'fi' => array( 'Näytetäänkö tämä sivu kielellä %s?', 'Kyllä', 'Ei kiitos' ),
		'pl' => array( 'Wyświetlić tę stronę w języku: %s?', 'Tak', 'Nie, dziękuję' ),
		'cs' => array( 'Zobrazit tuto stránku v jazyce %s?', 'Ano', 'Ne, děkuji' ),
		'ru' => array( 'Открыть эту страницу на языке: %s?', 'Да', 'Нет, спасибо' ),
		'tr' => array( 'Bu sayfa %s görüntülensin mi?', 'Evet', 'Hayır, teşekkürler' ),
		'sw' => array( 'Tazama ukurasa huu kwa %s?', 'Ndiyo', 'Hapana, asante' ),
		'ar' => array( 'عرض هذه الصفحة باللغة %s؟', 'نعم', 'لا، شكرًا' ),
		'zh' => array( '以%s查看此页面？', '是', '不用了' ),
		'ja' => array( 'このページを%sで表示しますか？', 'はい', 'いいえ' ),
		'ko' => array( '이 페이지를 %s(으)로 보시겠습니까?', '예', '아니요' ),
	);

	/** Language names as used inside the question, where they differ from the native name. */
	const NAMES_IN_SENTENCE = array(
		'fr' => 'français',
		'es' => 'español',
		'de' => 'Deutsch',
		'da' => 'dansk',
		'sv' => 'svenska',
		'nb' => 'norsk',
		'it' => 'italiano',
		'pt' => 'português',
		'nl' => 'Nederlands',
	);

	/**
	 * Hooks.
	 */
	public static function init() {
		if ( self::enabled() || self::redirect_enabled() ) {
			add_action( 'wp_footer', array( __CLASS__, 'output' ), 98 );
		}
	}

	/**
	 * Whether the bar is on.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return (bool) apply_filters( 'beaver_press_suggest', 'no' !== get_option( self::OPTION, 'yes' ) );
	}

	/**
	 * Whether the first-visit redirect is on.
	 *
	 * @return bool
	 */
	public static function redirect_enabled() {
		return (bool) apply_filters( 'beaver_press_redirect', 'yes' === get_option( self::REDIRECT_OPTION, 'no' ) );
	}

	/**
	 * Offered languages with this page's address and the bar texts.
	 *
	 * @return array
	 */
	public static function languages() {
		$trp       = TRP_Translate_Press::get_trp_instance();
		$settings  = $trp->get_component( 'settings' )->get_settings();
		$converter = $trp->get_component( 'url_converter' );
		$codes     = (array) ( $settings['publish-languages'] ?? array() );
		$names     = $trp->get_component( 'languages' )->get_language_names( $codes, 'native_name' );
		$out       = array();
		$original  = (string) $converter->get_url_for_language( $settings['default-language'], null, '' );
		foreach ( $codes as $code ) {
			$url = $converter->get_url_for_language( $code, null, '' );
			if ( ! is_string( $url ) || '' === $url || BP_Complete::known_incomplete( $code, $original ) ) {
				continue; // Not offered where visitors would only get the original page.
			}
			$tag   = strtolower( str_replace( '_', '-', $code ) );        // fr-fr, pt-br.
			$base  = strtok( $tag, '-' );                                // fr.
			$base  = 'no' === $base ? 'nb' : $base;
			$texts = self::TEXTS[ $base ] ?? self::TEXTS['en'];
			$name  = self::NAMES_IN_SENTENCE[ $base ] ?? (string) ( $names[ $code ] ?? $code );
			$out[] = array(
				'code' => $code,
				'tag'  => $tag,
				'base' => $base,
				'url'  => $url,
				'dir'  => in_array( $base, array( 'ar', 'he', 'fa', 'ur' ), true ) ? 'rtl' : 'ltr',
				'q'    => sprintf( $texts[0], $name ),
				'yes'  => $texts[1],
				'no'   => $texts[2],
			);
		}
		return (array) apply_filters( 'beaver_press_suggest_languages', $out );
	}

	/**
	 * Bar, style and script in the footer.
	 */
	public static function output() {
		global $TRP_LANGUAGE;
		if ( is_admin() || is_feed() || is_embed() || ( function_exists( 'trp_is_translation_editor' ) && trp_is_translation_editor() ) ) {
			return;
		}
		$languages = self::languages();
		if ( count( $languages ) < 2 ) {
			return;
		}
		$settings = TRP_Translate_Press::get_trp_instance()->get_component( 'settings' )->get_settings();
		$data     = array(
			'langs'    => $languages,
			'current'  => (string) $TRP_LANGUAGE,
			'default'  => (string) $settings['default-language'],
			'bar'      => self::enabled(),
			'redirect' => self::redirect_enabled() && ! is_user_logged_in(),
			'visitor'  => ! is_user_logged_in(),
		);
		?>
<div class="bp-suggest" id="bp-suggest" role="region" aria-live="polite" hidden data-no-translation translate="no">
	<p class="bp-suggest__text"></p>
	<a class="bp-suggest__yes" href="#"></a>
	<button type="button" class="bp-suggest__no"></button>
</div>
<style>
.bp-suggest{position:fixed;left:16px;right:16px;bottom:16px;z-index:99990;display:flex;flex-wrap:wrap;align-items:center;gap:8px 16px;max-width:560px;margin:0 auto;padding:12px 16px;background:var(--bp-suggest-bg,#fff);color:var(--bp-suggest-fg,#1d1d1d);border:1px solid var(--bp-suggest-line,rgba(0,0,0,.14));box-shadow:0 10px 30px rgba(0,0,0,.16);font-size:15px;line-height:1.4}
.bp-suggest[hidden]{display:none}
.bp-suggest__text{flex:1 1 220px;margin:0}
.bp-suggest__yes{display:inline-block;padding:8px 16px;background:var(--bp-suggest-accent,#1d1d1d);color:var(--bp-suggest-accent-fg,#fff);text-decoration:none;font-weight:600}
.bp-suggest__no{padding:8px 4px;background:none;border:0;color:inherit;font:inherit;text-decoration:underline;cursor:pointer}
@media (prefers-reduced-motion:no-preference){.bp-suggest:not([hidden]){animation:bp-suggest-in .3s ease-out}@keyframes bp-suggest-in{from{opacity:0;transform:translateY(12px)}}}
</style>
<script data-no-translation>
(function (d, n, w) {
	var cfg = <?php echo wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); ?>;
	if (/[?&]trp-edit-translation=/.test(location.search)) { return; }
	function getC() { var m = d.cookie.match(/(?:^|; )bp_lang=([^;]*)/); return m ? decodeURIComponent(m[1]) : ''; }
	function setC(v) { d.cookie = 'bp_lang=' + encodeURIComponent(v) + '; path=/; max-age=31536000; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : ''); }
	function norm(u) { try { var x = new URL(u, location.href); return x.origin + x.pathname; } catch (e) { return ''; } }
	var byUrl = {}; cfg.langs.forEach(function (l) { byUrl[norm(l.url)] = l; });
	// A language picked anywhere (switcher, links) is the visitor's choice from now on.
	d.addEventListener('click', function (e) {
		var a = e.target && e.target.closest && e.target.closest('a[href]'); var l = a && byUrl[norm(a.href)];
		if (l && !a.classList.contains('bp-suggest__yes')) { setC('chosen:' + l.code); }
	}, true);
	if (getC() || d.getElementById('bp-untranslated')) { return; }
	// The visitor's preferred language among those offered: exact match first, then base language.
	var want = null, prefs = (n.languages && n.languages.length ? n.languages : [n.language || '']);
	for (var i = 0; i < prefs.length && !want; i++) {
		var p = String(prefs[i]).toLowerCase(), b = p.split('-')[0] === 'no' ? 'nb' : p.split('-')[0];
		cfg.langs.forEach(function (l) { if (!want && l.tag === p) { want = l; } });
		cfg.langs.forEach(function (l) { if (!want && l.base === b) { want = l; } });
	}
	if (!want || want.code === cfg.current) { return; }
	var bot = /bot|crawl|spider|slurp|mediapartners|lighthouse|headless|preview|facebookexternalhit|embedly|quora|pinterest|whatsapp|telegram/i.test(n.userAgent);
	if (cfg.redirect && !bot && cfg.current === cfg['default'] && !n.webdriver) {
		setC('auto:' + want.code); location.replace(want.url); return;
	}
	if (!cfg.bar || bot) { return; }
	var bar = d.getElementById('bp-suggest'); if (!bar) { return; }
	bar.lang = want.tag; bar.dir = want.dir;
	bar.querySelector('.bp-suggest__text').textContent = want.q;
	var yes = bar.querySelector('.bp-suggest__yes'); yes.textContent = want.yes; yes.href = want.url; yes.hreflang = want.tag;
	yes.addEventListener('click', function () { setC('chosen:' + want.code); });
	var no = bar.querySelector('.bp-suggest__no'); no.textContent = want.no;
	no.addEventListener('click', function () { setC('dismissed:' + want.code); bar.hidden = true; });
	bar.hidden = false;
	// Sit above anything else fixed at the bottom (cookie notices, chat buttons); follow it when it closes.
	function lift() {
		var top = innerHeight, all = d.body.getElementsByTagName('*');
		for (var k = 0; k < all.length; k++) {
			var el = all[k]; if (el === bar || bar.contains(el) || el.contains(bar)) { continue; }
			var cs = getComputedStyle(el); if (cs.position !== 'fixed' || cs.display === 'none' || cs.visibility === 'hidden') { continue; }
			var r = el.getBoundingClientRect(); if (r.height > 0 && r.height < innerHeight * 0.6 && r.bottom >= innerHeight - 40 && r.top > innerHeight * 0.3) { top = Math.min(top, r.top); }
		}
		bar.style.bottom = (innerHeight - top + 16) + 'px';
	}
	lift(); setTimeout(lift, 350); setTimeout(lift, 800); w.addEventListener('resize', lift);
	var ticks = 0, timer = setInterval(function () { lift(); if (++ticks > 60 || bar.hidden) { clearInterval(timer); } }, 1000);
	d.addEventListener('click', function () { setTimeout(lift, 400); });
	// Fetch the suggested page now, so "yes" opens it at once (visitors only: an editor's visit may start paid translations).
	if (cfg.visitor && HTMLScriptElement.supports && HTMLScriptElement.supports('speculationrules')) {
		var s = d.createElement('script'); s.type = 'speculationrules'; s.textContent = JSON.stringify({ prefetch: [{ source: 'list', urls: [want.url] }] }); d.head.appendChild(s);
	}
})(document, navigator, window);
</script>
		<?php
	}
}
