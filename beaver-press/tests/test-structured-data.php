<?php
// Beaver Press test: run from the command line only (see tests/README.md).
if ( PHP_SAPI !== 'cli' ) { exit; }
$_SERVER['HTTP_HOST'] = getenv( 'BP_HOST' ) ?: 'localhost'; $_SERVER['REQUEST_URI'] = '/';
require __DIR__ . '/bootstrap.php'; // WordPress + settings put back however the test ends.
global $wpdb;
$pass = 0; $fail = 0;
function ok( $n, $c, $x = '' ) { global $pass, $fail; $c ? $pass++ : $fail++; echo ( $c ? 'PASS ' : 'FAIL ' ), $n, $x ? "  [$x]" : '', "\n"; }
// Snapshot everything this touches (keys, settings, usage, counter by value).
$saved = array(); foreach ( array( BP_Keys::OPTION, 'trp_machine_translation_settings', BP_Usage::OPTION, BP_Providers::ERROR_OPTION, BP_Batch::REJECT_OPTION ) as $o ) { $saved[ $o ] = get_option( $o, null ); }
$counter = get_option( 'trp_machine_translation_counter' );
$d = $wpdb->prefix . 'trp_dictionary_en_us_fr_fr'; $max = (int) $wpdb->get_var( "SELECT MAX(id) FROM $d" );

$mt = get_option( 'trp_machine_translation_settings' );
$mt = array_merge( $mt, array( 'bp-provider' => 'custom', 'bp-endpoint' => 'http://fake.invalid/v1/chat/completions', 'bp-model' => 'fake', 'machine-translation' => 'yes', 'translation-engine' => 'beaver_ai' ) );
update_option( 'trp_machine_translation_settings', $mt );
BP_Keys::set( 'custom', 'fake-b11-key' );
$sent = array();
add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( &$sent ) {
	if ( false === strpos( $url, 'fake.invalid' ) ) { return $pre; }
	$body = json_decode( $args['body'], true ); $user = $body['messages'][1]['content'];
	$in = json_decode( substr( $user, strpos( $user, '{' ) ), true ); $out = array();
	foreach ( $in as $k => $v ) { $sent[] = $v; $out[ $k ] = 'FR ' . $v; }
	return array( 'response' => array( 'code' => 200, 'message' => 'OK' ), 'headers' => array(), 'cookies' => array(), 'body' => wp_json_encode( array( 'choices' => array( array( 'finish_reason' => 'stop', 'message' => array( 'content' => wp_json_encode( $out ) ) ) ) ) ) );
}, 10, 3 );
// Rebuild TranslatePress's engine with these settings.
$trp = TRP_Translate_Press::get_trp_instance();
$render = $trp->get_component( 'translation_render' );
$prop = new ReflectionProperty( $render, 'machine_translator' ); $prop->setAccessible( true );
require_once BP_PATH . 'includes/class-bp-ai-machine-translator.php';
$all = $trp->get_component( 'settings' )->get_settings(); $all['trp_machine_translation_settings'] = $mt;
$prop->setValue( $render, new BP_AI_Machine_Translator( $all ) );

$u = 'b11 unique ' . wp_rand();
$src = array( '@context' => 'https://schema.org', '@graph' => array(
	array( '@type' => 'TravelAgency', '@id' => home_url( '/#business' ), 'name' => get_bloginfo( 'name' ), 'url' => home_url( '/' ) ),
	array( '@type' => array( 'TouristTrip', 'Product' ), 'name' => "Trip $u", 'description' => "Desc $u &amp; more", 'image' => home_url( '/x.jpg' ), 'duration' => 'P4D',
		'offers' => array( '@type' => 'Offer', 'price' => '925', 'priceCurrency' => 'USD' ), 'touristType' => array( 'Serengeti' ) ),
	array( '@type' => 'BlogPosting', 'headline' => "Head $u", 'datePublished' => '2026-07-10T22:28:14+03:00', 'articleSection' => array( "Sec $u" ) ),
	array( '@type' => 'FAQPage', 'mainEntity' => array( array( '@type' => 'Question', 'name' => "Q $u?", 'acceptedAnswer' => array( '@type' => 'Answer', 'text' => "A $u </script> x" ) ) ) ),
) );
$json = wp_json_encode( $src, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
// Visitor guard on: an untrusted request sends nothing and the block stays English.
$out = BP_Schema::translate_json( $json, 'fr_FR' );
ok( 'guard: visitor sends nothing', 0 === count( $sent ) && false !== strpos( $out, "Trip $u" ) && false === strpos( $out, 'FR Trip' ) );
$wpdb->query( $wpdb->prepare( "DELETE FROM $d WHERE id > %d", $max ) );
$mt['bp-visitor-guard'] = 'no'; update_option( 'trp_machine_translation_settings', $mt ); $all['trp_machine_translation_settings'] = $mt;
$prop->setValue( $render, new BP_AI_Machine_Translator( $all ) );
$out  = BP_Schema::translate_json( $json, 'fr_FR' );
$r    = json_decode( (string) $out, true );
ok( 'valid JSON back', is_array( $r ) );
$g = $r['@graph'];
ok( 'name translated', "FR Trip $u" === $g[1]['name'] );
ok( 'description translated, entity kept', "FR Desc $u &amp; more" === $g[1]['description'] );
ok( 'headline + articleSection list', "FR Head $u" === $g[2]['headline'] && array( "FR Sec $u" ) === $g[2]['articleSection'] );
ok( 'FAQ question + answer', "FR Q $u?" === $g[3]['mainEntity'][0]['name'] && 0 === strpos( $g[3]['mainEntity'][0]['acceptedAnswer']['text'], "FR A $u" ) );
ok( 'no raw </script> in output', false === stripos( $out, '</script' ) );
ok( 'price, currency, date, duration unchanged', '925' === $g[1]['offers']['price'] && 'USD' === $g[1]['offers']['priceCurrency'] && '2026-07-10T22:28:14+03:00' === $g[2]['datePublished'] && 'P4D' === $g[1]['duration'] );
ok( 'urls, @id, @type, touristType unchanged', home_url( '/' ) === $g[0]['url'] && home_url( '/#business' ) === $g[0]['@id'] && array( 'TouristTrip', 'Product' ) === $g[1]['@type'] && array( 'Serengeti' ) === $g[1]['touristType'] && home_url( '/x.jpg' ) === $g[1]['image'] );
ok( 'site name not sent', get_bloginfo( 'name' ) === $g[0]['name'] && ! in_array( get_bloginfo( 'name' ), $sent, true ) );
ok( 'stored in dictionary (reviewable)', 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT status FROM $d WHERE original = %s", "Trip $u" ) ) );
$n = count( $sent ); BP_Schema::translate_json( $json, 'fr_FR' );
ok( 'second time: no new requests', count( $sent ) === $n );
ok( 'invalid JSON left alone', null === BP_Schema::translate_json( '{"name": "broken"', 'fr_FR' ) );
ok( 'hooked', false !== has_filter( 'trp_process_other_text_nodes', array( 'BP_Schema', 'node' ) ) );

// Clean up: only this test's rows, then settings.
$wpdb->query( $wpdb->prepare( "DELETE FROM $d WHERE id > %d", $max ) );
foreach ( $saved as $o => $v ) { null === $v ? delete_option( $o ) : update_option( $o, $v, false ); }
$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = 'trp_machine_translation_counter'", $counter ) );
echo "\n$pass passed, $fail failed\n";
