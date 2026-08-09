<?php
/**
 * Removes everything this plugin created, when it is deleted from the Plugins
 * screen. Deactivating keeps your settings, backups and trash; only deleting
 * clears them.
 *
 * @package BeaverFileManager
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/*
 * Keep the stored versions and the trash when a site opts out, so an accidental
 * delete does not take the only remaining copy of a file with it. Add this to
 * wp-config.php:
 *
 *   define( 'BFM_KEEP_DATA_ON_UNINSTALL', true );
 */
if ( defined( 'BFM_KEEP_DATA_ON_UNINSTALL' ) && BFM_KEEP_DATA_ON_UNINSTALL ) {
	return;
}

/**
 * Removes a folder and everything under it.
 *
 * Written out here rather than reused from the plugin, because uninstall.php
 * runs without the plugin's classes loaded.
 *
 * @param string $path Absolute path.
 */
function bfm_uninstall_erase( $path ) {
	if ( is_link( $path ) || is_file( $path ) ) {
		@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		return;
	}

	if ( ! is_dir( $path ) ) {
		return;
	}

	$handle = @opendir( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

	if ( ! $handle ) {
		return;
	}

	while ( false !== ( $name = readdir( $handle ) ) ) {
		if ( '.' === $name || '..' === $name ) {
			continue;
		}

		bfm_uninstall_erase( $path . '/' . $name );
	}

	closedir( $handle );

	@rmdir( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
}

$bfm_cleanup = static function () {
	$key = get_option( 'beaver_fm_storage_key' );

	if ( $key && is_string( $key ) ) {
		$uploads = wp_upload_dir( null, false );

		if ( ! empty( $uploads['basedir'] ) ) {
			$storage = untrailingslashit( wp_normalize_path( $uploads['basedir'] ) ) . '/beaver-fm-' . $key;

			// Only ever delete a folder whose name this plugin generated.
			if ( is_dir( $storage ) && false !== strpos( $storage, '/beaver-fm-' ) ) {
				bfm_uninstall_erase( $storage );
			}
		}
	}

	delete_option( 'beaver_fm_settings' );
	delete_option( 'beaver_fm_log' );
	delete_option( 'beaver_fm_storage_key' );
	delete_transient( 'beaver_fm_storage_ready' );
};

if ( is_multisite() ) {
	$bfm_sites = get_sites( array( 'fields' => 'ids' ) );

	foreach ( $bfm_sites as $bfm_site_id ) {
		switch_to_blog( $bfm_site_id );
		$bfm_cleanup();
		restore_current_blog();
	}
} else {
	$bfm_cleanup();
}
