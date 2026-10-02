<?php
// Beaver Press test: run from the command line only (see tests/README.md).
if ( PHP_SAPI !== 'cli' ) { exit; }
$_SERVER['HTTP_HOST'] = getenv( 'BP_HOST' ) ?: 'localhost'; $_SERVER['REQUEST_URI'] = '/';
require __DIR__ . '/bootstrap.php'; // WordPress + settings put back however the test ends.
$pass = 0; $fail = 0;
function ok( $name, $cond, $extra = '' ) { global $pass, $fail; $cond ? $pass++ : $fail++; echo ( $cond ? 'PASS ' : 'FAIL ' ), $name, $extra ? "  [$extra]" : '', "\n"; }

// Fake endpoint: queue of responses; every request is recorded.
$GLOBALS['queue'] = array(); $GLOBALS['sent'] = array();
add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
	$GLOBALS['sent'][] = array( 'url' => $url, 'headers' => $args['headers'], 'body' => json_decode( $args['body'], true ) );
	$r = array_shift( $GLOBALS['queue'] ) ?: array( 500, array() );
	return array( 'headers' => $r[2] ?? array(), 'body' => wp_json_encode( $r[1] ), 'response' => array( 'code' => $r[0], 'message' => '' ), 'cookies' => array(), 'filename' => null );
}, 10, 3 );
function last() { return end( $GLOBALS['sent'] ); }
$schema = array( 'type' => 'object', 'properties' => array( 's1' => array( 'type' => 'string' ) ), 'required' => array( 's1' ), 'additionalProperties' => false );
$claude = array( 'provider' => 'claude' ) + BP_Providers::preset( 'claude' );

// 1. Claude Opus 5.5: structured output, effort, fallback header.
$GLOBALS['queue'][] = array( 200, array( 'stop_reason' => 'end_turn', 'content' => array( array( 'type' => 'thinking', 'thinking' => '' ), array( 'type' => 'text', 'text' => '{"s1":"Bonjour"}' ) ) ) );
$r = BP_Providers::request_json( $claude, 'sk-ant-test-1234', 'sys', 'user', $schema ); $q = last();
ok( 'claude parses JSON reply', is_array( $r ) && 'Bonjour' === ( $r['s1'] ?? '' ) );
ok( 'claude headers', 'sk-ant-test-1234' === $q['headers']['x-api-key'] && '2023-06-01' === $q['headers']['anthropic-version'] );
ok( 'claude json_schema', 'json_schema' === ( $q['body']['output_config']['format']['type'] ?? '' ) );
ok( 'claude effort low', 'low' === ( $q['body']['output_config']['effort'] ?? '' ) );
ok( 'claude fallback default + beta', 'default' === ( $q['body']['fallbacks'] ?? '' ) && 'server-side-fallback-2026-07-01' === ( $q['headers']['anthropic-beta'] ?? '' ) );
ok( 'claude no thinking/temperature', ! isset( $q['body']['thinking'] ) && ! isset( $q['body']['temperature'] ) );

// 2. Claude Haiku 4.5: no effort, no fallback.
$GLOBALS['queue'][] = array( 200, array( 'stop_reason' => 'end_turn', 'content' => array( array( 'type' => 'text', 'text' => '{"s1":"x"}' ) ) ) );
BP_Providers::request_json( array( 'model' => 'claude-haiku-4-5' ) + $claude, 'k-12345678', 's', 'u', $schema ); $q = last();
ok( 'haiku: no effort, no fallback', ! isset( $q['body']['output_config']['effort'] ) && ! isset( $q['body']['fallbacks'] ) && ! isset( $q['headers']['anthropic-beta'] ) );

// 3. Claude refusal.
$GLOBALS['queue'][] = array( 200, array( 'stop_reason' => 'refusal', 'content' => array() ) );
$r = BP_Providers::request_json( $claude, 'k-12345678', 's', 'u', $schema );
ok( 'claude refusal -> bp_refusal', is_wp_error( $r ) && 'bp_refusal' === $r->get_error_code() );

// 4. OpenAI-compatible (DeepSeek): bearer, json_object, fenced reply.
$ds = array( 'provider' => 'deepseek' ) + BP_Providers::preset( 'deepseek' );
$GLOBALS['queue'][] = array( 200, array( 'choices' => array( array( 'finish_reason' => 'stop', 'message' => array( 'content' => "```json\n{\"s1\":\"Salut\"}\n```" ) ) ) ) );
$r = BP_Providers::request_json( $ds, 'ds-key-abcdef', 'sys', 'user' ); $q = last();
ok( 'deepseek fenced JSON parsed', is_array( $r ) && 'Salut' === ( $r['s1'] ?? '' ) );
ok( 'deepseek bearer + json_object', 'Bearer ds-key-abcdef' === $q['headers']['authorization'] && 'json_object' === ( $q['body']['response_format']['type'] ?? '' ) && 'https://api.deepseek.com/chat/completions' === $q['url'] );

// 5. Custom server that rejects response_format -> retried without it.
$custom = array( 'provider' => 'custom', 'api' => 'openai', 'url' => 'http://127.0.0.1:11434/v1/chat/completions', 'model' => 'llama3' );
$GLOBALS['queue'][] = array( 400, array( 'error' => array( 'message' => 'Unknown parameter: response_format' ) ) );
$GLOBALS['queue'][] = array( 200, array( 'choices' => array( array( 'finish_reason' => 'stop', 'message' => array( 'content' => '{"s1":"ok"}' ) ) ) ) );
$n = count( $GLOBALS['sent'] ); $r = BP_Providers::request_json( $custom, 'x-12345678', 's', 'u' ); $q = last();
ok( 'custom: retry without response_format', is_array( $r ) && 2 === count( $GLOBALS['sent'] ) - $n && ! isset( $q['body']['response_format'] ) );

// 6. Gemini: model in URL, header key, parse.
$gm = array( 'provider' => 'gemini', 'model' => 'gemini-test-model' ) + BP_Providers::preset( 'gemini' );
$GLOBALS['queue'][] = array( 200, array( 'candidates' => array( array( 'finishReason' => 'STOP', 'content' => array( 'parts' => array( array( 'text' => '{"s1":"Hallo"}' ) ) ) ) ) ) );
$r = BP_Providers::request_json( $gm, 'AIza-test-9999', 'sys', 'user' ); $q = last();
ok( 'gemini parsed', is_array( $r ) && 'Hallo' === ( $r['s1'] ?? '' ) );
ok( 'gemini url + header + json mime', false !== strpos( $q['url'], '/models/gemini-test-model:generateContent' ) && 'AIza-test-9999' === $q['headers']['x-goog-api-key'] && 'application/json' === $q['body']['generationConfig']['responseMimeType'] );
$GLOBALS['queue'][] = array( 200, array( 'candidates' => array( array( 'finishReason' => 'SAFETY', 'content' => array( 'parts' => array() ) ) ) ) );
$r = BP_Providers::request_json( $gm, 'AIza-test-9999', 's', 'u' );
ok( 'gemini safety -> bp_refusal', is_wp_error( $r ) && 'bp_refusal' === $r->get_error_code() );

// 7. 401 with provider message; last error saved without the key.
$GLOBALS['queue'][] = array( 401, array( 'type' => 'error', 'error' => array( 'type' => 'authentication_error', 'message' => 'invalid x-api-key' ) ) );
$r = BP_Providers::request_json( $claude, 'sk-secret-SHOULD-NOT-LEAK', 's', 'u', $schema ); $e = BP_Providers::last_error();
ok( '401 message', is_wp_error( $r ) && 'bp_http_401' === $r->get_error_code() && false !== strpos( $r->get_error_message(), 'invalid x-api-key' ), is_wp_error( $r ) ? $r->get_error_message() : '' );
ok( 'last error saved, no key in it', is_array( $e ) && false === strpos( wp_json_encode( $e ), 'SHOULD-NOT-LEAK' ) );

// 8. 429 then 200 -> one retry.
$GLOBALS['queue'][] = array( 429, array( 'error' => array( 'message' => 'slow down' ) ), array( 'retry-after' => '1' ) );
$GLOBALS['queue'][] = array( 200, array( 'stop_reason' => 'end_turn', 'content' => array( array( 'type' => 'text', 'text' => '{"s1":"retry ok"}' ) ) ) );
$r = BP_Providers::request_json( $claude, 'k-12345678', 's', 'u', $schema );
ok( '429 retried once', is_array( $r ) && 'retry ok' === ( $r['s1'] ?? '' ) );
ok( 'success clears last error', null === BP_Providers::last_error() );

// 9. Bad JSON and missing model.
$GLOBALS['queue'][] = array( 200, array( 'choices' => array( array( 'finish_reason' => 'stop', 'message' => array( 'content' => 'Voici la traduction' ) ) ) ) );
$r = BP_Providers::request_json( $ds, 'k-12345678', 's', 'u' );
ok( 'non-JSON reply -> bp_bad_json', is_wp_error( $r ) && 'bp_bad_json' === $r->get_error_code() );
$n = count( $GLOBALS['sent'] ); $r = BP_Providers::request_json( array( 'model' => '' ) + $ds, 'k-12345678', 's', 'u' );
ok( 'no model -> no request sent', is_wp_error( $r ) && 'bp_no_model' === $r->get_error_code() && count( $GLOBALS['sent'] ) === $n );

// 10. Key store.
BP_Keys::set( 'bp_test', 'sk-plain-KEY-7788' );
$raw = get_option( BP_Keys::OPTION );
ok( 'key readable', 'sk-plain-KEY-7788' === BP_Keys::get( 'bp_test' ) );
ok( 'key encrypted at rest', false === strpos( wp_json_encode( $raw ), 'KEY-7788' ) && false === strpos( wp_json_encode( $raw ), 'sk-plain' ) );
ok( 'hint + source', '7788' === BP_Keys::hint( 'bp_test' ) && 'saved' === BP_Keys::source( 'bp_test' ) );
ok( 'blank keeps saved key', false === BP_Keys::set( 'bp_test', '  ' ) && 'sk-plain-KEY-7788' === BP_Keys::get( 'bp_test' ) );
ok( 'option not autoloaded', 'off' === $GLOBALS['wpdb']->get_var( "SELECT autoload FROM {$GLOBALS['wpdb']->options} WHERE option_name='" . BP_Keys::OPTION . "'" ) || 'no' === $GLOBALS['wpdb']->get_var( "SELECT autoload FROM {$GLOBALS['wpdb']->options} WHERE option_name='" . BP_Keys::OPTION . "'" ) );
define( 'BEAVER_PRESS_BP_TEST_API_KEY', 'from-constant-0000' );
ok( 'constant overrides saved key', 'from-constant-0000' === BP_Keys::get( 'bp_test' ) && 'constant' === BP_Keys::source( 'bp_test' ) );
BP_Keys::delete( 'bp_test' ); delete_option( BP_Providers::ERROR_OPTION );
$left = get_option( BP_Keys::OPTION );
ok( 'test key removed', empty( $left['bp_test'] ) );
echo "\n$pass passed, $fail failed\n";
