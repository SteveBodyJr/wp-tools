<?php
// Beaver Press test: run from the command line only (see tests/README.md).
if ( PHP_SAPI !== 'cli' ) { exit; }
$_SERVER['HTTP_HOST'] = getenv( 'BP_HOST' ) ?: 'localhost'; $_SERVER['REQUEST_URI'] = '/';
require __DIR__ . '/bootstrap.php'; // WordPress + settings put back however the test ends.
require_once ABSPATH . 'wp-admin/includes/user.php';
$pass = 0; $fail = 0;
function ok( $n, $c, $x = '' ) { global $pass, $fail; $c ? $pass++ : $fail++; echo ( $c ? 'PASS ' : 'FAIL ' ), $n, $x ? "  [$x]" : '', "\n"; }
// Never lose the owner's real keys or settings: snapshot, restore at the end.
$bp_saved = array(); foreach ( array( BP_Keys::OPTION, 'trp_machine_translation_settings', BP_Providers::ERROR_OPTION ) as $o ) { $bp_saved[ $o ] = get_option( $o, null ); }
$mt = array( 'translation-engine' => 'beaver_ai', 'machine-translation' => 'yes' );
$admin = (int) get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0];
$ed  = wp_insert_user( array( 'user_login' => 'bp_test_editor_' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'editor' ) );
$sub = wp_insert_user( array( 'user_login' => 'bp_test_sub_' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
$f = function () use ( &$mt ) { return apply_filters( "trp_machine_translator_is_available", true, array( "en_US", "fr_FR" ), $mt ); };

wp_set_current_user( 0 );   ok( 'anonymous visitor: blocked', false === $f() );
wp_set_current_user( $sub ); ok( 'subscriber: blocked', false === $f() );
// Browsing the site: editors and administrators do not start paid translations (fast pages).
wp_set_current_user( $ed );  ok( 'editor browsing: no translation', false === $f() );
wp_set_current_user( $admin ); ok( 'administrator browsing: no translation', false === $f() );
add_filter( 'beaver_press_translate_while_browsing', '__return_true' );
ok( 'administrator browsing, filter on: allowed', true === $f() );
remove_filter( 'beaver_press_translate_while_browsing', '__return_true' );
// In wp-admin (Try it, Prepare forms, drafts...): allowed.
require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
require_once ABSPATH . 'wp-admin/includes/screen.php';
$GLOBALS['current_screen'] = WP_Screen::get( 'settings_page_beaver-press' );
wp_set_current_user( $ed );  ok( 'editor in wp-admin: allowed', true === $f() );
wp_set_current_user( $admin ); ok( 'administrator in wp-admin: allowed', true === $f() );
$GLOBALS['current_screen'] = null;
wp_set_current_user( 0 );
$_SERVER['HTTP_X_BEAVER_PRESS_RUN'] = BP_Guard::run_token(); ok( 'signed run header: allowed', true === $f() );
$_SERVER['HTTP_X_BEAVER_PRESS_RUN'] = BP_Guard::run_token( (int) floor( time() / BP_Guard::WINDOW ) - 1 ); ok( 'previous window token: allowed', true === $f() );
$_SERVER['HTTP_X_BEAVER_PRESS_RUN'] = BP_Guard::run_token( (int) floor( time() / BP_Guard::WINDOW ) - 2 ); ok( 'expired token: blocked', false === $f() );
$_SERVER['HTTP_X_BEAVER_PRESS_RUN'] = 'forged'; ok( 'forged token: blocked', false === $f() );
unset( $_SERVER['HTTP_X_BEAVER_PRESS_RUN'] );
$mt['bp-visitor-guard'] = 'no'; ok( 'guard off: visitors allowed', true === $f() ); unset( $mt['bp-visitor-guard'] );
ok( 'other engine untouched', true === apply_filters( 'trp_machine_translator_is_available', true, array(), array( 'translation-engine' => 'google_translate_v2' ) ) );
ok( 'unavailable stays unavailable', false === apply_filters( 'trp_machine_translator_is_available', false, array(), $mt ) );

// Second line: engine itself refuses for a visitor, with no request.
BP_Keys::set( 'deepseek', 'guard-test-key' ); $calls = 0;
add_filter( 'pre_http_request', function () use ( &$calls ) { $calls++; return array( 'headers' => array(), 'body' => '{}', 'response' => array( 'code' => 500, 'message' => '' ), 'cookies' => array(), 'filename' => null ); } );
$s = TRP_Translate_Press::get_trp_instance()->get_component( 'settings' )->get_settings();
$s['trp_machine_translation_settings'] = $mt + array( 'bp-provider' => 'deepseek' );
$e = new BP_AI_Machine_Translator( $s );
ok( 'engine refuses visitor, no request', array() === $e->translate_array( array( 'Hi there' ), 'fr_FR', 'en_US' ) && 0 === $calls );
BP_Keys::delete( 'deepseek' );

// Panel checkbox + saving.
ob_start(); BP_Engine_Settings::render_panel( array( 'translation-engine' => 'beaver_ai' ) ); $h = ob_get_clean();
ok( 'panel: guard ticked by default', (bool) preg_match( '/id="bp-visitor-guard"[^>]*checked/', $h ) );
ok( 'unticked saves no', 'no' === BP_Engine_Settings::sanitize( array(), array( 'bp-visitor-guard' => 'no' ) )['bp-visitor-guard'] );
ok( 'ticked saves yes', 'yes' === BP_Engine_Settings::sanitize( array(), array( 'bp-visitor-guard' => 'yes' ) )['bp-visitor-guard'] );

wp_delete_user( $ed ); wp_delete_user( $sub );
foreach ( $bp_saved as $o => $v ) { null === $v ? delete_option( $o ) : update_option( $o, $v, false ); }
echo "\n$pass passed, $fail failed\n";
