<?php
/**
 * Removes every trace of the plugin when it is deleted.
 *
 * @package BeaverPWA
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$beaver_pwa_options = array(
	'beaver_pwa_settings',
	'beaver_pwa_cache_bump',
	'beaver_pwa_icons',
	'beaver_pwa_rules_version',
);

foreach ( $beaver_pwa_options as $beaver_pwa_option ) {
	delete_option( $beaver_pwa_option );
}

delete_transient( 'beaver_pwa_health' );

// Remove the rendered icon set.
$beaver_pwa_uploads = wp_upload_dir();
$beaver_pwa_dir     = trailingslashit( $beaver_pwa_uploads['basedir'] ) . 'beaver-pwa';

if ( is_dir( $beaver_pwa_dir ) ) {
	$beaver_pwa_files = glob( trailingslashit( $beaver_pwa_dir ) . '*' );

	if ( is_array( $beaver_pwa_files ) ) {
		foreach ( $beaver_pwa_files as $beaver_pwa_file ) {
			if ( is_file( $beaver_pwa_file ) ) {
				wp_delete_file( $beaver_pwa_file );
			}
		}
	}

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	@rmdir( $beaver_pwa_dir );
}

flush_rewrite_rules( false );
