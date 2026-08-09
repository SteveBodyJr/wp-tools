<?php
/**
 * Removes everything this plugin created.
 *
 * Define BEAVER_SHUTTER_KEEP_DATA_ON_UNINSTALL in wp-config.php to keep the log.
 *
 * @package BeaverShutter
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( defined( 'BEAVER_SHUTTER_KEEP_DATA_ON_UNINSTALL' ) && BEAVER_SHUTTER_KEEP_DATA_ON_UNINSTALL ) {
	return;
}

/**
 * Clears this plugin's data from the current site.
 *
 * @since 1.0.0
 */
function beaver_shutter_uninstall_site() {
	delete_option( 'beaver_shutter' );
	delete_option( 'beaver_shutter_log' );
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $beaver_shutter_site_id ) {
		switch_to_blog( (int) $beaver_shutter_site_id );
		beaver_shutter_uninstall_site();
		restore_current_blog();
	}
} else {
	beaver_shutter_uninstall_site();
}
