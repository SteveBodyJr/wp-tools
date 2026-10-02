<?php
// Beaver Press test: run from the command line only (see tests/README.md).
if ( PHP_SAPI !== 'cli' ) { exit; }
$_SERVER['HTTP_HOST'] = getenv( 'BP_HOST' ) ?: 'localhost'; $_SERVER['REQUEST_URI'] = '/';
require __DIR__ . '/bootstrap.php'; // WordPress + settings put back however the test ends.
$pass = 0; $fail = 0;
function ok( $n, $c, $x = '' ) { global $pass, $fail; $c ? $pass++ : $fail++; echo ( $c ? 'PASS ' : 'FAIL ' ), $n, $x ? "  [$x]" : '', "\n"; }
wp_set_current_user( (int) get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0] );
$mt_before = get_option( 'trp_machine_translation_settings', null );
$keys_before = get_option( BP_Keys::OPTION, null );

// Registration.
$classes = apply_filters( 'trp_automatic_translation_engines_classes', array() );
ok( 'engine class registered', ( $classes['beaver_ai'] ?? '' ) === 'BP_AI_Machine_Translator' && class_exists( 'BP_AI_Machine_Translator' ) );
$engines = apply_filters( 'trp_machine_translation_engines', array() );
ok( 'engine in dropdown', in_array( 'beaver_ai', wp_list_pluck( $engines, 'value' ), true ) );

// Engine instance with TranslatePress settings.
$trp = TRP_Translate_Press::get_trp_instance();
$settings = $trp->get_component( 'settings' )->get_settings();
$settings['trp_machine_translation_settings'] = array( 'translation-engine' => 'beaver_ai', 'machine-translation' => 'no', 'bp-provider' => 'deepseek', 'bp-chunk-size' => 12 );
$e = new BP_AI_Machine_Translator( $settings );
ok( 'chunk size from settings', 12 === $e->get_chunk_size() );
$sup = $e->get_supported_languages(); $need = $e->get_engine_specific_language_codes( array( 'fr_FR', 'de_DE', 'sw' ) );
ok( 'all languages supported, no request', ! array_diff( $need, $sup ), implode( ',', $need ) );
// Never reach a real provider from a test: block HTTP and count attempts.
$b5_calls = 0; $b5_block = function ( $pre, $args, $url ) use ( &$b5_calls ) { $b5_calls++; return new WP_Error( 'b5_offline', 'offline test' ); };
add_filter( 'pre_http_request', $b5_block, 1, 3 );
wp_set_current_user( 0 ); // A visitor: the guard must stop the call before any request.
ok( 'visitor: guard sends nothing', array() === $e->translate_array( array( 'Hello' ), 'fr_FR', 'en_US' ) && 0 === $b5_calls );
wp_set_current_user( (int) get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0] );
remove_filter( 'pre_http_request', $b5_block, 1 );
$nk = $settings; $nk['trp_machine_translation_settings']['bp-provider'] = 'gemini'; // A provider with no saved key.
ok( 'no key -> validity error', '' !== BP_Keys::get( 'gemini' ) || true === ( new BP_AI_Machine_Translator( $nk ) )->check_api_key_validity()['error'] );

// Panel HTML never contains a key.
BP_Keys::set( 'claude', 'sk-ant-SECRET-abcd1234' );
ob_start(); BP_Engine_Settings::render_panel( array( 'translation-engine' => 'beaver_ai', 'bp-provider' => 'claude' ) ); $html = ob_get_clean();
ok( 'panel rendered with id beaver_ai', false !== strpos( $html, 'id="beaver_ai"' ) );
ok( 'six providers incl DeepL', 6 === substr_count( $html, 'class="trp-settings-options-item bp-row bp-key-row"' ) && false !== strpos( $html, '>DeepL<' ) );
ok( 'key never in HTML', false === strpos( $html, 'SECRET' ) && false === strpos( $html, 'sk-ant' ) );
ok( 'hint shown', false !== strpos( $html, 'ending ...1234' ) );
ob_start(); BP_Engine_Settings::render_panel( array( 'translation-engine' => 'beaver_ai', 'bp-provider' => 'deepl' ) ); $h2 = ob_get_clean();
ok( 'deepl: model row hidden', (bool) preg_match( '/bp-not-deepl" style="display:none"/', $h2 ) );

// Saving through TranslatePress's own sanitize (as options.php would).
$_POST = array( 'option_page' => 'trp_machine_translation_settings', 'bp_api_key' => array( 'deepseek' => ' ds-NEW-key-5678 ', 'claude' => '' ), 'bp_api_key_remove' => array() );
$tab = $trp->get_component( 'machine_translation_tab' );
$saved = $tab->sanitize_settings( array( 'machine-translation' => 'no', 'translation-engine' => 'beaver_ai', 'bp-provider' => 'deepseek', 'bp-model' => ' deepseek-chat ', 'bp-chunk-size' => '99', 'bp-effort' => 'bogus', 'bp-endpoint' => 'javascript:alert(1)' ) );
ok( 'bp fields kept through core sanitize', 'deepseek' === $saved['bp-provider'] && 'deepseek-chat' === $saved['bp-model'] && 'beaver_ai' === $saved['translation-engine'] );
ok( 'values clamped/cleaned', 50 === $saved['bp-chunk-size'] && 'low' === $saved['bp-effort'] && '' === $saved['bp-endpoint'] );
ok( 'typed key saved (trimmed), blank keeps old', 'ds-NEW-key-5678' === BP_Keys::get( 'deepseek' ) && 'sk-ant-SECRET-abcd1234' === BP_Keys::get( 'claude' ) );
ok( 'key not in TranslatePress settings', false === strpos( wp_json_encode( $saved ), 'NEW-key' ) );
$_POST = array( 'option_page' => 'trp_machine_translation_settings', 'bp_api_key_remove' => array( 'deepseek' => '1' ) );
$tab->sanitize_settings( array( 'translation-engine' => 'beaver_ai', 'bp-provider' => 'deepseek' ) );
ok( 'remove box deletes key', '' === BP_Keys::get( 'deepseek' ) );
$_POST = array( 'bp_api_key' => array( 'openai' => 'sneaky' ) );
$tab->sanitize_settings( array( 'translation-engine' => 'beaver_ai' ) );
ok( 'keys ignored outside the settings form', '' === BP_Keys::get( 'openai' ) );
$_POST = array();

// Test connection + cache, fake provider.
$GLOBALS['q'] = array();
add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
	$r = array_shift( $GLOBALS['q'] ) ?: array( 500, array() );
	$GLOBALS['last_url'] = $url; $GLOBALS['last_body'] = $args['body'] ?? '';
	return array( 'headers' => array(), 'body' => wp_json_encode( $r[1] ), 'response' => array( 'code' => $r[0], 'message' => '' ), 'cookies' => array(), 'filename' => null );
}, 10, 3 );
$claude = BP_Engine_Settings::current( array( 'bp-provider' => 'claude' ) );
$GLOBALS['q'][] = array( 200, array( 'stop_reason' => 'end_turn', 'content' => array( array( 'type' => 'text', 'text' => '{"s1":"Bonjour"}' ) ) ) );
$r = BP_Engine_Settings::run_test( $claude, BP_Keys::get( 'claude' ) );
ok( 'claude test ok', $r['ok'] && false !== strpos( $r['message'], 'Bonjour' ), $r['message'] );
$m = new ReflectionMethod( 'BP_Engine_Settings', 'remember_test' ); $m->setAccessible( true ); $m->invoke( null, $claude, BP_Keys::get( 'claude' ), $r );
ok( 'cached for same settings', is_array( BP_Engine_Settings::cached_test( $claude ) ) );
ok( 'not reused after model change', null === BP_Engine_Settings::cached_test( BP_Engine_Settings::current( array( 'bp-provider' => 'claude', 'bp-model' => 'claude-haiku-4-5' ) ) ) );
$settings['trp_machine_translation_settings'] = array( 'translation-engine' => 'beaver_ai', 'bp-provider' => 'claude' );
$e2 = new BP_AI_Machine_Translator( $settings ); $n = count( $GLOBALS['q'] );
ok( 'validity uses cache, no request', false === $e2->check_api_key_validity()['error'] && count( $GLOBALS['q'] ) === $n );

// DeepL.
BP_Keys::set( 'deepl', 'abc-123-free:fx' );
$GLOBALS['q'][] = array( 200, array( 'translations' => array( array( 'detected_source_language' => 'EN', 'text' => 'Bonjour' ) ) ) );
$GLOBALS['q'][] = array( 200, array( 'character_count' => 1200, 'character_limit' => 500000 ) );
$r = BP_Engine_Settings::run_test( BP_Engine_Settings::current( array( 'bp-provider' => 'deepl' ) ), BP_Keys::get( 'deepl' ) );
ok( 'deepl test ok + usage', $r['ok'] && false !== strpos( $r['message'], 'API Free' ) && false !== strpos( $r['message'], '500,000' ), $r['message'] );
$GLOBALS['q'][] = array( 200, array( 'translations' => array( array( 'text' => 'Guten Tag' ) ) ) );
BP_Providers::deepl_translate( array( 'Good <b>day</b>' ), 'en_US', 'pt_BR', 'pro-key' ); $b = json_decode( $GLOBALS['last_body'], true );
ok( 'deepl pro host, regional code, html tags', 0 === strpos( $GLOBALS['last_url'], 'https://api.deepl.com/v2/translate' ) && 'PT-BR' === $b['target_lang'] && 'EN' === $b['source_lang'] && 'html' === $b['tag_handling'] );
$GLOBALS['q'][] = array( 456, array( 'message' => 'Quota exceeded' ) );
$r = BP_Providers::deepl_translate( array( 'x' ), 'en_US', 'fr_FR', 'k:fx' );
ok( 'deepl 456 -> quota message', is_wp_error( $r ) && false !== stripos( $r->get_error_message(), 'quota' ) );

// Model lists.
$GLOBALS['q'][] = array( 200, array( 'data' => array( array( 'id' => 'gpt-x-mini' ), array( 'id' => 'text-embedding-3' ), array( 'id' => 'dall-e-3' ), array( 'id' => 'gpt-x' ) ) ) );
$r = BP_Providers::list_models( 'openai', 'k', '' );
ok( 'openai list filtered to chat models', array( 'gpt-x-mini', 'gpt-x' ) === $r, is_array( $r ) ? implode( ',', $r ) : $r->get_error_message() );
$GLOBALS['q'][] = array( 200, array( 'models' => array( array( 'name' => 'models/gemini-a', 'supportedGenerationMethods' => array( 'generateContent' ) ), array( 'name' => 'models/embed-b', 'supportedGenerationMethods' => array( 'embedContent' ) ) ) ) );
$r = BP_Providers::list_models( 'gemini', 'k', '' );
ok( 'gemini list: generateContent only', array( 'gemini-a' ) === $r );
$GLOBALS['q'][] = array( 200, array( 'data' => array( array( 'id' => 'local-llm' ) ) ) );
$r = BP_Providers::list_models( 'custom', 'k', 'http://127.0.0.1:11434/v1/chat/completions' );
ok( 'custom: /models derived from endpoint', array( 'local-llm' ) === $r && 'http://127.0.0.1:11434/v1/models' === $GLOBALS['last_url'] );

// Clean up: restore options exactly.
if ( null === $keys_before ) { delete_option( BP_Keys::OPTION ); } else { update_option( BP_Keys::OPTION, $keys_before, false ); }
if ( null === $mt_before ) { delete_option( 'trp_machine_translation_settings' ); } else { update_option( 'trp_machine_translation_settings', $mt_before ); }
delete_option( BP_Engine_Settings::TEST_OPTION ); delete_option( BP_Providers::ERROR_OPTION );
echo "\n$pass passed, $fail failed\n";
