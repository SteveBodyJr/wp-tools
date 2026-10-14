<?php
// Beaver Press test: run from the command line only (see tests/README.md).
// 3.0.1: Pause and Cancel win over a step that is fetching a page; the run lock is atomic;
// translated addresses keep WordPress's endings (/page/2, /feed...).
if ( PHP_SAPI !== 'cli' ) { exit; }
$_SERVER['HTTP_HOST'] = getenv( 'BP_HOST' ) ?: 'localhost'; $_SERVER['REQUEST_URI'] = '/';
require __DIR__ . '/bootstrap.php'; // WordPress + settings put back however the test ends.
$pass = 0; $fail = 0;
function ok( $n, $c, $x = '' ) { global $pass, $fail; $c ? $pass++ : $fail++; echo ( $c ? 'PASS ' : 'FAIL ' ), $n, $x ? "  [$x]" : '', "\n"; }
function call( $class, $method, array $args = array() ) { $m = new ReflectionMethod( $class, $method ); $m->setAccessible( true ); return $m->invokeArgs( null, $args ); }

$state_before = get_option( BP_Run::STATE_OPTION, null );
$lock_before  = get_option( BP_Run::LOCK, null );
delete_option( BP_Run::LOCK );

// Interrupted: the stored run changed while this step fetched a page.
$started = time() - 100;
$mine    = array( 'status' => 'running', 'started' => $started, 'pos' => 5, 'done' => 5, 'languages' => array( 'fr_FR' ), 'budget' => 0, 'message' => '' );
update_option( BP_Run::STATE_OPTION, $mine, false );
$s = $mine; $abandon = false;
$m = new ReflectionMethod( 'BP_Run', 'interrupted' ); $m->setAccessible( true );
ok( 'still running: step goes on', false === $m->invokeArgs( null, array( &$s, &$abandon ) ) && ! $abandon );

update_option( BP_Run::STATE_OPTION, array_merge( $mine, array( 'status' => 'paused', 'message' => 'Paused.', 'pos' => 3 ) ), false );
$s = $mine; $abandon = false;
$stop = $m->invokeArgs( null, array( &$s, &$abandon ) );
ok( 'paused meanwhile: step stops, takes the pause, keeps its progress', $stop && ! $abandon && 'paused' === $s['status'] && 5 === $s['pos'], wp_json_encode( $s ) );

update_option( BP_Run::STATE_OPTION, array( 'status' => 'idle' ), false );
$s = $mine; $abandon = false;
ok( 'cancelled meanwhile: state dropped', $m->invokeArgs( null, array( &$s, &$abandon ) ) && $abandon );

update_option( BP_Run::STATE_OPTION, array_merge( $mine, array( 'started' => $started + 50 ) ), false );
$s = $mine; $abandon = false;
ok( 'a new run started meanwhile: state dropped', $m->invokeArgs( null, array( &$s, &$abandon ) ) && $abandon );

// step() itself never writes over a pause.
update_option( BP_Run::STATE_OPTION, array_merge( $mine, array( 'status' => 'paused' ) ), false );
BP_Run::step( 1 );
ok( 'step on a paused run changes nothing', 'paused' === BP_Run::state()['status'] );

// The lock: only one step holds it; a stale one is taken over.
ok( 'lock taken', true === call( 'BP_Run', 'lock' ) && BP_Run::locked() );
ok( 'second lock refused', false === call( 'BP_Run', 'lock' ) );
update_option( BP_Run::LOCK, time() - BP_Run::LOCK_TTL - 5, false );
ok( 'stale lock taken over', true === call( 'BP_Run', 'lock' ) );
call( 'BP_Run', 'unlock' );
ok( 'unlocked', ! BP_Run::locked() );

// Translated addresses keep WordPress's endings.
$back = array( 'fr-safaris/serengeti-fr' => 'safaris/serengeti', 'categorie/parcs' => 'category/parks' );
ok( 'exact address', 'safaris/serengeti' === call( 'BP_Slugs', 'lookup', array( $back, 'fr-safaris/serengeti-fr' ) ) );
ok( 'archive page 2', 'category/parks/page/2' === call( 'BP_Slugs', 'lookup', array( $back, 'categorie/parcs/page/2' ) ) );
ok( 'post page 3', 'safaris/serengeti/3' === call( 'BP_Slugs', 'lookup', array( $back, 'fr-safaris/serengeti-fr/3' ) ) );
ok( 'feed and comment page', 'category/parks/feed/rss2' === call( 'BP_Slugs', 'lookup', array( $back, 'categorie/parcs/feed/rss2' ) ) && 'safaris/serengeti/comment-page-2' === call( 'BP_Slugs', 'lookup', array( $back, 'fr-safaris/serengeti-fr/comment-page-2' ) ) );
ok( 'unknown address untouched', 'other/page/2' === call( 'BP_Slugs', 'lookup', array( $back, 'other/page/2' ) ) );
ok( 'a child page is not an ending', 'categorie/parcs/tarangire' === call( 'BP_Slugs', 'lookup', array( $back, 'categorie/parcs/tarangire' ) ) );

// Put back.
null === $state_before ? delete_option( BP_Run::STATE_OPTION ) : update_option( BP_Run::STATE_OPTION, $state_before, false );
null === $lock_before ? delete_option( BP_Run::LOCK ) : update_option( BP_Run::LOCK, $lock_before, false );
echo "\n$pass passed, $fail failed\n";
