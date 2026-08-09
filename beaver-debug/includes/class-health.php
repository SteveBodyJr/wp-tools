<?php
/**
 * Environment checks.
 *
 * @package BeaverDebug
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reports the facts about a server you cannot SSH into.
 *
 * Every check here earned its place by having cost real debugging time: the
 * imaging engine, the execution and memory limits, and whether cron is
 * actually running are the answers you find yourself guessing at from a
 * distance.
 *
 * @since 1.0.0
 */
class Beaver_Debug_Health {

	/**
	 * Runs every check.
	 *
	 * @since 1.0.0
	 *
	 * @return array<int,array> Checks with 'label', 'value', 'status' and 'note'.
	 */
	public static function checks() {
		$checks = array();

		// --- PHP ---------------------------------------------------------

		$php = PHP_VERSION;

		$checks[] = array(
			'label'  => __( 'PHP version', 'beaver-debug' ),
			'value'  => $php,
			'status' => version_compare( $php, '7.4', '<' ) ? 'bad' : ( version_compare( $php, '8.1', '<' ) ? 'warn' : 'good' ),
			'note'   => version_compare( $php, '8.1', '<' ) ? __( 'Older than the versions still receiving security fixes.', 'beaver-debug' ) : '',
		);

		$memory = wp_convert_hr_to_bytes( ini_get( 'memory_limit' ) );

		$checks[] = array(
			'label'  => __( 'PHP memory limit', 'beaver-debug' ),
			'value'  => (string) ini_get( 'memory_limit' ),
			'status' => $memory > 0 && $memory < 256 * MB_IN_BYTES ? 'warn' : 'good',
			'note'   => $memory > 0 && $memory < 256 * MB_IN_BYTES
				? __( 'Large images and bulk jobs are the first things to hit this.', 'beaver-debug' )
				: '',
		);

		$max_time = (int) ini_get( 'max_execution_time' );

		$checks[] = array(
			'label'  => __( 'Max execution time', 'beaver-debug' ),
			'value'  => $max_time > 0 ? sprintf( '%ds', $max_time ) : __( 'unlimited', 'beaver-debug' ),
			'status' => ( $max_time > 0 && $max_time < 30 ) ? 'warn' : 'good',
			'note'   => ( $max_time > 0 && $max_time < 30 )
				? __( 'Bulk jobs have to work in smaller slices than usual here.', 'beaver-debug' )
				: '',
		);

		$checks[] = array(
			'label'  => __( 'WordPress memory limit', 'beaver-debug' ),
			'value'  => defined( 'WP_MEMORY_LIMIT' ) ? (string) WP_MEMORY_LIMIT : __( 'default', 'beaver-debug' ),
			'status' => 'good',
			'note'   => '',
		);

		// --- Imaging -----------------------------------------------------

		$engine = 'none';

		if ( extension_loaded( 'imagick' ) && class_exists( 'Imagick' ) ) {
			$engine = 'Imagick';
		} elseif ( function_exists( 'gd_info' ) ) {
			$engine = 'GD';
		}

		$checks[] = array(
			'label'  => __( 'Image engine', 'beaver-debug' ),
			'value'  => $engine,
			'status' => 'none' === $engine ? 'bad' : 'good',
			'note'   => 'Imagick' === $engine
				? __( 'Imagick allocates outside the PHP memory limit, so image work can exhaust the container without PHP noticing.', 'beaver-debug' )
				: '',
		);

		$webp = false;

		if ( 'Imagick' === $engine ) {
			$webp = in_array( 'WEBP', array_map( 'strtoupper', (array) Imagick::queryFormats( 'WEBP' ) ), true );
		} elseif ( 'GD' === $engine ) {
			$webp = function_exists( 'imagewebp' );
		}

		$checks[] = array(
			'label'  => __( 'WebP support', 'beaver-debug' ),
			'value'  => $webp ? __( 'yes', 'beaver-debug' ) : __( 'no', 'beaver-debug' ),
			'status' => $webp ? 'good' : 'warn',
			'note'   => $webp ? '' : __( 'Image optimization cannot produce WebP on this server.', 'beaver-debug' ),
		);

		// --- WordPress ---------------------------------------------------

		$checks[] = array(
			'label'  => __( 'WordPress version', 'beaver-debug' ),
			'value'  => get_bloginfo( 'version' ),
			'status' => 'good',
			'note'   => '',
		);

		$cron_disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$overdue       = self::overdue_cron_events();

		$checks[] = array(
			'label'  => __( 'Scheduled tasks', 'beaver-debug' ),
			'value'  => $cron_disabled
				? __( 'WP-Cron disabled', 'beaver-debug' )
				: sprintf(
					/* translators: %d: number of overdue events. */
					_n( '%d overdue', '%d overdue', $overdue, 'beaver-debug' ),
					$overdue
				),
			'status' => ( $cron_disabled || $overdue > 5 ) ? 'warn' : 'good',
			'note'   => $overdue > 5
				? __( 'Events are queued but not running. Anything scheduled — backups, syncs, cleanups — is silently not happening.', 'beaver-debug' )
				: '',
		);

		$checks[] = array(
			'label'  => __( 'HTTPS', 'beaver-debug' ),
			'value'  => is_ssl() ? __( 'yes', 'beaver-debug' ) : __( 'no', 'beaver-debug' ),
			'status' => is_ssl() ? 'good' : 'warn',
			'note'   => '',
		);

		$uploads  = wp_upload_dir( null, false );
		$writable = empty( $uploads['error'] ) && ! empty( $uploads['basedir'] ) && is_writable( $uploads['basedir'] );

		$checks[] = array(
			'label'  => __( 'Uploads writable', 'beaver-debug' ),
			'value'  => $writable ? __( 'yes', 'beaver-debug' ) : __( 'no', 'beaver-debug' ),
			'status' => $writable ? 'good' : 'bad',
			'note'   => $writable ? '' : __( 'Uploads and generated files will fail.', 'beaver-debug' ),
		);

		$checks[] = array(
			'label'  => __( 'Debug display', 'beaver-debug' ),
			'value'  => ( defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY ) ? __( 'on', 'beaver-debug' ) : __( 'off', 'beaver-debug' ),
			'status' => ( defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY ) ? 'bad' : 'good',
			'note'   => ( defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY )
				? __( 'Errors are being printed to visitors. Turn this off on a live site.', 'beaver-debug' )
				: '',
		);

		/**
		 * Filters the health checks.
		 *
		 * @since 1.0.0
		 *
		 * @param array $checks Checks.
		 */
		return apply_filters( 'beaver_debug_health_checks', $checks );
	}

	/**
	 * Counts cron events whose time has passed.
	 *
	 * @since 1.0.0
	 *
	 * @return int Count.
	 */
	private static function overdue_cron_events() {
		$cron = _get_cron_array();

		if ( ! is_array( $cron ) ) {
			return 0;
		}

		$now     = time();
		$overdue = 0;

		foreach ( $cron as $timestamp => $hooks ) {
			// A minute's grace: cron fires on traffic, so a little lateness is
			// normal rather than a fault.
			if ( $timestamp < ( $now - MINUTE_IN_SECONDS ) ) {
				$overdue += count( (array) $hooks );
			}
		}

		return $overdue;
	}

	/**
	 * Builds a plain-text report that can be pasted into a message.
	 *
	 * The point of this plugin is to end the round trip where a developer asks
	 * for a log they cannot reach. One block, copy, paste.
	 *
	 * @since 1.0.0
	 *
	 * @param int $errors How many recent problems to include.
	 * @return string Report.
	 */
	public static function report( $errors = 10 ) {
		$lines = array();

		$lines[] = sprintf( 'Beaver Debug report — %s', home_url( '/' ) );
		$lines[] = sprintf( 'Generated %s', gmdate( 'Y-m-d H:i:s' ) . ' UTC' );
		$lines[] = '';
		$lines[] = 'ENVIRONMENT';

		foreach ( self::checks() as $check ) {
			$flag = 'good' === $check['status'] ? '  ' : ( 'warn' === $check['status'] ? '! ' : 'X ' );

			$lines[] = sprintf( '%s%-24s %s', $flag, $check['label'], $check['value'] );
		}

		/*
		 * Versions matter more than names. On a site you did not build, "which
		 * plugin and which version" is usually the whole diagnosis, and asking
		 * the client to read them off the Plugins screen is another round trip.
		 */
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$installed = function_exists( 'get_plugins' ) ? get_plugins() : array();
		$active    = (array) get_option( 'active_plugins', array() );

		$lines[] = '';
		$lines[] = 'ACTIVE PLUGINS';

		if ( empty( $active ) ) {
			$lines[] = '  (none)';
		}

		foreach ( $active as $plugin ) {
			$lines[] = sprintf(
				'  %-38s %s',
				isset( $installed[ $plugin ]['Name'] ) ? $installed[ $plugin ]['Name'] : dirname( $plugin ),
				isset( $installed[ $plugin ]['Version'] ) ? $installed[ $plugin ]['Version'] : '?'
			);
		}

		$mu = function_exists( 'get_mu_plugins' ) ? get_mu_plugins() : array();

		if ( ! empty( $mu ) ) {
			$lines[] = '';
			$lines[] = 'MUST-USE PLUGINS';

			foreach ( $mu as $file => $data ) {
				$lines[] = sprintf( '  %-38s %s', $data['Name'] ?? $file, $data['Version'] ?? '?' );
			}
		}

		$theme  = wp_get_theme();
		$parent = $theme->parent();

		$lines[] = '';
		$lines[] = sprintf( 'THEME: %s %s', $theme->get( 'Name' ), $theme->get( 'Version' ) );

		if ( $parent ) {
			$lines[] = sprintf( 'PARENT THEME: %s %s', $parent->get( 'Name' ), $parent->get( 'Version' ) );
		}
		$lines[] = '';
		$lines[] = 'RECENT PROBLEMS';

		$groups = Beaver_Debug_Logger::read( (int) $errors );

		if ( empty( $groups ) ) {
			$lines[] = '  (nothing recorded)';
		}

		foreach ( $groups as $group ) {
			$lines[] = sprintf(
				'  [%s x%d] %s',
				strtoupper( $group['severity'] ),
				$group['count'],
				$group['message']
			);

			if ( '' !== $group['file'] ) {
				$lines[] = sprintf( '      %s:%d (%s)', str_replace( wp_normalize_path( ABSPATH ), '', wp_normalize_path( $group['file'] ) ), $group['line'], $group['source'] );
			}

			if ( ! empty( $group['context']['where'] ) ) {
				$lines[] = sprintf(
					'      during %s%s%s',
					$group['context']['where'],
					! empty( $group['context']['action'] ) ? ' (' . $group['context']['action'] . ')' : '',
					! empty( $group['context']['uri'] ) ? ' ' . $group['context']['uri'] : ''
				);
			}

			$lines[] = sprintf( '      last seen %s UTC', gmdate( 'Y-m-d H:i:s', $group['last'] ) );
		}

		return implode( "\n", $lines );
	}
}
