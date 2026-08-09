<?php
/**
 * Removes everything this plugin created.
 *
 * @package BeaverAccess
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// Any temporary account still on the site is removed with the plugin: leaving
// one behind would leave an account nobody remembers creating.
$beaver_access_users = get_users(
	array(
		'meta_key' => '_beaver_access_link', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		'fields'   => 'ID',
		'number'   => 500,
	)
);

if ( ! empty( $beaver_access_users ) ) {
	require_once ABSPATH . 'wp-admin/includes/user.php';

	foreach ( $beaver_access_users as $beaver_access_user_id ) {
		wp_delete_user( (int) $beaver_access_user_id );
	}
}

$beaver_access_table = $wpdb->prefix . 'beaver_access_links';

$wpdb->query( "DROP TABLE IF EXISTS {$beaver_access_table}" ); // phpcs:ignore WordPress.DB

delete_option( 'beaver_access_settings' );
delete_option( 'beaver_access_log' );
delete_option( 'beaver_access_db_version' );

wp_clear_scheduled_hook( 'beaver_access_cleanup' );
