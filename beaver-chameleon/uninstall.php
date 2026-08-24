<?php
/**
 * Removes everything this plugin created.
 *
 * Define BEAVER_CHAMELEON_KEEP_DATA_ON_UNINSTALL in wp-config.php to keep the
 * statistics instead.
 *
 * @package BeaverChameleon
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( defined( 'BEAVER_CHAMELEON_KEEP_DATA_ON_UNINSTALL' ) && BEAVER_CHAMELEON_KEEP_DATA_ON_UNINSTALL ) {
	return;
}

/**
 * Clears this plugin's data from the current site.
 *
 * The daily transient expires on its own within a day either way; clearing
 * today's and yesterday's rows here just means nothing lingers even for the
 * few hours before that happens.
 *
 * @since 1.0.0
 */
function beaver_chameleon_uninstall_site() {
	delete_option( 'beaver_chameleon_stats' );
	delete_option( 'beaver_chameleon_log' );
	delete_transient( 'beaver_chameleon_today_' . gmdate( 'Y-m-d' ) );
	delete_transient( 'beaver_chameleon_today_' . gmdate( 'Y-m-d', strtotime( '-1 day' ) ) );
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $beaver_chameleon_site_id ) {
		switch_to_blog( (int) $beaver_chameleon_site_id );
		beaver_chameleon_uninstall_site();
		restore_current_blog();
	}
} else {
	beaver_chameleon_uninstall_site();
}
