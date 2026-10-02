<?php
/**
 * Defect A (2.6.2): language links refresh at once when a page gets or changes its translated
 * address, ready pages on. Disposable copies only.
 *
 *   BP_WP_LOAD=/copy/wp-load.php php bp-hreflang-refresh.php [--path=/about/] [--language=fr_FR]
 *
 * @package BeaverPress
 */

if ( PHP_SAPI !== 'cli' ) {
	exit;
}
$opts = getopt( '', array( 'path:', 'language:' ) );
$_SERVER['HTTP_HOST']   = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
require getenv( 'BP_WP_LOAD' ) ?: dirname( __DIR__, 5 ) . '/wp-load.php';
if ( 'yes' !== get_option( 'beaver_press_disposable_site' ) ) {
	fwrite( STDERR, "Refused: not a disposable test copy.\n" );
	exit( 2 );
}
$pass = 0;
$fail = 0;

/**
 * Record a check.
 *
 * @param string $n Name.
 * @param bool   $c Passed.
 * @param string $x Detail.
 */
function ok( $n, $c, $x = '' ) {
	global $pass, $fail;
	$c ? $pass++ : $fail++;
	echo ( $c ? 'PASS ' : 'FAIL ' ), $n, $x ? "  [$x]" : '', "\n";
}

/**
 * GET without following redirects; as the signed run when asked.
 *
 * @param string $u   URL.
 * @param bool   $run Signed run request.
 * @return array [ code, location, body, cache header ].
 */
function get( $u, $run = false ) {
	$h = curl_init( $u );
	curl_setopt_array( $h, array( CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 120, CURLOPT_HTTPHEADER => $run ? array( BP_Guard::HEADER . ': ' . BP_Guard::run_token() ) : array() ) );
	$r    = (string) curl_exec( $h );
	$size = (int) curl_getinfo( $h, CURLINFO_HEADER_SIZE );
	$out  = array( (int) curl_getinfo( $h, CURLINFO_RESPONSE_CODE ), (string) curl_getinfo( $h, CURLINFO_REDIRECT_URL ), substr( $r, $size ), (int) preg_match( '/x-beaver-press-cache:\s*hit/i', substr( $r, 0, $size ) ) );
	curl_close( $h );
	return $out;
}

/**
 * The hreflang address for a language code in a page.
 *
 * @param string $html HTML.
 * @param string $code hreflang code (fr-FR).
 * @return string
 */
function alt( $html, $code ) {
	return preg_match( '/<link\s+rel="alternate"\s+hreflang="' . preg_quote( $code, '/' ) . '"\s+href="([^"]*)"/i', $html, $m ) ? html_entity_decode( $m[1] ) : '';
}

$lang   = (string) ( $opts['language'] ?? 'fr_FR' );
$code   = str_replace( '_', '-', $lang );
$slug   = (string) TRP_Translate_Press::get_trp_instance()->get_component( 'settings' )->get_settings()['url-slugs'][ $lang ];
$post   = get_page_by_path( trim( (string) ( $opts['path'] ?? '/about/' ), '/' ) );
$en     = get_permalink( $post );
$keep   = get_post_meta( $post->ID, BP_Slugs::META, true );
$target = (string) ( $keep[ $lang ] ?? '' );
$plain  = untrailingslashit( home_url() ) . '/' . $slug . '/' . $post->post_name . '/';
$new    = untrailingslashit( home_url() ) . '/' . $slug . '/' . $target . '/';
ok( 'page has a translated address to work with', '' !== $target && BP_Cache::enabled(), $target );
echo "Page {$en}, {$lang} address {$new}\n";
try {
	foreach ( array( 'with ready pages warm', 'after clearing the cache' ) as $round ) {
		// Start: no translated address yet (the English slug in this language), ready pages warm.
		$slugs = (array) $keep;
		unset( $slugs[ $lang ] );
		update_post_meta( $post->ID, BP_Slugs::META, $slugs );
		BP_Slugs::forget_map();
		BP_Cache::clear();
		get( $en );
		$warm = get( $en );
		ok( "{$round}: English page served ready, linking the untranslated address", 1 === $warm[3] && alt( $warm[2], $code ) === $plain, alt( $warm[2], $code ) );
		if ( 'after clearing the cache' === $round ) {
			BP_Cache::clear();
		}
		// The run visits the page in the language: its address is drafted from the stored title.
		get( $plain . '?bp-refresh=' . wp_rand(), true );
		wp_cache_flush();
		$now = (array) get_post_meta( $post->ID, BP_Slugs::META, true );
		ok( "{$round}: address drafted by the run", ( $now[ $lang ] ?? '' ) === $target, (string) ( $now[ $lang ] ?? 'none' ) );
		// Immediately afterwards, as a visitor.
		$after = get( $en );
		ok( "{$round}: hreflang points to the new address at once", alt( $after[2], $code ) === $new, alt( $after[2], $code ) );
		ok( "{$round}: old address no longer advertised", false === strpos( $after[2], 'href="' . $plain . '"' ) );
		$old = get( $plain );
		ok( "{$round}: old address answers 301 to the new one", 301 === $old[0] && $old[1] === $new, $old[0] . ' -> ' . $old[1] );
		$page = get( $new );
		ok( "{$round}: new address 200, canonical itself", 200 === $page[0] && false !== strpos( $page[2], '<link rel="canonical" href="' . $new . '"' ) );
	}
} finally {
	update_post_meta( $post->ID, BP_Slugs::META, $keep );
	BP_Slugs::forget_map();
	BP_Cache::clear();
}
echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail ? 1 : 0 );
