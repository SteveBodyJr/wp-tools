<?php
// Beaver Press test: run from the command line only (see tests/README.md).
if ( PHP_SAPI !== 'cli' ) { exit; }
$_SERVER['HTTP_HOST'] = getenv( 'BP_HOST' ) ?: 'localhost'; $_SERVER['REQUEST_URI'] = '/';
require __DIR__ . '/bootstrap.php'; // WordPress + settings put back however the test ends.
$usage_before = get_option( BP_Usage::OPTION, null );
$pass = 0; $fail = 0;
function ok( $n, $c, $x = '' ) { global $pass, $fail; $c ? $pass++ : $fail++; echo ( $c ? 'PASS ' : 'FAIL ' ), $n, $x ? "  [$x]" : '', "\n"; }
$mt_before = get_option( 'trp_machine_translation_settings', null ); $keys_before = get_option( BP_Keys::OPTION, null );
BP_Glossary::flush();
$auto = BP_Glossary::auto_terms();
ok( 'auto list: destinations, areas, stays, routes', in_array( 'Serengeti National Park', $auto, true ) && in_array( 'Lake Manyara', $auto, true ) && in_array( 'Iwandaa Lodge', $auto, true ) && in_array( 'Machame', $auto, true ), count( $auto ) . ' names' );
ok( 'auto list cached', is_array( get_transient( BP_Glossary::TRANSIENT ) ) );
$all = BP_Batch::glossary();
ok( 'glossary = site name + auto', in_array( get_bloginfo( 'name' ), $all, true ) && in_array( 'Ngorongoro Crater', $all, true ) );

$m = BP_Batch::glossary_for( array( 'Game drives in the Serengeti and around Lake Manyara', 'Climb the <b>Machame</b> route' ) );
ok( 'only names in the batch', in_array( 'Serengeti', $m, true ) && in_array( 'Lake Manyara', $m, true ) && in_array( 'Machame', $m, true ) && ! in_array( 'Zanzibar', $m, true ), implode( ', ', $m ) );
ok( 'whole words only', array() === BP_Glossary::matching( array( 'Arusha' ), array( 'Arushaland tours' ) ) && array( 'Arusha' ) === BP_Glossary::matching( array( 'Arusha' ), array( 'Fly to Arusha.' ) ) );
ok( 'no names -> empty list', array() === BP_Batch::glossary_for( array( 'Plan your trip with us' ) ) );
$p = BP_Batch::system_prompt( 'English', 'French', $m );
ok( 'prompt: names kept, generic words translated', false !== strpos( $p, 'Keep the spelling of these names exactly' ) && false !== strpos( $p, 'Lake Manyara' ) && false !== strpos( $p, 'Parc national du Serengeti' ) && false !== strpos( $p, 'Kilimanjaro stays Kilimanjaro' ) );

// Manual list and auto switch.
update_option( 'trp_machine_translation_settings', array( 'bp-glossary' => "Karibu\nBig Five", 'bp-glossary-auto' => 'no' ) );
$g = BP_Batch::glossary();
ok( 'manual names used, auto off', in_array( 'Karibu', $g, true ) && in_array( 'Big Five', $g, true ) && ! in_array( 'Machame', $g, true ) );
update_option( 'trp_machine_translation_settings', array( 'bp-glossary-auto' => 'yes' ) );
ok( 'auto back on', in_array( 'Machame', BP_Batch::glossary(), true ) );
$s = BP_Engine_Settings::sanitize( array(), array( 'bp-glossary' => "  Karibu \n\nKaribu\n<b>Big Five</b>\n", 'bp-glossary-auto' => 'no' ) );
ok( 'sanitize: trimmed, unique, tags stripped', "Karibu\nBig Five" === $s['bp-glossary'] && 'no' === $s['bp-glossary-auto'], str_replace( "\n", '|', $s['bp-glossary'] ) );

// Cache refresh on destination save.
$dest = (int) get_posts( array( 'post_type' => 'destination', 'posts_per_page' => 1, 'fields' => 'ids' ) )[0];
BP_Glossary::auto_terms(); do_action( 'save_post', $dest, get_post( $dest ), true );
ok( 'saving a destination refreshes the list', false === get_transient( BP_Glossary::TRANSIENT ) );

// DeepL protection.
ok( 'core names', 'Serengeti' === BP_Glossary::core( 'Serengeti National Park' ) && 'Manyara' === BP_Glossary::core( 'Lake Manyara' ) && 'Iwandaa Lodge' === BP_Glossary::core( 'Iwandaa Lodge' ) );
$t = 'Visit <a href="/destinations/serengeti/" title="Serengeti">Serengeti National Park</a> near Lake Manyara';
$w = BP_Glossary::protect( $t, array( 'Serengeti National Park', 'Lake Manyara' ) );
ok( 'wrapped in text only, links untouched', false !== strpos( $w, 'href="/destinations/serengeti/" title="Serengeti"' ) && false !== strpos( $w, '<bp-keep>Serengeti</bp-keep> National Park' ) && false !== strpos( $w, 'Lake <bp-keep>Manyara</bp-keep>' ), $w );
ok( 'unwrap restores', $t === BP_Glossary::unprotect( $w ) );

// DeepL request carries ignore_tags and the reply comes back clean.
BP_Keys::set( 'deepl', 'dl-glossary:fx' ); $sent = null;
add_filter( 'pre_http_request', function ( $pre, $args ) use ( &$sent ) { $sent = json_decode( $args['body'], true ); $out = array_map( fn( $x ) => array( 'text' => str_replace( 'National Park', 'Parc national', $x ) ), $sent['text'] ); return array( 'headers' => array(), 'body' => wp_json_encode( array( 'translations' => $out ) ), 'response' => array( 'code' => 200, 'message' => '' ), 'cookies' => array(), 'filename' => null ); }, 10, 2 );
$st = TRP_Translate_Press::get_trp_instance()->get_component( 'settings' )->get_settings();
$st['trp_machine_translation_settings'] = array( 'translation-engine' => 'beaver_ai', 'bp-provider' => 'deepl', 'bp-visitor-guard' => 'no' );
$r = ( new BP_AI_Machine_Translator( $st ) )->translate_array( array( 'x' => 'Serengeti National Park at dawn', 'y' => 'Plan your trip' ), 'fr_FR', 'en_US' );
ok( 'deepl: ignore_tags sent, names wrapped', array( 'bp-keep' ) === ( $sent['ignore_tags'] ?? null ) && false !== strpos( $sent['text'][0], '<bp-keep>Serengeti</bp-keep>' ) && 'Plan your trip' === $sent['text'][1] );
ok( 'deepl: reply unwrapped and accepted', 'Serengeti Parc national at dawn' === ( $r['x'] ?? '' ), $r['x'] ?? '(missing)' );

null === $mt_before ? delete_option( 'trp_machine_translation_settings' ) : update_option( 'trp_machine_translation_settings', $mt_before );
null === $keys_before ? delete_option( BP_Keys::OPTION ) : update_option( BP_Keys::OPTION, $keys_before, false );
delete_option( BP_Providers::ERROR_OPTION );
null === $usage_before ? delete_option( BP_Usage::OPTION ) : update_option( BP_Usage::OPTION, $usage_before, false );
delete_option( 'beaver_press_auto_queue' ); wp_clear_scheduled_hook( 'beaver_press_auto_tick' );
echo "\n$pass passed, $fail failed\n";
