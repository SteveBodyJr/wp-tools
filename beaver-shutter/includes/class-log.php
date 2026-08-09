<?php
/**
 * Audit log.
 *
 * @package BeaverShutter
 */

defined( 'ABSPATH' ) || exit;

/**
 * Records when the site was closed, reopened, or reconfigured.
 *
 * Taking a site down is the kind of thing somebody asks about a month later —
 * "was the site off on the 12th?" A dated record answers it without anybody
 * having to remember.
 *
 * @since 1.0.0
 */
class Beaver_Shutter_Log {

	const OPTION = 'beaver_shutter_log';
	const MAX    = 200;

	/**
	 * Writes one entry.
	 *
	 * @since 1.0.0
	 *
	 * @param string $event   installed, deactivated, closed, reopened, changed, edited.
	 * @param string $message What happened, already translated.
	 */
	public static function record( $event, $message ) {
		$log = get_option( self::OPTION, array() );
		$log = is_array( $log ) ? $log : array();

		$log[] = array(
			'time'    => time(),
			'event'   => sanitize_key( $event ),
			'message' => sanitize_text_field( $message ),
			'user'    => get_current_user_id(),
		);

		// Not autoloaded and hard-capped: the record must not become a
		// performance problem on every page load.
		update_option( self::OPTION, array_slice( $log, -self::MAX ), false );
	}

	/**
	 * Returns entries, newest first.
	 *
	 * @since 1.0.0
	 *
	 * @param int $limit Maximum rows.
	 * @return array<int,array> Entries.
	 */
	public static function all( $limit = 100 ) {
		$log = get_option( self::OPTION, array() );
		$log = is_array( $log ) ? array_reverse( $log ) : array();

		return array_slice( $log, 0, (int) $limit );
	}

	/**
	 * Empties the log.
	 *
	 * @since 1.0.0
	 */
	public static function clear() {
		delete_option( self::OPTION );
	}
}
