<?php
/**
 * Beaver Press with SEO plugins (V4): Yoast SEO, Rank Math, SEOPress, and none.
 *
 * For each: activate it on the disposable copy, make the sample pages complete in two
 * languages (temporary translations, removed afterwards), then check translated and original
 * pages for exactly one canonical (the page itself), one <title>, at most one description /
 * og:title / og:url (og:url = the page), hreflang (self, x-default, no duplicates), and that
 * the translated pages are in a sitemap reachable from the active sitemap index. Then
 * deactivate it again.
 *
 *   BP_WP_LOAD=/copy/wp-load.php BP_SITE=http://localhost/copy BP_WP_CLI="php /path/wp-cli.phar" \
 *     php bp-seo-plugins.php [--report=/path/report.md]
 *
 * Disposable copies only (option beaver_press_disposable_site = yes).
 *
 * @package BeaverPress
 */

if ( PHP_SAPI !== 'cli' ) {
	exit;
}
$opts                   = getopt( '', array( 'report:' ) );
$_SERVER['HTTP_HOST']   = (string) parse_url( (string) getenv( 'BP_SITE' ), PHP_URL_HOST ) ?: 'localhost'; // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- before WordPress.
$_SERVER['REQUEST_URI'] = '/';
require getenv( 'BP_WP_LOAD' ) ?: dirname( __DIR__, 5 ) . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
if ( 'yes' !== get_option( 'beaver_press_disposable_site' ) ) {
	fwrite( STDERR, "Refused: not a disposable test copy.\n" );
	exit( 2 );
}
$wpcli = (string) getenv( 'BP_WP_CLI' );
$pass  = 0;
$fail  = 0;
$lines = array();

/**
 * Record a check.
 *
 * @param bool   $c Passed.
 * @param string $n Check.
 * @param string $u URL.
 * @param string $x Detail.
 */
function ok( $c, $n, $u = '', $x = '' ) {
	global $pass, $fail, $lines;
	$c ? $pass++ : $fail++;
	if ( ! $c ) {
		$lines[] = "| FAIL | {$n} | {$u} | {$x} |";
		echo "FAIL  {$n}  {$u}  [{$x}]\n";
	}
}

/**
 * Fetch (no redirects, logged out).
 *
 * @param string $u URL.
 * @return array [ code, body ].
 */
function get( $u ) {
	$h = curl_init( $u );
	curl_setopt_array( $h, array( CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 60, CURLOPT_USERAGENT => 'BeaverAudit' ) );
	$b = (string) curl_exec( $h );
	$c = (int) curl_getinfo( $h, CURLINFO_RESPONSE_CODE );
	curl_close( $h );
	return array( $c, $b );
}

/**
 * Count matches in the head.
 *
 * @param string $html    HTML.
 * @param string $pattern Regex.
 * @return array Matches (group 1).
 */
function in_head( $html, $pattern ) {
	preg_match_all( $pattern, (string) strstr( $html, '</head>', true ), $m );
	return $m[1];
}

/**
 * Run WP-CLI on the copy (flush rewrites after a plugin change).
 *
 * @param string $args Arguments.
 */
function cli( $args ) {
	global $wpcli;
	if ( '' !== $wpcli ) {
		shell_exec( $wpcli . ' --path=' . escapeshellarg( ABSPATH ) . ' --skip-themes ' . $args . ' 2>&1' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec -- test helper.
	}
}

global $wpdb;
$langs   = array( 'fr_FR', 'es_ES' );
$home    = untrailingslashit( home_url() );
$samples = array( home_url( '/about/' ), home_url( '/tours/4-days-budget-camping-safari-tarangire-serengeti-ngorongoro/' ) );
$plugins = array(
	'none'       => '',
	'Yoast SEO'  => 'wordpress-seo/wp-seo.php',
	'Rank Math'  => 'seo-by-rank-math/rank-math.php',
	'SEOPress'   => 'wp-seopress/seopress.php',
);
$index_of = array(
	'none'      => '/wp-sitemap.xml',
	'Yoast SEO' => '/sitemap_index.xml',
	'Rank Math' => '/sitemap_index.xml',
	'SEOPress'  => '/sitemaps.xml',
);
update_option( 'rank_math_wizard_completed', true ); // As after Rank Math's setup wizard.
update_option( 'rank_math_registration_skip', 1 );

foreach ( $plugins as $name => $file ) {
	echo "== {$name}\n";
	$filled = array();
	try {
		if ( $file ) {
			activate_plugin( $file );
		}
		cli( 'rewrite flush' );
		cli( 'eval "BP_Cache::clear(); BP_Slugs::forget_map();"' );
		// Make the samples complete in each language (temporary translations).
		foreach ( $langs as $l ) {
			$table = TRP_Translate_Press::get_trp_instance()->get_component( 'query' )->get_table_name( $l );
			foreach ( $samples as $s ) {
				$u = BP_Run::url_in( $s, $l );
				for ( $round = 1; $round <= 15; $round++ ) {
					get( $u . '?bp-seo=' . $round . wp_rand() );
					wp_cache_flush();
					$missing = BP_Complete::missing_texts( $l, BP_Complete::key( $s ) );
					if ( ! $missing ) {
						break;
					}
					foreach ( $missing as $t ) {
						// Missing texts are recorded plain and cut at 160 characters: match untranslated rows that
						// start with them (as stored: plain, escaped or texturized), or contain a short one.
						$like = array();
						foreach ( array_unique( array( $t, esc_html( $t ), wptexturize( esc_html( $t ) ) ) ) as $v ) {
							$like[] = $wpdb->prepare( 'original LIKE %s', $wpdb->esc_like( $v ) . '%' );
							if ( mb_strlen( $t ) < 40 ) {
								$like[] = $wpdb->prepare( 'original LIKE %s', '%' . $wpdb->esc_like( $v ) . '%' );
							}
						}
						$ids = $wpdb->get_col( "SELECT id FROM `{$table}` WHERE ( translated = '' OR translated IS NULL ) AND ( " . implode( ' OR ', $like ) . ' )' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- parts prepared above.
						foreach ( $ids as $id ) {
							$wpdb->update( $table, array( 'translated' => '[' . $l . '] ' . $t, 'status' => 2 ), array( 'id' => (int) $id ) );
							$filled[ $table ][] = (int) $id;
						}
					}
				}
			}
		}
		cli( 'eval "BP_Cache::clear();"' );

		foreach ( $samples as $s ) {
			foreach ( array_merge( array( '' ), $langs ) as $l ) {
				$u = '' === $l ? $s : BP_Run::url_in( $s, $l );
				list( $code, $html ) = get( $u );
				ok( 200 === $code, "{$name}: status 200", $u, (string) $code );
				$canon = in_head( $html, '/<link\s+rel=["\']canonical["\']\s+href=["\']([^"\']*)/i' );
				ok( array( $u ) === $canon, "{$name}: exactly one canonical = the page", $u, implode( ' , ', $canon ) ?: 'none' );
				$titles = in_head( $html, '#<title[^>]*>(.*?)</title>#is' );
				ok( 1 === count( $titles ), "{$name}: one <title>", $u, (string) count( $titles ) );
				foreach ( array( 'description' => '/<meta\s+name=["\']description["\']\s+content=["\']([^"\']*)/i', 'og:title' => '/<meta\s+property=["\']og:title["\']\s+content=["\']([^"\']*)/i', 'og:description' => '/<meta\s+property=["\']og:description["\']\s+content=["\']([^"\']*)/i' ) as $tag => $re ) {
					$n = count( in_head( $html, $re ) );
					ok( $n <= 1, "{$name}: at most one {$tag}", $u, (string) $n );
				}
				$ogu = in_head( $html, '/<meta\s+property=["\']og:url["\']\s+content=["\']([^"\']*)/i' );
				ok( count( $ogu ) <= 1 && ( ! $ogu || $ogu[0] === $u ), "{$name}: og:url = the page", $u, implode( ' , ', $ogu ) );
				preg_match_all( '/<link\s+rel=["\']alternate["\']\s+hreflang=["\']([^"\']*)["\']\s+href=["\']([^"\']*)/i', (string) strstr( $html, '</head>', true ), $m, PREG_SET_ORDER );
				$codes = array_column( $m, 1 );
				ok( count( $codes ) === count( array_unique( $codes ) ), "{$name}: no duplicate hreflang", $u );
				ok( in_array( 'x-default', $codes, true ), "{$name}: x-default", $u );
				ok( in_array( $u, array_column( $m, 2 ), true ), "{$name}: hreflang lists the page", $u );
				if ( '' !== $l ) {
					ok( false === strpos( $html, 'id="bp-untranslated"' ), "{$name}: translated page served (complete)", $u );
					ok( false !== strpos( implode( '', $titles ), '[' . $l . ']' ) || false === strpos( implode( '', $titles ), 'About' ), "{$name}: title translated", $u, implode( '', $titles ) );
				}
			}
		}

		// Sitemaps: the active index leads to the translated pages.
		list( $code, $index ) = get( $home . $index_of[ $name ] );
		ok( 200 === $code, "{$name}: sitemap index answers", $home . $index_of[ $name ], (string) $code );
		preg_match_all( '#<loc>([^<]*)</loc>#', $index, $locs );
		$lang_maps = array_values( array_filter( $locs[1], static fn( $x ) => (bool) preg_match( '/(bp-sitemap|wp-sitemap-languages)-fr-\d+\.xml/', $x ) ) );
		ok( (bool) $lang_maps, "{$name}: French sitemap in the index", $home . $index_of[ $name ] );
		if ( $lang_maps ) {
			list( $c2, $x2 ) = get( html_entity_decode( $lang_maps[0] ) );
			$fr = BP_Run::url_in( $samples[0], 'fr_FR' );
			ok( 200 === $c2 && false !== simplexml_load_string( $x2 ) && false !== strpos( $x2, $fr ), "{$name}: French sitemap valid and lists the page", $lang_maps[0], $c2 . ' ' . ( false === strpos( $x2, $fr ) ? 'page missing' : '' ) );
		}
		// noindex (T2): mark the first sample noindex the way this system does it; its translated
		// versions must leave the language sitemap, the other sample must stay, canonical and
		// hreflang unchanged; then remove it again.
		$about_id = url_to_postid( $samples[0] );
		$mu       = WPMU_PLUGIN_DIR . '/bp-test-noindex.php';
		$set_ni   = function ( $on ) use ( $name, $about_id, $mu ) {
			// Through WP-CLI (a normal WordPress request with the SEO plugin loaded), so the plugin's
			// own watchers see the change (Yoast keeps robots settings in its indexables table).
			foreach ( array( '_yoast_wpseo_meta-robots-noindex', 'rank_math_robots', '_seopress_robots_index' ) as $meta ) {
				cli( 'post meta delete ' . (int) $about_id . ' ' . $meta );
			}
			if ( is_file( $mu ) ) {
				unlink( $mu ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- test file.
			}
			if ( $on ) {
				if ( 'Yoast SEO' === $name ) {
					cli( 'post meta update ' . (int) $about_id . ' _yoast_wpseo_meta-robots-noindex 1' );
				} elseif ( 'Rank Math' === $name ) {
					cli( 'post meta update ' . (int) $about_id . ' rank_math_robots ' . escapeshellarg( '["noindex"]' ) . ' --format=json' );
				} elseif ( 'SEOPress' === $name ) {
					cli( 'post meta update ' . (int) $about_id . ' _seopress_robots_index yes' );
				} else {
					// No SEO plugin: WordPress core's own robots API.
					file_put_contents( $mu, "<?php\nadd_filter( 'wp_robots', function ( \$r ) { if ( is_singular() && (int) get_queried_object_id() === " . (int) $about_id . " ) { \$r['noindex'] = true; } return \$r; } );\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- test file, disposable copy.
				}
			}
			wp_cache_flush();
		};
		$sitemap_has = function ( $url ) use ( $home, $index_of, $name ) {
			list( , $ix ) = get( $home . $index_of[ $name ] );
			preg_match_all( '#<loc>([^<]*(?:bp-sitemap|wp-sitemap-languages)-fr-\d+\.xml)</loc>#', $ix, $mm );
			foreach ( $mm[1] as $map ) {
				if ( false !== strpos( get( html_entity_decode( $map ) )[1], $url ) ) {
					return true;
				}
			}
			return false;
		};
		$fr_about = BP_Run::url_in( $samples[0], 'fr_FR' );
		$fr_tour  = BP_Run::url_in( $samples[1], 'fr_FR' );
		try {
			$set_ni( true );
			cli( 'eval "BP_Cache::clear();"' );
			list( , $h ) = get( $fr_about . '?bp-ni=' . wp_rand() ); // Built once: the record notes noindex.
			ok( BP_Complete::says_noindex( $h ), "{$name}: noindex printed on the translated page", $fr_about );
			$robots_tags = in_head( $h, '/<meta\s+name=["\']robots["\']\s+content=["\']([^"\']*)/i' );
			ok( 1 === count( $robots_tags ), "{$name}: one robots tag (no duplicate)", $fr_about, implode( ' | ', $robots_tags ) );
			list( , $h ) = get( $fr_about );
			$canon    = in_head( $h, '/<link\s+rel=["\']canonical["\']\s+href=["\']([^"\']*)/i' );
			$en_canon = in_head( get( $samples[0] . '?bp-ni-en=' . wp_rand() )[1], '/<link\s+rel=["\']canonical["\']\s+href=["\']([^"\']*)/i' );
			// Some SEO plugins print no canonical on a noindex page: the translated page must do the same
			// as the original (none, or itself), never point elsewhere or print two.
			ok( count( $canon ) === count( $en_canon ) && ( ! $canon || array( $fr_about ) === $canon ), "{$name}: noindex page canonical as on the original", $fr_about, implode( ' , ', $canon ) . ' (original: ' . ( implode( ' , ', $en_canon ) ?: 'none' ) . ')' );
			ok( false !== strpos( $h, 'hreflang="x-default"' ), "{$name}: noindex page keeps its hreflang", $fr_about );
			wp_cache_flush();
			ok( ! $sitemap_has( $fr_about ), "{$name}: noindex page left out of the language sitemap", $fr_about );
			ok( $sitemap_has( $fr_tour ), "{$name}: indexable page stays in the language sitemap", $fr_tour );
		} finally {
			$set_ni( false );
			cli( 'eval "BP_Cache::clear();"' );
			get( $fr_about . '?bp-ni=' . wp_rand() ); // Built again: the record is cleared.
		}
		wp_cache_flush();
		ok( $sitemap_has( $fr_about ), "{$name}: back in the sitemap once noindex is removed", $fr_about );

		$robots = apply_filters( 'robots_txt', '', true );
		if ( BP_Sitemap_Standalone::active() ) {
			ok( false !== strpos( $robots, 'bp-sitemap.xml' ), "{$name}: robots.txt names the language sitemaps" );
		}
	} finally {
		foreach ( $filled as $table => $ids ) {
			$wpdb->query( "UPDATE `{$table}` SET translated = '', status = 0 WHERE id IN (" . implode( ',', array_map( 'intval', $ids ) ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		if ( $file ) {
			deactivate_plugins( $file );
		}
		cli( 'rewrite flush' );
		cli( 'eval "BP_Cache::clear();"' );
	}
}

echo "\n{$pass} passed, {$fail} failed\n";
if ( ! empty( $opts['report'] ) ) {
	file_put_contents( $opts['report'], "# Beaver Press with SEO plugins\n\n**{$pass} passed, {$fail} failed**\n\n" . ( $lines ? "| | Check | URL | Detail |\n|---|---|---|---|\n" . implode( "\n", $lines ) . "\n" : '' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
}
exit( $fail ? 1 : 0 );
