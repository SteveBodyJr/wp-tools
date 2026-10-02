<?php
// Beaver Press test: run from the command line only (see tests/README.md).
if ( PHP_SAPI !== 'cli' ) { exit; }
$_SERVER['HTTP_HOST'] = getenv( 'BP_HOST' ) ?: 'localhost'; $_SERVER['REQUEST_URI'] = '/';
require __DIR__ . '/bootstrap.php'; // WordPress + settings put back however the test ends.
$pass = 0; $fail = 0;
function ok( $n, $c, $x = '' ) { global $pass, $fail; $c ? $pass++ : $fail++; echo ( $c ? 'PASS ' : 'FAIL ' ), $n, $x ? "  [$x]" : '', "\n"; }
// Daily top-up. Nothing is fetched or sent: the provider error and the switch are set for a moment.
$bp_saved = array(); foreach ( array( BP_Topup::OPTION, BP_Topup::STATE_OPTION, BP_Providers::ERROR_OPTION, 'trp_plugin_optin' ) as $o ) { $bp_saved[ $o ] = get_option( $o, null ); }

ok( 'on by default', BP_Topup::enabled() );
ok( 'daily event scheduled', (bool) wp_next_scheduled( BP_Topup::CRON ) );
ok( 'incomplete list is language + address pairs', is_array( BP_Topup::incomplete() ) && ( ! BP_Topup::incomplete() || 2 === count( BP_Topup::incomplete()[0] ) ) );
ok( '402 (no credit) stops a run', in_array( 'bp_http_402', BP_Run::FATAL, true ) );

$mt = get_option( 'trp_machine_translation_settings', array() );
update_option( BP_Providers::ERROR_OPTION, array( 'time' => time(), 'provider' => BP_Engine_Settings::current( is_array( $mt ) ? $mt : array() )['provider'], 'code' => 'bp_http_402', 'message' => 'Test: no credit.' ), false );
$v0 = (int) BP_Topup::state()['visited']; // Today's real run may already have visited pages.
$s = BP_Topup::tick( 1 );
ok( 'provider refusal: nothing visited', $v0 === $s['visited'] && false !== strpos( $s['reason'], 'Test: no credit.' ), $s['reason'] );
update_option( BP_Providers::ERROR_OPTION, array( 'time' => time() - 7 * HOUR_IN_SECONDS, 'provider' => '', 'code' => 'bp_http_402', 'message' => 'old' ), false );
update_option( BP_Topup::OPTION, 'no', false );
$s = BP_Topup::tick( 1 );
ok( 'switched off: nothing visited', $v0 === $s['visited'] && '' !== $s['reason'] );
BP_Topup::schedule();
ok( 'switched off: no daily event', ! wp_next_scheduled( BP_Topup::CRON ) );

ok( 'engine data opt-in always no', 'no' === get_option( 'trp_plugin_optin' ) );
$adv = apply_filters( 'trp_register_advanced_settings', array( array( 'name' => 'plugin_optin_setting' ), array( 'name' => 'other' ) ) );
ok( 'engine "Marketing optin" setting removed', ! in_array( 'plugin_optin_setting', wp_list_pluck( $adv, 'name' ), true ) );

foreach ( $bp_saved as $o => $v ) { null === $v ? delete_option( $o ) : update_option( $o, $v, false ); }
wp_clear_scheduled_hook( BP_Topup::MORE_CRON );
BP_Topup::schedule();
ok( 'restored: daily event back', (bool) wp_next_scheduled( BP_Topup::CRON ) );
echo "\n$pass passed, $fail failed\n";
