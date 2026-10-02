<?php
// Beaver Press test: run from the command line only (see tests/README.md).
// Spending limit in money: requests stop at the day or month limit, runs and the top-up wait,
// the alert says why, and a raised limit lets the next request through.
if ( PHP_SAPI !== 'cli' ) { exit; }
require __DIR__ . '/bootstrap.php'; // WordPress + settings put back however the test ends.
$pass = 0; $fail = 0;
function ok( $n, $c, $x = '' ) { global $pass, $fail; $c ? $pass++ : $fail++; echo ( $c ? 'PASS ' : 'FAIL ' ), $n, $x ? "  [$x]" : '', "\n"; }
add_filter( 'pre_wp_mail', '__return_true' ); // Alerts are captured, never sent.
$GLOBALS['http'] = 0;
add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
	if ( false === strpos( $url, 'deepseek' ) ) { return $pre; }
	$GLOBALS['http']++;
	$data = array( 'choices' => array( array( 'message' => array( 'content' => '{"s1":"Bonjour"}' ) ) ), 'usage' => array( 'prompt_tokens' => 1000000, 'completion_tokens' => 0 ) );
	return array( 'headers' => array(), 'body' => wp_json_encode( $data ), 'response' => array( 'code' => 200, 'message' => '' ), 'cookies' => array(), 'filename' => null );
}, 10, 3 );
$cfg  = array( 'provider' => 'deepseek', 'api' => 'openai', 'url' => 'https://api.deepseek.com/chat/completions', 'model' => 'deepseek-flash' );
$send = function () use ( $cfg ) {
	$r = BP_Providers::request_json( $cfg, 'sk-test-budget', 'Translate.', 'Input JSON: {"s1": "Hello"}' );
	$u = BP_Providers::last_usage();
	if ( null !== $u ) { BP_Usage::add( 'deepseek', 'deepseek-flash', 5, $u[0], $u[1] ); }
	return $r;
};
delete_option( BP_Usage::OPTION ); delete_option( BP_Usage::PRICES_OPTION ); delete_option( BP_Providers::ERROR_OPTION ); delete_option( BP_Alerts::STATE_OPTION );

delete_option( BP_Budget::OPTION );
ok( 'no limit: nothing stops', '' === BP_Budget::reached() && is_array( $send() ) && 1 === $GLOBALS['http'] );
ok( 'cost counted at the built-in price', abs( BP_Budget::spent()['day'] - 0.3 ) < 0.0001, (string) BP_Budget::spent()['day'] );

update_option( BP_Budget::OPTION, array( 'day' => 0.5, 'month' => 0 ), false );
ok( 'under the day limit: sent', is_array( $send() ) && 2 === $GLOBALS['http'] );
$r = $send();
ok( 'day limit reached: stopped before sending', is_wp_error( $r ) && 'bp_budget' === $r->get_error_code() && 2 === $GLOBALS['http'], is_wp_error( $r ) ? $r->get_error_message() : 'sent' );
ok( 'a run stops on it', in_array( 'bp_budget', BP_Run::FATAL, true ) );
$why = ( function () { $m = new ReflectionMethod( 'BP_Topup', 'reason' ); $m->setAccessible( true ); return (string) $m->invoke( null ); } )();
ok( 'the daily top-up waits', false !== strpos( $why, 'spending limit' ), $why );
$p = BP_Alerts::current();
ok( 'alert explains it', $p && false !== strpos( BP_Alerts::explain( $p )[0], 'spending limit' ) );

update_option( BP_Budget::OPTION, array( 'day' => 0, 'month' => 0.5 ), false );
ok( 'month limit applies too', false !== strpos( BP_Budget::reached(), 'per month' ) );

update_option( BP_Budget::OPTION, array( 'day' => 5, 'month' => 0 ), false );
ok( 'raised limit: sends again and clears the stop', is_array( $send() ) && 3 === $GLOBALS['http'] && null === BP_Providers::last_error() );

ob_start(); BP_Budget::render( array( 'api' => 'openai', 'model' => 'unknown-model-x' ) ); $html = ob_get_clean();
ok( 'card warns when the model has no price', false !== strpos( $html, 'No price is known for unknown-model-x' ) && false !== strpos( $html, 'name="budget_day"' ) );
ob_start(); BP_Budget::render( array( 'api' => 'deepl', 'model' => 'deepl' ) ); $html = ob_get_clean();
ok( 'card says DeepL is not covered', false !== strpos( $html, 'DeepL bills characters' ) );

echo "\n$pass passed, $fail failed\n";
