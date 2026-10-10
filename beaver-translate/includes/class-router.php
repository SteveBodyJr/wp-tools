<?php
/**
 * Language-prefixed URL routing.
 *
 * @package BeaverTranslate
 */

defined( 'ABSPATH' ) || exit;

/**
 * Duplicates WordPress's own, already-correct rewrite rules with a language
 * prefix, and confirms a resolved request actually belongs to that language.
 *
 * WordPress's rewrite matching is a single flat pass over one list of
 * regexes — there is no way to say "strip a prefix, then re-run matching on
 * what's left." The only complete, proven way to add a language segment in
 * front of every permalink shape a site has — posts, pages, archives,
 * whatever a theme or another plugin has registered — is to take the rules
 * WordPress has already built correctly and duplicate each one with the
 * prefix prepended. This is the same approach Polylang and WPML both use.
 * Rebuilding permalink-structure regexes from scratch here would mean
 * re-solving a problem WP_Rewrite has already solved, worse.
 *
 * @since 1.1.0
 */
class Beaver_Translate_Router {

	const QUERY_VAR = 'beaver_translate_lang';

	/**
	 * The language this request was resolved as, set on `parse_request`.
	 *
	 * @var string|null
	 */
	private static $current_language = null;

	/**
	 * Registers the hooks.
	 *
	 * @since 1.1.0
	 */
	public static function init() {
		add_filter( 'query_vars', array( __CLASS__, 'add_query_var' ) );
		add_filter( 'rewrite_rules_array', array( __CLASS__, 'prefix_rules' ) );
		add_action( 'parse_request', array( __CLASS__, 'set_current_language' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'filter_query_by_language' ) );

		// Priority 0: this has to decide whether the request is even valid
		// before anything else — a theme's own template_redirect hooks, an
		// SEO plugin's canonical logic — reasons about the "wrong" post.
		add_action( 'template_redirect', array( __CLASS__, 'verify_post_language' ), 0 );
	}

	/**
	 * Registers the query variable a prefixed rule feeds a language code into.
	 *
	 * @since 1.1.0
	 *
	 * @param string[] $vars Existing query variables.
	 * @return string[] Filtered query variables.
	 */
	public static function add_query_var( $vars ) {
		$vars[] = self::QUERY_VAR;

		return $vars;
	}

	/**
	 * Duplicates the assembled rewrite rules with a language prefix.
	 *
	 * Runs on `rewrite_rules_array`, the last filter WordPress applies before
	 * caching the rules — by this point every rule from core, every post
	 * type, every taxonomy, every theme and every other active plugin is
	 * already merged into one array, so duplicating it here covers all of
	 * them without this class needing to know any of them exist.
	 *
	 * @since 1.1.0
	 *
	 * @param array $rules Regex => query string, as WordPress built it.
	 * @return array Rules with a language-prefixed duplicate of each entry
	 *               worth duplicating, checked before the originals.
	 */
	public static function prefix_rules( $rules ) {
		$languages = Beaver_Translate_Languages::targets();

		if ( empty( $languages ) || empty( $rules ) ) {
			return $rules;
		}

		$codes = array_map(
			static function ( $language ) {
				return preg_quote( $language['code'], '#' );
			},
			$languages
		);

		$prefix   = '(' . implode( '|', $codes ) . ')/';
		$prefixed = array();

		foreach ( $rules as $regex => $query ) {
			if ( self::is_excluded( $query ) ) {
				continue;
			}

			$prefixed[ $prefix . $regex ] = self::shift_and_prepend( $query );
		}

		/*
		 * Checked before the originals, deliberately. Without this order, a
		 * request for `/fr/my-post/` could match the *unprefixed* post rule
		 * first — most permalink structures accept any non-slash characters
		 * as a post name, and "fr" alone would happily match one — and never
		 * reach the prefixed rule at all.
		 */
		return $prefixed + $rules;
	}

	/**
	 * Whether a rule's query string identifies something that should never
	 * exist a second time under a language prefix.
	 *
	 * @since 1.1.0
	 *
	 * @param string $query The rule's `index.php?...` query string.
	 * @return bool
	 */
	private static function is_excluded( $query ) {
		$excluded_vars = array(
			'rest_route',          // The REST API is not user-facing content.
			'beaver_seo_sitemap',  // A sitemap has no reason to exist per language.
		);

		foreach ( $excluded_vars as $var ) {
			if ( false !== strpos( $query, $var . '=' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Renumbers a rule's `$matches[N]` backreferences up by one, and injects
	 * the language capture as the new `$matches[1]`.
	 *
	 * Prepending a capturing group to a regex shifts every group after it up
	 * by one position — the old group 1 becomes group 2, and so on — so the
	 * query string has to be rewritten to match. Indices are replaced highest
	 * first: replacing `$matches[1]` with `$matches[2]` before `$matches[2]`
	 * has been shifted to `$matches[3]` would make the two indistinguishable
	 * and silently merge them.
	 *
	 * @since 1.1.0
	 *
	 * @param string $query Original `index.php?...` query string.
	 * @return string Query string with backreferences shifted and the
	 *                language variable injected.
	 */
	private static function shift_and_prepend( $query ) {
		preg_match_all( '/\$matches\[(\d+)\]/', $query, $found );

		$indices = array_unique( array_map( 'intval', $found[1] ) );
		rsort( $indices );

		foreach ( $indices as $index ) {
			$query = str_replace( '$matches[' . $index . ']', '$matches[' . ( $index + 1 ) . ']', $query );
		}

		if ( false !== strpos( $query, 'index.php?' ) ) {
			$query = str_replace( 'index.php?', 'index.php?' . self::QUERY_VAR . '=$matches[1]&', $query );
		} else {
			$query = 'index.php?' . self::QUERY_VAR . '=$matches[1]&' . $query;
		}

		return rtrim( $query, '&' );
	}

	/**
	 * Records the language a prefixed rule matched, for this request.
	 *
	 * Read from `$wp->query_vars` rather than `get_query_var()`, because this
	 * runs on `parse_request` — before the main `WP_Query` exists to read
	 * from.
	 *
	 * @since 1.1.0
	 *
	 * @param WP $wp The main WP instance, mid-request.
	 */
	public static function set_current_language( $wp ) {
		$code = isset( $wp->query_vars[ self::QUERY_VAR ] )
			? Beaver_Translate_Languages::sanitize_code( $wp->query_vars[ self::QUERY_VAR ] )
			: '';

		self::$current_language = Beaver_Translate_Languages::is_valid( $code )
			? $code
			: Beaver_Translate_Languages::default_code();
	}

	/**
	 * The language this request resolved as.
	 *
	 * Available from `parse_request` onward. Before that — or on a request
	 * this router never saw, such as an AJAX call — returns the site's
	 * default language rather than an empty string, since "no language
	 * detected" and "the default language" mean the same thing here.
	 *
	 * @since 1.1.0
	 *
	 * @return string Language code.
	 */
	public static function current_language() {
		return self::$current_language ?? Beaver_Translate_Languages::default_code();
	}

	/**
	 * Restricts a non-singular main query — an archive, the posts-page
	 * front page, a date or author archive, a feed sharing any of those
	 * queries — to the requested language's own posts.
	 *
	 * verify_post_language() below only ever has one resolved post to check
	 * a language against, after the query already ran. An archive has no
	 * single post to check — it is a list the query itself builds — so the
	 * only way to keep a `/fr/` category or date archive from listing
	 * English posts alongside French ones is to shape the query before it
	 * runs, not inspect its result afterward. A feed reuses whichever of
	 * these queries backs it, so filtering here covers it too without a
	 * separate check.
	 *
	 * Every post this plugin creates carries its own language in postmeta;
	 * a post that predates this plugin, or was simply never translated,
	 * carries none. Requesting the site's default language leaves that
	 * meaning what it already means everywhere else in this class — "no
	 * language filtering, nothing about this request changes" — while
	 * requesting any other configured language narrows the query to only
	 * what was actually translated into it, term-name mislabelling aside
	 * (see the note on taxonomy terms elsewhere in this plugin).
	 *
	 * @since 1.5.0
	 *
	 * @param WP_Query $query The query being filtered.
	 */
	public static function filter_query_by_language( $query ) {
		if ( is_admin() || ! $query->is_main_query() || $query->is_singular() ) {
			return;
		}

		$requested = self::current_language();
		$default   = Beaver_Translate_Languages::default_code();

		if ( $default === $requested ) {
			return;
		}

		$meta_query = (array) $query->get( 'meta_query' );

		$meta_query[] = array(
			'key'   => Beaver_Translate_Translator::META_LANGUAGE,
			'value' => $requested,
		);

		$query->set( 'meta_query', $meta_query );
	}

	/**
	 * Confirms a resolved singular post actually belongs to the requested
	 * language, and corrects the two ways that can be wrong.
	 *
	 * A translation visited at its own bare slug — no language prefix at
	 * all — is redirected to its correct prefixed URL, so a translation is
	 * only ever reachable at one canonical address. A language-prefixed
	 * request that resolves to a post which is not actually that language's
	 * translation — the source post, or nothing published in that language
	 * yet — 404s rather than silently showing the wrong language's content.
	 *
	 * Scope note: this covers singular posts and pages. Archives, the front
	 * page and feeds are covered separately, by filter_query_by_language()
	 * above, since a list of posts has nothing singular here to verify.
	 *
	 * @since 1.1.0
	 */
	public static function verify_post_language() {
		if ( ! is_singular() ) {
			return;
		}

		$post = get_queried_object();

		if ( ! $post instanceof WP_Post ) {
			return;
		}

		$requested = self::current_language();
		$default   = Beaver_Translate_Languages::default_code();
		$post_lang = get_post_meta( $post->ID, Beaver_Translate_Translator::META_LANGUAGE, true );
		$is_translation = '' !== $post_lang && Beaver_Translate_Languages::is_valid( $post_lang );

		if ( $default === $requested ) {
			/*
			 * No language prefix was used. A translation is only ever
			 * canonical at its prefixed address — send visitors (and search
			 * engines) there instead of serving it twice.
			 *
			 * Gated on the language still being configured: Permalinks::
			 * add_prefix() correctly refuses to prefix a URL for a language
			 * that is no longer valid, which would otherwise mean
			 * redirecting to the exact URL already being served — every
			 * request to a translation whose language was later removed
			 * from settings looping forever instead of simply rendering.
			 */
			if ( $is_translation ) {
				$url = get_permalink( $post );

				if ( $url ) {
					wp_safe_redirect( $url, 301 );
					exit;
				}
			}

			return;
		}

		// A language prefix was used. What it resolved to must actually be
		// that language's translation — not the source post, and not a
		// translation into some other language that merely shares this slug
		// by coincidence.
		if ( ! $is_translation || $post_lang !== $requested ) {
			global $wp_query;

			$wp_query->set_404();
			status_header( 404 );
		}
	}
}
