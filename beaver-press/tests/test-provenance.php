<?php
// Beaver Press test: run from the command line only (see tests/README.md).
// Provenance (provider, model, status) and changed originals (manual translation kept, marked
// outdated, never replaced by the AI unless asked).
if ( PHP_SAPI !== 'cli' ) { exit; }
require __DIR__ . '/bootstrap.php'; // WordPress + settings put back however the test ends.
$pass = 0; $fail = 0;
function ok( $n, $c, $x = '' ) { global $pass, $fail; $c ? $pass++ : $fail++; echo ( $c ? 'PASS ' : 'FAIL ' ), $n, $x ? "  [$x]" : '', "\n"; }
global $wpdb;
$lang = 'fr_FR';
$q    = TRP_Translate_Press::get_trp_instance()->get_component( 'query' );
$dict = $q->get_table_name( $lang );
$meta = $q->get_table_name_for_original_meta();
$T    = BP_Provenance::table();
$post = wp_insert_post( array( 'post_title' => 'Beaver prov test', 'post_status' => 'publish', 'post_type' => 'page', 'post_content' => 'x' ) );
register_shutdown_function( function () use ( $wpdb, $dict, $T, $post ) {
	$wpdb->query( "DELETE FROM `{$dict}` WHERE original LIKE 'Beaver prov%' OR original LIKE 'Our camp sits%' OR original LIKE 'Breakfast is%'" ); // phpcs:ignore
	$wpdb->query( "DELETE FROM `{$T}` WHERE old_source LIKE 'Our camp%' OR provider IN ('test-provider','deepseek','import') OR source_hash IN ('" . implode( "','", array_map( 'md5', array( 'Beaver prov machine', 'Beaver prov manual', 'Our camp sits right beside the river at Ndutu', 'Breakfast is served at seven' ) ) ) . "')" ); // phpcs:ignore
	wp_delete_post( $post, true );
} );
BP_Provenance::install();
ok( 'table exists', $T === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $T ) ) );

// Machine, then a manual edit after it.
BP_Provenance::machine( $lang, array( 'Beaver prov machine' ), array( 'provider' => 'test-provider', 'api' => 'openai', 'model' => 'test-model' ) );
$r = BP_Provenance::get( $lang, 'Beaver prov machine' );
ok( 'machine: provider, model, hash, time', $r && 'machine_translated' === $r['status'] && 'test-provider' === $r['provider'] && 'test-model' === $r['model'] && md5( 'Beaver prov machine' ) === $r['source_hash'] && '' !== $r['created_at'] );
do_action( 'trp_save_editor_translations_regular_strings', array( $lang => array( array( 'original' => 'Beaver prov machine', 'translated' => 'Édité', 'status' => 2 ) ) ), array() );
$r = BP_Provenance::get( $lang, 'Beaver prov machine' );
ok( 'editor save: manually edited after AI, editor kept', 'manually_edited' === $r['status'] && 1 === (int) $r['edited_after_ai'] && 'test-provider' === $r['provider'] );
BP_Provenance::machine( $lang, array( 'Beaver prov machine' ), array( 'provider' => 'test-provider', 'model' => 'other' ) );
ok( 'a machine run never turns a manual row back', 'manually_edited' === BP_Provenance::get( $lang, 'Beaver prov machine' )['status'] );
do_action( 'trp_save_editor_translations_regular_strings', array( $lang => array( array( 'original' => 'Beaver prov machine', 'translated' => '', 'status' => 0 ) ) ), array() );
ok( 'translation cleared: row removed', null === BP_Provenance::get( $lang, 'Beaver prov machine' ) );

// A changed original on a page: the manual translation is carried and marked outdated.
$old = 'Our camp sits beside the river at Ndutu';
$new = 'Our camp sits right beside the river at Ndutu';
$q->insert_strings( array( $old, 'Breakfast is served at seven' ), $lang );
$wpdb->query( $wpdb->prepare( "UPDATE `{$dict}` SET translated = %s, status = 2 WHERE original = %s", 'Notre camp est au bord de la rivière à Ndutu', $old ) ); // phpcs:ignore
$wpdb->query( $wpdb->prepare( "UPDATE `{$dict}` SET translated = %s, status = 2 WHERE original = %s", 'Le petit-déjeuner est servi à sept heures', 'Breakfast is served at seven' ) ); // phpcs:ignore
foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT original_id FROM `{$dict}` WHERE original IN (%s, %s)", $old, 'Breakfast is served at seven' ) ) as $oid ) { // phpcs:ignore
	$wpdb->insert( $meta, array( 'original_id' => (int) $oid, 'meta_key' => $q->get_meta_key_for_post_parent_id(), 'meta_value' => (string) $post ) );
}
$GLOBALS['wp_query'] = new WP_Query( array( 'page_id' => $post ) );
$GLOBALS['wp_the_query'] = $GLOBALS['wp_query'];
$orig = new ReflectionProperty( 'BP_Complete', 'original' ); $orig->setAccessible( true );
$orig->setValue( null, '<html><body><p>' . $new . '</p><p>Breakfast is served at seven</p></body></html>' );
$c = BP_Provenance::carry( $lang, array( 'k1' => $new, 'k2' => 'Completely different sentence about lions' ) );
ok( 'changed text: manual translation carried', array( 'k1' => 'Notre camp est au bord de la rivière à Ndutu' ) === $c, wp_json_encode( $c ) );
$r = BP_Provenance::get( $lang, $new );
ok( 'marked outdated with the old source and translation', $r && 'outdated' === $r['status'] && $old === $r['old_source'] && 'Notre camp est au bord de la rivière à Ndutu' === $r['old_translation'] && $post === (int) $r['post_id'] );
$orig->setValue( null, '<html><body><p>' . $new . '</p><p>' . $old . '</p></body></html>' );
$wpdb->query( $wpdb->prepare( "DELETE FROM `{$T}` WHERE source_hash = %s", md5( $new ) ) ); // phpcs:ignore
ok( 'old text still on the page: nothing carried', array() === BP_Provenance::carry( $lang, array( 'k1' => $new ) ) );
// Linked only through Beaver Press's own record (the engine linked the text to another post first).
$wpdb->query( $wpdb->prepare( "DELETE FROM `{$meta}` WHERE meta_value = %s", (string) $post ) ); // phpcs:ignore
BP_Provenance::put( $lang, $old, array( 'status' => 'manually_edited', 'post_id' => $post ) );
$orig->setValue( null, '<html><body><p>' . $new . '</p></body></html>' );
$wpdb->query( $wpdb->prepare( "DELETE FROM `{$T}` WHERE source_hash = %s", md5( $new ) ) ); // phpcs:ignore
ok( 'carried through Beaver Press\'s own post link too', array( 'k1' => 'Notre camp est au bord de la rivière à Ndutu' ) === BP_Provenance::carry( $lang, array( 'k1' => $new ) ) );
BP_Provenance::forget( $lang, $old );
foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT original_id FROM `{$dict}` WHERE original IN (%s, %s)", $old, 'Breakfast is served at seven' ) ) as $oid ) { // phpcs:ignore
	$wpdb->insert( $meta, array( 'original_id' => (int) $oid, 'meta_key' => $q->get_meta_key_for_post_parent_id(), 'meta_value' => (string) $post ) ); // The engine's link back for the next checks.
}
$orig->setValue( null, '<html><body><p>' . $new . '</p></body></html>' );

// Through the engine: the carried text is not sent to the AI; the rest is, and is recorded.
update_option( 'trp_machine_translation_settings', array( 'translation-engine' => 'beaver_ai', 'bp-provider' => 'deepseek', 'bp-model' => 'deepseek-flash', 'bp-visitor-guard' => 'no' ) );
BP_Keys::set( 'deepseek', 'sk-test-provenance' );
$GLOBALS['sent'] = array();
add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
	if ( false === strpos( $url, 'deepseek' ) ) { return $pre; }
	$body = json_decode( $args['body'], true );
	$in   = json_decode( (string) substr( $body['messages'][1]['content'], strpos( $body['messages'][1]['content'], '{' ) ), true );
	$GLOBALS['sent'] = array_merge( $GLOBALS['sent'], array_values( (array) $in ) );
	$outj = array_map( fn( $t ) => 'FR ' . $t, (array) $in );
	return array( 'headers' => array(), 'body' => wp_json_encode( array( 'choices' => array( array( 'message' => array( 'content' => wp_json_encode( $outj ) ) ) ), 'usage' => array( 'prompt_tokens' => 10, 'completion_tokens' => 10 ) ) ), 'response' => array( 'code' => 200, 'message' => '' ), 'cookies' => array(), 'filename' => null );
}, 20, 3 );
$st = TRP_Translate_Press::get_trp_instance()->get_component( 'settings' )->get_settings();
$st['trp_machine_translation_settings'] = get_option( 'trp_machine_translation_settings' );
require_once BP_PATH . 'includes/class-bp-ai-machine-translator.php';
$res = ( new BP_AI_Machine_Translator( $st ) )->translate_array( array( 'a' => $new, 'b' => 'Beaver prov fresh text' ), $lang, 'en_US' );
ok( 'carried text not sent to the AI', ! in_array( $new, $GLOBALS['sent'], true ) && in_array( 'Beaver prov fresh text', $GLOBALS['sent'], true ), wp_json_encode( $GLOBALS['sent'] ) );
ok( 'engine gets the carried and the new translation', 'Notre camp est au bord de la rivière à Ndutu' === ( $res['a'] ?? '' ) && 'FR Beaver prov fresh text' === ( $res['b'] ?? '' ), wp_json_encode( $res ) );
$r = BP_Provenance::get( $lang, 'Beaver prov fresh text' );
ok( 'new text recorded with provider and model', $r && 'deepseek' === $r['provider'] && 'deepseek-flash' === $r['model'] && 'machine_translated' === $r['status'] );
ok( 'recorded with type, language, hash and time', $r && 'gettext' === $r['type'] && 'fr_FR' === $r['language'] && md5( 'Beaver prov fresh text' ) === $r['source_hash'] && '' !== (string) $r['created_at'] ); // Not in the page-text table: a theme/plugin text.
ok( 'the outdated row stays outdated', 'outdated' === BP_Provenance::get( $lang, $new )['status'] );

// The owner's actions. Store the carried translation as the engine would.
$q->insert_strings( array( $new ), $lang );
$wpdb->query( $wpdb->prepare( "UPDATE `{$dict}` SET translated = %s, status = 1 WHERE original = %s", 'Notre camp est au bord de la rivière à Ndutu', $new ) ); // phpcs:ignore
$id = (int) BP_Provenance::get( $lang, $new )['id'];
ob_start(); BP_Provenance::render(); $html = ob_get_clean();
ok( 'changed texts card shows the difference', false !== strpos( $html, 'id="bp-changed"' ) && false !== strpos( $html, 'right' ) && false !== strpos( $html, 'Retranslate with AI' ) );
ok( 'keep: becomes manual', BP_Provenance::keep( $id ) && 2 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM `{$dict}` WHERE original = %s", $new ) ) && 'manually_edited' === BP_Provenance::get( $lang, $new )['status'] ); // phpcs:ignore
BP_Provenance::put( $lang, $new, array( 'status' => 'outdated', 'old_source' => $old, 'post_id' => $post ) );
$id = (int) BP_Provenance::get( $lang, $new )['id'];
$GLOBALS['sent'] = array();
$rt = BP_Provenance::retranslate( $id );
ok( 'retranslate (explicit): AI translation stored, recorded as machine', true === $rt && 'FR ' . $new === $wpdb->get_var( $wpdb->prepare( "SELECT translated FROM `{$dict}` WHERE original = %s", $new ) ) && 'machine_translated' === BP_Provenance::get( $lang, $new )['status'], is_wp_error( $rt ) ? $rt->get_error_message() : '' ); // phpcs:ignore
ok( 'retranslate sent only that text, nothing carried', array( $new ) === $GLOBALS['sent'] );

echo "\n$pass passed, $fail failed\n";
