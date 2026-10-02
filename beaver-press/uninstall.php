<?php
/**
 * Removes Beaver Press's own options. TranslatePress settings and translations are left alone.
 *
 * @package BeaverPress
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'beaver_press_keys' );
delete_option( 'beaver_press_last_error' );
delete_option( 'beaver_press_test' );
delete_option( 'beaver_press_rejected' );
delete_transient( 'bp_glossary_auto' );
delete_option( 'beaver_press_run' );
delete_option( 'beaver_press_usage' );
delete_option( 'beaver_press_quiet' );
delete_option( 'beaver_press_auto' );
delete_option( 'beaver_press_auto_queue' );
delete_option( 'beaver_press_auto_last' );
delete_option( 'beaver_press_roles' );
remove_role( 'bp_translator' );
wp_clear_scheduled_hook( 'beaver_press_auto_tick' );
delete_option( 'beaver_press_run_queue' );
delete_transient( 'bp_run_lock' );
wp_clear_scheduled_hook( 'beaver_press_run_tick' );
foreach ( array( 'claude', 'openai', 'deepseek', 'gemini', 'deepl', 'custom' ) as $bp_provider ) {
	delete_transient( 'bp_models_' . $bp_provider );
}
delete_option( 'beaver_press_cache' );
delete_option( 'beaver_press_suggest' );
delete_option( 'beaver_press_complete_only' );
delete_option( 'beaver_press_complete_map' );
delete_option( 'beaver_press_pages' );
global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'bp\\_pg\\_%' OR option_name LIKE 'bp\\_pt\\_%' OR option_name LIKE 'bp\\_ni\\_%'" ); // phpcs:ignore -- per-page progress rows and their missing texts.
delete_option( 'beaver_press_missing_seen' );
delete_option( 'beaver_press_redirect' );
delete_option( 'beaver_press_instructions' );
delete_option( 'beaver_press_prices' );
delete_option( 'beaver_press_names' );
delete_option( 'beaver_press_alerts' );
delete_option( 'beaver_press_alert_state' );
delete_option( 'beaver_press_budget' );
delete_option( 'beaver_press_history' );
delete_option( 'beaver_press_slug_bases' );
delete_option( 'beaver_press_profile' );
delete_option( 'beaver_press_xdefault_set' );
delete_option( 'beaver_press_backups' );
$bp_backup_key = get_option( 'beaver_press_backup_dir' );
if ( $bp_backup_key ) {
	$bp_backup_dir = trailingslashit( wp_upload_dir()['basedir'] ) . 'beaver-press-backups-' . sanitize_file_name( $bp_backup_key );
	foreach ( (array) glob( $bp_backup_dir . '/{,.}*', GLOB_BRACE ) as $bp_file ) {
		if ( is_file( $bp_file ) ) {
			wp_delete_file( $bp_file );
		}
	}
	@rmdir( $bp_backup_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- empty folder.
}
delete_option( 'beaver_press_backup_dir' );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}bp_translation_meta" ); // phpcs:ignore WordPress.DB -- own table.
delete_option( 'beaver_press_meta_db' );
delete_metadata( 'user', 0, 'bp_alert_hidden', '', true );
delete_option( 'beaver_press_form_texts' );
delete_option( 'beaver_press_cache_gen' );
delete_option( 'beaver_press_cache_build' );
wp_clear_scheduled_hook( 'beaver_press_cache_purge' );
wp_clear_scheduled_hook( 'beaver_press_cache_warm' );
delete_option( 'beaver_press_cache_warm' );
// Ready translated pages.
$bp_uploads = wp_upload_dir( null, false );
foreach ( array( WP_CONTENT_DIR . '/cache/beaver-press', $bp_uploads['basedir'] . '/beaver-press-cache' ) as $bp_cache_root ) {
	if ( ! is_dir( $bp_cache_root ) ) {
		continue;
	}
	$bp_it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $bp_cache_root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $bp_it as $bp_f ) {
		$bp_f->isDir() ? @rmdir( $bp_f->getPathname() ) : @unlink( $bp_f->getPathname() ); // phpcs:ignore
	}
	@rmdir( $bp_cache_root ); // phpcs:ignore
}
delete_option( 'beaver_press_slugs' );
delete_option( 'beaver_press_slugs_backfill' );
delete_option( 'beaver_press_topup' );
delete_option( 'beaver_press_topup_state' );
wp_clear_scheduled_hook( 'beaver_press_topup' );
wp_clear_scheduled_hook( 'beaver_press_topup_more' );
wp_clear_scheduled_hook( 'beaver_press_slugs_backfill' );
delete_transient( 'bp_slug_map' );
delete_post_meta_by_key( '_bp_slug' );
$wpdb->query( "DELETE FROM {$wpdb->termmeta} WHERE meta_key = '_bp_slug'" ); // phpcs:ignore -- translated term addresses.
