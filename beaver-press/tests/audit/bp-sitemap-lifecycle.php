<?php
/**
 * Defect C (2.6.2): a language sitemap appears in the active sitemap index only while the
 * language has complete, indexable pages, and is always valid XML. Disposable sites only.
 *
 *   BP_WP_LOAD=/site/wp-load.php php bp-sitemap-lifecycle.php --language=de_DE --path=/
 *
 * @package BeaverPress
 */

if ( PHP_SAPI !== 'cli' ) {
	exit;
}
$opts = getopt( '', array( 'language:', 'path:' ) );
$_SERVER['HTTP_HOST']   = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
require getenv( 'BP_WP_LOAD' ) ?: dirname( __DIR__, 5 ) . '/wp-load.php';
if ( 'yes' !== get_option( 'beaver_press_disposable_site' ) ) {
	fwrite( STDERR, "Refused: not a disposable test site.\n" );
	exit( 2 );
}
global $wpdb;
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
 * GET as a visitor (no redirects).
 *
 * @param string $u URL.
 * @return array [ code, body ].
 */
function get( $u ) {
	$h = curl_init( $u );
	curl_setopt_array( $h, array( CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60 ) );
	$b = (string) curl_exec( $h );
	$c = (int) curl_getinfo( $h, CURLINFO_RESPONSE_CODE );
	curl_close( $h );
	return array( $c, $b );
}

/**
 * Sitemap files named in the active index (whichever system owns it).
 *
 * @return string[]
 */
function index_locs() {
	foreach ( array( '/wp-sitemap.xml', '/sitemap_index.xml', '/sitemaps.xml' ) as $p ) {
		list( $c, $b ) = get( home_url( $p ) );
		if ( 200 === $c ) {
			preg_match_all( '#<loc>([^<]*)</loc>#', $b, $m );
			return array_map( 'html_entity_decode', $m[1] );
		}
	}
	return array();
}

$lang  = (string) ( $opts['language'] ?? 'de_DE' );
$slug  = (string) TRP_Translate_Press::get_trp_instance()->get_component( 'settings' )->get_settings()['url-slugs'][ $lang ];
$page  = home_url( (string) ( $opts['path'] ?? '/' ) );
$url   = BP_Run::url_in( $page, $lang );
$table = TRP_Translate_Press::get_trp_instance()->get_component( 'query' )->get_table_name( $lang );
$mine  = static fn( $locs ) => array_values( array_filter( $locs, static fn( $l ) => (bool) preg_match( '#(bp-sitemap|wp-sitemap-languages)-' . preg_quote( $slug, '#' ) . '-\d+\.xml#', $l ) ) );
$valid = static function ( $locs ) {
	foreach ( $locs as $l ) {
		list( $c, $b ) = get( $l );
		libxml_use_internal_errors( true );
		if ( 200 !== $c || false === simplexml_load_string( $b ) ) {
			return $l . ' (' . $c . ')';
		}
	}
	return '';
};
$filled = array();
try {
	BP_Cache::clear();
	ok( 'start: the language has no complete page', ! BP_Sitemap::urls( $lang ), count( BP_Sitemap::urls( $lang ) ) . ' pages' );
	$locs = index_locs();
	ok( 'no sitemap of the language in the index', ! $mine( $locs ), implode( ' ', $mine( $locs ) ) );
	ok( 'every sitemap in the index is valid XML', '' === $valid( $locs ), $valid( $locs ) );

	// Make one page complete (temporary translations, removed at the end).
	for ( $round = 1; $round <= 12; $round++ ) {
		get( $url . '?bp-lc=' . $round . wp_rand() );
		wp_cache_flush();
		$missing = BP_Complete::missing_texts( $lang, BP_Complete::key( $page ) );
		if ( ! $missing && 0 === ( BP_Complete::map( true )[ $lang ][ BP_Complete::key( $page ) ] ?? -1 ) ) {
			break;
		}
		foreach ( $missing as $t ) {
			$like = array();
			foreach ( array_unique( array( $t, esc_html( $t ), wptexturize( esc_html( $t ) ) ) ) as $v ) {
				$like[] = $wpdb->prepare( 'original LIKE %s', $wpdb->esc_like( $v ) . '%' );
				$like[] = $wpdb->prepare( 'original LIKE %s', '%' . $wpdb->esc_like( $v ) . '%' );
			}
			foreach ( $wpdb->get_col( "SELECT id FROM `{$table}` WHERE ( translated = '' OR translated IS NULL ) AND ( " . implode( ' OR ', $like ) . ' )' ) as $id ) { // phpcs:ignore WordPress.DB
				$wpdb->update( $table, array( 'translated' => '[' . $lang . '] text', 'status' => 2 ), array( 'id' => (int) $id ) );
				$filled[] = (int) $id;
			}
		}
	}
	wp_cache_flush();
	ok( 'one page complete in the language', in_array( $url, BP_Sitemap::urls( $lang ), true ) || 0 === ( BP_Complete::map( true )[ $lang ][ BP_Complete::key( $page ) ] ?? -1 ) );
	BP_Cache::clear();
	$locs = index_locs();
	ok( 'now the language sitemap is in the index', 1 <= count( $mine( $locs ) ), implode( ' ', $mine( $locs ) ) );
	ok( 'it is valid XML and lists the page', '' === $valid( $mine( $locs ) ) && $mine( $locs ) && false !== strpos( get( $mine( $locs )[0] )[1], $url ) );
	ok( 'every sitemap in the index is valid XML', '' === $valid( $locs ), $valid( $locs ) );

	// The page becomes incomplete again (one translation emptied, page visited).
	if ( $filled ) {
		$wpdb->update( $table, array( 'translated' => '', 'status' => 0 ), array( 'id' => $filled[0] ) );
	}
	get( $url . '?bp-lc=back' . wp_rand() );
	wp_cache_flush();
	BP_Cache::clear();
	$locs = index_locs();
	ok( 'incomplete again: the language sitemap leaves the index', ! $mine( $locs ), implode( ' ', $mine( $locs ) ) );
	ok( 'every sitemap in the index is valid XML', '' === $valid( $locs ), $valid( $locs ) );
} finally {
	if ( $filled ) {
		$wpdb->query( "UPDATE `{$table}` SET translated = '', status = 0 WHERE id IN (" . implode( ',', array_map( 'intval', $filled ) ) . ')' ); // phpcs:ignore WordPress.DB
	}
	get( $url . '?bp-lc=end' . wp_rand() );
	BP_Cache::clear();
}
echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail ? 1 : 0 );
