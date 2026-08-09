<?php
/**
 * Plugin Name: Beaver Debug Loader
 * Description: Boots Beaver Debug before other plugins, so an error thrown while another plugin is loading is still recorded. Copy this single file into wp-content/mu-plugins/.
 * Version: 1.0.0
 * Author: Digital Beaver
 *
 * @package BeaverDebug
 */

defined( 'ABSPATH' ) || exit;

/*
 * A normal plugin cannot record the error that stops plugins from loading,
 * because it has not loaded yet. Must-use plugins run first, so loading the
 * main file from here closes that gap. The main file guards against being
 * loaded twice, so leaving the plugin active as well is harmless.
 */
$beaver_debug_main = WP_PLUGIN_DIR . '/beaver-debug/beaver-debug.php';

if ( file_exists( $beaver_debug_main ) ) {
	require_once $beaver_debug_main;
}
