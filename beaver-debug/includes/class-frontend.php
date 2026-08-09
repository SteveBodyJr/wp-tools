<?php
/**
 * Browser-side error capture.
 *
 * @package BeaverDebug
 */

defined( 'ABSPATH' ) || exit;

/**
 * Records JavaScript errors from real visitors.
 *
 * On a site you did not build, this is often the whole game: a broken slider,
 * a theme script fighting jQuery, a checkout button that silently does
 * nothing. None of that reaches PHP, so none of it appears in any server log —
 * the only place it exists is the visitor's console, which you will never see.
 *
 * The endpoint has to be public, because the people hitting the bug are not
 * logged in. That makes throttling and strict truncation part of the design
 * rather than an afterthought.
 *
 * @since 1.0.0
 */
class Beaver_Debug_Frontend {

	const ACTION       = 'beaver_debug_js';
	const RATE_KEY     = 'beaver_debug_js_rate';
	const MAX_PER_HOUR = 60;

	/**
	 * Registers hooks.
	 *
	 * @since 1.0.0
	 */
	public static function init() {
		if ( ! Beaver_Debug_Settings::get( 'enabled' ) || ! Beaver_Debug_Settings::get( 'capture_js' ) ) {
			return;
		}

		add_action( 'wp_head', array( __CLASS__, 'print_listener' ), 1 );
		add_action( 'admin_head', array( __CLASS__, 'print_listener' ), 1 );

		add_action( 'wp_ajax_' . self::ACTION, array( __CLASS__, 'receive' ) );
		add_action( 'wp_ajax_nopriv_' . self::ACTION, array( __CLASS__, 'receive' ) );
	}

	/**
	 * Prints the listener as early as possible in the head.
	 *
	 * Deliberately inline and dependency-free: a script that waits for jQuery
	 * cannot record the error where jQuery failed to load, which is exactly the
	 * failure worth catching.
	 *
	 * @since 1.0.0
	 */
	public static function print_listener() {
		$endpoint = esc_url_raw( admin_url( 'admin-ajax.php' ) );
		?>
<script id="beaver-debug-listener">
(function(){
	var sent = {}, count = 0, url = <?php echo wp_json_encode( $endpoint ); ?>;

	function send( kind, message, file, line, stack ) {
		// One report per distinct error per page, and a hard ceiling: a script
		// erroring inside an animation frame would otherwise flood the log.
		var key = kind + '|' + message + '|' + file + '|' + line;
		if ( sent[ key ] || count >= 5 ) { return; }
		sent[ key ] = true; count++;

		var body = new FormData();
		body.append( 'action', <?php echo wp_json_encode( self::ACTION ); ?> );
		body.append( 'kind', kind );
		body.append( 'message', String( message || '' ).slice( 0, 500 ) );
		body.append( 'file', String( file || '' ).slice( 0, 300 ) );
		body.append( 'line', String( line || 0 ) );
		body.append( 'stack', String( stack || '' ).slice( 0, 1000 ) );
		body.append( 'page', String( location.pathname + location.search ).slice( 0, 200 ) );
		body.append( 'agent', String( navigator.userAgent ).slice( 0, 200 ) );

		try {
			if ( navigator.sendBeacon ) { navigator.sendBeacon( url, body ); }
			else { fetch( url, { method: 'POST', body: body, credentials: 'same-origin', keepalive: true } ); }
		} catch ( e ) {}
	}

	window.addEventListener( 'error', function ( event ) {
		// A failed script, stylesheet or image fires an error on the element
		// rather than the window, and reports no message.
		if ( event.target && event.target !== window && event.target.tagName ) {
			var src = event.target.src || event.target.href || '';
			if ( src ) { send( 'resource', event.target.tagName.toLowerCase() + ' failed to load', src, 0, '' ); }
			return;
		}
		send( 'js', event.message, event.filename, event.lineno, event.error && event.error.stack );
	}, true );

	window.addEventListener( 'unhandledrejection', function ( event ) {
		var reason = event.reason || {};
		send( 'promise', reason.message || String( reason ), '', 0, reason.stack );
	} );
})();
</script>
		<?php
	}

	/**
	 * Receives one browser error.
	 *
	 * @since 1.0.0
	 */
	public static function receive() {
		if ( ! self::allowed() ) {
			wp_send_json_success( array( 'throttled' => true ) );
		}

		$kind    = isset( $_POST['kind'] ) ? sanitize_key( wp_unslash( $_POST['kind'] ) ) : 'js';
		$message = isset( $_POST['message'] ) ? sanitize_text_field( wp_unslash( $_POST['message'] ) ) : '';
		$file    = isset( $_POST['file'] ) ? esc_url_raw( wp_unslash( $_POST['file'] ) ) : '';
		$line    = isset( $_POST['line'] ) ? absint( $_POST['line'] ) : 0;
		$stack   = isset( $_POST['stack'] ) ? sanitize_textarea_field( wp_unslash( $_POST['stack'] ) ) : '';
		$page    = isset( $_POST['page'] ) ? esc_url_raw( wp_unslash( $_POST['page'] ) ) : '';
		$agent   = isset( $_POST['agent'] ) ? sanitize_text_field( wp_unslash( $_POST['agent'] ) ) : '';

		if ( '' === $message ) {
			wp_send_json_success( array( 'ignored' => true ) );
		}

		/*
		 * "Script error." is what a browser reports for an exception thrown by
		 * a script served from another origin without CORS headers. It carries
		 * no file, no line and no stack, so it is noise rather than a finding.
		 */
		if ( 0 === strpos( $message, 'Script error' ) && '' === $file ) {
			wp_send_json_success( array( 'ignored' => true ) );
		}

		$message = mb_substr( $message, 0, 500 );

		Beaver_Debug_Logger::write(
			array(
				'signature' => md5( 'js|' . $message . '|' . $file . '|' . $line ),
				'time'      => time(),
				'type'      => 'js',
				'severity'  => 'js',
				'message'   => $message,
				'file'      => mb_substr( $file, 0, 300 ),
				'line'      => $line,
				'source'    => self::attribute_asset( $file ),
				'trace'     => mb_substr( $stack, 0, 1000 ),
				'context'   => array(
					'where' => 'browser',
					'uri'   => mb_substr( $page, 0, 200 ),
					'agent' => self::shorten_agent( $agent ),
					'kind'  => $kind,
					'user'  => get_current_user_id(),
				),
			)
		);

		wp_send_json_success( array( 'recorded' => true ) );
	}

	/**
	 * Whether another browser report may be recorded this hour.
	 *
	 * @since 1.0.0
	 *
	 * @return bool Whether to accept.
	 */
	private static function allowed() {
		$count = (int) get_transient( self::RATE_KEY );

		if ( $count >= self::MAX_PER_HOUR ) {
			return false;
		}

		set_transient( self::RATE_KEY, $count + 1, HOUR_IN_SECONDS );

		return true;
	}

	/**
	 * Names the plugin or theme a script belongs to.
	 *
	 * Works from a URL rather than a path, which is what the browser reports.
	 * This is what turns "a script broke" into "this plugin's script broke" on
	 * a site whose code you have never read.
	 *
	 * @since 1.0.0
	 *
	 * @param string $url Script URL.
	 * @return string Human-readable source.
	 */
	public static function attribute_asset( $url ) {
		$url = (string) $url;

		if ( '' === $url ) {
			return '';
		}

		if ( preg_match( '#/(?:plugins|mu-plugins)/([^/]+)#', $url, $matches ) ) {
			/* translators: %s: plugin folder name. */
			return sprintf( __( 'plugin: %s', 'beaver-debug' ), $matches[1] );
		}

		if ( preg_match( '#/themes/([^/]+)#', $url, $matches ) ) {
			/* translators: %s: theme folder name. */
			return sprintf( __( 'theme: %s', 'beaver-debug' ), $matches[1] );
		}

		if ( false !== strpos( $url, '/wp-includes/' ) ) {
			return __( 'WordPress core', 'beaver-debug' );
		}

		$host = wp_parse_url( $url, PHP_URL_HOST );

		if ( $host && $host !== wp_parse_url( home_url(), PHP_URL_HOST ) ) {
			/* translators: %s: third-party host name. */
			return sprintf( __( 'external: %s', 'beaver-debug' ), $host );
		}

		return __( 'unknown', 'beaver-debug' );
	}

	/**
	 * Reduces a user agent string to something readable.
	 *
	 * @since 1.0.0
	 *
	 * @param string $agent Raw user agent.
	 * @return string Browser and platform.
	 */
	private static function shorten_agent( $agent ) {
		$browser = 'unknown browser';

		foreach ( array( 'Edg' => 'Edge', 'OPR' => 'Opera', 'Chrome' => 'Chrome', 'Safari' => 'Safari', 'Firefox' => 'Firefox' ) as $needle => $label ) {
			if ( false !== strpos( $agent, $needle ) ) {
				$browser = $label;
				break;
			}
		}

		$platform = 'unknown platform';

		foreach ( array( 'Android' => 'Android', 'iPhone' => 'iOS', 'iPad' => 'iPadOS', 'Windows' => 'Windows', 'Mac OS' => 'macOS', 'Linux' => 'Linux' ) as $needle => $label ) {
			if ( false !== strpos( $agent, $needle ) ) {
				$platform = $label;
				break;
			}
		}

		return $browser . ' on ' . $platform;
	}
}
