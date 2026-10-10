<?php
/**
 * Crawlers.
 *
 * @package BeaverShield
 */

defined( 'ABSPATH' ) || exit;

/**
 * Decides what the answer engines are told.
 *
 * Worth being clear about what is and is not possible here, because this is the
 * part most often sold as protection and is not.
 *
 * An AI crawler fetches raw HTML over HTTP. It runs no JavaScript, so the copy
 * deterrent in this plugin is invisible to it, and the text of every page is
 * readable by every one of them today. The only lever that exists is a
 * directive in robots.txt naming each bot, and that is honoured by choice: the
 * major labs respect it, and anything presenting itself as a browser does not.
 * Enforcement is a job for the network layer, not a plugin.
 *
 * Which leaves a commercial decision rather than a technical one, and the
 * default here takes the other side of it. Blocking these crawlers removes a
 * site from ChatGPT, Perplexity and AI Overviews, which is a real and growing
 * source of high intent traffic, in exchange for withholding text a competitor
 * could copy by hand anyway. So they are allowed, and the site publishes an
 * llms.txt instead: a plain text map that tells a model what this business
 * does, which pages matter, and how it expects to be credited. Better to be
 * read accurately than not read at all.
 *
 * The block list is still here for the sites that want it.
 *
 * @since 1.0.0
 */
class Beaver_Shield_Crawlers {

	const TRANSIENT = 'beaver_shield_llms_txt';

	/**
	 * Registers hooks.
	 *
	 * @since 1.0.0
	 */
	public static function init() {
		add_filter( 'robots_txt', array( __CLASS__, 'filter_robots' ), 20, 2 );

		if ( Beaver_Shield_Settings::on( 'llms_txt' ) ) {
			// Late on purpose. A site that already publishes an llms.txt, from a
			// theme or another plugin, has one written for that site by someone
			// who knew it. Serving ours over the top would replace a considered
			// document with a generated one, and the only sign would be the
			// wording quietly changing. Whoever hooks earlier wins.
			add_action( 'init', array( __CLASS__, 'maybe_serve_llms_txt' ), 99 );
		}

		// The map goes stale the moment the pages behind it change.
		add_action( 'save_post', array( __CLASS__, 'flush_llms_txt' ) );
		add_action( 'deleted_post', array( __CLASS__, 'flush_llms_txt' ) );
	}

	/**
	 * The AI crawlers worth naming.
	 *
	 * Googlebot and Bingbot are deliberately absent. Google-Extended is not
	 * Googlebot: it governs whether content is used to train Gemini and has no
	 * bearing on search ranking, while blocking Googlebot itself would take the
	 * site out of the index altogether. Keeping the two apart is the point of
	 * listing them by name rather than offering one switch.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string,string> User agent to what it is for.
	 */
	public static function ai_agents() {
		return array(
			'GPTBot'          => __( 'OpenAI, training.', 'beaver-shield' ),
			'OAI-SearchBot'   => __( 'OpenAI, the index behind ChatGPT search results.', 'beaver-shield' ),
			'ChatGPT-User'    => __( 'OpenAI, fetching a page because a user asked about it.', 'beaver-shield' ),
			'ClaudeBot'       => __( 'Anthropic, training.', 'beaver-shield' ),
			'Claude-Web'      => __( 'Anthropic, fetching a page on request.', 'beaver-shield' ),
			'PerplexityBot'   => __( 'Perplexity, which cites and links its sources.', 'beaver-shield' ),
			'Google-Extended' => __( 'Google, Gemini training only. Does not affect Search.', 'beaver-shield' ),
			'Applebot-Extended' => __( 'Apple, training only. Does not affect Siri or Spotlight.', 'beaver-shield' ),
			'CCBot'           => __( 'Common Crawl, the open dataset most models are built from.', 'beaver-shield' ),
			'Bytespider'      => __( 'ByteDance. Known for ignoring robots.txt.', 'beaver-shield' ),
			'meta-externalagent' => __( 'Meta, training.', 'beaver-shield' ),
		);
	}

	/**
	 * Adds this plugin's lines to robots.txt.
	 *
	 * @since 1.0.0
	 *
	 * @param string $output Existing robots.txt.
	 * @param string $public Whether the site is set to discourage search engines.
	 * @return string Filtered robots.txt.
	 */
	public static function filter_robots( $output, $public ) {
		// A site set to discourage search engines is already saying no to
		// everything. Adding to that would only muddle it.
		if ( '1' !== (string) $public ) {
			return $output;
		}

		$lines = array( '' );

		// An SEO plugin, a theme or another plugin of ours may already have
		// written to this file. Repeating a directive it has already made is at
		// best noise and at worst a contradiction sitting two lines below the
		// original, so anything already present is left alone.
		if ( Beaver_Shield_Settings::on( 'block_ai_crawlers' ) ) {
			$fresh = array();

			foreach ( array_keys( self::ai_agents() ) as $agent ) {
				if ( false !== stripos( $output, 'user-agent: ' . $agent ) ) {
					continue;
				}

				$fresh[] = 'User-agent: ' . $agent;
				$fresh[] = 'Disallow: /';
				$fresh[] = '';
			}

			if ( ! empty( $fresh ) ) {
				$lines[] = '# AI crawlers are asked not to read this site.';
				$lines   = array_merge( $lines, $fresh );
			}
		}

		if ( Beaver_Shield_Settings::on( 'llms_txt' ) && false === stripos( $output, 'llms.txt' ) ) {
			$lines[] = '# A plain text guide to this site for language models:';
			$lines[] = '# ' . home_url( '/llms.txt' );
			$lines[] = '';
		}

		// Nothing to add means nothing is added, rather than a trailing blank
		// line appearing in robots.txt for no reason.
		if ( 1 === count( $lines ) ) {
			return $output;
		}

		return $output . implode( "\n", $lines );
	}

	/**
	 * Serves /llms.txt when that is what was asked for.
	 *
	 * Matched on the request path rather than through the rewrite rules, so it
	 * works on a site with plain permalinks and does not depend on a flush.
	 * A real file of the same name on disk is served by the web server before
	 * PHP ever runs, so an existing one always wins.
	 *
	 * @since 1.0.0
	 */
	public static function maybe_serve_llms_txt() {
		$path = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH );

		if ( 'llms.txt' !== ltrim( basename( $path ), '/' ) ) {
			return;
		}

		$body = get_transient( self::TRANSIENT );

		if ( false === $body ) {
			$body = self::build_llms_txt();

			set_transient( self::TRANSIENT, $body, DAY_IN_SECONDS );
		}

		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Cache-Control: public, max-age=3600' );
		header( 'X-Robots-Tag: noindex' );

		echo $body; // phpcs:ignore WordPress.Security.EscapingOutput.OutputNotEscaped -- plain text document, escaped as it is assembled.

		exit;
	}

	/**
	 * Builds the llms.txt document.
	 *
	 * @since 1.0.0
	 *
	 * @return string Document.
	 */
	public static function build_llms_txt() {
		$name        = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$description = wp_specialchars_decode( get_bloginfo( 'description' ), ENT_QUOTES );

		$out = array( '# ' . $name );

		if ( '' !== $description ) {
			$out[] = '';
			$out[] = '> ' . $description;
		}

		$intro = trim( (string) Beaver_Shield_Settings::get( 'llms_txt_intro', '' ) );

		if ( '' !== $intro ) {
			$out[] = '';
			$out[] = $intro;
		}

		$pages = get_posts(
			self::query_args(
				array(
					'post_type'   => 'page',
					'numberposts' => 40,
					'orderby'     => 'menu_order title',
					'order'       => 'ASC',
				)
			)
		);

		if ( ! empty( $pages ) ) {
			$out[] = '';
			$out[] = '## Pages';
			$out[] = '';

			foreach ( $pages as $page ) {
				$out[] = self::entry( $page );
			}
		}

		$posts = get_posts(
			self::query_args(
				array(
					'post_type'   => 'post',
					'numberposts' => 30,
				)
			)
		);

		if ( ! empty( $posts ) ) {
			$out[] = '';
			$out[] = '## Writing';
			$out[] = '';

			foreach ( $posts as $post ) {
				$out[] = self::entry( $post );
			}
		}

		$out[] = '';
		$out[] = '## Using this content';
		$out[] = '';
		$out[] = sprintf(
			/* translators: %s: site name. */
			__( 'Quoting from these pages is welcome when the passage is attributed to %s and links to the page it came from.', 'beaver-shield' ),
			$name
		);
		$out[] = __( 'Reproducing a page in full, or presenting it as your own work, is not.', 'beaver-shield' );

		return implode( "\n", $out ) . "\n";
	}

	/**
	 * Query arguments for the pages that go into the map.
	 *
	 * `lang` is set to an empty string on purpose. On a site running Polylang
	 * that is the documented way to say "every language"; without it the map
	 * would silently list only whichever language happened to be current when
	 * the file was first built and cached, which on a bilingual site means half
	 * the pages are missing and nothing looks wrong.
	 *
	 * On a site with no multilingual plugin it is an unrecognised argument and
	 * is ignored, so it costs nothing to send.
	 *
	 * @since 1.0.0
	 *
	 * @param array $args Arguments to merge in.
	 * @return array Query arguments.
	 */
	private static function query_args( array $args ) {
		$defaults = array(
			'post_status'      => 'publish',
			'suppress_filters' => false,
			'lang'             => '',
		);

		/**
		 * Filters the query behind llms.txt.
		 *
		 * @since 1.0.0
		 *
		 * @param array $args Query arguments.
		 */
		return apply_filters( 'beaver_shield_llms_query', array_merge( $defaults, $args ) );
	}

	/**
	 * One line of the map.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_Post $post Post.
	 * @return string Markdown list item.
	 */
	private static function entry( $post ) {
		// This is a plain text document, so the entities WordPress adds for
		// HTML have to come back out. A curly quote written as &#8220; reads as
		// &#8220; to whatever is parsing this.
		$title = html_entity_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES, 'UTF-8' );

		$line = sprintf( '- [%s](%s)', $title, get_permalink( $post ) );

		$excerpt = trim( html_entity_decode( wp_strip_all_tags( get_the_excerpt( $post ) ), ENT_QUOTES, 'UTF-8' ) );

		if ( '' !== $excerpt ) {
			$line .= ': ' . wp_trim_words( $excerpt, 30, '' );
		}

		return $line;
	}

	/**
	 * Drops the cached document.
	 *
	 * @since 1.0.0
	 */
	public static function flush_llms_txt() {
		delete_transient( self::TRANSIENT );
	}
}
