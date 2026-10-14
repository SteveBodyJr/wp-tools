<?php
// Beaver Press test: run from the command line only (see tests/README.md).
// 3.1.0: hosting check (reach itself, cron, time limit), the server-address fallback, and the
// languages suggested for a safari site. Opens only the page-not-found address: nothing is translated.
if ( PHP_SAPI !== 'cli' ) { exit; }
$_SERVER['HTTP_HOST'] = getenv( 'BP_HOST' ) ?: 'localhost'; $_SERVER['REQUEST_URI'] = '/';
require __DIR__ . '/bootstrap.php'; // WordPress + settings put back however the test ends.
$pass = 0; $fail = 0;
function ok( $n, $c, $x = '' ) { global $pass, $fail; $c ? $pass++ : $fail++; echo ( $c ? 'PASS ' : 'FAIL ' ), $n, $x ? "  [$x]" : '', "\n"; }
$host_before = get_option( BP_Hosting::OPTION, null );
$ip_before   = get_option( BP_Hosting::IP_OPTION, null );

// The guard answers only a signed request.
$url    = home_url( '/' . BP_Run::MISSING_PATH . '/?bp_t=' . time() );
$signed = wp_remote_get( $url, array( 'timeout' => 20, 'sslverify' => false, 'redirection' => 0, 'headers' => array( BP_Guard::HEADER => BP_Guard::run_token() ) ) );
$plain  = wp_remote_get( $url, array( 'timeout' => 20, 'sslverify' => false, 'redirection' => 0 ) );
ok( 'signed request: the site says it saw the run', 'ok' === wp_remote_retrieve_header( $signed, 'x-beaver-press-run' ), is_wp_error( $signed ) ? $signed->get_error_message() : '' );
ok( 'visitor request: no such header', '' === wp_remote_retrieve_header( $plain, 'x-beaver-press-run' ) );

// The check on this site: it reaches itself.
delete_option( BP_Hosting::IP_OPTION );
$r = BP_Hosting::check();
ok( 'check: site reaches itself', in_array( $r['reach'], array( 'public', 'local' ), true ) && $r['seen'], wp_json_encode( $r ) );
ok( 'check: result kept', BP_Hosting::last() && BP_Hosting::last()['time'] >= time() - 60 );
ok( 'health lines: reach and cron', count( BP_Hosting::checks() ) >= 2 );

// Public address blocked: the server's own address is tried and kept.
$block = function ( $pre, $args, $u ) {
	return ( false !== strpos( $u, 'bp_hosting=' ) && '' === ( $args['bp_ip'] ?? '' ) ) ? new WP_Error( 'http_request_failed', 'cURL error 28: Connection timed out' ) : $pre;
};
add_filter( 'pre_http_request', $block, 10, 3 );
$r = BP_Hosting::check();
remove_filter( 'pre_http_request', $block, 10 );
ok( 'public blocked: reached through the server address', 'local' === $r['reach'] && '127.0.0.1' === $r['ip'] && '127.0.0.1' === BP_Hosting::ip(), wp_json_encode( $r ) );
$marked = BP_Hosting::args( array() );
ok( 'requests to itself are marked for the fallback', ! empty( $marked[ BP_Hosting::ARG ] ) );

// Nothing reaches: a red line, and a run does not start.
$none = function ( $pre, $args, $u ) { return false !== strpos( $u, 'bp_hosting=' ) ? new WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect' ) : $pre; };
add_filter( 'pre_http_request', $none, 10, 3 );
$r = BP_Hosting::check();
$lines = BP_Hosting::checks();
ok( 'unreachable: reported with the reason', 'none' === $r['reach'] && false !== strpos( $lines[0][1], 'Failed to connect' ) && false === $lines[0][0] );
if ( '' === BP_Run::blocker() ) {
	$state_before = get_option( BP_Run::STATE_OPTION, null );
	$s = BP_Run::start( array_keys( BP_Run::languages() ) );
	ok( 'unreachable: Translate site refuses to start', is_wp_error( $s ) && 'bp_run_unreachable' === $s->get_error_code(), is_wp_error( $s ) ? $s->get_error_code() : 'started' );
	null === $state_before ? delete_option( BP_Run::STATE_OPTION ) : update_option( BP_Run::STATE_OPTION, $state_before, false );
}
remove_filter( 'pre_http_request', $none, 10 );

// Cron lateness is measured from the oldest task due.
$c = BP_Hosting::cron();
ok( 'cron: lateness and switch read', is_int( $c['late'] ) && is_bool( $c['disabled'] ) );

// Languages suggested for a safari site: the big markets first, never Swahili, only those missing.
ok( 'safari profile suggests de, fr, es, it', array( 'de_DE', 'fr_FR', 'es_ES', 'it_IT' ) === BP_Profiles::get( 'languages' ) );
$all = array_merge( (array) BP_Profiles::get( 'languages' ), (array) BP_Profiles::get( 'languages_more' ) );
ok( 'Swahili never suggested', ! array_filter( $all, static fn( $l ) => 0 === strpos( $l, 'sw' ) ) );
$m = new ReflectionMethod( 'BP_Admin', 'render_language_suggestions' ); $m->setAccessible( true );
ob_start(); $m->invoke( null, array( 'fr_FR' => 'French' ) ); $html = ob_get_clean();
ok( 'suggestions leave out a language the site has', false !== strpos( $html, 'German' ) && false === strpos( $html, 'French' ), wp_strip_all_tags( $html ) );
ob_start(); $m->invoke( null, array_fill_keys( $all, 'x' ) ); $html = ob_get_clean();
ok( 'nothing shown when every suggestion is there', '' === trim( $html ) );

// Put back.
null === $host_before ? delete_option( BP_Hosting::OPTION ) : update_option( BP_Hosting::OPTION, $host_before, false );
null === $ip_before ? delete_option( BP_Hosting::IP_OPTION ) : update_option( BP_Hosting::IP_OPTION, $ip_before, true );
echo "\n$pass passed, $fail failed\n";
