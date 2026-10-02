<?php
// Beaver Press test: run from the command line only (see tests/README.md).
// Names to keep: clean group labels, the AI rule, Suggest, Find, and the daily sorting of new names.
if ( PHP_SAPI !== 'cli' ) { exit; }
$_SERVER['HTTP_HOST'] = getenv( 'BP_HOST' ) ?: 'localhost'; $_SERVER['REQUEST_URI'] = '/';
require __DIR__ . '/bootstrap.php'; // WordPress + settings put back however the test ends.
$pass = 0; $fail = 0;
function ok( $n, $c, $x = '' ) { global $pass, $fail; $c ? $pass++ : $fail++; echo ( $c ? 'PASS ' : 'FAIL ' ), $n, $x ? "  [$x]" : '', "\n"; }
$mt_before    = get_option( 'trp_machine_translation_settings', null );
$keys_before  = get_option( BP_Keys::OPTION, null );
$names_before = get_option( BP_Glossary::NAMES_OPTION, null );
$usage_before = get_option( BP_Usage::OPTION, null );
$err_before   = get_option( BP_Providers::ERROR_OPTION, null );
// Put everything back however the test ends (a failed check can stop it with wp_die()).
register_shutdown_function( function () use ( $mt_before, $keys_before, $names_before, $usage_before, $err_before ) {
	null === $mt_before ? delete_option( 'trp_machine_translation_settings' ) : update_option( 'trp_machine_translation_settings', $mt_before );
	null === $keys_before ? delete_option( BP_Keys::OPTION ) : update_option( BP_Keys::OPTION, $keys_before, false );
	null === $names_before ? delete_option( BP_Glossary::NAMES_OPTION ) : update_option( BP_Glossary::NAMES_OPTION, $names_before, false );
	null === $usage_before ? delete_option( BP_Usage::OPTION ) : update_option( BP_Usage::OPTION, $usage_before, false );
	null === $err_before ? delete_option( BP_Providers::ERROR_OPTION ) : update_option( BP_Providers::ERROR_OPTION, $err_before, false );
	BP_Glossary::flush();
} );

// N1: a cache rebuilt with the engine's gettext markers prints clean labels and names.
$wrap = fn( $s ) => '#!trpst#trp-gettext data-trpgettextoriginal=0#!trpen#' . $s . '#!trpst#/trp-gettext#!trpen#';
BP_Glossary::flush();
$add = function ( $g ) use ( $wrap ) { $g['extra'] = array( 'label' => $wrap( 'Extra group' ), 'names' => array( $wrap( 'Zzmarked Lodge' ) ) ); return $g; };
add_filter( 'beaver_press_name_groups', $add );
$g = BP_Glossary::groups();
remove_filter( 'beaver_press_name_groups', $add );
ok( 'built-in labels looked up, not cached', 'Accommodation names' === $g['accommodation']['label'] && '' === get_transient( BP_Glossary::TRANSIENT )['accommodation']['label'] );
ok( 'markers stripped from filter labels and names', 'Extra group' === $g['extra']['label'] && array( 'Zzmarked Lodge' ) === $g['extra']['names'] );
$cached = get_transient( BP_Glossary::TRANSIENT ); $cached['accommodation']['label'] = $wrap( 'Accommodation names' );
set_transient( BP_Glossary::TRANSIENT, $cached, 60 );
ok( 'an old marked cache still reads clean', 'Accommodation names' === BP_Glossary::groups()['accommodation']['label'] );
ob_start(); BP_Instructions::render_names(); $html = ob_get_clean();
ok( 'card has no markers', false === strpos( $html, '#!trp' ) );
ok( 'card: rule box, search, AI buttons', false !== strpos( $html, 'id="bp-names-rule"' ) && false !== strpos( $html, 'bp-names__search' ) && false !== strpos( $html, 'bp-names__suggest' ) && false !== strpos( $html, 'bp-names__find' ) );
BP_Glossary::flush();

// The rule.
delete_option( BP_Glossary::NAMES_OPTION );
ok( 'built-in rule in use', BP_Names_AI::rule() === BP_Names_AI::default_rule() && false !== strpos( BP_Names_AI::rule(), 'When unsure, keep' ) );
BP_Names_AI::store( array( 'rule' => 'Keep everything.' ) );
ok( 'own rule wins', 'Keep everything.' === BP_Names_AI::rule() );
delete_option( BP_Glossary::NAMES_OPTION );

// Fake DeepSeek: "translate" for huts, caves and the circuit, keep the rest.
update_option( 'trp_machine_translation_settings', array( 'translation-engine' => 'beaver_ai', 'bp-provider' => 'deepseek', 'bp-visitor-guard' => 'no' ) );
BP_Keys::set( 'deepseek', 'sk-test-names' );
$GLOBALS['calls'] = array();
add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
	if ( false === strpos( $url, 'deepseek' ) ) { return $pre; }
	$body = json_decode( $args['body'], true ); $GLOBALS['calls'][] = $body;
	$in   = json_decode( substr( $body['messages'][1]['content'], strlen( 'Names (JSON): ' ) ), true );
	$rows = array_map( fn( $n ) => array( 'name' => $n, 'keep' => ! preg_match( '/\b(Hut|Cave|Circuit|October|Days)\b/', $n ), 'reason' => 'test' ), $in['names'] );
	$data = array( 'choices' => array( array( 'message' => array( 'content' => wp_json_encode( array( 'names' => $rows ) ) ) ) ), 'usage' => array( 'prompt_tokens' => 1000, 'completion_tokens' => 500 ) );
	return array( 'headers' => array(), 'body' => wp_json_encode( $data ), 'response' => array( 'code' => 200, 'message' => '' ), 'cookies' => array(), 'filename' => null );
}, 10, 3 );

$r = BP_Names_AI::ask( array( 'Kibo Hut', 'Machame', 'Northern Circuit' ), BP_Names_AI::default_rule() );
ok( 'ask: one answer per name', ! is_wp_error( $r ) && false === $r['Kibo Hut']['keep'] && true === $r['Machame']['keep'] && false === $r['Northern Circuit']['keep'] );
ok( 'ask: rule is the system prompt', false !== strpos( $GLOBALS['calls'][0]['messages'][0]['content'], 'When unsure, keep' ) );
$GLOBALS['calls'] = array();
BP_Names_AI::ask( array_map( fn( $i ) => "Name $i", range( 1, 230 ) ), 'r' );
ok( 'long lists go in chunks', 3 === count( $GLOBALS['calls'] ), count( $GLOBALS['calls'] ) . ' requests' );

// Daily run: first run only remembers today's names; later only new names are sorted.
delete_option( BP_Glossary::NAMES_OPTION ); $GLOBALS['calls'] = array();
BP_Names_AI::sort_new();
$st = BP_Names_AI::state();
ok( 'first daily run: no request, names remembered', ! $GLOBALS['calls'] && count( $st['seen'] ) === count( BP_Names_AI::all_names() ) );
$new = function ( $g ) { $g['accommodation']['names'][] = 'Zztest Hut'; $g['accommodation']['names'][] = 'Zztest Serena'; return $g; };
add_filter( 'beaver_press_name_groups', $new ); BP_Glossary::flush();
BP_Names_AI::sort_new();
$st = BP_Names_AI::state();
ok( 'new names only sent', 1 === count( $GLOBALS['calls'] ) && 2 === count( json_decode( substr( $GLOBALS['calls'][0]['messages'][1]['content'], 14 ), true )['names'] ) );
ok( 'translate -> unticked, keep -> ticked', ! empty( $st['off'][ md5( 'Zztest Hut' ) ] ) && empty( $st['off'][ md5( 'Zztest Serena' ) ] ) && 1 === $st['ai'][ md5( 'Zztest Hut' ) ]['a'] );
ok( 'glossary follows', ! in_array( 'Zztest Hut', BP_Glossary::auto_terms(), true ) && in_array( 'Zztest Serena', BP_Glossary::auto_terms(), true ) );
$GLOBALS['calls'] = array(); BP_Names_AI::sort_new();
ok( 'nothing new -> no request', ! $GLOBALS['calls'] );
BP_Names_AI::store( array( 'seen' => array() ) ); BP_Names_AI::store( array( 'auto' => 'no' ) ); BP_Names_AI::sort_new();
ok( 'switch off -> no request', ! $GLOBALS['calls'] );
remove_filter( 'beaver_press_name_groups', $new ); BP_Glossary::flush();

// Candidates for "Find names I missed".
$c = BP_Names_AI::candidates();
ok( 'candidates: phrases not on the card', count( $c ) > 0 && ! in_array( 'Serengeti', $c, true ) && ! in_array( 'Machame', $c, true ), count( $c ) . ': ' . implode( ', ', array_slice( $c, 0, 8 ) ) );

// Save keeps AI tags, stores the built-in rule as '' and marks every name seen.
delete_option( BP_Glossary::NAMES_OPTION );
BP_Names_AI::store( array( 'ai' => array( 'h' => array( 'k' => 1, 'r' => 'x' ) ) ) );
wp_set_current_user( (int) get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ids' ) )[0] );
$_POST = array( '_wpnonce' => wp_create_nonce( 'bp_names' ), 'bp_names_rule' => BP_Names_AI::default_rule(), 'bp_names_auto' => 'yes', 'bp_names_groups' => array( 'accommodation' => 'yes' ), 'bp_names_keep' => array() );
$_REQUEST = $_POST;
add_filter( 'wp_redirect', function () { throw new Exception( 'redirect' ); } );
try { BP_Instructions::save_names(); } catch ( Exception $e ) { unset( $e ); }
$st = BP_Names_AI::state();
ok( 'save: built-in rule stored as empty, tags kept, all seen', '' === $st['rule'] && isset( $st['ai']['h'] ) && count( $st['seen'] ) === count( BP_Names_AI::all_names() ) && 'yes' === $st['auto'] );

echo "\n$pass passed, $fail failed\n";
