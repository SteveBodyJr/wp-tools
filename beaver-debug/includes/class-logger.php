<?php
/**
 * Event storage.
 *
 * @package BeaverDebug
 */

defined( 'ABSPATH' ) || exit;

/**
 * Writes events to a protected file and reads them back grouped.
 *
 * A log is only useful if it exists when you need it and cannot be read by
 * anyone else. On shared hosting there is no directory outside the web root to
 * write to, so the file lives in uploads behind a random directory name and a
 * deny rule, and is never given a guessable name like debug.log.
 *
 * @since 1.0.0
 */
class Beaver_Debug_Logger {

	const OPTION_SECRET = 'beaver_debug_secret';

	/**
	 * Bytes a log file may reach before it is rotated.
	 */
	const MAX_BYTES = 2097152;

	/**
	 * Cached directory path.
	 *
	 * @var string|null
	 */
	private static $dir = null;

	/**
	 * Returns the directory events are written to, creating it if needed.
	 *
	 * @since 1.0.0
	 *
	 * @return string Absolute path with a trailing slash, or an empty string on failure.
	 */
	public static function dir() {
		if ( null !== self::$dir ) {
			return self::$dir;
		}

		$uploads = wp_upload_dir( null, false );

		if ( ! empty( $uploads['error'] ) || empty( $uploads['basedir'] ) ) {
			self::$dir = '';

			return self::$dir;
		}

		$secret = get_option( self::OPTION_SECRET, '' );

		if ( ! is_string( $secret ) || 32 !== strlen( $secret ) ) {
			$secret = wp_generate_password( 32, false, false );
			update_option( self::OPTION_SECRET, $secret, false );
		}

		$dir = trailingslashit( $uploads['basedir'] ) . 'beaver-debug-' . substr( md5( $secret ), 0, 12 ) . '/';

		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		if ( ! is_dir( $dir ) || ! is_writable( $dir ) ) {
			self::$dir = '';

			return self::$dir;
		}

		self::protect( $dir );

		self::$dir = $dir;

		return self::$dir;
	}

	/**
	 * Writes the guards that keep the log private.
	 *
	 * Belt and braces: Apache and LiteSpeed honour the .htaccess, and the
	 * index.php covers servers that ignore it but would otherwise list the
	 * directory. Neither helps on Nginx, which is why the directory name
	 * carries a random component as well.
	 *
	 * @since 1.0.0
	 *
	 * @param string $dir Directory to protect.
	 */
	private static function protect( $dir ) {
		if ( ! file_exists( $dir . '.htaccess' ) ) {
			file_put_contents( $dir . '.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		if ( ! file_exists( $dir . 'index.php' ) ) {
			file_put_contents( $dir . 'index.php', '<?php // Silence is golden.' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
	}

	/**
	 * Returns the current log file path.
	 *
	 * @since 1.0.0
	 *
	 * @return string Path, or an empty string when logging is unavailable.
	 */
	public static function file() {
		$dir = self::dir();

		return '' === $dir ? '' : $dir . 'events.log';
	}

	/**
	 * Appends one event.
	 *
	 * Deliberately dependency-free and defensive: this runs inside an error
	 * handler, and a logger that throws while recording an error would replace
	 * a readable failure with an unreadable one.
	 *
	 * @since 1.0.0
	 *
	 * @param array $event Event data.
	 * @return bool Whether the event was written.
	 */
	public static function write( $event ) {
		$file = self::file();

		if ( '' === $file ) {
			return false;
		}

		if ( file_exists( $file ) && filesize( $file ) > self::MAX_BYTES ) {
			self::rotate( $file );
		}

		$line = wp_json_encode( $event );

		if ( ! is_string( $line ) ) {
			return false;
		}

		return (bool) file_put_contents( $file, $line . "\n", FILE_APPEND | LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	/**
	 * Moves the current log aside and keeps one previous generation.
	 *
	 * @since 1.0.0
	 *
	 * @param string $file Log file path.
	 */
	private static function rotate( $file ) {
		$previous = $file . '.1';

		if ( file_exists( $previous ) ) {
			wp_delete_file( $previous );
		}

		@rename( $file, $previous ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	/**
	 * Reads events back, newest first, grouped by what caused them.
	 *
	 * The same warning inside a loop can be written hundreds of times. Grouping
	 * by signature turns that into one row with a count, which is what you
	 * actually want to read.
	 *
	 * @since 1.0.0
	 *
	 * @param int $limit Maximum groups to return.
	 * @return array<int,array> Grouped events.
	 */
	public static function read( $limit = 100 ) {
		$file = self::file();

		if ( '' === $file || ! file_exists( $file ) ) {
			return array();
		}

		$handle = fopen( $file, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		if ( ! $handle ) {
			return array();
		}

		$groups = array();

		while ( false !== ( $line = fgets( $handle ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			$event = json_decode( trim( $line ), true );

			if ( ! is_array( $event ) || empty( $event['signature'] ) ) {
				continue;
			}

			$key = (string) $event['signature'];

			if ( isset( $groups[ $key ] ) ) {
				++$groups[ $key ]['count'];

				if ( $event['time'] > $groups[ $key ]['last'] ) {
					$groups[ $key ]['last']    = $event['time'];
					$groups[ $key ]['context'] = $event['context'] ?? array();
				}

				$groups[ $key ]['first'] = min( $groups[ $key ]['first'], $event['time'] );

				continue;
			}

			$groups[ $key ] = array(
				'signature' => $key,
				'type'      => (string) ( $event['type'] ?? 'error' ),
				'severity'  => (string) ( $event['severity'] ?? 'notice' ),
				'message'   => (string) ( $event['message'] ?? '' ),
				'file'      => (string) ( $event['file'] ?? '' ),
				'line'      => (int) ( $event['line'] ?? 0 ),
				'source'    => (string) ( $event['source'] ?? '' ),
				'context'   => $event['context'] ?? array(),
				'trace'     => (string) ( $event['trace'] ?? '' ),
				'count'     => 1,
				'first'     => (int) ( $event['time'] ?? 0 ),
				'last'      => (int) ( $event['time'] ?? 0 ),
			);
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		uasort(
			$groups,
			static function ( $a, $b ) {
				return $b['last'] <=> $a['last'];
			}
		);

		return array_slice( array_values( $groups ), 0, (int) $limit );
	}

	/**
	 * Counts events by severity since a point in time.
	 *
	 * @since 1.0.0
	 *
	 * @param int $since Unix timestamp.
	 * @return array Counts keyed by severity.
	 */
	public static function summary( $since = 0 ) {
		$counts = array( 'fatal' => 0, 'warning' => 0, 'notice' => 0, 'http' => 0, 'js' => 0, 'db' => 0, 'slow' => 0, 'deprecated' => 0, 'change' => 0 );

		foreach ( self::read( 500 ) as $group ) {
			if ( $group['last'] < $since ) {
				continue;
			}

			$severity = isset( $counts[ $group['severity'] ] ) ? $group['severity'] : 'notice';

			$counts[ $severity ] += (int) $group['count'];
		}

		return $counts;
	}

	/**
	 * Deletes every stored event.
	 *
	 * @since 1.0.0
	 */
	public static function clear() {
		$file = self::file();

		if ( '' === $file ) {
			return;
		}

		foreach ( array( $file, $file . '.1' ) as $path ) {
			if ( file_exists( $path ) ) {
				wp_delete_file( $path );
			}
		}
	}

	/**
	 * Drops events older than the retention setting.
	 *
	 * Rewrites the file rather than truncating it, so a long-running site does
	 * not accumulate a log nobody will ever read the bottom of.
	 *
	 * @since 1.0.0
	 */
	public static function prune() {
		$file = self::file();

		if ( '' === $file || ! file_exists( $file ) ) {
			return;
		}

		$cutoff = time() - ( (int) Beaver_Debug_Settings::get( 'retain_days', 14 ) * DAY_IN_SECONDS );
		$handle = fopen( $file, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		if ( ! $handle ) {
			return;
		}

		$kept = '';

		while ( false !== ( $line = fgets( $handle ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			$event = json_decode( trim( $line ), true );

			if ( is_array( $event ) && (int) ( $event['time'] ?? 0 ) >= $cutoff ) {
				$kept .= $line;
			}
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		file_put_contents( $file, $kept, LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	/**
	 * Returns the size of the stored log in bytes.
	 *
	 * @since 1.0.0
	 *
	 * @return int Bytes.
	 */
	public static function size() {
		$file  = self::file();
		$bytes = 0;

		if ( '' === $file ) {
			return 0;
		}

		foreach ( array( $file, $file . '.1' ) as $path ) {
			if ( file_exists( $path ) ) {
				$bytes += (int) filesize( $path );
			}
		}

		return $bytes;
	}
}
