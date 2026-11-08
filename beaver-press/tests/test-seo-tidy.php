<?php
// Beaver Press test: run from the command line only (see tests/README.md).
// 3.2.0: soft hyphens out of head/JSON-LD/attributes, description fit, og:locale codes, theme-file cache stamp.
if ( PHP_SAPI !== 'cli' ) { exit; }
$_SERVER['HTTP_HOST'] = getenv( 'BP_HOST' ) ?: 'localhost'; $_SERVER['REQUEST_URI'] = '/';
require __DIR__ . '/bootstrap.php'; // WordPress + settings put back however the test ends.
$pass = 0; $fail = 0;
function ok( $n, $c, $x = '' ) { global $pass, $fail; $c ? $pass++ : $fail++; echo ( $c ? 'PASS ' : 'FAIL ' ), $n, $x ? "  [$x]" : '', "\n"; }
$shy = "\u{00AD}";

// 1. Soft hyphens.
$html = "<html><head><title>Kili{$shy}man{$shy}jaro</title><meta name=\"description\" content=\"Ber{$shy}ge\" />"
	. "<meta property=\"og:title\" content=\"Kili&shy;man&#173;jaro\" /></head><body>"
	. "<h1 aria-label=\"Ber{$shy}ge\">Ber{$shy}ge</h1><img alt=\"Lö{$shy}we\" title=\"Lö{$shy}we\" src=\"x\">"
	. "<input placeholder=\"Su{$shy}chen\"><p>Ki{$shy}li</p>"
	. "<script type=\"application/ld+json\">{\"name\":\"Kili{$shy}man\\u00adjaro\"}</script></body></html>";
$out  = BP_Seo::tidy_html( $html );
$head = explode( '</head>', $out )[0];
ok( 'no soft hyphen left in <head> (character or entity)', false === strpos( $head, $shy ) && false === strpos( $head, '&shy;' ) && false === strpos( $head, '&#173;' ), $head );
ok( 'title reads Kilimanjaro', false !== strpos( $out, '<title>Kilimanjaro</title>' ) );
ok( 'JSON-LD: character and \\u00ad escape removed', false !== strpos( $out, '{"name":"Kilimanjaro"}' ) );
ok( 'alt, title, aria-label, placeholder cleaned', false !== strpos( $out, 'alt="Löwe" title="Löwe"' ) && false !== strpos( $out, 'aria-label="Berge"' ) && false !== strpos( $out, 'placeholder="Suchen"' ) );
ok( 'visible text keeps its soft hyphens', false !== strpos( $out, ">Ber{$shy}ge</h1>" ) && false !== strpos( $out, "<p>Ki{$shy}li</p>" ) );
ok( 'non-string passes through', null === BP_Seo::tidy_html( null ) && '' === BP_Seo::tidy_html( '' ) );

// 2. Descriptions.
$one  = 'Erster Satz ist hier und lang genug, damit er sicher mehr als achtzig Zeichen hat, ganz sicher.';
$long = $one . ' Zweiter Satz macht die Beschreibung viel zu lang für die Ergebnisse einer Suche.';
$page = static function ( $d ) { return '<html><head><meta name="description" content="' . esc_attr( $d ) . '" /><meta property="og:description" content="' . esc_attr( $d ) . '" /></head><body></body></html>'; };
$out  = BP_Seo::tidy_html( $page( $long ) );
ok( 'description over 160 ends at last full sentence', 2 === substr_count( $out, 'content="' . esc_attr( $one ) . '"' ), (string) mb_strlen( $long ) );
$short = 'Kurz. Und gut.';
ok( 'description within 160 untouched', false !== strpos( BP_Seo::tidy_html( $page( $short ) ), 'content="Kurz. Und gut."' ) );
$nostop = str_repeat( 'Wort ', 40 ) . 'Ende';
ok( 'one long sentence is left as written', false !== strpos( BP_Seo::tidy_html( $page( $nostop ) ), 'content="' . $nostop . '"' ) );
$ent = 'L&#039;itinéraire est clair et la journée commence tôt, avec un thé chaud apporté à la tente du camp. ' . 'La suite du texte rend la description beaucoup trop longue pour un résultat.';
$o   = BP_Seo::tidy_html( '<html><head><meta name="description" content="' . $ent . '" /></head><body></body></html>' );
ok( 'escaped apostrophe survives the cut', 1 === preg_match( '/content="L&#039;itinéraire[^"]*tente du camp\."/', $o ), $o );
$ar = 'هذه جملة عربية أولى طويلة بما يكفي لتتجاوز ثمانين حرفًا، وتصف رحلة سفاري في تنزانيا مع مرشد خاص؟ ' . 'والجملة الثانية تجعل الوصف أطول بكثير مما يظهر في نتائج البحث عند جوجل وغيره.';
ok( 'Arabic question mark ends a sentence', false !== strpos( BP_Seo::tidy_html( $page( $ar ) ), 'مع مرشد خاص؟"' ), (string) mb_strlen( $ar ) );
$edge = str_repeat( 'a', 150 ) . ' bbbbbbbbb. ' . str_repeat( 'c', 30 ); // stop at character 161: must not be used
$o    = BP_Seo::tidy_html( $page( 'Satz eins ist kurz genug, aber mit mehr als achtzig Zeichen geschrieben worden, wirklich, ganz sicher. ' . $edge ) );
ok( 'never longer than 160 (stop at 161 skipped)', 1 === preg_match( '/content="([^"]*)"/', $o, $mm ) && mb_strlen( html_entity_decode( $mm[1] ) ) <= 160, isset( $mm[1] ) ? (string) mb_strlen( $mm[1] ) : '' );
$s = TRP_Translate_Press::get_trp_instance()->get_component( 'settings' )->get_settings();
ok( 'original language keeps its long description', false !== strpos( BP_Seo::tidy_html( $page( $long ), $s['default-language'] ), esc_attr( $long ) ) );
ok( 'translated language is fitted', false === strpos( BP_Seo::tidy_html( $page( $long ), 'xx_XX' ), esc_attr( $long ) ) );
add_filter( 'beaver_press_meta_description_max', '__return_zero' );
ok( 'filter 0 turns the fit off', false !== strpos( BP_Seo::tidy_html( $page( $long ) ), esc_attr( $long ) ) );
remove_filter( 'beaver_press_meta_description_max', '__return_zero' );

// 3. og:locale codes.
$code = new ReflectionMethod( 'BP_Seo', 'og_code' );
$code->setAccessible( true );
foreach ( array( 'de_DE_formal' => 'de_DE', 'pt_PT_ao90' => 'pt_PT', 'en_US' => 'en_US', 'ar' => 'ar_AR', 'sw' => 'sw_KE', 'fr' => 'fr_FR', 'zh_HK' => 'zh_HK', 'bad' => 'bad', '_x' => '' ) as $in => $want ) {
	ok( "og code $in -> $want", $want === $code->invoke( null, $in ) );
}
ob_start(); BP_Seo::og_locale(); $meta = ob_get_clean();
ok( 'og:locale printed for the original language', 1 === preg_match( '/<meta property="og:locale" content="[a-z]{2,3}(_[A-Z]{2})?" \/>/', $meta ), trim( $meta ) );
add_filter( 'beaver_press_og_locale', '__return_false' );
ob_start(); BP_Seo::og_locale(); $meta = ob_get_clean();
ok( 'beaver_press_og_locale false prints nothing', '' === $meta );
remove_filter( 'beaver_press_og_locale', '__return_false' );

// 4. Theme files start a new cache generation.
$marker = BP_Cache::root() . '/theme-stamp-' . get_current_blog_id() . '.txt';
$style  = get_stylesheet_directory() . '/style.css';
$mtime  = filemtime( $style );
$a      = BP_Cache::theme_stamp();
ok( 'stamp kept for a minute (marker written)', is_file( $marker ) && $a === BP_Cache::theme_stamp(), $a );
touch( $style, time() + 3 ); clearstatcache();
ok( 'within the minute an edit is not looked for yet', $a === BP_Cache::theme_stamp() );
@unlink( $marker );
$b = BP_Cache::theme_stamp();
ok( 'after the minute the edit changes the stamp', $a !== $b, "$a -> $b" );
add_filter( 'beaver_press_cache_watch_theme', '__return_false' );
ok( 'watch off: empty stamp', '' === BP_Cache::theme_stamp() );
remove_filter( 'beaver_press_cache_watch_theme', '__return_false' );
touch( $style, $mtime ); @unlink( $marker ); clearstatcache();
ok( 'stamp back after restoring the file time', $a === BP_Cache::theme_stamp() );

echo "\n$pass passed, $fail failed\n";
