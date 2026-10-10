<?php
// Beaver Press test: run from the command line only (see tests/README.md).
// 2.7.0 admin layout: tabs, switches, costs and limits, prefixes in use, profile card.
if ( PHP_SAPI !== 'cli' ) { exit; }
$_SERVER['HTTP_HOST'] = getenv( 'BP_HOST' ) ?: 'localhost'; $_SERVER['REQUEST_URI'] = '/';
require __DIR__ . '/bootstrap.php';
$pass = 0; $fail = 0;
function ok( $n, $c, $x = '' ) { global $pass, $fail; $c ? $pass++ : $fail++; echo ( $c ? 'PASS ' : 'FAIL ' ), $n, $x ? "  [$x]" : '', "\n"; }
function html_of( $class, $method, ...$args ) { $m = new ReflectionMethod( $class, $method ); $m->setAccessible( true ); ob_start(); $m->invoke( null, ...$args ); return (string) ob_get_clean(); }
wp_set_current_user( get_users( array( 'role' => 'administrator', 'number' => 1 ) )[0]->ID );
$budget_before = get_option( BP_Budget::OPTION, null );

// Tabs.
ok( 'tab addresses', false === strpos( BP_Admin::tab_url(), 'tab=' ) && str_ends_with( BP_Admin::tab_url( 'settings', 'bp-costs' ), '&tab=settings#bp-costs' ) );
$head = BP_Admin::header_html( 'settings' );
ok( 'six tabs, Settings active', 6 === substr_count( $head, '<a class="nav-tab' ) && 1 === preg_match( '/nav-tab-active" href="[^"]*tab=settings"/', $head ) && false !== strpos( $head, 'tab=tools' ) );

// 2.9.0 header: notices go below it, provider state shown, icons decorative.
ok( 'header: notice marker after the header row', false !== strpos( $head, '<hr class="wp-header-end">' ) && strpos( $head, 'wp-header-end' ) < strpos( $head, 'bp-tabs' ) && strpos( $head, 'wp-header-end' ) > strpos( $head, 'bp-title' ) );
ok( 'header: provider pill and Translate site button', 1 === preg_match( '/class="bp-pill[^"]*"[^>]*>[^<]+(connected|not tested|not working)</', $head ) && false !== strpos( $head, 'bp-head__go' ) );
ok( 'icons are hidden from screen readers', ! preg_match( '/<svg(?![^>]*aria-hidden="true")/', $head ) );

// Switches: one row each, the same field names as before, one Save.
$mt = get_option( 'trp_machine_translation_settings', array() );
$sw = html_of( 'BP_Admin', 'render_switches', is_array( $mt ) ? $mt : array() );
$names = array( 'auto', 'topup', 'alerts', 'cache', 'slugs', 'complete', 'suggest', 'redirect', 'quiet' );
ok( 'nine switch rows in three groups', 9 === substr_count( $sw, 'class="bp-switch"' ) && 3 === substr_count( $sw, 'bp-section-title' ) && 9 === substr_count( $sw, 'class="bp-toggle"' ) );
ok( 'field names unchanged', ! array_filter( $names, static fn( $n ) => false === strpos( $sw, 'name="' . $n . '" value="yes"' ) ) );
ok( 'one form, same action and nonce', 1 === substr_count( $sw, '<form' ) && false !== strpos( $sw, 'value="bp_quiet"' ) && false !== strpos( $sw, 'name="_wpnonce"' ) );
ok( 'ticks follow the settings', ( false !== strpos( $sw, 'name="auto" value="yes"  checked' ) ) === BP_Auto::enabled() && ( false !== strpos( $sw, 'name="redirect" value="yes"  checked' ) ) === BP_Suggest::redirect_enabled() );

// Costs and limits: one form with the price and the limits.
$engine = BP_Engine_Settings::current( is_array( $mt ) ? $mt : array() );
$co = html_of( 'BP_Admin', 'render_costs', $engine );
ok( 'costs: one form, one Save', 1 === substr_count( $co, '<form' ) && 1 === substr_count( $co, '<button' ) && false !== strpos( $co, 'value="bp_costs"' ) );
ok( 'costs: limit fields inside, price fields unless DeepL', false !== strpos( $co, 'name="budget_day"' ) && ( 'deepl' === $engine['api'] || false !== strpos( $co, 'name="price_in"' ) ) );
ob_start(); BP_Budget::render( $engine ); $own = ob_get_clean();
ok( 'limit fields still work as their own form', 1 === substr_count( $own, '<form' ) && false !== strpos( $own, 'value="bp_budget"' ) );
BP_Budget::store( 7.456, -3 );
ok( 'store(): rounded, never below 0', array( 'day' => 7.46, 'month' => 0.0 ) === BP_Budget::limits() );
update_option( BP_Providers::ERROR_OPTION, array( 'time' => time(), 'code' => 'bp_budget', 'message' => 'x' ), false );
BP_Budget::store( 0, 0 );
ok( 'store(): a lifted limit clears the stop', false === get_option( BP_Providers::ERROR_OPTION ) );

// Address prefixes: an internal type with nothing published is not offered.
register_post_type( 'bp_test_internal', array( 'public' => true, 'rewrite' => array( 'slug' => 'bp-test-internal' ) ) );
ok( 'prefix of an empty type not in use', ! isset( BP_Slug_Bases::in_use()['bp-test-internal'] ) && isset( BP_Slug_Bases::originals()['bp-test-internal'] ) );
$with = ''; foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $pt ) { if ( ! empty( $pt->rewrite['slug'] ) && (int) wp_count_posts( $pt->name )->publish > 0 ) { $with = trim( $pt->rewrite['slug'], '/' ); break; } }
ok( 'prefix with published content in use', '' !== $with && isset( BP_Slug_Bases::in_use()[ $with ] ), $with );

// 3.0.0: settings history in plain words; one-line credit.
ok( 'history in plain words, switches grouped', 'Switches (3), Spending limit, Model' === BP_History::describe( array( 'auto', 'topup', 'cache', 'budget', 'bp-model' ) ), BP_History::describe( array( 'auto', 'topup', 'cache', 'budget', 'bp-model' ) ) );
ok( 'history: two switches named, unknown engine keys generic', 'Daily top-up, Ready pages, Other engine setting' === BP_History::describe( array( 'topup', 'cache', 'some-new-key' ) ) );
ok( 'credit is one line', ( static function () { ob_start(); BP_Admin::render_credit(); $h = ob_get_clean(); return false !== strpos( $h, '<p class="bp-credit">' ) && false === strpos( $h, '<img' ); } )() );

null === $budget_before ? delete_option( BP_Budget::OPTION ) : update_option( BP_Budget::OPTION, $budget_before, false );
echo "\n$pass passed, $fail failed\n";
