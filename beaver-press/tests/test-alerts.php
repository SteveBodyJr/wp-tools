<?php
// Beaver Press test: run from the command line only (see tests/README.md).
// Provider alerts: notice on every admin screen and one email per blocking problem.
if ( PHP_SAPI !== 'cli' ) { exit; }
require __DIR__ . '/bootstrap.php'; // WordPress + settings put back however the test ends.
$pass = 0; $fail = 0;
function ok( $n, $c, $x = '' ) { global $pass, $fail; $c ? $pass++ : $fail++; echo ( $c ? 'PASS ' : 'FAIL ' ), $n, $x ? "  [$x]" : '', "\n"; }
$GLOBALS['mails'] = array();
add_filter( 'pre_wp_mail', function ( $pre, $atts ) { $GLOBALS['mails'][] = $atts; return true; }, 10, 2 ); // Captured, never sent.
$admin = (int) get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0];
$hidden_before = get_user_meta( $admin, BP_Alerts::HIDDEN_META, true );
wp_set_current_user( $admin );
$err = fn( $code, $provider = 'deepseek' ) => array( 'time' => time(), 'provider' => $provider, 'model' => 'deepseek-chat', 'code' => $code, 'message' => 'Test message ' . $code );
$notice = function () { ob_start(); BP_Alerts::notice(); return ob_get_clean(); };

delete_option( BP_Providers::ERROR_OPTION ); delete_option( BP_Alerts::STATE_OPTION ); delete_user_meta( $admin, BP_Alerts::HIDDEN_META );
update_option( BP_Alerts::OPTION, 'yes', false );
ok( 'no problem, no notice', '' === $notice() );

update_option( BP_Providers::ERROR_OPTION, $err( 'bp_http_402' ), false );
ok( 'no credit: one email to the admin address', 1 === count( $GLOBALS['mails'] ) && get_option( 'admin_email' ) === $GLOBALS['mails'][0]['to'] && false !== strpos( $GLOBALS['mails'][0]['subject'], 'no credit' ) );
ok( 'email says what to do, no key in it', false !== strpos( $GLOBALS['mails'][0]['message'], 'Top up' ) && false !== strpos( $GLOBALS['mails'][0]['message'], 'platform.deepseek.com' ) );
$n = $notice();
ok( 'notice shown with the fix', false !== strpos( $n, 'no credit left' ) && false !== strpos( $n, 'trp_machine_translation' ) && false !== strpos( $n, 'Test message bp_http_402' ) );

update_option( BP_Providers::ERROR_OPTION, $err( 'bp_http_402' ) + array( 'time' => time() + 5 ), false );
ok( 'same problem again: no second email', 1 === count( $GLOBALS['mails'] ) );

update_option( BP_Providers::ERROR_OPTION, $err( 'bp_http_401' ), false );
ok( 'different problem: new email', 2 === count( $GLOBALS['mails'] ) && false !== strpos( $GLOBALS['mails'][1]['subject'], 'refused the API key' ) );

$p = BP_Alerts::current();
update_user_meta( $admin, BP_Alerts::HIDDEN_META, $p['sig'] . '@' . $p['since'] );
ok( 'hidden by the administrator', '' === $notice() );
delete_option( BP_Providers::ERROR_OPTION ); // A successful request.
ok( 'solved: state cleared, notice gone', false === get_option( BP_Alerts::STATE_OPTION ) && '' === $notice() );
sleep( 1 );
update_option( BP_Providers::ERROR_OPTION, $err( 'bp_http_401' ), false );
ok( 'comes back: emailed again and shown again', 3 === count( $GLOBALS['mails'] ) && '' !== $notice() );

delete_option( BP_Providers::ERROR_OPTION );
update_option( BP_Providers::ERROR_OPTION, $err( 'bp_http_429' ), false );
ok( 'rate limit: no email, no notice', 3 === count( $GLOBALS['mails'] ) && '' === $notice() );
delete_option( BP_Providers::ERROR_OPTION );

update_option( BP_Alerts::OPTION, 'no', false );
update_option( BP_Providers::ERROR_OPTION, $err( 'bp_no_key' ), false );
ok( 'email switched off: notice only', 3 === count( $GLOBALS['mails'] ) && false !== strpos( $notice(), 'no API key' ) );

wp_set_current_user( (int) ( get_users( array( 'role' => 'editor', 'number' => 1, 'fields' => 'ID' ) )[0] ?? 0 ) );
ok( 'not for non-administrators', '' === $notice() );
wp_set_current_user( $admin );

ok( 'switch in the settings file', 'no' === ( BP_Transfer::data()['switches']['provider_alert_email'] ?? '' ) );

'' === $hidden_before ? delete_user_meta( $admin, BP_Alerts::HIDDEN_META ) : update_user_meta( $admin, BP_Alerts::HIDDEN_META, $hidden_before );
echo "\n$pass passed, $fail failed\n";
