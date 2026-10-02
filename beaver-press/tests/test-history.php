<?php
// Beaver Press test: run from the command line only (see tests/README.md).
// Settings history: copies after a change, never keys, restore puts a copy back.
if ( PHP_SAPI !== 'cli' ) { exit; }
require __DIR__ . '/bootstrap.php'; // WordPress + settings put back however the test ends.
$pass = 0; $fail = 0;
function ok( $n, $c, $x = '' ) { global $pass, $fail; $c ? $pass++ : $fail++; echo ( $c ? 'PASS ' : 'FAIL ' ), $n, $x ? "  [$x]" : '', "\n"; }
global $wpdb;
$raw = fn() => (string) $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = '" . BP_History::OPTION . "'" );

delete_option( BP_History::OPTION );
update_option( BP_Topup::OPTION, 'yes', false );
$mt = get_option( 'trp_machine_translation_settings', array() );
$mt['google-translate-key'] = 'SECRET-ENGINE-KEY-123'; $mt['machine_translation_counter_date'] = '2000-01-01';
update_option( 'trp_machine_translation_settings', $mt );
BP_Names_AI::store( array( 'off' => array( md5( 'Kibo Hut' ) => 1 ), 'ai' => array( 'x' => array( 'k' => 1, 'r' => 'r' ) ), 'seen' => array( 'y' => 1 ) ) );

ok( 'first copy kept', BP_History::record( 'Tester' ) && 1 === count( BP_History::entries() ) );
ok( 'nothing changed: no new copy', ! BP_History::record( 'Tester' ) && 1 === count( BP_History::entries() ) );
$h = $raw();
ok( 'no key in a copy', false === strpos( $h, 'SECRET-ENGINE-KEY-123' ) && false === strpos( $h, 'google-translate-key' ) && false === strpos( $h, BP_Keys::OPTION ) );
ok( 'working data left out', false === strpos( $h, '2000-01-01' ) && ! isset( BP_History::entries()[0]['data']['names']['ai'] ) );

// Change things, then restore the first copy.
update_option( BP_Topup::OPTION, 'no', false );
$mt = get_option( 'trp_machine_translation_settings' ); $mt['bp-chunk-size'] = 7; $mt['google-translate-key'] = 'NEW-KEY-456'; $mt['machine_translation_counter_date'] = '2000-01-02';
update_option( 'trp_machine_translation_settings', $mt );
BP_Names_AI::store( array( 'off' => array(), 'ai' => array( 'z' => array( 'k' => 0, 'r' => 'r' ) ) ) );
ok( 'change: second copy', BP_History::record( 'Tester' ) && 2 === count( BP_History::entries() ) );
$diff = BP_History::differences( BP_History::entries()[1]['data'] );
ok( 'differences listed', in_array( 'bp-chunk-size', $diff, true ) && in_array( 'topup', $diff, true ) && in_array( 'names to keep', $diff, true ), implode( ', ', $diff ) );

$r = BP_History::restore( 1 );
$mt = get_option( 'trp_machine_translation_settings' );
ok( 'restored: settings back', ! is_wp_error( $r ) && 'yes' === get_option( BP_Topup::OPTION ) && 7 !== (int) $mt['bp-chunk-size'], (string) $mt['bp-chunk-size'] );
ok( 'restored: key field and counter date stay current', 'NEW-KEY-456' === $mt['google-translate-key'] && '2000-01-02' === $mt['machine_translation_counter_date'] );
$names = get_option( BP_Glossary::NAMES_OPTION );
ok( 'restored: name ticks back, AI tags kept', ! empty( $names['off'][ md5( 'Kibo Hut' ) ] ) && isset( $names['ai']['z'] ) );
ok( 'restore of a missing copy refused', is_wp_error( BP_History::restore( 99 ) ) );

// The translated addresses switch is not flipped by a restore.
$slugs = get_option( BP_Slugs::OPTION, 'no' );
BP_History::record( 'Tester' );
update_option( BP_Slugs::OPTION, 'yes' === $slugs ? 'no' : 'yes', false );
BP_History::record( 'Tester' );
$r = BP_History::restore( 1 );
ok( 'addresses switch left as it is, and said so', ( 'yes' === $slugs ? 'no' : 'yes' ) === get_option( BP_Slugs::OPTION ) && 1 === count( $r['left'] ) );
update_option( BP_Slugs::OPTION, $slugs, false );

for ( $i = 0; $i < 12; $i++ ) { update_option( BP_Budget::OPTION, array( 'day' => $i + 1, 'month' => 0 ), false ); BP_History::record( 'Tester' ); }
ok( 'ten copies kept', 10 === count( BP_History::entries() ) );
ok( 'not recorded outside a wp-admin save', ( function () { $n = count( BP_History::entries() ); update_option( BP_Budget::OPTION, array( 'day' => 99 ), false ); BP_History::maybe_record(); return count( BP_History::entries() ) === $n; } )() );

ob_start(); BP_History::render(); $html = ob_get_clean();
ok( 'card lists copies with Restore', false !== strpos( $html, 'id="bp-history"' ) && false !== strpos( $html, 'bp_history_restore' ) && false === strpos( $html, 'NEW-KEY-456' ) );

echo "\n$pass passed, $fail failed\n";
