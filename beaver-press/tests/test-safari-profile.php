<?php
// Beaver Press test: run from the command line only (see tests/README.md).
// The Safari & Tourism translation profile: a fresh site uses it; the AI is given the tourism
// context, terminology and protections; no customer is named except the site's own name; a
// site's saved instruction and names rule still win.
if ( PHP_SAPI !== 'cli' ) { exit; }
require __DIR__ . '/bootstrap.php'; // WordPress + settings put back however the test ends.
$pass = 0; $fail = 0;
function ok( $n, $c, $x = '' ) { global $pass, $fail; $c ? $pass++ : $fail++; echo ( $c ? 'PASS ' : 'FAIL ' ), $n, $x ? "  [$x]" : '', "\n"; }

// As on a fresh site: nothing chosen or saved, another site name, only that name to keep.
delete_option( BP_Profiles::OPTION );
delete_option( BP_Instructions::OPTION );
BP_Names_AI::store( array( 'rule' => '' ) );
add_filter( 'pre_option_blogname', static fn() => 'Example Lodge Co' ); // In this process only.
add_filter( 'beaver_press_glossary', static fn() => array( 'Example Lodge Co' ), 999 );

ok( 'fresh site uses the Safari & Tourism profile', 'safari' === BP_Profiles::current_id() && 'Safari & Tourism' === BP_Profiles::current()['label'] );
$g = BP_Instructions::get();
ok( 'site instruction: tourism context with the site\'s own name', ! $g['saved'] && 0 === strpos( $g['site'], 'Example Lodge Co is a safari and tourism business' ) );
$prompt = BP_Instructions::full_prompt( 'fr_FR' );
ok( 'prompt: safari and tourism context', false !== strpos( $prompt, 'safari and tourism business' ) && false !== strpos( $prompt, 'East Africa' ) );
ok( 'prompt: tourism style for travellers', false !== strpos( $prompt, 'professional tourism French for international travellers' ) );
ok( 'prompt: terminology, only where it appears', false !== strpos( $prompt, 'game drive' ) && false !== strpos( $prompt, 'tented camp' ) && false !== strpos( $prompt, 'never add them' ) );
ok( 'prompt: places keep their names, none added', false !== strpos( $prompt, 'Serengeti' ) && false !== strpos( $prompt, 'never add a place that is not in the text' ) );
foreach ( array( 'HTML tag' => 'HTML', 'placeholders' => 'placeholders', 'prices, currencies, dates' => 'prices, currencies, dates', 'URLs, email addresses and phone numbers' => 'URLs, emails, phones', 'shortcodes' => 'shortcodes', 'package or booking codes' => 'package codes', 'lodge, camp and hotel names' => 'lodge/camp/hotel names', 'brand names' => 'brand names', 'Never invent prices' => 'no invented facts' ) as $needle => $label ) {
	ok( 'prompt protects ' . $label, false !== strpos( $prompt, $needle ) );
}
ok( 'no customer named but the site itself', false === stripos( $prompt, 'Raya' ) && false === stripos( BP_Names_AI::rule(), 'Raya' ) && false === stripos( $g['site'], 'Raya' ) );
ok( 'names rule: safari and tourism', 0 === strpos( BP_Names_AI::rule(), 'You are sorting names from a safari and tourism website' ) );
ok( 'per-language suggestion from the profile', false !== strpos( BP_Instructions::suggestion( 'es_ES' ), 'travel websites' ) && false !== strpos( BP_Instructions::suggestion( 'sw' ), 'East African tourism' ) );
ok( 'profile card shown', ( function () { ob_start(); BP_Profiles::render(); $h = ob_get_clean(); return false !== strpos( $h, 'Safari &amp; Tourism' ) && false !== strpos( $h, 'id="bp-profile"' ); } )() );

// A site's own saved instruction and names rule win (as on Raya).
$own = 'Our own words for our own company.';
update_option( BP_Instructions::OPTION, array( 'site' => $own, 'langs' => array( 'fr_FR' => 'Vouvoyer.' ) ), false );
BP_Names_AI::store( array( 'rule' => 'Keep every name.' ) );
ok( 'saved instruction wins', $own === BP_Instructions::get()['site'] && false !== strpos( BP_Instructions::full_prompt( 'fr_FR' ), $own ) );
ok( 'saved names rule wins', 'Keep every name.' === BP_Names_AI::rule() );
// Another profile can be added without code changes elsewhere.
add_filter( 'beaver_press_profiles', static function ( $p ) { $p['test'] = array( 'label' => 'Test', 'context' => 'TEST CONTEXT.', 'site' => '%s test.' ); return $p; } );
update_option( BP_Profiles::OPTION, 'test', false );
ok( 'profiles are extensible (filter)', 0 === strpos( BP_Batch::system_prompt( 'English', 'French', array() ), 'TEST CONTEXT.' ) );
update_option( BP_Profiles::OPTION, 'nonexistent', false );
ok( 'unknown profile falls back to Safari & Tourism', 'safari' === BP_Profiles::current_id() );
echo "\n$pass passed, $fail failed\n";
