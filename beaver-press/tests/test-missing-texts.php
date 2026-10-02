<?php
// Beaver Press test: run from the command line only (see tests/README.md).
// Which texts a page still misses: recorded per page and language, shown on the Pages tab,
// and flagged when the same text keeps coming back on different days.
if ( PHP_SAPI !== 'cli' ) { exit; }
require __DIR__ . '/bootstrap.php'; // WordPress + settings put back however the test ends.
$pass = 0; $fail = 0;
function ok( $n, $c, $x = '' ) { global $pass, $fail; $c ? $pass++ : $fail++; echo ( $c ? 'PASS ' : 'FAIL ' ), $n, $x ? "  [$x]" : '', "\n"; }
$seen_before = get_option( BP_Complete::SEEN_OPTION, null );
$lang = 'fr_FR'; $key = md5( '/bp-test-missing-texts' );
$rows = array( BP_Complete::PREFIX . $lang . '_' . $key, BP_Complete::TEXTS_PREFIX . $lang . '_' . $key );
$set  = function ( $name, $value ) { $r = new ReflectionProperty( 'BP_Complete', $name ); $r->setAccessible( true ); $r->setValue( null, $value ); };
$remember = function ( $missing ) use ( $lang, $key ) { $m = new ReflectionMethod( 'BP_Complete', 'remember' ); $m->setAccessible( true ); $m->invoke( null, $lang, $key, $missing ); };
$page = function ( array $strings, array $translated ) use ( $set ) { $set( 'missing', 0 ); $set( 'missing_texts', array() ); BP_Complete::note( array( 'translateable_strings' => $strings ), $translated ); };

// A page with two untranslated texts (and a number and an email, which need none).
$page( array( 'Game drives at dawn', '<b>Last quarter</b>', '1,250', 'hello@example.com', 'Tarangire' ), array( 4 => 'Tarangire' ) );
$remember( 2 );
ok( 'missing texts recorded (tags stripped, no number or email)', array( 'Game drives at dawn', 'Last quarter' ) === BP_Complete::missing_texts( $lang, $key ), wp_json_encode( BP_Complete::missing_texts( $lang, $key ) ) );
$map = BP_Complete::texts_map();
ok( 'texts map for the Pages tab', isset( $map[ $lang ][ $key ] ) && 'Game drives at dawn' === $map[ $lang ][ $key ][0][0] && false === $map[ $lang ][ $key ][0][1] );

// At most five kept per page.
$page( array_map( fn( $i ) => "Text number $i here", range( 1, 9 ) ), array() );
$remember( 9 );
ok( 'at most five texts kept', 5 === count( BP_Complete::missing_texts( $lang, $key ) ) );

// Coming back on three different days: flagged.
$seen = get_option( BP_Complete::SEEN_OPTION, array() );
$seen[ md5( 'Last quarter' ) ] = array( 'Last quarter', array( '2026-09-30', '2026-10-01', wp_date( 'Y-m-d' ) ) );
update_option( BP_Complete::SEEN_OPTION, $seen, false );
ok( 'recurring text flagged', BP_Complete::recurring( 'Last quarter' ) && ! BP_Complete::recurring( 'Text number 1 here' ) );

// Pages tab cell lists them; a recurring one gets the hint.
$cell = BP_Admin::page_cell( 2, 'http://example.com/fr/x/', array( array( 'Game drives at dawn', false ), array( 'Last quarter', true ) ) );
ok( 'cell lists the texts', false !== strpos( $cell, '<details class="bp-miss">' ) && false !== strpos( $cell, 'Game drives at dawn' ) && 1 === substr_count( $cell, 'bp-miss__again' ) );
ok( 'cell without texts stays a link', 0 === strpos( BP_Admin::page_cell( 3, 'http://example.com/' ), '<a class="bp-cell bp-cell--missing"' ) );
ok( 'cell text escaped', false === strpos( BP_Admin::page_cell( 1, 'http://example.com/', array( array( '<script>x</script>', false ) ) ), '<script>' ) );

// Complete: the texts row goes.
$page( array( 'Game drives at dawn' ), array( 'Safaris à l\'aube' ) );
$remember( 0 );
ok( 'complete page: texts removed', array() === BP_Complete::missing_texts( $lang, $key ) && false === get_option( $rows[1] ) );

foreach ( $rows as $r ) { delete_option( $r ); }
null === $seen_before ? delete_option( BP_Complete::SEEN_OPTION ) : update_option( BP_Complete::SEEN_OPTION, $seen_before, false );
echo "\n$pass passed, $fail failed\n";
