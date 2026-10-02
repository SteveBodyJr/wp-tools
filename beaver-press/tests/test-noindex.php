<?php
// Beaver Press test: run from the command line only (see tests/README.md).
// T2: pages printed with noindex (by the theme, an SEO plugin or WordPress) leave the language
// sitemaps; indexable pages stay.
if ( PHP_SAPI !== 'cli' ) { exit; }
require __DIR__ . '/bootstrap.php'; // WordPress + settings put back however the test ends.
$pass = 0; $fail = 0;
function ok( $n, $c, $x = '' ) { global $pass, $fail; $c ? $pass++ : $fail++; echo ( $c ? 'PASS ' : 'FAIL ' ), $n, $x ? "  [$x]" : '', "\n"; }
$head = fn( $tags ) => '<html><head>' . $tags . '</head><body>x</body></html>';
ok( 'robots noindex', BP_Complete::says_noindex( $head( '<meta name="robots" content="noindex, follow" />' ) ) );
ok( 'robots noindex,follow (no space, Yoast/theme style)', BP_Complete::says_noindex( $head( "<meta name='robots' content='noindex,follow' />" ) ) );
ok( 'WordPress core wp_robots style', BP_Complete::says_noindex( $head( "<meta name='robots' content='noindex, max-image-preview:large' />" ) ) );
ok( 'googlebot noindex', BP_Complete::says_noindex( $head( '<meta name="googlebot" content="noindex" />' ) ) );
ok( 'robots none', BP_Complete::says_noindex( $head( '<meta name="robots" content="none" />' ) ) );
ok( 'index, follow is indexable', ! BP_Complete::says_noindex( $head( '<meta name="robots" content="index, follow, max-image-preview:large" />' ) ) );
ok( 'no robots tag is indexable', ! BP_Complete::says_noindex( $head( '<title>x</title>' ) ) );
ok( 'noindex in the body text does not count', ! BP_Complete::says_noindex( '<html><head></head><body><meta name="robots" content="noindex"></body></html>' ) );
ok( 'other meta with noindex word does not count', ! BP_Complete::says_noindex( $head( '<meta name="description" content="How we noindex nothing" />' ) ) );

// Sitemap: a complete page known to be noindex is left out; another complete page stays.
$urls = BP_Run::urls();
$a    = $urls[1]; $b = $urls[2];
$ka   = BP_Complete::key( $a ); $kb = BP_Complete::key( $b );
$rows = array( BP_Complete::PREFIX . 'fr_FR_' . $ka, BP_Complete::PREFIX . 'fr_FR_' . $kb, BP_Complete::NOINDEX_PREFIX . 'fr_FR_' . $ka );
$keep = array(); foreach ( $rows as $r ) { $keep[ $r ] = get_option( $r, null ); }
register_shutdown_function( function () use ( $keep ) { foreach ( $keep as $r => $v ) { null === $v ? delete_option( $r ) : update_option( $r, $v, false ); } } );
update_option( BP_Complete::OPTION, 'yes', false );
update_option( $rows[0], 0, false ); update_option( $rows[1], 0, false ); update_option( $rows[2], 1, false );
$list = ( new ReflectionMethod( 'BP_Sitemap', 'urls' ) )->invoke( null, 'fr_FR' );
ok( 'noindex page not in the language sitemap', ! in_array( BP_Run::url_in( $a, 'fr_FR' ), $list, true ) );
ok( 'indexable complete page stays', in_array( BP_Run::url_in( $b, 'fr_FR' ), $list, true ) );
ok( 'noindex map lists it', isset( BP_Complete::noindex_map()['fr_FR'][ $ka ] ) );
echo "\n$pass passed, $fail failed\n";
