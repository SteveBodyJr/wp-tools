<?php
// Beaver Press test: run from the command line only (see tests/README.md).
if ( PHP_SAPI !== 'cli' ) { exit; }
$_SERVER['HTTP_HOST'] = getenv( 'BP_HOST' ) ?: 'localhost'; $_SERVER['REQUEST_URI'] = '/';
require __DIR__ . '/bootstrap.php'; // WordPress + settings put back however the test ends.
$usage_before = get_option( BP_Usage::OPTION, null );
$pass = 0; $fail = 0;
function ok( $n, $c, $x = '' ) { global $pass, $fail; $c ? $pass++ : $fail++; echo ( $c ? 'PASS ' : 'FAIL ' ), $n, $x ? "  [$x]" : '', "\n"; }
$keys_before = get_option( BP_Keys::OPTION, null ); $rej_before = get_option( BP_Batch::REJECT_OPTION, null );
delete_option( BP_Batch::REJECT_OPTION );
BP_Keys::set( 'deepseek', 'ds-test-key-0001' ); BP_Keys::set( 'claude', 'sk-ant-test-0002' ); BP_Keys::set( 'deepl', 'dl-test:fx' );

// Fake provider: translates by prefixing "FR " to text outside tags; markers force faults.
function fake_tr( $v ) {
	$t = preg_replace_callback( '/(^|>)([^<]+)/', fn( $m ) => $m[1] . ( trim( $m[2] ) === '' || preg_match( '/^1TP\d+T/', trim( $m[2] ) ) ? $m[2] : 'FR ' . $m[2] ), $v );
	if ( false !== strpos( $v, 'DROPTAG' ) ) { $t = strip_tags( $t ); }
	if ( false !== strpos( $v, 'DASH' ) ) { $t .= " \u{2014} fin"; }
	return $t;
}
$GLOBALS['calls'] = array(); $GLOBALS['force'] = null;
add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
	if ( false !== strpos( $url, 'wp-cron.php' ) ) { return $pre; } // WordPress's own background requests.
	$body = json_decode( $args['body'], true ); $GLOBALS['calls'][] = array( 'url' => $url, 'body' => $body, 'headers' => $args['headers'] );
	$resp = function ( $code, $data ) { return array( 'headers' => array(), 'body' => wp_json_encode( $data ), 'response' => array( 'code' => $code, 'message' => '' ), 'cookies' => array(), 'filename' => null ); };
	if ( $GLOBALS['force'] ) { return $resp( $GLOBALS['force'][0], $GLOBALS['force'][1] ); }
	if ( false !== strpos( $url, 'deepl.com' ) ) { return $resp( 200, array( 'translations' => array_map( fn( $t ) => array( 'text' => fake_tr( $t ) ), $body['text'] ) ) ); }
	$user = false !== strpos( $url, 'anthropic' ) ? $body['messages'][0]['content'] : $body['messages'][1]['content'];
	$in = json_decode( substr( $user, strpos( $user, '{' ) ), true );
	$out = wp_json_encode( array_map( 'fake_tr', $in ) );
	if ( false !== strpos( $url, 'anthropic' ) ) { return $resp( 200, array( 'stop_reason' => 'end_turn', 'content' => array( array( 'type' => 'text', 'text' => $out ) ) ) ); }
	return $resp( 200, array( 'choices' => array( array( 'finish_reason' => 'stop', 'message' => array( 'content' => $out ) ) ) ) );
}, 10, 3 );

$trp = TRP_Translate_Press::get_trp_instance();
$base = $trp->get_component( 'settings' )->get_settings();
function engine_for( $provider, $chunk = 10 ) { global $base; $s = $base; $s['trp_machine_translation_settings'] = array( 'translation-engine' => 'beaver_ai', 'machine-translation' => 'yes', 'bp-provider' => $provider, 'bp-chunk-size' => $chunk, 'machine_translation_limit_enabled' => 'no', 'bp-visitor-guard' => 'no' ); return new BP_AI_Machine_Translator( $s ); }

// 1. Normal batch (DeepSeek).
$e = engine_for( 'deepseek' );
$r = $e->translate_array( array( 7 => 'Hello', 9 => 'From <strong>1TP1T2,400</strong> per person', 12 => '  Plan your trip  ' ), 'fr_FR', 'en_US' );
$c = end( $GLOBALS['calls'] );
ok( 'keys preserved', array( 7, 9, 12 ) === array_keys( $r ) );
ok( 'tags + placeholder kept', 'FR From <strong>1TP1T2,400</strong>FR  per person' === $r[9], $r[9] );
ok( 'edge whitespace preserved', 0 === strpos( $r[12], '  FR' ) && '  ' === substr( $r[12], -2 ) && ' ' !== substr( $r[12], -3, 1 ), '[' . $r[12] . ']' );
ok( 'prompt names French, no name list when none occur, json mode', false !== strpos( $c['body']['messages'][0]['content'], 'into French' ) && false === strpos( $c['body']['messages'][0]['content'], 'Keep the spelling' ) && 'json_object' === $c['body']['response_format']['type'] );

// 2. Em dash cleaned.
$r = $e->translate_array( array( 'a' => 'Great DASH views' ), 'fr_FR', 'en_US' );
ok( 'em dash -> hyphen', false === strpos( $r['a'], "\u{2014}" ) && false !== strpos( $r['a'], ' - fin' ), $r['a'] );

// 3. Broken item: left out, counted, then given up (kept, no request).
$bad = 'Read <a href="/x">DROPTAG more</a>';
$r = $e->translate_array( array( 'ok' => 'Fine', 'bad' => $bad ), 'fr_FR', 'en_US' );
ok( 'broken item left out, good one kept', isset( $r['ok'] ) && ! isset( $r['bad'] ) );
ok( 'failure counted with reason', 1 === BP_Batch::rejects()[ 'fr_FR:' . md5( $bad ) ]['count'] && 'HTML tags changed' === BP_Batch::rejects()[ 'fr_FR:' . md5( $bad ) ]['reason'] );
$e->translate_array( array( 'bad' => $bad ), 'fr_FR', 'en_US' );
$n = count( $GLOBALS['calls'] ); $r = $e->translate_array( array( 'bad' => $bad ), 'fr_FR', 'en_US' );
ok( 'after 2 failures: kept as is, no request', $bad === ( $r['bad'] ?? null ) && count( $GLOBALS['calls'] ) === $n );

// 4. Chunking.
$n = count( $GLOBALS['calls'] ); $many = array(); for ( $i = 0; $i < 25; $i++ ) { $many[ "k$i" ] = "Line number $i"; }
$r = engine_for( 'deepseek', 10 )->translate_array( $many, 'fr_FR', 'en_US' );
ok( '25 strings / 10 per request = 3 requests', 3 === count( $GLOBALS['calls'] ) - $n && 25 === count( $r ) );

// 5. Whole-call errors are not the strings' fault.
$GLOBALS['force'] = array( 401, array( 'error' => array( 'message' => 'bad key' ) ) );
$r = $e->translate_array( array( 'x' => 'Unique auth test' ), 'fr_FR', 'en_US' );
ok( '401: nothing returned, nothing counted', array() === $r && ! isset( BP_Batch::rejects()[ 'fr_FR:' . md5( 'Unique auth test' ) ] ) );
// 6. Refusal counts against the strings (Claude).
$GLOBALS['force'] = array( 200, array( 'stop_reason' => 'refusal', 'content' => array() ) );
$r = engine_for( 'claude' )->translate_array( array( 'x' => 'Unique refusal test' ), 'fr_FR', 'en_US' );
ok( 'refusal: nothing returned, counted', array() === $r && 1 === BP_Batch::rejects()[ 'fr_FR:' . md5( 'Unique refusal test' ) ]['count'] );
$GLOBALS['force'] = null;

// 7. Claude: schema keys match the batch.
$r = engine_for( 'claude' )->translate_array( array( 'p' => 'One', 'q' => 'Two' ), 'fr_FR', 'en_US' ); $c = end( $GLOBALS['calls'] );
ok( 'claude schema s1,s2 required', array( 's1', 's2' ) === $c['body']['output_config']['format']['schema']['required'] && 'FR Two' === $r['q'] );

// 8. DeepL path keeps order and checks tags too.
$r = engine_for( 'deepl' )->translate_array( array( 'm' => 'Morning', 'n' => 'See <em>Serengeti</em>' ), 'fr_FR', 'en_US' ); $c = end( $GLOBALS['calls'] );
ok( 'deepl mapped back in order', 'FR Morning' === $r['m'] && 'FR See <em>FR Serengeti</em>' === $r['n'] && 'FR' === $c['body']['target_lang'], wp_json_encode( $r ) );

// 9. End to end through TranslatePress's translate(): "$" goes out as a placeholder and comes back.
$u = 'Only $50 today ' . wp_generate_password( 6, false );
$r = engine_for( 'deepseek' )->translate( array( $u ), 'fr_FR', 'en_US' );
ok( 'TranslatePress translate(): $ restored', isset( $r[ $u ] ) && false !== strpos( $r[ $u ], '$50' ) && 0 === strpos( $r[ $u ], 'FR ' ), wp_json_encode( $r ) );

// Clean up.
if ( null === $keys_before ) { delete_option( BP_Keys::OPTION ); } else { update_option( BP_Keys::OPTION, $keys_before, false ); }
if ( null === $rej_before ) { delete_option( BP_Batch::REJECT_OPTION ); } else { update_option( BP_Batch::REJECT_OPTION, $rej_before, false ); }
delete_option( BP_Providers::ERROR_OPTION );
null === $usage_before ? delete_option( BP_Usage::OPTION ) : update_option( BP_Usage::OPTION, $usage_before, false );
echo "\n$pass passed, $fail failed\n";
