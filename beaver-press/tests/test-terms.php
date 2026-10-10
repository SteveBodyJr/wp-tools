<?php
// Beaver Press test: run from the command line only (see tests/README.md).
// 2.8.0: terms per language and search words in the request; service pages in the run.
if ( PHP_SAPI !== 'cli' ) { exit; }
$_SERVER['HTTP_HOST'] = getenv( 'BP_HOST' ) ?: 'localhost'; $_SERVER['REQUEST_URI'] = '/';
require __DIR__ . '/bootstrap.php'; // Puts beaver_press_terms back however the test ends.
$pass = 0; $fail = 0;
function ok( $n, $c, $x = '' ) { global $pass, $fail; $c ? $pass++ : $fail++; echo ( $c ? 'PASS ' : 'FAIL ' ), $n, $x ? "  [$x]" : '', "\n"; }
$before = get_option( BP_Terms::OPTION, null );

// Nothing saved: rows from the profile, no translations, nothing added to the request.
delete_option( BP_Terms::OPTION );
$g = BP_Terms::get();
ok( 'unsaved: rows from the profile terms', ! $g['saved'] && isset( $g['terms']['game drive'] ) && array() === $g['terms']['game drive'] );
ok( 'unsaved: nothing added to the request', array() === BP_Terms::prompt_lines( 'de_DE', 'German', array( 'Morning game drive' ) ) );

// Saved terms and search words.
update_option( BP_Terms::OPTION, array( 'terms' => array( 'game drive' => array( 'de_DE' => 'Pirschfahrt', 'fr_FR' => 'safari en 4x4' ), 'tented camp' => array( 'de_DE' => 'Zeltcamp' ) ), 'search' => array( 'de_DE' => array( 'Tansania Safari', 'Safari Reise Tansania' ) ) ), false );
$lines = BP_Terms::prompt_lines( 'de_DE', 'German', array( 'A morning game drive in Tarangire' ) );
ok( 'term in the batch: its translation is required', false !== strpos( implode( "\n", $lines ), 'game drive = Pirschfahrt' ) );
ok( 'term not in the batch: left out', false === strpos( implode( "\n", $lines ), 'tented camp' ) );
ok( 'search words line for the language', false !== strpos( implode( "\n", $lines ), 'Tansania Safari; Safari Reise Tansania' ) && false !== strpos( implode( "\n", $lines ), 'Never add one where the text does not say it' ) );
ok( 'other language: only its own term', 1 === count( BP_Terms::prompt_lines( 'fr_FR', 'French', array( 'game drive' ) ) ) && false !== strpos( BP_Terms::prompt_lines( 'fr_FR', 'French', array( 'game drive' ) )[0], 'safari en 4x4' ) );
ok( 'full-prompt view: every term', false !== strpos( implode( "\n", BP_Terms::prompt_lines( 'de_DE', 'German', null ) ), 'tented camp = Zeltcamp' ) );
ok( 'plural in the text still matches', false !== strpos( implode( "\n", BP_Terms::prompt_lines( 'de_DE', 'German', array( 'Morning game drives and tented camps' ) ) ), 'tented camp = Zeltcamp' ) );
ok( 'word match only (no "game drives" inside other words)', array() === array_filter( BP_Terms::prompt_lines( 'fr_FR', 'French', array( 'Endgame drivetrain' ) ) ) );
$p = BP_Batch::system_prompt( 'English', 'German', array(), 'de_DE', array( 'Daily game drive' ) );
ok( 'in the request: after the profile, before the owner\'s instructions', strpos( $p, 'game drive = Pirschfahrt' ) > strpos( $p, 'Places such as' ) && ( false === strpos( $p, 'Instructions from the site owner' ) || strpos( $p, 'game drive = Pirschfahrt' ) < strpos( $p, 'Instructions from the site owner' ) ) );
ok( 'no language code: nothing added', false === strpos( BP_Batch::system_prompt( 'English', 'German', array() ), 'Pirschfahrt' ) );

// Search phrases: split, cleaned, de-duplicated, at most 12.
$ph = BP_Terms::phrases( "Tansania Safari\nsafari reise, Tansania safari; <b>Kilimandscharo</b>\n\n" . implode( "\n", range( 1, 20 ) ) );
ok( 'phrases cleaned and limited', 'Tansania Safari' === $ph[0] && 'safari reise' === $ph[1] && 'Kilimandscharo' === $ph[2] && 12 === count( $ph ) );

// Service pages: search results, nothing found, page not found.
$s = BP_Run::service_urls();
ok( 'three service pages', 3 === count( $s ) && false !== strpos( $s[0], '?s=' ) && false !== strpos( $s[2], BP_Run::MISSING_PATH ) );
ok( 'service pages may be chosen for a run', 3 === count( BP_Run::known_urls( $s ) ) );
ok( 'service pages never in a language sitemap', ! array_filter( $s, static fn( $u ) => BP_Sitemap::listable( $u ) && false === strpos( $u, BP_Run::MISSING_PATH ) ) );

// 2.9.1: suggestions sent with examples, rules and the decided terms; steady only on DeepSeek. Nothing leaves the site (faked reply).
$sent = array();
add_filter( 'pre_http_request', function ( $pre, $args, $url ) use ( &$sent ) { if ( false !== strpos( $url, 'wp-cron.php' ) ) { return $pre; } $sent[] = array( 'url' => $url, 'body' => json_decode( (string) $args['body'], true ) ); return array( 'headers' => array(), 'response' => array( 'code' => 200, 'message' => '' ), 'cookies' => array(), 'filename' => null, 'body' => wp_json_encode( array( 'choices' => array( array( 'finish_reason' => 'stop', 'message' => array( 'content' => wp_json_encode( array( 'terms' => array( array( 'term' => 'private safari', 'translation' => 'Privatsafari' ), array( 'term' => 'game drive', 'translation' => 'Pirschfahrt' ) ) ) ) ) ) ) ) ) ); }, 10, 3 );
$ds  = array( 'engine' => array( 'provider' => 'deepseek', 'api' => 'openai', 'url' => 'https://api.deepseek.com/chat/completions', 'model' => 'deepseek-chat', 'timeout' => 30 ), 'key' => 'test-key' );
$r   = BP_Terms::suggest_terms( $ds, 'de_DE', 'German', array( 'private safari', 'game drive' ), array( 'safari' => 'Safari' ) );
$sys = (string) ( $sent[0]['body']['messages'][0]['content'] ?? '' );
$usr = (string) ( $sent[0]['body']['messages'][1]['content'] ?? '' );
ok( 'suggestions: answers mapped back', array( 'private safari' => 'Privatsafari', 'game drive' => 'Pirschfahrt' ) === $r, wp_json_encode( $r ) );
ok( 'suggestions: rules against added meaning, short, one base word', false !== strpos( $sys, 'Never add a quality' ) && false !== strpos( $sys, 'never a description' ) && false !== strpos( $sys, 'build it from that term' ) );
ok( 'suggestions: decided terms passed on', false !== strpos( $sys, 'safari = Safari' ) );
ok( 'suggestions: base words first, each with an example field', 1 === preg_match( '/"term":"game drive","example":".*"term":"private safari"/s', $usr ) );
ok( 'suggestions: steady on DeepSeek (temperature 0)', isset( $sent[0]['body']['temperature'] ) && 0.0 === (float) $sent[0]['body']['temperature'] );
$sent = array(); $oa = $ds; $oa['engine']['provider'] = 'openai'; $oa['engine']['url'] = 'https://api.openai.com/v1/chat/completions';
BP_Terms::suggest_terms( $oa, 'de_DE', 'German', array( 'game drive' ) );
ok( 'other providers: no temperature field', ! isset( $sent[0]['body']['temperature'] ) );
ok( 'example sentence from the site uses the term', ( static function () { $e = BP_Terms::example( 'national park', 'fr_FR' ); return '' === $e || BP_Terms::occurs( 'national park', $e ); } )() );

null === $before ? delete_option( BP_Terms::OPTION ) : update_option( BP_Terms::OPTION, $before, false );
echo "\n$pass passed, $fail failed\n";
