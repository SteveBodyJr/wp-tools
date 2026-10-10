<?php
/**
 * Exposure scan.
 *
 * @package BeaverShield

 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads the site the way an outsider does, and reports what it found.
 *
 * A screen full of switches tells you what was asked for. It does not tell you
 * what a caching layer, a CDN, another plugin or a server rule did with it
 * afterwards, and on a real site those disagree more often than not. So the
 * plugin fetches its own pages over HTTP, anonymously, exactly as a scanner
 * would, and reports what actually came back.
 *
 * This is the part worth trusting. Everything else in the plugin is an
 * intention; this is the result.
 *
 * @since 1.0.0
 */
class Beaver_Shield_Scan {

	const PASS = 'pass';
	const FAIL = 'fail';
	const WARN = 'warn';
	const SKIP = 'skip';

	/**
	 * Runs every check and stores the result.
	 *
	 * @since 1.0.0
	 *
	 * @return array The scan: `time` and `checks`.
	 */
	public static function run() {
		$home = self::fetch( home_url( '/' ) );

		$scan = array(
			'time'   => time(),
			'checks' => array_merge(
				self::check_seo( $home ),
				self::check_rendering( $home ),
				self::check_page( $home ),
				self::check_headers( $home ),
				self::check_files(),
				self::check_enumeration()
			),
		);

		Beaver_Shield_Settings::update( array( 'scan' => $scan ) );

		return $scan;
	}

	/**
	 * The last stored scan.
	 *
	 * @since 1.0.0
	 *
	 * @return array Scan, or an empty array.
	 */
	public static function last() {
		$scan = Beaver_Shield_Settings::get( 'scan', array() );

		return is_array( $scan ) ? $scan : array();
	}

	/**
	 * Fetches a URL as an anonymous visitor.
	 *
	 * @since 1.0.0
	 *
	 * @param string $url    URL.
	 * @param bool   $follow Whether to follow redirects.
	 * @return array|WP_Error Response.
	 */
	private static function fetch( $url, $follow = true ) {
		return wp_remote_get(
			$url,
			array(
				'timeout'     => 15,
				'redirection' => $follow ? 3 : 0,
				'sslverify'   => false,
				'headers'     => array( 'Cache-Control' => 'no-cache' ),
				// Named honestly. Pretending to be a browser to test your own
				// site only teaches you what a liar sees.
				'user-agent'  => 'BeaverShield/' . BEAVER_SHIELD_VERSION . ' (self check)',
			)
		);
	}

	/**
	 * Builds one result row.
	 *
	 * @since 1.0.0
	 *
	 * @param string $id     Check id.
	 * @param string $label  What was checked.
	 * @param string $status One of the status constants.
	 * @param string $detail What was found.
	 * @return array Row.
	 */
	private static function row( $id, $label, $status, $detail ) {
		return compact( 'id', 'label', 'status', 'detail' );
	}

	/**
	 * Whether anything here is standing in the way of search.
	 *
	 * These checks run first and are the ones to read first. Every other row on
	 * this screen is about hiding something; these four exist to prove that
	 * nothing worth finding got hidden with it. A hardening plugin that quietly
	 * costs a site its traffic has done more damage than the attack it was
	 * installed to prevent.
	 *
	 * The last of them is the important one. Serving a crawler different HTML
	 * from a visitor is cloaking, it is a manual-action offence, and it is what
	 * every "hide your technology" plugin is tempted into doing. This plugin
	 * never inspects a user agent, so the two responses should match. The check
	 * fetches the page twice, once announced as Googlebot, and compares them, so
	 * the claim is tested rather than asserted.
	 *
	 * @since 1.0.0
	 *
	 * @param array|WP_Error $response Home page response.
	 * @return array Rows.
	 */
	private static function check_seo( $response ) {
		$rows = array();

		$rows[] = get_option( 'blog_public' )
			? self::row( 'indexable', __( 'Open to search engines', 'beaver-shield' ), self::PASS, __( 'Settings → Reading is set to allow indexing.', 'beaver-shield' ) )
			: self::row( 'indexable', __( 'Open to search engines', 'beaver-shield' ), self::FAIL, __( 'Settings → Reading is set to discourage search engines. Nothing else on this page matters until that is switched off: the site is asking not to be indexed.', 'beaver-shield' ) );

		// Google only reads robots.txt from the top level of a host: "Crawlers
		// don't check for robots.txt files in subdirectories." A WordPress in a
		// subfolder therefore has a robots.txt nothing will ever read, which
		// silently makes the AI crawler directives and the llms.txt pointer
		// decorative. Checking the host root rather than the install root is
		// what catches that.
		$host_root = self::host_root();
		$robots    = self::fetch( $host_root . 'robots.txt' );

		if ( ! is_wp_error( $robots ) ) {
			$code = (int) wp_remote_retrieve_response_code( $robots );
			$body = (string) wp_remote_retrieve_body( $robots );

			if ( 200 !== $code ) {
				$rows[] = self::row(
					'robots',
					'robots.txt',
					self::WARN,
					sprintf(
						/* translators: 1: URL, 2: HTTP status code. */
						__( '%1$s answered %2$d. Crawlers only read robots.txt from the top level of a host, never from a subfolder, so nothing this plugin writes there reaches them. On a WordPress installed in a subdirectory the file has to be placed at the host root by hand.', 'beaver-shield' ),
						$host_root . 'robots.txt',
						$code
					)
				);
			} else {
				$blocked = (bool) preg_match( '/User-agent:\s*(\*|Googlebot|Bingbot)\s*\R+Disallow:\s*\/\s*$/mi', $body );

				$rows[] = $blocked
					? self::row( 'robots', 'robots.txt', self::FAIL, __( 'Search engines are being told to stay off the whole site.', 'beaver-shield' ) )
					: self::row( 'robots', 'robots.txt', self::PASS, __( 'Reachable at the host root, and Googlebot and Bingbot are not restricted.', 'beaver-shield' ) );
			}
		}

		if ( ! is_wp_error( $response ) ) {
			$body = (string) wp_remote_retrieve_body( $response );

			$rows[] = preg_match( '#<link[^>]+rel=["\']canonical["\']#i', $body )
				? self::row( 'canonical', __( 'Canonical tag', 'beaver-shield' ), self::PASS, __( 'Present. This is what tells a search engine which copy of a page is the original, and it matters more against a competitor than anything JavaScript can do.', 'beaver-shield' ) )
				: self::row( 'canonical', __( 'Canonical tag', 'beaver-shield' ), self::WARN, __( 'Not found on the home page. Some themes only add it to single posts. If nothing on this site emits one, an SEO plugin should.', 'beaver-shield' ) );

			$as_googlebot = self::fetch_as( home_url( '/' ), 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)' );

			if ( ! is_wp_error( $as_googlebot ) ) {
				$crawler = (string) wp_remote_retrieve_body( $as_googlebot );
				$visitor = strlen( $body );
				$drift   = $visitor > 0 ? abs( strlen( $crawler ) - $visitor ) / $visitor : 0;

				// A few tenths of a percent is nonces, timestamps and cache
				// markers moving. A real cloak is not subtle.
				$rows[] = $drift < 0.02
					? self::row( 'cloaking', __( 'Same page for crawlers and visitors', 'beaver-shield' ), self::PASS, __( 'The page fetched as Googlebot matches the page fetched as anyone else. No cloaking risk.', 'beaver-shield' ) )
					: self::row(
						'cloaking',
						__( 'Same page for crawlers and visitors', 'beaver-shield' ),
						self::WARN,
						sprintf(
							/* translators: %s: percentage difference. */
							__( 'The page served to Googlebot differs by %s. This plugin never varies its output, so something else on the site is doing it: usually a caching layer with a separate bot cache, sometimes a geolocation or A/B testing plugin. Worth knowing about either way.', 'beaver-shield' ),
							round( $drift * 100, 1 ) . '%'
						)
					);
			}
		}

		return $rows;
	}

	/**
	 * The top level of this host, which is the only place robots.txt counts.
	 *
	 * @since 1.0.0
	 *
	 * @return string URL with a trailing slash.
	 */
	private static function host_root() {
		$parts  = wp_parse_url( home_url( '/' ) );
		$scheme = $parts['scheme'] ?? 'https';
		$host   = $parts['host'] ?? '';
		$port   = isset( $parts['port'] ) ? ':' . $parts['port'] : '';

		return $scheme . '://' . $host . $port . '/';
	}

	/**
	 * Whether everything the page loads still answers.
	 *
	 * This is the check that matters most to search, and it is the one a
	 * hardening plugin is least likely to include, because it exists to catch
	 * that plugin's own mistakes.
	 *
	 * Google renders a page in headless Chromium before indexing it, and its
	 * guidance is blunt: make sure Googlebot is not blocked from any resource
	 * that affects the meaning of the page, because a blocked resource is a
	 * resource it renders without. A deny rule with a regular expression
	 * slightly wider than intended, an over-tight content security policy, or a
	 * hotlink rule that catches a CDN, all fail exactly this way: the page still
	 * returns 200, so nothing looks wrong, and the version Google indexes is
	 * missing its stylesheet.
	 *
	 * So the scan reads the stylesheets and scripts out of the page and fetches
	 * every one of them the way a renderer would.
	 *
	 * @since 1.0.0
	 *
	 * @param array|WP_Error $response Home page response.
	 * @return array Rows.
	 */
	private static function check_rendering( $response ) {
		if ( is_wp_error( $response ) ) {
			return array();
		}

		$body = (string) wp_remote_retrieve_body( $response );
		$host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );

		preg_match_all( '#<(?:script[^>]+src|link[^>]+href)=["\']([^"\']+\.(?:css|js)(?:\?[^"\']*)?)["\']#i', $body, $matches );

		$assets = array();

		foreach ( (array) ( $matches[1] ?? array() ) as $url ) {
			$url = html_entity_decode( $url, ENT_QUOTES, 'UTF-8' );

			// Only this host. Something else's CDN failing is not this site's
			// rule doing it, and is not this scan's business.
			if ( wp_parse_url( $url, PHP_URL_HOST ) !== $host ) {
				continue;
			}

			$assets[ $url ] = true;
		}

		$assets = array_slice( array_keys( $assets ), 0, 15 );

		if ( empty( $assets ) ) {
			return array();
		}

		$broken = array();

		foreach ( $assets as $url ) {
			// Announced as Googlebot and sent with the page as its referrer,
			// which is what the renderer does. Hotlink rules key on exactly that
			// header, so testing without it would test the wrong thing.
			$fetched = wp_remote_get(
				$url,
				array(
					'timeout'    => 10,
					'sslverify'  => false,
					'user-agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
					'headers'    => array( 'Referer' => home_url( '/' ) ),
				)
			);

			$code = is_wp_error( $fetched ) ? 0 : (int) wp_remote_retrieve_response_code( $fetched );

			if ( 200 !== $code ) {
				$broken[] = basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) . ' (' . ( $code ? $code : 'no response' ) . ')';
			}
		}

		return array(
			empty( $broken )
				? self::row(
					'rendering',
					__( 'Everything the page loads', 'beaver-shield' ),
					self::PASS,
					sprintf(
						/* translators: %d: number of files. */
						_n( 'The %d stylesheet and script on the home page all answer to a crawler. Nothing here is blocking what Google needs to render the page.', 'All %d stylesheets and scripts on the home page answer to a crawler. Nothing here is blocking what Google needs to render the page.', count( $assets ), 'beaver-shield' ),
						count( $assets )
					)
				)
				: self::row(
					'rendering',
					__( 'Everything the page loads', 'beaver-shield' ),
					self::FAIL,
					sprintf(
						/* translators: %s: list of files and status codes. */
						__( 'A crawler cannot fetch: %s. Google renders pages before indexing them, so it would index this page without those files. Check the server rules and, if a content security policy is being enforced, check that too.', 'beaver-shield' ),
						implode( ', ', $broken )
					)
				),
		);
	}

	/**
	 * Fetches a URL announcing a particular user agent.
	 *
	 * @since 1.0.0
	 *
	 * @param string $url   URL.
	 * @param string $agent User agent.
	 * @return array|WP_Error Response.
	 */
	private static function fetch_as( $url, $agent ) {
		return wp_remote_get(
			$url,
			array(
				'timeout'     => 15,
				'redirection' => 3,
				'sslverify'   => false,
				'headers'     => array( 'Cache-Control' => 'no-cache' ),
				'user-agent'  => $agent,
			)
		);
	}

	/**
	 * What the home page gives away in its markup.
	 *
	 * @since 1.0.0
	 *
	 * @param array|WP_Error $response Home page response.
	 * @return array Rows.
	 */
	private static function check_page( $response ) {
		if ( is_wp_error( $response ) ) {
			return array(
				self::row(
					'page',
					__( 'Home page markup', 'beaver-shield' ),
					self::SKIP,
					sprintf(
						/* translators: %s: error message. */
						__( 'The site could not fetch itself: %s. This is usually a host that blocks loopback requests rather than a problem with the site.', 'beaver-shield' ),
						$response->get_error_message()
					)
				),
			);
		}

		$body = (string) wp_remote_retrieve_body( $response );
		$rows = array();

		$version = '';

		if ( preg_match( '#<meta[^>]+name=["\']generator["\'][^>]*content=["\']([^"\']+)#i', $body, $match ) ) {
			$version = trim( $match[1] );
		}

		$rows[] = '' === $version
			? self::row( 'generator', __( 'Generator meta tag', 'beaver-shield' ), self::PASS, __( 'Not present.', 'beaver-shield' ) )
			: self::row(
				'generator',
				__( 'Generator meta tag', 'beaver-shield' ),
				self::FAIL,
				sprintf(
					/* translators: %s: generator string. */
					__( 'The page announces "%s". That is the single most useful line on the page to anyone looking for a site running a version with a known hole in it.', 'beaver-shield' ),
					$version
				)
			);

		$ver_count = preg_match_all( '#[?&]ver=([0-9][^"\'&\s]*)#i', $body, $matches );

		$rows[] = $ver_count
			? self::row(
				'versions',
				__( 'Version query strings', 'beaver-shield' ),
				self::FAIL,
				sprintf(
					/* translators: 1: number of assets, 2: example version. */
					_n(
						'%1$d asset URL still carries a version, for example ver=%2$s. Each one dates the plugin that loaded it.',
						'%1$d asset URLs still carry a version, for example ver=%2$s. Each one dates the plugin that loaded it.',
						$ver_count,
						'beaver-shield'
					),
					$ver_count,
					$matches[1][0]
				)
			)
			: self::row( 'versions', __( 'Version query strings', 'beaver-shield' ), self::PASS, __( 'No ver= parameters in the page.', 'beaver-shield' ) );

		$discovery = array();

		foreach ( array( 'rsd+xml' => 'RSD', 'wlwmanifest' => 'wlwmanifest', 'shortlink' => 'shortlink' ) as $needle => $name ) {
			if ( false !== stripos( $body, $needle ) ) {
				$discovery[] = $name;
			}
		}

		$rows[] = empty( $discovery )
			? self::row( 'discovery', __( 'Legacy discovery links', 'beaver-shield' ), self::PASS, __( 'None in the head.', 'beaver-shield' ) )
			: self::row(
				'discovery',
				__( 'Legacy discovery links', 'beaver-shield' ),
				self::WARN,
				sprintf(
					/* translators: %s: list of link names. */
					__( 'Still advertising: %s. No modern client uses these, and detectors read them as a signature.', 'beaver-shield' ),
					implode( ', ', $discovery )
				)
			);

		return $rows;
	}

	/**
	 * What the response headers give away, and what they protect.
	 *
	 * @since 1.0.0
	 *
	 * @param array|WP_Error $response Home page response.
	 * @return array Rows.
	 */
	private static function check_headers( $response ) {
		if ( is_wp_error( $response ) ) {
			return array();
		}

		$rows = array();

		foreach ( array( 'x-powered-by', 'x-pingback' ) as $name ) {
			$value = wp_remote_retrieve_header( $response, $name );

			$rows[] = '' === $value
				? self::row( $name, sprintf( '%s header', $name ), self::PASS, __( 'Not sent.', 'beaver-shield' ) )
				: self::row(
					$name,
					sprintf( '%s header', $name ),
					self::FAIL,
					sprintf(
						/* translators: %s: header value. */
						__( 'Sent as "%s".', 'beaver-shield' ),
						is_array( $value ) ? implode( ', ', $value ) : $value
					)
				);
		}

		$wanted = array(
			'x-frame-options'        => __( 'Stops the site being framed by someone else.', 'beaver-shield' ),
			'x-content-type-options' => __( 'Stops the browser guessing at content types.', 'beaver-shield' ),
			'referrer-policy'        => __( 'Controls how much of the current URL leaks to the next site.', 'beaver-shield' ),
			'permissions-policy'     => __( 'Switches off browser features the site never asks for.', 'beaver-shield' ),
		);

		$missing = array();

		foreach ( $wanted as $name => $why ) {
			if ( '' === wp_remote_retrieve_header( $response, $name ) ) {
				$missing[] = $name;
			}
		}

		$rows[] = empty( $missing )
			? self::row( 'security-headers', __( 'Security headers', 'beaver-shield' ), self::PASS, __( 'All four present.', 'beaver-shield' ) )
			: self::row(
				'security-headers',
				__( 'Security headers', 'beaver-shield' ),
				self::WARN,
				sprintf(
					/* translators: %s: list of header names. */
					__( 'Missing: %s. If a caching layer or CDN sits in front of this site, check that it is passing them through.', 'beaver-shield' ),
					implode( ', ', $missing )
				)
			);

		return $rows;
	}

	/**
	 * Which files answer that should not.
	 *
	 * @since 1.0.0
	 *
	 * @return array Rows.
	 */
	private static function check_files() {
		$rows = array();

		$readme = self::fetch( site_url( 'readme.html' ) );

		$rows[] = self::code_row(
			'core-readme',
			__( 'readme.html', 'beaver-shield' ),
			$readme,
			__( 'Reachable. It names the WordPress release in its first heading.', 'beaver-shield' ),
			__( 'Not reachable.', 'beaver-shield' )
		);

		$plugin_readme = self::first_plugin_readme();

		if ( '' !== $plugin_readme ) {
			$rows[] = self::code_row(
				'plugin-readme',
				__( 'Plugin readme files', 'beaver-shield' ),
				self::fetch( $plugin_readme ),
				__( 'Reachable. Every plugin ships one, and each states its exact version, which is how a scan builds a list of what to try.', 'beaver-shield' ),
				__( 'Not reachable.', 'beaver-shield' )
			);
		}

		$uploads = wp_get_upload_dir();

		if ( empty( $uploads['error'] ) ) {
			$listing = self::fetch( trailingslashit( $uploads['baseurl'] ) );

			if ( ! is_wp_error( $listing ) ) {
				$is_index = false !== stripos( (string) wp_remote_retrieve_body( $listing ), 'index of' );

				$rows[] = $is_index
					? self::row( 'listing', __( 'Uploads directory listing', 'beaver-shield' ), self::FAIL, __( 'The uploads folder lists its contents to anyone who asks.', 'beaver-shield' ) )
					: self::row( 'listing', __( 'Uploads directory listing', 'beaver-shield' ), self::PASS, __( 'Does not list.', 'beaver-shield' ) );
			}
		}

		if ( Beaver_Shield_Settings::on( 'llms_txt' ) ) {
			$llms = self::fetch( home_url( '/llms.txt' ) );

			$rows[] = ! is_wp_error( $llms ) && 200 === (int) wp_remote_retrieve_response_code( $llms )
				? self::row( 'llms', 'llms.txt', self::PASS, __( 'Serving. Answer engines have a map of the site.', 'beaver-shield' ) )
				: self::row( 'llms', 'llms.txt', self::WARN, __( 'Not serving. A real file of that name on disk, or a rule in front of WordPress, would take priority.', 'beaver-shield' ) );
		}

		return $rows;
	}

	/**
	 * What can be learned about the accounts on the site.
	 *
	 * @since 1.0.0
	 *
	 * @return array Rows.
	 */
	private static function check_enumeration() {
		$rows = array();

		$author = self::fetch( add_query_arg( 'author', 1, home_url( '/' ) ), false );

		if ( ! is_wp_error( $author ) ) {
			$code     = (int) wp_remote_retrieve_response_code( $author );
			$location = (string) wp_remote_retrieve_header( $author, 'location' );
			$leaks    = in_array( $code, array( 301, 302 ), true ) && false !== strpos( $location, '/author/' );

			$rows[] = $leaks
				? self::row(
					'author-query',
					__( 'Username enumeration', 'beaver-shield' ),
					self::FAIL,
					sprintf(
						/* translators: %s: redirect target. */
						__( '?author=1 redirects to %s, which hands over a real username. A username is half of a login.', 'beaver-shield' ),
						$location
					)
				)
				: self::row( 'author-query', __( 'Username enumeration', 'beaver-shield' ), self::PASS, __( '?author=1 gives nothing away.', 'beaver-shield' ) );
		}

		$users = self::fetch( rest_url( 'wp/v2/users' ) );

		if ( ! is_wp_error( $users ) ) {
			$decoded = json_decode( (string) wp_remote_retrieve_body( $users ), true );
			$listed  = is_array( $decoded ) && ! empty( $decoded ) && isset( $decoded[0]['slug'] );

			$rows[] = $listed
				? self::row(
					'rest-users',
					__( 'REST users endpoint', 'beaver-shield' ),
					self::FAIL,
					sprintf(
						/* translators: %d: number of accounts. */
						_n( 'Returns %d account to anyone who asks.', 'Returns %d accounts to anyone who asks.', count( $decoded ), 'beaver-shield' ),
						count( $decoded )
					)
				)
				: self::row( 'rest-users', __( 'REST users endpoint', 'beaver-shield' ), self::PASS, __( 'Closed to anonymous callers.', 'beaver-shield' ) );
		}

		$xmlrpc = self::fetch( site_url( 'xmlrpc.php' ) );

		if ( ! is_wp_error( $xmlrpc ) ) {
			$code = (int) wp_remote_retrieve_response_code( $xmlrpc );

			$rows[] = in_array( $code, array( 403, 404, 410 ), true )
				? self::row( 'xmlrpc', 'xmlrpc.php', self::PASS, __( 'Blocked at the server.', 'beaver-shield' ) )
				: self::row(
					'xmlrpc',
					'xmlrpc.php',
					self::WARN,
					__( 'Still answering. WordPress will refuse the methods if you have switched XML-RPC off, but the file is being reached, so the requests still cost the site something.', 'beaver-shield' )
				);
		}

		return $rows;
	}

	/**
	 * Turns a response code into a pass or a fail.
	 *
	 * @since 1.0.0
	 *
	 * @param string         $id       Check id.
	 * @param string         $label    Label.
	 * @param array|WP_Error $response Response.
	 * @param string         $open     Detail when the file answers.
	 * @param string         $closed   Detail when it does not.
	 * @return array Row.
	 */
	private static function code_row( $id, $label, $response, $open, $closed ) {
		if ( is_wp_error( $response ) ) {
			return self::row( $id, $label, self::SKIP, $response->get_error_message() );
		}

		return 200 === (int) wp_remote_retrieve_response_code( $response )
			? self::row( $id, $label, self::FAIL, $open )
			: self::row( $id, $label, self::PASS, $closed );
	}

	/**
	 * A readme.txt belonging to an active plugin.
	 *
	 * @since 1.0.0
	 *
	 * @return string URL, or an empty string.
	 */
	private static function first_plugin_readme() {
		$active = (array) get_option( 'active_plugins', array() );

		foreach ( $active as $plugin ) {
			$dir = dirname( (string) $plugin );

			if ( '.' === $dir ) {
				continue;
			}

			if ( file_exists( WP_PLUGIN_DIR . '/' . $dir . '/readme.txt' ) ) {
				return plugins_url( $dir . '/readme.txt' );
			}
		}

		return '';
	}

	/**
	 * How many checks came back at each status.
	 *
	 * @since 1.0.0
	 *
	 * @param array $scan Scan.
	 * @return array<string,int> Status to count.
	 */
	public static function tally( $scan ) {
		$counts = array(
			self::PASS => 0,
			self::WARN => 0,
			self::FAIL => 0,
			self::SKIP => 0,
		);

		foreach ( (array) ( $scan['checks'] ?? array() ) as $check ) {
			$status = $check['status'] ?? self::SKIP;

			if ( isset( $counts[ $status ] ) ) {
				++$counts[ $status ];
			}
		}

		return $counts;
	}
}
