<?php
/**
 * Removes everything this plugin stored.
 *
 * WordPress runs this file directly on uninstall, with no plugin code loaded,
 * which is why it repeats the option names rather than referencing class
 * constants. That is also the reason it exists at all: the alternative,
 * register_uninstall_hook(), stores its callback in the database, so anything
 * unserializable passed to it is fatal at activation.
 *
 * @package BeaverDebug
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$beaver_debug_secret = get_option( 'beaver_debug_secret', '' );

// Rebuild the log directory name the same way the logger does.
if ( is_string( $beaver_debug_secret ) && '' !== $beaver_debug_secret ) {
	$beaver_debug_uploads = wp_upload_dir( null, false );

	if ( empty( $beaver_debug_uploads['error'] ) && ! empty( $beaver_debug_uploads['basedir'] ) ) {
		$beaver_debug_dir = trailingslashit( $beaver_debug_uploads['basedir'] )
			. 'beaver-debug-' . substr( md5( $beaver_debug_secret ), 0, 12 ) . '/';

		if ( is_dir( $beaver_debug_dir ) ) {
			foreach ( array( 'events.log', 'events.log.1', 'viewer-attempts.json', '.htaccess', 'index.php' ) as $beaver_debug_file ) {
				if ( file_exists( $beaver_debug_dir . $beaver_debug_file ) ) {
					wp_delete_file( $beaver_debug_dir . $beaver_debug_file );
				}
			}

			@rmdir( $beaver_debug_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}
}

foreach ( array( 'viewer-config.php' ) as $beaver_debug_leftover ) {
	if ( file_exists( __DIR__ . '/' . $beaver_debug_leftover ) ) {
		wp_delete_file( __DIR__ . '/' . $beaver_debug_leftover );
	}
}

delete_option( 'beaver_debug_pending_alert' );
delete_option( 'beaver_debug_alerted' );
delete_option( 'beaver_debug_settings' );
delete_option( 'beaver_debug_secret' );
delete_transient( 'beaver_debug_js_rate' );

wp_clear_scheduled_hook( 'beaver_debug_prune' );
wp_clear_scheduled_hook( 'beaver_debug_digest' );
