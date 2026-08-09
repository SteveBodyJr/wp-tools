<?php
/**
 * Removes everything this plugin created.
 *
 * Define BEAVER_APPBAR_KEEP_DATA_ON_UNINSTALL in wp-config.php to keep the
 * settings through an uninstall and reinstall.
 *
 * @package BeaverAppBar
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( defined( 'BEAVER_APPBAR_KEEP_DATA_ON_UNINSTALL' ) && BEAVER_APPBAR_KEEP_DATA_ON_UNINSTALL ) {
	return;
}

/**
 * Clears this plugin's data from the current site.
 *
 * @since 1.0.0
 */
function beaver_appbar_uninstall_site() {
	delete_option( 'beaver_appbar' );
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $beaver_appbar_site_id ) {
		switch_to_blog( (int) $beaver_appbar_site_id );
		beaver_appbar_uninstall_site();
		restore_current_blog();
	}
} else {
	beaver_appbar_uninstall_site();
}
