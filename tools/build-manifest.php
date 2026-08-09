<?php
/**
 * Builds plugins.json from the plugin headers.
 *
 * The manifest is what an update channel reads to decide whether a site is
 * behind. It is generated rather than hand written so it can never drift from
 * the versions actually in the source, which is the one failure that would
 * make every site either miss an update or be offered one that does not exist.
 *
 * Run from the repository root:
 *
 *     php tools/build-manifest.php
 *
 * @package DigitalBeaverWPTools
 */

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

const REPO = 'https://github.com/SteveBodyJr/wp-tools';

$root = dirname( __DIR__ );

chdir( $root );

$plugins = array();
$errors  = array();

foreach ( glob( 'beaver-*', GLOB_ONLYDIR ) as $slug ) {
	$main = $slug . '/' . $slug . '.php';

	if ( ! file_exists( $main ) ) {
		$errors[] = sprintf( '%s: no %s.php in the plugin root.', $slug, $slug );

		continue;
	}

	$source = (string) file_get_contents( $main );
	$header = static function ( $field ) use ( $source ) {
		$pattern = '/^\s*\*\s*' . preg_quote( $field, '/' ) . ':\s*(.+)$/mi';

		return preg_match( $pattern, $source, $match ) ? trim( $match[1] ) : '';
	};

	$version = $header( 'Version' );
	$name    = $header( 'Plugin Name' );

	if ( '' === $version || '' === $name ) {
		$errors[] = sprintf( '%s: the plugin header is missing a name or a version.', $slug );

		continue;
	}

	// The version lives in three places and they have to agree, or cache
	// busting and the update offer disagree about what is installed.
	if ( preg_match( "/_VERSION',\s*'([0-9.]+)'/", $source, $constant ) && $constant[1] !== $version ) {
		$errors[] = sprintf( '%s: header says %s, constant says %s.', $slug, $version, $constant[1] );
	}

	$tested = '';
	$readme = $slug . '/readme.txt';

	if ( file_exists( $readme ) ) {
		$text = (string) file_get_contents( $readme );

		if ( preg_match( '/^Tested up to:\s*(.+)$/mi', $text, $match ) ) {
			$tested = trim( $match[1] );
		}

		if ( preg_match( '/^Stable tag:\s*(.+)$/mi', $text, $match ) && trim( $match[1] ) !== $version ) {
			$errors[] = sprintf( '%s: header says %s, readme stable tag says %s.', $slug, $version, trim( $match[1] ) );
		}
	}

	$archive = sprintf( '%s-%s.zip', $slug, $version );

	if ( ! file_exists( $archive ) ) {
		$errors[] = sprintf( '%s: %s has not been built yet.', $slug, $archive );
	}

	$plugins[ $slug ] = array(
		'slug'         => $slug,
		'name'         => $name,
		'version'      => $version,
		'requires'     => $header( 'Requires at least' ),
		'requires_php' => $header( 'Requires PHP' ),
		'tested'       => $tested,
		'author'       => $header( 'Author' ),
		'homepage'     => REPO . '/tree/main/' . $slug,
		'package'      => sprintf( '%s/releases/download/%s-%s/%s', REPO, $slug, $version, $archive ),
	);
}

if ( $errors ) {
	fwrite( STDERR, "Refusing to write a manifest that does not match the source:\n" );

	foreach ( $errors as $error ) {
		fwrite( STDERR, '  - ' . $error . "\n" );
	}

	exit( 1 );
}

$manifest = array(
	'schema'  => 1,
	'updated' => gmdate( 'c' ),
	'plugins' => $plugins,
);

file_put_contents(
	$root . '/plugins.json',
	wp_json_pretty( $manifest ) . "\n"
);

printf( "Wrote plugins.json with %d plugins.\n", count( $plugins ) );

foreach ( $plugins as $slug => $plugin ) {
	printf( "  %-24s %s\n", $slug, $plugin['version'] );
}

/**
 * Encodes the manifest the way a human will want to read the diff.
 *
 * @param array $data Manifest.
 * @return string
 */
function wp_json_pretty( array $data ) {
	return (string) json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
}
