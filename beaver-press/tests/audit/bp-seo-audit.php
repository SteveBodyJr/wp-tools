<?php
/**
 * Beaver Press multilingual SEO / URL audit (V1).
 *
 * Fetches the site over HTTP as a logged-out visitor (desktop and mobile) and checks, per
 * language: status codes, canonical (self or, for a fallback page, the original), hreflang
 * (self, two-way, x-default, no duplicates), html lang, translated title and description,
 * internal links staying in the language, translated slugs and 301s from old addresses,
 * expected 404s, language sitemaps (valid XML, every address resolving). With --mutate it also
 * changes the site for a moment: permalink structure, default theme, an Elementor page and a
 * Gutenberg page (each put back afterwards).
 *
 * Runs ONLY on a disposable copy: the site must have the option beaver_press_disposable_site =
 * yes. Never on a client's live site.
 *
 *   BP_WP_LOAD=/path/to/copy/wp-load.php BP_SITE=http://localhost/copy \
 *     php bp-seo-audit.php [--all] [--mutate] [--report=/path/report.md]
 *
 * Output: one line per failed or warned check (URL + check + detail), a summary, and a Markdown
 * report. Exit code 1 when anything failed.
 *
 * @package BeaverPress
 */

if ( PHP_SAPI !== 'cli' ) {
	exit;
}
$opts = getopt( '', array( 'all', 'mutate', 'report:' ) );
$_SERVER['HTTP_HOST']   = (string) ( wp_parse_host_env() );
$_SERVER['REQUEST_URI'] = '/';
require getenv( 'BP_WP_LOAD' ) ?: dirname( __DIR__, 5 ) . '/wp-load.php';

/**
 * Host from BP_SITE, so the copy's own config builds the right URLs.
 *
 * @return string
 */
function wp_parse_host_env() {
	$site = (string) getenv( 'BP_SITE' );
	$host = '' !== $site ? (string) parse_url( $site, PHP_URL_HOST ) : 'localhost'; // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- before WordPress loads.
	$port = '' !== $site ? parse_url( $site, PHP_URL_PORT ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
	return $host . ( $port ? ':' . $port : '' );
}

if ( 'yes' !== get_option( 'beaver_press_disposable_site' ) ) {
	fwrite( STDERR, "Refused: this site is not marked as a disposable test copy (option beaver_press_disposable_site).\n" );
	exit( 2 );
}

/* ------------------------------------------------------------------ */
/* Report                                                               */
/* ------------------------------------------------------------------ */

$GLOBALS['rows']  = array();
$GLOBALS['count'] = array( 'PASS' => 0, 'FAIL' => 0, 'WARN' => 0 );

/**
 * Record a check.
 *
 * @param string $status PASS, FAIL or WARN.
 * @param string $check  Check name.
 * @param string $url    URL.
 * @param string $detail Detail.
 */
function rec( $status, $check, $url = '', $detail = '' ) {
	$GLOBALS['count'][ $status ]++;
	if ( 'PASS' !== $status ) {
		$GLOBALS['rows'][] = array( $status, $check, $url, $detail );
		echo $status, '  ', $check, '  ', $url, $detail ? '  [' . $detail . ']' : '', "\n";
	}
	$GLOBALS['checks'][ $check ][ $status ] = ( $GLOBALS['checks'][ $check ][ $status ] ?? 0 ) + 1;
}

/**
 * PASS or FAIL.
 *
 * @param bool   $ok     Passed.
 * @param string $check  Check.
 * @param string $url    URL.
 * @param string $detail Detail when failed.
 */
function ok( $ok, $check, $url = '', $detail = '' ) {
	rec( $ok ? 'PASS' : 'FAIL', $check, $url, $ok ? '' : $detail );
}

/* ------------------------------------------------------------------ */
/* HTTP                                                                 */
/* ------------------------------------------------------------------ */

const UA_DESKTOP = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Safari/537.36 BeaverAudit';
const UA_MOBILE  = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1 BeaverAudit';

$GLOBALS['cache'] = array();

/**
 * Fetch URLs in parallel (no redirects followed, no cookies).
 *
 * @param string[] $urls URLs.
 * @param string   $ua   User agent.
 * @return array URL => [ code, location, body ].
 */
function fetch_many( array $urls, $ua = UA_DESKTOP ) {
	$out  = array();
	$todo = array();
	foreach ( array_unique( $urls ) as $u ) {
		if ( isset( $GLOBALS['cache'][ $ua ][ $u ] ) ) {
			$out[ $u ] = $GLOBALS['cache'][ $ua ][ $u ];
		} else {
			$todo[] = $u;
		}
	}
	foreach ( array_chunk( $todo, 8 ) as $chunk ) {
		$mh = curl_multi_init();
		$hs = array();
		foreach ( $chunk as $u ) {
			$h = curl_init( $u );
			curl_setopt_array( $h, array( CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_USERAGENT => $ua, CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => array( 'Accept-Language: en-US,en;q=0.9' ) ) );
			curl_multi_add_handle( $mh, $h );
			$hs[ $u ] = $h;
		}
		do {
			curl_multi_exec( $mh, $running );
			curl_multi_select( $mh, 1 );
		} while ( $running );
		foreach ( $hs as $u => $h ) {
			$res = array( (int) curl_getinfo( $h, CURLINFO_RESPONSE_CODE ), (string) curl_getinfo( $h, CURLINFO_REDIRECT_URL ), (string) curl_multi_getcontent( $h ) );
			$GLOBALS['cache'][ $ua ][ $u ] = $res;
			$out[ $u ]                     = $res;
			curl_multi_remove_handle( $mh, $h );
		}
		curl_multi_close( $mh );
	}
	return $out;
}

/**
 * Head facts of a page.
 *
 * @param string $html HTML.
 * @return array
 */
function head_of( $html ) {
	$f = array( 'lang' => '', 'canonical' => array(), 'hreflang' => array(), 'robots' => '', 'title' => array(), 'desc' => array(), 'og_title' => array(), 'viewport' => false );
	if ( preg_match( '/<html[^>]*\blang="([^"]*)"/i', $html, $m ) ) {
		$f['lang'] = $m[1];
	}
	$head = (string) strstr( $html, '</head>', true );
	preg_match_all( '/<link\s+rel="canonical"\s+href="([^"]*)"/i', $head, $m );
	$f['canonical'] = $m[1];
	preg_match_all( '/<link\s+rel="alternate"\s+hreflang="([^"]*)"\s+href="([^"]*)"/i', $head, $m, PREG_SET_ORDER );
	foreach ( $m as $x ) {
		$f['hreflang'][ $x[1] ][] = html_entity_decode( $x[2] );
	}
	if ( preg_match( '/<meta\s+name="robots"\s+content="([^"]*)"/i', $head, $m ) ) {
		$f['robots'] = $m[1];
	}
	preg_match_all( '#<title[^>]*>(.*?)</title>#is', $head, $m );
	$f['title'] = array_map( 'trim', $m[1] );
	preg_match_all( '/<meta\s+name="description"\s+content="([^"]*)"/i', $head, $m );
	$f['desc'] = $m[1];
	preg_match_all( '/<meta\s+property="og:title"\s+content="([^"]*)"/i', $head, $m );
	$f['og_title'] = $m[1];
	$f['viewport'] = (bool) preg_match( '/<meta\s+name="viewport"/i', $head );
	$f['fallback'] = false !== strpos( $html, 'id="bp-untranslated"' ); // Beaver Press "not available yet" page.
	$f['noindex']  = false !== stripos( $f['robots'], 'noindex' );
	return $f;
}

/* ------------------------------------------------------------------ */
/* Site facts                                                           */
/* ------------------------------------------------------------------ */

$trp      = TRP_Translate_Press::get_trp_instance()->get_component( 'settings' )->get_settings();
$default  = (string) $trp['default-language'];
$langs    = array_values( array_unique( array_merge( array( $default ), (array) $trp['translation-languages'] ) ) );
$home     = untrailingslashit( home_url() );
$slug_of  = static fn( $l ) => $l === $default ? '' : (string) $trp['url-slugs'][ $l ];
$short    = static fn( $l ) => strtolower( substr( $l, 0, 2 ) );
$in_lang  = static fn( $url, $l ) => $l === $default ? $url : BP_Run::url_in( $url, $l );
$all      = isset( $opts['all'] );

$originals = BP_Run::urls();
if ( ! $all ) {
	// Representative: the home page, then the first three of each address family.
	$pick = array( trailingslashit( $home ) );
	$seen = array();
	foreach ( $originals as $u ) {
		$family = strtok( trim( (string) substr( $u, strlen( $home ) ), '/' ), '/' ) ?: '/';
		$depth  = substr_count( trim( (string) substr( $u, strlen( $home ) ), '/' ), '/' );
		$family = $depth ? $family : 'page';
		if ( ( $seen[ $family ] = ( $seen[ $family ] ?? 0 ) + 1 ) <= 3 ) {
			$pick[] = $u;
		}
	}
	$originals = array_values( array_unique( $pick ) );
}
echo 'Site ', $home, ' - ', count( $langs ), ' languages, ', count( $originals ), ' original addresses', $all ? ' (all)' : ' (representative)', "\n\n";

// Start from the site's current state: a fresh address map, and every sampled page rendered
// once in every language (uncached), so each page's completeness record is current. A real site
// does the same in the background after a change (ready-page warm-up).
BP_Slugs::forget_map();
wp_cache_flush();
$warm = array();
foreach ( $originals as $o ) {
	foreach ( $langs as $l ) {
		$warm[] = $in_lang( $o, $l ) . '?bp-audit-warm=1';
	}
}
fetch_many( $warm );
$GLOBALS['cache'] = array();
wp_cache_flush();

/**
 * Whether a page is the "not complete yet" fallback (original content, noindex).
 *
 * @param array $f Head facts.
 * @return bool
 */
function is_fallback( array $f ) {
	return ! empty( $f['fallback'] );
}

/* ------------------------------------------------------------------ */
/* 1. Pages in every language                                           */
/* ------------------------------------------------------------------ */

/**
 * Check every original address in every language.
 *
 * @param string[] $originals Original addresses.
 * @param string   $label     Label for the checks ("" or "default theme"...).
 * @return array URL => head facts (complete pages only), for later checks.
 */
function check_pages( array $originals, $label = '' ) {
	global $langs, $default, $home, $slug_of, $short, $in_lang;
	$p      = '' !== $label ? $label . ': ' : '';
	$urls   = array();
	foreach ( $originals as $o ) {
		foreach ( $langs as $l ) {
			$urls[ $o ][ $l ] = $in_lang( $o, $l );
		}
	}
	$flat  = array_merge( ...array_values( array_map( 'array_values', $urls ) ) );
	$res   = fetch_many( $flat );
	$facts = array();
	foreach ( $urls as $o => $by_lang ) {
		$en_head = head_of( $res[ $by_lang[ $default ] ][2] );
		if ( 200 !== $res[ $by_lang[ $default ] ][0] ) {
			// The original itself redirects (e.g. an empty WooCommerce checkout, a builder's internal
			// post type): nothing to check about its translations.
			rec( 'WARN', $p . 'original page does not answer 200 (not a translation issue)', $by_lang[ $default ], $res[ $by_lang[ $default ] ][0] . ' -> ' . $res[ $by_lang[ $default ] ][1] );
			continue;
		}
		$no_canon = ! $en_head['canonical']; // The SEO plugin prints none here (e.g. Yoast on a noindex page).
		foreach ( $by_lang as $l => $u ) {
			list( $code, $loc, $body ) = $res[ $u ];
			ok( 200 === $code, $p . 'status 200', $u, $code . ( $loc ? ' -> ' . $loc : '' ) );
			if ( 200 !== $code ) {
				continue;
			}
			$f = head_of( $body );
			ok( $no_canon ? ! $f['canonical'] : 1 === count( $f['canonical'] ), $p . 'one canonical (none where the original has none)', $u, count( $f['canonical'] ) . ' found' );
			if ( count( $f['title'] ) > 1 && count( $f['title'] ) === count( $en_head['title'] ) ) {
				rec( 'WARN', $p . 'several <title> tags, already on the original page (theme or SEO plugin)', $u, count( $f['title'] ) . ' found' );
			} else {
				ok( 1 === count( $f['title'] ), $p . 'one <title>', $u, count( $f['title'] ) . ' found' );
			}
			ok( count( $f['desc'] ) <= 1, $p . 'at most one meta description', $u, count( $f['desc'] ) . ' found' );
			$dup = array_filter( $f['hreflang'], static fn( $hrefs ) => count( $hrefs ) > 1 );
			ok( ! $dup, $p . 'no duplicate hreflang', $u, implode( ',', array_keys( $dup ) ) );
			ok( false === strpos( $body, 'id="wpadminbar"' ), $p . 'logged-out output (no admin bar)', $u );
			if ( is_fallback( $f ) ) {
				// Not complete in this language: original content, so the original is canonical.
				ok( $no_canon ? ! $f['canonical'] : ( $f['canonical'][0] ?? '' ) === $by_lang[ $default ], $p . 'fallback page: canonical = original', $u, 'canonical ' . ( $f['canonical'][0] ?? 'none' ) );
				ok( ! $f['hreflang'], $p . 'fallback page: no hreflang', $u, count( $f['hreflang'] ) . ' hreflang tags' );
				// A text that changes by date (a month inside a sentence, or one seen missing on several
				// days) makes the page incomplete again and again: it belongs in the theme as a fixed
				// text plus a separate value.
				$missing_now = BP_Complete::missing_texts( $l, BP_Complete::key( $o ) );
				$recurring   = array_filter( $missing_now, array( 'BP_Complete', 'recurring' ) );
				ok( ! $recurring, $p . 'no text that comes back every day', $u, implode( ' | ', $recurring ) );
				$months = array_filter( $missing_now, static fn( $t ) => preg_match( '/\b(January|February|March|April|May|June|July|August|September|October|November|December)\b/', $t ) && str_word_count( $t ) > 1 && mb_strlen( $t ) < 80 );
				if ( $months ) {
					rec( 'WARN', $p . 'short text with a month name inside (new text each month?)', $u, implode( ' | ', $months ) );
				}
				rec( 'WARN', $p . 'page not complete in this language (served in the original, noindex)', $u );
				continue;
			}
			$facts[ $u ] = $f + array( 'lang_code' => $l, 'original' => $o );
			ok( $no_canon ? ! $f['canonical'] : ( $f['canonical'][0] ?? '' ) === $u, $p . 'self canonical', $u, 'canonical ' . ( $f['canonical'][0] ?? 'none' ) );
			ok( 0 === stripos( $f['lang'], $short( $l ) ), $p . 'html lang', $u, 'lang="' . $f['lang'] . '"' );
			$self = $f['hreflang'][ str_replace( '_', '-', $l ) ][0] ?? ( $f['hreflang'][ $short( $l ) ][0] ?? '' );
			ok( $self === $u, $p . 'hreflang lists the page itself', $u, 'got ' . ( $self ?: 'none' ) );
			ok( isset( $f['hreflang']['x-default'] ), $p . 'x-default hreflang', $u );
			if ( $l !== $default ) {
				$same_title = ( $f['title'][0] ?? '' ) === ( $en_head['title'][0] ?? '' );
				rec( $same_title ? 'WARN' : 'PASS', $p . 'title translated', $u, $same_title ? 'same as original: ' . ( $f['title'][0] ?? '' ) : '' );
				if ( $en_head['desc'] ) {
					$same = ( $f['desc'][0] ?? '' ) === $en_head['desc'][0];
					rec( $same ? 'WARN' : 'PASS', $p . 'meta description translated', $u, $same ? 'same as original' : '' );
				}
			}
			// Internal links stay in this language (language switcher links are the page's own alternates).
			$alts = array();
			foreach ( $f['hreflang'] as $hrefs ) {
				$alts = array_merge( $alts, $hrefs );
			}
			foreach ( $langs as $x ) {
				$alts[] = $in_lang( $o, $x ); // The language switcher links to this page in the other languages,
				$alts[] = $home . '/' . ( '' !== $slug_of( $x ) ? $slug_of( $x ) . '/' : '' ); // or to their home pages.
			}
			preg_match_all( '/<a\s[^>]*href="(' . preg_quote( $home, '/' ) . '[^"#]*)"/i', $body, $m );
			$bad = array();
			$pre = $home . '/' . ( '' !== $slug_of( $l ) ? $slug_of( $l ) . '/' : '' );
			foreach ( array_unique( $m[1] ) as $href ) {
				$href = html_entity_decode( $href );
				if ( preg_match( '#/(wp-content|wp-includes|wp-json|wp-admin|feed)/|\.(jpe?g|png|webp|gif|svg|pdf|xml|css|js)(\?|$)|[?&](s|add-to-cart)=#i', $href ) || in_array( $href, $alts, true ) ) {
					continue;
				}
				$in_other = false;
				foreach ( $langs as $x ) {
					if ( $x !== $l && '' !== $slug_of( $x ) && 0 === strpos( $href, $home . '/' . $slug_of( $x ) . '/' ) ) {
						$in_other = true;
					}
				}
				if ( ( $l !== $default && 0 !== strpos( $href, $pre ) && rtrim( $href, '/' ) !== rtrim( $pre, '/' ) ) || ( $l === $default && $in_other ) ) {
					$bad[] = $href;
				}
			}
			ok( ! $bad, $p . 'internal links stay in the language', $u, count( $bad ) . ' e.g. ' . implode( ' ', array_slice( $bad, 0, 2 ) ) );
		}
	}
	return $facts;
}

$facts = check_pages( $originals );

/* ------------------------------------------------------------------ */
/* 2. Two-way hreflang                                                  */
/* ------------------------------------------------------------------ */

$targets = array();
foreach ( $facts as $u => $f ) {
	foreach ( $f['hreflang'] as $code => $hrefs ) {
		$targets[] = $hrefs[0];
	}
}
$tres = fetch_many( $targets );
foreach ( $facts as $u => $f ) {
	foreach ( $f['hreflang'] as $code => $hrefs ) {
		if ( 'x-default' === $code || $hrefs[0] === $u ) {
			continue;
		}
		list( $code_t, , $body ) = $tres[ $hrefs[0] ];
		if ( 200 !== $code_t ) {
			ok( false, 'hreflang target answers 200', $u, $code . ' -> ' . $hrefs[0] . ' (' . $code_t . ')' );
			continue;
		}
		$t = head_of( $body );
		ok( ! is_fallback( $t ), 'hreflang points only to complete pages', $u, $code . ' -> ' . $hrefs[0] . ' is a not-yet-translated fallback' );
		$back = false;
		foreach ( $t['hreflang'] as $c2 => $h2 ) {
			$back = $back || in_array( $u, $h2, true );
		}
		ok( $back || is_fallback( $t ), 'two-way hreflang', $u, $code . ' -> ' . $hrefs[0] . ' does not link back' );
	}
}

/* ------------------------------------------------------------------ */
/* 3. Mobile                                                            */
/* ------------------------------------------------------------------ */

$mob_urls = array();
foreach ( array_slice( $originals, 0, 5 ) as $o ) {
	foreach ( $langs as $l ) {
		$mob_urls[] = $in_lang( $o, $l );
	}
}
$mres = fetch_many( $mob_urls, UA_MOBILE );
$dres = fetch_many( $mob_urls );
foreach ( $mob_urls as $u ) {
	$m = head_of( $mres[ $u ][2] );
	$d = head_of( $dres[ $u ][2] );
	ok( $mres[ $u ][0] === $dres[ $u ][0], 'mobile: same status as desktop', $u, $mres[ $u ][0] . ' vs ' . $dres[ $u ][0] );
	ok( $m['canonical'] === $d['canonical'] && $m['lang'] === $d['lang'] && $m['hreflang'] === $d['hreflang'], 'mobile: same canonical, lang, hreflang', $u );
	if ( 200 === $mres[ $u ][0] ) {
		ok( $m['viewport'], 'mobile: viewport meta', $u );
	}
}

// A query string (e.g. a newsletter's ?utm_source=) must not change the language links.
foreach ( array_slice( $mob_urls, 0, 14 ) as $u ) {
	$q = $u . '?utm_source=bp-audit';
	$a = head_of( fetch_many( array( $q ) )[ $q ][2] )['hreflang'];
	$b = head_of( $dres[ $u ][2] )['hreflang'];
	ok( array_keys( $a ) === array_keys( $b ), 'query string: same hreflang languages', $q, implode( ',', array_keys( $a ) ) . ' vs ' . implode( ',', array_keys( $b ) ) );
}

/* ------------------------------------------------------------------ */
/* 4. Language roots, translated slugs, 301s, 404s                      */
/* ------------------------------------------------------------------ */

foreach ( $langs as $l ) {
	if ( $l === $default ) {
		continue;
	}
	$root = $home . '/' . $slug_of( $l ) . '/';
	ok( 200 === fetch_many( array( $root ) )[ $root ][0], 'language root answers 200', $root );
	foreach ( array( $root . 'bp-audit-no-such-page-' . $short( $l ) . '/', $root . 'tours/bp-audit-no-such-tour/' ) as $missing ) {
		$c = fetch_many( array( $missing ) )[ $missing ][0];
		ok( 404 === $c, 'missing page answers 404', $missing, (string) $c );
	}
}
$missing = $home . '/bp-audit-no-such-page/';
ok( 404 === fetch_many( array( $missing ) )[ $missing ][0], 'missing page answers 404', $missing );

$translated = 0;
foreach ( $originals as $o ) {
	foreach ( $langs as $l ) {
		if ( $l === $default ) {
			continue;
		}
		$u   = $in_lang( $o, $l );
		$old = $home . '/' . $slug_of( $l ) . substr( $o, strlen( $home ) );
		if ( $u === $old ) {
			continue;
		}
		$translated++;
		$r = fetch_many( array( $old ) )[ $old ];
		ok( 301 === $r[0] && $r[1] === $u, 'old address 301 to the translated one', $old, $r[0] . ' -> ' . ( $r[1] ?: 'none' ) . ', want ' . $u );
		// The translated slug is not an address in the original language.
		$wrong = $home . substr( $u, strlen( $home . '/' . $slug_of( $l ) ) );
		if ( ! in_array( trailingslashit( $wrong ), array_map( 'trailingslashit', BP_Run::urls() ), true ) ) {
			$c = fetch_many( array( $wrong ) )[ $wrong ][0];
			ok( in_array( $c, array( 301, 404 ), true ), 'translated slug not served in the original language', $wrong, (string) $c );
		}
	}
}
rec( $translated ? 'PASS' : 'WARN', 'translated slugs in use', '', $translated . ' translated addresses checked' );

/* ------------------------------------------------------------------ */
/* 5. Sitemaps                                                          */
/* ------------------------------------------------------------------ */

// The active sitemap index: WordPress's own, or the SEO plugin's (Yoast/Rank Math, SEOPress).
foreach ( array( '/wp-sitemap.xml', '/sitemap_index.xml', '/sitemaps.xml' ) as $index_path ) {
	$index_url = $home . $index_path;
	$ix        = fetch_many( array( $index_url ) )[ $index_url ];
	if ( 200 === $ix[0] ) {
		break;
	}
}
libxml_use_internal_errors( true );
$xml = simplexml_load_string( $ix[2] );
ok( 200 === $ix[0] && false !== $xml, 'sitemap index is valid XML', $index_url, $ix[0] . ' ' . ( false === $xml ? 'invalid XML' : '' ) );
$maps = array();
if ( $xml ) {
	foreach ( $xml->sitemap as $s ) {
		$maps[] = (string) $s->loc;
	}
}
$sres = fetch_many( $maps );
$locs = array();
foreach ( $langs as $l ) {
	if ( $l === $default ) {
		continue;
	}
	$mine = array_filter( $maps, static fn( $m ) => false !== strpos( $m, 'languages-' . $slug_of( $l ) . '-' ) || false !== strpos( $m, 'bp-sitemap-' . $slug_of( $l ) . '-' ) );
	if ( ! BP_Sitemap::urls( $l ) ) {
		ok( ! $mine, 'no sitemap listed for a language without complete pages', $index_url, $l );
		continue;
	}
	ok( (bool) $mine, 'language sitemap listed in the index', $index_url, $l );
	foreach ( $mine as $m ) {
		$x = simplexml_load_string( $sres[ $m ][2] );
		ok( 200 === $sres[ $m ][0] && false !== $x, 'language sitemap is valid XML', $m );
		if ( ! $x ) {
			continue;
		}
		$list = array();
		foreach ( $x->url as $e ) {
			$list[] = (string) $e->loc;
		}
		ok( count( $list ) === count( array_unique( $list ) ), 'no duplicate addresses in a sitemap', $m );
		$wrong = array_filter( $list, static fn( $loc ) => 0 !== strpos( $loc, $home . '/' . $slug_of( $l ) . '/' ) );
		ok( ! $wrong, 'sitemap addresses are in its language', $m, implode( ' ', array_slice( $wrong, 0, 2 ) ) );
		$locs = array_merge( $locs, $all ? $list : array_slice( $list, 0, 25 ) );
	}
}
foreach ( fetch_many( $locs ) as $loc => $r ) {
	ok( 200 === $r[0], 'sitemap address answers 200', $loc, (string) $r[0] );
	if ( 200 === $r[0] ) {
		$f = head_of( $r[2] );
		ok( ! is_fallback( $f ) && ( $f['canonical'][0] ?? '' ) === $loc, 'sitemap address is complete and self-canonical', $loc, ( is_fallback( $f ) ? 'not-yet-translated fallback' : 'canonical ' . ( $f['canonical'][0] ?? 'none' ) ) );
		ok( empty( $f['noindex'] ), 'sitemap address is indexable (no noindex)', $loc, 'robots: ' . $f['robots'] );
	}
}

/* ------------------------------------------------------------------ */
/* 6. Changes to the copy (--mutate), each put back                     */
/* ------------------------------------------------------------------ */

/**
 * Clear ready pages and the address map after a change.
 */
function fresh() {
	BP_Slugs::forget_map();
	BP_Cache::clear();
	wp_cache_flush();
}

/**
 * A published test page whose text is translated into a language by hand (as the visual
 * editor would store it), so the check does not depend on a provider.
 *
 * @param array  $post     wp_insert_post data.
 * @param array  $meta     Post meta.
 * @param string $language Language.
 * @return array [ post id, original URL, translated URL, French marker text ].
 */
function test_page( array $post, array $meta, $language ) {
	global $wpdb, $in_lang;
	$id = wp_insert_post( $post + array( 'post_status' => 'publish', 'post_type' => 'page' ) );
	foreach ( $meta as $k => $v ) {
		update_post_meta( $id, $k, wp_slash( $v ) );
	}
	fresh();
	$en = get_permalink( $id );
	$tr = $in_lang( $en, $language );
	$table = $wpdb->prefix . 'trp_dictionary_' . strtolower( $GLOBALS['default'] ) . '_' . strtolower( $language );
	// Visit, translate what is still missing (5 texts are listed per visit), until complete.
	for ( $round = 1; $round <= 12; $round++ ) {
		unset( $GLOBALS['cache'][ UA_DESKTOP ][ $tr . '?bp-audit=' . $round ] );
		fetch_many( array( $tr . '?bp-audit=' . $round ) ); // The engine stores the page's texts (untranslated: the copy blocks providers).
		$missing = BP_Complete::missing_texts( $language, BP_Complete::key( $en ) );
		if ( ! $missing ) {
			break;
		}
		foreach ( $missing as $text ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET translated = %s, status = 2 WHERE ( translated = '' OR translated IS NULL ) AND ( original = %s OR original = %s OR original = %s )", '[' . $language . '] ' . $text, $text, esc_html( $text ), wptexturize( esc_html( $text ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from settings.
		}
		wp_cache_flush();
	}
	// Texts with markup are stored with it: translate any still empty row of this page's texts.
	$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET translated = CONCAT(%s, original), status = 2 WHERE ( translated = '' OR translated IS NULL ) AND original LIKE %s", '[' . $language . '] ', '%Beaver audit%' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	fresh();
	unset( $GLOBALS['cache'][ UA_DESKTOP ][ $tr ] );
	return array( $id, $en, $tr );
}

if ( isset( $opts['mutate'] ) ) {
	$sample = array_slice( $originals, 0, 6 );

	// 6a Permalink structure change, then back.
	$structure = (string) get_option( 'permalink_structure' );
	$post_id   = (int) ( get_posts( array( 'post_type' => 'post', 'numberposts' => 1, 'fields' => 'ids' ) )[0] ?? 0 );
	try {
		$before_en = get_permalink( $post_id );
		$before_fr = $in_lang( $before_en, 'fr_FR' );
		$GLOBALS['wp_rewrite']->set_permalink_structure( '/blog/%postname%/' ); // Option and in-memory rules together.
		flush_rewrite_rules( false );
		fresh();
		$after_en = get_permalink( $post_id );
		$GLOBALS['cache'] = array();
		// The French address as the site gives it now (its language link), not as computed here.
		$alt      = head_of( fetch_many( array( $after_en ) )[ $after_en ][2] )['hreflang'];
		$after_fr = $alt['fr-FR'][0] ?? $alt['fr'][0] ?? $in_lang( $after_en, 'fr_FR' );
		ok( false !== strpos( $after_fr, '/fr/blog/' ), 'permalink change: French address follows the new structure', $after_fr );
		$r = fetch_many( array( $after_en, $after_fr, $before_fr ) );
		ok( 200 === $r[ $after_en ][0], 'permalink change: new address answers 200', $after_en, (string) $r[ $after_en ][0] );
		ok( 200 === $r[ $after_fr ][0], 'permalink change: new French address answers 200', $after_fr, (string) $r[ $after_fr ][0] );
		ok( 301 === $r[ $before_fr ][0] && $r[ $before_fr ][1] === $after_fr, 'permalink change: old French address 301 to the new one', $before_fr, $r[ $before_fr ][0] . ' -> ' . ( $r[ $before_fr ][1] ?: 'none' ) );
		$f = head_of( $r[ $after_fr ][2] );
		ok( is_fallback( $f ) || ( $f['canonical'][0] ?? '' ) === $after_fr, 'permalink change: self canonical', $after_fr, 'canonical ' . ( $f['canonical'][0] ?? 'none' ) );
	} finally {
		$GLOBALS['wp_rewrite']->set_permalink_structure( $structure );
		flush_rewrite_rules( false );
		fresh();
		$GLOBALS['cache'] = array();
	}

	// 6b Default WordPress theme, then back (WordPress's own records of the switch put back too).
	$theme      = get_stylesheet();
	$theme_opts = array();
	foreach ( array( 'theme_mods_' . $theme, 'theme_mods_twentytwentyfive', 'theme_switched', 'theme_switch_menu_locations', 'att_itinerary_plan_version' ) as $o ) {
		$theme_opts[ $o ] = get_option( $o, null );
	}
	try {
		switch_theme( 'twentytwentyfive' );
		fresh();
		$GLOBALS['cache'] = array();
		check_pages( $sample, 'default theme' );

		// 6c Elementor and Gutenberg pages, shown by the default theme (Raya's theme has no general
		// page template), translated by hand, then removed.
		$made = array();
		try {
			if ( defined( 'ELEMENTOR_VERSION' ) ) {
				$data = wp_json_encode( array( array( 'id' => 'bpa1', 'elType' => 'section', 'settings' => new stdClass(), 'elements' => array( array( 'id' => 'bpa2', 'elType' => 'column', 'settings' => array( '_column_size' => 100 ), 'elements' => array( array( 'id' => 'bpa3', 'elType' => 'widget', 'widgetType' => 'heading', 'settings' => array( 'title' => 'Beaver audit Elementor heading' ), 'elements' => array() ) ) ) ) ) ) );
				list( $id, $en, $tr ) = test_page( array( 'post_title' => 'Beaver audit Elementor page', 'post_content' => '' ), array( '_elementor_edit_mode' => 'builder', '_elementor_template_type' => 'wp-page', '_elementor_data' => $data, '_elementor_version' => ELEMENTOR_VERSION ), 'fr_FR' );
				$made[] = $id;
				$r = fetch_many( array( $en, $tr ) );
				ok( false !== strpos( $r[ $en ][2], 'Beaver audit Elementor heading' ), 'Elementor: widget rendered in the original', $en );
				ok( false !== strpos( $r[ $tr ][2], '[fr_FR] Beaver audit Elementor heading' ), 'Elementor: widget text translated on the server', $tr, is_fallback( head_of( $r[ $tr ][2] ) ) ? 'page still a fallback: ' . implode( ' | ', BP_Complete::missing_texts( 'fr_FR', BP_Complete::key( $en ) ) ) : 'translation missing in HTML' );
				$f = head_of( $r[ $tr ][2] );
				ok( ( $f['canonical'][0] ?? '' ) === $tr && isset( $f['hreflang']['x-default'] ), 'Elementor: canonical and hreflang', $tr );
			} else {
				rec( 'WARN', 'Elementor not active on the copy: not tested' );
			}
			list( $id, $en, $tr ) = test_page( array( 'post_title' => 'Beaver audit Gutenberg page', 'post_content' => "<!-- wp:paragraph -->\n<p>Beaver audit Gutenberg paragraph</p>\n<!-- /wp:paragraph -->\n<!-- wp:image {\"sizeSlug\":\"large\"} -->\n<figure class=\"wp-block-image size-large\"><img src=\"" . esc_url( includes_url( 'images/w-logo-blue.png' ) ) . "\" alt=\"Beaver audit image alt\"/></figure>\n<!-- /wp:image -->" ), array(), 'fr_FR' );
			$made[] = $id;
			$r      = fetch_many( array( $tr ) );
			ok( false !== strpos( $r[ $tr ][2], '[fr_FR] Beaver audit Gutenberg paragraph' ), 'Gutenberg: block text translated on the server', $tr, implode( ' | ', BP_Complete::missing_texts( 'fr_FR', BP_Complete::key( $en ) ) ) );
			ok( false !== strpos( $r[ $tr ][2], 'alt="[fr_FR] Beaver audit image alt"' ), 'Gutenberg: image alt translated', $tr );
		} finally {
			foreach ( $made as $id ) {
				wp_delete_post( $id, true );
			}
			$wpdb_tables = array();
			foreach ( $langs as $l ) {
				if ( $l !== $default ) {
					$GLOBALS['wpdb']->query( "DELETE FROM {$GLOBALS['wpdb']->prefix}trp_dictionary_" . strtolower( $default ) . '_' . strtolower( $l ) . " WHERE original LIKE '%Beaver audit%'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed text.
				}
			}
			fresh();
		}
	} finally {
		switch_theme( $theme );
		foreach ( $theme_opts as $o => $v ) {
			null === $v ? delete_option( $o ) : update_option( $o, $v );
		}
		fresh();
		$GLOBALS['cache'] = array();
	}
}

/* ------------------------------------------------------------------ */
/* Report                                                               */
/* ------------------------------------------------------------------ */

$c = $GLOBALS['count'];
echo "\n", $c['PASS'], ' passed, ', $c['FAIL'], ' failed, ', $c['WARN'], " warnings\n";
$md  = '# Beaver Press SEO / URL audit' . "\n\n" . 'Site: ' . $home . ' - ' . gmdate( 'Y-m-d H:i' ) . ' UTC - ' . count( $langs ) . ' languages, ' . count( $originals ) . ' original addresses' . ( $all ? ' (all)' : ' (representative)' ) . ( isset( $opts['mutate'] ) ? ', with changes (permalinks, default theme, Elementor, Gutenberg)' : '' ) . "\n\n";
$md .= '**' . $c['PASS'] . ' passed, ' . $c['FAIL'] . ' failed, ' . $c['WARN'] . " warnings**\n\n| Check | Pass | Fail | Warn |\n|---|---|---|---|\n";
foreach ( $GLOBALS['checks'] as $name => $n ) {
	$md .= '| ' . $name . ' | ' . ( $n['PASS'] ?? 0 ) . ' | ' . ( $n['FAIL'] ?? 0 ) . ' | ' . ( $n['WARN'] ?? 0 ) . " |\n";
}
if ( $GLOBALS['rows'] ) {
	$md .= "\n## Failed and warned\n\n| | Check | URL | Detail |\n|---|---|---|---|\n";
	foreach ( $GLOBALS['rows'] as $r ) {
		$md .= '| ' . implode( ' | ', array_map( static fn( $x ) => str_replace( '|', '\|', (string) $x ), $r ) ) . " |\n";
	}
}
if ( ! empty( $opts['report'] ) ) {
	file_put_contents( $opts['report'], $md ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- CLI report.
	echo 'Report: ', $opts['report'], "\n";
}
exit( $c['FAIL'] ? 1 : 0 );
