<?php
/**
 * Upgrade and fresh-install check (disposable sites only).
 *
 *   php bp-upgrade-test.php --mode=setup  --site=URL --load=/site/wp-load.php   (on the OLD version)
 *   php bp-upgrade-test.php --mode=verify --site=URL --load=/site/wp-load.php   (after replacing the plugin files)
 *   php bp-upgrade-test.php --mode=fresh  --site=URL --load=/site/wp-load.php   (new version, new site)
 *
 * setup: default theme, French and Spanish, a page with a translated address, a repeated text
 * ("Book Now"), an image alt, machine translations plus one manual one, a backup, and a baseline
 * snapshot (option beaver_press_upgrade_baseline). verify: every translation, setting, address and
 * head tag as before; manual translation still manual; backups readable; no PHP errors; no
 * duplicate rewrite rules or head tags. fresh: setup then the same checks on the new version.
 *
 * Only uses functions that exist in Beaver Press 2.6.0, so setup runs on the old version.
 *
 * @package BeaverPress
 */

if ( PHP_SAPI !== 'cli' ) {
	exit;
}
$opts = getopt( '', array( 'mode:', 'site:', 'load:' ) );
$mode = (string) ( $opts['mode'] ?? '' );
$_SERVER['HTTP_HOST']   = (string) parse_url( (string) $opts['site'], PHP_URL_HOST ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- before WordPress.
$_SERVER['REQUEST_URI'] = '/';
require (string) $opts['load'];
require_once ABSPATH . 'wp-admin/includes/plugin.php';
if ( 'yes' !== get_option( 'beaver_press_disposable_site' ) ) {
	fwrite( STDERR, "Refused: not a disposable test site.\n" );
	exit( 2 );
}
global $wpdb;
$pass = 0;
$fail = 0;

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
 * GET (no redirects, logged out).
 *
 * @param string $u URL.
 * @return array [ code, location, body ].
 */
function get( $u ) {
	$h = curl_init( $u );
	curl_setopt_array( $h, array( CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 60 ) );
	$b = (string) curl_exec( $h );
	$r = array( (int) curl_getinfo( $h, CURLINFO_RESPONSE_CODE ), (string) curl_getinfo( $h, CURLINFO_REDIRECT_URL ), $b );
	curl_close( $h );
	return $r;
}

/**
 * Head facts used for the comparison.
 *
 * @param string $html HTML.
 * @return array
 */
function head_facts( $html ) {
	$head = (string) strstr( $html, '</head>', true );
	preg_match_all( '/<link\s+rel=["\']canonical["\']\s+href=["\']([^"\']*)/i', $head, $c );
	preg_match_all( '/<link\s+rel=["\']alternate["\']\s+hreflang=["\']([^"\']*)["\']\s+href=["\']([^"\']*)/i', $head, $h, PREG_SET_ORDER );
	preg_match_all( '#<title[^>]*>(.*?)</title>#is', $head, $t );
	preg_match( '/<html[^>]*\blang="([^"]*)"/i', $html, $l );
	$alts = array();
	foreach ( $h as $x ) {
		$alts[ $x[1] ] = $x[2];
	}
	ksort( $alts );
	return array(
		'canonical' => $c[1],
		'hreflang'  => $alts,
		'titles'    => $t[1],
		'lang'      => $l[1] ?? '',
	);
}

/**
 * Translation rows of a language (id, original, translated, status).
 *
 * @param string $language Language.
 * @return array
 */
function rows_of( $language ) {
	global $wpdb;
	$t = TRP_Translate_Press::get_trp_instance()->get_component( 'query' )->get_table_name( $language );
	return $wpdb->get_results( "SELECT id, original, translated, status FROM `{$t}` ORDER BY id", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- engine table.
}

/**
 * The state compared before and after.
 *
 * @param int $page Page id.
 * @return array
 */
function snapshot( $page ) {
	global $wpdb;
	$en  = get_permalink( $page );
	$out = array(
		'settings' => get_option( 'trp_settings' ),
		'slugs'    => get_post_meta( $page, '_bp_slug', true ),
		'bp_slugs' => get_option( 'beaver_press_slugs' ),
		'rows'     => array(),
		'pages'    => array(),
		'rules'    => count( (array) get_option( 'rewrite_rules' ) ),
		'backups'  => array_keys( (array) get_option( 'beaver_press_backups', array() ) ),
	);
	foreach ( array( 'fr_FR', 'es_ES' ) as $l ) {
		foreach ( rows_of( $l ) as $r ) {
			$out['rows'][ $l ][ (int) $r['id'] ] = md5( $r['original'] . "\0" . $r['translated'] . "\0" . $r['status'] );
		}
	}
	foreach ( array( $en, home_url( '/fr/safaris-tanzanie/' ), home_url( '/es/safaris-tanzania/' ) ) as $u ) {
		list( $code, , $body ) = get( $u );
		$out['pages'][ $u ] = array( 'code' => $code ) + head_facts( $body );
	}
	return $out;
}

/**
 * Build the test content and translations (works on 2.6.0 and later).
 *
 * @return int Page id.
 */
function setup_site() {
	global $wpdb;
	switch_theme( 'twentytwentyfive' );
	if ( ! is_plugin_active( 'beaver-press/beaver-press.php' ) ) {
		$r = activate_plugin( 'beaver-press/beaver-press.php' );
		ok( 'plugin activates', ! is_wp_error( $r ), is_wp_error( $r ) ? $r->get_error_message() : '' );
	}
	$s = get_option( 'trp_settings', array() );
	if ( ! is_array( $s ) || empty( $s['default-language'] ) ) {
		$s = TRP_Translate_Press::get_trp_instance()->get_component( 'settings' )->get_settings();
	}
	$s['translation-languages'] = array( 'en_US', 'fr_FR', 'es_ES' );
	$s['publish-languages']     = array( 'en_US', 'fr_FR', 'es_ES' );
	$s['url-slugs']             = array( 'en_US' => 'en', 'fr_FR' => 'fr', 'es_ES' => 'es' );
	update_option( 'trp_settings', $s );
	$q = TRP_Translate_Press::get_trp_instance()->get_component( 'query' );
	$gt = $q->get_query_component( 'gettext_table_creation' );
	$q->check_original_table();
	$gt->check_gettext_original_table();
	foreach ( array( 'fr_FR', 'es_ES' ) as $l ) {
		$q->check_table( 'en_US', $l );
		$gt->check_gettext_table( $l );
	}
	$q->check_original_meta_table();
	update_option( 'beaver_press_slugs', 'yes', false );
	$page = wp_insert_post(
		array(
			'post_title'   => 'Safari tours',
			'post_name'    => 'safari-tours',
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_content' => "<!-- wp:paragraph --><p>Private safaris in northern Tanzania.</p><!-- /wp:paragraph -->\n<!-- wp:paragraph --><p>Book Now</p><!-- /wp:paragraph -->\n<!-- wp:paragraph --><p>Book Now</p><!-- /wp:paragraph -->\n<!-- wp:image --><figure class=\"wp-block-image\"><img src=\"" . esc_url( includes_url( 'images/w-logo-blue.png' ) ) . "\" alt=\"Lions in the grass\"/></figure><!-- /wp:image -->",
		)
	);
	update_post_meta( $page, '_bp_slug', array( 'fr_FR' => 'safaris-tanzanie', 'es_ES' => 'safaris-tanzania' ) );
	flush_rewrite_rules( false );
	if ( class_exists( 'BP_Slugs' ) ) {
		BP_Slugs::forget_map();
	}
	// Visit so the engine stores the texts (untranslated: providers are blocked on test sites).
	foreach ( array( 'fr_FR' => 'fr/safaris-tanzanie', 'es_ES' => 'es/safaris-tanzania' ) as $l => $path ) {
		for ( $i = 0; $i < 3; $i++ ) {
			get( home_url( '/' . $path . '/?v=' . $i ) );
			$t = $q->get_table_name( $l );
			$wpdb->query( $wpdb->prepare( "UPDATE `{$t}` SET translated = CONCAT( %s, original ), status = 1 WHERE status = 0", '[' . $l . '] ' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$g = $q->get_gettext_table_name( $l );
			$wpdb->query( $wpdb->prepare( "UPDATE `{$g}` SET translated = CONCAT( %s, original ), status = 1 WHERE status = 0", '[' . $l . '] ' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		$wpdb->query( $wpdb->prepare( "UPDATE `{$t}` SET translated = %s, status = 2 WHERE original = 'Book Now'", 'fr_FR' === $l ? 'Réserver' : 'Reservar' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the manual one.
	}
	if ( class_exists( 'BP_Cache' ) ) {
		BP_Cache::clear();
	}
	wp_cache_flush();
	foreach ( array( 'fr/safaris-tanzanie', 'es/safaris-tanzania' ) as $path ) {
		get( home_url( '/' . $path . '/?w=1' ) ); // Records: complete.
	}
	if ( ! class_exists( 'BP_Backup' ) ) {
		ok( 'no backups in this version (before 2.6.0): skipped', true );
		update_option( 'beaver_press_upgrade_page', $page, false );
		return $page;
	}
	// In a fresh process: this one still has the engine's language list from before setup.
	$out = (string) shell_exec( escapeshellarg( PHP_BINARY ) . ' -r ' . escapeshellarg( '$_SERVER["HTTP_HOST"]="' . $_SERVER['HTTP_HOST'] . '"; require "' . ABSPATH . 'wp-load.php"; $id = BP_Backup::create( "Upgrade test baseline" ); echo is_wp_error( $id ) ? "error" : ( BP_Backup::backups()[ $id ]["rows"] ?? 0 );' ) . ' 2>/dev/null' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec -- test helper.
	ok( 'baseline backup made, with translations', (int) trim( $out ) > 0, trim( $out ) . ' rows' );
	update_option( 'beaver_press_upgrade_page', $page, false );
	return $page;
}

/**
 * The checks after an upgrade (or on a fresh site).
 *
 * @param array $before Baseline (empty on a fresh site).
 */
function verify_site( array $before ) {
	global $wpdb;
	$page  = (int) get_option( 'beaver_press_upgrade_page' );
	$now   = snapshot( $page );
	$en    = get_permalink( $page );
	$fr    = home_url( '/fr/safaris-tanzanie/' );
	$es    = home_url( '/es/safaris-tanzania/' );
	$fresh = ! $before;
	ok( 'version is the new one', version_compare( BP_VERSION, '2.6.1', '>=' ), BP_VERSION );
	if ( ! $fresh ) {
		$changed = 0;
		$added   = 0;
		foreach ( $before['rows'] as $l => $rows ) {
			foreach ( $rows as $id => $h ) {
				$changed += ( $now['rows'][ $l ][ $id ] ?? '' ) === $h ? 0 : 1;
			}
			$added += count( array_diff_key( $now['rows'][ $l ] ?? array(), $rows ) );
		}
		ok( 'every existing translation row unchanged (both languages)', 0 === $changed, $changed . ' changed' );
		echo 'INFO rows added since the baseline (new texts found on visits, e.g. image alt): ', $added, "\n";
		ok( 'language settings intact', $before['settings'] === $now['settings'] );
		ok( 'translated addresses intact', $before['slugs'] === $now['slugs'] && $before['bp_slugs'] === $now['bp_slugs'] );
		ok( 'no duplicate rewrite rules (same count)', $before['rules'] === $now['rules'], $before['rules'] . ' -> ' . $now['rules'] );
		foreach ( $before['pages'] as $u => $p ) {
			$n = $now['pages'][ $u ];
			ok( 'same status: ' . $u, $p['code'] === $n['code'], $p['code'] . ' -> ' . $n['code'] );
			ok( 'same canonical and lang: ' . $u, $p['canonical'] === $n['canonical'] && $p['lang'] === $n['lang'], wp_json_encode( $n['canonical'] ) );
			ok( 'same title: ' . $u, $p['titles'] === $n['titles'] );
			ok( 'hreflang kept (x-default may be added): ' . $u, array_diff_key( $p['hreflang'], array( 'x-default' => 1 ) ) === array_diff_key( $n['hreflang'], array( 'x-default' => 1 ) ) );
		}
		$list = (array) get_option( 'beaver_press_backups', array() );
		if ( $before['backups'] ) {
			$id   = (string) array_key_first( $list );
			$data = '' !== $id ? BP_Backup::read_file( BP_Backup::path( $id ) ) : null;
			ok( 'backups still listed and readable', '' !== $id && is_array( $data ) && ! is_wp_error( BP_Backup::validate( $data ) ) );
		} else {
			$id = BP_Backup::create( 'First backup after upgrade' );
			ok( 'first backup works after upgrading from a version without backups', ! is_wp_error( $id ) );
		}
	}
	foreach ( array( $en, $fr, $es ) as $u ) {
		list( $code, , $body ) = get( $u );
		$h = head_facts( $body );
		ok( '200: ' . $u, 200 === $code, (string) $code );
		ok( 'one canonical, itself: ' . $u, array( $u ) === $h['canonical'], wp_json_encode( $h['canonical'] ) );
		ok( 'one <title>: ' . $u, 1 === count( $h['titles'] ) );
		ok( 'hreflang with x-default: ' . $u, isset( $h['hreflang']['x-default'] ) && 3 <= count( $h['hreflang'] ) );
	}
	list( , $frbody ) = array_slice( get( $fr ), 1 );
	ok( 'repeated text translated once and used twice', 2 === substr_count( $frbody, 'Réserver' ) );
	ok( 'old address 301 to the translated one', 301 === get( home_url( '/fr/safari-tours/' ) )[0] );
	ok( 'missing page 404', 404 === get( home_url( '/fr/bp-no-such-page/' ) )[0] );
	$t = TRP_Translate_Press::get_trp_instance()->get_component( 'query' )->get_table_name( 'fr_FR' );
	ok( 'manual translation still manual', 2 === (int) $wpdb->get_var( "SELECT status FROM `{$t}` WHERE original = 'Book Now'" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	BP_Provenance::install();
	ok( 'provenance table present', BP_Provenance::table() === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', BP_Provenance::table() ) ) );
	ok( 'old translations not given a provider (legacy)', null === BP_Provenance::get( 'fr_FR', 'Private safaris in northern Tanzania.' ) );
	$engine = BP_Engine_Settings::current( (array) get_option( 'trp_machine_translation_settings', array() ) );
	ok( 'provider configuration valid', '' !== $engine['provider'] && '' !== $engine['api'] );
	$log = WP_CONTENT_DIR . '/debug.log';
	$err = is_file( $log ) ? preg_grep( '/PHP (Fatal|Warning|Parse|Notice|Deprecated).*(beaver-press)/', (array) file( $log ) ) : array();
	ok( 'no PHP errors from Beaver Press in debug.log', ! $err, implode( ' | ', array_slice( $err, 0, 2 ) ) );
}

if ( 'setup' === $mode ) {
	$page = setup_site();
	update_option( 'beaver_press_upgrade_baseline', snapshot( $page ), false );
	ok( 'baseline saved', (bool) get_option( 'beaver_press_upgrade_baseline' ) );
} elseif ( 'verify' === $mode ) {
	verify_site( (array) get_option( 'beaver_press_upgrade_baseline', array() ) );
} elseif ( 'fresh' === $mode ) {
	setup_site();
	verify_site( array() );
	// A new site uses the Safari & Tourism profile (2.6.2).
	if ( class_exists( 'BP_Profiles' ) ) {
		$prompt = BP_Instructions::full_prompt( 'fr_FR' );
		ok( 'fresh site: Safari & Tourism profile in the AI instructions', 'safari' === BP_Profiles::current_id() && false !== strpos( $prompt, 'safari and tourism business' ) && false !== strpos( $prompt, 'Never invent prices' ) );
	}
} elseif ( 'uninstall' === $mode ) {
	// Deactivate: the site keeps working (original language); uninstall: Beaver Press's own data goes,
	// the engine's translation tables stay (the engine has no uninstaller, by design).
	$page = (int) get_option( 'beaver_press_upgrade_page' );
	$url  = get_permalink( $page );
	deactivate_plugins( 'beaver-press/beaver-press.php' );
	ok( 'deactivated: original page still 200', 200 === get( $url )[0] );
	$dir = (string) get_option( 'beaver_press_backup_dir' );
	uninstall_plugin( 'beaver-press/beaver-press.php' );
	wp_cache_flush();
	$left = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'beaver\_press\_%' OR option_name LIKE 'bp\_pg\_%' OR option_name LIKE 'bp\_pt\_%' OR option_name LIKE 'bp\_ni\_%'" ); // phpcs:ignore
	$left = array_diff( $left, array( 'beaver_press_disposable_site', 'beaver_press_upgrade_baseline', 'beaver_press_upgrade_page' ) );
	ok( 'uninstalled: Beaver Press options removed', ! $left, implode( ', ', array_slice( $left, 0, 5 ) ) );
	ok( 'uninstalled: provenance table removed', ! $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}bp_translation_meta'" ) );
	ok( 'uninstalled: backup folder removed', '' === $dir || ! is_dir( trailingslashit( wp_upload_dir()['basedir'] ) . 'beaver-press-backups-' . $dir ) );
	ok( 'engine translations kept (no engine uninstaller)', (bool) $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}trp_dictionary_en_us_fr_fr'" ) );
} else {
	echo "Usage: --mode=setup|verify|fresh|uninstall --site=URL --load=PATH\n";
	exit( 2 );
}
echo "\n{$pass} passed, {$fail} failed\n";
exit( $fail ? 1 : 0 );
