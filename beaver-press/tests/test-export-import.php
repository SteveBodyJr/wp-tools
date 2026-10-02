<?php
// Beaver Press test: run from the command line only (see tests/README.md).
if ( PHP_SAPI !== 'cli' ) { exit; }
require __DIR__ . '/bootstrap.php'; // WordPress + settings put back however the test ends.
$pass = 0; $fail = 0;
function ok( $n, $c, $x = '' ) { global $pass, $fail; $c ? $pass++ : $fail++; echo ( $c ? 'PASS ' : 'FAIL ' ), $n, $x ? "  [$x]" : '', "\n"; }
$opts = array( 'trp_machine_translation_settings', 'trp_settings', BP_Instructions::OPTION, BP_Auto::OPTION, BP_Cache::OPTION, BP_Complete::OPTION, BP_Suggest::OPTION, BP_Suggest::REDIRECT_OPTION, BP_Slugs::OPTION, BP_Quiet::OPTION, BP_Keys::OPTION );
$snap = array(); foreach ( $opts as $o ) { $snap[ $o ] = get_option( $o, null ); }
// Saved instructions with the site name, to see {site}.
update_option( BP_Instructions::OPTION, array( 'site' => 'Raya Safaris is a test voice.', 'langs' => array( 'fr_FR' => 'Use vous.' ) ), false );
$json = BP_Transfer::export_json();
$data = json_decode( $json, true );
$key  = BP_Keys::get( 'deepseek' );
ok( 'format and version', 'beaver-press-settings' === $data['format'] && 1 === $data['version'] );
ok( 'no API key anywhere', '' !== $key && false === strpos( $json, $key ) && false === strpos( $json, substr( $key, 3, 12 ) ) && ! isset( $data['keys'] ) );
ok( 'site name as {site}', '{site} is a test voice.' === $data['instructions']['site'] );
ok( 'engine and switches exported', 'deepseek' === $data['engine']['bp-provider'] && isset( $data['switches']['translated_addresses'] ) );
$before = BP_Transfer::data();
// Change things, then import the file.
update_option( BP_Slugs::OPTION, 'no', false ); update_option( BP_Suggest::REDIRECT_OPTION, 'yes', false ); delete_option( BP_Instructions::OPTION );
$mt = get_option( 'trp_machine_translation_settings' ); $mt['bp-chunk-size'] = 7; $mt['bp-model'] = 'other-model'; update_option( 'trp_machine_translation_settings', $mt );
$r = BP_Transfer::import_json( $json );
ok( 'import succeeded', is_array( $r ), is_wp_error( $r ) ? $r->get_error_message() : count( $r ) . ' lines' );
$after = BP_Transfer::data();
unset( $before['exported_at'], $after['exported_at'] );
ok( 'round trip identical', $before === $after, wp_json_encode( array_diff_assoc( array_map( 'wp_json_encode', $before ), array_map( 'wp_json_encode', $after ) ) ) );
$g = BP_Instructions::get();
ok( 'languages not in the file keep their suggestions', false !== strpos( $g['langs']['de_DE'], 'Sie' ) && 'Use vous.' === $g['langs']['fr_FR'] );
ok( 'prompt uses the site name', false !== strpos( BP_Instructions::prompt_part( 'fr_FR', 'French' ), 'Raya Safaris is a test voice.' ) );
ok( 'keys untouched', $snap[ BP_Keys::OPTION ] === get_option( BP_Keys::OPTION, null ) );
ok( 'not a settings file', is_wp_error( BP_Transfer::import_json( '{"format":"x"}' ) ) && is_wp_error( BP_Transfer::import_json( 'nonsense' ) ) );
ok( 'newer version refused', is_wp_error( BP_Transfer::import_json( wp_json_encode( array( 'format' => 'beaver-press-settings', 'version' => 99 ) ) ) ) );
ok( 'bad provider cleaned', ! is_wp_error( BP_Transfer::import_json( wp_json_encode( array( 'format' => 'beaver-press-settings', 'version' => 1, 'engine' => array( 'bp-provider' => '<script>' ) ) ) ) ) && in_array( get_option( 'trp_machine_translation_settings' )['bp-provider'], BP_Engine_Settings::PROVIDERS, true ) );
$big = BP_Transfer::import_json( str_repeat( ' ', 70000 ) );
ok( 'oversized refused', is_wp_error( $big ) );
// Restore exactly.
foreach ( $snap as $o => $v ) { null === $v ? delete_option( $o ) : update_option( $o, $v ); }
BP_Slugs::forget_map();
echo "\n$pass passed, $fail failed\n";
