<?php
/**
 * Standalone log reader.
 *
 * Deliberately loads nothing: no WordPress, no plugin classes, no database.
 * The entire point is to still work when a fatal error means WordPress cannot
 * boot at all, which is exactly when wp-admin — and therefore the normal log
 * screen — has stopped being reachable.
 *
 * @package BeaverDebug
 */

// phpcs:disable WordPress.Security.NonceVerification.Recommended
// phpcs:disable WordPress.WP.AlternativeFunctions

$config_path = __DIR__ . '/viewer-config.php';

header( 'Content-Type: text/plain; charset=utf-8' );
header( 'X-Robots-Tag: noindex, nofollow' );

if ( ! file_exists( $config_path ) ) {
	http_response_code( 404 );
	exit( "Not configured.\n" );
}

$config = require $config_path;

if ( ! is_array( $config ) || empty( $config['hash'] ) || empty( $config['log'] ) ) {
	http_response_code( 404 );
	exit( "Not configured.\n" );
}

/*
 * Throttle before anything else. This endpoint is unauthenticated by design —
 * it has to be, because the site it belongs to may be unable to authenticate
 * anyone — so guessing has to be made expensive.
 */
$attempts_file = ! empty( $config['attempts'] ) ? (string) $config['attempts'] : __DIR__ . '/viewer-attempts.json';
$attempts      = array();

if ( file_exists( $attempts_file ) ) {
	$attempts = array_filter(
		(array) json_decode( (string) file_get_contents( $attempts_file ), true ),
		static function ( $time ) {
			return ( time() - (int) $time ) < 900;
		}
	);
}

if ( count( $attempts ) >= 10 ) {
	http_response_code( 429 );
	exit( "Too many attempts. Wait fifteen minutes.\n" );
}

// If the throttle cannot be written, the endpoint is unprotected against
// guessing — refuse rather than serve without a working rate limit.
if ( ! is_writable( dirname( $attempts_file ) ) ) {
	http_response_code( 503 );
	exit( "Rate limiting unavailable — refusing to serve.\n" );
}

$token = isset( $_GET['token'] ) ? (string) $_GET['token'] : '';

if ( '' === $token ) {
	http_response_code( 403 );
	exit( "Forbidden.\n" );
}

/*
 * Constant-time comparison against a hash written by PHP's own password_hash().
 * Deliberately not wp_hash_password(): WordPress 6.8 and later pre-hash the
 * input and prefix the result with `$wp$`, which password_verify() cannot
 * check — and this file has no WordPress to ask.
 */
if ( ! password_verify( $token, (string) $config['hash'] ) ) {
	$attempts[] = time();
	file_put_contents( $attempts_file, json_encode( array_values( $attempts ) ), LOCK_EX );

	http_response_code( 403 );
	exit( "Forbidden.\n" );
}

if ( file_exists( $attempts_file ) ) {
	@unlink( $attempts_file );
}

$limit = isset( $_GET['limit'] ) ? max( 1, min( 500, (int) $_GET['limit'] ) ) : 60;
$only  = isset( $_GET['severity'] ) ? preg_replace( '/[^a-z]/', '', (string) $_GET['severity'] ) : '';

echo "Beaver Debug — standalone reader\n";
echo 'Site: ' . ( $config['site'] ?? 'unknown' ) . "\n";
echo 'Now:  ' . gmdate( 'Y-m-d H:i:s' ) . " UTC\n";
echo "\n";

echo "SERVER\n";
printf( "  PHP            %s\n", PHP_VERSION );
printf( "  memory_limit   %s\n", ini_get( 'memory_limit' ) );
printf( "  max_execution  %ss\n", ini_get( 'max_execution_time' ) );
printf( "  free disk      %s\n", @disk_free_space( __DIR__ ) ? round( disk_free_space( __DIR__ ) / 1048576 ) . ' MB' : 'unknown' );
echo "\n";

if ( ! file_exists( $config['log'] ) ) {
	exit( "No log file yet — nothing has been recorded.\n" );
}

$handle = fopen( $config['log'], 'rb' );

if ( ! $handle ) {
	exit( "Log file could not be opened.\n" );
}

$groups = array();

while ( false !== ( $line = fgets( $handle ) ) ) {
	$event = json_decode( trim( $line ), true );

	if ( ! is_array( $event ) || empty( $event['signature'] ) ) {
		continue;
	}

	if ( '' !== $only && ( $event['severity'] ?? '' ) !== $only ) {
		continue;
	}

	$key = $event['signature'];

	if ( isset( $groups[ $key ] ) ) {
		$groups[ $key ]['count']++;
		$groups[ $key ]['last'] = max( $groups[ $key ]['last'], (int) $event['time'] );
		continue;
	}

	$groups[ $key ] = array(
		'severity' => (string) ( $event['severity'] ?? '' ),
		'message'  => (string) ( $event['message'] ?? '' ),
		'file'     => (string) ( $event['file'] ?? '' ),
		'line'     => (int) ( $event['line'] ?? 0 ),
		'source'   => (string) ( $event['source'] ?? '' ),
		'context'  => (array) ( $event['context'] ?? array() ),
		'trace'    => (string) ( $event['trace'] ?? '' ),
		'count'    => 1,
		'last'     => (int) ( $event['time'] ?? 0 ),
	);
}

fclose( $handle );

usort(
	$groups,
	static function ( $a, $b ) {
		return $b['last'] <=> $a['last'];
	}
);

$groups = array_slice( $groups, 0, $limit );

if ( empty( $groups ) ) {
	exit( "Nothing recorded.\n" );
}

printf( "PROBLEMS (%d)\n\n", count( $groups ) );

foreach ( $groups as $group ) {
	printf( "[%s x%d] %s\n", strtoupper( $group['severity'] ), $group['count'], $group['message'] );

	if ( '' !== $group['file'] ) {
		printf( "    %s:%d\n", $group['file'], $group['line'] );
	}

	if ( '' !== $group['source'] ) {
		printf( "    from %s\n", $group['source'] );
	}

	if ( ! empty( $group['context']['where'] ) ) {
		printf(
			"    during %s%s %s\n",
			$group['context']['where'],
			! empty( $group['context']['action'] ) ? ' (' . $group['context']['action'] . ')' : '',
			$group['context']['uri'] ?? ''
		);
	}

	printf( "    last %s UTC\n", gmdate( 'Y-m-d H:i:s', $group['last'] ) );

	if ( '' !== $group['trace'] ) {
		echo "    ---\n";

		foreach ( explode( "\n", $group['trace'] ) as $frame ) {
			printf( "    %s\n", $frame );
		}
	}

	echo "\n";
}

echo "Filters: ?severity=fatal  &limit=200\n";
