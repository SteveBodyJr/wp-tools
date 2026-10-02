<?php
// Beaver Press test: run from the command line only (see tests/README.md).
// Which addresses may enter a language sitemap (defect B), and that a language without any
// complete page gets no sitemap of its own (defect C).
if ( PHP_SAPI !== 'cli' ) { exit; }
require __DIR__ . '/bootstrap.php'; // WordPress + settings put back however the test ends.
$pass = 0; $fail = 0;
function ok( $n, $c, $x = '' ) { global $pass, $fail; $c ? $pass++ : $fail++; echo ( $c ? 'PASS ' : 'FAIL ' ), $n, $x ? "  [$x]" : '', "\n"; }
$h  = untrailingslashit( home_url() );
$ps = (string) get_option( 'permalink_structure' );
register_shutdown_function( function () use ( $ps ) { update_option( 'permalink_structure', $ps ); } );

update_option( 'permalink_structure', '/%postname%/' );
ok( 'pretty permalinks: normal page listed', BP_Sitemap::listable( $h . '/about/' ) );
ok( 'pretty permalinks: post listed', BP_Sitemap::listable( $h . '/2026/10/a-post/' ) );
ok( 'query-string address (Elementor library) not listed', ! BP_Sitemap::listable( $h . '/?elementor_library=default-kit' ) );
ok( 'preview not listed', ! BP_Sitemap::listable( $h . '/about/?preview=true' ) );
ok( 'wp-admin not listed', ! BP_Sitemap::listable( $h . '/wp-admin/post.php' ) );
ok( 'login not listed', ! BP_Sitemap::listable( $h . '/wp-login.php' ) );
ok( 'REST API not listed', ! BP_Sitemap::listable( $h . '/wp-json/wp/v2/pages' ) );
ok( 'another site not listed', ! BP_Sitemap::listable( 'https://example.com/about/' ) );
update_option( 'permalink_structure', '' );
ok( 'plain permalinks: ?p= address listed', BP_Sitemap::listable( $h . '/?p=12' ) );
ok( 'plain permalinks: preview still not listed', ! BP_Sitemap::listable( $h . '/?p=12&preview=true' ) );
update_option( 'permalink_structure', $ps );

// The language sitemaps only ever list addresses that pass, are complete and indexable.
$bad = array();
foreach ( BP_Sitemap::languages() as $code ) {
	foreach ( BP_Sitemap::urls( $code ) as $u ) {
		if ( false !== strpos( $u, '?' ) && '' !== $ps ) {
			$bad[] = $u;
		}
	}
}
ok( 'no query-string address in any language sitemap', ! $bad, implode( ' ', array_slice( $bad, 0, 2 ) ) );
// A language with no complete page has no file in the standalone list (used with SEO plugins).
$files = BP_Sitemap_Standalone::files();
$empty = array_filter( BP_Sitemap::languages(), static fn( $code ) => ! BP_Sitemap::urls( $code ) );
$listed_empty = array_filter( $files, static fn( $f ) => in_array( $f[0], $empty, true ) );
ok( 'no sitemap file for a language without complete pages', ! $listed_empty, implode( ',', $empty ) );
echo "\n$pass passed, $fail failed\n";
