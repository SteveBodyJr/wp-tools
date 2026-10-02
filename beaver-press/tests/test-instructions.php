<?php
// Beaver Press test: run from the command line only (see tests/README.md).
if ( PHP_SAPI !== 'cli' ) { exit; }
$_SERVER['HTTP_HOST'] = getenv( 'BP_HOST' ) ?: 'localhost'; $_SERVER['REQUEST_URI'] = '/';
require __DIR__ . '/bootstrap.php'; // WordPress + settings put back however the test ends.
$pass = 0; $fail = 0;
function ok( $n, $c, $x = '' ) { global $pass, $fail; $c ? $pass++ : $fail++; echo ( $c ? 'PASS ' : 'FAIL ' ), $n, $x ? "  [$x]" : '', "\n"; }
$before = get_option( BP_Instructions::OPTION, null );
delete_option( BP_Instructions::OPTION );
$g = BP_Instructions::get();
ok( 'unsaved: suggestions in use', ! $g['saved'] && false !== strpos( $g['site'], 'is a safari and tourism business' ) && isset( $g['langs']['de_DE'] ) && false !== strpos( $g['langs']['de_DE'], 'Sie' ) );
$p = BP_Batch::system_prompt( 'English', 'German', array( 'Serengeti National Park' ), 'de_DE' );
ok( 'prompt: rules first, owner part after', strpos( $p, 'Keep placeholders' ) < strpos( $p, 'Instructions from the site owner' ) && false !== strpos( $p, 'For German: Address the reader formally (Sie)' ) );
ok( 'prompt ends with the JSON reminder', "Reply with the JSON object only." === substr( $p, -strlen( 'Reply with the JSON object only.' ) ) );
ok( 'prompt without code: no owner part', false === strpos( BP_Batch::system_prompt( 'English', 'German', array(), '' ), 'site owner' ) );
update_option( BP_Instructions::OPTION, array( 'site' => '', 'langs' => array( 'fr_FR' => '' ) ), false );
ok( 'saved empty: no owner part', false === strpos( BP_Batch::system_prompt( 'English', 'French', array(), 'fr_FR' ), 'site owner' ) );
$c = BP_Instructions::clean( array( 'site' => str_repeat( 'a', 3000 ) . '<script>x</script>', 'langs' => array( 'fr_FR' => '  Use vous.  ', 'xx_YY' => 'unknown', 'de_DE' => '' ) ) );
ok( 'clean: length capped, tags stripped', 2000 === mb_strlen( $c['site'] ) && false === strpos( $c['site'], '<' ) );
ok( 'clean: every offered language kept (empty on purpose), trimmed, unknown dropped', 'Use vous.' === $c['langs']['fr_FR'] && '' === $c['langs']['de_DE'] && ! isset( $c['langs']['xx_YY'] ) );
// Flexible languages: saved without Swedish (added later) -> suggestion; saved empty German -> stays empty; removed language kept.
update_option( BP_Instructions::OPTION, array( 'site' => 'S', 'langs' => array( 'de_DE' => '', 'fr_FR' => 'Vous.', 'it_IT' => 'Tu.' ) ), false );
$g = BP_Instructions::get();
ok( 'new language gets the suggestion', false !== strpos( $g['langs']['sv_SE'], '(du)' ) );
ok( 'saved empty stays empty', '' === $g['langs']['de_DE'] && false === strpos( BP_Batch::system_prompt( 'English', 'German', array(), 'de_DE' ), 'For German' ) );
ok( 'removed language text kept', 'Tu.' === $g['langs']['it_IT'] );
// Removed language: its progress rows go.
update_option( 'bp_pg_zz_ZZ_' . md5( 'x' ), 3, false );
BP_Complete::languages_changed( array( 'translation-languages' => array( 'en_US', 'zz_ZZ' ) ), array( 'translation-languages' => array( 'en_US' ) ) );
ok( 'removed language progress rows deleted', false === get_option( 'bp_pg_zz_ZZ_' . md5( 'x' ) ) );
ok( 'languages limit has room', BP_Languages::allowed() >= 5 && 30 === BP_Languages::DEFAULT_EXTRA );
$fr = BP_Instructions::machine_counts( 'fr_FR' );
ok( 'machine counts read', $fr['strings'] > 0 && $fr['chars'] > $fr['strings'] );
ok( 'ajax hooks', false !== has_action( 'wp_ajax_bp_try_instructions' ) && false !== has_action( 'wp_ajax_bp_redo_language' ) && false === has_action( 'wp_ajax_nopriv_bp_redo_language' ) );
null === $before ? delete_option( BP_Instructions::OPTION ) : update_option( BP_Instructions::OPTION, $before, false );
echo "\n$pass passed, $fail failed\n";
