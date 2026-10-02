<?php
// Beaver Press test: run from the command line only (see tests/README.md). Disposable copy only.
// Debug mode: administrators only, all fields shown, no key, problems detected, never cached.
if ( PHP_SAPI !== 'cli' ) { exit; }
require __DIR__ . '/bootstrap.php'; // WordPress + settings put back however the test ends.
$pass = 0; $fail = 0;
function ok( $n, $c, $x = '' ) { global $pass, $fail; $c ? $pass++ : $fail++; echo ( $c ? 'PASS ' : 'FAIL ' ), $n, $x ? "  [$x]" : '', "\n"; }
if ( 'yes' !== get_option( 'beaver_press_disposable_site' ) ) { echo "Skipped: not a disposable copy.\n\n0 passed, 0 failed\n"; exit; }

$cookie = function ( $user ) {
	$e = time() + 600;
	return LOGGED_IN_COOKIE . '=' . rawurlencode( wp_generate_auth_cookie( $user, $e, 'logged_in' ) );
};
$get = function ( $url, $cookie = '' ) {
	$h = curl_init( $url );
	curl_setopt_array( $h, array( CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90, CURLOPT_COOKIE => $cookie ) );
	$b = (string) curl_exec( $h ); curl_close( $h ); return $b;
};
$admin = (int) get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0];
$sub   = wp_insert_user( array( 'user_login' => 'bp_debug_sub_' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
register_shutdown_function( function () use ( $sub ) { require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user( $sub ); } );

$fr  = BP_Run::url_in( home_url( '/about/' ), 'fr_FR' );
$en  = home_url( '/about/' );
$a   = $get( $fr . '?bp_debug=1', $cookie( $admin ) );
ok( 'administrator: panel on a translated page', false !== strpos( $a, 'id="bp-debug"' ) );
foreach ( array( 'Source', 'Translation', 'ID', 'Status', 'Provenance', 'Provider / model', 'Source hash', 'Canonical', 'hreflang', 'Ready pages', 'Engine', 'Original address' ) as $label ) {
	ok( 'panel shows ' . $label, false !== strpos( $a, $label ) );
}
ok( 'panel is never translated', (bool) preg_match( '/id="bp-debug"[^>]*data-no-translation/', $a ) );
$keys = array_filter( array_map( array( 'BP_Keys', 'get' ), BP_Engine_Settings::PROVIDERS ) );
ok( 'no API key in the panel', ! array_filter( $keys, static fn( $k ) => false !== strpos( $a, $k ) ) && ( false !== strpos( $a, 'key saved' ) ) );
ok( 'older translations shown as legacy, provider unknown (not guessed)', false !== strpos( $a, 'legacy (provider unknown)' ) );
ok( 'administrator: panel on an original page', false !== strpos( $get( $en . '?bp_debug=1', $cookie( $admin ) ), 'id="bp-debug"' ) );
ok( 'visitor: no panel', false === strpos( $get( $fr . '?bp_debug=1' ), 'id="bp-debug"' ) );
ok( 'subscriber: no panel', false === strpos( $get( $fr . '?bp_debug=1', $cookie( $sub ) ), 'id="bp-debug"' ) );
ok( 'visitor: the plain page has no panel either', false === strpos( $get( $fr ), 'id="bp-debug"' ) );

// Problems are found (checked on made-up pages).
$h = '<html><head><link rel="canonical" href="http://x/a/" /><link rel="canonical" href="http://x/b/" /></head><body></body></html>';
list( , $p ) = BP_Debug::diagnose( $h, '' );
ok( 'two canonicals reported', (bool) preg_grep( '/Canonical: 2 tags/', $p ) );
ok( 'no x-default reported', (bool) preg_grep( '/no x-default/', $p ) );
ok( 'page not listing itself reported', (bool) preg_grep( '/does not list itself/', $p ) );
update_option( BP_Providers::ERROR_OPTION, array( 'time' => time(), 'provider' => 'deepseek', 'code' => 'bp_http_402', 'message' => 'Insufficient Balance' ), false );
list( , $p ) = BP_Debug::diagnose( $h, '' );
ok( 'provider failure reported', (bool) preg_grep( '/Provider failure .*bp_http_402/', $p ) );

echo "\n$pass passed, $fail failed\n";
