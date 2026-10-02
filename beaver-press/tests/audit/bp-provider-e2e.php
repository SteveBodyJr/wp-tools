<?php
/**
 * Provider end-to-end check on a disposable copy (real, paid requests: a few short texts).
 *
 *   BP_WP_LOAD=/copy/wp-load.php php bp-provider-e2e.php [--language=fr_FR]
 *
 * Uses the provider, model and key saved on the copy. Creates one post with three sentences,
 * then: a visitor visit starts no request; the signed run translates and stores; reloads cost
 * nothing; a manual edit survives another automatic pass; a changed original keeps the manual
 * translation, marked outdated (Keep / Retranslate with AI / Edit offered); provenance is
 * recorded; the key appears nowhere in pages, the debug panel or an export. Everything it
 * created is removed at the end. Allows the provider in the copy's sandbox only while it runs.
 *
 * @package BeaverPress
 */

if ( PHP_SAPI !== 'cli' ) {
	exit;
}
$opts = getopt( '', array( 'language:' ) );
$_SERVER['HTTP_HOST']   = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
require getenv( 'BP_WP_LOAD' ) ?: dirname( __DIR__, 5 ) . '/wp-load.php';
if ( 'yes' !== get_option( 'beaver_press_disposable_site' ) ) {
	fwrite( STDERR, "Refused: not a disposable test copy.\n" );
	exit( 2 );
}
global $wpdb;
$lang  = (string) ( $opts['language'] ?? 'fr_FR' );
$pass  = 0;
$fail  = 0;
$mt    = (array) get_option( 'trp_machine_translation_settings', array() );
$eng   = BP_Engine_Settings::current( $mt );
$key   = BP_Keys::get( $eng['provider'] );
$q     = TRP_Translate_Press::get_trp_instance()->get_component( 'query' );
$table = $q->get_table_name( $lang );

/**
 * Record a check.
 *
 * @param string $n Name.
 * @param bool   $c Passed.
 * @param string $x Detail.
 */
function ok( $n, $c, $x = '' ) {
	global $pass, $fail;
	$c ? $pass++ : $fail++;
	echo ( $c ? 'PASS ' : 'FAIL ' ), $n, $x ? "  [$x]" : '', "\n";
}

/**
 * GET as a visitor, or as the signed Translate-site run.
 *
 * @param string $u   URL.
 * @param bool   $run Signed run request (may translate).
 * @return string Body.
 */
function fetch( $u, $run = false ) {
	$h = curl_init( $u );
	curl_setopt_array( $h, array( CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 180, CURLOPT_HTTPHEADER => $run ? array( BP_Guard::HEADER . ': ' . BP_Guard::run_token() ) : array() ) );
	$b = (string) curl_exec( $h );
	curl_close( $h );
	return $b;
}

/**
 * Provider requests recorded so far today (all requests count, paid or not).
 *
 * @return int
 */
function requests() {
	wp_cache_flush();
	return (int) ( BP_Usage::days()[ gmdate( 'Y-m-d' ) ]['requests'] ?? 0 );
}

/**
 * A translation row.
 *
 * @param string $o Original.
 * @return object|null
 */
function row( $o ) {
	global $wpdb, $table;
	wp_cache_flush();
	return $wpdb->get_row( $wpdb->prepare( "SELECT id, translated, status FROM `{$table}` WHERE original = %s", $o ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

if ( '' === $key ) {
	echo "No key saved for {$eng['provider']} on this copy.\n\n0 passed, 1 failed\n";
	exit( 1 );
}
echo "Provider {$eng['provider']}, model {$eng['model']}, language {$lang}\n";
$a    = 'Our guides meet every guest at Kilimanjaro airport.';
$b    = 'Every safari vehicle has a pop-up roof for photography.';
$c    = 'Book Now';
$a2   = 'Our guides meet every guest at Kilimanjaro International Airport.';
$post = 0;
update_option( 'bp_test_allow_provider', $eng['provider'], false );
$guard_before = $mt['bp-visitor-guard'] ?? 'yes';
$theme_before = get_stylesheet();
try {
	switch_theme( 'twentytwentyfive' ); // A theme that prints post content as WordPress gives it.
	$mt['bp-visitor-guard'] = 'yes';
	update_option( 'trp_machine_translation_settings', $mt );
	$post = wp_insert_post( array( 'post_title' => 'Beaver e2e test post', 'post_status' => 'publish', 'post_type' => 'post', 'post_content' => "<!-- wp:paragraph --><p>{$a}</p><!-- /wp:paragraph --><!-- wp:paragraph --><p>{$b}</p><!-- /wp:paragraph --><!-- wp:paragraph --><p>{$c}</p><!-- /wp:paragraph --><!-- wp:paragraph --><p>{$c}</p><!-- /wp:paragraph -->" ) );
	BP_Cache::clear();
	$url = BP_Run::url_in( get_permalink( $post ), $lang );

	$n0 = requests();
	fetch( $url . '?v=1' );
	ok( 'a visitor visit starts no provider request', requests() === $n0, ( requests() - $n0 ) . ' requests' );

	fetch( $url . '?r=1', true ); // The Translate-site run.
	$n1 = requests();
	ok( 'the run sent the new texts to the provider', $n1 > $n0, ( $n1 - $n0 ) . ' requests' );
	$ra = row( $a );
	ok( 'translation stored (machine)', $ra && '' !== (string) $ra->translated && 1 === (int) $ra->status, $ra ? $ra->translated : 'missing' );
	$p = BP_Provenance::get( $lang, $a );
	ok( 'provenance: provider, model, type, hash, time, status', $p && $eng['provider'] === $p['provider'] && $eng['model'] === $p['model'] && 'regular' === $p['type'] && md5( $a ) === $p['source_hash'] && 'machine_translated' === $p['status'] && '' !== (string) $p['created_at'], $p ? wp_json_encode( array_intersect_key( $p, array_flip( array( 'provider', 'model', 'type', 'status' ) ) ) ) : 'none' );
	$book = row( $c );
	ok( 'repeated text stored once (translation memory)', $book && 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE original = %s", $c ) ) ); // phpcs:ignore

	$html = fetch( $url );
	fetch( $url );
	ok( 'reloads as a visitor cost nothing', requests() === $n1, ( requests() - $n1 ) . ' requests' );
	$why = false !== strpos( $html, 'id="bp-untranslated"' ) ? 'fallback, missing: ' . implode( ' | ', BP_Complete::missing_texts( $lang, BP_Complete::key( get_permalink( $post ) ) ) ) : 'complete';
	$text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' );
	ok( 'translated page served from the server', false !== strpos( $text, html_entity_decode( wp_strip_all_tags( (string) row( $b )->translated ), ENT_QUOTES, 'UTF-8' ) ), $why . ' / ' . row( $b )->translated );
	ok( 'repeated text shown twice from one translation', $book && 2 <= substr_count( $text, html_entity_decode( wp_strip_all_tags( (string) $book->translated ), ENT_QUOTES, 'UTF-8' ) ), $book ? $book->translated : '' );

	// Manual edit, as the visual editor saves it.
	$manual = 'Nos guides accueillent chaque voyageur à l\'aéroport du Kilimandjaro (édité à la main).';
	$q->update_strings( array( array( 'id' => (int) $ra->id, 'original' => $a, 'translated' => $manual, 'status' => 2, 'block_type' => 0, 'original_id' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT original_id FROM `{$table}` WHERE id = %d", $ra->id ) ) ) ), $lang ); // phpcs:ignore
	do_action( 'trp_save_editor_translations_regular_strings', array( $lang => array( array( 'original' => $a, 'translated' => $manual, 'status' => 2 ) ) ), array() );
	ok( 'manual edit recorded (after AI)', 'manually_edited' === BP_Provenance::get( $lang, $a )['status'] && 1 === (int) BP_Provenance::get( $lang, $a )['edited_after_ai'] );
	BP_Cache::clear();
	fetch( $url . '?r=2', true ); // Automatic translation again.
	ok( 'automatic translation did not overwrite the manual one', $manual === (string) row( $a )->translated && 2 === (int) row( $a )->status );

	// The original changes.
	$n2 = requests();
	wp_update_post( array( 'ID' => $post, 'post_content' => str_replace( $a, $a2, get_post_field( 'post_content', $post ) ) ) );
	BP_Cache::clear();
	$after = fetch( $url . '?r=3', true );
	if ( getenv( 'BP_E2E_KEEP' ) ) {
		file_put_contents( sys_get_temp_dir() . '/bp-e2e-after.html', $after ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- debugging aid.
	}
	$r2 = row( $a2 );
	$p2 = BP_Provenance::get( $lang, $a2 );
	ok( 'changed original: manual translation kept on the new text', $r2 && $manual === (string) $r2->translated, $r2 ? $r2->translated : 'missing; post content now: ' . wp_strip_all_tags( get_post_field( 'post_content', $post ) ) );
	ok( 'marked outdated, with the old source', $p2 && 'outdated' === $p2['status'] && $a === $p2['old_source'] );
	// The changed sentence itself was not machine translated: its translation is still the hand-made
	// one (checked above). Other new texts on the page may be (e.g. a description an SEO plugin builds
	// from the content), so the request count is only reported.
	ok( 'changed text not machine translated (translation is still the manual one)', $r2 && $manual === (string) $r2->translated && 'outdated' === ( $p2['status'] ?? '' ) );
	echo 'INFO requests while the page changed: ', requests() - $n2, " (other new texts on the page, if any)\n";
	ok( 'old manual translation still stored', $manual === (string) row( $a )->translated );
	ob_start();
	BP_Provenance::render();
	$card = ob_get_clean();
	ok( 'Changed texts offers Keep, Retranslate with AI and Edit', false !== strpos( $card, '>Keep<' ) && false !== strpos( $card, 'Retranslate with AI' ) && false !== strpos( $card, '>Edit<' ) );

	// As from the Changed texts button: an administrator's request, which the visitor guard trusts.
	$_SERVER[ 'HTTP_' . strtoupper( str_replace( '-', '_', BP_Guard::HEADER ) ) ] = BP_Guard::run_token();
	$n3 = requests();
	$rt = BP_Provenance::retranslate( (int) $p2['id'] );
	unset( $_SERVER[ 'HTTP_' . strtoupper( str_replace( '-', '_', BP_Guard::HEADER ) ) ] );
	$p3 = BP_Provenance::get( $lang, $a2 );
	ok( 'explicit retranslation: new AI translation, provider and model recorded', true === $rt && $manual !== (string) row( $a2 )->translated && 'machine_translated' === $p3['status'] && $eng['provider'] === $p3['provider'] && $eng['model'] === $p3['model'], is_wp_error( $rt ) ? $rt->get_error_message() : (string) row( $a2 )->translated );
	ok( 'retranslation was one request', requests() - $n3 === 1, ( requests() - $n3 ) . ' requests' );

	// The key appears nowhere.
	$admin = (int) get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0];
	$url   = BP_Run::url_in( get_permalink( $post ), $lang ); // Its French address may have been translated meanwhile.
	$h     = curl_init( $url . '?bp_debug=1' );
	curl_setopt_array( $h, array( CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_COOKIE => LOGGED_IN_COOKIE . '=' . rawurlencode( wp_generate_auth_cookie( $admin, time() + 300, 'logged_in' ) ) ) );
	$debug = (string) curl_exec( $h );
	curl_close( $h );
	$export = (string) wp_json_encode( BP_Backup::data( array( $lang ) ) );
	ok( 'key not in the page, the debug panel or an export', false === strpos( $html . $debug . $export, $key ) && false !== strpos( $debug, 'id="bp-debug"' ) );
} finally {
	if ( getenv( 'BP_E2E_KEEP' ) ) {
		echo "Kept for inspection (BP_E2E_KEEP): post {$post}\n";
		delete_option( 'bp_test_allow_provider' );
		exit;
	}
	if ( $post ) {
		wp_delete_post( $post, true );
	}
	foreach ( array( $a, $a2, $b, 'Beaver e2e test post' ) as $o ) {
		$wpdb->query( $wpdb->prepare( "DELETE FROM `{$table}` WHERE original = %s OR original LIKE %s", $o, '%' . $wpdb->esc_like( $o ) . '%' ) ); // phpcs:ignore
		BP_Provenance::forget( $lang, $o );
	}
	$mt['bp-visitor-guard'] = $guard_before;
	update_option( 'trp_machine_translation_settings', $mt );
	delete_option( 'bp_test_allow_provider' );
	switch_theme( $theme_before );
	BP_Cache::clear();
}
echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail ? 1 : 0 );
