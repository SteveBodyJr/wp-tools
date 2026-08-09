<?php
/**
 * Error, fatal and HTTP capture.
 *
 * @package BeaverDebug
 */

defined( 'ABSPATH' ) || exit;

/**
 * Catches what goes wrong and records enough context to act on it.
 *
 * "Allowed memory size exhausted" on its own starts an investigation. The same
 * message with the request that triggered it, the plugin the file belongs to,
 * and the user who was logged in usually ends one.
 *
 * @since 1.0.0
 */
class Beaver_Debug_Capture {

	/**
	 * Signatures already written this request, so a warning inside a loop is
	 * recorded once rather than ten thousand times.
	 *
	 * @var array<string,bool>
	 */
	private static $seen = array();

	/**
	 * Memory held back so a fatal has room to be recorded.
	 *
	 * @var string|null
	 */
	private static $reserve = null;

	/**
	 * Registers the handlers.
	 *
	 * @since 1.0.0
	 */
	public static function init() {
		if ( ! Beaver_Debug_Settings::get( 'enabled' ) ) {
			return;
		}

		/*
		 * A fatal caused by exhausting memory leaves nothing to allocate with,
		 * including the few kilobytes needed to describe it. Holding some back
		 * and releasing it during shutdown is what makes the report possible.
		 */
		self::$reserve = str_repeat( '0', 128 * 1024 );

		set_error_handler( array( __CLASS__, 'on_error' ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler
		register_shutdown_function( array( __CLASS__, 'on_shutdown' ) );

		if ( Beaver_Debug_Settings::get( 'capture_http' ) ) {
			add_action( 'http_api_debug', array( __CLASS__, 'on_http' ), 10, 5 );
		}

		if ( Beaver_Debug_Settings::get( 'capture_db' ) ) {
			add_filter( 'wp_die_handler', array( __CLASS__, 'on_db_die' ), 1 );
			add_action( 'shutdown', array( __CLASS__, 'on_db_error' ), 1 );
		}

		if ( (int) Beaver_Debug_Settings::get( 'slow_request' ) > 0 ) {
			add_action( 'shutdown', array( __CLASS__, 'on_slow_request' ), 2 );
		}

		/*
		 * WordPress announces its own deprecations through actions rather than
		 * PHP errors, so they are invisible to an error handler. These are the
		 * warnings that tell you what will break on the next WordPress or PHP
		 * release, which is worth knowing before the host upgrades for you.
		 */
		foreach ( array( 'deprecated_function_run', 'deprecated_argument_run', 'deprecated_hook_run', 'deprecated_file_included' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'on_deprecated' ), 10, 3 );
		}
	}

	/**
	 * Records a WordPress deprecation.
	 *
	 * @since 1.1.0
	 *
	 * @param string $thing       What is deprecated.
	 * @param string $replacement Suggested replacement.
	 * @param string $version     Version it was deprecated in.
	 */
	public static function on_deprecated( $thing, $replacement = '', $version = '' ) {
		$message = sprintf(
			/* translators: 1: deprecated thing, 2: version. */
			__( 'Deprecated since %2$s: %1$s', 'beaver-debug' ),
			(string) $thing,
			'' !== $version ? $version : '?'
		);

		if ( '' !== $replacement ) {
			/* translators: %s: replacement to use instead. */
			$message .= ' ' . sprintf( __( 'Use %s instead.', 'beaver-debug' ), (string) $replacement );
		}

		// The caller is what matters — the deprecation itself is in core, but
		// the plugin calling it is the thing that has to change.
		$caller = '';

		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 12 ) as $frame ) { // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace
			if ( ! empty( $frame['file'] ) && false === strpos( wp_normalize_path( $frame['file'] ), '/wp-includes/' ) ) {
				$caller = $frame['file'];
				break;
			}
		}

		self::record(
			array(
				'type'     => 'deprecated',
				'severity' => 'deprecated',
				'message'  => $message,
				'file'     => $caller,
				'line'     => 0,
			)
		);
	}

	/**
	 * Records the last database error, if the request produced one.
	 *
	 * A failing query rarely stops the page — WordPress carries on and the
	 * feature that needed the data simply does nothing. That is a bug report
	 * of "it doesn't save" with no server error to go with it.
	 *
	 * @since 1.0.0
	 */
	public static function on_db_error() {
		global $wpdb;

		if ( ! isset( $wpdb ) || empty( $wpdb->last_error ) ) {
			return;
		}

		self::record(
			array(
				'type'     => 'db',
				'severity' => 'db',
				'message'  => (string) $wpdb->last_error,
				'file'     => '',
				'line'     => 0,
				'query'    => mb_substr( (string) $wpdb->last_query, 0, 300 ),
			)
		);
	}

	/**
	 * Notes that WordPress died on a database problem.
	 *
	 * @since 1.0.0
	 *
	 * @param callable $handler The wp_die handler.
	 * @return callable Unmodified handler.
	 */
	public static function on_db_die( $handler ) {
		self::on_db_error();

		return $handler;
	}

	/**
	 * Records a request that took longer than the configured threshold.
	 *
	 * Slowness is the complaint that never gets a log line. Recording which
	 * URL was slow, and how slow, turns "the site feels sluggish" into
	 * something with a page attached to it.
	 *
	 * @since 1.0.0
	 */
	public static function on_slow_request() {
		$threshold = (float) Beaver_Debug_Settings::get( 'slow_request', 0 );

		if ( $threshold <= 0 || ! defined( 'BEAVER_DEBUG_START' ) ) {
			return;
		}

		$elapsed = microtime( true ) - BEAVER_DEBUG_START;

		if ( $elapsed < $threshold ) {
			return;
		}

		global $wpdb;

		$context = self::context();
		$detail  = '';

		if ( isset( $wpdb ) ) {
			/* translators: %d: number of database queries. */
			$detail = sprintf( __( '%d database queries', 'beaver-debug' ), (int) $wpdb->num_queries );

			/*
			 * The individual queries only exist when SAVEQUERIES is on, which is
			 * too expensive to enable for everyone. When it happens to be on,
			 * the slowest few are the answer rather than a hint.
			 */
			if ( defined( 'SAVEQUERIES' ) && SAVEQUERIES && ! empty( $wpdb->queries ) ) {
				$queries = $wpdb->queries;

				usort(
					$queries,
					static function ( $a, $b ) {
						return ( $b[1] ?? 0 ) <=> ( $a[1] ?? 0 );
					}
				);

				foreach ( array_slice( $queries, 0, 3 ) as $query ) {
					$detail .= sprintf( "\n%ss  %s", number_format_i18n( (float) $query[1], 3 ), mb_substr( trim( (string) $query[0] ), 0, 160 ) );
				}
			}
		}

		self::record(
			array(
				'type'     => 'slow',
				'severity' => 'slow',
				'query'    => $detail,
				// The URI is part of the message so each slow page groups on its
				// own rather than every slow request collapsing into one row.
				'message'  => sprintf(
					/* translators: 1: seconds, 2: request path. */
					__( 'Slow request: %1$ss for %2$s', 'beaver-debug' ),
					number_format_i18n( $elapsed, 1 ),
					$context['uri']
				),
				'file'     => '',
				'line'     => 0,
			)
		);
	}

	/**
	 * Records a PHP warning or notice.
	 *
	 * Returns false so PHP's own handler still runs: this observes, it does not
	 * take over error handling from the site.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $errno   Error level.
	 * @param string $message Message.
	 * @param string $file    File.
	 * @param int    $line    Line.
	 * @return bool Always false.
	 */
	public static function on_error( $errno, $message, $file = '', $line = 0 ) {
		// Respect both error_reporting() and the @ operator, which in PHP 8
		// narrows error_reporting() rather than zeroing it.
		if ( ! ( error_reporting() & $errno ) ) { // phpcs:ignore WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting
			return false;
		}

		$severity = self::severity( $errno );

		if ( ! self::should_capture( $severity ) ) {
			return false;
		}

		self::record(
			array(
				'type'     => 'php',
				'severity' => $severity,
				'message'  => (string) $message,
				'file'     => (string) $file,
				'line'     => (int) $line,
			)
		);

		return false;
	}

	/**
	 * Records a fatal error, if the request ended in one.
	 *
	 * @since 1.0.0
	 */
	public static function on_shutdown() {
		self::$reserve = null;

		$error = error_get_last();

		if ( null === $error ) {
			return;
		}

		$fatal = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR );

		if ( ! in_array( $error['type'], $fatal, true ) ) {
			return;
		}

		self::record(
			array(
				'type'     => 'php',
				'severity' => 'fatal',
				'message'  => (string) $error['message'],
				'file'     => (string) $error['file'],
				'line'     => (int) $error['line'],
			)
		);
	}

	/**
	 * Records an outbound HTTP request that failed.
	 *
	 * A plugin that talks to an API fails silently far more often than it
	 * fatals — a timeout or a 500 from the far end just becomes "it did not
	 * work". These are the events that explain those reports.
	 *
	 * @since 1.0.0
	 *
	 * @param array|WP_Error $response Response or error.
	 * @param string         $context  Always 'response' here.
	 * @param string         $class    Transport class.
	 * @param array          $args     Request arguments.
	 * @param string         $url      Requested URL.
	 */
	public static function on_http( $response, $context, $class, $args, $url ) {
		unset( $context, $class );

		$code    = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		$failed  = is_wp_error( $response ) || $code >= 400;

		if ( ! $failed ) {
			return;
		}

		$message = is_wp_error( $response )
			? $response->get_error_message()
			: sprintf( 'HTTP %d', $code );

		self::record(
			array(
				'type'     => 'http',
				'severity' => 'http',
				'message'  => sprintf( '%s %s — %s', strtoupper( (string) ( $args['method'] ?? 'GET' ) ), self::redact_url( $url ), $message ),
				'file'     => '',
				'line'     => 0,
			)
		);
	}

	/**
	 * Builds and stores one event.
	 *
	 * @since 1.0.0
	 *
	 * @param array $event Partial event.
	 */
	private static function record( $event ) {
		$signature = md5( $event['severity'] . '|' . $event['message'] . '|' . $event['file'] . '|' . $event['line'] );

		// Once per request is enough to know it happened; the stored count
		// across requests is what shows how often.
		if ( isset( self::$seen[ $signature ] ) ) {
			return;
		}

		self::$seen[ $signature ] = true;

		$event['signature'] = $signature;

		if ( ! empty( $event['query'] ) ) {
			$event['trace'] = (string) $event['query'];
		}

		unset( $event['query'] );
		$event['time']      = time();
		$event['source']    = self::attribute( $event['file'] );
		$event['context']   = self::context();

		if ( 'fatal' === $event['severity'] ) {
			$event['trace'] = self::trace();
		}

		Beaver_Debug_Logger::write( $event );

		if ( class_exists( 'Beaver_Debug_Alerts' ) ) {
			Beaver_Debug_Alerts::consider( $event );
		}
	}

	/**
	 * Names the plugin, theme or core area a file belongs to.
	 *
	 * This is the single most useful field in the whole record: it turns "an
	 * error happened" into "this plugin has a problem".
	 *
	 * @since 1.0.0
	 *
	 * @param string $file Absolute path.
	 * @return string Human-readable source.
	 */
	public static function attribute( $file ) {
		$file = (string) $file;

		if ( '' === $file ) {
			return '';
		}

		$file = wp_normalize_path( $file );

		if ( preg_match( '#/(?:plugins|mu-plugins)/([^/]+)#', $file, $matches ) ) {
			return sprintf(
				/* translators: %s: plugin folder or file name. */
				__( 'plugin: %s', 'beaver-debug' ),
				str_replace( '.php', '', $matches[1] )
			);
		}

		if ( preg_match( '#/themes/([^/]+)#', $file, $matches ) ) {
			return sprintf(
				/* translators: %s: theme folder name. */
				__( 'theme: %s', 'beaver-debug' ),
				$matches[1]
			);
		}

		if ( false !== strpos( $file, '/wp-includes/' ) || false !== strpos( $file, '/wp-admin/' ) ) {
			return __( 'WordPress core', 'beaver-debug' );
		}

		return __( 'unknown', 'beaver-debug' );
	}

	/**
	 * Captures what the site was doing when the event happened.
	 *
	 * @since 1.0.0
	 *
	 * @return array Context.
	 */
	private static function context() {
		$where = 'front end';

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			$where = 'WP-CLI';
		} elseif ( wp_doing_cron() ) {
			$where = 'cron';
		} elseif ( wp_doing_ajax() ) {
			$where = 'ajax';
		} elseif ( is_admin() ) {
			$where = 'admin';
		}

		$uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

		$action = '';

		// The AJAX action is what identifies the failing operation, and it is
		// the first thing you want to know about an admin-ajax fatal.
		if ( 'ajax' === $where && isset( $_REQUEST['action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$action = sanitize_key( wp_unslash( $_REQUEST['action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		return array(
			'where'  => $where,
			'uri'    => mb_substr( $uri, 0, 200 ),
			'action' => $action,
			'user'   => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
			'memory' => (int) memory_get_peak_usage( true ),
			'limit'  => (string) ini_get( 'memory_limit' ),
		);
	}

	/**
	 * Returns a compact backtrace with absolute paths shortened.
	 *
	 * @since 1.0.0
	 *
	 * @return string Trace.
	 */
	private static function trace() {
		$frames = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 12 ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace
		$root   = wp_normalize_path( ABSPATH );
		$out    = array();

		foreach ( $frames as $frame ) {
			if ( empty( $frame['file'] ) ) {
				continue;
			}

			$out[] = sprintf(
				'%s:%d %s',
				str_replace( $root, '', wp_normalize_path( $frame['file'] ) ),
				(int) ( $frame['line'] ?? 0 ),
				(string) ( $frame['function'] ?? '' )
			);
		}

		return implode( "\n", array_slice( $out, 0, 10 ) );
	}

	/**
	 * Strips credentials from a URL before it is stored.
	 *
	 * Outbound requests routinely carry a key in the query string, and a log
	 * that records those has turned a diagnostic into a liability.
	 *
	 * @since 1.0.0
	 *
	 * @param string $url Requested URL.
	 * @return string Redacted URL.
	 */
	private static function redact_url( $url ) {
		$parts = wp_parse_url( (string) $url );

		if ( empty( $parts['host'] ) ) {
			return '';
		}

		$clean = ( $parts['scheme'] ?? 'https' ) . '://' . $parts['host'] . ( $parts['path'] ?? '' );

		if ( ! empty( $parts['query'] ) ) {
			$clean .= '?…';
		}

		return $clean;
	}

	/**
	 * Maps a PHP error level to a severity this plugin reports on.
	 *
	 * @since 1.0.0
	 *
	 * @param int $errno Error level.
	 * @return string Severity.
	 */
	private static function severity( $errno ) {
		$fatal = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR );

		if ( in_array( $errno, $fatal, true ) ) {
			return 'fatal';
		}

		$warning = array( E_WARNING, E_CORE_WARNING, E_COMPILE_WARNING, E_USER_WARNING );

		return in_array( $errno, $warning, true ) ? 'warning' : 'notice';
	}

	/**
	 * Whether the configured level records this severity.
	 *
	 * @since 1.0.0
	 *
	 * @param string $severity Severity.
	 * @return bool Whether to record.
	 */
	private static function should_capture( $severity ) {
		// These are opted into by their own settings, so the PHP error level
		// does not gate them.
		if ( in_array( $severity, array( 'http', 'db', 'slow', 'js', 'deprecated', 'change' ), true ) ) {
			return true;
		}

		$level = (string) Beaver_Debug_Settings::get( 'level', 'warning' );

		if ( 'fatal' === $level ) {
			return 'fatal' === $severity;
		}

		if ( 'warning' === $level ) {
			return in_array( $severity, array( 'fatal', 'warning' ), true );
		}

		return true;
	}
}
