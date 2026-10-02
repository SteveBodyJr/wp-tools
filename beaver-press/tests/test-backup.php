<?php
// Beaver Press test: run from the command line only (see tests/README.md).
// Translation backup, export, import (validation, duplicates, dry run, rules), restore.
if ( PHP_SAPI !== 'cli' ) { exit; }
require __DIR__ . '/bootstrap.php'; // WordPress + settings put back however the test ends.
$pass = 0; $fail = 0;
function ok( $n, $c, $x = '' ) { global $pass, $fail; $c ? $pass++ : $fail++; echo ( $c ? 'PASS ' : 'FAIL ' ), $n, $x ? "  [$x]" : '', "\n"; }
global $wpdb;
$lang  = 'fr_FR';
$q     = TRP_Translate_Press::get_trp_instance()->get_component( 'query' );
$table = $q->get_table_name( $lang );
$before_ids = array_keys( BP_Backup::backups() );
$texts = array( 'Beaver backup test manual', 'Beaver backup test machine', 'Beaver backup test empty' );
$row   = function ( $o ) use ( $wpdb, $table ) { return $wpdb->get_row( $wpdb->prepare( "SELECT translated, status FROM `{$table}` WHERE original = %s", $o ) ); }; // phpcs:ignore
$set   = function ( $o, $t, $s ) use ( $wpdb, $table ) { $wpdb->query( $wpdb->prepare( "UPDATE `{$table}` SET translated = %s, status = %d WHERE original = %s", $t, $s, $o ) ); }; // phpcs:ignore
$cleanup = function () use ( $wpdb, $table, $before_ids ) {
	$wpdb->query( "DELETE FROM `{$table}` WHERE original LIKE 'Beaver backup test%'" ); // phpcs:ignore
	$dir  = BP_Backup::dir(); // Runs after the settings (and so the backup list) were put back:
	$keep = array_column( BP_Backup::backups(), 'file' ); // files the list does not know are this test's.
	foreach ( is_wp_error( $dir ) ? array() : (array) glob( $dir . '/translations-*.json.gz' ) as $f ) {
		if ( ! in_array( basename( $f ), $keep, true ) ) { wp_delete_file( $f ); }
	}
};
register_shutdown_function( $cleanup );

$q->insert_strings( $texts, $lang );
$set( $texts[0], 'Manuel (fait main)', 2 );
$set( $texts[1], 'Machine (IA)', 1 );

// Backups.
$id = BP_Backup::create( 'Test' );
$path = BP_Backup::path( $id );
ok( 'backup written', ! is_wp_error( $id ) && '' !== $path && filesize( $path ) > 100 );
$dir = BP_Backup::dir();
ok( 'backup folder protected', is_file( $dir . '/.htaccess' ) && is_file( $dir . '/index.php' ) && false !== strpos( basename( $dir ), 'beaver-press-backups-' ) && strlen( basename( $dir ) ) > 30 );
$url  = str_replace( wp_upload_dir()['basedir'], wp_upload_dir()['baseurl'], $path );
$code = (int) wp_remote_retrieve_response_code( wp_remote_get( $url ) );
ok( 'backup file not downloadable over HTTP', 200 !== $code, (string) $code );
$data = BP_Backup::read_file( $path );
ok( 'backup readable, has the test rows', is_array( $data ) && in_array( 'Beaver backup test manual', array_column( $data['languages'][ $lang ]['regular'], 'o' ), true ) );
ok( 'untranslated rows left out', ! in_array( 'Beaver backup test empty', array_column( $data['languages'][ $lang ]['regular'], 'o' ), true ) );
$plain = (string) gzdecode( file_get_contents( $path ) ); // phpcs:ignore
$keys  = array_filter( array_map( array( 'BP_Keys', 'get' ), BP_Engine_Settings::PROVIDERS ) );
ok( 'no API key in a backup', array() === array_filter( $keys, static fn( $k ) => false !== strpos( $plain, $k ) ) && false === strpos( $plain, BP_Keys::OPTION ), count( $keys ) . ' saved keys checked' );

// CSV.
$h = fopen( 'php://memory', 'r+' ); BP_Backup::csv( $lang, $h ); rewind( $h ); $csv = stream_get_contents( $h ); fclose( $h ); // phpcs:ignore
ok( 'CSV export has header and rows', 0 === strpos( $csv, "type,original,translation,status" ) && false !== strpos( $csv, 'Manuel (fait main)' ) && false !== strpos( $csv, ',manual,' ) );

// Validation.
ok( 'not our format refused', is_wp_error( BP_Backup::validate( array( 'format' => 'x' ) ) ) );
ok( 'other original language refused', is_wp_error( BP_Backup::validate( array( 'format' => BP_Backup::FORMAT, 'version' => 1, 'default_language' => 'de_DE', 'languages' => array() ) ) ) );
$file = array( 'format' => BP_Backup::FORMAT, 'version' => 1, 'languages' => array(
	$lang   => array( 'regular' => array(
		array( 'o' => $texts[0], 't' => 'Manuel REMPLACÉ', 's' => 1 ),      // machine over manual: kept
		array( 'o' => $texts[1], 't' => 'Machine nouvelle', 's' => 1 ),     // machine over machine: kept unless asked
		array( 'o' => $texts[1], 't' => 'Machine nouvelle 2', 's' => 1 ),   // duplicate: last wins
		array( 'o' => $texts[2], 't' => 'Vide rempli', 's' => 1 ),          // empty: filled
		array( 'o' => 'Beaver backup test new', 't' => 'Nouveau', 's' => 2 ), // not on the site: added
		array( 'o' => '', 't' => 'x', 's' => 1 ),                           // invalid
		array( 'o' => 'Beaver backup test bad', 't' => 'x', 's' => 9 ),     // invalid status
	), 'gettext' => array() ),
	'xx_XX' => array( 'regular' => array( array( 'o' => 'a', 't' => 'b', 's' => 1 ) ) ),
) );
$c = BP_Backup::validate( $file );
ok( 'validation counts rows, duplicates, invalid, unknown languages', ! is_wp_error( $c ) && 4 === $c['count']['rows'] && 1 === $c['count']['duplicates'] && 2 === $c['count']['invalid'] && array( 'xx_XX' ) === $c['count']['unknown_languages'], wp_json_encode( $c['count'] ?? null ) );

// Dry run writes nothing.
$n = count( BP_Backup::backups() );
$r = BP_Backup::import( $c, array( 'dry_run' => true ) );
$rc = $r['languages'][ $lang ];
ok( 'dry run reports', 1 === $rc['added'] && 1 === $rc['filled'] && 1 === $rc['kept_manual'] && 1 === $rc['kept_machine'], wp_json_encode( $rc ) );
ok( 'dry run writes nothing, makes no backup', 'Machine (IA)' === $row( $texts[1] )->translated && null === $row( 'Beaver backup test new' ) && count( BP_Backup::backups() ) === $n );

// Import with the default rules.
$r = BP_Backup::import( $c, array( 'dry_run' => false ) );
ok( 'backup made before the import', count( BP_Backup::backups() ) === $n + 1 && isset( $r['backup'] ) );
ok( 'manual translation kept', 'Manuel (fait main)' === $row( $texts[0] )->translated && 2 === (int) $row( $texts[0] )->status );
ok( 'machine translation kept without the option', 'Machine (IA)' === $row( $texts[1] )->translated );
ok( 'empty translation filled', 'Vide rempli' === $row( $texts[2] )->translated );
ok( 'new text added with its translation', 'Nouveau' === ( $row( 'Beaver backup test new' )->translated ?? '' ) && 2 === (int) $row( 'Beaver backup test new' )->status );

// Options: machine over machine, then manual too.
$c2 = BP_Backup::validate( $file );
BP_Backup::import( $c2, array( 'dry_run' => false, 'overwrite_machine' => true ) );
ok( 'overwrite machine: last duplicate wins', 'Machine nouvelle 2' === $row( $texts[1] )->translated && 'Manuel (fait main)' === $row( $texts[0] )->translated );
BP_Backup::import( $c2, array( 'dry_run' => false, 'overwrite_manual' => true ) );
ok( 'overwrite manual only when asked', 'Manuel REMPLACÉ' === $row( $texts[0] )->translated );

// Restore the first test backup: values back, the added text removed.
$restore_from = $r['backup']; // Made just before the first import.
$rr = BP_Backup::restore( $restore_from );
ok( 'restore: values back', ! is_wp_error( $rr ) && 'Manuel (fait main)' === $row( $texts[0] )->translated && 'Machine (IA)' === $row( $texts[1] )->translated, is_wp_error( $rr ) ? $rr->get_error_message() : '' );
ok( 'restore: text added by the import removed', null === $row( 'Beaver backup test new' ) );
ok( 'restore: backup of the state before', isset( $rr['backup'] ) && '' !== BP_Backup::path( $rr['backup'] ) );

// CSV import round trip.
$tmp = wp_tempnam( 'bp-csv' ); file_put_contents( $tmp, "type,original,translation,status\nregular,\"{$texts[2]}\",\"Depuis CSV\",manual\n" ); // phpcs:ignore
$cc = BP_Backup::validate( BP_Backup::read_file( $tmp, $lang ) );
BP_Backup::import( $cc, array( 'dry_run' => false ) );
ok( 'CSV import: manual from the file replaces machine', 'Depuis CSV' === $row( $texts[2] )->translated && 2 === (int) $row( $texts[2] )->status );
ok( 'CSV without a language refused', is_wp_error( BP_Backup::read_file( $tmp ) ) );
wp_delete_file( $tmp );

// Before a reset (Redo a language) a backup is made.
$n = count( BP_Backup::backups() );
do_action( 'beaver_press_before_reset', 'Redo', 'French' );
ok( 'backup before Redo', count( BP_Backup::backups() ) === $n + 1 && false !== strpos( reset( BP_Backup::backups() )['reason'] ?? '', 'Redo' ) );

echo "\n$pass passed, $fail failed\n";
