<?php
// Beaver Press test: run from the command line only (see tests/README.md).
// 2.6.3: a translation must keep the facts of the original (numbers, addresses, shortcodes).
if ( PHP_SAPI !== 'cli' ) { exit; }
$_SERVER['HTTP_HOST'] = getenv( 'BP_HOST' ) ?: 'localhost'; $_SERVER['REQUEST_URI'] = '/';
require __DIR__ . '/bootstrap.php';
$pass = 0; $fail = 0;
function ok( $n, $c, $x = '' ) { global $pass, $fail; $c ? $pass++ : $fail++; echo ( $c ? 'PASS ' : 'FAIL ' ), $n, $x ? "  [$x]" : '', "\n"; }
function keeps( $o, $t ) { return true === BP_Batch::check( $o, $t ); }
function why( $o, $t ) { $r = BP_Batch::check( $o, $t ); return true === $r ? 'kept' : $r; }

// Kept: the same facts written the target language's way.
ok( 'price with a different thousands mark', keeps( 'From $3,550 per person', 'Ab 3.550 $ pro Person' ) );
ok( 'price with a space (French)', keeps( 'From $3,550', 'À partir de 3 550 $' ) );
ok( 'price with a narrow space', keeps( 'From $3,550', "À partir de 3\u{202F}550 \$" ) );
ok( 'decimal comma', keeps( 'About 2.5 hours', 'Environ 2,5 heures' ) );
ok( 'days and nights', keeps( '7 days, 6 nights', '7 jours, 6 nuits' ) );
ok( 'Chinese: digits next to characters', keeps( '7 days, 6 nights', '7天6晚' ) );
ok( 'Chinese: number words up to ten', keeps( 'Luxury (5-Star) for 2 people', '奢华（五星级）两人' ) );
ok( 'Chinese: full-width comma in thousands', keeps( 'at roughly 2,850 metres', '海拔约2，850米' ) );
ok( 'Chinese: full-width digits', keeps( 'Day 3', '第３天' ) );
ok( 'Chinese: month as a number is extra, fine', keeps( 'On 4 October', '10月4日' ) );
ok( 'Chinese: 1.5 million as 150万', keeps( 'About 1.5 million', '约150万' ) );
ok( 'Chinese: 200,000 as 20万', keeps( 'About 200,000 zebra', '约20万匹斑马' ) );
ok( 'Chinese: 20万 for 300,000 refused', 'numbers changed' === why( 'About 300,000 zebra', '约20万匹斑马' ) );
ok( 'Chinese: save 10% as 9折', keeps( 'Book before 31 March 2027 and save 10%.', '在2027年3月31日前预订，可享9折优惠。' ) );
ok( 'Chinese: save 15% as 85折', keeps( 'Save 15% on 5 nights or more.', '入住5晚或以上，可享85折优惠。' ) );
ok( 'Chinese: save 10% as 8折 refused', 'numbers changed' === why( 'Save 10% now.', '现在享8折。' ) );
ok( '11 pm as 23 h', keeps( 'Around 11 pm', 'Vers 23 h' ) );
ok( '6:30 am kept', keeps( 'Starts at 6:30 am', 'Début à 6 h 30' ) );
ok( '12 am as 0 h', keeps( 'At 12 am', 'À 0 h' ) );
ok( '19th century as XIXe', keeps( 'a 19th-century building', 'un bâtiment du XIXe siècle' ) );
ok( '19th century as 1800-tallet', keeps( 'a 19th-century building', 'en bygning fra 1800-tallet' ) );
ok( '4x4 in words', keeps( 'Your own 4x4', 'Ihr eigener Geländewagen' ) );
ok( '24-hour in words', keeps( 'a 24-hour front desk', 'en døgnåben reception' ) );
ok( 'phone number kept', keeps( 'Call +255 700 000 000', 'Appelez le +255 700 000 000' ) );
ok( 'ids inside escaped markup are not facts', true === BP_Batch::facts( "&lt;span data-id=&#039;113&#039;&gt;Lake Manyara&lt;/span&gt;", '<span data-id="113">lac Manyara</span>' ) );
ok( 'digits of a placeholder are not facts', keeps( 'From <b>1TP1T2,400</b>', 'Dès <b>1TP1T2,400</b>' ) );
ok( 'e-mail kept, case ignored', keeps( 'Write to hello@raya.com.', 'Écrivez à Hello@Raya.com.' ) );
ok( 'web address kept', keeps( 'See www.example.com/park for more', 'Voir www.example.com/park' ) );
ok( 'text without facts', keeps( 'Plan your trip', 'Planifiez votre voyage' ) );

// Refused: a fact lost or changed.
ok( 'changed number of days', 'numbers changed' === why( '7 days in Serengeti', '8 jours dans le Serengeti' ) );
ok( 'changed price', 'numbers changed' === why( 'From $3,550', 'À partir de 3 500 $' ) );
ok( 'number dropped (20%)', 'numbers changed' === why( 'More than 20% of the animals', '超过动物的%' ) );
ok( 'number replaced by an address', 'numbers changed' === why( '443', 'hello@rayasafaris.com' ) );
ok( 'wrong year', 'numbers changed' === why( 'Since 2019', 'Depuis 2018' ) );
ok( 'phone digit changed', 'numbers changed' === why( 'Call +255 700 000 000', 'Appelez le +255 700 000 001' ) );
ok( '11 pm as 22 h refused', 'numbers changed' === why( 'Around 11 pm', 'Vers 22 h' ) );
ok( 'e-mail changed', 'e-mail or web address changed' === why( 'Write to hello@raya.com', 'Écrivez à bonjour@raya.com' ) );
ok( 'web address dropped', 'e-mail or web address changed' === why( 'Visit www.example.com', 'Visitez notre site' ) );

// Shortcodes: only the site's own, exactly as written.
add_shortcode( 'bp_test_button', '__return_empty_string' );
ok( 'shortcode kept', keeps( 'Book now [bp_test_button id="7"]', 'Réservez [bp_test_button id="7"]' ) );
ok( 'shortcode changed refused', 'shortcodes changed' === why( 'Book now [bp_test_button id="7"]', 'Réservez [bp_test_bouton id="7"]' ) );
ok( 'brackets that are no shortcode may be translated', keeps( '[Note] Bring a jacket', '[Remarque] Prenez une veste' ) );
remove_shortcode( 'bp_test_button' );

// The prompt asks for the same.
$p = BP_Batch::system_prompt( 'English', 'French', array(), 'fr_FR' );
ok( 'prompt: negations and inclusions', false !== strpos( $p, 'never drop or reverse a negation' ) && false !== strpos( $p, 'included or excluded' ) );
ok( 'prompt: short labels', false !== strpos( $p, 'about as short as the original' ) );

echo "\n$pass passed, $fail failed\n";
