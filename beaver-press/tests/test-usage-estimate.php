<?php
// Beaver Press test: run from the command line only (see tests/README.md).
if ( PHP_SAPI !== 'cli' ) { exit; }
$_SERVER['HTTP_HOST'] = getenv( 'BP_HOST' ) ?: 'localhost'; $_SERVER['REQUEST_URI'] = '/';
require __DIR__ . '/bootstrap.php'; // WordPress + settings put back however the test ends.
$pass = 0; $fail = 0;
function ok( $n, $c, $x = '' ) { global $pass, $fail; $c ? $pass++ : $fail++; echo ( $c ? 'PASS ' : 'FAIL ' ), $n, $x ? "  [$x]" : '', "\n"; }
global $wpdb;
$saved = array();
foreach ( array( BP_Usage::OPTION, 'trp_machine_translation_counter', 'trp_machine_translation_settings', BP_Keys::OPTION ) as $o ) { $saved[ $o ] = get_option( $o, null ); }
delete_option( BP_Usage::OPTION );

// Totals and prices.
BP_Usage::reset_run();
BP_Usage::add( 'claude', 'claude-opus-5-5', 1000, 1000000, 1000000 );
BP_Usage::add( 'deepseek', 'some-model', 500, 2000, 3000 );
$run = BP_Usage::run(); $day = BP_Usage::days()[ gmdate( 'Y-m-d' ) ];
ok( 'run totals add up', 2 === $run['requests'] && 1500 === $run['chars'] && 1002000 === $run['in'] && 1003000 === $run['out'] );
ok( 'opus 5.5 priced $4 in + $20 out per MTok', abs( $day['cost'] - 24.0 ) < 0.0001, (string) $day['cost'] );
ok( 'unknown model adds no cost', null === BP_Usage::price( 'some-model' ) );
ok( 'money format', '$24.00' === BP_Admin::money( 24 ) && '< $0.01' === BP_Admin::money( 0.004 ) );
$d = get_option( BP_Usage::OPTION ); for ( $i = 70; $i > 0; $i-- ) { $d['days'][ gmdate( 'Y-m-d', strtotime( "-$i days" ) ) ] = array( 'requests' => 1, 'chars' => 1, 'in' => 0, 'out' => 0, 'cost' => 0 ); } update_option( BP_Usage::OPTION, $d, false );
BP_Usage::add( 'deepl', '', 10 );
ok( 'keeps two months of days', BP_Usage::KEEP_DAYS === count( BP_Usage::days() ) && 62 === BP_Usage::KEEP_DAYS );

// Estimate.
$left = BP_Usage::untranslated( 'fr_FR' );
$urls = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}trp_dictionary_en_us_fr_fr WHERE status=0 AND original LIKE 'http%'" );
ok( 'untranslated counted, links left out', $left['strings'] > 0 && $left['chars'] > $left['strings'] && $urls > 0, $left['strings'] . ' texts, ' . $left['chars'] . ' chars; ' . $urls . ' link rows excluded' );
$opus  = BP_Usage::estimate( 'fr_FR', BP_Engine_Settings::current( array( 'bp-provider' => 'claude' ) ) );
$haiku = BP_Usage::estimate( 'fr_FR', BP_Engine_Settings::current( array( 'bp-provider' => 'claude', 'bp-model' => 'claude-haiku-4-5' ) ) );
$dl    = BP_Usage::estimate( 'fr_FR', BP_Engine_Settings::current( array( 'bp-provider' => 'deepl' ) ) );
ok( 'estimate: requests = texts / 20', (int) ceil( $left['strings'] / 20 ) === $opus['requests'] );
ok( 'estimate: opus costs 4x haiku', abs( $opus['cost'] - 4 * $haiku['cost'] ) < 0.01, sprintf( 'opus ~$%.2f, haiku ~$%.2f, %s in / %s out tokens', $opus['cost'], $haiku['cost'], $opus['in'], $opus['out'] ) );
ok( 'estimate: DeepL has characters, no dollar figure', null === $dl['cost'] && $dl['chars'] === $left['chars'] );

// Token counts from replies, and what is recorded.
$q = array();
add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( &$q ) { $r = array_shift( $q ); return array( 'headers' => array(), 'body' => wp_json_encode( $r[1] ), 'response' => array( 'code' => $r[0], 'message' => '' ), 'cookies' => array(), 'filename' => null ); }, 10, 3 );
BP_Keys::set( 'claude', 'k-usage-1' ); BP_Keys::set( 'openai', 'k-usage-2' ); BP_Keys::set( 'gemini', 'k-usage-3' );
$st = TRP_Translate_Press::get_trp_instance()->get_component( 'settings' )->get_settings();
$eng = function ( $p, $m = '' ) use ( $st ) { $st['trp_machine_translation_settings'] = array( 'translation-engine' => 'beaver_ai', 'bp-provider' => $p, 'bp-model' => $m, 'bp-visitor-guard' => 'no' ); return new BP_AI_Machine_Translator( $st ); };
BP_Usage::reset_run();
$q[] = array( 200, array( 'stop_reason' => 'end_turn', 'usage' => array( 'input_tokens' => 600, 'cache_read_input_tokens' => 100, 'output_tokens' => 50 ), 'content' => array( array( 'type' => 'text', 'text' => '{"s1":"Bonjour"}' ) ) ) );
$eng( 'claude' )->translate_array( array( 'Hello' ), 'fr_FR', 'en_US' );
$r = BP_Usage::run(); ok( 'claude tokens (incl. cache reads) recorded', 700 === $r['in'] && 50 === $r['out'] && 5 === $r['chars'] && $r['priced'] );
$q[] = array( 200, array( 'usage' => array( 'prompt_tokens' => 300, 'completion_tokens' => 40 ), 'choices' => array( array( 'finish_reason' => 'stop', 'message' => array( 'content' => '{"s1":"Salut"}' ) ) ) ) );
$eng( 'openai', 'gpt-test' )->translate_array( array( 'Hi' ), 'fr_FR', 'en_US' );
$r = BP_Usage::run(); ok( 'openai tokens recorded, unpriced', 1000 === $r['in'] && 90 === $r['out'] && ! $r['priced'] );
$q[] = array( 200, array( 'usageMetadata' => array( 'promptTokenCount' => 200, 'candidatesTokenCount' => 30, 'thoughtsTokenCount' => 20 ), 'candidates' => array( array( 'finishReason' => 'STOP', 'content' => array( 'parts' => array( array( 'text' => '{"s1":"Coucou"}' ) ) ) ) ) ) );
$eng( 'gemini', 'gemini-test' )->translate_array( array( 'Hey' ), 'fr_FR', 'en_US' );
$r = BP_Usage::run(); ok( 'gemini tokens incl. thinking recorded', 1200 === $r['in'] && 140 === $r['out'] && 3 === $r['requests'] );
$q[] = array( 200, array( 'stop_reason' => 'refusal', 'usage' => array( 'input_tokens' => 80, 'output_tokens' => 5 ), 'content' => array() ) );
$eng( 'claude' )->translate_array( array( 'Declined text x' ), 'fr_FR', 'en_US' );
$r = BP_Usage::run(); ok( 'refused reply still recorded (billed)', 4 === $r['requests'] && 1280 === $r['in'] );
$q[] = array( 401, array( 'error' => array( 'message' => 'bad key' ) ) );
$eng( 'claude' )->translate_array( array( 'Auth fail text' ), 'fr_FR', 'en_US' );
ok( 'rejected call not recorded', 4 === BP_Usage::run()['requests'] );
ok( 'TranslatePress daily counter also counted', (int) $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name='trp_machine_translation_counter'" ) > 0, (string) $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name='trp_machine_translation_counter'" ) . ' chars' );
remove_all_filters( 'pre_http_request' );
delete_option( BP_Batch::REJECT_OPTION );

// Daily limit reader.
$mt = get_option( 'trp_machine_translation_settings', array() ); $mt = is_array( $mt ) ? $mt : array();
$mt['machine_translation_counter_date'] = date( 'Y-m-d' ); $mt['machine_translation_limit'] = 5000; update_option( 'trp_machine_translation_settings', $mt );
update_option( 'trp_machine_translation_counter', 1234 );
$dl2 = BP_Usage::daily_limit(); ok( 'daily limit read fresh', 5000 === $dl2['limit'] && 1234 === $dl2['used'] );
$mt['machine_translation_counter_date'] = '2020-01-01'; update_option( 'trp_machine_translation_settings', $mt );
ok( 'old counter date counts as 0 today', 0 === BP_Usage::daily_limit()['used'] );
// Built-in DeepSeek prices (P2): the provider's default and the old name are priced.
ok( 'deepseek prices built in', array( 0.3, 1.2 ) === BP_Usage::price( 'deepseek-flash' ) && array( 0.3, 1.2 ) === BP_Usage::price( 'deepseek-chat' ) && null !== BP_Usage::price( 'deepseek-v4-pro' ) );
ok( 'deepseek default model is priced', null !== BP_Usage::price( BP_Providers::preset( 'deepseek' )['model'] ) );
update_option( BP_Usage::PRICES_OPTION, array( 'deepseek-flash' => array( 0.15, 0.6 ) ), false );
ok( 'owner price overrides the built-in one', array( 0.15, 0.6 ) === BP_Usage::price( 'deepseek-flash' ) );
echo "\n$pass passed, $fail failed\n";
foreach ( $saved as $o => $v ) { if ( 'trp_machine_translation_counter' === $o ) { $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %d WHERE option_name = 'trp_machine_translation_counter'", (int) $v ) ); continue; } null === $v ? delete_option( $o ) : update_option( $o, $v, false ); }
delete_option( BP_Providers::ERROR_OPTION );
