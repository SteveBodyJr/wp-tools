<?php
/**
 * Activity log for every change the file manager makes.
 *
 * @package BeaverFileManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Records write operations so a surprise change can be traced back to a person.
 *
 * Reads are never logged: browsing a folder is not an event worth keeping, and
 * logging it would bury the handful of entries that matter.
 *
 * @since 1.0.0
 */
class Beaver_FM_Logger {

	const OPTION = 'beaver_fm_log';

	/**
	 * Appends an entry to the log.
	 *
	 * @since 1.0.0
	 *
	 * @param string $action Machine name of the operation, e.g. `save`.
	 * @param string $path   Path relative to the browsing root.
	 * @param string $detail Short human-readable note.
	 */
	public static function record( $action, $path = '', $detail = '' ) {
		if ( ! Beaver_FM_Settings::value( 'log_enabled' ) ) {
			return;
		}

		$user = wp_get_current_user();
		$log  = self::all();

		array_unshift(
			$log,
			array(
				'time'   => time(),
				'user'   => $user && $user->exists() ? $user->user_login : '—',
				'action' => (string) $action,
				'path'   => (string) $path,
				'detail' => (string) $detail,
				'ip'     => self::client_ip(),
			)
		);

		$limit = absint( Beaver_FM_Settings::value( 'log_limit', 300 ) );
		$log   = array_slice( $log, 0, max( 20, $limit ) );

		update_option( self::OPTION, $log, false );
	}

	/**
	 * Retrieves the whole log, newest first.
	 *
	 * @since 1.0.0
	 *
	 * @return array[]
	 */
	public static function all() {
		$log = get_option( self::OPTION, array() );

		return is_array( $log ) ? $log : array();
	}

	/**
	 * Retrieves the log formatted for display.
	 *
	 * @since 1.0.0
	 *
	 * @param int $limit Maximum entries to return.
	 * @return array[]
	 */
	public static function recent( $limit = 100 ) {
		$labels  = self::action_labels();
		$entries = array_slice( self::all(), 0, absint( $limit ) );
		$out     = array();

		foreach ( $entries as $entry ) {
			$action = isset( $entry['action'] ) ? $entry['action'] : '';

			$out[] = array(
				'time'   => isset( $entry['time'] ) ? (int) $entry['time'] : 0,
				'when'   => isset( $entry['time'] ) ? self::human_time( (int) $entry['time'] ) : '',
				'user'   => isset( $entry['user'] ) ? $entry['user'] : '',
				'action' => $action,
				'label'  => isset( $labels[ $action ] ) ? $labels[ $action ] : $action,
				'path'   => isset( $entry['path'] ) ? $entry['path'] : '',
				'detail' => isset( $entry['detail'] ) ? $entry['detail'] : '',
				'ip'     => isset( $entry['ip'] ) ? $entry['ip'] : '',
			);
		}

		return $out;
	}

	/**
	 * Empties the log.
	 *
	 * @since 1.0.0
	 */
	public static function clear() {
		delete_option( self::OPTION );
	}

	/**
	 * Human-readable labels for the recorded actions.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string,string>
	 */
	private static function action_labels() {
		return array(
			'save'          => __( 'Edited', 'beaver-filemanager' ),
			'create-file'   => __( 'Created file', 'beaver-filemanager' ),
			'create-folder' => __( 'Created folder', 'beaver-filemanager' ),
			'upload'        => __( 'Uploaded', 'beaver-filemanager' ),
			'rename'        => __( 'Renamed', 'beaver-filemanager' ),
			'copy'          => __( 'Copied', 'beaver-filemanager' ),
			'move'          => __( 'Moved', 'beaver-filemanager' ),
			'delete'        => __( 'Deleted', 'beaver-filemanager' ),
			'trash'         => __( 'Trashed', 'beaver-filemanager' ),
			'restore'       => __( 'Restored', 'beaver-filemanager' ),
			'chmod'         => __( 'Permissions', 'beaver-filemanager' ),
			'zip'           => __( 'Compressed', 'beaver-filemanager' ),
			'unzip'         => __( 'Extracted', 'beaver-filemanager' ),
			'download'      => __( 'Downloaded', 'beaver-filemanager' ),
		);
	}

	/**
	 * Formats a timestamp against the site's timezone.
	 *
	 * @since 1.0.0
	 *
	 * @param int $timestamp Unix timestamp.
	 * @return string
	 */
	private static function human_time( $timestamp ) {
		if ( $timestamp <= 0 ) {
			return '';
		}

		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}

	/**
	 * Best-effort client IP.
	 *
	 * Only `REMOTE_ADDR` is trusted — forwarded headers are attacker-controlled
	 * on most setups and a log that can be forged is worse than no log.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	private static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';
		$ip = filter_var( $ip, FILTER_VALIDATE_IP );

		return $ip ? $ip : '';
	}
}
