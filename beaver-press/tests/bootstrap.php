<?php
/**
 * Beaver Press tests: shared start. Loads WordPress and protects the site's settings.
 *
 * Every PHP test switches settings for a moment. This file takes a copy of them before the
 * test starts and puts it back however the test ends (checks failing, wp_die(), exit, a
 * fatal error). The copy is also kept in the database while the test runs: if a test is
 * killed outright, the next test restores it first and says so.
 *
 * The engine's daily counter is only ever written back, never deleted or created (the engine
 * treats a re-created counter as an error).
 *
 * @package BeaverPress
 */

if ( PHP_SAPI !== 'cli' ) {
	exit;
}
if ( empty( $_SERVER['HTTP_HOST'] ) ) {
	$_SERVER['HTTP_HOST'] = getenv( 'BP_HOST' ) ?: 'localhost';
}
if ( empty( $_SERVER['REQUEST_URI'] ) ) {
	$_SERVER['REQUEST_URI'] = '/';
}
require getenv( 'BP_WP_LOAD' ) ?: dirname( __DIR__, 4 ) . '/wp-load.php';

/** Option holding the copy while a test runs. */
const BP_TEST_SNAPSHOT = 'beaver_press_test_snapshot';

/**
 * Options the tests may change.
 *
 * @return string[]
 */
function bp_test_options() {
	return array(
		'trp_machine_translation_settings',
		'trp_settings',
		'trp_advanced_settings',
		'trp_plugin_optin',
		'trp_machine_translated_characters',
		'trp_db_stored_data',
		BP_Keys::OPTION,
		BP_Usage::OPTION,
		BP_Usage::PRICES_OPTION,
		BP_Providers::ERROR_OPTION,
		BP_Batch::REJECT_OPTION,
		BP_Glossary::NAMES_OPTION,
		BP_Instructions::OPTION,
		BP_Engine_Settings::TEST_OPTION,
		BP_Auto::OPTION,
		BP_Auto::QUEUE_OPTION,
		BP_Cache::OPTION,
		BP_Complete::OPTION,
		BP_Suggest::OPTION,
		BP_Suggest::REDIRECT_OPTION,
		BP_Slugs::OPTION,
		BP_Quiet::OPTION,
		BP_Topup::OPTION,
		'beaver_press_alerts',
		'beaver_press_alert_state',
		BP_Complete::SEEN_OPTION,
		'beaver_press_budget',
		'beaver_press_history',
		'beaver_press_slug_bases',
		'beaver_press_backups',
		'beaver_press_profile',
	);
}

/**
 * Read an option straight from the database (no cache), null when missing.
 *
 * @param string $name Option name.
 * @return mixed
 */
function bp_test_read( $name ) {
	global $wpdb;
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
	return $row ? maybe_unserialize( $row->option_value ) : null;
}

/**
 * Put a copy back.
 *
 * @param array $snap Option => value (null = did not exist), plus 'counter'.
 */
function bp_test_restore( array $snap ) {
	global $wpdb;
	foreach ( (array) ( $snap['options'] ?? array() ) as $name => $value ) {
		wp_cache_delete( $name, 'options' );
		if ( null === $value ) {
			delete_option( $name );
		} elseif ( bp_test_read( $name ) !== $value ) {
			update_option( $name, $value, false );
		}
	}
	if ( isset( $snap['counter'] ) && null !== bp_test_read( 'trp_machine_translation_counter' ) ) {
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = 'trp_machine_translation_counter'", (string) $snap['counter'] ) );
		wp_cache_delete( 'trp_machine_translation_counter', 'options' );
	}
	wp_cache_delete( 'alloptions', 'options' );
	if ( class_exists( 'BP_Glossary' ) ) {
		BP_Glossary::flush();
	}
}

// A test that calls itself (test-forms-email.php) runs under its parent's protection.
if ( ! getenv( 'BP_TEST_NESTED' ) ) {
	putenv( 'BP_TEST_NESTED=1' );

	$bp_left = bp_test_read( BP_TEST_SNAPSHOT );
	if ( is_array( $bp_left ) ) {
		bp_test_restore( $bp_left );
		delete_option( BP_TEST_SNAPSHOT );
		fwrite( STDERR, "NOTE An earlier test was stopped before it finished: its settings copy was put back.\n" );
	}

	$bp_snap = array(
		'options' => array(),
		'counter' => bp_test_read( 'trp_machine_translation_counter' ),
	);
	foreach ( bp_test_options() as $bp_o ) {
		$bp_snap['options'][ $bp_o ] = bp_test_read( $bp_o );
	}
	update_option( BP_TEST_SNAPSHOT, $bp_snap, false );

	register_shutdown_function(
		static function () use ( $bp_snap ) {
			bp_test_restore( $bp_snap );
			delete_option( BP_TEST_SNAPSHOT );
		}
	);
	unset( $bp_left, $bp_o );
}
