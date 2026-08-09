<?php
/**
 * Removes every trace of the plugin when it is deleted.
 *
 * @package BeaverUpdates
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_site_transient( 'beaver_updates_manifest' );

// The update data this plugin injected goes with it, so nothing is left
// offering packages that nothing can now supply.
delete_site_transient( 'update_plugins' );
