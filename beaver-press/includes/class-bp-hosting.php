<?php
/**
 * Hosting check: can this site reach itself, does WordPress's cron run, may a request run long?
 *
 * The Translate-site run, the daily top-up, form preparation and the ready-page warm-up all work
 * by the site opening its own pages over HTTP (BP_Run::fetch) and by WP-Cron. On cheap shared
 * hosting, behind Cloudflare or in a container, the site's public address often cannot be
 * reached from the server itself; the run then fails quietly and pages never become complete.
 *
 * The check opens the page-not-found address in the original language as the signed run
 * (nothing is translated, nothing is sent to a provider) and reads the X-Beaver-Press-Run reply
 * header that BP_Guard adds when it recognised the signature. When the public address fails,
 * the server's own address is tried (127.0.0.1, then SERVER_ADDR); if that works it is kept and
 * every request the site makes to itself goes there (curl's resolve list: the URL, the Host
 * header and the TLS name stay the site's own). BEAVER_PRESS_LOOPBACK_IP in wp-config.php sets
 * that address by hand.
 *
 * @package BeaverPress
 */

defined( 'ABSPATH' ) || exit;

/**
 * Hosting check and loopback fallback.
 */
final class BP_Hosting {

	/** Last check: time, reach (public | local | none), ip, code, seconds, seen, error. */
	const OPTION = 'beaver_press_hosting';

	/** Server address used for requests to itself when the public one fails ('' = public). */
	const IP_OPTION = 'beaver_press_loopback_ip';

	/** Request argument that marks a request to this site. */
	const ARG = 'bp_loopback';

	/** Scheduled tasks later than this (seconds) mean WP-Cron is not running. */
	const CRON_LATE = 3600;

	/** A request to itself slower than this (seconds) is reported as slow. */
	const SLOW = 15;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'http_api_curl', array( __CLASS__, 'curl' ), 10, 3 );
		add_action( 'admin_post_bp_hosting_check', array( __CLASS__, 'handle_check' ) );
	}

	/**
	 * Arguments for a request this site makes to itself.
	 *
	 * @param array $args wp_remote_get arguments.
	 * @return array
	 */
	public static function args( array $args ) {
		$args[ self::ARG ] = true;
		return $args;
	}

	/**
	 * Server address for requests to itself, '' for the public one.
	 *
	 * @return string
	 */
	public static function ip() {
		if ( defined( 'BEAVER_PRESS_LOOPBACK_IP' ) && filter_var( BEAVER_PRESS_LOOPBACK_IP, FILTER_VALIDATE_IP ) ) {
			return (string) BEAVER_PRESS_LOOPBACK_IP;
		}
		$ip = (string) get_option( self::IP_OPTION, '' );
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/**
	 * Send a marked request to this site's own server instead of its public address.
	 *
	 * @param resource|CurlHandle $handle cURL handle.
	 * @param array               $args   Request arguments.
	 * @param string              $url    URL.
	 */
	public static function curl( $handle, $args, $url ) {
		$ip = $args['bp_ip'] ?? self::ip();
		if ( empty( $args[ self::ARG ] ) || '' === (string) $ip || ! defined( 'CURLOPT_RESOLVE' ) ) {
			return;
		}
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		$home = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		if ( '' === $host || strtolower( $host ) !== strtolower( $home ) ) {
			return; // Only this site's own address.
		}
		$ip    = false !== strpos( $ip, ':' ) ? '[' . $ip . ']' : $ip;
		$ports = array_unique( array_filter( array( 80, 443, (int) wp_parse_url( $url, PHP_URL_PORT ) ) ) );
		curl_setopt( $handle, CURLOPT_RESOLVE, array_map( static fn( $p ) => $host . ':' . $p . ':' . $ip, $ports ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- WP HTTP's own handle.
	}

	/**
	 * Run the check now and keep the result.
	 *
	 * @return array Result (see OPTION).
	 */
	public static function check() {
		$tries = array( '' );
		foreach ( array( '127.0.0.1', (string) ( $_SERVER['SERVER_ADDR'] ?? '' ) ) as $candidate ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- validated below.
			if ( filter_var( $candidate, FILTER_VALIDATE_IP ) && ! in_array( $candidate, $tries, true ) ) {
				$tries[] = $candidate;
			}
		}
		if ( defined( 'BEAVER_PRESS_LOOPBACK_IP' ) ) {
			$tries = array( (string) BEAVER_PRESS_LOOPBACK_IP );
		}
		$first = null;
		foreach ( $tries as $ip ) {
			$probe = self::probe( $ip );
			$first = $first ?? $probe;
			if ( $probe['seen'] ) {
				break;
			}
		}
		$ok     = ! empty( $probe['seen'] );
		$result = array(
			'time'    => time(),
			'reach'   => $ok ? ( '' === $probe['ip'] ? 'public' : 'local' ) : 'none',
			'ip'      => $ok ? $probe['ip'] : '',
			'code'    => $ok ? $probe['code'] : $first['code'],
			'seconds' => $ok ? $probe['seconds'] : $first['seconds'],
			'seen'    => $ok,
			'error'   => $ok ? '' : $first['error'],
		);
		if ( ! defined( 'BEAVER_PRESS_LOOPBACK_IP' ) ) {
			if ( 'local' === $result['reach'] ) {
				update_option( self::IP_OPTION, $result['ip'], true );
			} elseif ( 'public' === $result['reach'] ) {
				delete_option( self::IP_OPTION );
			}
		}
		update_option( self::OPTION, $result, false );
		return $result;
	}

	/**
	 * Open the page-not-found address as the signed run, through one address.
	 *
	 * @param string $ip Server address, '' for the public one.
	 * @return array ip, code, seconds, seen, error.
	 */
	private static function probe( $ip ) {
		$url   = add_query_arg( 'bp_hosting', time(), home_url( '/' . BP_Run::MISSING_PATH . '/' ) );
		$start = microtime( true );
		$args  = self::args(
			array(
				'timeout'     => 20,
				'redirection' => 0,
				'sslverify'   => false, // Checking reach, not certificates; a local address never matches one.
				'headers'     => array(
					BP_Guard::HEADER => BP_Guard::run_token(),
					'Cache-Control'  => 'no-cache',
				),
			)
		);
		$args['bp_ip'] = $ip;
		$response      = wp_remote_get( $url, $args );
		$out           = array(
			'ip'      => $ip,
			'code'    => 0,
			'seconds' => round( microtime( true ) - $start, 1 ),
			'seen'    => false,
			'error'   => '',
		);
		if ( is_wp_error( $response ) ) {
			$out['error'] = $response->get_error_message();
			return $out;
		}
		$out['code'] = (int) wp_remote_retrieve_response_code( $response );
		$out['seen'] = 'ok' === strtolower( (string) wp_remote_retrieve_header( $response, 'x-beaver-press-run' ) );
		if ( ! $out['seen'] ) {
			$out['error'] = $out['code'] >= 300 && $out['code'] < 400
				/* translators: %s: address */
				? sprintf( __( 'the site answered with a redirect to %s', 'beaver-press' ), (string) wp_remote_retrieve_header( $response, 'location' ) )
				/* translators: %d: HTTP status */
				: sprintf( __( 'the site answered (HTTP %d) but the run\'s signature did not reach WordPress (a firewall or proxy may remove it)', 'beaver-press' ), $out['code'] );
		}
		return $out;
	}

	/**
	 * The last check, or null.
	 *
	 * @return array|null
	 */
	public static function last() {
		$last = get_option( self::OPTION );
		return is_array( $last ) ? $last : null;
	}

	/**
	 * Whether the site cannot reach itself (last check failed).
	 *
	 * @return bool
	 */
	public static function unreachable() {
		$last = self::last();
		return $last && 'none' === $last['reach'];
	}

	/**
	 * WordPress's scheduled tasks: how late the oldest one due is, and whether WP-Cron is off.
	 *
	 * @return array{late: int, disabled: bool}
	 */
	public static function cron() {
		$late = 0;
		$now  = time();
		foreach ( array_keys( (array) _get_cron_array() ) as $when ) {
			if ( (int) $when < $now ) {
				$late = max( $late, $now - (int) $when );
			}
		}
		return array(
			'late'     => $late,
			'disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
		);
	}

	/**
	 * Seconds PHP lets one request run, 0 for no limit; and whether a plugin may raise it.
	 *
	 * @return array{limit: int, raise: bool}
	 */
	public static function time_limit() {
		$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );
		return array(
			'limit' => (int) ini_get( 'max_execution_time' ),
			'raise' => function_exists( 'set_time_limit' ) && ! in_array( 'set_time_limit', $disabled, true ),
		);
	}

	/**
	 * Health lines: [ok (true|false|null), text].
	 *
	 * @return array[]
	 */
	public static function checks() {
		$out  = array();
		$last = self::last();
		if ( ! $last ) {
			$out[] = array( null, __( 'Hosting not checked yet: press Check hosting', 'beaver-press' ) );
		} elseif ( 'public' === $last['reach'] ) {
			$out[] = array(
				$last['seconds'] <= self::SLOW ? true : null,
				$last['seconds'] <= self::SLOW
					/* translators: %s: seconds */
					? sprintf( __( 'The site reaches itself (%s s)', 'beaver-press' ), number_format_i18n( (float) $last['seconds'], 1 ) )
					/* translators: %s: seconds */
					: sprintf( __( 'The site reaches itself, but slowly (%s s): use a smaller batch size if pages fail', 'beaver-press' ), number_format_i18n( (float) $last['seconds'], 1 ) ),
			);
		} elseif ( 'local' === $last['reach'] ) {
			/* translators: %s: server address */
			$out[] = array( true, sprintf( __( 'The site reaches itself through the server\'s own address (%s): its public address does not work from the server', 'beaver-press' ), $last['ip'] ) );
		} else {
			/* translators: %s: reason */
			$out[] = array( false, sprintf( __( 'The site cannot reach itself: %s. Translate site, the top-up and form preparation cannot work until it can (see below).', 'beaver-press' ), $last['error'] ) );
		}

		$cron = self::cron();
		if ( $cron['late'] > self::CRON_LATE ) {
			$out[] = array(
				false,
				$cron['disabled']
					/* translators: %s: how late, e.g. "3 hours" */
					? sprintf( __( 'WP-Cron is switched off in wp-config.php and nothing runs it instead: scheduled tasks are %s late', 'beaver-press' ), human_time_diff( time() - $cron['late'] ) )
					/* translators: %s: how late, e.g. "3 hours" */
					: sprintf( __( 'Scheduled tasks are %s late: background runs and the top-up wait for visitors. Add a real cron (see below)', 'beaver-press' ), human_time_diff( time() - $cron['late'] ) ),
			);
		} else {
			$out[] = array( true, $cron['disabled'] ? __( 'Scheduled tasks run (a real cron)', 'beaver-press' ) : __( 'Scheduled tasks run', 'beaver-press' ) );
		}

		$time = self::time_limit();
		if ( ! $time['raise'] && $time['limit'] > 0 && $time['limit'] < 300 ) {
			/* translators: %d: seconds */
			$out[] = array( null, sprintf( __( 'PHP stops a request after %d seconds and the host does not let plugins raise it: long pages may be cut off. Use a batch size of 10, or run from WP-CLI', 'beaver-press' ), $time['limit'] ) );
		}
		return $out;
	}

	/**
	 * What to do when the browser-driven run cannot work: a real cron and WP-CLI.
	 */
	public static function render_help() {
		$cron = 'wget -q -O - "' . site_url( 'wp-cron.php?doing_wp_cron' ) . '" >/dev/null 2>&1';
		?>
		<details class="bp-card__body bp-hosting-help">
			<summary><?php esc_html_e( 'Run without the browser: real cron and WP-CLI', 'beaver-press' ); ?></summary>
			<p><?php esc_html_e( 'Real cron (cPanel -> Cron Jobs, or crontab), every 5 minutes:', 'beaver-press' ); ?></p>
			<pre class="bp-code-block">*/5 * * * * <?php echo esc_html( $cron ); ?></pre>
			<p><?php echo wp_kses( __( 'Then add <code>define( \'DISABLE_WP_CRON\', true );</code> to wp-config.php, so visitors no longer start the tasks.', 'beaver-press' ), array( 'code' => array() ) ); ?></p>
			<p><?php esc_html_e( 'With WP-CLI on the server (SSH), the same without HTTP to the scheduler:', 'beaver-press' ); ?></p>
			<pre class="bp-code-block">*/5 * * * * cd <?php echo esc_html( untrailingslashit( ABSPATH ) ); ?> &amp;&amp; wp cron event run --due-now --quiet
wp beaver-press hosting      <?php echo esc_html( '# ' . __( 'this check', 'beaver-press' ) ); ?>

wp beaver-press run          <?php echo esc_html( '# ' . __( 'translate the whole site from the command line', 'beaver-press' ) ); ?></pre>
			<p><?php echo wp_kses( __( 'If the site cannot reach itself at all, ask the host to allow requests from the server to its own address, or set the server\'s address in wp-config.php: <code>define( \'BEAVER_PRESS_LOOPBACK_IP\', \'127.0.0.1\' );</code>', 'beaver-press' ), array( 'code' => array() ) ); ?></p>
		</details>
		<?php
	}

	/**
	 * "Check hosting" pressed.
	 */
	public static function handle_check() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'beaver-press' ), 403 );
		}
		check_admin_referer( 'bp_hosting_check' );
		self::check();
		wp_safe_redirect( BP_Admin::tab_url( 'run', 'bp-health' ) );
		exit;
	}
}
