<?php
/**
 * Removes everything this plugin created, when it is deleted from the Plugins
 * screen. Deactivating leaves your settings and leads alone; only deleting
 * clears them.
 *
 * @package BeaverAIChat
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/*
 * Keep the data when a site opts out, so an accidental delete does not lose a
 * year of conversations. Add this to wp-config.php:
 *
 *   define( 'BAC_KEEP_DATA_ON_UNINSTALL', true );
 */
if ( defined( 'BAC_KEEP_DATA_ON_UNINSTALL' ) && BAC_KEEP_DATA_ON_UNINSTALL ) {
	return;
}

$bac_cleanup = static function () {
	foreach ( array( 'beaver_ai_chat_settings', 'bac_digest_last', 'bac_usage', 'bac_cap_notified', 'bac_gaps_closed', 'bac_last_error' ) as $bac_option ) {
		delete_option( $bac_option );
	}

	foreach ( array( 'bac_knowledge', 'bac_knowledge_index', 'bac_gaps_report', 'bac_new_count' ) as $bac_transient ) {
		delete_transient( $bac_transient );
	}

	// These events carry the lead ID as an argument, so they need unscheduling
	// by hook rather than clearing by hook plus exact arguments.
	wp_unschedule_hook( 'bac_enrich_lead' );
	wp_unschedule_hook( 'bac_notify_lead' );
	wp_clear_scheduled_hook( 'bac_notify_digest' );

	$leads = get_posts(
		array(
			'post_type'      => 'bac_lead',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		)
	);

	foreach ( $leads as $lead_id ) {
		wp_delete_post( $lead_id, true );
	}

	// Rate limit transients are short lived, so clear only the leftovers.
	global $wpdb;
	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_bac_rl_%' OR option_name LIKE '_transient_timeout_bac_rl_%'"
	);
};

if ( is_multisite() ) {
	$bac_sites = get_sites( array( 'fields' => 'ids' ) );

	foreach ( $bac_sites as $bac_site_id ) {
		switch_to_blog( $bac_site_id );
		$bac_cleanup();
		restore_current_blog();
	}
} else {
	$bac_cleanup();
}
