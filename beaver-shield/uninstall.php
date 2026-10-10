<?php
/**
 * Removes everything this plugin created.
 *
 * The .htaccess block is taken out on deactivation rather than here, because a
 * plugin that is switched off but still installed must not be leaving rules
 * behind on the server.
 *
 * Define BEAVER_SHIELD_KEEP_DATA_ON_UNINSTALL in wp-config.php to keep the
 * settings.
 *
 * @package BeaverShield
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( defined( 'BEAVER_SHIELD_KEEP_DATA_ON_UNINSTALL' ) && BEAVER_SHIELD_KEEP_DATA_ON_UNINSTALL ) {
	return;
}

/**
 * Clears this plugin's data from the current site.
 *
 * @since 1.0.0
 */
function beaver_shield_uninstall_site() {
	delete_option( 'beaver_shield' );
	delete_transient( 'beaver_shield_llms_txt' );
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $beaver_shield_site_id ) {
		switch_to_blog( (int) $beaver_shield_site_id );
		beaver_shield_uninstall_site();
		restore_current_blog();
	}
} else {
	beaver_shield_uninstall_site();
}
