<?php
/**
 * Alerting.
 *
 * @package BeaverDebug
 */

defined( 'ABSPATH' ) || exit;

/**
 * Tells you a site broke, instead of waiting to be asked.
 *
 * A log nobody opens is a log nobody reads. The first time a new fatal appears,
 * this sends it — once — with the same report the admin screen would show.
 *
 * @since 1.1.0
 */
class Beaver_Debug_Alerts {

	const OPTION_PENDING = 'beaver_debug_pending_alert';
	const OPTION_SENT    = 'beaver_debug_alerted';

	/**
	 * Registers hooks.
	 *
	 * @since 1.1.0
	 */
	public static function init() {
		// A site that fatals on every request has no "healthy request" to send
		// from, so delivery is also attempted on admin_init and on cron.
		add_action( 'admin_init', array( __CLASS__, 'flush' ) );
		add_action( 'beaver_debug_prune', array( __CLASS__, 'flush' ) );
	}

	/**
	 * Considers an event for alerting.
	 *
	 * Called from the capture path, which may be running inside a shutdown
	 * handler after a fatal. Everything here is therefore cheap and guarded:
	 * the alert is written down first and delivered second, so a failure to
	 * send loses nothing.
	 *
	 * @since 1.1.0
	 *
	 * @param array $event The recorded event.
	 */
	public static function consider( $event ) {
		$when = (string) Beaver_Debug_Settings::get( 'alert_on', 'fatal' );

		if ( 'off' === $when ) {
			return;
		}

		$severity = (string) ( $event['severity'] ?? '' );

		$wanted = array(
			'fatal'    => array( 'fatal' ),
			'fatal_db' => array( 'fatal', 'db' ),
			'all'      => array( 'fatal', 'db', 'warning', 'http', 'js', 'slow' ),
		);

		if ( ! in_array( $severity, $wanted[ $when ] ?? array( 'fatal' ), true ) ) {
			return;
		}

		// One alert per distinct problem per day. A fatal inside a loop would
		// otherwise mean thousands of identical emails.
		$sent = get_option( self::OPTION_SENT, array() );
		$sent = is_array( $sent ) ? $sent : array();
		$key  = (string) $event['signature'];

		if ( isset( $sent[ $key ] ) && ( time() - (int) $sent[ $key ] ) < DAY_IN_SECONDS ) {
			return;
		}

		$sent[ $key ] = time();

		// Keep the ledger small; old entries are past their throttle window.
		if ( count( $sent ) > 100 ) {
			$sent = array_slice( $sent, -50, null, true );
		}

		update_option( self::OPTION_SENT, $sent, false );

		$pending   = get_option( self::OPTION_PENDING, array() );
		$pending   = is_array( $pending ) ? $pending : array();
		$pending[] = array(
			'severity' => $severity,
			'message'  => (string) ( $event['message'] ?? '' ),
			'file'     => (string) ( $event['file'] ?? '' ),
			'line'     => (int) ( $event['line'] ?? 0 ),
			'source'   => (string) ( $event['source'] ?? '' ),
			'context'  => (array) ( $event['context'] ?? array() ),
			'time'     => (int) ( $event['time'] ?? time() ),
		);

		update_option( self::OPTION_PENDING, array_slice( $pending, -20 ), false );

		self::flush();
	}

	/**
	 * Delivers anything waiting.
	 *
	 * @since 1.1.0
	 */
	public static function flush() {
		$pending = get_option( self::OPTION_PENDING, array() );

		if ( ! is_array( $pending ) || empty( $pending ) ) {
			return;
		}

		// Clear first. A delivery that dies half way through must not leave a
		// queue that re-sends the same alert on every subsequent request.
		delete_option( self::OPTION_PENDING );

		foreach ( $pending as $alert ) {
			self::deliver( $alert );
		}
	}

	/**
	 * Sends one alert by email and webhook.
	 *
	 * @since 1.1.0
	 *
	 * @param array $alert Alert data.
	 */
	private static function deliver( $alert ) {
		$site    = wp_parse_url( home_url(), PHP_URL_HOST );
		$subject = sprintf(
			/* translators: 1: severity, 2: site host. */
			__( '[%1$s] %2$s', 'beaver-debug' ),
			strtoupper( $alert['severity'] ),
			$site
		);

		$body = self::body( $alert );

		$email = (string) Beaver_Debug_Settings::get( 'alert_email', '' );

		if ( '' === $email ) {
			$email = (string) get_option( 'admin_email', '' );
		}

		if ( '' !== $email && function_exists( 'wp_mail' ) ) {
			try {
				wp_mail( $email, $subject, $body );
			} catch ( Throwable $e ) {
				// A mail failure must never take the request with it — the
				// event is already safely in the log either way.
				unset( $e );
			}
		}

		$webhook = (string) Beaver_Debug_Settings::get( 'alert_webhook', '' );

		if ( '' !== $webhook ) {
			wp_remote_post(
				$webhook,
				array(
					'timeout'  => 5,
					'blocking' => false,
					'headers'  => array( 'Content-Type' => 'application/json' ),
					'body'     => wp_json_encode(
						array(
							// "text" is what Slack, Discord and most incoming
							// webhooks render without any configuration.
							'text'     => $subject . "\n" . $body,
							'site'     => home_url( '/' ),
							'severity' => $alert['severity'],
							'message'  => $alert['message'],
							'source'   => $alert['source'],
						)
					),
				)
			);
		}
	}

	/**
	 * Builds the message body.
	 *
	 * @since 1.1.0
	 *
	 * @param array $alert Alert data.
	 * @return string Body.
	 */
	private static function body( $alert ) {
		$lines = array();

		$lines[] = sprintf( '%s on %s', strtoupper( $alert['severity'] ), home_url( '/' ) );
		$lines[] = '';
		$lines[] = $alert['message'];

		if ( '' !== $alert['file'] ) {
			$lines[] = sprintf( '  %s:%d', str_replace( wp_normalize_path( ABSPATH ), '', wp_normalize_path( $alert['file'] ) ), $alert['line'] );
		}

		if ( '' !== $alert['source'] ) {
			$lines[] = sprintf( '  from %s', $alert['source'] );
		}

		$context = $alert['context'];

		if ( ! empty( $context['where'] ) ) {
			$lines[] = sprintf(
				'  during %s%s%s',
				$context['where'],
				! empty( $context['action'] ) ? ' (' . $context['action'] . ')' : '',
				! empty( $context['uri'] ) ? ' ' . $context['uri'] : ''
			);
		}

		if ( ! empty( $context['memory'] ) ) {
			$lines[] = sprintf( '  peak memory %s of %s', size_format( (int) $context['memory'] ), (string) ( $context['limit'] ?? '' ) );
		}

		$lines[] = '';
		$lines[] = sprintf( 'Seen %s UTC', gmdate( 'Y-m-d H:i:s', (int) $alert['time'] ) );
		$lines[] = sprintf( 'Full log: %s', admin_url( 'tools.php?page=beaver-debug' ) );

		$token = (string) Beaver_Debug_Settings::get( 'viewer_token', '' );

		if ( '' !== $token ) {
			$lines[] = sprintf( 'If the site is down: %s', Beaver_Debug_Viewer::url() );
		}

		$lines[] = '';
		$lines[] = __( 'You will not be told about this same problem again today.', 'beaver-debug' );

		return implode( "\n", $lines );
	}
}
