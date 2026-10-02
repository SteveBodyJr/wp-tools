<?php
/**
 * Debug mode for administrators: add ?bp_debug=1 to any page while logged in with
 * manage_options (or use the admin-bar link). Visitors never see it, and such requests are
 * never served from or stored in the ready-page cache (they carry a query string).
 *
 * A panel at the bottom of the page (never translated itself) shows:
 * - every text of the page in this language: source, translation, engine row id, source and
 *   target language, status, type, provider, model, source hash, provenance status;
 * - the page: language, original address, translated address and prefix, cache state,
 *   completeness and missing texts, engine and model (never a key: only whether one is saved),
 *   the provider's last error;
 * - problems found: missing or outdated translations, routing (address rewritten, old address),
 *   canonical (none, several, not the page), hreflang (no self, no x-default, duplicates,
 *   pointing to pages not complete), provider failures, cache folder not writable.
 *
 * @package BeaverPress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Debug panel.
 */
final class BP_Debug {

	/** Texts of this page in this language: [ original, translation, node type ]. */
	private static $strings = array();

	/** Language of this request. */
	private static $language = '';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'maybe_enable' ), 30 );
		add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar' ), 100 );
	}

	/**
	 * Whether this request shows the panel.
	 *
	 * @return bool
	 */
	public static function on() {
		return ! is_admin() && isset( $_GET['bp_debug'] ) && function_exists( 'current_user_can' ) && current_user_can( 'manage_options' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view for administrators.
	}

	/**
	 * Turn on for this request.
	 */
	public static function maybe_enable() {
		if ( ! self::on() ) {
			return;
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // Page-cache plugins: never store a debug page.
		}
		add_action( 'trp_translateable_information', array( __CLASS__, 'collect' ), 20, 3 );
		add_filter( 'trp_translated_html', array( __CLASS__, 'panel_translated' ), 2000, 4 );
		add_action( 'template_redirect', array( __CLASS__, 'buffer_original' ), 9999 );
	}

	/**
	 * Admin bar: a link that turns the panel on or off for this page.
	 *
	 * @param WP_Admin_Bar $bar Admin bar.
	 */
	public static function admin_bar( $bar ) {
		if ( is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$url = self::on() ? remove_query_arg( 'bp_debug' ) : add_query_arg( 'bp_debug', '1' );
		$bar->add_node(
			array(
				'id'    => 'bp-debug',
				'title' => self::on() ? __( 'Beaver Press debug: on', 'beaver-press' ) : __( 'Beaver Press debug', 'beaver-press' ),
				'href'  => esc_url( $url ),
			)
		);
	}

	/**
	 * The engine reports the page's texts and their translations.
	 *
	 * @param array  $info       Strings and nodes.
	 * @param array  $translated Translations by index.
	 * @param string $language   Language code.
	 */
	public static function collect( $info, $translated, $language = '' ) {
		self::$language = (string) $language;
		$nodes          = (array) ( $info['nodes'] ?? array() );
		foreach ( (array) ( $info['translateable_strings'] ?? array() ) as $i => $text ) {
			self::$strings[] = array( (string) $text, (string) ( $translated[ $i ] ?? '' ), (string) ( $nodes[ $i ]['type'] ?? '' ) );
		}
	}

	/**
	 * Original-language pages: append the panel through an output buffer.
	 */
	public static function buffer_original() {
		global $TRP_LANGUAGE;
		$settings = TRP_Translate_Press::get_trp_instance()->get_component( 'settings' )->get_settings();
		if ( (string) $TRP_LANGUAGE === (string) $settings['default-language'] ) {
			ob_start( array( __CLASS__, 'panel_original' ) );
		}
	}

	/**
	 * Panel on an original-language page.
	 *
	 * @param string $html Page.
	 * @return string
	 */
	public static function panel_original( $html ) {
		return self::append( (string) $html, '' );
	}

	/**
	 * Panel on a translated page (after Beaver Press decided complete or fallback).
	 *
	 * @param string $html     Page.
	 * @param string $language Language.
	 * @param string $code     Language code.
	 * @param bool   $preview  Editor preview.
	 * @return string
	 */
	public static function panel_translated( $html, $language = '', $code = '', $preview = false ) {
		global $TRP_LANGUAGE;
		return $preview ? $html : self::append( (string) $html, (string) $TRP_LANGUAGE );
	}

	/**
	 * Put the panel before </body>.
	 *
	 * @param string $html     Page.
	 * @param string $language Language ('' = original).
	 * @return string
	 */
	private static function append( $html, $language ) {
		$pos = strripos( $html, '</body>' );
		if ( false === $pos ) {
			return $html;
		}
		return substr( $html, 0, $pos ) . self::panel( $html, $language ) . substr( $html, $pos );
	}

	/* ------------------------------------------------------------------ */
	/* Facts                                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * Page-level facts and problems.
	 *
	 * @param string $html     Final HTML.
	 * @param string $language Language ('' = original).
	 * @return array [ facts (label => value), problems (string[]) ].
	 */
	public static function diagnose( $html, $language ) {
		$trp      = TRP_Translate_Press::get_trp_instance();
		$settings = $trp->get_component( 'settings' )->get_settings();
		$default  = (string) $settings['default-language'];
		$lang     = '' === $language ? $default : $language;
		$scheme   = is_ssl() ? 'https://' : 'http://';
		$here     = $scheme . (string) ( $_SERVER['HTTP_HOST'] ?? '' ) . strtok( (string) wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ), '?' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- shown escaped.
		$original = strtok( (string) $trp->get_component( 'url_converter' )->get_url_for_language( $default, null, '' ), '?' );
		$head     = (string) strstr( $html, '</head>', true );
		$facts    = array();
		$problems = array();

		$facts[ __( 'Language', 'beaver-press' ) ]         = $lang . ( $lang === $default ? ' (' . __( 'original', 'beaver-press' ) . ')' : '' );
		$facts[ __( 'This address', 'beaver-press' ) ]     = $here;
		$facts[ __( 'Original address', 'beaver-press' ) ] = $original;

		// Routing.
		if ( $lang !== $default && class_exists( 'BP_Slugs' ) && BP_Slugs::enabled() ) {
			$path_orig = trim( (string) substr( $original, strlen( untrailingslashit( home_url() ) ) ), '/' );
			$facts[ __( 'Translated address', 'beaver-press' ) ] = BP_Slugs::translate_path( $path_orig, $default, $lang );
			$facts[ __( 'Prefixes', 'beaver-press' ) ]           = wp_json_encode( BP_Slug_Bases::saved()[ $lang ] ?? array() );
			$target = BP_Slugs::old_address_target();
			if ( '' !== $target ) {
				/* translators: %s: address */
				$problems[] = sprintf( __( 'Routing: this is an old address; visitors are sent (301) to %s.', 'beaver-press' ), $target );
			}
		}

		// Completeness.
		$fallback = false !== strpos( $html, 'id="bp-untranslated"' );
		if ( $lang !== $default ) {
			$missing = BP_Complete::missing_texts( $lang, BP_Complete::key( $original ) );
			$facts[ __( 'Complete', 'beaver-press' ) ] = $fallback ? __( 'no: visitors get the original page (noindex)', 'beaver-press' ) : __( 'yes', 'beaver-press' );
			if ( $fallback || $missing ) {
				/* translators: %s: texts */
				$problems[] = sprintf( __( 'Missing translations: %s', 'beaver-press' ), implode( ' | ', $missing ) ?: __( '(see the texts below)', 'beaver-press' ) );
			}
		}

		// Canonical.
		preg_match_all( '/<link\s+rel=["\']canonical["\']\s+href=["\']([^"\']*)/i', $head, $m );
		$facts[ __( 'Canonical', 'beaver-press' ) ] = implode( ' , ', $m[1] ) ?: __( 'none', 'beaver-press' );
		$want = $fallback ? $original : $here;
		if ( 1 !== count( $m[1] ) ) {
			/* translators: %d: count */
			$problems[] = sprintf( __( 'Canonical: %d tags (expected one).', 'beaver-press' ), count( $m[1] ) );
		} elseif ( html_entity_decode( $m[1][0] ) !== $want ) {
			/* translators: %s: expected address */
			$problems[] = sprintf( __( 'Canonical: does not point to %s.', 'beaver-press' ), $want );
		}

		// hreflang.
		preg_match_all( '/<link\s+rel=["\']alternate["\']\s+hreflang=["\']([^"\']*)["\']\s+href=["\']([^"\']*)/i', $head, $h, PREG_SET_ORDER );
		$codes = array_column( $h, 1 );
		$facts['hreflang'] = implode( ', ', $codes ) ?: __( 'none', 'beaver-press' );
		if ( ! $fallback ) {
			if ( count( $codes ) !== count( array_unique( $codes ) ) ) {
				$problems[] = __( 'hreflang: a language is listed twice.', 'beaver-press' );
			}
			if ( ! in_array( 'x-default', $codes, true ) ) {
				$problems[] = __( 'hreflang: no x-default.', 'beaver-press' );
			}
			if ( ! in_array( $here, array_map( 'html_entity_decode', array_column( $h, 2 ) ), true ) ) {
				$problems[] = __( 'hreflang: the page does not list itself.', 'beaver-press' );
			}
			foreach ( $h as $alt ) {
				foreach ( array_keys( BP_Run::languages() ) as $code ) {
					if ( 0 === stripos( str_replace( '_', '-', $code ), $alt[1] ) && BP_Complete::known_incomplete( $code, $original ) ) {
						/* translators: %s: hreflang code */
						$problems[] = sprintf( __( 'hreflang: %s points to a version that is not complete.', 'beaver-press' ), $alt[1] );
					}
				}
			}
		} elseif ( $codes ) {
			$problems[] = __( 'hreflang: a fallback page should carry none.', 'beaver-press' );
		}

		// Cache.
		$facts[ __( 'Ready pages', 'beaver-press' ) ] = BP_Cache::enabled() ? sprintf( /* translators: %d: generation */ __( 'on (generation %d); this debug view is never cached', 'beaver-press' ), BP_Cache::gen() ) : __( 'off', 'beaver-press' );
		if ( BP_Cache::enabled() && ! wp_is_writable( dirname( BP_Cache::base() ) ) ) {
			$problems[] = __( 'Cache: the ready-pages folder is not writable.', 'beaver-press' );
		}

		// Provider (never a key).
		$mt     = get_option( 'trp_machine_translation_settings', array() );
		$engine = BP_Engine_Settings::current( is_array( $mt ) ? $mt : array() );
		$facts[ __( 'Engine', 'beaver-press' ) ] = $engine['provider'] . ' / ' . $engine['model'] . ' - ' . ( '' !== BP_Keys::get( $engine['provider'] ) ? __( 'key saved', 'beaver-press' ) : __( 'no key saved', 'beaver-press' ) );
		$error = BP_Providers::last_error();
		if ( $error ) {
			/* translators: 1: time ago, 2: code, 3: message */
			$problems[] = sprintf( __( 'Provider failure %1$s ago (%2$s): %3$s', 'beaver-press' ), human_time_diff( (int) $error['time'] ), $error['code'], wp_strip_all_tags( (string) $error['message'] ) );
		}
		return array( $facts, $problems );
	}

	/**
	 * Rows for the texts table.
	 *
	 * @param string $language Language.
	 * @return array[]
	 */
	public static function rows( $language ) {
		if ( '' === $language || ! self::$strings ) {
			return array();
		}
		$query  = TRP_Translate_Press::get_trp_instance()->get_component( 'query' );
		$by     = array();
		$unique = array_values( array_unique( array_column( self::$strings, 0 ) ) );
		foreach ( array_chunk( $unique, 300 ) as $chunk ) {
			foreach ( (array) $query->get_string_rows( array(), $chunk, $language ) as $r ) {
				$by[ (string) $r->original ] = $r;
			}
		}
		$labels = array( 0 => __( 'not translated', 'beaver-press' ), 1 => __( 'machine', 'beaver-press' ), 2 => __( 'manual', 'beaver-press' ) );
		$out    = array();
		$seen   = array();
		foreach ( self::$strings as $s ) {
			list( $original, $translation, $type ) = $s;
			if ( isset( $seen[ $original ] ) ) {
				continue;
			}
			$seen[ $original ] = true;
			$row               = $by[ $original ] ?? null;
			$prov              = class_exists( 'BP_Provenance' ) ? BP_Provenance::get( $language, $original ) : null;
			$out[]             = array(
				'source'      => $original,
				'translation' => '' !== $translation ? $translation : (string) ( $row->translated ?? '' ),
				'id'          => $row ? (int) $row->id : 0,
				'status'      => $row ? ( $labels[ (int) $row->status ] ?? (string) $row->status ) : __( 'not stored yet', 'beaver-press' ),
				'type'        => $type ? $type : 'text',
				// No row: made before provenance existed (2.6.0) - shown as unknown, never guessed.
				'provenance'  => $prov ? $prov['status'] . ( (int) $prov['edited_after_ai'] ? ' (after AI)' : '' ) : ( $row && (int) $row->status > 0 ? __( 'legacy (provider unknown)', 'beaver-press' ) : '' ),
				'provider'    => $prov ? trim( $prov['provider'] . ' ' . $prov['model'] ) : '',
				'hash'        => md5( $original ),
				'when'        => $prov ? (string) $prov['updated_at'] : '',
			);
		}
		return $out;
	}

	/**
	 * The panel HTML (built as a string: it runs inside output-buffer callbacks, where PHP
	 * allows no further buffering).
	 *
	 * @param string $html     Final page.
	 * @param string $language Language ('' = original).
	 * @return string
	 */
	private static function panel( $html, $language ) {
		list( $facts, $problems ) = self::diagnose( $html, $language );
		$rows     = self::rows( $language );
		$settings = TRP_Translate_Press::get_trp_instance()->get_component( 'settings' )->get_settings();
		$e        = 'esc_html';
		$out      = '<div id="bp-debug" data-no-translation translate="no" style="position:fixed;left:0;right:0;bottom:0;max-height:45vh;overflow:auto;z-index:2147483647;background:#fff;color:#1d2327;border-top:3px solid #2271b1;font:12px/1.4 -apple-system,BlinkMacSystemFont,\'Segoe UI\',sans-serif;padding:8px 12px;box-shadow:0 -4px 16px rgba(0,0,0,.2)">'
			. '<strong>Beaver Press debug</strong> <button type="button" onclick="this.parentNode.remove()" style="float:right">' . $e( __( 'Close', 'beaver-press' ) ) . '</button>'
			. '<div style="display:flex;flex-wrap:wrap;gap:4px 16px;margin:6px 0">';
		foreach ( $facts as $k => $v ) {
			$out .= '<span><b>' . $e( (string) $k ) . ':</b> ' . $e( (string) $v ) . '</span>';
		}
		$out .= '</div>';
		if ( $problems ) {
			$out .= '<ul style="margin:4px 0 8px 16px;color:#8a2424;list-style:disc">';
			foreach ( $problems as $p ) {
				$out .= '<li>' . $e( $p ) . '</li>';
			}
			$out .= '</ul>';
		} else {
			$out .= '<p style="color:#00a32a;margin:4px 0">' . $e( __( 'No problems found on this page.', 'beaver-press' ) ) . '</p>';
		}
		if ( $rows ) {
			$heads = array(
				__( 'Source', 'beaver-press' ) . ' (' . $settings['default-language'] . ')',
				__( 'Translation', 'beaver-press' ) . ' (' . $language . ')',
				'ID',
				__( 'Status', 'beaver-press' ),
				__( 'Type', 'beaver-press' ),
				__( 'Provenance', 'beaver-press' ),
				__( 'Provider / model', 'beaver-press' ),
				__( 'Source hash', 'beaver-press' ),
			);
			$out .= '<input type="search" placeholder="' . esc_attr__( 'Filter texts', 'beaver-press' ) . '" oninput="var q=this.value.toLowerCase();document.querySelectorAll(\'#bp-debug tbody tr\').forEach(function(r){r.hidden=q&amp;&amp;r.textContent.toLowerCase().indexOf(q)&lt;0;})" style="margin:4px 0" />'
				. '<table style="width:100%;border-collapse:collapse"><thead><tr style="text-align:left;border-bottom:1px solid #ccc">';
			foreach ( $heads as $h ) {
				$out .= '<th>' . $e( $h ) . '</th>';
			}
			$out .= '</tr></thead><tbody>';
			foreach ( $rows as $r ) {
				$bg   = ( 'not translated' === $r['status'] || '' === $r['translation'] ) ? 'background:#fcf0f1' : ( 0 === strpos( $r['provenance'], 'outdated' ) ? 'background:#fcf9e8' : '' );
				$find = wp_strip_all_tags( '' !== $r['translation'] ? $r['translation'] : $r['source'] );
				$out .= '<tr style="border-bottom:1px solid #eee;' . $bg . '" onclick="window.find &amp;&amp; window.find(' . esc_attr( (string) wp_json_encode( $find ) ) . ')">';
				foreach ( array( wp_strip_all_tags( $r['source'] ), wp_strip_all_tags( $r['translation'] ), (string) $r['id'], $r['status'], $r['type'], $r['provenance'], $r['provider'] ) as $cell ) {
					$out .= '<td>' . $e( $cell ) . '</td>';
				}
				$out .= '<td><code>' . $e( substr( $r['hash'], 0, 10 ) ) . '</code></td></tr>';
			}
			$out .= '</tbody></table>';
		}
		return $out . '</div>';
	}
}
