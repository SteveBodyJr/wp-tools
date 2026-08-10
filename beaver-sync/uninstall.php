<?php
/**
 * Removes everything this plugin created.
 *
 * Define BEAVER_SYNC_KEEP_DATA_ON_UNINSTALL in wp-config.php to keep the
 * settings through an uninstall and reinstall.
 *
 * @package BeaverSync
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( defined( 'BEAVER_SYNC_KEEP_DATA_ON_UNINSTALL' ) && BEAVER_SYNC_KEEP_DATA_ON_UNINSTALL ) {
	return;
}

/**
 * Clears this plugin's data from the current site.
 *
 * Downloaded media is deliberately left where it is. Those are the site's own
 * files now, and removing a plugin is not a reason to delete a media library.
 *
 * @since 1.0.0
 */
function beaver_sync_uninstall_site() {
	delete_option( 'beaver_sync' );
	delete_transient( 'beaver_sync_queue' );
	delete_transient( 'beaver_sync_plan' );
	delete_transient( 'beaver_sync_error' );
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $beaver_sync_site_id ) {
		switch_to_blog( (int) $beaver_sync_site_id );
		beaver_sync_uninstall_site();
		restore_current_blog();
	}
} else {
	beaver_sync_uninstall_site();
}
